<?php
/**
 * ============================================================================
 * create-app-user.php - Creates least-privilege database accounts and writes
 * the app/config.local.php that uses them.
 *
 *   php tools/create-app-user.php                     create/refresh accounts
 *   php tools/create-app-user.php --show              print grants, change nothing
 *
 * WHY
 * A stock XAMPP install runs the application as root with no password. Any SQL
 * injection, or anyone who reaches the config file, then has DROP rights over
 * every database on the server - including the payroll history this system
 * exists to protect.
 *
 * Two accounts are created instead:
 *
 *   <db>_app    SELECT, INSERT, UPDATE, DELETE on this database only.
 *               What the web application runs as. Cannot DROP or ALTER
 *               anything, so a compromised request cannot destroy the schema.
 *
 *   <db>_migrate  The above plus CREATE, ALTER, DROP, INDEX, REFERENCES.
 *               Used only by tools/migrate.php, from a terminal, by a person.
 *
 * Run as an administrative account (root); the accounts it creates are what
 * everything afterwards uses.
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("create-app-user.php is a command-line tool.\n");
}

require_once dirname(__DIR__) . '/app/config.php';

const LOCAL_CONFIG = __DIR__ . '/../app/config.local.php';

$showOnly = in_array('--show', array_slice($argv, 1), true);

try {
    exit(main($showOnly));
} catch (Throwable $e) {
    fwrite(STDERR, "\n  ERROR  " . $e->getMessage() . "\n\n");
    exit(1);
}

/* ========================================================================== */

function main(bool $showOnly): int
{
    $database = DB_NAME;
    $appUser = substr($database, 0, 20) . '_app';
    $migrateUser = substr($database, 0, 20) . '_migrate';

    $appPass = generatePassword();
    $migratePass = generatePassword();

    if ($showOnly) {
        say('Statements that would run (passwords redacted):');
        say('');
        foreach (grants($database, $appUser, '********', $migrateUser, '********') as $sql) {
            say('  ' . $sql);
        }
        return 0;
    }

    say("connecting as " . DB_USER . '@' . DB_HOST . ' (needs GRANT rights)');
    $pdo = adminConnection();

    // Grants are per-database, so the database has to exist before they can be
    // issued. migrate.php does the same, and CREATE DATABASE IF NOT EXISTS is
    // a no-op on an existing install.
    foreach ([$database, $database . '_test'] as $name) {
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $name));
    }

    if (is_file(LOCAL_CONFIG)) {
        throw new RuntimeException(
            "app/config.local.php already exists.\n"
            . "  Delete it first if you really want to regenerate the credentials -\n"
            . '  overwriting it would lock the application out of its own database.');
    }

    foreach (grants($database, $appUser, $appPass, $migrateUser, $migratePass) as $sql) {
        $pdo->exec($sql);
    }
    say("created  $appUser      SELECT, INSERT, UPDATE, DELETE on $database");
    say("created  $migrateUser  the above plus CREATE, ALTER, DROP, INDEX, REFERENCES");

    writeLocalConfig($appUser, $appPass, $migrateUser, $migratePass);
    say('wrote    app/config.local.php (git-ignored)');

    verify($appUser, $appPass, $database);

    say('');
    say('The application now connects as ' . $appUser . '.');
    say('Run migrations with:');
    say('');
    say('  DB_USER=' . $migrateUser . ' DB_PASS=<see config.local.php> php tools/migrate.php');
    say('');
    say('Keep a copy of app/config.local.php somewhere safe - it is not in git,');
    say('and these passwords are not recoverable from the database.');
    return 0;
}

/** Connects with the current (administrative) credentials. */
function adminConnection(): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * The DDL that creates both accounts.
 *
 * Identifiers and passwords are validated rather than parameterised because
 * MySQL does not accept placeholders in CREATE USER / GRANT.
 */
function grants(string $database, string $appUser, string $appPass,
                string $migrateUser, string $migratePass): array
{
    foreach ([$database, $appUser, $migrateUser] as $identifier) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException("Refusing to build SQL with the identifier '$identifier'.");
        }
    }
    foreach ([$appPass, $migratePass] as $password) {
        if (str_contains($password, "'") || str_contains($password, '\\')) {
            throw new RuntimeException('Generated password contains a quote - regenerate.');
        }
    }

    $host = 'localhost';
    $test = $database . '_test';

    return [
        "CREATE USER IF NOT EXISTS '$appUser'@'$host' IDENTIFIED BY '$appPass'",
        "ALTER USER '$appUser'@'$host' IDENTIFIED BY '$appPass'",
        "GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.* TO '$appUser'@'$host'",

        "CREATE USER IF NOT EXISTS '$migrateUser'@'$host' IDENTIFIED BY '$migratePass'",
        "ALTER USER '$migrateUser'@'$host' IDENTIFIED BY '$migratePass'",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
           ON `$database`.* TO '$migrateUser'@'$host'",

        // The integration suite runs against <db>_test and needs to create and
        // drop tables there. Granting DDL on the test database only keeps the
        // production grant narrow while leaving `composer test` working.
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
           ON `$test`.* TO '$appUser'@'$host'",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
           ON `$test`.* TO '$migrateUser'@'$host'",

        'FLUSH PRIVILEGES',
    ];
}

/** Password from a character set that never needs SQL or shell escaping. */
function generatePassword(int $length = 32): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function writeLocalConfig(string $appUser, string $appPass,
                          string $migrateUser, string $migratePass): void
{
    $generated = date('Y-m-d H:i');

    $php = <<<PHP
    <?php
    /**
     * config.local.php - Machine-local overrides. NOT in version control.
     *
     * Generated by tools/create-app-user.php on $generated.
     * app/config.php requires this file first; anything defined here wins.
     */

    declare(strict_types=1);

    // The web application. SELECT/INSERT/UPDATE/DELETE only - it cannot alter
    // or drop the schema.
    define('DB_USER', '$appUser');
    define('DB_PASS', '$appPass');

    // For tools/migrate.php only. Pass it explicitly, from a terminal:
    //   DB_USER=$migrateUser DB_PASS=$migratePass php tools/migrate.php
    // Kept here so the credential is not lost, NOT used by the application.
    define('DB_MIGRATE_USER', '$migrateUser');
    define('DB_MIGRATE_PASS', '$migratePass');

    PHP;

    // Heredoc indentation is stripped by PHP 7.3+; normalise line endings to
    // match the rest of the tree.
    $php = str_replace("\n", "\r\n", $php);

    if (file_put_contents(LOCAL_CONFIG, $php) === false) {
        throw new RuntimeException('Could not write app/config.local.php.');
    }
    @chmod(LOCAL_CONFIG, 0600);
}

/** Proves the new account can read, and cannot drop. */
function verify(string $appUser, string $appPass, string $database): void
{
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ";dbname=$database;charset=utf8mb4",
        $appUser,
        $appPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->query('SELECT 1');
    say('verified ' . $appUser . ' can connect and read');

    try {
        $pdo->exec('CREATE TABLE _privilege_probe (id INT)');
        $pdo->exec('DROP TABLE _privilege_probe');
        throw new RuntimeException(
            "$appUser was able to CREATE and DROP a table - the grant is too wide.");
    } catch (PDOException) {
        say('verified ' . $appUser . ' cannot create or drop tables');
    }
}

function say(string $line): void
{
    fwrite(STDOUT, $line . "\n");
}
