<?php
/**
 * ============================================================================
 * PayrollRepo - Scoped reads of payroll headers and lines.
 *
 * Header and line are scoped separately and on different columns. A payroll's
 * OfficeCode is the batch's owning office; a line's ChargedOfficeCode is which
 * appropriation pays for that person, and migration 0006 made it first-class
 * precisely because the two differ - a detail to another office, work funded by
 * a different function. Scoping the lines by their header would hide that
 * difference again, and it is the difference Phase 6's "payroll contains lines
 * outside preparer's scope" rule is looking for.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class PayrollRepo
{
    /**
     * Payroll headers this user may see.
     *
     * @param array<string, mixed> $user the requireUser() row
     * @param array<string, mixed> $filters the API payload
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters): array
    {
        $scope = ScopeGateway::where($user, 'Payroll');

        $sql = 'SELECT * FROM Payroll WHERE ' . $scope['sql'];
        $params = $scope['params'];

        foreach (['PeriodID', 'OfficeCode', 'Department', 'Status'] as $f) {
            if (!empty($filters[$f])) { $sql .= " AND `$f` = ?"; $params[] = $filters[$f]; }
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (PayrollNo LIKE ? OR OfficeCode LIKE ? OR Department LIKE ?'
                . ' OR PreparedBy LIKE ? OR Remarks LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY DateCreated DESC';

        return DB::rows($sql, $params);
    }

    /**
     * One payroll header, or null when it does not exist or is out of scope.
     *
     * The two cases return the same thing on purpose. Distinguishing "no such
     * payroll" from "not yours" tells a caller that a payroll they cannot see
     * exists, which is itself the cross-office disclosure this layer prevents.
     */
    public static function findScoped(array $user, string $payrollNo): ?array
    {
        $scope = ScopeGateway::where($user, 'Payroll');

        return DB::row(
            'SELECT * FROM Payroll WHERE ' . $scope['sql'] . ' AND PayrollNo = ?',
            array_merge($scope['params'], [$payrollNo]));
    }

    /**
     * The lines of a payroll the user may see, themselves scoped by charge.
     *
     * A user who may read the header still only sees the lines charged within
     * their own scope - which is what makes a payroll split across two offices
     * readable by both without either seeing the other's people.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function detailsScoped(array $user, string $payrollNo): array
    {
        $scope = ScopeGateway::where($user, 'PayrollDetails');

        return DB::rows(
            'SELECT * FROM PayrollDetails
              WHERE ' . $scope['sql'] . ' AND PayrollNo = ?
              ORDER BY LineNo',
            array_merge($scope['params'], [$payrollNo]));
    }

    /**
     * Gross and net per period-month, newest first, within scope.
     *
     * The dashboard's chart. Aggregated in SQL rather than in PHP because the
     * GROUP BY is the point - but the scope predicate goes in the same WHERE as
     * every other read, so an office-scoped user's chart is their own office's
     * money and not the city's.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function monthlyTotalsScoped(array $user, int $limit = 12): array
    {
        $scope = ScopeGateway::where($user, 'Payroll', 'h.');

        return DB::rows(
            "SELECT pd.PayrollYear, pd.PayrollMonth, MIN(pd.StartDate) AS SortDate,
                    SUM(h.TotalGross) AS gross, SUM(h.TotalNet) AS net, COUNT(*) AS count
               FROM Payroll h
               JOIN PayrollPeriods pd ON pd.PeriodID = h.PeriodID
              WHERE " . $scope['sql'] . " AND h.Status <> 'Cancelled'
              GROUP BY pd.PayrollYear, pd.PayrollMonth
              ORDER BY SortDate DESC
              LIMIT " . max(1, $limit),
            $scope['params']);
    }

    /**
     * Non-cancelled headers within scope, for the report engine.
     *
     * Separate from listScoped() because reporting excludes Cancelled by rule
     * rather than by filter, and takes no free-text search.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forReportingScoped(array $user, array $filters): array
    {
        $scope = ScopeGateway::where($user, 'Payroll');

        $sql = "SELECT * FROM Payroll WHERE " . $scope['sql'] . " AND Status <> 'Cancelled'";
        $params = $scope['params'];

        foreach (['PeriodID', 'OfficeCode', 'Department'] as $f) {
            if (!empty($filters[$f])) { $sql .= " AND `$f` = ?"; $params[] = $filters[$f]; }
        }
        return DB::rows($sql, $params);
    }

    /**
     * Detail lines of the given payrolls, themselves scoped by charged office.
     *
     * Scoped a second time on purpose: a user who may read a payroll header
     * still only reports on the lines charged within their own scope, which is
     * what lets one payroll spanning two offices be reported by both without
     * either seeing the other's people.
     *
     * @param string[] $payrollNos
     * @return array<int, array<string, mixed>>
     */
    public static function detailsForReportingScoped(
        array $user,
        array $payrollNos,
        string $employeeId = ''
    ): array {
        if (!$payrollNos) return [];

        $scope = ScopeGateway::where($user, 'PayrollDetails');
        $ph = implode(',', array_fill(0, count($payrollNos), '?'));

        $sql = 'SELECT * FROM PayrollDetails
                 WHERE ' . $scope['sql'] . " AND PayrollNo IN ($ph)";
        $params = array_merge($scope['params'], array_values($payrollNos));

        if ($employeeId !== '') { $sql .= ' AND EmployeeID = ?'; $params[] = $employeeId; }

        return DB::rows($sql . ' ORDER BY PayrollNo, LineNo', $params);
    }

}
