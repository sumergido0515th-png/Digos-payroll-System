<?php
/**
 * download.php - Streams a registered backup file to administrators.
 * /download.php?id=<FileID>  (FileID = registered backup file name)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $user = requireUser();
    requirePermission($user, 'backup.run');

    $id = (string) ($_GET['id'] ?? '');
    $entry = DB::row('SELECT * FROM Backup WHERE FileID = ?', [$id]);
    if (!$entry) throw new RuntimeException('Unknown backup file.');

    // basename() prevents any path traversal via a tampered registry row.
    $path = BACKUP_DIR . DIRECTORY_SEPARATOR . basename($entry['FileName']);
    if (!is_file($path)) throw new RuntimeException('Backup file missing on disk.');

    writeLog($user['Email'], 'DOWNLOAD_BACKUP', 'Backup', $entry['FileName']);
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($entry['FileName']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);

} catch (Throwable $e) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}
