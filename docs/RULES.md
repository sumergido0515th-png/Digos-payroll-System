# Pre-audit rule catalogue

Phase 6's deliverable. Twenty-two rules, run by
[`RuleEngine::validate()`](../app/Domain/Rules/RuleEngine.php) — one pure function, no
side effects, everything it needs passed in by
[`app/PreAudit.php`](../app/PreAudit.php).

> The engine **reports**. It does not decide what happens next: Phase 7's transition
> guard and Phase 8's print gate read the severities and act. That split is what lets a
> preparer run the pre-audit speculatively against their own work with no consequences,
> and lets a rule be re-tiered without touching workflow code.

## Severity tiers

The difference between tiers is **what happens next**, not "how bad".

| Tier | Effect | When |
|---|---|---|
| **BLOCKER** | Print disabled. **No override.** | The document would be wrong on its face — a negative net pay, a rate contradicting the contract, a line charged where the preparer cannot see. |
| **WARNING** | Allowed, with a logged justification. | Most document-integrity findings. A missing scan is usually a filing delay, and blocking payroll for it means people go unpaid because a document is late. |
| **INFO** | Advisory. | Worth a pre-auditor's eye, not worth stopping for. |

Moving a rule between tiers is a **policy decision**, not a tuning knob. Change it here
as well as in the code.

---

## Attachment and document integrity

| ID | Severity | Fires when |
|---|---|---|
| `DOC-001` | WARNING | A hand-keyed working day has no bio exemption, travel order or attachment covering it. |
| `DOC-002` | WARNING | A cited travel order's dates fall entirely outside the period. |
| `DOC-003` | INFO | Two attachments share one SHA-256. |
| `DOC-004` | INFO | One control number appears on more than one memorandum. |

`DOC-001` is the rule the whole document layer exists to serve — and it is a WARNING on
purpose. `DOC-003` is unreachable through the application (the unique key on `Sha256`
refuses it), so a hit means rows arrived another way: a restore, or a direct edit.

## Conflict detection

| ID | Severity | Fires when |
|---|---|---|
| `CON-001` | WARNING | A day is recorded as an absence **and** covered by a travel order. |
| `CON-002` | INFO | A bio exemption covers a day that has a biometric record anyway. |
| `CON-003` | **BLOCKER** | The employee appears on another payroll covering dates in this period. |
| `CON-004` | WARNING | Overtime with no memorandum authorising it. |
| `CON-005` | **BLOCKER** | More overtime claimed than the cited memorandum's own window allows. |

`CON-002` is rarely fraud — usually a standing exemption left in place after its reason
ended — but it makes every *other* exemption less believable, which is why it is
reported.

`CON-005` blocks where `CON-004` warns: a missing document is a filing problem, but a
figure that contradicts the authority cited on the same voucher is a contradiction no
justification resolves.

**`CON-003` is redacted.** The check runs system-wide — an employee paid twice by two
offices is the case it exists for, and a scoped query would answer "no clash" to the one
reader who most needs to know. The finding names only the employee, who is already on the
payroll in front of the reader; the other payroll's number and office are dropped in
`redactedOverlaps()` before the engine sees them. This is Phase 2's rule: *"returns
redacted finding to each affected scope, full detail visible only to Admin."*

## Shift and computation

| ID | Severity | Fires when |
|---|---|---|
| `SHF-001` | WARNING | A DTR day falls outside every version of the shift, so late/undertime were computed against nothing. |
| `SHF-002` | INFO | Night differential is claimed by a shift defining no night window. |
| `SHF-003` | WARNING | A rest day was worked with no memorandum. |
| `CMP-001` | WARNING | The contract in force ends before the period does. |
| `CMP-002` | **BLOCKER** | The line's daily rate and the contract's rate disagree. |
| `CMP-002` | INFO | *No contract covers the employee* — the check could not run. |
| `CMP-003` | **BLOCKER** | Net pay ≤ 0, or deductions exceed gross. |

