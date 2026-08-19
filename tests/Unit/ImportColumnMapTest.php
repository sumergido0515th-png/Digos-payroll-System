<?php
/**
 * ============================================================================
 * ImportColumnMapTest - Digos\Domain\Import\ColumnMap.
 *
 * WHY THIS EXISTS
 * This class guesses. That is its job - an office's spreadsheet says "Surname"
 * where the schema says `LastName`, and requiring the file to be renamed first
 * is the manual step the import exists to remove. What makes a guess safe is
 * that it is shown for confirmation, and what makes the confirmation useful is
 * that the score is honest: a match reported as confident had better be one,
 * because that is the row an operator will skim past.
 *
 * The competition cases matter most. Two fields wanting one column is where a
 * naive first-match-wins mapper puts the employee number into the employee id
 * and nobody notices until the import creates twelve new records instead of
 * updating twelve.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Import\ColumnMap;
use PHPUnit\Framework\TestCase;

final class ImportColumnMapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/Domain/Import/ColumnMap.php';
    }

    /** The employee spec's shape, trimmed to what each test needs. */
    private const SPEC = [
        'LastName' => ['Last Name', 'Surname'],
        'FirstName' => ['First Name', 'Given Name'],
        'OfficeCode' => ['Office Code', 'Office'],
        'SalaryRate' => ['Daily Rate', 'Rate'],
        'Position' => ['Position Title'],
    ];

    public function testExactFieldNameMatches(): void
    {
        $map = ColumnMap::propose(['LastName', 'FirstName'], self::SPEC);

        $this->assertSame(0, $map['LastName']['column']);
        $this->assertSame(100, $map['LastName']['score']);
        $this->assertTrue($map['LastName']['confident']);
    }

    public function testAliasMatches(): void
    {
        $map = ColumnMap::propose(['Surname', 'Given Name'], self::SPEC);

        $this->assertSame(0, $map['LastName']['column']);
        $this->assertSame(1, $map['FirstName']['column']);
        $this->assertTrue($map['FirstName']['confident']);
    }

    public function testSpacingCasingAndPunctuationAreIgnored(): void
    {
        $map = ColumnMap::propose(['  last_name  ', 'FIRST NAME'], self::SPEC);

        $this->assertSame(0, $map['LastName']['column']);
        $this->assertSame(1, $map['FirstName']['column']);
    }

    public function testUnitInBracketsIsIgnored(): void
    {
        $map = ColumnMap::propose(['Daily Rate (PHP)'], self::SPEC);

        $this->assertSame(0, $map['SalaryRate']['column']);
        $this->assertTrue($map['SalaryRate']['confident']);
    }

    public function testATypoStillMatchesButIsNotReportedAsConfident(): void
    {
        $map = ColumnMap::propose(['Positon Title'], self::SPEC);

        $this->assertSame(0, $map['Position']['column']);
        $this->assertLessThan(ColumnMap::CONFIDENT, $map['Position']['score']);
        $this->assertFalse($map['Position']['confident'],
            'A typo match must be offered for review, never applied silently.');
    }

    public function testUnrelatedHeadingMatchesNothing(): void
    {
        $map = ColumnMap::propose(['Remarks'], self::SPEC);

        foreach ($map as $field => $m) {
            $this->assertNull($m['column'], $field . ' claimed an unrelated column.');
        }
    }

    /**
     * The case a first-match-wins mapper gets wrong. Both fields score against
     * "Employee No"; the exact match has to win, and the loser must then take
     * nothing rather than the next column along.
     */
    public function testTwoFieldsCompetingForOneColumnResolveByScore(): void
    {
        $spec = [
            'EmployeeID' => ['Employee ID', 'System ID'],
            'EmployeeNo' => ['Employee No', 'Employee Number'],
        ];

        $map = ColumnMap::propose(['Employee No'], $spec);

        $this->assertSame(0, $map['EmployeeNo']['column']);
        $this->assertSame(100, $map['EmployeeNo']['score']);
        $this->assertNull($map['EmployeeID']['column']);
    }

    /**
     * "Office Code" and "Office Name" both contain the OfficeCode alias
     * "Office". The exact match must take the column it belongs to.
     */
    public function testContainmentDoesNotBeatAnExactMatch(): void
    {
        $spec = [
            'OfficeCode' => ['Office Code', 'Office'],
            'OfficeName' => ['Office Name', 'Name'],
        ];

        $map = ColumnMap::propose(['Office Name', 'Office Code'], $spec);

        $this->assertSame(1, $map['OfficeCode']['column']);
        $this->assertSame(0, $map['OfficeName']['column']);
    }

    public function testOneColumnIsNeverAssignedToTwoFields(): void
    {
        $map = ColumnMap::propose(['Rate'], self::SPEC);

        $claimed = array_filter(array_column($map, 'column'), fn($c) => $c !== null);
        $this->assertSame(count($claimed), count(array_unique($claimed)));
    }

    /* ==================================================================
     * Hand corrections
     * ================================================================ */

    public function testOverrideWins(): void
    {
        $map = ColumnMap::propose(['Surname', 'Nickname'], self::SPEC, ['LastName' => 1]);

        $this->assertSame(1, $map['LastName']['column']);
        $this->assertSame('Nickname', $map['LastName']['header']);
        $this->assertTrue($map['LastName']['confident']);
    }

    /** An empty override is the preview screen's "do not import this field". */
    public function testEmptyOverrideUnmapsAConfidentGuess(): void
    {
        $map = ColumnMap::propose(['Surname'], self::SPEC, ['LastName' => '']);

        $this->assertNull($map['LastName']['column']);
    }

    public function testOverridePointingPastTheLastColumnIsIgnored(): void
    {
        $map = ColumnMap::propose(['Surname'], self::SPEC, ['LastName' => 9]);

        $this->assertNull($map['LastName']['column']);
    }

    /* ==================================================================
     * Reporting back
     * ================================================================ */

    public function testColumnsNothingClaimedAreReported(): void
    {
        $headers = ['Surname', 'Biometric No', 'Given Name'];
        $map = ColumnMap::propose($headers, self::SPEC);

        $spare = ColumnMap::unmatched($headers, $map);

        $this->assertArrayHasKey(1, $spare);
        $this->assertSame('Biometric No', $spare[1]);
    }

    /**
     * "Rate 2024" contains the SalaryRate alias "Rate". The column is worth
     * offering - it probably is the rate - but claiming it confidently is how
     * last year's figures get imported as this year's, so it has to land below
     * the confidence line and be shown for review.
     */
    public function testContainmentIsOfferedButNeverConfident(): void
    {
        $map = ColumnMap::propose(['Rate 2024'], self::SPEC);

        $this->assertSame(0, $map['SalaryRate']['column'], 'The column should still be offered.');
        $this->assertFalse($map['SalaryRate']['confident'],
            'A partial-word match must never be applied without review.');
    }

    /**
     * The general form of the rule above: only an exact match on the field name
     * or one of its listed spellings is ever reported confident.
     */
    public function testOnlyExactMatchesAreConfident(): void
    {
        $headers = ['Surname', 'Rate 2024', 'Positon Title', 'Office'];
        $map = ColumnMap::propose($headers, self::SPEC);

        foreach ($map as $field => $m) {
            if ($m['column'] === null) continue;
            $this->assertSame($m['confident'], $m['score'] === 100,
                $field . ' is reported confident on a score of ' . $m['score']
                . ' - only an exact match may be.');
        }
    }

    public function testEveryFieldInTheSpecAppearsInTheMap(): void
    {
        $map = ColumnMap::propose(['Surname'], self::SPEC);

        $this->assertSame(array_keys(self::SPEC), array_keys($map));
    }

    public function testBlankHeadingsClaimNothing(): void
    {
        $map = ColumnMap::propose(['', 'Surname'], self::SPEC);

        $this->assertSame(1, $map['LastName']['column']);
    }
}
