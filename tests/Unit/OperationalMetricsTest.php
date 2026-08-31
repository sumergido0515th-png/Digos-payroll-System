<?php
/**
 * ============================================================================
 * OperationalMetricsTest - the three Phase 10 baseline figures, against
 * fixtures. No database: these are pure functions over rows a repository
 * already scoped, so this suite proves the arithmetic and the definitions,
 * not the scoping - CitywideAggregateTest and the like are where a
 * disclosure gap would show up, not here.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Reports\OperationalMetrics;
use PHPUnit\Framework\TestCase;

final class OperationalMetricsTest extends TestCase
{
    /* ------------------------------------------------------- print activity */

    public function testNoPrintsIsZeroEverythingNotADivisionByZero(): void
    {
        $this->assertSame(
            ['officialPrints' => 0, 'reprints' => 0, 'reprintRate' => 0.0, 'pagesPrinted' => 0],
            OperationalMetrics::printActivity([]));
    }

    /** One Official print of one form is not a reprint - nothing to explain yet. */
    public function testASinglePrintIsNotAReprint(): void
    {
        $result = OperationalMetrics::printActivity([
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-01 09:00:00'],
        ]);

        $this->assertSame(1, $result['officialPrints']);
        $this->assertSame(0, $result['reprints']);
        $this->assertSame(0.0, $result['reprintRate']);
        $this->assertSame(1, $result['pagesPrinted']);
    }

    /**
     * The second Official print of the SAME (PayrollNo, Form) pair is a
     * reprint - the exact definition PrintDoc.php's recordOfficialPrint()
     * enforces server-side (a reason is required from exactly this print).
     */
    public function testASecondPrintOfTheSamePayrollAndFormIsAReprint(): void
    {
        $result = OperationalMetrics::printActivity([
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-01 09:00:00'],
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-02 09:00:00'],
        ]);

        $this->assertSame(2, $result['officialPrints']);
        $this->assertSame(1, $result['reprints']);
        $this->assertSame(0.5, $result['reprintRate']);
    }

    /** Different FORMS of the same payroll are independent - a Summary reprint is not a Payroll one. */
    public function testDifferentFormsOfTheSamePayrollAreNotGroupedTogether(): void
    {
        $result = OperationalMetrics::printActivity([
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-01 09:00:00'],
            ['PayrollNo' => 'PR-1', 'Form' => 'summary', 'PrintedAt' => '2026-08-01 09:01:00'],
        ]);

        $this->assertSame(0, $result['reprints']);
    }

    /** Every Official print is at least one page, third+ reprint included. */
    public function testPagesPrintedCountsEveryOfficialPrintEvent(): void
    {
        $result = OperationalMetrics::printActivity([
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-01 09:00:00'],
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-02 09:00:00'],
            ['PayrollNo' => 'PR-1', 'Form' => 'payroll', 'PrintedAt' => '2026-08-03 09:00:00'],
        ]);

        $this->assertSame(3, $result['pagesPrinted']);
        $this->assertSame(2, $result['reprints']);
    }

    /* --------------------------------------------------- suspension activity */

    public function testNoSuspensionsIsEmptyGroundsAndNullTurnaround(): void
    {
        $result = OperationalMetrics::suspensionActivity([]);

        $this->assertSame([], $result['topGrounds']);
        $this->assertSame(0, $result['settledCount']);
        $this->assertNull($result['averageTurnaroundHours']);
    }

    public function testGroundsAreCountedAndSortedMostFrequentFirst(): void
    {
        $result = OperationalMetrics::suspensionActivity([
            ['GroundCode' => 'MISSING_ATTACHMENT', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null],
            ['GroundCode' => 'MISSING_ATTACHMENT', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null],
            ['GroundCode' => 'NO_MEMO', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null],
        ]);

        $this->assertSame(
            [['GroundCode' => 'MISSING_ATTACHMENT', 'Count' => 2], ['GroundCode' => 'NO_MEMO', 'Count' => 1]],
            $result['topGrounds']);
    }

    /** Only the top 5 are kept, however many distinct grounds were raised. */
    public function testOnlyTheTopFiveGroundsAreKept(): void
    {
        $rows = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $ground) {
            $rows[] = ['GroundCode' => $ground, 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null];
        }

        $result = OperationalMetrics::suspensionActivity($rows);

        $this->assertCount(5, $result['topGrounds']);
    }

    /** A blank ground code (a pre-auditor's own judgement call, RuleID NULL) is still counted, not dropped. */
    public function testABlankGroundCodeIsCountedAsItsOwnBucket(): void
    {
        $result = OperationalMetrics::suspensionActivity([
            ['GroundCode' => '', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null],
        ]);

        $this->assertSame('(no ground given)', $result['topGrounds'][0]['GroundCode']);
    }

    /** Turnaround is measured only over settled suspensions - an open one has none yet. */
    public function testAnOpenSuspensionDoesNotCountTowardTurnaround(): void
    {
        $result = OperationalMetrics::suspensionActivity([
            ['GroundCode' => 'X', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => null],
        ]);

        $this->assertSame(0, $result['settledCount']);
        $this->assertNull($result['averageTurnaroundHours']);
    }

    public function testTurnaroundIsHoursFromRaisedToSettledAveragedAcrossSettledOnes(): void
    {
        $result = OperationalMetrics::suspensionActivity([
            // 24 hours.
            ['GroundCode' => 'X', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => '2026-08-02 00:00:00'],
            // 48 hours. Average is 36.
            ['GroundCode' => 'X', 'RaisedAt' => '2026-08-01 00:00:00', 'SettledAt' => '2026-08-03 00:00:00'],
        ]);

        $this->assertSame(2, $result['settledCount']);
        $this->assertSame(36.0, $result['averageTurnaroundHours']);
    }

    /** A settlement timestamp somehow before it was raised is data corruption, not turnaround - excluded, not negative. */
    public function testASettlementBeforeItWasRaisedIsExcludedRatherThanCountedNegative(): void
    {
        $result = OperationalMetrics::suspensionActivity([
            ['GroundCode' => 'X', 'RaisedAt' => '2026-08-02 00:00:00', 'SettledAt' => '2026-08-01 00:00:00'],
        ]);

        $this->assertSame(0, $result['settledCount']);
        $this->assertNull($result['averageTurnaroundHours']);
    }
}
