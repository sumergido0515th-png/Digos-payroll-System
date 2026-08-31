<?php
/**
 * ============================================================================
 * Payroll.php - Periods, payroll transactions (max 15 employees), automatic
 * computation, the Phase 7 workflow state machine, duplicate detection,
 * sequential numbering and single-step undo.
 *
 *   DRAFT -> FOR_PRE_AUDIT -> PRE_AUDIT_APPROVED -> FOR_PRINTING -> PRINTED -> SUBMITTED
 *                   |- SUSPENDED -> (settle) -> FOR_PRE_AUDIT
 *                   `- RETURNED_TO_PREPARER -> FOR_PRE_AUDIT
 *
 * The graph itself, the approval guard and the suspension split live in the
 * pure Digos\Domain\Workflow\PayrollWorkflow; this file loads what that class
 * needs and persists what it decides.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Print\PayloadHash;
use Digos\Domain\Rules\RuleEngine;
use Digos\Domain\Workflow\PayrollWorkflow;
use Digos\Repo\AttachmentRepo;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\HolidayRepo;
use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\ScopeGateway;
use Digos\Repo\SuspensionRepo;

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
    // DtrDays.PeriodID is ON DELETE SET NULL, so the captured days survive the
    // period and stop belonging to one. Phase 3B's whole claim is that a payroll
    // total can be re-derived by summing that employee's days for the period -
    // days with no period cannot be summed for it, and nothing reports the loss.
    $days = (int) DB::scalar('SELECT COUNT(*) FROM DtrDays WHERE PeriodID = ?', [$p['PeriodID']]);
    if ($days) {
        throw new RuntimeException("$days daily time record(s) were captured for this period. "
            . 'Deleting it would leave them belonging to no period at all. Close or lock it instead.');
    }
    return ['deleted' => DB::exec('DELETE FROM PayrollPeriods WHERE PeriodID = ?', [$p['PeriodID']])];
}

/* ==========================================================================
 * Numbering & computation
 * ======================================================================== */

/**
 * Next sequential payroll number, e.g. "PR-2026-000001".
 *
 * The Counters row is locked (FOR UPDATE) so concurrent saves cannot collide.
 * Must be called inside a transaction. Filtered to the PAYROLL series - since
 * migration 0021 gave Counters a Series column so suspension numbers (NsNo)
 * could have their own independent sequence, a bare `WHERE YearNo = ?` would
 * match two rows once a suspension has been raised in the same year, and
 * which one a plain fetchColumn() returns is not something to depend on.
 */
function nextPayrollNo(): string
{
    $prefix = getSetting('PayrollPrefix', 'PR');
    $year = (int) date('Y');

    DB::exec('INSERT IGNORE INTO Counters (YearNo, Series, LastNo) VALUES (?, ?, 0)',
        [$year, 'PAYROLL']);
    $last = (int) DB::scalar(
        'SELECT LastNo FROM Counters WHERE YearNo = ? AND Series = ? FOR UPDATE',
        [$year, 'PAYROLL']);
    $next = $last + 1;
    DB::exec('UPDATE Counters SET LastNo = ? WHERE YearNo = ? AND Series = ?',
        [$next, $year, 'PAYROLL']);

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
 * Computes one payroll line:
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
        // Scoped, unlike the save path below it. There is no saved payroll here
        // to check the caller against - this is the grid preview - so the check
        // has to be on the employee. Out of scope reports the same "not found"
        // as a bad id, as apiGetPayroll and printBundle do: saying which of the
        // two it was confirms that the employee exists.
        $emp = EmployeeRepo::findForComputationScoped($user, (string) ($l['EmployeeID'] ?? ''));
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
    return array_map('aliasFunctionOut', PayrollRepo::search($user, $p));
}

/**
 * The choices the payroll filter bar may offer this user.
 *
 * A separate call rather than a field on the list response: the options do not
 * change as the user narrows their filters - narrowing them by what is already
 * on screen is what makes a facet impossible to widen again - and the list is
 * re-fetched on every keystroke of the search box.
 *
 * Scoped in the repository, like every other read. See
 * tests/Integration/FilterScopeTest.php for why a dropdown is a disclosure
 * surface in its own right.
 */
