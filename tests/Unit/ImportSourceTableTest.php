<?php
/**
 * ============================================================================
 * ImportSourceTableTest - Digos\Domain\Import\SourceTable.
 *
 * WHY THIS EXISTS
 * The importer's whole safety argument is that the operator confirms a preview
 * before anything is written. That argument only holds if the preview shows
 * what is actually in the file - so the parser is the piece where a quiet
 * misread does the damage: a column shifted by one empty cell, a date read as
 * its serial number, an accented surname mangled by the wrong encoding. Each
 * of those produces a plausible-looking preview and a wrong record.
 *
 * Pure - bytes in, arrays out - so all of it is tested without a database and
 * without an actual upload. The .xlsx cases build a real workbook in memory
 * rather than carrying a binary fixture, which keeps what is being tested
 * readable in the test itself.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Import\SourceTable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportSourceTableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/Domain/Import/SourceTable.php';
    }

    /* ==================================================================
     * Delimited text
     * ================================================================ */

    public function testReadsCommaSeparatedText(): void
    {
        $table = SourceTable::parse("Code,Name\nCMO,City Mayor's Office\n", 'offices.csv');

        $this->assertSame('csv', $table['format']);
        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame([['CMO', "City Mayor's Office"]], $table['rows']);
    }

    public function testSniffsSemicolonSeparatedText(): void
    {
        $table = SourceTable::parse("Code;Name\nCMO;City Mayor\nCTO;City Treasurer\n", 'offices.csv');

        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame(['CTO', 'City Treasurer'], $table['rows'][1]);
    }

    /**
     * A comma inside a quoted address must not split the field - the case that
     * makes counting commas the wrong way to sniff a delimiter, and the reason
     * the sniffer scores consistency instead.
     */
    public function testCommaInsideQuotesDoesNotSplitTheField(): void
    {
        $table = SourceTable::parse("Name,Address\nJuan,\"Zone I, Digos City\"\n", 'e.csv');

        $this->assertSame(['Juan', 'Zone I, Digos City'], $table['rows'][0]);
    }

    public function testNewlineInsideQuotesStaysInTheField(): void
    {
        $table = SourceTable::parse("Name,Address\nJuan,\"Zone I\nDigos City\"\n", 'e.csv');

        $this->assertCount(1, $table['rows']);
        $this->assertSame("Zone I\nDigos City", $table['rows'][0][1]);
    }

    public function testDoubledQuoteBecomesOneLiteralQuote(): void
    {
        $table = SourceTable::parse("Code,Name\nCMO,\"City Mayor\"\"s Office\"\n", 'o.csv');

        $this->assertSame('City Mayor"s Office', $table['rows'][0][1]);
    }

    /* ==================================================================
     * Encoding
     * ================================================================ */

    public function testUtf8ByteOrderMarkIsStripped(): void
    {
        $table = SourceTable::parse("\xEF\xBB\xBFCode,Name\nCMO,Mayor\n", 'o.csv');

        // Without stripping, the first header is "\xEF\xBB\xBFCode" and matches
        // nothing - the whole first column silently fails to map.
        $this->assertSame('Code', $table['headers'][0]);
    }

    public function testWindows1252IsRecoveredRatherThanMangled(): void
    {
        // Excel on a Windows install writes this, not UTF-8. 0xF1 is 'n-tilde'.
        $table = SourceTable::parse("Name\nMu\xF1oz\n", 'e.csv');

        $this->assertSame('Muñoz', $table['rows'][0][0]);
    }

    public function testUtf16IsRecovered(): void
    {
        $utf16 = "\xFF\xFE" . mb_convert_encoding("Code,Name\nCMO,Mayor\n", 'UTF-16LE', 'UTF-8');
        $table = SourceTable::parse($utf16, 'o.csv');

        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame(['CMO', 'Mayor'], $table['rows'][0]);
    }

    /* ==================================================================
     * Formats that are refused rather than guessed at
     * ================================================================ */

    public function testPdfIsRefusedWithAWayOut(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/PDF.*\.xlsx or \.csv/s');

        SourceTable::parse("%PDF-1.7\n1 0 obj\n<< >>\n", 'payroll.pdf');
    }

    public function testLegacyXlsIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Excel 97-2003/');

        SourceTable::parse("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 64), 'old.xls');
    }

    public function testEmptyFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        SourceTable::parse('', 'empty.csv');
    }

    public function testFileWithOnlyAHeaderRowYieldsNoRows(): void
    {
        $table = SourceTable::parse("Code,Name\n", 'o.csv');

        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame([], $table['rows']);
    }

    /* ==================================================================
     * HTML and JSON
     * ================================================================ */

    public function testReadsTheFirstTableOnAPage(): void
    {
        $html = '<html><body><p>Ignore me</p>'
            . '<table><tr><th>Code</th><th>Name</th></tr>'
            . '<tr><td>CMO</td><td>City Mayor</td></tr></table>'
            . '<table><tr><td>other</td></tr></table></body></html>';

        $table = SourceTable::parse($html, 'page.html');

        $this->assertSame('html', $table['format']);
        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame([['CMO', 'City Mayor']], $table['rows']);
    }

    /** &nbsp; is not whitespace to trim(), so an empty pasted cell reads as one. */
    public function testNonBreakingSpaceCellReadsAsEmpty(): void
    {
        $html = '<table><tr><th>Code</th><th>Name</th></tr>'
            . '<tr><td>CMO</td><td>&nbsp;</td></tr></table>';

        $this->assertSame('', SourceTable::parse($html, 'p.html')['rows'][0][1]);
    }

    public function testReadsJsonObjects(): void
    {
        $json = '[{"Code":"CMO","Name":"City Mayor"},{"Code":"CTO","Name":"City Treasurer"}]';
        $table = SourceTable::parse($json, 'offices.json');

        $this->assertSame('json', $table['format']);
        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame(['CTO', 'City Treasurer'], $table['rows'][1]);
    }

    /**
     * A row that omits an optional key must not shorten the header for the
     * rows that do carry it, or every later column shifts left.
     */
    public function testJsonHeaderIsTheUnionOfEveryObjectsKeys(): void
    {
        $json = '[{"Code":"CMO"},{"Code":"CTO","Head":"Someone"}]';
        $table = SourceTable::parse($json, 'o.json');

        $this->assertSame(['Code', 'Head'], $table['headers']);
        $this->assertSame(['CMO', ''], $table['rows'][0]);
        $this->assertSame(['CTO', 'Someone'], $table['rows'][1]);
    }

    /* ==================================================================
     * Shaping
     * ================================================================ */

    public function testTitleRowsAboveTheTableAreSkipped(): void
    {
        $table = SourceTable::parse(",,\n,,\nCode,Name\nCMO,Mayor\n", 'o.csv');

        $this->assertSame(['Code', 'Name'], $table['headers']);
        $this->assertSame([['CMO', 'Mayor']], $table['rows']);
    }

    public function testShortRowsArePaddedToTheHeaderWidth(): void
    {
        $table = SourceTable::parse("Code,Name,Head\nCMO,Mayor\n", 'o.csv');

        $this->assertSame(['CMO', 'Mayor', ''], $table['rows'][0]);
    }

    public function testRowLimitIsEnforced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/limit is/');

        SourceTable::parse("Code\n" . str_repeat("X\n", SourceTable::MAX_ROWS + 1), 'big.csv');
    }

    /* ==================================================================
     * XLSX
     * ================================================================ */

    public function testReadsAnXlsxWorkbook(): void
    {
        $table = SourceTable::parse($this->workbook(), 'employees.xlsx');

        $this->assertSame('xlsx', $table['format']);
        $this->assertSame(['Surname', 'Daily Rate', 'Date Hired'], $table['headers']);
        $this->assertSame(['DELA CRUZ', '520', '2026-01-05'], $table['rows'][0]);
    }

    /**
     * A date cell holds a serial number and nothing else; only its style says
     * it is a date. Read without the styles, "2026-01-05" arrives as "46027".
     */
    public function testDateStyledCellBecomesACalendarDate(): void
    {
        $table = SourceTable::parse($this->workbook(), 'e.xlsx');

        $this->assertSame('2026-01-05', $table['rows'][0][2]);
    }

    /**
     * Empty cells are simply absent from the XML, so a row is rebuilt from
     * each cell's own reference. Without that, row 2's rate lands in the
     * surname column and every value after it is one place out.
     */
    public function testAbsentCellsKeepLaterColumnsAligned(): void
    {
        $table = SourceTable::parse($this->workbook(), 'e.xlsx');

        $this->assertSame(['REYES', '', '2026-01-05'], $table['rows'][1]);
    }

    /**
     * A two-sheet workbook whose first tab is not sheet1.xml. Reading the part
     * by name rather than by the workbook's own order imports the wrong tab,
     * which looks like a successful import of the wrong data.
     */
    public function testReadsTheWorkbooksFirstSheetNotTheFirstFileName(): void
    {
        $table = SourceTable::parse($this->twoSheetWorkbook(), 'two.xlsx');

        $this->assertSame(['Wanted'], $table['headers']);
        $this->assertSame([['yes']], $table['rows']);
    }

    /* ==================================================================
     * Fixture builders
     * ================================================================ */

    /**
     * A workbook with a shared-string column, a plain number, a date-styled
     * number, and a deliberately absent cell.
     *
     * A1 Surname   B1 Daily Rate  C1 Date Hired
     * A2 DELA CRUZ B2 520         C2 46027 (styled as a date)
     * A3 REYES     (no B3)        C3 46027
     */
    private function workbook(): string
    {
        $shared = '<?xml version="1.0"?><sst count="4" uniqueCount="4">'
            . '<si><t>Surname</t></si><si><t>Daily Rate</t></si><si><t>Date Hired</t></si>'
            . '<si><t>DELA CRUZ</t></si><si><t>REYES</t></si></sst>';

        // cellXfs index 1 points at numFmtId 14 (m/d/yy), a built-in date.
        $styles = '<?xml version="1.0"?><styleSheet><cellXfs count="2">'
            . '<xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>';

        $sheet = '<?xml version="1.0"?><worksheet><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c>'
            . '<c r="C1" t="s"><v>2</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2"><v>520</v></c>'
            . '<c r="C2" s="1"><v>46027</v></c></row>'
            . '<row r="3"><c r="A3" t="s"><v>4</v></c>'
            . '<c r="C3" s="1"><v>46027</v></c></row>'
            . '</sheetData></worksheet>';

        return $this->zip([
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook><sheets>'
                . '<sheet name="Staff" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships>'
                . '<Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/sharedStrings.xml' => $shared,
            'xl/styles.xml' => $styles,
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /** Two sheets where the first tab is stored as sheet2.xml. */
    private function twoSheetWorkbook(): string
    {
        $wanted = '<?xml version="1.0"?><worksheet><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Wanted</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>yes</t></is></c></row>'
            . '</sheetData></worksheet>';
        $other = '<?xml version="1.0"?><worksheet><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Wrong</t></is></c></row>'
            . '</sheetData></worksheet>';

        return $this->zip([
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook><sheets>'
                . '<sheet name="First" sheetId="1" r:id="rId7"/>'
                . '<sheet name="Second" sheetId="2" r:id="rId8"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships>'
                . '<Relationship Id="rId7" Target="worksheets/sheet2.xml"/>'
                . '<Relationship Id="rId8" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => $other,
            'xl/worksheets/sheet2.xml' => $wanted,
        ]);
    }

    /**
     * Builds a ZIP with every entry stored uncompressed.
     *
     * Stored rather than deflated so the fixture exercises the container
     * walking without depending on how zlib happens to compress; the deflate
     * path is what a real Excel file uses and is covered by the deployment
     * package tests elsewhere.
     *
     * @param array<string,string> $files
     */
    private function zip(array $files): string
    {
        $local = '';
        $central = '';

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $offset = strlen($local);

            $local .= "PK\x03\x04" . pack('vvvvvVVVvv',
                20, 0, 0, 0, 0, $crc, $size, $size, strlen($name), 0) . $name . $content;

            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV',
                20, 20, 0, 0, 0, 0, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }

        return $local . $central . "PK\x05\x06" . pack('vvvvVVv',
            0, 0, count($files), count($files), strlen($central), strlen($local), 0);
    }
}
