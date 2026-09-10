<?php
/**
 * ============================================================================
 * WatchlistTest - Phase 9C's exit gate: the four standing queries return
 * exactly the expected records against fixture data, and stay behind the
 * same scope gate as the ordinary list.
 *
 * Every predicate here is exercised at its boundary, not just inside it - an
 * off-by-one at a date comparison is invisible on data safely in the middle
 * of a window and is exactly the kind of defect this file exists to catch.
 * One deliberately out-of-scope row is seeded alongside each watchlist too:
 * these are new query paths built on the same "WHERE (scope) AND (...)"
 * composition FilterScopeTest already proved, and a copy-paste error that
 * dropped or swapped the scope fragment here would otherwise pass silently.
 *
 * TODAY is the 15th, not the 30th or 31st, on purpose. "6 months before" a
 * month-end date runs into MySQL/MariaDB's documented month-interval
 * overflow (subtracting 6 months from the 30th can land on a February that
 * only has 28 days, and the engine rolls the remainder into March rather
 * than clamping) - a real property of the predicate the phase plan decided,
 * not a bug in it, but not a boundary this file needs to also pin down. The
 * 15th lands on a real calendar day either way and keeps the assertions
 * about which rows qualify unambiguous.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\ContractRepo;
use Digos\Repo\EmployeeDocumentRepo;
use Digos\Repo\MemorandumRepo;
use Digos\Repo\ScopeGrantRepo;
use Digos\Repo\SuspensionRepo;
use PHPUnit\Framework\TestCase;

final class WatchlistTest extends TestCase
{
    private const TODAY = '2026-08-15';

    private const MINE = 'ZZWMINE';
    private const THEIRS = 'ZZWTHEIRS';

    private const MY_PAYROLL = 'ZZW-MINE-1';
    private const THEIR_PAYROLL = 'ZZW-THEIRS-1';

    private const MY_EMPLOYEE = 'EMP-ZZW-MINE';
    private const THEIR_EMPLOYEE = 'EMP-ZZW-THEIRS';

    private const ENCODER = 'zzw-encoder@digos.gov.ph';

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

    private function user(): array
    {
        return [
            'Email' => self::ENCODER,
            'FullName' => 'Watchlist fixture',
            'Role' => 'Encoder',
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS['Encoder'],
        ];
    }

    private function ids(array $rows, string $key): array
    {
        return array_map(fn(array $r) => (string) $r[$key], $rows);
    }

    /* =============================================================== fixture */

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Watchlist fixture $office", 'Active']);
        }

        $db->prepare('INSERT INTO Users (Email, FullName, Role, OfficeCode, Status, PasswordHash)
                      VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([self::ENCODER, 'Watchlist fixture', 'Encoder', '', 'Active', 'x']);
        $db->prepare('INSERT INTO ScopeGrants (GrantID, UserEmail, OfficeCode, CanRead, CanWrite)
                      VALUES (?, ?, ?, 1, 1)')
            ->execute(['SG-ZZWATCH-1', self::ENCODER, self::MINE]);

        $employee = $db->prepare(
            'INSERT INTO Employees (EmployeeID, EmployeeNo, LastName, FirstName, OfficeCode,
                                    Department, EmploymentType, EmploymentTypeCode, Position, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $employee->execute([self::MY_EMPLOYEE, 'ZZW-0001', 'MINE', 'Person', self::MINE,
            'Watchlist Division', 'Job Order', 'JO', 'Worker', 'Active']);
        $employee->execute([self::THEIR_EMPLOYEE, 'ZZW-0002', 'THEIRS', 'Person', self::THEIRS,
            'Watchlist Division', 'Job Order', 'JO', 'Worker', 'Active']);

        $period = $db->prepare(
            'INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear, StartDate, EndDate, Status)
             VALUES (?, ?, 2026, ?, ?, ?)');
        $period->execute(['PRD-ZZWATCH-A', 'September', '2026-09-01', '2026-09-15', 'Open']);

        $payroll = $db->prepare(
            'INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Department, Status,
                                  PreparedBy, DateCreated, TotalGross, TotalNet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $payroll->execute([self::MY_PAYROLL, 'PRD-ZZWATCH-A', self::MINE, 'Watchlist Division',
            'DRAFT', 'MINE, Preparer', self::TODAY . ' 09:00:00', 1000.00, 1000.00]);
        $payroll->execute([self::THEIR_PAYROLL, 'PRD-ZZWATCH-A', self::THEIRS, 'Watchlist Division',
            'DRAFT', 'THEIRS, Preparer', self::TODAY . ' 09:00:00', 1000.00, 1000.00]);

        $this->createBioExemptions($db);
        $this->createContracts($db);
        $this->createMemoranda($db);
        $this->createSuspensions($db);
    }

    private function createBioExemptions(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO BioExemptions (ExemptionID, EmployeeID, ReasonCode, ValidFrom, ValidTo, Status)
             VALUES (?, ?, ?, ?, ?, ?)');

        // Window is [TODAY, TODAY + 15 days] = [2026-08-15, 2026-08-30].
        $insert->execute(['BX-ZZW-IN', self::MY_EMPLOYEE, 'MID', '2026-07-01', '2026-08-22', 'Active']);
        $insert->execute(['BX-ZZW-TODAY', self::MY_EMPLOYEE, 'LOWER', '2026-07-01', self::TODAY, 'Active']);
        $insert->execute(['BX-ZZW-EDGE', self::MY_EMPLOYEE, 'UPPER', '2026-07-01', '2026-08-30', 'Active']);
        $insert->execute(['BX-ZZW-JUSTOUT', self::MY_EMPLOYEE, 'OUT', '2026-07-01', '2026-08-31', 'Active']);
        $insert->execute(['BX-ZZW-PAST', self::MY_EMPLOYEE, 'PAST', '2026-01-01', '2026-08-10', 'Active']);
        $insert->execute(['BX-ZZW-REVOKED', self::MY_EMPLOYEE, 'REVOKED', '2026-07-01', '2026-08-20', 'Revoked']);
        $insert->execute(['BX-ZZW-THEIRS', self::THEIR_EMPLOYEE, 'THEIRS', '2026-07-01', '2026-08-20', 'Active']);
    }

    private function createContracts(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Contracts (ContractID, EmployeeID, TypeCode, RateBasis, Rate,
                                    StartDate, EndDate, Status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        // Watched against a period ending 2026-09-15.
        $insert->execute(['CON-ZZW-EDGE', self::MY_EMPLOYEE, 'JO', 'Daily', 500,
            '2026-01-01', '2026-09-15', 'Active']);
        $insert->execute(['CON-ZZW-PAST', self::MY_EMPLOYEE, 'JO', 'Daily', 500,
            '2025-01-01', '2026-01-01', 'Active']);
        $insert->execute(['CON-ZZW-OUT', self::MY_EMPLOYEE, 'JO', 'Daily', 500,
            '2026-01-01', '2026-09-16', 'Active']);
        $insert->execute(['CON-ZZW-SUPERSEDED', self::MY_EMPLOYEE, 'JO', 'Daily', 500,
            '2026-01-01', '2026-09-10', 'Superseded']);
        $insert->execute(['CON-ZZW-OPENEND', self::MY_EMPLOYEE, 'JO', 'Daily', 500,
            '2026-01-01', null, 'Active']);
        $insert->execute(['CON-ZZW-THEIRS', self::THEIR_EMPLOYEE, 'JO', 'Daily', 500,
            '2026-01-01', '2026-09-10', 'Active']);
    }

    private function createMemoranda(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Memorandum (MemoID, ControlNo, Subject, OfficeCode, AuthorityType,
                                     EffectivityType, DateIssued, Status, RevokedByID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $touch = $db->prepare('UPDATE Memorandum SET UpdatedAt = ? WHERE MemoID = ?');

        // TODAY - 6 months = 2026-02-15. Stale means UpdatedAt strictly before that.
        $insert->execute(['MEMO-ZZW-STALE', 'ZZW-MEMO-1', 'Stale open-ended', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-01-01 00:00:00', 'MEMO-ZZW-STALE']);

        $insert->execute(['MEMO-ZZW-EDGEIN', 'ZZW-MEMO-2', 'Just past the cutoff', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-02-14 23:59:59', 'MEMO-ZZW-EDGEIN']);

        $insert->execute(['MEMO-ZZW-EDGEOUT', 'ZZW-MEMO-3', 'Exactly the cutoff', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-02-15 00:00:00', 'MEMO-ZZW-EDGEOUT']);

        $insert->execute(['MEMO-ZZW-RECENT', 'ZZW-MEMO-4', 'Recently touched', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-08-01 00:00:00', 'MEMO-ZZW-RECENT']);

        $insert->execute(['MEMO-ZZW-RANGED', 'ZZW-MEMO-5', 'Not open-ended', self::MINE,
            'Detail', 'Range', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-01-01 00:00:00', 'MEMO-ZZW-RANGED']);

        $insert->execute(['MEMO-ZZW-SUPERSEDED', 'ZZW-MEMO-6', 'Closed out', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Superseded', null]);
        $touch->execute(['2026-01-01 00:00:00', 'MEMO-ZZW-SUPERSEDED']);

        $insert->execute(['MEMO-ZZW-REVOKED', 'ZZW-MEMO-7', 'Revoked by another', self::MINE,
            'Detail', 'OpenEnded', '2025-01-01', 'Active', 'MEMO-ZZW-STALE']);
        $touch->execute(['2026-01-01 00:00:00', 'MEMO-ZZW-REVOKED']);

        $insert->execute(['MEMO-ZZW-THEIRS', 'ZZW-MEMO-8', 'Another office, also stale',
            self::THEIRS, 'Detail', 'OpenEnded', '2025-01-01', 'Active', null]);
        $touch->execute(['2026-01-01 00:00:00', 'MEMO-ZZW-THEIRS']);
    }

    private function createSuspensions(\PDO $db): void
    {
        $insert = $db->prepare(
            'INSERT INTO Suspensions (NsNo, PayrollNo, GroundCode, Deadline, Status)
             VALUES (?, ?, ?, ?, ?)');

        $insert->execute(['NS-ZZW-OVERDUE', self::MY_PAYROLL, 'GROUND', '2026-08-01', 'Open']);
        $insert->execute(['NS-ZZW-TODAY', self::MY_PAYROLL, 'GROUND', self::TODAY, 'Open']);
        $insert->execute(['NS-ZZW-FUTURE', self::MY_PAYROLL, 'GROUND', '2026-09-01', 'Open']);
        $insert->execute(['NS-ZZW-SETTLED', self::MY_PAYROLL, 'GROUND', '2026-08-01', 'Settled']);
        $insert->execute(['NS-ZZW-NODEADLINE', self::MY_PAYROLL, 'GROUND', null, 'Open']);
        $insert->execute(['NS-ZZW-THEIRS', self::THEIR_PAYROLL, 'GROUND', '2026-08-01', 'Open']);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM ScopeGrants WHERE GrantID LIKE 'SG-ZZWATCH-%'");
        $db->exec("DELETE FROM Suspensions WHERE NsNo LIKE 'NS-ZZW-%'");
        $db->exec("DELETE FROM Contracts WHERE ContractID LIKE 'CON-ZZW-%'");
        $db->exec("DELETE FROM Memorandum WHERE MemoID LIKE 'MEMO-ZZW-%'");
        $db->exec("DELETE FROM BioExemptions WHERE ExemptionID LIKE 'BX-ZZW-%'");
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZW-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID LIKE 'PRD-ZZWATCH-%'");
        $db->exec("DELETE FROM Employees WHERE EmployeeID LIKE 'EMP-ZZW-%'");
        $db->exec("DELETE FROM Users WHERE Email = '" . self::ENCODER . "'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    /* ------------------------------------------------------- bio exemptions */

    public function testBioExemptionsWatchlistIsExactlyTheWindowInScope(): void
    {
        $rows = EmployeeDocumentRepo::exemptionsExpiringScoped($this->user(), self::TODAY, 15);

        $this->assertSame(
            ['BX-ZZW-TODAY', 'BX-ZZW-IN', 'BX-ZZW-EDGE'],
            $this->ids($rows, 'ExemptionID'));
    }

    /* ------------------------------------------------------------ contracts */

    public function testContractsWatchlistHasNoLowerBoundAndExcludesInactiveStatus(): void
    {
        $rows = ContractRepo::expiringByScoped($this->user(), '2026-09-15');

        $this->assertSame(
            ['CON-ZZW-PAST', 'CON-ZZW-EDGE'],
            $this->ids($rows, 'ContractID'));
    }

    /* ----------------------------------------------------------- memoranda */

    public function testMemorandumWatchlistMatchesTheDecidedPredicateExactly(): void
    {
        $rows = MemorandumRepo::openEndedStaleScoped($this->user(), self::TODAY);

        $this->assertSame(
            ['MEMO-ZZW-STALE', 'MEMO-ZZW-EDGEIN'],
            $this->ids($rows, 'MemoID'));
    }

    /* --------------------------------------------------------- suspensions */

    public function testSuspensionsWatchlistIsStrictlyPastTheDeadline(): void
    {
        $rows = SuspensionRepo::pastDeadlineScoped($this->user(), self::TODAY);

        $this->assertSame(['NS-ZZW-OVERDUE'], $this->ids($rows, 'NsNo'));
    }

    /* --------------------------------------------------------------- scope */

    /**
     * The third limb every watchlist shares with FilterScopeTest's first two:
     * an office-scoped user never sees another office's rows on a watchlist,
     * even one that would otherwise qualify. Checked once per entity, with
     * windows wide enough that only scope - not the date predicate - could be
     * excluding the other office's row.
     */
    public function testNoWatchlistDisclosesAnotherOfficesRow(): void
    {
        $user = $this->user();

        $this->assertNotContains('BX-ZZW-THEIRS',
            $this->ids(EmployeeDocumentRepo::exemptionsExpiringScoped($user, self::TODAY, 999),
                'ExemptionID'));

        $this->assertNotContains('CON-ZZW-THEIRS',
            $this->ids(ContractRepo::expiringByScoped($user, '2026-12-31'), 'ContractID'));

        $this->assertNotContains('MEMO-ZZW-THEIRS',
            $this->ids(MemorandumRepo::openEndedStaleScoped($user, self::TODAY), 'MemoID'));

        $this->assertNotContains('NS-ZZW-THEIRS',
            $this->ids(SuspensionRepo::pastDeadlineScoped($user, '2026-12-31'), 'NsNo'));
    }
}
