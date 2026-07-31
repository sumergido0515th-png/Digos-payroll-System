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

/**
 * Where uploaded payroll attachments are stored.
 *
 * Outside the web root, next to backups/, and NOT under public/assets/uploads
 * where the branding images live. These are scanned memoranda and medical
 * certificates justifying somebody's pay - a guessable URL would serve them to
 * anyone who tried one. They are streamed by public/attachment.php, which
 * checks the caller's scope first.
 */
define('ATTACHMENT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'attachments');

/**
 * Where uploaded branding images (office logo, page watermark) are stored.
 * Unlike backups this is inside the web root, because the browser and the
 * print view fetch it directly by URL. Only files whose extension this
 * application generates itself ever land here - see apiUploadImageSetting().
 */
define('UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public'
    . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads');

/** The URL that maps to UPLOAD_DIR, relative to the pages in public/. */
define('UPLOAD_URL', 'assets/uploads');

// --- Mail (payslips) --------------------------------------------------------
/** From-address used by mail(); configure SMTP in php.ini for delivery. */
defined('MAIL_FROM') || define('MAIL_FROM', 'payroll@digoscity.gov.ph');

// --- Bootstrap --------------------------------------------------------------
date_default_timezone_set(APP_TIMEZONE);
error_reporting(E_ALL);
ini_set('display_errors', '0');          // errors go to the JSON envelope/log
ini_set('log_errors', '1');

/**
 * True when the request reached us over HTTPS.
 *
 * Shared hosts and CDNs terminate TLS at a proxy and forward plain HTTP, so
 * $_SERVER['HTTPS'] alone reports "no" on a site that is in fact encrypted.
 * The forwarded headers are only trustworthy behind such a proxy - which is
 * exactly where they appear - and the consequence of believing them wrongly
 * is a cookie marked Secure on a plain-HTTP site, which fails safe: the
 * browser simply refuses to send it.
 */
function requestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;

    $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwarded === 'https') return true;

    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
}

// CLI entry points (cron.php, tools/*.php) have no browser and no session.
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // Set automatically rather than by hand: this system now runs on
        // hosting where forgetting it means session cookies for a payroll
        // system travelling in clear text.
        'secure' => requestIsHttps(),
    ]);
    session_start();
}
