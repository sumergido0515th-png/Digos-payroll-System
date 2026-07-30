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
