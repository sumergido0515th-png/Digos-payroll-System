# GAP_MAP — Current State vs. Phase Plan Target

**Phase 0 deliverable.** Ground truth on what exists before anything is designed on top of it.

**Audited:** 2026-07-27, at commit `d777107` (branch `main`).
**Scope of audit:** all 30 source files, 5,175 lines.

**Gap sizes:** `none` (exists, usable as-is) · `modify` (exists, needs change) · `build new` (does not exist yet)

---

## Stack and layout

| | |
|---|---|
| Runtime | PHP 8.1+ (deployment runs 8.2.12, XAMPP) |
| Database | **MariaDB 10.4.32** via PDO, prepared statements throughout — measured, not assumed. XAMPP ships MariaDB, not MySQL. **10.4 reached end of life in June 2024**; upgrade target is 10.11 LTS (supported to 2028) |
| Framework | None. No autoloader before Phase 0; explicit `require_once` chain in [`app/bootstrap.php`](../app/bootstrap.php) |
| Frontend | Bootstrap 5 SPA, no build step, CDN-loaded |
| Entry points | `public/index.php` (SPA shell), `public/api.php` (JSON API), `public/login.php`, `public/print.php`, `public/download.php`, `cron.php` (CLI) |

**Request path — the single chokepoint every phase depends on:**

```
app.js api(fn,payload) → POST public/api.php {action,payload}
  → ROUTES whitelist   (api.php:20)
  → requireUser()      (Auth.php:91)   session + idle timeout
  → requirePermission()(Auth.php:122)  role → permission
  → $action($payload, $user)           the module function
  → writeLog()         (api.php:113)   if the route declares an audit action
  → ok()/fail()        (Helpers.php:12)
```

49 routes, 49 `api*` functions, exact 1:1 correspondence (enforced by `tests/Architecture/RouteTableTest.php`).

---

## Data model

Baseline schema preserved verbatim at [`migrations/0001_baseline_schema.sql`](../migrations/0001_baseline_schema.sql).

