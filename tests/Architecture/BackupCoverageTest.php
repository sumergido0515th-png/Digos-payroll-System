<?php
/**
 * ============================================================================
 * Guard 5 - Every table the migrations create is either backed up or
 * deliberately excluded.
 *
 * WHY THIS EXISTS
 *   Phase 1 added EmploymentTypes, Contracts and DtrDays. BACKUP_TABLES was
 *   not updated, so every backup taken afterwards silently omitted all three -
 *   and a backup you cannot notice is incomplete is worse than none.
 *
 *   Restore made it destructive rather than merely lossy. It deletes each
 *   listed table before reinserting, Contracts and DtrDays cascade from
 *   Employees, and neither was in the file to put back. Restoring a backup
 *   would have deleted every contract and DTR row in the system.
 *
 *   This is the disaster-recovery path. It is the last thing that should fail
 *   quietly, and it is the least likely to be exercised before it matters.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class BackupCoverageTest extends TestCase
{
    /**
     * Tables deliberately left out of backups, and why.
     *
     * @var array<string,string>
     */
    private const EXCLUDED = [
        'Backup' => 'the registry itself must survive a restore, or the file '
            . 'just restored from disappears from the list',
        'schema_migrations' => 'describes the schema, which a data-only restore never changes',
    ];

    public function testEveryTableIsBackedUpOrExplicitlyExcluded(): void
    {
        $constants = $this->backupTables();
        $missing = [];

        foreach ($this->migrationTables() as $table) {
            if (in_array($table, $constants, true)) continue;
            if (isset(self::EXCLUDED[$table])) continue;
            $missing[] = $table;
        }

        sort($missing);
        $this->assertSame([], $missing, sprintf(
            "Tables a migration creates that no backup contains:\n  - %s\n\n" .
            "Backups omit them silently, and restore DELETEs the tables it does\n" .
            "know about - so anything cascading from those is destroyed with\n" .
            "nothing in the file to restore it. Add each to BACKUP_TABLES in\n" .
            "app/Settings.php, or to EXCLUDED in this test with a reason.",
            implode("\n  - ", $missing)));
    }

    public function testBackupTablesAllExist(): void
    {
        $known = $this->migrationTables();
        $phantom = array_values(array_diff($this->backupTables(), $known));

        $this->assertSame([], $phantom, sprintf(
            "BACKUP_TABLES names tables no migration creates:\n  - %s\n" .
            "runBackup() would fail on the first one it reached.",
            implode("\n  - ", $phantom)));
    }

    public function testParentsAreListedBeforeTheirChildren(): void
    {
        // Restore disables foreign key checks, so this is belt and braces
        // rather than load-bearing - but a dump whose order already makes
        // sense is one that can still be imported by hand, through
        // phpMyAdmin, on a host where that SET is not permitted.
        $order = array_flip($this->backupTables());
        $wrong = [];

        foreach ([
            'Contracts' => 'Employees',
            'DtrDays' => 'Employees',
            'Employees' => 'EmploymentTypes',
            'PayrollDetails' => 'Payroll',
            'Payroll' => 'PayrollPeriods',
        ] as $child => $parent) {
            if (!isset($order[$child], $order[$parent])) continue;
            if ($order[$parent] > $order[$child]) $wrong[] = "$parent must come before $child";
        }

        $this->assertSame([], $wrong, sprintf(
            "BACKUP_TABLES is not in restore-safe order:\n  - %s",
            implode("\n  - ", $wrong)));
    }

    /** @return string[] */
    private function backupTables(): array
    {
        $source = SourceTree::read('app/Settings.php');

        $this->assertSame(1, preg_match('/const\s+BACKUP_TABLES\s*=\s*\[(.*?)\];/s', $source, $m),
            'Could not find BACKUP_TABLES in app/Settings.php - this guard is not testing anything.');

        preg_match_all("/'([A-Za-z_]+)'/", $m[1], $names);
        return $names[1];
    }

    /**
     * Tables the migrations create.
     *
     * @return string[]
     */
    private function migrationTables(): array
    {
        $tables = ['schema_migrations'];   // created by tools/migrate.php, not a migration

        foreach (glob(PROJECT_ROOT . '/migrations/*.sql') ?: [] as $path) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));
            if (preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?/i', $sql, $m)) {
                foreach ($m[1] as $table) $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }
}
