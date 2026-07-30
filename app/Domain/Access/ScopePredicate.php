<?php
/**
 * ============================================================================
 * ScopePredicate - Turns a user's scope grants into a SQL WHERE fragment.
 *
 * This is the whole of Phase 2's access decision, and it is pure: grants and
 * an entity name in, a bound SQL fragment out. No DB::, no $_SESSION, no clock
 * read - the caller passes today's date in. That is what lets the rules below
 * be tested against fixtures rather than against a database, which matters
 * because a mistake here does not throw, it returns other offices' rows.
 *
 * THE TWO RULES THAT MATTER
 *
 *   1. No applicable grant denies everything ("0 = 1"). An empty grant list
 *      must never widen to "everything" - that is the failure mode where the
 *      control silently stops existing. Every other decision here is chosen to
 *      fail in this direction.
 *
 *   2. NULL on a dimension is a wildcard. One grant with every dimension NULL
 *      expresses "all offices, all funds, all employment types, all years"
 *      without a separate concept for it.
 *
 * Pure: no DB::, no session, no clock.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Access;

final class ScopePredicate
{
    /** Denies every row. Returned whenever no grant applies. */
    public const DENY_ALL = '0 = 1';

    /** Matches every row. Returned only for a grant that narrows nothing. */
    public const ALLOW_ALL = '1 = 1';

    /**
     * The grants that are live for this user, role and access right today.
     *
     * $today is passed in rather than read, so a test can place a grant's
     * expiry on either side of "now" without touching the system clock.
     *
     * @param array<int, array<string, mixed>> $grants rows from ScopeGrants
     * @param string $access 'read' or 'write'
     * @return array<int, array<string, mixed>>
     */
    public static function applicable(
        array $grants,
        string $role,
        string $today,
        string $access = 'read'
    ): array {
        $rightColumn = $access === 'write' ? 'CanWrite' : 'CanRead';

        return array_values(array_filter($grants, function (array $g) use ($role, $today, $rightColumn) {
            if (!self::isTruthy($g[$rightColumn] ?? 0)) return false;

            // A NULL role means "whatever role they hold". A named one means
            // the grant only applies while they are acting in it, so somebody
            // holding two roles does not carry the union of both scopes.
            $grantRole = self::nullable($g['RoleCode'] ?? null);
            if ($grantRole !== null && $grantRole !== $role) return false;

            // NULL on either side is unbounded that way, which a permanent
            // assignment legitimately is. Dates are ISO strings, so string
            // comparison is date comparison.
            $from = self::nullable($g['ValidFrom'] ?? null);
            $to = self::nullable($g['ValidTo'] ?? null);
            if ($from !== null && $today < $from) return false;
            if ($to !== null && $today > $to) return false;

            return true;
        }));
    }

    /**
     * The WHERE fragment for these grants against one entity.
     *
     * @param array<int, array<string, mixed>> $grants already filtered by applicable()
     * @param string $alias table alias including the dot, e.g. 'p.', or ''
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function build(array $grants, string $entity, string $alias = ''): array
    {
        $columns = ScopeEntity::columns($entity);

        $clauses = [];
        $params = [];

        foreach ($grants as $grant) {
            $clause = self::forGrant($grant, $columns, $alias, $params);

            // One unrestricted grant makes every other clause redundant, and
            // returning "1 = 1" rather than a chain of ORs keeps the generated
            // SQL readable when an administrator is the one asking.
            if ($clause === self::ALLOW_ALL) {
                return ['sql' => self::ALLOW_ALL, 'params' => []];
            }
            if ($clause !== null) $clauses[] = $clause;
        }

        if (!$clauses) return ['sql' => self::DENY_ALL, 'params' => []];

        return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
    }

    /**
     * One grant's clause, or null when the grant cannot apply to this entity.
     *
     * A grant narrowing a dimension the entity does not carry - a JO-only grant
     * against Payroll, which has no employment-type column - returns null and
     * therefore contributes nothing. The alternative is to ignore the dimension
     * we cannot check, which would silently widen a JO-only grant into every
     * payroll in the city. Denying what cannot be verified is the only safe
     * direction, and it is visible: the user sees nothing and asks, rather than
     * seeing too much and not knowing.
     *
     * FiscalYear is the live example - no entity carries it yet, because a
     * payroll's year lives on PayrollPeriods and reaching it needs a join. A
     * grant scoped to a fiscal year currently denies rather than leaks.
     *
     * @param array<int, mixed> $params appended to, by reference
     */
    private static function forGrant(
        array $grant,
        array $columns,
        string $alias,
        array &$params
    ): ?string {
        $conditions = [];
        $pending = [];

        foreach (ScopeEntity::DIMENSIONS as $dimension) {
            $value = self::nullable($grant[$dimension] ?? null);
            if ($value === null) continue;               // wildcard on this dimension

            if (!isset($columns[$dimension])) return null;  // unverifiable - deny

            // The column name is interpolated, and is safe to interpolate
            // because it comes from ScopeEntity's hardcoded map and never from
            // a payload. The value is always bound.
            $conditions[] = $alias . '`' . $columns[$dimension] . '` = ?';
            $pending[] = $value;
        }

        if (!$conditions) return self::ALLOW_ALL;

        foreach ($pending as $value) $params[] = $value;

        return '(' . implode(' AND ', $conditions) . ')';
    }

    /** Treats '' as NULL: an empty form field is not a scope of "". */
    private static function nullable(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /** MySQL hands TINYINT back as the string '1' or '0'. */
    private static function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
