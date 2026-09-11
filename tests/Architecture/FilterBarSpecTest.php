<?php
/**
 * ============================================================================
 * FilterBarSpecTest - a filter dropdown a screen offers is a facet the query
 * core actually has.
 *
 * WHY THIS EXISTS
 * The two halves fail in opposite directions and only one of them is loud.
 *
 * A misspelled SORT key is refused: FilterSpec treats sorting as the one place
 * a payload becomes an identifier, so an unknown key throws and the screen is
 * visibly broken the first time anyone picks it.
 *
 * A misspelled FILTER key is IGNORED, by design - a stale bookmark naming a
 * facet that has since been dropped should still open the screen rather than
 * error. The cost is that a typo in a view is invisible: the dropdown renders
 * (FacetOptions returns nothing for a key it does not know, so it shows "All"
 * and nothing else), selecting from it is impossible, and the list quietly
 * returns every row while the filter bar implies it is filtered. Nobody
 * reports a filter that was never offered.
 *
 * That is the same shape as the bug this guard was written beside: the
 * Documents filter bar carried ONE hardcoded Status list for all five tabs,
 * offering memoranda an "Inactive" no memo can hold while hiding the "Revoked"
 * they do - for as long as the screen existed, with nothing failing.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use Digos\Domain\Query\FilterSpec;
use PHPUnit\Framework\TestCase;

final class FilterBarSpecTest extends TestCase
{
    public function testEveryFacetOfferedIsAnOptionFacetOfThatEntity(): void
    {
        $tabs = self::documentTabs();

        foreach ($tabs as $tab => $cfg) {
            if (!$cfg['facets']) continue;

            $this->assertNotSame('', $cfg['entity'],
                "The '$tab' tab offers filter dropdowns but names no FilterSpec entity.");

            $known = array_keys(FilterSpec::optionColumns($cfg['entity']));

            foreach ($cfg['facets'] as $key) {
                $this->assertContains($key, $known,
                    "The '$tab' tab offers a '$key' dropdown, which is not an option facet of "
                    . "{$cfg['entity']}. An unknown filter key is IGNORED rather than refused, so "
                    . 'this renders an empty dropdown over an unfiltered list and never fails. '
                    . 'Known: ' . implode(', ', $known) . '.');
            }
        }
    }

    /**
     * Offering a sort key the entity has no allowlist entry for throws out of
     * FilterSpec, so this test fails by exception rather than by assertion -
     * with the same message a user would have seen in the fail() envelope.
     */
    public function testEverySortOfferedIsInThatEntitysSortAllowlist(): void
    {
        foreach (self::documentTabs() as $tab => $cfg) {
            if (!$cfg['sorts']) continue;

            foreach ($cfg['sorts'] as $key) {
                // Put through fromPayload() rather than compared against a
                // copy of the allowlist: the contract being checked is that
                // the real code path accepts the key, and a second table to
                // read it from is a second table that can drift.
                FilterSpec::fromPayload($cfg['entity'], ['sort' => $key]);
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A tab that names a facet endpoint must offer dropdowns, and vice versa.
     *
     * Either half alone is dead weight that looks like a working feature: an
     * endpoint fetched and thrown away, or controls that can never populate.
     */
    public function testFacetEndpointAndFacetListTravelTogether(): void
    {
        foreach (self::documentTabs() as $tab => $cfg) {
            $this->assertSame($cfg['facetApi'] !== '', (bool) $cfg['facets'],
                "The '$tab' tab declares " . ($cfg['facetApi'] !== ''
                    ? "facetApi '{$cfg['facetApi']}' but no facets to spend it on."
                    : 'facet dropdowns but no facetApi to fill them from.'));
        }
    }

    /**
     * views/documents.php's TABS table, one entry per tab.
     *
     * Parsed rather than executed because it is JavaScript. The parse is kept
     * deliberately narrow - a tab opens on a line of exactly four spaces, a
     * name and ' {' - and the count is asserted, so a TABS table this stops
     * understanding fails here instead of passing vacuously.
     *
     * @return array<string, array{entity: string, facetApi: string,
     *                             facets: string[], sorts: string[]}>
     */
    private static function documentTabs(): array
    {
        $source = SourceTree::read('views/documents.php');

        $parts = preg_split('/^    (\w+): \{$/m', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        $tabs = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $body = $parts[$i + 1];

            // Stop at the next tab so a later tab's keys cannot bleed into
            // this one; preg_split already did that, but the tail after the
            // last tab runs on into the rest of the file.
            $body = preg_split('/^  \};$/m', $body)[0];

            $tabs[$parts[$i]] = [
                'entity' => self::scalar($body, 'entity'),
                'facetApi' => self::scalar($body, 'facetApi'),
                'facets' => self::pairKeys($body, 'facets'),
                'sorts' => self::pairKeys($body, 'sorts'),
            ];
        }

        self::assertCount(5, $tabs,
            'views/documents.php no longer parses as five tabs - this guard has stopped '
            . 'reading the table it is meant to be checking.');

        return $tabs;
    }

    /** `entity: 'Memorandum',` -> 'Memorandum'. */
    private static function scalar(string $body, string $key): string
    {
        return preg_match("/\b$key: '([^']+)'/", $body, $m) ? $m[1] : '';
    }

    /**
     * `facets: [['Status', 'Status'], ['OfficeCode', 'Office']]` -> the first
     * element of each pair, which is the key the payload carries. The second
     * is the visible label and is nobody's business but the reader's.
     *
     * @return string[]
     */
    private static function pairKeys(string $body, string $key): array
    {
        if (!preg_match("/\b$key: \[(.*?)\]\],/s", $body, $m)) return [];

        preg_match_all("/\['([^']+)',/", $m[1] . ']', $pairs);
        return $pairs[1];
    }
}
