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
 * Branding images (office logo, page watermark)
 * ======================================================================== */

/**
 * The settings an image may be uploaded into, and the file-name prefix each
 * one is stored under.
 *
 * This is an allowlist for the same reason interpolated column names are: the
 * key arrives in the payload, and without it a caller could aim an upload at
 * any setting in the table - SystemTheme, MaxEmployeesPerPayroll, or the
 * signatory names printed on a voucher.
 */
const IMAGE_SETTINGS = [
    'OfficeLogoUrl' => 'LOGO',
    'PrintLogoUrl' => 'SEAL',
    'WatermarkUrl' => 'MARK',
];

/** Accepted formats: detected image type => the extension we store it as. */
const IMAGE_TYPES = [
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/** Largest image accepted. The logo prints at ~62px wide, so this is generous. */
const IMAGE_MAX_BYTES = 2097152;

/**
 * How faint the watermark may be forced to stay, whatever is typed in.
 *
 * The point of the ceiling is readability: past roughly a quarter opacity a
 * seal with any solid area of its own starts competing with the text sitting
 * over it, and the dashboard figures are what people are there to read. The
 * floor stops a value of 0 being mistaken for "the upload did not work".
 */
const WATERMARK_MIN_OPACITY = 0.02;
const WATERMARK_MAX_OPACITY = 0.25;

/**
 * Stores an uploaded branding image and points its setting at it.
 *
 * Payload: {key: "OfficeLogoUrl", data: "data:image/png;base64,..."}. The
 * browser reads the file and sends it inline because api.php speaks JSON only
 * - there is no multipart endpoint to add one to.
 *
 * The stored name and extension are generated here from the *detected* image
 * type and never from what the browser sent: a caller-supplied file name is
 * how a ".php" ends up under the web root. SVG is refused for the same reason
 * - it is a script container, and this directory is served same-origin.
 */
function apiUploadImageSetting(array $p, array $user): array
{
    requireFields($p, ['key', 'data']);

    $key = (string) $p['key'];
    $prefix = IMAGE_SETTINGS[$key] ?? null;
    if ($prefix === null) throw new RuntimeException('That setting does not hold an image.');

    if (!preg_match('~^data:image/[\w.+-]+;base64,~', (string) $p['data'], $m)) {
        throw new RuntimeException('That file is not an image. Choose a PNG, JPG, GIF or WEBP file.');
    }
    $binary = base64_decode(substr((string) $p['data'], strlen($m[0])), true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('The image could not be read. Open it, save it again and retry.');
    }
    if (strlen($binary) > IMAGE_MAX_BYTES) {
        throw new RuntimeException('That image is bigger than 2 MB. Please use a smaller one.');
    }

    // The file's own header decides the format, not the type the browser
    // declared and not the file name.
    $info = getimagesizefromstring($binary);
    $ext = $info ? (IMAGE_TYPES[$info[2]] ?? null) : null;
    if ($ext === null) {
        throw new RuntimeException('Unsupported image format. Save it as PNG, JPG, GIF or WEBP.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Cannot create the upload folder. Check public/assets/uploads permissions.');
    }

    $name = newId($prefix) . '.' . $ext;
    if (file_put_contents(UPLOAD_DIR . DIRECTORY_SEPARATOR . $name, $binary) === false) {
        throw new RuntimeException('Cannot save the image file. Check public/assets/uploads permissions.');
    }

    $previous = getSetting($key);
    $url = UPLOAD_URL . '/' . $name;
    setSetting($key, $url);
    deleteUploadedImage($previous);

    return ['key' => $key, 'url' => $url, 'fileName' => $name];
}

/**
 * Deletes a superseded branding image, but only when it is one of ours. The
 * setting can equally hold a URL somebody typed in by hand, and neither an
 * external address nor a stray "../" in one may reach unlink().
 */
function deleteUploadedImage(string $url): void
{
    if (!str_starts_with($url, UPLOAD_URL . '/')) return;

    $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($url);
    if (is_file($path)) @unlink($path);
}

/**
 * The watermark opacity as a number the CSS can use, clamped to the readable
 * range. Applied on read rather than on save so that a value already in the
 * table - typed in before the bound existed, or restored from a backup -
 * cannot make a page unreadable either.
 */
function watermarkOpacity(): float
{
    $value = (float) num(getSetting('WatermarkOpacity', '0.20'));
    return round(max(WATERMARK_MIN_OPACITY, min(WATERMARK_MAX_OPACITY, $value)), 3);
}

/**
 * A branding URL that is safe to drop inside a CSS url(), or '' if it is not.
 *
 * The SPA gets these settings as JSON and can escape them at the point of use,
 * but login.php has no session to ask and renders its watermark into a <style>
 * block server-side. CSS nested in HTML has two escaping rules that do not
 * compose - htmlspecialchars() does not neutralise a quote inside url(), and
 * entities are not decoded inside <style> - so anything that could end the
 * url() token or the block around it is refused outright instead. These
 * settings are free text: the field takes a hand-typed URL as well as an
 * upload. '' is what the caller draws when no watermark is configured anyway.
 */
function cssSafeImageUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('~[\s"\'()<>{};\\\\]~', $url)) return '';

    if (str_starts_with($url, UPLOAD_URL . '/')) return str_contains($url, '..') ? '' : $url;

    return preg_match('~^https?://~i', $url) ? $url : '';
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
