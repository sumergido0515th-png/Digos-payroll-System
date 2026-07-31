<?php
/**
 * ============================================================================
 * ShiftResolver - Which version of a shift was in force on a date.
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O.
 *
 * Phase 3 made WorkShifts versioned - an edit inserts a row and closes the
 * previous one - for a reason that only pays off here: a DTR row from March is
 * judged against the shift as it was in March, not as it is today. Without
 * this, changing the office start time from 08:00 to 09:00 would
 * retroactively un-late everybody who had been late for months.
 *
 * A shift also answers "was this a rest day", which is why RestDay is not in
 * the Holidays table. A rest day is a property of the person's schedule, not
 * of the date - Saturday is a rest day for the office and a working day for
 * the market's weekend crew.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Resolver;

final class ShiftResolver
{
    /**
     * The version of a shift effective on a date, or null.
     *
     * Latest EffectiveFrom that is not after the date, and not already ended.
     * Null when the shift did not exist yet - which is a real answer, not a
     * failure: a DTR row predating the shift's creation has no shift to be
     * judged against, and inventing the current one would be the retroactive
     * rewrite this class exists to prevent.
     *
     * @param array<int, array<string, mixed>> $versions rows for ONE ShiftCode
     * @param string $date YYYY-MM-DD
     * @return array<string, mixed>|null
     */
    public static function versionOn(array $versions, string $date): ?array
    {
        $best = null;

        foreach ($versions as $version) {
            $from = (string) ($version['EffectiveFrom'] ?? '');
            if ($from === '' || $from > $date) continue;

            $to = $version['EffectiveTo'] ?? null;
            if ($to !== null && (string) $to !== '' && (string) $to < $date) continue;

            if ($best === null
                || $from > (string) $best['EffectiveFrom']
                || ($from === (string) $best['EffectiveFrom']
                    && (int) $version['VersionNo'] > (int) $best['VersionNo'])) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * Whether a date is a rest day under a shift version.
     *
     * RestDays is a comma-separated ISO weekday list, 1 = Monday .. 7 = Sunday.
     * An empty list means no rest days, which a shift worker legitimately has -
     * so it is distinguished from "no shift", where the question has no answer
     * rather than the answer "no".
     */
    public static function isRestDay(?array $shift, string $date): bool
    {
        if ($shift === null) return false;

        $restDays = array_filter(array_map('trim',
            explode(',', (string) ($shift['RestDays'] ?? ''))));
        if (!$restDays) return false;

        return in_array((string) (int) date('N', (int) strtotime($date)), $restDays, true);
    }

    /**
     * The night-differential minutes within a worked span.
     *
     * The window wraps midnight far more often than not - 22:00 to 06:00 is the
     * usual one - so a naive `end - start` would return a negative number and
     * silently pay nobody. Both halves are measured against a day flattened to
     * minutes, and the wrapped window is treated as two.
     *
     * @param array<string, mixed>|null $shift
     */
    public static function nightDifferentialMinutes(?array $shift, string $from, string $to): int
    {
        if ($shift === null || empty($shift['NightDiffFrom']) || empty($shift['NightDiffTo'])) {
            return 0;
        }

        $workStart = self::minutes($from);
        $workEnd = self::minutes($to);
        if ($workEnd <= $workStart) $workEnd += 24 * 60;        // the shift itself wrapped

        $windowStart = self::minutes((string) $shift['NightDiffFrom']);
        $windowEnd = self::minutes((string) $shift['NightDiffTo']);

        $windows = $windowEnd > $windowStart
            ? [[$windowStart, $windowEnd]]
            : [[$windowStart, 24 * 60], [0, $windowEnd]];

        $total = 0;
        foreach ($windows as [$start, $end]) {
            // Each window is also considered a day later, because a span that
            // began at 21:00 and ran to 03:00 crosses tonight's 22:00-24:00 and
            // tomorrow's 00:00-06:00.
            foreach ([0, 24 * 60] as $offset) {
                $total += max(0, min($workEnd, $end + $offset) - max($workStart, $start + $offset));
            }
        }

        return $total;
    }

    /** Minutes a shift is worked, break deducted. */
    public static function scheduledMinutes(?array $shift): int
    {
        if ($shift === null || empty($shift['TimeIn']) || empty($shift['TimeOut'])) return 0;

        $start = self::minutes((string) $shift['TimeIn']);
        $end = self::minutes((string) $shift['TimeOut']);
        if ($end <= $start) $end += 24 * 60;

        return max(0, $end - $start - (int) ($shift['BreakMinutes'] ?? 0));
    }

    private static function minutes(string $time): int
    {
        $parts = explode(':', $time);
        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
