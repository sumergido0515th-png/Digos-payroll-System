# DIGOS CITY GOVERNMENT — Payroll Management System

Payroll processing for JO/COS personnel at the City Government of Digos, with a
pre-audit control layer. PHP 8 + MySQL/MariaDB, self-hosted on any LAMP/WAMP stack
(XAMPP, Laragon, IIS + PHP, Linux + Apache/Nginx).

No framework and no build step: the front end is a Bootstrap 5 single-page app
served as plain files, so a deployment is a file copy and a database migration.

## Layout

```
├─ public/                ← POINT YOUR WEB SERVER'S DOCUMENT ROOT HERE
│  ├─ index.php           SPA shell        ├─ api.php     JSON API
│  ├─ login.php / logout.php               ├─ print.php   Print view
│  ├─ download.php        Backup download  ├─ attachment.php
│  └─ assets/             css/app.css, js/app.js
├─ app/                   Application layer (NOT web-accessible)
│  ├─ Domain/             Namespaced code (Digos\Domain\), pure where possible
│  ├─ Repo/               The only place direct database access is allowed
│  ├─ config.php          Timezone, mail-from, session policy (no real credentials)
│  ├─ config.local.php    Real credentials, git-ignored, created by tools/
│  ├─ bootstrap.php       Loads everything below
│  ├─ Database.php        PDO wrapper (rows/row/exec/insert/update/tx)
│  ├─ Helpers.php         Envelopes, validation, money, amount-in-words
│  ├─ Auth.php            Login, roles/permissions, session timeout, audit log
│  ├─ Access.php          Scope grants and enforcement
│  ├─ Master.php          Employees, offices, departments, functions, timekeepers
│  ├─ Dtr.php             Daily time records
│  ├─ Payroll.php         Periods, computation, workflow, numbering, undo, payslips
│  ├─ PreAudit.php        Rule engine entry point
│  ├─ Reports.php         Dashboard + the 10 reports
│  ├─ PrintDoc.php        Printable forms: Daily Wage Payroll, Pag-IBIG list,
│  │                      Summary of Payroll (GF 30-A), CAFOA
│  ├─ Documents.php       Employee documents   ├─ Attachments.php
│  └─ Calendar.php        Holidays and shifts  └─ Settings.php  Settings + backup
├─ views/                 SPA page partials (HTML + page JS modules)
├─ migrations/            Numbered schema migrations, applied once, in order
├─ seeds/demo-seed.sql    Fabricated employees for demo/UAT instances
├─ tools/                 migrate.php, create-app-user.php, build-deploy.php
├─ tests/                 PHPUnit: architecture guards, unit, integration
├─ docs/                  Roadmap, schema, roles, rules, deployment
├─ backups/               SQL dump files (denied to the web)
├─ attachments/           Uploaded files (denied to the web)
└─ cron.php               Automatic-backup runner (CLI, honours BackupSchedule)
```

## Installation (XAMPP example, 5 minutes)

1. **Requirements:** PHP 8.1+ with `pdo_mysql`, MySQL/MariaDB.
2. Copy the project somewhere outside the web root, e.g. `C:\apps\digos-payroll\`.
3. **Create/update the database:**

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

4. **Create least-privilege database accounts:**

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
   cannot be recovered from the database. Never put a real password in
   `app/config.php`; that file is tracked in git.

5. **Point the docroot at `public/`.** In Apache (`httpd-vhosts.conf`):

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

   (Quick test without a vhost: `php -S localhost:8080 -t public` from the
   project root. Note that `php -S` ignores `.htaccess` entirely, so it cannot
   verify the deny rules.)

   **Alternative — XAMPP htdocs, no vhost needed (verified):** copy the whole
   project to `C:\xampp\htdocs\digos-payroll\` and browse to
   `http://localhost/digos-payroll/public/`. This works because XAMPP's
   htdocs has `AllowOverride All`, so the bundled `.htaccess` files return
   **403** for `app/` and `backups/` (confirmed on Apache 2.4 + PHP 8.1).
   Prefer the vhost setup for production; htdocs is fine for a LAN pilot.

6. **Sign in:** `admin@digos.gov.ph` / `ChangeMe!123` — then immediately go to
   **Users** and change the password (edit the admin, set a new password). The
   seed credentials are published in this repository and in every copy of the
   schema.