function apiGetPayrollFacets(array $p, array $user): array
{
    return PayrollRepo::facetOptionsScoped($user);
}

/** One payroll with its lines. */
function apiGetPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = PayrollRepo::findScoped($user, $p['PayrollNo']);

    // Out of scope reports the same thing as absent, deliberately. Telling a
    // caller "that payroll exists but is not yours" confirms the existence of
    // another office's record, which is the disclosure the scope layer is here
    // to prevent - and the message a legitimate mistyped number produces is the
    // same either way.
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    return [
        'header' => aliasFunctionOut($header),
        'details' => PayrollRepo::detailsScoped($user, $p['PayrollNo']),
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

    // Scope on the way in, not only on the way out. Filtering reads while
    // leaving writes open would let a user prepare a payroll charged to an
    // office they cannot see - and then not be able to open it again. The
    // charge is checked, not the employees' home offices: which appropriation
    // pays is the question scope answers.
    ScopeGateway::requirePermits($user, 'Payroll', [
        'OfficeCode' => $p['OfficeCode'],
        'FunctionCode' => $office['FunctionCode'] ?? null,
    ], 'payrolls charged to ' . $p['OfficeCode']);

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
        $clashes = duplicateEmployees($p['PeriodID'], $p['PayrollNo'] ?? '', $ids, $user);
        if ($clashes) {
            throw new RuntimeException('Already on another payroll this period: '
                . implode('; ', $clashes) . '. Tick "Allow duplicates" to override.');
        }
    }

    $isNew = empty($p['PayrollNo']);
    $existing = $isNew ? null : DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$isNew) {
        if (!$existing) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
        if (!PayrollWorkflow::isEditable($existing['Status'])) {
            throw new RuntimeException('Only a Draft, submitted-for-pre-audit or returned payroll '
                . 'can be edited. This one is ' . $existing['Status'] . '.');
        }
    }

    return DB::tx(function () use ($p, $user, $cfg, $office, $lines, $isNew, $existing) {
        $payrollNo = $isNew ? nextPayrollNo() : $p['PayrollNo'];

        $computed = [];
        foreach (array_values($lines) as $i => $l) {
            $emp = EmployeeRepo::findForComputation((string) ($l['EmployeeID'] ?? ''));
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
            'Status' => $existing ? $existing['Status'] : 'DRAFT',
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

/**
 * Employees already on another non-cancelled payroll of the same period.
 *
 * REDACTED ACROSS SCOPE. This is the cross-scope conflict check the phase plan
 * describes: the lookup has to run system-wide, because an employee paid twice
 * in one period is the finding whatever office did it - but the answer must not
 * carry another office's employee names and payroll numbers back to whoever
 * happened to type that employee's id.
 *
 * In scope, the caller sees what they always saw. Out of scope they are told
 * that a clash exists and nothing more, which is enough to act on: tick "Allow
 * duplicates" or take the person off the grid. Full detail needs `scope.manage`
 * - the same permission that administers scope is the one that may look across
 * it.
 *
 * @return string[] human-readable clash descriptions
 */
function duplicateEmployees(string $periodId, string $exceptPayrollNo, array $employeeIds, array $user): array
{
    if (!$employeeIds) return [];
    $ph = implode(',', array_fill(0, count($employeeIds), '?'));

    $rows = DB::rows(
        "SELECT d.EmployeeName, d.PayrollNo, d.ChargedOfficeCode
           FROM PayrollDetails d
           JOIN Payroll h ON h.PayrollNo = d.PayrollNo
          WHERE h.PeriodID = ? AND h.Status <> 'CANCELLED' AND h.PayrollNo <> ?
            AND d.EmployeeID IN ($ph)",
        array_merge([$periodId, $exceptPayrollNo], array_values($employeeIds)));

    if (!$rows) return [];

    $seesEverything = hasPermission($user, 'scope.manage');

    $visible = [];
    $redacted = 0;
    foreach ($rows as $r) {
        $inScope = $seesEverything || ScopeGateway::permits($user, 'PayrollDetails', [
            'OfficeCode' => $r['ChargedOfficeCode'],
        ], 'read');

        if ($inScope) {
            $visible[] = $r['EmployeeName'] . ' (' . $r['PayrollNo'] . ')';
        } else {
            $redacted++;
        }
    }

    if ($redacted > 0) {
        $visible[] = $redacted . ' more on another office\'s payroll you do not have access to';
    }
    return $visible;
}

/** Deletes a Draft payroll and its lines. */
function apiDeletePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = DB::row('SELECT Status FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    if ($header['Status'] !== 'DRAFT') {
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

/**
 * Segregation of duties: the approver may not be the preparer.
 *
 * Reads PreparedByUser, the foreign key migration 0007 added, and never the
 * PreparedBy display string beside it. The string is what the printed form
 * shows and it can be two different people spelled the same way, or one person
 * spelled two ways; an identity check written against it either passes
 * everything or blocks the wrong person. That the key was missing is why this
 * check could not be written before Phase 1.
 *
 * There is no override. The plan states segregation of duties is enforced in
 * code rather than policy, and an override is how it becomes policy again.
 * Payrolls approved BEFORE this check existed are not revisited - three of them
 * are self-approved and were grandfathered at Phase 1 sign-off as development
 * records.
 */
function requireDifferentApprover(array $header, array $user): void
{
    $preparer = trim((string) ($header['PreparedByUser'] ?? ''));
    if ($preparer === '' || $preparer !== $user['Email']) return;

    throw new RuntimeException(
        'You prepared this payroll, so you cannot also approve it. '
        . 'Someone else with approval authority has to review it.');
}

/**
 * Re-verifies the caller's own password at the moment of a sensitive action.
 *
 * requireUser() already proved who is signed in for this whole request;
 * this proves they can still produce the one secret an unattended or
 * hijacked session cannot. Approval is the one step in this workflow with no
 * real undo - Undo reverts a status flag, not a suspension already raised
 * against somebody's pay, or a supplemental payroll already split off - so it
 * is the one action that asks again, at the moment it happens.
 */
function reauthenticate(array $user, string $password): void
{
    $hash = (string) DB::scalar('SELECT PasswordHash FROM Users WHERE Email = ?', [$user['Email']]);
    if ($hash === '' || !password_verify($password, $hash)) {
        throw new RuntimeException('Incorrect password. Re-enter it to confirm this approval.');
    }
}

/** Validated status transition + timestamps. Segregation of duties is the caller's job. */
function payrollTransition(string $payrollNo, string $to, array $user): array
{
    $header = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$payrollNo]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $payrollNo);

    if (!PayrollWorkflow::canTransition($header['Status'], $to)) {
        throw new RuntimeException('Cannot move a ' . $header['Status'] . " payroll to $to.");
    }

    $patch = ['Status' => $to];

    // Stamped on entry to FOR_PRE_AUDIT rather than on creation: a Draft can
    // sit unedited for weeks before anyone submits it, and the pre-auditor
    // worklist sorts its queue by how long a payroll has actually been
    // waiting for review, not by how long it has existed.
    //
    // PayloadHash is cleared here too, whether this is a fresh submission (it
    // was never set) or Phase 8's tamper revert (it just proved stale) -
    // either way there is no valid hash for a payroll back in review.
    if ($to === 'FOR_PRE_AUDIT') {
        $patch['SubmittedAt'] = date('Y-m-d H:i:s');
        $patch['PayloadHash'] = null;
    }

    if ($to === 'PRE_AUDIT_APPROVED') {
        // As with PreparedBy on save: the display name is for the printed
        // form, the key is what the Phase 2 segregation-of-duties check reads.
        $patch['ApprovedBy'] = $user['FullName'] ?: $user['Email'];
        $patch['ApprovedByUser'] = $user['Email'];
        $patch['ApprovedAt'] = date('Y-m-d H:i:s');
        $patch['PayloadHash'] = computePayloadHash(
            PayrollRepo::detailsUnscoped($payrollNo), (string) $header['PeriodID']);
    }
    if ($to === 'SUBMITTED') $patch['ReleasedAt'] = date('Y-m-d H:i:s');

    DB::update('Payroll', $patch, 'PayrollNo', $payrollNo);
    setUndo(['action' => 'status', 'PayrollNo' => $payrollNo,
        'prevStatus' => $header['Status'], 'user' => $user['Email']]);
    return ['PayrollNo' => $payrollNo, 'Status' => $to];
}

/**
 * Hashes exactly what an Official print renders - see PayloadHash's own
 * docblock for which three inputs and why those three.
 *
 * Takes $lines directly rather than re-querying, so the split path
 * (raiseSuspensionsAndSplit) can hash the clean half it is about to write
 * before that write happens, and payrollTransition can hash the unscoped
 * read it already needed for the same reason - see PayrollRepo::
 * detailsUnscoped()'s own docblock for why this is unscoped throughout.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function computePayloadHash(array $lines, string $periodId): string
{
    $employeeIds = array_values(array_unique(array_column($lines, 'EmployeeID')));
    $period = ReferenceRepo::period($periodId);
    $start = (string) ($period['StartDate'] ?? '');
    $end = (string) ($period['EndDate'] ?? '');

    return PayloadHash::compute(
        $lines,
        AttachmentRepo::coverageFor($employeeIds, $start, $end),
        HolidayRepo::holidaysBetween($start, $end)
    );
}

/** DRAFT or RETURNED_TO_PREPARER -> FOR_PRE_AUDIT. */
function apiSubmitPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'FOR_PRE_AUDIT', $user);
}

