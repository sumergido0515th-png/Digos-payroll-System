<?php
/**
 * ============================================================================
 * migrate.php - Forward-only schema migration runner (CLI).
 *
 *   php tools/migrate.php              apply every pending migration
 *   php tools/migrate.php --status     list applied / pending, change nothing
 *   php tools/migrate.php --dry-run    show what would run, change nothing
 *
 * Migrations are files named migrations/NNNN_description.sql, applied in
 * ascending numeric order exactly once. Applied versions are recorded in the
 * schema_migrations table with a checksum of the file, so a migration edited
 * after it was applied is reported rather than silently ignored.
 *
 * Connection settings come from the environment (DB_HOST, DB_NAME, DB_USER,
 * DB_PASS) when set - which is how CI and test databases are pointed
 * elsewhere - and otherwise from app/config.php.
 *
 * NOTE ON TRANSACTIONS: MySQL and MariaDB commit implicitly on DDL, so a
 * migration cannot be rolled back as a unit. Keep each migration small, and
 * take a backup before running against real data.
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("migrate.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/app/config.php';

use Digos\Domain\Migration\MigrationFile;
use Digos\Domain\Migration\StatementSplitter;

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

$args = array_slice($argv, 1);
$status = in_array('--status', $args, true);
$dryRun = in_array('--dry-run', $args, true);

// --sql emits the schema as one importable script instead of connecting.
// Shared hosting without shell access (InfinityFree and similar) has no way
// to run this tool on the server; the output goes into phpMyAdmin instead.
//
// --sql=<file> writes the file directly. Prefer it over shell redirection:
// PowerShell's `>` produces UTF-8 WITH a byte-order mark, and phpMyAdmin
// reports that BOM as a syntax error on line 1 of an otherwise valid script.
$sqlOut = null;
foreach ($args as $arg) {
    if ($arg === '--sql') $sqlOut = 'php://stdout';
    if (str_starts_with($arg, '--sql=')) $sqlOut = substr($arg, 6);
}

if ($sqlOut !== null) {
    try {
        exit(emitSql($sqlOut));
    } catch (Throwable $e) {
        fwrite(STDERR, "\n  ERROR  " . $e->getMessage() . "\n\n");
        exit(1);
    }
}

try {
    exit(run($status, $dryRun));
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR  " . $e->getMessage() . "\n\n");
    exit(1);
}

/* ========================================================================== */

/**
 * Writes every migration to stdout as a single import script, including the
 * schema_migrations bookkeeping, so a database populated this way is
 * indistinguishable from one built by the runner.
 *
 * Deliberately does not connect: it is generated on a workstation and carried
 * to a host this machine cannot reach.
 */
function emitSql(string $target): int
{
    $migrations = availableMigrations();
    if (!$migrations) throw new RuntimeException('No migration files found in migrations/.');

    $out = fopen($target, 'wb');
    if ($out === false) throw new RuntimeException("Cannot write to $target.");

    fwrite($out, "-- Digos Payroll - full schema import\n");
    fwrite($out, '-- Generated ' . date('Y-m-d H:i') . " by tools/migrate.php --sql\n");
    fwrite($out, "--\n");
    fwrite($out, "-- For hosts with no shell access. Import through phpMyAdmin into an\n");
    fwrite($out, "-- EMPTY database; it is not safe to re-run over existing data.\n");
    fwrite($out, "--\n");
    fwrite($out, "-- No CREATE DATABASE or USE statement: shared hosts name the database\n");
    fwrite($out, "-- for you, and phpMyAdmin imports into whichever one is selected.\n\n");

    fwrite($out, "SET SESSION sql_mode = '" . DB_SQL_MODE . "';\n\n");

    fwrite($out, "CREATE TABLE IF NOT EXISTS schema_migrations (\n"
        . "    Version   INT          NOT NULL PRIMARY KEY,\n"
        . "    Filename  VARCHAR(190) NOT NULL,\n"
        . "    Checksum  CHAR(64)     NOT NULL,\n"
        . "    AppliedAt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . "    AppliedBy VARCHAR(120) NOT NULL DEFAULT ''\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");

    foreach ($migrations as $migration) {
        $sql = fileContents($migration);

        fwrite($out, str_repeat('-', 76) . "\n");
        fwrite($out, '-- ' . $migration['file'] . "\n");
        fwrite($out, str_repeat('-', 76) . "\n\n");

        foreach (StatementSplitter::split($sql) as $statement) {
            fwrite($out, $statement . ";\n");
        }

        fwrite($out, sprintf(
            "\nINSERT INTO schema_migrations (Version, Filename, Checksum, AppliedBy)\n"
            . "VALUES (%d, '%s', '%s', 'sql-import');\n\n",
            $migration['version'],
            str_replace("'", "''", $migration['file']),
            MigrationFile::checksum($sql)));
    }

    fwrite($out, "-- End of import.\n");
    fclose($out);

    if ($target !== 'php://stdout') {
        say('wrote ' . $target . ' (' . number_format((float) filesize($target)) . ' bytes, no BOM)');
    }
    return 0;
}

function run(bool $statusOnly, bool $dryRun): int
{
    $cfg = connectionConfig();
    $pdo = connect($cfg);

    say("database   {$cfg['user']}@{$cfg['host']}/{$cfg['name']}");

    ensureRegistry($pdo);
    $applied = appliedVersions($pdo);
    $available = availableMigrations();

    if (!$available) {
        say('no migration files found in migrations/');
        return 0;
    }

    verifyChecksums($available, $applied);

    $pending = array_filter($available,
        fn(array $m): bool => !isset($applied[$m['version']]));

    if ($statusOnly) {
        say('');
        foreach ($available as $m) {
            $mark = isset($applied[$m['version']]) ? 'applied' : 'PENDING';
            say(sprintf('  [%s]  %s', $mark, $m['file']));
        }
        say('');
        say(sprintf('%d applied, %d pending', count($applied), count($pending)));
        return 0;
    }

    if (!$pending) {
        say('schema is up to date (' . count($applied) . ' migrations applied)');
        return 0;
    }

    say(count($pending) . ' pending migration(s)' . ($dryRun ? ' - dry run, nothing will change' : ''));
    say('');

    foreach ($pending as $m) {
        if ($dryRun) {
            say(sprintf('  would apply  %s  (%d statements)',
                $m['file'], count(StatementSplitter::split(fileContents($m)))));
            continue;
        }
        apply($pdo, $m);
    }

    say('');
    say($dryRun ? 'dry run complete' : 'done');
    return 0;
}

/**
 * Resolves DB settings in precedence order:
 *   1. environment (how CI and one-off runs point elsewhere)
 *   2. DB_MIGRATE_USER / DB_MIGRATE_PASS from app/config.local.php
 *   3. DB_USER / DB_PASS
 *
 * Step 2 exists because the application account deliberately has no DDL
 * rights - see tools/create-app-user.php. Without it, migrating would fail
 * with a bare "access denied" on the first CREATE TABLE.
 */
function connectionConfig(): array
{
    $user = getenv('DB_USER') ?: (defined('DB_MIGRATE_USER') ? DB_MIGRATE_USER : DB_USER);

    if (getenv('DB_PASS') !== false) {
        $pass = (string) getenv('DB_PASS');
    } elseif (!getenv('DB_USER') && defined('DB_MIGRATE_PASS')) {
        $pass = DB_MIGRATE_PASS;
    } else {
        $pass = DB_PASS;
    }

    return [
        'host' => getenv('DB_HOST') ?: DB_HOST,
        'name' => getenv('DB_NAME') ?: DB_NAME,
        'user' => $user,
        'pass' => $pass,
    ];
}

/**
 * Connects, creating the database if it does not exist yet - so a fresh
 * checkout needs only `php tools/migrate.php` rather than a manual CREATE.
 */
function connect(array $cfg): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = '" . DB_SQL_MODE . "'",
    ];

    $server = new PDO("mysql:host={$cfg['host']};charset=utf8mb4", $cfg['user'], $cfg['pass'], $options);
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        str_replace('`', '', $cfg['name'])));

    return new PDO("mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
        $cfg['user'], $cfg['pass'], $options);
}

