<?php
/**
 * ============================================================================
 * CsvTest - the export format, against fixtures.
 *
 * This class never queries anything, so its correctness is entirely about the
 * text it produces: RFC 4180 quoting, formula-injection neutralisation, and
 * the header lines the phase's task list calls for. A row's own scope
 * correctness is FilterScopeTest's job, upstream of this one.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testEmptyResultStillNamesTheFilters(): void
    {
        $csv = Csv::render([], ['OfficeCode in (CMO)'], 'Payroll');

        $this->assertSame(
            "Payroll export\r\n"
                . "Filters: OfficeCode in (CMO)\r\n"
                . "\r\n"
                . "No matching rows.\r\n",
            $csv);
    }

    public function testNoFiltersReadsAsNoneRatherThanBlank(): void
    {
        $csv = Csv::render([], [], 'Payroll');

        $this->assertStringContainsString('Filters: none', $csv);
    }

    public function testColumnsComeFromTheRowsInTheOrderTheQueryReturnedThem(): void
    {
        $rows = [
            ['PayrollNo' => 'PR-1', 'OfficeCode' => 'CMO'],
            ['PayrollNo' => 'PR-2', 'OfficeCode' => 'OCM'],
        ];

        $csv = Csv::render($rows, [], 'Payroll');

        $this->assertSame(
            "Payroll export\r\n"
                . "Filters: none\r\n"
                . "\r\n"
                . "PayrollNo,OfficeCode\r\n"
                . "PR-1,CMO\r\n"
                . "PR-2,OCM\r\n",
            $csv);
    }

    /* -------------------------------------------------------------- quoting */

    public function testACommaInAValueIsQuoted(): void
    {
        $csv = Csv::render([['LastName' => 'DELA CRUZ, JUAN']], [], 'Employees');

        $this->assertStringContainsString('"DELA CRUZ, JUAN"', $csv);
    }

    public function testAQuoteInAValueIsDoubledAndTheFieldIsQuoted(): void
    {
        $csv = Csv::render([['Remarks' => 'Marked "urgent" by HR']], [], 'Payroll');

        $this->assertStringContainsString('"Marked ""urgent"" by HR"', $csv);
    }

    public function testANewlineInAValueIsQuoted(): void
    {
        $csv = Csv::render([['Remarks' => "line one\nline two"]], [], 'Payroll');

        $this->assertStringContainsString("\"line one\nline two\"", $csv);
    }

    /* ------------------------------------------------------ formula injection */

    /**
     * =, +, -, @ and a leading tab or CR are all live formula openers in a
     * spreadsheet. Every one of them is data this system stores as free text
     * and never reviews: Remarks, Particulars, Subject, a search box.
     */
    public function testACellOpeningWithAFormulaCharacterIsNeutralised(): void
    {
        foreach (['=SUM(A1:A9)', '+1+1', '-2+3', '@SUM(1,2)'] as $formula) {
            $csv = Csv::render([['Remarks' => $formula]], [], 'Payroll');

            $this->assertStringContainsString("'" . $formula, $csv,
                "'$formula' was not neutralised.");
        }
    }

    /** A formula character that is not the FIRST character of the cell is not a trigger. */
    public function testAFormulaCharacterInTheMiddleOfAValueIsLeftAlone(): void
    {
        $csv = Csv::render([['Position' => 'Co-Terminus']], [], 'Employees');

        $this->assertStringContainsString("Position\r\nCo-Terminus\r\n", $csv);
    }

    /**
     * A filter's own label ("search: ") always precedes the payload value it
     * describes, so the rendered cell never opens with the value's own first
     * character - the same property that makes "Co-Terminus" above safe.
     */
    public function testAFilterLinesLabelKeepsAPayloadValueFromOpeningTheCell(): void
    {
        $csv = Csv::render([], ['search: =cmd|/c calc'], 'Payroll');

        $this->assertStringContainsString('Filters: search: =cmd|/c calc', $csv);
    }

    /* ---------------------------------------------------------------- misc */

    public function testALineEndingIsCrlfThroughout(): void
    {
        $csv = Csv::render([['A' => '1']], [], 'X');

        $this->assertStringNotContainsString("\r\n\n", $csv);
        $this->assertStringEndsWith("\r\n", $csv);
    }

    public function testAMissingColumnOnOneRowRendersAsBlankRatherThanFailing(): void
    {
        $rows = [
            ['A' => '1', 'B' => '2'],
            ['A' => '3'],
        ];

        $csv = Csv::render($rows, [], 'X');

        $this->assertStringContainsString("3,\r\n", $csv);
    }
}
