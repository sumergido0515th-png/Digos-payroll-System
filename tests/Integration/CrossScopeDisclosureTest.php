<?php
/**
 * ============================================================================
 * CrossScopeDisclosureTest - the two paths that answered questions about
 * another office's people.
 *
 * Neither was a missing permission check. Both endpoints were reachable by
 * design; what leaked was WHOSE data came back:
 *
 *   apiComputePayroll   took any EmployeeID, looked it up unscoped, and
 *                       returned that employee's daily rate in the computed
 *                       line. Gated on payroll.view alone - held by Encoder,
 *                       Office Head and Internal Auditor, none of whom hold
 *                       employee.sensitive.
 *
 *   duplicateEmployees  ran system-wide, correctly, and then handed back the
 *                       clashing employee's NAME and PAYROLL NUMBER whatever
 *                       office they belonged to.
 *
 * The rate and the clash are both legitimate answers. The names attached to
 * them were not.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class CrossScopeDisclosureTest extends TestCase
{
    private const MINE = 'ZZDMINE';
    private const THEIRS = 'ZZDTHEIRS';
    private const PERIOD = 'PRD-ZZDISC';
    private const THEIR_PAYROLL = 'ZZD-THEIRS-1';
    private const MY_EMPLOYEE = 'EMP-ZZDISC-MINE';
    private const THEIR_EMPLOYEE = 'EMP-ZZDISC-THEIRS';
    private const THEIR_NAME = 'UNSEEABLE, Person';

    private const ENCODER = 'zzd-encoder@digos.gov.ph';
    private const ADMIN = 'zzd-admin@digos.gov.ph';

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

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Disclosure fixture $office", 'Active']);
        }
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        // Distinct rates, so a test can tell which employee it actually got.
        foreach ([[self::MY_EMPLOYEE, self::MINE, 'MINE, Person', 500.00],
                  [self::THEIR_EMPLOYEE, self::THEIRS, self::THEIR_NAME, 999.00]] as $e) {
            [$id, $office, $name, $rate] = $e;
            [$last, $first] = explode(', ', $name);

            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $last, $first, $office, 'Job Order', 'JO', 'Worker', 'Active']);
            $db->prepare('INSERT INTO EmployeeSensitive (EmployeeID, DailyRate, HourlyRate)
                          VALUES (?, ?, ?)')
                ->execute([$id, $rate, $rate / 8]);
        }

        // Their employee is already on their payroll, in this period - the
        // clash duplicateEmployees() is meant to find.
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet)
                      VALUES (?, ?, ?, ?, ?)')
            ->execute([self::THEIR_PAYROLL, self::PERIOD, self::THEIRS, 'Approved', 999.00]);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZDISC-1', self::THEIR_PAYROLL, self::THEIR_EMPLOYEE, self::THEIRS,
                self::THEIR_NAME, 999.00, 1, 999.00, 999.00]);

        // An Encoder scoped to one office, and an Admin who may look across.
        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ENCODER, 'Disclosure fixture', 'Encoder', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZDISC-1', self::ENCODER, self::MINE]);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ADMIN, 'Disclosure fixture', 'Admin', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite)
                      VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZDISC-2', self::ADMIN]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZDISC-%'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo LIKE 'ZZD-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZD-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM EmployeeSensitive WHERE EmployeeID LIKE 'EMP-ZZDISC-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZDISC-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzd-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    private function user(string $email, string $role): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Disclosure fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /* ------------------------------------------- A1: the rate through compute */

    /**
     * The Encoder is the sharp case: they hold payroll.view and NOT
     * employee.sensitive, so this endpoint was their route to any rate in the
     * city.
     */
    public function testComputingWithAnOutOfScopeEmployeeIsRefused(): void
    {
        $this->expectExceptionMessage('Employee not found');

        \apiComputePayroll(
            ['lines' => [['EmployeeID' => self::THEIR_EMPLOYEE, 'DaysWorked' => 1]]],
            $this->user(self::ENCODER, 'Encoder'));
    }

    /** The refusal must not name them either. */
    public function testTheRefusalDoesNotNameTheEmployee(): void
    {
        try {
            \apiComputePayroll(
                ['lines' => [['EmployeeID' => self::THEIR_EMPLOYEE, 'DaysWorked' => 1]]],
                $this->user(self::ENCODER, 'Encoder'));
            $this->fail('An out-of-scope employee was computed.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('UNSEEABLE', $e->getMessage());
            $this->assertStringNotContainsString('999', $e->getMessage());
        }
    }

    /** Their own office still computes, rate and all - this is the day job. */
    public function testComputingWithinScopeStillWorks(): void
    {
        $result = \apiComputePayroll(
            ['lines' => [['EmployeeID' => self::MY_EMPLOYEE, 'DaysWorked' => 1]]],
            $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame(500.0, $result['lines'][0]['SalaryRate']);
        $this->assertSame(500.0, $result['totals']['gross']);
    }

    /* --------------------------------------- A2: the clash through duplicates */

    /**
     * The clash still has to be reported - an employee paid twice in one period
     * is the finding whatever office did it - but without the name or the
     * payroll number.
     */
    public function testAnOutOfScopeClashIsReportedWithoutIdentifyingAnyone(): void
    {
        $clashes = \duplicateEmployees(
            self::PERIOD, '', [self::THEIR_EMPLOYEE], $this->user(self::ENCODER, 'Encoder'));

        $this->assertNotEmpty($clashes, 'The clash was not reported at all.');

        $joined = implode(' | ', $clashes);
        $this->assertStringNotContainsString('UNSEEABLE', $joined,
            'Another office\'s employee name was disclosed.');
        $this->assertStringNotContainsString(self::THEIR_PAYROLL, $joined,
            'Another office\'s payroll number was disclosed.');
        $this->assertStringContainsString('do not have access', $joined);
    }

    /** scope.manage is what the phase plan grants the full picture to. */
    public function testAnAdminSeesTheFullDetail(): void
    {
        $joined = implode(' | ', \duplicateEmployees(
            self::PERIOD, '', [self::THEIR_EMPLOYEE], $this->user(self::ADMIN, 'Admin')));

        $this->assertStringContainsString('UNSEEABLE', $joined);
        $this->assertStringContainsString(self::THEIR_PAYROLL, $joined);
    }

    /** In scope, nothing changes: the preparer sees who and which payroll. */
    public function testAnInScopeClashIsStillNamed(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet)
                      VALUES (?, ?, ?, ?, ?)')
            ->execute(['ZZD-MINE-1', self::PERIOD, self::MINE, 'Draft', 500.00]);
        $db->prepare('INSERT INTO PayrollDetails (DetailID, PayrollNo, LineNo, EmployeeID,
                                                  ChargedOfficeCode, EmployeeName, SalaryRate,
                                                  DaysWorked, GrossPay, NetPay)
                      VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['PD-ZZDISC-2', 'ZZD-MINE-1', self::MY_EMPLOYEE, self::MINE,
                'MINE, Person', 500.00, 1, 500.00, 500.00]);

        $joined = implode(' | ', \duplicateEmployees(
            self::PERIOD, '', [self::MY_EMPLOYEE], $this->user(self::ENCODER, 'Encoder')));

        $this->assertStringContainsString('MINE, Person', $joined);
        $this->assertStringContainsString('ZZD-MINE-1', $joined);
    }
}
