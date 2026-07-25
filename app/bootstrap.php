<?php
/**
 * bootstrap.php - Loads the whole application layer in dependency order.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Master.php';
require_once __DIR__ . '/Payroll.php';
require_once __DIR__ . '/Reports.php';
require_once __DIR__ . '/PrintDoc.php';
