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
$bgPhoto = '';
$governmentEmail = '';
$headline = 'The City of Choice';
$tagline = 'To live, work, and visit | Hugpong Digoseños';
try {
    $gov = getSetting('GovernmentName', $gov);
    $logo = getSetting('OfficeLogoUrl', '');
    $watermark = getSetting('WatermarkUrl', '');
    $watermarkOpacity = watermarkOpacity();
    $bgPhoto = getSetting('LoginBackgroundUrl', '');
    $governmentEmail = getSetting('GovernmentEmail', '');
    $headline = getSetting('LoginHeadline', $headline);
    $tagline = getSetting('LoginTagline', $tagline);
} catch (Throwable) { /* DB not ready yet */ }

$watermark = cssSafeImageUrl($watermark);
$bgPhoto = cssSafeImageUrl($bgPhoto);

// The last word of the headline and the text after "|" in the tagline are
// rendered in the accent color, matching the reference design ("The City of
// **Choice**", "To live, work and visit | **Hugpong Digoseños**") without
// hardcoding either string - an admin who changes GovernmentName can change
// these too, from the Settings screen.
$headlineParts = explode(' ', trim($headline));
$headlineAccent = array_pop($headlineParts) ?? '';
$headlineLead = implode(' ', $headlineParts);

$taglineBits = array_map('trim', explode('|', $tagline, 2));
$taglineLead = $taglineBits[0] ?? '';
$taglineAccent = $taglineBits[1] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in - Digos City Payroll</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
  <style>
    /* ---- page shell ---------------------------------------------------- */
    html { background: #eef3fb; }
    body {
      min-height: 100vh; display: flex; flex-direction: column; align-items: center;
      justify-content: center; gap: 22px; padding: 48px 16px 0;
      font-family: 'Segoe UI', system-ui, sans-serif; position: relative;
      overflow-x: hidden; isolation: isolate;
      background: linear-gradient(180deg, #f5f9ff 0%, #eaf1fc 55%, #eaf1fc 100%);
    }

    /* ---- decorative backdrop: dot grid + drifting line art --------------
       White rather than gov-blue: the backdrop is now the hero photo itself
       (mostly a dark sky at the top), and a dark-on-dark dot would disappear
       exactly where these sit. */
    .deco { position: fixed; inset: 0; z-index: 1; pointer-events: none; overflow: hidden; }
    .deco .dots {
      position: absolute; width: 260px; height: 260px; opacity: .35;
      background-image: radial-gradient(#fff 1.6px, transparent 1.6px);
      background-size: 16px 16px; -webkit-mask-image: radial-gradient(circle, #000 0%, transparent 75%);
              mask-image: radial-gradient(circle, #000 0%, transparent 75%);
    }
    .deco .dots.tl { top: -40px; left: -40px; }
    .deco .dots.br { bottom: 8vh; right: -60px; opacity: .22; }
    .deco svg.wisp { position: absolute; opacity: .28; }
    .deco svg.wisp.a { top: 6%; left: -4%; width: 46vw; animation: drift 22s ease-in-out infinite; }
    .deco svg.wisp.b { top: 14%; right: -6%; width: 40vw; animation: drift 26s ease-in-out infinite reverse; }

    /* Clouds: five circles per cloud via box-shadow, blurred into one soft
       puff rather than hand-drawn - the same trick as the dot grid above,
       just stacked instead of tiled. Each drifts the full width of the
       viewport on its own loop (duration, height and size all differ) so the
       sky never repeats in an obviously looped way. */
    .cloud {
      position: fixed; z-index: 1; pointer-events: none;
      width: 60px; height: 60px; border-radius: 50%; background: #fff;
      filter: blur(5px); opacity: .4;
      animation: cloudDrift linear infinite;
    }
    .cloud::before, .cloud::after { content: ''; position: absolute; border-radius: 50%; background: #fff; }
    .cloud.c1 {
      top: 9%; left: -22%; width: 70px; height: 70px; opacity: .32;
      box-shadow: 46px 8px 0 -8px #fff, 88px -6px 0 -14px #fff, -34px 10px 0 -16px #fff;
      animation-duration: 75s;
    }
    .cloud.c2 {
      top: 22%; left: -18%; width: 46px; height: 46px; opacity: .22;
      box-shadow: 34px 6px 0 -8px #fff, 62px -4px 0 -14px #fff;
      animation-duration: 95s; animation-delay: -30s;
    }
    .cloud.c3 {
      top: 4%; left: -26%; width: 90px; height: 90px; opacity: .18;
      box-shadow: 58px 10px 0 -14px #fff, 108px -8px 0 -22px #fff, -44px 14px 0 -24px #fff;
      animation-duration: 120s; animation-delay: -60s;
    }
    @keyframes cloudDrift {
      from { transform: translateX(0); }
      to   { transform: translateX(150vw); }
    }

    /* Birds: a simple chevron path flying left-to-right across the upper
       band, each on its own loop so they never look synchronized. */
    .bird { position: fixed; top: 12%; left: -6%; z-index: 1; opacity: .7; pointer-events: none;
            width: 22px; animation: fly 26s linear infinite; }
    .bird path { fill: none; stroke: #fff; stroke-width: 7; stroke-linecap: round; }
    .bird.b2 { top: 20%; width: 15px; animation-duration: 34s; animation-delay: -9s; opacity: .5; }
    .bird.b3 { top: 8%; width: 17px; animation-duration: 30s; animation-delay: -18s; opacity: .45; }

    @keyframes fly {
      0%   { transform: translate(0, 0); }
      50%  { transform: translate(60vw, -3vh); }
      100% { transform: translate(130vw, 1vh); }
    }
    @keyframes drift {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      50% { transform: translate(1.5vw, 1vh) rotate(1.5deg); }
    }
    @keyframes floatUp {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes waveShift {
      0%, 100% { transform: translateX(0); }
      50% { transform: translateX(-2.5%); }
    }

    /* ---- hero photo: the whole page, not a band ----------------------------
       The headline/seal/card are meant to sit ON the photo, not next to it -
       so it fills the viewport behind everything rather than being confined
       to a strip at the bottom. */
    .hero-photo {
      position: fixed; inset: 0; z-index: 0;
      background-color: var(--gov-blue-dark, #082c6b);
<?php if ($bgPhoto !== ''): ?>
      background-image: url("<?= $bgPhoto ?>");
      background-size: cover;
      background-repeat: no-repeat;
      background-position: center top;
      background-color: transparent;
<?php endif; ?>
    }
<?php if ($bgPhoto !== ''): ?>
    .hero-photo::after { background: none; }
<?php endif; ?>
<?php if ($bgPhoto !== ''): ?>
    .hero-photo.standby {
      background-image: url("<?= $bgPhoto ?>");
      background-blend-mode: normal;
      background-color: transparent;
    }
<?php endif; ?>
    /* Dark at the very top so the headline reads on any photo's own
       brightness there, clear through the middle so the photo shows
       naturally, and a faint blue tint at the bottom to blend the card's
       shadow and the footer note into the scene - not a wash over the whole
       image, which would flatten it. The radial layer fades in only at the
       corners, where a cut-out photo subject's matting is roughest, rather
       than over the subject itself. */
    .hero-photo::after {
      content: ''; position: absolute; inset: 0;
      background:
        radial-gradient(140% 100% at 50% 40%, rgba(4,14,32,0) 55%, rgba(4,14,32,.5) 100%),
        linear-gradient(180deg,
          rgba(4,14,32,.6) 0%, rgba(4,14,32,.18) 26%,
          rgba(4,14,32,0) 42%, rgba(4,14,32,0) 62%,
          rgba(8,44,107,.32) 100%);
    }
    .hero-photo .skyline { position: absolute; inset: 0; width: 100%; height: 100%; opacity: .9; }
    /* Only ever shown over the drawn skyline fallback: a real uploaded photo
       comes with its own foreground treatment (both photos supplied so far
       already had a wave baked into their bottom edge), and a second wave
       from here would double up on it. */
    .hero-wave-bottom {
      position: fixed; left: -2%; width: 104%; bottom: -1px; z-index: 1; pointer-events: none;
      animation: waveShift 24s ease-in-out infinite reverse;
    }

    @media (prefers-reduced-motion: reduce) {
      .bird, .deco svg.wisp, .hero-wave-bottom, .cloud { animation: none; }
      .login-content > * { animation: none !important; opacity: 1 !important; transform: none !important; }
    }

    /* ---- header branding -------------------------------------------------- */
    .login-content { position: relative; z-index: 3; width: 100%; max-width: 460px;
                      display: flex; flex-direction: column; align-items: center;
                      transition: opacity .35s ease, transform .35s ease, visibility .35s ease; }
    .login-content.standby { opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(18px); }
    body.standby .login-content { opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(18px); }
    body.standby .deco,
    body.standby .bird,
    body.standby .cloud,
    body.standby .watermark,
    body.standby .hero-wave-bottom { opacity: 0 !important; pointer-events: none !important; }
    body.standby .hero-photo::after { opacity: 0 !important; }
    body.standby .hero-photo { background-color: transparent !important; background-blend-mode: normal !important; }
    .brand-block { text-align: center; margin-bottom: 6px;
                   animation: floatUp .6s ease both; }
    .seal { width: 84px; height: 84px; border-radius: 50%; background: var(--gov-blue, #0b3d91);
            color: #fff; display: inline-flex; align-items: center; justify-content: center;
            font-size: 42px; box-shadow: 0 8px 24px rgba(0,0,0,.35); border: 3px solid rgba(255,255,255,.85); }
    .seal.has-logo { width: 116px; height: 116px; border-radius: 50%; background: #fff; padding: 6px;
                   box-shadow: 0 14px 32px rgba(0,0,0,.18); border: 2px solid rgba(255,255,255,.9); }
    .seal.has-logo img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;
                         box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
    /* White on the photo, not the dark navy this used before there was
       always a photo behind it - and a shadow rather than relying on the
       photo being dark enough everywhere on its own. */
    .headline { font-size: clamp(26px, 5vw, 38px); font-weight: 800; margin: 14px 0 2px;
                color: #fff; text-shadow: 0 2px 16px rgba(0,0,0,.45); }
    .headline .accent { color: var(--gov-accent, #f4b400); }
    .tagline { font-size: 14px; color: rgba(255,255,255,.9); margin: 0; text-shadow: 0 1px 8px rgba(0,0,0,.4); }
    .tagline .accent { color: var(--gov-accent, #f4b400); font-weight: 700; }

    /* ---- the card ----------------------------------------------------------
       Behaves like the dashboard's glass modals (same rgba(--card-rgb,·)
       + backdrop-filter pairing): a quiet, translucent glass by default that
       lets the hero photo show through, and a sharp, solid card while it is
       actually in use - hovered, a field focused, or just clicked. It settles
       back to glass a couple of seconds after nothing is happening, rather
       than needing a person to notice and dismiss anything. */
    .login-card {
      width: 100%; border-radius: 18px; border: none;
      animation: floatUp .6s .12s ease both;
      background: rgba(var(--card-rgb), .7);
      -webkit-backdrop-filter: blur(16px) saturate(1.3);
      backdrop-filter: blur(16px) saturate(1.3);
      box-shadow: 0 18px 44px rgba(11,35,80,.16);
      transition: background .35s ease, backdrop-filter .35s ease, box-shadow .35s ease;
    }
    .login-card.is-active {
      background: rgba(var(--card-rgb), .98);
      -webkit-backdrop-filter: blur(0px);
      backdrop-filter: blur(0px);
      box-shadow: 0 28px 70px rgba(11,35,80,.3);
    }
    .login-card .govname { color: var(--gov-blue, #0b3d91); }
    .field-group { position: relative; }
    .field-group .material-icons.leading {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      font-size: 19px; color: var(--muted, #6b7280); pointer-events: none;
    }
    .field-group input { padding-left: 38px; }
    .field-group input.pw { padding-right: 40px; }
    .field-group .pw-toggle {
      position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
      border: none; background: none; color: var(--muted, #6b7280); line-height: 1;
      padding: 6px; border-radius: 50%;
    }
    .field-group .pw-toggle:hover { background: var(--gov-blue-light, #e8effc); color: var(--gov-blue, #0b3d91); }
    .btn-gov-grad {
      background: linear-gradient(135deg, var(--gov-blue, #0b3d91), var(--gov-blue-dark, #082c6b));
      color: #fff; font-weight: 600; letter-spacing: .2px;
      display: flex; align-items: center; justify-content: center; gap: 6px;
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .btn-gov-grad:hover { color: #fff; transform: translateY(-1px);
      box-shadow: 0 10px 22px rgba(11,61,145,.35); }
    .btn-gov-grad .material-icons { font-size: 18px; transition: transform .15s ease; }
    .btn-gov-grad:hover .material-icons { transform: translateX(3px); }
    #forgot-note { display: none; }
<?php if ($watermark !== ''): ?>
    /*
     * The watermark sits behind the card, drawn as a light silhouette: the
     * card is opaque, so it never competes with the form itself - only with
     * the hero photo behind it.
     *
     * invert + screen rather than a plain opacity, because a seal exported for
     * print is normally dark ink on a solid white background, not a
     * transparent PNG. Inverting turns that white into black and the crest
     * into a light tone; screen then drops black to nothing and keeps the
     * light crest. A transparent PNG behaves the same way under screen.
     *
     * The URL is already through cssSafeImageUrl() above - see the note on
     * that function for why it is a whitelist rather than an escape.
     */
    .watermark { position: fixed; inset: 5vh 5vw; z-index: 0; pointer-events: none;
                 background: url("<?= $watermark ?>") center / contain no-repeat;
                 filter: invert(1); mix-blend-mode: screen;
                 opacity: <?= $watermarkOpacity ?>; }
<?php endif; ?>
  </style>
</head>
<body>

  <!-- Decorative, non-interactive backdrop: dot grid, drifting line art, birds, clouds. -->
  <div class="deco" aria-hidden="true">
    <div class="dots tl"></div>
    <div class="dots br"></div>
    <svg class="wisp a" viewBox="0 0 400 60" aria-hidden="true">
      <path d="M0 40 Q100 0 200 30 T400 20" fill="none" stroke="#0b3d91" stroke-width="2"/>
    </svg>
    <svg class="wisp b" viewBox="0 0 400 60" aria-hidden="true">
      <path d="M0 20 Q100 55 200 25 T400 45" fill="none" stroke="#0b3d91" stroke-width="2"/>
    </svg>
    <div class="cloud c1"></div>
    <div class="cloud c2"></div>
    <div class="cloud c3"></div>
  </div>
  <svg class="bird" viewBox="0 0 24 12" aria-hidden="true"><path d="M1 6 Q6 1 12 6 Q18 1 23 6"/></svg>
  <svg class="bird b2" viewBox="0 0 24 12" aria-hidden="true"><path d="M1 6 Q6 1 12 6 Q18 1 23 6"/></svg>
  <svg class="bird b3" viewBox="0 0 24 12" aria-hidden="true"><path d="M1 6 Q6 1 12 6 Q18 1 23 6"/></svg>

<?php if ($watermark !== ''): ?>
  <div class="watermark" aria-hidden="true"></div>
<?php endif; ?>

  <!-- Full-bleed hero. background-size: cover auto-fits whatever an admin
       uploads (any size or aspect ratio) without stretching it, so quality
       is only ever limited by the source file itself. Without one, a drawn
       skyline plus its own wave keeps the page from looking unfinished. -->
  <div class="hero-photo" aria-hidden="true">
<?php if ($bgPhoto === ''): ?>
    <svg class="skyline" viewBox="0 0 1440 300" preserveAspectRatio="xMidYMax slice">
      <rect x="0" y="0" width="1440" height="300" fill="#0e3f8f"/>
      <g fill="#0a3477">
        <rect x="40" y="140" width="90" height="160"/>
        <rect x="150" y="90" width="70" height="210"/>
        <rect x="240" y="160" width="110" height="140"/>
        <rect x="1140" y="120" width="80" height="180"/>
        <rect x="1250" y="170" width="120" height="130"/>
        <rect x="1370" y="100" width="60" height="200"/>
      </g>
      <g fill="#123e8a">
        <rect x="520" y="70" width="140" height="230"/>
        <rect x="690" y="40" width="70" height="260"/>
        <rect x="780" y="100" width="160" height="200"/>
        <rect x="960" y="60" width="90" height="240"/>
      </g>
      <g fill="#f4b400" opacity=".55">
        <rect x="545" y="100" width="10" height="10"/><rect x="575" y="100" width="10" height="10"/>
        <rect x="605" y="100" width="10" height="10"/><rect x="545" y="140" width="10" height="10"/>
        <rect x="575" y="140" width="10" height="10"/><rect x="605" y="140" width="10" height="10"/>
        <rect x="810" y="140" width="10" height="10"/><rect x="850" y="140" width="10" height="10"/>
        <rect x="890" y="140" width="10" height="10"/><rect x="810" y="180" width="10" height="10"/>
        <rect x="850" y="180" width="10" height="10"/><rect x="890" y="180" width="10" height="10"/>
      </g>
    </svg>
<?php endif; ?>
  </div>
<?php if ($bgPhoto === ''): ?>
  <svg class="hero-wave-bottom" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
    <path d="M0,40 C240,90 480,0 720,30 C960,60 1200,10 1440,45 L1440,100 L0,100 Z" fill="#0b3d91"/>
  </svg>
<?php endif; ?>

  <div class="login-content">
    <div class="brand-block">
      <div class="seal<?= $logo !== '' ? ' has-logo' : '' ?>">
        <?php if ($logo !== ''): ?>
          <img src="<?= htmlspecialchars($logo) ?>" alt="Office logo">
        <?php else: ?>
          <span class="material-icons">account_balance</span>
        <?php endif; ?>
      </div>
      <h1 class="headline"><?= htmlspecialchars($headlineLead) ?>
        <?php if ($headlineAccent !== ''): ?><span class="accent"><?= htmlspecialchars($headlineAccent) ?></span><?php endif; ?></h1>
      <p class="tagline"><?= htmlspecialchars($taglineLead) ?><?php if ($taglineAccent !== ''): ?>
        &nbsp;&bull;&nbsp;<span class="accent"><?= htmlspecialchars($taglineAccent) ?></span><?php endif; ?></p>
    </div>

    <div class="card login-card p-4">
      <div class="text-center mb-3">
        <h5 class="mt-1 mb-0 fw-bold govname"><?= htmlspecialchars($gov) ?></h5>
        <small class="text-muted">Employee Payroll Management System<br>Job Order &bull; Contract of Service</small>
      </div>
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" autocomplete="off" id="login-form">
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="f-email">Email</label>
          <div class="field-group">
            <span class="material-icons leading">person</span>
            <input class="form-control" type="email" name="email" id="f-email" required autofocus
                   value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" placeholder="admin@digos.gov.ph">
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold" for="f-password">Password</label>
          <div class="field-group">
            <span class="material-icons leading">lock</span>
            <input class="form-control pw" type="password" name="password" id="f-password" required>
            <button type="button" class="pw-toggle" id="pw-toggle" aria-label="Show password" tabindex="-1">
              <span class="material-icons" style="font-size:19px">visibility</span>
            </button>
          </div>
        </div>
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="f-remember" checked>
            <label class="form-check-label small" for="f-remember">Remember me</label>
          </div>
          <a href="#" class="small" id="forgot-link">Forgot Password?</a>
        </div>
        <div class="alert alert-secondary py-2 small" id="forgot-note">
          Password resets aren't self-service yet &mdash; contact your system administrator
          <?php if ($governmentEmail !== ''): ?>
            at <a href="mailto:<?= htmlspecialchars($governmentEmail) ?>"><?= htmlspecialchars($governmentEmail) ?></a>.
          <?php else: ?>.<?php endif; ?>
        </div>
        <button class="btn btn-gov-grad w-100 py-2">Sign in <span class="material-icons">arrow_forward</span></button>
      </form>
      <p class="text-center text-muted small mt-3 mb-0">
        <span class="material-icons align-middle" style="font-size:14px">shield</span>
        Authorized personnel only. All activity is logged.</p>
    </div>
  </div>

<script>
(function () {
  var pwField = document.getElementById('f-password');
  var pwToggle = document.getElementById('pw-toggle');
  pwToggle.onclick = function () {
    var show = pwField.type === 'password';
    pwField.type = show ? 'text' : 'password';
    pwToggle.querySelector('.material-icons').textContent = show ? 'visibility_off' : 'visibility';
    pwToggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  };

  var forgotLink = document.getElementById('forgot-link');
  var forgotNote = document.getElementById('forgot-note');
  forgotLink.onclick = function (e) {
    e.preventDefault();
    forgotNote.style.display = forgotNote.style.display === 'block' ? 'none' : 'block';
  };

  // "Remember me" only ever remembers the email address, client-side, so a
  // returning user does not have to retype it - the password and the session
  // itself are never touched here.
  var REMEMBER_KEY = 'digosPayrollRememberedEmail';
  var emailField = document.getElementById('f-email');
  var rememberBox = document.getElementById('f-remember');
  try {
    var remembered = localStorage.getItem(REMEMBER_KEY);
    if (remembered && !emailField.value) emailField.value = remembered;
  } catch (e) { /* storage unavailable (private mode) - fall through silently */ }

  document.getElementById('login-form').addEventListener('submit', function () {
    try {
      if (rememberBox.checked) localStorage.setItem(REMEMBER_KEY, emailField.value);
      else localStorage.removeItem(REMEMBER_KEY);
    } catch (e) { /* storage unavailable - sign-in still proceeds */ }
  });

  // The card starts sharp (autofocus already puts the cursor in the email
  // field) and settles into glass a couple of seconds after the pointer
  // leaves and nothing is focused - not on a fixed timer, so someone reading
  // slowly with a field still focused never has it recede under them.
  var card = document.querySelector('.login-card');
  var content = document.querySelector('.login-content');
  var idleTimer;
  function resetStandbyTimer() {
    if (content) content.classList.remove('standby');
    document.body.classList.remove('standby');
    clearTimeout(idleTimer);
    idleTimer = setTimeout(function () {
      if (content) content.classList.add('standby');
      document.body.classList.add('standby');
    }, 30000);
  }
  function cardActive() {
    card.classList.add('is-active');
    resetStandbyTimer();
  }
  function cardIdleSoon() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(function () { card.classList.remove('is-active'); }, 2400);
  }
  card.addEventListener('mouseenter', cardActive);
  card.addEventListener('mousedown', cardActive);
  card.addEventListener('focusin', cardActive);
  card.addEventListener('mouseleave', cardIdleSoon);
  card.addEventListener('focusout', cardIdleSoon);
  document.addEventListener('mousemove', resetStandbyTimer);
  document.addEventListener('keydown', resetStandbyTimer);
  document.addEventListener('touchstart', resetStandbyTimer);
  resetStandbyTimer();
  cardActive();
})();
</script>
</body>
</html>