/** Creates the migration registry if this is the first run. */
function ensureRegistry(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        Version   INT          NOT NULL PRIMARY KEY,
        Filename  VARCHAR(190) NOT NULL,
        Checksum  CHAR(64)     NOT NULL,
        AppliedAt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        AppliedBy VARCHAR(120) NOT NULL DEFAULT ""
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** @return array<int, array{Version:int, Filename:string, Checksum:string}> keyed by version */
function appliedVersions(PDO $pdo): array
{
    $rows = $pdo->query('SELECT Version, Filename, Checksum FROM schema_migrations')->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[(int) $r['Version']] = $r;
    return $out;
}

/**
 * Migration files on disk, ordered by version.
 * @return array<int, array{version:int, file:string, path:string}>
 */
function availableMigrations(): array
{
    $files = glob(MIGRATIONS_DIR . '/*.sql') ?: [];
    $out = [];

    foreach ($files as $path) {
        $file = basename($path);
        if (!preg_match('/^(\d+)_/', $file, $m)) {
            throw new RuntimeException(
                "Migration '$file' is not named NNNN_description.sql - rename it.");
        }
        $version = (int) $m[1];

        if (isset($out[$version])) {
            throw new RuntimeException(
                "Two migrations share version $version: {$out[$version]['file']} and $file.");
        }
        $out[$version] = ['version' => $version, 'file' => $file, 'path' => $path];
    }

    ksort($out);
    return array_values($out);
}

/**
 * An applied migration whose file has since changed is a silent divergence
 * between this database and every other one. Report it loudly.
 */
function verifyChecksums(array $available, array $applied): void
{
    $drifted = [];

    foreach ($available as $m) {
        if (!isset($applied[$m['version']])) continue;
        if (MigrationFile::checksum(fileContents($m)) !== $applied[$m['version']]['Checksum']) {
            $drifted[] = $m['file'];
        }
    }

    if ($drifted) {
        throw new RuntimeException(
            "These migrations were edited after being applied:\n    - "
            . implode("\n    - ", $drifted)
            . "\n\n  Applied migrations are immutable. Revert the edit and add a new\n"
            . '  migration with the change instead.');
    }
}

function fileContents(array $migration): string
{
    $sql = file_get_contents($migration['path']);
    if ($sql === false) throw new RuntimeException("Cannot read {$migration['file']}.");
    return $sql;
}

/** Runs one migration and records it. */
function apply(PDO $pdo, array $migration): void
{
    $sql = fileContents($migration);
    $statements = StatementSplitter::split($sql);

    say(sprintf('  applying  %s  (%d statements)', $migration['file'], count($statements)));

    foreach ($statements as $i => $statement) {
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf(
                "%s failed at statement %d:\n\n    %s\n\n  %s\n\n" .
                "  The database may be partially migrated - DDL cannot be rolled back.",
                $migration['file'], $i + 1,
                trim(substr($statement, 0, 300)), $e->getMessage()));
        }
    }

    $insert = $pdo->prepare(
        'INSERT INTO schema_migrations (Version, Filename, Checksum, AppliedBy)
         VALUES (?, ?, ?, ?)');
    $insert->execute([
        $migration['version'],
        $migration['file'],
        MigrationFile::checksum($sql),
        get_current_user() ?: 'cli',
    ]);
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}
