# Execution Budget — Running the Phase Plan Without Running Out

**Purpose:** keep the ten-phase plan inside a finite token/credit budget, and make the
spend predictable enough to plan around rather than discover.

**Anchor:** Phase 0 is complete and measured. Everything below is scaled from it rather
than guessed.

---

## What Phase 0 actually cost

| | |
|---|---|
| Existing code read to establish ground truth | ~5,000 lines |
| New code, tests, migrations and docs produced | ~2,130 lines |
| Files created | 16 |
| Files modified | 5 |
| Verification cycles run against a live database | ~12 |

Phase 0 is the **cheapest phase per unit of downstream saving** and one of the smaller
ones in absolute terms. Treat it as **1 phase-unit** and read the table below in those
units.

The single largest cost was not writing code — it was *reading* 5,000 lines to establish
what existed. That cost is now paid once and captured in `GAP_MAP.md`. Every later phase
should read that file instead of re-reading the repository.

---

## What Phase 1 actually cost

| | |
|---|---|
| Migrations written (`0003`–`0009`, `0013`) | 481 lines |
| `SCHEMA.md` | 230 lines |
| Application code changed to write the new model | ~40 lines across 2 files |
| Commits | 2 (`75ae428` schema, `60c2e65` write paths) |
| Verification cycles against a live database | ~10 |

**Roughly 2 units — inside the 2–3 estimate**, and the checkpoint below is therefore not
triggered. The estimate's shape was right too: low code volume, high review density.

Two things cost more than the estimate anticipated, and both are worth carrying forward:

1. **The collation trap.** Three databases had to be migrated to find that hardcoding
   `utf8mb4_unicode_ci` works on a copy of live data and fails on a fresh install. That was
   not schema design — it was environment variance, and no amount of care at the design
   stage would have surfaced it. Budget verification cycles against *more than one*
   database for any phase that writes DDL.
2. **The write paths were forgotten entirely.** The migrations froze the model and nothing
   wrote to it; it took a separate measuring pass a day later to notice. This was free to
   fix at ~40 lines and would have been expensive to discover in Phase 2, where the
   segregation-of-duties check reads a column that would have been NULL. **A migration is
   not finished when it applies cleanly — it is finished when something writes the column.**

---

## Relative cost per phase

Sized from the gap map: how much is `build new`, how much existing code must be understood
first, and how much iteration the exit gate demands.

| Phase | Units | Driver |
|---|---|---|
| 0 — Audit & baseline | 1 | **done** |
| 1 — Core data model | 2–3 | **~2 spent, inside estimate.** Schema + migrations + data-migration from the baseline. High review density, low code volume. Sign-off loop is still the open variable. |
| 2 — Auth & scope | 3–4 | Touches every existing module. The seven files on the `DB::` allowlist each need moving behind the repository layer. |
| 3 — Document CRUD | 2–3 | Four near-identical modules. High volume, low difficulty — the cheapest per line in the plan. |
| **3B — DTR & biometric ingest** | 2–3 | **Not in the original plan.** Blocks 4, 5 and 6. |
| 4 — Resolvers | 4–5 | Hardest logic. Pure functions, so iteration is cheap per cycle — but there will be many cycles. |
| 5 — Attachments & matrix | 2 | Logic already done in Phase 4; mostly a grid and file handling. |
| 6 — Rule engine | 5–6 | **The most expensive phase.** ~25 rules × (implementation + fixture + expected findings). |
| 7 — Workflow & roles | 2–3 | Well-scoped state machine plus one substantial screen. |
| 8 — Print gating | 2 | Template and formatting work; low logic density. Needs a PDF library decision. |
| 9 — Filters & dashboards | 2–3 | Wide but shallow. Rewriting the PHP-side `array_filter` list endpoints is the real work. |
| 10 — UAT & cutover | 1–2 | Mostly not a coding phase. Budget for defect turnaround, not construction. |
| **Total** | **28–37** | Phase 0 was 1 unit. |

**Reserve 20% on top.** Phases 1 and 2 have sign-off gates whose outcome is not under
engineering control, and Phase 10 will surface defects in earlier phases.

---

## Where budget actually goes

In this repository, in order of size:

