<?php
/**
 * ============================================================================
 * Watchlists - the four standing queries, as date predicates.
 *
 * Each method returns a WHERE fragment in the same shape FilterSql produces -
 * {sql, params} - so a repository composes it exactly like a filter, always
 * "WHERE (scope) AND (watchlist)". These are not part of FilterSpec's facet
 * map: a caller does not choose one from a payload, so there is nothing here
 * for an allowlist to guard. Each is a fixed question this system already
 * knows how to ask.
 *
 * Pure: no DB::, no session, no clock. "Today" and the period-end date are
 * parameters rather than reads, in the same way ScopeGateway isolates its own
 * clock read - a fixture can then place a boundary exactly where a test needs
 * it without waiting for the calendar to cooperate.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

final class Watchlists
{
    /**
     * Bio exemptions expiring within $withinDays of $today, not yet expired.
     *
     * The lower bound matters as much as the upper one: without ValidTo >=
     * $today this would also list every exemption that lapsed months ago,
     * which is a different problem - an unexcused absence sitting unresolved
     * - than the one this watchlist raises, which is "renew this before it
     * runs out".
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function bioExemptionsExpiringSoon(
        string $today,
        int $withinDays = 15,
        string $alias = ''
    ): array {
        $status = FilterSql::column('Status', $alias);
        $validTo = FilterSql::column('ValidTo', $alias);

        return [
            'sql' => "($status = ? AND $validTo IS NOT NULL"
                . " AND $validTo >= ? AND $validTo <= ? + INTERVAL ? DAY)",
            'params' => ['Active', $today, $today, $withinDays],
        ];
    }

    /**
     * Active contracts ending on or before a payroll period's end date.
     *
     * No lower bound. A contract that lapsed further back than that is still
     * exactly what this watchlist exists to catch - Status = 'Active' still
     * being true past its own EndDate is itself the problem, not a reason to
     * exclude it.
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function contractsEndingBy(string $periodEnd, string $alias = ''): array
    {
        $status = FilterSql::column('Status', $alias);
        $endDate = FilterSql::column('EndDate', $alias);

        return [
            'sql' => "($status = ? AND $endDate IS NOT NULL AND $endDate <= ?)",
            'params' => ['Active', $periodEnd],
        ];
    }

    /**
     * Open-ended memoranda nobody has touched in six months.
     *
     * The exact predicate decided at Phase 9 planning, 2026-08-29:
     * EffectivityType = 'OpenEnded' is what "open-ended" means, unlike
     * "EffectivityEnd IS NULL", which Specific and Recurring both carry
     * legitimately and would have swept onto this list too. A memo already
     * revoked or superseded is excluded - closed-out authority is not
     * outstanding, whatever its EffectivityType still reads.
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function openEndedMemosStale(string $today, string $alias = ''): array
    {
        $type = FilterSql::column('EffectivityType', $alias);
        $status = FilterSql::column('Status', $alias);
        $revokedBy = FilterSql::column('RevokedByID', $alias);
        $updatedAt = FilterSql::column('UpdatedAt', $alias);

        return [
            'sql' => "($type = ? AND $status = ? AND $revokedBy IS NULL"
                . " AND $updatedAt < ? - INTERVAL 6 MONTH)",
            'params' => ['OpenEnded', 'Active', $today],
        ];
    }

    /**
     * Suspensions still Open past their deadline.
     *
     * Strictly before $today - a suspension whose deadline is today still has
     * today to be settled in.
     *
     * @return array{sql: string, params: array<int, mixed>}
     */
    public static function suspensionsPastDeadline(string $today, string $alias = ''): array
    {
        $status = FilterSql::column('Status', $alias);
        $deadline = FilterSql::column('Deadline', $alias);

        return [
            'sql' => "($status = ? AND $deadline IS NOT NULL AND $deadline < ?)",
            'params' => ['Open', $today],
        ];
    }
}
