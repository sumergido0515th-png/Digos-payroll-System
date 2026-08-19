<?php
/**
 * ============================================================================
 * EmployeeDocumentRepo - Bio exemptions and travel orders.
 *
 * Two tables, one class, because they are the same shape and the same scope
 * rule: a document about one person, read by whoever may read that person.
 *
 * NEITHER TABLE CARRIES AN OFFICE CODE. Scope comes from a join to Employees,
 * not from a copy of the employee's office on the document. A copy would have
 * to be kept in step with transfers, and the moment it fell behind there would
 * be two answers to "whose row is this?" - the one the scope layer reads and
 * the true one. That is the failure mode the layer exists to prevent, so it is
 * not worth reintroducing for a saved join.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class EmployeeDocumentRepo
{
    /**
     * The employee's display name, composed in SQL.
     *
     * Employees stores LastName and FirstName separately; EmployeeName is a
     * PayrollDetails column, not one of theirs. Composed the same way here so a
     * document list and a payroll line show the same person the same way.
     */
    private const EMPLOYEE_NAME = "CONCAT(e.LastName, ', ', e.FirstName) AS EmployeeName";

    /**
     * Bio exemptions within the caller's scope.
     *
     * @param array<string, mixed> $filters EmployeeID, Status, search
     * @return array<int, array<string, mixed>>
     */
    public static function listExemptionsScoped(array $user, array $filters = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $sql = 'SELECT x.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
                  FROM BioExemptions x
                  JOIN Employees e ON e.EmployeeID = x.EmployeeID
                 WHERE ' . $scope['sql'];
        $params = $scope['params'];

        if (!empty($filters['EmployeeID'])) {
            $sql .= ' AND x.EmployeeID = ?'; $params[] = $filters['EmployeeID'];
        }
        if (!empty($filters['Status'])) {
            $sql .= ' AND x.Status = ?'; $params[] = $filters['Status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (e.LastName LIKE ? OR x.ReasonCode LIKE ? OR x.Reason LIKE ?'
                . ' OR x.ProofType LIKE ? OR x.ProofRef LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return DB::rows($sql . ' ORDER BY x.ValidFrom DESC, e.LastName', $params);
    }

    /** One bio exemption, or null when absent or out of scope. */
    public static function findExemptionScoped(array $user, string $exemptionId): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT x.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM BioExemptions x
               JOIN Employees e ON e.EmployeeID = x.EmployeeID
              WHERE ' . $scope['sql'] . ' AND x.ExemptionID = ?',
            array_merge($scope['params'], [$exemptionId]));
    }

    /** @param array<string, mixed> $record */
    public static function saveExemption(string $exemptionId, array $record, bool $isNew): void
    {
        if ($isNew) {
            DB::insert('BioExemptions', array_merge(['ExemptionID' => $exemptionId], $record));
            return;
        }
        DB::update('BioExemptions', $record, 'ExemptionID', $exemptionId);
    }

    public static function deleteExemption(string $exemptionId): int
    {
        return DB::exec('DELETE FROM BioExemptions WHERE ExemptionID = ?', [$exemptionId]);
    }

    /* ====================================================================== */

    /**
     * Travel orders within the caller's scope.
     *
     * @param array<string, mixed> $filters EmployeeID, Status, search
     * @return array<int, array<string, mixed>>
     */
    public static function listTravelOrdersScoped(array $user, array $filters = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $sql = 'SELECT t.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
                  FROM TravelOrders t
                  JOIN Employees e ON e.EmployeeID = t.EmployeeID
                 WHERE ' . $scope['sql'];
        $params = $scope['params'];

        if (!empty($filters['EmployeeID'])) {
            $sql .= ' AND t.EmployeeID = ?'; $params[] = $filters['EmployeeID'];
        }
        if (!empty($filters['Status'])) {
            $sql .= ' AND t.Status = ?'; $params[] = $filters['Status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (t.TravelOrderNo LIKE ? OR t.Destination LIKE ?'
                . ' OR t.Purpose LIKE ? OR e.LastName LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return DB::rows($sql . ' ORDER BY t.DepartDate DESC, t.TravelOrderNo DESC', $params);
    }

    /** One travel order, or null when absent or out of scope. */
    public static function findTravelOrderScoped(array $user, string $travelOrderId): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT t.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM TravelOrders t
               JOIN Employees e ON e.EmployeeID = t.EmployeeID
              WHERE ' . $scope['sql'] . ' AND t.TravelOrderID = ?',
            array_merge($scope['params'], [$travelOrderId]));
    }

    /** Whether a travel order number is taken by a different order. */
    public static function travelOrderNoTaken(string $number, string $exceptId = ''): bool
    {
        return (bool) DB::scalar(
            'SELECT COUNT(*) FROM TravelOrders WHERE TravelOrderNo = ? AND TravelOrderID <> ?',
            [$number, $exceptId]);
    }

    /** @param array<string, mixed> $record */
    public static function saveTravelOrder(string $travelOrderId, array $record, bool $isNew): void
    {
        if ($isNew) {
            DB::insert('TravelOrders', array_merge(['TravelOrderID' => $travelOrderId], $record));
            return;
        }
        DB::update('TravelOrders', $record, 'TravelOrderID', $travelOrderId);
    }

    public static function deleteTravelOrder(string $travelOrderId): int
    {
        return DB::exec('DELETE FROM TravelOrders WHERE TravelOrderID = ?', [$travelOrderId]);
    }
}
