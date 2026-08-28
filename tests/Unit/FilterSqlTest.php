<?php
/**
 * ============================================================================
 * FilterSqlTest - the generated fragment, against fixtures.
 *
 * The assertions are on the SQL string itself, which is unusual and is the
 * point: this class exists to guarantee two properties that no integration
 * test can see once the query has run - that every VALUE is a placeholder, and
 * that every IDENTIFIER came from FilterSpec's map. A query returning the
 * right rows tells you nothing about either.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\FilterSql;
use PHPUnit\Framework\TestCase;

final class FilterSqlTest extends TestCase
{
    private function build(array $payload, string $alias = ''): array
    {
        return FilterSql::build(FilterSpec::fromPayload('Payroll', $payload), $alias);
    }

    /* ------------------------------------------------- the asymmetry with scope */

    /**
     * NO FILTERS MEANS MATCH_ALL, where no grants means DENY_ALL.
     *
     * This is the one place the two layers deliberately disagree, and the
     * reason is that they answer different questions: an unfiltered list is
     * the ordinary case, an ungranted user is not. It is safe only because the
     * repository always writes "WHERE (scope) AND (filters)", which the next
     * test fixes in place.
     */
    public function testNoFiltersMatchesEverything(): void
    {
        $this->assertSame(
            ['sql' => FilterSql::MATCH_ALL, 'params' => []], $this->build([]));
    }

    /**
     * MATCH_ALL is a complete boolean expression, not an empty string - so a
     * caller concatenating it into "WHERE (scope) AND (filters)" cannot
     * produce broken SQL and be tempted to drop the AND.
     */
    public function testMatchAllIsAWholeExpression(): void
    {
        $this->assertSame('1 = 1', FilterSql::MATCH_ALL);
    }

    /* -------------------------------------------------------------- the clauses */

    public function testAnExactFacetBindsItsValue(): void
    {
        $built = $this->build(['PayrollNo' => 'PR-2026-0001']);

        $this->assertSame('(`PayrollNo` = ?)', $built['sql']);
        $this->assertSame(['PR-2026-0001'], $built['params']);
    }

    public function testAMultiValueFacetEmitsOnePlaceholderPerValue(): void
    {
        $built = $this->build(['Status' => 'DRAFT,FOR_PRE_AUDIT,CANCELLED']);

        $this->assertSame('(`Status` IN (?, ?, ?))', $built['sql']);
        $this->assertSame(['DRAFT', 'FOR_PRE_AUDIT', 'CANCELLED'], $built['params']);
    }

    public function testSeveralFacetsAreAndedTogether(): void
    {
        $built = $this->build(['OfficeCode' => 'CMO', 'PayrollNo' => 'PR-2026-0001']);

        $this->assertSame('(`PayrollNo` = ? AND `OfficeCode` IN (?))', $built['sql']);
        $this->assertSame(['PR-2026-0001', 'CMO'], $built['params']);
    }

    /**
     * Clause order follows FilterSpec's facet map, not the order the payload
     * happened to arrive in - so the same filters produce the same SQL string
     * whatever order the SPA serialised its form in. That is what makes the
     * generated query worth asserting on at all, and what would let it be
     * cached or logged and compared later.
     */
    public function testClauseOrderFollowsTheFacetMapNotThePayload(): void
    {
        $filters = ['PayrollNo' => 'PR-1', 'Status' => 'DRAFT', 'OfficeCode' => 'CMO'];

        $this->assertSame(
            $this->build($filters)['sql'],
            $this->build(array_reverse($filters, true))['sql']);
    }

    public function testAnAliasIsAppliedToEveryColumn(): void
    {
        $built = $this->build(['OfficeCode' => 'CMO', 'PayrollNo' => 'PR-1'], 'h.');

        $this->assertSame('(h.`PayrollNo` = ? AND h.`OfficeCode` IN (?))', $built['sql']);
    }

    /* ------------------------------------------------------------ date ranges */

    public function testAFromDateIsInclusive(): void
    {
        $built = $this->build(['CreatedFrom' => '2026-08-01']);

        $this->assertSame('(`DateCreated` >= ?)', $built['sql']);
        $this->assertSame(['2026-08-01'], $built['params']);
    }

    /**
     * "To the 16th" has to include the 16th.
     *
     * A DATETIME compared with '2026-08-16' is compared against midnight, so
     * <= would silently drop everything stamped during the day the user named.
     * That reads as missing data rather than as an off-by-one, which is why
     * this is asserted on the SQL rather than left to the reader.
     */
    public function testATimestampToDateCoversTheWholeDayNamed(): void
    {
        $built = $this->build(['CreatedTo' => '2026-08-16']);

        $this->assertSame('(`DateCreated` < ? + INTERVAL 1 DAY)', $built['sql']);
        $this->assertSame(['2026-08-16'], $built['params']);
    }

    public function testBothEndsOfARangeCompose(): void
    {
        $built = $this->build(['CreatedFrom' => '2026-08-01', 'CreatedTo' => '2026-08-16']);

        $this->assertSame(
            '(`DateCreated` >= ? AND `DateCreated` < ? + INTERVAL 1 DAY)', $built['sql']);
        $this->assertSame(['2026-08-01', '2026-08-16'], $built['params']);
    }

    /* ---------------------------------------------------------- free text */

    public function testSearchIsOredAcrossItsColumnsAndBoundOncePerColumn(): void
    {
        $built = $this->build(['search' => 'CMO']);

        $this->assertSame(
            '((`PayrollNo` LIKE ? OR `OfficeCode` LIKE ? OR `Department` LIKE ?'
            . ' OR `PreparedBy` LIKE ? OR `Remarks` LIKE ?))',
            $built['sql']);
        $this->assertSame(array_fill(0, 5, '%CMO%'), $built['params']);
    }

    /**
     * Without escaping, searching for "50%" matches every row in scope rather
     * than the ones containing "50%". Not a disclosure - the scope predicate
     * bounds that - but a search box that lies about what it found.
     */
    public function testLikeWildcardsInTheTermAreEscaped(): void
    {
        $this->assertSame('%50\\%%', $this->build(['search' => '50%'])['params'][0]);
        $this->assertSame('%PR\\_1%', $this->build(['search' => 'PR_1'])['params'][0]);
    }

    /**
     * The backslash goes first, or escaping the others doubles back over it.
     *
     * The term the user typed is a\%b - a literal backslash, then a literal
     * percent. Escaping the percent first would give a\\%b, in which the
     * doubled backslash is itself an escaped backslash and the percent is a
     * wildcard again. Written as concatenated pieces because the alternative
     * is six consecutive backslashes in a string literal, which nobody can
     * read and everybody eventually miscounts.
     */
    public function testABackslashInTheTermIsEscapedFirst(): void
    {
        $escaped = $this->build(['search' => 'a\\%b'])['params'][0];

        $this->assertSame('%' . 'a' . '\\\\' . '\\%' . 'b' . '%', $escaped);
    }

    /* -------------------------------------------------------------- ORDER BY */

    public function testTheDefaultOrderIsNewestFirstWithAStableTiebreak(): void
    {
        $spec = FilterSpec::unfiltered('Payroll');

        $this->assertSame('`DateCreated` DESC, `PayrollNo` DESC', FilterSql::orderBy($spec));
    }

    public function testTheTiebreakIsNotRepeatedWhenItIsTheSortColumn(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['sort' => 'payrollNo']);

        $this->assertSame('`PayrollNo` DESC', FilterSql::orderBy($spec));
    }

    public function testOrderByHonoursTheAliasToo(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['sort' => 'aging', 'direction' => 'asc']);

        $this->assertSame('h.`SubmittedAt` ASC, h.`PayrollNo` ASC',
            FilterSql::orderBy($spec, 'h.'));
    }

    /* ----------------------------------------------------- the whole property */

    /**
     * The guard the rest of this file exists to support: across every facet at
     * once, no VALUE from the payload appears in the generated SQL. Each is a
     * placeholder, and the distinctive strings below would be plainly visible
     * if any one of them were interpolated instead.
     */
    public function testNoPayloadValueEverAppearsInTheSql(): void
    {
        $built = $this->build([
            'PayrollNo' => 'INJECT-PAYROLLNO',
            'OfficeCode' => 'INJECT-OFFICE,INJECT-OFFICE-2',
            'Department' => 'INJECT-DEPARTMENT',
            'FunctionCode' => 'INJECT-FUNCTION',
            'TimekeeperID' => 'INJECT-TIMEKEEPER',
            'PreparedByUser' => 'INJECT-PREPARER',
            'ApprovedByUser' => 'INJECT-APPROVER',
            'PeriodID' => 'INJECT-PERIOD',
            'Status' => 'INJECT-STATUS',
            'search' => 'INJECT-SEARCH',
            'CreatedFrom' => '2026-01-01',
            'CreatedTo' => '2026-12-31',
            'SubmittedFrom' => '2026-02-02',
            'SubmittedTo' => '2026-11-30',
            'ApprovedFrom' => '2026-03-03',
            'ApprovedTo' => '2026-10-10',
        ]);

        $this->assertStringNotContainsString('INJECT', $built['sql']);
        $this->assertStringNotContainsString('2026', $built['sql']);

        // Every facet above did apply - otherwise the assertion is vacuous.
        $this->assertSame(
            substr_count($built['sql'], '?'), count($built['params']));
        $this->assertGreaterThan(20, count($built['params']));
    }
}
