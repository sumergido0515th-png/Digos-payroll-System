# Digos Payroll System — Phase Plan

**Purpose:** Pre-audit and role-based control layer for JO/COS payroll processing — reduce reprints, waste, and error rate before submission to Accounting/Audit.

**How to use this file:** Reference this document directly in Claude Code / AI sessions instead of re-explaining project context each time. Update the status column as phases complete. Do not start a phase until the previous phase's exit gate has passed. Park new ideas in the Backlog section at the bottom rather than injecting them mid-phase.

---

## Operating Principles

1. **Schema and rules before UI.** UI is the most expensive thing to regenerate. Freeze the data model before building screens.
2. **One spec, referenced not re-explained.** `SCHEMA.md` and `RULES.md` (produced in Phase 1 and Phase 6) are the source of truth. Point sessions at these files.
3. **Pure functions with test fixtures over manual review.** The rule engine and resolvers must be pure (`input → output`, no side effects) and unit-tested. A failing test is cheap to fix; a human re-reading generated output for correctness is not.
4. **Targeted diffs, not full-file regeneration**, once a file exists and works.
5. **Freeze scope per phase.** No mid-phase additions — log them in Backlog instead.
6. **Exit gate required before advancing.** Each phase has a pass/fail gate. If it doesn't pass, fix within the phase — don't carry the defect forward.
7. **Segregation of duties is enforced in code, not policy**, from Phase 2 onward: preparer ≠ pre-auditor ≠ printer, checked programmatically.

---

## Status Legend

`NOT STARTED` · `IN PROGRESS` · `BLOCKED` · `DONE`

---

## Phase 0 — Repo Audit & Gap Map

**Status:** DONE
**Depends on:** nothing
**Completed:** 2026-07-27 — deliverables: [GAP_MAP.md](GAP_MAP.md), [CLAUDE.md](../CLAUDE.md), and the engineering baseline (composer + PHPUnit, `tools/migrate.php`, CI, architecture guards)

### Objective
Establish ground truth on what already exists before designing anything new on top of it.

### Tasks
- Inventory current repo structure (tech stack, file layout, entry points)
- Inventory current data model (tables/sheets, fields, relationships)
- Inventory current auth model, if any (login, roles, session handling)
- Inventory current document handling (Memorandum, Bio Exemption, Travel Order, Work Shift) — what fields exist, how they're stored, how they're attached to payroll
- Map current payroll computation flow end to end
- Identify what's a genuine gap vs. what exists but needs modification

### Deliverable
`GAP_MAP.md` — table of: feature area | current state | target state (per this plan) | gap size (none / modify / build new)

### Exit Gate
Every phase below can be traced to a specific file or table in the current repo, or explicitly marked "does not exist yet."

### Token-saving notes
One-time cost. Skipping this causes guessing in every later phase, which compounds. Do this even if it feels like overhead — it's the cheapest phase per unit of downstream savings.

---

## Phase 1 — Core Data Model

**Status:** DONE
**Depends on:** Phase 0
**Completed:** 2026-07-29 — signed off. Deliverables: [SCHEMA.md](SCHEMA.md) + migrations `0003`–`0009` (commit `75ae428`), write paths (`60c2e65`), backfills `0013`–`0014`, and the sign-off record in [PHASE_1_SIGNOFF.md](PHASE_1_SIGNOFF.md)

> **Signed off 2026-07-29, with one item carried forward.** Three decisions accepted as
> proposed: the Tier 1 / Tier 2 split lands as Phase 2's first migration, the three existing
> self-approved payrolls are **grandfathered as development records** (Phase 7 must not assume
> every historical approval passes the new rule), and `Contracts` gets its own module in
> Phase 3. The fourth — **assigning a real Function/PPA to each of the four offices** — is
> data entry only the owner can do and is **not** a Phase 2 blocker; it blocks **Phase 6**,
> whose CAFOA rules would otherwise pass vacuously against four NULL functions. It stays in
> the Backlog until entered.

### Objective
Freeze the schema that every other phase builds on.

