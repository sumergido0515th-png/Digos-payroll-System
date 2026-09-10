<?php
/**
 * ============================================================================
 * PrintForms/PayrollForm.php - the Payroll worksheet itself: the printed
 * form app/PrintDoc.php's own header describes ("DAILY WAGE OF PAYROLL").
 * One of seven files split out of what was a single 1374-line PrintDoc.php -
 * see that file's header for why. Reached only through buildFormHtml()'s
 * dispatch there; never called directly.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\EmployeeRepo;

/**
 * Builds the complete printable HTML document in the uploaded worksheet's
 * layout. Every value comes from the database; unset signatories stay blank.
 */
function buildPrintHtml(string $payrollNo, array $user, array $printMeta = []): string
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

            // RA 9994 senior citizens: the Pag-Ibig cell names the exemption
            // instead of a deduction amount. Gated on $withSensitive because
            // Birthdate is Tier 2, same as CashCard above.
            $isSenior = $withSensitive
                && isSeniorCitizen($b['employees'][$employeeId]['Birthdate'] ?? null);

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
                . ($isSenior
                    ? '<td class="c" style="font-size:6pt">Senior Citizen</td>'
                    : '<td class="r">' . ($l['pagibig'] > 0 ? money($l['pagibig']) : '') . '</td>')
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
        . ' &nbsp;|&nbsp; Status: ' . esc($h['Status'])
        . printStampHtml($h, $user, $printMeta) . '</span>'
        . '<span>Printed: ' . date('m/d/Y H:i') . '</span>'
        . '<img src="' . esc($qr) . '" width="42" height="42" alt="QR"></div>'

        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}
