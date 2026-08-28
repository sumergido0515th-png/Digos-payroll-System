<?php
/**
 * ============================================================================
 * FilterScopeTest - Phase 9's exit gate: "office-scoped user never sees
 * another office's totals in any view or export."
 *
 * WHY THIS IS THE FIRST FILE OF THE PHASE
 * Phase 9 looks like UI work and is not. Faceted search is the first feature
 * that lets a user compose their OWN query against a scoped table, and every
 * disclosure this layer prevents is one AND away from coming back. The three
 * ways it comes back, in the order they are easy to miss:
 *
 *   1. The rows.    A filter naming another office must return EMPTY, not a
 *                   refusal. "You may not filter by OCEEM" confirms OCEEM
 *                   exists and has payrolls in it; an empty result says only
 *                   that the caller has none matching.
 *
 *   2. The OPTIONS. A dropdown listing every office name discloses the org
 *                   chart before a single row is fetched. Facet options are
 *                   derived from the rows the caller may already read, so the
 *                   list cannot name a value they cannot see.
 *
 *   3. The exports. This is where the 2026-07-30 leak lived: printBundle()
 *                   took no user and queried Payroll directly, so
 *                   apiGetPayroll refused another office's payroll while
 *                   apiGetPrintHtml rendered it in full. An export built
 *                   BESIDE the list path rather than ON TOP of it repeats
 *                   that defect exactly.
 *
 * 9A covers 1 and 2 for Payroll, the entity taken end to end. 3 has nothing to
 * assert against yet - the filtered-export path is 9D - and its assertions are
 * added to this file when that path exists, rather than being written now
 * against a function whose shape is still open.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\PayrollRepo;
use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FilterScopeTest extends TestCase
{
    private const MINE = 'ZZFMINE';
    private const THEIRS = 'ZZFTHEIRS';

    private const MY_PERIOD = 'PRD-ZZFILT-A';
    private const THEIR_PERIOD = 'PRD-ZZFILT-B';

    private const MY_PAYROLL = 'ZZF-MINE-1';
    private const THEIR_PAYROLL = 'ZZF-THEIRS-1';

    /** Distinctive so a leak through any column is recognisable in a failure. */
    private const THEIR_DEPARTMENT = 'Unseeable Division';
    private const THEIR_PREPARER = 'UNSEEABLE, Preparer';
    private const THEIR_REMARK = 'UNSEEABLE remark';

    private const ENCODER = 'zzf-encoder@digos.gov.ph';
    private const ADMIN = 'zzf-admin@digos.gov.ph';

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
                ->execute([$office, "Filter fixture $office", 'Active']);
        }

        foreach ([[self::MY_PERIOD, 'July', '2026-07-01', '2026-07-15'],
                  [self::THEIR_PERIOD, 'August', '2026-08-01', '2026-08-15']] as $p) {
            [$id, $month, $start, $end] = $p;
            $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear,
                                                      StartDate, EndDate, Status)
                          VALUES (?, ?, 2026, ?, ?, ?)')
                ->execute([$id, $month, $start, $end, 'Open']);
        }

        $insert = $db->prepare(
            'INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Department, Status,
                                  PreparedBy, Remarks, DateCreated, TotalGross, TotalNet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $insert->execute([self::MY_PAYROLL, self::MY_PERIOD, self::MINE, 'Visible Division',
            'DRAFT', 'MINE, Preparer', 'mine', '2026-07-16 09:00:00', 1000.00, 1000.00]);

        $insert->execute([self::THEIR_PAYROLL, self::THEIR_PERIOD, self::THEIRS,
            self::THEIR_DEPARTMENT, 'FOR_PRE_AUDIT', self::THEIR_PREPARER, self::THEIR_REMARK,
            '2026-08-16 09:00:00', 987654.00, 987654.00]);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ENCODER, 'Filter fixture', 'Encoder', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZFILT-1', self::ENCODER, self::MINE]);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ADMIN, 'Filter fixture', 'Admin', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite)
                      VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZFILT-2', self::ADMIN]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZFILT-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZF-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID LIKE 'PRD-ZZFILT-%'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzf-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    private function user(string $email, string $role): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Filter fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /** Every distinct value of one column across the returned rows. */
    private function column(array $rows, string $key): array
    {
        return array_values(array_unique(array_map(fn(array $r) => (string) $r[$key], $rows)));
    }

    /**
     * Nothing in this result names the other office, by any of its columns.
     *
     * Asserted against the whole serialised result rather than one field: the
     * leak that matters is whichever column nobody thought to check, and a
     * payload search catches a column added later for free.
     */
    private function assertDisclosesNothing(array $result, string $what): void
    {
        $json = (string) json_encode($result);

        foreach ([self::THEIRS, self::THEIR_PAYROLL, self::THEIR_DEPARTMENT,
                  self::THEIR_PREPARER, self::THEIR_REMARK, self::THEIR_PERIOD,
                  '987654'] as $secret) {
            $this->assertStringNotContainsString($secret, $json,
                "$what disclosed '$secret' from another office.");
        }
    }

    /* ===================================================================
     * 1. The rows
     * =================================================================== */

    /** The day job: an unfiltered list is the caller's own office. */
    public function testAnUnfilteredListIsScopedToTheCallersOffice(): void
    {
        $rows = \apiListPayrolls([], $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([self::MY_PAYROLL], $this->column($rows, 'PayrollNo'));
        $this->assertDisclosesNothing($rows, 'The unfiltered list');
    }

    /**
     * THE CASE THAT GETS MISSED. Naming the other office explicitly must come
     * back empty - not refused, and not populated.
     *
     * A refusal would be a disclosure in itself: it distinguishes "that office
     * exists and is not yours" from "no such office", which is the same
     * distinction apiGetPayroll deliberately refuses to make.
     */
    public function testFilteringByAnotherOfficeReturnsEmptyRatherThanRefusing(): void
    {
        $rows = \apiListPayrolls(
            ['OfficeCode' => self::THEIRS], $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([], $rows, 'A filter named another office and rows came back.');
    }

    /** Same for a period only the other office has payrolls in. */
    public function testFilteringByAnotherOfficesPeriodReturnsEmpty(): void
    {
        $rows = \apiListPayrolls(
            ['PeriodID' => self::THEIR_PERIOD], $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([], $rows);
    }

    /**
     * Free text is the widest door: it is the one filter that reads several
     * columns at once, and the one a user can aim at a name they overheard.
     */
    public function testFreeTextSearchCannotReachAnotherOffice(): void
    {
        $encoder = $this->user(self::ENCODER, 'Encoder');

        foreach (['UNSEEABLE', self::THEIR_PAYROLL, self::THEIR_DEPARTMENT,
                  self::THEIRS, 'remark'] as $term) {
            $rows = \apiListPayrolls(['search' => $term], $encoder);
            $this->assertDisclosesNothing($rows, "Searching for '$term'");
        }
    }

    /** A status only the other office's payroll holds still returns nothing. */
    public function testFilteringByAStatusOnlyAnotherOfficeHoldsReturnsEmpty(): void
    {
        $rows = \apiListPayrolls(
            ['Status' => 'FOR_PRE_AUDIT'], $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([], $rows);
    }

    /**
     * Filters narrow within scope and never widen past it - proven by the one
     * filter that legitimately matches: the caller's own row.
     */
    public function testAFilterMatchingTheCallersOwnRowStillReturnsIt(): void
    {
        $rows = \apiListPayrolls(
            ['OfficeCode' => self::MINE, 'Status' => 'DRAFT'],
            $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([self::MY_PAYROLL], $this->column($rows, 'PayrollNo'));
    }

    /**
     * The test is not vacuous: a wildcard grant does see both, so the empty
     * results above are the scope working and not the fixture missing.
     */
    public function testAnAdministratorSeesBothOffices(): void
    {
        $found = $this->column(
            \apiListPayrolls([], $this->user(self::ADMIN, 'Admin')), 'PayrollNo');

        $this->assertContains(self::MY_PAYROLL, $found);
        $this->assertContains(self::THEIR_PAYROLL, $found);
    }

    /* ===================================================================
     * 2. The facet options
     * =================================================================== */

    /**
     * A dropdown is a query result too. This one is built from the rows the
     * caller may already read, which is what makes it structurally incapable
     * of naming an office they cannot see.
     */
    public function testFacetOptionsNameOnlyValuesWithinScope(): void
    {
        $facets = \apiGetPayrollFacets([], $this->user(self::ENCODER, 'Encoder'));

        $this->assertSame([self::MINE], $facets['OfficeCode']);
        $this->assertSame(['Visible Division'], $facets['Department']);
        $this->assertSame([self::MY_PERIOD], $facets['PeriodID']);
        $this->assertSame(['DRAFT'], $facets['Status']);

        $this->assertDisclosesNothing($facets, 'The facet options');
    }

    /** Again, not vacuous. */
    public function testAnAdministratorsFacetOptionsSpanBothOffices(): void
    {
        $facets = \apiGetPayrollFacets([], $this->user(self::ADMIN, 'Admin'));

        $this->assertContains(self::MINE, $facets['OfficeCode']);
        $this->assertContains(self::THEIRS, $facets['OfficeCode']);
    }

    /**
     * A user with no grant at all gets no options, not every option.
     *
     * ScopePredicate returns DENY_ALL rather than an empty string precisely so
     * this is the default direction, and a facet list is where a caller most
     * plausibly forgets to pass the predicate through.
     */
    public function testAUserWithNoGrantsSeesNoOptionsAtAll(): void
    {
        TestDatabase::connect()
            ->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['zzf-nobody@digos.gov.ph', 'Filter fixture', 'Encoder', '', 'Active', 'x']);
        ScopeGrantRepo::forget();

        $nobody = $this->user('zzf-nobody@digos.gov.ph', 'Encoder');

        $this->assertSame([], \apiListPayrolls([], $nobody));

        foreach (\apiGetPayrollFacets([], $nobody) as $facet => $options) {
            $this->assertSame([], $options,
                "The '$facet' facet offered options to a user with no grants.");
        }
    }

    /* ===================================================================
     * The payload never reaches SQL as an identifier
     * =================================================================== */

    /**
     * Sorting is the one place Phase 9 interpolates a column name, so it is
     * the one place the payload could become SQL. An unknown sort key is
     * refused rather than passed through or silently ignored.
     */
    public function testAnUnknownSortKeyIsRefused(): void
    {
        $this->expectException(RuntimeException::class);

        \apiListPayrolls(
            ['sort' => 'TotalNet; DROP TABLE Payroll'], $this->user(self::ENCODER, 'Encoder'));
    }

    /** A known sort key works, and still only sorts what the caller may see. */
    public function testAKnownSortKeyIsAcceptedAndStaysScoped(): void
    {
        $rows = PayrollRepo::search(
            $this->user(self::ENCODER, 'Encoder'), ['sort' => 'net', 'direction' => 'asc']);

        $this->assertSame([self::MY_PAYROLL], $this->column($rows, 'PayrollNo'));
    }
}