| Feature area | Current state | Target (phase plan) | Gap |
|---|---|---|---|
| **Employee — Tier 1 directory** | `Employees` table: name, `EmployeeNo`, `OfficeCode`, `Position`, `EmploymentType`, `PhotoURL`, `Status` | Same fields, split into its own tier | `modify` |
| **Employee — Tier 2 restricted** | **Same table.** `TIN`, `GSIS`, `PhilHealth`, `PagIBIG`, `CashCard`, `Birthdate`, `Address`, `SalaryRate`, `DailyRate`, `HourlyRate`, `MonthlyRate` all sit in `Employees` and are returned by `SELECT *` ([Master.php:18](../app/Master.php#L18)) to every role holding `employee.view` — HR, Payroll Officer, Accounting, Timekeeper **and Viewer** | Separate restricted tier, access-controlled | `modify` — **live exposure, fix early** |
| **Biometric number** | Does not exist | Tier 1 field | `build new` |
| **Contract validity flag** | `ContractStart` / `ContractEnd` columns on `Employees`; no history | Tier 1 flag derived from a Contract entity | `modify` |
| **Contract entity** | Does not exist. One start/end pair per employee, overwritten on edit — no rate history, no versioning | `Contract`: employee, type, rate, effectivity start/end, status | `build new` |
| **Office/Department** | `Offices` (`OfficeCode` PK) and `Departments` (`DeptCode` PK), both flat | Code, name, **parent hierarchy** | `modify` |
| **Function / PPA code** | `Functions` table keyed by `FunctionCode`, but `Payroll` and `Offices` store `FunctionName` **as a string**. Requires the `aliasFunctionIn/Out` workaround ([Helpers.php:148-158](../app/Helpers.php#L148)) | Code, description, owning office; referenced by code | `modify` |
| **Employment type** | Free-text `EmploymentType` column, no enumeration, no computation implications attached | JO / COS / Plantilla with distinct computation flags | `modify` |
| **`charged_office_id` on payroll lines** | Header-level only, copied from the office record at save ([Payroll.php:281](../app/Payroll.php#L281)) | **First-class field on payroll lines**, not derived from home office | `build new` |
| **`function_code` on payroll lines** | Header-level `FunctionName` string, same derivation | First-class field on payroll lines | `build new` |
| **Foreign keys** | **None.** No FK constraint anywhere in the schema | Referential integrity | `build new` |
| **Migrations** | `schema.sql` monolith, `CREATE TABLE IF NOT EXISTS` only — could not express a change to an existing table | Numbered, ordered, applied-once migrations | **`none` — built in Phase 0** |

---

## Auth and scope

| Feature area | Current state | Target | Gap |
|---|---|---|---|
| **Authentication** | Email + bcrypt, PHP sessions, idle timeout, `session_regenerate_id` on login ([Auth.php:58-75](../app/Auth.php#L58)) | Same | `none` |
| **Roles** | 6 hardcoded in a `const`: Administrator, HR, Payroll Officer, Accounting, Timekeeper, Viewer ([Auth.php:13-52](../app/Auth.php#L13)) | 7 different ones: Encoder, Pre-Auditor, Payroll In-Charge, Office Head, Admin, Internal Auditor/COA liaison, HRMO | `modify` — a remap, not a rename |
| **Permissions** | Flat action strings (`payroll.approve`), checked at the route | Actions only, no scope baked in | `none` — already the right shape |
| **Scope enforcement** | **None.** `Users.OfficeCode` is stored and never read by any query. `apiListPayrolls`, `apiListEmployees`, `apiRunReport` and `apiGetPrintHtml` all return every office's rows to every user | `scope_grant` table + enforced gateway | `build new` |
| **`scope_grant` table** | Does not exist | `user_id, role_code, office_id, function_code, employment_type, fiscal_year, can_read, can_write, valid_from, valid_to, granted_by, granted_at` | `build new` |
| **Row-level security** | Not available — MariaDB has no RLS. Decision recorded: **stay on MariaDB**, enforce in an application gateway | Enforcement layer | `build new` |
| **Database credentials** | Was `root` with an empty password, full DDL over every database on the server. Now a least-privilege `digos_payroll_app` account (DML only) with credentials in git-ignored `app/config.local.php`; migrations run as a separate `digos_payroll_migrate` account | Least privilege | `none` — resolved in Phase 0 |
| **`sql_mode`** | Was the MariaDB default: **not strict**. `DECIMAL(12,2)` given `"12,450.00"` stored `12.00`, `DECIMAL(6,2)` given `99999.99` stored `9999.99`, over-long names truncated — all silently, all measured on this server. Now `STRICT_ALL_TABLES` on every connection, guarded by `SqlModeTest` | Reject bad values | `none` — resolved in Phase 0 |
| **Segregation of duties** | **None.** `payrollTransition()` ([Payroll.php:342](../app/Payroll.php#L342)) validates the status transition only, never identity. `PreparedBy` is a **display-name string** ([Payroll.php:284](../app/Payroll.php#L284)), so an SoD check cannot even be written against it | `prepared_by != current_user`, enforced in code | `build new` — **needs a Phase 1 schema change first** |
| **Cross-scope conflict detection** | Does not exist. `duplicateEmployees()` ([Payroll.php:308](../app/Payroll.php#L308)) does an unredacted cross-office lookup and returns full names + payroll numbers | Elevated check returning *redacted* findings per scope | `modify` |
| **Grant lifecycle** | Does not exist | Expiring grants, capped delegation, auto-revoke on separation | `build new` |
| **Audit log** | `Logs` table, written for every mutating route, records user/action/module/details/IP ([Auth.php:131](../app/Auth.php#L131)) | Same, extended | `none` — solid foundation |

---

## Documents and timekeeping

| Feature area | Current state | Target | Gap |
|---|---|---|---|
| **Memorandum** | Does not exist | Control no., effectivity kinds, supersession chain | `build new` |
| **Bio Exemption** | Does not exist | Employee, reason code, validity, alternate proof | `build new` |
| **Travel Order** | Does not exist | TO no., destination, dates, per diem flag | `build new` |
| **Work Shift** | Does not exist | Versioned shift with ND window, rest days | `build new` |
| **DTR / day-level timekeeping** | **Does not exist.** `PayrollDetails` stores `DaysWorked`, `HoursWorked`, `OvertimeHours`, `LateMinutes`, `UndertimeMinutes`, `AbsentDays` as **manually keyed period totals** ([Payroll.php:109](../app/Payroll.php#L109)) — there is no per-date record anywhere in the system | Per-date records that resolvers and the coverage matrix operate on | `build new` — **blocks Phases 4, 5 and 6** |
| **Biometric log ingest** | Does not exist | Import + reconciliation against manual entries | `build new` |
| **Attachments / file storage** | Does not exist. The only file handling is SQL backup dumps ([Settings.php:67](../app/Settings.php#L67)) served by `download.php` | Upload, SHA-256 dedup, binding to covered dates | `build new` |
| **Holiday / calendar** | Does not exist. No holiday table, no day-type concept. `OvertimeMultiplier` is one global setting | Scoped, versioned day-type table with legal basis | `build new` |

> **Phase-plan implication.** Phase 4's time-window intersection, Phase 5's employee×day coverage matrix, and the calendar and shift rule families in Phase 6 all compute *per date*. None of them have an input until day-level DTR exists. This is the plan's largest unstated dependency.

---

## Payroll processing

| Feature area | Current state | Target | Gap |
|---|---|---|---|
| **Computation** | `computeLine()` ([Payroll.php:109](../app/Payroll.php#L109)): `(daily×days + hourly×hours + hourly×OTmult×OT) − (perMin×(late+UT) + daily×absent)`, then flat deductions. Recomputed server-side on every save | Rule-checked computation | `modify` |
| **Purity** | `computeLine()` is *already* pure (`$emp`, `$line`, `$cfg` in → array out). `apiComputePayroll` is the shell | Pure functions with fixtures | `none` — pattern already present, extend it |
| **Numbering** | `Counters` row locked `FOR UPDATE` in a transaction ([Payroll.php:82](../app/Payroll.php#L82)) — concurrency-safe | Same, plus print serials | `none` |
| **Workflow states** | `Draft → Pending → Approved → Released`, plus `Cancelled` ([Payroll.php:14-20](../app/Payroll.php#L14)) | `DRAFT → FOR_PRE_AUDIT → PRE_AUDIT_APPROVED → FOR_PRINTING → PRINTED → SUBMITTED`, plus `SUSPENDED` and `RETURNED_TO_PREPARER` | `modify` |
| **Suspensions** | Does not exist | `ns_no`, ground code, deadline, settlement tracking; employee-scoped | `build new` |
| **Batch size** | Hard cap of 15 lines (`MaxEmployeesPerPayroll` setting **and** `PRINT_ROWS = 15` in [PrintDoc.php:27](../app/PrintDoc.php#L27), which drives the printed form geometry) | Splitting clean vs. suspended employees across batches | `modify` — interacts with the cap **and** page layout |
| **Rule engine / findings** | Does not exist. Validation is scattered inline `throw new RuntimeException` calls | `validate(payrollPeriod) → Finding[]`, one pure function, severity-tiered | `build new` |
| **Undo** | Single-step, stored in **one global** `Settings` row `_PayrollUndo` ([Payroll.php:400](../app/Payroll.php#L400)) — two users' undos collide; can revert a status change without an audit narrative | Reconsider against audit integrity | `modify` |

---

## Print and output

| Feature area | Current state | Target | Gap |
|---|---|---|---|
| **Print forms** | Four HTML builders in [PrintDoc.php](../app/PrintDoc.php): Daily Wage Payroll, Pag-IBIG list, Summary (GF 30-A), CAFOA | Same, plus gating | `none` |
| **PDF generation** | **None.** HTML → browser "Save as PDF". No PDF engine, no stored bytes | Mandatory PDF preview; stored PDF bytes at approval | `build new` — needs a renderer dependency |
| **Payload hash** | Does not exist | `sha256(canonical_json(...))` at approval, re-verified at print | `build new` |
| **Print gating** | None. `apiGetPrintHtml` requires only `print.run`; no status check | Official print only post-approval; Draft watermarked | `build new` |
| **Print serial / reprint reason** | Does not exist | Serial + mandatory reason, stamped in footer | `build new` |
| **Certification / NS slip / settlement report** | Do not exist | Three print artefacts | `build new` |
| **QR on printouts** | Fetched live from `api.qrserver.com` ([Helpers.php:103](../app/Helpers.php#L103)) — blank on an offline LAN, already noted in the README | Offline-safe | `modify` |

---

## Search, filters, reporting

| Feature area | Current state | Target | Gap |
|---|---|---|---|
| **Reports** | 10 report types in [Reports.php](../app/Reports.php), unscoped | Same, scope-enforced | `modify` |
| **Filtering** | Mixed. Payroll and employee lists filter in SQL; periods, offices, departments and timekeepers read the **whole table and filter in PHP** with `array_filter` (e.g. [Payroll.php:29](../app/Payroll.php#L29), [Master.php:164](../app/Master.php#L164)) | Faceted, server-side, scope-enforced | `modify` |
| **Global control-number search** | Does not exist | Single box, jumps to record | `build new` |
| **URL-encoded filter state** | Does not exist — the SPA has no router, `showPage()` toggles CSS classes ([app.js:183](../public/assets/js/app.js#L183)) | Shareable filter links | `build new` |
| **Saved views per role** | Does not exist | Role defaults | `build new` |
| **Export to CSV/XLSX** | Does not exist | With active filters in the header | `build new` |
| **Watchlists** | Does not exist | Expiring exemptions, contracts, stale memos, overdue suspensions | `build new` |
| **Citywide aggregate permission** | Does not exist. Dashboard ([Reports.php:17](../app/Reports.php#L17)) already shows citywide totals to everyone with `dashboard.view` | Behind explicit `VIEW_CITYWIDE_AGGREGATE` | `build new` |

---

## Engineering baseline

| Feature area | Before Phase 0 | After Phase 0 | Gap |
|---|---|---|---|
| **Dependency management** | None | `composer.json`, PSR-4 for `Digos\Domain\` and `Digos\Repo\` | `none` |
| **Test framework** | None | PHPUnit 10.5, three suites (architecture / unit / integration) | `none` |
| **Migrations** | `schema.sql` monolith | `migrations/` + `tools/migrate.php`, checksum-verified | `none` |
| **CI** | `laravel.yml` — a Laravel template running `composer install` and `php artisan test` in a repo with neither. **Could never have passed** | `ci.yml` — lint, guards, unit, migrate, idempotence, integration, on PHP 8.1 and 8.2 | `none` |
| **Architecture guards** | None | DB access confined to `app/Repo/`; every route resolved, permissioned and audited | `none` |
| **Conventions doc** | None | [`CLAUDE.md`](../CLAUDE.md) | `none` |

---

## Exit gate

> *Every phase below can be traced to a specific file or table in the current repo, or explicitly marked "does not exist yet."*

**Met.** Every row above cites a file and line, or is marked `build new`.

## Findings that change the plan

1. **Day-level DTR does not exist** and Phases 4, 5 and 6 cannot be built without it. Needs a Phase 1 schema addition and a build phase before Phase 4.
2. **`PreparedBy` is a display-name string**, so the Phase 2 segregation-of-duties check needs a Phase 1 schema change first.
3. **Postgres RLS is not available** on MySQL; Phase 2 must use the application-gateway path, which requires confining database access to a repository layer (guard now in place).
4. **Tier 2 employee data is exposed to every role today** — worth fixing ahead of the full Phase 1 tier split.
5. **The 15-line batch cap is load-bearing for the printed form**, and interacts with Phase 7's employee-scoped suspensions.
6. **The deployed database is MariaDB 10.4, which is end of life.** Phase 1 writes the schema that everything else builds on, so the upgrade to 10.11 LTS is cheapest *before* that work, not after. CI already proves both versions.

## Resolved during Phase 0

- **Silent value coercion.** The server ran without `STRICT_ALL_TABLES`, so out-of-range and mistyped values were coerced rather than rejected. Every connection is now strict. Today's payroll path was not corrupting money — `num()` in `app/Helpers.php` strips thousands separators before values reach the database — but string truncation was unguarded, and every module added in Phases 1–8 is a fresh chance to write to a `DECIMAL` column directly. This is the backstop.
- **Root database access.** The application ran as `root` with an empty password. It now runs as an account that cannot alter or drop anything; migrations use a separate account; credentials are out of version control.
