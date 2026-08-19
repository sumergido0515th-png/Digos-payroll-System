# Demo seed, as importable files

The same synthetic data as [`../demo-seed.sql`](../demo-seed.sql), in the format the
**Settings → Import Data** screen reads. Everything the warning at the top of that file
says applies here unchanged: **none of this is real personnel.** Every identifier is in an
impossible range (TIN/GSIS/PhilHealth/Pag-IBIG/cash card all begin `9000…`) and every
address is `example.invalid`, which can never receive mail.

## Import them in this order

The number on each file is the order, and it is not cosmetic — an employee names an office
code, so the office has to exist first.

| # | File | Import type | Rows |
|---|---|---|---|
| 1 | `1-offices.csv` | Offices | 3 |
| 2 | `2-departments.csv` | Departments | 3 |
| 3 | `3-timekeepers.csv` | Timekeepers | 3 |
| 4 | `4-employees.csv` | Employees | 12 |
| 5 | `5-payroll-periods.csv` | Payroll Periods | 1 |

Each import previews before it writes. On a database that already holds these codes the
import updates them rather than duplicating them, so re-running the set is safe.

## The headings are deliberately not the field names

`4-employees.csv` says `Surname`, `Given Name`, `Sex`, `Nature of Appointment` and
`Daily Rate` — not `LastName`, `FirstName`, `Gender`, `EmploymentType`, `SalaryRate`. That
is the point of these files as a test: they are shaped like a spreadsheet an office
actually keeps, and the importer is supposed to work out the mapping and show it to you
for confirmation. If you open one and it looks like it needs renaming before it will
import, something has regressed.

`Daily Rate` is worth naming specifically. The employee form takes a rate plus a *rate
basis*, and no spreadsheet anywhere carries a "rate basis" column. The importer defaults
it to Daily, which is how a JO/COS rate is quoted, and `deriveRates()` computes the hourly
and monthly figures from it — so the imported employee ends up with exactly the rates the
form would have produced.

## What is not in these files, and why

**No `EmployeeID`, `TimekeeperID` or `PeriodID` columns.** Those are `newId()` strings the
system generates. `demo-seed.sql` hardcodes them (`EMP-DEMO-001`) because it inserts rows
directly; the importer goes through the ordinary save functions, where an id that is
present but unknown means "update this existing record" — and on a fresh database there is
nothing to update. Leaving the column out is what makes these files import as new records.

**No `Counters` row.** `demo-seed.sql` seeds `(2026, 0)` so the first payroll of the year
numbers from 1. That is not master data, it is the payroll-number sequence, and there is no
import type for it — nor should there be. Import the SQL seed if you need it, or let the
first payroll create it.

**No payroll.** For the same reason `demo-seed.sql` has none: a payroll line's money is
computed by `computeLine()` from rate, days and deductions. Seeding totals by hand bakes in
numbers the application never produced, which hides a computation regression instead of
exposing it. Create the payroll through the UI — that is the flow a walkthrough is meant to
show.

## Deployment

These files are data, not application code, so `tools/build-deploy.php` does not ship
`seeds/` — the same separation the SQL seed relies on. A production deployment cannot
inherit fabricated employees by accident; getting them onto a server takes somebody
deliberately uploading one of these files and confirming the preview.
