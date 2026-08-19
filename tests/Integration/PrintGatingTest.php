<?php
/**
 * ============================================================================
 * PrintGatingTest - Phase 8's exit gate.
 *
 *   "Editing a locked payroll and attempting print is refused and logged.
 *    Hash mismatch is reproducible on demand in a test."
 *
 * The edit in the test below is a direct mutation of PayrollDetails after
 * approval - exactly the "should not happen" class of state PrintScopeTest's
 * setStatus() helper and WorkflowTest's fixtures already use elsewhere in
 * this suite to prove a guard actually guards, rather than merely describing
 * an attack this file never performs.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\PrintLogRepo;
use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PrintGatingTest extends TestCase
{
    private const OFFICE = 'ZZPG';
    private const PERIOD = 'PRD-ZZPG';
    private const PAYROLL = 'ZZPG-1';
    private const EMPLOYEE = 'EMP-ZZPG-1';
    private const AUDITOR = 'zzpg-auditor@digos.gov.ph';
    private const PASSWORD = 'print-gating-fixture-password';

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

    /* ------------------------------------------------------------ the gate */

    /** A Draft, never approved, cannot be printed Official at all. */
    public function testAnUnapprovedPayrollCannotPrintOfficial(): void
    {
        $message = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor()));

        $this->assertStringContainsString('passed pre-audit approval', $message);
    }

    /** Draft/preview rendering never touches the hash gate, approved or not. */
    public function testADraftPreviewAlwaysRendersRegardlessOfApproval(): void
    {
        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL], $this->auditor());

        $this->assertFalse($result['official']);
        $this->assertStringContainsString('NOT OFFICIAL', $result['html']);
    }

    /** An approved payroll, unedited since, prints Official and is logged with a serial. */
    public function testAnApprovedUneditedPayrollPrintsOfficialWithASerial(): void
    {
        $this->approve();

        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor());

        $this->assertTrue($result['official']);
        $this->assertStringContainsString('Serial: PS-', $result['html']);
        $this->assertStringNotContainsString('NOT OFFICIAL', $result['html']);

        $history = PrintLogRepo::historyFor(self::PAYROLL);
        $this->assertCount(1, $history);
        $this->assertSame(1, (int) $history[0]['IsOfficial']);
        $this->assertSame(self::AUDITOR, $history[0]['PrintedByUser']);
    }

    /**
     * THE EXIT GATE. A payroll edited after approval - here, PayrollDetails
     * mutated directly, standing in for whatever bypassed the normal
     * EDITABLE-status guard - is refused at Official print and logged, and
     * the payroll is returned to FOR_PRE_AUDIT rather than left approved
     * with figures nobody re-approved.
     */
    public function testAnEditAfterApprovalIsRefusedAtPrintAndLogged(): void
    {
        $this->approve();
        $this->mutateApprovedPayrollDirectly();

        $message = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor()));

        $this->assertStringContainsString('changed since it was approved', $message);
        $this->assertSame('FOR_PRE_AUDIT', $this->statusOf(self::PAYROLL),
            'A hash mismatch must return the payroll to pre-audit, not leave it approved.');

        $log = TestDatabase::connect()->prepare(
            "SELECT * FROM Logs WHERE Action = 'PRINT_HASH_MISMATCH' AND User = ? ORDER BY LogID DESC LIMIT 1");
        $log->execute([self::AUDITOR]);
        $row = $log->fetch();

        $this->assertNotFalse($row, 'The refused print left no record in the audit log.');
        $this->assertStringContainsString(self::PAYROLL, $row['Details']);
    }

    /** The mismatch is reproducible on demand: asserted a second time, independently. */
    public function testTheHashMismatchIsReproducibleOnDemand(): void
    {
        $this->approve();
        $this->mutateApprovedPayrollDirectly();

        $first = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor()));

        // The first attempt's revert already moved the payroll back to
        // FOR_PRE_AUDIT, but left the mutated NetPay in place - which the rule
        // engine would now flag on its own, refusing the re-approval for a
        // reason unrelated to what this test demonstrates. Restored first, so
        // a second, independent mismatch can be shown against a fresh hash.
        TestDatabase::connect()
            ->prepare('UPDATE PayrollDetails SET NetPay = 5000.00 WHERE PayrollNo = ?')
            ->execute([self::PAYROLL]);
        $this->approve();
        $this->mutateApprovedPayrollDirectly();

        $second = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor()));

        $this->assertSame($first, $second);
    }

    /** The first Official print of a form needs no reprint reason. */
    public function testTheFirstOfficialPrintNeedsNoReprintReason(): void
    {
        $this->approve();

        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor());

        $this->assertTrue($result['official']);
    }

    /** A second Official print of the same form is refused without a reason. */
    public function testASecondOfficialPrintNeedsAReprintReason(): void
    {
        $this->approve();
        \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor());

        $message = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor()));

        $this->assertStringContainsString('reprint reason', $message);
    }

    /** ...and succeeds once one is given, logged as a distinct PrintLog row. */
    public function testASecondOfficialPrintSucceedsWithAReprintReason(): void
    {
        $this->approve();
        \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'official' => true], $this->auditor());

        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'official' => true,
            'ReprintReason' => 'Original copy damaged before release.'], $this->auditor());

        $this->assertTrue($result['official']);
        $this->assertCount(2, PrintLogRepo::historyFor(self::PAYROLL));
    }

    /* --------------------------------------------------- the three artifacts */

    public function testCertificationRefusesBeforeApproval(): void
    {
        $message = $this->refusalFrom(fn() => \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'form' => 'certification'], $this->auditor()));

        $this->assertStringContainsString('passed pre-audit approval', $message);
    }

    public function testCertificationShowsFindingsApprovalAndHashOnceApproved(): void
    {
        $this->approve();

        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'form' => 'certification'], $this->auditor());

        $this->assertStringContainsString('PRE-AUDIT CERTIFICATION', $result['html']);
        $this->assertStringContainsString('Print gating fixture', $result['html']);
        $this->assertStringContainsString($this->currentPayloadHash(), $result['html']);
    }

    public function testNoticeOfSuspensionSlipShowsTheGroundAndParticulars(): void
    {
        $raised = \apiSuspendPayroll([
            'PayrollNo' => self::PAYROLL, 'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'The travel order cited does not match the dates claimed.',
            'RequiredAction' => 'Submit the correct travel order.',
        ], $this->auditor());

        $result = \apiGetPrintHtml(
            ['PayrollNo' => self::PAYROLL, 'form' => 'ns', 'NsNo' => $raised['NsNo']], $this->auditor());

        $this->assertStringContainsString('NOTICE OF SUSPENSION', $result['html']);
        $this->assertStringContainsString($raised['NsNo'], $result['html']);
        $this->assertStringContainsString('DOCUMENT_INTEGRITY', $result['html']);
        $this->assertStringContainsString('travel order cited does not match', $result['html']);
    }

    public function testSettlementReportListsASettledSuspensionAndOmitsOpenOnes(): void
    {
        $raised = \apiSuspendPayroll([
            'PayrollNo' => self::PAYROLL, 'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'x', 'RequiredAction' => 'y',
        ], $this->auditor());
        \apiSettleSuspension(
            ['NsNo' => $raised['NsNo'], 'SettlementRef' => 'Travel order TO-2026-0099 filed.'],
            $this->auditor());

        $result = \apiGetPrintHtml(['PayrollNo' => self::PAYROLL, 'form' => 'settlement'], $this->auditor());

        $this->assertStringContainsString('SETTLEMENT REPORT', $result['html']);
        $this->assertStringContainsString($raised['NsNo'], $result['html']);
        $this->assertStringContainsString('TO-2026-0099', $result['html']);
        $this->assertStringNotContainsString('No suspension against this payroll has been settled',
            $result['html']);
    }

    /* -------------------------------------------------------------- fixture */

    private function currentPayloadHash(): string
    {
        $st = TestDatabase::connect()->prepare('SELECT PayloadHash FROM Payroll WHERE PayrollNo = ?');
        $st->execute([self::PAYROLL]);
        return (string) $st->fetchColumn();
    }

    private function approve(): void
    {
        $result = \apiApprovePayroll(
            ['PayrollNo' => self::PAYROLL, 'Password' => self::PASSWORD], $this->auditor());
        $this->assertTrue($result['approved'], 'Fixture setup: the clean payroll failed to approve.');
    }

    /** Simulates an edit to a locked payroll bypassing the normal save path. */
    private function mutateApprovedPayrollDirectly(): void
    {
        TestDatabase::connect()
            ->prepare('UPDATE PayrollDetails SET NetPay = 999999.00 WHERE PayrollNo = ?')
            ->execute([self::PAYROLL]);
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

    private function auditor(): array
    {
        return [
            'Email' => self::AUDITOR, 'FullName' => 'Print gating fixture', 'Role' => 'Pre-Auditor',
            'OfficeCode' => '', 'permissions' => \PERMISSIONS['Pre-Auditor'],
        ];
    }

    private function statusOf(string $payrollNo): string
    {
        $st = TestDatabase::connect()->prepare('SELECT Status FROM Payroll WHERE PayrollNo = ?');
        $st->execute([$payrollNo]);
        return (string) $st->fetchColumn();
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
            ->execute([self::OFFICE, 'Print gating fixture', 'Active']);
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::AUDITOR, 'Print gating fixture', 'Pre-Auditor', '', 'Active', $hash]);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZPG-1', self::AUDITOR, self::OFFICE]);

        $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                             EmploymentType, EmploymentTypeCode, Position, Status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::EMPLOYEE, 'CLEAN', 'Employee', self::OFFICE, 'Job Order', 'JO', 'Worker', 'Active']);

        // Rate matches the contract exactly, so RuleEngine raises no BLOCKER
        // and apiApprovePayroll approves outright - the payload hash needs an
        // approval to exist against, not a suspension.
        $db->prepare('INSERT INTO Contracts (ContractID, EmployeeID, RateBasis, Rate, StartDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['CON-' . self::EMPLOYEE, self::EMPLOYEE, 'Daily', 500.00, '2026-01-01', 'Active']);

        // PreparedByUser deliberately left unset (NULL): requireDifferentApprover()
        // treats an empty preparer as no refusal, and PreparedByUser is a real
        // FK to Users - naming an email with no matching row would fail the insert.
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet, PreparedBy)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PAYROLL, self::PERIOD, self::OFFICE, 'FOR_PRE_AUDIT', 5000.00,
                'Print gating fixture']);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZPG-1', self::PAYROLL, self::EMPLOYEE, self::OFFICE,
                'CLEAN, Employee', 500.00, 10, 5000.00, 5000.00]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();

        $db->exec("DELETE FROM Logs WHERE Details LIKE '" . self::PAYROLL . "%'");
        $db->exec("DELETE FROM PrintLog WHERE PayrollNo = '" . self::PAYROLL . "'");
        $db->exec("DELETE FROM Suspensions WHERE PayrollNo = '" . self::PAYROLL . "'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo = '" . self::PAYROLL . "'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo = '" . self::PAYROLL . "'");
        $db->exec("DELETE FROM Contracts WHERE EmployeeID = '" . self::EMPLOYEE . "'");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID = 'SG-ZZPG-1'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID = '" . self::EMPLOYEE . "'");
        $db->exec("DELETE FROM Users WHERE Email = '" . self::AUDITOR . "'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode = '" . self::OFFICE . "'");
    }
}
