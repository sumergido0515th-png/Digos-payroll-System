<?php
/**
 * ============================================================================
 * PrintDoc.php - Print module core: the route handler, the Official-print
 * gate, and the small helpers every printed form shares.
 *
 * The seven form renderers - buildPrintHtml() (the Payroll worksheet itself)
 * through buildSettlementReportHtml() - live in app/PrintForms/, one file
 * each, reached only through buildFormHtml()'s dispatch below. This file was
 * 1374 lines carrying all eight functions in one place before the split;
 * EXECUTION_BUDGET.md named it, at 725 lines, as one of the three files where
 * a full rewrite is "most tempting and most wasteful" - by 1374 that tempting
 * rewrite was a real risk on every future form change. What stayed here is
 * exactly what every renderer needs before it can run: the request that
 * loaded the data (printBundle()), the gate deciding whether it may print as
 * Official (guardOfficialPrint(), recordOfficialPrint()), and formatting
 * helpers with no per-form logic of their own (printStampHtml(), pesoWords(),
 * percov(), printLine()). Nothing about the split changes behavior - every
 * function moved verbatim, and app/bootstrap.php loads all eight files in
 * the same request exactly as it loaded one.
 *
 * The original module purpose, unchanged: recreates the uploaded "DAILY WAGE
 * OF PAYROLL" worksheet (legal, landscape) as a BLANK template populated
 * only at print time:
 *
 *   Title / project line / Agency-Office / Period header
 *   Grid: No | NAME | Occupation | Rate Per Day | Days | TOTAL |
 *         Late/Undertime | Extra Work qty | Extra Work amount | CAFOA |
 *         Pag-Ibig | SSS | BIR | Net Amount | Signature or Thumbmark
 *   Bottom: Computation of Late block, certifications 1 (Timekeeper),
 *           2 (Department Head), APPROVED FOR PAYMENT (City Mayor),
 *           3 (Disbursing Officer), with the signature column continuing
 *           down the right-hand side.
 *
 * Data mapping per line: TOTAL = basic pay (rate x days + rate/hr x hours);
 * Late/Undertime = computed time-deduction amount; Extra Work = overtime;
 * CAFOA = gross; BIR = Tax, SSS = Cash Advance, Pag-Ibig = Other Deductions
 * so every row foots exactly to the stored Net Amount.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\EmployeeRepo;
use Digos\Repo\PayrollRepo;
use Digos\Repo\PrintLogRepo;
use Digos\Repo\ReferenceRepo;

/** Fixed number of body rows on the printed form. */
const PRINT_ROWS = 15;

/** Rows in the "Computation of Late" sub-table. */
const LATE_ROWS = 6;

/**
 * Allotment class printed on the CAFOA. JO/COS wages are charged to MOOE, so
 * this is 200 on every payroll this system produces - it is a constant rather
 * than a setting because a different allotment class would mean a payroll that
 * does not belong on this form at all.
 */
const CAFOA_ALLOTMENT = '200';

/* ==========================================================================
 * Official vs. review output
 * ========================================================================== */

/**
 * Whether a payroll in this status prints as the official document.
 *
 * Pure, and the single place the question is answered. It reads the STORED
 * status - never a URL parameter, never a caller's argument - because a
 * marking that can be removed by editing a query string is decoration rather
 * than a control.
 *
 * Cancelled is deliberately unofficial. A cancelled payroll is still worth
 * reading back during an audit, and it must not print as though it stands.
 *
 * This is the narrowest possible piece of Phase 8, which owns print gating
 * properly - print serials, reprint reasons, mandatory PDF preview, and an
 * Official mode reachable only after approval. It exists now only because
 * previewing a Draft from the workflow would otherwise produce a printout
 * indistinguishable from an approved one.
 */
function payrollPrintIsOfficial(string $status): bool
{
    return \Digos\Domain\Workflow\PayrollWorkflow::isOfficial($status);
}

/**
 * Stylesheet for the review marking.
 *
 * Deliberately NOT built from the WatermarkUrl / WatermarkOpacity settings.
 * PHASE_PLAN.md carries an explicit warning against exactly that: those are
 * decorative office branding for the dashboard and sign-in screens, and an
 * administrator can blank them. A page asserting that it is not official
 * cannot be something an administrator can switch off, so this is hardcoded.
 *
 * It must survive @media print - the paper copy is the whole point, and a
 * marking that only shows on screen protects nothing. `position:fixed` is
 * relative to the page box when printing, which is what keeps one rule working
 * across the four forms' different @page sizes (legal landscape for the
 * payroll, letter portrait for the summary and CAFOA).
 */
