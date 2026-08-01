<?php
/**
 * ============================================================================
 * PreAudit.php - Phase 6's imperative shell.
 *
 * Loads everything RuleEngine::validate() needs and calls it. All the
 * decisions are in the pure function; this file is I/O and one redaction.
 *
 * THE REDACTION IS THE ONE PIECE OF JUDGEMENT HERE, and it belongs here rather
 * than in the engine because it depends on who is asking - which is exactly
 * the kind of thing a pure function must not know. The cross-scope duplicate
 * check has to run system-wide to be worth anything (an employee paid twice by
 * two different offices is the case it exists for), so it runs with elevated
 * reach and then drops everything the caller may not see before the engine
 * ever gets it. Phase 2's plan calls for precisely this: "returns redacted
 * finding to each affected scope, full detail visible only to Admin".
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Rules\RuleEngine;
use Digos\Domain\Rules\Severity;
use Digos\Repo\AttachmentRepo;
use Digos\Repo\ContractRepo;
use Digos\Repo\DtrRepo;
use Digos\Repo\EmployeeDocumentRepo;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\HolidayRepo;
use Digos\Repo\MemorandumRepo;
use Digos\Repo\PayrollRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\ScopeGrantRepo;
use Digos\Repo\SuspensionRepo;
use Digos\Repo\WorkShiftRepo;

/**
 * Runs the pre-audit over one payroll.
 *
 * Read-only and side-effect free at this phase: it reports, and Phase 7's
 * transition guard is what refuses to move a payroll carrying a BLOCKER.
 * Running it speculatively - "what would this be flagged for?" - is therefore
 * safe, which matters because a preparer should be able to check their own
 * work before submitting it.
 */
function apiRunPreAudit(array $p, array $user): array
{
    requireFields($p, ['PayrollNo']);

    $payroll = PayrollRepo::findScoped($user, (string) $p['PayrollNo']);
    if (!$payroll) throw new RuntimeException('Payroll not found.');

    $context = preAuditContext($payroll, $user, (string) ($p['ShiftCode'] ?? ''));
    $findings = RuleEngine::validateToArray($context);

    return [
        'PayrollNo' => $payroll['PayrollNo'],
        'Status' => $payroll['Status'],
        'findings' => $findings,
        'counts' => preAuditCounts($findings),
        'blocked' => Severity::blocks(RuleEngine::validate($context)),
    ];
}

/**
 * The pre-auditor's queue: payrolls awaiting review or already suspended,
 * within scope, oldest-waiting first.
 *
 * Findings are NOT recomputed here for every row - apiRunPreAudit does that,
 * once, when a specific payroll is opened. Running the rule engine over an
 * entire queue on every list refresh would make the worklist itself the slow
 * part of the workflow it exists to speed up.
 */
function apiGetWorklist(array $p, array $user): array
{
    $forReview = PayrollRepo::listScoped($user, ['Status' => 'FOR_PRE_AUDIT']);
    $suspended = PayrollRepo::listScoped($user, ['Status' => 'SUSPENDED']);

    $rows = [];
    foreach ($forReview as $payroll) {
        $rows[] = worklistRow($payroll, (string) ($payroll['SubmittedAt'] ?? $payroll['DateCreated']), []);
    }
    foreach ($suspended as $payroll) {
        $open = SuspensionRepo::openFor((string) $payroll['PayrollNo']);
        $since = $open ? min(array_column($open, 'RaisedAt'))
            : (string) ($payroll['SubmittedAt'] ?? $payroll['DateCreated']);
        $rows[] = worklistRow($payroll, $since, $open);
    }

    usort($rows, fn(array $a, array $b) => $a['AgingSince'] <=> $b['AgingSince']);

    return ['rows' => $rows,
        'counts' => ['ForPreAudit' => count($forReview), 'Suspended' => count($suspended)]];
}

/** @param array<int, array<string, mixed>> $openSuspensions */
function worklistRow(array $payroll, string $agingSince, array $openSuspensions): array
{
    return [
        'PayrollNo' => $payroll['PayrollNo'],
        'PeriodID' => $payroll['PeriodID'],
        'OfficeCode' => $payroll['OfficeCode'],
        'Status' => $payroll['Status'],
        'TotalNet' => $payroll['TotalNet'],
        'PreparedBy' => $payroll['PreparedBy'],
        'AgingSince' => $agingSince,
        'OpenSuspensions' => array_map(fn(array $s) => [
            'NsNo' => $s['NsNo'], 'GroundCode' => $s['GroundCode'], 'EmployeeID' => $s['EmployeeID'],
        ], $openSuspensions),
    ];
}

/** How many findings of each severity, so a screen can summarise at a glance. */
function preAuditCounts(array $findings): array
{
    $counts = array_fill_keys(Severity::ORDER, 0);
    foreach ($findings as $finding) {
        $counts[$finding['Severity']] = ($counts[$finding['Severity']] ?? 0) + 1;
    }
    return $counts;
}

