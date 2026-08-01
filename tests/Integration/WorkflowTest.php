<?php
/**
 * ============================================================================
 * WorkflowTest - Phase 7's exit gate, with two real accounts.
 *
 *   "Test with two real accounts: pre-auditor can suspend/approve; preparer
 *    account cannot approve its own submitted payroll (blocked at the
 *    permission layer, not just hidden in UI)."
 *
 * BLOCKED AT THE PERMISSION LAYER is the phrase that matters and is asserted
 * literally: Payroll In-Charge does not hold payroll.approve at all, so
 * requirePermission() - the same gate every route in public/api.php passes
 * through - refuses the action before apiApprovePayroll is ever called. A UI
 * that merely hid the Approve button would still leave the route reachable by
 * anyone who could see the network request; this is the difference between
 * the two.
 *
 * The rest of the file exercises what the plan calls the harder half:
 * suspending, settling, and the employee-scoped split that lets clean lines
 * proceed while a blocked one is held for a supplemental payroll.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use Digos\Repo\SuspensionRepo;
use PHPUnit\Framework\TestCase;
use Throwable;

final class WorkflowTest extends TestCase
{
    private const OFFICE = 'ZZWF';
    private const PERIOD = 'PRD-ZZWF';
    private const CLEAN_PAYROLL = 'ZZWF-CLEAN';
    private const SPLIT_PAYROLL = 'ZZWF-SPLIT';
    private const EMP_CLEAN = 'EMP-ZZWF-1';
    private const EMP_MISMATCHED = 'EMP-ZZWF-2';
    // Distinct from the two above and from each other: CON-003 checks for one
    // employee on two payrolls in the same period, and reusing EMP_CLEAN on
    // both the clean and the split fixture would be exactly that - a genuine
    // double-booking this file would then be creating by accident rather than
    // testing on purpose.
    private const EMP_SPLIT_CLEAN = 'EMP-ZZWF-3';
    private const EMP_SPLIT_MISMATCHED = 'EMP-ZZWF-4';
    private const PREPARER = 'zzwf-preparer@digos.gov.ph';
    private const AUDITOR = 'zzwf-auditor@digos.gov.ph';
    private const PASSWORD = 'workflow-fixture-password';

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

    /* ------------------------------------------------------- the exit gate */

    /**
     * The preparer's role does not hold payroll.approve at all.
     *
     * Checked at the same gate every route passes through - requirePermission()
     * - rather than by asking whether a button would be visible. A hidden
     * button still leaves the route reachable to anyone who can see the
     * network request; a permission the role never holds does not.
     */
    public function testThePreparersRoleCannotReachApprovalAtThePermissionLayer(): void
    {
        $preparer = $this->user(self::PREPARER, 'Payroll In-Charge');

        $this->assertFalse(\hasPermission($preparer, 'payroll.approve'),
            'Payroll In-Charge must not hold payroll.approve - suspending, settling and '
            . 'approving are the pre-auditor\'s role, not the preparer\'s.');

        $this->expectExceptionMessage('Access denied');
        \requirePermission($preparer, 'payroll.approve');
    }

    /** The preparer's own SoD refusal still applies even for a role that could approve. */
    public function testThePreparerCannotApproveEvenIfActingAsPreAuditor(): void
    {
        // The preparer, this time holding the Pre-Auditor role - the case
        // where the permission layer alone is not enough and Phase 2's
        // identity check has to do the rest.
        $preparerAsAuditor = $this->user(self::PREPARER, 'Pre-Auditor');

        $message = $this->refusalFrom(fn() => \apiApprovePayroll(
            ['PayrollNo' => self::CLEAN_PAYROLL, 'Password' => self::PASSWORD], $preparerAsAuditor));

        $this->assertStringContainsString('cannot also approve', $message);
    }

    /** A pre-auditor who did not prepare it can approve a clean payroll. */
    public function testAPreAuditorCanApproveACleanPayroll(): void
    {
        $result = \apiApprovePayroll(
            ['PayrollNo' => self::CLEAN_PAYROLL, 'Password' => self::PASSWORD], $this->auditor());

        $this->assertTrue($result['approved']);
        $this->assertFalse($result['split']);
        $this->assertSame('PRE_AUDIT_APPROVED', $this->statusOf(self::CLEAN_PAYROLL));
    }

    /** A pre-auditor can suspend a payroll, manually, with a stated ground. */
    public function testAPreAuditorCanSuspendAPayroll(): void
    {
        $result = \apiSuspendPayroll([
            'PayrollNo' => self::CLEAN_PAYROLL,
            'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'The travel order cited does not match the dates claimed.',
            'RequiredAction' => 'Submit the correct travel order or amend the DTR.',
        ], $this->auditor());

        $this->assertSame('SUSPENDED', $result['Status']);
        $this->assertSame('SUSPENDED', $this->statusOf(self::CLEAN_PAYROLL));

        $suspension = SuspensionRepo::find($result['NsNo']);
        $this->assertSame(self::CLEAN_PAYROLL, $suspension['PayrollNo']);
        $this->assertNull($suspension['EmployeeID'], 'A manual whole-batch suspension named an employee.');
        $this->assertSame('Open', $suspension['Status']);
    }

    /* ---------------------------------------------------------- settlement */

    /** Settling the only open suspension returns the payroll to pre-audit. */
    public function testSettlingTheLastOpenSuspensionReturnsThePayrollToPreAudit(): void
    {
        $raised = \apiSuspendPayroll([
            'PayrollNo' => self::CLEAN_PAYROLL, 'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'x', 'RequiredAction' => 'y',
        ], $this->auditor());

        $result = \apiSettleSuspension(
            ['NsNo' => $raised['NsNo'], 'SettlementRef' => 'Travel order TO-2026-0099 filed.'],
            $this->auditor());

        $this->assertSame('Settled', $result['Status']);
        $this->assertTrue($result['payrollReopened']);
        $this->assertSame('FOR_PRE_AUDIT', $this->statusOf(self::CLEAN_PAYROLL));
    }

    /** A second open suspension keeps the payroll held after the first settles. */
    public function testASecondOpenSuspensionKeepsThePayrollHeld(): void
    {
        $first = \apiSuspendPayroll([
            'PayrollNo' => self::CLEAN_PAYROLL, 'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'first', 'RequiredAction' => 'fix first',
        ], $this->auditor());

        // Raising a second suspension while the payroll is already SUSPENDED:
        // SuspensionRepo::raise() writes the row directly and does not itself
        // require a transition, so this is legitimate even though
        // apiSuspendPayroll's own transition would refuse a second SUSPENDED
        // -> SUSPENDED move.
        SuspensionRepo::raise(SuspensionRepo::nextNsNo(), [
            'PayrollNo' => self::CLEAN_PAYROLL, 'EmployeeID' => null,
            'GroundCode' => 'COMPUTATION', 'RuleID' => null,
            'Particulars' => 'second', 'RequiredAction' => 'fix second',
            'RaisedBy' => self::AUDITOR,
        ]);

        $result = \apiSettleSuspension(
            ['NsNo' => $first['NsNo'], 'SettlementRef' => 'first fixed'], $this->auditor());

        $this->assertFalse($result['payrollReopened'],
            'The payroll reopened while a second finding was still open.');
        $this->assertSame('SUSPENDED', $this->statusOf(self::CLEAN_PAYROLL));
    }

    /** An already-settled suspension cannot be settled twice. */
    public function testAnAlreadySettledSuspensionCannotBeSettledAgain(): void
    {
        $raised = \apiSuspendPayroll([
            'PayrollNo' => self::CLEAN_PAYROLL, 'GroundCode' => 'DOCUMENT_INTEGRITY',
            'Particulars' => 'x', 'RequiredAction' => 'y',
        ], $this->auditor());
        \apiSettleSuspension(['NsNo' => $raised['NsNo'], 'SettlementRef' => 'done'], $this->auditor());

        $message = $this->refusalFrom(fn() => \apiSettleSuspension(
            ['NsNo' => $raised['NsNo'], 'SettlementRef' => 'again'], $this->auditor()));

        $this->assertStringContainsString('already Settled', $message);
    }

    /* --------------------------------------------- the employee-scoped split */

    /**
     * A BLOCKER naming one employee splits the batch: the clean line proceeds
     * to PRE_AUDIT_APPROVED under the original number, and the mismatched
     * employee moves to a new supplemental payroll that holds at SUSPENDED.
     *
     * This is the property Phase 5's 15-line cap exists to interact with:
     * fourteen correct coworkers are not made to wait on the fifteenth's
     * unresolved finding.
     */
    public function testABlockerNamingOneEmployeeSplitsTheBatch(): void
    {
        $result = \apiApprovePayroll(
            ['PayrollNo' => self::SPLIT_PAYROLL, 'Password' => self::PASSWORD], $this->auditor());

        $this->assertTrue($result['approved'], 'The clean line should still have been approved.');
        $this->assertTrue($result['split']);
        $this->assertArrayHasKey('supplementalPayrollNo', $result);

        $original = TestDatabase::connect()->prepare(
            'SELECT Status, TotalNet FROM Payroll WHERE PayrollNo = ?');
        $original->execute([self::SPLIT_PAYROLL]);
        $originalRow = $original->fetch();

        $supplementalNo = $result['supplementalPayrollNo'];
        $supplemental = TestDatabase::connect()->prepare(
            'SELECT Status, SupplementsPayrollNo, TotalNet FROM Payroll WHERE PayrollNo = ?');
        $supplemental->execute([$supplementalNo]);
        $supplementalRow = $supplemental->fetch();

        $this->assertSame('PRE_AUDIT_APPROVED', $originalRow['Status']);
        $this->assertSame('SUSPENDED', $supplementalRow['Status']);
        $this->assertSame(self::SPLIT_PAYROLL, $supplementalRow['SupplementsPayrollNo']);

        $this->rememberSupplemental($supplementalNo);

        // The clean employee's line stayed on the original; the mismatched
        // one moved to the supplemental, and only there.
        $originalLines = TestDatabase::connect()->prepare(
            'SELECT EmployeeID, LineNo FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo');
        $originalLines->execute([self::SPLIT_PAYROLL]);
        $originalEmployees = array_column($originalLines->fetchAll(), 'EmployeeID');

        $supplementalLines = TestDatabase::connect()->prepare(
            'SELECT EmployeeID, LineNo FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo');
        $supplementalLines->execute([$supplementalNo]);
        $supplementalLinesRows = $supplementalLines->fetchAll();

        $this->assertSame([self::EMP_SPLIT_CLEAN], $originalEmployees);
        $this->assertSame([self::EMP_SPLIT_MISMATCHED], array_column($supplementalLinesRows, 'EmployeeID'));
        $this->assertSame(1, (int) $supplementalLinesRows[0]['LineNo'],
            'The supplemental payroll\'s single line was not renumbered to 1.');

        // The suspension travelled with the employee, not with the batch it
        // was raised against.
        $open = SuspensionRepo::openFor($supplementalNo);
        $this->assertCount(1, $open);
        $this->assertSame(self::EMP_SPLIT_MISMATCHED, $open[0]['EmployeeID']);
        $this->assertSame('CMP-002', $open[0]['RuleID']);

        $this->assertSame([], SuspensionRepo::openFor(self::SPLIT_PAYROLL),
            'A suspension for the mismatched employee was left on the original payroll.');
    }

    /* -------------------------------------------------------------- fixture */

    /** @var string[] supplemental payroll numbers created mid-test, for cleanup */
    private array $supplementals = [];

    private function rememberSupplemental(string $payrollNo): void
    {
        $this->supplementals[] = $payrollNo;
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
        return $this->user(self::AUDITOR, 'Pre-Auditor');
    }

    private function user(string $email, string $role): array
    {
        return [
            'Email' => $email, 'FullName' => 'Workflow fixture', 'Role' => $role,
            'OfficeCode' => '', 'permissions' => \PERMISSIONS[$role],
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
            ->execute([self::OFFICE, 'Workflow fixture', 'Active']);
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        foreach ([self::PREPARER, self::AUDITOR] as $email) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Workflow fixture', 'Pre-Auditor', '', 'Active', $hash]);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                          VALUES (?, ?, ?, 1, 1)')
                ->execute(['SG-ZZWF-' . substr(md5($email), 0, 8), $email, self::OFFICE]);
        }

        foreach ([[self::EMP_CLEAN, 'CLEAN, Employee'], [self::EMP_MISMATCHED, 'MISMATCHED, Employee'],
                  [self::EMP_SPLIT_CLEAN, 'SPLITCLEAN, Employee'],
                  [self::EMP_SPLIT_MISMATCHED, 'SPLITMISMATCHED, Employee']] as $e) {
            [$id, $name] = $e;
            [$last, $first] = explode(', ', $name);
            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $last, $first, self::OFFICE, 'Job Order', 'JO', 'Worker', 'Active']);
        }

        // Contracts: all four at 500.00. The two "mismatched" employees'
        // PAYROLL LINES will claim 600.00, which is what CMP-002 catches.
        foreach ([self::EMP_CLEAN, self::EMP_MISMATCHED,
                  self::EMP_SPLIT_CLEAN, self::EMP_SPLIT_MISMATCHED] as $employeeId) {
            $db->prepare('INSERT INTO Contracts (ContractID, EmployeeID, RateBasis, Rate, StartDate, Status)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['CON-' . $employeeId, $employeeId, 'Daily', 500.00, '2026-01-01', 'Active']);
        }

        // The clean payroll: one line, rate matches the contract, fully in
        // order. Used for the plain approve/suspend/settle tests.
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet,
                                          PreparedBy, PreparedByUser)
                      VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::CLEAN_PAYROLL, self::PERIOD, self::OFFICE, 'FOR_PRE_AUDIT', 5000.00,
                'Workflow fixture', self::PREPARER]);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZWF-CLEAN', self::CLEAN_PAYROLL, self::EMP_CLEAN, self::OFFICE,
                'CLEAN, Employee', 500.00, 10, 5000.00, 5000.00]);

        // The split payroll: one clean line, one whose rate contradicts its
        // own contract - CMP-002, a BLOCKER, naming only that employee.
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet,
                                          PreparedBy, PreparedByUser)
                      VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::SPLIT_PAYROLL, self::PERIOD, self::OFFICE, 'FOR_PRE_AUDIT', 11000.00,
                'Workflow fixture', self::PREPARER]);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZWF-SPLIT-1', self::SPLIT_PAYROLL, self::EMP_SPLIT_CLEAN, self::OFFICE,
                'SPLITCLEAN, Employee', 500.00, 10, 5000.00, 5000.00]);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 2, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZWF-SPLIT-2', self::SPLIT_PAYROLL, self::EMP_SPLIT_MISMATCHED, self::OFFICE,
                'SPLITMISMATCHED, Employee', 600.00, 10, 6000.00, 6000.00]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ($this->supplementals as $no) {
            $db->exec("DELETE FROM Suspensions WHERE PayrollNo = '$no'");
            $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo = '$no'");
            $db->exec("DELETE FROM Payroll WHERE PayrollNo = '$no'");
        }
        $this->supplementals = [];

        $db->exec("DELETE FROM Suspensions WHERE PayrollNo LIKE 'ZZWF-%'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo LIKE 'ZZWF-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZWF-%'");
        $db->exec("DELETE FROM Contracts WHERE EmployeeID LIKE 'EMP-ZZWF-%'");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZWF-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZWF-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzwf-%@digos.gov.ph'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode = '" . self::OFFICE . "'");
    }
}
