# Digos Payroll System — working notes

Payroll processing for JO/COS personnel at the City Government of Digos, with a
pre-audit control layer being added phase by phase.

**Read [`docs/PHASE_PLAN.md`](docs/PHASE_PLAN.md) before starting work.** It defines the phase
order, the exit gate for each phase, and the rule that scope is frozen per phase — new ideas
go in its Backlog section rather than into the current phase.
[`docs/GAP_MAP.md`](docs/GAP_MAP.md) records what existed versus what each phase targets,
with file and line references — but it is a **Phase 0 snapshot** (audited 2026-07-27 at
`d777107`, when the tree had 49 routes; it now has 96). Trust it for rationale and for the
findings that shaped the plan, not for the current shape of the tree.

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
another office's rows. Four pre-existing files are grandfathered into an allowlist in that
test — it started at seven, and `PrintDoc`, `Reports` and `Settings` have since come off it.
**That list may only shrink.**

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

- **A migration adding `ON DELETE CASCADE` or `SET NULL` owes a delete guard the same day.**
  The guards have fallen behind the schema twice — `0009` in July, and Phases 3/5/7 in
  August, when `TravelOrders`, `BioExemptions` and `Suspensions` began hanging off
  `Employees` and `apiDeleteEmployee` never learned. Neither time did anything fail:
  `SET NULL` is the worse half, because it keeps the row and erases only the link, so the
  payroll still exists with nobody having prepared it.
  `tests/Integration/CascadeGuardTest.php` reads the live schema and fails unless every
  destructive key onto a table an endpoint deletes from is either named in that endpoint or
  listed there with the reason the rows are meant to go. It proves the key was considered,
  not that the guard is right — `tests/Integration/DeleteGuardTest.php` is what proves a
  refusal refuses, in words a timekeeper can act on.
- `app/bootstrap.php` opens a database connection and starts a session. **Never require it
  from a test** — `tests/bootstrap.php` deliberately does not.
- Applied migrations are immutable. `tools/migrate.php` checksums them and refuses to run if
  one changed. Corrections go in a new numbered migration.
- MySQL commits implicitly on DDL, so a failed migration cannot be rolled back. Keep each
  migration small.
- The printed form's row count is **15, written in five places**: `PRINT_ROWS` in
  `app/PrintDoc.php`, `RuleEngine::PRINT_ROWS`, the `MaxEmployeesPerPayroll` fallback in each
  of `app/Auth.php` and `app/Payroll.php`, and the seeded setting in `0001`. All five are
  load-bearing for the printed form geometry — change one and a payroll passes pre-audit,
  prints, and drops its last lines off the bottom of the form.
  `tests/Architecture/PrintRowGeometryTest.php` fails if they diverge.
- `Payroll.PreparedBy` stores a **display name**, not a user id — it is what the printed form
  shows and must not drift. Segregation-of-duties checks read `PreparedByUser`, the foreign
  key migration `0007` added, and never the display string beside it; see
  `tests/Integration/SegregationOfDutiesTest.php`.
- `Functions` is keyed by `FunctionCode`. Phase 1 (`0004`) added `FunctionCode` to `Offices`
  and `Payroll` but did **not** drop `FunctionName` beside it: both columns still exist,
  `Employees` still carries only `FunctionName`, and `aliasFunctionIn/Out` in
  `app/Helpers.php` still translates between the two. The collapse to the code is unfinished.

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
