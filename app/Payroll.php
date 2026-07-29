<?php
/**
 * ============================================================================
 * Payroll.php - Periods, payroll transactions (max 15 employees), automatic
 * computation, workflow (Draft -> Pending -> Approved -> Released/Cancelled),
 * duplicate detection, sequential numbering and single-step undo.
 * Mirrors src/Payroll.gs.
 * ============================================================================
 */

declare(strict_types=1);

/** Legal workflow transitions, keyed by current status. */
const PAYROLL_FLOW = [
    'Draft' => ['Pending', 'Cancelled'],
    'Pending' => ['Approved', 'Draft', 'Cancelled'],
    'Approved' => ['Released', 'Cancelled'],
    'Released' => [],
    'Cancelled' => [],
];

/* ==========================================================================
 * Payroll periods
 * ======================================================================== */

/** Lists payroll periods, newest first. */
function apiListPeriods(array $p, array $user): array
{
    return array_values(array_filter(
        DB::rows('SELECT * FROM PayrollPeriods ORDER BY StartDate DESC'),
        function ($r) use ($p) {
            if (!empty($p['Status']) && $r['Status'] !== $p['Status']) return false;
            return rowMatches($r, ['PeriodID', 'PayrollMonth', 'PayrollYear'], $p['search'] ?? '');
        }));
}

/** Creates or updates a payroll period. */
function apiSavePeriod(array $p, array $user): array
{
    requireFields($p, ['PayrollMonth', 'PayrollYear', 'StartDate', 'EndDate']);
    if ($p['StartDate'] > $p['EndDate']) throw new RuntimeException('End Date must fall on or after Start Date.');
    $status = $p['Status'] ?? 'Open';
    if (!in_array($status, ['Open', 'Closed', 'Locked'], true)) {
        throw new RuntimeException('Status must be Open, Closed or Locked.');
    }

    $record = [
        'PayrollMonth' => $p['PayrollMonth'],
        'PayrollYear' => (int) $p['PayrollYear'],
        'StartDate' => $p['StartDate'],
        'EndDate' => $p['EndDate'],
        'Status' => $status,
    ];
    if (!empty($p['PeriodID']) &&
        DB::row('SELECT PeriodID FROM PayrollPeriods WHERE PeriodID = ?', [$p['PeriodID']])) {
        DB::update('PayrollPeriods', $record, 'PeriodID', $p['PeriodID']);
        return ['updated' => true, 'PeriodID' => $p['PeriodID']];
    }
    $record['PeriodID'] = newId('PRD');
    DB::insert('PayrollPeriods', $record);
    return ['created' => true, 'PeriodID' => $record['PeriodID']];
}

/** Deletes a period when no payroll references it. */
function apiDeletePeriod(array $p, array $user): array
{
    requireFields($p, ['PeriodID']);
    $used = (int) DB::scalar('SELECT COUNT(*) FROM Payroll WHERE PeriodID = ?', [$p['PeriodID']]);
    if ($used) throw new RuntimeException('Payroll transactions exist for this period. Close or lock it instead.');
    return ['deleted' => DB::exec('DELETE FROM PayrollPeriods WHERE PeriodID = ?', [$p['PeriodID']])];
}

/* ==========================================================================
 * Numbering & computation
 * ======================================================================== */

/**
 * Next sequential payroll number, e.g. "PR-2026-000001".
 * The Counters row is locked (FOR UPDATE) so concurrent saves cannot collide.
 * Must be called inside a transaction.
 */
function nextPayrollNo(): string
{
    $prefix = getSetting('PayrollPrefix', 'PR');
    $year = (int) date('Y');

    DB::exec('INSERT IGNORE INTO Counters (YearNo, LastNo) VALUES (?, 0)', [$year]);
    $last = (int) DB::scalar('SELECT LastNo FROM Counters WHERE YearNo = ? FOR UPDATE', [$year]);
    $next = $last + 1;
    DB::exec('UPDATE Counters SET LastNo = ? WHERE YearNo = ?', [$next, $year]);

    return sprintf('%s-%d-%06d', $prefix, $year, $next);
}

/** Computation settings, read once per request. */
function computationConfig(): array
{
    return [
        'otMultiplier' => num(getSetting('OvertimeMultiplier', '1.25')) ?: 1.25,
        'taxRate' => num(getSetting('DefaultTaxRate', '0')),
        'maxLines' => (int) num(getSetting('MaxEmployeesPerPayroll', '15')) ?: 15,
    ];
}

