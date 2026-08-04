<?php
/**
 * ============================================================================
 * Database.php - Thin PDO wrapper. Every query is a prepared statement.
 * ============================================================================
 */

declare(strict_types=1);

final class DB
{
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;

    /** Returns the shared PDO connection (lazy, exceptions on). */
    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // Rejects out-of-range and mistyped values instead of
                    // silently coercing them - see DB_SQL_MODE in config.php.
                    PDO::MYSQL_ATTR_INIT_COMMAND =>
                        "SET SESSION sql_mode = '" . DB_SQL_MODE . "'",
                ]
            );
        }
        return self::$pdo;
    }

    /** Runs a SELECT and returns every row. */
    public static function rows(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Runs a SELECT and returns the first row or null. */
    public static function row(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** Runs a SELECT and returns the first column of the first row. */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchColumn();
    }

    /** Runs an INSERT/UPDATE/DELETE; returns affected row count. */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Inserts one associative record into a table. */
    public static function insert(string $table, array $record): void
    {
        $cols = array_keys($record);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::exec($sql, array_values($record));
    }

    /** Updates the fields of $patch on rows where $keyCol = $keyVal. */
    public static function update(string $table, array $patch, string $keyCol, mixed $keyVal): int
    {
        if (!$patch) return 0;
        $sets = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($patch)));
        $params = array_values($patch);
        $params[] = $keyVal;
        return self::exec("UPDATE `$table` SET $sets WHERE `$keyCol` = ?", $params);
    }

    /**
     * Runs a callback inside a transaction (commit on success, rollback on
     * throw). Reentrant: a call made while one is already open joins it rather
     * than opening a second - PDO has no nested transactions, and
     * beginTransaction() on top of an open one throws "There is already an
     * active transaction". The outer call alone owns begin/commit/rollback; an
     * exception from an inner call simply propagates to it, which is exactly
     * the all-or-nothing behaviour an outer transaction is for. Repositories
     * such as EmployeeRepo::save() call tx() on their own so a direct save
     * still gets one, and this is what lets ImportRepo wrap a whole batch of
     * those same saves in a single transaction without either side knowing
     * about the other.
     */
    public static function tx(callable $fn): mixed
    {
        if (self::$txDepth > 0) {
            self::$txDepth++;
            try {
                return $fn();
            } finally {
                self::$txDepth--;
            }
        }

        $pdo = self::pdo();
        $pdo->beginTransaction();
        self::$txDepth = 1;
        try {
            $out = $fn();
            $pdo->commit();
            return $out;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        } finally {
            self::$txDepth = 0;
        }
    }
}