/**
 * Everything the engine needs, in one pass.
 *
 * @param array<string, mixed> $payroll the header, already scope-checked
 * @return array<string, mixed>
 */
function preAuditContext(array $payroll, array $user, string $shiftCode = ''): array
{
    $payrollNo = (string) $payroll['PayrollNo'];
    $period = ReferenceRepo::period((string) $payroll['PeriodID']);

    $periodStart = (string) ($period['StartDate'] ?? '');
    $periodEnd = (string) ($period['EndDate'] ?? '');

    $lines = PayrollRepo::detailsScoped($user, $payrollNo);
    $employeeIds = array_values(array_unique(array_column($lines, 'EmployeeID')));

    // Day rows, contracts and employment types come from the unscoped repo
    // methods on purpose. The caller has already been shown these lines by the
    // scoped read above; re-applying their grants to the evidence behind a
    // line they can see would make the pre-audit's answer depend on who ran it.
    $days = DtrRepo::daysForPeriod((string) $payroll['PeriodID'], $employeeIds);

    $contracts = [];
    $employmentTypes = [];
    foreach ($employeeIds as $employeeId) {
        foreach (ContractRepo::historyFor((string) $employeeId) as $contract) {
            $contracts[] = $contract;
        }
        $employee = EmployeeRepo::findScoped($user, (string) $employeeId);
        $employmentTypes[(string) $employeeId] = (string) ($employee['EmploymentTypeCode'] ?? '');
    }

    return [
        'periodStart' => $periodStart,
        'periodEnd' => $periodEnd,
        'payroll' => $payroll,
        'lines' => $lines,

        'dtrDays' => $days,
        'contracts' => $contracts,
        'employmentTypes' => $employmentTypes,

        'attachments' => AttachmentRepo::listScoped($user),
        'attachmentCoverage' => AttachmentRepo::coverageFor($employeeIds, $periodStart, $periodEnd),
        'bioExemptions' => EmployeeDocumentRepo::listExemptionsScoped($user),
        'travelOrders' => EmployeeDocumentRepo::listTravelOrdersScoped($user),

        'memoranda' => MemorandumRepo::activeForDate($periodEnd),
        'memorandumCoverage' => MemorandumRepo::coverageForDate($periodEnd),

        'shiftVersions' => $shiftCode === '' ? [] : WorkShiftRepo::versionsOf($shiftCode),

        'holidays' => HolidayRepo::holidaysBetween($periodStart, $periodEnd),
        'holidayPayRules' => HolidayRepo::payRules(),
        'officeScope' => officeScopeFor((string) ($payroll['OfficeCode'] ?? '')),

        'overlappingPayrolls' => redactedOverlaps($payrollNo, $employeeIds, $periodStart, $periodEnd),
        'preparerOfficeCodes' => preparerOfficeCodes($user),
    ];
}

/**
 * Employees on more than one payroll covering these dates, redacted.
 *
 * The check runs system-wide - an employee paid twice by two offices is the
 * whole point, and a scoped check would miss exactly that case. What comes
 * back names only the employee, who is already on the payroll in front of the
 * reader. The other payroll's number and office are deliberately dropped:
 * disclosing them would leak the existence and shape of another office's
 * payroll to somebody with no grant over it.
 *
 * @param string[] $employeeIds
 * @return array<int, array{EmployeeID: string, EmployeeName: string}>
 */
function redactedOverlaps(string $payrollNo, array $employeeIds, string $start, string $end): array
{
    if (!$employeeIds || $start === '' || $end === '') return [];

    $clashes = PayrollRepo::employeesOnOverlappingPayrolls($payrollNo, $employeeIds, $start, $end);

    return array_map(fn(array $row) => [
        'EmployeeID' => (string) $row['EmployeeID'],
        'EmployeeName' => (string) $row['EmployeeName'],
    ], $clashes);
}

/**
 * The office codes the preparer may charge to, or null for a wildcard grant.
 *
 * Null rather than an empty array, and the engine treats the two differently:
 * null is "may charge anywhere, nothing to check", an empty array is "may
 * charge nowhere, every line is a finding". Collapsing them would turn an
 * administrator's payroll into a wall of scope violations.
 *
 * @return string[]|null
 */
function preparerOfficeCodes(array $user): ?array
{
    $grants = ScopeGrantRepo::forUser((string) $user['Email']);

    $codes = [];
    foreach ($grants as $grant) {
        $office = $grant['OfficeCode'] ?? null;

        // One wildcard grant is enough: it permits every office, so listing
        // the others would not narrow anything.
        if ($office === null || trim((string) $office) === '') return null;

        $codes[(string) $office] = true;
    }

    return array_keys($codes);
}
