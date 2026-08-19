<?php
/**
 * ============================================================================
 * ScopeGatewayTest - Phase 2's exit gate, stated as the plan states it:
 *
 *   "Test user with CMO-only scope cannot read OCEEM rows - verified by an
 *    actual query returning empty/denied, not by UI inspection."
 *
 * So these tests call the api* functions and assert on what comes back, not on
 * the SQL that produced it. A predicate that is correct in isolation and never
 * reached would pass ScopePredicateTest and fail here, and that gap is exactly
 * what "not by UI inspection" is warning about.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class ScopeGatewayTest extends TestCase
{
    private const CMO = 'ZZCMO';
    private const OCEEM = 'ZZOCEEM';
    private const PERIOD = 'PRD-ZZSCOPE';
    private const CMO_PAYROLL = 'ZZS-CMO-1';
    private const OCEEM_PAYROLL = 'ZZS-OCEEM-1';
    private const SCOPED_USER = 'zz-cmo-only@digos.gov.ph';
    private const WIDE_USER = 'zz-citywide@digos.gov.ph';

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

    /** Two offices, one payroll each, and two users scoped differently. */
    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::CMO, self::OCEEM] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Scope fixture $office", 'Active']);
        }
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        foreach ([[self::CMO_PAYROLL, self::CMO], [self::OCEEM_PAYROLL, self::OCEEM]] as [$no, $office]) {
            $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet)
                          VALUES (?, ?, ?, ?, ?)')
                ->execute([$no, self::PERIOD, $office, 'DRAFT', 100.00]);
        }

        foreach ([self::SCOPED_USER, self::WIDE_USER] as $email) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Scope fixture', 'Payroll In-Charge', '', 'Active', 'x']);
        }

        // One office. This is the grant the exit gate is about.
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZ-CMO', self::SCOPED_USER, self::CMO]);

        // Every dimension NULL - the wildcard, and the control for the test
        // below: it proves the fixture rows are readable at all, so a CMO-only
        // user seeing nothing means scope worked and not that the seed failed.
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite)
                      VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZ-WIDE', self::WIDE_USER]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZ%'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo LIKE 'ZZS-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZS-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zz-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::CMO . "','" . self::OCEEM . "')");
    }

    /** The requireUser() shape the api* functions expect. */
    private function user(string $email): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Scope fixture',
            'Role' => 'Payroll In-Charge',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Payroll In-Charge'],
        ];
    }

    /* ------------------------------------------------------------ the gate */

    public function testACmoOnlyUserCannotReadAnOceemPayroll(): void
    {
        $rows = \apiListPayrolls([], $this->user(self::SCOPED_USER));
        $numbers = array_column($rows, 'PayrollNo');

        $this->assertContains(self::CMO_PAYROLL, $numbers,
            'The CMO-only user cannot see their own office - the grant is not being applied at all.');
        $this->assertNotContains(self::OCEEM_PAYROLL, $numbers,
            'A CMO-only user read an OCEEM payroll. This is the Phase 2 exit gate.');
    }

    /** The control: the same rows are readable, so above is scope and not an empty fixture. */
    public function testAWildcardUserReadsBothOffices(): void
    {
        $numbers = array_column(\apiListPayrolls([], $this->user(self::WIDE_USER)), 'PayrollNo');

        $this->assertContains(self::CMO_PAYROLL, $numbers);
        $this->assertContains(self::OCEEM_PAYROLL, $numbers);
    }

    /**
     * Knowing the payroll number must not be a way around the list filter.
     * Out of scope reports the same "not found" as a number that never
     * existed, so the caller cannot confirm another office's record by asking.
     */
    public function testFetchingAnOutOfScopePayrollByNumberIsRefused(): void
    {
        $this->expectExceptionMessage('Payroll not found');

        \apiGetPayroll(['PayrollNo' => self::OCEEM_PAYROLL], $this->user(self::SCOPED_USER));
    }

    public function testFetchingAnInScopePayrollByNumberSucceeds(): void
    {
        $payroll = \apiGetPayroll(['PayrollNo' => self::CMO_PAYROLL], $this->user(self::SCOPED_USER));

        $this->assertSame(self::CMO_PAYROLL, $payroll['header']['PayrollNo']);
    }

    /**
     * A user with no grant at all is the state of every account before anyone
     * grants them anything. Deny-by-default has to hold there, or the control
     * is absent exactly when it looks present.
     */
    public function testAUserWithNoGrantsSeesNothing(): void
    {
        TestDatabase::connect()->exec("DELETE FROM ScopeGrants WHERE GrantID = 'SG-ZZ-CMO'");
        ScopeGrantRepo::forget();

        $this->assertSame([], \apiListPayrolls([], $this->user(self::SCOPED_USER)));
    }

    /** Expiry is the point of the validity window - a detail has to end. */
    public function testAnExpiredGrantStopsWorking(): void
    {
        TestDatabase::connect()
            ->prepare("UPDATE ScopeGrants SET ValidTo = ? WHERE GrantID = 'SG-ZZ-CMO'")
            ->execute([date('Y-m-d', strtotime('-1 day'))]);
        ScopeGrantRepo::forget();

        $this->assertSame([], \apiListPayrolls([], $this->user(self::SCOPED_USER)));
    }

    /** Scope applies to writes too, or a user could create what they cannot read. */
    public function testAUserCannotCreateAPayrollChargedOutsideTheirScope(): void
    {
        $this->expectExceptionMessage('Your access does not cover');

        \apiSavePayroll([
            'PeriodID' => self::PERIOD,
            'OfficeCode' => self::OCEEM,
            'lines' => [['EmployeeID' => 'nobody']],
        ], $this->user(self::SCOPED_USER));
    }
}
