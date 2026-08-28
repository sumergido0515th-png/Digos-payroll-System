<?php
/**
 * ============================================================================
 * FilterSpec - What a caller is allowed to filter and sort by, and nothing
 * else.
 *
 * This is the allowlist half of Phase 9. Everything a payload can influence
 * about a query passes through the FACETS and SORTS maps below, and anything
 * absent from them cannot reach SQL at all - which is what lets FilterSql
 * interpolate a column name safely, the same bargain ScopeEntity strikes for
 * the scope layer: values are always bound, and an interpolated identifier
 * comes from a hardcoded map and never from a payload.
 *
 * WHAT THIS CLASS IS NOT
 * It is not an access control. A FilterSpec never sees $user and never sees a
 * grant, and a spec naming another office is perfectly valid - it simply
 * produces a query that returns nothing, because the repository ANDs it with
 * ScopeGateway::where(). That separation is deliberate and is the exit gate:
 * refusing to filter by an office would confirm the office exists, so the
 * filter must be allowed to be asked and the scope must be what answers it.
 *
 * WHY UNKNOWN KEYS ARE IGNORED RATHER THAN REFUSED
 * The payload is the whole request body - it carries 'action', and the SPA's
 * list screens post their entire form - so an unrecognised key is ordinary,
 * not suspicious. The cost of ignoring one is a filter that fails to narrow,
 * which returns a SUPERSET of what was asked for but never a superset of what
 * the caller may see. Sorting is the exception and does refuse: an unknown
 * sort key is the only payload value that would otherwise become an
 * identifier.
 *
 * Pure: no DB::, no session, no clock.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

use InvalidArgumentException;
use RuntimeException;

final class FilterSpec
{
    /**
     * entity => facet key => how it narrows the query.
     *
     * 'op' selects the comparison FilterSql emits:
     *   exact         `col` = ?
     *   in            `col` IN (?, ...)          - accepts an array or "a,b,c"
     *   dateFrom/To   `col` >= ? / <= ?          - for DATE columns
     *   datetimeFrom  `col` >= ?                 - midnight of the given day
     *   datetimeTo    `col` < ? + INTERVAL 1 DAY - so "to the 16th" includes
     *                                              everything stamped ON the
     *                                              16th, which <= would not
     *   search        (`a` LIKE ? OR `b` LIKE ?) - free text over 'columns'
     *
     * 'options' marks the facets that also produce a dropdown; a repository
     * offers exactly these as choices, built from rows already inside the
     * caller's scope. See PayrollRepo::facetOptionsScoped().
     *
     * Payroll only, for 9A. The remaining entities are 9B.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const FACETS = [
        'Payroll' => [
            // What
            'PayrollNo' => ['op' => 'exact', 'column' => 'PayrollNo'],
            'FunctionCode' => ['op' => 'in', 'column' => 'FunctionCode', 'options' => true],

            // Who
            'OfficeCode' => ['op' => 'in', 'column' => 'OfficeCode', 'options' => true],
            'Department' => ['op' => 'in', 'column' => 'Department', 'options' => true],
            'TimekeeperID' => ['op' => 'exact', 'column' => 'TimekeeperID'],

            // Who acted. Both are the Users foreign keys migration 0007 added,
            // never the PreparedBy/ApprovedBy display strings beside them -
            // those are what the printed form shows and two people can share a
            // name. See CLAUDE.md > Traps.
            'PreparedByUser' => ['op' => 'exact', 'column' => 'PreparedByUser'],
            'ApprovedByUser' => ['op' => 'exact', 'column' => 'ApprovedByUser'],

            // When
            'PeriodID' => ['op' => 'in', 'column' => 'PeriodID', 'options' => true],
            'CreatedFrom' => ['op' => 'datetimeFrom', 'column' => 'DateCreated'],
            'CreatedTo' => ['op' => 'datetimeTo', 'column' => 'DateCreated'],
            'SubmittedFrom' => ['op' => 'datetimeFrom', 'column' => 'SubmittedAt'],
            'SubmittedTo' => ['op' => 'datetimeTo', 'column' => 'SubmittedAt'],
            'ApprovedFrom' => ['op' => 'datetimeFrom', 'column' => 'ApprovedAt'],
            'ApprovedTo' => ['op' => 'datetimeTo', 'column' => 'ApprovedAt'],

            // State
            'Status' => ['op' => 'in', 'column' => 'Status', 'options' => true],

            // The single box. Deliberately does NOT read PreparedByUser: an
            // email address is an identifier, not something a timekeeper types
            // into a search box, and matching it here would let a partial
            // address probe for accounts.
            'search' => ['op' => 'search', 'columns' => [
                'PayrollNo', 'OfficeCode', 'Department', 'PreparedBy', 'Remarks']],
        ],
    ];

    /**
     * entity => sort key the payload may send => the column it means.
     *
     * Keys are UI words rather than column names on purpose: they are the one
     * thing here a caller can name that becomes an identifier, so they are
     * kept deliberately unlike the schema. 'aging' is the pre-auditor's
     * default - how long a payroll has been waiting is SubmittedAt, which is
     * the column migration 0021 added because neither DateCreated nor
     * ApprovedAt answers it.
     *
     * @var array<string, array<string, string>>
     */
    private const SORTS = [
        'Payroll' => [
            'created' => 'DateCreated',
            'submitted' => 'SubmittedAt',
            'aging' => 'SubmittedAt',
            'approved' => 'ApprovedAt',
            'payrollNo' => 'PayrollNo',
            'office' => 'OfficeCode',
            'status' => 'Status',
            'gross' => 'TotalGross',
            'net' => 'TotalNet',
        ],
    ];

    /** entity => [sort key, direction] when the payload names none. */
    private const DEFAULT_SORT = [
        'Payroll' => ['created', 'DESC'],
    ];

    /**
     * entity => its primary key, appended to every ORDER BY as a tiebreak.
     *
     * Sorting by Status alone leaves rows in whatever order the storage engine
     * returns them, which is stable until it is not. A total order costs one
     * column and is what makes a page boundary mean the same thing twice.
     *
     * @var array<string, string>
     */
    private const KEYS = [
        'Payroll' => 'PayrollNo',
    ];

    /** @param array<int, array<string, mixed>> $conditions */
    private function __construct(
        private readonly string $entity,
        private readonly array $conditions,
        private readonly string $sortColumn,
        private readonly string $sortDirection,
        private readonly string $tiebreakColumn
    ) {
    }

    /**
     * Reads the facets this entity allows out of a request payload.
     *
     * @param array<string, mixed> $payload the api* $p array, as posted
     * @throws InvalidArgumentException for an entity with no facet map - the
     *         same loud failure ScopeEntity makes, and for the same reason:
     *         the alternative to a known entity is an unfiltered query.
     * @throws RuntimeException for a value the caller can fix - a malformed
     *         date, an unknown sort key - in words that reach them through
     *         the fail() envelope.
     */
    public static function fromPayload(string $entity, array $payload): self
    {
        $facets = self::facets($entity);

        $conditions = [];
        foreach ($facets as $key => $facet) {
            $condition = self::condition($key, $facet, $payload[$key] ?? null);
            if ($condition !== null) $conditions[] = $condition;
        }

        [$column, $direction] = self::sort($entity, $payload);

        return new self($entity, $conditions, $column, $direction, self::KEYS[$entity]);
    }

    /** An unfiltered spec: every row of the entity, in its default order. */
    public static function unfiltered(string $entity): self
    {
        return self::fromPayload($entity, []);
    }

    public function entity(): string
    {
        return $this->entity;
    }

    /**
     * The narrowing clauses, normalised. Empty when nothing was filtered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    public function sortColumn(): string
    {
        return $this->sortColumn;
    }

    /** 'ASC' or 'DESC' - never anything else, so it is safe to interpolate. */
    public function sortDirection(): string
    {
        return $this->sortDirection;
    }

    public function tiebreakColumn(): string
    {
        return $this->tiebreakColumn;
    }

    /**
     * The columns whose distinct in-scope values make up this entity's
     * dropdowns.
     *
     * Returned as facet key => column so a repository can build the option
     * lists without naming a column of its own - the point being that the
     * options a screen offers and the filters it may then apply come from one
     * map, and cannot drift into offering a choice that filters nothing.
     *
     * @return array<string, string>
     */
    public static function optionColumns(string $entity): array
    {
        $columns = [];
        foreach (self::facets($entity) as $key => $facet) {
            if (!empty($facet['options'])) $columns[$key] = (string) $facet['column'];
        }
        return $columns;
    }

    /** @return array<string, array<string, mixed>> */
    private static function facets(string $entity): array
    {
        if (!isset(self::FACETS[$entity])) {
            throw new InvalidArgumentException(
                "'$entity' has no filter facets. Add it to FilterSpec::FACETS before "
                . 'searching it.');
        }
        return self::FACETS[$entity];
    }

    /**
     * One facet's clause, or null when the payload did not ask for it.
     *
     * A blank value is "not filtered" rather than "equal to blank": a cleared
     * dropdown posts an empty string, and reading that as a real value would
     * quietly return nothing at all and look like a scope problem.
     *
     * @return array<string, mixed>|null
     */
    private static function condition(string $key, array $facet, mixed $raw): ?array
    {
        $op = (string) $facet['op'];

        if ($op === 'in') {
            $values = self::values($raw);
            return $values ? ['op' => 'in', 'column' => $facet['column'], 'values' => $values] : null;
        }

        $value = self::scalar($raw);
        if ($value === null) return null;

        return match ($op) {
            'exact' => ['op' => 'exact', 'column' => $facet['column'], 'values' => [$value]],
            'search' => ['op' => 'search', 'columns' => $facet['columns'], 'values' => [$value]],
            'dateFrom', 'dateTo', 'datetimeFrom', 'datetimeTo' => [
                'op' => $op,
                'column' => $facet['column'],
                'values' => [self::date($key, $value)],
            ],
            default => throw new InvalidArgumentException(
                "FilterSpec facet '$key' declares an unknown op '$op'."),
        };
    }

    /**
     * A multi-value facet's values, from either an array or "a,b,c".
     *
     * Comma-splitting exists because URL-encoded filter state is a deliverable
     * of this phase and "?Status=DRAFT,FOR_PRE_AUDIT" is how a shareable link
     * spells a two-value facet.
     *
     * @return array<int, string>
     */
    private static function values(mixed $raw): array
    {
        if ($raw === null) return [];
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        $values = [];
        foreach ($items as $item) {
            if (is_array($item)) continue;          // a nested array is not a value
            $item = trim((string) $item);
            if ($item !== '') $values[] = $item;
        }
        return array_values(array_unique($values));
    }

    /** A single trimmed value, or null when blank or not scalar. */
    private static function scalar(mixed $raw): ?string
    {
        if ($raw === null || is_array($raw)) return null;
        $value = trim((string) $raw);
        return $value === '' ? null : $value;
    }

    /**
     * A calendar date, refused if it is not one.
     *
     * Refusing rather than dropping is the right direction here even though
     * ignoring an unknown key is: a date the caller typed is a filter they
     * believe is applied, and silently discarding it shows them more rows than
     * they asked for while telling them nothing.
     */
    private static function date(string $key, string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $value;
        }

        throw new RuntimeException(
            "The date in '$key' is not a real date: '$value'. Use the date picker, or "
            . 'type it as 2026-08-29.');
    }

    /**
     * The sort column and direction, both from allowlists.
     *
     * @return array{0: string, 1: string}
     */
    private static function sort(string $entity, array $payload): array
    {
        $keys = self::SORTS[$entity] ?? [];
        [$defaultKey, $defaultDirection] = self::DEFAULT_SORT[$entity];

        $key = self::scalar($payload['sort'] ?? null) ?? $defaultKey;

        if (!isset($keys[$key])) {
            throw new RuntimeException(
                "There is no '$key' column to sort by. Choose one of: "
                . implode(', ', array_keys($keys)) . '.');
        }

        $direction = strtoupper(self::scalar($payload['direction'] ?? null) ?? $defaultDirection);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new RuntimeException(
                "Sort direction must be 'asc' or 'desc', not '$direction'.");
        }

        return [$keys[$key], $direction];
    }
}
