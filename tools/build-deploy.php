<?php
/**
 * ============================================================================
 * build-deploy.php - Assembles an upload-ready tree for shared hosting.
 *
 *   php tools/build-deploy.php            build into dist/upload
 *   php tools/build-deploy.php --out=DIR  build somewhere else
 *
 * LAYOUT
 * The application expects `app/` to sit one level above the served directory
 * (every entry point does require dirname(__DIR__) . '/app/bootstrap.php').
 * Shared hosts give you `htdocs/` as the web root and nothing above it, so the
 * whole project goes inside htdocs and `public/` becomes the served subfolder:
 *
 *     htdocs/
 *       .htaccess        rewrites / to public/ so the URL stays clean
 *       app/             .htaccess: deny  <- config.local.php lives here
 *       views/           .htaccess: deny
 *       migrations/      .htaccess: deny
 *       backups/         .htaccess: deny  <- dumps of every employee record
 *       public/          the only directory meant to be reachable
 *
 * This needs no code change and no write access above htdocs.
 *
 * WHAT IS DELIBERATELY NOT SHIPPED
 *   vendor/       only PHPUnit lives there; nothing at runtime requires it
 *   tests/        no reason to expose the suite
 *   tools/        includes this file and the credential generator
 *   docs/         internal planning documents
 *   app/config.local.php   real database credentials - never leaves the machine
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("build-deploy.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Digos\Domain\Migration\StatementSplitter;

const PROJECT = __DIR__ . '/..';

/** Directories copied wholesale into the package. */
const SHIP = ['app', 'views', 'public', 'migrations'];

/** Never copied, whatever directory they appear in. */
const EXCLUDE_NAMES = ['config.local.php', '.git', '.gitignore', 'node_modules'];

/** Never copied, by extension - backup dumps in particular. */
const EXCLUDE_EXTENSIONS = ['sql~', 'log'];

$out = PROJECT . '/dist/upload';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
}

try {
    exit(build($out));
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR  " . $e->getMessage() . "\n\n");
    exit(1);
}

/* ========================================================================== */

function build(string $out): int
{
    if (is_dir($out)) removeTree($out);
    mkdir($out, 0775, true);

    $copied = 0;
    foreach (SHIP as $dir) {
        $source = PROJECT . '/' . $dir;
        if (!is_dir($source)) throw new RuntimeException("Missing source directory: $dir");
        $copied += copyTree($source, $out . '/' . $dir);
        say(sprintf('  %-14s copied', $dir . '/'));
    }

    // backups/ ships as an empty, protected directory. Its contents are the
    // single most sensitive artefact the system produces and must never be
    // part of a package that gets emailed or uploaded.
    mkdir($out . '/backups', 0775, true);
    copy(PROJECT . '/backups/.htaccess', $out . '/backups/.htaccess');
    file_put_contents($out . '/backups/.gitkeep', '');
    say('  backups/       created empty (protected)');

    writeRootHtaccess($out);
    writeConfigTemplate($out);

    $schema = PROJECT . '/dist/deploy-schema.sql';
    passthru(sprintf('"%s" "%s" --sql=%s',
        PHP_BINARY, PROJECT . '/tools/migrate.php', escapeshellarg($schema)));

    $reset = writeResetScript();

    audit($out);

    say('');
    say(sprintf('package: %s', realpath($out)));
    say(sprintf('files:   %d', $copied + 3));
    say(sprintf('schema:  %s', realpath($schema) ?: $schema));
    say(sprintf('reset:   %s', realpath($reset) ?: $reset));
    return 0;
}

