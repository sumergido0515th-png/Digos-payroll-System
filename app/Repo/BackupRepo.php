<?php
/**
 * ============================================================================
 * BackupRepo - The SQL dump/restore pair and the Backup registry.
 *
 * The only place in the codebase that legitimately holds a raw PDO handle.
 * A dump has to enumerate whole tables and quote arbitrary values, and a
 * restore has to execute statements it did not compose - neither is a
 * prepared statement and neither can be. Confining that to one class is the
 * point: nothing else needs DB::pdo(), so nothing else has it.
 *
 * Unscoped, and necessarily so. A backup is the whole database or it is not
 * a backup; scoping it would produce a file that restores to a subset and
 * silently deletes the rest. Access is gated by the settings.manage
 * permission on the route instead.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use RuntimeException;

final class BackupRepo
{
    /**
     * Dumps every table in $tables to $path as DELETE + INSERT statements.
     *
     * Streamed row by row rather than collected into a string: the whole
     * point of a backup is that it still works when the database is large.
     *
     * @param string[] $tables
     */
    public static function dumpTo(string $path, array $tables, string $stamp): void
    {
        $fh = fopen($path, 'wb');
        if (!$fh) throw new RuntimeException('Cannot write backup file. Check php/backups permissions.');

        fwrite($fh, "-- Digos Payroll backup $stamp\nSET FOREIGN_KEY_CHECKS=0;\n");
        $pdo = DB::pdo();
        foreach ($tables as $table) {
            fwrite($fh, "\nDELETE FROM `$table`;\n");
            $st = $pdo->query("SELECT * FROM `$table`");
            while ($row = $st->fetch()) {
                $cols = '`' . implode('`,`', array_keys($row)) . '`';
                $vals = implode(',', array_map(
                    fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
                fwrite($fh, "INSERT INTO `$table` ($cols) VALUES ($vals);\n");
            }
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }

    /** Records a completed dump in the Backup registry. */
    public static function register(string $backupId, string $fileName, string $user, string $type): void
    {
        DB::insert('Backup', [
            'BackupID' => $backupId,
            'FileID' => $fileName,                  // file name doubles as the id
            'FileName' => $fileName,
            'User' => $user ?: 'system',
            'Type' => $type,
        ]);
    }

    /** @return array<int, array<string, mixed>> newest first */
    public static function listAll(): array
    {
        return DB::rows('SELECT * FROM Backup ORDER BY Timestamp DESC');
    }

    public static function find(string $fileId): ?array
    {
        return DB::row('SELECT * FROM Backup WHERE FileID = ?', [$fileId]);
    }

    /**
     * Executes a dump file inside one transaction.
     *
     * Comment lines are stripped before splitting, not after. The old form
     * skipped any chunk that *began* with "--", and the dump's first chunk is
     * the header comment followed by SET FOREIGN_KEY_CHECKS=0 - so the one
     * statement that makes a restore possible was the one statement never run.
     * That was harmless while the schema had no foreign keys. Since 0009 added
     * twenty, a restore would delete Employees with the constraints still live,
     * cascading Contracts and DtrDays away before the inserts that refill them.
     */
    public static function restoreFrom(string $path): void
    {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));

        DB::tx(function () use ($sql) {
            foreach (explode(";\n", (string) $sql) as $statement) {
                $statement = trim($statement);
                if ($statement === '') continue;
                DB::pdo()->exec($statement);
            }
        });
    }
}