1. **Re-reading code that is already documented.** The most avoidable cost by a wide
   margin. `GAP_MAP.md` and `CLAUDE.md` exist precisely to stop this.
2. **Long sessions carrying dead context.** Cost per turn grows with everything that came
   before it. A session that has already delivered its phase is the most expensive place
   to start the next one.
3. **Iterating on a vague target.** "Make the rule engine correct" costs several times
   "make this failing fixture pass".
4. **Regenerating whole files for small changes.** A 500-line file rewritten to change 10
   lines costs 50× what the change was worth.
5. **Reviewing generated output by reading it.** A test that asserts the same thing costs
   less every time after the first.

---

## Controls

### One phase, one session, one branch

Start a new session at each phase boundary. Do not carry Phase 4's context into Phase 5 —
the resolver internals are irrelevant once `RESOLVERS.md` and the tests exist.

If a phase is large (2, 4, 6), split at a natural seam and start fresh:

- Phase 2 → scope model and grants · then migrating modules behind the gateway
- Phase 4 → `resolveAuthority` · then `resolveHoliday`
- Phase 6 → rule catalogue and fixtures · then rules by family (attachments, conflicts,
  shift, form, calendar, scope)

### Open every session the same way

> Read `docs/PHASE_PLAN.md` § Phase N and `docs/GAP_MAP.md` § <relevant section>.
> Do not read other source files unless a task requires it.
> Task: <the phase's task list>.
> Done when: <the phase's exit gate>, verified by `vendor/bin/phpunit`.

Naming the files to read is what keeps a session from exploring the whole repository.

### Test-first for Phases 4 and 6 specifically

These two phases have exit gates stated as "fixtures produce exactly the expected
findings, verified by automated test". Write the fixture and the expected output **before**
the implementation. A session handed a failing test converges in far fewer cycles than one
handed a description, and the result is verified rather than reviewed.

This is the highest-leverage control in this document. It is also the plan's own operating
principle #3 — it just needs to be applied as a budget measure too.

### Never regenerate a working file

Targeted edits only, once a file exists and works. This is operating principle #4 and it
matters most in `app/PrintDoc.php` (725 lines), `views/payroll.php` (515) and
`views/employees.php` (415) — the three files where a full rewrite is most tempting and
most wasteful.

### Let the guards do the reviewing

The architecture suite already checks, on every run and for free, that database access
stays behind the repository layer and that every endpoint is routed, permissioned and
audited. Do not spend a review pass re-checking what a test asserts.

### Park scope, do not chase it

Anything discovered mid-phase goes to the plan's Backlog section. Chasing it is how a
2-unit phase becomes 5.

---

## Checkpoints

Re-forecast at the end of Phases 1, 4 and 6 — these are the phases whose actual cost most
strongly predicts the rest.

| Signal | Meaning | Response |
|---|---|---|
| Phase 1 over ~3 units | Schema churn; the model is not settled | Stop and settle the model before Phase 2 — the cost of carrying it forward is much higher |
| Phase 4 over ~5 units | Resolver rules under-specified | Get the LGU ordinance/policy source in hand before continuing |
| Phase 6 over ~6 units | Rules being debugged rather than specified | Return to fixtures; a rule without a fixture is not finished |
| Any phase >2× estimate | Scope crept | Check the Backlog discipline |

## If the budget runs short

Cut in this order. The first three cost little; the last two are real losses.

1. **Phase 9** — filters and dashboards. Build the role-default views only; skip faceted
   search, saved views and export. Records stay findable.
2. **Phase 8 amendment flow** — supplemental/variance generation. Keep hash verification
   and print gating, which are the integrity-critical parts.
3. **Phase 5 coverage matrix UI** — keep the attachment binding and hash dedup, defer the
   visual grid. The data is still correct; the gaps are just less obvious.
4. **Phase 6 rule families** — implement attachment integrity, conflict detection and
   scope integrity; defer form completeness. *This weakens the pre-audit guarantee* — say
   so explicitly to Accounting/Audit rather than shipping quietly.
5. **Phase 3B** — do not cut. Phases 4, 5 and 6 have no inputs without it.

**Never cut:** Phase 1 (schema), Phase 2 (scope), or the fixtures in Phases 4 and 6. A
defect in any of those surfaces as a wrong figure on a signed voucher — the failure this
project exists to prevent.
