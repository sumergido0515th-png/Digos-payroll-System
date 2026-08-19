# Digos Payroll System — working notes

Payroll processing for JO/COS personnel at the City Government of Digos, with a
pre-audit control layer being added phase by phase.

**Read [`docs/PHASE_PLAN.md`](docs/PHASE_PLAN.md) before starting work.** It defines the phase
order, the exit gate for each phase, and the rule that scope is frozen per phase — new ideas
go in its Backlog section rather than into the current phase.
[`docs/GAP_MAP.md`](docs/GAP_MAP.md) records what actually exists versus what each phase
targets, with file and line references.

## Commands

PHP is not on `PATH` on the development machine; it is at `C:\xampp\php\php.exe`.

```
php vendor/bin/phpunit                      full suite
php vendor/bin/phpunit --testsuite unit     pure logic, no database, fast
php vendor/bin/phpunit --testsuite architecture   structural guards
php tools/migrate.php                       apply pending migrations
php tools/migrate.php --status              applied / pending
php tools/migrate.php --dry-run             show what would run
```

Integration tests need `DB_NAME` pointing at a database whose name contains `test`;
`TestDatabase` refuses anything else so the suite can never touch live payroll data.
`phpunit.xml` defaults it to `digos_payroll_test`, so the full suite runs as written above —
without that default it fell back to the working database and the guard turned all nine
integration tests into errors — which is how they sat unrun from the day they were written
(2026-07-27) until 2026-07-29. Create
the database and migrate it with `DB_NAME=digos_payroll_test php tools/migrate.php`. An
absent or unreachable test database is a skip, not a failure.

## Architecture

```
public/api.php   ── the ONLY door to business logic
   ROUTES whitelist → requireUser() → requirePermission() → api*() → writeLog() → ok()/fail()
app/             legacy procedural modules (Auth, Master, Payroll, Reports, PrintDoc, Settings)
app/Domain/      new code: namespaced Digos\Domain\, PSR-4, pure where possible
app/Repo/        the only place `DB::` may appear
views/           HTML fragments; each registers Pages.<name> = IIFE with init()
migrations/      NNNN_description.sql, forward-only, applied once
```

### Adding an endpoint

1. Write `function apiDoThing(array $p, array $user): array` in the relevant module.
2. Add one line to `ROUTES` in [public/api.php](public/api.php):
   `'apiDoThing' => ['some.permission', 'Module', 'DO_THING']`.

Action name and function name must match — the dispatcher calls the action string directly.
The third element is the audit action; `''` means read-only and unlogged.

### Database access

`DB::` is confined to `app/Repo/`. This is enforced by
`tests/Architecture/DatabaseAccessTest.php`, not by convention.

The reason is Phase 2: scope enforcement works by routing reads through a gateway that
applies the caller's scope grants, and one direct query bypasses it silently and leaks
another office's rows. Seven pre-existing files are grandfathered into an allowlist in that
test. **That list may only shrink.**

### Pure core, imperative shell

Resolvers (Phase 4) and the rule engine (Phase 6) take arrays in and return arrays out —
no `DB::`, no `$_SESSION`, no clock reads, no file I/O. The `api*` function loads the data
and calls them.

This is what makes those phases testable against fixtures, which is what their exit gates
require. `computeLine()` in [app/Payroll.php](app/Payroll.php) already follows this shape —
use it as the reference.

## Conventions

- **Database columns are PascalCase** and deliberately match the frontend field names.
  Do not "fix" this; the SPA reads them directly.
- **Identifiers are backtick-quoted** — `DB::insert`/`DB::update` do this for you.
- **Every query is a prepared statement.** No string interpolation of values, ever.
  Interpolated *column* names must come from a hardcoded allowlist, never from the payload.
- **Money** is `DECIMAL(12,2)` in the database and goes through `round2()` in PHP.
- **IDs** are `newId('PREFIX')` strings, not auto-increment (except `Logs`).
- **Errors** are `throw new RuntimeException($humanReadableMessage)` — `api.php` catches
  and returns them in the `fail()` envelope, so the message reaches the user directly.
  Write it for a timekeeper, not a developer.
- **Line endings are CRLF** across the existing tree. Match the file you are editing.
- Comments explain *why*, and are omitted when the code already says it.

## Platform decisions

- **MariaDB, not Postgres.** Decided in Phase 0. There is no row-level security, so scope
  enforcement is an application gateway backed by the architecture guard above. Rationale,
  including what this gives up: `docs/GAP_MAP.md`.
- **Deployment runs MariaDB 10.4.32** (XAMPP default) — *not* MySQL 8. It is **end of life
  since June 2024**. CI tests 10.4 and 10.11 LTS so the upgrade is already proven; write SQL
  that works on both. Notably 10.4 has no `SKIP LOCKED` and no native `JSON` type.
- **Every connection sets `DB_SQL_MODE`** (see `app/config.php`), which includes
  `STRICT_ALL_TABLES`. The server default is permissive: without it, `DECIMAL(12,2)` given
  `"12,450.00"` stores `12.00` and over-long strings truncate, both silently. Never remove it
  from a PDO options array — `tests/Integration/SqlModeTest.php` will fail if you do.
- **Credentials live in `app/config.local.php`** (git-ignored), generated by
  `php tools/create-app-user.php`. The application account has no DDL rights; migrations run
  as a separate account. Never put a real password in `app/config.php`.
- **No framework, no build step.** The SPA loads Bootstrap from a CDN and has no bundler.
  Keep it that way unless a phase explicitly calls for otherwise.

## Traps

- `app/bootstrap.php` opens a database connection and starts a session. **Never require it
  from a test** — `tests/bootstrap.php` deliberately does not.
- Applied migrations are immutable. `tools/migrate.php` checksums them and refuses to run if
  one changed. Corrections go in a new numbered migration.
- MySQL commits implicitly on DDL, so a failed migration cannot be rolled back. Keep each
  migration small.
- `MaxEmployeesPerPayroll` (setting) and `PRINT_ROWS` (in `app/PrintDoc.php`) are both 15 and
  both load-bearing for the printed form geometry. Changing one without the other breaks
  printouts.
- `Payroll.PreparedBy` stores a **display name**, not a user id. Segregation-of-duties checks
  cannot be written against it until Phase 1 adds a proper foreign key.
- `Functions` is keyed by `FunctionCode`, but `Payroll` and `Offices` store `FunctionName` as
  a string — hence `aliasFunctionIn/Out` in `app/Helpers.php`. Phase 1 collapses this to the
  code.

## Working rhythm

One phase per branch, **one phase per session** — start fresh at each phase boundary rather
than carrying the previous phase's context forward.

Before implementing a phase, read its section in `docs/PHASE_PLAN.md` and the relevant part
of `docs/GAP_MAP.md`, and **do not read other source files unless a task needs them** — the
gap map exists so the repository does not have to be re-read every time.

Write the exit-gate test first, especially for Phases 4 and 6, where the gate is "fixtures
produce exactly the expected findings, verified by automated test."

Cost model, per-phase estimates and what to cut if the budget runs short:
[`docs/EXECUTION_BUDGET.md`](docs/EXECUTION_BUDGET.md).
