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

**Status:** NOT STARTED
**Depends on:** Phase 0

### Objective
Freeze the schema that every other phase builds on.

### Tasks
- **Employee** — split into Tier 1 (Directory: name, ID, home office, position, employment type, contract validity flag, biometric no., photo) and Tier 2 (Restricted: rate, contract amount, personal data, TIN/PhilHealth/Pag-IBIG/GSIS numbers)
- **Office/Department** — code, name, parent hierarchy if applicable
- **Function/PPA code** — code, description, owning office
- **Employment type** — JO, COS, Plantilla, with distinct computation implications flagged
- **Contract** — employee, type, rate, effectivity start/end, status
- Define `charged_office_id` and `function_code` as first-class fields on payroll lines (not derived from employee's home office — see Phase 2 rationale)
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
- Implement enforcement:
  - Postgres path: Row-Level Security policies per restricted table
  - Sheets/Apps Script path: single `queryScoped(user, entity, filters)` gateway; direct range access to transactional sheets disallowed
- Implement segregation-of-duties check: `payroll.prepared_by != current_user AND current_user NOT IN payroll.editors[]` gating entry to pre-audit actions
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
- Basic list/detail/create screens per document type, scoped per Phase 2

### Deliverable
Four working document modules, CRUD only

### Exit Gate
Each document type stores and lists correctly under the correct scope. No resolvers, no rule engine yet — deliberately.

### Token-saving notes
This is repetitive boilerplate — a good phase to move fast on. Save careful/expensive reasoning for Phase 4 and 6.

---

## Phase 4 — Resolvers

**Status:** NOT STARTED
**Depends on:** Phase 3

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
- Migration script if moving off Sheets/current storage
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

- (none logged yet)

---

## Change Log

| Date | Change |
|---|---|
| 2026-07-27 | Initial phase plan created |
| 2026-07-27 | Moved into the repository at `docs/PHASE_PLAN.md`; character encoding repaired (the original was double-encoded UTF-8) |
| 2026-07-27 | Phase 0 completed. Platform decision recorded: **MySQL 8, not Postgres** — scope enforcement will be an application gateway backed by an architecture test, since MySQL has no row-level security |
| 2026-07-27 | Phase 0 audit raised three revisions to this plan that are **still awaiting approval**: (1) day-level DTR does not exist and blocks Phases 4–6, needing a Phase 1 schema addition plus a new Phase 3B; (2) `PreparedBy` is a display-name string, so Phase 2's segregation-of-duties check needs a Phase 1 foreign key first; (3) Phase 2's Postgres RLS option does not apply. See [GAP_MAP.md](GAP_MAP.md) |
