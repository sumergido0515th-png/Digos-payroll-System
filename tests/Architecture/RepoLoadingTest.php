<?php
/**
 * ============================================================================
 * Guard 5 - Every repository is loaded by both entry points.
 *
 * WHY THIS EXISTS
 * There is no autoloader for app/ (no framework, no build step - see
 * CLAUDE.md), so app/bootstrap.php and tests/Integration/ApplicationLayer.php
 * each require the repository files by hand, in dependency order. Adding a
 * repository and forgetting one of those lists produces a fatal "Class not
 * found" - in production if bootstrap.php was missed, or a green suite hiding
 * a broken application if ApplicationLayer.php was.
 *
 * The failure is silent in the direction that matters: the test suite passes
 * either way until something exercises the new class.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RepoLoadingTest extends TestCase
{
    /** @return string[] repo-relative paths of every repository class */
    private function repositories(): array
    {
        $files = array_values(array_filter(
            SourceTree::phpFiles(),
            fn(string $f) => str_starts_with($f, 'app/Repo/')));

        $this->assertNotEmpty($files, 'app/Repo/ is empty - the glob is wrong.');
        return $files;
    }

    public function testProductionBootstrapLoadsEveryRepository(): void
    {
        $this->assertLoadedBy('app/bootstrap.php');
    }

    public function testTheTestApplicationLayerLoadsEveryRepository(): void
    {
        $this->assertLoadedBy('tests/Integration/ApplicationLayer.php');
    }

    /**
     * The same for app/Domain/Access/, which the repositories depend on.
     *
     * Access only, not all of app/Domain/. app/Domain/Migration/ is reached by
     * tools/migrate.php and is deliberately not part of the request path - the
     * application must never be able to run DDL, which is why migrations run
     * as a separate database account.
     */
    public function testBothLoadersAgreeOnTheAccessLayer(): void
    {
        foreach (['app/bootstrap.php', 'tests/Integration/ApplicationLayer.php'] as $loader) {
            $src = $this->readLoader($loader);
            foreach (SourceTree::phpFiles() as $file) {
                if (!str_starts_with($file, 'app/Domain/Access/')) continue;
                $this->assertStringContainsString(basename($file), $src,
                    "$loader does not load $file.");
            }
        }
    }

    private function assertLoadedBy(string $loader): void
    {
        $src = $this->readLoader($loader);
        $missing = [];

        foreach ($this->repositories() as $file) {
            // Matched on basename: bootstrap.php requires by __DIR__ . '/Repo/X.php'
            // and ApplicationLayer lists 'app/Repo/X.php'. The file name is the
            // part both spell the same way.
            if (!str_contains($src, basename($file))) $missing[] = $file;
        }

        $this->assertSame([], $missing, sprintf(
            "%s does not load:\n  - %s\n\n"
            . "There is no autoloader for app/. Add the require to that file, and to\n"
            . 'the other loader, or the class is missing at runtime or in tests.',
            $loader, implode("\n  - ", $missing)));
    }

    private function readLoader(string $relative): string
    {
        // Not SourceTree::read() for the test loader: phpFiles() covers app/,
        // public/, views/ and tools/, and this one lives under tests/.
        return (string) file_get_contents(
            PROJECT_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    }
}
