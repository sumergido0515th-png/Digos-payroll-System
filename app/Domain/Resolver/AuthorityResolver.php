<?php
/**
 * ============================================================================
 * AuthorityResolver - Which memorandum authorised this, and for how long.
 *
 *   resolve(memos, coverage, employeeId, datetime, authorityType, shift)
 *     -> { memo_id, control_no, window, source_scope, superseded_chain[] }
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O.
 *
 * NEVER TAKE CLAIMED HOURS AT FACE VALUE. The authorised span is the
 * intersection of three things, and the narrowest wins:
 *
 *     (biometric span) x (memo window) x (shift)
 *
 * A memo authorising overtime 18:00-22:00, a punch-out at 23:30 and a shift
 * ending at 17:00 authorise four hours, not six and a half. Computing that in
 * one place is the point - the alternative is each caller intersecting two of
 * the three and quietly disagreeing about the rest.
 *
 * SUPERSESSION TRUNCATES, IT DOES NOT DELETE. When memo B supersedes memo A,
 * A's window ends the day before B's begins. A stays readable, and the
 * truncation is reported on it rather than applied silently - because "this
 * overtime was authorised by a memo that had already been replaced" is a
 * finding, and it needs the original window to be stateable.
 *
 * An AMENDMENT is not a replacement. Amending narrows or changes part of a
 * memo that stays in force; superseding ends it. The two are separate columns
 * on Memorandum precisely so this function can tell them apart.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Resolver;

final class AuthorityResolver
{
    /** How a memo's effectivity columns are to be read. */
    public const EFFECTIVITY_TYPES = ['Range', 'Specific', 'Recurring', 'Window', 'OpenEnded'];

    /**
     * The memoranda authorising something for an employee at a moment.
     *
     * Returns every applicable memo, most recently issued first, because
     * overlapping memoranda are a real situation and one of Phase 6's rules is
     * about them. The caller takes the first for "what authorised this" and
     * the count for "was there more than one".
     *
     * @param array<int, array<string, mixed>> $memos Memorandum rows
     * @param array<int, array<string, mixed>> $coverage MemorandumEmployees rows
     * @param string $datetime 'YYYY-MM-DD HH:MM' or 'YYYY-MM-DD'
     * @param string $authorityType '' matches any
     * @param array<string, mixed> $shift the WorkShifts version in force, or []
     * @return array<string, mixed>
     */
    public static function resolve(
        array $memos,
        array $coverage,
        string $employeeId,
        string $datetime,
        string $authorityType = '',
        array $shift = [],
        array $claimedSpan = []
    ): array {
        $date = substr($datetime, 0, 10);
        $covering = self::memoIdsCovering($coverage, $employeeId);
        $byId = [];
        foreach ($memos as $memo) $byId[(string) $memo['MemoID']] = $memo;

        $applicable = [];

        foreach ($memos as $memo) {
            $memoId = (string) $memo['MemoID'];

            if (!isset($covering[$memoId])) continue;
            if ($authorityType !== '' && (string) ($memo['AuthorityType'] ?? '') !== $authorityType) continue;
            if (($memo['Status'] ?? 'Active') === 'Draft') continue;

            $window = self::effectiveWindow($memo, $byId);
            if (!self::coversDate($memo, $date, $window)) continue;

            $applicable[] = [
                'memo' => $memo,
                'window' => $window,
                'truncated' => $window['truncated'],
                'chain' => self::supersessionChain($memo, $byId),
            ];
        }

        // Most recently issued first: when two memoranda both authorise a
        // moment, the later one is the operative instrument and the earlier is
        // the overlap worth reporting.
        usort($applicable, fn(array $a, array $b) =>
            [(string) ($b['memo']['DateIssued'] ?? ''), (string) $b['memo']['ControlNo']]
            <=> [(string) ($a['memo']['DateIssued'] ?? ''), (string) $a['memo']['ControlNo']]);

        if (!$applicable) {
            return [
                'authorised' => false,
                'memo_id' => '',
                'control_no' => '',
                'window' => null,
                'source_scope' => '',
                'superseded_chain' => [],
                'overlapping' => [],
                'authorised_minutes' => 0,
                'reason' => sprintf(
                    'No %smemorandum covers this employee on %s.',
                    $authorityType === '' ? '' : strtolower($authorityType) . ' ', $date),
            ];
        }

        $primary = $applicable[0];
        $memo = $primary['memo'];

        return [
            'authorised' => true,
            'memo_id' => (string) $memo['MemoID'],
            'control_no' => (string) $memo['ControlNo'],
            'authority_type' => (string) ($memo['AuthorityType'] ?? ''),
            'window' => $primary['window'],
            'truncated' => $primary['truncated'],

            // Which office's authority this is. A memo with no office is
            // citywide, and saying so is more useful than an empty string.
            'source_scope' => (string) ($memo['OfficeCode'] ?? '') ?: 'Citywide',

            'superseded_chain' => $primary['chain'],

            // Every other memo covering the same moment. Phase 6 rule:
            // overlapping authorities need a human to say which governs.
            'overlapping' => array_map(fn(array $a) => [
                'memo_id' => (string) $a['memo']['MemoID'],
                'control_no' => (string) $a['memo']['ControlNo'],
            ], array_slice($applicable, 1)),

            'authorised_minutes' => self::authorisedMinutes($memo, $shift, $claimedSpan),
            'reason' => '',
        ];
    }

    /**
     * The intersection of memo window, shift and claimed span, in minutes.
     *
     * This is the "never take claimed hours at face value" rule as a number.
     * Each of the three narrows the answer and none of them widens it; a
     * component that says nothing about time simply does not narrow.
     *
     * @param array<string, mixed> $memo
     * @param array<string, mixed> $shift WorkShifts row, or []
     * @param array<string, mixed> $claimedSpan ['From' => 'HH:MM', 'To' => 'HH:MM']
     */
    public static function authorisedMinutes(array $memo, array $shift = [], array $claimedSpan = []): int
    {
        $spans = [];

        // The memo's own time window, when it has one.
        if (!empty($memo['TimeFrom']) && !empty($memo['TimeTo'])) {
            $spans[] = [self::minutes((string) $memo['TimeFrom']), self::minutes((string) $memo['TimeTo'])];
        }
        // What the employee claims to have worked.
        if (!empty($claimedSpan['From']) && !empty($claimedSpan['To'])) {
            $spans[] = [self::minutes((string) $claimedSpan['From']), self::minutes((string) $claimedSpan['To'])];
        }
        // The shift, when the authority is bounded by it.
        //
        // Overtime is the deliberate exception: it is by definition outside the
        // shift, so intersecting it with the shift would authorise nothing at
        // all. That inversion is the bug this comment exists to prevent
        // somebody "fixing" back in.
        if (!empty($shift['TimeIn']) && !empty($shift['TimeOut'])
            && (string) ($memo['AuthorityType'] ?? '') !== 'Overtime') {
            $spans[] = [self::minutes((string) $shift['TimeIn']), self::minutes((string) $shift['TimeOut'])];
        }

        if (!$spans) return 0;

        $start = max(array_column($spans, 0));
        $end = min(array_column($spans, 1));

        return max(0, $end - $start);
    }

    /**
     * A memo's window after supersession, and whether it was truncated.
     *
     * @param array<string, array<string, mixed>> $byId every memo, keyed
     * @return array{start: ?string, end: ?string, truncated: bool, truncated_by: string, original_end: ?string}
     */
    public static function effectiveWindow(array $memo, array $byId): array
    {
        $start = self::orNull($memo['EffectivityStart'] ?? null);
        $end = self::orNull($memo['EffectivityEnd'] ?? null);

        $window = ['start' => $start, 'end' => $end, 'truncated' => false,
            'truncated_by' => '', 'original_end' => $end];

        // Find the memo that supersedes this one, if any. The link points
        // backwards - the successor names what it replaced - so this is a scan
        // rather than a lookup.
        foreach ($byId as $candidate) {
            if ((string) ($candidate['SupersedesID'] ?? '') !== (string) $memo['MemoID']) continue;

            $successorStart = self::orNull($candidate['EffectivityStart'] ?? null);
            if ($successorStart === null) continue;

            $truncatedEnd = date('Y-m-d', strtotime($successorStart . ' -1 day'));

            // Only a truncation if it actually shortens the window. A successor
            // starting after this memo already ended changes nothing, and
            // reporting that as a truncation would be noise on every renewal.
            if ($end === null || $truncatedEnd < $end) {
                $window['end'] = $truncatedEnd;
                $window['truncated'] = true;
                $window['truncated_by'] = (string) $candidate['MemoID'];
            }
            break;
        }

        return $window;
    }

    /**
     * What this memo replaced, oldest last.
     *
     * Walks SupersedesID backwards. The visited set is not defensive
     * programming for its own sake: the three chain columns are self-
     * referencing foreign keys with no cycle constraint, so A-supersedes-B-
     * supersedes-A is storable, and without this the resolver would hang
     * rather than report bad data.
     *
     * @param array<string, array<string, mixed>> $byId
     * @return array<int, array<string, string>>
     */
    public static function supersessionChain(array $memo, array $byId): array
    {
        $chain = [];
        $visited = [(string) $memo['MemoID'] => true];
        $cursor = $memo;

        while (true) {
            $previousId = (string) ($cursor['SupersedesID'] ?? '');
            if ($previousId === '' || isset($visited[$previousId]) || !isset($byId[$previousId])) break;

            $visited[$previousId] = true;
            $previous = $byId[$previousId];

            $chain[] = [
                'memo_id' => $previousId,
                'control_no' => (string) $previous['ControlNo'],
                'superseded_on' => (string) ($cursor['EffectivityStart'] ?? ''),
            ];
            $cursor = $previous;
        }

        return $chain;
    }

    /**
     * Whether a memo's effectivity covers a date.
     *
     * The five effectivity types are read here and nowhere else; Phase 3 stores
     * them raw precisely so that this is the only place that interprets them.
     *
     * @param array{start: ?string, end: ?string} $window the post-supersession window
     */
    public static function coversDate(array $memo, string $date, array $window): bool
    {
        $type = (string) ($memo['EffectivityType'] ?? 'Range');

        if ($type === 'Specific') {
            $dates = array_filter(array_map('trim',
                explode(',', (string) ($memo['SpecificDates'] ?? ''))));
            return in_array($date, $dates, true);
        }

        // Every other type is bounded by the window; a truncated one is
        // therefore already handled, which is the point of doing supersession
        // before this rather than after.
        if ($window['start'] !== null && $date < $window['start']) return false;
        if ($window['end'] !== null && $date > $window['end']) return false;

        if ($type === 'Recurring') {
            $days = array_filter(array_map('trim',
                explode(',', (string) ($memo['RecurrenceDays'] ?? ''))));
            if (!$days) return false;                   // recurring on no days is no days

            // ISO-8601: 1 = Monday .. 7 = Sunday, matching what the Phase 3
            // form asks for and what WorkShifts.RestDays stores.
            return in_array((string) (int) date('N', (int) strtotime($date)), $days, true);
        }

        // Range, Window and OpenEnded are all "inside the window", which the
        // two checks above have already established. Window differs only in
        // also having a time-of-day, and that narrows the minutes rather than
        // the dates.
        return true;
    }

    /**
     * Memo ids covering an employee, as a set.
     *
     * @param array<int, array<string, mixed>> $coverage
     * @return array<string, true>
     */
    private static function memoIdsCovering(array $coverage, string $employeeId): array
    {
        $ids = [];
        foreach ($coverage as $row) {
            if ((string) ($row['EmployeeID'] ?? '') === $employeeId) {
                $ids[(string) $row['MemoID']] = true;
            }
        }
        return $ids;
    }

    private static function orNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private static function minutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
