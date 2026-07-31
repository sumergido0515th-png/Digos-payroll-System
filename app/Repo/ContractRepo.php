<?php
/**
 * ============================================================================
 * ContractRepo - Engagements, with supersession.
 *
 * The table has existed since 0005 and nothing has ever written to it. That was
 * a decision rather than an oversight: employee save mirrors the form's single
 * ContractStart/ContractEnd pair, and writing that on every save is exactly the
 * overwrite 0005 was built to prevent - a renewal at a new rate would destroy
 * the rate that was in force when last quarter's payroll was prepared.
 *
 * So a renewal ADDS A ROW. renew() closes the contract in force and inserts the
 * next one; it never edits a rate in place. Phase 6's "daily rate != contract
 * rate" rule reads rateInForceOn(), and that question only has an answer while
 * the superseded rows are still there.
 *
 * Scope comes from a join to Employees, for the reason set out in
 * EmployeeDocumentRepo: the document is about a person, so its scope is that
 * person's, and a copied office code would eventually disagree with the true
 * one.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use RuntimeException;

final class ContractRepo
{
    /** The employee display name, composed as in EmployeeDocumentRepo. */
    private const EMPLOYEE_NAME = "CONCAT(e.LastName, ', ', e.FirstName) AS EmployeeName";

    /**
     * Contracts within the caller's scope, newest engagement first.
     *
     * @param array<string, mixed> $filters EmployeeID, Status
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $sql = 'SELECT c.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
                  FROM Contracts c
                  JOIN Employees e ON e.EmployeeID = c.EmployeeID
                 WHERE ' . $scope['sql'];
        $params = $scope['params'];

        if (!empty($filters['EmployeeID'])) {
            $sql .= ' AND c.EmployeeID = ?'; $params[] = $filters['EmployeeID'];
        }
        if (!empty($filters['Status'])) {
            $sql .= ' AND c.Status = ?'; $params[] = $filters['Status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (e.LastName LIKE ? OR c.Remarks LIKE ? OR c.ContractID LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return DB::rows($sql . ' ORDER BY c.StartDate DESC, e.LastName, e.FirstName', $params);
    }

    /** One contract, or null when absent or out of scope. */
    public static function findScoped(array $user, string $contractId): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT c.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM Contracts c
               JOIN Employees e ON e.EmployeeID = c.EmployeeID
              WHERE ' . $scope['sql'] . ' AND c.ContractID = ?',
            array_merge($scope['params'], [$contractId]));
    }

    /**
     * The contract in force for an employee on a date.
     *
     * Unscoped on purpose, and not reachable from a route. This is the lookup
     * Phase 6 makes while checking a payroll line it has already established
     * the caller may read; re-deriving the scope here would apply the caller's
     * grants to a question the rule engine asks on the system's behalf.
     *
     * An open-ended contract (EndDate NULL) covers everything from its start.
     * Most recent start wins, so a renewal recorded before the old one was
     * closed still answers with the newer engagement.
     */
    public static function inForceOn(string $employeeId, string $date): ?array
    {
        return DB::row(
            'SELECT * FROM Contracts
              WHERE EmployeeID = ?
                AND (StartDate IS NULL OR StartDate <= ?)
                AND (EndDate IS NULL OR EndDate >= ?)
              ORDER BY StartDate DESC
              LIMIT 1',
            [$employeeId, $date, $date]);
    }

    /** The rate in force on a date, or null when no contract covers it. */
    public static function rateInForceOn(string $employeeId, string $date): ?string
    {
        $contract = self::inForceOn($employeeId, $date);
        return $contract === null ? null : (string) $contract['Rate'];
    }

    /** Every contract for one employee, oldest first - the history screen. */
    public static function historyFor(string $employeeId): array
    {
        return DB::rows(
            'SELECT * FROM Contracts WHERE EmployeeID = ? ORDER BY StartDate, ContractID',
            [$employeeId]);
    }

    /**
     * The first contract for an employee who has none.
     *
     * @param array<string, mixed> $record
     */
    public static function create(string $contractId, array $record): void
    {
        DB::insert('Contracts', array_merge(['ContractID' => $contractId], $record));
    }

    /**
     * Closes the contract in force and opens the next one.
     *
     * Both halves in one transaction. Closing without opening leaves the
     * employee with no engagement, and opening without closing leaves two rows
     * claiming the same days - and rateInForceOn() would then have to pick one,
     * which is precisely the ambiguity this table exists to remove.
     *
     * @param array<string, mixed> $record the new contract's columns
     */
    public static function renew(
        string $employeeId,
        string $newContractId,
        array $record,
        string $startDate
    ): void {
        $current = DB::row(
            'SELECT * FROM Contracts WHERE EmployeeID = ?
              ORDER BY StartDate DESC, ContractID DESC LIMIT 1',
            [$employeeId]);

        if ($current === null) {
            throw new RuntimeException(
                'This employee has no contract to renew. Record the first one instead.');
        }
        if ($current['StartDate'] !== null && $startDate <= (string) $current['StartDate']) {
            throw new RuntimeException(sprintf(
                'The renewal must start after the contract it replaces (which starts %s). '
                . 'Two contracts covering the same day cannot both be the rate that was '
                . 'in force.',
                $current['StartDate']));
        }

        DB::tx(function () use ($current, $newContractId, $record, $startDate) {
            // Ends the day before the renewal starts, so the two windows meet
            // without overlapping and no day falls between them.
            DB::update('Contracts', [
                'EndDate' => date('Y-m-d', strtotime($startDate . ' -1 day')),
                'Status' => 'Superseded',
            ], 'ContractID', (string) $current['ContractID']);

            DB::insert('Contracts', array_merge(
                ['ContractID' => $newContractId, 'StartDate' => $startDate], $record));
        });
    }

    /**
     * Corrects a contract in place.
     *
     * Deliberately narrow: Remarks and Status only. Rate, dates and type are
     * what supersession exists to preserve, and letting them be edited here
     * would be the overwrite by another name. A wrong rate is fixed by
     * recording the correct engagement, not by rewriting history.
     *
     * @param array<string, mixed> $patch
     */
    public static function amend(string $contractId, array $patch): int
    {
        $allowed = array_intersect_key($patch, array_flip(['Remarks', 'Status']));
        if (!$allowed) return 0;

        return DB::update('Contracts', $allowed, 'ContractID', $contractId);
    }
}
