<?php
/**
 * ============================================================================
 * Reports.php - Dashboard aggregation and the 10-report engine.
 * Uniform report shape: {title, columns, rows, totals, filters, generatedAt}.
 * Mirrors src/Reports.gs + the dashboard endpoint from src/Code.gs.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Workflow\PayrollWorkflow;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;

/* ==========================================================================
 * Dashboard
 * ======================================================================== */

/** Stat cards, chart series and the recent transaction feed. */
function apiGetDashboard(array $p, array $user): array
{
    // Every figure below is the caller's own scope, not the city's. This
    // screen showed citywide headcount and citywide money to anyone holding
    // dashboard.view, which is every role - an office-scoped user could not
    // open another office's payroll but could read its totals off the front
    // page. The citywide view returns in Phase 9, behind its own explicit
    // VIEW_CITYWIDE_AGGREGATE permission rather than by default.
    $counts = EmployeeRepo::countsScoped($user);

    $payrolls = array_map('aliasFunctionOut', PayrollRepo::listScoped($user, []));
    // Processed = passed pre-audit sign-off (Phase 7's OFFICIAL states); pending
    // is everything short of that and not yet cancelled - Draft, awaiting
    // pre-audit, suspended or returned all still need something done to them.
    $pending = array_filter($payrolls, fn($x) => !PayrollWorkflow::isOfficial($x['Status'])
        && $x['Status'] !== 'CANCELLED');
    $processed = array_filter($payrolls, fn($x) => PayrollWorkflow::isOfficial($x['Status']));

    // Monthly net/gross series for the last 12 period-months.
    $monthlyRows = PayrollRepo::monthlyTotalsScoped($user);
    $monthly = array_reverse(array_map(fn($m) => [
        'label' => substr((string) $m['PayrollMonth'], 0, 3) . ' ' . $m['PayrollYear'],
        'gross' => round2($m['gross']),
        'net' => round2($m['net']),
        'count' => (int) $m['count'],
    ], $monthlyRows));

    $statusSplit = [];
    foreach (PayrollWorkflow::ALL as $s) {
        $statusSplit[] = ['label' => $s,
            'value' => count(array_filter($payrolls, fn($x) => $x['Status'] === $s))];
    }

    usort($payrolls, fn($a, $b) => strcmp((string) $b['DateCreated'], (string) $a['DateCreated']));

    return [
        'stats' => [
            'totalEmployees' => (int) $counts['total'],
            'activeJO' => (int) $counts['jo'],
            'activeCOS' => (int) $counts['cos'],
            // Structure, not payroll data: how many offices the city has is
            // not a fact about anyone's pay, and the office list is already in
            // every filter dropdown.
            'departments' => ReferenceRepo::structureCounts()['departments'],
            'offices' => ReferenceRepo::structureCounts()['offices'],
            'payrollCount' => count($payrolls),
            'pendingPayroll' => count($pending),
            'processedPayroll' => count($processed),
            'pendingAmount' => round2(array_sum(array_map(fn($x) => num($x['TotalNet']), $pending))),
            'processedAmount' => round2(array_sum(array_map(fn($x) => num($x['TotalNet']), $processed))),
        ],
        'employmentSplit' => [
            ['label' => 'Job Order', 'value' => (int) $counts['jo']],
            ['label' => 'Contract of Service', 'value' => (int) $counts['cos']],
        ],
        'statusSplit' => $statusSplit,
        'monthly' => $monthly,
        'recent' => array_slice($payrolls, 0, 10),
    ];
}

/* ==========================================================================
 * Report engine
 * ======================================================================== */

/** Runs a report. Payload: {type, PeriodID?, OfficeCode?, Department?, EmployeeID?} */
function apiRunReport(array $p, array $user): array
{
    requireFields($p, ['type']);
    $ctx = reportContext($p, $user);

    $report = match ($p['type']) {
        'monthly' => monthlyReport($ctx),
        'office' => groupedReport('Office Payroll Report', 'OfficeCode', 'Office', $ctx),
        'department' => groupedReport('Department Payroll Report', 'Department', 'Department', $ctx),
        'summary' => summaryReport($ctx),
        'history' => historyReport($p, $ctx, $user),
        'register' => reportShape('Payroll Register', detailColumns(),
            array_map('detailRow', $ctx['details'])),
        'journal' => journalReport($ctx),
        'gross', 'deduction', 'net' => payComponentReport($p['type'], $ctx),
        default => throw new RuntimeException('Unknown report type: ' . $p['type']),
    };

    $report['generatedAt'] = date('m/d/Y H:i');
    $report['filters'] = describeFilters($p, $ctx, $user);
    return $report;
}

