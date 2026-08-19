<?php
/**
 * ============================================================================
 * ShiftResolverTest - which shift version governed a historical date.
 *
 * Phase 3 made WorkShifts versioned; this is the payoff. Without it, changing
 * the office start time from 08:00 to 09:00 would retroactively un-late
 * everybody who had been late for months, and no test would notice.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Resolver\ShiftResolver;
use PHPUnit\Framework\TestCase;

final class ShiftResolverTest extends TestCase
{
    /** A March date resolves to the version in force in March. */
    public function testAHistoricalDateResolvesToTheVersionInForceThen(): void
    {
        $march = ShiftResolver::versionOn($this->versions(), '2026-03-15');
        $august = ShiftResolver::versionOn($this->versions(), '2026-08-15');

        $this->assertSame('08:00', $march['TimeIn'],
            'A later edit rewrote what "late" meant on a day already paid.');
        $this->assertSame('09:00', $august['TimeIn']);
    }

    /** The boundary belongs to the new version, not the old. */
    public function testTheChangeoverDayBelongsToTheNewVersion(): void
    {
        $this->assertSame('08:00', ShiftResolver::versionOn($this->versions(), '2026-06-30')['TimeIn']);
        $this->assertSame('09:00', ShiftResolver::versionOn($this->versions(), '2026-07-01')['TimeIn']);
    }

    /**
     * A date before the shift existed has no version.
     *
     * Null is a real answer. Inventing the current version would be exactly the
     * retroactive rewrite this class exists to prevent.
     */
    public function testADateBeforeTheShiftExistedResolvesToNothing(): void
    {
        $this->assertNull(ShiftResolver::versionOn($this->versions(), '2025-12-31'));
    }

    /* ---------------------------------------------------------- rest days */

    /** A rest day is a property of the shift, not of the date. */
    public function testRestDaysComeFromTheShiftAndDifferBetweenShifts(): void
    {
        $office = ['RestDays' => '6,7'];                    // Saturday and Sunday
        $market = ['RestDays' => '2'];                      // Tuesday

        // 2026-07-11 is a Saturday, 2026-07-07 a Tuesday.
        $this->assertTrue(ShiftResolver::isRestDay($office, '2026-07-11'));
        $this->assertFalse(ShiftResolver::isRestDay($market, '2026-07-11'),
            'The weekend crew works Saturdays; the date alone cannot say.');
        $this->assertTrue(ShiftResolver::isRestDay($market, '2026-07-07'));
    }

    /** No rest days is a real schedule, distinct from having no shift. */
    public function testAShiftWithNoRestDaysIsNotTheSameAsNoShift(): void
    {
        $this->assertFalse(ShiftResolver::isRestDay(['RestDays' => ''], '2026-07-11'));
        $this->assertFalse(ShiftResolver::isRestDay(null, '2026-07-11'));
    }

    /* ------------------------------------------------- night differential */

    /**
     * The night window normally wraps midnight.
     *
     * 22:00 to 06:00 is the usual one, and a naive end-minus-start would give a
     * negative number and silently pay nobody.
     */
    public function testAWrappedNightWindowIsMeasuredAcrossMidnight(): void
    {
        $shift = ['NightDiffFrom' => '22:00', 'NightDiffTo' => '06:00'];

        $this->assertSame(300, ShiftResolver::nightDifferentialMinutes($shift, '21:00', '03:00'),
            '22:00 to 03:00 is five hours inside the window.');
    }

    /** A span entirely outside the window earns none. */
    public function testADaytimeSpanEarnsNoNightDifferential(): void
    {
        $shift = ['NightDiffFrom' => '22:00', 'NightDiffTo' => '06:00'];

        $this->assertSame(0, ShiftResolver::nightDifferentialMinutes($shift, '08:00', '17:00'));
    }

    /** A window that does not wrap is measured plainly. */
    public function testANonWrappingWindowIsMeasuredPlainly(): void
    {
        $shift = ['NightDiffFrom' => '13:00', 'NightDiffTo' => '17:00'];

        $this->assertSame(120, ShiftResolver::nightDifferentialMinutes($shift, '15:00', '19:00'));
    }

    /** A shift with no night window earns nothing rather than everything. */
    public function testAShiftWithNoNightWindowEarnsNothing(): void
    {
        $this->assertSame(0,
            ShiftResolver::nightDifferentialMinutes(['TimeIn' => '08:00'], '22:00', '23:00'));
        $this->assertSame(0, ShiftResolver::nightDifferentialMinutes(null, '22:00', '23:00'));
    }

    /* -------------------------------------------------- scheduled length */

    /** The break comes out of the scheduled day. */
    public function testTheBreakIsDeductedFromTheScheduledDay(): void
    {
        $this->assertSame(480, ShiftResolver::scheduledMinutes(
            ['TimeIn' => '08:00', 'TimeOut' => '17:00', 'BreakMinutes' => 60]));
    }

    /** A shift crossing midnight has a positive length. */
    public function testAShiftCrossingMidnightHasAPositiveLength(): void
    {
        $this->assertSame(480, ShiftResolver::scheduledMinutes(
            ['TimeIn' => '22:00', 'TimeOut' => '06:00', 'BreakMinutes' => 0]));
    }

    /** @return array<int, array<string, mixed>> two versions of one shift code */
    private function versions(): array
    {
        return [
            ['ShiftID' => 'S-1', 'ShiftCode' => 'OFFICE', 'VersionNo' => 1,
                'TimeIn' => '08:00', 'TimeOut' => '17:00', 'RestDays' => '6,7',
                'EffectiveFrom' => '2026-01-01', 'EffectiveTo' => '2026-06-30'],
            ['ShiftID' => 'S-2', 'ShiftCode' => 'OFFICE', 'VersionNo' => 2,
                'TimeIn' => '09:00', 'TimeOut' => '18:00', 'RestDays' => '6,7',
                'EffectiveFrom' => '2026-07-01', 'EffectiveTo' => null],
        ];
    }
}
