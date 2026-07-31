<?php
/**
 * ============================================================================
 * api.php - Single JSON endpoint. The SPA posts {action, payload}; the
 * dispatcher authenticates, checks the action's permission, invokes the
 * matching api* function and returns the standard {ok, data, message}
 * envelope. Mutating actions are written to the audit log.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

/**
 * Route table: action => [permission, module, logAction].
 * Only whitelisted actions are callable; '' permission = any signed-in user;
 * '' logAction = read-only (not logged).
 */
const ROUTES = [
    // Session
    'apiGetSession' => ['', 'Auth', ''],
    'apiHeartbeat' => ['', 'Auth', ''],
    'apiLogout' => ['', 'Auth', ''],

    // Dashboard
    'apiGetDashboard' => ['dashboard.view', 'Dashboard', ''],

    // Employees
    'apiListEmployees' => ['employee.view', 'Employees', ''],
    'apiGetEmployee' => ['employee.view', 'Employees', ''],
    'apiSaveEmployee' => ['employee.edit', 'Employees', 'SAVE_EMPLOYEE'],
    'apiDeleteEmployee' => ['employee.delete', 'Employees', 'DELETE_EMPLOYEE'],

    // Offices / departments / functions
    'apiListOffices' => ['office.view', 'Offices', ''],
    'apiSaveOffice' => ['office.edit', 'Offices', 'SAVE_OFFICE'],
    'apiDeleteOffice' => ['office.edit', 'Offices', 'DELETE_OFFICE'],
    'apiListDepartments' => ['office.view', 'Departments', ''],
    'apiSaveDepartment' => ['office.edit', 'Departments', 'SAVE_DEPARTMENT'],
    'apiDeleteDepartment' => ['office.edit', 'Departments', 'DELETE_DEPARTMENT'],
    'apiListFunctions' => ['office.view', 'Functions', ''],
    'apiSaveFunction' => ['office.edit', 'Functions', 'SAVE_FUNCTION'],
    'apiDeleteFunction' => ['office.edit', 'Functions', 'DELETE_FUNCTION'],

    // Timekeepers
    'apiListTimekeepers' => ['timekeeper.view', 'Timekeepers', ''],
    'apiSaveTimekeeper' => ['timekeeper.edit', 'Timekeepers', 'SAVE_TIMEKEEPER'],
    'apiDeleteTimekeeper' => ['timekeeper.edit', 'Timekeepers', 'DELETE_TIMEKEEPER'],

    // Lookups
    'apiGetLookups' => ['dashboard.view', 'Lookups', ''],

    // Periods
    'apiListPeriods' => ['period.view', 'PayrollPeriods', ''],
    'apiSavePeriod' => ['period.edit', 'PayrollPeriods', 'SAVE_PERIOD'],
    'apiDeletePeriod' => ['period.edit', 'PayrollPeriods', 'DELETE_PERIOD'],

    // Payroll
    'apiComputePayroll' => ['payroll.view', 'Payroll', ''],
    'apiListPayrolls' => ['payroll.view', 'Payroll', ''],
    'apiGetPayroll' => ['payroll.view', 'Payroll', ''],
    'apiSavePayroll' => ['payroll.edit', 'Payroll', 'SAVE_PAYROLL'],
    'apiDeletePayroll' => ['payroll.edit', 'Payroll', 'DELETE_PAYROLL'],
    'apiSubmitPayroll' => ['payroll.submit', 'Payroll', 'SUBMIT_PAYROLL'],
    'apiApprovePayroll' => ['payroll.approve', 'Payroll', 'APPROVE_PAYROLL'],
    'apiReturnPayroll' => ['payroll.approve', 'Payroll', 'RETURN_PAYROLL'],
    'apiReleasePayroll' => ['payroll.release', 'Payroll', 'RELEASE_PAYROLL'],
    'apiCancelPayroll' => ['payroll.edit', 'Payroll', 'CANCEL_PAYROLL'],
    'apiUndoLast' => ['payroll.edit', 'Payroll', 'UNDO'],
    'apiEmailPayslips' => ['payroll.release', 'Payroll', 'EMAIL_PAYSLIPS'],

    // Documents (Phase 3)
    'apiListMemoranda' => ['document.view', 'Memorandum', ''],
    'apiGetMemorandum' => ['document.view', 'Memorandum', ''],
    'apiSaveMemorandum' => ['document.edit', 'Memorandum', 'SAVE_MEMORANDUM'],
    'apiDeleteMemorandum' => ['document.delete', 'Memorandum', 'DELETE_MEMORANDUM'],
    'apiListBioExemptions' => ['document.view', 'BioExemptions', ''],
    'apiSaveBioExemption' => ['document.edit', 'BioExemptions', 'SAVE_BIO_EXEMPTION'],
    'apiDeleteBioExemption' => ['document.delete', 'BioExemptions', 'DELETE_BIO_EXEMPTION'],
    'apiListTravelOrders' => ['document.view', 'TravelOrders', ''],
    'apiSaveTravelOrder' => ['document.edit', 'TravelOrders', 'SAVE_TRAVEL_ORDER'],
    'apiDeleteTravelOrder' => ['document.delete', 'TravelOrders', 'DELETE_TRAVEL_ORDER'],

    // Work shifts and contracts are versioned: saving supersedes, never
    // overwrites, so there is no delete route for either.
    'apiListWorkShifts' => ['shift.view', 'WorkShifts', ''],
    'apiGetWorkShiftHistory' => ['shift.view', 'WorkShifts', ''],
    'apiSaveWorkShift' => ['shift.edit', 'WorkShifts', 'SAVE_WORK_SHIFT'],
    'apiListContracts' => ['contract.view', 'Contracts', ''],
    'apiGetContractHistory' => ['contract.view', 'Contracts', ''],
    'apiSaveContract' => ['contract.edit', 'Contracts', 'SAVE_CONTRACT'],
    'apiAmendContract' => ['contract.edit', 'Contracts', 'AMEND_CONTRACT'],

    // Daily time records (Phase 3B)
    'apiGetDtrGrid' => ['dtr.view', 'DtrDays', ''],
    'apiGetDtrTotals' => ['dtr.view', 'DtrDays', ''],
    'apiSaveDtrDays' => ['dtr.edit', 'DtrDays', 'SAVE_DTR_DAYS'],
    'apiDeleteDtrDay' => ['dtr.edit', 'DtrDays', 'DELETE_DTR_DAY'],
    'apiImportBiometricLogs' => ['dtr.import', 'DtrDays', 'IMPORT_BIOMETRIC'],

    // Calendar and resolvers (Phase 4)
    'apiListHolidays' => ['calendar.view', 'Holidays', ''],
    'apiListHolidayPayRules' => ['calendar.view', 'HolidayPayRules', ''],
    'apiSaveHoliday' => ['calendar.edit', 'Holidays', 'SAVE_HOLIDAY'],
    'apiDeleteHoliday' => ['calendar.edit', 'Holidays', 'DELETE_HOLIDAY'],
    'apiResolveDay' => ['dtr.view', 'Resolvers', ''],

    // Attachments and the coverage matrix (Phase 5)
    'apiListAttachments' => ['attachment.view', 'Attachments', ''],
    'apiGetAttachment' => ['attachment.view', 'Attachments', ''],
    'apiUploadAttachment' => ['attachment.edit', 'Attachments', 'UPLOAD_ATTACHMENT'],
    'apiDeleteAttachment' => ['attachment.edit', 'Attachments', 'DELETE_ATTACHMENT'],
    'apiGetCoverageMatrix' => ['attachment.view', 'Coverage', ''],

    // Pre-audit (Phase 6)
    'apiRunPreAudit' => ['payroll.view', 'PreAudit', ''],

    // Reports & print
    'apiRunReport' => ['report.view', 'Reports', 'RUN_REPORT'],
    'apiGetPrintHtml' => ['print.run', 'Print', 'PRINT'],

    // Users & logs (Administrator only via '*')
    'apiListUsers' => ['user.manage', 'Users', ''],
    'apiSaveUser' => ['user.manage', 'Users', 'SAVE_USER'],
    'apiDeleteUser' => ['user.manage', 'Users', 'DELETE_USER'],
    'apiGetRoles' => ['user.manage', 'Users', ''],
    'apiGetLogs' => ['log.view', 'Logs', ''],

    // Scope grants. Every mutation is audited: this is the table that decides
    // who can see which office's payroll, so a change to it is exactly the kind
    // of thing an auditor asks about later.
    'apiListScopeGrants' => ['scope.manage', 'ScopeGrants', ''],
    'apiGetScopeDimensions' => ['scope.manage', 'ScopeGrants', ''],
    'apiSaveScopeGrant' => ['scope.manage', 'ScopeGrants', 'SAVE_SCOPE_GRANT'],
    'apiDeleteScopeGrant' => ['scope.manage', 'ScopeGrants', 'DELETE_SCOPE_GRANT'],

    // Settings & backup
    'apiGetSettings' => ['settings.view', 'Settings', ''],
    'apiSaveSettings' => ['settings.edit', 'Settings', 'SAVE_SETTINGS'],
    'apiUploadImageSetting' => ['settings.edit', 'Settings', 'UPLOAD_IMAGE'],
    'apiBackupNow' => ['backup.run', 'Backup', 'BACKUP'],
    'apiListBackups' => ['backup.run', 'Backup', ''],
    'apiRestoreBackup' => ['backup.run', 'Backup', 'RESTORE'],
    'apiApplyBackupSchedule' => ['settings.edit', 'Backup', 'SCHEDULE_BACKUP'],
];

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('POST only.');
    }
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $action = (string) ($body['action'] ?? '');
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

    if (!isset(ROUTES[$action]) || !function_exists($action)) {
        throw new RuntimeException('Unknown action: ' . $action);
    }
    [$permission, $module, $logAction] = ROUTES[$action];

    $user = requireUser();
    requirePermission($user, $permission);

    $data = $action($payload, $user);

    if ($logAction !== '') {
        $summary = json_encode(auditSummary($payload), JSON_UNESCAPED_UNICODE) ?: '';
        writeLog($user['Email'], $logAction, $module, mb_substr($summary, 0, 400));
    }
    echo json_encode(ok($data));

} catch (Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    echo json_encode(fail($e->getMessage()));
}
