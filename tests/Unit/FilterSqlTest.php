<?php
/**
 * ============================================================================
 * FilterSqlTest - FilterSpec's normalized request, turned into SQL, tested
 * against fixtures.
 *
 * Pure, so every rule below runs with no database. Values must always come
 * back as bound params, never interpolated - the cheapest place to prove that
 * is a test that runs in a millisecond.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\FilterSql;
use PHPUnit\Framework\TestCase;

final class FilterSqlTest extends TestCase
{
    /** @param array<int, array{column: string, value: string}> $facets */
    private function normalized(array $facets = [], string $searchValue = '', array $searchColumns = []): array
    {
        return [
            'facets' => $facets,
            'search' => ['columns' => $searchColumns, 'value' => $searchValue],
        ];
    }

    /**
     * This is what makes it safe for FilterSql to return "always true": the
     * fragment is only ever ANDed onto a scope clause that has already
     * decided ALLOW_ALL/DENY_ALL, never used by itself.
     */
    public function testNoFacetsAndNoSearchProducesTheAlwaysTrueFragment(): void
    {
        $result = FilterSql::build($this->normalized());

        $this->assertSame(FilterSql::NO_FILTER, $result['sql']);
        $this->assertSame('1 = 1', $result['sql']);
        $this->assertSame([], $result['params']);
    }

    public function testOneFacetProducesABoundEqualityClause(): void
    {
        $result = FilterSql::build($this->normalized([['column' => 'OfficeCode', 'value' => 'CMO']]));

        $this->assertSame('(`OfficeCode` = ?)', $result['sql']);
        $this->assertSame(['CMO'], $result['params']);
    }

    public function testMultipleFacetsAreAnded(): void
    {
        $result = FilterSql::build($this->normalized([
            ['column' => 'OfficeCode', 'value' => 'CMO'],
            ['column' => 'Status', 'value' => 'DRAFT'],
        ]));

        $this->assertSame('(`OfficeCode` = ? AND `Status` = ?)', $result['sql']);
        $this->assertSame(['CMO', 'DRAFT'], $result['params']);
    }

    public function testSearchProducesAnOredLikeGroupAcrossTheGivenColumns(): void
    {
        $result = FilterSql::build($this->normalized(
            [], 'payslip', ['PayrollNo', 'Remarks']));

        $this->assertSame('(`PayrollNo` LIKE ? OR `Remarks` LIKE ?)', $result['sql']);
        $this->assertSame(['%payslip%', '%payslip%'], $result['params']);
    }

    /**
     * The composed case, matching what PayrollRepo::listScoped() actually
     * concatenates: facets and search AND together, params in
     * facet-then-search order, ready to be array_merge()'d after scope's own
     * params.
     */
    public function testFacetsAndSearchCombineWithAnd(): void
    {
        $result = FilterSql::build($this->normalized(
            [['column' => 'Status', 'value' => 'DRAFT']],
            'payslip',
            ['PayrollNo']));

        $this->assertSame('(`Status` = ? AND (`PayrollNo` LIKE ?))', $result['sql']);
        $this->assertSame(['DRAFT', '%payslip%'], $result['params']);
    }

    public function testAnAliasPrefixesEveryColumn(): void
    {
        $result = FilterSql::build(
            $this->normalized([['column' => 'OfficeCode', 'value' => 'CMO']], 'x', ['PayrollNo']),
            'p.');

        $this->assertSame('(p.`OfficeCode` = ? AND (p.`PayrollNo` LIKE ?))', $result['sql']);
    }

    /**
     * A value shaped like SQL must never appear inside the returned SQL
     * string itself - only ever as a bound parameter.
     */
    public function testValuesAreNeverInterpolatedIntoTheSqlString(): void
    {
        $malicious = "x'; DROP TABLE Payroll; --";

        $result = FilterSql::build($this->normalized([['column' => 'Remarks', 'value' => $malicious]]));

        $this->assertStringNotContainsString($malicious, $result['sql']);
        $this->assertSame('(`Remarks` = ?)', $result['sql']);
        $this->assertSame([$malicious], $result['params']);
    }
}
