<?php
/**
 * ============================================================================
 * OperationalMetrics - the three baseline figures docs/PHASE_PLAN.md names
 * for Phase 10's live run: reprint rate, top suspension grounds and average
 * settlement turnaround. Pages printed rides along with the reprint figure,
 * since both come from the same print history.
 *
 * Built ahead of the live run itself, not after it - the whole point of a
 * baseline is that it exists before the thing it is measuring starts, so
 * there is something to compare the live period against rather than a
 * number reconstructed from raw tables once someone remembers this was
 * asked for.
 *
 * Pure: no DB::, no session, no clock. Each function takes the rows a
 * repository already scoped to the caller and returns figures - the
 * repository's job is only ever "which rows may this caller see", never
 * "what do they add up to".
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Reports;

final class OperationalMetrics
{
    /**
     * Reprint rate and an estimate of pages printed, from a scoped print
     * history.
     *
     * A REPRINT is the second-or-later Official print of the same
     * (PayrollNo, Form) pair - not a guess, but the exact definition
     * app/PrintDoc.php's recordOfficialPrint() already enforces server-side:
     * a reprint reason is required starting from precisely that print. Rows
     * are grouped and ranked here in PHP rather than with a SQL window
     * function, so the definition lives in the one place fixtures can prove
     * it rather than also being trusted to run the same way on both of the
     * MariaDB versions this deployment targets.
     *
     * PAGES PRINTED is an estimate, and is not asserted as more than one:
     * the Payroll form is capped at a fixed row count per transaction (see
     * CLAUDE.md's own trap about that number, load-bearing in five other
     * places), so it is exactly one page every time - but no other form
     * carries that same guarantee. Counting every print as one page is
     * therefore an undercount for any form that ever grows past its own
     * minimum row count, never an overcount, which is the safe direction to
     * be wrong in for a metric whose whole point is showing paper use going
     * down.
     *
     * @param array<int, array{PayrollNo: string, Form: string, PrintedAt: string}> $rows
     *        Official prints only - PrintLogRepo::officialPrintsScoped() already filters that.
     * @return array{officialPrints: int, reprints: int, reprintRate: float, pagesPrinted: int}
     */
    public static function printActivity(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['PayrollNo'] . "\0" . $row['Form']][] = $row['PrintedAt'];
        }

        $official = 0;
        $reprints = 0;
        foreach ($groups as $printedAts) {
            $official += count($printedAts);
            $reprints += max(0, count($printedAts) - 1);
        }

        return [
            'officialPrints' => $official,
            'reprints' => $reprints,
            'reprintRate' => $official > 0 ? round($reprints / $official, 4) : 0.0,
            // Every print is at least one page - see the class doc above.
            'pagesPrinted' => $official,
        ];
    }

    /**
     * The top suspension grounds and average settlement turnaround, from a
     * scoped set of suspensions.
     *
     * "A stable top ground for 3+ months signals a process/form fix, not a
     * software fix" is the phase plan's own reading of this figure - it is
     * a count by GroundCode, not a judgement about which ground matters
     * more, and reading that trend is left to whoever runs this over
     * successive months.
     *
     * Turnaround is measured only across suspensions that have actually
     * been settled - an open suspension has no turnaround yet, and
     * counting "time since raised" for one would conflate "resolved
     * quickly" with "still open", the opposite of what the average is for.
     *
     * @param array<int, array{GroundCode: string, RaisedAt: string, SettledAt: ?string}> $rows
     * @return array{
     *   topGrounds: array<int, array{GroundCode: string, Count: int}>,
     *   settledCount: int,
     *   averageTurnaroundHours: ?float
     * }
     */
    public static function suspensionActivity(array $rows): array
    {
        $groundCounts = [];
        $turnaroundHours = [];

        foreach ($rows as $row) {
            $ground = $row['GroundCode'] !== '' ? $row['GroundCode'] : '(no ground given)';
            $groundCounts[$ground] = ($groundCounts[$ground] ?? 0) + 1;

            if ($row['SettledAt'] === null) continue;

            $raised = strtotime($row['RaisedAt']);
            $settled = strtotime($row['SettledAt']);
            if ($raised === false || $settled === false || $settled < $raised) continue;

            $turnaroundHours[] = ($settled - $raised) / 3600;
        }

        arsort($groundCounts);
        $top = [];
        foreach (array_slice($groundCounts, 0, 5, true) as $ground => $count) {
            $top[] = ['GroundCode' => $ground, 'Count' => $count];
        }

        return [
            'topGrounds' => $top,
            'settledCount' => count($turnaroundHours),
            'averageTurnaroundHours' => $turnaroundHours
                ? round(array_sum($turnaroundHours) / count($turnaroundHours), 1)
                : null,
        ];
    }
}
