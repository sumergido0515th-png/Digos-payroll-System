<?php
/**
 * ============================================================================
 * ExportTableTest - public/export.php's EXPORTABLE table never drifts from
 * public/api.php's ROUTES table.
 *
 * WHY THIS EXISTS
 * export.php cannot look the permission up from ROUTES at runtime - api.php
 * is executable top-level code, not includable - so it carries its own copy.
 * A copy that drifts is a real hole: widen it (export.php names a weaker
 * permission than the list route requires) and a caller can download data
 * their role could not otherwise see; narrow it and an export merely breaks,
 * which is at least the safe direction to fail in but still not one this
 * guard should let through silently.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ExportTableTest extends TestCase
{
    public function testEveryExportablePermissionMatchesItsListRoute(): void
    {
        $exportable = SourceTree::exportable();
        $routes = SourceTree::routes();

        $this->assertNotEmpty($exportable, 'EXPORTABLE parsed empty - the parser is probably broken.');

        foreach ($exportable as $entity => $entry) {
            $this->assertArrayHasKey($entry['action'], $routes,
                "export.php's '$entity' names '{$entry['action']}', which has no ROUTES entry.");

            $this->assertSame(
                $routes[$entry['action']]['permission'], $entry['permission'],
                "export.php's '$entity' requires '{$entry['permission']}', but "
                    . "'{$entry['action']}' is routed on '{$routes[$entry['action']]['permission']}' "
                    . 'in public/api.php - the two have drifted apart.');
        }
    }

    /**
     * Every exportable entity must still be one FilterSpec knows how to
     * describe - export.php calls FilterSpec::fromPayload($entity, ...) to
     * build the "active filters" header line, and an entity present in one
     * table but not the other is a fatal error the moment someone exports it.
     */
    public function testEveryExportableEntityIsAFilterSpecEntity(): void
    {
        $exportable = SourceTree::exportable();

        foreach (array_keys($exportable) as $entity) {
            $this->assertContains($entity, \Digos\Domain\Query\FilterSpec::entities(),
                "export.php lists '$entity', which FilterSpec does not know how to describe.");
        }
    }
}