/**
 * Computes one payroll line (same formula as the Apps Script version):
 * earnings - time cuts = gross; gross - (tax + CA + other) = net.
 */
function computeLine(array $emp, array $line, array $cfg): array
{
    $daily = num($emp['DailyRate']);
    $hourly = num($emp['HourlyRate']) ?: $daily / 8;
    $perMin = $hourly / 60;

    $earnings = $daily * num($line['DaysWorked'] ?? 0)
        + $hourly * num($line['HoursWorked'] ?? 0)
        + $hourly * $cfg['otMultiplier'] * num($line['OvertimeHours'] ?? 0);

    $timeCuts = $perMin * (num($line['LateMinutes'] ?? 0) + num($line['UndertimeMinutes'] ?? 0))
        + $daily * num($line['AbsentDays'] ?? 0);

    $gross = max(0, round2($earnings - $timeCuts));

    $tax = (!isset($line['Tax']) || $line['Tax'] === '' || $line['Tax'] === null)
        ? round2($gross * $cfg['taxRate'] / 100)
        : round2($line['Tax']);

    $cashAdvance = round2($line['CashAdvance'] ?? 0);
    $others = round2($line['OtherDeductions'] ?? 0);
    $totalDeductions = round2($tax + $cashAdvance + $others);
    $net = round2($gross - $totalDeductions);

    return [
        'EmployeeID' => $emp['EmployeeID'],
        'EmployeeName' => fullName($emp),
        'Position' => $emp['Position'],
        'SalaryRate' => round2($daily),
        'DaysWorked' => num($line['DaysWorked'] ?? 0),
        'HoursWorked' => num($line['HoursWorked'] ?? 0),
        'OvertimeHours' => num($line['OvertimeHours'] ?? 0),
        'LateMinutes' => num($line['LateMinutes'] ?? 0),
        'UndertimeMinutes' => num($line['UndertimeMinutes'] ?? 0),
        'AbsentDays' => num($line['AbsentDays'] ?? 0),
        'GrossPay' => $gross,
        'Tax' => $tax,
        'CashAdvance' => $cashAdvance,
        'OtherDeductions' => $others,
        'TotalDeductions' => $totalDeductions,
        'NetPay' => $net,
        'Remarks' => $line['Remarks'] ?? '',
    ];
}

/** Sums money columns of computed lines. */
function payrollTotals(array $lines): array
{
    $t = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
    foreach ($lines as $l) {
        $t['gross'] += num($l['GrossPay']);
        $t['deductions'] += num($l['TotalDeductions']);
        $t['net'] += num($l['NetPay']);
    }
    return array_map('round2', $t);
}

/** Grid preview: computes every line without persisting. */
function apiComputePayroll(array $p, array $user): array
{
    $cfg = computationConfig();
    $lines = [];
    foreach ($p['lines'] ?? [] as $l) {
        $emp = DB::row('SELECT * FROM Employees WHERE EmployeeID = ?', [$l['EmployeeID'] ?? '']);
        if (!$emp) throw new RuntimeException('Employee not found: ' . ($l['EmployeeID'] ?? '?'));
        $lines[] = computeLine($emp, $l, $cfg);
    }
    return ['lines' => $lines, 'totals' => payrollTotals($lines)];
}

/* ==========================================================================
 * Payroll transactions
 * ======================================================================== */

/** Lists payrolls with search + filters, newest first. */
function apiListPayrolls(array $p, array $user): array
{
    $sql = 'SELECT * FROM Payroll WHERE 1=1';
    $params = [];
    foreach (['PeriodID', 'OfficeCode', 'Department', 'Status'] as $f) {
        if (!empty($p[$f])) { $sql .= " AND `$f` = ?"; $params[] = $p[$f]; }
    }
    if (!empty($p['search'])) {
        $sql .= ' AND (PayrollNo LIKE ? OR OfficeCode LIKE ? OR Department LIKE ? OR PreparedBy LIKE ? OR Remarks LIKE ?)';
        $like = '%' . $p['search'] . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY DateCreated DESC';
    return array_map('aliasFunctionOut', DB::rows($sql, $params));
}

/** One payroll with its lines. */
function apiGetPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    return [
        'header' => aliasFunctionOut($header),
        'details' => DB::rows('SELECT * FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo',
            [$p['PayrollNo']]),
    ];
}

