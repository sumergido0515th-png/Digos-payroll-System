<?php
/**
 * ============================================================================
 * DeleteGuardTest - Every master-data delete must refuse in words a timekeeper
 * can act on, and must never destroy payroll history on the way.
 *
 * Migration 0009 turned the schema's references into real foreign keys, which
 * changed what a delete does without changing any calling code:
 *
 *   ON DELETE RESTRICT  the database refuses, raising SQLSTATE 23000 errno
 *                       1451. api.php returns the exception message verbatim,
 *                       so an unguarded path shows a foreign key constraint
 *                       error to a user who wanted to tidy up an office list.
 *   ON DELETE SET NULL  the database allows it and silently blanks the
 *                       referencing column - including FunctionCode on
 *                       approved payrolls, which is which appropriation paid
 *                       them and is recoverable from nothing else.
 *
 * The second is the dangerous one, because nothing at all appears to go wrong.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeleteGuardTest extends TestCase
{
    private const OFFICE = 'ZZGUARD';
    private const FUNC = 'ZZGUARDF';
    private const PERIOD = 'PRD-ZZGUARD';
    private const PAYROLL = 'ZZG-0001';
    private const HISTORY_EMPLOYEE = 'EMP-ZZGUARD-HIST';

    /**
     * Carries documents but no contract and no DTR row, so each document guard
     * is the one being tested. referenceGuard stops at the first non-zero
     * count, so an employee with rate history would prove only that the
     * contract check still works.
     */
    private const DOC_EMPLOYEE = 'EMP-ZZGUARD-DOC';

    private const PARENT_DEPT = 'ZZGUARD-PAR';
    private const CHILD_DEPT = 'ZZGUARD-CHI';
    private const EMPTY_PERIOD = 'PRD-ZZGUARD-EMPTY';
    private const ACTOR = 'zzguard.actor@example.test';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped(
                'No test database reachable. Set DB_HOST/DB_NAME/DB_USER/DB_PASS and run '
                . 'php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
    }

    /** An office and a Function/PPA, with one approved payroll charged to both. */
    private function createFixture(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
            ->execute([self::OFFICE, 'Delete guard fixture', 'Active']);
        $db->prepare('INSERT INTO Functions (FunctionCode, FunctionName, Status) VALUES (?, ?, ?)')
            ->execute([self::FUNC, 'Delete guard fund', 'Active']);
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, FunctionCode, Status)
                      VALUES (?, ?, ?, ?, ?)')
            ->execute([self::PAYROLL, self::PERIOD, self::OFFICE, self::FUNC, 'PRE_AUDIT_APPROVED']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('DELETE FROM DtrDays WHERE EmployeeID = ?')->execute([self::HISTORY_EMPLOYEE]);
        $db->prepare('DELETE FROM Contracts WHERE EmployeeID = ?')->execute([self::HISTORY_EMPLOYEE]);
        $db->prepare('DELETE FROM Employees WHERE EmployeeID = ?')->execute([self::HISTORY_EMPLOYEE]);
        // Suspensions reference the payroll as well as the employee, so they go
        // before it rather than with the rest of the document rows.
        $db->prepare('DELETE FROM Suspensions WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM TravelOrders WHERE EmployeeID = ?')->execute([self::DOC_EMPLOYEE]);
        $db->prepare('DELETE FROM BioExemptions WHERE EmployeeID = ?')->execute([self::DOC_EMPLOYEE]);
        $db->prepare('DELETE FROM Employees WHERE EmployeeID = ?')->execute([self::DOC_EMPLOYEE]);
        $db->prepare('DELETE FROM DtrDays WHERE PeriodID = ?')->execute([self::EMPTY_PERIOD]);
        $db->prepare('DELETE FROM PayrollPeriods WHERE PeriodID = ?')->execute([self::EMPTY_PERIOD]);
        $db->prepare('DELETE FROM Departments WHERE DeptCode IN (?, ?)')
            ->execute([self::CHILD_DEPT, self::PARENT_DEPT]);
        $db->prepare('DELETE FROM PayrollDetails WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM Payroll WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM PayrollPeriods WHERE PeriodID = ?')->execute([self::PERIOD]);
        $db->prepare('DELETE FROM Offices WHERE OfficeCode = ?')->execute([self::OFFICE]);
        $db->prepare('DELETE FROM Functions WHERE FunctionCode = ?')->execute([self::FUNC]);
        // Last: Users is SET NULL from Payroll, so the payroll rows above have
        // to be gone before this is a clean removal rather than a blanking.
        $db->prepare('DELETE FROM Users WHERE Email = ?')->execute([self::ACTOR]);
    }

    /** A separate employee with live rate and timekeeping history. */
    private function createHistoryEmployeeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                             EmploymentType, EmploymentTypeCode, Position, Status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::HISTORY_EMPLOYEE, 'Guard', 'History', self::OFFICE,
                'Job Order', 'JO', 'Clerk', 'Active']);
        $db->prepare('INSERT INTO Contracts (ContractID, EmployeeID, RateBasis, Rate, StartDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['CNT-ZZGUARD-001', self::HISTORY_EMPLOYEE, 'Daily', 500.00, '2026-07-01', 'Active']);
        $db->prepare('INSERT INTO DtrDays (DtrDayID, EmployeeID, WorkDate, PeriodID, HoursWorked, Source)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['DTR-ZZGUARD-001', self::HISTORY_EMPLOYEE, '2026-07-01', self::PERIOD, 8.00, 'Manual']);
    }

    /** An employee with nothing attached yet - the mistake a delete is for. */
    private function createDocumentEmployeeFixture(): void
    {
        TestDatabase::connect()
            ->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                              EmploymentType, EmploymentTypeCode, Position, Status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::DOC_EMPLOYEE, 'Guard', 'Document', self::OFFICE,
                'Job Order', 'JO', 'Clerk', 'Active']);
    }

    public function testDeletingAnEmployeeWithTravelOrdersIsRefused(): void
    {
        $this->createDocumentEmployeeFixture();
        TestDatabase::connect()
            ->prepare('INSERT INTO TravelOrders (TravelOrderID, TravelOrderNo, EmployeeID,
                                                 Destination, DepartDate, ReturnDate)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['TO-ZZGUARD-001', 'TO-2026-ZZG-001', self::DOC_EMPLOYEE,
                'Davao City', '2026-07-02', '2026-07-03']);

        $message = $this->refusalMessageFrom(fn() => apiDeleteEmployee([
            'EmployeeID' => self::DOC_EMPLOYEE,
        ], []));

        $this->assertNotNull($message,
            'Deleting an employee with a travel order should have been refused - the order '
            . 'cascades away with them and it carries its own control number.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('travel order', $message);

        $this->assertSame(1,
            $this->rowCount('SELECT COUNT(*) FROM TravelOrders WHERE EmployeeID = ?', self::DOC_EMPLOYEE),
            'The travel order was destroyed by the cascade.');
    }

    public function testDeletingAnEmployeeWithBioExemptionsIsRefused(): void
    {
        $this->createDocumentEmployeeFixture();
        TestDatabase::connect()
            ->prepare('INSERT INTO BioExemptions (ExemptionID, EmployeeID, ReasonCode, Reason,
                                                  ValidFrom, ValidTo)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['BIO-ZZGUARD-001', self::DOC_EMPLOYEE, 'FIELD', 'Field assignment',
                '2026-07-01', '2026-07-15']);

        $message = $this->refusalMessageFrom(fn() => apiDeleteEmployee([
            'EmployeeID' => self::DOC_EMPLOYEE,
        ], []));

        $this->assertNotNull($message,
            'Deleting an employee with a bio exemption should have been refused - it is what '
            . 'accounts for their missing scans.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('exemption', $message);

        $this->assertSame(1,
            $this->rowCount('SELECT COUNT(*) FROM BioExemptions WHERE EmployeeID = ?', self::DOC_EMPLOYEE),
            'The bio exemption was destroyed by the cascade.');
    }

    public function testDeletingAnEmployeeWithPreAuditSuspensionsIsRefused(): void
    {
        $this->createDocumentEmployeeFixture();
        TestDatabase::connect()
            ->prepare('INSERT INTO Suspensions (NsNo, PayrollNo, EmployeeID, GroundCode, Particulars)
                       VALUES (?, ?, ?, ?, ?)')
            ->execute(['NS-ZZGUARD-001', self::PAYROLL, self::DOC_EMPLOYEE, 'DOC-001',
                'Hand-keyed day with no justification.']);

        $message = $this->refusalMessageFrom(fn() => apiDeleteEmployee([
            'EmployeeID' => self::DOC_EMPLOYEE,
        ], []));

        $this->assertNotNull($message,
            'Deleting an employee named on a pre-audit suspension should have been refused - '
            . 'the finding and its settlement record cascade away with them.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('suspension', $message);

        $this->assertSame(1,
            $this->rowCount('SELECT COUNT(*) FROM Suspensions WHERE EmployeeID = ?', self::DOC_EMPLOYEE),
            'The suspension was destroyed by the cascade.');
    }

    /**
     * The guard has to stay a guard rather than becoming a wall. A count query
     * missing its WHERE clause would refuse every delete in the system while
     * every test above still passed, so the permitted case is asserted too.
     */
    public function testAnEmployeeWithNoHistoryAtAllStillDeletes(): void
    {
        $this->createDocumentEmployeeFixture();

        $result = apiDeleteEmployee(['EmployeeID' => self::DOC_EMPLOYEE], []);

        $this->assertSame(1, $result['deleted'],
            'An employee with no payroll, contract, DTR or document history should delete - '
            . 'removing a mis-keyed record is what this endpoint is for.');
        $this->assertSame(0,
            $this->rowCount('SELECT COUNT(*) FROM Employees WHERE EmployeeID = ?', self::DOC_EMPLOYEE));
    }

    public function testDeletingADepartmentThatOthersSitUnderIsRefused(): void
    {
        $db = TestDatabase::connect();
        $insert = $db->prepare('INSERT INTO Departments (DeptCode, ParentDeptCode, DeptName, OfficeCode, Status)
                                VALUES (?, ?, ?, ?, ?)');
        $insert->execute([self::PARENT_DEPT, null, 'Guard parent', self::OFFICE, 'Active']);
        $insert->execute([self::CHILD_DEPT, self::PARENT_DEPT, 'Guard child', self::OFFICE, 'Active']);

        $message = $this->refusalMessageFrom(fn() => apiDeleteDepartment([
            'DeptCode' => self::PARENT_DEPT,
        ], []));

        $this->assertNotNull($message,
            'Deleting a department with children should have been refused - ParentDeptCode is '
            . 'SET NULL, so the children survive with no parent and no record of which it was.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('department', $message);

        $this->assertSame(self::PARENT_DEPT,
            TestDatabase::connect()
                ->query("SELECT ParentDeptCode FROM Departments WHERE DeptCode = '" . self::CHILD_DEPT . "'")
                ->fetchColumn(),
            'The child department was orphaned - its parent link was blanked.');
    }

    public function testDeletingAPeriodWithCapturedDtrDaysIsRefused(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::EMPTY_PERIOD, 'August', 2026, '2026-08-01', '2026-08-15', 'Open']);
        $this->createDocumentEmployeeFixture();
        $db->prepare('INSERT INTO DtrDays (DtrDayID, EmployeeID, WorkDate, PeriodID, HoursWorked, Source)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['DTR-ZZGUARD-EMPTY', self::DOC_EMPLOYEE, '2026-08-03', self::EMPTY_PERIOD, 8.00, 'Manual']);

        $message = $this->refusalMessageFrom(fn() => apiDeletePeriod([
            'PeriodID' => self::EMPTY_PERIOD,
        ], []));

        $this->assertNotNull($message,
            'Deleting a period with captured DTR days should have been refused - DtrDays.PeriodID '
            . 'is SET NULL, so the days survive belonging to no period.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('daily time record', $message);

        $this->assertSame(1,
            $this->rowCount('SELECT COUNT(*) FROM DtrDays WHERE PeriodID = ?', self::EMPTY_PERIOD),
            'The DTR day was detached from its period.');
    }

    /**
     * The two columns segregation of duties is checked against are SET NULL
     * from Users. Deleting the account leaves the payroll standing with nobody
     * having prepared it, which is the question an auditor asks first.
     */
    public function testDeletingAUserWhoPreparedAPayrollIsRefused(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO Users (Email, FullName, Role, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?)')
            ->execute([self::ACTOR, 'Guard Actor', 'Encoder', 'Active', 'x']);
        $db->prepare('UPDATE Payroll SET PreparedByUser = ? WHERE PayrollNo = ?')
            ->execute([self::ACTOR, self::PAYROLL]);

        $message = $this->refusalMessageFrom(fn() => apiDeleteUser(
            ['Email' => self::ACTOR],
            ['Email' => 'someone.else@example.test']));

        $this->assertNotNull($message,
            'Deleting a user who prepared a payroll should have been refused.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('prepared', $message);
        $this->assertStringContainsString('inactive', $message);

        $this->assertSame(self::ACTOR,
            TestDatabase::connect()
                ->query("SELECT PreparedByUser FROM Payroll WHERE PayrollNo = '" . self::PAYROLL . "'")
                ->fetchColumn(),
            'The payroll lost the name of whoever prepared it.');
    }

    public function testDeletingAUserWhoHasTouchedNothingStillWorks(): void
    {
        TestDatabase::connect()
            ->prepare('INSERT INTO Users (Email, FullName, Role, Status, PasswordHash)
                       VALUES (?, ?, ?, ?, ?)')
            ->execute([self::ACTOR, 'Guard Actor', 'Encoder', 'Active', 'x']);

        $result = apiDeleteUser(['Email' => self::ACTOR], ['Email' => 'someone.else@example.test']);

        $this->assertSame(1, $result['deleted'],
            'An account that has prepared, approved, printed and granted nothing should still '
            . 'be removable - otherwise a mis-keyed address can never be cleaned up.');
    }

    public function testDeletingAnOfficeWithPayrollHistoryIsRefusedInPlainWords(): void
    {
        $message = $this->refusalMessageFrom(fn() => apiDeleteOffice(['OfficeCode' => self::OFFICE], []));

        $this->assertNotNull($message,
            'Deleting an office with a payroll charged to it should have been refused.');
        $this->assertStringNotContainsString('sqlstate', $message,
            'The raw driver error reached the user instead of a guard message.');
        $this->assertStringNotContainsString('foreign key', $message);
        $this->assertStringContainsString('payroll', $message);

        $this->assertSame(1, $this->rowCount('SELECT COUNT(*) FROM Offices WHERE OfficeCode = ?', self::OFFICE),
            'The office was deleted despite the guard.');
    }

    public function testDeletingAnEmployeeWithContractsOrDtrHistoryIsRefused(): void
    {
        $this->createHistoryEmployeeFixture();

        $message = $this->refusalMessageFrom(fn() => apiDeleteEmployee([
            'EmployeeID' => self::HISTORY_EMPLOYEE,
        ], []));

        $this->assertNotNull($message,
            'Deleting an employee with contracts and DTR history should have been refused.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('contract', $message);
        $this->assertStringContainsString('history', $message);

        $this->assertSame(1, $this->rowCount('SELECT COUNT(*) FROM Employees WHERE EmployeeID = ?', self::HISTORY_EMPLOYEE),
            'The employee was deleted despite having history.');
    }

    public function testDeletingAFunctionChargedByAPayrollIsRefused(): void
    {
        $message = $this->refusalMessageFrom(fn() => apiDeleteFunction(['FunctionCode' => self::FUNC], []));

        $this->assertNotNull($message,
            'Deleting a Function/PPA charged by a payroll should have been refused.');
        $this->assertStringNotContainsString('sqlstate', $message);
        $this->assertStringContainsString('payroll', $message);
    }

    /**
     * The lower-cased refusal message, or null if the call was allowed.
     *
     * Catching RuntimeException around the call itself would be wrong here:
     * PHPUnit\Framework\Exception extends RuntimeException, so a `fail()`
     * inside the try block is swallowed by its own catch and the test passes
     * whatever happens. This one caught exactly that.
     */
    private function refusalMessageFrom(callable $call): ?string
    {
        try {
            $call();
            return null;
        } catch (\Throwable $e) {
            return strtolower($e->getMessage());
        }
    }

    /**
     * The regression this file exists for. Functions is referenced ON DELETE
     * SET NULL, so before the guard the delete succeeded and the approved
     * payroll simply stopped saying which appropriation paid it.
     */
    public function testAnApprovedPayrollKeepsItsAppropriationAfterARefusedDelete(): void
    {
        try {
            apiDeleteFunction(['FunctionCode' => self::FUNC], []);
        } catch (RuntimeException) {
            // The refusal is asserted above; here only its effect matters.
        }

        $st = TestDatabase::connect()->prepare('SELECT FunctionCode FROM Payroll WHERE PayrollNo = ?');
        $st->execute([self::PAYROLL]);

        $this->assertSame(self::FUNC, $st->fetchColumn(),
            'The approved payroll lost its Function/PPA - which appropriation paid it is now unrecoverable.');
    }

    public function testAnUnreferencedOfficeStillDeletes(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('DELETE FROM Payroll WHERE PayrollNo = ?')->execute([self::PAYROLL]);

        apiDeleteOffice(['OfficeCode' => self::OFFICE], []);

        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM Offices WHERE OfficeCode = ?', self::OFFICE),
            'The guard blocks a delete that nothing references.');
    }

    public function testTheHistoryEmployeeCanBeCleanedUpOnceItsHistoryIsRemoved(): void
    {
        $this->createHistoryEmployeeFixture();
        $db = TestDatabase::connect();
        $db->prepare('DELETE FROM DtrDays WHERE EmployeeID = ?')->execute([self::HISTORY_EMPLOYEE]);
        $db->prepare('DELETE FROM Contracts WHERE EmployeeID = ?')->execute([self::HISTORY_EMPLOYEE]);

        apiDeleteEmployee(['EmployeeID' => self::HISTORY_EMPLOYEE], []);

        $this->assertSame(0, $this->rowCount('SELECT COUNT(*) FROM Employees WHERE EmployeeID = ?', self::HISTORY_EMPLOYEE),
            'The employee still existed after the history rows were removed.');
    }

    private function rowCount(string $sql, string $param): int
    {
        $st = TestDatabase::connect()->prepare($sql);
        $st->execute([$param]);
        return (int) $st->fetchColumn();
    }
}
