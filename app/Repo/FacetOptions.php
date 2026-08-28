<?php
/**
 * ============================================================================
 * FacetOptions - The choices a filter dropdown may offer, always scoped.
 *
 * A facet list is a query result like any other, and the one most easily
 * forgotten: a dropdown naming every office discloses the org chart before a
 * single row is fetched. Deriving the options from the already-scoped table
 * makes that structurally impossible - an option can only appear because the
 * caller can already read a row carrying it.
 *
 * It lives in app/Repo/ rather than beside FilterSpec because it runs a query,
 * and DB:: is confined here. What it must never do is grow a $user parameter:
 * the caller passes in the scope fragment it already built for the list, which
 * is what guarantees the options and the rows are bounded by the same
 * predicate rather than by two that could drift.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;

final class FacetOptions
{
    /**
     * The distinct in-scope value of each option facet on $entity.
     *
     * One query per column rather than a UNION: the columns are few, they are
     * indexed differently, and a UNION would have to cast them to a common
     * type, which is how a code with a leading zero stops matching itself.
     *
     * @param string $entity a FilterSpec entity name
     * @param string $from the FROM body, including any join the scope needs -
     *        the SAME one the list query uses, so an option cannot come from a
     *        row the list would not have returned
     * @param array{sql: string, params: array<int, mixed>} $scope from ScopeGateway::where()
     * @param string $alias default alias for columns that do not carry one
     * @return array<string, array<int, string>> facet key => sorted values
     */
    public static function build(
        string $entity,
        string $from,
        array $scope,
        string $alias = ''
    ): array {
        $options = [];

        foreach (FilterSpec::optionColumns($entity) as $facet => $column) {
            // Qualified through FilterSql so the options and the filters spell
            // the column the same way. Both sides come from FilterSpec's
            // hardcoded map and never from a payload.
            $qualified = FilterSql::column($column, $alias);

            $rows = DB::rows(
                "SELECT DISTINCT $qualified AS value
                   FROM $from
                  WHERE " . $scope['sql'] . "
                    AND $qualified IS NOT NULL AND $qualified <> ''
                  ORDER BY $qualified",
                $scope['params']);

            $options[$facet] = array_map(fn(array $r) => (string) $r['value'], $rows);
        }

        return $options;
    }
}