/**
 * Creates or updates a payroll transaction (Draft/Pending only), recomputing
 * every line server-side inside one transaction.
 */
function apiSavePayroll(array $p, array $user): array
{
    requireFields($p, ['PeriodID', 'OfficeCode']);
    $cfg = computationConfig();

    $period = DB::row('SELECT * FROM PayrollPeriods WHERE PeriodID = ?', [$p['PeriodID']]);
    if (!$period) throw new RuntimeException('Payroll period not found.');
    if ($period['Status'] !== 'Open') {
        throw new RuntimeException('Payroll period is ' . $period['Status'] . '. Only Open periods accept payrolls.');
    }

    $office = DB::row('SELECT * FROM Offices WHERE OfficeCode = ?', [$p['OfficeCode']]);
    if (!$office) throw new RuntimeException('Office not found: ' . $p['OfficeCode']);

    $lines = $p['lines'] ?? [];
    if (!$lines) throw new RuntimeException('Add at least one employee to the payroll.');
    if (count($lines) > $cfg['maxLines']) {
        throw new RuntimeException('A payroll transaction may contain at most ' . $cfg['maxLines'] . ' employees.');
    }

    $ids = array_column($lines, 'EmployeeID');
    if (count($ids) !== count(array_unique($ids))) {
        throw new RuntimeException('The same employee appears twice in the grid.');
    }

    if (empty($p['allowDuplicates'])) {
        $clashes = duplicateEmployees($p['PeriodID'], $p['PayrollNo'] ?? '', $ids);
        if ($clashes) {
            throw new RuntimeException('Already on another payroll this period: '
                . implode('; ', $clashes) . '. Tick "Allow duplicates" to override.');
        }
    }

    $isNew = empty($p['PayrollNo']);
    $existing = $isNew ? null : DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$isNew) {
        if (!$existing) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
        if (!in_array($existing['Status'], ['Draft', 'Pending'], true)) {
            throw new RuntimeException('Only Draft or Pending payrolls can be edited. This one is '
                . $existing['Status'] . '.');
        }
    }

    return DB::tx(function () use ($p, $user, $cfg, $office, $lines, $isNew, $existing) {
        $payrollNo = $isNew ? nextPayrollNo() : $p['PayrollNo'];

        $computed = [];
        foreach (array_values($lines) as $i => $l) {
            $emp = DB::row('SELECT * FROM Employees WHERE EmployeeID = ?', [$l['EmployeeID'] ?? '']);
            if (!$emp) throw new RuntimeException('Employee not found: ' . ($l['EmployeeID'] ?? '?'));
            if ($emp['Status'] !== 'Active') {
                throw new RuntimeException(fullName($emp) . ' is ' . $emp['Status'] . ' and cannot be paid.');
            }
            $rec = computeLine($emp, $l, $cfg);
            $rec['DetailID'] = newId('PD');
            $rec['PayrollNo'] = $payrollNo;
            $rec['LineNo'] = $i + 1;
            // Charging defaults to the batch's office and function, never to
            // the employee's home office: where someone is assigned and which
            // appropriation pays them are different questions, and deriving
            // one from the other bills the wrong one silently. The columns are
            // per line so a later screen can override them individually - this
            // only stops them being written NULL in the meantime.
            $rec['ChargedOfficeCode'] = $p['OfficeCode'];
            $rec['FunctionCode'] = $office['FunctionCode'] ?? null;
            $computed[] = $rec;
        }
        $sums = payrollTotals($computed);

        $header = [
            'PeriodID' => $p['PeriodID'],
            'OfficeCode' => $p['OfficeCode'],
            'Department' => $office['Department'] ?? '',
            'FunctionName' => $office['FunctionName'] ?? '',
            'FunctionCode' => $office['FunctionCode'] ?? null,
            'TimekeeperID' => $p['TimekeeperID'] ?? '',
            // Both, and they are not redundant. PreparedBy is the name as
            // rendered on the printed form and must not drift; PreparedByUser
            // is the identity Phase 2's segregation-of-duties check reads,
            // which cannot be written against a display string.
            'PreparedBy' => $user['FullName'] ?: $user['Email'],
            'PreparedByUser' => $user['Email'],
            'Remarks' => $p['Remarks'] ?? '',
            'Status' => $existing ? $existing['Status'] : 'Draft',
            'TotalGross' => $sums['gross'],
            'TotalDeductions' => $sums['deductions'],
            'TotalNet' => $sums['net'],
        ];

        if ($isNew) {
            $header['PayrollNo'] = $payrollNo;
            DB::insert('Payroll', $header);
        } else {
            DB::update('Payroll', $header, 'PayrollNo', $payrollNo);
            DB::exec('DELETE FROM PayrollDetails WHERE PayrollNo = ?', [$payrollNo]);
        }
        foreach ($computed as $rec) DB::insert('PayrollDetails', $rec);

        setUndo(['action' => $isNew ? 'create' : 'edit', 'PayrollNo' => $payrollNo,
            'user' => $user['Email']]);
        return ['PayrollNo' => $payrollNo, 'created' => $isNew, 'totals' => $sums];
    });
}

