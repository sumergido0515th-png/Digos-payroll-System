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

    // Delegates to apiGetPrintHtml() rather than calling buildFormHtml()
    // directly, as this file did before Phase 8. That direct call was a
    // second, unguarded path to every printed form: it never assigned a
    // serial, never checked the payload hash, and never enforced a reprint
    // reason, regardless of ?official= - the whole gate lived only in the
    // SPA's API route and this bookmarkable URL walked straight around it.
    $result = apiGetPrintHtml([
        'PayrollNo' => $no,
        'form' => $form,
        'NsNo' => (string) ($_GET['ns'] ?? ''),
        'official' => !empty($_GET['official']),
        'ReprintReason' => (string) ($_GET['reason'] ?? ''),
    ], $user);

    // This file talks to apiGetPrintHtml() in-process rather than through
    // api.php's HTTP dispatcher, so its automatic per-route audit log never
    // fires here - recordOfficialPrint() already wrote PrintLog for an
    // Official request, but a plain preview would otherwise leave no trace
    // at all. PREVIEW and PRINT stay distinct because "pages printed per
    // period" is one of the project's post-launch metrics, and the review
    // loop this screen supports is designed to produce many previews -
    // counting a reviewer opening a Draft six times as six pages printed
    // would make that baseline meaningless.
    writeLog($user['Email'], $result['official'] ? 'PRINT' : 'PREVIEW', 'Print',
        $no . ' [' . $form . ']' . ($result['official'] ? ' Official' : ''));

    echo $result['html'];

} catch (Throwable $e) {
    http_response_code(403);
    echo '<div style="font-family:system-ui;max-width:520px;margin:80px auto;padding:32px;'
        . 'border:1px solid #ddd;border-radius:12px;text-align:center">'
        . '<h2 style="color:#0b3d91">Digos City Payroll System</h2>'
        . '<p style="color:#b00020">' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p><a href="login.php">Sign in</a></p></div>';
}
