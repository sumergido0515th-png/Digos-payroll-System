<?php
/**
 * ============================================================================
 * DtrCaptureTest - Phase 3B's exit gate.
 *
 * "For a test period, every PayrollDetails total is reproducible by summing
 *  that employee's DtrDays rows - verified by automated test, not by reading
 *  the screen. A manual entry is distinguishable from a biometric one in the
 *  stored data."
 *
 * Both halves are asserted here, and the first is asserted the hard way: the
 * payroll is SAVED through apiSavePayroll from totals the derivation produced,
 * and then the stored PayrollDetails row is compared back against a fresh
 * derivation from the day rows. Comparing the derivation to itself would pass
 * whatever the payroll actually stored.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Domain\Dtr\PeriodTotals;
use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;
use Throwable;

final class DtrCaptureTest extends TestCase
{
    private const OFFICE = 'ZZDTR';
    private const OTHER_OFFICE = 'ZZDTRB';
    private const PERIOD = 'PRD-ZZDTR';
    private const EMPLOYEE = 'EMP-ZZDTR-1';
    private const OTHER_EMPLOYEE = 'EMP-ZZDTR-2';
    private const KEEPER = 'zzdtr-keeper@digos.gov.ph';

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

    /* --------------------------------------------------------- the exit gate */

    /**
     * The stored payroll line matches a fresh derivation from the day rows.
     *
     * This is the whole point of the phase: after it, a line's DaysWorked is
     * evidence rather than an assertion, and this test is what says so.
     */
    public function testEveryStoredPayrollTotalIsReproducibleFromTheDayRows(): void
    {
        $this->keyAFortnight();

        $derived = \apiGetDtrTotals(
            ['PeriodID' => self::PERIOD, 'EmployeeID' => self::EMPLOYEE], $this->keeper());

        $saved = \apiSavePayroll([
            'PeriodID' => self::PERIOD,
            'OfficeCode' => self::OFFICE,
            'lines' => [array_merge(
                ['EmployeeID' => self::EMPLOYEE], $derived['totals'])],
        ], $this->keeper());

        $stored = TestDatabase::connect()->prepare(
            'SELECT DaysWorked, HoursWorked, OvertimeHours, LateMinutes,
                    UndertimeMinutes, AbsentDays
               FROM PayrollDetails WHERE PayrollNo = ?');
        $stored->execute([$saved['PayrollNo']]);
        $stored = $stored->fetch();

        $this->assertNotFalse($stored, 'The payroll saved no detail line.');

        // Derived a second time, from the days, rather than reusing $derived -
        // otherwise this compares the derivation to itself and would pass even
        // if the payroll stored something else entirely.
        $days = \Digos\Repo\DtrRepo::daysForPeriod(self::PERIOD, [self::EMPLOYEE]);
        $fresh = PeriodTotals::fromDays($days);

        foreach (PeriodTotals::KEYS as $key) {
            $this->assertEqualsWithDelta($fresh[$key], (float) $stored[$key], 0.001,
                "$key on the stored payroll line does not match the day rows it came from.");
        }
    }

    /** And the derivation is not vacuous - the fortnight has real numbers in it. */
    public function testTheDerivedTotalsAreTheFortnightThatWasKeyed(): void
    {
        $this->keyAFortnight();

        $totals = \apiGetDtrTotals(
            ['PeriodID' => self::PERIOD, 'EmployeeID' => self::EMPLOYEE],
            $this->keeper())['totals'];

        $this->assertSame(8.0, $totals['DaysWorked']);
        $this->assertSame(4.0, $totals['HoursWorked']);
        $this->assertSame(1.0, $totals['AbsentDays']);
        $this->assertSame(3.0, $totals['OvertimeHours']);
        $this->assertSame(45.0, $totals['LateMinutes']);
    }

    /* ---------------------------------------------- provenance: manual vs bio */

    /** Source is written on every row, so provenance is a fact not a default. */
    public function testAManualRowAndABiometricRowAreDistinguishableWhenStored(): void
    {
        \apiSaveDtrDays(['PeriodID' => self::PERIOD, 'days' => [
            ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01',
                'HoursWorked' => 8, 'Source' => 'Manual'],
            ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-02',
                'HoursWorked' => 8, 'Source' => 'Biometric'],
        ]], $this->keeper());

        $sources = TestDatabase::connect()->query(
            "SELECT WorkDate, Source FROM DtrDays
              WHERE EmployeeID = '" . self::EMPLOYEE . "' ORDER BY WorkDate")->fetchAll();

        $this->assertSame(['Manual', 'Biometric'], array_column($sources, 'Source'));
    }

    /** An unknown source is refused rather than stored as a fourth kind. */
    public function testAnUnrecognisedSourceIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveDtrDays([
            'PeriodID' => self::PERIOD,
            'days' => [['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01',
                'HoursWorked' => 8, 'Source' => 'Guessed']],
        ], $this->keeper()));

        $this->assertStringContainsString('Source must be one of', $message);
    }

    /**
     * The import does not overwrite a hand-keyed day.
     *
     * A manual entry is a claim somebody made, and Phase 6 checks it against a
     * covering bio exemption. Replacing it with the device's version erases the
     * discrepancy that check exists to find.
     */
    public function testABiometricImportLeavesAHandKeyedDayAloneAndReportsIt(): void
    {
        \apiSaveDtrDays(['PeriodID' => self::PERIOD, 'days' => [
            ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01',
                'HoursWorked' => 8, 'Source' => 'Manual', 'Remarks' => 'keyed by hand'],
        ]], $this->keeper());

        $result = \apiImportBiometricLogs(['PeriodID' => self::PERIOD, 'punches' => [
            ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01', 'HoursWorked' => 2],
            ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-02', 'HoursWorked' => 8],
        ]], $this->keeper());

        $this->assertSame(1, $result['imported']);
        $this->assertSame([['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01']],
            $result['conflicts']);

        $kept = TestDatabase::connect()->query(
            "SELECT HoursWorked, Source, Remarks FROM DtrDays
              WHERE EmployeeID = '" . self::EMPLOYEE . "' AND WorkDate = '2026-07-01'")->fetch();

        $this->assertSame('8.00', $kept['HoursWorked'], 'The import overwrote a manual entry.');
        $this->assertSame('Manual', $kept['Source']);
    }

    /* ------------------------------------------------------------- integrity */

    /** A day outside its period would be counted by nobody. */
    public function testADayOutsideThePeriodIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveDtrDays([
            'PeriodID' => self::PERIOD,
            'days' => [['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-08-20',
                'HoursWorked' => 8]],
        ], $this->keeper()));

        $this->assertStringContainsString('outside the period', $message);
    }

    /** Absent and eight hours worked cannot both be true. */
    public function testADayThatIsBothAbsentAndWorkedIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveDtrDays([
            'PeriodID' => self::PERIOD,
            'days' => [['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01',
                'HoursWorked' => 8, 'IsAbsent' => true]],
        ], $this->keeper()));

        $this->assertStringContainsString('marked absent and also has', $message);
    }

    /** Saving twice for one date updates rather than duplicating. */
    public function testSavingTheSameDayTwiceUpdatesTheOneRow(): void
    {
        foreach ([6, 8] as $hours) {
            \apiSaveDtrDays(['PeriodID' => self::PERIOD, 'days' => [
                ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-01',
                    'HoursWorked' => $hours],
            ]], $this->keeper());
        }

        $rows = TestDatabase::connect()->query(
            "SELECT HoursWorked FROM DtrDays
              WHERE EmployeeID = '" . self::EMPLOYEE . "' AND WorkDate = '2026-07-01'")->fetchAll();

        $this->assertCount(1, $rows, 'The unique key on (EmployeeID, WorkDate) was not used.');
        $this->assertSame('8.00', $rows[0]['HoursWorked']);
    }

    /* ----------------------------------------------------------------- scope */

    /** A DTR row is about a person, so it is scoped to whoever may read them. */
    public function testDaysForAnotherOfficesEmployeeAreNotReturned(): void
    {
        $db = TestDatabase::connect();
        $db->prepare('INSERT INTO DtrDays (DtrDayID, EmployeeID, WorkDate, PeriodID, HoursWorked, Source)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['DTR-ZZOTHER', self::OTHER_EMPLOYEE, '2026-07-01', self::PERIOD, 8, 'Manual']);

        $grid = \apiGetDtrGrid(['PeriodID' => self::PERIOD], $this->keeper());

        $this->assertSame([], array_values(array_filter($grid['days'],
            fn(array $d) => $d['EmployeeID'] === self::OTHER_EMPLOYEE)),
            "Another office's day rows were returned.");
    }

    /** And one cannot be written for them either. */
    public function testKeyingADayForAnOutOfScopeEmployeeIsRefused(): void
    {
        $message = $this->refusalFrom(fn() => \apiSaveDtrDays([
            'PeriodID' => self::PERIOD,
            'days' => [['EmployeeID' => self::OTHER_EMPLOYEE, 'WorkDate' => '2026-07-01',
                'HoursWorked' => 8]],
        ], $this->keeper()));

        $this->assertStringContainsString('Employee not found', $message);
    }

    /* --------------------------------------------------------------- fixture */

    /**
     * Eight whole days, a half day, an absence, a rest day, and some overtime.
     *
     * Mixed on purpose: a fortnight of identical days would pass even if the
     * classification branches were wrong.
     */
    private function keyAFortnight(): void
    {
        $days = [];
        for ($i = 1; $i <= 8; $i++) {
            $days[] = ['EmployeeID' => self::EMPLOYEE,
                'WorkDate' => sprintf('2026-07-%02d', $i),
                'HoursWorked' => 8,
                'OvertimeHours' => $i === 3 ? 3 : 0,
                'LateMinutes' => $i <= 3 ? 15 : 0,
                'Source' => 'Biometric'];
        }
        $days[] = ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-09',
            'HoursWorked' => 4, 'Source' => 'Biometric'];
        $days[] = ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-10',
            'IsAbsent' => true, 'Source' => 'Manual'];
        $days[] = ['EmployeeID' => self::EMPLOYEE, 'WorkDate' => '2026-07-11',
            'HoursWorked' => 0, 'DayType' => 'RestDay', 'Source' => 'Biometric'];

        \apiSaveDtrDays(['PeriodID' => self::PERIOD, 'days' => $days], $this->keeper());
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

    private function keeper(): array
    {
        return [
            'Email' => self::KEEPER,
            'FullName' => 'DTR fixture',
            'Role' => 'Payroll In-Charge',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Payroll In-Charge'],
        ];
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::OFFICE, self::OTHER_OFFICE] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "DTR fixture $office", 'Active']);
        }
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear,
                                                  StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        foreach ([[self::EMPLOYEE, self::OFFICE, 'MINE, Keyed'],
                  [self::OTHER_EMPLOYEE, self::OTHER_OFFICE, 'UNSEEABLE, Keyed']] as $e) {
            [$id, $office, $name] = $e;
            [$last, $first] = explode(', ', $name);

            $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                                 EmploymentType, EmploymentTypeCode, Position, Status)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, $last, $first, $office, 'Job Order', 'JO', 'Worker', 'Active']);
            $db->prepare('INSERT INTO EmployeeSensitive (EmployeeID, DailyRate, HourlyRate)
                          VALUES (?, ?, ?)')->execute([$id, 500.00, 62.50]);
        }

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::KEEPER, 'DTR fixture', 'Payroll In-Charge', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZDTR-1', self::KEEPER, self::OFFICE]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $offices = "'" . self::OFFICE . "','" . self::OTHER_OFFICE . "'";

        // By employee and period, NOT by a payroll-number prefix: apiSavePayroll
        // generates the number itself, so a prefix this fixture chose would
        // match nothing, and the orphaned detail row would then block the
        // employee delete and turn every later test in the file into an error.
        $db->exec("DELETE FROM PayrollDetails WHERE EmployeeID LIKE 'EMP-ZZDTR-%'");
        $db->exec("DELETE FROM Payroll WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM DtrDays WHERE EmployeeID LIKE 'EMP-ZZDTR-%'");
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZDTR-%'");
        $db->exec("DELETE FROM EmployeeSensitive WHERE EmployeeID LIKE 'EMP-ZZDTR-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZDTR-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzdtr-%@digos.gov.ph'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ($offices)");
    }
}
