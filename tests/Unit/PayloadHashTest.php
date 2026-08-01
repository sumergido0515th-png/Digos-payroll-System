<?php
/**
 * ============================================================================
 * PayloadHashTest - determinism is the entire mechanism.
 *
 * A hash that changes for a reason unrelated to the payroll's actual content -
 * column order, a redundant key, float vs string - is a false tamper alarm,
 * and one false alarm is what teaches a pre-auditor to stop trusting every
 * alarm after it. These tests exist to make that failure mode impossible
 * rather than merely unlikely.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Print\PayloadHash;
use PHPUnit\Framework\TestCase;

final class PayloadHashTest extends TestCase
{
    private function line(array $overrides = []): array
    {
        return array_merge([
            'DetailID' => 'PD-0001', 'PayrollNo' => 'PR-2026-000001', 'LineNo' => 1,
            'EmployeeID' => 'EMP-001', 'ChargedOfficeCode' => 'CMO', 'FunctionCode' => 'GEN',
            'SalaryRate' => 520.00, 'DaysWorked' => 10, 'HoursWorked' => 0, 'OvertimeHours' => 0,
            'LateMinutes' => 0, 'UndertimeMinutes' => 0, 'AbsentDays' => 0,
            'GrossPay' => 5200.00, 'Tax' => 0, 'CashAdvance' => 0, 'OtherDeductions' => 0,
            'TotalDeductions' => 0, 'NetPay' => 5200.00,
        ], $overrides);
    }

    /* --------------------------------------------------------- determinism */

    public function testTheSameInputsAlwaysHashTheSame(): void
    {
        $lines = [$this->line()];
        $a = PayloadHash::compute($lines, [], []);
        $b = PayloadHash::compute($lines, [], []);

        $this->assertSame($a, $b);
    }

    public function testLineOrderDoesNotAffectTheHash(): void
    {
        $one = $this->line(['LineNo' => 1, 'EmployeeID' => 'EMP-001']);
        $two = $this->line(['LineNo' => 2, 'EmployeeID' => 'EMP-002']);

        $forward = PayloadHash::compute([$one, $two], [], []);
        $reversed = PayloadHash::compute([$two, $one], [], []);

        $this->assertSame($forward, $reversed,
            'PayrollDetails rows arriving in a different order produced a different hash.');
    }

    /** DetailID is a random id assigned on every insert - not content. */
    public function testARegeneratedDetailIdDoesNotChangeTheHash(): void
    {
        $original = PayloadHash::compute([$this->line(['DetailID' => 'PD-0001'])], [], []);
        $resplit = PayloadHash::compute([$this->line(['DetailID' => 'PD-9999'])], [], []);

        $this->assertSame($original, $resplit,
            'A suspension split, which reassigns DetailID, would falsely register as tampering.');
    }

    public function testNumericAndStringFormsOfTheSameAmountHashTheSame(): void
    {
        $asFloat = PayloadHash::compute([$this->line(['SalaryRate' => 520.00])], [], []);
        $asString = PayloadHash::compute([$this->line(['SalaryRate' => '520.00'])], [], []);
        $asShortString = PayloadHash::compute([$this->line(['SalaryRate' => '520'])], [], []);

        $this->assertSame($asFloat, $asString);
        $this->assertSame($asFloat, $asShortString);
    }

    public function testAttachmentCoverageOrderDoesNotAffectTheHash(): void
    {
        $a = ['AttachmentID' => 'ATT-1', 'EmployeeID' => 'EMP-001', 'CoveredDate' => '2026-07-01'];
        $b = ['AttachmentID' => 'ATT-2', 'EmployeeID' => 'EMP-001', 'CoveredDate' => '2026-07-02'];

        $forward = PayloadHash::compute([$this->line()], [$a, $b], []);
        $reversed = PayloadHash::compute([$this->line()], [$b, $a], []);

        $this->assertSame($forward, $reversed);
    }

    public function testHolidayOrderDoesNotAffectTheHash(): void
    {
        $h1 = ['HolidayDate' => '2026-07-04', 'DayType' => 'RegularHoliday'];
        $h2 = ['HolidayDate' => '2026-07-11', 'DayType' => 'SpecialNonWorking'];

        $forward = PayloadHash::compute([$this->line()], [], [$h1, $h2]);
        $reversed = PayloadHash::compute([$this->line()], [], [$h2, $h1]);

        $this->assertSame($forward, $reversed);
    }

    /* -------------------------------------------------------- sensitivity */

    public function testAChangedNetPayChangesTheHash(): void
    {
        $before = PayloadHash::compute([$this->line(['NetPay' => 5200.00])], [], []);
        $after = PayloadHash::compute([$this->line(['NetPay' => 6200.00])], [], []);

        $this->assertNotSame($before, $after,
            'Editing NetPay after approval must be detectable - this is the entire point.');
    }

    public function testARemovedLineChangesTheHash(): void
    {
        $twoLines = PayloadHash::compute(
            [$this->line(['LineNo' => 1, 'EmployeeID' => 'EMP-001']),
             $this->line(['LineNo' => 2, 'EmployeeID' => 'EMP-002'])], [], []);
        $oneLine = PayloadHash::compute([$this->line(['LineNo' => 1, 'EmployeeID' => 'EMP-001'])], [], []);

        $this->assertNotSame($twoLines, $oneLine,
            'A payroll line disappearing after approval must be detectable.');
    }

    public function testARemovedAttachmentChangesTheHash(): void
    {
        $covered = ['AttachmentID' => 'ATT-1', 'EmployeeID' => 'EMP-001', 'CoveredDate' => '2026-07-01'];

        $withAttachment = PayloadHash::compute([$this->line()], [$covered], []);
        $withoutAttachment = PayloadHash::compute([$this->line()], [], []);

        $this->assertNotSame($withAttachment, $withoutAttachment,
            'Deleting the evidence behind an approved line must be detectable, even though it '
            . 'never touches PayrollDetails.');
    }

    public function testAWithdrawnHolidayChangesTheHash(): void
    {
        $holiday = ['HolidayDate' => '2026-07-04', 'DayType' => 'RegularHoliday'];

        $withHoliday = PayloadHash::compute([$this->line()], [], [$holiday]);
        $withoutHoliday = PayloadHash::compute([$this->line()], [], []);

        $this->assertNotSame($withHoliday, $withoutHoliday);
    }

    public function testTheHashIsALowercaseHexSha256(): void
    {
        $hash = PayloadHash::compute([$this->line()], [], []);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
