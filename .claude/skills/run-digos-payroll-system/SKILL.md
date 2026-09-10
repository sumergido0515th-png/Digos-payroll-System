---
name: run-digos-payroll-system
description: Build, seed, run, and drive the Digos Payroll System (PHP + MariaDB, Bootstrap SPA frontend, no build step). Use when asked to start the app, log in, take a screenshot of a screen, exercise an API action, or run its PHPUnit suite.
---

The Digos Payroll System is a PHP 8 app with no framework and no build step:
`public/` is the docroot, `public/api.php` is the one JSON endpoint, and the
frontend is a Bootstrap SPA served as plain files. There is no dev server to
start beyond PHP's own built-in server. For agent/automated use, drive it via
the Playwright REPL at `.claude/skills/run-digos-payroll-system/driver.mjs` -
no `chromium-cli` in this environment, so this adapts its `nav`/`wait-for`/
`click`/`screenshot` ergonomics directly on Playwright.

All paths below are relative to the repo root.

## Prerequisites

Already present in this environment: `php` (8.4), `mariadb-server`, global
`playwright` (`npm ls -g` shows `playwright@1.56.1`) with Chromium
pre-installed at `/opt/pw-browsers`, and `tmux`. Nothing to `apt-get`.

`vendor/` is committed (or already `composer install`ed) - no Composer step
needed to run the app or its tests.

## Setup

**1. Start MariaDB** (it does not autostart in this container):

```bash
service mariadb start
mysqladmin ping   # "mysqld is alive"
```

