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

**Status:** NOT STARTED
**Depends on:** Phase 1

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

**Status:** NOT STARTED
**Depends on:** Phase 2

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

**Status:** NOT STARTED
**Depends on:** Phase 3
**Added:** 2026-07-28, settled revision 1 from the Phase 0 audit

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

**Status:** NOT STARTED
**Depends on:** Phase 3B

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

**Status:** NOT STARTED
**Depends on:** Phase 3, Phase 4

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

**Status:** NOT STARTED
**Depends on:** Phase 4, Phase 5

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

**Status:** NOT STARTED
**Depends on:** Phase 6

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

**Status:** NOT STARTED
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
- ~~**`Contracts` needs its own module with supersession semantics.**~~ **Triaged into Phase 3 at Phase 1 sign-off, 2026-07-29** — see that phase's task list. One of three employees has no contract row until it lands.
- **No guard stops a new nullable column from never being written.** The Phase 1 columns sat unwritten by `app/` for a day before anyone measured it (see [SCHEMA.md](SCHEMA.md#the-backfills-were-not-enough--the-write-paths-were-never-wired)). An architecture test asserting that every column a migration adds is referenced somewhere in `app/` would have caught it the same day.
- **Branding is unenforced by any guard.** `IMAGE_SETTINGS` in `app/Settings.php` is an allowlist of settings that accept an upload; nothing fails if a future setting is added to the form but not to that list. Consider an architecture test if the list grows past the current two.
- **`Users.OfficeCode` is empty on every user, and is not a foreign key.** Both accounts store `''`, which is not an office; `Users` is the one table `0009` left unreferenced in that direction. Phase 2's scope grants key off user → office, so the column has to mean something before the gateway can read it — and `''` will not fail a foreign key that does not exist. Decide in Phase 2 whether the column stays or is superseded outright by `scope_grant`, which carries `office_id` itself.
- **Two offices are duplicates.** `OCEEM` and `OCM` are both named "OCEEM public market", and payroll history now hangs off both (`DIG-2026-000004` charges `OCM`). Merging them after Phase 2 means rewriting scope grants as well as payroll rows; it is cheapest now and it is data entry, not code. Related to the Function/PPA item above — the same four offices need attention either way.
- **Deleting an employee still cascades their contract and DTR history away.** `apiDeleteEmployee` refuses when the employee appears on a payroll line, but `Contracts` and `DtrDays` are `ON DELETE CASCADE`, so an employee never yet paid loses their rate history and timekeeping silently. Harmless while `DtrDays` is empty; revisit when Phase 3B fills it.
- **Undo is still a single global `Settings` row.** `GAP_MAP` already flags this (`_PayrollUndo`, two users' undos collide, no audit narrative). One outright bug was fixed in place — undoing a status change on a deleted payroll updated zero rows and reported success — but the redesign belongs to whichever phase revisits audit integrity, not to Phase 1.

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
| 2026-07-29 | **Phase 1 signed off; status `DONE`; Phase 2 unblocked.** Decision 1 (Tier 1 / Tier 2 split) **accepted** — lands as Phase 2's first migration, atomically with the gateway; until then every role holding `employee.view` still reads Tier 2, which is `GAP_MAP` finding 4 unchanged. Decision 2 (three self-approved payrolls) **grandfathered** as development records, with the three payroll numbers named in the Backlog so Phase 7 does not assume every historical approval passes the new rule. Decision 3 (`Contracts`) **accepted** — triaged out of the Backlog into **Phase 3**'s task list. Decision 4 (Function/PPA per office) **outstanding, owner entering** — it does not gate Phase 2, it gates **Phase 6**, where the CAFOA rules would pass vacuously rather than fail against four NULL functions |
| 2026-07-29 | **Phase 1 re-verified against the live database before sign-off; three defects found and fixed, none of which change the four open decisions.** (1) `0013` never backfilled `PayrollDetails.ChargedOfficeCode` — two lines still NULL, closed by `0014`; `SCHEMA.md`'s rule that a NULL there means the write path regressed would have misdiagnosed it, and now says so. (2) **The delete guards never caught up with `0009`'s foreign keys**: deleting an office with payroll history returned `SQLSTATE ... 1451 a foreign key constraint fails` to the user, and deleting a Function/PPA — unguarded, `ON DELETE SET NULL` — silently blanked `FunctionCode` on *approved* payrolls, erasing which appropriation paid them. Both refuse in plain words now, asserted by `tests/Integration/DeleteGuardTest.php`. (3) **The test harness could have written to the live database** — `TestDatabase` guards its own connection, but `DB::` reads the `DB_NAME` constant, which `app/config.php` defaults to the working database; `tests/bootstrap.php` now pins it first. Phase 2's gateway tests would have been the first to hit that. Suite is 55 tests. Backlog gained five items, all parked rather than chased |
