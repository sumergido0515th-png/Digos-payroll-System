<?php
/**
 * ============================================================================
 * FilterSql - A FilterSpec as a bound WHERE fragment.
 *
 * The mirror of ScopePredicate, with one deliberate difference: NO FILTERS
 * MEANS MATCH_ALL, where no grants means DENY_ALL.
 *
 * That asymmetry is the whole safety argument of this class and is worth being
 * explicit about. A scope predicate answers "which rows may this person see?",
 * and an empty answer to that question must mean none, because the failure
 * mode is a control that silently stops existing. A filter answers "which of
 * those rows did they ask for?", and an empty answer to THAT means all of
 * them - an unfiltered list is the ordinary case, not a mistake.
 *
 * The two are safe together only because they are always composed the same
 * way, in one place per entity:
 *
 *     WHERE (scope) AND (filters)
 *
 * A filter can therefore only ever narrow what the scope already permitted. It
 * cannot widen it, whatever the payload said - which is why FilterSpec is free
 * to accept a filter naming an office the caller cannot see and let it return
 * nothing, rather than refusing it and confirming the office exists.
 *
 * Pure: no DB::, no session, no clock.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

use InvalidArgumentException;

final class FilterSql
{
    /** Matches every row. Returned when nothing was filtered - see above. */
    public const MATCH_ALL = '1 = 1';

    /**
     * The WHERE fragment for these filters.
     *
     * Always AND this with ScopeGateway::where(); never use it alone.
     *
     * @param string $alias table alias including the dot, e.g. 'h.', or ''
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function build(FilterSpec $spec, string $alias = ''): array
    {
        $clauses = [];
        $params = [];

        foreach ($spec->conditions() as $condition) {
            $clauses[] = self::clause($condition, $alias, $params);
        }

        if (!$clauses) return ['sql' => self::MATCH_ALL, 'params' => []];

        return ['sql' => '(' . implode(' AND ', $clauses) . ')', 'params' => $params];
    }

    /**
     * The ORDER BY body - "`DateCreated` DESC, `PayrollNo` DESC".
     *
     * Every column name comes from FilterSpec's allowlist and the direction is
     * one of two literals, so this is the one identifier interpolation in the
     * query layer and it can never carry a payload value. The tiebreak follows
     * the same direction as the sort so the total order reads naturally rather
     * than reversing halfway down the page, and is skipped when the sort
     * already names it.
     *
     * A sort key may name several columns - an employee list sorts by surname
     * then first name, and collapsing that to surname alone would reorder
     * everyone who shares one.
     */
    public static function orderBy(FilterSpec $spec, string $alias = ''): string
    {
        $direction = $spec->sortDirection();
        $columns = $spec->sortColumns();

        if (!in_array($spec->tiebreakColumn(), $columns, true)) {
            $columns[] = $spec->tiebreakColumn();
        }

        return implode(', ', array_map(
            fn(string $column) => self::column($column, $alias) . ' ' . $direction,
            $columns));
    }

    /**
     * One condition, appending its bound values to $params.
     *
     * @param array<string, mixed> $condition as normalised by FilterSpec
     * @param array<int, mixed> $params appended to, by reference
     */
    private static function clause(array $condition, string $alias, array &$params): string
    {
        $op = (string) $condition['op'];
        $values = $condition['values'];

        switch ($op) {
            case 'exact':
                $params[] = $values[0];
                return self::column($condition['column'], $alias) . ' = ?';

            case 'in':
                foreach ($values as $value) $params[] = $value;
                return self::column($condition['column'], $alias)
                    . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';

            case 'dateFrom':
            case 'datetimeFrom':
                $params[] = $values[0];
                return self::column($condition['column'], $alias) . ' >= ?';

            case 'dateTo':
                $params[] = $values[0];
                return self::column($condition['column'], $alias) . ' <= ?';

            case 'before':
                // Strictly before, unlike dateTo. "Untouched since March" means
                // the last edit was before March, not up to and including it.
                $params[] = $values[0];
                return self::column($condition['column'], $alias) . ' < ?';

            case 'isNull':
                // The one condition that binds nothing. It is still a filter
                // and not an identifier: which column may be asked about comes
                // from FilterSpec, and the payload only chooses the direction.
                return self::column($condition['column'], $alias)
                    . ($values[0] ? ' IS NOT NULL' : ' IS NULL');

            case 'datetimeTo':
                // "to the 16th" has to include the 16th. A DATETIME compared
                // with '2026-08-16' is compared against midnight, so <= would
                // drop everything stamped during the day the user named -
                // which looks like missing data, not like an off-by-one.
                $params[] = $values[0];
                return self::column($condition['column'], $alias)
                    . ' < ? + INTERVAL 1 DAY';

            case 'search':
                $like = '%' . self::escapeLike((string) $values[0]) . '%';
                $parts = [];
                foreach ($condition['columns'] as $column) {
                    $parts[] = self::column($column, $alias) . ' LIKE ?';
                    $params[] = $like;
                }
                return '(' . implode(' OR ', $parts) . ')';

            default:
                throw new InvalidArgumentException("FilterSql cannot build op '$op'.");
        }
    }

    /**
     * A backtick-quoted, optionally aliased column name.
     *
     * Safe to interpolate because every caller reaches it through FilterSpec,
     * whose FACETS and SORTS maps are hardcoded. The backticks are the
     * codebase convention rather than a defence; the allowlist is the defence.
     *
     * A facet column may carry its own alias - "e.LastName" - which overrides
     * the one passed in. That is not a second source of identifiers: the
     * prefix is written in the same hardcoded map as the column beside it. It
     * exists because the entities added in 9B are scoped through a join, so
     * their free-text search legitimately spans two tables - a bio exemption
     * is searched by its reason AND by the employee's surname, and those live
     * on different sides of the join.
     *
     * Public so the facet-option queries in app/Repo/FacetOptions.php qualify
     * a column exactly the way the filters do. Two spellings of the same
     * column is how a dropdown ends up offering a value that then matches
     * nothing.
     */
    public static function column(string $column, string $alias = ''): string
    {
        if (!str_contains($column, '.')) return $alias . '`' . $column . '`';

        [$explicit, $name] = explode('.', $column, 2);

        return $explicit . '.`' . $name . '`';
    }

    /**
     * Neutralises LIKE's own wildcards in a user's search term.
     *
     * Without this, searching for "50%" matches every row rather than the ones
     * containing "50%", and an underscore in a control number silently matches
     * any character. Not a disclosure - the scope predicate is what bounds
     * that - but a search box that lies about what it found.
     *
     * The backslash is escaped first, or escaping the others would double back
     * over it.
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
