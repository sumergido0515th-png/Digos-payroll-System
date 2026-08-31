<?php
/**
 * ============================================================================
 * FilterSpec - Which facets a caller may filter each entity by.
 *
 * This registry is the ONLY place a filter facet name is mapped to a real
 * column, and it is hardcoded on purpose - the same convention ScopeEntity
 * follows for scope dimensions: FilterSql interpolates these column names
 * straight into SQL, which is safe precisely because they never come from a
 * payload. A payload key not in an entity's allowlist is simply never read,
 * so it can never become a column name and can never widen a query.
 *
 * Phase 9's own plan (docs/PHASE_PLAN.md) frames this as the reusable
 * replacement for rowMatches()-style PHP-side filtering: that pattern fetches
 * rows already scoped by SQL and then substring-matches them in memory, which
 * cannot carry faceted, SQL-side filters without every new facet risking a
 * leak. FilterSpec + FilterSql move that work back into SQL, composed with
 * ScopeGateway::where() as "WHERE (scope) AND (filters)" - never in place of
 * it.
 *
 * Pure: no DB::, no session, no clock, no file I/O.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

use InvalidArgumentException;

final class FilterSpec
{
    /**
     * entity => facet name => real column.
     *
     * Payroll's FunctionCode is deliberately absent. Every office currently
     * charges the placeholder code 9999 after the 0004/0006/0014 backfills
     * (docs/PHASE_PLAN.md, Phase 9's "Carried-over risk" note) - none of them
     * resolve to a real Functions row - so a filter over it would look like
     * it works while actually filtering nothing meaningful. Add it only once
     * that data-entry item closes; until then this is a facet the plan itself
     * warns not to build on top of yet.
     *
     * @var array<string, array<string, string>>
     */
    private const FACETS = [
        'Payroll' => [
            'PeriodID'       => 'PeriodID',
            'OfficeCode'     => 'OfficeCode',
            'Department'     => 'Department',
            'Status'         => 'Status',
            'PreparedByUser' => 'PreparedByUser',
        ],
    ];

    /**
     * entity => the columns its free-text "search" facet matches across.
     *
     * @var array<string, string[]>
     */
    private const SEARCH_COLUMNS = [
        'Payroll' => ['PayrollNo', 'OfficeCode', 'Department', 'PreparedBy', 'Remarks'],
    ];

    /**
     * A raw API payload, narrowed to what this entity actually allows.
     *
     * Silent, not throwing, on an unrecognized payload key - "search",
     * pagination parameters, or a stray field left over from a shared form
     * all pass through a route harmlessly, the same way an unrecognized
     * scope dimension is simply not checked rather than treated as an error.
     * Throwing is reserved for an unregistered ENTITY, because that is a
     * caller-side mistake (a typo in code), not a shape a user's payload can
     * take.
     *
     * @param array<string, mixed> $payload the API request array
     * @return array{
     *   facets: array<int, array{column: string, value: string}>,
     *   search: array{columns: string[], value: string}
     * }
     * @throws InvalidArgumentException for an unregistered entity.
     */
    public static function normalize(string $entity, array $payload): array
    {
        if (!isset(self::FACETS[$entity])) {
            throw new InvalidArgumentException(
                "'$entity' is not a filterable entity. Add it to FilterSpec::FACETS "
                . 'before filtering it.');
        }

        $facets = [];
        foreach (self::FACETS[$entity] as $name => $column) {
            $value = self::scalarOrNull($payload[$name] ?? null);
            if ($value === null) continue;  // absent, blank, or a non-scalar shape: not a filter

            $facets[] = ['column' => $column, 'value' => $value];
        }

        $searchValue = self::scalarOrNull($payload['search'] ?? null) ?? '';

        return [
            'facets' => $facets,
            'search' => ['columns' => self::SEARCH_COLUMNS[$entity] ?? [], 'value' => $searchValue],
        ];
    }

    /**
     * A trimmed string value, or null for absent/blank/non-scalar input.
     *
     * A non-scalar payload value (an array, for instance) is dropped rather
     * than coerced, so it never becomes the literal string "Array" bound as
     * a silently-wrong parameter. An empty string is treated the same as
     * absent - a blank form field is not a filter on "".
     */
    private static function scalarOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
