<?php
/**
 * ============================================================================
 * WatchlistScopeTest - Phase 9C's exit gate: "watchlist queries return correct
 * results against fixture data with known expiring records."
 *
 * Two things at once, because a watchlist can fail either way:
 *
 *   CORRECTNESS  the right rows, and only those. Every threshold has a record
 *                placed just inside it and one just outside, so a boundary
 *                that moves by a day fails here rather than in an office.
 *
 *   SCOPE        a watchlist is a saved filter, so it inherits the scope
 *                predicate from the ordinary search - and this proves it did,
 *                because "an office-scoped user's dashboard quietly listing
 *                another office's expiring contracts" is the exit gate of the
 *                whole phase, not just of this session.
 *
 * The dates are relative to today rather than fixed, so the fixtures sit the
 * same distance from each threshold whenever the suite runs. The boundary
 * arithmetic itself is pinned to exact dates in tests/Unit/WatchlistTest.php,
 * where today is an argument.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ScopeGrantRepo;
use PHPUnit\Framework\TestCase;

final class WatchlistScopeTest extends TestCase
{
    private const MINE = 'ZZWMINE';
    private const THEIRS = 'ZZWTHEIRS';

    private const MY_EMPLOYEE = 'EMP-ZZWATCH-MINE';
    private const THEIR_EMPLOYEE = 'EMP-ZZWATCH-THEIRS';

    private const PERIOD = 'PRD-ZZWATCH';
    private const MY_PAYROLL = 'ZZW-MINE-1';
    private const THEIR_PAYROLL = 'ZZW-THEIRS-1';

    private const ENCODER = 'zzw-encoder@digos.gov.ph';
    private const ADMIN = 'zzw-admin@digos.gov.ph';

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

    /** Today shifted by $days, as the fixtures and the watchlists both see it. */
    private static function day(int $days): string
    {
        return date('Y-m-d', strtotime("$days days", strtotime(date('Y-m-d'))));
    }

    /** Today shifted by whole months, for the staleness fixtures. */
    private static function month(int $months): string
    {
        return date('Y-m-d H:i:s', strtotime("$months months", strtotime(date('Y-m-d'))));
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Watchlist fixture $office", 'Active']);
        }

        // The period the contracts watchlist is measured against: ends in 30
        // days, so "ending in 5 days" is inside it and "in 100 days" is not.
        $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear,
                                                  StartDate, EndDate, Status)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::PERIOD, 'Watchlist', 2026, self::day(-15), self::day(30), 'Open']);

        $employee = $db->prepare(
            'INSERT INTO Employees (EmployeeID, LastName, FirstName, OfficeCode,
                                    EmploymentType, EmploymentTypeCode, Position, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $employee->execute([self::MY_EMPLOYEE, 'MINE', 'Person', self::MINE,
            'Job Order', 'JO', 'Worker', 'Active']);
        $employee->execute([self::THEIR_EMPLOYEE, 'UNSEEABLE', 'Person', self::THEIRS,
            'Job Order', 'JO', 'Worker', 'Active']);

        $payroll = $db->prepare(
            'INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Status, TotalNet)
             VALUES (?, ?, ?, ?, ?)');
        $payroll->execute([self::MY_PAYROLL, self::PERIOD, self::MINE, 'FOR_PRE_AUDIT', 100.00]);
        $payroll->execute([self::THEIR_PAYROLL, self::PERIOD, self::THEIRS, 'FOR_PRE_AUDIT', 100.00]);

        $this->createExemptions($db);
        $this->createContracts($db);
        $this->createMemoranda($db);
        $this->createSuspensions($db);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ENCODER, 'Watchlist fixture', 'HRMO', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZWATCH-1', self::ENCODER, self::MINE]);

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ADMIN, 'Watchlist fixture', 'Admin', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, CanRead, CanWrite)
                      VALUES (?, ?, 1, 1)')
            ->execute(['SG-ZZWATCH-2', self::ADMIN]);
    }

    /** Inside the 15-day horizon, outside it, already lapsed, and theirs. */
    private function createExemptions(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO BioExemptions (ExemptionID, EmployeeID, ReasonCode, Reason,
                                        ValidFrom, ValidTo, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?)');

        $insert->execute(['BX-ZZWATCH-SOON', self::MY_EMPLOYEE, 'SOON', 'Expiring soon',
            self::day(-30), self::day(5), 'Active']);
        $insert->execute(['BX-ZZWATCH-LATER', self::MY_EMPLOYEE, 'LATER', 'Expiring later',
            self::day(-30), self::day(40), 'Active']);
        $insert->execute(['BX-ZZWATCH-GONE', self::MY_EMPLOYEE, 'GONE', 'Already lapsed',
            self::day(-60), self::day(-10), 'Active']);
        $insert->execute(['BX-ZZWATCH-INACTIVE', self::MY_EMPLOYEE, 'INACTIVE', 'Cancelled',
            self::day(-30), self::day(5), 'Cancelled']);
        $insert->execute(['BX-ZZWATCH-THEIRS', self::THEIR_EMPLOYEE, 'THEIRS', 'Theirs',
            self::day(-30), self::day(5), 'Active']);
    }

    /** Lapsing inside the period, well after it, long ago, and theirs. */
    private function createContracts(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Contracts (ContractID, EmployeeID, TypeCode, RateBasis, Rate,
                                    StartDate, EndDate, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        $insert->execute(['CON-ZZWATCH-SOON', self::MY_EMPLOYEE, 'JO', 'Daily', 500.00,
            self::day(-300), self::day(5), 'Active']);
        $insert->execute(['CON-ZZWATCH-LATER', self::MY_EMPLOYEE, 'JO', 'Daily', 500.00,
            self::day(-300), self::day(100), 'Active']);

        // Already lapsed. Deliberately EXPECTED in the results - somebody is
        // being paid on an engagement that ended - which is why the contracts
        // watchlist has no lower bound while the exemptions one does.
        $insert->execute(['CON-ZZWATCH-LAPSED', self::MY_EMPLOYEE, 'JO', 'Daily', 500.00,
            self::day(-400), self::day(-30), 'Active']);

        $insert->execute(['CON-ZZWATCH-THEIRS', self::THEIR_EMPLOYEE, 'JO', 'Daily', 500.00,
            self::day(-300), self::day(5), 'Active']);
    }

    /**
     * The set that proves decision 3.
     *
     * RANGE and RECURRING both carry a NULL EffectivityEnd and are both stale,
     * and neither is open-ended. The predicate the phase plan originally
     * guessed - EffectivityEnd IS NULL - would have reported all three.
     */
    private function createMemoranda(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Memorandum (MemoID, ControlNo, Subject, OfficeCode, AuthorityType,
                                     EffectivityType, EffectivityStart, EffectivityEnd,
                                     DateIssued, Status, RevokedByID, UpdatedAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        $insert->execute(['MEMO-ZZWATCH-STALE', 'ZZW-M-STALE', 'Open-ended and forgotten',
            self::MINE, 'Overtime', 'OpenEnded', self::day(-400), null,
            self::day(-400), 'Active', null, self::month(-8)]);

        $insert->execute(['MEMO-ZZWATCH-FRESH', 'ZZW-M-FRESH', 'Open-ended and current',
            self::MINE, 'Overtime', 'OpenEnded', self::day(-400), null,
            self::day(-400), 'Active', null, self::month(-1)]);

        $insert->execute(['MEMO-ZZWATCH-RANGE', 'ZZW-M-RANGE', 'Range with no end typed in',
            self::MINE, 'Overtime', 'Range', self::day(-400), null,
            self::day(-400), 'Active', null, self::month(-8)]);

        $insert->execute(['MEMO-ZZWATCH-RECUR', 'ZZW-M-RECUR', 'Recurring, so no end date',
            self::MINE, 'Overtime', 'Recurring', self::day(-400), null,
            self::day(-400), 'Active', null, self::month(-8)]);

        $insert->execute(['MEMO-ZZWATCH-REVOKER', 'ZZW-M-REVOKER', 'The revoking instrument',
            self::MINE, 'Other', 'Range', self::day(-400), self::day(-399),
            self::day(-400), 'Active', null, self::month(-8)]);

        $insert->execute(['MEMO-ZZWATCH-REVOKED', 'ZZW-M-REVOKED', 'Open-ended but revoked',
            self::MINE, 'Overtime', 'OpenEnded', self::day(-400), null,
            self::day(-400), 'Active', 'MEMO-ZZWATCH-REVOKER', self::month(-8)]);

        $insert->execute(['MEMO-ZZWATCH-THEIRS', 'ZZW-M-THEIRS', 'UNSEEABLE and forgotten',
            self::THEIRS, 'Overtime', 'OpenEnded', self::day(-400), null,
            self::day(-400), 'Active', null, self::month(-8)]);
    }

    /** Past deadline, due today, due tomorrow, settled, and theirs. */
    private function createSuspensions(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Suspensions (NsNo, PayrollNo, GroundCode, Particulars,
                                      RequiredAction, Deadline, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?)');

        $insert->execute(['NS-ZZWATCH-OVERDUE', self::MY_PAYROLL, 'LATE', 'Overdue',
            'Settle it', self::day(-3), 'Open']);
        $insert->execute(['NS-ZZWATCH-TODAY', self::MY_PAYROLL, 'TODAY', 'Due today',
            'Settle it', self::day(0), 'Open']);
        $insert->execute(['NS-ZZWATCH-TOMORROW', self::MY_PAYROLL, 'SOON', 'Due tomorrow',
            'Settle it', self::day(1), 'Open']);
        $insert->execute(['NS-ZZWATCH-SETTLED', self::MY_PAYROLL, 'DONE', 'Already settled',
            'Nothing', self::day(-3), 'Settled']);
        $insert->execute(['NS-ZZWATCH-THEIRS', self::THEIR_PAYROLL, 'THEIRS', 'UNSEEABLE',
            'Settle it', self::day(-3), 'Open']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZWATCH-%'");
        $db->exec("DELETE FROM Suspensions WHERE NsNo LIKE 'NS-ZZWATCH-%'");
        $db->exec("DELETE FROM Contracts WHERE ContractID LIKE 'CON-ZZWATCH-%'");
        $db->exec("DELETE FROM BioExemptions WHERE ExemptionID LIKE 'BX-ZZWATCH-%'");
        // The revoked memo points at the revoker, so clear the link first.
        $db->exec("UPDATE Memorandum SET RevokedByID = NULL WHERE MemoID LIKE 'MEMO-ZZWATCH-%'");
        $db->exec("DELETE FROM Memorandum WHERE MemoID LIKE 'MEMO-ZZWATCH-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZW-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZWATCH-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID = '" . self::PERIOD . "'");
        $db->exec("DELETE FROM Users WHERE Email LIKE 'zzw-%@digos.gov.ph'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    private function user(string $email, string $role): array
    {
        return [
            'Email' => $email,
            'FullName' => 'Watchlist fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /** The ids a watchlist returned, for comparison against an expected set. */
    private function ids(array $rows, string $key): array
    {
        $ids = array_map(fn(array $r) => (string) $r[$key], $rows);
        sort($ids);
        return $ids;
    }

    /* =================================================================== */

    /**
     * Inside the horizon and Active only.
     *
     * LATER is outside it, GONE has already lapsed, INACTIVE is cancelled, and
     * THEIRS belongs to another office.
     */
    public function testExpiringExemptionsAreTheOnesInsideTheHorizon(): void
    {
        $rows = \apiGetExpiringBioExemptions([], $this->user(self::ENCODER, 'HRMO'));

        $this->assertSame(['BX-ZZWATCH-SOON'], $this->ids($rows, 'ExemptionID'));
    }

    /**
     * Contracts lapsing on or before the period ends - INCLUDING one that
     * lapsed long ago, which is the worse case rather than an old record to
     * skip.
     */
    public function testExpiringContractsIncludeAlreadyLapsedEngagements(): void
    {
        $rows = \apiGetExpiringContracts(
            ['PeriodID' => self::PERIOD], $this->user(self::ENCODER, 'HRMO'));

        $this->assertSame(['CON-ZZWATCH-LAPSED', 'CON-ZZWATCH-SOON'],
            $this->ids($rows, 'ContractID'));
    }

    /**
     * THE ASSERTION DECISION 3 EXISTS FOR.
     *
     * Only the open-ended, unrevoked, six-months-untouched memo. RANGE and
     * RECURRING are equally stale and equally end-dateless, and the predicate
     * the plan originally guessed would have reported both.
     */
    public function testStaleMemorandaAreOnlyTheOpenEndedOnes(): void
    {
        $rows = \apiGetStaleMemoranda([], $this->user(self::ENCODER, 'HRMO'));

        $this->assertSame(['MEMO-ZZWATCH-STALE'], $this->ids($rows, 'MemoID'));
    }

    /** Past the deadline, and open. Due today is not yet overdue. */
    public function testOverdueSuspensionsExcludeTheOnesDueTodayAndTomorrow(): void
    {
        $rows = \apiGetOverdueSuspensions([], $this->user(self::ENCODER, 'HRMO'));

        $this->assertSame(['NS-ZZWATCH-OVERDUE'], $this->ids($rows, 'NsNo'));
    }

    /* ------------------------------------------------------------- scope */

    /**
     * The exit gate. Every watchlist, as an office-scoped user: nothing from
     * the other office, by any column.
     */
    public function testNoWatchlistDisclosesAnotherOffice(): void
    {
        $hrmo = $this->user(self::ENCODER, 'HRMO');

        $results = [
            'expiring bio exemptions' => \apiGetExpiringBioExemptions([], $hrmo),
            'expiring contracts' => \apiGetExpiringContracts(['PeriodID' => self::PERIOD], $hrmo),
            'stale memoranda' => \apiGetStaleMemoranda([], $hrmo),
            'overdue suspensions' => \apiGetOverdueSuspensions([], $hrmo),
        ];

        foreach ($results as $what => $rows) {
            $json = (string) json_encode($rows);

            foreach ([self::THEIRS, 'UNSEEABLE', self::THEIR_EMPLOYEE,
                      self::THEIR_PAYROLL, 'ZZW-M-THEIRS'] as $secret) {
                $this->assertStringNotContainsString($secret, $json,
                    "The $what watchlist disclosed '$secret' from another office.");
            }
        }
    }

    /**
     * And none of it is vacuous: every one of those rows exists and an
     * administrator sees it, so the results above are the scope working rather
     * than the fixture being empty.
     */
    public function testAnAdministratorSeesTheOtherOfficesRowsOnEveryWatchlist(): void
    {
        $admin = $this->user(self::ADMIN, 'Admin');

        $this->assertContains('BX-ZZWATCH-THEIRS',
            $this->ids(\apiGetExpiringBioExemptions([], $admin), 'ExemptionID'));

        $this->assertContains('CON-ZZWATCH-THEIRS', $this->ids(
            \apiGetExpiringContracts(['PeriodID' => self::PERIOD], $admin), 'ContractID'));

        $this->assertContains('MEMO-ZZWATCH-THEIRS',
            $this->ids(\apiGetStaleMemoranda([], $admin), 'MemoID'));

        $this->assertContains('NS-ZZWATCH-THEIRS',
            $this->ids(\apiGetOverdueSuspensions([], $admin), 'NsNo'));
    }

    /** The contracts watchlist will not answer without being told the period. */
    public function testTheContractWatchlistRequiresAPeriod(): void
    {
        $this->expectExceptionMessage('PeriodID');

        \apiGetExpiringContracts([], $this->user(self::ENCODER, 'HRMO'));
    }
}