function reviewOverlayCss(): string
{
    return
        '.review-strip{position:fixed;top:0;left:0;right:0;z-index:9999;'
        . 'background:#b00020;color:#fff;font-family:Arial,sans-serif;font-weight:bold;'
        . 'font-size:9pt;letter-spacing:.12em;text-align:center;padding:3px 0;}'
        . '.review-wash{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9998;'
        . 'pointer-events:none;display:flex;align-items:center;justify-content:center;}'
        . '.review-wash span{font-family:Arial,sans-serif;font-weight:bold;'
        . 'font-size:60pt;color:rgba(176,0,32,.13);transform:rotate(-30deg);'
        . 'white-space:nowrap;letter-spacing:.06em;}'
        // Keep the ink in print too. print-color-adjust is what stops the
        // browser "helpfully" dropping the background of the strip.
        . '@media print{.review-strip,.review-wash{display:flex;'
        . '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        . '.review-strip{display:block;}}';
}

/**
 * The review marking itself, or '' for an official payroll.
 *
 * Names the status, so a reader can tell a draft under preparation from one
 * waiting on approval without going back to the system.
 */
function reviewOverlayHtml(string $status): string
{
    if (payrollPrintIsOfficial($status)) return '';

    $label = strtoupper($status) === 'CANCELLED'
        ? 'CANCELLED PAYROLL - NOT OFFICIAL, NOT FOR PAYMENT'
        : 'FOR REVIEW ONLY - NOT OFFICIAL (' . strtoupper($status) . ')';

    return '<div class="review-strip">' . esc($label) . '</div>'
        . '<div class="review-wash"><span>' . esc('NOT OFFICIAL') . '</span></div>';
}

/**
 * Readable Function/PPA label for a printed form.
 *
 * `Offices` and `Payroll` hold the function as a free string, and what was
 * typed there is sometimes the FunctionCode and sometimes the FunctionName -
 * both share one column until Phase 1 collapses them onto the code. Printed
 * forms want the name, so resolve either against the Functions master. A value
 * matching neither is printed as stored rather than dropped: a wrong-looking
 * label on the form is something the budget officer can catch, a silently
 * blank cell is not.
 */
function functionLabel(string $stored): string
{
    $stored = trim($stored);
    if ($stored === '') return '';

    return ReferenceRepo::functionName($stored) ?? $stored;
}

/**
 * The Function/PPA the payroll's own employees are assigned to.
 *
 * Last resort for the CAFOA, used only when neither the payroll nor its office
 * records one. Returns '' unless every employee on the payroll agrees: a
 * payroll spanning two functions is a real pre-audit finding, and picking one
 * of them would print an amount under an appropriation it was not charged to.
 * Returning '' defers to the caller's office-code fallback, which is visibly
 * not a function name - better that than a confident wrong answer.
 *
 * @param array $employees Employee master rows keyed by id, as loaded by
 *                         printBundle - raw DB shape, so `FunctionName`.
 */
function employeesFunction(array $employees): string
{
    $found = [];
    foreach ($employees as $e) {
        $f = trim((string) ($e['FunctionName'] ?? ''));
        if ($f !== '') $found[$f] = true;
    }
    return count($found) === 1 ? (string) array_key_first($found) : '';
}

/**
 * Loads everything one printed payroll needs, within the caller's scope.
 *
 * This function used to take no user and query Payroll directly, which made the
 * print path a way around the scope layer: apiGetPayroll refused another
 * office's payroll and apiGetPrintHtml rendered the same number in full, names
 * and all. `print.run` is held by five of the seven roles, so guessing a payroll
 * number was the whole attack. It reads through the same repositories as every
 * other scoped read now.
 *
 * @param bool $withSensitive whether the caller may read the restricted
 *                            employee tier - decided by permission upstream,
 *                            never by which form is being printed.
 */
function printBundle(string $payrollNo, array $user, bool $withSensitive = false): array
{
    $header = PayrollRepo::findScoped($user, $payrollNo);

    // Out of scope reports the same thing as absent, as apiGetPayroll does:
    // distinguishing them confirms that another office's payroll exists.
    if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);
    $header = aliasFunctionOut($header);

    $details = PayrollRepo::detailsScoped($user, $payrollNo);

    // A header inside the caller's scope whose lines are all charged outside it
    // is a real state - charging is per line, which is what migration 0006 was
    // for. Refuse rather than render, because a payroll form with no rows on it
    // does not look broken, it looks like a payroll with nobody on it, and it
    // would be printed, signed and filed.
    if (!$details) {
        throw new RuntimeException(
            'None of the lines on ' . $payrollNo . ' are charged to an office you have '
            . 'access to, so there is nothing you can print from it.');
    }

    $employees = EmployeeRepo::forPayrollLines(
        array_column($details, 'EmployeeID'), $withSensitive);

    return [
        'header' => $header,
        'details' => $details,
        'employees' => $employees,
        'period' => ReferenceRepo::period((string) $header['PeriodID']),
        'office' => aliasFunctionOut(ReferenceRepo::office((string) $header['OfficeCode'])),
        'timekeeper' => ReferenceRepo::timekeeper((string) ($header['TimekeeperID'] ?? '')),
        's' => settingsMap(),
    ];
}

