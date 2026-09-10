<?php
/**
 * ============================================================================
 * PrintForms/SettlementReport.php - every settled (or waived) suspension
 * against one payroll, as one report. One of seven files split out of what
 * was a single 1374-line app/PrintDoc.php - see that file's header for why.
 * Reached only through buildFormHtml()'s dispatch there; never called
 * directly.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\SuspensionRepo;

/** Every settled (or waived) suspension against one payroll, as one report. */
function buildSettlementReportHtml(string $payrollNo, array $user, array $printMeta = []): string
{
    $header = PayrollRepo::findScoped($user, $payrollNo);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);
    $header = aliasFunctionOut($header);
    $office = aliasFunctionOut(ReferenceRepo::office((string) $header['OfficeCode']));
    $s = settingsMap();

    $settled = array_values(array_filter(
        SuspensionRepo::listScoped($user, ['PayrollNo' => $payrollNo]),
        fn(array $sus) => $sus['Status'] !== 'Open'
    ));

    $rows = '';
    foreach ($settled as $sus) {
        $rows .= '<tr><td>' . esc($sus['NsNo']) . '</td>'
            . '<td>' . esc($sus['GroundCode']) . '</td>'
            . '<td>' . esc($sus['EmployeeID'] ?? 'Batch-wide') . '</td>'
            . '<td>' . esc($sus['Status']) . '</td>'
            . '<td>' . fmtDate($sus['SettledAt'], 'm/d/Y') . '</td>'
            . '<td>' . esc($sus['SettledBy'] ?? '') . '</td>'
            . '<td>' . esc($sus['SettlementRef']) . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="c muted">No suspension against this payroll has been settled</td></tr>';
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Settlement Report - ' . esc($payrollNo) . '</title><style>'
        . '@page{size:letter landscape;margin:0.5in;}'
        . 'body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;margin:0;}'
        . 'h1{font-size:13pt;text-align:center;margin:0 0 2px;}'
        . 'h2{font-size:10pt;text-align:center;margin:0 0 12px;font-weight:normal;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #000;padding:4px 6px;font-size:9pt;text-align:left;}'
        . 'th{background:#eee;}'
        . '.c{text-align:center}.muted{color:#666;font-style:italic}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:10px;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . '<h1>' . esc($s['GovernmentName'] ?? 'CITY GOVERNMENT OF DIGOS') . '</h1>'
        . '<h2>SETTLEMENT REPORT</h2>'
        . '<p><b>Payroll No.:</b> ' . esc($payrollNo) . ' &nbsp;|&nbsp; <b>Office:</b> '
        . esc($office['OfficeName'] ?? $header['OfficeCode']) . '</p>'

        . '<table><tr><th>NS No.</th><th>Ground</th><th>Employee</th><th>Status</th>'
        . '<th>Settled On</th><th>Settled By</th><th>Settlement Reference</th></tr>'
        . $rows . '</table>'

        . '<div class="foot">Printed: ' . date('m/d/Y H:i') . printStampHtml($header, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</body></html>';
}
