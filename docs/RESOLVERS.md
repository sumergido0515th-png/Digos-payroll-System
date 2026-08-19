# Resolvers

Phase 4's deliverable. Three pure functions that answer the questions every later
phase asks about a single day:

| Question | Function |
|---|---|
| What was this date, and what is it worth to *this* employee? | `HolidayResolver::resolve()` |
| What authorised this, and for how long? | `AuthorityResolver::resolve()` |
| Which version of the shift governed that date? | `ShiftResolver::versionOn()` |

All three live in [`app/Domain/Resolver/`](../app/Domain/Resolver/) and are **pure** —
no `DB::`, no `$_SESSION`, no clock, no file I/O. Rows go in, a decision comes out.
[`app/Calendar.php`](../app/Calendar.php) is the imperative shell that loads the rows
and calls them.

That split is not stylistic. Phase 4's exit gate is *"fixture-based tests pass for
overlapping memos, superseded windows, holiday scope precedence, JO/COS vs plantilla
divergence, partial-day work suspension"* — and a function that reaches for a database
cannot be handed a fixture that states the case it is about.

---

## `HolidayResolver::resolve()`

```
resolve(holidays, rules, date, employmentTypeCode, worked, officeScope, standardDayHours)
  -> { day_type, paid, multiplier, legal_basis, scope_level, scope_code,
       holiday_id, holiday_name, partial, coverage_fraction, hours_covered,
       unresolved, unresolved_reason }
```

Three decisions, in order, deliberately separate.

### 1. Which declaration applies — most specific wins

```
National → Region → Province → City → Barangay
```

A city that declares its fiesta a local holiday overrides the national ordinary day.
A national regular holiday still applies to a city that declared nothing. Another
city's holiday is **not** this city's — the resolver checks the declaration's
`ScopeCode` against the office's own scope and skips what does not match.

`SCOPE_LEVELS` is ordered least-specific-first and the array index *is* the
precedence, so a level added without a place in that list ranks nothing rather than
silently outranking a level that has one.

### 2. Which pay rule applies — specificity, then the version in force

Two independent tie-breaks, and both matter:

- **A rule naming the employment type beats the `NULL` fallback**, however recent the
  fallback is. Without this, adding a new general rule would silently switch JO
  workers back onto plantilla terms.
- **Among equally specific rules, the latest `EffectiveFrom` not in the future wins** —
  measured against *the date being resolved*, never today. A payroll prepared last
  year was computed under last year's policy, and a pre-audit re-checking it has to
  ask what the rule was then.

**A missing rule is reported, never guessed at.** `unresolved` comes back true with a
reason naming the day type, the employment type and the date. A resolver that
invented "unpaid, 0×" when its table was incomplete would produce a wrong payroll
that looked exactly like a correct one, and Phase 6 would have nothing to flag.

### 3. How much of the day it covered

A suspension from 13:00 to 17:00 is half an eight-hour day. `coverage_fraction` is
what a partial-day computation multiplies by. A window longer than a standard day is
still one day, not more.

### The divergence this exists for

| | Regular holiday, **not** worked | Regular holiday, worked |
|---|---|---|
| Plantilla | paid, ×1.0 | paid, ×2.0 |
| **Job Order** | **not paid, ×0.0** | paid, ×2.0 |
| **Contract of Service** | **not paid, ×0.0** | paid, ×2.0 |

A JO/COS worker is engaged for *services actually rendered*. Paying them for an
unworked regular holiday is a disallowance, and it is the easiest mistake to make
when a payroll is prepared by hand. Worked, everyone converges again.

---

## `AuthorityResolver::resolve()`

```
resolve(memos, coverage, employeeId, datetime, authorityType, shift, claimedSpan)
  -> { authorised, memo_id, control_no, window, source_scope,
       superseded_chain[], overlapping[], authorised_minutes, truncated }
```

### Never take claimed hours at face value

The authorised span is the **intersection** of three things, narrowest wins:

```
(memo window) × (claimed span) × (shift)
```

A memo authorising 18:00–22:00 with a punch-out at 23:30 authorises **four** hours,
not five and a half. A claim *narrower* than the authority is taken at its own
length — a memo authorising four hours does not pay four to somebody who worked two.

