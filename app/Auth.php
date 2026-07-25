<?php
/**
 * ============================================================================
 * Auth.php - Email/password authentication, role-based access control,
 * session timeout and the audit-trail writer. Mirrors src/Auth.gs, with
 * Google sign-in replaced by PHP sessions + bcrypt passwords.
 * ============================================================================
 */

declare(strict_types=1);

/** Permission matrix. '*' grants everything (Administrator only). */
const PERMISSIONS = [
    'Administrator' => ['*'],

    'HR' => [
        'dashboard.view',
        'employee.view', 'employee.edit',
        'office.view', 'office.edit',
        'timekeeper.view', 'timekeeper.edit',
        'payroll.view', 'period.view',
        'report.view', 'print.run',
    ],

    'Payroll Officer' => [
        'dashboard.view',
        'employee.view',
        'office.view', 'timekeeper.view',
        'period.view', 'period.edit',
        'payroll.view', 'payroll.edit', 'payroll.submit',
        'report.view', 'print.run',
    ],

    'Accounting' => [
        'dashboard.view',
        'employee.view', 'office.view',
        'period.view', 'payroll.view', 'payroll.approve', 'payroll.release',
        'report.view', 'print.run',
    ],

    'Timekeeper' => [
        'dashboard.view',
        'employee.view', 'office.view', 'timekeeper.view',
        'period.view', 'payroll.view', 'payroll.edit', 'payroll.submit',
        'print.run',
    ],

    'Viewer' => [
        'dashboard.view', 'employee.view', 'office.view', 'timekeeper.view',
        'period.view', 'payroll.view', 'report.view',
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
