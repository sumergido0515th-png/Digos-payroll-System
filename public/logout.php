<?php
/** logout.php - Ends the session and returns to the sign-in page. */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

authLogout();
header('Location: login.php');
exit;