**2. Create a demo database and account** (separate from the `..._test` DB
`tests/` uses - don't reuse it, `TestDatabase` refuses to run migrations
against something PHPUnit didn't create):

```bash
mysql -e "CREATE DATABASE IF NOT EXISTS digos_payroll_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "GRANT ALL PRIVILEGES ON digos_payroll_demo.* TO 'digos'@'localhost' IDENTIFIED BY 'digos_test_pw'; FLUSH PRIVILEGES;"
```

(If `app/config.local.php` doesn't exist yet, create it - see Gotchas for
why `DB_NAME` has to live there and not just in the environment.)

```php
<?php
declare(strict_types=1);
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'digos');
define('DB_PASS', 'digos_test_pw');
define('DB_NAME', 'digos_payroll_demo');
```

**3. Migrate and seed:**

```bash
DB_HOST=127.0.0.1 DB_NAME=digos_payroll_demo DB_USER=digos DB_PASS=digos_test_pw php tools/migrate.php
mysql -h127.0.0.1 -udigos -pdigos_test_pw digos_payroll_demo < seeds/demo-seed.sql
```

Confirms 24 migrations applied, then 12 fabricated employees across 3
offices (`seeds/demo-seed.sql` - see Gotchas, it needed a fix to match the
current schema).

## Build

None. No bundler, no compile step - `public/assets/js/app.js` is loaded as-is.

## Run (agent path)

**Start the app** (PHP's built-in server, docroot = `public/`):

```bash
nohup php -S 127.0.0.1:8899 -t public > /tmp/php-server.log 2>&1 &
timeout 10 bash -c 'until curl -sf -o /dev/null http://127.0.0.1:8899/login.php; do sleep 0.5; done'
```

Stop it with `lsof -ti:8899 -sTCP:LISTEN | xargs -r kill` before relaunching.

**Drive it** via the REPL driver, wrapped in tmux:

```bash
tmux new-session -d -s app -x 200 -y 50
tmux send-keys -t app 'NODE_PATH=/opt/node22/lib/node_modules node .claude/skills/run-digos-payroll-system/driver.mjs' Enter
timeout 15 bash -c 'until tmux capture-pane -t app -p | grep -q "driver>"; do sleep 0.3; done'
tmux send-keys -t app 'launch' Enter
timeout 15 bash -c 'until tmux capture-pane -t app -p | grep -q "launched\."; do sleep 0.3; done'
tmux send-keys -t app 'login' Enter
timeout 30 bash -c 'until tmux capture-pane -t app -p | grep -qE "login ->|ERROR"; do sleep 0.5; done'
tmux send-keys -t app 'screenshot dashboard' Enter
timeout 10 bash -c 'until tmux capture-pane -t app -p | grep -q "screenshot:"; do sleep 0.3; done'
tmux capture-pane -t app -p
```

`NODE_PATH` is required - Playwright is a global npm install here (this is a
PHP project, not a Node one), and the driver resolves it via
`createRequire()` since ESM `import` ignores `NODE_PATH`.

`launch` opens headless Chromium with route interception already wired up
for the CDN assets this sandbox can't reach (see Gotchas). `login` (no args)
signs in as the seeded admin and waits for the SPA shell to actually boot,
not just for the redirect. Screenshots land in `/tmp/shots/` (override:
`SCREENSHOT_DIR`).

### Commands

| command | what it does |
|---|---|
| `launch` | open headless Chromium with CDN stubs routed |
| `nav <path>` | go to a `public/*.php` path, e.g. `nav login.php` |
| `login [email] [password]` | fill + submit `login.php`, wait for the SPA to boot. Defaults to `admin@digos.gov.ph` / `ChangeMe!123` |
| `goto <hash-route>` | SPA navigation, e.g. `goto employees`, `goto payroll?OfficeCode=CMO` - sets `location.hash`, which is how every in-app link actually navigates |
| `wait-for text=Foo` / `wait-for <css-sel>` | wait up to 10s |
| `click <css-sel>` / `click-text <text>` | click, DOM-selector or visible text |
| `fill <css-sel> <text>` | fill an input |
| `press <key>` | keyboard press |
| `screenshot [name]` / `ss [name]` | full-page PNG -> `/tmp/shots/<name>.png` |
| `console [--errors]` | dump captured `console`/`pageerror` messages |
| `eval <js>` | evaluate in the page, print JSON |
| `text [css-sel]` | print `innerText` (body if no selector) |
| `quit` | close the browser, exit |

Sidebar routes worth knowing (from `public/index.php`'s `data-page`
attributes): `dashboard`, `payroll`, `dtr`, `preaudit`, `periods`, `reports`,
`print`, `employees`, `timekeepers`, `departments`, `documents`, `coverage`,
`import`, `users`, `logs`, `settings`, `backup`.

## Direct invocation

Most PRs to this codebase touch `app/`, not the UI - the fastest verification
loop is calling `api.php` directly, or running the relevant PHPUnit suite,
neither of which needs a browser:

```bash
# Any api* action, once logged in with a cookie jar:
curl -s -b /tmp/cookies.txt -c /tmp/cookies.txt -X POST http://127.0.0.1:8899/api.php \
  -H "Content-Type: application/json" \
  -d '{"action":"apiListEmployees","payload":{}}'
```

(Get the cookie jar via `curl -c /tmp/cookies.txt -d "email=admin@digos.gov.ph" -d "password=ChangeMe!123" http://127.0.0.1:8899/login.php` first.)

## Run (human path)

Point Apache/XAMPP's docroot at `public/` per `README.md`'s Installation
section (real deployment target) - not applicable headless; use the agent
path above instead.

## Test

```bash
php vendor/bin/phpunit --testsuite unit          # 365 tests, no DB, ~0.2s
DB_NAME=digos_payroll_test php vendor/bin/phpunit  # full suite, needs the test DB migrated
```

Full suite: 619 tests, 2148 assertions, green (one pre-existing PHPUnit
warning, not a failure). `digos_payroll_test` must already exist and be
migrated - `DB_NAME=digos_payroll_test php tools/migrate.php` - the test
suite's own guard refuses to run against a database whose name doesn't
contain `test`.

## Gotchas

- **`seeds/demo-seed.sql` was stale against the current schema** - it
  predated migrations `0015`/`0016`, which split `Employees` into a
  directory tier and a new `EmployeeSensitive` table (TIN, GSIS, rates,
  contact info). The seed still inserted all of that into `Employees`,
  so `INSERT IGNORE` failed the whole statement with `Unknown column 'TIN'`
  and silently left 0 employees seeded. Fixed by splitting the insert
  across both tables to match the post-`0016` schema - the fix is already
  in the file; if a future migration reshapes either table again, this is
  the seed to check first.

- **`config.php` only checks `defined('DB_NAME')`, not `getenv()`** -
  unlike `tools/migrate.php` and `tests/bootstrap.php`, which explicitly
  read `getenv('DB_NAME')` first. Setting `DB_NAME` as a shell env var
  before `php -S` does nothing for the running app; it has to be a
  `define()` in `app/config.local.php`. Migrations and tests don't have
  this problem - only the live app does.

- **The CDN assets `public/index.php` loads are unreachable from this
  sandbox** (Bootstrap CSS/JS from jsdelivr, Material Icons from
  fonts.googleapis.com, Google Charts from gstatic) - outbound HTTPS here
  goes through an agent proxy Chromium isn't configured to use, so each
  request fails as `ERR_TUNNEL_CONNECTION_FAILED` rather than a fast 404,
  which delayed first script execution by 20s+ per page before it was
  fixed. `driver.mjs`'s `launch` command routes all four to local
  stand-ins (`stub-bootstrap.js`, `stub-gcharts.js` beside it, plus empty
  CSS) *before* the first `nav`/`login`. The app renders unstyled
  (no Bootstrap CSS - Material Icons show as their ligature names, e.g.
  "dashboard" instead of the glyph) but is fully functional; the two chart
  panels on the dashboard render as empty boxes since the chart stub's
  `draw()` is a no-op - functional stand-in, not a rendering test.

- **The login submit button has no `type="submit"` attribute** - it's the
  implicit default for a `<button>` inside a `<form>`, so a
  `button[type="submit"]` CSS selector matches nothing and `page.click()`
  hangs for its full timeout with no error. The form also has an earlier
  `type="button"` password-visibility toggle, so `#login-form button`
  alone would click the wrong element. `driver.mjs` matches on visible
  text (`button:has-text("Sign in")`) instead.

- **`Promise.all([page.waitForNavigation(), page.click(...)])` timed out
  on the login POST even though the navigation had already completed** -
  confirmed via `eval` that `#user-email` was populated and
  `document.readyState` was `"complete"` while `waitForNavigation` was
  still reporting a timeout. This is a plain POST -> 302 -> GET, not a
  fetch-based login, and the race the `Promise.all` pattern is supposed to
  prevent apparently still lost the event here. Fix was to drop
  `waitForNavigation` entirely: `click()`, then
  `waitForSelector('#user-email:not(:empty)')` - it survives the
  navigation fine and, unlike a raw URL check, actually proves the
  `login -> apiGetSession() -> SPA boot` chain worked, not just that the
  redirect fired.

- **MariaDB does not autostart in this container** - `service mariadb
  status` reports "stopped" on a fresh shell even though the data
  directory and grants persist across restarts. Always `service mariadb
  start` before anything else; it's fast (~1s) and idempotent to re-run.

## Troubleshooting

- **`Error [ERR_MODULE_NOT_FOUND]: Cannot find package 'playwright'`**:
  ESM `import` doesn't consult `NODE_PATH`. Either run with `NODE_PATH=
  /opt/node22/lib/node_modules` set (the driver resolves Playwright via
  `createRequire()`, which does honor it), or find the actual global path
  with `npm root -g` if it's moved.
- **`driver>` never appears in `tmux capture-pane`**: the previous run's
  Chromium is probably still attached to the same tmux pane from a earlier
  hung command. `tmux kill-session -t app` and start fresh rather than
  reusing a pane that had a stuck command in it.
- **`login` hangs with no output at all**: almost always the CDN stall
  from Gotchas, not a real hang - give it the full 30s in the polling loop
  before assuming something's wrong; a bare `sleep 5` isn't enough.
- **`mysqladmin ping` fails / "Can't connect"**: `service mariadb start`
  wasn't run yet, or was run in a different shell that didn't share this
  one's environment - it's a system service, not per-shell state, so this
  should be rare after the first start.
