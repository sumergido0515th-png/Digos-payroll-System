<?php
/**
 * ============================================================================
 * ImportTest - The bulk import, end to end, against a real database.
 *
 * WHY THIS EXISTS
 * The unit suite proves the parser reads a file and the mapper matches a
 * heading. Neither can prove the part that actually matters: that an imported
 * record is indistinguishable from one typed into the form. The importer's
 * whole safety argument is that it writes nothing itself and delegates to the
 * ordinary api*Save* functions, so this suite checks the consequences of that -
 * derived rates, the classification code Phase 4's resolvers branch on, an
 * audit entry, and a rollback that leaves nothing behind.
 *
 * It imports seeds/demo-seed-import/*.csv, which is the shipped demo data. If
 * those files stop importing cleanly this fails, which is the point: they are
 * documented as the thing to try first on a new deployment.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;

final class ImportTest extends TestCase
{
    /** An administrator: '*' covers data.import and every entity permission. */
    private const USER = ['Email' => 'import-test@example.invalid', 'Role' => 'Admin',
        'permissions' => ['*']];

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped(
                'No test database reachable. Set DB_HOST/DB_NAME/DB_USER/DB_PASS and run '
                . 'php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM EmployeeSensitive WHERE EmployeeID IN "
            . "(SELECT EmployeeID FROM Employees WHERE EmployeeNo LIKE 'DEMO-%')");
        $db->exec("DELETE FROM Employees WHERE EmployeeNo LIKE 'DEMO-%'");
        $db->exec("DELETE FROM Timekeepers WHERE EmployeeName LIKE 'DEMO Timekeeper%'");
        $db->exec("DELETE FROM Departments WHERE DeptCode IN ('GS','FIN','HS')");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID LIKE 'PRD-%' AND PayrollMonth = 'July'"
            . " AND PayrollYear = 2026 AND PeriodID NOT IN (SELECT PeriodID FROM Payroll)");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('CMO','CTO','CHO')"
            . " AND OfficeName LIKE 'City %'");
        $db->exec("DELETE FROM Logs WHERE User = '" . self::USER['Email'] . "'");
    }

    /** One of the shipped demo files, as the inline payload the SPA sends. */
    private function seed(string $file): string
    {
        $path = PROJECT_ROOT . '/seeds/demo-seed-import/' . $file;
        $this->assertFileExists($path, 'The shipped demo import files are part of the deliverable.');

        return 'data:text/csv;base64,' . base64_encode((string) file_get_contents($path));
    }

    private function import(string $entity, string $file, array $extra = []): array
    {
        return \apiCommitImport(array_merge(
            ['entity' => $entity, 'data' => $this->seed($file), 'fileName' => $file], $extra),
            self::USER);
    }

    /** Offices first - an employee row names an office code. */
    private function importOffices(): void
    {
        $this->import('offices', '1-offices.csv');
    }

    /* ==================================================================
     * The demo files import as shipped
     * ================================================================ */

    public function testOfficesImportFromTheShippedFile(): void
    {
        $result = $this->import('offices', '1-offices.csv');

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);

        $row = TestDatabase::connect()
            ->query("SELECT OfficeName, Status FROM Offices WHERE OfficeCode = 'CMO'")->fetch();
        $this->assertSame("City Mayor's Office", $row['OfficeName']);
        $this->assertSame('Active', $row['Status']);
    }

    public function testEmployeesImportFromTheShippedFile(): void
    {
        $this->importOffices();
        $result = $this->import('employees', '4-employees.csv');

        $this->assertSame(12, $result['created']);
        $this->assertSame(12, $result['total']);
    }

    public function testTimekeepersAndPeriodsImportFromTheShippedFiles(): void
    {
        $this->importOffices();

        $this->assertSame(3, $this->import('timekeepers', '3-timekeepers.csv')['created']);
        $this->assertSame(3, $this->import('departments', '2-departments.csv')['created']);
        $this->assertSame(1, $this->import('periods', '5-payroll-periods.csv')['created']);
    }

    /* ==================================================================
     * An imported record is a properly saved record
     * ================================================================ */

    /**
     * The headings in the shipped file are "Surname", "Given Name", "Sex",
     * "Nature of Appointment" and "Daily Rate" - none of them field names. If
     * the mapper regressed, this is where it shows.
     */
    public function testHumanHeadingsLandInTheRightColumns(): void
    {
        $this->importOffices();
        $this->import('employees', '4-employees.csv');

        $db = TestDatabase::connect();
        $row = $db->query(
            "SELECT e.*, s.Gender, s.Birthdate FROM Employees e
             JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
             WHERE e.EmployeeNo = 'DEMO-0001'")->fetch();

        $this->assertSame('DELA CRUZ', $row['LastName']);
        $this->assertSame('Juan', $row['FirstName']);
        $this->assertSame('Santos', $row['MiddleName']);
        $this->assertSame('Male', $row['Gender']);
        $this->assertSame('Job Order', $row['EmploymentType']);
        $this->assertSame('Administrative Aide', $row['Position']);
        $this->assertSame('CMO', $row['OfficeCode']);
        $this->assertSame('1992-03-14', $row['Birthdate']);
        $this->assertSame('2026-12-31', $row['ContractEnd']);
    }

    /**
     * A spreadsheet carries a daily rate and no "rate basis" column - that
     * concept exists only in this system's form. The spec defaults it so that
     * deriveRates() produces the same hourly and monthly figures the form
     * would have, rather than leaving an employee whose rate is set and whose
     * derived rates are zero.
     */
    public function testDailyRateColumnDrivesTheDerivedRates(): void
    {
        $this->importOffices();
        $this->import('employees', '4-employees.csv');

        $row = TestDatabase::connect()->query(
            "SELECT s.DailyRate, s.HourlyRate, s.MonthlyRate FROM Employees e
             JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
             WHERE e.EmployeeNo = 'DEMO-0001'")->fetch();

        $hoursPerDay = (float) (\getSetting('WorkingHoursPerDay', '8') ?: 8);
        $daysPerMonth = (float) (\getSetting('WorkingDaysPerMonth', '22') ?: 22);

        $this->assertSame('520.00', $row['DailyRate']);
        $this->assertEquals(round(520 / $hoursPerDay, 2), (float) $row['HourlyRate']);
        $this->assertEquals(round(520 * $daysPerMonth, 2), (float) $row['MonthlyRate']);
    }

    /**
     * EmploymentTypeCode is what Phase 4's resolvers branch on for the JO/COS
     * holiday divergence. An import that set the display string and left the
     * key NULL would resolve every imported employee's holiday pay wrongly.
     */
    public function testEmploymentTypeCodeIsPopulatedForTheResolvers(): void
    {
        $this->importOffices();
        $this->import('employees', '4-employees.csv');

        $db = TestDatabase::connect();
        $this->assertSame('JO', $db
            ->query("SELECT EmploymentTypeCode FROM Employees WHERE EmployeeNo = 'DEMO-0001'")
            ->fetchColumn());
        $this->assertSame('COS', $db
            ->query("SELECT EmploymentTypeCode FROM Employees WHERE EmployeeNo = 'DEMO-0004'")
            ->fetchColumn());
    }

    /** The restricted tier is written too - migration 0015's split, not bypassed. */
    public function testRestrictedTierIsWritten(): void
    {
        $this->importOffices();
        $this->import('employees', '4-employees.csv');

        $row = TestDatabase::connect()->query(
            "SELECT s.TIN, s.CashCard FROM Employees e
             JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
             WHERE e.EmployeeNo = 'DEMO-0001'")->fetch();

        $this->assertSame('900-000-000-001', $row['TIN']);
        $this->assertSame('9000000000000001', $row['CashCard']);
    }

    public function testReimportingUpdatesRatherThanDuplicating(): void
    {
        $this->importOffices();
        $second = $this->import('offices', '1-offices.csv');

        $this->assertSame(0, $second['created']);
        $this->assertSame(3, $second['updated']);

        $count = TestDatabase::connect()->query(
            "SELECT COUNT(*) FROM Offices WHERE OfficeCode IN ('CMO','CTO','CHO')")->fetchColumn();
        $this->assertSame(3, (int) $count);
    }

    /* ==================================================================
     * Refusals
     * ================================================================ */

    public function testInvalidRowsBlockTheWholeImportByDefault(): void
    {
        $this->importOffices();

        $csv = "Office Code,Surname,Given Name,Nature of Appointment,Position Title,Daily Rate\n"
            . "CMO,VALID,Juan,Job Order,Clerk,500\n"
            . "CMO,BROKEN,Maria,Casual,Clerk,500\n";

        $message = $this->refusalFrom(fn() => \apiCommitImport(
            ['entity' => 'employees', 'data' => 'data:text/csv;base64,' . base64_encode($csv)],
            self::USER));

        $this->assertNotNull($message, 'A file with a bad row should not import silently.');
        $this->assertStringContainsString('row 3', $message);

        $this->assertSame(0, (int) TestDatabase::connect()
            ->query("SELECT COUNT(*) FROM Employees WHERE LastName = 'VALID'")->fetchColumn(),
            'The valid row was written even though the import was refused.');
    }

    public function testSkipInvalidImportsOnlyTheGoodRows(): void
    {
        $this->importOffices();

        $csv = "Office Code,Employee No,Surname,Given Name,Nature of Appointment,Position Title,Daily Rate\n"
            . "CMO,DEMO-9001,VALID,Juan,Job Order,Clerk,500\n"
            . "CMO,DEMO-9002,BROKEN,Maria,Casual,Clerk,500\n";

        $result = \apiCommitImport(
            ['entity' => 'employees', 'data' => 'data:text/csv;base64,' . base64_encode($csv),
             'skipInvalid' => true],
            self::USER);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
    }

    /**
     * A save that throws mid-file rolls the whole import back. Without the
     * transaction the operator is left with some rows written, no clear count,
     * and a file they will naturally correct and import again.
     *
     * Row 2's email is malformed. EntitySpec has no email coercion - free text
     * passes through unchanged - so the preview reports both rows clean, and
     * only apiSaveEmployee's own isEmail() check catches it. That gap is
     * exactly what makes this a save-time failure the preview cannot predict,
     * which is the case the transaction exists for.
     */
    public function testAFailureMidwayLeavesNothingBehind(): void
    {
        $this->importOffices();

        $csv = "Office Code,Employee No,Surname,Given Name,Nature of Appointment,Position Title,"
            . "Daily Rate,Email Address\n"
            . "CMO,DEMO-7001,FIRST,Juan,Job Order,Clerk,500,\n"
            . "CMO,DEMO-7002,SECOND,Maria,Job Order,Clerk,500,not-an-email\n";

        $preview = \apiPreviewImport(
            ['entity' => 'employees', 'data' => 'data:text/csv;base64,' . base64_encode($csv)],
            self::USER);
        $this->assertSame(0, $preview['invalidCount'],
            'This case only proves anything if the preview reports both rows clean.');

        $message = $this->refusalFrom(fn() => \apiCommitImport(
            ['entity' => 'employees', 'data' => 'data:text/csv;base64,' . base64_encode($csv)],
            self::USER));

        $this->assertNotNull($message);
        $this->assertStringContainsString('Nothing was imported', $message);

        $this->assertSame(0, (int) TestDatabase::connect()
            ->query("SELECT COUNT(*) FROM Employees WHERE LastName IN ('FIRST','SECOND')")
            ->fetchColumn(),
            'The first row survived a rolled-back import.');
    }

    public function testPdfIsRefusedRatherThanParsed(): void
    {
        $message = $this->refusalFrom(fn() => \apiPreviewImport(
            ['entity' => 'employees', 'data' => 'data:application/pdf;base64,'
                . base64_encode("%PDF-1.7\ntrailer\n"), 'fileName' => 'list.pdf'],
            self::USER));

        $this->assertNotNull($message);
        $this->assertStringContainsString('PDF', $message);
        $this->assertStringContainsString('.csv', $message);
    }

    /* ==================================================================
     * Preview writes nothing; the audit records what happened
     * ================================================================ */

    public function testPreviewWritesNothing(): void
    {
        $this->importOffices();

        $preview = \apiPreviewImport(
            ['entity' => 'employees', 'data' => $this->seed('4-employees.csv'),
             'fileName' => '4-employees.csv'],
            self::USER);

        $this->assertSame(12, $preview['validCount']);
        $this->assertSame(0, $preview['invalidCount']);
        $this->assertSame([], $preview['missingRequired']);

        $this->assertSame(0, (int) TestDatabase::connect()
            ->query("SELECT COUNT(*) FROM Employees WHERE EmployeeNo LIKE 'DEMO-%'")->fetchColumn(),
            'Preview created records.');
    }

    /**
     * The mapping the preview proposes for the shipped file must be confident
     * on every required field, or the demo data is not the one-click check its
     * README says it is.
     */
    public function testEveryRequiredFieldMapsConfidentlyForTheShippedFile(): void
    {
        $preview = \apiPreviewImport(
            ['entity' => 'employees', 'data' => $this->seed('4-employees.csv'),
             'fileName' => '4-employees.csv'],
            self::USER);

        foreach (['LastName', 'FirstName', 'OfficeCode', 'EmploymentType', 'Position'] as $field) {
            $this->assertTrue($preview['mapping'][$field]['confident'],
                "$field did not map confidently from the shipped demo file.");
        }
        $this->assertTrue($preview['mapping']['SalaryRate']['confident'],
            '"Daily Rate" should map confidently onto SalaryRate.');
    }

    public function testTheOutcomeIsAudited(): void
    {
        $this->importOffices();

        $details = TestDatabase::connect()->query(
            "SELECT Details FROM Logs WHERE User = '" . self::USER['Email']
            . "' AND Action = 'IMPORT_DATA' ORDER BY LogID DESC LIMIT 1")->fetchColumn();

        $this->assertIsString($details, 'A committed import wrote no audit entry.');
        $this->assertStringContainsString('3 created', $details);
        $this->assertStringContainsString('CMO', $details);
    }

    /* ==================================================================
     * Permissions
     * ================================================================ */

    /**
     * data.import gets you to the screen; the entity's own permission decides
     * what may go through it. An Encoder holds neither, but a role holding
     * data.import without employee.edit must still be refused.
     */
    public function testEntityPermissionIsCheckedNotJustDataImport(): void
    {
        $user = ['Email' => 'limited@example.invalid', 'Role' => 'Encoder',
            'permissions' => ['data.import', 'office.view']];

        $message = $this->refusalFrom(fn() => \apiCommitImport(
            ['entity' => 'employees', 'data' => $this->seed('4-employees.csv')], $user));

        $this->assertNotNull($message);
        $this->assertStringContainsString('Access denied', $message);
    }

    public function testTypeListIsFilteredToWhatTheCallerMayWrite(): void
    {
        $user = ['Email' => 'limited@example.invalid', 'Role' => 'Encoder',
            'permissions' => ['data.import', 'office.edit']];

        $entities = array_column(\apiGetImportTypes([], $user)['types'], 'entity');

        $this->assertContains('offices', $entities);
        $this->assertNotContains('employees', $entities,
            'An entity the caller cannot write should not be offered.');
    }

    /** Returns the refusal message, or null if the call unexpectedly succeeded. */
    private function refusalFrom(callable $call): ?string
    {
        try {
            $call();
            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