/**
 * API wrapper for the SPA print-preview window.
 *
 * `official: true` requests the gated path: refused unless the payroll has
 * passed pre-audit approval AND its recomputed payload hash still matches
 * what was approved (guardOfficialPrint()), and - once past the gate -
 * assigned a print serial and logged, a reprint reason required from the
 * second Official print of a given form onward (recordOfficialPrint()).
 * Anything else is a Draft/preview render: always available to whoever holds
 * `print.run`, carrying the existing NOT OFFICIAL marking instead of a serial.
 */
function apiGetPrintHtml(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $payrollNo = (string) $p['PayrollNo'];
    $form = (string) ($p['form'] ?? 'payroll');
    $official = !empty($p['official']);
    $nsNo = (string) ($p['NsNo'] ?? '');
    if ($form === 'ns') requireFields($p, ['NsNo']);

    $printMeta = ['official' => false, 'serial' => null];

    if ($official) {
        $header = PayrollRepo::findScoped($user, $payrollNo);
        if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);

        guardOfficialPrint($header, $user);
        $printMeta = [
            'official' => true,
            'serial' => recordOfficialPrint($payrollNo, $form, $user,
                (string) ($p['ReprintReason'] ?? ''), (string) $header['PayloadHash']),
        ];
    }

    return [
        'html' => buildFormHtml($payrollNo, $form, $user, $printMeta, $nsNo),
        'official' => $official,
    ];
}

/**
 * Refuses an Official print outright before approval, or when the payroll's
 * data has changed since it was approved.
 *
 * A caught mismatch reverts the payroll to FOR_PRE_AUDIT and is logged
 * explicitly - api.php only logs a route AFTER the handler returns, so a
 * thrown refusal never reaches its automatic log call. Auth.php's
 * LOGIN_FAILED is the same shape: a refusal that must still leave a record
 * writes its own.
 */
function guardOfficialPrint(array $header, array $user): void
{
    $payrollNo = (string) $header['PayrollNo'];

    if (!payrollPrintIsOfficial((string) ($header['Status'] ?? ''))) {
        throw new RuntimeException(
            'Only a payroll that has passed pre-audit approval can be printed as Official. '
            . 'Print a Draft copy instead, or wait for approval.');
    }

    $stored = (string) ($header['PayloadHash'] ?? '');
    $recomputed = computePayloadHash(
        PayrollRepo::detailsUnscoped($payrollNo), (string) $header['PeriodID']);

    if ($stored !== '' && hash_equals($stored, $recomputed)) return;

    payrollTransition($payrollNo, 'FOR_PRE_AUDIT', $user);
    writeLog($user['Email'], 'PRINT_HASH_MISMATCH', 'Payroll',
        $payrollNo . ' returned to pre-audit: data changed since approval, caught at print time.');

    throw new RuntimeException(
        "This payroll's data has changed since it was approved, so it cannot be printed as "
        . 'Official. It has been returned to pre-audit for a fresh review.');
}

/**
 * Assigns a print serial and logs the Official print, once guardOfficialPrint()
 * has already passed.
 *
 * The reprint reason is required starting on this form's SECOND Official
 * print, not its first - a payroll approved once and printed once has
 * nothing to explain yet.
 */
function recordOfficialPrint(
    string $payrollNo,
    string $form,
    array $user,
    string $reprintReason,
    string $payloadHash
): string {
    if (PrintLogRepo::hasOfficialPrint($payrollNo, $form) && trim($reprintReason) === '') {
        throw new RuntimeException(
            'The ' . $form . ' form has already been printed Official once for this payroll. '
            . 'Give a reprint reason before printing it again.');
    }

    $serial = PrintLogRepo::nextSerial();

    PrintLogRepo::record([
        'PrintLogID' => newId('PRN'),
        'PayrollNo' => $payrollNo,
        'Form' => $form,
        'IsOfficial' => 1,
        'PrintSerial' => $serial,
        'ReprintReason' => $reprintReason,
        'PayloadHashAtPrint' => $payloadHash,
        'PrintedBy' => $user['FullName'] ?: $user['Email'],
        'PrintedByUser' => $user['Email'],
    ]);

    return $serial;
}