7. **First-run configuration**, in this order — each step depends on the one
   before it:

   | Step | Where | What to set |
   | --- | --- | --- |
   | 1 | **Settings** | Signatories (prepared/certified/approved by), agency header, rates and deduction defaults, backup schedule |
   | 2 | **Offices** | The office and department tree, and the function codes payroll charges against |
   | 3 | **Users** | One account per timekeeper/approver, with the role and office scope each may see |
   | 4 | **Employees** | Personnel records, contracts, and rates |
   | 5 | **Periods** | The payroll period to work in — nothing can be computed until one exists |

   Roles and what each may do: [`docs/ROLES.md`](docs/ROLES.md).

8. **Automatic backups:** register a daily 2 AM task running
   `php.exe C:\apps\digos-payroll\cron.php`
   (Task Scheduler on Windows, crontab on Linux). The script itself skips
   runs according to the _Backup Schedule_ setting (off/daily/weekly).

## Deployment

Deploying to shared hosting (InfinityFree and similar), including the data-privacy
constraints that apply to real payroll data:
[`docs/DEPLOY-INFINITYFREE.md`](docs/DEPLOY-INFINITYFREE.md).

Build an upload-ready package with `php tools/build-deploy.php`. It refuses to
produce a package containing credentials or database dumps.

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

## Documentation

| File | Contents |
| --- | --- |
| [`docs/PHASE_PLAN.md`](docs/PHASE_PLAN.md) | Phase order, exit gates, decision log |
| [`docs/GAP_MAP.md`](docs/GAP_MAP.md) | What exists vs. what each phase targets |
| [`docs/SCHEMA.md`](docs/SCHEMA.md) | Tables, columns, and why they are shaped that way |
| [`docs/ROLES.md`](docs/ROLES.md) | Roles, permissions, and scope grants |
| [`docs/RULES.md`](docs/RULES.md) | Pre-audit rules and their severities |
| [`docs/RESOLVERS.md`](docs/RESOLVERS.md) | Holiday, shift and authority resolution |
| [`docs/DEPLOY-INFINITYFREE.md`](docs/DEPLOY-INFINITYFREE.md) | Shared-hosting deployment, step by step |
| [`docs/EXECUTION_BUDGET.md`](docs/EXECUTION_BUDGET.md) | Cost model and per-phase estimates |
| [`docs/PHASE_1_SIGNOFF.md`](docs/PHASE_1_SIGNOFF.md) | Phase 1 exit-gate sign-off record |

## Security notes

- All queries are prepared statements; all output is escaped client-side
  (`esc()`), server-rendered pages use `htmlspecialchars`.
- `app/`, `views/`, `migrations/` and `backups/` ship with `Require all denied`
  .htaccess files as a second line of defense, but keeping the docroot on
  `public/` is the real fix.
- Sessions are HttpOnly + SameSite=Lax. The `secure` cookie flag is set
  automatically once the request arrives over HTTPS, including behind a
  reverse proxy — see `requestIsHttps()` in `app/config.php`. No configuration
  needed.
- Passwords are bcrypt-hashed; the seed admin hash must be changed on day one.
- Payroll numbering uses a `Counters` row locked `FOR UPDATE` inside a
  transaction — concurrent saves cannot produce duplicate numbers.
- Every connection sets `STRICT_ALL_TABLES`; without it MariaDB silently
  truncates over-long strings and mangles decimals.

## Payslip email

`apiEmailPayslips` uses PHP's `mail()`. On Windows/XAMPP configure
`[mail function]` in `php.ini` (SMTP host/port) or swap in PHPMailer if your
mail provider requires authenticated SMTP. Most shared hosts block `mail()`
outright, in which case payslips report as skipped.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| Blank page / 500 | Check PHP error log; usually wrong DB credentials in `app/config.local.php`. |
| "Unknown action" | `api.php` whitelists actions — a typo in a view, or an outdated api.php. |
| Login always fails | Re-import the seed user or reset: `UPDATE Users SET PasswordHash='<new bcrypt hash>' WHERE Email='admin@digos.gov.ph';` (generate with `php -r "echo password_hash('NewPass', PASSWORD_BCRYPT);"`) |
| Session expires instantly | Check server clock/timezone vs `APP_TIMEZONE`. |
| QR missing on printouts | The print view loads the QR from api.qrserver.com; offline networks will show a blank box (harmless). |
| Restore fails midway | Restores run in a transaction; data is untouched. Check the dump file exists in `backups/`. |
| Migration refuses to run | A previously applied migration was edited. Corrections go in a new numbered migration; applied ones are immutable. |

## License

See [LICENSE](LICENSE).
