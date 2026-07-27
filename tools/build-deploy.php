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

    audit($out);

    say('');
    say(sprintf('package: %s', realpath($out)));
    say(sprintf('files:   %d', $copied + 3));
    say(sprintf('schema:  %s', realpath($schema) ?: $schema));
    return 0;
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
        RewriteCond %{REQUEST_URI} !^/public/
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

    $sensitive = 0;
    foreach ($iterator as $file) {
        $name = $file->getFilename();
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($out) + 1));

        if ($name === 'config.local.php') $problems[] = "credentials included: $relative";
        if (str_ends_with($name, '.sql') && str_starts_with($relative, 'backups/')) {
            $problems[] = "database dump included: $relative";
        }
        if (preg_match('/PayrollDB_Backup_/', $name)) $problems[] = "backup included: $relative";
        $sensitive++;
    }

    // Every directory that is not public/ must carry a deny rule.
    foreach (['app', 'views', 'migrations', 'backups'] as $dir) {
        if (!is_file("$out/$dir/.htaccess")) {
            $problems[] = "$dir/ has no .htaccess - it would be served over HTTP";
        }
    }
    if (!is_file("$out/.htaccess")) $problems[] = 'root .htaccess missing';

    if ($problems) {
        removeTree($out);
        throw new RuntimeException(
            "Refusing to produce the package:\n    - " . implode("\n    - ", $problems)
            . "\n\n  The partial build has been deleted.");
    }

    say('');
    say('  audit          no credentials, no dumps, every private directory denied');
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