/**
 * Attempts pre-audit approval.
 *
 * Runs the same rule engine a preparer can run speculatively against their
 * own work (apiRunPreAudit), and applies Phase 6's promise that a BLOCKER
 * finding has no override: if one is present this does not throw, because
 * the workflow has somewhere to put a refused approval - it raises a Notice
 * of Suspension for each BLOCKER and moves the payroll (or the affected
 * employees' share of it) to SUSPENDED instead.
 *
 * Segregation of duties is checked before anything else: refusing a
 * preparer's attempt to approve their own payroll must not depend on what the
 * rule engine finds, and must not cost a password re-check to reach. Re-
 * authentication then gates the one path that would otherwise really approve
 * something - it is not asked of an attempt that was always going to be
 * refused for being the same person.
 */
function apiApprovePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);

    // The worklist is scope-filtered, but the action still has to re-check the
    // payroll itself. A direct lookup here would let a caller who knows a
    // payroll number act on a row outside the office they are allowed to
    // review, which is exactly the kind of "it works in the UI but not in the
    // gate" bug scope enforcement exists to prevent.
    $header = PayrollRepo::findScoped($user, $p['PayrollNo']);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);

    if (!PayrollWorkflow::canTransition($header['Status'], 'PRE_AUDIT_APPROVED')) {
        throw new RuntimeException('Cannot move a ' . $header['Status'] . ' payroll to PRE_AUDIT_APPROVED.');
    }

    requireDifferentApprover($header, $user);
    reauthenticate($user, (string) ($p['Password'] ?? ''));

    $context = preAuditContext($header, $user, (string) ($p['ShiftCode'] ?? ''));
    $findings = RuleEngine::validateToArray($context);
    $guard = PayrollWorkflow::guardApproval($findings);

    if ($guard['approved']) {
        $result = payrollTransition($p['PayrollNo'], 'PRE_AUDIT_APPROVED', $user);
        return $result + ['approved' => true, 'split' => false, 'suspensions' => 0];
    }

    return raiseSuspensionsAndSplit($header, $context['lines'], $guard['toRaise'], $user);
}

