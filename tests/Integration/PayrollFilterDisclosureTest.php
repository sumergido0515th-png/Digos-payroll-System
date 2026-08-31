<?php
/**
 * ============================================================================
 * PayrollFilterDisclosureTest - Phase 9A's exit gate.
 *
 * docs/PHASE_PLAN.md, Phase 9's implementation addendum, names three
 * disclosure vectors a faceted-filter layer must not open:
 *
 *   1. A scoped user filtering explicitly for an out-of-scope value must get
 *      empty results, not a refusal - a refusal confirms the office exists.
 *   2. Facet OPTIONS (e.g. an office dropdown) must be scoped too, not just
 *      the filtered rows.
 *   3. Exports must run the identical scoped query as the view they export.
 *
 * This test covers (1) against the new FilterSpec/FilterSql layer specifically
 * - both through a plain facet and through the free-text search clause, since
 * each is a different SQL fragment and either could independently escape the
 * scope predicate's parentheses.
 *
 * (2) is DELIBERATELY NOT covered here. Verified directly: apiGetLookups() in
 * app/Master.php returns Offices via a bare DB::rows(), no ScopeGateway::where()
 * at all - a real, pre-existing gap (a CMO-scoped user's Office dropdown lists
 * every office). But it is a shared endpoint feeding every module's dropdowns,
 * not something this phase's new files touch or introduce a new instance of,
 * and the addendum itself assigns "scoping the facet options" to 9B by name.
 * Its absence here is that decision, not an oversight.
 *
 * (3) does not apply yet - no export path exists until 9D.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class PayrollFilterDisclosureTest extends TestCase
{
    private const CMO = 'ZZQCMO';
    private const OCEEM = 'ZZQOCEEM';
    private const PERIOD = 'PRD-ZZQUERY';
    private const CMO_DEPT = 'ZzqCmoDept';
    private const OCEEM_DEPT = 'ZzqOceemDept';
    private const CMO_OPEN = 'ZZQ-CMO-1';
    private const OCEEM_OPEN = 'ZZQ-OCEEM-1';
    private const CMO_SUSPENDED = 'ZZQ-CMO-2';
    private const OCEEM_SUSPENDED = 'ZZQ-OCEEM-2';
    private const SCOPED_USER = 'zzq-cmo-only@digos.gov.ph';
    private const WIDE_USER = 'zzq-citywide@digos.gov.ph';

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

    /**
     * Two offices, each with one open and one suspended payroll sharing a
     * Status with its counterpart office, plus a Department string unique to
     * each - so a facet filter, a shared-value facet filter, and a search
     * term each have something to prove.
     */
    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::CMO, self::OCEEM] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Filter fixture $office", 'Active']);
        }
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'July', 2026, '2026-07-01', '2026-07-15', 'Open']);

        $rows = [
            [self::CMO_OPEN, self::CMO, 'DRAFT', self::CMO_DEPT],
            [self::OCEEM_OPEN, self::OCEEM, 'DRAFT', self::OCEEM_DEPT],
            [self::CMO_SUSPENDED, self::CMO, 'SUSPENDED', self::CMO_DEPT],
            [self::OCEEM_SUSPENDED, self::OCEEM, 'SUSPENDED', self::OCEEM_DEPT],
        ];
        foreach ($rows as [$no, $office, $status, $dept]) {
            $db->prepare('INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Department, Status, TotalNet)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$no, self::PERIOD, $office, $dept, $status, 100.00]);
        }

        foreach ([self::SCOPED_USER, self::WIDE_USER] as $email) {
            $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$email, 'Filter fixture', 'Payroll In-Charge', '', 'Active', 'x']);
        }

        // One office. This is the grant every disclosure assertion below rests on.
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZQ-CMO', self::SCOPED_USER, self::CMO]);

        // Every dimension NULL - the control, proving the fixture rows are
        // readable at all so a scoped user seeing nothing means scope worked
        // and not that the seed failed.
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite)
                      VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZQ-WIDE', self::WIDE_USER]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZQ-%'");
        $db->exec("DELETE FROM PayrollDetails WHERE PayrollNo LIKE 'ZZQ-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZQ-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzq-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::CMO . "','" . self::OCEEM . "')");
    }

    /** The requireUser() shape the api* functions expect. */
    private function user(string $email): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Filter fixture',
            'Role' => 'Payroll In-Charge',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Payroll In-Charge'],
        ];
    }

    /* ------------------------------------------------------------ the gate */

    /**
     * Vector 1, applied to a plain facet. Asking BY NAME for the office the
     * user cannot see must come back empty - not an exception, which would
     * itself confirm ZZQOCEEM exists to a caller who cannot otherwise see it.
     */
    public function testFilteringExplicitlyForTheOutOfScopeOfficeReturnsEmptyNotARefusal(): void
    {
        $rows = \apiListPayrolls(['OfficeCode' => self::OCEEM], $this->user(self::SCOPED_USER));

        $this->assertSame([], $rows);
    }

    /** Control: the same facet still works for the office the user does hold. */
    public function testFilteringForTheInScopeOfficeStillReturnsRows(): void
    {
        $numbers = array_column(
            \apiListPayrolls(['OfficeCode' => self::CMO], $this->user(self::SCOPED_USER)), 'PayrollNo');

        $this->assertContains(self::CMO_OPEN, $numbers);
        $this->assertContains(self::CMO_SUSPENDED, $numbers);
        $this->assertNotContains(self::OCEEM_OPEN, $numbers);
    }

    /**
     * Vector 1, applied to the free-text search clause instead of a facet.
     * Guards against the search OR-group escaping the scope predicate's own
     * parentheses and being OR'd at the top level instead of AND'd - a
     * plausible implementation bug this specific fixture is shaped to catch,
     * since the search term matches nothing except the out-of-scope row.
     */
    public function testSearchTermMatchingOnlyTheOutOfScopeRowReturnsEmpty(): void
    {
        $scoped = \apiListPayrolls(['search' => self::OCEEM_DEPT], $this->user(self::SCOPED_USER));
        $this->assertSame([], $scoped);

        $wide = \apiListPayrolls(['search' => self::OCEEM_DEPT], $this->user(self::WIDE_USER));
        $this->assertNotEmpty($wide, 'The wide user found nothing either - the fixture itself is broken.');
    }

    /**
     * Same class of bug, from the facet side: both offices share the Status
     * "DRAFT" here, so a naive "filters OR scope" precedence bug would let
     * the shared value alone select the out-of-scope row.
     */
    public function testFacetFilterAndScopeCombineWithAndNotOr(): void
    {
        $numbers = array_column(
            \apiListPayrolls(['Status' => 'DRAFT'], $this->user(self::SCOPED_USER)), 'PayrollNo');

        $this->assertContains(self::CMO_OPEN, $numbers);
        $this->assertNotContains(self::OCEEM_OPEN, $numbers);
    }

    /**
     * The rewritten listScoped() has three callers, not one (app/Payroll.php,
     * app/Reports.php, app/PreAudit.php) - cheap insurance the in-place
     * rewrite didn't only happen to work for apiListPayrolls. Exercises
     * apiGetWorklist()'s actual ['Status' => 'SUSPENDED'] call path.
     */
    public function testPreAuditWorklistStillExcludesOutOfScopeSuspendedPayrolls(): void
    {
        $worklist = \apiGetWorklist([], $this->user(self::SCOPED_USER));
        $numbers = array_column($worklist['rows'], 'PayrollNo');

        $this->assertContains(self::CMO_SUSPENDED, $numbers);
        $this->assertNotContains(self::OCEEM_SUSPENDED, $numbers);
    }

    /**
     * The control for both disclosure tests above: the wide user proves the
     * out-of-scope rows genuinely exist and are genuinely findable by
     * someone with the grant to see them, so the emptiness asserted above is
     * scope working, not a fixture that seeded nothing.
     */
    public function testAWildcardUserFindsBothOfficesByFacetAndSearch(): void
    {
        $byStatus = array_column(
            \apiListPayrolls(['Status' => 'DRAFT'], $this->user(self::WIDE_USER)), 'PayrollNo');
        $this->assertContains(self::CMO_OPEN, $byStatus);
        $this->assertContains(self::OCEEM_OPEN, $byStatus);

        $bySearch = array_column(
            \apiListPayrolls(['search' => self::OCEEM_DEPT], $this->user(self::WIDE_USER)), 'PayrollNo');
        $this->assertContains(self::OCEEM_OPEN, $bySearch);
    }
}
