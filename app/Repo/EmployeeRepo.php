<?php
/**
 * ============================================================================
 * EmployeeRepo - Reads and writes across the two employee tiers.
 *
 * Migration 0015 split Employees into a directory tier and EmployeeSensitive
 * (rates, TIN, GSIS, PhilHealth, Pag-IBIG, CashCard, personal data). Every
 * read here returns Tier 1 alone unless the caller is explicitly entitled to
 * more - which is the whole reason the columns were moved, rather than left in
 * one table behind a filter that one forgotten SELECT * would undo.
 *
 * Two callers legitimately need Tier 2 and get named paths rather than
 * exceptions to the rule: the payroll engine, which computes from DailyRate
 * and HourlyRate, and the payslip mailer, which needs the employee's address.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;

final class EmployeeRepo
{
    /** The permission entitling a caller to read the restricted tier. */
    public const SENSITIVE_PERMISSION = 'employee.sensitive';

    /**
     * Columns of the restricted tier, in the order SCHEMA.md classifies them.
     *
     * Hardcoded, and interpolated into SQL only from here. Reading them from
     * anywhere a payload could reach would put column selection in the hands of
     * the caller, which is the one thing the tier split exists to prevent.
     */
    public const SENSITIVE_COLUMNS = [
        'TIN', 'GSIS', 'PhilHealth', 'PagIBIG', 'CashCard',
        'Birthdate', 'Gender', 'Address', 'Contact', 'Email',
        'SalaryRate', 'DailyRate', 'HourlyRate', 'MonthlyRate',
        'SSSDeductionApproved', 'BIRTaxPercent',
    ];

    // The two search-column lists moved to FilterSpec in 9B, as the
    // 'Employees' and 'EmployeesSensitive' facet maps. Which of the two this
    // repository asks for is still decided here, in search(), because that is
    // the permission question and FilterSpec never sees $user.

    /**
     * Employees this user may see, filtered and searched.
     *
     * @param array<string, mixed> $user the requireUser() row
     * @param array<string, mixed> $filters the API payload
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $user, array $payload, bool $withSensitive = false): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        // The entity name is the whole of the sensitive-tier decision here.
        // EmployeesSensitive differs from Employees only in the columns its
        // search box reaches, because searching a column is a way of reading
        // it - a caller could confirm a TIN by typing it and seeing whether a
        // row comes back. FilterSpec never sees $user, so the permission stays
        // in this layer with the other permissions.
        $spec = FilterSpec::fromPayload(
            $withSensitive ? 'EmployeesSensitive' : 'Employees', $payload);
        $filter = FilterSql::build($spec, 'e.');

        return DB::rows(
            'SELECT ' . self::selectList($withSensitive)
            . ' FROM ' . self::from($withSensitive)
            . ' WHERE ' . $scope['sql'] . ' AND ' . $filter['sql']
            . ' ORDER BY ' . FilterSql::orderBy($spec, 'e.'),
            array_merge($scope['params'], $filter['params']));
    }

    /**
     * The choices each employee dropdown may offer, built from rows in scope.
     *
     * Always the non-sensitive spec: none of the option facets is a restricted
     * column, and a dropdown of every TIN in the office would be a disclosure
     * whatever the caller may search.
     *
     * @return array<string, array<int, string>>
     */
    public static function facetOptionsScoped(array $user): array
    {
        return FacetOptions::build(
            'Employees', self::from(false), ScopeGateway::where($user, 'Employees', 'e.'), 'e.');
    }

    /**
     * The FROM body, with the restricted tier joined only when it is readable.
     *
     * Shared by the list and the facet options so the two cannot come to be
     * built over different row sets.
     */
    private static function from(bool $withSensitive): string
    {
        return 'Employees e'
            . ($withSensitive ? ' LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID' : '');
    }

    /**
     * Employees this user may see.
     *
     * Kept as the name the modules already call; it is search().
     */
    public static function listScoped(array $user, array $filters, bool $withSensitive = false): array
    {
        return self::search($user, $filters, $withSensitive);
    }

    /** One employee, or null when it does not exist or is out of scope. */
    public static function findScoped(array $user, string $employeeId, bool $withSensitive = false): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT ' . self::selectList($withSensitive)
            . ' FROM Employees e'
            . ($withSensitive ? ' LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID' : '')
            . ' WHERE ' . $scope['sql'] . ' AND e.EmployeeID = ?',
            array_merge($scope['params'], [$employeeId]));
    }

    /**
     * Both tiers, restricted to employees the caller may see.
     *
     * For computing a payroll line where there is no saved payroll to check
     * the caller against - the grid preview. findForComputation() below is
     * safe only because apiSavePayroll has already checked the scope of the
     * payroll being saved; apiComputePayroll has no such payroll, so using
     * that method there let any holder of `payroll.view` post one line naming
     * any employee in any office and read that employee's daily rate back out
     * of the computed line. Encoder, Office Head and Internal Auditor hold
     * `payroll.view` and none of them hold `employee.sensitive`.
     *
     * The rate itself is not the leak - a preparer legitimately sees the rate
     * on a line they are preparing, and the printed payroll form shows it.
     * WHOSE rate is the leak, so this scopes and does not gate on permission.
     */
    public static function findForComputationScoped(array $user, string $employeeId): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT e.*, ' . self::sensitiveSelect()
            . ' FROM Employees e
                 LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
                WHERE ' . $scope['sql'] . ' AND e.EmployeeID = ?',
            array_merge($scope['params'], [$employeeId]));
    }

    /**
     * Both tiers, unscoped - the save path's lookup.
     *
     * computeLine() computes from DailyRate and HourlyRate, so preparing a
     * payroll genuinely requires the restricted tier. It is not a scope bypass:
     * apiSavePayroll checks the caller's scope against the payroll's charged
     * office before it gets here, and refuses an employee it may not pay.
     *
     * Any NEW caller without that prior check wants findForComputationScoped()
     * above instead. That distinction is the whole reason both exist.
     */
    public static function findForComputation(string $employeeId): ?array
    {
        return DB::row(
            'SELECT e.*, ' . self::sensitiveSelect()
            . ' FROM Employees e
                 LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
                WHERE e.EmployeeID = ?',
            [$employeeId]);
    }

    /**
     * Headcount within scope: total, and the two employment types the
     * dashboard splits on.
     *
     * Tier 1 only - a count is not a disclosure of anyone's rate. What it was
     * disclosing before this existed is how many people another office employs.
     *
     * @return array{total:int, jo:int, cos:int}
     */
    public static function countsScoped(array $user): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $row = DB::row(
            "SELECT COUNT(*) AS total,
                    SUM(e.Status = 'Active' AND e.EmploymentType = 'Job Order') AS jo,
                    SUM(e.Status = 'Active' AND e.EmploymentType = 'Contract of Service') AS cos
               FROM Employees e
              WHERE " . $scope['sql'],
            $scope['params']) ?? [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'jo' => (int) ($row['jo'] ?? 0),
            'cos' => (int) ($row['cos'] ?? 0),
        ];
    }

    /** The restricted tier alone, by id. */
    public static function sensitive(string $employeeId): ?array
    {
        return DB::row('SELECT * FROM EmployeeSensitive WHERE EmployeeID = ?', [$employeeId]);
    }

    /**
     * The employee records behind a payroll's lines, keyed by id.
     *
     * $withSensitive is decided by the caller's permission, not by the form
     * being printed: only the Pag-IBIG list needs the restricted tier, and a
     * caller without `employee.sensitive` is refused that form outright rather
     * than handed it with the ID columns blank.
     *
     * No scope filter, and that is correct here - these are the employees on
     * lines the caller has already been allowed to read through
     * PayrollRepo::detailsScoped(). Filtering again by the employee's HOME
     * office would drop anyone detailed in from elsewhere, printing a payroll
     * form with fewer people on it than the payroll has.
     *
     * @param string[] $employeeIds
     * @return array<string, array<string, mixed>>
     */
    public static function forPayrollLines(array $employeeIds, bool $withSensitive): array
    {
        if (!$employeeIds) return [];

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $select = $withSensitive ? 'e.*, ' . self::sensitiveSelect() : 'e.*';

        $rows = DB::rows(
            "SELECT $select
               FROM Employees e
               LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
              WHERE e.EmployeeID IN ($placeholders)",
            array_values($employeeIds));

        $keyed = [];
        foreach ($rows as $row) $keyed[$row['EmployeeID']] = $row;
        return $keyed;
    }

    /**
     * Writes both tiers as one unit.
     *
     * In a transaction because a directory row whose restricted half failed to
     * save is an employee with no rate, which the payroll engine would then
     * compute as zero pay rather than refuse.
     *
     * @param array<string, mixed> $tier1 Employees columns
     * @param array<string, mixed> $tier2 EmployeeSensitive columns
     */
    public static function save(
        string $employeeId,
        array $tier1,
        array $tier2,
        bool $isNew,
        bool $mayWriteSensitive = true
    ): void {
        DB::tx(function () use ($employeeId, $tier1, $tier2, $isNew, $mayWriteSensitive) {
            if ($isNew) {
                $tier1['EmployeeID'] = $employeeId;
                DB::insert('Employees', $tier1);
            } else {
                DB::update('Employees', $tier1, 'EmployeeID', $employeeId);
            }

            $exists = DB::row('SELECT EmployeeID FROM EmployeeSensitive WHERE EmployeeID = ?', [$employeeId]);

            // A caller who may not READ the restricted tier must not be able to
            // erase it either. The edit form posts every field it knows about,
            // and the fields it was never sent come back empty - so saving an
            // employee would blank their TIN and rate without anyone touching
            // them, and without the person doing it ever seeing what was lost.
            // Today only HRMO and Admin can edit an employee and both hold the
            // permission, so this guards a role that does not exist yet rather
            // than a live hole. That is the point: the next role added is where
            // it would otherwise have happened.
            if ($exists && !$mayWriteSensitive) return;

            if ($exists) {
                DB::update('EmployeeSensitive', $tier2, 'EmployeeID', $employeeId);
            } else {
                $tier2['EmployeeID'] = $employeeId;
                DB::insert('EmployeeSensitive', $tier2);
            }
        });
    }

    /** Splits an incoming record into [Tier 1, Tier 2] by the frozen classification. */
    public static function splitTiers(array $record): array
    {
        $tier2 = [];
        foreach (self::SENSITIVE_COLUMNS as $column) {
            if (array_key_exists($column, $record)) {
                $tier2[$column] = $record[$column];
                unset($record[$column]);
            }
        }
        return [$record, $tier2];
    }

    /** True when this user may read the restricted tier. */
    public static function mayReadSensitive(array $user): bool
    {
        $perms = $user['permissions'] ?? [];
        return in_array('*', $perms, true)
            || in_array(self::SENSITIVE_PERMISSION, $perms, true);
    }

    private static function selectList(bool $withSensitive): string
    {
        return $withSensitive ? 'e.*, ' . self::sensitiveSelect() : 'e.*';
    }

    private static function sensitiveSelect(): string
    {
        return implode(', ', array_map(fn($c) => "s.`$c`", self::SENSITIVE_COLUMNS));
    }
}
