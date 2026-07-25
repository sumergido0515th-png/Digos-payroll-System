<?php
/**
 * ============================================================================
 * cron.php - Automatic backup runner (CLI only).
 *
 * Register with your OS scheduler; the script itself honours the
 * BackupSchedule setting (off / daily / weekly - weekly fires on Sundays),
 * so a single daily registration is enough:
 *
 *   Windows Task Scheduler (daily 02:00):
 *     php.exe C:\path\to\php\cron.php
 *   Linux crontab:
 *     0 2 * * * php /path/to/php/cron.php
 * ============================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/app/bootstrap.php';

$schedule = getSetting('BackupSchedule', 'weekly');
if ($schedule === 'off') {
    echo "Backup schedule is off; nothing to do.\n";
    exit(0);
}
if ($schedule === 'weekly' && (int) date('w') !== 0) {
    echo "Weekly schedule: not Sunday; nothing to do.\n";
    exit(0);
}

try {
    $result = runBackup('Automatic', 'system');
    echo 'Backup created: ' . $result['fileName'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Automatic backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
