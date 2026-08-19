<?php
/**
 * ============================================================================
 * PeriodTotalsTest - the derivation, against fixtures.
 *
 * This is the seam where hand-keyed totals stop being authoritative, and every
 * phase after this one trusts it. Unit-tested rather than only exercised
 * through the API because the function is pure and its edge cases - a day that
 * is neither worked nor absent, a part day, an absence with hours on it - are
 * all statable as arrays.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Dtr\PeriodTotals;
use PHPUnit\Framework\TestCase;

final class PeriodTotalsTest extends TestCase
{
    /** A whole day is one day, not eight hours. */
    public function testAFullDayCountsAsADayAndNotAsHours(): void
    {
        $totals = PeriodTotals::fromDays([$this->day(['HoursWorked' => 8])]);

        $this->assertSame(1.0, $totals['DaysWorked']);
        $this->assertSame(0.0, $totals['HoursWorked'],
            'A full day counted as both a day and eight hours would be paid twice - '
            . 'computeLine() adds daily x DaysWorked to hourly x HoursWorked.');
    }

    /** A part day is hours, not a fraction of a day. */
    public function testAPartDayCountsAsHours(): void
    {
        $totals = PeriodTotals::fromDays([$this->day(['HoursWorked' => 4])]);

        $this->assertSame(0.0, $totals['DaysWorked']);
        $this->assertSame(4.0, $totals['HoursWorked']);
    }

    /** More hours than a standard day is still one day; the rest is overtime. */
    public function testHoursBeyondAStandardDayDoNotBecomeExtraDays(): void
    {
        $totals = PeriodTotals::fromDays([
            $this->day(['HoursWorked' => 11, 'OvertimeHours' => 3]),
        ]);

        $this->assertSame(1.0, $totals['DaysWorked']);
        $this->assertSame(0.0, $totals['HoursWorked']);
        $this->assertSame(3.0, $totals['OvertimeHours'],
            'Overtime is its own column precisely so a long day is not a longer day.');
    }

    /** An absence contributes an absent day and nothing else. */
    public function testAnAbsenceIsCountedOnceAndAddsNoTime(): void
    {
        $totals = PeriodTotals::fromDays([
            $this->day(['IsAbsent' => 1, 'LateMinutes' => 30, 'OvertimeHours' => 2]),
        ]);

        $this->assertSame(1.0, $totals['AbsentDays']);
        $this->assertSame(0.0, $totals['DaysWorked']);
        $this->assertSame(0.0, $totals['LateMinutes'],
            'Late minutes on a day nobody attended are not a deduction on top of the '
            . 'absence - the absence already costs the whole day.');
        $this->assertSame(0.0, $totals['OvertimeHours']);
    }

    /**
     * A day with no hours and no absence contributes nothing.
     *
     * That is a rest day or an unworked holiday, and deciding which is Phase
     * 4's resolveHoliday. Counting it as an absence here would pre-empt that
     * with a deduction that no rule asked for.
     */
    public function testARestDayIsNeitherWorkedNorAbsent(): void
    {
        $totals = PeriodTotals::fromDays([
            $this->day(['HoursWorked' => 0, 'DayType' => 'RestDay']),
        ]);

        $this->assertSame(0.0, $totals['DaysWorked']);
        $this->assertSame(0.0, $totals['AbsentDays']);
        $this->assertSame(0.0, $totals['HoursWorked']);
    }

    /** Late and undertime accumulate across worked days. */
    public function testLateAndUndertimeAccumulate(): void
    {
        $totals = PeriodTotals::fromDays([
            $this->day(['HoursWorked' => 8, 'LateMinutes' => 15]),
            $this->day(['HoursWorked' => 8, 'LateMinutes' => 20, 'UndertimeMinutes' => 10]),
        ]);

        $this->assertSame(2.0, $totals['DaysWorked']);
        $this->assertSame(35.0, $totals['LateMinutes']);
        $this->assertSame(10.0, $totals['UndertimeMinutes']);
    }

    /** A fortnight, mixed, adds up the way a timekeeper would total it. */
    public function testAMixedFortnight(): void
    {
        $days = [];
        for ($i = 0; $i < 10; $i++) $days[] = $this->day(['HoursWorked' => 8]);
        $days[] = $this->day(['HoursWorked' => 4]);                    // half day
        $days[] = $this->day(['IsAbsent' => 1]);                       // absent
        $days[] = $this->day(['HoursWorked' => 0, 'DayType' => 'RestDay']);
        $days[] = $this->day(['HoursWorked' => 8, 'OvertimeHours' => 2.5]);

        $totals = PeriodTotals::fromDays($days);

        $this->assertSame(11.0, $totals['DaysWorked']);
        $this->assertSame(4.0, $totals['HoursWorked']);
        $this->assertSame(1.0, $totals['AbsentDays']);
        $this->assertSame(2.5, $totals['OvertimeHours']);
    }

    /** The standard day is a parameter, because it is not universal. */
    public function testTheStandardDayLengthChangesWhatCountsAsAWholeDay(): void
    {
        $sixHourDay = PeriodTotals::fromDays([$this->day(['HoursWorked' => 6])], 6.0);
        $eightHourDay = PeriodTotals::fromDays([$this->day(['HoursWorked' => 6])], 8.0);

        $this->assertSame(1.0, $sixHourDay['DaysWorked']);
        $this->assertSame(0.0, $eightHourDay['DaysWorked']);
        $this->assertSame(6.0, $eightHourDay['HoursWorked']);
    }

    /**
     * A nonsensical standard day falls back rather than producing nonsense.
     *
     * Zero would make every worked day a whole day and leave HoursWorked empty,
     * which looks like a plausible answer instead of an obviously wrong one.
     */
    public function testAZeroStandardDayFallsBackInsteadOfCountingEveryDayWhole(): void
    {
        $totals = PeriodTotals::fromDays([$this->day(['HoursWorked' => 2])], 0.0);

        $this->assertSame(0.0, $totals['DaysWorked']);
        $this->assertSame(2.0, $totals['HoursWorked']);
    }

    /** Grouping by employee keeps one person's days out of another's totals. */
    public function testTotalsAreGroupedPerEmployee(): void
    {
        $totals = PeriodTotals::byEmployee([
            $this->day(['EmployeeID' => 'A', 'HoursWorked' => 8]),
            $this->day(['EmployeeID' => 'A', 'HoursWorked' => 8]),
            $this->day(['EmployeeID' => 'B', 'HoursWorked' => 4]),
        ]);

        $this->assertSame(2.0, $totals['A']['DaysWorked']);
        $this->assertSame(0.0, $totals['B']['DaysWorked']);
        $this->assertSame(4.0, $totals['B']['HoursWorked']);
    }

    /** No days is zeroes, not an empty array the caller has to guard. */
    public function testAnEmptyPeriodIsAllZeroes(): void
    {
        $this->assertSame(
            array_fill_keys(PeriodTotals::KEYS, 0.0),
            PeriodTotals::fromDays([]));
    }

    /** Provenance: Phase 6 rule #1 needs to know a row was keyed by hand. */
    public function testAManualEntryIsDetectableAmongBiometricOnes(): void
    {
        $biometric = [
            $this->day(['Source' => 'Biometric']),
            $this->day(['Source' => 'Biometric']),
        ];

        $this->assertFalse(PeriodTotals::hasManualEntry($biometric));
        $this->assertTrue(PeriodTotals::hasManualEntry(
            array_merge($biometric, [$this->day(['Source' => 'Manual'])])));
    }

    /** @param array<string, mixed> $overrides */
    private function day(array $overrides = []): array
    {
        return array_merge([
            'EmployeeID' => 'EMP-TEST',
            'WorkDate' => '2026-07-01',
            'HoursWorked' => 0,
            'OvertimeHours' => 0,
            'LateMinutes' => 0,
            'UndertimeMinutes' => 0,
            'IsAbsent' => 0,
            'DayType' => 'Regular',
            'Source' => 'Manual',
        ], $overrides);
    }
}
