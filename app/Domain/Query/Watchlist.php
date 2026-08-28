<?php
/**
 * ============================================================================
 * Watchlist - The four standing queries, as ordinary filter payloads.
 *
 * WHY THESE ARE NOT THEIR OWN QUERY PATH
 * A watchlist is a saved filter, not a second kind of search. Expressing each
 * one as a payload FilterSpec already understands means it composes with the
 * scope predicate exactly like a hand-set filter does - so an office-scoped
 * user's watchlist is their own office's expiring contracts and nobody else's,
 * without a line of scope code being written here. It also means each one is
 * URL-encodable (Phase 9E), exportable (9D) and answerable by the same
 * repository the screen already uses, instead of by a parallel query that
 * would have to be re-proven not to leak.
 *
 * The alternative - a bespoke SQL query per watchlist - is the shape of the
 * 2026-07-30 print leak: a second path to the same rows, built beside the
 * scoped one rather than on top of it.
 *
 * Pure: no DB::, no session, and no clock - today is passed in, so a fixture
 * can put a record either side of a threshold without touching the system
 * clock. That is what the exit gate asks for: "watchlist queries return
 * correct results against fixture data with known expiring records."
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Query;

use InvalidArgumentException;
use RuntimeException;

final class Watchlist
{
    /** Bio exemptions whose validity runs out within FIFTEEN_DAYS. */
    public const BIO_EXEMPTIONS_EXPIRING = 'bioExemptionsExpiring';

    /** Contracts lapsing on or before the end of a named payroll period. */
    public const CONTRACTS_EXPIRING = 'contractsExpiring';

    /** Open-ended memoranda nobody has touched in STALE_MONTHS. */
    public const MEMORANDA_STALE = 'memorandaStale';

    /** Open suspensions whose deadline has passed. */
    public const SUSPENSIONS_OVERDUE = 'suspensionsOverdue';

    /**
     * The horizon for "expiring soon", from the phase's task list.
     *
     * Fifteen days rather than a month because it is half a payroll period:
     * an exemption lapsing inside the next fortnight is one that may stop
     * covering somebody mid-period, which is when it becomes a finding rather
     * than a diary note.
     */
    public const EXPIRING_SOON_DAYS = 15;

    /** How long an open-ended memo may sit untouched before it is flagged. */
    public const STALE_MONTHS = 6;

    /**
     * watchlist name => the FilterSpec entity it queries.
     *
     * @var array<string, string>
     */
    private const ENTITIES = [
        self::BIO_EXEMPTIONS_EXPIRING => 'BioExemptions',
        self::CONTRACTS_EXPIRING => 'Contracts',
        self::MEMORANDA_STALE => 'Memorandum',
        self::SUSPENSIONS_OVERDUE => 'Suspensions',
    ];

    /** Every watchlist name, for the API and for tests. */
    public static function names(): array
    {
        return array_keys(self::ENTITIES);
    }

    /** The entity a watchlist queries. */
    public static function entity(string $name): string
    {
        if (!isset(self::ENTITIES[$name])) {
            throw new InvalidArgumentException(
                "'$name' is not a watchlist. Known: " . implode(', ', self::names()) . '.');
        }
        return self::ENTITIES[$name];
    }

    /**
     * The filter payload for one watchlist, as of $today.
     *
     * @param string $today ISO date; passed in rather than read, so a fixture
     *        can place a record either side of a threshold
     * @param array<string, mixed> $context extra input a watchlist needs -
     *        only CONTRACTS_EXPIRING uses it, for 'periodEnd'
     * @return array<string, mixed> a payload FilterSpec::fromPayload accepts
     * @throws RuntimeException when required context is missing, in words the
     *         caller can act on
     */
    public static function payload(string $name, string $today, array $context = []): array
    {
        self::entity($name);                       // validates the name

        return match ($name) {
            self::BIO_EXEMPTIONS_EXPIRING => [
                'Status' => 'Active',
                // From today, so an exemption that lapsed last month is not
                // reported as "expiring": it has already expired, which is a
                // different problem with a different remedy.
                'ExpiresFrom' => $today,
                'ExpiresTo' => self::plusDays($today, self::EXPIRING_SOON_DAYS),
                'sort' => 'validTo',
                'direction' => 'asc',
            ],

            self::CONTRACTS_EXPIRING => [
                'Status' => 'Active',
                // No lower bound on purpose. A contract that ended before the
                // period even began is the worse case, not one to exclude -
                // somebody is being paid on an engagement that already lapsed.
                'EndTo' => self::periodEnd($context),
                'sort' => 'end',
                'direction' => 'asc',
            ],

            self::MEMORANDA_STALE => [
                // Decided 2026-08-29: EffectivityType already encodes this.
                // EffectivityEnd IS NULL would also match Specific and
                // Recurring memos, which legitimately have no end date and are
                // nothing like open-ended - and a watchlist that cries wolf is
                // one people stop reading.
                'EffectivityType' => 'OpenEnded',
                'Status' => 'Active',
                'Revoked' => '0',
                'UpdatedBefore' => self::minusMonths($today, self::STALE_MONTHS),
                'sort' => 'issued',
                'direction' => 'asc',
            ],

            self::SUSPENSIONS_OVERDUE => [
                'Status' => 'Open',
                // Strictly before today: a suspension due today is not yet
                // overdue, and reporting it as such on the morning of the
                // deadline is how a list stops being trusted.
                'DeadlineTo' => self::minusDays($today, 1),
                'sort' => 'deadline',
                'direction' => 'asc',
            ],
        };
    }

    /**
     * The period end a contract watchlist is measured against.
     *
     * Required rather than defaulted to today: "expiring before period end" is
     * a question about a specific payroll period, and quietly substituting
     * today would answer a different question that looks the same.
     */
    private static function periodEnd(array $context): string
    {
        $end = trim((string) ($context['periodEnd'] ?? ''));

        if ($end === '') {
            throw new RuntimeException(
                'Choose a payroll period first - the contracts watchlist reports the ones '
                . 'ending before that period does.');
        }
        return $end;
    }

    private static function plusDays(string $date, int $days): string
    {
        return self::shift($date, "+$days days");
    }

    private static function minusDays(string $date, int $days): string
    {
        return self::shift($date, "-$days days");
    }

    private static function minusMonths(string $date, int $months): string
    {
        return self::shift($date, "-$months months");
    }

    /**
     * $date moved by $interval, as an ISO date.
     *
     * strtotime rather than DateTime for one arithmetic step, and the base is
     * always the date passed in - never time() - so this stays pure.
     */
    private static function shift(string $date, string $interval): string
    {
        $base = strtotime($date);
        if ($base === false) {
            throw new InvalidArgumentException("'$date' is not a date Watchlist can work from.");
        }

        return date('Y-m-d', (int) strtotime($interval, $base));
    }
}
