<?php
/**
 * ============================================================================
 * Auth.php - Email/password authentication, role-based access control,
 * session timeout and the audit-trail writer. PHP sessions + bcrypt passwords.
 * ============================================================================
 */

declare(strict_types=1);

/**
 * Permission matrix. '*' grants everything (Admin only).
 *
 * Phase 2 replaced the six original roles with the seven the phase plan
 * defines. It was a remap of intent rather than a rename - migration 0016
 * carries the old-to-new mapping and the reasoning for each.
 *
 * These are ACTIONS ONLY. No scope is baked in here: which offices a user may
 * see is ScopeGrants, applied by Digos\Repo\ScopeGateway. Holding
 * 'payroll.view' says you may look at payrolls, never at which ones - keeping
 * the two separate is what lets one Pre-Auditor cover two offices and another
 * cover one without inventing a role per office.
 *
 * 'employee.sensitive' is the Tier 2 gate added when migration 0015 split the
 * restricted columns out. Note who does NOT hold it: an Encoder prepares
 * payrolls without ever reading a rate, because the server computes from the
 * rate rather than being handed one.
 */
const PERMISSIONS = [
    'Admin' => ['*'],

    // HRMO owns the employee record, including the restricted tier, and the
    // office structure. No payroll authority at all.
    'HRMO' => [
        'dashboard.view',
        'employee.view', 'employee.edit', 'employee.sensitive',
        'office.view', 'office.edit',
        'timekeeper.view', 'timekeeper.edit',
        'payroll.view', 'period.view',
        // HRMO owns the authority documents for the same reason it owns the
        // employee record: a memorandum, a bio exemption and a travel order are
        // personnel instruments, and the contract is the engagement itself.
        'document.view', 'document.edit', 'document.delete',
        'contract.view', 'contract.edit',
        'shift.view', 'shift.edit',
        'dtr.view', 'dtr.edit', 'dtr.import',
        'calendar.view', 'calendar.edit',
        'attachment.view', 'attachment.edit',
        'report.view', 'print.run',
    ],

    // Prepares and submits; never approves. The segregation of duties this
    // project exists to enforce starts as the absence of 'payroll.approve'
    // here, and is enforced again per payroll in payrollTransition().
    'Payroll In-Charge' => [
        'dashboard.view',
        'employee.view', 'employee.sensitive',
        'office.view', 'timekeeper.view',
        'period.view', 'period.edit',
        'payroll.view', 'payroll.edit', 'payroll.submit',
        // Reads the documents a line is justified by; does not issue them.
        'document.view', 'contract.view', 'shift.view',
        'dtr.view', 'dtr.edit', 'dtr.import',
        'calendar.view', 'attachment.view', 'attachment.edit',
        'report.view', 'print.run',
    ],

    // Verifies and approves; cannot create or edit. Reads the restricted tier
    // because checking a daily rate against the contract is the job.
    'Pre-Auditor' => [
        'dashboard.view',
        'employee.view', 'employee.sensitive',
        'office.view',
        'period.view', 'payroll.view', 'payroll.approve', 'payroll.release',
        // The Phase 7 reviewer verbs: suspend, settle and return-to-preparer.
        // Grouped under one permission because all three are the same
        // judgment call - approving is the only one that needs its own,
        // since it is the one act with no undo that matters.
        'payroll.suspend',
        // The pre-audit is conducted against these. Checking a daily rate
        // against the contract in force is the job, and from Phase 6 so is
        // checking a manual DTR entry against a covering bio exemption.
        'document.view', 'contract.view', 'shift.view',
        // The pre-audit reads the day rows a payroll line was derived from.
        'dtr.view', 'calendar.view', 'attachment.view',
        'report.view', 'print.run',
    ],

    // Keys the payroll. Deliberately without 'employee.sensitive'.
    'Encoder' => [
        'dashboard.view',
        'employee.view', 'office.view', 'timekeeper.view',
        'period.view', 'payroll.view', 'payroll.edit', 'payroll.submit',
        // Sees the memo authorising the overtime being keyed, and the shift
        // that says what late means. No contract access - that is the rate,
        // and this role deliberately has no route to the restricted tier.
        // Keying the DTR grid is the encoder's day job.
        'document.view', 'shift.view',
        'dtr.view', 'dtr.edit', 'calendar.view', 'attachment.view', 'attachment.edit',
        'print.run',
    ],

    // Sees their own office's records; no editing, no approval. What makes the
    // role useful is the scope grant, not the permission list.
    'Office Head' => [
        'dashboard.view',
        'employee.view', 'office.view', 'timekeeper.view',
        'period.view', 'payroll.view',
        'document.view', 'shift.view', 'dtr.view', 'calendar.view', 'attachment.view',
        'report.view', 'print.run',
    ],

    // COA liaison. Read-only oversight, and the audit log with it.
    'Internal Auditor' => [
        'dashboard.view', 'employee.view', 'office.view', 'timekeeper.view',
        'period.view', 'payroll.view', 'report.view', 'log.view',
        // Read-only oversight extends to the documents an audit is conducted
        // against, contracts included - the whole point of the role is to be
        // able to check the same things a pre-auditor checked.
        'document.view', 'contract.view', 'shift.view', 'dtr.view',
        'calendar.view', 'attachment.view',
    ],
];

