<?php
/**
 * ============================================================================
 * RuleEngineTest - Phase 6's exit gate.
 *
 * "Fixture payrolls produce exactly the expected findings, verified by
 *  automated test - not manual read-through."
 *
 * EXACTLY is the word that matters, and it is asserted literally: every test
 * compares the FULL list of rule ids, not "contains". A rule that fires when it
 * should not is as much a defect as one that stays silent - a pre-audit that
 * cries wolf teaches its users to click past the one finding that mattered.
 *
 * The known-good payroll producing ZERO findings is the load-bearing test in
 * this file. Without it, every other test here would pass just as well against
 * an engine that flagged everything.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Rules\RuleEngine;
use Digos\Domain\Rules\Severity;
use PHPUnit\Framework\TestCase;

final class RuleEngineTest extends TestCase
{
    private const EMP = 'EMP-1';
    private const PERIOD_START = '2026-07-01';
    private const PERIOD_END = '2026-07-15';

    /* ================================================== the known-good payroll */

    /** A correct payroll is silent. Everything else in this file depends on it. */
    public function testAKnownGoodPayrollProducesNoFindingsAtAll(): void
    {
        $this->assertSame([], $this->ruleIds($this->clean()),
            'The clean fixture must be silent, or no other test in this file proves anything.');
    }

    /** And nothing in it blocks printing. */
    public function testAKnownGoodPayrollDoesNotBlockPrinting(): void
    {
        $this->assertFalse(Severity::blocks(RuleEngine::validate($this->clean())));
    }

    /* ============================================ document integrity */

    /** DOC-001: hand-keyed hours with nothing on file. */
    public function testAManualDayWithNoJustificationIsFlagged(): void
    {
        $context = $this->clean();
        $context['attachmentCoverage'] = [];
        $context['bioExemptions'] = [];

        $this->assertSame(['DOC-001'], $this->ruleIds($context));
        $this->assertSame(Severity::WARNING, $this->first($context)->severity,
            'A late scan must not stop people being paid.');
    }

    /** A travel order covering the day justifies it just as well. */
    public function testATravelOrderJustifiesAManualDay(): void
    {
        $context = $this->clean();
        $context['attachmentCoverage'] = [];
        $context['bioExemptions'] = [];
        $context['travelOrders'] = [$this->travelOrder(['DepartDate' => '2026-07-06',
            'ReturnDate' => '2026-07-06'])];

        $this->assertSame([], $this->ruleIds($context));
    }

    /** DOC-002: a travel order for a different period entirely. */
    public function testATravelOrderOutsideThePeriodIsFlagged(): void
    {
        $context = $this->clean();
        $context['travelOrders'] = [$this->travelOrder([
            'DepartDate' => '2025-03-01', 'ReturnDate' => '2025-03-03'])];

        $this->assertSame(['DOC-002'], $this->ruleIds($context));
    }

    /** DOC-003: two attachments, one hash. */
    public function testTwoAttachmentsWithOneHashAreFlagged(): void
    {
        $context = $this->clean();
        $context['attachments'] = [
            ['AttachmentID' => 'A1', 'FileName' => 'memo.pdf', 'Sha256' => str_repeat('a', 64)],
            ['AttachmentID' => 'A2', 'FileName' => 'memo-copy.pdf', 'Sha256' => str_repeat('a', 64)],
        ];

        $this->assertSame(['DOC-003'], $this->ruleIds($context));
    }

    /** DOC-004: one control number on two memoranda. */
    public function testADuplicateControlNumberIsFlagged(): void
    {
        $context = $this->clean();
        $context['memoranda'][] = $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-001']);

        $this->assertSame(['DOC-004'], $this->ruleIds($context));
    }

    /* ==================================================== conflicts */

    /** CON-001: absent and travelling on the same day. */
    public function testAbsentAndTravellingOnTheSameDayIsFlagged(): void
    {
        $context = $this->clean();
        $context['dtrDays'][] = $this->day(['WorkDate' => '2026-07-07',
            'HoursWorked' => 0, 'IsAbsent' => 1]);
        $context['travelOrders'] = [$this->travelOrder(['DepartDate' => '2026-07-07',
            'ReturnDate' => '2026-07-07'])];

        $this->assertSame(['CON-001'], $this->ruleIds($context));
    }

    /** CON-002: an exemption excusing a punch that exists. */
    public function testAnExemptionOverABiometricDayIsFlagged(): void
    {
        $context = $this->clean();
        $context['dtrDays'][] = $this->day(['WorkDate' => '2026-07-08',
            'Source' => 'Biometric']);
        $context['bioExemptions'] = [$this->exemption()];

        $this->assertSame(['CON-002'], $this->ruleIds($context));
    }

    /**
     * CON-003: the same person on two overlapping payrolls.
     *
     * Redacted per Phase 2: the message names the employee, whom the reader
     * can already see, and never the other payroll's number or office.
     */
    public function testAnOverlappingPayrollIsFlaggedWithoutNamingTheOtherOne(): void
    {
        $context = $this->clean();
        $context['overlappingPayrolls'] = [
            ['EmployeeID' => self::EMP, 'EmployeeName' => 'DELA CRUZ, Juan'],
        ];

        $this->assertSame(['CON-003'], $this->ruleIds($context));

        $message = $this->first($context)->message;
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
        $this->assertStringNotContainsString('PR-', $message,
            'The other payroll number must not appear - it may be outside the reader\'s scope.');
    }

    /** CON-004: overtime with no memorandum authorising it. */
    public function testOvertimeWithNoMemorandumIsFlagged(): void
    {
        $context = $this->clean();
        $context['dtrDays'][0]['OvertimeHours'] = 3;
        $context['memoranda'] = [];
        $context['memorandumCoverage'] = [];

        $this->assertSame(['CON-004'], $this->ruleIds($context));
    }

    /** Overtime inside an authorising memorandum is silent. */
    public function testOvertimeInsideItsAuthorityIsNotFlagged(): void
    {
        $context = $this->clean();
        $context['dtrDays'][0]['OvertimeHours'] = 3;

        $this->assertSame([], $this->ruleIds($context));
    }

    /**
     * CON-005: more overtime claimed than the memo's own window allows.
     *
     * BLOCKER, unlike a missing document: this figure contradicts the
     * authority cited on the same voucher.
     */
    public function testOvertimeBeyondTheAuthorisedWindowIsBlocked(): void
    {
        $context = $this->clean();
        $context['dtrDays'][0]['OvertimeHours'] = 6;
        $context['memoranda'][0]['TimeFrom'] = '18:00';
        $context['memoranda'][0]['TimeTo'] = '22:00';

        $this->assertSame(['CON-005'], $this->ruleIds($context));
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
        $this->assertStringContainsString('at most 4', $this->first($context)->message);
    }

    /* ======================================== shift and computation */

    /** SHF-001: a day predating every version of the shift. */
    public function testADayBeforeAnyShiftVersionIsFlagged(): void
    {
        $context = $this->clean();
        $context['shiftVersions'] = [[
            'ShiftID' => 'S-9', 'ShiftCode' => 'OFFICE', 'VersionNo' => 1,
            'TimeIn' => '08:00', 'TimeOut' => '17:00', 'RestDays' => '',
            'EffectiveFrom' => '2027-01-01', 'EffectiveTo' => null,
        ]];

        $this->assertSame(['SHF-001'], $this->ruleIds($context));
    }

    /** SHF-002: night differential with no window defined. */
    public function testNightDifferentialWithoutAWindowIsFlagged(): void
    {
        $context = $this->clean();
        $context['lines'][0]['NightDiffHours'] = 2;

        $this->assertSame(['SHF-002'], $this->ruleIds($context));
    }

    /** SHF-003: a rest day worked with no memorandum. */
    public function testARestDayWorkedWithoutAMemorandumIsFlagged(): void
    {
        $context = $this->clean();
        // 2026-07-06 is a Monday; make Monday the rest day.
        $context['shiftVersions'][0]['RestDays'] = '1';
        $context['memoranda'] = [];
        $context['memorandumCoverage'] = [];

        // The same day is now also overtime-less manual work, so DOC-001 does
        // not fire - the attachment still covers it. Only the rest day does.
        $this->assertSame(['SHF-003'], $this->ruleIds($context));
    }

    /** CMP-001: the contract ends inside the period. */
    public function testAContractEndingBeforeThePeriodEndsIsFlagged(): void
    {
        $context = $this->clean();
        $context['contracts'][0]['EndDate'] = '2026-07-10';

        $this->assertSame(['CMP-001'], $this->ruleIds($context));
    }

    /** CMP-002: the line and the contract disagree about the rate. */
    public function testALineRateThatContradictsTheContractIsBlocked(): void
    {
        $context = $this->clean();
        $context['lines'][0]['SalaryRate'] = 600.00;

        $this->assertSame(['CMP-002'], $this->ruleIds($context));
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
        $this->assertStringContainsString('600.00', $this->first($context)->message);
        $this->assertStringContainsString('500.00', $this->first($context)->message);
    }

    /**
     * A missing contract is reported, not passed over.
     *
     * A check that could not run and a check that found nothing look identical
     * from outside, and only one of them is reassuring.
     */
    public function testAnAbsentContractIsReportedRatherThanSilentlyPassed(): void
    {
        $context = $this->clean();
        $context['contracts'] = [];

        $this->assertSame(['CMP-002'], $this->ruleIds($context));
        $this->assertSame(Severity::INFO, $this->first($context)->severity,
            'Unable to check is not the same as checked and blocked.');
        $this->assertStringContainsString('could not be checked', $this->first($context)->message);
    }

    /** CMP-003: a line that pays nothing. */
    public function testANonPositiveNetPayIsBlocked(): void
    {
        $context = $this->clean();
        $context['lines'][0]['NetPay'] = 0;
        $context['payroll']['TotalNet'] = 0;

        $this->assertSame(['CMP-003'], $this->ruleIds($context));
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
    }

    /** CMP-003 again: deductions swallowing the gross. */
    public function testDeductionsExceedingGrossAreBlocked(): void
    {
        $context = $this->clean();
        $context['lines'][0]['TotalDeductions'] = 9000;

        $this->assertSame(['CMP-003'], $this->ruleIds($context));
    }

    /* ====================================== form completeness */

    /** FRM-001: an unsigned form. */
    public function testAnEmptySignatoryBlockIsFlagged(): void
    {
        $context = $this->clean();
        $context['payroll']['ApprovedBy'] = '';

        $this->assertSame(['FRM-001'], $this->ruleIds($context));
    }

    /** FRM-002: a header stating a total its lines do not add up to. */
    public function testAHeaderTotalThatContradictsItsLinesIsBlocked(): void
    {
        $context = $this->clean();
        $context['payroll']['TotalNet'] = 9999.00;

        $this->assertSame(['FRM-002'], $this->ruleIds($context));
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
    }

    /**
     * FRM-003: more lines than the printed form has rows.
     *
     * PRINT_ROWS and MaxEmployeesPerPayroll are both 15 and both load-bearing
     * for the printed geometry.
     */
    public function testMoreLinesThanTheFormHoldsIsFlagged(): void
    {
        $context = $this->clean();
        $total = 0.0;

        for ($i = 0; $i < 16; $i++) {
            $line = $this->line(['EmployeeID' => 'EMP-' . $i]);
            $context['lines'][$i] = $line;
            $context['contracts'][$i] = $this->contract(['EmployeeID' => 'EMP-' . $i]);
            $total += (float) $line['NetPay'];
        }
        $context['payroll']['TotalNet'] = $total;

        $this->assertSame(['FRM-003'], $this->ruleIds($context));
    }

    /* ============================================ calendar */

    /** CAL-001: worked on a declared non-working day with no memorandum. */
    public function testWorkOnAHolidayWithoutAMemorandumIsFlagged(): void
    {
        $context = $this->clean();
        $context['holidays'] = [$this->holiday()];
        $context['memoranda'] = [];
        $context['memorandumCoverage'] = [];

        $this->assertSame(['CAL-001'], $this->ruleIds($context));
    }

    /** With a memorandum, the same day is silent. */
    public function testWorkOnAHolidayWithAMemorandumIsNotFlagged(): void
    {
        $context = $this->clean();
        $context['holidays'] = [$this->holiday()];

        $this->assertSame([], $this->ruleIds($context));
    }

    /** CAL-003: a declaration nobody can cite. */
    public function testAHolidayWithNoLegalBasisIsFlagged(): void
    {
        $context = $this->clean();
        $context['holidays'] = [$this->holiday(['HolidayDate' => '2026-07-20', 'LegalBasis' => ''])];

        $this->assertSame(['CAL-003'], $this->ruleIds($context));
    }

    /* ============================================ scope */

    /** SCP-001: a line charged outside the preparer's scope. */
    public function testALineChargedOutsideThePreparersScopeIsBlocked(): void
    {
        $context = $this->clean();
        $context['preparerOfficeCodes'] = ['CMO'];
        $context['lines'][0]['ChargedOfficeCode'] = 'OCEEM';

        $this->assertSame(['SCP-001'], $this->ruleIds($context));
        $this->assertSame(Severity::BLOCKER, $this->first($context)->severity);
    }

    /** A wildcard-scoped preparer has nothing to check. */
    public function testAWildcardScopedPreparerTriggersNoScopeFinding(): void
    {
        $context = $this->clean();
        $context['preparerOfficeCodes'] = null;
        $context['lines'][0]['ChargedOfficeCode'] = 'ANYWHERE';

        $this->assertSame([], $this->ruleIds($context));
    }

    /* ============================================ ordering and shape */

    /** Findings come back most severe first, and deterministically. */
    public function testFindingsAreOrderedBySeverityAndAreDeterministic(): void
    {
        $context = $this->clean();
        $context['lines'][0]['SalaryRate'] = 600.00;         // CMP-002 BLOCKER
        $context['payroll']['ApprovedBy'] = '';              // FRM-001 WARNING
        $context['memoranda'][] = $this->memo(['MemoID' => 'M-2', 'ControlNo' => 'CN-001']);

        $first = $this->ruleIds($context);
        $second = $this->ruleIds($context);

        $this->assertSame(['CMP-002', 'FRM-001', 'DOC-004'], $first);
        $this->assertSame($first, $second, 'Two runs must produce the same list in the same order.');
    }

    /** Every finding carries a rule id, a severity and a message. */
    public function testEveryFindingIsFullyFormed(): void
    {
        $context = $this->clean();
        $context['lines'][0]['NetPay'] = -1;
        $context['payroll']['TotalNet'] = -1;

        foreach (RuleEngine::validateToArray($context) as $finding) {
            $this->assertNotSame('', $finding['RuleID']);
            $this->assertContains($finding['Severity'], Severity::ORDER);
            $this->assertNotSame('', $finding['Message']);
        }
    }

    /* =============================================================== fixture */

    /** @return string[] */
    private function ruleIds(array $context): array
    {
        return array_map(fn($f) => $f->ruleId, RuleEngine::validate($context));
    }

    private function first(array $context)
    {
        return RuleEngine::validate($context)[0];
    }

    /**
     * A payroll with nothing wrong with it.
     *
     * One employee, one hand-keyed day covered by an attachment, a contract
     * whose rate matches the line, a shift in force, a memorandum authorising
     * overtime, and a header whose total equals its lines.
     *
     * @return array<string, mixed>
     */
    private function clean(): array
    {
        return [
            'periodStart' => self::PERIOD_START,
            'periodEnd' => self::PERIOD_END,

            'payroll' => [
                'PayrollNo' => 'PR-CLEAN',
                'PreparedBy' => 'SANTOS, Maria',
                'ApprovedBy' => 'REYES, Pedro',
                'TotalNet' => 5000.00,
            ],
            'lines' => [$this->line()],

            'dtrDays' => [$this->day()],

            // The manual day is covered by an attachment, so DOC-001 is quiet.
            'attachmentCoverage' => [
                ['AttachmentID' => 'A-1', 'EmployeeID' => self::EMP, 'CoveredDate' => '2026-07-06'],
            ],
            'attachments' => [
                ['AttachmentID' => 'A-1', 'FileName' => 'memo.pdf', 'Sha256' => str_repeat('b', 64)],
            ],

            'bioExemptions' => [],
            'travelOrders' => [],

            'memoranda' => [$this->memo()],
            'memorandumCoverage' => [['MemoID' => 'M-1', 'EmployeeID' => self::EMP]],

            'contracts' => [$this->contract()],
            'shiftVersions' => [[
                'ShiftID' => 'S-1', 'ShiftCode' => 'OFFICE', 'VersionNo' => 1,
                'TimeIn' => '08:00', 'TimeOut' => '17:00', 'BreakMinutes' => 60,
                'RestDays' => '', 'NightDiffFrom' => null, 'NightDiffTo' => null,
                'EffectiveFrom' => '2026-01-01', 'EffectiveTo' => null,
            ]],

            'holidays' => [],
            'holidayPayRules' => [],
            'officeScope' => ['City' => 'Digos'],
            'employmentTypes' => [self::EMP => 'JO'],

            'overlappingPayrolls' => [],
            'preparerOfficeCodes' => null,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function line(array $overrides = []): array
    {
        return array_merge([
            'EmployeeID' => self::EMP,
            'ChargedOfficeCode' => 'CMO',
            'SalaryRate' => 500.00,
            'GrossPay' => 5500.00,
            'TotalDeductions' => 500.00,
            'NetPay' => 5000.00,
            'NightDiffHours' => 0,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function day(array $overrides = []): array
    {
        return array_merge([
            'EmployeeID' => self::EMP,
            'WorkDate' => '2026-07-06',
            'HoursWorked' => 8,
            'OvertimeHours' => 0,
            'IsAbsent' => 0,
            'Source' => 'Manual',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function memo(array $overrides = []): array
    {
        return array_merge([
            'MemoID' => 'M-1',
            'ControlNo' => 'CN-001',
            'AuthorityType' => 'Overtime',
            'OfficeCode' => 'CMO',
            'EffectivityType' => 'Range',
            'EffectivityStart' => self::PERIOD_START,
            'EffectivityEnd' => self::PERIOD_END,
            'TimeFrom' => null,
            'TimeTo' => null,
            'SpecificDates' => null,
            'RecurrenceDays' => null,
            'SupersedesID' => null,
            'DateIssued' => '2026-06-28',
            'Status' => 'Active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function contract(array $overrides = []): array
    {
        return array_merge([
            'ContractID' => 'CON-1',
            'EmployeeID' => self::EMP,
            'Rate' => 500.00,
            'StartDate' => '2026-01-01',
            'EndDate' => null,
            'Status' => 'Active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function travelOrder(array $overrides = []): array
    {
        return array_merge([
            'TravelOrderID' => 'TO-1',
            'TravelOrderNo' => 'TO-001',
            'EmployeeID' => self::EMP,
            'Destination' => 'Davao City',
            'DepartDate' => '2026-07-06',
            'ReturnDate' => '2026-07-06',
            'Status' => 'Active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function exemption(array $overrides = []): array
    {
        return array_merge([
            'ExemptionID' => 'BEX-1',
            'EmployeeID' => self::EMP,
            'ReasonCode' => 'FIELDWORK',
            'Reason' => 'Assigned to field work',
            'ValidFrom' => self::PERIOD_START,
            'ValidTo' => self::PERIOD_END,
            'Status' => 'Active',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function holiday(array $overrides = []): array
    {
        return array_merge([
            'HolidayID' => 'H-1',
            'HolidayDate' => '2026-07-06',
            'HolidayName' => 'City charter day',
            'DayType' => 'LocalHoliday',
            'ScopeLevel' => 'City',
            'ScopeCode' => 'Digos',
            'StartTime' => null,
            'EndTime' => null,
            'LegalBasis' => 'City Ordinance 2026-01',
            'Status' => 'Active',
        ], $overrides);
    }
}