/**
 * Writes the script that empties a database so deploy-schema.sql can be
 * imported into it.
 *
 * deploy-schema.sql requires an empty database and cannot be re-run: its
 * ALTER TABLE statements are not idempotent, so a second pass dies on
 * "Duplicate column name". An import that fails partway - a host defaulting to
 * MyISAM, a dropped connection, a timeout - therefore leaves a database that
 * the same file can no longer be imported into, and the retry fails on
 * whichever leftover it meets first rather than on anything informative.
 *
 * Dropping every table by hand in phpMyAdmin is the alternative, and with
 * twenty foreign keys the order matters. This does it in one paste. It is a
 * separate file, never referenced by the import, because it destroys data and
 * that has to stay a deliberate act.
 *
 * The drops are ordered children-before-parents rather than alphabetically.
 * SET FOREIGN_KEY_CHECKS = 0 is still emitted, but it cannot be relied on
 * alone: it is a session variable, and a host that does not carry the session
 * across every statement of the paste leaves the constraints live, at which
 * point `Employees` - referenced by nine tables and early in the alphabet -
 * fails with #1451 and the database is left half-emptied. Ordering makes the
 * script correct with the checks either way.
 */
function writeResetScript(): string
{
    $path = PROJECT . '/dist/deploy-reset.sql';

    [$tables, $edges] = readSchemaGraph();
    $tables['schema_migrations'] = true;      // created by migrate.php, not a migration
    [$names, $breaks] = dropOrder($tables, $edges);

    $body = "-- Digos Payroll - reset a database before importing deploy-schema.sql\n"
        . '-- Generated ' . date('Y-m-d H:i') . " by tools/build-deploy.php\n"
        . "--\n"
        . "-- DESTROYS ALL DATA in the selected database. Run it only when an\n"
        . "-- import has failed partway and you are starting that import again.\n"
        . "-- Take a backup first if the database holds anything you want.\n"
        . "--\n"
        . "-- phpMyAdmin -> click your database in the left sidebar (not the\n"
        . "-- server home page) -> SQL -> paste this whole file -> Go. Then\n"
        . "-- import deploy-schema.sql into the now-empty database.\n"
        . "--\n"
        . "-- #1046 \"No database selected\" means the SQL tab was opened at the\n"
        . "-- server rather than inside a database. Open the database first, or\n"
        . "-- uncomment the next line and fill in its name:\n"
        . "--\n"
        . "-- USE `your_database_name`;\n"
        . "--\n"
        . "-- The DROP order below is children-before-parents, so the script is\n"
        . "-- correct even where the FOREIGN_KEY_CHECKS setting does not hold for\n"
        . "-- every statement. Without that order the parent tables fail with\n"
        . "-- #1451 and the reset stops halfway.\n\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($breaks as [$child, $parent]) $body .= breakReference($child, $parent);

    foreach ($names as $t) $body .= "DROP TABLE IF EXISTS `$t`;\n";

    $body .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

    file_put_contents($path, $body);
    say(sprintf('  deploy-reset.sql  written (%d tables)', count($names)));

    return $path;
}

/**
 * SQL that drops the foreign key from $child to $parent, if it is there.
 *
 * Written against information_schema rather than as a plain ALTER TABLE for
 * two reasons. A bare `ALTER TABLE x DROP FOREIGN KEY y` fails outright when
 * the table or the constraint is absent, and a database left half-built by a
 * failed import - the only situation this script exists for - is exactly where
 * that happens; MariaDB 10.4 has no `ALTER TABLE IF EXISTS` to fall back on.
 * And looking the name up by which table it points at, rather than hardcoding
 * it, keeps this correct if a migration ever renames the constraint.
 *
 * `DO 0` is the do-nothing statement executed when there is no such reference.
 */
function breakReference(string $child, string $parent): string
{
    return "-- `$child` and `$parent` reference each other; no drop order works\n"
        . "-- for the pair, so one side of the cycle goes first.\n"
        . "SET @stmt := IFNULL((\n"
        . "    SELECT CONCAT('ALTER TABLE `$child` DROP FOREIGN KEY `', CONSTRAINT_NAME, '`')\n"
        . "      FROM information_schema.REFERENTIAL_CONSTRAINTS\n"
        . "     WHERE CONSTRAINT_SCHEMA = DATABASE()\n"
        . "       AND TABLE_NAME = '$child'\n"
        . "       AND REFERENCED_TABLE_NAME = '$parent'\n"
        . "     LIMIT 1), 'DO 0');\n"
        . "PREPARE stmt FROM @stmt;\n"
        . "EXECUTE stmt;\n"
        . "DEALLOCATE PREPARE stmt;\n\n";
}