/**
 * Loads payroll headers + detail lines pre-filtered by the payload,
 * excluding Cancelled payrolls, with period labels attached.
 */
function reportContext(array $p, array $user): array
{
    $periods = [];
    // Periods are unscoped reference data: a period is a calendar fortnight,
 // not anybody's payroll, and every scoped user needs the label for their
 // own rows. Nothing about which offices used it is disclosed here.
    foreach (ReferenceRepo::periods() as $pd) $periods[$pd['PeriodID']] = $pd;

    // Scoped, both halves. The reports are the other way a payroll can be read
    // in bulk, and this one was missed when the list and print paths were
    // scoped - report.view is held by five of the seven roles, and every report
    // was running citywide regardless of the caller's grants.
    $headers = array_map('aliasFunctionOut', PayrollRepo::forReportingScoped($user, $p));

    $byNo = [];
    foreach ($headers as &$h) {
        $h['PeriodLabel'] = periodLabel($periods[$h['PeriodID']] ?? null);
        $byNo[$h['PayrollNo']] = $h;
    }
    unset($h);

    $details = [];
    foreach (PayrollRepo::detailsForReportingScoped(
        $user, array_keys($byNo), (string) ($p['EmployeeID'] ?? '')) as $d) {
        $h = $byNo[$d['PayrollNo']];
        $d['OfficeCode'] = $h['OfficeCode'];
        $d['Department'] = $h['Department'];
        $d['PeriodLabel'] = $h['PeriodLabel'];
        $d['PayrollStatus'] = $h['Status'];
        $details[] = $d;
    }
    return ['headers' => $headers, 'details' => $details, 'periods' => $periods];
}

/** "January 2026 (01/01-01/15)" */
function periodLabel(?array $pd): string
{
    if (!$pd) return '';
    return $pd['PayrollMonth'] . ' ' . $pd['PayrollYear']
        . ' (' . fmtDate($pd['StartDate'], 'm/d') . '-' . fmtDate($pd['EndDate'], 'm/d') . ')';
}

/** Human-readable filter line under the report title. */
function describeFilters(array $p, array $ctx, array $user): string
{
    $parts = [];
    if (!empty($p['PeriodID'])) $parts[] = 'Period: ' . periodLabel($ctx['periods'][$p['PeriodID']] ?? null);
    if (!empty($p['OfficeCode'])) $parts[] = 'Office: ' . $p['OfficeCode'];
    if (!empty($p['Department'])) $parts[] = 'Department: ' . $p['Department'];
    if (!empty($p['EmployeeID'])) {
        $e = EmployeeRepo::findScoped($user, (string) $p['EmployeeID']);
        if ($e) $parts[] = 'Employee: ' . fullName($e);
    }
    return $parts ? implode('  |  ', $parts) : 'All records';
}

/** Standard report shape + totals row. */
function reportShape(string $title, array $columns, array $rows): array
{
    $totals = [];
    foreach ($columns as $c) {
        if (empty($c['money'])) continue;
        $totals[$c['key']] = round2(array_sum(array_map(fn($r) => num($r[$c['key']] ?? 0), $rows)));
    }
    return ['title' => $title, 'columns' => $columns, 'rows' => array_values($rows), 'totals' => $totals];
}

/* ---- builders ---------------------------------------------------------- */

function monthlyReport(array $ctx): array
{
    $rows = $ctx['headers'];
    usort($rows, fn($a, $b) => strcmp($a['PayrollNo'], $b['PayrollNo']));
    return reportShape('Monthly Payroll Report', [
        ['key' => 'PayrollNo', 'label' => 'Payroll No.'],
        ['key' => 'PeriodLabel', 'label' => 'Period'],
        ['key' => 'OfficeCode', 'label' => 'Office'],
        ['key' => 'Department', 'label' => 'Department'],
        ['key' => 'Status', 'label' => 'Status'],
        ['key' => 'TotalGross', 'label' => 'Gross', 'money' => true],
        ['key' => 'TotalDeductions', 'label' => 'Deductions', 'money' => true],
        ['key' => 'TotalNet', 'label' => 'Net', 'money' => true],
    ], $rows);
}

