# DIGOS CITY GOVERNMENT — Payroll Management System (PHP Edition)

Full-stack port of the Apps Script system to **PHP 8 + MySQL**: same
features, same UI, same computation rules — self-hosted on any LAMP/WAMP
stack (XAMPP, Laragon, IIS + PHP, Linux + Apache/Nginx).

## What changed vs. the Apps Script edition

| Area             | Apps Script                 | PHP                                                            |
| ---------------- | --------------------------- | -------------------------------------------------------------- |
| Database         | Google Sheets tables        | MySQL (`migrations/`, PDO prepared statements)                 |
| Sign-in          | Google account              | Email + password (bcrypt), PHP sessions                        |
| Frontend         | identical                   | identical (same Bootstrap 5 SPA, same page modules)            |
| API              | `google.script.run`         | `fetch()` → `public/api.php` (same action names & JSON shapes) |
| PDF              | Drive temp-sheet export     | Print view (`print.php?no=…`) + browser "Save as PDF"          |
| Backup           | Spreadsheet copies to Drive | SQL dumps to `php/backups/`, downloadable, restorable          |
| Scheduled backup | Apps Script trigger         | `cron.php` + OS scheduler                                      |
| Audit log IP     | unavailable                 | recorded from the request                                      |

## Layout

```
php/
├─ migrations/             Numbered schema migrations, applied once, in order
├─ tools/migrate.php       Migration runner (CLI) — replaces the old schema.sql
├─ tests/                  PHPUnit: architecture guards, unit, integration
├─ docs/                   PHASE_PLAN.md (roadmap), GAP_MAP.md (current vs. target)
├─ composer.json           Dependencies + PSR-4 autoloading for app/Domain
├─ cron.php                Automatic-backup runner (CLI, honours BackupSchedule)
├─ app/                    Application layer (NOT web-accessible)
│  ├─ Domain/              New namespaced code (Digos\Domain\), pure where possible
│  ├─ Repo/                The only place direct database access is allowed
│  ├─ config.php           DB credentials, timezone, mail-from  ← EDIT THIS
│  ├─ bootstrap.php        Loads everything below
│  ├─ Database.php         PDO wrapper (rows/row/exec/insert/update/tx)
│  ├─ Helpers.php          Envelopes, validation, money, amount-in-words
│  ├─ Auth.php             Login, roles/permissions, session timeout, audit log
│  ├─ Master.php           Employees, offices, departments, functions, timekeepers
│  ├─ Payroll.php          Periods, computation, workflow, numbering, undo, payslips
│  ├─ Reports.php          Dashboard + the 10 reports
│  ├─ PrintDoc.php         Printable forms: Daily Wage Payroll, Pag-IBIG list,
│  │                       Summary of Payroll (GF 30-A), CAFOA (blank templates)
│  └─ Settings.php         Settings + SQL backup/restore
├─ views/                  SPA page partials (HTML + page JS modules)
├─ backups/                SQL dump files (denied to the web)
└─ public/                 ← POINT YOUR WEB SERVER'S DOCUMENT ROOT HERE
   ├─ index.php            SPA shell   ├─ api.php     JSON API
   ├─ login.php / logout.php           ├─ print.php   Print view
   ├─ download.php         Backup download
   └─ assets/  css/app.css, js/app.js
```

## Installation (XAMPP example, 5 minutes)

1. **Requirements:** PHP 8.1+ with `pdo_mysql`, MySQL/MariaDB.
2. Copy the `php/` folder somewhere outside the web root, e.g. `C:\apps\digos-payroll\`.
3. **Configure:** edit `app/config.php` — DB host/name (and `MAIL_FROM`). Leave the
   credentials alone; step 5 replaces them.
4. **Create/update the database:**

   ```
   php tools/migrate.php
   ```

   The runner creates the database if it does not exist and applies every
   pending migration from `migrations/`, so this same command handles both a
   fresh install and an upgrade. `--status` lists what is applied and pending;
   `--dry-run` shows what would run without changing anything.

   Migrations are recorded in `schema_migrations` and checksum-verified: an
   already-applied migration that has been edited is reported rather than
   silently skipped. **Take a backup before migrating real data** — MariaDB
   commits implicitly on DDL, so a failed migration cannot be rolled back.
5. **Create least-privilege database accounts:**

   ```
   php tools/create-app-user.php
   ```

   XAMPP's default is to run everything as `root` with no password, which gives
   the web application DROP rights over every database on the server. This
   creates two narrower accounts and writes `app/config.local.php` (git-ignored)
   pointing the application at the restricted one:

   | Account | Rights | Used by |
   | --- | --- | --- |
   | `<db>_app` | SELECT, INSERT, UPDATE, DELETE | the web application |
   | `<db>_migrate` | the above plus CREATE, ALTER, DROP, INDEX | `tools/migrate.php` only |

   `tools/migrate.php` picks up the migrate account automatically. **Keep a copy
   of `app/config.local.php`** — it is not in version control and the passwords
   cannot be recovered from the database.

6. **Point the docroot at `public/`.** In Apache (`httpd-vhosts.conf`):

   ```apache
   <VirtualHost *:80>
     ServerName payroll.local
     DocumentRoot "C:/apps/digos-payroll/public"
     <Directory "C:/apps/digos-payroll/public">
       Require all granted
       AllowOverride All
     </Directory>
   </VirtualHost>
   ```

   (Quick test without a vhost: `php -S localhost:8080 -t public` from the `php/` folder.)

   **Alternative — XAMPP htdocs, no vhost needed (verified):** copy the whole
   `php/` folder to `C:\xampp\htdocs\digos-payroll\` and browse to
   `http://localhost/digos-payroll/public/`. This works because XAMPP's
   htdocs has `AllowOverride All`, so the bundled `.htaccess` files return
   **403** for `app/` and `backups/` (confirmed on Apache 2.4 + PHP 8.1).
   Prefer the vhost setup for production; htdocs is fine for a LAN pilot.

