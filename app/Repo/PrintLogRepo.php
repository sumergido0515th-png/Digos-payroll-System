<?php
/**
 * ============================================================================
 * PrintLogRepo - print serials, reprint reasons, and every print attempt.
 *
 * One row per apiGetPrintHtml call, Official or Draft alike. A Draft/preview
 * render is logged too (IsOfficial = 0, no serial) because "who has looked at
 * this payroll and when" is exactly what an auditor asks after the fact, and
 * a gap in that history is indistinguishable from nobody having looked.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class PrintLogRepo
{
    /**
     * The next print serial, PS-YYYY-NNNNNN. Reuses Counters' (YearNo, Series)
     * key from migration 0021 with Series = 'PRINT' - the same reasoning that
     * gave suspensions their own 'SUSPENSION' series rather than sharing
     * PayrollNo's counter.
     */
    public static function nextSerial(): string
    {
        $year = (int) date('Y');

        DB::exec('INSERT IGNORE INTO Counters (YearNo, Series, LastNo) VALUES (?, ?, 0)',
            [$year, 'PRINT']);
        $last = (int) DB::scalar(
            'SELECT LastNo FROM Counters WHERE YearNo = ? AND Series = ? FOR UPDATE',
            [$year, 'PRINT']);
        $next = $last + 1;
        DB::exec('UPDATE Counters SET LastNo = ? WHERE YearNo = ? AND Series = ?',
            [$next, $year, 'PRINT']);

        return sprintf('PS-%d-%06d', $year, $next);
    }

    /**
     * Whether this payroll+form has already been printed Official at least
     * once - the trigger for requiring a reprint reason on the next one.
     */
    public static function hasOfficialPrint(string $payrollNo, string $form): bool
    {
        return (bool) DB::scalar(
            'SELECT COUNT(*) FROM PrintLog WHERE PayrollNo = ? AND Form = ? AND IsOfficial = 1',
            [$payrollNo, $form]);
    }

    /** @param array<string, mixed> $entry */
    public static function record(array $entry): void
    {
        DB::insert('PrintLog', $entry);
    }

    /**
     * Official prints in the caller's scope, optionally bounded by
     * PrintedAt - the raw material for
     * Digos\Domain\Reports\OperationalMetrics::printActivity(), which does
     * the reprint-rate and pages-printed arithmetic. Draft/preview prints
     * are excluded here the same way hasOfficialPrint() excludes them from
     * the reprint-reason trigger - a preview is not a reprint of anything.
     *
     * @return array<int, array{PayrollNo: string, Form: string, PrintedAt: string}>
     */
    public static function officialPrintsScoped(array $user, ?string $from, ?string $to): array
    {
        $scope = ScopeGateway::where($user, 'Payroll', 'h.');
        $clauses = [$scope['sql'], 'l.IsOfficial = 1'];
        $params = $scope['params'];

        if ($from !== null) { $clauses[] = 'l.PrintedAt >= ?'; $params[] = $from; }
        if ($to !== null) { $clauses[] = 'l.PrintedAt < ? + INTERVAL 1 DAY'; $params[] = $to; }

        return DB::rows(
            'SELECT l.PayrollNo, l.Form, l.PrintedAt
               FROM PrintLog l JOIN Payroll h ON h.PayrollNo = l.PayrollNo
              WHERE ' . implode(' AND ', $clauses),
            $params);
    }

    /**
     * A payroll's print history, newest first - the Certification cover sheet
     * and the pre-auditor's own review both want to show it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function historyFor(string $payrollNo): array
    {
        return DB::rows(
            'SELECT * FROM PrintLog WHERE PayrollNo = ? ORDER BY PrintedAt DESC',
            [$payrollNo]);
    }
}
