<?php
/**
 * ============================================================================
 * EmployeesFunctionTest - employeesFunction() in app/PrintDoc.php.
 *
 * WHY THIS EXISTS
 * This is the last fallback for the CAFOA's Function/PPA cell, reached when
 * neither the payroll nor its office records one. It decides which
 * appropriation an amount is printed against, so the case that matters is the
 * disagreeing one: a payroll whose employees sit under two functions must
 * yield '' and leave the cell for the budget officer, never pick a winner.
 *
 * The function is pure - arrays in, string out, no DB:: - which is what lets
 * it be tested here without a database.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmployeesFunctionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once PROJECT_ROOT . '/app/PrintDoc.php';
    }

    /** Employee rows are keyed by id and carry the raw DB column name. */
    private function employees(string ...$functions): array
    {
        $rows = [];
        foreach ($functions as $i => $f) {
            $rows['EMP' . $i] = ['EmployeeID' => 'EMP' . $i, 'FunctionName' => $f];
        }
        return $rows;
    }

    public function testReturnsTheFunctionEveryEmployeeShares(): void
    {
        self::assertSame('General Public Services',
            employeesFunction($this->employees('General Public Services', 'General Public Services')));
    }

    public function testReturnsEmptyWhenEmployeesDisagree(): void
    {
        self::assertSame('',
            employeesFunction($this->employees('General Public Services', 'Health Services')));
    }

    public function testIgnoresEmployeesWithNoFunctionRecorded(): void
    {
        self::assertSame('Health Services',
            employeesFunction($this->employees('', 'Health Services', '')));
    }

    public function testReturnsEmptyWhenNoEmployeeHasOne(): void
    {
        self::assertSame('', employeesFunction($this->employees('', '')));
    }

    public function testReturnsEmptyForAPayrollWithNoLines(): void
    {
        self::assertSame('', employeesFunction([]));
    }

    /** Whitespace-only is not a function; it would print as a blank cell. */
    public function testTreatsWhitespaceAsAbsent(): void
    {
        self::assertSame('Health Services',
            employeesFunction($this->employees('   ', 'Health Services')));
    }

    /** A missing column must not fatal - older rows predate the field. */
    public function testToleratesRowsWithoutTheColumn(): void
    {
        self::assertSame('Health Services',
            employeesFunction([
                'EMP0' => ['EmployeeID' => 'EMP0'],
                'EMP1' => ['EmployeeID' => 'EMP1', 'FunctionName' => 'Health Services'],
            ]));
    }
}