/**
 * Attempts a login; on success the session is populated.
 * @throws RuntimeException on bad credentials / inactive account.
 */
function authLogin(string $email, string $password): array
{
    $user = DB::row('SELECT * FROM Users WHERE Email = ?', [$email]);
    if (!$user || !password_verify($password, $user['PasswordHash'])) {
        writeLog($email, 'LOGIN_FAILED', 'Auth', 'Invalid credentials');
        throw new RuntimeException('Invalid email or password.');
    }
    if ($user['Status'] !== 'Active') {
        throw new RuntimeException('Your account is ' . $user['Status'] . '. Contact the administrator.');
    }

    session_regenerate_id(true);
    $_SESSION['email'] = $user['Email'];
    $_SESSION['last'] = time();
    DB::update('Users', ['LastLogin' => date('Y-m-d H:i:s')], 'Email', $user['Email']);
    writeLog($user['Email'], 'LOGIN', 'Auth', 'User signed in');
    return $user;
}

/** Destroys the current session. */
function authLogout(): void
{
    $email = $_SESSION['email'] ?? '';
    if ($email) writeLog($email, 'LOGOUT', 'Auth', 'User signed out');
    $_SESSION = [];
    session_destroy();
}

/**
 * Resolves the signed-in user, enforcing registration, status and idle
 * timeout. Throws when access must be denied.
 * @return array Users row + 'permissions' list.
 */
function requireUser(): array
{
    $email = $_SESSION['email'] ?? '';
    if (!$email) throw new RuntimeException('Not signed in. Please sign in.');

    $minutes = (int) num(getSetting('SessionTimeoutMinutes', '30')) ?: 30;
    $last = (int) ($_SESSION['last'] ?? 0);
    if ($last && (time() - $last) > $minutes * 60) {
        authLogout();
        throw new RuntimeException("Your session expired after $minutes minutes of inactivity. Please sign in again.");
    }
    $_SESSION['last'] = time();

    $user = DB::row('SELECT * FROM Users WHERE Email = ?', [$email]);
    if (!$user) throw new RuntimeException('Access denied: account no longer registered.');
    if ($user['Status'] !== 'Active') {
        throw new RuntimeException('Your account is ' . $user['Status'] . '.');
    }
    $user['permissions'] = PERMISSIONS[$user['Role']] ?? [];
    unset($user['PasswordHash']);
    return $user;
}

