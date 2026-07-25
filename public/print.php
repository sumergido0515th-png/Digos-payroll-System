<?php
/**
 * print.php - Standalone print view: /print.php?no=PR-2026-000001
 * Renders the blank General Payroll template populated with the payroll's
 * data. Use the browser's print dialog for paper or Save-as-PDF.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

try {
    $user = requireUser();
    requirePermission($user, 'print.run');

    $no = (string) ($_GET['no'] ?? '');
    if ($no === '') throw new RuntimeException('Missing payroll number (?no=...).');
    $form = (string) ($_GET['form'] ?? 'payroll');

    writeLog($user['Email'], 'PRINT', 'Print', $no . ' [' . $form . ']');
    echo buildFormHtml($no, $form);

} catch (Throwable $e) {
    http_response_code(403);
    echo '<div style="font-family:system-ui;max-width:520px;margin:80px auto;padding:32px;'
        . 'border:1px solid #ddd;border-radius:12px;text-align:center">'
        . '<h2 style="color:#0b3d91">Digos City Payroll System</h2>'
        . '<p style="color:#b00020">' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p><a href="login.php">Sign in</a></p></div>';
}
