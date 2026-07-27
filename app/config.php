<?php
/**
 * ============================================================================
 * config.php - Environment configuration for the Digos Payroll System (PHP).
 * Edit these values for your server; everything else is automatic.
 * ============================================================================
 */

declare(strict_types=1);

// --- Local overrides --------------------------------------------------------
// app/config.local.php is git-ignored and defines whatever it needs to change.
// Real credentials belong there, never in this tracked file. Anything it
// defines wins, because the defaults below only fill in what is still unset.
//
// Create one with: php tools/create-app-user.php
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// --- Database ---------------------------------------------------------------
// Defaults suit a fresh XAMPP install. The root/no-password account has full
// DDL rights over every database on the server - fine for a first run, wrong
// for anything holding real payroll data. Run tools/create-app-user.php to
// generate a least-privilege account and the config.local.php that uses it.
defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'digos_payroll');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

/**
 * Session sql_mode applied to every connection.
 *
 * STRICT_ALL_TABLES is the important one and is NOT enabled by default on
 * MariaDB 10.4 as shipped with XAMPP. Without it the server silently coerces
 * bad values instead of rejecting them: a DECIMAL(12,2) column given the
 * string "12,450.00" stores 12.00, and an over-long name is truncated at the
 * column width. In a payroll system those are wrong numbers on a printed
 * voucher rather than an error someone can act on.
 *
 * Setting it per connection means this holds regardless of how the server
 * itself is configured, which matters because the deployment target is a
 * stock XAMPP install nobody is going to re-tune.
 */
defined('DB_SQL_MODE') || define('DB_SQL_MODE',
    'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION');

// --- Application ------------------------------------------------------------
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Manila');

/** Directory where SQL backups are written (outside the web root). */
define('BACKUP_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups');

// --- Mail (payslips) --------------------------------------------------------
/** From-address used by mail(); configure SMTP in php.ini for delivery. */
defined('MAIL_FROM') || define('MAIL_FROM', 'payroll@digoscity.gov.ph');

// --- Bootstrap --------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');          // errors go to the JSON envelope/log
ini_set('log_errors', '1');

// CLI entry points (cron.php, tools/*.php) have no browser and no session.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true,             // enable when serving over HTTPS
    ]);
    session_start();
}
