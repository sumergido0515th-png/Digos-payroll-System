<?php
/**
 * ============================================================================
 * ApplicationLayer - Loads the api* functions for integration tests.
 *
 * app/bootstrap.php is deliberately NOT used (see CLAUDE.md > Traps): it is the
 * production entry point and pulls in the whole application including a session
 * start. The files below are required in the same order it uses, which is the
 * dependency order.
 *
 * Safety: DB:: reads the DB_NAME *constant*, and app/config.php defaults that
 * to the working database. tests/bootstrap.php pins it to the test database
 * before anything here can load the config, and the assertion below refuses to
 * proceed if that did not happen - because the failure mode is an integration
 * test truncating live payroll data, which no test result is worth.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Tests\Integration;

use RuntimeException;

final class ApplicationLayer
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;

        $expected = TestDatabase::config()['name'];

        if (!defined('DB_NAME') || DB_NAME !== $expected) {
            throw new RuntimeException(sprintf(
                "Refusing to load the application layer against '%s' instead of '%s'.\n"
                . 'tests/bootstrap.php pins DB_NAME; something loaded app/config.php first.',
                defined('DB_NAME') ? DB_NAME : '(undefined)', $expected));
        }

        foreach ([
            'app/config.php',
            'app/Database.php',
            'app/Helpers.php',
            'app/Domain/Access/ScopeEntity.php',
            'app/Domain/Access/ScopePredicate.php',
            'app/Domain/Dtr/PeriodTotals.php',
            'app/Domain/Resolver/HolidayResolver.php',
            'app/Domain/Resolver/AuthorityResolver.php',
            'app/Domain/Resolver/ShiftResolver.php',
            'app/Domain/Coverage/CoverageMatrix.php',
            'app/Domain/Rules/Severity.php',
            'app/Domain/Rules/Finding.php',
            'app/Domain/Rules/RuleEngine.php',
            'app/Domain/Workflow/PayrollWorkflow.php',
            'app/Domain/Print/PayloadHash.php',
            'app/Domain/Import/SourceTable.php',
            'app/Domain/Import/ColumnMap.php',
            'app/Domain/Import/EntitySpec.php',
            'app/Domain/Query/FilterSpec.php',
            'app/Domain/Query/FilterSql.php',
            'app/Domain/Query/Watchlist.php',
            'app/Repo/FacetOptions.php',
            'app/Repo/ScopeGrantRepo.php',
            'app/Repo/ScopeGateway.php',
            'app/Repo/EmployeeRepo.php',
            'app/Repo/PayrollRepo.php',
            'app/Repo/ReferenceRepo.php',
            'app/Repo/SettingsRepo.php',
            'app/Repo/BackupRepo.php',
            'app/Repo/MemorandumRepo.php',
            'app/Repo/EmployeeDocumentRepo.php',
            'app/Repo/WorkShiftRepo.php',
            'app/Repo/ContractRepo.php',
            'app/Repo/DtrRepo.php',
            'app/Repo/HolidayRepo.php',
            'app/Repo/AttachmentRepo.php',
            'app/Repo/SuspensionRepo.php',
            'app/Repo/PrintLogRepo.php',
            'app/Repo/ImportRepo.php',
            'app/Settings.php',
            'app/Auth.php',
            'app/Access.php',
            'app/Master.php',
            'app/Import.php',
            'app/Payroll.php',
            'app/Documents.php',
            'app/Dtr.php',
            'app/Calendar.php',
            'app/Attachments.php',
            'app/PreAudit.php',
            // PrintDoc last, as app/bootstrap.php loads it. It was missing here
            // and PrintScopeTest still passed in a full run, because
            // tests/Unit/EmployeesFunctionTest.php requires the file and the
            // unit suite runs first - so the print tests passed on a side
            // effect of an unrelated test's load order, and failed the moment
            // the file was run on its own.
            'app/PrintDoc.php',
        ] as $file) {
            require_once PROJECT_ROOT . '/' . $file;
        }

        self::$loaded = true;
    }
}
