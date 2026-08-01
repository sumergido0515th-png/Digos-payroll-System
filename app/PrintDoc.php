<?php
/**
 * ============================================================================
 * PrintDoc.php - Print module. Recreates the uploaded "DAILY WAGE OF
 * PAYROLL" worksheet (legal, landscape) as a BLANK template populated only
 * at print time:
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

/** API wrapper for the SPA print-preview window. */
function apiGetPrintHtml(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return ['html' => buildFormHtml($p['PayrollNo'], (string) ($p['form'] ?? 'payroll'), $user)];
}

/**
 * Dispatches to one of the printable forms of a payroll:
 * payroll (default) | pagibig | summary | cafoa.
 */
function buildFormHtml(string $payrollNo, string $form, array $user): string
{
    return match ($form) {
        '', 'payroll' => buildPrintHtml($payrollNo, $user),
        'pagibig' => buildPagibigHtml($payrollNo, $user),
        'summary' => buildSummaryHtml($payrollNo, $user),
        'cafoa' => buildCafoaHtml($payrollNo, $user),
        default => throw new RuntimeException('Unknown print form: ' . $form),
    };
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

/**
 * Builds the complete printable HTML document in the uploaded worksheet's
 * layout. Every value comes from the database; unset signatories stay blank.
 */
function buildPrintHtml(string $payrollNo, array $user): string
{
    // Whether the "...Cash Card Number" half of the signature column can be
    // filled in at all - CashCard lives in EmployeeSensitive (migration 0015),
    // so a caller without the restricted tier gets the column exactly as
    // before: blank, for a physical signature or thumbmark.
    $withSensitive = EmployeeRepo::mayReadSensitive($user);
    $b = printBundle($payrollNo, $user, $withSensitive);
    $s = $b['s'];
    $pd = $b['period'];
    $h = $b['header'];
    $hoursPerDay = num(getSetting('WorkingHoursPerDay', '8')) ?: 8;

    $periodText = !empty($pd['StartDate'])
        ? fmtDate($pd['StartDate'], 'F j') . ' - ' . fmtDate($pd['EndDate'], 'j, Y')
        : trim(($pd['PayrollMonth'] ?? '') . ' ' . ($pd['PayrollYear'] ?? ''));

    $lines = array_map(fn($d) => printLine($d, $hoursPerDay), $b['details']);

    // ---- body rows + per-column totals -------------------------------------
    $sum = ['total' => 0, 'lateUT' => 0, 'extra' => 0, 'cafoa' => 0,
        'pagibig' => 0, 'sss' => 0, 'bir' => 0, 'net' => 0];
    $rowsHtml = '';
    $count = max(PRINT_ROWS, count($lines));
    for ($i = 0; $i < $count; $i++) {
        $l = $lines[$i] ?? null;
        if ($l) {
            foreach ($sum as $k => $v) $sum[$k] = round2($v + $l[$k]);

            // A cash card number on file means this employee is paid onto
            // that card rather than in hand, so it prints in place of the
            // signature/thumbmark space - the disbursing officer's cert on
            // this form ("paid in cash... established his identity...")
            // otherwise reads as satisfied by a blank cell.
            $employeeId = (string) ($b['details'][$i]['EmployeeID'] ?? '');
            $cashCard = $withSensitive
                ? trim((string) ($b['employees'][$employeeId]['CashCard'] ?? ''))
                : '';

            $rowsHtml .= '<tr>'
                . '<td class="c">' . ($i + 1) . '</td>'
                . '<td class="name">' . esc($l['name']) . '</td>'
                . '<td>' . esc($l['occupation']) . '</td>'
                . '<td class="r">' . money($l['rate']) . '</td>'
                . '<td class="c">' . esc($l['days']) . '</td>'
                . '<td class="r">' . money($l['total']) . '</td>'
                . '<td class="r">' . ($l['lateUT'] > 0 ? money($l['lateUT']) : '') . '</td>'
                . '<td class="c">' . ($l['extraQty'] > 0 ? esc($l['extraQty']) : '') . '</td>'
                . '<td class="r">' . ($l['extra'] > 0 ? money($l['extra']) : '') . '</td>'
                . '<td class="r">' . money($l['cafoa']) . '</td>'
                . '<td class="r">' . ($l['pagibig'] > 0 ? money($l['pagibig']) : '') . '</td>'
                . '<td class="r">' . ($l['sss'] > 0 ? money($l['sss']) : '') . '</td>'
                . '<td class="r">' . ($l['bir'] > 0 ? money($l['bir']) : '') . '</td>'
                . '<td class="r"><b>' . money($l['net']) . '</b></td>'
                . '<td class="sig">' . esc($cashCard) . '</td></tr>';
        } else {
            $rowsHtml .= '<tr class="blank"><td class="c">' . ($i + 1) . '</td>'
                . str_repeat('<td></td>', 13) . '<td class="sig"></td></tr>';
        }
    }

    // ---- Computation of Late sub-table -------------------------------------
    $lateLines = array_values(array_filter($lines, fn($l) => $l['lateMins'] > 0));
    $lateHtml = '';
    for ($i = 0; $i < max(LATE_ROWS, count($lateLines)); $i++) {
        $l = $lateLines[$i] ?? null;
        $lateHtml .= $l
            ? '<tr><td>' . esc($l['name']) . '</td><td class="c">' . esc($l['lateMins'])
                . ' min</td><td class="r">' . money($l['lateUT']) . '</td></tr>'
            : '<tr><td>&nbsp;</td><td></td><td></td></tr>';
    }
    $rateSample = $lines ? $lines[0]['rate'] : 0;
    $perMinSample = $hoursPerDay > 0 ? round2($rateSample / $hoursPerDay / 60) : 0;
    $lateNote = $rateSample > 0
        ? '* Computation of Rate per minute: ( P ' . money($rateSample) . '/' . $hoursPerDay
            . 'hours/60minutes ) = ' . number_format($perMinSample, 2)
        : '* Computation of Rate per minute: ( Rate/' . $hoursPerDay . 'hours/60minutes )';

    // ---- signatories (blank until configured in Settings / office head) ----
    $tkName = $s['SignatoryPreparedBy'] ?: ($b['timekeeper']['EmployeeName'] ?? '');
    $deptHead = $b['office']['OfficeHead'] ?? '';
    $mayor = $s['SignatoryApprovedBy'] ?? '';
    $mayorTitle = $s['SignatoryApprovedByTitle'] ?: 'City Mayor';
    $disbursing = $s['SignatoryCertifiedBy'] ?? '';

    $qr = qrUrl($h['PayrollNo'], 60);

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>' . esc($h['PayrollNo']) . '</title><style>'
        . '@page{size:legal landscape;margin:0.3in 0.35in;}'
        . 'html,body{margin:0;padding:0;}'
        . 'body{font-family:"Times New Roman",serif;font-size:10pt;color:#000;}'
        . '.sheet{width:12.6in;margin:0 auto;}'
        . 'h1{font-family:Arial,sans-serif;font-size:14pt;text-align:center;margin:0 0 1px;}'
        . '.project{text-align:center;margin:0;font-size:10.5pt;text-decoration:underline;font-weight:bold;}'
        . '.project-label{text-align:center;margin:0 0 4px;font-size:7.5pt;font-style:italic;}'
        . '.agency-row{display:flex;justify-content:space-between;align-items:flex-end;'
        . 'font-size:9.5pt;margin:2px 0 1px;}'
        . 'table.grid{width:100%;border-collapse:collapse;table-layout:fixed;}'
        . 'table.grid th,table.grid td{border:1px solid #000;padding:2px 5px;font-size:9.5pt;'
        . 'overflow:hidden;height:17px;}'
        . 'table.grid th{text-align:center;font-weight:bold;vertical-align:middle;'
        . 'white-space:normal;line-height:1.25;font-size:8.5pt;padding:5px 3px;}'
        . '.c{text-align:center}.r{text-align:right}.name{font-weight:bold;white-space:nowrap}'
        . 'td.sig{background:#fff;}tr.blank td.sig{background:#bfbfbf;}'
        . 'tr.total td{font-weight:bold;}'
        . '.bottom{display:flex;width:100%;margin-top:0;align-items:stretch;}'
        . '.b-left{width:34%;}.b-mid{width:44%;border:1px solid #000;border-top:none;}'
        . '.b-right{width:22%;display:flex;flex-direction:column;}'
        . 'table.late{width:100%;border-collapse:collapse;}'
        . 'table.late td{border:1px solid #000;padding:2px 5px;font-size:9pt;height:17px;}'
        . '.late-head{font-weight:bold;font-size:8.5pt;border:1px solid #000;border-bottom:none;'
        . 'padding:1px 3px;}'
        . '.late-note{font-size:7.5pt;font-weight:bold;border:1px solid #000;border-top:none;'
        . 'padding:1px 3px;}'
        . '.cert{padding:10px 14px;font-size:9pt;line-height:1.4;}'
        . '.cert h4{text-align:center;font-size:9.5pt;margin:2px 0 4px;}'
        . '.cert p{margin:2px 0;text-align:center;}'
        . '.cert .just{text-align:justify;}'
        . '.sigline{width:60%;margin:32px auto 0;border-bottom:1px solid #000;text-align:center;'
        . 'font-weight:bold;text-transform:uppercase;line-height:1.4;}'
        . '.sigcap{text-align:center;font-size:8.5pt;margin:1px 0 4px;}'
        . '.cert2{border:1px solid #000;border-top:none;padding:10px 14px;font-size:9pt;line-height:1.4;}'
        . '.approved{padding:12px 14px;text-align:center;font-size:9pt;}'
        . '.approved .label{text-align:left;font-weight:bold;}'
        . '.mayor{font-weight:bold;text-decoration:underline;text-transform:uppercase;'
        . 'font-size:10.5pt;margin:48px 0 0;}'
        . '.sigcells{flex:1;}'
        . 'table.sigcol{width:100%;border-collapse:collapse;}'
        . 'table.sigcol td{border:1px solid #000;height:17px;}'
        . 'table.sigcol td.gray{background:#bfbfbf;width:24%;}'
        . '.cert3{border:1px solid #000;padding:8px 10px;font-size:8pt;line-height:1.35;text-align:justify;}'
        . '.cert3 b{display:block;text-align:left;}'
        . '.foot{display:flex;justify-content:space-between;align-items:center;font-size:7.5pt;'
        . 'margin-top:4px;color:#333;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . reviewOverlayHtml((string) ($h['Status'] ?? ''))
        . '<div class="sheet">'

        // ---- header ---------------------------------------------------------
        . '<h1>DAILY WAGE OF PAYROLL</h1>'
        . '<p class="project">' . esc($h['Remarks'] !== '' ? $h['Remarks']
            : 'Doing various works from time to time') . '</p>'
        . '<p class="project-label">Project</p>'
        . '<div class="agency-row">'
        . '<span>Agency/Office: <b>' . esc(($s['GovernmentName'] ?? '') . ' - '
            . ($b['office']['OfficeName'] ?? $h['OfficeCode'])) . '</b></span>'
        . '<span>Payroll No.: <b>' . esc($h['PayrollNo']) . '</b>&nbsp;&nbsp;&nbsp;'
        . 'Period: <b>' . esc($periodText) . '</b></span></div>'

        // ---- main grid ------------------------------------------------------
        . '<table class="grid"><colgroup>'
        . '<col style="width:2%"><col style="width:14%"><col style="width:7%">'
        . '<col style="width:6.5%"><col style="width:5%"><col style="width:6%">'
        . '<col style="width:5.5%"><col style="width:5%"><col style="width:6%">'
        . '<col style="width:6.5%"><col style="width:5%"><col style="width:5%">'
        . '<col style="width:5%"><col style="width:6.5%"><col style="width:15%">'
        . '</colgroup><thead><tr>'
        . '<th></th><th>NAME</th><th>Occupation</th><th>Rate Per Day</th>'
        . '<th>Number of Days Worked</th><th>TOTAL</th><th>Late / Undertime</th>'
        . '<th>Extra Work Day/Hour</th><th>Extra Work Day/Hour (amount)</th>'
        . '<th>Income To be Controlled in CAFOA</th><th>Pag-Ibig</th><th>SSS</th>'
        . '<th>BIR</th><th>Net Amount</th><th>Signature or Thumbmark/Cash Card Number</th>'
        . '</tr></thead><tbody>' . $rowsHtml
        . '<tr class="total"><td colspan="5" class="r">TOTAL</td>'
        . '<td class="r">P ' . money($sum['total']) . '</td>'
        . '<td class="r">' . ($sum['lateUT'] > 0 ? money($sum['lateUT']) : '-') . '</td>'
        . '<td></td>'
        . '<td class="r">P ' . ($sum['extra'] > 0 ? money($sum['extra']) : '-') . '</td>'
        . '<td class="r">P ' . money($sum['cafoa']) . '</td>'
        . '<td class="r">P ' . ($sum['pagibig'] > 0 ? money($sum['pagibig']) : '-') . '</td>'
        . '<td class="r">P ' . ($sum['sss'] > 0 ? money($sum['sss']) : '-') . '</td>'
        . '<td class="r">P ' . ($sum['bir'] > 0 ? money($sum['bir']) : '-') . '</td>'
        . '<td class="r"><b>P ' . money($sum['net']) . '</b></td>'
        . '<td class="sig"></td></tr>'
        . '</tbody></table>'

        // ---- bottom: late computation | certifications | signature column ---
        . '<div class="bottom">'

        . '<div class="b-left">'
        . '<div class="late-head">Computation of Late:</div>'
        . '<table class="late"><colgroup><col style="width:46%"><col style="width:22%">'
        . '<col style="width:32%"></colgroup>' . $lateHtml . '</table>'
        . '<div class="late-note">' . esc($lateNote) . '</div>'
        . '<div class="cert2"><b>2. CERTIFIED:</b><br>'
        . '<span style="text-align:justify;display:block">I CERTIFY that this roll is correct; '
        . 'every person whose name appears hereon rendered service for the time and at the '
        . 'period stated under my general supervision and I recommend for the approval of '
        . 'payment for this roll.</span>'
        . '<div class="sigline">' . ($deptHead !== '' ? esc($deptHead) : '&nbsp;') . '</div>'
        . '<p class="sigcap">Department Head / Head of Office</p></div>'
        . '</div>'

        . '<div class="b-mid">'
        . '<div class="cert"><h4>1. CERTIFICATION</h4>'
        . '<p>I HEREBY CERTIFY that each person whose name appears on this roll rendered '
        . 'service as indicated and for the time stated. <i>(Including Saturdays and Holidays)</i></p>'
        . '<div class="sigline">' . ($tkName !== '' ? esc($tkName) : '&nbsp;') . '</div>'
        . '<p class="sigcap">Timekeeper</p></div>'
        . '<div class="approved"><div class="label">APPROVED FOR PAYMENT</div>'
        . '<p class="mayor">' . ($mayor !== '' ? esc($mayor) : '&nbsp;') . '</p>'
        . '<p class="sigcap">' . esc($mayorTitle) . '</p></div>'
        . '</div>'

        . '<div class="b-right">'
        . '<div class="sigcells"><table class="sigcol">'
        . str_repeat('<tr><td class="gray"></td><td></td></tr>', 6)
        . '</table></div>'
        . '<div class="cert3"><b>3. CERTIFIED:</b>'
        . 'I CERTIFY on my official oath that I have this __ day of __________, paid in cash '
        . 'to each man whose name appears on the above roll, the amount set opposite his name. '
        . 'He having presented himself, established his identity, and affixed his signature or '
        . 'thumbmark on the space provided therefore. Unpaid services are as noted.'
        . '<div class="sigline" style="width:90%">' . ($disbursing !== '' ? esc($disbursing) : '&nbsp;')
        . '</div>'
        . '<p class="sigcap">Name &amp; Signature of Disbursing Officer</p></div>'
        . '</div>'

        . '</div>'

        // ---- footer ---------------------------------------------------------
        . '<div class="foot"><span>Prepared by: ' . esc($h['PreparedBy'])
        . ' &nbsp;|&nbsp; Status: ' . esc($h['Status']) . '</span>'
        . '<span>Printed: ' . date('m/d/Y H:i') . '</span>'
        . '<img src="' . esc($qr) . '" width="42" height="42" alt="QR"></div>'

        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}

/* ==========================================================================
 * Pag-IBIG remittance list
 * ======================================================================== */

/**
 * Membership Savings remittance list: employer header block + one row per
 * employee with Pag-IBIG MID No., name parts, PERCOV, monthly compensation
 * and the EE share (the payroll line's Pag-IBIG deduction).
 */
function buildPagibigHtml(string $payrollNo, array $user): string
{
    // The only form that reads the restricted tier: it prints each employee's
    // Pag-IBIG MID number and monthly compensation, both of which migration
    // 0015 moved to EmployeeSensitive.
    //
    // Refused outright rather than rendered with those columns blank. A
    // statutory remittance list that looks complete and has holes in it is
    // worse than no list - nobody notices until the remittance is rejected,
    // and by then it has been signed and sent. Of the five roles holding
    // print.run, HRMO, Payroll In-Charge and Pre-Auditor hold this; Encoder
    // and Office Head do not.
    if (!EmployeeRepo::mayReadSensitive($user)) {
        throw new RuntimeException(
            'The Pag-IBIG remittance list shows each employee\'s Pag-IBIG number and '
            . 'monthly rate, which your role is not allowed to see. Ask HR or the '
            . 'payroll in-charge to print this one.');
    }

    $b = printBundle($payrollNo, $user, true);
    $s = $b['s'];
    $h = $b['header'];
    $pv = percov($b['period']);

    $rowsHtml = '';
    $total = 0.0;
    $count = max(10, count($b['details']));
    for ($i = 0; $i < $count; $i++) {
        $d = $b['details'][$i] ?? null;
        if ($d) {
            $e = $b['employees'][$d['EmployeeID']] ?? [];
            $ee = num($d['OtherDeductions']);
            $total = round2($total + $ee);
            $rowsHtml .= '<tr>'
                . '<td>' . esc($e['PagIBIG'] ?? '') . '</td><td></td><td></td>'
                . '<td>' . esc(strtoupper((string) ($e['LastName'] ?? ''))) . '</td>'
                . '<td>' . esc(strtoupper((string) ($e['FirstName'] ?? ''))) . '</td>'
                . '<td class="c">' . esc(($e['Suffix'] ?? '') !== '' ? strtoupper($e['Suffix']) : 'N/A') . '</td>'
                . '<td>' . esc(strtoupper((string) ($e['MiddleName'] ?? ''))) . '</td>'
                . '<td class="c">' . esc($pv) . '</td>'
                . '<td class="r">&#8369; ' . money($e['MonthlyRate'] ?? 0) . '</td>'
                . '<td class="r">' . ($ee > 0 ? money($ee) : '') . '</td>'
                . '<td></td><td></td></tr>';
        } else {
            $rowsHtml .= '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td>'
                . '<td></td><td></td><td></td><td></td><td></td><td></td></tr>';
        }
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Pag-IBIG - ' . esc($h['PayrollNo']) . '</title><style>'
        . '@page{size:legal landscape;margin:0.4in;}'
        . 'body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;margin:0;}'
        . '.sheet{width:12.4in;margin:0 auto;}'
        . '.emp{margin:0 0 10px;}'
        . '.emp div{display:flex;line-height:1.8;}'
        . '.emp b.k{width:2.4in;font-weight:bold;}'
        . 'table.g{width:100%;border-collapse:collapse;table-layout:fixed;}'
        . 'table.g th,table.g td{border:1px solid #000;padding:4px 6px;font-size:9.5pt;'
        . 'overflow:hidden;height:22px;}'
        . 'table.g th{background:#d9d9d9;text-align:center;font-weight:bold;'
        . 'vertical-align:middle;white-space:normal;line-height:1.1;}'
        . '.c{text-align:center}.r{text-align:right}'
        . 'tr.total td{font-weight:bold;border-top:2px solid #000;}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:6px;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . reviewOverlayHtml((string) ($h['Status'] ?? ''))
        . '<div class="sheet">'

        . '<div class="emp">'
        . '<div><b class="k">Employer ID No.</b><span><b>'
        . esc($s['PagibigEmployerId'] ?? '') . '</b></span></div>'
        . '<div><b class="k">Employer/Business Name</b><span><b>'
        . esc(($s['GovernmentName'] ?? '') . ' - ' . ($b['office']['OfficeName'] ?? $h['OfficeCode']))
        . '</b></span></div>'
        . '<div><b class="k">Employer/Business Address</b><span>'
        . esc($s['GovernmentAddress'] ?? '') . '</span></div>'
        . '<div><b class="k">Contact Number</b><span>' . esc($s['GovernmentContact'] ?? '') . '</span></div>'
        . '<div><b class="k">Email Address</b><span>' . esc($s['GovernmentEmail'] ?? '') . '</span></div>'
        . '</div>'

        . '<table class="g"><colgroup>'
        . '<col style="width:13%"><col style="width:7%"><col style="width:9%">'
        . '<col style="width:9%"><col style="width:9%"><col style="width:7%">'
        . '<col style="width:9%"><col style="width:6.5%"><col style="width:10%">'
        . '<col style="width:8%"><col style="width:7%"><col style="width:5.5%">'
        . '</colgroup><thead><tr>'
        . '<th>Pag-IBIG MID NO.</th><th>MP2 ACCOUNT NO.</th><th>MEMBERSHIP PROGRAM</th>'
        . '<th>LAST NAME</th><th>FIRST NAME</th><th>NAME EXTENSION</th><th>MIDDLE NAME</th>'
        . '<th>PERCOV</th><th>MONTHLY COMPENSATION</th><th>EE SHARE</th><th>ER SHARE</th>'
        . '<th>REMARKS</th></tr></thead><tbody>' . $rowsHtml
        . '<tr class="total"><td colspan="9" class="r">TOTAL</td>'
        . '<td class="r">&#8369; ' . money($total) . '</td><td></td><td></td></tr>'
        . '</tbody></table>'

        . '<div class="foot">Payroll No.: ' . esc($h['PayrollNo'])
        . ' &nbsp;|&nbsp; Period: ' . esc($pv)
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i') . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}

/* ==========================================================================
 * General Form No. 30-A - Summary of Payroll
 * ======================================================================== */

/**
 * Summary of Payroll (GF 30-A, portrait): heading block, recommending
 * approval amount, APPROVED BY signatories and the four numbered
 * certifications with amount in words.
 */
function buildSummaryHtml(string $payrollNo, array $user): string
{
    $b = printBundle($payrollNo, $user);
    $s = $b['s'];
    $h = $b['header'];
    $pd = $b['period'];

    $net = num($h['TotalNet']);
    $words = pesoWords($net);
    $periodText = !empty($pd['StartDate'])
        ? fmtDate($pd['StartDate'], 'F j') . ' - ' . fmtDate($pd['EndDate'], 'j, Y')
        : trim(($pd['PayrollMonth'] ?? '') . ' ' . ($pd['PayrollYear'] ?? ''));
    $approvedDate = $h['ApprovedAt'] ? fmtDate($h['ApprovedAt'], 'F j, Y') : '';

    $treasurer = $s['SignatoryCertifiedBy'] ?? '';
    $treasurerTitle = ($s['SignatoryCertifiedByTitle'] ?? '') !== ''
        ? $s['SignatoryCertifiedByTitle'] : 'City Treasurer';
    $mayor = $s['SignatoryApprovedBy'] ?? '';
    $mayorTitle = ($s['SignatoryApprovedByTitle'] ?? '') !== ''
        ? $s['SignatoryApprovedByTitle'] : 'City Mayor';
    $deptHead = $b['office']['OfficeHead'] ?? '';

    $sig = fn(string $name, string $title) =>
        '<div class="signame">' . ($name !== '' ? esc($name) : '&nbsp;') . '</div>'
        . '<div class="sigtitle">' . esc($title) . '</div>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Summary of Payroll - ' . esc($h['PayrollNo']) . '</title><style>'
        . '@page{size:letter portrait;margin:0.6in 0.7in;}'
        . 'body{font-family:"Times New Roman",serif;font-size:10.5pt;color:#000;margin:0;}'
        . '.sheet{width:7in;margin:0 auto;}'
        . '.gfrow{display:flex;justify-content:space-between;font-size:9.5pt;}'
        . 'h2{text-align:center;font-size:12pt;letter-spacing:.5px;margin:16px 0;}'
        . '.fline{display:flex;margin:5px 0;}'
        . '.fline .k{width:2.1in;}'
        . '.fline .v{flex:1;border-bottom:1px solid #000;font-weight:bold;padding-left:4px;}'
        . '.dbl{border-top:3px double #000;margin:10px 0;}'
        . '.reco{text-align:center;font-weight:bold;margin:14px 0 6px;}'
        . '.amt{width:1.6in;border-bottom:2px solid #000;font-weight:bold;padding:0 4px;}'
        . '.appr{margin:12px 0 4px;}'
        . '.two{display:flex;gap:24px;}'
        . '.two>div{flex:1;text-align:center;}'
        . '.signame{font-weight:bold;text-decoration:underline;text-transform:uppercase;'
        . 'margin-top:30px;}'
        . '.sigtitle{font-size:9.5pt;}'
        . '.certs{display:flex;gap:24px;margin-top:10px;}'
        . '.certs>div{flex:1;}'
        . '.cert{font-size:9.5pt;text-align:justify;line-height:1.5;margin-bottom:16px;}'
        . '.cert .u{font-weight:bold;text-decoration:underline;}'
        . '.pnum{margin:6px 0;}'
        . '.botsig{display:flex;gap:24px;margin-top:18px;text-align:center;font-size:9.5pt;}'
        . '.botsig>div{flex:1;border-top:1px solid #000;margin:0 12px;padding-top:1px;}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:10px;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . reviewOverlayHtml((string) ($h['Status'] ?? ''))
        . '<div class="sheet">'

        . '<div class="gfrow"><span>General Form No. 30-A<br>Revised: April, 1985</span>'
        . '<span>Voucher No: ____________________</span></div>'
        . '<h2>SUMMARY OF PAYROLL</h2>'

        . '<div class="fline"><span class="k">City / Municipality / Province:</span>'
        . '<span class="v">' . esc($s['GovernmentSubtitle'] ?? '') . '</span></div>'
        . '<div class="fline"><span class="k">For the period from:</span>'
        . '<span class="v">' . esc($periodText) . '</span></div>'
        . '<div class="fline"><span class="k">Project :</span>'
        . '<span class="v">' . esc($h['Remarks'] !== '' ? $h['Remarks']
            : 'Doing various works from time to time') . '</span></div>'

        . '<div class="dbl"></div>'
        . '<div class="reco">RECOMMENDING APPROVAL</div>'
        . '<div class="amt">P ' . money($net) . '</div>'
        . '<div class="appr">APPROVED BY:</div>'
        . '<div class="two"><div>' . $sig($treasurer, $treasurerTitle) . '</div>'
        . '<div>' . $sig($mayor, $mayorTitle) . '</div></div>'
        . '<div class="dbl"></div>'

        . '<div class="certs"><div>'
        . '<div class="cert">(1) I CERTIFY that the attached Payrolls, a summary of which '
        . 'appears hereon are just and correct the services rendered under my direction '
        . 'and approved payment on the day of<br><span class="u">'
        . ($approvedDate !== '' ? esc($approvedDate) : '_______________________') . '</span></div>'
        . '<div style="text-align:center">' . $sig($deptHead, 'Department Head/Head of Office') . '</div>'
        . '<div class="cert" style="margin-top:12px">(2) I HEREBY CERTIFY that the voucher '
        . 'has been pre-audited and the same may be paid in the amount of<br>'
        . '<span class="u">' . esc($words) . '</span>'
        . '<div class="pnum">(P ' . money($net) . ')</div></div>'
        . '</div><div>'
        . '<div class="cert">(3) I CERTIFY on my Official Oath that the attached Payrolls, '
        . 'summary of which approved hereon are the best of my knowledge and belief proper '
        . 'and correct, the same as being chargeable to the appropriation set aside '
        . 'therefore I CERTIFY FURTHER that the payment thereon have been made the total '
        . 'amount of<br><span class="u">' . esc($words) . '</span>'
        . '<div class="pnum">(P ' . money($net) . ')</div></div>'
        . '<div style="text-align:center">' . $sig($treasurer, $treasurerTitle) . '</div>'
        . '<div class="cert" style="margin-top:12px">(4) To be accomplished when payment of '
        . 'payroll was made without pre-audit, PASSES in the same amount<br>'
        . '<span class="u">' . esc($words) . '</span>'
        . '<div class="pnum">(P ' . money($net) . ')&nbsp; credit until my hand in cash book '
        . 'for this amount.</div></div>'
        . '</div></div>'

        . '<div class="botsig"><div>Province / City / Bureau / Auditor</div>'
        . '<div>Provincial Auditor</div></div>'

        . '<div class="foot">Payroll No.: ' . esc($h['PayrollNo'])
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i') . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}

/* ==========================================================================
 * CAFOA - Certification on Appropriations, Funds and Obligation of Allotment
 * ======================================================================== */

/**
 * CAFOA (portrait): request/payee block, allotment table, amount in words,
 * requesting official, the three certifications (Budget Officer, Treasurer,
 * Accountant) and the Subsidiary Ledger table.
 */
function buildCafoaHtml(string $payrollNo, array $user): string
{
    $b = printBundle($payrollNo, $user);
    $s = $b['s'];
    $h = $b['header'];
    $pd = $b['period'];

    $net = num($h['TotalNet']);
    $periodLabel = trim(($pd['PayrollMonth'] ?? '') . ' ' . ($pd['PayrollYear'] ?? ''));

    // Payee: the employee for single-line payrolls, the office roster otherwise.
    if (count($b['details']) === 1) {
        $payee = $b['details'][0]['EmployeeName'];
    } else {
        $payee = 'Various JO/COS Personnel - ' . ($b['office']['OfficeName'] ?? $h['OfficeCode']);
    }

    // The printed seal is its own upload; it falls back to the screen logo so
    // an installation that only ever set one keeps printing what it printed
    // before PrintLogoUrl existed.
    $logo = trim((string) ($s['PrintLogoUrl'] ?? ''));
    if ($logo === '') $logo = trim((string) ($s['OfficeLogoUrl'] ?? ''));

    /**
     * The seal's frame in the header.
     *
     * Wider than tall, with object-fit:contain, so the uploaded file fills the
     * frame whatever its shape: a square crest is limited by the height and
     * lands at 62px as before, while a wide logo gets the extra width instead
     * of being shrunk to fit a square. The height is what sets the header's
     * height, so it stays at 62px - the rest of the form's vertical geometry is
     * measured against it.
     *
     * SEAL_FRAME_W is used for both the frame and the balancing spacer on the
     * right; the title only sits centred while those two agree, which is why
     * it is one value here rather than a literal in three places.
     */
    $sealFrame = ['w' => 78, 'h' => 62];
    $spacer = '<div style="width:' . $sealFrame['w'] . 'px"></div>';
    $deptHead = $b['office']['OfficeHead'] ?? '';

    // Function/PPA charged in the allotment table. The payroll header wins:
    // one office can charge several functions, so the preparer's choice is
    // recorded on the payroll itself rather than derived. It falls back to the
    // function configured on the office, which is what a payroll saved before
    // that field was captured needs - otherwise the cell prints blank and gets
    // filled in by hand.
    $function = trim((string) ($h['Function'] ?? ''));
    if ($function === '') {
        $function = trim((string) ($b['office']['Function'] ?? ''));
    }
    if ($function === '') {
        $function = employeesFunction($b['employees']);
    }
    $function = functionLabel($function);

    // The cell must never print blank. The office code is always set on a
    // payroll, so it is the one value that is always available - and it points
    // the budget officer at the office whose function is unrecorded, which is
    // both the fix and something an empty cell cannot tell them.
    if ($function === '') {
        $function = trim((string) ($h['OfficeCode'] ?? ''));
    }

    $cert = fn(string $text, string $name, string $title) =>
        '<div class="cbox"><b>Certification:</b>'
        . '<p class="ci"><i>' . $text . '</i></p>'
        . '<div class="csig"><span class="signame">' . ($name !== '' ? esc($name) : '&nbsp;')
        . '</span><div class="crow"><span class="sigtitle">' . esc($title)
        . '</span><span class="date">Date</span></div></div></div>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>CAFOA - ' . esc($h['PayrollNo']) . '</title><style>'
        . '@page{size:letter portrait;margin:0.5in 0.6in;}'
        . 'body{font-family:"Times New Roman",serif;font-size:10pt;color:#000;margin:0;}'
        . '.sheet{width:7.3in;margin:0 auto;border:2px solid #000;}'
        . '.head{display:flex;align-items:center;gap:10px;padding:6px 10px;}'
        . '.head img{width:' . $sealFrame['w'] . 'px;height:' . $sealFrame['h']
        . 'px;object-fit:contain;}'
        . '.head .t{flex:1;text-align:center;font-weight:bold;}'
        . '.head .t .g{font-size:12pt;}'
        . '.head .t .f{font-size:11pt;}'
        . '.main{display:flex;border-top:2px solid #000;}'
        . '.left{width:46%;border-right:1px solid #000;padding:6px 8px;}'
        . '.right{width:54%;}'
        . '.fld{display:flex;margin:9px 0;}'
        . '.fld .k{width:1.1in;font-weight:bold;}'
        . '.fld .v{flex:1;border-bottom:1px solid #000;font-weight:bold;padding-left:4px;}'
        . 'table.al{width:100%;border-collapse:collapse;margin:8px 0;}'
        . 'table.al th,table.al td{border:1px solid #000;padding:3px 5px;font-size:9.5pt;'
        . 'text-align:center;height:19px;}'
        . 'table.al th{font-weight:bold;}'
        . '.reqoff{margin-top:14px;}'
        . '.signame{font-weight:bold;text-decoration:underline;text-transform:uppercase;}'
        . '.sigtitle{font-size:9pt;}'
        . '.obl{border-bottom:1px solid #000;padding:6px 8px;}'
        . '.obl .row{display:flex;justify-content:space-between;margin:2px 0;}'
        . '.cbox{border-bottom:1px solid #000;padding:12px 12px;min-height:1.35in;}'
        . '.cbox:last-child{border-bottom:none;}'
        . '.ci{margin:4px 0 8px;font-size:9.5pt;}'
        . '.csig{text-align:center;margin-top:26px;}'
        . '.crow{display:flex;justify-content:space-between;padding:0 8px;}'
        . '.crow .date{font-size:9pt;}'
        . '.ledger-title{text-align:center;font-weight:bold;border-top:2px solid #000;'
        . 'border-bottom:2px solid #000;padding:2px;}'
        . 'table.sl{width:100%;border-collapse:collapse;}'
        . 'table.sl th,table.sl td{border:1px solid #000;padding:3px 5px;font-size:9.5pt;'
        . 'text-align:center;height:19px;}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:6px;text-align:center;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . reviewOverlayHtml((string) ($h['Status'] ?? ''))
        . '<div class="sheet">'

        . '<div class="head">'
        . ($logo !== '' ? '<img src="' . esc($logo) . '" alt="Seal">' : $spacer)
        . '<div class="t"><div class="g">' . esc($s['GovernmentName'] ?? 'CITY GOVERNMENT OF DIGOS')
        . '</div><div class="f">CERTIFICATION ON APPROPRIATIONS, FUNDS AND<br>'
        . 'OBLIGATION OF ALLOTMENT</div></div>'
        . $spacer . '</div>'

        . '<div class="main"><div class="left">'
        . '<div class="fld"><span class="k">Request</span><span class="v">'
        . esc($h['Remarks'] !== '' ? $h['Remarks'] : 'Other General Services') . '</span></div>'
        . '<div class="fld"><span class="k">Payee</span><span class="v">' . esc($payee) . '</span></div>'
        . '<table class="al"><tr><th>Function</th><th>Allotment</th><th>Expense Code</th>'
        . '<th>Amount</th></tr>'
        . '<tr><td>' . esc($function) . '</td><td>' . CAFOA_ALLOTMENT . '</td>'
        . '<td>' . esc($s['CafoaExpenseCode'] ?? '') . '</td>'
        . '<td style="text-align:right">' . money($net) . '</td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td></tr></table>'
        . '<div class="fld"><span class="k">Total amount requested</span>'
        . '<span class="v">&#8369; ' . money($net) . '</span></div>'
        . '<div class="fld"><span class="k">Amount in Words:</span>'
        . '<span class="v">' . esc(pesoWords($net)) . '</span></div>'
        . '<div class="reqoff">Requesting Official:'
        . '<div class="csig"><span class="signame">'
        . ($deptHead !== '' ? esc($deptHead) : '&nbsp;') . '</span>'
        . '<div class="sigtitle">Department Head / Head of Office</div>'
        . '<div style="margin-top:8px;border-bottom:1px solid #000;display:inline-block;'
        . 'min-width:1.6in;font-weight:bold">' . fmtDate($h['DateCreated'], 'F j, Y') . '</div>'
        . '<div class="sigtitle">Date</div></div></div>'
        . '</div>'

        . '<div class="right">'
        . '<div class="obl"><div class="row"><span><b>Obligation No.:</b></span></div>'
        . '<div class="row"><span><b>Approved Amount:</b></span><span style="margin-right:1in">'
        . money($net) . '</span></div></div>'
        . $cert('I hereby certify as to the existence of appropriations',
            $s['SignatoryBudgetOfficer'] ?? '',
            ($s['SignatoryBudgetOfficerTitle'] ?? '') !== ''
                ? $s['SignatoryBudgetOfficerTitle'] : 'City Budget Officer')
        . $cert('I hereby certify as to the availability of funds for the expenditures in the '
            . 'amount specified herein:',
            $s['SignatoryCertifiedBy'] ?? '',
            ($s['SignatoryCertifiedByTitle'] ?? '') !== ''
                ? $s['SignatoryCertifiedByTitle'] : 'City Treasurer')
        . $cert('I hereby certify that the allotments are available for obligation in the '
            . 'amount specified herein:',
            $s['SignatoryFundsAvailable'] ?? '',
            ($s['SignatoryFundsAvailableTitle'] ?? '') !== ''
                ? $s['SignatoryFundsAvailableTitle'] : 'City Accountant')
        . '</div></div>'

        . '<div class="ledger-title">Subsidiary Ledger</div>'
        . '<table class="sl"><tr><th style="width:12%">Date</th>'
        . '<th style="width:34%">Particulars /Reference</th><th style="width:14%">Liquidations</th>'
        . '<th style="width:26%">Obligation Increase (Decrease)</th><th style="width:14%">Balance</th></tr>'
        . '<tr><td></td><td>' . esc($periodLabel) . '</td><td></td>'
        . '<td style="text-align:right">' . money($net) . '</td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>'
        . '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr></table>'
        . '</div>'

        . '<div class="foot">Payroll No.: ' . esc($h['PayrollNo'])
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i') . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</body></html>';
}
