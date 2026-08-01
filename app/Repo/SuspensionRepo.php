<?php
/**
 * ============================================================================
 * SuspensionRepo - Notice of Suspension records.
 *
 * Scoped through the payroll they were raised against, for the same reason
 * PayrollDetails is scoped on ChargedOfficeCode: a suspension is evidence
 * about a payroll, and whoever may read the payroll may read what held it.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class SuspensionRepo
{
    /**
     * Next sequential NS number, e.g. "NS-2026-000001".
     *
     * Mirrors nextPayrollNo() in app/Payroll.php, but against the SUSPENSION
     * series in Counters rather than PAYROLL - the two are independent
     * sequences on purpose. Must be called inside a transaction; the row lock
     * is what stops two suspensions raised in the same instant from claiming
     * the same number.
     */
    public static function nextNsNo(): string
    {
        $year = (int) date('Y');

        DB::exec('INSERT IGNORE INTO Counters (YearNo, Series, LastNo) VALUES (?, ?, 0)',
            [$year, 'SUSPENSION']);
        $last = (int) DB::scalar(
            'SELECT LastNo FROM Counters WHERE YearNo = ? AND Series = ? FOR UPDATE',
            [$year, 'SUSPENSION']);
        $next = $last + 1;
        DB::exec('UPDATE Counters SET LastNo = ? WHERE YearNo = ? AND Series = ?',
            [$next, $year, 'SUSPENSION']);

        return sprintf('NS-%d-%06d', $year, $next);
    }

    /**
     * Suspensions on payrolls the caller may see.
     *
     * @param array<string, mixed> $filters PayrollNo, Status, EmployeeID
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters = []): array
    {
        $scope = ScopeGateway::where($user, 'Payroll', 'h.');

        $sql = 'SELECT s.* FROM Suspensions s
                  JOIN Payroll h ON h.PayrollNo = s.PayrollNo
                 WHERE ' . $scope['sql'];
        $params = $scope['params'];

        foreach (['PayrollNo' => 's.PayrollNo', 'Status' => 's.Status',
                'EmployeeID' => 's.EmployeeID'] as $field => $column) {
            if (!empty($filters[$field])) { $sql .= " AND $column = ?"; $params[] = $filters[$field]; }
        }

        return DB::rows($sql . ' ORDER BY s.RaisedAt DESC', $params);
    }

    /** Open suspensions for one payroll, unscoped - the caller already holds it. */
    public static function openFor(string $payrollNo): array
    {
        return DB::rows(
            "SELECT * FROM Suspensions WHERE PayrollNo = ? AND Status = 'Open' ORDER BY RaisedAt",
            [$payrollNo]);
    }

    public static function find(string $nsNo): ?array
    {
        return DB::row('SELECT * FROM Suspensions WHERE NsNo = ?', [$nsNo]);
    }

    /**
     * Raises one suspension.
     *
     * @param array<string, mixed> $record GroundCode, RuleID, EmployeeID,
     *        Particulars, RequiredAction, Deadline, RaisedBy
     */
    public static function raise(string $nsNo, array $record): void
    {
        DB::insert('Suspensions', array_merge(['NsNo' => $nsNo], $record));
    }

    /** Marks one suspension Settled or Waived. */
    public static function close(string $nsNo, string $status, string $settledBy, string $settlementRef): void
    {
        DB::update('Suspensions', [
            'Status' => $status,
            'SettledBy' => $settledBy,
            'SettledAt' => date('Y-m-d H:i:s'),
            'SettlementRef' => $settlementRef,
        ], 'NsNo', $nsNo);
    }

    /** Employee ids with an open, employee-scoped suspension on this payroll. */
    public static function openEmployeeIds(string $payrollNo): array
    {
        $rows = DB::rows(
            "SELECT DISTINCT EmployeeID FROM Suspensions
              WHERE PayrollNo = ? AND Status = 'Open' AND EmployeeID IS NOT NULL",
            [$payrollNo]);

        return array_map('strval', array_column($rows, 'EmployeeID'));
    }

    /** Whether any OPEN suspension on this payroll names no employee (batch-wide). */
    public static function hasOpenBatchSuspension(string $payrollNo): bool
    {
        return (bool) DB::scalar(
            "SELECT COUNT(*) FROM Suspensions
              WHERE PayrollNo = ? AND Status = 'Open' AND EmployeeID IS NULL",
            [$payrollNo]);
    }

    /** Re-points every suspension on one payroll to another - used by the split. */
    public static function reassignPayroll(string $fromPayrollNo, string $toPayrollNo, array $employeeIds): void
    {
        if (!$employeeIds) return;
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        DB::exec(
            "UPDATE Suspensions SET PayrollNo = ?
              WHERE PayrollNo = ? AND EmployeeID IN ($placeholders)",
            array_merge([$toPayrollNo, $fromPayrollNo], array_values($employeeIds)));
    }
}
