<?php
/**
 * ============================================================================
 * SegregationOfDutiesTest - The second half of Phase 2's exit gate:
 *
 *   "Preparer account cannot self-approve a payroll they created."
 *
 * The check reads Payroll.PreparedByUser, the foreign key migration 0007 added.
 * Before it existed, identity was a display-name string and this test could not
 * have been written at all - two people spelled the same way are one preparer
 * to a string comparison, and one person spelled two ways is two.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class SegregationOfDutiesTest extends TestCase
{
    private const OFFICE = 'ZZSOD';
    private const PERIOD = 'PRD-ZZSOD';
    private const PAYROLL = 'ZZSOD-0001';
    private const PREPARER = 'zz-preparer@digos.gov.ph';
    private const APPROVER = 'zz-approver@digos.gov.ph';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
        ScopeGrantRepo::forget();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
        ScopeGrantRepo::forget();
    }

    /** A Pending payroll whose PreparedByUser is the preparer's key. */
    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
            ->execute([self::OFFICE, 'SoD fixture', 'Active']);
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        foreach ([self::PREPARER, self::APPROVER] as $email) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'SoD fixture', 'Pre-Auditor', '', 'Active', 'x']);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite) VALUES (?, ?, 1, 1)')
                ->execute(['SG-ZZSOD-' . substr(md5($email), 0, 8), $email]);
        }

        // Both the display string and the key. They are set to the same person
        // here, which is the ordinary case; the test that matters is that the
        // check reads the key.
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, PreparedBy, PreparedByUser)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PAYROLL, self::PERIOD, self::OFFICE, 'Pending', 'SoD fixture', self::PREPARER]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZSOD-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo = '" . self::PAYROLL . "'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zz-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode = '" . self::OFFICE . "'");
    }

    private function user(string $email): array
    {
        return [
            'Email' => $email,
            'FullName' => 'SoD fixture',
            'Role' => 'Pre-Auditor',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Pre-Auditor'],
        ];
    }

    private function statusOf(string $payrollNo): string
    {
        $st = TestDatabase::connect()->prepare('SELECT Status FROM Payroll WHERE PayrollNo = ?');
        $st->execute([$payrollNo]);
        return (string) $st->fetchColumn();
    }

    public function testThePreparerCannotApproveTheirOwnPayroll(): void
    {
        try {
            \apiApprovePayroll(['PayrollNo' => self::PAYROLL], $this->user(self::PREPARER));
            $this->fail('The preparer approved their own payroll.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('cannot also approve', $e->getMessage());
        }

        $this->assertSame('Pending', $this->statusOf(self::PAYROLL),
            'The payroll was approved despite the refusal.');
    }

    public function testADifferentApproverCanApproveIt(): void
    {
        \apiApprovePayroll(['PayrollNo' => self::PAYROLL], $this->user(self::APPROVER));

        $this->assertSame('Approved', $this->statusOf(self::PAYROLL));
    }

    /** The key is written on approval, so Phase 7 can tell the two actors apart. */
    public function testApprovalRecordsTheApproverAsAKeyNotJustAName(): void
    {
        \apiApprovePayroll(['PayrollNo' => self::PAYROLL], $this->user(self::APPROVER));

        $st = TestDatabase::connect()
            ->prepare('SELECT PreparedByUser, ApprovedByUser FROM Payroll WHERE PayrollNo = ?');
        $st->execute([self::PAYROLL]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(self::PREPARER, $row['PreparedByUser']);
        $this->assertSame(self::APPROVER, $row['ApprovedByUser']);
        $this->assertNotSame($row['PreparedByUser'], $row['ApprovedByUser']);
    }

    /**
     * The check must read the key and not the display string. Here the two
     * users share a name - the ordinary case of two staff with the same name,
     * and the reason PreparedBy alone was never enough.
     */
    public function testTheCheckReadsTheKeyAndNotTheDisplayName(): void
    {
        TestDatabase::connect()
            ->prepare('UPDATE Payroll SET PreparedBy = ? WHERE PayrollNo = ?')
            ->execute(['Somebody Else Entirely', self::PAYROLL]);

        $this->expectExceptionMessage('cannot also approve');

        \apiApprovePayroll(['PayrollNo' => self::PAYROLL], $this->user(self::PREPARER));
    }
}
