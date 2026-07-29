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

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped(
                'No test database reachable. Set DB_HOST/DB_NAME/DB_USER/DB_PASS and run '
                . 'php tools/migrate.php first.');
        }
        self::loadApplicationLayerAgainstTheTestDatabase();
        $this->removeFixture();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
    }

    /**
     * Loads the api* functions with DB_NAME pinned to the test database.
     *
     * app/bootstrap.php is never used here - it opens a connection and starts
     * a session (see CLAUDE.md). Defining DB_NAME first is what keeps DB:: off
     * the working database: app/config.php only fills in constants that are
     * still unset, and config.local.php does not set this one.
     */
    private static function loadApplicationLayerAgainstTheTestDatabase(): void
    {
        if (function_exists('apiDeleteOffice')) return;

        $name = TestDatabase::config()['name'];
        if (!defined('DB_NAME')) define('DB_NAME', $name);

        if (DB_NAME !== $name) {
            throw new RuntimeException(
                'DB_NAME is already ' . DB_NAME . ', not the test database ' . $name . '.');
        }

        require_once PROJECT_ROOT . '/app/config.php';
        require_once PROJECT_ROOT . '/app/Database.php';
        require_once PROJECT_ROOT . '/app/Helpers.php';
        require_once PROJECT_ROOT . '/app/Master.php';
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
            ->execute([self::PAYROLL, self::PERIOD, self::OFFICE, self::FUNC, 'Approved']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('DELETE FROM PayrollDetails WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM Payroll WHERE PayrollNo = ?')->execute([self::PAYROLL]);
        $db->prepare('DELETE FROM PayrollPeriods WHERE PeriodID = ?')->execute([self::PERIOD]);
        $db->prepare('DELETE FROM Offices WHERE OfficeCode = ?')->execute([self::OFFICE]);
        $db->prepare('DELETE FROM Functions WHERE FunctionCode = ?')->execute([self::FUNC]);
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

    private function rowCount(string $sql, string $param): int
    {
        $st = TestDatabase::connect()->prepare($sql);
        $st->execute([$param]);
        return (int) $st->fetchColumn();
    }
}
