<?php
/**
 * ============================================================================
 * WatchlistsTest - the four predicates, against fixtures.
 *
 * Same reason FilterSqlTest asserts on the SQL string itself: this class
 * exists to guarantee that "today" and the period-end date are the only
 * values threaded through as bound parameters, and that the boundary of each
 * window is the one Phase 9's planning actually decided - which a query
 * result cannot show once it has already run.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\Watchlists;
use PHPUnit\Framework\TestCase;

final class WatchlistsTest extends TestCase
{
    /* ------------------------------------------------------- bio exemptions */

    public function testBioExemptionsExpiringSoonBoundsBothEnds(): void
    {
        $built = Watchlists::bioExemptionsExpiringSoon('2026-08-30', 15);

        $this->assertSame(
            '(`Status` = ? AND `ValidTo` IS NOT NULL'
                . ' AND `ValidTo` >= ? AND `ValidTo` <= ? + INTERVAL ? DAY)',
            $built['sql']);
        $this->assertSame(['Active', '2026-08-30', '2026-08-30', 15], $built['params']);
    }

    /** A caller narrowing the window (e.g. a 7-day dashboard tile) just passes it. */
    public function testBioExemptionsWindowIsConfigurable(): void
    {
        $built = Watchlists::bioExemptionsExpiringSoon('2026-08-30', 7);

        $this->assertSame(7, $built['params'][3]);
    }

    public function testBioExemptionsHonoursAnAlias(): void
    {
        $built = Watchlists::bioExemptionsExpiringSoon('2026-08-30', 15, 'x.');

        $this->assertSame(
            '(x.`Status` = ? AND x.`ValidTo` IS NOT NULL'
                . ' AND x.`ValidTo` >= ? AND x.`ValidTo` <= ? + INTERVAL ? DAY)',
            $built['sql']);
    }

    /* ------------------------------------------------------------ contracts */

    public function testContractsEndingByHasNoLowerBound(): void
    {
        $built = Watchlists::contractsEndingBy('2026-09-15', 'c.');

        $this->assertSame(
            '(c.`Status` = ? AND c.`EndDate` IS NOT NULL AND c.`EndDate` <= ?)',
            $built['sql']);
        $this->assertSame(['Active', '2026-09-15'], $built['params']);
    }

    /* ----------------------------------------------------------- memoranda */

    /**
     * The exact predicate Phase 9 planning decided: OpenEnded by type, not by
     * a NULL EffectivityEnd, which Specific and Recurring both carry too.
     */
    public function testOpenEndedMemosStaleMatchesTheDecidedPredicate(): void
    {
        $built = Watchlists::openEndedMemosStale('2026-08-30', 'm.');

        $this->assertSame(
            '(m.`EffectivityType` = ? AND m.`Status` = ? AND m.`RevokedByID` IS NULL'
                . ' AND m.`UpdatedAt` < ? - INTERVAL 6 MONTH)',
            $built['sql']);
        $this->assertSame(['OpenEnded', 'Active', '2026-08-30'], $built['params']);
    }

    /* --------------------------------------------------------- suspensions */

    public function testSuspensionsPastDeadlineIsStrictlyBeforeToday(): void
    {
        $built = Watchlists::suspensionsPastDeadline('2026-08-30', 's.');

        $this->assertSame(
            '(s.`Status` = ? AND s.`Deadline` IS NOT NULL AND s.`Deadline` < ?)',
            $built['sql']);
        $this->assertSame(['Open', '2026-08-30'], $built['params']);
    }

    /* ------------------------------------------------------- the whole property */

    /**
     * Across every watchlist at once, no VALUE from a caller ever reaches the
     * SQL - the reference date is always bound, never interpolated - and the
     * distinctive date below would be plainly visible if it were.
     */
    public function testNoDateValueEverAppearsInTheSql(): void
    {
        $today = '1999-01-01';

        $fragments = [
            Watchlists::bioExemptionsExpiringSoon($today, 15),
            Watchlists::contractsEndingBy($today),
            Watchlists::openEndedMemosStale($today),
            Watchlists::suspensionsPastDeadline($today),
        ];

        foreach ($fragments as $built) {
            $this->assertStringNotContainsString('1999', $built['sql']);
            $this->assertSame(
                substr_count($built['sql'], '?'), count($built['params']));
        }
    }
}
