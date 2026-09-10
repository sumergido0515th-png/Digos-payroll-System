<?php
/**
 * ============================================================================
 * PrintForms/SummaryForm.php - General Form No. 30-A, Summary of Payroll.
 * One of seven files split out of what was a single 1374-line
 * app/PrintDoc.php - see that file's header for why. Reached only through
 * buildFormHtml()'s dispatch there; never called directly.
 * ============================================================================
 */

declare(strict_types=1);

/**
 * Summary of Payroll (GF 30-A, portrait): heading block, recommending
 * approval amount, APPROVED BY signatories and the four numbered
 * certifications with amount in words.
 */
function buildSummaryHtml(string $payrollNo, array $user, array $printMeta = []): string
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
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i')
        . printStampHtml($h, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</div></body></html>';
}
