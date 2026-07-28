# SCHEMA — Phase 1 Core Data Model

**Phase 1 deliverable.** The frozen data model every later phase builds on.

**Status:** awaiting sign-off. Per [PHASE_PLAN.md](PHASE_PLAN.md), Phase 2 does not start
until this document is explicitly approved — *"this is the highest-leverage review point in
the project."*

**Applies migrations** `0003`–`0009`. Baseline is
[`0001_baseline_schema.sql`](../migrations/0001_baseline_schema.sql); `0002` added
`Employees.CashCard`.

**Platform:** MariaDB 10.4.32 (EOL, upgrade target 10.11 LTS). No row-level security, no
`SKIP LOCKED`, no native `JSON` — see [CLAUDE.md](../CLAUDE.md).

---

## What Phase 1 changed, and why

| # | Migration | Answers |
|---|---|---|
| 0003 | `employment_types` | What is a JO entitled to, versus a Plantilla? |
| 0004 | `office_hierarchy_and_function_code` | Which office reports to which, and which Function/PPA is this really? |
| 0005 | `contracts` | What rate was in force *when this payroll was prepared*? |
| 0006 | `payroll_line_charging` | Which appropriation pays for this line? |
| 0007 | `payroll_actor_keys` | Who prepared this, as an identity rather than a name? |
| 0008 | `dtr_days` | What happened on a given date? |
| 0009 | `referential_integrity` | Can a payroll point at an employee who no longer exists? |

---

## Entities

### Reference data

**`EmploymentTypes`** *(new, 0003)* — `TypeCode` PK. Seeded `JO`, `COS`, `PLA`.

Carries the computation flags the resolvers branch on: `RateBasis`, `EarnsHolidayPay`,
`EarnsOvertime`, `EarnsNightDiff`, `EarnsLeave`. These exist as columns so Phase 4's
resolvers stay pure — they receive the row rather than reaching for a setting or
hardcoding a type name. Phase 4's exit gate requires a fixture for *"JO/COS vs Plantilla
holiday pay divergence"*; this table is what that fixture varies.

`Employees.EmploymentTypeCode` → `EmploymentTypes.TypeCode`. The legacy free-text
`EmploymentType` string remains and is still what the SPA reads.

**`Offices`** — gains `ParentOfficeCode` (self-referencing) and `FunctionCode`.
**`Departments`** — gains `ParentDeptCode` (self-referencing).
**`Functions`** — gains `OwningOfficeCode`.

Parent links are `ON DELETE SET NULL`: deleting a parent office must never cascade into
deleting the child offices whose payroll history hangs off them.

### Engagement

**`Contracts`** *(new, 0005)* — `ContractID` PK, `EmployeeID` → `Employees`,
`TypeCode` → `EmploymentTypes`, plus `RateBasis`, `Rate`, `StartDate`, `EndDate`, `Status`.

`Employees` previously held one start/end pair and one rate, overwritten in place on every
edit. A renewal destroyed the prior rate, so a payroll printed last quarter could not be
reconciled against the rate in force when it was prepared. Phase 6 has a rule for
*"daily rate ≠ contract rate"* with nothing to compare against until this exists.

Backfilled one contract per employee. **That is all the history there is** — this migration
cannot recover what was overwritten before it ran. Accumulation starts here.

### Payroll

**`Payroll`** — gains `FunctionCode`, `PreparedByUser`, `ApprovedByUser`.
**`PayrollDetails`** — gains `ChargedOfficeCode`, `FunctionCode`.

Charging moved to the **line**, not the header. An employee's home office is where they are
assigned; the charged office is which appropriation pays. They are usually the same and
occasionally not, and when they differ, deriving from the home office silently bills the
wrong appropriation. Phase 6's *"payroll contains lines outside preparer's scope"* rule
needs the charge on the line to be checkable at all.

`PreparedByUser` / `ApprovedByUser` are real keys to `Users`. The display strings stay —
the printed form must show the name as rendered at the time, and Phase 8's payload hash
depends on that not drifting when a user is renamed. The string is the historical record;
the key is the identity.

### Timekeeping

**`DtrDays`** *(new, 0008)* — one row per employee per date, `UNIQUE (EmployeeID, WorkDate)`.

The plan's largest unstated dependency. Phase 4's time-window intersection, Phase 5's
employee × day coverage matrix, and Phase 6's calendar and shift rules all compute per
date, and none had an input. Phase 3B fills this table.

`Source` (`Manual` / `Biometric` / `Imported`) is load-bearing, not descriptive: Phase 6's
first rule checks that a manual entry is covered by an approved Bio Exemption, which cannot
be written if the two are indistinguishable once stored. Provenance is only knowable at
write time.

Times are nullable — an absence, a holiday and a rest day are real rows with no clock
events. The row's *existence* is the record that the date was accounted for, which is
exactly what a red cell on the Phase 5 matrix means when it is missing.

