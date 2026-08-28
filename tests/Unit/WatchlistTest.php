<?php
/**
 * ============================================================================
 * WatchlistTest - the four standing queries, against fixtures.
 *
 * Watchlist is pure and takes today as an argument, so the boundaries can be
 * asserted exactly rather than approximately - which is the point, because
 * every one of these is an off-by-one waiting to happen and each off-by-one
 * has a different cost:
 *
 *   too early   a suspension reported overdue on the morning it is due, which
 *               is how a list stops being trusted
 *   too late    an exemption that lapses the day after the horizon, which is
 *               the case the watchlist exists to catch
 *
 * The integration side - that these payloads select the right rows through
 * the real repositories, and stay inside the caller's scope - is
 * tests/Integration/WatchlistScopeTest.php.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Unit;

use Digos\Domain\Query\FilterSpec;
use Digos\Domain\Query\Watchlist;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WatchlistTest extends TestCase
{
    /** A Saturday in the middle of a month, so nothing lands on a boundary. */
    private const TODAY = '2026-08-29';

    /* ------------------------------------------------------------ the registry */

    public function testEveryWatchlistNamesAnEntity(): void
    {
        $this->assertNotEmpty(Watchlist::names());

        foreach (Watchlist::names() as $name) {
            $entity = Watchlist::entity($name);

            $this->assertContains($entity, FilterSpec::entities(),
                "Watchlist '$name' queries '$entity', which FilterSpec cannot search.");
        }
    }

    public function testAnUnknownWatchlistIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Watchlist::entity('everythingWrong');
    }

    /**
     * The whole design claim: a watchlist is a saved filter, not a query of
     * its own. If FilterSpec cannot parse the payload, it is not one.
     */
    public function testEveryPayloadIsAValidFilterForItsEntity(): void
    {
        foreach (Watchlist::names() as $name) {
            $payload = Watchlist::payload(
                $name, self::TODAY, ['periodEnd' => '2026-09-15']);

            $spec = FilterSpec::fromPayload(Watchlist::entity($name), $payload);

            $this->assertNotEmpty($spec->conditions(),
                "Watchlist '$name' produced a payload that filters nothing at all.");
        }
    }

    /* ------------------------------------------- bio exemptions expiring soon */

    /** Bounded at BOTH ends - see the note in Watchlist on why. */
    public function testExpiringExemptionsLookFifteenDaysAhead(): void
    {
        $payload = Watchlist::payload(Watchlist::BIO_EXEMPTIONS_EXPIRING, self::TODAY);

        $this->assertSame(self::TODAY, $payload['ExpiresFrom']);
        $this->assertSame('2026-09-13', $payload['ExpiresTo']);
        $this->assertSame('Active', $payload['Status']);
    }

    /**
     * The lower bound is today, not the beginning of time.
     *
     * An exemption that lapsed last month has already expired - a different
     * problem with a different remedy - and reporting it here would bury the
     * ones still worth renewing.
     */
    public function testAlreadyExpiredExemptionsAreNotReportedAsExpiring(): void
    {
        $payload = Watchlist::payload(Watchlist::BIO_EXEMPTIONS_EXPIRING, self::TODAY);

        $this->assertArrayHasKey('ExpiresFrom', $payload);
        $this->assertSame(self::TODAY, $payload['ExpiresFrom']);
    }

    /* ------------------------------------------------------ contracts expiring */

    public function testExpiringContractsAreMeasuredAgainstThePeriodEnd(): void
    {
        $payload = Watchlist::payload(
            Watchlist::CONTRACTS_EXPIRING, self::TODAY, ['periodEnd' => '2026-09-15']);

        $this->assertSame('2026-09-15', $payload['EndTo']);
        $this->assertSame('Active', $payload['Status']);
    }

    /**
     * A contract that ended before the period began is the WORSE case, not one
     * to filter out: somebody is being paid on an engagement that has already
     * lapsed. So there is deliberately no lower bound here, unlike the
     * exemptions watchlist above.
     */
    public function testExpiringContractsHaveNoLowerBound(): void
    {
        $payload = Watchlist::payload(
            Watchlist::CONTRACTS_EXPIRING, self::TODAY, ['periodEnd' => '2026-09-15']);

        $this->assertArrayNotHasKey('EndFrom', $payload);
    }

    /**
     * "Expiring before period end" is a question about a specific period.
     * Quietly substituting today would answer a different question that looks
     * identical on screen, so the period is required.
     */
    public function testTheContractWatchlistRefusesWithoutAPeriod(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payroll period');

        Watchlist::payload(Watchlist::CONTRACTS_EXPIRING, self::TODAY);
    }

    /* --------------------------------------------------- open-ended memoranda */

    /**
     * DECIDED 2026-08-29, against the schema rather than by guess.
     *
     * The plan originally read this as `EffectivityEnd IS NULL`, which would
     * also have matched Specific and Recurring memoranda - both legitimately
     * carry no end date and neither is open-ended. Migration 0018 defines
     * OpenEnded as its own effectivity type, so that is what this asks for.
     */
    public function testStaleMemorandaAreSelectedByEffectivityTypeNotAMissingEndDate(): void
    {
        $payload = Watchlist::payload(Watchlist::MEMORANDA_STALE, self::TODAY);

        $this->assertSame('OpenEnded', $payload['EffectivityType']);
        $this->assertArrayNotHasKey('EffectivityEnd', $payload);
        $this->assertArrayNotHasKey('EffectiveTo', $payload);
    }

    /** A revoked memo is not an outstanding one. */
    public function testStaleMemorandaExcludeRevokedAndInactiveOnes(): void
    {
        $payload = Watchlist::payload(Watchlist::MEMORANDA_STALE, self::TODAY);

        $this->assertSame('0', $payload['Revoked']);
        $this->assertSame('Active', $payload['Status']);
    }

    public function testStaleMeansSixMonthsWithoutAnEdit(): void
    {
        $payload = Watchlist::payload(Watchlist::MEMORANDA_STALE, self::TODAY);

        $this->assertSame('2026-03-01', $payload['UpdatedBefore']);
    }

    /**
     * PHP's month arithmetic overflows a short month, and this records that it
     * is accepted rather than unnoticed: six months back from 31 August is
     * "31 February", which lands on 3 March.
     *
     * Left alone deliberately. The threshold is a housekeeping horizon where
     * three days either way changes nothing, and the alternative - clamping to
     * month ends - is machinery that would then need its own tests. It is
     * written down because the same arithmetic WOULD matter somewhere a
     * deadline is legally meaningful, and the next person to reach for this
     * pattern should see the caveat before copying it.
     */
    public function testTheSixMonthCutoffOverflowsShortMonthsAndThatIsAccepted(): void
    {
        $payload = Watchlist::payload(Watchlist::MEMORANDA_STALE, '2026-08-31');

        $this->assertSame('2026-03-03', $payload['UpdatedBefore']);
    }

    /* --------------------------------------------------- overdue suspensions */

    /**
     * Strictly before today. A suspension due today is not yet overdue, and
     * reporting it on the morning of the deadline is how a list stops being
     * read.
     */
    public function testOverdueSuspensionsExcludeOnesDueToday(): void
    {
        $payload = Watchlist::payload(Watchlist::SUSPENSIONS_OVERDUE, self::TODAY);

        $this->assertSame('2026-08-28', $payload['DeadlineTo']);
        $this->assertSame('Open', $payload['Status']);
    }

    /** Settled and Waived are closed; only Open can be overdue. */
    public function testOnlyOpenSuspensionsAreOverdue(): void
    {
        $payload = Watchlist::payload(Watchlist::SUSPENSIONS_OVERDUE, self::TODAY);

        $this->assertSame('Open', $payload['Status']);
    }

    /* ------------------------------------------------------------- purity */

    /**
     * No clock read anywhere: the same date in must give the same payload out,
     * today and next year. That is what lets the fixtures above pin a boundary
     * to the day.
     */
    public function testThePayloadDependsOnlyOnTheDatePassedIn(): void
    {
        foreach (Watchlist::names() as $name) {
            $context = ['periodEnd' => '2026-09-15'];

            $this->assertSame(
                Watchlist::payload($name, '2026-01-15', $context),
                Watchlist::payload($name, '2026-01-15', $context),
                "Watchlist '$name' is not a pure function of the date.");
        }
    }
}
