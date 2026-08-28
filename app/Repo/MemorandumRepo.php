<?php
/**
 * ============================================================================
 * MemorandumRepo - Scoped reads and writes of the authority document.
 *
 * A memo is scoped on the office that issued it, registered in ScopeEntity.
 * Its covered employees are a junction table rather than a column, because
 * Phase 4 resolves authority per employee per datetime and that is a lookup.
 *
 * Nothing here interprets effectivity. The columns are stored and returned
 * exactly as entered; working out which memo covers whom and when is
 * resolveAuthority(), and it is a pure function in Phase 4 for the same reason
 * computeLine() is one - it is the part worth testing against fixtures.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;

final class MemorandumRepo
{
    /**
     * Memoranda this user may see, narrowed by the facets they asked for.
     *
     * Scope and filters compose here as everywhere: WHERE (scope) AND
     * (filters), so a filter naming another office returns nothing rather than
     * refusing and thereby confirming the office exists.
     *
     * @param array<string, mixed> $payload the API payload
     * @return array<int, array<string, mixed>>
     */
    public static function search(array $user, array $payload = []): array
    {
        $scope = ScopeGateway::where($user, 'Memorandum', 'm.');
        $spec = FilterSpec::fromPayload('Memorandum', $payload);
        $filter = FilterSql::build($spec, 'm.');

        return DB::rows(
            'SELECT m.*, (SELECT COUNT(*) FROM MemorandumEmployees me
                            WHERE me.MemoID = m.MemoID) AS CoveredCount
               FROM Memorandum m
              WHERE ' . $scope['sql'] . ' AND ' . $filter['sql'] . '
              ORDER BY ' . FilterSql::orderBy($spec, 'm.'),
            array_merge($scope['params'], $filter['params']));
    }

    /** @return array<string, array<int, string>> */
    public static function facetOptionsScoped(array $user): array
    {
        return FacetOptions::build(
            'Memorandum', 'Memorandum m',
            ScopeGateway::where($user, 'Memorandum', 'm.'), 'm.');
    }

    /**
     * Memoranda this user may see.
     *
     * Kept as the name the modules already call; it is search().
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters = []): array
    {
        return self::search($user, $filters);
    }

    /**
     * One memorandum, or null when it does not exist or is out of scope.
     *
     * The two cases return the same thing, as everywhere else in this layer:
     * telling a caller that a memo they may not read exists is itself the
     * disclosure the scope layer prevents.
     */
    public static function findScoped(array $user, string $memoId): ?array
    {
        $scope = ScopeGateway::where($user, 'Memorandum', 'm.');

        return DB::row(
            'SELECT m.* FROM Memorandum m WHERE ' . $scope['sql'] . ' AND m.MemoID = ?',
            array_merge($scope['params'], [$memoId]));
    }

    /**
     * The employees a memorandum covers, with enough of the employee to show.
     *
     * Scoped on the employee, not on the memo: a citywide memo covering people
     * in four offices shows an office-scoped reader only their own. The memo
     * itself having been readable does not make its whole coverage list
     * readable, which is the same rule PayrollRepo::detailsScoped() applies to
     * a payroll spanning two offices.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function coveredEmployeesScoped(array $user, string $memoId): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::rows(
            "SELECT e.EmployeeID, CONCAT(e.LastName, ', ', e.FirstName) AS EmployeeName,
                    e.OfficeCode, e.Position
               FROM MemorandumEmployees me
               JOIN Employees e ON e.EmployeeID = me.EmployeeID
              WHERE " . $scope['sql'] . ' AND me.MemoID = ?
              ORDER BY e.LastName, e.FirstName',
            array_merge($scope['params'], [$memoId]));
    }

    /**
     * Every memo that could bear on a date, for the Phase 4 resolver.
     *
     * UNSCOPED, and not reachable from a route. AuthorityResolver decides
     * whether a memo covers an employee, and it can only do that if it is
     * handed every memo - including the ones a citywide instrument issued by
     * another office. Applying the caller's grants here would make a memo's
     * authority depend on who is asking, which is not a property authority has.
     *
     * The window filter is deliberately loose: a memo is fetched when its
     * window touches the date at all, and supersession truncation then decides
     * whether it still applies. Filtering tightly here would pre-empt a
     * decision that belongs in the pure function.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForDate(string $date): array
    {
        return DB::rows(
            "SELECT * FROM Memorandum
              WHERE Status <> 'Draft'
                AND (EffectivityStart IS NULL OR EffectivityStart <= ?
                     OR EffectivityType = 'Specific')
              ORDER BY DateIssued DESC, ControlNo DESC",
            [$date]);
    }

    /**
     * The coverage rows for those memoranda.
     *
     * Unscoped for the same reason, and returned whole rather than per employee
     * so the resolver can be handed one array for a whole period.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function coverageForDate(string $date): array
    {
        return DB::rows(
            "SELECT me.MemoID, me.EmployeeID
               FROM MemorandumEmployees me
               JOIN Memorandum m ON m.MemoID = me.MemoID
              WHERE m.Status <> 'Draft'
                AND (m.EffectivityStart IS NULL OR m.EffectivityStart <= ?
                     OR m.EffectivityType = 'Specific')",
            [$date]);
    }

    /** Whether a control number is already taken by a different memo. */
    public static function controlNoTaken(string $controlNo, string $exceptMemoId = ''): bool
    {
        return (bool) DB::scalar(
            'SELECT COUNT(*) FROM Memorandum WHERE ControlNo = ? AND MemoID <> ?',
            [$controlNo, $exceptMemoId]);
    }

    /** Unscoped existence check, for validating a supersedes/amends reference. */
    public static function exists(string $memoId): bool
    {
        return (bool) DB::scalar('SELECT COUNT(*) FROM Memorandum WHERE MemoID = ?', [$memoId]);
    }

    /**
     * Inserts or updates a memorandum and replaces its coverage list.
     *
     * One transaction: a memo whose coverage list half-applied would authorise
     * some of the people it names and not others, and nothing downstream could
     * tell that from a memo that deliberately covers fewer.
     *
     * @param array<string, mixed> $record column => value
     * @param string[] $employeeIds
     */
    public static function save(string $memoId, array $record, array $employeeIds, bool $isNew): void
    {
        DB::tx(function () use ($memoId, $record, $employeeIds, $isNew) {
            if ($isNew) {
                DB::insert('Memorandum', array_merge(['MemoID' => $memoId], $record));
            } else {
                DB::update('Memorandum', $record, 'MemoID', $memoId);
            }

            DB::exec('DELETE FROM MemorandumEmployees WHERE MemoID = ?', [$memoId]);
            foreach (array_unique($employeeIds) as $employeeId) {
                DB::insert('MemorandumEmployees',
                    ['MemoID' => $memoId, 'EmployeeID' => $employeeId]);
            }
        });
    }

    /** Deletes a memorandum; MemorandumEmployees cascades. */
    public static function delete(string $memoId): int
    {
        return DB::exec('DELETE FROM Memorandum WHERE MemoID = ?', [$memoId]);
    }

    /**
     * Memoranda pointing at this one, so a delete can explain itself.
     *
     * The foreign keys are ON DELETE SET NULL, so the delete would succeed and
     * quietly break the chain. Refusing with the list is more useful than
     * discovering later that a supersession no longer records what it replaced.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function referencedBy(string $memoId): array
    {
        return DB::rows(
            'SELECT MemoID, ControlNo FROM Memorandum
              WHERE SupersedesID = ? OR AmendsID = ? OR RevokedByID = ?',
            [$memoId, $memoId, $memoId]);
    }
}
