<?php
/**
 * ============================================================================
 * Guard 1 - Database access is confined to the repository layer.
 *
 * WHY THIS EXISTS
 * Scope enforcement (Phase 2) works by routing every read through a gateway
 * that applies the caller's scope grants. A single direct DB:: call in a
 * feature module silently bypasses that gateway and leaks another office's
 * rows. Code review does not reliably catch this; a test does.
 *
 * The pre-Phase-2 modules below are grandfathered in. That list may only
 * ever shrink. Adding a file to it is a deliberate decision to accept a
 * scope-bypass, and should be justified in the commit message.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class DatabaseAccessTest extends TestCase
{
    /**
     * Legacy files that access the database directly, written before the
     * repository layer existed. Phase 2 migrates these behind the scope
     * gateway and removes them from this list.
     *
     * @var string[]
     */
    private const LEGACY_DIRECT_ACCESS = [
        'app/Auth.php',
        'app/Master.php',
        'app/Payroll.php',
        // app/PrintDoc.php was here. Removed once the print path was scoped:
        // it had seven direct queries, and one of them - the payroll header -
        // was the scope layer's largest hole. apiGetPayroll refused another
        // office's payroll while apiGetPrintHtml rendered the same number in
        // full. Reads now go through PayrollRepo, EmployeeRepo and
        // ReferenceRepo. Six left.
        'app/Reports.php',
        'app/Settings.php',
        'public/download.php',
    ];

    public function testDatabaseAccessOnlyHappensInTheRepositoryLayer(): void
    {
        $offenders = [];

        foreach (SourceTree::phpFiles() as $file) {
            if (str_starts_with($file, 'app/Repo/')) continue;      // the sanctioned home
            if ($file === 'app/Database.php') continue;             // defines DB itself
            if (in_array($file, self::LEGACY_DIRECT_ACCESS, true)) continue;

            // Code only. A docblock reading "Pure: no DB::, no session" is a
            // promise not to, and matching it flagged the access layer whose
            // entire purpose is that it never queries.
            if (preg_match('/\bDB::/', SourceTree::readCode($file))) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Direct DB:: access outside app/Repo/ in:\n  - %s\n\n" .
            "Move the query into a repository class under app/Repo/ so that the\n" .
            "Phase 2 scope gateway is applied. See CLAUDE.md > Database access.",
            implode("\n  - ", $offenders)));
    }

    /**
     * The grandfather list is a debt ledger: every entry must still exist and
     * must still actually use DB::. Stale entries would silently re-open the
     * hole for a path that no longer needs it.
     */
    public function testLegacyAllowlistHasNoStaleEntries(): void
    {
        foreach (self::LEGACY_DIRECT_ACCESS as $file) {
            $path = PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

            $this->assertFileExists($path,
                "Allowlisted file $file no longer exists - remove it from LEGACY_DIRECT_ACCESS.");
            $this->assertMatchesRegularExpression('/\bDB::/', SourceTree::read($file),
                "Allowlisted file $file no longer uses DB:: - remove it from LEGACY_DIRECT_ACCESS.");
        }
    }
}
