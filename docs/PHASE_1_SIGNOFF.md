# Phase 1 — Sign-Off Packet

**Status: awaiting your decision.** Everything Phase 1 can deliver on its own is delivered
and verified. What remains is the second half of the exit gate, which the plan deliberately
never delegates:

> *Migrations run clean on a copy of production/real data. **Schema reviewed and explicitly
> signed off by you before Phase 2 starts** — this is the highest-leverage review point in
> the project.*

Phase 2 stays shut until the four decisions below are made. Three need a yes/no; one is a
data-entry task only you can do.

---

## What is already proven

Measured against the working database on 2026-07-29, not asserted:

| Check | Result |
|---|---|
| Migrations applied | 13 of 13, 0 pending |
| Tables / foreign keys | 17 / 20 — matches [SCHEMA.md](SCHEMA.md) exactly |
| Table collations | uniform; no foreign key can fail on a collation mismatch |
| `EmploymentTypeCode` unset | 0 employees |
| `PreparedByUser` unset | 0 payrolls |
| Test suite | 46 of 46 pass, including the 9 integration tests |

The write paths that populate the new model are wired and exercised end to end: employee
save writes the type code, payroll save writes the preparer key, approval writes the
approver key, and payroll lines write their charged office.

---

## Decision 1 — The Tier 1 / Tier 2 split was not migrated

**What was asked for:** split `Employees` into a directory tier and a restricted tier
(rates, TIN, GSIS, PhilHealth, Pag-IBIG, CashCard, personal data).

**What was delivered:** the split is *classified* in
[SCHEMA.md § Employee tiers](SCHEMA.md#employee-tiers) but the columns were not moved.

**Why.** Moving them breaks every reader today — `SELECT *` in `app/Master.php` among them —
and buys nothing until the Phase 2 gateway exists to enforce the boundary. Creating the
table now and backfilling it produces two sources of truth that drift; creating it empty is
decoration. It therefore lands as **Phase 2's first migration**, atomically with the gateway
that makes it mean something.

**What it costs you to accept:** until Phase 2 ships, **every role holding `employee.view`
can still read salary rates and government ID numbers.** That is `GAP_MAP` finding 4,
unchanged since Phase 0.

> **Recommended: accept.** The exposure is real but is not made worse by waiting, and the
> alternative spends Phase 1 effort on a migration Phase 2 would immediately rework.

---

## Decision 2 — Three of five payrolls are self-approved

`PreparedByUser == ApprovedByUser` on three payrolls. This was **undetectable** before
`0007`, because identity was a display-name string. Phase 1 did not create the problem; it
made it visible, which is the entire point of the key.

Phase 2 prohibits self-approval going forward. Nothing in the plan says what happens to rows
that already exist, and Phase 7 will build a workflow on top of them.

**Options:**

| | Consequence |
|---|---|
| **Grandfather them** | They keep their approval. The audit trail shows an approval that Phase 2's rules would now refuse. Cheapest, and honest if these are development records. |
| **Re-approve under the new rule** | A second, properly segregated approver signs them. Correct, but only meaningful if these represent real disbursements. |
| **Void them** | Appropriate only if they are test data that should never have existed. |

> **Recommended: grandfather, and record why.** The database holds 3 employees and 5
> payrolls — this is development data, not disbursement history. But say so explicitly in
> the change log, because "three self-approved payrolls" in an audit later is a question
> somebody will ask.

---

## Decision 3 — `Contracts` is not written by the application

One of three employees has no contract row. Employee save deliberately does **not** create
one.

**Why.** `0005` exists to preserve rate history — Phase 6's "daily rate ≠ contract rate"
rule needs it. The employee form holds a single start/end pair, so mirroring it on every
save overwrites exactly the history the table was created to keep.

**What it costs you to accept:** `Contracts` stays partly empty until it gets a real module
with supersession semantics. Nothing before Phase 6 reads it.

> **Recommended: accept, and schedule the module into Phase 3.** It is a document-shaped
> CRUD module and Phase 3 is already the cheapest phase per line in the plan.

---

## Decision 4 — Offices have no Function/PPA codes *(data entry, not code)*

`FunctionCode` is NULL on **4 of 4 offices, 5 of 5 payrolls, 5 of 5 payroll lines.**

The backfills refused to guess: two offices store `9999`, which is neither a code nor a
name, and one is empty. A wrong function prints an amount under an appropriation it was
never charged to, so NULL — visible at sign-off — was chosen over a plausible guess.

The application now defaults a payroll's function from its charged office, so **this fixes
itself for new payrolls the moment the offices have real codes.** Until then it cannot:
there is nothing to copy.

**This blocks Phase 6.** Its CAFOA rules check the charged appropriation; with every
function NULL they have nothing to check and would pass vacuously.

> **Required from you: assign each office its real Function/PPA code** in
> Departments & Offices. Four rows. This is the one item on this page that no amount of
> code can resolve.

---

## Sign-off

```
[ ] Decision 1 — Tier split deferred to Phase 2's first migration      accept / reject
[ ] Decision 2 — Existing self-approved payrolls          grandfather / re-approve / void
[ ] Decision 3 — Contracts module deferred to Phase 3                  accept / reject
[ ] Decision 4 — Function/PPA codes assigned to all four offices       done / outstanding
[ ] Schema reviewed and signed off; Phase 2 may start
```

Record the outcome in the Change Log in [PHASE_PLAN.md](PHASE_PLAN.md) and set Phase 1 to
`DONE`.
