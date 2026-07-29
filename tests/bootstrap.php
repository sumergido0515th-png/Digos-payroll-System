<?php
/**
 * ============================================================================
 * tests/bootstrap.php - Test harness entry point.
 *
 * Deliberately does NOT load app/bootstrap.php: the unit and architecture
 * suites must run with no database, no session and no configuration, so that
 * a failing test always means "the logic is wrong" and never "my MySQL was
 * not running". Integration tests opt into a real connection themselves via
 * TestDatabase::connect().
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

/** Repository root, for tests that inspect the source tree. */
define('PROJECT_ROOT', dirname(__DIR__));

/**
 * Pins DB_NAME to the test database before anything can load app/config.php.
 *
 * TestDatabase::connect() reads the environment and refuses a name without a
 * test marker, so its own connection was always safe. DB:: is not: it reads
 * the DB_NAME *constant*, and app/config.php defaults that to the working
 * database. Any test that loads an api* function - the Phase 2 gateway tests
 * will have to - would therefore have run against live payroll data, and the
 * TestDatabase guard would not have seen it happen.
 *
 * Defining the constant here wins, because app/config.php only fills in what
 * is still unset. It opens no connection, so the unit and architecture suites
 * stay database-free. A DB_NAME without a test marker is left alone and fails
 * loudly at TestDatabase instead of being quietly accepted here.
 */
$testDbName = getenv('DB_NAME');
if (is_string($testDbName) && preg_match('/(^|_)test(_|$)/i', $testDbName)) {
    define('DB_NAME', $testDbName);
}
