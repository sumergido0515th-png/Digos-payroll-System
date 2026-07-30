<?php
/**
 * ============================================================================
 * ScopeGateway - The single point every read of a restricted table passes
 * through.
 *
 * MariaDB has no row-level security, so there is no database-level place to
 * put this. The enforcement point is therefore application code, and the only
 * thing keeping a feature module from going around it is
 * tests/Architecture/DatabaseAccessTest.php, which confines DB:: to app/Repo/.
 * That guard is not bureaucracy - one direct query in a module is a silent
 * leak of another office's rows, and code review does not reliably catch it.
 *
 * The decision itself is not here. It is in Digos\Domain\Access\ScopePredicate,
 * which is pure and tested against fixtures; this class only supplies the
 * grants and today's date, and runs the query.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use Digos\Domain\Access\ScopeEntity;
use Digos\Domain\Access\ScopePredicate;
use RuntimeException;

final class ScopeGateway
{
    /**
     * The WHERE fragment restricting $entity to what $user may see.
     *
     * Always returns something restrictive: a user with no grants gets
     * ScopePredicate::DENY_ALL rather than an empty string, so a caller that
     * forgets to check still cannot widen the query by concatenating nothing.
     *
     * @param array<string, mixed> $user the requireUser() row
     * @param string $alias table alias including the dot, e.g. 'p.', or ''
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function where(
        array $user,
        string $entity,
        string $alias = '',
        string $access = 'read'
    ): array {
        $grants = ScopeGrantRepo::forUser((string) $user['Email']);

        $live = ScopePredicate::applicable(
            $grants, (string) ($user['Role'] ?? ''), self::today(), $access);

        return ScopePredicate::build($live, $entity, $alias);
    }

    /**
     * True when the user may act on a row carrying these dimension values.
     *
     * Used for writes, where there is no query to filter - the question is
     * whether this particular office and function may be charged at all. The
     * values are matched against the same predicate the reads use, so a user
     * can never write to a scope they could not have read.
     *
     * @param array<string, mixed> $dimensions e.g. ['OfficeCode' => 'CMO']
     */
    public static function permits(
        array $user,
        string $entity,
        array $dimensions,
        string $access = 'write'
    ): bool {
        $grants = ScopeGrantRepo::forUser((string) $user['Email']);
        $live = ScopePredicate::applicable(
            $grants, (string) ($user['Role'] ?? ''), self::today(), $access);

        $predicate = ScopePredicate::build($live, $entity);
        if ($predicate['sql'] === ScopePredicate::DENY_ALL) return false;
        if ($predicate['sql'] === ScopePredicate::ALLOW_ALL) return true;

        // Re-evaluate the same grants against the supplied values rather than
        // against a table. Building one-row SQL to ask this would be the same
        // decision computed a second way, and the two would eventually drift.
        foreach ($live as $grant) {
            if (self::grantCovers($grant, $entity, $dimensions)) return true;
        }
        return false;
    }

    /**
     * Refuses the write, in words the person doing it can act on.
     *
     * @throws RuntimeException when the scope does not permit it
     */
    public static function requirePermits(
        array $user,
        string $entity,
        array $dimensions,
        string $what
    ): void {
        if (self::permits($user, $entity, $dimensions)) return;

        throw new RuntimeException(
            'Your access does not cover ' . $what . '. Ask an administrator to '
            . 'extend your scope if this is part of your work.');
    }

    /**
     * Whether one grant covers the supplied dimension values.
     *
     * Mirrors ScopePredicate::forGrant(): a grant narrowing a dimension the
     * entity does not carry cannot be verified and therefore does not cover
     * anything, and a dimension the caller did not supply is treated as not
     * covered rather than as matching.
     */
    private static function grantCovers(array $grant, string $entity, array $dimensions): bool
    {
        $columns = ScopeEntity::columns($entity);

        foreach (ScopeEntity::DIMENSIONS as $dimension) {
            $required = $grant[$dimension] ?? null;
            if ($required === null || trim((string) $required) === '') continue;

            if (!isset($columns[$dimension])) return false;

            $actual = $dimensions[$dimension] ?? null;
            if ($actual === null || (string) $actual !== (string) $required) return false;
        }
        return true;
    }

    /**
     * Today, as the predicate wants it.
     *
     * The one clock read in the scope layer, deliberately isolated here so the
     * decision itself stays pure and its fixtures can place a grant's expiry
     * wherever they need it.
     */
    private static function today(): string
    {
        return date('Y-m-d');
    }
}
