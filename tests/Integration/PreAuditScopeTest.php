<?php
/**
 * ============================================================================
 * PreAuditScopeTest - The worklist and its actions stay inside scope.
 *
 * The pre-audit screen is a read-first view of payrolls a user may review,
 * but the actions behind it still have to re-check the payroll itself. If the
 * worklist is scoped but approve/suspend/settle act on a raw number, a caller
 * who knows another office's payroll number can still mutate it. That is the
 * kind of role-based gap this project is supposed to eliminate.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;

final class PreAuditScopeTest extends TestCase
{
    private const CMO = 'ZZPA-CMO';
    private const OCEEM = 'ZZPA-OCEEM';
    private const PERIOD = 'PRD-ZZPA';
    private const CMO_PAYROLL = 'ZZPA-001';
    private const OCEEM_PAYROLL = 'ZZPA-002';
    private const OCEEM_NS = 'NS-ZZPA-001';
    private const USER = 'zzpa-auditor@digos.gov.ph';
    private const PASSWORD = 'preaudit-scope-password';

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

    private function createFixture(): void
    {
        $db = TestDatabase::connect();
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        foreach ([self::CMO, self::OCEEM] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, 'Pre-audit scope fixture ' . $office, 'Active']);
        }

        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::USER, 'Pre-audit scope fixture', 'Pre-Auditor', '', 'Active', $hash]);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZPA-1', self::USER, self::CMO]);

        foreach ([[self::CMO_PAYROLL, self::CMO, 'FOR_PRE_AUDIT'],
                  [self::OCEEM_PAYROLL, self::OCEEM, 'SUSPENDED']] as [$payrollNo, $office, $status]) {
            $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, PreparedBy, PreparedByUser)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$payrollNo, self::PERIOD, $office, $status, 'Pre-audit scope fixture', self::USER]);
        }

        $db->prepare('INSERT INTO Suspensions (NsNo, PayrollNo, GroundCode, RuleID, Particulars,
                                               RequiredAction, RaisedBy, Status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::OCEEM_NS, self::OCEEM_PAYROLL, 'DOCUMENT_INTEGRITY', null,
                'Out-of-scope suspension fixture', 'Resolve out-of-scope fixture', self::USER, 'Open']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM Suspensions WHERE NsNo = '" . self::OCEEM_NS . "'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo IN ('" . self::CMO_PAYROLL . "','" . self::OCEEM_PAYROLL . "')");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID = 'SG-ZZPA-1'");
        $db->exec("DELETE FROM Users WHERE Email = '" . self::USER . "'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::CMO . "','" . self::OCEEM . "')");
    }

    private function user(): array
    {
        return [
            'Email' => self::USER,
            'FullName' => 'Pre-audit scope fixture',
            'Role' => 'Pre-Auditor',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Pre-Auditor'],
        ];
    }

    public function testTheWorklistOnlyShowsPayrollsWithinScope(): void
    {
        $rows = \apiGetWorklist([], $this->user())['rows'];
        $numbers = array_column($rows, 'PayrollNo');

        $this->assertContains(self::CMO_PAYROLL, $numbers);
        $this->assertNotContains(self::OCEEM_PAYROLL, $numbers);
    }

    public function testApprovalCannotReachAnOutOfScopePayroll(): void
    {
        $message = $this->refusalFrom(fn() => \apiApprovePayroll([
            'PayrollNo' => self::OCEEM_PAYROLL,
            'Password' => self::PASSWORD,
        ], $this->user()));

        $this->assertStringContainsString('Payroll not found', $message);
    }

    public function testSuspensionCannotReachAnOutOfScopePayroll(): void
    {
        $message = $this->refusalFrom(fn() => \apiSuspendPayroll([
            'PayrollNo' => self::OCEEM_PAYROLL,
            'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'Out of scope',
            'RequiredAction' => 'Should not be reachable',
        ], $this->user()));

        $this->assertStringContainsString('Payroll not found', $message);
    }

    public function testSettleCannotReachAnOutOfScopeSuspension(): void
    {
        $message = $this->refusalFrom(fn() => \apiSettleSuspension([
            'NsNo' => self::OCEEM_NS,
            'SettlementRef' => 'Should not be reachable',
        ], $this->user()));

        $this->assertStringContainsString('Suspension not found', $message);
    }

    private function refusalFrom(callable $call): string
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
        $this->fail('The call was expected to be refused and was not.');
    }
}
