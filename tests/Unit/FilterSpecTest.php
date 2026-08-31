<?php
/**
 * ============================================================================
 * FilterSpecTest - The facet allowlist, tested against fixtures.
 *
 * Pure, so every rule below runs with no database. A wrong answer here does
 * not throw, it lets an unrecognized payload key become a column name - the
 * cheapest place to catch that is a test that runs in a millisecond, the same
 * reasoning ScopePredicateTest is built on.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\FilterSpec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FilterSpecTest extends TestCase
{
    /**
     * An unregistered entity is an unfiltered query wearing a filtered
     * query's clothes, so it must throw rather than return something usable.
     */
    public function testAnUnregisteredEntityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilterSpec::normalize('Logs', []);
    }

    public function testEmptyPayloadYieldsNoFacetsAndNoSearch(): void
    {
        $normalized = FilterSpec::normalize('Payroll', []);

        $this->assertSame([], $normalized['facets']);
        $this->assertSame('', $normalized['search']['value']);
    }

    public function testAKnownFacetIsNormalizedWithItsMappedColumn(): void
    {
        $normalized = FilterSpec::normalize('Payroll', ['OfficeCode' => 'CMO']);

        $this->assertSame([['column' => 'OfficeCode', 'value' => 'CMO']], $normalized['facets']);
    }

    public function testMultipleFacetsComposeInDeclaredOrder(): void
    {
        $normalized = FilterSpec::normalize('Payroll', [
            'Status' => 'DRAFT',
            'PeriodID' => 'PRD-1',
        ]);

        $this->assertSame([
            ['column' => 'PeriodID', 'value' => 'PRD-1'],
            ['column' => 'Status', 'value' => 'DRAFT'],
        ], $normalized['facets']);
    }

    /**
     * The injection-shaped case. The malicious string is a PAYLOAD KEY, not
     * one of FilterSpec's own facet names, so it is never read at all - it
     * cannot become a column name because it was never in the allowlist to
     * begin with.
     */
    public function testAnUnknownPayloadKeyIsIgnored(): void
    {
        $normalized = FilterSpec::normalize('Payroll', [
            'Status`; DROP TABLE Payroll; --' => 'anything',
        ]);

        $this->assertSame([], $normalized['facets']);
    }

    /** A blank form field is not a filter on "" - it is no filter at all. */
    public function testABlankStringValueIsTreatedAsAbsent(): void
    {
        $normalized = FilterSpec::normalize('Payroll', ['OfficeCode' => '   ']);

        $this->assertSame([], $normalized['facets']);
    }

    /** Dropped, not coerced to the literal string "Array" bound as a param. */
    public function testANonScalarFacetValueIsIgnoredRatherThanCoerced(): void
    {
        $normalized = FilterSpec::normalize('Payroll', ['OfficeCode' => ['CMO', 'OCEEM']]);

        $this->assertSame([], $normalized['facets']);
    }

    public function testSearchValueIsTrimmedAndCarriesTheEntitysFixedSearchColumns(): void
    {
        $normalized = FilterSpec::normalize('Payroll', ['search' => '  payroll no  ']);

        $this->assertSame('payroll no', $normalized['search']['value']);
        $this->assertSame(
            ['PayrollNo', 'OfficeCode', 'Department', 'PreparedBy', 'Remarks'],
            $normalized['search']['columns']);
    }

    /**
     * A regression guard. FunctionCode is deliberately excluded from
     * Payroll's facets until the 9999-placeholder data-entry item closes -
     * see FilterSpec's own docblock. A future contributor "helpfully"
     * re-adding it without reading that comment would make every citywide
     * Function/PPA filter look like it works while filtering nothing real.
     */
    public function testFunctionCodeIsNotYetAPayrollFacet(): void
    {
        $normalized = FilterSpec::normalize('Payroll', ['FunctionCode' => '9999']);

        $this->assertSame([], $normalized['facets']);
    }
}
