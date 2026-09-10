<?php
/**
 * ============================================================================
 * OperationalMetricsTest - Phase 10's baseline, scoped and date-bounded
 * against fixtures.
 *
 * The arithmetic itself is tests/Unit/OperationalMetricsTest.php's job,
 * proven against arrays with no database at all. This file proves the two
 * things only a live query can: that PrintLogRepo::officialPrintsScoped() and
 * SuspensionRepo::activityScoped() carry the same scope predicate every other
 * read in this system does, and that a Draft/preview print or a date outside
 * the requested range never reaches the arithmetic in the first place.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\PrintLogRepo;
use Digos\Repo\SuspensionRepo;
use PHPUnit\Framework\TestCase;

final class OperationalMetricsTest extends TestCase
{
    private const MINE = 'ZZOMMINE';
    private const THEIRS = 'ZZOMTHEIRS';

    private const MY_PAYROLL = 'ZZOM-MINE-1';
    private const THEIR_PAYROLL = 'ZZOM-THEIRS-1';

    private const ENCODER = 'zzom-encoder@digos.gov.ph';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
    }

    private function user(): array
    {
        return [
            'Email' => self::ENCODER,
            'FullName' => 'Metrics fixture',
            'Role' => 'Encoder',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Encoder'],
        ];
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Metrics fixture $office", 'Active']);
        }

        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, 2026, ?, ?, ?)')
            ->execute(['PRD-ZZOM-A', 'August', '2026-08-01', '2026-08-15', 'Open']);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ENCODER, 'Metrics fixture', 'Encoder', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZOM-1', self::ENCODER, self::MINE]);

        $payroll = $db->prepare(
            'INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Department, Status, PreparedBy, DateCreated,
                                  TotalGross, TotalNet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $payroll->execute([self::MY_PAYROLL, 'PRD-ZZOM-A', self::MINE, 'Div', 'PRINTED',
            'MINE, Preparer', '2026-08-16 09:00:00', 1000, 900]);
        $payroll->execute([self::THEIR_PAYROLL, 'PRD-ZZOM-A', self::THEIRS, 'Div', 'PRINTED',
            'THEIRS, Preparer', '2026-08-16 09:00:00', 2000, 1800]);

        $print = $db->prepare(
            'INSERT INTO PrintLog (PrintLogID, PayrollNo, Form, IsOfficial, PrintSerial, ReprintReason,
                                   PrintedBy, PrintedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        // Mine: one Official print inside the window, one reprint inside it,
        // and one Draft/preview (IsOfficial = 0) that must never count.
        $print->execute(['PRN-ZZOM-1', self::MY_PAYROLL, 'payroll', 1, 'PS-2026-000001', '',
            'Encoder', '2026-08-16 10:00:00']);
        $print->execute(['PRN-ZZOM-2', self::MY_PAYROLL, 'payroll', 1, 'PS-2026-000002', 'Smudged copy',
            'Encoder', '2026-08-16 15:00:00']);
        $print->execute(['PRN-ZZOM-3', self::MY_PAYROLL, 'payroll', 0, null, '',
            'Encoder', '2026-08-16 09:30:00']);
        // Mine, but outside the [Aug 16, Aug 16] window used below.
        $print->execute(['PRN-ZZOM-4', self::MY_PAYROLL, 'summary', 1, 'PS-2026-000003', '',
            'Encoder', '2026-09-01 10:00:00']);
        // Theirs - must never appear in the scoped result at all.
        $print->execute(['PRN-ZZOM-5', self::THEIR_PAYROLL, 'payroll', 1, 'PS-2026-000004', '',
            'Encoder', '2026-08-16 10:00:00']);

        $suspension = $db->prepare(
            'INSERT INTO Suspensions (NsNo, PayrollNo, GroundCode, RaisedAt, SettledAt, Status)
             VALUES (?, ?, ?, ?, ?, ?)');
        $suspension->execute(['NS-ZZOM-1', self::MY_PAYROLL, 'MISSING_ATTACHMENT',
            '2026-08-16 08:00:00', '2026-08-17 08:00:00', 'Settled']);
        $suspension->execute(['NS-ZZOM-2', self::THEIR_PAYROLL, 'NO_MEMO',
            '2026-08-16 08:00:00', null, 'Open']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZOM-%'");
        $db->exec("DELETE FROM Suspensions WHERE NsNo LIKE 'NS-ZZOM-%'");
        $db->exec("DELETE FROM PrintLog WHERE PrintLogID LIKE 'PRN-ZZOM-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZOM-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID LIKE 'PRD-ZZOM-%'");
        $db->exec("DELETE FROM Users WHERE Email = '" . self::ENCODER . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    /* -------------------------------------------------------------- print scope */

    public function testOfficialPrintsScopedNeverReturnsAnotherOfficesRow(): void
    {
        $rows = PrintLogRepo::officialPrintsScoped($this->user(), null, null);

        $ids = array_map(fn(array $r) => $r['PayrollNo'], $rows);
        $this->assertNotContains(self::THEIR_PAYROLL, $ids);
        $this->assertContains(self::MY_PAYROLL, $ids);
    }

    public function testOfficialPrintsScopedExcludesDraftPreviews(): void
    {
        $rows = PrintLogRepo::officialPrintsScoped($this->user(), null, null);

        // Three Official rows are in scope: two 'payroll' inside the window,
        // one 'summary' outside it. The Draft preview (PRN-ZZOM-3) must not
        // be among them regardless of range.
        $this->assertCount(3, $rows);
    }

    public function testOfficialPrintsScopedRespectsTheDateRange(): void
    {
        $rows = PrintLogRepo::officialPrintsScoped($this->user(), '2026-08-16', '2026-08-16');

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('payroll', $row['Form']);
        }
    }

    /* --------------------------------------------------------- suspension scope */

    public function testActivityScopedNeverReturnsAnotherOfficesRow(): void
    {
        $rows = SuspensionRepo::activityScoped($this->user(), null, null);

        $this->assertCount(1, $rows);
        $this->assertSame('MISSING_ATTACHMENT', $rows[0]['GroundCode']);
    }

    public function testActivityScopedRespectsTheDateRange(): void
    {
        $rows = SuspensionRepo::activityScoped($this->user(), '2026-09-01', '2026-09-30');

        $this->assertSame([], $rows);
    }

    /* ------------------------------------------------------------- end to end */

    public function testApiGetOperationalMetricsComposesBothScopedAndCorrectly(): void
    {
        $result = \apiGetOperationalMetrics(
            ['From' => '2026-08-16', 'To' => '2026-08-16'], $this->user());

        $this->assertSame(2, $result['officialPrints']);
        $this->assertSame(1, $result['reprints']);
        $this->assertSame(0.5, $result['reprintRate']);
        $this->assertSame(1, $result['settledCount']);
        $this->assertSame(24.0, $result['averageTurnaroundHours']);
        $this->assertSame([['GroundCode' => 'MISSING_ATTACHMENT', 'Count' => 1]], $result['topGrounds']);
    }

    public function testAToBeforeFromIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        \apiGetOperationalMetrics(['From' => '2026-08-16', 'To' => '2026-08-01'], $this->user());
    }
}
