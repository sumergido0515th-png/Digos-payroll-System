<?php
/**
 * ============================================================================
 * Settings.php - Key/value settings (request-cached) and SQL backup /
 * restore. Backups are plain SQL dump files written to php/backups/
 * (outside the web root) and downloaded through download.php.
 * ============================================================================
 */

declare(strict_types=1);

/** Tables included in backups, in restore-safe order. */
const BACKUP_TABLES = ['Employees', 'Offices', 'Departments', 'Functions', 'Timekeepers',
    'PayrollPeriods', 'Payroll', 'PayrollDetails', 'Users', 'Logs', 'Settings', 'Counters'];

/** Returns the whole Settings table as a map, cached per request. */
function settingsMap(bool $refresh = false): array
{
    static $map = null;
    if ($map === null || $refresh) {
        $map = [];
        foreach (DB::rows('SELECT KeyName, Value FROM Settings') as $r) {
            $map[$r['KeyName']] = $r['Value'];
        }
    }
    return $map;
}

/** Reads one setting with a fallback default. */
function getSetting(string $key, string $fallback = ''): string
{
    $v = settingsMap()[$key] ?? null;
    return ($v === null || $v === '') ? $fallback : (string) $v;
}

/** Writes one setting (upsert) and refreshes the cache. */
function setSetting(string $key, mixed $value): void
{
    DB::exec('INSERT INTO Settings (KeyName, Value) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE Value = VALUES(Value)', [$key, (string) $value]);
    settingsMap(true);
}

/** Every setting for the Settings screen. */
function apiGetSettings(array $p, array $user): array
{
    return settingsMap(true);
}

/** Saves a batch of settings. Payload: {settings: {Key: Value}} */
function apiSaveSettings(array $p, array $user): array
{
    $entries = $p['settings'] ?? [];
    if (!$entries) throw new RuntimeException('Nothing to save.');
    foreach ($entries as $k => $v) setSetting((string) $k, $v);
    return ['saved' => count($entries)];
}

/* ==========================================================================
 * Backup & restore
 * ======================================================================== */

/**
 * Dumps every table to a SQL file in BACKUP_DIR and registers it.
 * Shared by the manual button and cron.php.
 */
function runBackup(string $type, string $userEmail): array
{
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0775, true);

    $stamp = date('Y-m-d_Hi');
    $name = 'PayrollDB_Backup_' . $stamp . '_' . random_int(100, 999) . '.sql';
    $path = BACKUP_DIR . DIRECTORY_SEPARATOR . $name;

    $fh = fopen($path, 'wb');
    if (!$fh) throw new RuntimeException('Cannot write backup file. Check php/backups permissions.');

    fwrite($fh, "-- Digos Payroll backup $stamp\nSET FOREIGN_KEY_CHECKS=0;\n");
    $pdo = DB::pdo();
    foreach (BACKUP_TABLES as $table) {
        fwrite($fh, "\nDELETE FROM `$table`;\n");
        $st = $pdo->query("SELECT * FROM `$table`");
        while ($row = $st->fetch()) {
            $cols = '`' . implode('`,`', array_keys($row)) . '`';
            $vals = implode(',', array_map(
                fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row)));
            fwrite($fh, "INSERT INTO `$table` ($cols) VALUES ($vals);\n");
        }
    }
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    $backupId = newId('BAK');
    DB::insert('Backup', [
        'BackupID' => $backupId,
        'FileID' => $name,                      // file name doubles as the id
        'FileName' => $name,
        'User' => $userEmail ?: 'system',
        'Type' => $type,
    ]);
    return ['fileId' => $name, 'fileName' => $name];
}

/** Manual backup now. */
function apiBackupNow(array $p, array $user): array
{
    return runBackup('Manual', $user['Email']);
}

/** Lists recorded backups, newest first, with download URLs. */
function apiListBackups(array $p, array $user): array
{
    return array_map(function ($b) {
        $b['Url'] = 'download.php?id=' . urlencode($b['FileID']);
        return $b;
    }, DB::rows('SELECT * FROM Backup ORDER BY Timestamp DESC'));
}

/**
 * Restores data from a registered backup file. A safety backup of the
 * current state is taken first; the Backup registry itself is preserved.
 */
function apiRestoreBackup(array $p, array $user): array
{
    requireFields($p, ['FileID']);
    $entry = DB::row('SELECT * FROM Backup WHERE FileID = ?', [$p['FileID']]);
    if (!$entry) throw new RuntimeException('Unknown backup file.');

    $path = BACKUP_DIR . DIRECTORY_SEPARATOR . basename($entry['FileName']);
    if (!is_file($path)) throw new RuntimeException('Backup file is missing on disk: ' . $entry['FileName']);

    runBackup('Pre-restore safety', $user['Email']);

    $sql = file_get_contents($path);
    DB::tx(function () use ($sql) {
        foreach (explode(";\n", $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) continue;
            DB::pdo()->exec($statement);
        }
    });
    settingsMap(true);
    return ['restored' => BACKUP_TABLES];
}

/**
 * Persists the BackupSchedule setting. Actual scheduling is done by the OS
 * (Windows Task Scheduler / cron) running php/cron.php - see the README.
 */
function apiApplyBackupSchedule(array $p, array $user): array
{
    return ['schedule' => getSetting('BackupSchedule', 'weekly'),
        'note' => 'Schedule saved. Ensure cron.php is registered with your OS scheduler.'];
}