/**
 * Reads the migrations and returns [tables, edges]: every table the schema
 * creates, and for each one the tables its foreign keys point at.
 *
 * Both forms matter - 0005 and 0016 declare constraints inside CREATE TABLE,
 * 0009 adds them afterwards with ALTER TABLE - so the statement is classified
 * first and every REFERENCES in it is attributed to that table.
 *
 * @return array{0: array<string,true>, 1: array<string,array<string,true>>}
 */
function readSchemaGraph(): array
{
    $tables = [];
    $edges = [];

    foreach (glob(PROJECT . '/migrations/*.sql') ?: [] as $file) {
        foreach (StatementSplitter::split((string) file_get_contents($file)) as $statement) {
            if (!preg_match(
                '/^(CREATE|ALTER)\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i',
                $statement, $m)) {
                continue;
            }

            $table = $m[2];
            if (strcasecmp($m[1], 'CREATE') === 0) $tables[$table] = true;

            if (preg_match_all('/REFERENCES\s+`?(\w+)`?/i', $statement, $refs)) {
                foreach ($refs[1] as $parent) {
                    // A self-reference (Memorandum.SupersedesID, WorkShifts)
                    // constrains nothing about drop order.
                    if (strcasecmp($parent, $table) !== 0) $edges[$table][$parent] = true;
                }
            }
        }
    }

    return [$tables, $edges];
}

/**
 * Orders tables so that a table is only dropped once everything referencing it
 * is gone. Ties are broken alphabetically to keep the generated file stable
 * between builds.
 *
 * `Offices` and `Functions` reference each other - an office has a function,
 * a function has an owning office - so for that pair no order exists at all.
 * The second return value lists the references that have to be dropped before
 * the tables can be, as [child, parent] pairs.
 *
 * @param array<string,true>                 $tables
 * @param array<string,array<string,true>>   $edges  child => [parent => true]
 * @return array{0: string[], 1: array<array{0: string, 1: string}>}
 */
function dropOrder(array $tables, array $edges): array
{
    $remaining = array_keys($tables);
    sort($remaining);

    $order = [];
    $breaks = [];

    while ($remaining) {
        $ready = [];
        foreach ($remaining as $table) {
            $referenced = false;
            foreach ($remaining as $other) {
                if ($other !== $table && isset($edges[$other][$table])) {
                    $referenced = true;
                    break;
                }
            }
            if (!$referenced) $ready[] = $table;
        }

        if ($ready) {
            foreach ($ready as $table) $order[] = $table;
            $remaining = array_values(array_diff($remaining, $ready));
            continue;
        }

        // Nothing is droppable, so everything left is in a reference cycle.
        // Cut every reference that stays inside the set: the next pass then
        // finds the whole remainder ready. Cutting more than the minimum is
        // deliberate - the tables are about to be dropped anyway, and it
        // avoids having to decide which single reference to sacrifice.
        $cut = 0;
        foreach ($remaining as $child) {
            foreach (array_keys($edges[$child] ?? []) as $parent) {
                if (in_array($parent, $remaining, true)) {
                    $breaks[] = [$child, $parent];
                    unset($edges[$child][$parent]);
                    $cut++;
                }
            }
        }

        // No reference stayed inside the set, so the cycle is not one this
        // can cut. Emit the rest and leave it to FOREIGN_KEY_CHECKS = 0.
        if ($cut === 0) {
            foreach ($remaining as $table) $order[] = $table;
            break;
        }
    }

    return [$order, $breaks];
}

/**
 * Rewrites the site root to public/ so the served URL is https://site/ rather
 * than https://site/public/, without moving any file out of its expected
 * position relative to app/.
 */
