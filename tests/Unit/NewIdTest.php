<?php
/**
 * ============================================================================
 * NewIdTest - newId() in app/Helpers.php.
 *
 * WHY THIS EXISTS
 * The millisecond + random suffix alone left a real collision window: two
 * ids minted in the same millisecond differed only by a draw from 900
 * values, which is exactly what a bulk import hits writing many rows in a
 * tight loop. It surfaced once in ImportTest as a `Duplicate entry ... for
 * key 'PRIMARY'` and passed on three immediate re-runs - a within-run
 * collision, not stale fixture data. Parked to the Backlog at the time
 * (2026-08-29) specifically because "a small change to a function every
 * write path uses... wants its own session and its own verification rather
 * than a drive-by edit inside a phase."
 *
 * The fix is a per-request `static` counter, so this suite proves the
 * property that actually matters: many ids minted in the same process,
 * however fast, are never equal to each other - not merely probably unique.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NewIdTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/Helpers.php';
    }

    public function testTheIdStartsWithThePrefix(): void
    {
        $this->assertStringStartsWith('EMP-', \newId('EMP'));
    }

    /**
     * The property a bulk import actually needs: not "collision is unlikely"
     * but "collision cannot happen" for every id minted inside one run,
     * regardless of how many share a millisecond.
     */
    public function testManyIdsMintedInTheSameProcessAreAllDistinct(): void
    {
        $ids = [];
        for ($i = 0; $i < 5000; $i++) {
            $ids[] = \newId('EMP');
        }

        $this->assertCount(5000, array_unique($ids),
            '5000 ids minted in one run were not all distinct.');
    }

    /**
     * The counter is what guarantees the above even when the millisecond and
     * the random draw both repeat, which is precisely the scenario that
     * produced the original bug - so this pins the mechanism, not just the
     * outcome above.
     */
    public function testTheSequenceSegmentIsStrictlyIncreasingWithinAProcess(): void
    {
        $sequenceOf = function (string $id): int {
            $parts = explode('-', $id);
            return (int) base_convert(end($parts), 36, 10);
        };

        $first = $sequenceOf(\newId('EMP'));
        $second = $sequenceOf(\newId('EMP'));

        $this->assertGreaterThan($first, $second);
    }

    /** Four hyphen-separated segments: prefix, milliseconds, random draw, sequence. */
    public function testTheIdHasFourSegments(): void
    {
        $this->assertCount(4, explode('-', \newId('PRD')));
    }

    public function testDifferentPrefixesNeverCollide(): void
    {
        $this->assertStringStartsWith('PRD-', \newId('PRD'));
        $this->assertStringStartsWith('EMP-', \newId('EMP'));
    }
}
