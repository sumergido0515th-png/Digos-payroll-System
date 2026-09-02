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
use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;

final class PayrollRepo
{
    /**
     * Payroll headers this user may see, narrowed by the facets they asked for.
     *
     * THE COMPOSITION IS THE POINT. Scope and filters meet here and only here,
     * always in this order and always with AND between them, so a filter can
     * narrow what the scope permitted and can never widen it. That is what
     * lets FilterSpec accept a filter naming another office - it produces a
     * query that returns nothing, rather than a refusal that would confirm the
     * office exists.
     *
     * @param array<string, mixed> $user the requireUser() row
     * @param array<string, mixed> $payload the API payload
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $user, array $payload): array
    {
        $scope = ScopeGateway::where($user, 'Payroll');
        $spec = FilterSpec::fromPayload('Payroll', $payload);
        $filter = FilterSql::build($spec);

        return DB::rows(
            'SELECT * FROM Payroll
              WHERE ' . $scope['sql'] . ' AND ' . $filter['sql'] . '
              ORDER BY ' . FilterSql::orderBy($spec),
            array_merge($scope['params'], $filter['params']));
    }

    /**
     * The choices each dropdown may offer, built from rows already in scope.
     *
     * A facet list is a query result like any other, and the one most easily
     * forgotten: a dropdown naming every office discloses the org chart before
     * a single row is fetched. Deriving the options from the scoped table
     * makes that structurally impossible - an option can only appear because
     * the caller can already read a row carrying it.
     *
     * One query per column rather than a UNION: the columns are few, they are
     * indexed differently, and a UNION would have to cast them to a common
     * type, which is how a code with a leading zero stops matching itself.
     *
     * @return array<string, array<int, string>> facet key => sorted values
     */
    public static function facetOptionsScoped(array $user): array
    {
        return FacetOptions::build(
            'Payroll', 'Payroll', ScopeGateway::where($user, 'Payroll'));
    }

    /**
     * Totals by office, across every office - never scoped.
     *
     * The one deliberate citywide read on this table, gated entirely by the
     * `aggregate.citywide` permission checked before this is ever called -
     * the same shape `apiGetLogs` already is, and for the same reason: a
     * citywide total is what the permission exists to grant, so there is
     * nothing left for a scope predicate to narrow. Still composed with
     * FilterSpec/FilterSql the ordinary way, so a caller can narrow the
     * aggregate to one period the same way they would narrow the list - the
     * WHERE clause is real, only the scope half of it is deliberately absent.
     *
     * @param array<string, mixed> $payload Payroll's own facets, e.g. PeriodID
     * @return array<int, array<string, mixed>> OfficeCode, PayrollCount, TotalGross, TotalNet
     */
    public static function citywideTotals(array $payload = []): array
    {
        $spec = FilterSpec::fromPayload('Payroll', $payload);
        $filter = FilterSql::build($spec);

        return DB::rows(
            'SELECT OfficeCode,
                    COUNT(*) AS PayrollCount,
                    COALESCE(SUM(TotalGross), 0) AS TotalGross,
                    COALESCE(SUM(TotalNet), 0) AS TotalNet
               FROM Payroll
              WHERE ' . $filter['sql'] . '
              GROUP BY OfficeCode
              ORDER BY OfficeCode',
            $filter['params']);
    }

    /**
     * Payroll headers this user may see.
     *
     * Kept as the name the modules already call. It is search() - the filters
     * it used to build by hand are now facets in FilterSpec, which is what
     * lets the same set be reached from a URL, an export and a dashboard
     * without three copies of this WHERE clause drifting apart.
     *
     * @param array<string, mixed> $user the requireUser() row
     * @param array<string, mixed> $filters the API payload
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters): array
    {
        return self::search($user, $filters);
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
     * Every line of a payroll, regardless of the caller's scope over the
     * charged office.
     *
     * NAMED PATH, LIKE EmployeeRepo::findForComputation(). Phase 8's payload
     * hash represents what the payroll IS, not what one particular caller's
     * grants happen to expose of it - detailsScoped() filters lines by
     * ChargedOfficeCode, so a payroll split across two offices would hash
     * differently depending on whether the approver's or the printer's scope
     * ran the computation, which is a false tamper alarm on every such
     * payroll rather than a real one.
     *
     * Safe here for the same reason it is safe there: the header is already
     * scope-checked via findScoped() before either caller (approval, print)
     * ever reaches this method, so an unscoped line read at this specific,
     * already-gated point discloses nothing a direct query elsewhere would
     * not have.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function detailsUnscoped(string $payrollNo): array
    {
        return DB::rows(
            'SELECT * FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo',
            [$payrollNo]);
    }

    /**
     * Employees appearing on another non-cancelled payroll over these dates.
     *
     * DELIBERATELY UNSCOPED, and the only method here that is. An employee paid
     * twice by two different offices is precisely the case this exists to
     * catch, and a scoped query would answer "no clash" to the one reader who
     * most needs to know - because the other payroll is the one they cannot
     * see.
     *
     * The disclosure that would follow is prevented by the CALLER, in
     * redactedOverlaps(): it keeps the employee, whom the reader can already
     * see on the payroll in front of them, and drops the other payroll's
     * number and office. Splitting it this way keeps the elevated query in one
     * named place instead of leaving a scoped-looking method that quietly is
     * not.
     *
     * @param string[] $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public static function employeesOnOverlappingPayrolls(
        string $exceptPayrollNo,
        array $employeeIds,
        string $start,
        string $end
    ): array {
        if (!$employeeIds) return [];

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        return DB::rows(
            "SELECT DISTINCT pd.EmployeeID, pd.EmployeeName
               FROM PayrollDetails pd
               JOIN Payroll h ON h.PayrollNo = pd.PayrollNo
               JOIN PayrollPeriods p ON p.PeriodID = h.PeriodID
              WHERE pd.PayrollNo <> ?
                AND h.Status <> 'CANCELLED'
                AND pd.EmployeeID IN ($placeholders)
                AND p.StartDate <= ? AND p.EndDate >= ?
              ORDER BY pd.EmployeeName",
            array_merge([$exceptPayrollNo], array_values($employeeIds), [$end, $start]));
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
              WHERE " . $scope['sql'] . " AND h.Status <> 'CANCELLED'
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

        $sql = "SELECT * FROM Payroll WHERE " . $scope['sql'] . " AND Status <> 'CANCELLED'";
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
