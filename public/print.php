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

    // Resolved here as well as inside buildFormHtml, because the audit action
    // depends on the payroll's status and that decision belongs outside the
    // renderer. Two scoped lookups on a primary key is the cost.
    //
    // PREVIEW and PRINT are separate deliberately: "pages printed per period"
    // is one of the project's post-launch metrics - the resource waste this
    // system exists to reduce - and the review loop this screen supports is
    // designed to produce many previews. Counting a reviewer opening a draft
    // six times as six pages printed would make the baseline meaningless.
    $header = \Digos\Repo\PayrollRepo::findScoped($user, $no);
    if (!$header) throw new RuntimeException('Payroll not found: ' . $no);

    $action = payrollPrintIsOfficial((string) $header['Status']) ? 'PRINT' : 'PREVIEW';
    writeLog($user['Email'], $action, 'Print',
        $no . ' [' . $form . '] ' . $header['Status']);

    echo buildFormHtml($no, $form, $user);

} catch (Throwable $e) {
    http_response_code(403);
    echo '<div style="font-family:system-ui;max-width:520px;margin:80px auto;padding:32px;'
        . 'border:1px solid #ddd;border-radius:12px;text-align:center">'
        . '<h2 style="color:#0b3d91">Digos City Payroll System</h2>'
        . '<p style="color:#b00020">' . htmlspecialchars($e->getMessage()) . '</p>'
        . '<p><a href="login.php">Sign in</a></p></div>';
}
