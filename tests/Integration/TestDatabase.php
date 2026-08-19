<?php
/**
 * ============================================================================
 * TestDatabase - Connection helper for the integration suite.
 *
 * Refuses to connect to anything that does not look like a test database, so
 * a mis-set DB_NAME cannot point the suite at live payroll data.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use PDO;
use RuntimeException;

final class TestDatabase
{
    private static ?PDO $pdo = null;

    /** Connection settings, environment first then app/config.php defaults. */
    public static function config(): array
    {
        require_once PROJECT_ROOT . '/app/config.php';

        return [
            'host' => getenv('DB_HOST') ?: DB_HOST,
            'name' => getenv('DB_NAME') ?: DB_NAME,
            'user' => getenv('DB_USER') ?: DB_USER,
            'pass' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : DB_PASS,
        ];
    }

    /**
     * Shared connection to the test database.
     * @throws RuntimeException when the target is not a test database.
     */
    public static function connect(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $cfg = self::config();
        self::guardTargetIsATestDatabase($cfg['name']);

        return self::$pdo = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
            $cfg['user'],
            $cfg['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = '" . DB_SQL_MODE . "'",
            ]);
    }

    /**
     * True when a test database is configured and reachable.
     *
     * A database that is merely absent or unreachable is a skip - developers
     * without MySQL running can still work on the unit suite. Being pointed
     * at a NON-test database is not a skip: that is a misconfiguration which
     * would otherwise let the whole integration suite pass without running,
     * so the guard exception is deliberately allowed to propagate.
     */
    public static function isAvailable(): bool
    {
        self::guardTargetIsATestDatabase(self::config()['name']);

        try {
            self::connect()->query('SELECT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Integration tests write to and truncate their target. Pointing them at
     * the production database would destroy payroll records, so the name must
     * opt in by carrying a test marker.
     */
    private static function guardTargetIsATestDatabase(string $name): void
    {
        if (!preg_match('/(^|_)test(_|$)/i', $name)) {
            throw new RuntimeException(
                "Refusing to run integration tests against database '$name'.\n" .
                "The name must contain 'test' (e.g. digos_payroll_test). Set DB_NAME.");
        }
    }
}