/** Employees already on another non-cancelled payroll of the same period. */
function duplicateEmployees(string $periodId, string $exceptPayrollNo, array $employeeIds): array
{
    if (!$employeeIds) return [];
    $ph = implode(',', array_fill(0, count($employeeIds), '?'));
    $rows = DB::rows(
        "SELECT d.EmployeeName, d.PayrollNo
           FROM PayrollDetails d
           JOIN Payroll h ON h.PayrollNo = d.PayrollNo
          WHERE h.PeriodID = ? AND h.Status <> 'Cancelled' AND h.PayrollNo <> ?
            AND d.EmployeeID IN ($ph)",
        array_merge([$periodId, $exceptPayrollNo], array_values($employeeIds)));
    return array_map(fn($r) => $r['EmployeeName'] . ' (' . $r['PayrollNo'] . ')', $rows);
}

/** Deletes a Draft payroll and its lines. */
function apiDeletePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = DB::row('SELECT Status FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    if ($header['Status'] !== 'Draft') {
        throw new RuntimeException('Only Draft payrolls can be deleted. Cancel it instead.');
    }
    return DB::tx(function () use ($p) {
        DB::exec('DELETE FROM PayrollDetails WHERE PayrollNo = ?', [$p['PayrollNo']]);
        return ['deleted' => DB::exec('DELETE FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']])];
    });
}

/* ==========================================================================
 * Workflow
 * ======================================================================== */

/** Validated status transition + timestamps. */
function payrollTransition(string $payrollNo, string $to, array $user): array
{
    $header = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$payrollNo]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);

    $allowed = PAYROLL_FLOW[$header['Status']] ?? [];
    if (!in_array($to, $allowed, true)) {
        throw new RuntimeException('Cannot move a ' . $header['Status'] . " payroll to $to.");
    }

    $patch = ['Status' => $to];
    if ($to === 'Approved') {
        // As with PreparedBy on save: the display name for the printed form,
        // the key for the Phase 2 check that the approver is not the preparer.
        $patch['ApprovedBy'] = $user['FullName'] ?: $user['Email'];
        $patch['ApprovedByUser'] = $user['Email'];
        $patch['ApprovedAt'] = date('Y-m-d H:i:s');
    }
    if ($to === 'Released') $patch['ReleasedAt'] = date('Y-m-d H:i:s');

    DB::update('Payroll', $patch, 'PayrollNo', $payrollNo);
    setUndo(['action' => 'status', 'PayrollNo' => $payrollNo,
        'prevStatus' => $header['Status'], 'user' => $user['Email']]);
    return ['PayrollNo' => $payrollNo, 'Status' => $to];
}

function apiSubmitPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'Pending', $user);
}

function apiApprovePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'Approved', $user);
}

function apiReturnPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'Draft', $user);
}

function apiReleasePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'Released', $user);
}

function apiCancelPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'Cancelled', $user);
}

/* ==========================================================================
 * Undo
 * ======================================================================== */

/** Stores the single-step undo record (Settings key '_PayrollUndo'). */
function setUndo(array $rec): void
{
    $rec['at'] = date('c');
    setSetting('_PayrollUndo', json_encode($rec));
}

