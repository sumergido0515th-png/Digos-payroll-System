<?php
/**
 * ============================================================================
 * BackupRestoreTest - The disaster path actually works.
 *
 * WHY THIS EXISTS
 * Backup and restore is the one feature whose failure is discovered at the
 * worst possible moment. Until now nothing executed it: BackupCoverageTest
 * checks that the table list keeps up with the migrations, which is a
 * different question from whether the dump can be read back.
 *
 * That gap has already hidden one real defect. The restore split the file on
 * ";\n" and skipped any chunk beginning with "--" - and the dump's first chunk
 * is the header comment followed by SET FOREIGN_KEY_CHECKS=0, so the single
 * statement that makes a restore possible was the single statement never run.
 * Harmless until 0009 added twenty foreign keys, after which a restore would
 * have deleted Employees with the constraints live and cascaded Contracts and
 * DtrDays away before the inserts that refill them.
 *
 * A round trip catches that class of bug. Assertions on the file's text would
 * not have.
 *
 * Safe to run: TestDatabase refuses any database whose name does not contain
 * "test", and the restore puts back exactly what the dump took.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\BackupRepo;
use PHPUnit\Framework\TestCase;

final class BackupRestoreTest extends TestCase
{
    private string $dump = '';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable.');
        }
        ApplicationLayer::load();

        $this->dump = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'digos_backup_test_' . getmypid() . '.sql';

        TestDatabase::connect()->exec(
            "DELETE FROM Settings WHERE KeyName = 'BackupRoundTripProbe'");
    }

    protected function tearDown(): void
    {
        if ($this->dump !== '' && is_file($this->dump)) unlink($this->dump);
        TestDatabase::connect()->exec(
            "DELETE FROM Settings WHERE KeyName = 'BackupRoundTripProbe'");
    }

    /**
     * The whole cycle: a value exists, is dumped, is destroyed, comes back.
     *
     * Settings is the probe table because it is small, is in BACKUP_TABLES,
     * and has no foreign keys pointing at it - so what this measures is the
     * dump and the restore, not a cascade.
     */
    public function testAValueDestroyedAfterTheDumpComesBack(): void
    {
        $pdo = TestDatabase::connect();
        $pdo->prepare('INSERT INTO Settings (KeyName, Value) VALUES (?, ?)')
            ->execute(['BackupRoundTripProbe', 'before']);

        BackupRepo::dumpTo($this->dump, BACKUP_TABLES, '2026-07-31_test');

        $pdo->prepare('UPDATE Settings SET Value = ? WHERE KeyName = ?')
            ->execute(['after', 'BackupRoundTripProbe']);
        $this->assertSame('after', $this->probe(), 'The test itself did not change the value.');

        BackupRepo::restoreFrom($this->dump);

        $this->assertSame('before', $this->probe(),
            'The restore did not put back the value the dump captured.');
    }

    /**
     * A statement sharing the first chunk with the header comment still runs.
     *
     * This is the defect described above, isolated. The round-trip tests above
     * do NOT catch it: the dump lists parents before children and 0009's
     * constraints cascade, so a restore with FOREIGN_KEY_CHECKS left on happens
     * to produce the right rows anyway. Reverting the fix and watching them all
     * still pass is what showed that - so this asserts the mechanism rather
     * than a downstream effect of it.
     *
     * The dump is hand-written rather than generated so the leading comment and
     * the statement after it are exactly the shape being tested.
     */
    public function testAStatementFollowingTheHeaderCommentIsExecuted(): void
    {
        file_put_contents($this->dump,
            "-- Digos Payroll backup 2026-07-31_test\n"
            . "INSERT INTO Settings (KeyName, Value)"
            . " VALUES ('BackupRoundTripProbe', 'from the first chunk');\n");

        BackupRepo::restoreFrom($this->dump);

        $this->assertSame('from the first chunk', $this->probe(),
            'The first chunk was skipped because it begins with a comment. In a real '
            . 'dump that chunk is SET FOREIGN_KEY_CHECKS=0, and dropping it is what '
            . 'makes a restore delete rows it then cannot re-insert.');
    }

    /** The generated dump really does have that shape, or the test above guards nothing. */
    public function testTheGeneratedDumpLeadsWithACommentThenTheForeignKeySwitch(): void
    {
        BackupRepo::dumpTo($this->dump, ['Settings'], '2026-07-31_test');
        $sql = (string) file_get_contents($this->dump);

        $this->assertStringStartsWith('--', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;',
            explode(";\n", $sql)[0] . ';');
    }

    /** A row deleted after the dump is restored, not merely left alone. */
    public function testARowDeletedAfterTheDumpIsRestored(): void
    {
        $pdo = TestDatabase::connect();
        $pdo->prepare('INSERT INTO Settings (KeyName, Value) VALUES (?, ?)')
            ->execute(['BackupRoundTripProbe', 'present']);

        BackupRepo::dumpTo($this->dump, BACKUP_TABLES, '2026-07-31_test');

        $pdo->exec("DELETE FROM Settings WHERE KeyName = 'BackupRoundTripProbe'");
        $this->assertNull($this->probe());

        BackupRepo::restoreFrom($this->dump);

        $this->assertSame('present', $this->probe());
    }

    /** NULL survives the round trip as NULL, not as the string "NULL". */
    public function testNullsAreRestoredAsNulls(): void
    {
        $pdo = TestDatabase::connect();
        $pdo->prepare('INSERT INTO Settings (KeyName, Value) VALUES (?, NULL)')
            ->execute(['BackupRoundTripProbe']);

        BackupRepo::dumpTo($this->dump, BACKUP_TABLES, '2026-07-31_test');
        $pdo->prepare('UPDATE Settings SET Value = ? WHERE KeyName = ?')
            ->execute(['not null any more', 'BackupRoundTripProbe']);

        BackupRepo::restoreFrom($this->dump);

        $row = $pdo->query(
            "SELECT Value FROM Settings WHERE KeyName = 'BackupRoundTripProbe'")->fetch();
        $this->assertNull($row['Value'],
            'A NULL came back as a value - the dump quoted it instead of writing NULL.');
    }

    private function probe(): ?string
    {
        $row = TestDatabase::connect()->query(
            "SELECT Value FROM Settings WHERE KeyName = 'BackupRoundTripProbe'")->fetch();
        return $row === false ? null : $row['Value'];
    }
}
