<?php
/**
 * ============================================================================
 * DtrRepo - Day-level timekeeping.
 *
 * Scoped through a join to Employees, like the other per-person documents: a
 * DTR row is about a person, so its scope is that person's.
 *
 * The unique key is (EmployeeID, WorkDate), so a save is an upsert. That is the
 * right shape for a grid the timekeeper fills in over several sittings - the
 * alternative, delete-then-insert per period, would lose a biometric row every
 * time somebody corrected a single day by hand.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class DtrRepo
{
    /**
     * The columns a day row carries, as the application writes them.
     *
     * Listed rather than SELECT *'d because MigrationColumnsAreUsedTest reads
     * this file to decide whether 0004's columns are actually used - and the
     * failure it guards against is exactly what happened to DtrDays, which was
     * created in Phase 1 and had no writer until now.
     */
    private const WRITABLE = ['EmployeeID', 'WorkDate', 'PeriodID',
        'TimeIn1', 'TimeOut1', 'TimeIn2', 'TimeOut2',
        'HoursWorked', 'OvertimeHours', 'LateMinutes', 'UndertimeMinutes',
        'IsAbsent', 'DayType', 'Source', 'Remarks'];

    /**
     * One employee's days in a period, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function daysForEmployeeScoped(
        array $user,
        string $employeeId,
        string $periodId
    ): array {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::rows(
            'SELECT d.DtrDayID, d.EmployeeID, d.WorkDate, d.PeriodID,
                    d.TimeIn1, d.TimeOut1, d.TimeIn2, d.TimeOut2,
                    d.HoursWorked, d.OvertimeHours, d.LateMinutes, d.UndertimeMinutes,
                    d.IsAbsent, d.DayType, d.Source, d.Remarks, d.CreatedAt
               FROM DtrDays d
               JOIN Employees e ON e.EmployeeID = d.EmployeeID
              WHERE ' . $scope['sql'] . ' AND d.EmployeeID = ? AND d.PeriodID = ?
              ORDER BY d.WorkDate',
            array_merge($scope['params'], [$employeeId, $periodId]));
    }

    /**
     * Every day row in a period the caller may see.
     *
     * The grid loads one period at a time, so this is one query rather than one
     * per employee.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function daysForPeriodScoped(array $user, string $periodId): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::rows(
            'SELECT d.DtrDayID, d.EmployeeID, d.WorkDate, d.PeriodID,
                    d.TimeIn1, d.TimeOut1, d.TimeIn2, d.TimeOut2,
                    d.HoursWorked, d.OvertimeHours, d.LateMinutes, d.UndertimeMinutes,
                    d.IsAbsent, d.DayType, d.Source, d.Remarks, d.CreatedAt
               FROM DtrDays d
               JOIN Employees e ON e.EmployeeID = d.EmployeeID
              WHERE ' . $scope['sql'] . ' AND d.PeriodID = ?
              ORDER BY e.LastName, e.FirstName, d.WorkDate',
            array_merge($scope['params'], [$periodId]));
    }

    /**
     * Day rows for the given employees in a period, for the rule engine.
     *
     * Unscoped on purpose and not reachable from a route, like
     * ContractRepo::inForceOn(): this answers a question the system asks while
     * checking a payroll the caller has already been shown.
     *
     * @param string[] $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public static function daysForPeriod(string $periodId, array $employeeIds = []): array
    {
        $sql = 'SELECT * FROM DtrDays WHERE PeriodID = ?';
        $params = [$periodId];

        if ($employeeIds) {
            $sql .= ' AND EmployeeID IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            $params = array_merge($params, array_values($employeeIds));
        }

        return DB::rows($sql . ' ORDER BY EmployeeID, WorkDate', $params);
    }

    /**
     * Writes one day, replacing whatever was there for that employee and date.
     *
     * @param array<string, mixed> $record
     */
    public static function upsertDay(string $dtrDayId, array $record): void
    {
        $record = array_intersect_key($record, array_flip(self::WRITABLE));

        $columns = array_keys($record);
        $sql = 'INSERT INTO `DtrDays` (`DtrDayID`,`' . implode('`,`', $columns) . '`) VALUES ('
            . implode(',', array_fill(0, count($columns) + 1, '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE '
            . implode(',', array_map(fn(string $c) => "`$c` = VALUES(`$c`)", $columns));

        DB::exec($sql, array_merge([$dtrDayId], array_values($record)));
    }

    /** Removes one day row. */
    public static function deleteDay(string $employeeId, string $workDate): int
    {
        return DB::exec('DELETE FROM DtrDays WHERE EmployeeID = ? AND WorkDate = ?',
            [$employeeId, $workDate]);
    }

    /** The id already stored for an employee and date, or '' when new. */
    public static function existingDayId(string $employeeId, string $workDate): string
    {
        return (string) DB::scalar(
            'SELECT DtrDayID FROM DtrDays WHERE EmployeeID = ? AND WorkDate = ?',
            [$employeeId, $workDate]);
    }

    /**
     * How many day rows a period has, and how many of them were keyed by hand.
     *
     * The split is the point: a period whose rows are all manual has had no
     * biometric import, and Phase 6 treats those differently.
     *
     * @return array{days: int, manual: int, employees: int}
     */
    public static function periodSummaryScoped(array $user, string $periodId): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $row = DB::row(
            "SELECT COUNT(*) AS days,
                    SUM(CASE WHEN d.Source = 'Manual' THEN 1 ELSE 0 END) AS manual,
                    COUNT(DISTINCT d.EmployeeID) AS employees
               FROM DtrDays d
               JOIN Employees e ON e.EmployeeID = d.EmployeeID
              WHERE " . $scope['sql'] . ' AND d.PeriodID = ?',
            array_merge($scope['params'], [$periodId]));

        return [
            'days' => (int) ($row['days'] ?? 0),
            'manual' => (int) ($row['manual'] ?? 0),
            'employees' => (int) ($row['employees'] ?? 0),
        ];
    }
}