/**
 * Turns a refused approval into one or more Notices of Suspension.
 *
 * EMPLOYEE-SCOPED BY DEFAULT. When some lines are clean and some carry a
 * BLOCKER naming a specific employee, the clean lines are split onto the
 * ORIGINAL payroll number and proceed straight to PRE_AUDIT_APPROVED; the
 * named employees move to a freshly numbered SUPPLEMENTAL payroll that holds
 * at SUSPENDED until settled. Fourteen coworkers are not made to wait on the
 * fifteenth's unresolved finding.
 *
 * A finding with no employee attached (a header-level total that does not
 * add up, a scope violation with nothing else to blame) suspends the WHOLE
 * batch instead - there is no subset of clean lines to split off, because
 * the finding is not about any one of them. The same is true when every
 * employee on the payroll is named by some finding: nothing clean survives
 * the split, so there is nothing to do but hold the batch as one unit.
 *
 * @param array<int, array<string, mixed>> $lines PayrollDetails rows already
 *        loaded for the pre-audit - reused rather than re-queried, since the
 *        findings were computed against exactly these rows
 * @param array<int, array<string, string>> $toRaise from PayrollWorkflow::guardApproval()
 */
function raiseSuspensionsAndSplit(array $header, array $lines, array $toRaise, array $user): array
{
    $payrollNo = (string) $header['PayrollNo'];
    $blockedEmployeeIds = array_values(array_unique(array_filter(array_column($toRaise, 'EmployeeID'))));
    $batchWide = array_filter($toRaise, fn(array $r) => $r['EmployeeID'] === '');

    $cleanRemains = !$batchWide && $blockedEmployeeIds
        && count(array_diff(array_column($lines, 'EmployeeID'), $blockedEmployeeIds)) > 0;

    return DB::tx(function () use ($header, $payrollNo, $lines, $toRaise, $user, $blockedEmployeeIds, $cleanRemains) {
        $raise = function (string $forPayrollNo) use ($toRaise, $user) {
            foreach ($toRaise as $entry) {
                SuspensionRepo::raise(SuspensionRepo::nextNsNo(), [
                    'PayrollNo' => $forPayrollNo,
                    'EmployeeID' => $entry['EmployeeID'] !== '' ? $entry['EmployeeID'] : null,
                    'GroundCode' => $entry['GroundCode'],
                    'RuleID' => $entry['RuleID'],
                    'Particulars' => $entry['Particulars'],
                    'RequiredAction' => 'Resolve the finding, then settle this suspension to '
                        . 'return the payroll to pre-audit.',
                    'RaisedBy' => $user['Email'],
                ]);
            }
        };

        if (!$cleanRemains) {
            $raise($payrollNo);
            payrollTransition($payrollNo, 'SUSPENDED', $user);
            return ['PayrollNo' => $payrollNo, 'Status' => 'SUSPENDED',
                'approved' => false, 'split' => false, 'suspensions' => count($toRaise)];
        }

        $split = PayrollWorkflow::partitionForSuspension($lines, $blockedEmployeeIds);
        $cleanTotals = payrollTotals($split['clean']);
        $suspendedTotals = payrollTotals($split['suspended']);
        $supplementalNo = nextPayrollNo();

        DB::update('Payroll', [
            'Status' => 'PRE_AUDIT_APPROVED',
            'TotalGross' => $cleanTotals['gross'],
            'TotalDeductions' => $cleanTotals['deductions'],
            'TotalNet' => $cleanTotals['net'],
            'ApprovedBy' => $user['FullName'] ?: $user['Email'],
            'ApprovedByUser' => $user['Email'],
            'ApprovedAt' => date('Y-m-d H:i:s'),
            // Hashed from $split['clean'] directly, not re-queried - these are
            // exactly the rows about to be written below, before the write.
            'PayloadHash' => computePayloadHash($split['clean'], (string) $header['PeriodID']),
        ], 'PayrollNo', $payrollNo);
        DB::exec('DELETE FROM PayrollDetails WHERE PayrollNo = ?', [$payrollNo]);
        foreach ($split['clean'] as $line) {
            $line['DetailID'] = newId('PD');
            $line['PayrollNo'] = $payrollNo;
            DB::insert('PayrollDetails', $line);
        }

        $original = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$payrollNo]);
        $supplemental = $original;
        $supplemental['PayrollNo'] = $supplementalNo;
        $supplemental['Status'] = 'SUSPENDED';
        $supplemental['SupplementsPayrollNo'] = $payrollNo;
        $supplemental['TotalGross'] = $suspendedTotals['gross'];
        $supplemental['TotalDeductions'] = $suspendedTotals['deductions'];
        $supplemental['TotalNet'] = $suspendedTotals['net'];
        $supplemental['ApprovedBy'] = '';
        $supplemental['ApprovedByUser'] = null;
        $supplemental['ApprovedAt'] = null;
        $supplemental['ReleasedAt'] = null;
        $supplemental['PdfFileId'] = '';
        // Not the original's hash, computed above from $split['clean'] - the
        // supplemental holds $split['suspended'], entirely different lines,
        // and it is not PRE_AUDIT_APPROVED yet regardless.
        $supplemental['PayloadHash'] = null;
        unset($supplemental['DateCreated']);        // a fresh payroll gets its own timestamp

        DB::insert('Payroll', $supplemental);
        foreach ($split['suspended'] as $line) {
            $line['DetailID'] = newId('PD');
            $line['PayrollNo'] = $supplementalNo;
            DB::insert('PayrollDetails', $line);
        }

        $raise($supplementalNo);

        return ['PayrollNo' => $payrollNo, 'Status' => 'PRE_AUDIT_APPROVED',
            'approved' => true, 'split' => true,
            'supplementalPayrollNo' => $supplementalNo, 'suspensions' => count($toRaise)];
    });
}