function writeRootHtaccess(string $out): void
{
    $htaccess = <<<APACHE
    # Serve the site from public/ while keeping app/, views/ and backups/
    # outside anything the web server will hand out.
    #
    # Without this the site still works at https://<host>/public/ - the rewrite
    # only removes that prefix from the URL.

    <IfModule mod_rewrite.c>
        RewriteEngine On

        # Requests already inside public/ pass through untouched; without this
        # guard the rule matches its own output and loops.
        #
        # The test is against \$1 - the path relative to this .htaccess - and
        # deliberately not against %{REQUEST_URI}, which carries whatever
        # subdirectory the site is deployed under. At a document root the two
        # agree. One level down, REQUEST_URI is "/rehearsal/public/x", which
        # does not start with "/public/", so a REQUEST_URI test never fires and
        # the rule rewrites its own output until Apache aborts the request with
        # AH00124 (10 internal redirects) and serves a 500.
        RewriteCond \$1 !^public/
        RewriteRule ^(.*)$ public/\$1 [L]
    </IfModule>

    # Directory listings disclose the layout even where files are denied.
    Options -Indexes

    # A stray dot-file (editor backup, .env, .git config) must never be served.
    <FilesMatch "^\.">
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>
    </FilesMatch>

    APACHE;

    file_put_contents($out . '/.htaccess', str_replace("\n", "\r\n", $htaccess));
    say('  .htaccess      written (root -> public/, listings off)');
}

/** The file the deployer fills in and renames on the server. */
function writeConfigTemplate(string $out): void
{
    $template = <<<'PHP'
    <?php
    /**
     * config.local.php - Machine-local overrides. NEVER commit this file.
     *
     * RENAME THIS FILE to config.local.php once the values below are filled in.
     * app/config.php loads it first; anything defined here wins.
     *
     * Shared hosts do not use localhost for MySQL and they choose the database
     * and user names for you. Copy the exact values from the host's control
     * panel - they normally look like sqlNNN.<host>.com and if0_12345678_name.
     */

    declare(strict_types=1);

    define('DB_HOST', 'sqlNNN.example-host.com');
    define('DB_NAME', 'if0_00000000_digos_payroll');
    define('DB_USER', 'if0_00000000');
    define('DB_PASS', 'PUT-THE-HOSTS-PASSWORD-HERE');

    // Free hosting usually blocks PHP's mail(). Payslip email will fail until
    // this points at a real mailbox and the host permits outbound mail.
    define('MAIL_FROM', 'payroll@example.org');

    PHP;

    file_put_contents($out . '/app/config.local.php.example',
        str_replace("\n", "\r\n", $template));
    say('  app/config.local.php.example  written');
}

/**
 * Refuses to hand over a package containing anything that should not leave the
 * machine. A build that silently included credentials or a database dump would
 * be worse than no build at all.
 */
