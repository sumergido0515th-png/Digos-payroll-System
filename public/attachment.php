<?php
/**
 * ============================================================================
 * attachment.php - Streams a payroll attachment to a caller whose scope
 * covers somebody it is evidence about.
 *
 *   /attachment.php?id=<AttachmentID>
 *
 * WHY THIS EXISTS RATHER THAN A URL UNDER public/
 * These are scanned memoranda and medical certificates justifying a person's
 * pay. Serving them from a static path would make them readable by anyone who
 * guessed a file name, and the scope layer would have nothing to say about it.
 * They live in ATTACHMENT_DIR, outside the web root, and reaching them means
 * passing through AttachmentRepo::findScoped() - the same predicate that
 * decides every other read.
 *
 * Inline rather than as a download: the point of the coverage matrix is that
 * a pre-auditor clicks a cell and looks at the evidence, and forcing a save
 * dialog on every one of those would make the screen unusable.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Digos\Repo\AttachmentRepo;

try {
    $user = requireUser();
    requirePermission($user, 'attachment.view');

    $attachment = AttachmentRepo::findScoped($user, (string) ($_GET['id'] ?? ''));

    // Absent and out of scope report the same thing, as everywhere else in
    // this layer: a 404 that means "exists, not yours" is itself a disclosure.
    if (!$attachment) throw new RuntimeException('Attachment not found.');

    // basename() even though StoredName is generated. A tampered row must not
    // be able to reach outside the folder, and the cost of being sure is one
    // function call.
    $path = ATTACHMENT_DIR . DIRECTORY_SEPARATOR . basename((string) $attachment['StoredName']);
    if (!is_file($path)) throw new RuntimeException('The attachment file is missing on disk.');

    writeLog($user['Email'], 'VIEW_ATTACHMENT', 'Attachments',
        $attachment['AttachmentID'] . ' ' . $attachment['FileName']);

    // The stored MIME type, which was decided from the file's own leading
    // bytes at upload - never from what the browser declared.
    header('Content-Type: ' . ($attachment['MimeType'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="'
        . str_replace('"', '', basename((string) $attachment['FileName'])) . '"');
    header('Content-Length: ' . filesize($path));

    // Nothing here should be cached by a shared proxy: the URL is the same for
    // every caller and only the session decides who may read it.
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');

    readfile($path);

} catch (Throwable $e) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}