/**
 * A pre-auditor's own judgment call, not tied to any rule finding.
 *
 * Unlike a refused approval this always holds the whole payroll: the
 * pre-auditor raising this by hand already knows exactly what they mean to
 * hold, and if that is one employee rather than the batch, splitting is a
 * decision for them to make explicitly by approving what remains afterward -
 * not one this action should guess at on their behalf.
 */
function apiSuspendPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo', 'GroundCode', 'Particulars', 'RequiredAction']);

    // Same rule as approval: the action can only touch a payroll the caller
    // may actually see. Otherwise any payroll number becomes an unscoped
    // write path.
    $header = PayrollRepo::findScoped($user, $p['PayrollNo']);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    if (!PayrollWorkflow::canTransition($header['Status'], 'SUSPENDED')) {
        throw new RuntimeException('Cannot move a ' . $header['Status'] . ' payroll to SUSPENDED.');
    }

    $employeeId = trim((string) ($p['EmployeeID'] ?? '')) ?: null;
    if ($employeeId !== null && !DB::row(
            'SELECT DetailID FROM PayrollDetails WHERE PayrollNo = ? AND EmployeeID = ?',
            [$p['PayrollNo'], $employeeId])) {
        throw new RuntimeException('That employee is not on this payroll.');
    }

    $nsNo = SuspensionRepo::nextNsNo();
    SuspensionRepo::raise($nsNo, [
        'PayrollNo' => $p['PayrollNo'],
        'EmployeeID' => $employeeId,
        'GroundCode' => (string) $p['GroundCode'],
        'RuleID' => null,
        'Particulars' => (string) $p['Particulars'],
        'RequiredAction' => (string) $p['RequiredAction'],
        'Deadline' => nullableDate($p['Deadline'] ?? null, 'Deadline'),
        'RaisedBy' => $user['Email'],
    ]);
    payrollTransition($p['PayrollNo'], 'SUSPENDED', $user);

    return ['NsNo' => $nsNo, 'PayrollNo' => $p['PayrollNo'], 'Status' => 'SUSPENDED'];
}