/**
 * Dispatches to one of the printable forms of a payroll:
 * payroll (default) | pagibig | summary | cafoa | certification | ns | settlement.
 *
 * 'ns' is the one form keyed by a suspension rather than by the payroll
 * itself - $nsNo is required for it and ignored by every other form.
 *
 * Each branch is defined in its own file under app/PrintForms/ - see that
 * directory's files for the actual rendering. This function is the only
 * place that needs to know all seven exist.
 *
 * @param array{official?: bool, serial?: ?string} $printMeta
 */
function buildFormHtml(
    string $payrollNo,
    string $form,
    array $user,
    array $printMeta = [],
    string $nsNo = ''
): string {
    return match ($form) {
        '', 'payroll' => buildPrintHtml($payrollNo, $user, $printMeta),
        'pagibig' => buildPagibigHtml($payrollNo, $user, $printMeta),
        'summary' => buildSummaryHtml($payrollNo, $user, $printMeta),
        'cafoa' => buildCafoaHtml($payrollNo, $user, $printMeta),
        'certification' => buildCertificationHtml($payrollNo, $user, $printMeta),
        'ns' => buildNoticeOfSuspensionHtml($nsNo, $user, $printMeta),
        'settlement' => buildSettlementReportHtml($payrollNo, $user, $printMeta),
        default => throw new RuntimeException('Unknown print form: ' . $form),
    };
}

/**
 * The stamp Phase 8 requires on every printed page: office, function, who
 * printed it and when, and - for an Official print only - the serial tying
 * this physical copy to one PrintLog row. A Draft/preview carries no serial;
 * reviewOverlayHtml() already marks those pages NOT OFFICIAL elsewhere.
 *
 * @param array{official?: bool, serial?: ?string} $printMeta
 */
function printStampHtml(array $header, array $user, array $printMeta): string
{
    $parts = [
        'Office: ' . esc((string) ($header['OfficeCode'] ?? '')),
        'Function: ' . esc(functionLabel((string) ($header['FunctionCode'] ?? ''))),
        'Printed by: ' . esc($user['FullName'] ?: $user['Email']),
    ];
    if (!empty($printMeta['official'])) {
        $parts[] = 'Serial: ' . esc((string) ($printMeta['serial'] ?? ''));
    }
    return ' &nbsp;|&nbsp; ' . implode(' &nbsp;|&nbsp; ', $parts);
}

/** "Fourteen Thousand Eight Hundred Fifty Pesos" style wording. */
function pesoWords(float $amount): string
{
    $pesos = (int) floor($amount);
    $cents = (int) round(($amount - $pesos) * 100);
    $w = ($pesos === 0 ? 'Zero' : numberWords($pesos)) . ' Pesos';
    if ($cents > 0) $w .= ' and ' . numberWords($cents) . ' Centavos';
    return $w;
}

/** Period coverage code "YYYYMM" from a period record. */
function percov(array $pd): string
{
    if (empty($pd['PayrollMonth'])) return '';
    $m = date('m', strtotime('1 ' . $pd['PayrollMonth'] . ' 2000'));
    return ($pd['PayrollYear'] ?? '') . $m;
}

/**
 * Derives the printed columns of one detail line from the stored figures.
 * basic  = rate x days + hourly x hours
 * lateUT = per-minute rate x (late + undertime) + rate x absences
 * extra  = gross - basic + lateUT  (reconstructs overtime pay exactly)
 * @param array $d PayrollDetails record.
 * @param float $hoursPerDay Working hours per day (settings).
 * @return array Printed money columns.
 */
function printLine(array $d, float $hoursPerDay): array
{
    $rate = num($d['SalaryRate']);
    $hourly = $hoursPerDay > 0 ? $rate / $hoursPerDay : 0;
    $perMin = $hourly / 60;

    $basic = round2($rate * num($d['DaysWorked']) + $hourly * num($d['HoursWorked']));
    $lateUT = round2($perMin * (num($d['LateMinutes']) + num($d['UndertimeMinutes']))
        + $rate * num($d['AbsentDays']));
    $extra = round2(max(0, num($d['GrossPay']) - $basic + $lateUT));

    return [
        'name' => $d['EmployeeName'],
        'occupation' => $d['Position'],
        'rate' => $rate,
        'days' => num($d['DaysWorked']),
        'total' => $basic,
        'lateUT' => $lateUT,
        'lateMins' => num($d['LateMinutes']) + num($d['UndertimeMinutes']),
        'extraQty' => num($d['OvertimeHours']),
        'extra' => $extra,
        'cafoa' => num($d['GrossPay']),
        'pagibig' => num($d['OtherDeductions']),
        'sss' => num($d['CashAdvance']),
        'bir' => num($d['Tax']),
        'net' => num($d['NetPay']),
    ];
}
