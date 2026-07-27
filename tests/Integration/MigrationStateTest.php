<?php
/**
 * ============================================================================
 * MigrationStateTest - Asserts that the database the suite is pointed at has
 * actually been migrated, and that its recorded state agrees with the files
 * on disk.
 *
 * This is the check that turns "migrations run clean on a copy of real data"
 * (the Phase 1 exit gate) into something a machine can verify.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Domain\Migration\MigrationFile;
use PHPUnit\Framework\TestCase;

final class MigrationStateTest extends TestCase
{
    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped(
                'No test database reachable. Set DB_HOST/DB_NAME/DB_USER/DB_PASS and run '
                . 'php tools/migrate.php first.');
        }
    }

    public function testMigrationRegistryExists(): void
    {
        $tables = TestDatabase::connect()
            ->query('SHOW TABLES LIKE "schema_migrations"')->fetchAll();

        $this->assertNotEmpty($tables,
            'schema_migrations is missing - run php tools/migrate.php against the test database.');
    }

    public function testEveryMigrationOnDiskHasBeenApplied(): void
    {
        $applied = array_map('intval', TestDatabase::connect()
            ->query('SELECT Version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN));

        $pending = [];
        foreach (glob(PROJECT_ROOT . '/migrations/*.sql') ?: [] as $path) {
            preg_match('/^(\d+)_/', basename($path), $m);
            if (!in_array((int) $m[1], $applied, true)) $pending[] = basename($path);
        }

        $this->assertSame([], $pending, sprintf(
            "Migrations not applied to the test database:\n  - %s\n  Run php tools/migrate.php.",
            implode("\n  - ", $pending)));
    }

    public function testAppliedMigrationsMatchTheFilesOnDisk(): void
    {
        $rows = TestDatabase::connect()
            ->query('SELECT Version, Filename, Checksum FROM schema_migrations')->fetchAll();

        $drifted = [];
        foreach ($rows as $row) {
            $path = PROJECT_ROOT . '/migrations/' . $row['Filename'];

            if (!is_file($path)) {
                $drifted[] = $row['Filename'] . ' (applied, but no longer on disk)';
                continue;
            }
            if (MigrationFile::checksum((string) file_get_contents($path)) !== $row['Checksum']) {
                $drifted[] = $row['Filename'] . ' (edited after being applied)';
            }
        }

        $this->assertSame([], $drifted, sprintf(
            "The test database disagrees with migrations/:\n  - %s",
            implode("\n  - ", $drifted)));
    }

    /**
     * Guards the Phase 1 migration work: the baseline tables must survive
     * every migration that follows, whatever else changes around them.
     */
    public function testBaselineTablesArePresent(): void
    {
        $expected = ['Employees', 'Offices', 'Departments', 'Functions', 'Timekeepers',
            'PayrollPeriods', 'Payroll', 'PayrollDetails', 'Users', 'Logs', 'Settings',
            'Backup', 'Counters'];

        $actual = TestDatabase::connect()
            ->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $lower = array_map('strtolower', $actual);

        foreach ($expected as $table) {
            $this->assertContains(strtolower($table), $lower, "Table $table is missing.");
        }
    }
}