/** FOR_PRE_AUDIT -> RETURNED_TO_PREPARER: the reject verb, no formal suspension raised. */
function apiReturnPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo', 'Remarks']);

    $header = PayrollRepo::findScoped($user, $p['PayrollNo']);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);

    $result = payrollTransition($p['PayrollNo'], 'RETURNED_TO_PREPARER', $user);

    $note = '[Returned by ' . ($user['FullName'] ?: $user['Email']) . ']: ' . trim((string) $p['Remarks']);
    $combined = trim((string) $header['Remarks']) !== '' ? $header['Remarks'] . "\n" . $note : $note;
    DB::update('Payroll', ['Remarks' => mb_substr($combined, 0, 255)], 'PayrollNo', $p['PayrollNo']);

    return $result;
}

/**
 * Closes a suspension. When nothing else is open on that payroll, it
 * re-enters FOR_PRE_AUDIT automatically - the point of a suspension is that
 * settling it is what sends a payroll back for review, not a separate step
 * someone has to remember to take.
 */
function apiSettleSuspension(array $p, array $user): array
{
    requireFields($p, ['NsNo', 'SettlementRef']);

    $suspension = SuspensionRepo::find((string) $p['NsNo']);
    if (!$suspension) throw new RuntimeException('Suspension not found: ' . $p['NsNo']);
    if ($suspension['Status'] !== 'Open') {
        throw new RuntimeException('This suspension is already ' . $suspension['Status'] . '.');
    }

    // The suspense record is scoped through its payroll, so settling it must
    // be scoped the same way. Settling a suspension on an out-of-scope
    // payroll would otherwise reopen something the caller could not even see.
    if (!PayrollRepo::findScoped($user, (string) $suspension['PayrollNo'])) {
        throw new RuntimeException('Suspension not found: ' . $p['NsNo']);
    }

    $status = !empty($p['Waive']) ? 'Waived' : 'Settled';
    SuspensionRepo::close((string) $p['NsNo'], $status, $user['Email'], (string) $p['SettlementRef']);

    $stillOpen = SuspensionRepo::openFor((string) $suspension['PayrollNo']);
    if (!$stillOpen) {
        payrollTransition((string) $suspension['PayrollNo'], 'FOR_PRE_AUDIT', $user);
    }

    return ['NsNo' => $p['NsNo'], 'Status' => $status, 'payrollReopened' => !$stillOpen];
}

