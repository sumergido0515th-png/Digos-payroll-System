<?php
/**
 * bootstrap.php - Loads the whole application layer in dependency order.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';

// Access layer, before anything that queries. Required explicitly rather than
// autoloaded: composer's autoloader lives in vendor/, which tools/build-deploy.php
// deliberately does not ship - "nothing at runtime requires it" - so pulling it
// in here would break the shared-host package the moment it was uploaded. The
// PSR-4 mapping in composer.json still serves the test suite, which does load
// the autoloader; require_once resolves by path, so a class reached both ways
// is only ever defined once.
require_once __DIR__ . '/Domain/Access/ScopeEntity.php';
require_once __DIR__ . '/Domain/Access/ScopePredicate.php';
require_once __DIR__ . '/Domain/Dtr/PeriodTotals.php';
require_once __DIR__ . '/Domain/Resolver/HolidayResolver.php';
require_once __DIR__ . '/Domain/Resolver/AuthorityResolver.php';
require_once __DIR__ . '/Domain/Resolver/ShiftResolver.php';
require_once __DIR__ . '/Domain/Coverage/CoverageMatrix.php';
require_once __DIR__ . '/Domain/Rules/Severity.php';
require_once __DIR__ . '/Domain/Rules/Finding.php';
require_once __DIR__ . '/Domain/Rules/RuleEngine.php';
require_once __DIR__ . '/Domain/Workflow/PayrollWorkflow.php';
require_once __DIR__ . '/Repo/ScopeGrantRepo.php';
require_once __DIR__ . '/Repo/ScopeGateway.php';
require_once __DIR__ . '/Repo/EmployeeRepo.php';
require_once __DIR__ . '/Repo/PayrollRepo.php';
require_once __DIR__ . '/Repo/ReferenceRepo.php';
require_once __DIR__ . '/Repo/SettingsRepo.php';
require_once __DIR__ . '/Repo/BackupRepo.php';
require_once __DIR__ . '/Repo/MemorandumRepo.php';
require_once __DIR__ . '/Repo/EmployeeDocumentRepo.php';
require_once __DIR__ . '/Repo/WorkShiftRepo.php';
require_once __DIR__ . '/Repo/ContractRepo.php';
require_once __DIR__ . '/Repo/DtrRepo.php';
require_once __DIR__ . '/Repo/HolidayRepo.php';
require_once __DIR__ . '/Repo/AttachmentRepo.php';
require_once __DIR__ . '/Repo/SuspensionRepo.php';

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Access.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Master.php';
require_once __DIR__ . '/Payroll.php';
require_once __DIR__ . '/Documents.php';
require_once __DIR__ . '/Dtr.php';
require_once __DIR__ . '/Calendar.php';
require_once __DIR__ . '/Attachments.php';
require_once __DIR__ . '/PreAudit.php';
require_once __DIR__ . '/Reports.php';
require_once __DIR__ . '/PrintDoc.php';
