<?php
/**
 * ============================================================================
 * SettingsRepo - The Settings key/value table.
 *
 * Deliberately unscoped, like ReferenceRepo. Settings are one row per
 * configuration key for the whole installation - the office letterhead, the
 * watermark, MaxEmployeesPerPayroll - not per-office data. There is no
 * dimension in ScopeEntity that could restrict them and nothing here that
 * belongs to one office rather than another.
 *
 * Write access is a different question and is answered where it belongs, by
 * the settings.manage permission on the route.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class SettingsRepo
{
    /**
     * Every setting as key => value.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $map = [];
        foreach (DB::rows('SELECT KeyName, Value FROM Settings') as $r) {
            $map[$r['KeyName']] = $r['Value'];
        }
        return $map;
    }

    /** Upsert of one setting. */
    public static function put(string $key, string $value): void
    {
        DB::exec('INSERT INTO Settings (KeyName, Value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE Value = VALUES(Value)', [$key, $value]);
    }
}
