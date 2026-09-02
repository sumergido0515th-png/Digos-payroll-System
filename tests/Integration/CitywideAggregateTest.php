<?php
/**
 * ============================================================================
 * CitywideAggregateTest - Phase 9D's citywide half.
 *
 * Two separate properties, checked separately because they are enforced in
 * two separate places. The permission is checked the same way WorkflowTest
 * checks payroll.approve: literally at requirePermission(), the same gate
 * every route in public/api.php passes through, so a hidden dashboard button
 * is not what stands between an ordinary role and every office's total. The
 * query itself is checked by fixture: PayrollRepo::citywideTotals() carries
 * no scope predicate at all, on purpose, so what actually keeps an
 * office-scoped user off it is that permission - which is exactly why both
 * halves need their own test.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use Digos\Repo\PayrollRepo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CitywideAggregateTest extends TestCase
{
    private const MINE = 'ZZAGMINE';
    private const THEIRS = 'ZZAGTHEIRS';

    private const PERIOD_A = 'PRD-ZZAG-A';
    private const PERIOD_B = 'PRD-ZZAG-B';

    protected function setUp(): void
    {
        if (!TestDatabase::isAvailable()) {
            $this->markTestSkipped('No test database reachable. Run php tools/migrate.php first.');
        }
        ApplicationLayer::load();
        $this->removeFixture();
        $this->createFixture();
    }

    protected function tearDown(): void
    {
        if (defined('DB_NAME')) $this->removeFixture();
    }

    private function createFixture(): void
    {
        $db = TestDatabase::connect();

        foreach ([self::MINE, self::THEIRS] as $office) {
            $db->prepare('INSERT INTO Offices (OfficeCode, OfficeName, Status) VALUES (?, ?, ?)')
                ->execute([$office, "Aggregate fixture $office", 'Active']);
        }

        foreach ([[self::PERIOD_A, 'July', '2026-07-01', '2026-07-15'],
                  [self::PERIOD_B, 'August', '2026-08-01', '2026-08-15']] as $p) {
            [$id, $month, $start, $end] = $p;
            $db->prepare('INSERT INTO PayrollPeriods (PeriodID, PayrollMonth, PayrollYear,
                                                      StartDate, EndDate, Status)
                          VALUES (?, ?, 2026, ?, ?, ?)')
                ->execute([$id, $month, $start, $end, 'Open']);
        }

        $insert = $db->prepare(
            'INSERT INTO Payroll (PayrollNo, PeriodID, OfficeCode, Department, Status,
                                  PreparedBy, DateCreated, TotalGross, TotalNet)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

        // Two payrolls in MINE's office during Period A, one in THEIRS during
        // Period A, and one more in THEIRS during Period B - so an aggregate
        // that quietly dropped the GROUP BY, or quietly filtered to one
        // office, would produce a visibly wrong total rather than a merely
        // incomplete one.
        $insert->execute(['ZZAG-MINE-1', self::PERIOD_A, self::MINE, 'Div', 'DRAFT',
            'MINE, Preparer', '2026-07-16 09:00:00', 1000.00, 900.00]);
        $insert->execute(['ZZAG-MINE-2', self::PERIOD_A, self::MINE, 'Div', 'DRAFT',
            'MINE, Preparer', '2026-07-16 09:05:00', 2000.00, 1800.00]);
        $insert->execute(['ZZAG-THEIRS-1', self::PERIOD_A, self::THEIRS, 'Div', 'DRAFT',
            'THEIRS, Preparer', '2026-07-16 09:10:00', 5000.00, 4500.00]);
        $insert->execute(['ZZAG-THEIRS-2', self::PERIOD_B, self::THEIRS, 'Div', 'DRAFT',
            'THEIRS, Preparer', '2026-08-16 09:00:00', 7000.00, 6300.00]);
    }

    private function removeFixture(): void
    {
        $db = TestDatabase::connect();
        $db->exec("DELETE FROM Payroll WHERE PayrollNo LIKE 'ZZAG-%'");
        $db->exec("DELETE FROM PayrollPeriods WHERE PeriodID LIKE 'PRD-ZZAG-%'");
        $db->exec("DELETE FROM Offices WHERE OfficeCode IN ('" . self::MINE . "','" . self::THEIRS . "')");
    }

    /* --------------------------------------------------------- the permission */

    public function testOfficeHeadDoesNotHoldTheCitywidePermission(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access denied');

        \requirePermission($this->user('Office Head'), 'aggregate.citywide');
    }

    /** The role the phase decided on, 2026-08-29: COA oversight. */
    public function testInternalAuditorHoldsTheCitywidePermission(): void
    {
        \requirePermission($this->user('Internal Auditor'), 'aggregate.citywide');
        $this->addToAssertionCount(1); // did not throw
    }

    /** No other named role holds it either - only Internal Auditor, and Admin via '*'. */
    public function testNoOtherNamedRoleHoldsTheCitywidePermission(): void
    {
        foreach (['HRMO', 'Payroll In-Charge', 'Pre-Auditor', 'Encoder', 'Office Head'] as $role) {
            try {
                \requirePermission($this->user($role), 'aggregate.citywide');
                $this->fail("$role unexpectedly holds aggregate.citywide.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Access denied', $e->getMessage());
            }
        }
    }

    private function user(string $role): array
    {
        return [
            'Email' => 'zzag-fixture@digos.gov.ph',
            'FullName' => 'Aggregate fixture',
            'Role' => $role,
            'OfficeCode' => '',
            'permissions' => \PERMISSIONS[$role],
        ];
    }

    /* -------------------------------------------------------------- the query */

    /** Unfiltered: every office, not just one - the whole point of "citywide". */
    public function testTotalsCoverEveryOfficeNotJustOne(): void
    {
        $totals = $this->byOffice(PayrollRepo::citywideTotals());

        $this->assertSame(2, $totals[self::MINE]['PayrollCount']);
        $this->assertEqualsWithDelta(3000.00, (float) $totals[self::MINE]['TotalGross'], 0.001);
        $this->assertEqualsWithDelta(2700.00, (float) $totals[self::MINE]['TotalNet'], 0.001);

        $this->assertSame(2, $totals[self::THEIRS]['PayrollCount']);
        $this->assertEqualsWithDelta(12000.00, (float) $totals[self::THEIRS]['TotalGross'], 0.001);
        $this->assertEqualsWithDelta(10800.00, (float) $totals[self::THEIRS]['TotalNet'], 0.001);
    }

    /** Filtered the ordinary way, through the same FilterSpec every list uses. */
    public function testTotalsCanBeNarrowedToOnePeriod(): void
    {
        $totals = $this->byOffice(PayrollRepo::citywideTotals(['PeriodID' => self::PERIOD_A]));

        $this->assertSame(2, $totals[self::MINE]['PayrollCount']);
        $this->assertSame(1, $totals[self::THEIRS]['PayrollCount']);
        $this->assertEqualsWithDelta(5000.00, (float) $totals[self::THEIRS]['TotalGross'], 0.001);
    }

    /** @return array<string, array<string, mixed>> OfficeCode => row */
    private function byOffice(array $rows): array
    {
        $byOffice = [];
        foreach ($rows as $row) {
            if (in_array($row['OfficeCode'], [self::MINE, self::THEIRS], true)) {
                $byOffice[$row['OfficeCode']] = $row;
            }
        }
        return $byOffice;
    }
}