7. **Sign in:** `admin@digos.gov.ph` / `ChangeMe!123` — then immediately go to
   **Users** and change the password (edit the admin, set a new password).
8. **First-run configuration** is the same as the Apps Script edition:
   Settings (signatories, rates), Users, Offices, Periods.
9. **Automatic backups:** register a daily 2 AM task running
   `php.exe C:\apps\digos-payroll\cron.php`
   (Task Scheduler on Windows, crontab on Linux). The script itself skips
   runs according to the _Backup Schedule_ setting (off/daily/weekly).

## Development

```
composer install                              dependencies (PHPUnit)
php vendor/bin/phpunit                        full suite
php vendor/bin/phpunit --testsuite unit       pure logic, no database
php vendor/bin/phpunit --testsuite architecture   structural guards
```

The **architecture** suite enforces two structural rules that the phase plan
depends on, and it runs in CI on every push:

- Direct `DB::` access is confined to `app/Repo/`, so the scope-enforcement
  gateway added in Phase 2 cannot be bypassed by a stray query. Pre-existing
  modules are grandfathered into an allowlist that may only shrink.
- Every endpoint is routed, declares a permission, and — if it mutates
  anything — writes an audit-log action.

The **integration** suite needs a database whose name contains `test`; it
refuses to run against anything else, so it can never touch live payroll data:

```
DB_NAME=digos_payroll_test php tools/migrate.php
DB_NAME=digos_payroll_test php vendor/bin/phpunit --testsuite integration
```

`DB_HOST`, `DB_NAME`, `DB_USER` and `DB_PASS` override `app/config.php` when set.

Roadmap and conventions: [`docs/PHASE_PLAN.md`](docs/PHASE_PLAN.md),
[`docs/GAP_MAP.md`](docs/GAP_MAP.md), [`CLAUDE.md`](CLAUDE.md).

## Security notes

- All queries are prepared statements; all output is escaped client-side
  (`esc()`), server-rendered pages use `htmlspecialchars`.
- `app/` and `backups/` ship with `Require all denied` .htaccess files as a
  second line of defense, but keeping the docroot on `public/` is the real fix.
- Sessions are HttpOnly + SameSite=Lax; enable the `secure` cookie flag in
  `app/config.php` once you serve over HTTPS.
- Passwords are bcrypt-hashed; the seed admin hash must be changed on day one.
- Payroll numbering uses a `Counters` row locked `FOR UPDATE` inside a
  transaction — concurrent saves cannot produce duplicate numbers.

## Payslip email

`apiEmailPayslips` uses PHP's `mail()`. On Windows/XAMPP configure
`[mail function]` in `php.ini` (SMTP host/port) or swap in PHPMailer if your
mail provider requires authenticated SMTP.

## Troubleshooting

| Symptom                   | Fix                                                                                                                                                                                                |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Blank page / 500          | Check PHP error log; usually wrong DB credentials in `app/config.php`.                                                                                                                             |
| "Unknown action"          | `api.php` whitelists actions — a typo in a view, or an outdated api.php.                                                                                                                           |
| Login always fails        | Re-import the seed user or reset: `UPDATE Users SET PasswordHash='<new bcrypt hash>' WHERE Email='admin@digos.gov.ph';` (generate with `php -r "echo password_hash('NewPass', PASSWORD_BCRYPT);"`) |
| Session expires instantly | Check server clock/timezone vs `APP_TIMEZONE`.                                                                                                                                                     |
| QR missing on printouts   | The print view loads the QR from api.qrserver.com; offline networks will show a blank box (harmless).                                                                                              |
| Restore fails midway      | Restores run in a transaction; data is untouched. Check the dump file exists in `backups/`.                                                                                                        |
