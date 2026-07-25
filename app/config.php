<?php
/**
 * ============================================================================
 * config.php - Environment configuration for the Digos Payroll System (PHP).
 * Edit these values for your server; everything else is automatic.
 * ============================================================================
 */

declare(strict_types=1);

// --- Database ---------------------------------------------------------------
const DB_HOST = '127.0.0.1';
const DB_NAME = 'digos_payroll';
const DB_USER = 'root';
const DB_PASS = '';

// --- Application ------------------------------------------------------------
const APP_TIMEZONE = 'Asia/Manila';

/** Directory where SQL backups are written (outside the web root). */
define('BACKUP_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups');

// --- Mail (payslips) --------------------------------------------------------
/** From-address used by mail(); configure SMTP in php.ini for delivery. */
const MAIL_FROM = 'payroll@digoscity.gov.ph';

// --- Bootstrap --------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');          // errors go to the JSON envelope/log
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true,             // enable when serving over HTTPS
    ]);
    session_start();
}
