<?php
/**
 * ============================================================================
 * PrintForms/PagibigForm.php - Membership Savings remittance list. One of
 * seven files split out of what was a single 1374-line app/PrintDoc.php -
 * see that file's header for why. Reached only through buildFormHtml()'s
 * dispatch there; never called directly.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\EmployeeRepo;

/**
 * Membership Savings remittance list: employer header block + one row per
 * employee with Pag-IBIG MID No., name parts, PERCOV, monthly compensation
 * and the EE share (the payroll line's Pag-IBIG deduction).
 */
function buildPagibigHtml(string $payrollNo, array $user, array $printMeta = []): string
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
            $remarks = isSeniorCitizen($e['Birthdate'] ?? null) ? 'Senior Citizen' : '';
            $rowsHtml .= '<tr>'
                . '<td>' . esc($e['PagIBIG'] ?? '') . '</td><td></td><td></td>'
                . '<td>' . esc(strtoupper((string) ($e['LastName'] ?? ''))) . '</td>'
                . '<td>' . esc(strtoupper((string) ($e['FirstName'] ?? ''))) . '</td>'
                . '<td class="c">' . esc(($e['Suffix'] ?? '') !== '' ? strtoupper($e['Suffix']) : 'N/A') . '</td>'
                . '<td>' . esc(strtoupper((string) ($e['MiddleName'] ?? ''))) . '</td>'
                . '<td class="c">' . esc($pv) . '</td>'
                . '<td class="r">&#8369; ' . money($e['MonthlyRate'] ?? 0) . '</td>'
                . '<td class="r">' . ($ee > 0 ? money($ee) : '') . '</td>'
                . '<td></td><td class="c">' . esc($remarks) . '</td></tr>';
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
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i')
        . printStampHtml($h, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}
