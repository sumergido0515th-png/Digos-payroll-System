<?php
/**
 * ============================================================================
 * PrintForms/Certification.php - the Pre-Audit Certification cover sheet.
 * One of seven files split out of what was a single 1374-line
 * app/PrintDoc.php - see that file's header for why. Reached only through
 * buildFormHtml()'s dispatch there; never called directly.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Rules\RuleEngine;
use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\SuspensionRepo;

/**
 * The paper trail behind an Official print: which rules ran, what they
 * found grouped by severity, the pre-auditor's identity and timestamp, and
 * the payload hash that ties this sheet to one exact state of the payroll.
 *
 * BLOCKER never has anything to list here by construction -
 * PayrollWorkflow::guardApproval() refuses the approval a BLOCKER would be
 * certifying instead of ever reaching PRE_AUDIT_APPROVED. WARNING findings
 * are what "proceeded with justification" means on this form: Phase 6 does
 * not store a separate override reason per finding, so the finding's own
 * message, next to the fact that pre-audit approved anyway, is the record.
 */
function buildCertificationHtml(string $payrollNo, array $user, array $printMeta = []): string
{
    $header = PayrollRepo::findScoped($user, $payrollNo);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);
    if (!payrollPrintIsOfficial((string) $header['Status'])) {
        throw new RuntimeException(
            'The Certification cover sheet exists only once a payroll has passed pre-audit '
            . 'approval. Print a Draft copy of the payroll itself to review it beforehand.');
    }
    $header = aliasFunctionOut($header);
    $office = aliasFunctionOut(ReferenceRepo::office((string) $header['OfficeCode']));
    $period = ReferenceRepo::period((string) $header['PeriodID']);
    $s = settingsMap();

    $findings = RuleEngine::validateToArray(preAuditContext($header, $user));
    $bySeverity = ['BLOCKER' => [], 'WARNING' => [], 'INFO' => []];
    foreach ($findings as $f) {
        $bySeverity[$f['Severity']][] = $f;
    }

    $suspensions = SuspensionRepo::listScoped($user, ['PayrollNo' => $payrollNo]);

    $findingTable = function (array $items, string $emptyLabel): string {
        if (!$items) {
            return '<tr><td colspan="3" class="c muted">' . esc($emptyLabel) . '</td></tr>';
        }
        $html = '';
        foreach ($items as $f) {
            $html .= '<tr><td>' . esc($f['RuleID']) . '</td>'
                . '<td>' . esc($f['EmployeeID'] !== '' ? $f['EmployeeID'] : 'Batch-wide') . '</td>'
                . '<td>' . esc($f['Message']) . '</td></tr>';
        }
        return $html;
    };

    $suspensionRows = '';
    foreach ($suspensions as $sus) {
        $suspensionRows .= '<tr><td>' . esc($sus['NsNo']) . '</td>'
            . '<td>' . esc($sus['GroundCode']) . '</td>'
            . '<td>' . esc($sus['EmployeeID'] ?? 'Batch-wide') . '</td>'
            . '<td>' . esc($sus['Status']) . '</td>'
            . '<td>' . fmtDate($sus['RaisedAt'], 'm/d/Y') . '</td>'
            . '<td>' . ($sus['SettledAt'] ? fmtDate($sus['SettledAt'], 'm/d/Y') : '') . '</td></tr>';
    }
    if ($suspensionRows === '') {
        $suspensionRows = '<tr><td colspan="6" class="c muted">None raised against this payroll</td></tr>';
    }

    return '<!DOCTYPE html><html><head><meta charset="utf-8">'
        . '<title>Certification - ' . esc($payrollNo) . '</title><style>'
        . '@page{size:letter portrait;margin:0.5in;}'
        . 'body{font-family:Calibri,Arial,sans-serif;font-size:10pt;color:#000;margin:0;}'
        . '.sheet{width:100%;}'
        . 'h1{font-size:13pt;text-align:center;margin:0 0 2px;}'
        . 'h2{font-size:10pt;text-align:center;margin:0 0 12px;font-weight:normal;}'
        . 'h3{font-size:10pt;background:#d9d9d9;padding:3px 6px;margin:14px 0 4px;}'
        . 'table{width:100%;border-collapse:collapse;margin-bottom:4px;}'
        . 'th,td{border:1px solid #000;padding:3px 6px;font-size:9pt;text-align:left;}'
        . 'th{background:#eee;}'
        . '.c{text-align:center}.muted{color:#666;font-style:italic}'
        . '.kv{display:flex;margin:2px 0;}'
        . '.kv b{width:2in;}'
        . '.foot{font-size:7.5pt;color:#333;margin-top:10px;}'
        . '@media print{.noprint{display:none}}'
        . reviewOverlayCss()
        . '</style></head><body>'
        . reviewOverlayHtml((string) $header['Status'])
        . '<div class="sheet">'
        . '<h1>' . esc($s['GovernmentName'] ?? 'CITY GOVERNMENT OF DIGOS') . '</h1>'
        . '<h2>PRE-AUDIT CERTIFICATION</h2>'

        . '<div class="kv"><b>Payroll No.:</b><span>' . esc($payrollNo) . '</span></div>'
        . '<div class="kv"><b>Office:</b><span>' . esc($office['OfficeName'] ?? $header['OfficeCode']) . '</span></div>'
        . '<div class="kv"><b>Period:</b><span>'
        . esc(trim(($period['PayrollMonth'] ?? '') . ' ' . ($period['PayrollYear'] ?? ''))) . '</span></div>'
        . '<div class="kv"><b>Status:</b><span>' . esc($header['Status']) . '</span></div>'

        . '<h3>BLOCKER findings (must be none)</h3>'
        . '<table><tr><th>Rule</th><th>Employee</th><th>Finding</th></tr>'
        . $findingTable($bySeverity['BLOCKER'], 'None') . '</table>'

        . '<h3>WARNING findings - proceeded with justification</h3>'
        . '<table><tr><th>Rule</th><th>Employee</th><th>Finding</th></tr>'
        . $findingTable($bySeverity['WARNING'], 'None') . '</table>'

        . '<h3>INFO findings</h3>'
        . '<table><tr><th>Rule</th><th>Employee</th><th>Finding</th></tr>'
        . $findingTable($bySeverity['INFO'], 'None') . '</table>'

        . '<h3>Suspensions raised against this payroll</h3>'
        . '<table><tr><th>NS No.</th><th>Ground</th><th>Employee</th><th>Status</th>'
        . '<th>Raised</th><th>Settled</th></tr>' . $suspensionRows . '</table>'

        . '<h3>Approval</h3>'
        . '<div class="kv"><b>Approved by:</b><span>' . esc($header['ApprovedBy'] ?: '(not yet approved)')
        . '</span></div>'
        . '<div class="kv"><b>Approved at:</b><span>'
        . ($header['ApprovedAt'] ? fmtDate($header['ApprovedAt'], 'm/d/Y H:i') : '') . '</span></div>'
        . '<div class="kv"><b>Payload hash:</b><span style="font-family:monospace;font-size:8pt">'
        . esc((string) ($header['PayloadHash'] ?? '')) . '</span></div>'

        . '<div class="foot">Generated: ' . date('m/d/Y H:i') . printStampHtml($header, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}
