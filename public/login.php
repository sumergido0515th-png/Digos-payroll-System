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

// Branding is best-effort: the sign-in page has to render even when the
// database is unreachable, because that is exactly when someone is trying to
// sign in and find out why.
$gov = 'CITY GOVERNMENT OF DIGOS';
$logo = '';
$watermark = '';
$watermarkOpacity = 0.20;
try {
    $gov = getSetting('GovernmentName', $gov);
    $logo = getSetting('OfficeLogoUrl', '');
    $watermark = getSetting('WatermarkUrl', '');
    $watermarkOpacity = watermarkOpacity();
} catch (Throwable) { /* DB not ready yet */ }

$watermark = cssSafeImageUrl($watermark);
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
    /* Giving html a background stops body's gradient being propagated to the
       canvas. That matters because a canvas background is not a reliable
       blending backdrop, and the watermark below blends against this gradient;
       isolation on body makes it the stacking context that blending resolves
       against. */
    html { background: #061f4d; }
    body { background: linear-gradient(160deg, #0b3d91 0%, #082c6b 60%, #061f4d 100%);
           min-height: 100vh; display: flex; align-items: center; justify-content: center;
           font-family: 'Segoe UI', system-ui, sans-serif; isolation: isolate; }
    .login-card { width: 380px; border-radius: 16px; border: none;
                  box-shadow: 0 20px 60px rgba(0,0,0,.35); }
    .seal { width: 64px; height: 64px; border-radius: 50%; background: #0b3d91; color: #fff;
            display: inline-flex; align-items: center; justify-content: center; font-size: 34px; }
    /* An uploaded logo gets a bigger square frame rather than the placeholder
       icon's circle: a circle crops a square crest's corners and wastes about
       a third of the width fitting a square inside it. contain keeps any
       aspect ratio undistorted - a wide banner letterboxes, never stretches. */
    .seal.has-logo { width: 104px; height: 104px; border-radius: 12px;
                     background: #fff; padding: 4px; }
    .seal.has-logo img { width: 100%; height: 100%; object-fit: contain; display: block; }
    .btn-gov { background: #0b3d91; color: #fff; }
    .btn-gov:hover { background: #082c6b; color: #fff; }
<?php if ($watermark !== ''): ?>
    /*
     * The watermark sits behind the card on the gradient, drawn as a light
     * silhouette: this background is always dark blue, and a dark crest on it
     * is invisible at any opacity a watermark is allowed to use.
     *
     * invert + screen rather than a plain opacity, because a seal exported for
     * print is normally dark ink on a solid white background, not a
     * transparent PNG. Inverting turns that white into black and the crest
     * into a light tone; screen then drops black to nothing and keeps the
     * light crest, so the box the image came in disappears instead of washing
     * the gradient out. A transparent PNG behaves the same way - its empty
     * pixels contribute nothing under screen either.
     *
     * The URL is already through cssSafeImageUrl() above - see the note on
     * that function for why it is a whitelist rather than an escape.
     */
    /* contain within an inset box: as large as the placement allows for any
       aspect ratio, without being cropped, stretched, or running into the
       window edges. */
    .watermark { position: fixed; inset: 5vh 5vw; z-index: 0; pointer-events: none;
                 background: url("<?= $watermark ?>") center / contain no-repeat;
                 filter: invert(1); mix-blend-mode: screen;
                 opacity: <?= $watermarkOpacity ?>; }
    .login-card { position: relative; z-index: 1; }
<?php endif; ?>
  </style>
</head>
<body>
<?php if ($watermark !== ''): ?>
  <div class="watermark" aria-hidden="true"></div>
<?php endif; ?>
  <div class="card login-card p-4">
    <div class="text-center mb-3">
      <div class="seal<?= $logo !== '' ? ' has-logo' : '' ?>">
        <?php if ($logo !== ''): ?>
          <img src="<?= htmlspecialchars($logo) ?>" alt="Office logo">
        <?php else: ?>
          <span class="material-icons">account_balance</span>
        <?php endif; ?>
      </div>
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