/** The choices the suspension filter bar may offer this user. */
function apiGetSuspensionFacets(array $p, array $user): array
{
    return SuspensionRepo::facetOptionsScoped($user);
}

/** Suspensions the caller may see, for the worklist and a payroll's own history. */
function apiListSuspensions(array $p, array $user): array
{
    return SuspensionRepo::search($user, $p);
}

/** Suspensions the caller may see, still Open past their deadline. */
function apiGetSuspensionWatchlist(array $p, array $user): array
{
    return SuspensionRepo::pastDeadlineScoped($user, date('Y-m-d'));
}

/**
 * Payroll totals by office, citywide - never scoped to the caller's own
 * office. Gated on `aggregate.citywide` in the route table rather than
 * `payroll.view`, since seeing every office's total is exactly what that
 * separate permission exists to decide.
 */
function apiGetCitywidePayrollTotals(array $p, array $user): array
{
    return PayrollRepo::citywideTotals($p);
}

/** PRE_AUDIT_APPROVED -> FOR_PRINTING. Phase 8 attaches certification to the next step. */
function apiQueueForPrinting(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'FOR_PRINTING', $user);
}

/** FOR_PRINTING -> PRINTED. */
function apiMarkPrinted(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'PRINTED', $user);
}

/** PRINTED -> SUBMITTED: handed to the paying office. The point of no return. */
function apiReleasePayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'SUBMITTED', $user);
}

function apiCancelPayroll(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    return payrollTransition($p['PayrollNo'], 'CANCELLED', $user);
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
        if ($header['Status'] !== 'DRAFT') {
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

/** Emails HTML payslips to employees on a payroll that has passed pre-audit sign-off. */
function apiEmailPayslips(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);
    $header = DB::row('SELECT * FROM Payroll WHERE PayrollNo = ?', [$p['PayrollNo']]);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $p['PayrollNo']);
    if (!PayrollWorkflow::isOfficial($header['Status'])) {
        throw new RuntimeException('Payslips can only be emailed once a payroll has passed pre-audit sign-off.');
    }

    $gov = getSetting('GovernmentName', 'CITY GOVERNMENT OF DIGOS');
    $period = DB::row('SELECT * FROM PayrollPeriods WHERE PeriodID = ?', [$header['PeriodID']]) ?? [];
    $details = DB::rows('SELECT * FROM PayrollDetails WHERE PayrollNo = ? ORDER BY LineNo', [$p['PayrollNo']]);

    $sent = 0;
    $skipped = 0;
    foreach ($details as $d) {
        // The employee's address is Tier 2 - migration 0015 moved Email out of
        // Employees along with the rest of their personal data.
        $emp = EmployeeRepo::sensitive((string) $d['EmployeeID']);
        if (!$emp || !isEmail($emp['Email'] ?? '')) { $skipped++; continue; }

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
