<?php
/**
 * ============================================================================
 * FilterSql - FilterSpec's normalized request, turned into a bound SQL
 * fragment.
 *
 * This never receives $user and never knows about scope - that decision is
 * ScopeGateway's, made first, and this class only narrows further. That is
 * also why, unlike ScopePredicate, it is safe here for an empty request to
 * produce NO_FILTER ("1 = 1"): the fragment this returns is ALWAYS ANDed onto
 * a scope clause that has already resolved ALLOW_ALL or DENY_ALL, and is
 * never used as a WHERE clause by itself.
 *
 * Pure: no DB::, no session, no clock, no file I/O.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

final class FilterSql
{
    /** Returned when there is nothing to filter by. Always safe: see class docblock. */
    public const NO_FILTER = '1 = 1';

    /**
     * @param array{
     *   facets: array<int, array{column: string, value: string}>,
     *   search: array{columns: string[], value: string}
     * } $normalized FilterSpec::normalize()'s output
     * @param string $alias table alias including the dot, e.g. 'p.', or ''
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function build(array $normalized, string $alias = ''): array
    {
        $clauses = [];
        $params = [];

        foreach ($normalized['facets'] as $facet) {
            // The column is FilterSpec's own hardcoded value, never the
            // payload - safe to interpolate for the same reason a scope
            // dimension's column is.
            $clauses[] = $alias . '`' . $facet['column'] . '` = ?';
            $params[] = $facet['value'];
        }

        $search = $normalized['search'];
        if ($search['value'] !== '' && $search['columns']) {
            $like = '%' . $search['value'] . '%';
            $ors = [];
            foreach ($search['columns'] as $column) {
                $ors[] = $alias . '`' . $column . '` LIKE ?';
                $params[] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        if (!$clauses) return ['sql' => self::NO_FILTER, 'params' => []];

        return ['sql' => '(' . implode(' AND ', $clauses) . ')', 'params' => $params];
    }
}
