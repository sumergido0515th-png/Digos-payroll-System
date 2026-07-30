<?php
/**
 * ============================================================================
 * EmployeeTierTest - The restricted tier stays restricted.
 *
 * GAP_MAP finding 4, open since Phase 0: every role holding `employee.view`
 * could read salary rates, TIN, GSIS, PhilHealth, Pag-IBIG and CashCard,
 * because they were columns on Employees and app/Master.php did SELECT *.
 * Migration 0015 moved them out and 0017 dropped the originals.
 *
 * The assertion that matters is on the returned array KEYS, not on values. A
 * blanked-out value still travelled to the browser; a missing key never left
 * the database.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\EmployeeRepo;
use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class EmployeeTierTest extends TestCase
{
    private const OFFICE = 'ZZTIER';
    private const EMPLOYEE = 'EMP-ZZTIER-001';
    private const ENCODER = 'zz-encoder@digos.gov.ph';
    private const HRMO = 'zz-hrmo@digos.gov.ph';

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

        $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
            ->execute([self::OFFICE, 'Tier fixture', 'Active']);
        $db->prepare('INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                             EmploymentType, EmploymentTypeCode, Position, Status)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::EMPLOYEE, 'Dela Cruz', 'Juan', self::OFFICE,
                'Job Order', 'JO', 'Utility Worker', 'Active']);
        $db->prepare('INSERT INTO EmployeeSensitive (EmployeeID, TIN, PagIBIG, CashCard, DailyRate, HourlyRate)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::EMPLOYEE, '123-456-789', 'PGB-0001', '5555000011112222', 520.00, 65.00]);

        foreach ([[self::ENCODER, 'Encoder'], [self::HRMO, 'HRMO']] as [$email, $role]) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Tier fixture', $role, '', 'Active', 'x']);
            $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite) VALUES (?, ?, 1, 1)')
                ->execute(['SG-ZZTIER-' . substr(md5($email), 0, 8), $email]);
        }
    }

    /**
     * Cleans by office rather than by id prefix.
     *
     * The save test creates an employee whose id the system generates, and an
     * assertion failing before its inline cleanup would leave that row holding
     * a foreign key onto the fixture office - which then fails to delete, and
     * the next test errors on setup instead of reporting its own result. Two
     * tests lied about which one was broken before this was changed.
     */
    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZTIER-%'");
        $db->exec("DELETE FROM EmployeeSensitive WHERE EmployeeID IN
                   (SELECT EmployeeID FROM Employees WHERE OfficeCode = '" . self::OFFICE . "')");
        $db->exec("DELETE FROM Employees WHERE OfficeCode = '" . self::OFFICE . "'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zz-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode = '" . self::OFFICE . "'");
    }

    private function user(string $email, string $role): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Tier fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /* --------------------------------------------------------------- reads */

    public function testAnEncoderNeverReceivesTheRestrictedColumns(): void
    {
        $employee = \apiGetEmployee(['EmployeeID' => self::EMPLOYEE],
            $this->user(self::ENCODER, 'Encoder'));

        foreach (EmployeeRepo::SENSITIVE_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $employee,
                "An Encoder received the restricted column $column.");
        }
        $this->assertSame('Utility Worker', $employee['Position'],
            'The directory tier should still be readable.');
    }

    public function testAnHrmoDoesReceiveThem(): void
    {
        $employee = \apiGetEmployee(['EmployeeID' => self::EMPLOYEE],
            $this->user(self::HRMO, 'HRMO'));

        $this->assertSame('123-456-789', $employee['TIN']);
        $this->assertSame('520.00', (string) $employee['DailyRate']);
    }

    public function testTheListEndpointHidesThemToo(): void
    {
        $result = \apiListEmployees(['search' => 'Dela Cruz'],
            $this->user(self::ENCODER, 'Encoder'));

        $this->assertNotEmpty($result['rows']);
        foreach (EmployeeRepo::SENSITIVE_COLUMNS as $column) {
            $this->assertArrayNotHasKey($column, $result['rows'][0],
                "The list endpoint leaked $column to an Encoder.");
        }
    }

    /**
     * Searching a restricted column is itself a disclosure: "does anyone have
     * TIN X?" is answerable from a hit count, without the column ever being
     * displayed. So the field drops out of the search for callers who may not
     * read it.
     */
    public function testAnEncoderCannotSearchByARestrictedColumn(): void
    {
        $asEncoder = \apiListEmployees(['search' => '123-456-789'],
            $this->user(self::ENCODER, 'Encoder'));
        $asHrmo = \apiListEmployees(['search' => '123-456-789'],
            $this->user(self::HRMO, 'HRMO'));

        $this->assertSame([], $asEncoder['rows'],
            'An Encoder confirmed an employee by their TIN without ever seeing it.');
        $this->assertNotEmpty($asHrmo['rows'],
            'HRMO may search the restricted tier and got nothing - the join is wrong.');
    }

    /* -------------------------------------------------------------- writes */

    /** One posted form, two tables, one transaction. */
    public function testSavingAnEmployeeWritesBothTiers(): void
    {
        // SalaryRate is what the form posts; deriveRates() computes the daily,
        // hourly and monthly figures from it and the rate basis.
        $saved = \apiSaveEmployee([
            'LastName' => 'Santos', 'FirstName' => 'Maria',
            'EmploymentType' => 'Job Order', 'Position' => 'Clerk',
            'OfficeCode' => self::OFFICE,
            'TIN' => '999-888-777', 'SalaryRate' => '600',
        ], $this->user(self::HRMO, 'HRMO'));

        $sensitive = EmployeeRepo::sensitive($saved['EmployeeID']);

        $this->assertNotNull($sensitive, 'No EmployeeSensitive row was written.');
        $this->assertSame('999-888-777', $sensitive['TIN']);
        $this->assertSame('600.00', (string) $sensitive['DailyRate']);

        // The directory half must not have picked the restricted columns back
        // up - splitTiers() removes them, and a typo there would put them in
        // both tables and quietly undo the split.
        $tier1 = \apiGetEmployee(['EmployeeID' => $saved['EmployeeID']],
            $this->user(self::ENCODER, 'Encoder'));
        $this->assertArrayNotHasKey('TIN', $tier1);
    }

    /**
     * The payroll engine is a legitimate reader of the restricted tier -
     * computeLine() computes from DailyRate - so it gets a named path rather
     * than an exception to the rule.
     */
    public function testThePayrollEnginePathStillSeesTheRates(): void
    {
        $employee = EmployeeRepo::findForComputation(self::EMPLOYEE);

        $this->assertSame('520.00', (string) $employee['DailyRate']);
        $this->assertSame('Utility Worker', $employee['Position']);
    }
}