---

## Employee tiers

Phase 1 **classifies**; Phase 2 **separates**. The classification is frozen here.

**Tier 1 — Directory.** `EmployeeID`, `EmployeeNo`, `LastName`, `FirstName`, `MiddleName`,
`Suffix`, `OfficeCode`, `Department`, `Division`, `Position`, `EmploymentType(Code)`,
`Status`, `PhotoURL`, `SignatureURL`, `DateHired`.

**Tier 2 — Restricted.** `TIN`, `GSIS`, `PhilHealth`, `PagIBIG`, **`CashCard`**,
`Birthdate`, `Gender`, `Address`, `Contact`, `Email`, `SalaryRate`, `DailyRate`,
`HourlyRate`, `MonthlyRate`.

> **Deliberate deviation from the task list, requiring sign-off.** The physical split into a
> separate table is **not** in these migrations. Moving those columns breaks every reader
> today — `SELECT *` in [Master.php:18](../app/Master.php#L18) among them — and separating
> them buys nothing until the Phase 2 gateway exists to enforce the boundary. Creating the
> table now and backfilling it would produce two sources of truth that drift; creating it
> empty would be decoration.
>
> The split therefore lands as the **first migration of Phase 2**, atomically with the
> gateway that makes it meaningful. Until then `Employees` remains authoritative for both
> tiers, and **Tier 2 data is still readable by every role holding `employee.view`** —
> `GAP_MAP` finding 4, unchanged by Phase 1.

---

## Known-unresolved data, surfaced by the backfills

Measured against a copy of the live database:

| What | Rows | Why it is NULL |
|---|---|---|
| `Offices.FunctionCode` | 3 of 3 | Two offices store `9999`, which is neither a `FunctionCode` nor a `FunctionName`; one is empty. Not recoverable by guessing |
| `Payroll.FunctionCode` | 3 of 3 | Inherited — the header string is empty and the office could not resolve |
| `PayrollDetails.FunctionCode` | 3 of 3 | Propagated from the above |

**These NULLs are the correct outcome, not a defect.** A wrong function prints an amount
under an appropriation it was never charged to. A NULL is visible at sign-off; a guess is
not. Fixing them is data entry — assign each office a real Function/PPA — and is a
prerequisite for Phase 6's CAFOA-related rules to mean anything.

**Segregation of duties, now measurable.** With `0007` applied, two of three payrolls have
`PreparedByUser` identical to `ApprovedByUser` — self-approval. This was undetectable while
identity was a display string. Phase 2 enforces the prohibition; Phase 1 merely makes it
visible, which is the point of the key.

---

## Collation — a trap these migrations already fell into

New tables here declare `ENGINE=InnoDB` and **no `CHARSET` or `COLLATE`**. That is
deliberate and load-bearing.

A foreign key between two `VARCHAR` columns of different collations fails with
`errno: 150 "Foreign key constraint is incorrectly formed"`. The baseline tables declare no
collation, so they take the database default — and on a shared host the *provider's panel*
creates the database and picks that default. An early draft of `0003`, `0005` and `0008`
hardcoded `utf8mb4_unicode_ci`, which worked against a copy of the live database (whose
default happens to be `utf8mb4_unicode_ci`) and **failed on a fresh install**, where the
server default is `utf8mb4_general_ci`. That is precisely the InfinityFree case.

Inheriting the default means both sides of every key always agree, whatever the host chose.

Verified against three databases:

| Path | Database collation | Result |
|---|---|---|
| Fresh install, server default | `utf8mb4_general_ci` | 20 FKs, 17 tables |
| Fresh install, explicit `general_ci` | `utf8mb4_general_ci` | 20 FKs, 17 tables |
| Upgrade a copy of the live database | `utf8mb4_unicode_ci` | 20 FKs, 17 tables |

The one arrangement that still breaks is a database whose *default* disagrees with its own
existing tables — which cannot arise naturally, only by restoring a dump into a
differently-collated database. Import into an **empty** database, as the deployment runbook
already instructs.

---

## Exit gate

> *Migrations run clean on a copy of production/real data. Schema reviewed and explicitly
> signed off by you before Phase 2 starts.*

**First half met.** `0003`–`0009` applied in order against a copy of `digos_payroll`, all
seven clean, 20 foreign keys created, every backfill verified by inspection.

**Caveat on what that proves.** The live database holds **2 employees, 3 payrolls, 3 payroll
lines, 0 departments**. It is development data, not a production dataset. The migrations are
proven correct against the *shape* of real data and against its known-bad values — the
`9999` function codes were caught this way — but not against production *volume* or the
variety a full employee roster would contain. Re-run this before any real cutover.

**Second half outstanding.** Sign-off is yours.
