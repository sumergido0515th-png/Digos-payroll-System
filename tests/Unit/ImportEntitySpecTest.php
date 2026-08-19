<?php
/**
 * ============================================================================
 * ImportEntitySpecTest - Digos\Domain\Import\EntitySpec::coerce().
 *
 * WHY THIS EXISTS
 * Every value in an imported file is text, and every one of these conversions
 * has a silent-failure version that would be easier to write: read the date
 * with strtotime() and take whatever comes back, strip the commas off the rate
 * and cast to float, default an unrecognised employment type to Job Order.
 * Each of those turns a bad cell into a plausible record rather than a
 * refusal - and a plausible record is what reaches a printed voucher.
 *
 * The date cases carry the most weight. A contract date decides contract
 * validity, and Phase 6 refuses a payroll over an expired contract - so a date
 * read as the wrong month is a rule silently not firing.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Import\EntitySpec;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportEntitySpecTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/Domain/Import/EntitySpec.php';
    }

    /* ==================================================================
     * Dates
     * ================================================================ */

    public function testIsoDatePassesThrough(): void
    {
        $this->assertSame('2026-01-05', EntitySpec::coerce('ContractStart', '2026-01-05'));
    }

    public function testSlashedDateIsReadMonthFirst(): void
    {
        // m/d/Y, matching fmtDate()'s default and what this system prints.
        $this->assertSame('2026-03-04', EntitySpec::coerce('Birthdate', '03/04/2026'));
    }

    public function testTwoDigitYearIsExpanded(): void
    {
        $this->assertSame('1992-03-14', EntitySpec::coerce('Birthdate', '3/14/92'));
    }

    /**
     * The refusal that matters. 14/03/2026 cannot be month-first, and quietly
     * switching to day-first would mean two files with identical layouts
     * importing differently - so it is refused and told what to write.
     */
    public function testAmbiguousDayFirstDateIsRefusedRatherThanReinterpreted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ambiguous.*YYYY-MM-DD/s');

        EntitySpec::coerce('Birthdate', '14/03/2026');
    }

    public function testImpossibleDateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a real date/');

        EntitySpec::coerce('Birthdate', '02/31/2026');
    }

    public function testSpelledOutMonthIsAccepted(): void
    {
        $this->assertSame('2026-03-15', EntitySpec::coerce('DateHired', '15 March 2026'));
        $this->assertSame('2026-03-15', EntitySpec::coerce('DateHired', 'March 15, 2026'));
    }

    public function testUnreadableDateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        EntitySpec::coerce('ContractEnd', 'end of the year');
    }

    public function testBlankStaysBlank(): void
    {
        // An optional date left empty is not an error - apiSaveEmployee turns
        // '' into NULL precisely so a caller may leave one out.
        $this->assertSame('', EntitySpec::coerce('Birthdate', ''));
    }

    /* ==================================================================
     * Money
     * ================================================================ */

    public function testThousandSeparatorsAndCurrencyAreStripped(): void
    {
        $this->assertSame('12450.00', EntitySpec::coerce('SalaryRate', 'PHP 12,450.00'));
    }

    public function testAccountingNegativeIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/negative/');

        EntitySpec::coerce('SalaryRate', '(500.00)');
    }

    public function testMinusSignedRateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        EntitySpec::coerce('SalaryRate', '-500');
    }

    public function testNonNumericRateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a number/');

        EntitySpec::coerce('SalaryRate', 'see attached');
    }

    /* ==================================================================
     * Controlled vocabularies
     * ================================================================ */

    public function testEmploymentTypeAbbreviationsExpand(): void
    {
        $this->assertSame('Job Order', EntitySpec::coerce('EmploymentType', 'JO'));
        $this->assertSame('Job Order', EntitySpec::coerce('EmploymentType', 'job order'));
        $this->assertSame('Contract of Service', EntitySpec::coerce('EmploymentType', 'COS'));
    }

    /** This system covers JO/COS only, and says so rather than guessing. */
    public function testPlantillaIsRefusedByName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/plantilla.*Job Order and Contract of Service/s');

        EntitySpec::coerce('EmploymentType', 'Plantilla');
    }

    public function testUnknownEmploymentTypeIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        EntitySpec::coerce('EmploymentType', 'Casual');
    }

    public function testStatusIsNormalised(): void
    {
        $this->assertSame('Active', EntitySpec::coerce('Status', 'ACTIVE'));
        $this->assertSame('Inactive', EntitySpec::coerce('Status', 'resigned'));
        $this->assertSame('Open', EntitySpec::coerce('Status', 'open'));
    }

    public function testGenderIsNormalised(): void
    {
        $this->assertSame('Male', EntitySpec::coerce('Gender', 'M'));
        $this->assertSame('Female', EntitySpec::coerce('Gender', 'babae'));
    }

    public function testBooleanColumnAcceptsTheUsualSpellings(): void
    {
        $this->assertSame('1', EntitySpec::coerce('SSSDeductionApproved', 'Yes'));
        $this->assertSame('1', EntitySpec::coerce('SSSDeductionApproved', 'x'));
        $this->assertSame('', EntitySpec::coerce('SSSDeductionApproved', 'no'));
    }

    public function testCodesAreUppercased(): void
    {
        $this->assertSame('CMO', EntitySpec::coerce('OfficeCode', 'cmo'));
    }

    public function testYearMustBeFourDigits(): void
    {
        $this->assertSame('2026', EntitySpec::coerce('PayrollYear', '2026'));

        $this->expectException(RuntimeException::class);
        EntitySpec::coerce('PayrollYear', '26');
    }

    /* ==================================================================
     * The catalogue itself
     * ================================================================ */

    public function testEverySpecNamesAnExistingSaveFunctionAndRequiredFields(): void
    {
        foreach (EntitySpec::all() as $entity => $spec) {
            $this->assertMatchesRegularExpression('/^apiSave[A-Za-z]+$/', $spec['save'],
                $entity . ' must delegate to an api* save function.');

            foreach ($spec['required'] as $field) {
                $this->assertArrayHasKey($field, $spec['fields'],
                    $entity . ": required field $field is not in the field list, so it can never be mapped.");
            }

            $this->assertArrayHasKey($spec['key'], $spec['fields'],
                $entity . ': the key field must be mappable or duplicates cannot be detected.');
        }
    }

    public function testUnknownEntityIsRefusedByName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown import type: payroll/');

        EntitySpec::get('payroll');
    }

    /**
     * Payroll is not importable, on purpose: its money is computed by
     * computeLine() from rate, days and deductions, and accepting totals from a
     * spreadsheet puts figures on a voucher this system never derived.
     */
    public function testPayrollAndDtrAreNotImportable(): void
    {
        $entities = array_keys(EntitySpec::all());

        $this->assertNotContains('payroll', $entities);
        $this->assertNotContains('dtr', $entities);
        $this->assertNotContains('dtrdays', $entities);
    }
}
