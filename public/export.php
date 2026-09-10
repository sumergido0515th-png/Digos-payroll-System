<?php
/**
 * ============================================================================
 * export.php - A filtered list, as a CSV download.
 *
 *   /export.php?entity=Payroll&OfficeCode=CMO&...
 *
 * WHY THIS FILE CALLS THE EXISTING apiList* FUNCTION RATHER THAN A REPOSITORY
 * This is precisely the shape of the 2026-07-30 printBundle() leak: that
 * function took no user and queried Payroll directly, so apiGetPayroll
 * refused another office's payroll while apiGetPrintHtml rendered the same
 * number in full. An export built BESIDE the list path rather than ON TOP of
 * it would repeat that defect exactly - a second query someone forgets to
 * scope the next time a facet is added.
 *
 * So this file runs no query of its own. EXPORTABLE names, for each entity,
 * the exact apiList* function the SPA's own list screen calls; export.php
 * calls that same function with the same payload and turns whatever it
 * returns into CSV. The permission is repeated here rather than looked up
 * from public/api.php's ROUTES table (which is executable top-level code,
 * not includable) - tests/Architecture/ExportTableTest.php is what keeps the
 * two from drifting apart, the same way this file's structure keeps the
 * QUERY from drifting apart.
 * ============================================================================
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Digos\Domain\Query\Csv;
use Digos\Domain\Query\FilterSpec;

/**
 * entity => [the apiList* function the SPA's screen already calls, its permission].
 *
 * The permission must be kept identical to that action's entry in
 * public/api.php's ROUTES table - ExportTableTest asserts it on every run.
 *
 * @var array<string, array{0: string, 1: string}>
 */
const EXPORTABLE = [
    'Payroll' => ['apiListPayrolls', 'payroll.view'],
    'Employees' => ['apiListEmployees', 'employee.view'],
    'Memorandum' => ['apiListMemoranda', 'document.view'],
    'Suspensions' => ['apiListSuspensions', 'payroll.view'],
    'BioExemptions' => ['apiListBioExemptions', 'document.view'],
    'TravelOrders' => ['apiListTravelOrders', 'document.view'],
    'Contracts' => ['apiListContracts', 'contract.view'],
];

try {
    $entity = (string) ($_GET['entity'] ?? '');
    if (!isset(EXPORTABLE[$entity])) {
        throw new RuntimeException("Unknown export entity: $entity");
    }
    [$action, $permission] = EXPORTABLE[$entity];

    $user = requireUser();
    requirePermission($user, $permission);

    $result = $action($_GET, $user);

    // apiListEmployees is the one list action that wraps its rows in a
    // pagination envelope for the screen; every other apiList* here already
    // returns the bare row list. An export has no "page" - the filters are
    // what narrows it, the same as a shareable link - so this unwraps rather
    // than exporting one page at a time.
    $rows = $entity === 'Employees' ? $result['rows'] : $result;

    $filters = FilterSpec::fromPayload($entity, $_GET)->describe();
    $csv = Csv::render($rows, $filters, $entity);

    // This file talks to the apiList* function in-process, so api.php's
    // automatic per-route audit log never fires here - the same gap
    // print.php's own comment explains for PREVIEW/PRINT.
    writeLog($user['Email'], 'EXPORT_CSV', $entity, count($rows) . ' row(s)');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'
        . strtolower($entity) . '-export-' . date('Ymd-His') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;

} catch (Throwable $e) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo $e->getMessage();
}
