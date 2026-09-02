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

    private const MY_EMPLOYEE = 'EMP-ZZFILT-MINE';
    private const THEIR_EMPLOYEE = 'EMP-ZZFILT-THEIRS';

    /** Distinctive so a leak through any column is recognisable in a failure. */
    private const THEIR_DEPARTMENT = 'Unseeable Division';
    private const THEIR_PREPARER = 'UNSEEABLE, Preparer';
    private const THEIR_REMARK = 'UNSEEABLE remark';
    private const THEIR_POSITION = 'Unseeable Position';
    private const THEIR_CONTROL_NO = 'ZZF-MEMO-UNSEEABLE';
    private const THEIR_REASON_CODE = 'UNSEEABLE-REASON';
    private const THEIR_TRAVEL_NO = 'ZZF-TO-UNSEEABLE';
    private const THEIR_GROUND = 'UNSEEABLE-GROUND';
    private const THEIR_NS_NO = 'NS-ZZFILT-THEIRS';

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

        $this->createDocumentFixture($db);

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

    /**
     * One row of every 9B entity, in each office.
     *
     * The employee-scoped documents matter most here: a bio exemption, travel
     * order and contract carry no office code of their own and are scoped
     * through a join to Employees, so a mistake in that join does not throw -
     * it returns the other office's people.
     */
    private function createDocumentFixture(\PDO $db): void
    {
        $employee = $db->prepare(
            'INSERT INTO Employees (EmployeeID, EmployeeNo, LastName, FirstName, OfficeCode,
                                    Department, EmploymentType, EmploymentTypeCode, Position, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $employee->execute([self::MY_EMPLOYEE, 'ZZF-0001', 'MINE', 'Person', self::MINE,
            'Visible Division', 'Job Order', 'JO', 'Worker', 'Active']);
        $employee->execute([self::THEIR_EMPLOYEE, 'ZZF-0002', 'UNSEEABLE', 'Person', self::THEIRS,
            self::THEIR_DEPARTMENT, 'Job Order', 'JO', self::THEIR_POSITION, 'Active']);

        $memo = $db->prepare(
            'INSERT INTO Memorandum (MemoID, ControlNo, Subject, OfficeCode, AuthorityType,
                                     EffectivityType, DateIssued, Status, Remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $memo->execute(['MEMO-ZZFILT-MINE', 'ZZF-MEMO-1', 'Visible subject', self::MINE,
            'Overtime', 'Range', '2026-07-01', 'Active', 'mine']);
        $memo->execute(['MEMO-ZZFILT-THEIRS', self::THEIR_CONTROL_NO, 'UNSEEABLE subject',
            self::THEIRS, 'Detail', 'OpenEnded', '2026-08-01', 'Active', self::THEIR_REMARK]);

        $exemption = $db->prepare(
            'INSERT INTO BioExemptions (ExemptionID, EmployeeID, ReasonCode, Reason,
                                        ValidFrom, ValidTo, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?)');

        $exemption->execute(['BX-ZZFILT-MINE', self::MY_EMPLOYEE, 'VISIBLE',
            'Visible reason', '2026-07-01', '2026-07-31', 'Active']);
        $exemption->execute(['BX-ZZFILT-THEIRS', self::THEIR_EMPLOYEE, self::THEIR_REASON_CODE,
            'UNSEEABLE reason', '2026-08-01', '2026-08-31', 'Active']);

        $travel = $db->prepare(
            'INSERT INTO TravelOrders (TravelOrderID, TravelOrderNo, EmployeeID, Destination,
                                       Purpose, DepartDate, ReturnDate, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        $travel->execute(['TO-ZZFILT-MINE', 'ZZF-TO-1', self::MY_EMPLOYEE, 'Davao',
            'Visible purpose', '2026-07-05', '2026-07-06', 'Active']);
        $travel->execute(['TO-ZZFILT-THEIRS', self::THEIR_TRAVEL_NO, self::THEIR_EMPLOYEE,
            'UNSEEABLE destination', 'UNSEEABLE purpose', '2026-08-05', '2026-08-06', 'Active']);

        $contract = $db->prepare(
            'INSERT INTO Contracts (ContractID, EmployeeID, TypeCode, RateBasis, Rate,
                                    StartDate, EndDate, Status, Remarks)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $contract->execute(['CON-ZZFILT-MINE', self::MY_EMPLOYEE, 'JO', 'Daily', 500.00,
            '2026-01-01', '2026-12-31', 'Active', 'mine']);
        $contract->execute(['CON-ZZFILT-THEIRS', self::THEIR_EMPLOYEE, 'JO', 'Monthly', 987654.00,
            '2026-01-01', '2026-12-31', 'Active', self::THEIR_REMARK]);

        // On THEIR payroll, so it is reachable only by someone who may read
        // that payroll - a suspension is scoped through the batch it holds.
        $db->prepare(
            'INSERT INTO Suspensions (NsNo, PayrollNo, GroundCode, Particulars,
                                      RequiredAction, Deadline, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([self::THEIR_NS_NO, self::THEIR_PAYROLL, self::THEIR_GROUND,
                'UNSEEABLE particulars', 'UNSEEABLE action', '2026-09-01', 'Open']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZFILT-%'");
        $db->exec("DELETE FROM Suspensions WHERE NsNo LIKE 'NS-ZZFILT-%'");
        $db->exec("DELETE FROM Contracts WHERE ContractID LIKE 'CON-ZZFILT-%'");
        $db->exec("DELETE FROM TravelOrders WHERE TravelOrderID LIKE 'TO-ZZFILT-%'");
        $db->exec("DELETE FROM BioExemptions WHERE ExemptionID LIKE 'BX-ZZFILT-%'");
        $db->exec("DELETE FROM Memorandum WHERE MemoID LIKE 'MEMO-ZZFILT-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZFILT-%'");
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
                  self::THEIR_EMPLOYEE, self::THEIR_POSITION, self::THEIR_CONTROL_NO,
                  self::THEIR_REASON_CODE, self::THEIR_TRAVEL_NO, self::THEIR_GROUND,
                  self::THEIR_NS_NO, 'UNSEEABLE', '987654'] as $secret) {
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
     * 9B: the same two limbs, for every remaining entity
     * =================================================================== */

    /**
     * Every searchable entity: list endpoint, facet endpoint, and the id
     * column its rows are recognised by.
     *
     * Driven from one table rather than written out six times so that adding
     * an entity to FilterSpec without covering it here is a visible omission -
     * the count assertion below is what makes it visible.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function entities(): array
    {
        return [
            'Payroll' => ['apiListPayrolls', 'apiGetPayrollFacets', 'PayrollNo'],
            'Employees' => ['apiListEmployees', 'apiGetEmployeeFacets', 'EmployeeID'],
            'Memorandum' => ['apiListMemoranda', 'apiGetMemorandumFacets', 'MemoID'],
            'BioExemptions' => ['apiListBioExemptions', 'apiGetBioExemptionFacets', 'ExemptionID'],
            'TravelOrders' => ['apiListTravelOrders', 'apiGetTravelOrderFacets', 'TravelOrderID'],
            'Contracts' => ['apiListContracts', 'apiGetContractFacets', 'ContractID'],
            'Suspensions' => ['apiListSuspensions', 'apiGetSuspensionFacets', 'NsNo'],
        ];
    }

    /** apiListEmployees paginates; the others return a bare list. */
    private function rowsOf(mixed $result): array
    {
        return is_array($result) && isset($result['rows']) ? $result['rows'] : $result;
    }

    /**
     * Limb 1, for every entity: an unfiltered list discloses nothing from the
     * other office, whichever way that entity happens to be scoped.
     *
     * @dataProvider entities
     */
    public function testEveryEntitysListIsScoped(string $list, string $facets, string $id): void
    {
        $rows = $this->rowsOf($list([], $this->user(self::ENCODER, 'Encoder')));

        $this->assertDisclosesNothing($rows, "$list");
    }

    /**
     * Limb 1 again, aimed deliberately: naming the other office in the filter
     * must return empty rather than refuse.
     *
     * Not every entity carries an OfficeCode facet on its own table - the
     * employee-scoped documents reach it through the join - which is exactly
     * why this is asserted through the endpoint rather than the repository.
     *
     * @dataProvider entities
     */
    public function testFilteringEveryEntityByAnotherOfficeReturnsEmpty(
        string $list, string $facets, string $id): void
    {
        $rows = $this->rowsOf(
            $list(['OfficeCode' => self::THEIRS], $this->user(self::ENCODER, 'Encoder')));

        $this->assertSame([], $rows,
            "$list returned rows when the filter named another office.");
    }

    /**
     * Limb 1, through the widest door: free text aimed at a string that exists
     * only on the other office's rows.
     *
     * @dataProvider entities
     */
    public function testFreeTextCannotReachAnotherOfficeOnAnyEntity(
        string $list, string $facets, string $id): void
    {
        $encoder = $this->user(self::ENCODER, 'Encoder');

        foreach (['UNSEEABLE', self::THEIRS, self::THEIR_CONTROL_NO] as $term) {
            $rows = $this->rowsOf($list(['search' => $term], $encoder));

            $this->assertDisclosesNothing($rows, "$list searching for '$term'");
        }
    }

    /**
     * Limb 2, for every entity: the dropdowns name nothing out of scope.
     *
     * @dataProvider entities
     */
    public function testEveryEntitysFacetOptionsAreScoped(
        string $list, string $facets, string $id): void
    {
        $options = $facets([], $this->user(self::ENCODER, 'Encoder'));

        $this->assertNotSame([], $options, "$facets offered no facets at all.");
        $this->assertDisclosesNothing($options, "$facets");
    }

    /**
     * And none of it is vacuous: an administrator sees the other office's row
     * on every one of these entities, so the empty results above are the scope
     * working rather than the fixture missing.
     *
     * @dataProvider entities
     */
    public function testAnAdministratorSeesTheOtherOfficeOnEveryEntity(
        string $list, string $facets, string $id): void
    {
        $rows = $this->rowsOf($list([], $this->user(self::ADMIN, 'Admin')));

        $this->assertNotSame([], $rows, "$list returned nothing even for an administrator.");
        $this->assertStringContainsString('UNSEEABLE', (string) json_encode($rows),
            "$list showed an administrator nothing from the other office - "
            . 'the fixture for this entity is not proving anything.');
    }

    /** Every entity FilterSpec knows about is covered by the cases above. */
    public function testEverySearchableEntityIsCoveredHere(): void
    {
        $covered = array_keys(self::entities());

        // EmployeesSensitive is Employees with a wider search box, exercised
        // through apiListEmployees for a caller holding employee.sensitive
        // rather than as an entity of its own.
        $expected = array_values(array_diff(
            \Digos\Domain\Query\FilterSpec::entities(), ['EmployeesSensitive']));

        sort($covered);
        sort($expected);

        $this->assertSame($expected, $covered,
            'A FilterSpec entity has no disclosure coverage in this file.');
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