function audit(string $out): void
{
    $problems = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $name = $file->getFilename();
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($out) + 1));

        if ($name === 'config.local.php') $problems[] = "credentials included: $relative";
        if (str_ends_with($name, '.sql') && str_starts_with($relative, 'backups/')) {
            $problems[] = "database dump included: $relative";
        }
        if (preg_match('/PayrollDB_Backup_/', $name)) $problems[] = "backup included: $relative";

        // Filenames are the easy half. A password pasted into app/config.php
        // ships under a name nothing here would flag, and config.php is
        // tracked - so this is also the check that stops one reaching GitHub.
        if (str_ends_with($name, '.php')) {
            foreach (hardcodedSecrets((string) file_get_contents($file->getPathname())) as $what) {
                $problems[] = "$what in $relative";
            }
        }
    }

    // Every directory that is not public/ must carry a deny rule.
    foreach (['app', 'views', 'migrations', 'backups'] as $dir) {
        if (!is_file("$out/$dir/.htaccess")) {
            $problems[] = "$dir/ has no .htaccess - it would be served over HTTP";
        }
    }
    if (!is_file("$out/.htaccess")) $problems[] = 'root .htaccess missing';

    $problems = array_merge($problems, stagingProblems());

    if ($problems) {
        removeTree($out);
        throw new RuntimeException(
            "Refusing to produce the package:\n    - " . implode("\n    - ", $problems)
            . "\n\n  The partial build has been deleted.");
    }

    say('');
    say('  audit          no credentials, no dumps, every private directory denied');

    // dist/config.local.php is the file you upload to become the server's
    // app/config.local.php. Report which of the two states it is in, because
    // they need opposite handling and telling them apart by eye means opening
    // the file: a filled-in password is the one thing in dist/ that must not
    // travel with anything else, and a blank one is a site that will not
    // connect until somebody types it in on the server.
    $staged = PROJECT . '/dist/config.local.php';
    if (is_file($staged)) {
        $hasPassword = preg_match('/define\(\s*[\'"]DB_PASS[\'"]\s*,\s*[\'"][^\'"]+[\'"]/',
            (string) file_get_contents($staged)) === 1;

        if ($hasPassword) {
            say('  note           dist/config.local.php has a password in it - upload it,');
            say('                 do not include it in anything you send anywhere else');
        } else {
            say('  note           dist/config.local.php has no password - set DB_PASS on the');
            say('                 server after uploading, or the site cannot reach its database');
        }
    }
}

/**
 * Problems in dist/ itself, outside the package.
 *
 * The package is not the only thing the build leaves behind, and dist/ is what
 * actually gets opened, zipped or handed to somebody. A database dump sitting
 * beside the schema is every employee's TIN, rate and address in plain text -
 * git ignores dist/, so nothing else is watching that directory at all.
 *
 * @return string[]
 */
function stagingProblems(): array
{
    $dist = PROJECT . '/dist';
    if (!is_dir($dist)) return [];

    $problems = [];
    foreach (scandir($dist) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || is_dir("$dist/$entry")) continue;

        if (preg_match('/PayrollDB_Backup_/', $entry) || str_ends_with($entry, '.sql.gz')) {
            $problems[] = "database dump left in dist/: $entry";
        }
    }

    return $problems;
}

/**
 * Password constants with a real value in them.
 *
 * Passwords only. app/config.php deliberately defaults DB_USER to 'root' for a
 * fresh XAMPP install, and a username is not a secret - flagging it would make
 * the audit fail on a correct tracked file, and an audit that cries wolf gets
 * switched off. The rule being enforced is the one in CLAUDE.md: never put a
 * real password in app/config.php.
 *
 * Matches only a non-empty literal, since config.php defines DB_PASS empty as
 * its fallback. Interpolation or a value read from elsewhere is not caught -
 * this is a backstop against the obvious mistake, not a secret scanner.
 *
 * @return string[]
 */
function hardcodedSecrets(string $php): array
{
    $found = [];

    foreach (['DB_PASS', 'DB_MIGRATE_PASS'] as $constant) {
        $pattern = "/define\(\s*['\"]" . $constant . "['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/";
        if (!preg_match($pattern, $php, $m)) continue;

        // The example template ships placeholders on purpose.
        if (preg_match('/^(CHANGE_?ME|XXX|placeholder|your[-_ ]?)/i', $m[1])) continue;

        $found[] = "hardcoded $constant";
    }

    return $found;
}

/** @return int files copied */
function copyTree(string $source, string $destination): int
{
    if (!is_dir($destination)) mkdir($destination, 0775, true);
    $count = 0;

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (in_array($entry, EXCLUDE_NAMES, true)) continue;

        $extension = pathinfo($entry, PATHINFO_EXTENSION);
        if (in_array($extension, EXCLUDE_EXTENSIONS, true)) continue;

        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;

        if (is_dir($from)) {
            $count += copyTree($from, $to);
            continue;
        }
        if (!copy($from, $to)) throw new RuntimeException("Failed to copy $from");
        $count++;
    }
    return $count;
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $path . '/' . $entry;
        is_dir($full) ? removeTree($full) : unlink($full);
    }
    rmdir($path);
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}