`CMP-001` and `CMP-002` look the contract up **at the period start**, not the end. `CMP-001`
is precisely the case of a contract that expired part-way through, and a lookup at the end
would never find one — it would report "no contract" instead, a different and much weaker
finding. *(That was a real bug, caught by the fixture on the first run.)*

**A missing contract reports INFO rather than passing silently.** A check that could not
run and a check that found nothing look identical from outside, and only one of them is
reassuring.

## Form completeness

| ID | Severity | Fires when |
|---|---|---|
| `FRM-001` | WARNING | The signatory block has no prepared-by or approved-by name. |
| `FRM-002` | **BLOCKER** | The lines do not sum to the header's stated total. |
| `FRM-003` | INFO | More lines than the printed form has rows (`PRINT_ROWS` = 15). |

`FRM-001` warns rather than blocks because a draft legitimately has no approver yet —
Phase 7's transition guard is where an unsigned form actually stops moving.

`FRM-003` reads `PRINT_ROWS`, which must stay equal to `MaxEmployeesPerPayroll` and to
`PRINT_ROWS` in [`app/PrintDoc.php`](../app/PrintDoc.php) — all three are load-bearing for
the printed geometry (CLAUDE.md → Traps).

## Calendar and holiday

| ID | Severity | Fires when |
|---|---|---|
| `CAL-001` | WARNING | Hours worked on a declared non-working day, with no memorandum. |
| `CAL-002` | WARNING | A holiday premium with no contractual basis for that engagement type. |
| `CAL-003` | INFO | A calendar declaration records no legal basis. |

`CAL-002` is Phase 4's JO/COS divergence surfacing as a finding: paying a Job Order worker
for a regular holiday they did not work is a disallowance, and `HolidayResolver` already
knows.

## Scope integrity

| ID | Severity | Fires when |
|---|---|---|
| `SCP-001` | **BLOCKER** | A line is charged to an office outside the preparer's scope. |

This is why `PayrollDetails` scopes on `ChargedOfficeCode` rather than the employee's home
office — decided in migration `0006`. A preparer who cannot read an office cannot verify
what they are charging to it.

`preparerOfficeCodes()` returns `null` for a wildcard grant and `[]` for a user with no
office grants at all, and the engine treats those differently: `null` means "nothing to
check", `[]` means "every line is a finding". Collapsing them would turn an
administrator's payroll into a wall of scope violations.

---

## Not yet implemented

Named in the phase plan, deliberately deferred, so their absence is a decision rather
than an oversight:

| Rule | Why deferred |
|---|---|
| Partial bio-exemption coverage | Needs per-hour coverage; `AttachmentCoverage` is per-day. A day is covered or it is not. |
| Document cited but not in Approved status | The document modules have a `Status` column but no approval workflow — that is Phase 7's shape, and building a check against a state machine that does not exist yet would encode a guess. |
| Missing/unreadable attachment file | Requires filesystem access, which would make the engine impure. Belongs in a maintenance sweep, not the per-payroll pre-audit. |
| Out-of-sequence control number | Sequence is an office convention this system has never been told. `DOC-004` catches the duplicate, which is the objective half. |
| Period contains a TENTATIVE calendar entry | `Holidays.Status` has no `Tentative` value yet. |
| Biometric log during a full-day suspension | Needs raw punch times; `DtrDays` stores derived hours. Revisit when the biometric import lands a real device format. |

## Tests

[`tests/Unit/RuleEngineTest.php`](../tests/Unit/RuleEngineTest.php) — 31 tests.

Every test asserts the **full** list of rule ids, not "contains". A rule that fires when it
should not is as much a defect as one that stays silent: a pre-audit that cries wolf teaches
its users to click past the one finding that mattered.

The load-bearing test is `testAKnownGoodPayrollProducesNoFindingsAtAll`. Without it, every
other test in the file would pass just as well against an engine that flagged everything.