/** Group-and-sum for the office / department reports. */
function groupedReport(string $title, string $field, string $label, array $ctx): array
{
    $groups = [];
    foreach ($ctx['headers'] as $h) {
        $key = $h[$field] ?: '(none)';
        $groups[$key] ??= ['Group' => $key, 'Payrolls' => 0, 'Employees' => 0,
            'TotalGross' => 0.0, 'TotalDeductions' => 0.0, 'TotalNet' => 0.0];
        $groups[$key]['Payrolls']++;
        $groups[$key]['TotalGross'] = round2($groups[$key]['TotalGross'] + num($h['TotalGross']));
        $groups[$key]['TotalDeductions'] = round2($groups[$key]['TotalDeductions'] + num($h['TotalDeductions']));
        $groups[$key]['TotalNet'] = round2($groups[$key]['TotalNet'] + num($h['TotalNet']));
    }
    foreach ($ctx['details'] as $d) {
        $key = $d[$field] ?: '(none)';
        if (isset($groups[$key])) $groups[$key]['Employees']++;
    }
    ksort($groups);
    return reportShape($title, [
        ['key' => 'Group', 'label' => $label],
        ['key' => 'Payrolls', 'label' => 'Payrolls'],
        ['key' => 'Employees', 'label' => 'Employee Lines'],
        ['key' => 'TotalGross', 'label' => 'Gross', 'money' => true],
        ['key' => 'TotalDeductions', 'label' => 'Deductions', 'money' => true],
        ['key' => 'TotalNet', 'label' => 'Net', 'money' => true],
    ], $groups);
}

function summaryReport(array $ctx): array
{
    $groups = [];
    foreach ($ctx['headers'] as $h) {
        $key = $h['PeriodLabel'] ?: $h['PeriodID'];
        $groups[$key] ??= ['PeriodLabel' => $key, 'Payrolls' => 0,
            'TotalGross' => 0.0, 'TotalDeductions' => 0.0, 'TotalNet' => 0.0];
        $groups[$key]['Payrolls']++;
        $groups[$key]['TotalGross'] = round2($groups[$key]['TotalGross'] + num($h['TotalGross']));
        $groups[$key]['TotalDeductions'] = round2($groups[$key]['TotalDeductions'] + num($h['TotalDeductions']));
        $groups[$key]['TotalNet'] = round2($groups[$key]['TotalNet'] + num($h['TotalNet']));
    }
    ksort($groups);
    return reportShape('Payroll Summary', [
        ['key' => 'PeriodLabel', 'label' => 'Period'],
        ['key' => 'Payrolls', 'label' => 'Payrolls'],
        ['key' => 'TotalGross', 'label' => 'Gross', 'money' => true],
        ['key' => 'TotalDeductions', 'label' => 'Deductions', 'money' => true],
        ['key' => 'TotalNet', 'label' => 'Net', 'money' => true],
    ], $groups);
}

function historyReport(array $p, array $ctx, array $user): array
{
    requireFields($p, ['EmployeeID']);
    $e = EmployeeRepo::findScoped($user, (string) $p['EmployeeID']);
    return reportShape('Employee Payroll History - ' . ($e ? fullName($e) : $p['EmployeeID']),
        detailColumns(), array_map('detailRow', $ctx['details']));
}

function journalReport(array $ctx): array
{
    $rows = array_map(fn($h) => [
        'Date' => fmtDate($h['DateCreated']),
        'PayrollNo' => $h['PayrollNo'],
        'Particulars' => 'Payroll - ' . $h['OfficeCode'] . ' (' . $h['PeriodLabel'] . ')',
        'Function' => $h['Function'] ?? '',
        'Status' => $h['Status'],
        'Debit' => $h['TotalGross'],
        'Withheld' => $h['TotalDeductions'],
        'Credit' => $h['TotalNet'],
    ], $ctx['headers']);
    usort($rows, fn($a, $b) => strcmp($a['PayrollNo'], $b['PayrollNo']));
    return reportShape('Payroll Journal', [
        ['key' => 'Date', 'label' => 'Date'],
        ['key' => 'PayrollNo', 'label' => 'Reference'],
        ['key' => 'Particulars', 'label' => 'Particulars'],
        ['key' => 'Function', 'label' => 'Fund'],
        ['key' => 'Status', 'label' => 'Status'],
        ['key' => 'Debit', 'label' => 'Gross (Dr)', 'money' => true],
        ['key' => 'Withheld', 'label' => 'Withheld (Cr)', 'money' => true],
        ['key' => 'Credit', 'label' => 'Net Paid (Cr)', 'money' => true],
    ], $rows);
}

