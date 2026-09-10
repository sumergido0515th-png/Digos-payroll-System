<?php
/**
 * ============================================================================
 * ErrorLog.php - Out-of-phase, requested directly. Everything CLAUDE.md's own
 * error convention leaves uncovered: "Errors are throw new
 * RuntimeException($humanReadableMessage)... write it for a timekeeper" is a
 * promise about the errors someone anticipated. It says nothing about a
 * TypeError, a PDOException, or a JS exception a screen throws mid-render -
 * and until now those reached api.php's single catch(Throwable) block the
 * same way a RuntimeException does, so $e->getMessage() went to the browser
 * either way. That is not "written for a timekeeper" - it is whatever PHP or
 * a stack trace happened to say, which can be a file path, a query fragment,
 * or a class name nobody meant to disclose.
 *
 * ErrorLogRepo (app/Repo/ErrorLogRepo.php) is where these land instead. This
 * file is the two ends of that pipe: capture (installErrorHandlers(),
 * logUnexpectedError(), and api.php's dispatcher calling the latter directly)
 * and the two routes that read/write it.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\ErrorLogRepo;

/**
 * Installs the net under every entry point that is not api.php - print.php,
 * export.php, attachment.php, download.php, login.php, index.php - none of
 * which wrap their whole body in a try/catch the way api.php's dispatcher
 * does. api.php never reaches this handler for its own action calls: they
 * are already inside its try/catch, which calls logUnexpectedError()
 * directly so it can also shape the response. This is only for what escapes
 * every catch block that exists - a genuinely uncaught exception, or a fatal
 * error PHP itself stops the request for.
 */
function installErrorHandlers(): void
{
    set_exception_handler(function (Throwable $e): void {
        logUnexpectedError($e);
    });

    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err === null) return;
        if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;

        ErrorLogRepo::record([
            'Source' => 'php',
            'Message' => $err['message'],
            'File' => $err['file'],
            'Line' => $err['line'],
            'Url' => currentRequestUrl(),
            'UserEmail' => (string) ($_SESSION['email'] ?? ''),
            'UserAgent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    });
}

/**
 * Records an unexpected Throwable and returns a short reference a user can
 * quote when reporting it, rather than the raw message.
 *
 * api.php's catch block is the one caller where the return value actually
 * changes what a user sees; everywhere else this fires this is the last
 * thing that runs before PHP's own (display_errors=0, so blank) response.
 */
function logUnexpectedError(Throwable $e): string
{
    ErrorLogRepo::record([
        'Source' => 'php',
        'Message' => $e->getMessage(),
        'File' => $e->getFile(),
        'Line' => $e->getLine(),
        'Trace' => $e->getTraceAsString(),
        'Url' => currentRequestUrl(),
        'UserEmail' => (string) ($_SESSION['email'] ?? ''),
        'UserAgent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);

    return strtoupper(substr(
        sha1($e->getMessage() . $e->getFile() . $e->getLine() . microtime()), 0, 8));
}

/** Best-effort host+path the current request was for. */
function currentRequestUrl(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return $host !== '' ? $host . $uri : $uri;
}

/**
 * Filtered application error log, newest first - the Audit Logs screen's
 * companion table.
 *
 * **Citywide by the same decision as `apiGetLogs()`**, whose docblock carries
 * the reasoning: it shares `log.view`, so it shares that permission's holders
 * (Internal Auditor and Admin) and its citywide reach. `$user` is accepted and
 * unused for that reason, not because scoping was forgotten.
 *
 * `ErrorLog` has no office column to scope *by* - it is shaped after `Logs`,
 * an append-only operational record - but it is still content rather than a
 * list: `Url` and `Trace` can carry request values. Widening `log.view` to a
 * scoped role would therefore mean answering what an error's office is before
 * this query could be made safe.
 */
function apiGetErrorLog(array $p, array $user): array
{
    return ErrorLogRepo::recent(
        isset($p['source']) ? (string) $p['source'] : null,
        isset($p['search']) ? (string) $p['search'] : null,
        (int) num($p['limit'] ?? 300) ?: 300
    );
}

/**
 * A browser-side error report. ROUTES gates this on '' (any signed-in role,
 * not a specific permission) - an Encoder hitting a broken screen should be
 * able to report it exactly as easily as an Administrator. Unlogged in the
 * audit Logs table (ROUTES' logAction is '' for this action): ErrorLog is
 * itself the log, and duplicating every client hiccup into the audit trail
 * would bury the business events that table exists to make findable.
 */
function apiLogClientError(array $p, array $user): array
{
    ErrorLogRepo::record([
        'Source' => 'js',
        'Message' => (string) ($p['message'] ?? 'Unknown client error'),
        'File' => (string) ($p['file'] ?? ''),
        'Line' => (int) num($p['line'] ?? 0),
        'Trace' => (string) ($p['stack'] ?? ''),
        'Url' => (string) ($p['url'] ?? ''),
        'UserEmail' => $user['Email'],
        'UserAgent' => (string) ($p['userAgent'] ?? ''),
    ]);

    return ['logged' => true];
}
