<?php
/**
 * login.php - Sign-in page (email + password). Successful login redirects
 * to the SPA; failures re-render with a message.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (!empty($_SESSION['email'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        authLogin(trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
        header('Location: index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$gov = 'CITY GOVERNMENT OF DIGOS';
try { $gov = getSetting('GovernmentName', $gov); } catch (Throwable) { /* DB not ready yet */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in - Digos City Payroll</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <style>
    body { background: linear-gradient(160deg, #0b3d91 0%, #082c6b 60%, #061f4d 100%);
           min-height: 100vh; display: flex; align-items: center; justify-content: center;
           font-family: 'Segoe UI', system-ui, sans-serif; }
    .login-card { width: 380px; border-radius: 16px; border: none;
                  box-shadow: 0 20px 60px rgba(0,0,0,.35); }
    .seal { width: 64px; height: 64px; border-radius: 50%; background: #0b3d91; color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-size: 34px; }
    .btn-gov { background: #0b3d91; color: #fff; }
    .btn-gov:hover { background: #082c6b; color: #fff; }
  </style>
</head>
<body>
  <div class="card login-card p-4">
    <div class="text-center mb-3">
      <div class="seal"><span class="material-icons">account_balance</span></div>
      <h5 class="mt-3 mb-0 fw-bold text-primary"><?= htmlspecialchars($gov) ?></h5>
      <small class="text-muted">Employee Payroll Management System<br>Job Order &bull; Contract of Service</small>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Email</label>
        <input class="form-control" type="email" name="email" required autofocus
               value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>">
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Password</label>
        <input class="form-control" type="password" name="password" required>
      </div>
      <button class="btn btn-gov w-100">Sign in</button>
    </form>
    <p class="text-center text-muted small mt-3 mb-0">Authorized personnel only. All activity is logged.</p>
  </div>
</body>
</html>
