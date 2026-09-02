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
use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;
use Digos\Domain\Query\Watchlists;

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
     * The FROM bodies. Both documents are about a person, so both are scoped
     * through the employee rather than by carrying an office code of their own
     * - see ScopeEntity's note on why two answers to "whose row is this?"
     * eventually disagree. The join therefore carries the scope, and the list
     * and the facet options must run over the same one.
     */
    private const EXEMPTION_FROM =
        'BioExemptions x JOIN Employees e ON e.EmployeeID = x.EmployeeID';

    private const TRAVEL_FROM =
        'TravelOrders t JOIN Employees e ON e.EmployeeID = t.EmployeeID';

    /**
     * Bio exemptions within the caller's scope.
     *
     * @param array<string, mixed> $filters EmployeeID, Status, search
     * @return array<int, array<string, mixed>>
     */
    public static function searchExemptions(array $user, array $payload = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');
        $spec = FilterSpec::fromPayload('BioExemptions', $payload);
        $filter = FilterSql::build($spec, 'x.');

        return DB::rows(
            'SELECT x.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM ' . self::EXEMPTION_FROM . '
              WHERE ' . $scope['sql'] . ' AND ' . $filter['sql'] . '
              ORDER BY ' . FilterSql::orderBy($spec, 'x.'),
            array_merge($scope['params'], $filter['params']));
    }

    /** @return array<string, array<int, string>> */
    public static function exemptionFacetOptionsScoped(array $user): array
    {
        return FacetOptions::build(
            'BioExemptions', self::EXEMPTION_FROM,
            ScopeGateway::where($user, 'Employees', 'e.'), 'x.');
    }

    /**
     * Bio exemptions within the caller's scope.
     *
     * Kept as the name the modules already call; it is searchExemptions().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listExemptionsScoped(array $user, array $filters = []): array
    {
        return self::searchExemptions($user, $filters);
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

    /**
     * Bio exemptions within the caller's scope, expiring within $withinDays.
     *
     * Composed the same way search() is - WHERE (scope) AND (watchlist) -
     * over the same join, so a caller cannot be shown an exemption on this
     * list they could not already read on the ordinary one.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function exemptionsExpiringScoped(
        array $user,
        string $today,
        int $withinDays = 15
    ): array {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');
        $watch = Watchlists::bioExemptionsExpiringSoon($today, $withinDays, 'x.');

        return DB::rows(
            'SELECT x.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM ' . self::EXEMPTION_FROM . '
              WHERE ' . $scope['sql'] . ' AND ' . $watch['sql'] . '
              ORDER BY x.ValidTo',
            array_merge($scope['params'], $watch['params']));
    }

    /* ====================================================================== */

    /**
     * Travel orders within the caller's scope.
     *
     * @param array<string, mixed> $filters EmployeeID, Status, search
     * @return array<int, array<string, mixed>>
     */
    public static function searchTravelOrders(array $user, array $payload = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');
        $spec = FilterSpec::fromPayload('TravelOrders', $payload);
        $filter = FilterSql::build($spec, 't.');

        return DB::rows(
            'SELECT t.*, ' . self::EMPLOYEE_NAME . ', e.OfficeCode
               FROM ' . self::TRAVEL_FROM . '
              WHERE ' . $scope['sql'] . ' AND ' . $filter['sql'] . '
              ORDER BY ' . FilterSql::orderBy($spec, 't.'),
            array_merge($scope['params'], $filter['params']));
    }

    /** @return array<string, array<int, string>> */
    public static function travelOrderFacetOptionsScoped(array $user): array
    {
        return FacetOptions::build(
            'TravelOrders', self::TRAVEL_FROM,
            ScopeGateway::where($user, 'Employees', 'e.'), 't.');
    }

    /**
     * Travel orders within the caller's scope.
     *
     * Kept as the name the modules already call; it is searchTravelOrders().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listTravelOrdersScoped(array $user, array $filters = []): array
    {
        return self::searchTravelOrders($user, $filters);
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