/** Reverts the current user's last create/status change. */
function apiUndoLast(array $p, array $user): array
{
    $raw = getSetting('_PayrollUndo', '');
    if (!$raw) throw new RuntimeException('Nothing to undo.');
    $rec = json_decode($raw, true) ?: [];
    if (($rec['user'] ?? '') !== $user['Email']) {
        throw new RuntimeException('The last change was made by ' . ($rec['user'] ?? 'unknown')
            . ' and can only be undone by them.');
    }

    if (($rec['action'] ?? '') === 'create') {
        $header = DB::row('SELECT Status FROM Payroll WHERE PayrollNo = ?', [$rec['PayrollNo']]);
        if (!$header) throw new RuntimeException('Payroll ' . $rec['PayrollNo'] . ' no longer exists.');
        if ($header['Status'] !== 'Draft') {
            throw new RuntimeException('Payroll has advanced past Draft; undo is no longer possible.');
        }
        DB::tx(function () use ($rec) {
            DB::exec('DELETE FROM PayrollDetails WHERE PayrollNo = ?', [$rec['PayrollNo']]);
            DB::exec('DELETE FROM Payroll WHERE PayrollNo = ?', [$rec['PayrollNo']]);
        });
        $result = ['undone' => 'create', 'PayrollNo' => $rec['PayrollNo']];
    } elseif (($rec['action'] ?? '') === 'status') {
        // The create branch checks this and this one did not, so undoing a
        // status change on a payroll that has since been deleted updated zero
        // rows and still reported success - the undo record outlives the row
        // it names. The live database is in that state right now.
        if (!DB::row('SELECT PayrollNo FROM Payroll WHERE PayrollNo = ?', [$rec['PayrollNo']])) {
            throw new RuntimeException('Payroll ' . $rec['PayrollNo'] . ' no longer exists.');
        }
        DB::update('Payroll', ['Status' => $rec['prevStatus']], 'PayrollNo', $rec['PayrollNo']);
        $result = ['undone' => 'status', 'PayrollNo' => $rec['PayrollNo'], 'Status' => $rec['prevStatus']];
    } else {
        throw new RuntimeException('The last change (an edit) replaced previous figures and cannot be undone.');
    }

    setSetting('_PayrollUndo', '');
    return $result;
}

/* ==========================================================================
 * Payslip email
 * ======================================================================== */

/** Emails HTML payslips to employees on an Approved/Released payroll. */
function apiEmailPayslips(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    if (!in_array($header['Status'], ['Approved', 'Released'], true)) {
        throw new RuntimeException('Payslips can only be emailed for Approved or Released payrolls.');
    }

    $gov = getSetting('GovernmentName', 'CITY GOVERNMENT OF DIGOS');
    $period = DB::row('SELECT * FROM PayrollPeriods WHERE PeriodID = ?', [$header['PeriodID']]) ?? [];
    $details = DB::rows('SELECT * FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo', [$p['PayrollNo']]);

    $sent = 0;
    $skipped = 0;
    foreach ($details as $d) {
        $emp = DB::row('SELECT Email FROM Employees WHERE EmployeeID = ?', [$d['EmployeeID']]);
        if (!$emp || !isEmail($emp['Email'])) { $skipped++; continue; }

        $rows = '';
        foreach ([['Gross Pay', $d['GrossPay']], ['Tax', $d['Tax']],
                  ['Cash Advance', $d['CashAdvance']], ['Other Deductions', $d['OtherDeductions']],
                  ['Total Deductions', $d['TotalDeductions']], ['NET PAY', $d['NetPay']]] as $r) {
            $rows .= '<tr><td style="padding:4px 12px;border:1px solid #ccc">' . esc($r[0])
                . '</td><td style="padding:4px 12px;border:1px solid #ccc;text-align:right">'
                . money($r[1]) . '</td></tr>';
        }
        $periodLabel = ($period['PayrollMonth'] ?? '') . ' ' . ($period['PayrollYear'] ?? '');
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px">'
            . '<h3 style="color:#0b3d91;margin-bottom:2px">' . esc($gov) . '</h3>'
            . '<p style="margin:2px 0">Payslip for <b>' . esc($d['EmployeeName']) . '</b> ('
            . esc($d['Position']) . ')</p>'
            . '<p style="margin:2px 0;color:#555">Payroll ' . esc($p['PayrollNo']) . ' &middot; '
            . esc($periodLabel) . ' &middot; ' . num($d['DaysWorked']) . ' day(s) worked</p>'
            . '<table style="border-collapse:collapse;margin-top:8px">' . $rows . '</table>'
            . '<p style="color:#888;font-size:11px;margin-top:12px">This is a system-generated payslip.</p></div>';

        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
            . 'From: ' . MAIL_FROM . "\r\n";
        $subject = 'Payslip ' . $p['PayrollNo'] . ' - ' . $periodLabel;
        if (@mail($emp['Email'], $subject, $html, $headers)) $sent++;
        else $skipped++;
    }
    return ['sent' => $sent, 'skipped' => $skipped];
}
