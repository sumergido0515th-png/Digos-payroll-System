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
