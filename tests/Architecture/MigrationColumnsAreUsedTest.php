<?php
/**
 * ============================================================================
 * Guard 4 - Every column a migration adds is referenced somewhere in app/.
 *
 * WHY THIS EXISTS
 *   Phase 1 froze the data model in seven migrations and then nothing wrote to
 *   it. EmploymentTypeCode, PreparedByUser, ApprovedByUser and the payroll-line
 *   charging columns applied cleanly, backfilled the rows that existed, and
 *   were never populated again - every record created through the UI after that
 *   arrived with them empty. Nothing failed, because nothing fails when a
 *   nullable column stays null. It took a separate measuring pass to notice.
 *
 *   PreparedByUser is the case that mattered: Phase 2's segregation-of-duties
 *   check reads that column and nothing else, and on a payroll created through
 *   the UI it would have found NULL - a check that cannot tell preparer from
 *   approver either passes everything or blocks everything.
 *
 *   A migration is not finished when it applies cleanly. It is finished when
 *   something writes the column. This test is what says so.
 *
 * WHEN THIS FAILS
 *   Either wire the column up in app/, or - if it is genuinely for a later
 *   phase - add it to DEFERRED below with the phase that will claim it. The
 *   entry is the point: a column nobody writes is a decision, and it should be
 *   a stated one rather than an oversight that looks identical.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class MigrationColumnsAreUsedTest extends TestCase
{
    /**
     * Columns deliberately not written by app/ yet, and what will claim them.
     *
     * `Table.*` defers a whole table. Like the DB:: allowlist in
     * DatabaseAccessTest, **this list may only shrink** - every entry is a
     * promise that some later phase is going to collect.
     *
     * @var array<string,string>
     */
    private const DEFERRED = [
        // DtrDays.* and Contracts.* were both here. Phase 3B writes every DTR
        // column through DtrRepo, and Phase 3 gave Contracts its first write
        // path - so the two entries that had been promises since Phase 1 are
        // collected. That is the whole point of this list shrinking.

        // Seeded reference data read through SELECT * and handed to the Phase 4
        // resolvers as a row; no code names these individually yet.
        'EmploymentTypes.*' => 'Phase 4 - resolvers branch on these flags',

        // Phase 8 stores the rendered PDF alongside the approved payroll rather
        // than re-rendering it on demand; this is where the reference goes.
        'Payroll.PdfFileId' => 'Phase 8 - print gating stores the rendered PDF',

        // Maintained by the server through DEFAULT / ON UPDATE
        // CURRENT_TIMESTAMP. Naming them in PHP would be how they start being
        // wrong, not how they start being right.
        'Employees.UpdatedAt' => 'maintained by the database, never written by PHP',
        'Settings.UpdatedAt' => 'maintained by the database, never written by PHP',
        'EmployeeSensitive.UpdatedAt' => 'maintained by the database, never written by PHP',
        'Memorandum.UpdatedAt' => 'maintained by the database, never written by PHP',
        'BioExemptions.UpdatedAt' => 'maintained by the database, never written by PHP',
        'TravelOrders.UpdatedAt' => 'maintained by the database, never written by PHP',
        'WorkShifts.UpdatedAt' => 'maintained by the database, never written by PHP',
        'Contracts.UpdatedAt' => 'maintained by the database, never written by PHP',
        'DtrDays.UpdatedAt' => 'maintained by the database, never written by PHP',
    ];

    /** Tables whose columns are structural rather than application data. */
    private const IGNORED_TABLES = ['schema_migrations'];

    public function testEveryColumnAMigrationAddsIsReferencedInApp(): void
    {
        $appSource = $this->appSource();
        $unused = [];

        foreach ($this->migrationColumns() as $qualified => $file) {
            [$table, $column] = explode('.', $qualified, 2);

            if (in_array($table, self::IGNORED_TABLES, true)) continue;
            if (isset(self::DEFERRED[$table . '.*'])) continue;
            if (isset(self::DEFERRED[$qualified])) continue;

            if (!preg_match('/\b' . preg_quote($column, '/') . '\b/', $appSource)) {
                $unused[] = sprintf('%s  (added by %s)', $qualified, $file);
            }
        }

        sort($unused);
        $this->assertSame([], $unused, sprintf(
            "Columns added by a migration that no code in app/ ever names:\n  - %s\n\n" .
            "These apply cleanly and then stay NULL on every row written from now on,\n" .
            "silently, because nothing fails when a nullable column is never set.\n" .
            "Wire each one up, or add it to DEFERRED in this test with the phase that\n" .
            "will claim it.",
            implode("\n  - ", $unused)));
    }

    public function testDeferredEntriesStillCorrespondToRealColumns(): void
    {
        $known = [];
        foreach (array_keys($this->migrationColumns()) as $qualified) {
            [$table] = explode('.', $qualified, 2);
            $known[$qualified] = true;
            $known[$table . '.*'] = true;
        }

        $stale = array_values(array_diff(array_keys(self::DEFERRED), array_keys($known)));

        $this->assertSame([], $stale, sprintf(
            "DEFERRED lists columns or tables no migration creates:\n  - %s\n" .
            "A stale entry silently exempts nothing, and hides the next real one.",
            implode("\n  - ", $stale)));
    }

    /**
     * Every column the migrations create, as `Table.Column` => defining file.
     *
     * @return array<string,string>
     */
    private function migrationColumns(): array
    {
        $columns = [];

        foreach (glob(PROJECT_ROOT . '/migrations/*.sql') ?: [] as $path) {
            $file = basename($path);
            $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($path));

            // CREATE TABLE <name> ( ... );
            if (preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\s*\)/is',
                    $sql, $tables, PREG_SET_ORDER)) {
                foreach ($tables as [, $table, $body]) {
                    foreach ($this->columnsInTableBody($body) as $column) {
                        $columns["$table.$column"] ??= $file;
                    }
                }
            }

            // ALTER TABLE <name> ... ADD COLUMN <col> ...
            if (preg_match_all('/ALTER\s+TABLE\s+`?(\w+)`?(.*?);/is', $sql, $alters, PREG_SET_ORDER)) {
                foreach ($alters as [, $table, $body]) {
                    if (preg_match_all('/ADD\s+COLUMN\s+`?(\w+)`?/i', $body, $added)) {
                        foreach ($added[1] as $column) $columns["$table.$column"] ??= $file;
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * Column names from a CREATE TABLE body, skipping table-level constraints.
     *
     * @return string[]
     */
    private function columnsInTableBody(string $body): array
    {
        $skip = ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK', 'FULLTEXT'];
        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || !preg_match('/^`?(\w+)`?\s+\S/', $line, $m)) continue;
            if (in_array(strtoupper($m[1]), $skip, true)) continue;
            $columns[] = $m[1];
        }

        return $columns;
    }

    /**
     * Every PHP source file concatenated once.
     *
     * Deliberately wider than app/: a column the SPA renders is in a view, and
     * reading one is still using it. The bug this guards against was a column
     * named in *no* file at all.
     */
    private function appSource(): string
    {
        $source = '';
        foreach (SourceTree::phpFiles() as $relative) {
            $source .= SourceTree::read($relative) . "\n";
        }
        return $source;
    }
}
