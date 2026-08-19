<?php
/**
 * ============================================================================
 * MigrationFile - Identity of a migration file.
 *
 * Pure: string in, string out.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Migration;

final class MigrationFile
{
    /**
     * Checksum used to detect a migration edited after it was applied.
     *
     * Line endings are normalised before hashing. Git is configured to convert
     * them on checkout (core.autocrlf), so the same committed migration has
     * CRLF in a Windows working copy and LF in a Linux one. Hashing raw bytes
     * would make a database migrated from XAMPP look tampered with the moment
     * anyone ran the migrator from WSL or CI against it - a false alarm that
     * blocks all further migrations.
     *
     * A trailing newline is likewise insignificant and is ignored.
     */
    public static function checksum(string $sql): string
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $sql);

        return hash('sha256', rtrim($normalised, "\n"));
    }
}
