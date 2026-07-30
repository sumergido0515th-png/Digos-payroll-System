<?php
/**
 * ============================================================================
 * ReferenceRepo - Reads of the reference tables a printed form needs.
 *
 * EVERY METHOD HERE IS DELIBERATELY UNSCOPED, which is exactly what the
 * architecture guard exists to stop somebody adding by accident - so the
 * reasoning belongs here rather than in a commit message.
 *
 * These are the period a payroll belongs to, the office it is charged to, the
 * timekeeper named on it, and the Function/PPA label. A caller who has already
 * been allowed to read the payroll header holds all four values in that header;
 * looking up the readable name of a code the caller can already see discloses
 * nothing further. Scoping them would mean an in-scope payroll printing with a
 * blank office name.
 *
 * The scope decision belongs upstream, on the payroll itself - PayrollRepo's
 * findScoped() and detailsScoped(). If a caller reaches these methods for a
 * payroll they may not read, the bug is that they got past that check, not that
 * these are unscoped.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class ReferenceRepo
{
    /** A payroll period by id, or [] when it no longer exists. */
    public static function period(string $periodId): array
    {
        return DB::row('SELECT * FROM PayrollPeriods WHERE PeriodID = ?', [$periodId]) ?? [];
    }

    /** An office by code, or [] . */
    public static function office(string $officeCode): array
    {
        return DB::row('SELECT * FROM Offices WHERE OfficeCode = ?', [$officeCode]) ?? [];
    }

    /** A timekeeper by id, or null when the payroll names none. */
    public static function timekeeper(string $timekeeperId): ?array
    {
        if ($timekeeperId === '') return null;

        return DB::row('SELECT * FROM Timekeepers WHERE TimekeeperID = ?', [$timekeeperId]);
    }

    /**
     * The readable Function/PPA name for a stored value.
     *
     * `Offices` and `Payroll` hold the function as a free string, and what was
     * typed is sometimes the code and sometimes the name - both share one column
     * until Phase 1's collapse onto the code finishes. Matching either is why
     * this takes the same value twice.
     */
    public static function functionName(string $stored): ?string
    {
        $row = DB::row(
            'SELECT FunctionName FROM Functions WHERE FunctionCode = ? OR FunctionName = ? LIMIT 1',
            [$stored, $stored]);

        return $row['FunctionName'] ?? null;
    }
}
