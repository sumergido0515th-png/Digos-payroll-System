<?php
/**
 * ============================================================================
 * PrintForms/CafoaForm.php - Certification on Appropriations, Funds and
 * Obligation of Allotment. One of seven files split out of what was a
 * single 1374-line app/PrintDoc.php - see that file's header for why.
 * Reached only through buildFormHtml()'s dispatch there; never called
 * directly.
 * ============================================================================
 */

declare(strict_types=1);

/**
 * CAFOA (portrait): request/payee block, allotment table, amount in words,
 * requesting official, the three certifications (Budget Officer, Treasurer,
 * Accountant) and the Subsidiary Ledger table.
 */
function buildCafoaHtml(string $payrollNo, array $user, array $printMeta = []): string
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
        . ' &nbsp;|&nbsp; Printed: ' . date('m/d/Y H:i')
        . printStampHtml($h, $user, $printMeta) . '</div>'
        . '<div class="noprint" style="text-align:center;margin:14px">'
        . '<button onclick="window.print()" style="padding:8px 24px;font-size:14px;cursor:pointer">'
        . 'Print / Save as PDF</button></div>'
        . '</body></html>';
}
