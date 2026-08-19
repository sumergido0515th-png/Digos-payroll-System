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
        $db->prepare('DELETE FROM PayrollDetails WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM Payroll WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM PayrollPeriods WHERE PeriodID = ?')->execute([self::PERIOD]);
        $db->prepare('DELETE FROM Offices WHERE OfficeCode = ?')->execute([self::OFFICE]);
        $db->prepare('DELETE FROM Functions WHERE FunctionCode = ?')->execute([self::FUNC]);
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
