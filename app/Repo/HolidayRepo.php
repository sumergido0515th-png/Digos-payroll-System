<?php
/**
 * ============================================================================
 * HolidayRepo - The calendar and the pay-rule table.
 *
 * UNSCOPED, deliberately, like ReferenceRepo and WorkShiftRepo. A national
 * holiday is not any office's row, and a pay rule is policy. Scoping either
 * would mean an office-scoped user resolving a date differently from the
 * pre-auditor checking their payroll, which is worse than useless.
 *
 * The declarations ARE scoped in a different sense - a City row applies to one
 * city - but that is a property of the row read by HolidayResolver, not an
 * access-control question. Everyone may read the whole calendar; only some
 * rows apply to them.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class HolidayRepo
{
    /**
     * Declarations, newest first.
     *
     * @param array<string, mixed> $filters Year, DayType, ScopeLevel, search
     * @return array<int, array<string, mixed>>
     */
    public static function listHolidays(array $filters = []): array
    {
        $sql = 'SELECT HolidayID, HolidayDate, HolidayName, DayType, ScopeLevel, ScopeCode,
                       StartTime, EndTime, LegalBasis, Status, Remarks, CreatedBy, CreatedAt
                  FROM Holidays WHERE 1 = 1';
        $params = [];

        if (!empty($filters['Year'])) {
            $sql .= ' AND YEAR(HolidayDate) = ?'; $params[] = (int) $filters['Year'];
        }
        foreach (['DayType', 'ScopeLevel', 'Status'] as $f) {
            if (!empty($filters[$f])) { $sql .= " AND `$f` = ?"; $params[] = $filters[$f]; }
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (HolidayName LIKE ? OR LegalBasis LIKE ? OR ScopeCode LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return DB::rows($sql . ' ORDER BY HolidayDate DESC, ScopeLevel', $params);
    }

    /**
     * Every declaration touching a date range.
     *
     * The whole range in one query, because the resolvers take the calendar as
     * an argument: a per-date lookup would issue fifteen queries to resolve a
     * fortnight and each would be answered from the same handful of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function holidaysBetween(string $start, string $end): array
    {
        return DB::rows(
            'SELECT * FROM Holidays
              WHERE HolidayDate BETWEEN ? AND ? AND Status = ?
              ORDER BY HolidayDate',
            [$start, $end, 'Active']);
    }

    public static function findHoliday(string $holidayId): ?array
    {
        return DB::row('SELECT * FROM Holidays WHERE HolidayID = ?', [$holidayId]);
    }

    /** Whether the same declaration already exists for that date and scope. */
    public static function declarationExists(
        string $date,
        string $scopeLevel,
        ?string $scopeCode,
        string $dayType,
        string $exceptId = ''
    ): bool {
        return (bool) DB::scalar(
            'SELECT COUNT(*) FROM Holidays
              WHERE HolidayDate = ? AND ScopeLevel = ? AND DayType = ?
                AND (ScopeCode <=> ?) AND HolidayID <> ?',
            [$date, $scopeLevel, $dayType, $scopeCode, $exceptId]);
    }

    /** @param array<string, mixed> $record */
    public static function saveHoliday(string $holidayId, array $record, bool $isNew): void
    {
        if ($isNew) {
            DB::insert('Holidays', array_merge(['HolidayID' => $holidayId], $record));
            return;
        }
        DB::update('Holidays', $record, 'HolidayID', $holidayId);
    }

    public static function deleteHoliday(string $holidayId): int
    {
        return DB::exec('DELETE FROM Holidays WHERE HolidayID = ?', [$holidayId]);
    }

    /* ====================================================================== */

    /**
     * Every pay-rule version.
     *
     * All of them, not just the ones in force: the resolver picks the version
     * effective on the date being resolved, and filtering to "current" here
     * would make a re-check of last year's payroll silently use this year's
     * policy.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function payRules(): array
    {
        return DB::rows(
            'SELECT RuleID, DayType, EmploymentTypeCode, Worked, Paid, Multiplier,
                    LegalBasis, EffectiveFrom, EffectiveTo, Notes, CreatedAt
               FROM HolidayPayRules
              ORDER BY DayType, EmploymentTypeCode, Worked, EffectiveFrom');
    }

    /** @param array<string, mixed> $record */
    public static function savePayRule(string $ruleId, array $record, bool $isNew): void
    {
        if ($isNew) {
            DB::insert('HolidayPayRules', array_merge(['RuleID' => $ruleId], $record));
            return;
        }

        // Rate, day type and effectivity are what versioning exists to
        // preserve. A correction adds a version; only the annotation is
        // editable in place, for the same reason ContractRepo::amend() is
        // narrow.
        DB::update('HolidayPayRules',
            array_intersect_key($record, array_flip(['Notes', 'EffectiveTo'])),
            'RuleID', $ruleId);
    }
}