**Overtime is deliberately not bounded by the shift.** Overtime is by definition
outside the shift, so intersecting the two would authorise nothing at all. Every
other authority type *is* shift-bounded. That inversion is easy to "fix" back in, so
it carries a comment at the point of the exception and a test named after it.

### Supersession truncates; it does not delete

When memo B supersedes memo A, A's window ends **the day before** B's begins. A stays
readable and `original_end` keeps what was granted, because *"this overtime was
authorised by a memo that had already been replaced"* is a finding and stating it
needs both windows.

A successor starting after the original had already ended is **not** a truncation —
reporting one would put noise on every ordinary renewal.

`supersession_chain` walks `SupersedesID` backwards. It carries a visited set because
the three chain columns are self-referencing foreign keys with no cycle constraint, so
A→B→A is storable; bad data should produce a bad report, not a hung screen.

**An amendment is not a replacement.** `AmendsID` narrows a memo that stays in force;
`SupersedesID` ends it. They are separate columns so this function can tell them
apart.

### Overlapping memoranda

All applicable memos come back, most recently issued first. The caller takes the first
as the operative instrument and `overlapping` as the ones to report — two instruments
authorising the same overtime, possibly at different rates, is exactly what a
pre-audit needs to see rather than have chosen for it.

### Effectivity

The five types stored raw by Phase 3 are interpreted **here and nowhere else**:

| Type | Read as |
|---|---|
| `Range` | inside `EffectivityStart`..`EffectivityEnd` |
| `Specific` | the comma-separated `SpecificDates` only |
| `Recurring` | ISO weekdays in `RecurrenceDays`, inside the window — recurring on *no* days covers no days |
| `Window` | as `Range`, plus `TimeFrom`/`TimeTo` narrowing the minutes |
| `OpenEnded` | from `EffectivityStart`, no end |

---

## `ShiftResolver`

`versionOn()` returns the version whose `EffectiveFrom` is latest but not after the
date, and which has not already ended. The changeover day belongs to the **new**
version.

**A date before the shift existed returns `null`, and that is a real answer.**
Inventing the current version would be exactly the retroactive rewrite versioning
exists to prevent: changing an office start time from 08:00 to 09:00 would otherwise
un-late everybody who had been late for months.

`isRestDay()` is here rather than in the holiday calendar because a rest day is a
property of the person's schedule, not of the date — Saturday is a rest day for the
office and a working day for the market's weekend crew.

`nightDifferentialMinutes()` handles the wrap. The usual window is 22:00–06:00, and a
naive `end − start` returns a negative number and silently pays nobody.

---

## Where the data comes from

Migration [`0019`](../migrations/0019_holidays_and_pay_rules.sql) creates two tables,
kept separate because they are two different kinds of fact on two different
schedules:

- **`Holidays`** — proclamations. Twenty-odd new rows a year, forever.
- **`HolidayPayRules`** — policy. Changes rarely, versioned by effectivity so the old
  version still governs payrolls already prepared.

`LegalBasis` is `NOT NULL` on both. A finding that says *"this should have been paid
2×"* is worth little; one that cites the issuance is auditable.

> **The seeded multipliers need confirming against the city's own issuances before
> go-live.** They are the national default. An LGU may be more generous by ordinance —
> which is precisely why this is a versioned table with a legal basis rather than a
> constant in PHP.

## Tests

| File | Covers |
|---|---|
| [`tests/Unit/HolidayResolverTest.php`](../tests/Unit/HolidayResolverTest.php) | scope precedence, the JO/COS divergence, rule versioning, partial-day suspension |
| [`tests/Unit/AuthorityResolverTest.php`](../tests/Unit/AuthorityResolverTest.php) | overlapping memos, superseded windows, the time-window intersection, all five effectivity types |
| [`tests/Unit/ShiftResolverTest.php`](../tests/Unit/ShiftResolverTest.php) | historical version selection, rest days, the midnight-wrapping night window |

Fixtures state their own rule tables rather than reading the migration's seed, so each
test passes or fails for a reason written down inside it.