### Tasks
- **Employee** — split into Tier 1 (Directory: name, ID, home office, position, employment type, contract validity flag, biometric no., photo) and Tier 2 (Restricted: rate, contract amount, personal data, TIN/PhilHealth/Pag-IBIG/GSIS numbers)
- **Office/Department** — code, name, parent hierarchy if applicable
- **Function/PPA code** — code, description, owning office
- **Employment type** — JO, COS, Plantilla, with distinct computation implications flagged
- **Contract** — employee, type, rate, effectivity start/end, status
- Define `charged_office_id` and `function_code` as first-class fields on payroll lines (not derived from employee's home office — see Phase 2 rationale)
- **Day-level DTR** (settled revision 1) — `DtrDays`: one row per employee per date, carrying the raw in/out and the derived hours the period totals are currently keyed by hand. Phases 4, 5 and 6 all compute per date and have no input without it; Phase 3B builds capture on top
- **`Payroll.PreparedByUser`** (settled revision 2) — a real foreign key to `Users`. The existing `PreparedBy` display string stays, because the printed form must show the name as rendered at the time; Phase 2's segregation-of-duties check reads the key
- Draft migrations from current repo structure (per Phase 0 gap map) to this schema

### Deliverable
`SCHEMA.md` (entity-relationship description) + migration scripts, run against a copy of real data

### Exit Gate
Migrations run clean on a copy of production/real data. Schema reviewed and explicitly signed off by you before Phase 2 starts — **this is the highest-leverage review point in the project.**

### Token-saving notes
Slowest phase per unit of code produced; spend the time here. A schema mistake caught now costs a review comment. Caught in Phase 6 it costs rewriting the rule engine.

---

## Phase 2 — Auth & Scope Layer

**Status:** DONE — signed off 2026-07-31
**Depends on:** Phase 1

> **Session A landed: the model and the gate.** Migrations `0015`–`0017` (the physical
> Tier 1 / Tier 2 split, `ScopeGrants`, the role remap), the pure `ScopePredicate`, the
> `app/Repo/` gateway, segregation of duties, and the four read endpoints plus payroll save
> routed through it. **Both halves of the exit gate pass**, and each was verified to fail
> with the enforcement removed rather than only to pass with it.
>
> **Grant administration landed with it** (`app/Access.php`, `scope.manage`, the Scope Grants
> panel on the Users page). It was pulled forward out of session B because without it the
> system could not be run at all: `0016` seeds grants for administrators only, so every other
> account signed in successfully and saw nothing, with no in-app remedy. The full lifecycle —
> create user → grant one office → that user sees only that office → revoke → access gone —
> is verified over HTTP, and `GrantedBy` is now written, so the control has an audit trail of
> its own administration.
>
> **`PrintDoc.php` is done and off the allowlist** (six files left), pulled forward out of
> session B because the print path was the scope layer's largest hole: `printBundle()` took no
> user at all, so `apiGetPayroll` refused another office's payroll while `apiGetPrintHtml`
> rendered the same number in full. Reproduced live before the fix and again after. The Print
> screen now shows submitted payrolls by default, so a correction can be re-scanned and
> re-previewed without changing a filter.
>
> **Session B, 2026-07-31 — the leaks the allowlist was not measuring.** A scope audit of
> every read route found four that still returned other offices' data. The worst was
> `apiComputePayroll`: it took any `EmployeeID`, looked it up unscoped and returned that
> person's daily rate, gated on `payroll.view` alone — which Encoder, Office Head and
> Internal Auditor hold and none of them hold `employee.sensitive`. `apiRunReport` was
> running **every report citywide**, and it was missed by the first audit because that
> audit only looked at routes with no audit action and this one logs `RUN_REPORT`.
> `duplicateEmployees()` handed back other offices' employee names and payroll numbers,
> where the plan calls for a redacted finding. The dashboard showed citywide headcount and
> money to every role. All four are closed, `Reports.php` is off the allowlist (five files
> left), and [ROLES.md](ROLES.md) — the phase's second deliverable — is now generated from
> `PERMISSIONS` rather than hand-written, with `--check` to catch drift.
>
> **Closed 2026-07-31.** `Settings.php` went behind `SettingsRepo` and `BackupRepo`, leaving
> **four** files on the allowlist: `Auth.php`, `Master.php`, `Payroll.php` and
> `public/download.php`. Those stay listed on purpose — `Auth.php` reads `Users` to
> authenticate, which cannot be scoped by a layer that does not yet know who is asking.
> `BackupRepo` is also the one sanctioned holder of a raw PDO handle: a dump enumerates whole
> tables and a restore executes statements it did not compose, and neither can be a prepared
> statement.
>
> Two guards were added on the way out, both for failures the suite could not have caught.
> `RepoLoadingTest` fails when a repository is missing from `app/bootstrap.php` or
> `tests/Integration/ApplicationLayer.php` — there is no autoloader, both lists are
> maintained by hand, and missing the first is a fatal in production while missing the second
> is a green suite hiding a broken application. `BackupRestoreTest` executes the disaster
> path for the first time: dump, destroy, restore, and the value comes back.

### Objective
Build role × scope access control before any feature module, so nothing needs retrofitting.

### Tasks
- Define **roles** (Encoder, Pre-Auditor, Payroll In-Charge, Office Head, Admin, Internal Auditor/COA liaison, HRMO) — actions only, no scope baked in
- Define **scope_grant** table: `user_id, role_code, office_id, function_code, employment_type, fiscal_year, can_read, can_write, valid_from, valid_to, granted_by, granted_at` (nullable fields = wildcard)
- Implement enforcement as a **single application gateway** — `queryScoped(user, entity, filters)` — through which every read of a restricted table passes, applying the caller's scope grants. There is no database-level alternative: MariaDB has no row-level security, which is why `DB::` is confined to `app/Repo/` and guarded by `tests/Architecture/DatabaseAccessTest.php`. One direct query bypasses the gateway silently and leaks another office's rows
- Implement segregation-of-duties check gating entry to pre-audit actions: `Payroll.PreparedByUser != current_user`, read from the Phase 1 foreign key and never from the `PreparedBy` display string
- Implement cross-scope conflict detection pattern: system-level check runs with elevated privilege, returns **redacted** finding to each affected scope (no cross-office data leakage), full detail visible only to Admin/Payroll Administrator role
- Grant lifecycle: expiring grants by default, delegation with capped duration and auto-revoke, auto-revoke on separation (HRMO contract-expired trigger)

### Deliverable
Working scope enforcement layer + role/permission matrix document

### Exit Gate
Test user with CMO-only scope cannot read OCEEM rows — verified by an actual query returning empty/denied, not by UI inspection. Preparer account cannot self-approve a payroll they created.

### Token-saving notes
Build before feature modules. Retrofitting scope onto finished modules means touching every module twice. This phase and Phase 1 are the two where a mistake is most expensive to leave uncaught.

---

## Phase 3 — Document Modules (CRUD Only)

**Status:** DONE — 2026-07-31
**Depends on:** Phase 2

> Migration `0018` creates `Memorandum`, `MemorandumEmployees`, `BioExemptions`, `TravelOrders`
> and `WorkShifts`; `Contracts` (from `0005`) gets its first write path. `app/Documents.php`,
> four repositories, twenty routes, three new permission families and one page with five tabs.
>
> **The scope decisions are the part worth re-reading.** Memorandum carries `OfficeCode` and is
> registered in `ScopeEntity`. Bio exemptions, travel orders and contracts carry **no office
> code at all** — they are about a person, so their scope is that person's, applied through a
> join to `Employees`. Copying the office onto the document would need keeping in step with
> transfers, and the moment it fell behind there would be two answers to "whose row is this?".
> `WorkShifts` is unscoped reference data: a shift definition is a rule about hours.
> Memorandum coverage is checked **per employee**, because naming another office's employee on
> your own memo would otherwise make coverage a write path into a scope you cannot read.
>
> Both versioned tables behave as designed: editing a shift or renewing a contract inserts a
> row and closes the previous one the day before the new one starts. `amend()` on a contract is
> deliberately narrow enough that a rate cannot be corrected in place.
>
> Verified by `DocumentModulesTest` (19 tests, passing alone), by reverting each of five
> enforcements one at a time and confirming exactly the expected tests failed, and live over
> HTTP as a scoped account. **The live probe found a defect the suite could not**: the employee
> picker read `apiListEmployees` as a bare array when it answers `{total, page, pageSize, rows}`
> — and at the default page size of 25 it would have silently omitted everybody after the
> twenty-fifth name.

### Objective
Get Memorandum, Bio Exemption, Travel Order, and Work Shift storing and listing correctly under scope — no business logic yet.

### Tasks
- Memorandum: control no., subject, covered employees, `date_issued/date_approved/date_received`, effectivity fields (support range / specific dates / recurring / time-windowed / open-ended — store raw entry only, resolution comes in Phase 4), `supersedes_id/amends_id/revoked_by_id`
- Bio Exemption: employee, reason code, `valid_from/valid_to`, alternate proof type + attachment
- Travel Order: TO no., destination, depart/return date, per diem flag
- Work Shift: shift code, in/out, break, rest days, night differential window, effectivity — **versioned, edits create a new version rather than overwriting**
- **Contracts** *(scheduled here at Phase 1 sign-off, 2026-07-29)*: employee, type, rate, effectivity start/end, status — with **supersession semantics**, so a renewal adds a row rather than overwriting the rate in force when an earlier payroll was prepared. The table exists (`0005`) and the application never writes it; employee save deliberately does not, because mirroring the form's single start/end pair is exactly the overwrite `0005` was built to prevent. Phase 6's "daily rate ≠ contract rate" rule reads this
- Basic list/detail/create screens per document type, scoped per Phase 2

### Deliverable
Four working document modules, CRUD only

### Exit Gate
Each document type stores and lists correctly under the correct scope. No resolvers, no rule engine yet — deliberately.

### Token-saving notes
This is repetitive boilerplate — a good phase to move fast on. Save careful/expensive reasoning for Phase 4 and 6.

---

## Phase 3B — DTR Capture

**Status:** DONE — 2026-07-31
**Depends on:** Phase 3
**Added:** 2026-07-28, settled revision 1 from the Phase 0 audit

> **The seam.** Before this, a payroll line's six totals were typed straight onto the line and
> nothing could say where they came from. They are now derived by
> `Digos\Domain\Dtr\PeriodTotals`, which is pure — day rows in, totals out — and unit-tested
> against fixtures. No migration: `DtrDays` has existed since `0008` with every column this
> needs, including `Source`.
>
> **The classification is the part that matters.** `computeLine()` pays
> `daily × DaysWorked + hourly × HoursWorked + overtime`, adding rather than choosing, so the
> three have to stay disjoint or a full day is paid twice. A day at or over the standard length
> is one whole day; a shorter worked day is hours; an absence is an absent day and nothing else.
> A day with no hours and no absence contributes **nothing** — that is a rest day or an unworked
> holiday, and deciding which is Phase 4's `resolveHoliday`, not a deduction taken here.
>
> **Provenance is written, not defaulted.** `Source` is set on every row, and the biometric
> import refuses to overwrite a hand-keyed day — it reports those as conflicts instead. A manual
> entry is a claim somebody made, and Phase 6 rule #1 checks it against a covering bio exemption;
> replacing it with the device's version erases the discrepancy that check exists to find.
>
> Two entries came off `MigrationColumnsAreUsedTest`'s deferral list — `DtrDays.*` and
> `Contracts.*`, both promises made in Phase 1 — leaving only database-maintained `UpdatedAt`
> columns. A shared helper was fixed on the way through: `requireFields()` cast every value to a
> string, so an array field warned and an **empty** array passed as `"Array"`. Nothing had
> required an array field until now.

### Objective
Put real per-date timekeeping into `DtrDays`, so the resolvers have an input.

### Tasks
- Day-level DTR entry screen: employee × date grid for a period, scoped per Phase 2
- Derive the period totals `computeLine()` consumes (`DaysWorked`, `HoursWorked`, `OvertimeHours`, `LateMinutes`, `UndertimeMinutes`, `AbsentDays`) **from** the day rows rather than accepting them keyed by hand
- Keep manual entry possible but marked as such — Phase 6 rule #1 checks manual entries against covering Bio Exemptions, and cannot if manual and biometric entries are indistinguishable
- Biometric log import + reconciliation against manual entries

### Deliverable
DTR capture module writing `DtrDays`, with period totals derived from it

### Exit Gate
For a test period, every `PayrollDetails` total is reproducible by summing that employee's `DtrDays` rows — verified by automated test, not by reading the screen. A manual entry is distinguishable from a biometric one in the stored data.

### Token-saving notes
The schema is already fixed in Phase 1, so this is capture UI plus a derivation function. The derivation is worth a fixture test: it is the seam where hand-keyed totals stop being authoritative, and Phases 4–6 all trust it.

---

## Phase 4 — Resolvers

**Status:** DONE — 2026-07-31
**Depends on:** Phase 3B

> Three pure functions in `app/Domain/Resolver/`, the shell in `app/Calendar.php`, and
> migration `0019` for the two tables they read. Full write-up: [RESOLVERS.md](RESOLVERS.md).
>
> **The JO/COS divergence is the finding this phase exists for.** A Job Order or Contract
> of Service worker is engaged for services actually rendered, so they are not paid for a
> regular holiday they did not work, where a plantilla employee is. Paying it is a
> disallowance and it is the easiest mistake to make by hand. It falls out of two
> independent tie-breaks in `ruleFor()`: a rule naming the employment type beats the NULL
> fallback **however recent the fallback is**, and among equally specific rules the version
> in force **on the date being resolved** wins rather than today’s.
>
> **A missing pay rule is reported, never assumed.** A resolver that invented “unpaid, 0×”
> for an incomplete table would produce a wrong payroll indistinguishable from a correct
> one, and Phase 6 would have nothing to flag.
>
> **Overtime is deliberately not clipped to the shift** — it is by definition outside it, so
> intersecting the two would authorise nothing at all. Every other authority type is
> shift-bounded. That inversion is easy to “fix” back in, so it carries a comment at the
> exception and a test named after it.
>
> All five exit-gate cases are covered by fixtures, and each of three enforcements was
> reverted one at a time: specificity failed 4 tests, the overtime exception 1, the shift
> date filter 3 — each of them the tests that name it.
>
> **Owner decision still outstanding:** the seeded multipliers are the national default and
> need confirming against the city’s own issuances before go-live.

### Objective
Build the two hardest logic pieces in the system as pure, tested functions.

### Tasks
- `resolveAuthority(employee_id, datetime, authority_type) → { memo_id, control_no, window, source_scope, superseded_chain[] }`
  - Time-window intersection: (biometric span) × (memo window) × (shift) — never take claimed hours at face value
  - Supersession chain resolution — truncate superseded memo windows automatically, keep truncation visible on the original record
- `resolveHoliday(office_scope, date, employment_type, worked) → { day_type, paid, multiplier, legal_basis }`
  - Scope hierarchy: National → Region → Province → City → Barangay, most-specific-wins
  - Day type table: Regular Holiday, Special Non-Working, Special Working, Local Holiday, Work Suspension (datetime-granular for partial-day suspensions), Rest Day (derived from shift)
  - Employment-type resolution table: `day_type × employment_type × worked? → {paid, multiplier, legal_basis}` — seeded from actual LGU ordinance/policy, versioned with effectivity dates, `legal_basis` field required
- Shift versioning: resolve which shift version was effective on a given historical date

### Deliverable
`RESOLVERS.md` + resolver functions with unit test suite

### Exit Gate
Fixture-based tests pass for: overlapping memos, superseded windows, holiday scope precedence, JO/COS vs. Plantilla holiday pay divergence, partial-day work suspension computation

### Token-saving notes
Hardest logic in the system. Worth the token spend to get right once, with tests, rather than debug it later inside the rule engine where failures are harder to isolate from other causes.

---

## Phase 5 — Attachments & Coverage Matrix

**Status:** DONE — 2026-07-31
**Depends on:** Phase 3, Phase 4

> Migration `0020`, `CoverageMatrix` (pure), `AttachmentRepo`, `app/Attachments.php`,
> `public/attachment.php` and a two-tab page.
>
> **Coverage is one row per employee per date, not a range on the file.** A range cannot
> answer “was THIS person’s manual entry on THIS date covered?” without re-deriving who was
> named — and capturing it at upload rather than at print time is precisely so the answer
> stops being derived at all.
>
> **`Sha256` carries a UNIQUE key.** The application checks first so the refusal names what
> is being duplicated, but the constraint is the guarantee: a PHP-only check is one two
> concurrent requests can both pass, and there is a test that inserts a colliding row
> directly to prove the database refuses it.
>
> **Only one cell state is loud.** A day is red when the employee was recorded as working,
> the record was hand-keyed, and nothing on file explains it. A biometric day, a rest day,
> an unworked holiday and a day with no record are all quiet — colouring those red would
> bury the real findings, which is the failure this screen exists to avoid.
>
> Files live in `attachments/`, outside the web root, streamed by a route that applies the
> scope predicate. Verified live: signed-in 200, no session 403, unknown id 403 — and the
> type is decided by the file’s own leading bytes, so a PHP script named `.pdf` is refused.
>
> **Backups carry the rows, not the bytes.** `Attachments` and `AttachmentCoverage` are in
> `BACKUP_TABLES`; the files are not in a SQL dump and must be copied separately. A registry
> pointing at files nobody copied is worse than none — it looks like the evidence survived.

### Objective
Bind attachments to the specific dates they justify, and make gaps visually obvious.

### Tasks
- Upload flow: capture control number, date range, covered employees at upload time (not deferred to print time)
- Store file hash (SHA-256); reject on hash collision (catches recycled/duplicate attachments)
- Bind attachment to specific covered DTR dates — this binding is what Phase 6 rule #1 checks against
- Build employee × day coverage matrix: cell states = biometric ✓ / Travel Order / Bio Exemption / Leave / Holiday / **unjustified (red)**
- Store rendered PDF bytes alongside the payroll version at approval (Phase 8) — not re-rendered on demand

### Deliverable
Attachment module with hash-dedup + coverage matrix view

### Exit Gate
Uploading a duplicate file (same hash, different control number) is rejected. A red cell on the matrix corresponds to a real, verifiable gap in a test dataset.

### Token-saving notes
UI here is simple (a grid). The hard logic is already done in Phase 4 — this phase should move quickly.

---

## Phase 6 — Pre-Audit Rule Engine

**Status:** DONE — 2026-07-31
**Depends on:** Phase 4, Phase 5

> Twenty-two rules in one pure function. Catalogue: [RULES.md](RULES.md).
>
> **The engine reports; it does not decide what happens next.** Phase 7’s transition guard and
> Phase 8’s print gate read the severities and act. That split lets a preparer run the
> pre-audit speculatively against their own work with no consequences, and lets a rule be
> re-tiered without touching workflow code.
>
> **Missing data is not a clean bill.** A check that could not run reports INFO rather than
> passing silently — from outside, a rule with no input and a rule that found nothing look
> identical, and only one is reassuring.
>
> **CON-003 is the redacted cross-scope check Phase 2 specified.** It runs system-wide,
> because an employee paid twice by two offices is exactly the case a scoped query would
> answer “no clash” to. The finding names only the employee, already visible to the reader;
> the other payroll’s number and office are dropped before the engine sees them.
>
> The fixture caught a real bug on its first run: CMP-001 looked the contract up at the
> period END, so a contract that expired mid-period was never found — it reported “no
> contract” instead. Now looked up at the period start.
>
> **Still gated on owner data.** The CAFOA/Function-PPA rules have nothing to check while all
> four offices carry a NULL function code. The fixtures supply their own data, so the engine
> is proven; against the live database those rules pass vacuously until the codes are set.

### Objective
Single pure function producing severity-tiered findings, consumed by the pre-audit workflow.

### Tasks
- Implement `validate(payrollPeriod) → Finding[]` as one pure function, no side effects
- Severity tiers: BLOCKER (print disabled, no override), WARNING (allowed with logged justification), INFO (advisory)
- Implement rule set (minimum set, expand as needed):
  - **Attachment/document integrity:** manual DTR entry lacks covering approved Bio Exemption; partial Bio Exemption coverage; Travel Order dates outside period; document not in Approved status but cited; missing/unreadable attachment; duplicate attachment hash; duplicate/out-of-sequence control number
  - **Conflict detection:** Travel Order + Leave/Absence same date; Bio Exemption manual entry + actual biometric log same date; employee on two payroll batches overlapping dates (via redacted cross-scope check per Phase 2); OT without covering Memorandum; OT exceeds Memorandum-authorized hours
  - **Shift/computation:** DTR computed against shift version not effective on that date; night differential claimed without ND window in shift; work on rest day/holiday without Memorandum; contract expired before period end; daily rate ≠ contract rate; net pay ≤ 0 or deductions > gross
  - **Form completeness:** empty signatory block; page totals ≠ grand total; row count splits employee across page break
  - **Calendar/holiday (Phase 4 dependent):** work on holiday/suspension without Memorandum; holiday premium applied to JO/COS without contractual basis; period contains TENTATIVE calendar entry; biometric log during full-day suspension; calendar entry lacks legal basis attachment
  - **Scope integrity:** payroll contains lines outside preparer's scope
- Build fixture payrolls: known-good (zero findings expected) and known-bad (specific findings expected per rule)

### Deliverable
`RULES.md` (rule catalog with IDs, severity, description) + rule engine module + fixture test suite

### Exit Gate
Fixture payrolls produce exactly the expected findings, verified by automated test — not manual read-through.

### Token-saving notes
Everything downstream depends on this being correct. Bugs here surface as wrong payroll amounts reaching print — the most expensive class of bug in this domain. Front-load the test suite; don't treat tests as an afterthought for this phase specifically.

---

## Phase 7 — Workflow & Roles

**Status:** DONE — 2026-08-01
**Depends on:** Phase 6

> Migration `0021` renames every status — Draft/Pending/Approved/Released/Cancelled become
> DRAFT/FOR_PRE_AUDIT/PRE_AUDIT_APPROVED/SUBMITTED/CANCELLED, the same way `0016` remapped role
> names rather than leaving old values for the app to translate forever — and adds
> `Suspensions`. The graph, the approval guard and the batch split are pure
> (`Digos\Domain\Workflow\PayrollWorkflow`, 28 fixture tests); `app/Payroll.php` is the shell
> that loads what they need and persists what they decide.
>
> **FOR_PRINTING and PRINTED are placeholders on purpose.** They exist in the graph as plain
> status flags with no certification behind them yet — Phase 8 attaches payload hashes and
> print serials to the PRINTED transition. Building that early would have meant guessing at a
> phase not yet designed.
>
> **A refused approval is not an error, it is the workflow doing its job.** `guardApproval()`
> turns every BLOCKER into a Notice of Suspension automatically — no override, per Phase 6's
> promise. **Employee-scoped by default:** when a BLOCKER names one employee and others on the
> batch are clean, the clean lines proceed to `PRE_AUDIT_APPROVED` under the *original* payroll
> number, and the named employee is split onto a freshly numbered supplemental payroll that
> holds at `SUSPENDED` — traced back via `SupplementsPayrollNo`. A batch-wide finding, or one
> that names every employee on the batch, suspends the whole thing instead — there is no clean
> subset to split off.
>
> **Re-authentication gates only the path that would actually approve something.** Segregation
> of duties is checked first and refuses a preparer approving their own payroll before a
> password is ever asked for — re-auth is not a hoop the refusal has to jump through first.
>
> **Exit gate verified with two real accounts and a real BLOCKER**, not just the pure fixture
> suite: a Payroll In-Charge account does not hold `payroll.approve` at all — refused at
> `requirePermission()`, the same gate every route passes through, not a hidden button. A
> Pre-Auditor distinct from the preparer approves a clean payroll and suspends another. A
> genuine rate-mismatch BLOCKER on one of two employees splits the batch exactly as designed.
>
> New permission: `payroll.suspend`, held by Pre-Auditor, covering suspend/settle/return —
> grouped under one verb because the plan describes them as the same reviewer judgment call.
> Approving keeps its own permission, being the one act with no real undo.
>
> Deliberately deferred from the plan's task list: the attachment viewer embedded in the
> worklist links out to the existing attachment stream rather than duplicating it, and SLA
> colour thresholds are simple client-side bands (24h / 72h) rather than a configurable
> setting — revisit if the real pre-audit cadence needs something finer.

### Objective
Wire the rule engine into a state machine with enforced role transitions.

### Tasks
- Implement state machine:
  ```
  DRAFT → FOR_PRE_AUDIT → PRE_AUDIT_APPROVED → FOR_PRINTING → PRINTED → SUBMITTED
                  ├── SUSPENDED → (settle) → FOR_PRE_AUDIT
                  └── RETURNED_TO_PREPARER
  ```
- Suspension record: `ns_no, payroll_id, employee_id (nullable), ground_code, rule_id, particulars, required_action, deadline, status, raised_by/at, settled_by/at, settlement_ref`
- Auto-generate suspensions from BLOCKER findings; allow manual suspensions for judgment calls
- **Employee-scoped suspensions by default** — one employee's issue doesn't hold the whole batch; clean employees proceed to `FOR_PRINTING`, suspended ones held for Supplemental Payroll
- Enforce permission matrix from Phase 2: pre-auditor can verify/reject/suspend/approve but not create/edit; payroll in-charge can print but not approve
- Pre-auditor worklist screen: queue sorted by aging with SLA color, findings panel grouped by severity, coverage matrix embedded, attachment viewer, Approve/Suspend actions, re-authentication required on Approve

### Deliverable
Working workflow engine + pre-auditor worklist UI

### Exit Gate
Test with two real accounts: pre-auditor can suspend/approve; preparer account cannot approve its own submitted payroll (blocked at the permission layer, not just hidden in UI).

### Token-saving notes
Well-scoped, moderate complexity. Good candidate for a single focused session rather than spreading across several.

---

## Phase 8 — Print Gating & Certification

**Status:** DONE (2026-08-01)
**Depends on:** Phase 6, Phase 7

### Objective
Make the print action provably tied to what was audited.

### Tasks
- Compute `payload_hash = sha256(canonical_json(employees, rates, days, deductions, net, attachment_ids, shift_versions, resolved_calendar_days))` at `PRE_AUDIT_APPROVED`
- Recompute and compare hash at print time; mismatch reverts to `FOR_PRE_AUDIT` and logs a tamper event
- Two print modes: Draft (watermarked, non-official) and Official (only reachable post-approval)
- Mandatory PDF preview before Official print — no direct browser print
- Print serial + mandatory reprint reason, stamped in footer
- Amendment flow: post-lock corrections generate a Supplemental/Amended Payroll + variance sheet instead of a full reprint
- Generate Pre-Audit Certification cover sheet: rules run, findings, overrides + justifications, pre-auditor identity/timestamp, payload hash
- Generate Notice of Suspension slip (per suspension) and Settlement report
- Footer/watermark: office code, function code, user, timestamp on every printed page

> **One piece of this phase already exists, deliberately narrow.** Reviewing the four forms
> from the payroll workflow (2026-07-30) would otherwise have produced Draft printouts
> indistinguishable from approved ones, so `payrollPrintIsOfficial()` and a hardcoded
> `NOT OFFICIAL` marking landed in [app/PrintDoc.php](../app/PrintDoc.php), and `print.php`
> now logs unapproved output as `PREVIEW` rather than `PRINT`. **That is all of it** — no
> print serial, no reprint reason, no mandatory PDF preview, no Official mode gated on
> approval, and no payload hash. Phase 8 still owns every one of those, and should treat the
> existing marking as a starting point to absorb rather than a thing already done.
>
> **Name collision — read this before grepping for "watermark".** In this phase the word
> means the *DRAFT / non-official* overlay on a printed page, and the per-page footer stamp
> above. It is unrelated to the `WatermarkUrl` / `WatermarkOpacity` settings and the
> `#watermark` CSS layer added out of sequence on 2026-07-29, which are decorative office
> branding on the **dashboard and sign-in screens only** and never reach print output.
> Phase 8 must not reuse those settings for the draft overlay: a decorative seal an
> administrator can blank out is the wrong mechanism for a control asserting that a page is
> not official.

### Deliverable
Print gating logic + three print artifacts (Certification sheet, NS slip, Settlement report)

### Exit Gate
Editing a locked payroll and attempting print is refused and logged. Hash mismatch is reproducible on demand in a test.

### Token-saving notes
Low logic complexity, mostly template/formatting work. Cheap phase — should not require significant rework if Phases 6–7 are solid.

### What actually landed

`Payroll.PayloadHash` (migration `0024`) is computed at every `PRE_AUDIT_APPROVED` transition —
both the plain approval in `payrollTransition()` and the clean half of a suspension split in
`raiseSuspensionsAndSplit()` — over exactly what an Official print renders: the PayrollDetails
lines, the attachment coverage justifying them, and the holidays declared over the period
(`Digos\Domain\Print\PayloadHash`, pure, canonical-JSON + sha256). `apiGetPrintHtml`'s new
`official: true` path (`guardOfficialPrint()`) recomputes and compares; a mismatch reverts the
payroll to `FOR_PRE_AUDIT`, logs a `PRINT_HASH_MISMATCH` audit entry explicitly (a thrown refusal
never reaches `api.php`'s automatic post-success log, the same reason `Auth.php`'s
`LOGIN_FAILED` writes its own), and refuses the print. Exit gate: `tests/Integration/
PrintGatingTest.php`, 12 tests, edit-then-print proven with a real fixture and a real negative
proof.

**Narrower than the plan's own hash spec, on purpose:** `shift_versions` is not hashed. There is
no per-employee shift assignment anywhere in the data model to derive it from without a caller
supplying one — `preAuditContext()` already treats `ShiftCode` as optional caller input, not
something derivable from the payroll alone. Recorded in `PayloadHash`'s own docblock and
migration `0024`'s header rather than silently dropped.

`PrintLog` (also `0024`) is the print-serial + reprint-reason record: one row per Official print
attempt, serials drawn from `Counters` with `Series = 'PRINT'` (the same pattern Phase 7 gave
suspensions). A second Official print of a given payroll+form is refused without a reprint
reason; the first needs none. Three new print artifacts in `PrintDoc.php` — Certification cover
sheet (re-runs the rule engine live, since the payload hash is exactly what makes that safe;
lists findings by severity, suspensions, approver identity/timestamp, the hash itself), Notice of
Suspension slip (per `NsNo`), Settlement report (settled/waived suspensions) — all reachable
through the same `apiGetPrintHtml` dispatch as the four Phase 2 forms, so they inherit scope
checking and the Official gate for free.

`public/print.php` — the standalone bookmarkable `?no=...` endpoint — was quietly the largest
risk found mid-phase: it called `buildFormHtml()` directly, a second unguarded path to every
printed form that never assigned a serial, never checked the hash, and never enforced a reprint
reason regardless of a `?official=` query string. Rewired to delegate to `apiGetPrintHtml()`
itself, so both entry points share one gate. Fixing that path also revealed it had its own manual
`PREVIEW`/`PRINT` audit logging that a naive delegation would have silently dropped (calling the
handler in-process bypasses `api.php`'s automatic per-route log) — restored explicitly rather
than lost.

"Mandatory PDF preview before Official print — no direct browser print" is implemented without a
new PDF library (the `EXECUTION_BUDGET.md` note that this needed "a PDF library decision" is
resolved as: no new dependency). The SPA's new "Print Official" action shows the Draft rendering
in an iframe inside a confirmation modal first; only the explicit "Confirm & Print Official"
click calls the gated endpoint, and `window.print()` is never reachable before that round trip
completes. This keeps the no-framework/no-build-step platform decision intact.

**Cut, per `EXECUTION_BUDGET.md`'s own guidance** ("keep hash verification and print gating,
which are the integrity-critical parts"): the amendment flow (post-lock corrections generating a
Supplemental/Amended Payroll + variance sheet). Logged in Backlog below rather than built on a
guess at requirements no accounting stakeholder has yet reviewed.

**A pre-existing issue found while live-probing this phase, not caused by it:**
`apiApprovePayroll`'s audit log entry stores the caller's password in cleartext in
`Logs.Details` — `auditSummary()` redacts inline `data:` URLs but not a `Password` field, and
`APPROVE_PAYROLL`'s logged action is the raw payload. Pre-dates Phase 8; flagged here because
this is where it surfaced. See Backlog.

---

## Phase 9 — Filters, Search & Dashboards

**Status:** NOT STARTED
**Depends on:** Phases 1–8 stable

### Objective
Make records findable without breaking scope isolation.

### Tasks
- Global control-number search (single box, jumps to record)
- Faceted filters — Who (employee/office/employment type/status), What (doc type/control no./subject/day type/ground code), When (period/issued/effectivity/received/deadline), State (workflow state/finding severity/suspension status/print status/aging), Who acted (preparer/pre-auditor/in-charge/approver)
- Filter state encoded in URL for shareable links
- Saved default views per role (Pre-Auditor → `FOR_PRE_AUDIT` sorted by aging; In-Charge → `FOR_PRINTING`)
- Server-side scope enforcement on every query — filter UI never offers out-of-scope options
- Export filtered results to CSV/XLSX with active filters printed in header
- Standing watchlist queries: bio exemptions expiring ≤15 days, contracts expiring before period end, open-ended memos untouched 6+ months, suspensions past deadline
- **Citywide aggregate dashboards behind separate explicit permission** (`VIEW_CITYWIDE_AGGREGATE`) — office-scoped users never see cross-office totals, including in exports

### Deliverable
Search/filter UI + role-default dashboards + citywide aggregate views (permission-gated)

### Exit Gate
Office-scoped user never sees another office's totals in any view or export. Watchlist queries return correct results against fixture data with known expiring records.

### Token-saving notes
Build last — dashboards query everything else, so building them before earlier phases stabilize means rebuilding them repeatedly.

---

## Phase 10 — UAT & Cutover

**Status:** NOT STARTED
**Depends on:** All prior phases

### Objective
Prove the system on one real payroll period before full reliance.

### Tasks
- Run one live payroll period in parallel with the existing process
- Data migration script, if any office is still keeping payroll outside this system
- Collect reprint-rate, suspension-ground, and settlement-turnaround metrics from the live run (baseline for future monitoring)
- Sign-off checklist with Accounting/Audit stakeholders

### Deliverable
One fully processed real payroll period through the new system, zero manual overrides required, plus migration script if applicable

### Exit Gate
One live payroll period processed end-to-end with zero manual override needed.

---

## Post-Launch Metrics to Track

- Reprint rate per payroll period
- Top 5 suspension grounds (a stable top ground for 3+ months signals a process/form fix, not a software fix)
- Average settlement turnaround (suspension raised → settled)
- Pages printed per period (the resource-waste metric this whole project targets)

---

## Backlog (Ideas Not Yet Scheduled)

*Log new ideas here during active phase work instead of injecting them mid-phase. Triage into a phase at the next planning checkpoint.*

- **Assign every office a real Function/PPA code.** *(Phase 1 sign-off, 2026-07-29: owner is entering these; open until confirmed.)* All four offices, five payrolls and five payroll lines carry `FunctionCode = NULL` after the `0004`/`0006`/`0014` backfills, because three offices store `9999` and one `8721` — none of them a code or a name in `Functions`. This is data entry, not code, and it is a **prerequisite for Phase 6's CAFOA rules to mean anything**: a rule that checks the charged appropriation with nothing to check does not fail, it passes. Dropdown at Departments & Offices → edit an office → "Function / PPA charged". See [SCHEMA.md § Known-unresolved data](SCHEMA.md#known-unresolved-data-surfaced-by-the-backfills).
- **Three of five existing payrolls were self-approved** — `PR-2026-000001`, `PR-2026-000003`, `DIG-2026-000004` (`PreparedByUser == ApprovedByUser`, visible for the first time since `0007`). **Settled at Phase 1 sign-off, 2026-07-29: grandfathered as development records**, not disbursement history. Phase 2 prohibits self-approval from that point forward. **Phase 7 must not assume every historical approval passes the new rule** — the workflow it builds runs over these three rows.
- **MariaDB 10.4 → 10.11 LTS upgrade.** `GAP_MAP` finding 6: 10.4 has been end of life since June 2024, CI already proves both versions, and the gap map argued it was cheapest *before* Phase 1 froze the schema. That window has now closed — re-cost it rather than assume it is still cheap.
- **Branding is unenforced by any guard.** `IMAGE_SETTINGS` in `app/Settings.php` is an allowlist of settings that accept an upload; nothing fails if a future setting is added to the form but not to that list. Consider an architecture test if the list grows past the current two.
- **`Users.OfficeCode` is empty on every user, and is not a foreign key.** Both accounts store `''`, which is not an office; `Users` is the one table `0009` left unreferenced in that direction. Phase 2's scope grants key off user → office, so the column has to mean something before the gateway can read it — and `''` will not fail a foreign key that does not exist. Decide in Phase 2 whether the column stays or is superseded outright by `scope_grant`, which carries `office_id` itself.
- **Two offices are duplicates.** `OCEEM` and `OCM` are both named "OCEEM public market", and payroll history now hangs off both (`DIG-2026-000004` charges `OCM`). Merging them after Phase 2 means rewriting scope grants as well as payroll rows; it is cheapest now and it is data entry, not code. Related to the Function/PPA item above — the same four offices need attention either way.
- **Deleting an employee still cascades their contract and DTR history away.** `apiDeleteEmployee` refuses when the employee appears on a payroll line, but `Contracts` and `DtrDays` are `ON DELETE CASCADE`, so an employee never yet paid loses their rate history and timekeeping silently. Harmless while `DtrDays` is empty; revisit when Phase 3B fills it.
- **Undo is still a single global `Settings` row.** `GAP_MAP` already flags this (`_PayrollUndo`, two users' undos collide, no audit narrative). One outright bug was fixed in place — undoing a status change on a deleted payroll updated zero rows and reported success — but the redesign belongs to whichever phase revisits audit integrity, not to Phase 1.
- **Phase 8's amendment flow was cut**, per `EXECUTION_BUDGET.md`'s own guidance to keep hash
  verification and print gating over this piece specifically. Post-lock corrections currently
  have no path at all other than a fresh suspension cycle back through `FOR_PRE_AUDIT` — there is
  no Supplemental/Amended Payroll + variance sheet for a correction discovered after `SUBMITTED`.
  Needs an accounting/audit stakeholder's sign-off on what a variance sheet should actually show
  before it is worth building against a guess.
- **`apiApprovePayroll`'s audit log stores the caller's password in cleartext**, found live-probing
  Phase 8, not caused by it. `auditSummary()` (`app/Helpers.php`) redacts inline `data:` URLs from
  a logged payload but nothing else, and `APPROVE_PAYROLL`'s `ROUTES` entry logs the raw payload -
  which includes `Password`, sent for `reauthenticate()`. Every approval leaves that pre-auditor's
  password sitting in `Logs.Details` in plain text. **Should be treated as urgent** given it is a
  live credential exposure in a table several roles can read (`log.view`), not deferred like the
  rest of this list - fix is narrow (redact `Password`/similar keys in `auditSummary()`, same shape
  as the existing `data:` redaction) but touches the audit trail every role trusts, so it wants its
  own reviewed change rather than a drive-by edit inside an unrelated phase.

---

## Change Log

| Date | Change |
|---|---|
| 2026-07-27 | Initial phase plan created |
| 2026-07-27 | Moved into the repository at `docs/PHASE_PLAN.md`; character encoding repaired (the original was double-encoded UTF-8) |
| 2026-07-27 | Phase 0 completed. Platform decision recorded: **MariaDB 10.4.32, not Postgres** — measured on the deployment server, not assumed; XAMPP ships MariaDB. Scope enforcement will be an application gateway backed by an architecture test, since MariaDB has no row-level security |
| 2026-07-28 | `Employees.CashCard` added out of sequence (migration `0002`) on request — master data only, not on the printed form. **Phase 1 must fold it into the Tier 1 / Tier 2 split**: it is payee payment data and belongs in the restricted tier alongside TIN/GSIS/PhilHealth/Pag-IBIG |
| 2026-07-27 | Phase 0 audit raised three revisions to this plan. **All three settled 2026-07-28** — see the entry below |
| 2026-07-28 | **The three Phase 0 revisions are settled, all as proposed.** (1) *Day-level DTR* — accepted: Phase 1 adds a per-employee-per-date `DtrDays` table, and a new **Phase 3B** builds capture on top of it before Phase 4. Phases 4, 5 and 6 all compute per date and have no input without it. (2) *`PreparedBy`* — accepted: Phase 1 adds `Payroll.PreparedByUser` as a real foreign key to `Users`, keeping the existing display-name string for the printed form, which must show the name as rendered at the time. Phase 2's segregation-of-duties check reads the key, never the string. (3) *Postgres RLS* — accepted: removed from Phase 2. MariaDB has no row-level security, so the application-gateway path is the only path, and it is now stated as such rather than as one of two options |
| 2026-07-28 | Removed two stale assumptions that contradicted the repository: Phase 2's Postgres/Apps Script enforcement fork, and Phase 10's "moving off Sheets". This system is PHP + MariaDB and always has been in this repo; the references predate it and would have sent a session designing against the wrong platform |
| 2026-07-28 | **Phase 1 deliverable landed** (commit `75ae428`): `SCHEMA.md` and migrations `0003`–`0009`. Recorded here on 2026-07-29 — the commit updated neither this plan nor `GAP_MAP.md`, so for a day the plan showed a phase in progress whose deliverable was already complete. **Phase 1 is still open:** the exit gate's second half is your sign-off, and the Tier 1 / Tier 2 split was deliberately deferred to Phase 2's first migration rather than built here |
| 2026-07-29 | **Branding images added out of sequence** (migration `0010`, settings `WatermarkUrl` / `WatermarkOpacity`, endpoint `apiUploadImageSetting`): an uploadable office logo for the sidebar, sign-in page and printed seal, plus a decorative watermark behind the dashboard and sign-in screens. Requested directly, built during Phase 1, and belonging to no phase — the same shape as `Employees.CashCard` at `0002`. It touches no payroll data, no schema beyond two `Settings` rows, and no phase's exit gate. **Phase 8 owns a different, unrelated "watermark"** — the disambiguation note now sits in that phase |
| 2026-07-29 | **Phase 1 verified against the live database, and a defect found and fixed: the migrations froze the model but nothing in `app/` ever wrote the new columns.** Every employee and payroll created through the UI after `0003`/`0007` arrived with `EmploymentTypeCode`, `PreparedByUser`, `ApprovedByUser` and the line-charging columns empty — one employee and three payrolls by the time it was measured. Phase 2's segregation-of-duties check would have read NULL on any payroll made through the UI. Write paths corrected in `Master.php` and `Payroll.php`; migration `0013` backfills the rows written while the gap was open; per-line charging now defaults from the payroll header. `Contracts` deferred to its own module by decision, not oversight |
| 2026-07-29 | Plan revalidated against the repository. Backlog populated for the first time (it read "none logged yet" while four items were outstanding, three of them surfaced by Phase 1's own backfills). `GAP_MAP.md` findings 1 and 2 are now resolved by migrations `0008` and `0007` but still read as open in that file — **not corrected here**, since amending the Phase 0 audit record is a separate decision |
| 2026-07-29 | **Grant administration pulled forward from session B, because without it the system could not be run.** `0016` seeds grants for administrators only, so every other account signed in and saw nothing with no in-app remedy — the control was correct and the product was unusable. `app/Access.php` (no `DB::`; it goes through the repository, so the legacy allowlist did not grow), permission `scope.manage`, and a Scope Grants panel on the Users page. Two refusals worth naming: revoking your own last read grant is blocked, because it locks you out of the screen that would undo it; and a blank form means *wildcard*, so the summary spells out "EVERYTHING - all offices, funds and years" rather than showing a row of blank cells that reads as an unfinished record. `ScopeGrants.GrantedBy` is now written and left the deferred list. Verified over HTTP end to end: create user → grant one office → sees only that office → revoke → access gone, with the change in the audit log |
| 2026-07-29 | **Phase 2 session A: the scope model and the gate.** Migrations `0015` (EmployeeSensitive, create + backfill), `0016` (`ScopeGrants`, the six-to-seven role remap, a seeded wildcard grant per administrator) and `0017` (drop the Tier 2 columns — destructive, applied only after every reader was moved). The decision itself is `Digos\Domain\Access\ScopePredicate`, pure and fixture-tested: **no applicable grant denies everything**, and a grant narrowing a dimension an entity does not carry denies rather than widens. Enforcement is `app/Repo/ScopeGateway`, reached only through repositories. **`GAP_MAP` finding 4 is closed** — the restricted tier is a separate table and an Encoder never receives its columns. Suite is 92 tests; each of the seven enforcement tests was verified to fail with the enforcement removed. Three guards caught real defects during the work: backups omitted both new tables (restore would have destroyed them), two new columns were never written, and `DatabaseAccessTest` matched `DB::` inside prose — that last one was the guard's own defect and is fixed by tokenising comments out |
| 2026-07-30 | **The four printouts are reviewable from the payroll workflow.** Payroll, HDMF/Pag-IBIG, Summary (GF 30-A) and CAFOA now open from the read-only viewer on the Payroll Transactions screen, so a payroll is checked on the form it will be printed on before it is submitted and again before it is approved — previously that meant leaving for the Print screen and finding the payroll again by number. Two consequences handled rather than deferred: **an unapproved payroll printed identically to an approved one**, so a hardcoded `NOT OFFICIAL` marking now comes from the stored status (never a URL parameter) and survives `@media print` — the narrowest possible piece of Phase 8, recorded in that phase so it is absorbed rather than assumed done; and **every preview logged as `PRINT`**, which would have corrupted *pages printed per period*, one of the post-launch metrics — unapproved output logs as `PREVIEW`. Pag-IBIG stays hidden from roles without `employee.sensitive` rather than offered and refused. Verified at Draft, Pending, Cancelled and Approved against the live database. **A test wrote itself wrong again**: the print-survival assertion matched one literal spelling and passed while the marking was hidden through a grouped selector — found by sabotaging the CSS, and now parses the rules instead |
| 2026-07-30 | **The four printouts are reviewable from the payroll transaction itself.** Viewing any payroll now offers Payroll, HDMF/Pag-IBIG, Summary and CAFOA, so a preparer can check the actual forms before submitting and a pre-auditor before approving — reviewing a grid that resembles the payroll is not the same as reviewing what gets signed. The Pag-IBIG button is hidden, not offered-then-refused, for roles without `employee.sensitive`. **Every form of a payroll that is not Approved or Released now prints marked `NOT OFFICIAL`**, naming the status, with `CANCELLED PAYROLL - NOT OFFICIAL, NOT FOR PAYMENT` for cancelled ones. This is the narrowest slice of Phase 8, brought forward only because previewing a Draft would otherwise produce paper indistinguishable from an approved payroll; it is deliberately **not** built from the `WatermarkUrl`/`WatermarkOpacity` settings, per the warning in Phase 8 — a marking an administrator can blank is not a control. Phase 8 still owns print serials, reprint reasons, mandatory PDF preview and the Official mode. **The decision function was written, unit-tested and called from nowhere**: `payrollPrintIsOfficial()` was green while the forms rendered unmarked and the Payroll screen promised the reviewer otherwise. Wiring it in and asserting on the *rendered* output is what closed it, and the assertion counts the marking rather than merely finding it — a first pass emitted it twice on every form and a contains-assertion was happy either way |
| 2026-07-30 | **Preview submitted payrolls — and the print scope leak that had to be closed first.** The ask was to let a submitted payroll be previewed so corrections can be re-scanned and re-verified; the Print screen already listed every status and only *defaulted* to Approved & Released, so the feature was one filter default. What the investigation found is the real entry: **`printBundle()` took no user and queried `Payroll` directly**, so the print path bypassed the scope layer entirely — `apiGetPayroll` refused another office's payroll while `apiGetPrintHtml` rendered the same number in full, names and all, for any of the five roles holding `print.run`. Reproduced live before and after. The print path now reads through `PayrollRepo`, `EmployeeRepo` and a new `ReferenceRepo`; **`PrintDoc.php` came off the `DB::` allowlist** (seven queries, six files left). The Pag-IBIG list — the only form needing the restricted tier — is refused outright without `employee.sensitive` rather than printed with holes in it, and a payroll whose lines are all charged out of scope is refused rather than printed as an empty form. `PrintScopeTest` covers all of it, and **caught itself passing for the wrong reason**: it went green in a full run only because a unit test happens to require `PrintDoc.php` first, and failed the moment it was run alone |
| 2026-07-29 | **New guard: every route permission must be one some role can hold.** Nothing checked that a `ROUTES` permission string appears in any role's `PERMISSIONS`, so a typo — `scope.manag` — leaves the route reachable by administrators alone through `'*'`: it fails closed, but silently, and works for whoever built it while being invisibly broken for every other role. Verified by introducing that exact typo. Its first real run found `employee.delete`, which **no role has ever held on either side of the role remap** — employee deletion has been administrator-only since Phase 0. Recorded in `ADMIN_ONLY_PERMISSIONS` rather than granted to HRMO, because handing a role a destructive power it never had is a policy decision, not a side effect of adding a test. **Worth a deliberate answer before Phase 7** builds its permission matrix on these roles |
| 2026-07-29 | **Phase 1 signed off; status `DONE`; Phase 2 unblocked.** Decision 1 (Tier 1 / Tier 2 split) **accepted** — lands as Phase 2's first migration, atomically with the gateway; until then every role holding `employee.view` still reads Tier 2, which is `GAP_MAP` finding 4 unchanged. Decision 2 (three self-approved payrolls) **grandfathered** as development records, with the three payroll numbers named in the Backlog so Phase 7 does not assume every historical approval passes the new rule. Decision 3 (`Contracts`) **accepted** — triaged out of the Backlog into **Phase 3**'s task list. Decision 4 (Function/PPA per office) **outstanding, owner entering** — it does not gate Phase 2, it gates **Phase 6**, where the CAFOA rules would pass vacuously rather than fail against four NULL functions |
| 2026-07-29 | **Phase 1 re-verified against the live database before sign-off; three defects found and fixed, none of which change the four open decisions.** (1) `0013` never backfilled `PayrollDetails.ChargedOfficeCode` — two lines still NULL, closed by `0014`; `SCHEMA.md`'s rule that a NULL there means the write path regressed would have misdiagnosed it, and now says so. (2) **The delete guards never caught up with `0009`'s foreign keys**: deleting an office with payroll history returned `SQLSTATE ... 1451 a foreign key constraint fails` to the user, and deleting a Function/PPA — unguarded, `ON DELETE SET NULL` — silently blanked `FunctionCode` on *approved* payrolls, erasing which appropriation paid them. Both refuse in plain words now, asserted by `tests/Integration/DeleteGuardTest.php`. (3) **The test harness could have written to the live database** — `TestDatabase` guards its own connection, but `DB::` reads the `DB_NAME` constant, which `app/config.php` defaults to the working database; `tests/bootstrap.php` now pins it first. Phase 2's gateway tests would have been the first to hit that. Suite is 55 tests. Backlog gained five items, all parked rather than chased |
| 2026-08-01 | **Phase 8 closed.** Payload hash (migration `0024`) computed at `PRE_AUDIT_APPROVED` over PayrollDetails + attachment coverage + holidays, recomputed and compared at Official print time; a mismatch reverts to `FOR_PRE_AUDIT` and logs `PRINT_HASH_MISMATCH` explicitly, since a thrown refusal never reaches `api.php`'s automatic post-success log. Print serials and reprint reasons (`PrintLog`), three new print artifacts (Certification, Notice of Suspension, Settlement report), and a mandatory-preview-then-confirm SPA flow for Official print — no new PDF library, resolving the `EXECUTION_BUDGET.md` open question. **`public/print.php` turned out to be a second, unguarded path to every printed form** — it called `buildFormHtml()` directly and never went through the new gate regardless of a `?official=` query string; rewired to delegate through `apiGetPrintHtml()`, which also meant restoring its own `PREVIEW`/`PRINT` audit logging that a naive delegation would have silently dropped (an in-process call bypasses `api.php`'s automatic per-route log). Amendment flow cut per `EXECUTION_BUDGET.md`'s own guidance; logged to Backlog. **Live-probing the exit gate over real HTTP found migration `0024` had only been applied to the test database, not the working one** — phpunit alone could not have caught that, since it always runs against the test database; fixed before sign-off. That same probe surfaced an unrelated, pre-existing issue — `apiApprovePayroll` logs the caller's password in cleartext — flagged as urgent in Backlog rather than fixed inline, since it touches the audit trail every role trusts. Suite is 366 tests |
