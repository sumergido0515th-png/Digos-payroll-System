<?php
/**
 * ============================================================================
 * FilterSpecTest - the allowlist, against fixtures.
 *
 * FilterSpec is pure, so this suite needs no database: arrays in, arrays out,
 * the Phase 4 and 6 shape. What it is proving is narrow and specific - that
 * nothing a caller can type becomes an identifier, and that the normalisation
 * reads a blank field the way a person filling in a form means it.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\FilterSpec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FilterSpecTest extends TestCase
{
    /** The condition for one facet column, or null when it was not applied. */
    private function conditionOn(FilterSpec $spec, string $column): ?array
    {
        foreach ($spec->conditions() as $condition) {
            if (($condition['column'] ?? null) === $column) return $condition;
        }
        return null;
    }

    /* ------------------------------------------------------- what it accepts */

    public function testAnEmptyPayloadFiltersNothing(): void
    {
        $this->assertSame([], FilterSpec::fromPayload('Payroll', [])->conditions());
    }

    public function testAnExactFacetBecomesOneEqualityCondition(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['PayrollNo' => 'PR-2026-0001']);

        $this->assertSame(
            ['op' => 'exact', 'column' => 'PayrollNo', 'values' => ['PR-2026-0001']],
            $this->conditionOn($spec, 'PayrollNo'));
    }

    /** A shareable link spells a two-value facet with a comma. */
    public function testAMultiValueFacetSplitsOnCommas(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['Status' => 'DRAFT,FOR_PRE_AUDIT']);

        $this->assertSame(
            ['op' => 'in', 'column' => 'Status', 'values' => ['DRAFT', 'FOR_PRE_AUDIT']],
            $this->conditionOn($spec, 'Status'));
    }

    public function testAMultiValueFacetAlsoAcceptsAnArray(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['Status' => ['DRAFT', ' FOR_PRE_AUDIT ']]);

        $this->assertSame(['DRAFT', 'FOR_PRE_AUDIT'],
            $this->conditionOn($spec, 'Status')['values']);
    }

    public function testRepeatedValuesCollapse(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['Status' => 'DRAFT,DRAFT,DRAFT']);

        $this->assertSame(['DRAFT'], $this->conditionOn($spec, 'Status')['values']);
    }

    /* ------------------------------------------------------- what it ignores */

    /**
     * A cleared dropdown posts an empty string. Reading that as a real value
     * would return nothing at all and look exactly like a scope problem.
     */
    public function testABlankValueIsNotAFilter(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', [
            'OfficeCode' => '', 'Status' => '   ', 'search' => '', 'PayrollNo' => null]);

        $this->assertSame([], $spec->conditions());
    }

    public function testAnEmptyItemInsideAMultiValueFacetIsDropped(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['Status' => 'DRAFT,,  ,CANCELLED']);

        $this->assertSame(['DRAFT', 'CANCELLED'],
            $this->conditionOn($spec, 'Status')['values']);
    }

    /**
     * The payload is the whole request body, so unrecognised keys are ordinary
     * rather than suspicious - and ignoring one can only fail to narrow, never
     * widen past the scope the repository ANDs on separately.
     */
    public function testKeysThatAreNotFacetsAreIgnored(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', [
            'action' => 'apiListPayrolls',
            'TotalNet' => '999999',
            'PasswordHash' => 'x',
            'OfficeCode' => 'CMO',
        ]);

        $this->assertCount(1, $spec->conditions());
        $this->assertSame(['CMO'], $this->conditionOn($spec, 'OfficeCode')['values']);
    }

    /**
     * A column that exists on the table but is not a facet stays unreachable.
     * PreparedBy is the live example: it is searchable as free text, but it is
     * a display name that two people can share, so it is not filterable on its
     * own - PreparedByUser is.
     */
    public function testANonFacetColumnCannotBeFilteredDirectly(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['PreparedBy' => 'SANTOS, Maria']);

        $this->assertSame([], $spec->conditions());
    }

    /* --------------------------------------------------------- what it refuses */

    /**
     * A date the caller typed is a filter they believe is applied. Dropping it
     * silently shows them more rows than they asked for and tells them nothing,
     * so this is the one malformed VALUE that refuses rather than being ignored.
     */
    public function testAMalformedDateIsRefusedInWordsTheCallerCanActdOn(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2026-08-29');

        FilterSpec::fromPayload('Payroll', ['CreatedFrom' => '29/08/2026']);
    }

    public function testADateThatIsNotOnTheCalendarIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        FilterSpec::fromPayload('Payroll', ['CreatedFrom' => '2026-02-30']);
    }

    public function testAWellFormedDateIsKept(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['CreatedFrom' => '2026-08-01']);

        $this->assertSame(
            ['op' => 'datetimeFrom', 'column' => 'DateCreated', 'values' => ['2026-08-01']],
            $this->conditionOn($spec, 'DateCreated'));
    }

    /**
     * An entity with no facet map fails loudly, as ScopeEntity does.
     *
     * DtrDays is a real table and deliberately not searchable: the DTR grid is
     * reached per period per employee, not by filtering days across the city.
     * Using a real-but-unregistered table rather than a made-up name is what
     * makes this assert the registry rather than a typo.
     */
    public function testAnUnregisteredEntityIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FilterSpec::fromPayload('DtrDays', []);
    }

    /* ------------------------------------------------------------ sorting */

    public function testTheDefaultSortIsNewestFirst(): void
    {
        $spec = FilterSpec::unfiltered('Payroll');

        $this->assertSame(['DateCreated'], $spec->sortColumns());
        $this->assertSame('DESC', $spec->sortDirection());
        $this->assertSame('PayrollNo', $spec->tiebreakColumn());
    }

    /** Sort keys are UI words, deliberately unlike the schema. */
    public function testASortKeyResolvesToItsColumn(): void
    {
        $spec = FilterSpec::fromPayload('Payroll', ['sort' => 'aging', 'direction' => 'asc']);

        $this->assertSame(['SubmittedAt'], $spec->sortColumns());
        $this->assertSame('ASC', $spec->sortDirection());
    }

    /**
     * One sort key, two columns - "by name" is surname then first name.
     * Collapsing it would reorder everyone sharing a surname on every load.
     */
    public function testASortKeyMayNameSeveralColumns(): void
    {
        $spec = FilterSpec::unfiltered('Employees');

        $this->assertSame(['LastName', 'FirstName'], $spec->sortColumns());
        $this->assertSame('ASC', $spec->sortDirection());
    }

    /**
     * THE ONE PAYLOAD VALUE THAT WOULD BECOME AN IDENTIFIER. It is refused
     * rather than ignored: silently falling back to the default sort would
     * hide a broken saved view instead of reporting it.
     */
    public function testAnUnknownSortKeyIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        FilterSpec::fromPayload('Payroll', ['sort' => 'TotalNet; DROP TABLE Payroll']);
    }

    public function testAColumnNameIsNotASortKey(): void
    {
        $this->expectException(RuntimeException::class);

        FilterSpec::fromPayload('Payroll', ['sort' => 'DateCreated']);
    }

    public function testAnUnknownDirectionIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        FilterSpec::fromPayload('Payroll', ['sort' => 'net', 'direction' => 'DESC; --']);
    }

    /* ------------------------------------------------- every entity is whole */

    /**
     * An entity in FACETS with no SORTS, DEFAULT_SORT or KEYS entry is a fatal
     * the moment anyone searches it, and nothing else would catch it: the four
     * maps are separate constants, and adding an entity means remembering all
     * four. This is the check that remembers.
     */
    public function testEveryEntityIsDeclaredInEveryMap(): void
    {
        $entities = FilterSpec::entities();
        $this->assertNotEmpty($entities);

        foreach ($entities as $entity) {
            $spec = FilterSpec::unfiltered($entity);

            $this->assertNotEmpty($spec->sortColumns(),
                "'$entity' resolves to no sort column - check SORTS and DEFAULT_SORT.");
            $this->assertNotSame('', $spec->tiebreakColumn(),
                "'$entity' has no KEYS entry, so its ordering is not total.");
            $this->assertContains($spec->sortDirection(), ['ASC', 'DESC']);
        }
    }

    /** Every entity can also be filtered and searched without throwing. */
    public function testEveryEntityAcceptsItsOwnFacets(): void
    {
        foreach (FilterSpec::entities() as $entity) {
            foreach (FilterSpec::optionColumns($entity) as $facet => $column) {
                $spec = FilterSpec::fromPayload($entity, [$facet => 'SOME-VALUE']);

                $this->assertNotNull($this->conditionOn($spec, $column),
                    "The '$facet' dropdown on '$entity' offers choices that filter nothing.");
            }
        }
    }

    /* --------------------------------------------------------- facet options */

    /**
     * The options a screen offers and the filters it may then apply come from
     * one map, so a dropdown cannot offer a choice that filters nothing.
     */
    public function testEveryOptionFacetIsAlsoAFilterableFacet(): void
    {
        $options = FilterSpec::optionColumns('Payroll');

        $this->assertNotEmpty($options);

        foreach ($options as $facet => $column) {
            $spec = FilterSpec::fromPayload('Payroll', [$facet => 'SOME-VALUE']);

            $this->assertNotNull($this->conditionOn($spec, $column),
                "The '$facet' dropdown offers choices that filter nothing.");
        }
    }
}