/** Tests whether a user holds a permission. */
function hasPermission(array $user, string $permission): bool
{
    $perms = $user['permissions'] ?? (PERMISSIONS[$user['Role']] ?? []);
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

/** Asserts a permission ('' skips the check). */
function requirePermission(array $user, string $permission): void
{
    if ($permission === '' || hasPermission($user, $permission)) return;
    throw new RuntimeException('Access denied: your role (' . $user['Role'] . ') is not allowed to perform this action.');
}

/**
 * Appends an audit-trail entry. Never throws.
 */
function writeLog(string $email, string $action, string $module, string $details = ''): void
{
    try {
        DB::insert('Logs', [
            'User' => $email ?: 'system',
            'Action' => $action,
            'Module' => $module,
            'Details' => mb_substr($details, 0, 2000),
            'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('Audit log failure: ' . $e->getMessage());
    }
}

/* ==========================================================================
 * API endpoints (dispatched by public/api.php)
 * ======================================================================== */

/** Session context for the SPA boot. */
function apiGetSession(array $p, array $user): array
{
    return [
        'email' => $user['Email'],
        'fullName' => $user['FullName'],
        'role' => $user['Role'],
        'officeCode' => $user['OfficeCode'],
        'permissions' => $user['permissions'],
        'settings' => [
            'governmentName' => getSetting('GovernmentName', 'CITY GOVERNMENT OF DIGOS'),
            'subtitle' => getSetting('GovernmentSubtitle', ''),
            'logoUrl' => getSetting('OfficeLogoUrl', ''),
            'watermarkUrl' => getSetting('WatermarkUrl', ''),
            'watermarkOpacity' => watermarkOpacity(),
            'theme' => getSetting('SystemTheme', 'light'),
            'maxEmployeesPerPayroll' => (int) num(getSetting('MaxEmployeesPerPayroll', '15')),
            'sessionTimeoutMinutes' => (int) num(getSetting('SessionTimeoutMinutes', '30')),
        ],
    ];
}

/** Heartbeat: requireUser() already refreshed the idle timer. */
function apiHeartbeat(array $p, array $user): array
{
    return ['alive' => true];
}

/** Explicit logout from the SPA. */
function apiLogout(array $p, array $user): array
{
    authLogout();
    return ['loggedOut' => true];
}

/** Lists all system users. */
function apiListUsers(array $p, array $user): array
{
    return DB::rows('SELECT Email, FullName, Role, OfficeCode, Status, LastLogin, CreatedAt
                     FROM Users ORDER BY FullName');
}

/** Creates or updates a system user (optional Password resets it). */
function apiSaveUser(array $p, array $user): array
{
    requireFields($p, ['Email', 'FullName', 'Role']);
    if (!isEmail($p['Email'])) throw new RuntimeException('Invalid email address: ' . $p['Email']);
    if (!isset(PERMISSIONS[$p['Role']])) throw new RuntimeException('Unknown role: ' . $p['Role']);

    $record = [
        'FullName' => $p['FullName'],
        'Role' => $p['Role'],
        'OfficeCode' => $p['OfficeCode'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
    if (!empty($p['Password'])) {
        if (strlen((string) $p['Password']) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        $record['PasswordHash'] = password_hash((string) $p['Password'], PASSWORD_BCRYPT);
    }

    $existing = DB::row('SELECT Email FROM Users WHERE Email = ?', [$p['Email']]);
    if ($existing) {
        DB::update('Users', $record, 'Email', $p['Email']);
        return ['updated' => true, 'email' => $p['Email']];
    }
    if (empty($record['PasswordHash'])) {
        throw new RuntimeException('A password is required when creating a new user.');
    }
    $record['Email'] = $p['Email'];
    DB::insert('Users', $record);
    return ['created' => true, 'email' => $p['Email']];
}

/** Deletes a user (self-delete and last-admin are protected). */
function apiDeleteUser(array $p, array $user): array
{
    requireFields($p, ['Email']);
    if ($p['Email'] === $user['Email']) throw new RuntimeException('You cannot delete your own account.');

    $target = DB::row('SELECT Role FROM Users WHERE Email = ?', [$p['Email']]);
    if ($target && $target['Role'] === 'Administrator') {
        $admins = (int) DB::scalar(
            "SELECT COUNT(*) FROM Users WHERE Role = 'Administrator' AND Status = 'Active'");
        if ($admins <= 1) throw new RuntimeException('Cannot delete the last active administrator.');
    }
    return ['deleted' => DB::exec('DELETE FROM Users WHERE Email = ?', [$p['Email']])];
}

/** Role -> permission matrix for the Users screen. */
function apiGetRoles(array $p, array $user): array
{
    $out = [];
    foreach (PERMISSIONS as $role => $perms) {
        $out[] = ['role' => $role, 'permissions' => $perms];
    }
    return $out;
}

/** Filtered audit log, newest first. */
function apiGetLogs(array $p, array $user): array
{
    $sql = 'SELECT * FROM Logs WHERE 1=1';
    $params = [];
    if (!empty($p['dateFrom'])) { $sql .= ' AND Timestamp >= ?'; $params[] = $p['dateFrom'] . ' 00:00:00'; }
    if (!empty($p['dateTo']))   { $sql .= ' AND Timestamp <= ?'; $params[] = $p['dateTo'] . ' 23:59:59'; }
    if (!empty($p['search'])) {
        $sql .= ' AND (User LIKE ? OR Action LIKE ? OR Module LIKE ? OR Details LIKE ?)';
        $like = '%' . $p['search'] . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $limit = (int) num($p['limit'] ?? 300) ?: 300;
    $sql .= ' ORDER BY Timestamp DESC, LogID DESC LIMIT ' . min($limit, 1000);
    return DB::rows($sql, $params);
}
