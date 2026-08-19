<?php
/**
 * ============================================================================
 * PeriodTotals - Turns a period's day rows into the six totals a payroll line
 * carries.
 *
 * THIS IS THE SEAM. Before Phase 3B a timekeeper typed DaysWorked, HoursWorked,
 * OvertimeHours, LateMinutes, UndertimeMinutes and AbsentDays straight onto the
 * payroll line, and nothing in the system could say where those numbers came
 * from. From here they are derived from DtrDays, and this function is the only
 * place that derivation happens. Phases 4, 5 and 6 all trust it.
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O. Day rows in, totals out.
 * That is what lets the exit gate be "every PayrollDetails total is reproducible
 * by summing that employee's DtrDays rows", checked by a test rather than by
 * reading a screen.
 *
 * HOW A DAY BECOMES A TOTAL
 * computeLine() pays `daily x DaysWorked + hourly x HoursWorked + overtime`,
 * adding the three rather than choosing between them. So the classification has
 * to keep them disjoint, or a full day counted in both is paid twice:
 *
 *   absent                      -> AbsentDays += 1, nothing else
 *   hours >= a standard day     -> DaysWorked += 1  (the day is a whole day)
 *   hours >  0, under standard  -> HoursWorked += that day's hours
 *
 * Overtime, late and undertime accumulate on every non-absent day whichever
 * branch it took. Overtime is its own column in DtrDays precisely so that hours
 * beyond a standard day are never mistaken for a longer ordinary day.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Dtr;

final class PeriodTotals
{
    /**
     * The default length of a full working day, in hours.
     *
     * 8 because computeLine() derives an hourly rate as daily / 8 when the
     * employee has none, so anything else here would make the two disagree
     * about what a day is worth.
     */
    public const STANDARD_DAY_HOURS = 8.0;

    /** The six keys this produces, in the order a payroll line lists them. */
    public const KEYS = ['DaysWorked', 'HoursWorked', 'OvertimeHours',
        'LateMinutes', 'UndertimeMinutes', 'AbsentDays'];

    /**
     * Totals for one employee's days.
     *
     * @param array<int, array<string, mixed>> $days DtrDays rows, any order
     * @param float $standardDayHours what counts as a whole day
     * @return array{DaysWorked: float, HoursWorked: float, OvertimeHours: float,
     *               LateMinutes: float, UndertimeMinutes: float, AbsentDays: float}
     */
    public static function fromDays(
        array $days,
        float $standardDayHours = self::STANDARD_DAY_HOURS
    ): array {
        $totals = array_fill_keys(self::KEYS, 0.0);

        // A standard day of zero or less would make every worked day a whole
        // day AND leave HoursWorked empty, which is a plausible-looking wrong
        // answer rather than an obvious one. Fall back rather than divide the
        // period into nonsense.
        if ($standardDayHours <= 0) $standardDayHours = self::STANDARD_DAY_HOURS;

        foreach ($days as $day) {
            if (!empty($day['IsAbsent'])) {
                $totals['AbsentDays'] += 1.0;
                continue;
            }

            $hours = (float) ($day['HoursWorked'] ?? 0);

            if ($hours >= $standardDayHours) {
                $totals['DaysWorked'] += 1.0;
            } elseif ($hours > 0) {
                $totals['HoursWorked'] += $hours;
            }
            // A row with no hours and not marked absent contributes nothing.
            // That is a rest day or a holiday not worked, and it is deliberately
            // not an absence - Phase 4's resolveHoliday decides which, and
            // counting it here would pre-empt that with a deduction.

            $totals['OvertimeHours'] += (float) ($day['OvertimeHours'] ?? 0);
            $totals['LateMinutes'] += (float) ($day['LateMinutes'] ?? 0);
            $totals['UndertimeMinutes'] += (float) ($day['UndertimeMinutes'] ?? 0);
        }

        return array_map(fn(float $v) => round($v, 2), $totals);
    }

    /**
     * Totals for several employees at once, keyed by employee id.
     *
     * @param array<int, array<string, mixed>> $days rows for any number of people
     * @return array<string, array<string, float>>
     */
    public static function byEmployee(
        array $days,
        float $standardDayHours = self::STANDARD_DAY_HOURS
    ): array {
        $grouped = [];
        foreach ($days as $day) {
            $grouped[(string) ($day['EmployeeID'] ?? '')][] = $day;
        }

        $out = [];
        foreach ($grouped as $employeeId => $rows) {
            $out[$employeeId] = self::fromDays($rows, $standardDayHours);
        }
        return $out;
    }

    /**
     * Whether any of a period's rows was keyed by hand.
     *
     * Phase 6 rule #1 checks manual entries against a covering bio exemption,
     * and it cannot if manual and biometric rows are indistinguishable - which
     * is why Source is written on every row rather than defaulted.
     *
     * @param array<int, array<string, mixed>> $days
     */
    public static function hasManualEntry(array $days): bool
    {
        foreach ($days as $day) {
            if (($day['Source'] ?? 'Manual') === 'Manual') return true;
        }
        return false;
    }
}
