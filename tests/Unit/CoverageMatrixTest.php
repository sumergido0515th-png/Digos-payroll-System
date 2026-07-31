<?php
/**
 * ============================================================================
 * CoverageMatrixTest - Phase 5's exit gate, matrix half.
 *
 * "A red cell corresponds to a real, verifiable gap in a test dataset."
 *
 * The interesting assertions are the ones about what is NOT red. A matrix that
 * flags everything is as useless as one that flags nothing, and it is much
 * easier to write by accident - so half of this file is cells that must stay
 * quiet.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Coverage\CoverageMatrix;
use PHPUnit\Framework\TestCase;

final class CoverageMatrixTest extends TestCase
{
    private const EMP = 'EMP-1';
    private const DATE = '2026-07-06';

    /* ------------------------------------------------------- the red cell */

    /** Hand-keyed hours with nothing on file is the finding. */
    public function testHandKeyedHoursWithNoJustificationIsUnjustified(): void
    {
        $m = $this->build([$this->day()]);

        $this->assertSame(CoverageMatrix::UNJUSTIFIED, $this->state($m));
        $this->assertSame(
            [['EmployeeID' => self::EMP, 'Date' => self::DATE,
                'reason' => 'Hours were keyed by hand and nothing on file explains the missing punch.']],
            $m['gaps']);
    }

    /** A biometric day is its own evidence and is never a gap. */
    public function testABiometricDayIsNeverAGap(): void
    {
        $m = $this->build([$this->day(['Source' => 'Biometric'])]);

        $this->assertSame(CoverageMatrix::BIOMETRIC, $this->state($m));
        $this->assertSame([], $m['gaps']);
    }

    /* --------------------------------------------- what justifies a cell */

    /** A travel order covering the date explains the missing punch. */
    public function testATravelOrderCoveringTheDateJustifiesIt(): void
    {
        $m = $this->build([$this->day()], [], [$this->travelOrder()]);

        $this->assertSame(CoverageMatrix::TRAVEL_ORDER, $this->state($m));
        $this->assertStringContainsString('TO-001', $this->cell($m)['reason']);
        $this->assertSame([], $m['gaps']);
    }

    /** A travel order for another date does not. */
    public function testATravelOrderForAnotherDateDoesNotJustifyIt(): void
    {
        $m = $this->build([$this->day()], [],
            [$this->travelOrder(['DepartDate' => '2026-07-20', 'ReturnDate' => '2026-07-22'])]);

        $this->assertSame(CoverageMatrix::UNJUSTIFIED, $this->state($m));
    }

    /**
     * A travel order with no return date covers only the day it departed.
     *
     * Treating a missing return as open-ended would excuse every day after it,
     * forever, on one unfinished record.
     */
    public function testATravelOrderWithNoReturnCoversOnlyItsDepartureDay(): void
    {
        $order = $this->travelOrder(['DepartDate' => self::DATE, 'ReturnDate' => null]);

        $onTheDay = $this->build([$this->day()], [], [$order]);
        $theNextDay = $this->build(
            [$this->day(['WorkDate' => '2026-07-07'])], [], [$order], [], ['2026-07-07']);

        $this->assertSame(CoverageMatrix::TRAVEL_ORDER, $this->state($onTheDay));
        $this->assertSame(CoverageMatrix::UNJUSTIFIED,
            $theNextDay['cells'][self::EMP]['2026-07-07']['state']);
    }

    /** A cancelled travel order justifies nothing. */
    public function testAnInactiveTravelOrderDoesNotJustifyAnything(): void
    {
        $m = $this->build([$this->day()], [], [$this->travelOrder(['Status' => 'Cancelled'])]);

        $this->assertSame(CoverageMatrix::UNJUSTIFIED, $this->state($m));
    }

    /** A bio exemption covering the date explains it. */
    public function testABioExemptionCoveringTheDateJustifiesIt(): void
    {
        $m = $this->build([$this->day()], [], [], [$this->exemption()]);

        $this->assertSame(CoverageMatrix::BIO_EXEMPTION, $this->state($m));
        $this->assertStringContainsString('FIELDWORK', $this->cell($m)['reason']);
    }

    /**
     * An exemption with no end date does run indefinitely.
     *
     * Unlike a travel order - an exemption for a permanent condition is
     * exactly what an open end means.
     */
    public function testAnOpenEndedExemptionKeepsCovering(): void
    {
        $m = $this->build([$this->day(['WorkDate' => '2027-01-01'])], [], [],
            [$this->exemption(['ValidTo' => null])], ['2027-01-01']);

        $this->assertSame(CoverageMatrix::BIO_EXEMPTION,
            $m['cells'][self::EMP]['2027-01-01']['state']);
    }

    /** An attachment bound to the date explains it. */
    public function testAnAttachmentBoundToTheDateJustifiesIt(): void
    {
        $m = $this->build([$this->day()],
            [['AttachmentID' => 'ATT-1', 'EmployeeID' => self::EMP, 'CoveredDate' => self::DATE]]);

        $this->assertSame(CoverageMatrix::ATTACHMENT, $this->state($m));
        $this->assertSame(['ATT-1'], $this->cell($m)['AttachmentIDs']);
    }

    /** An attachment bound to somebody else's date does not. */
    public function testAnAttachmentBoundToAnotherEmployeeDoesNotJustifyIt(): void
    {
        $m = $this->build([$this->day()],
            [['AttachmentID' => 'ATT-1', 'EmployeeID' => 'SOMEBODY-ELSE',
                'CoveredDate' => self::DATE]]);

        $this->assertSame(CoverageMatrix::UNJUSTIFIED, $this->state($m));
    }

    /** Precedence is fixed, so a day with two reasons reports the same one. */
    public function testATravelOrderOutranksAnExemptionOnTheSameDay(): void
    {
        $m = $this->build([$this->day()], [], [$this->travelOrder()], [$this->exemption()]);

        $this->assertSame(CoverageMatrix::TRAVEL_ORDER, $this->state($m),
            'The order must be fixed, or the same data reports differently between runs.');
    }

    /* ------------------------------------------ what must NOT be red */

    /** A rest day is not a gap - nobody claimed anything. */
    public function testARestDayWithNoRecordIsNotAGap(): void
    {
        $m = CoverageMatrix::build([self::EMP], [self::DATE], [], [], [], [], [],
            [self::EMP . '|' . self::DATE => true]);

        $this->assertSame(CoverageMatrix::REST_DAY, $this->state($m));
        $this->assertSame([], $m['gaps']);
    }

    /** Nor is a holiday nobody worked. */
    public function testAHolidayNotWorkedIsNotAGap(): void
    {
        $m = CoverageMatrix::build([self::EMP], [self::DATE], [], [], [], [],
            [self::DATE => ['day_type' => 'RegularHoliday', 'holiday_name' => 'Heroes Day']]);

        $this->assertSame(CoverageMatrix::HOLIDAY, $this->state($m));
        $this->assertStringContainsString('Heroes Day', $this->cell($m)['reason']);
    }

    /**
     * A special WORKING day is an ordinary day with a ceremonial name.
     *
     * The distinction only bites on a day nobody worked: a regular holiday
     * explains the empty cell, a special working day does not, and the two
     * must not report the same thing.
     */
    public function testASpecialWorkingDayIsNotTreatedAsAHoliday(): void
    {
        $working = CoverageMatrix::build([self::EMP], [self::DATE], [], [], [], [],
            [self::DATE => ['day_type' => 'SpecialWorking', 'holiday_name' => 'Founders Day']]);
        $holiday = CoverageMatrix::build([self::EMP], [self::DATE], [], [], [], [],
            [self::DATE => ['day_type' => 'RegularHoliday', 'holiday_name' => 'Heroes Day']]);

        $this->assertSame(CoverageMatrix::NO_RECORD, $this->state($working),
            'A special working day is an ordinary day; nothing about it explains an empty cell.');
        $this->assertSame(CoverageMatrix::HOLIDAY, $this->state($holiday));
    }

    /**
     * A holiday the employee DID work still needs its punch explained.
     *
     * Working a holiday does not make the missing biometric record any less
     * missing - the person was there, so the device should have seen them.
     */
    public function testAHolidayWorkedByHandIsStillUnjustified(): void
    {
        $m = CoverageMatrix::build([self::EMP], [self::DATE], [$this->day()], [], [], [],
            [self::DATE => ['day_type' => 'RegularHoliday', 'holiday_name' => 'Heroes Day']]);

        $this->assertSame(CoverageMatrix::UNJUSTIFIED, $this->state($m));
    }

    /** A day with no record at all is not a gap either. */
    public function testADayWithNoRecordIsNotAGap(): void
    {
        $m = $this->build([]);

        $this->assertSame(CoverageMatrix::NO_RECORD, $this->state($m));
        $this->assertSame([], $m['gaps']);
    }

    /** An absence is a recorded fact, not an unexplained one. */
    public function testAnAbsenceIsNotAGap(): void
    {
        $m = $this->build([$this->day(['IsAbsent' => 1, 'HoursWorked' => 0])]);

        $this->assertSame(CoverageMatrix::ABSENT, $this->state($m));
        $this->assertSame([], $m['gaps']);
    }

    /* ------------------------------------------------------- the whole grid */

    /** Every employee gets every date, and the counts add up. */
    public function testTheMatrixIsCompleteAndCountsEveryCell(): void
    {
        $dates = ['2026-07-06', '2026-07-07', '2026-07-08'];

        $m = CoverageMatrix::build(['A', 'B'], $dates, [
            ['EmployeeID' => 'A', 'WorkDate' => '2026-07-06', 'HoursWorked' => 8, 'Source' => 'Biometric'],
            ['EmployeeID' => 'A', 'WorkDate' => '2026-07-07', 'HoursWorked' => 8, 'Source' => 'Manual'],
            ['EmployeeID' => 'B', 'WorkDate' => '2026-07-06', 'HoursWorked' => 8, 'Source' => 'Manual'],
        ]);

        $this->assertCount(2, $m['cells']);
        $this->assertCount(3, $m['cells']['A']);
        $this->assertSame(6, array_sum($m['counts']), 'Two employees times three dates.');
        $this->assertSame(2, $m['counts'][CoverageMatrix::UNJUSTIFIED]);
        $this->assertCount(2, $m['gaps']);
    }

    /* -------------------------------------------------------------- fixture */

    private function build(
        array $days,
        array $coverage = [],
        array $travelOrders = [],
        array $exemptions = [],
        array $dates = [self::DATE]
    ): array {
        return CoverageMatrix::build(
            [self::EMP], $dates, $days, $coverage, $travelOrders, $exemptions);
    }

    private function cell(array $matrix, string $date = self::DATE): array
    {
        return $matrix['cells'][self::EMP][$date];
    }

    private function state(array $matrix, string $date = self::DATE): string
    {
        return $this->cell($matrix, $date)['state'];
    }

    /** @param array<string, mixed> $overrides */
    private function day(array $overrides = []): array
    {
        return array_merge([
            'EmployeeID' => self::EMP,
            'WorkDate' => self::DATE,
            'HoursWorked' => 8,
            'IsAbsent' => 0,
            'Source' => 'Manual',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function travelOrder(array $overrides = []): array
    {
        return array_merge([
            'TravelOrderID' => 'TO-X',
            'TravelOrderNo' => 'TO-001',
            'EmployeeID' => self::EMP,
            'Destination' => 'Davao City',
            'DepartDate' => '2026-07-06',
            'ReturnDate' => '2026-07-08',
            'Status' => 'Active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function exemption(array $overrides = []): array
    {
        return array_merge([
            'ExemptionID' => 'BEX-X',
            'EmployeeID' => self::EMP,
            'ReasonCode' => 'FIELDWORK',
            'Reason' => 'Assigned to field work',
            'ValidFrom' => '2026-07-01',
            'ValidTo' => '2026-07-31',
            'Status' => 'Active',
        ], $overrides);
    }
}
