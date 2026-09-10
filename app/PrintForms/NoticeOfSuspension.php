<?php
/**
 * ============================================================================
 * PrintForms/NoticeOfSuspension.php - the physical Notice of Suspension slip
 * a preparer is handed. One of seven files split out of what was a single
 * 1374-line app/PrintDoc.php - see that file's header for why. Reached only
 * through buildFormHtml()'s dispatch there; never called directly.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\EmployeeRepo;
use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\SuspensionRepo;

/** One Notice of Suspension, the physical slip a preparer is handed. */
function buildNoticeOfSuspensionHtml(string $nsNo, array $user, array $printMeta = []): string
{
    if ($nsNo === '') throw new RuntimeException('NsNo is required to print a Notice of Suspension.');

    $suspension = SuspensionRepo::find($nsNo);
    if (!$suspension) throw new RuntimeException('Suspension not found: ' . $nsNo);

    $header = PayrollRepo::findScoped($user, (string) $suspension['PayrollNo']);
    if (!$header) throw new RuntimeException('Suspension not found: ' . $nsNo);
    $header = aliasFunctionOut($header);
    $office = aliasFunctionOut(ReferenceRepo::office((string) $header['OfficeCode']));
    $s = settingsMap();

    $employeeLine = 'The entire batch';
    if (!empty($suspension['EmployeeID'])) {
        $employee = EmployeeRepo::findScoped($user, (string) $suspension['EmployeeID']);
        $employeeLine = $employee ? fullName($employee) . ' (' . $suspension['EmployeeID'] . ')'
            : (string) $suspension['EmployeeID'];
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>NS ' . esc($nsNo) . '</title><style>'
        . '@page{size:letter portrait;margin:0.6in;}'
        . 'body{font-family:Calibri,Arial,sans-serif;font-size:10.5pt;color:#000;margin:0;}'
        . 'h1{font-size:14pt;text-align:center;margin:0 0 2px;}'
        . 'h2{font-size:10.5pt;text-align:center;margin:0 0 16px;font-weight:normal;}'
        . '.kv{display:flex;margin:5px 0;}'
        . '.kv b{width:1.8in;}'
        . '.box{border:1px solid #000;padding:8px 10px;margin:10px 0;min-height:1.2in;}'
        . '.sigline{width:60%;margin:40px auto 0;border-bottom:1px solid #000;text-align:center;'
        . 'font-weight:bold;text-transform:uppercase;}'
        . '.sigcap{text-align:center;font-size:8.5pt;margin:1px 0 4px;}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:10px;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . '<h1>' . esc($s['GovernmentName'] ?? 'CITY GOVERNMENT OF DIGOS') . '</h1>'
        . '<h2>NOTICE OF SUSPENSION</h2>'

        . '<div class="kv"><b>NS No.:</b><span>' . esc($nsNo) . '</span></div>'
        . '<div class="kv"><b>Payroll No.:</b><span>' . esc((string) $header['PayrollNo']) . '</span></div>'
        . '<div class="kv"><b>Office:</b><span>' . esc($office['OfficeName'] ?? $header['OfficeCode']) . '</span></div>'
        . '<div class="kv"><b>Concerns:</b><span>' . esc($employeeLine) . '</span></div>'
        . '<div class="kv"><b>Ground:</b><span>' . esc((string) $suspension['GroundCode'])
        . ($suspension['RuleID'] ? ' (' . esc((string) $suspension['RuleID']) . ')' : '') . '</span></div>'
        . '<div class="kv"><b>Raised by:</b><span>' . esc((string) $suspension['RaisedBy']) . '</span></div>'
        . '<div class="kv"><b>Raised on:</b><span>' . fmtDate($suspension['RaisedAt'], 'm/d/Y H:i') . '</span></div>'
        . '<div class="kv"><b>Deadline:</b><span>'
        . ($suspension['Deadline'] ? fmtDate($suspension['Deadline'], 'm/d/Y') : 'None set') . '</span></div>'
        . '<div class="kv"><b>Status:</b><span>' . esc((string) $suspension['Status']) . '</span></div>'

        . '<p><b>Particulars:</b></p><div class="box">' . nl2br(esc((string) $suspension['Particulars'])) . '</div>'
        . '<p><b>Required action:</b></p><div class="box">'
        . nl2br(esc((string) $suspension['RequiredAction'])) . '</div>'

        . '<div class="sigline">&nbsp;</div><p class="sigcap">Pre-Auditor</p>'

        . '<div class="foot">Printed: ' . date('m/d/Y H:i') . printStampHtml($header, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</body></html>';
}