/** Per-employee rollup for the gross / deduction / net reports. */
function payComponentReport(string $kind, array $ctx): array
{
    $config = [
        'gross' => ['title' => 'Gross Pay Report', 'cols' => [
            ['key' => 'GrossPay', 'label' => 'Gross Pay', 'money' => true]]],
        'deduction' => ['title' => 'Deduction Report', 'cols' => [
            ['key' => 'Tax', 'label' => 'Tax', 'money' => true],
            ['key' => 'CashAdvance', 'label' => 'Cash Advance', 'money' => true],
            ['key' => 'OtherDeductions', 'label' => 'Other', 'money' => true],
            ['key' => 'TotalDeductions', 'label' => 'Total Deductions', 'money' => true]]],
        'net' => ['title' => 'Net Pay Report', 'cols' => [
            ['key' => 'GrossPay', 'label' => 'Gross', 'money' => true],
            ['key' => 'TotalDeductions', 'label' => 'Deductions', 'money' => true],
            ['key' => 'NetPay', 'label' => 'Net Pay', 'money' => true]]],
    ][$kind];

    $moneyCols = ['GrossPay', 'Tax', 'CashAdvance', 'OtherDeductions', 'TotalDeductions', 'NetPay'];
    $groups = [];
    foreach ($ctx['details'] as $d) {
        $g = &$groups[$d['EmployeeID']];
        $g ??= ['EmployeeName' => $d['EmployeeName'], 'Position' => $d['Position'],
            'OfficeCode' => $d['OfficeCode'], 'Lines' => 0,
            'GrossPay' => 0.0, 'Tax' => 0.0, 'CashAdvance' => 0.0,
            'OtherDeductions' => 0.0, 'TotalDeductions' => 0.0, 'NetPay' => 0.0];
        $g['Lines']++;
        foreach ($moneyCols as $c) $g[$c] = round2($g[$c] + num($d[$c]));
        unset($g);
    }
    $rows = array_values($groups);
    usort($rows, fn($a, $b) => strcmp($a['EmployeeName'], $b['EmployeeName']));

    return reportShape($config['title'], array_merge([
        ['key' => 'EmployeeName', 'label' => 'Employee'],
        ['key' => 'Position', 'label' => 'Position'],
        ['key' => 'OfficeCode', 'label' => 'Office'],
        ['key' => 'Lines', 'label' => 'Payrolls'],
    ], $config['cols']), $rows);
}

/** Column set shared by register/history. */
function detailColumns(): array
{
    return [
        ['key' => 'PayrollNo', 'label' => 'Payroll No.'],
        ['key' => 'PeriodLabel', 'label' => 'Period'],
        ['key' => 'EmployeeName', 'label' => 'Employee'],
        ['key' => 'Position', 'label' => 'Position'],
        ['key' => 'SalaryRate', 'label' => 'Rate/Day', 'money' => true],
        ['key' => 'DaysWorked', 'label' => 'Days'],
        ['key' => 'GrossPay', 'label' => 'Gross', 'money' => true],
        ['key' => 'TotalDeductions', 'label' => 'Deductions', 'money' => true],
        ['key' => 'NetPay', 'label' => 'Net', 'money' => true],
    ];
}

/** Projects a detail record onto the register/history columns. */
function detailRow(array $d): array
{
    return [
        'PayrollNo' => $d['PayrollNo'], 'PeriodLabel' => $d['PeriodLabel'],
        'EmployeeName' => $d['EmployeeName'], 'Position' => $d['Position'],
        'SalaryRate' => $d['SalaryRate'], 'DaysWorked' => $d['DaysWorked'],
        'GrossPay' => $d['GrossPay'], 'TotalDeductions' => $d['TotalDeductions'],
        'NetPay' => $d['NetPay'],
    ];
}
