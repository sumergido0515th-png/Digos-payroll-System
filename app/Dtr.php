<?php
/**
 * ============================================================================
 * Dtr.php - Phase 3B. Day-level timekeeping capture.
 *
 * WHAT CHANGES HERE
 * Until now a payroll line's DaysWorked, HoursWorked, OvertimeHours,
 * LateMinutes, UndertimeMinutes and AbsentDays were typed straight onto the
 * line, and nothing could say where they came from. From here they are derived
 * from DtrDays by PeriodTotals, which is pure and tested against fixtures.
 *
 * Hand-keying stays possible - a biometric device that was down does not stop
 * payroll - but a hand-keyed row is MARKED as one. Phase 6's first rule checks
 * manual entries against a covering bio exemption and cannot do that if manual
 * and biometric rows look identical in the stored data. `Source` is therefore
 * written on every row rather than left to its column default, so that a row's
 * provenance is a recorded fact rather than the absence of one.
 *
 * The biometric import is deliberately format-agnostic: it takes already-parsed
 * punch rows. Which device the city buys, and what it exports, is not a
 * decision this phase should hardcode.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Dtr\PeriodTotals;
use Digos\Repo\DtrRepo;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\ReferenceRepo;

/** Where a day row came from. Anything else is refused at the door. */
const DTR_SOURCES = ['Manual', 'Biometric', 'Imported'];

/**
 * Day types a timekeeper can set by hand.
 *
 * Deliberately short. Whether a date was a holiday, and what that is worth, is
 * Phase 4's resolveHoliday reading a versioned ordinance table - not a dropdown
 * a timekeeper guesses at. These are the facts about the day that only the
 * office knows.
 */
const DTR_DAY_TYPES = ['Regular', 'RestDay', 'Holiday', 'Suspension'];

/* ==========================================================================
 * Reading
 * ======================================================================== */

/**
 * The employee x date grid for a period.
 *
 * Returns the period's date range, the employees in scope, the day rows that
 * exist, and the derived totals - everything the screen draws, in one call,
 * because a grid that fetched per employee would issue one request per row.
 */
function apiGetDtrGrid(array $p, array $user): array
{
    requireFields($p, ['PeriodID']);

    $period = ReferenceRepo::period((string) $p['PeriodID']);
    if (!$period) throw new RuntimeException('That payroll period does not exist.');

    $employees = EmployeeRepo::listScoped($user,
        ['Status' => 'Active'] + array_intersect_key($p, array_flip(['OfficeCode', 'search'])));

    $days = DtrRepo::daysForPeriodScoped($user, (string) $p['PeriodID']);

    return [
        'period' => $period,
        'dates' => dtrDateRange((string) $period['StartDate'], (string) $period['EndDate']),
        'employees' => array_map(fn(array $e) => [
            'EmployeeID' => $e['EmployeeID'],
            'EmployeeName' => fullName($e),
            'OfficeCode' => $e['OfficeCode'],
            'Position' => $e['Position'],
        ], $employees['rows'] ?? $employees),
        'days' => $days,
        'totals' => PeriodTotals::byEmployee($days, standardDayHours()),
        'summary' => DtrRepo::periodSummaryScoped($user, (string) $p['PeriodID']),
    ];
}

/**
 * The six totals for one employee in a period, derived from their day rows.
 *
 * This is what the payroll grid calls instead of asking a timekeeper to retype
 * numbers that already exist.
 */
function apiGetDtrTotals(array $p, array $user): array
{
    requireFields($p, ['PeriodID', 'EmployeeID']);

    $days = DtrRepo::daysForEmployeeScoped(
        $user, (string) $p['EmployeeID'], (string) $p['PeriodID']);

    return [
        'EmployeeID' => $p['EmployeeID'],
        'PeriodID' => $p['PeriodID'],
        'dayCount' => count($days),
        'hasManualEntry' => PeriodTotals::hasManualEntry($days),
        'totals' => PeriodTotals::fromDays($days, standardDayHours()),
    ];
}

/* ==========================================================================
 * Writing
 * ======================================================================== */

/**
 * Saves a batch of day rows.
 *
 * Batched because the screen is a grid: a timekeeper fills a fortnight for one
 * employee and saves once. Each row is scope-checked on its own employee, and
 * each is dated inside the period - a row outside it would be counted by no
 * period's totals and found by nobody.
 */
function apiSaveDtrDays(array $p, array $user): array
{
    requireFields($p, ['PeriodID', 'days']);

    $period = ReferenceRepo::period((string) $p['PeriodID']);
    if (!$period) throw new RuntimeException('That payroll period does not exist.');

    $rows = $p['days'];
    if (!is_array($rows)) throw new RuntimeException('Days must be a list of day entries.');

    $seen = [];
    $prepared = [];

    foreach ($rows as $row) {
        if (!is_array($row)) throw new RuntimeException('Each day entry must be a record.');

        $employeeId = trim((string) ($row['EmployeeID'] ?? ''));
        $workDate = nullableDate($row['WorkDate'] ?? null, 'Work date');

        if ($employeeId === '' || $workDate === null) {
            throw new RuntimeException('Every day entry needs an employee and a date.');
        }

        // Scope is checked per employee and the result cached, because a
        // fortnight for one person is fifteen rows and re-asking fifteen times
        // is fifteen queries for one answer.
        if (!isset($seen[$employeeId])) {
            requireEmployeeInScope($user, $employeeId, 'a daily time record');
            $seen[$employeeId] = true;
        }

        if ($workDate < (string) $period['StartDate'] || $workDate > (string) $period['EndDate']) {
            throw new RuntimeException(sprintf(
                '%s is outside the period, which runs %s to %s. A day recorded outside '
                . 'its period is counted by no payroll.',
                $workDate, $period['StartDate'], $period['EndDate']));
        }

        $source = (string) ($row['Source'] ?? 'Manual');
        if (!in_array($source, DTR_SOURCES, true)) {
            throw new RuntimeException('Source must be one of: ' . implode(', ', DTR_SOURCES) . '.');
        }

        $dayType = (string) ($row['DayType'] ?? 'Regular');
        if (!in_array($dayType, DTR_DAY_TYPES, true)) {
            throw new RuntimeException('Day type must be one of: ' . implode(', ', DTR_DAY_TYPES) . '.');
        }

        $isAbsent = !empty($row['IsAbsent']);
        $hours = round2($row['HoursWorked'] ?? 0);

        if ($isAbsent && $hours > 0) {
            throw new RuntimeException(sprintf(
                '%s is marked absent and also has %s hours worked. One of the two is wrong, '
                . 'and the totals would count both.', $workDate, $hours));
        }

        $prepared[] = [
            'EmployeeID' => $employeeId,
            'WorkDate' => $workDate,
            'PeriodID' => (string) $p['PeriodID'],
            'TimeIn1' => nullableTime($row['TimeIn1'] ?? null, 'Time in'),
            'TimeOut1' => nullableTime($row['TimeOut1'] ?? null, 'Time out'),
            'TimeIn2' => nullableTime($row['TimeIn2'] ?? null, 'Afternoon time in'),
            'TimeOut2' => nullableTime($row['TimeOut2'] ?? null, 'Afternoon time out'),
            'HoursWorked' => $hours,
            'OvertimeHours' => round2($row['OvertimeHours'] ?? 0),
            'LateMinutes' => round2($row['LateMinutes'] ?? 0),
            'UndertimeMinutes' => round2($row['UndertimeMinutes'] ?? 0),
            'IsAbsent' => $isAbsent ? 1 : 0,
            'DayType' => $dayType,
            'Source' => $source,
            'Remarks' => (string) ($row['Remarks'] ?? ''),
        ];
    }

    foreach ($prepared as $record) {
        $existing = DtrRepo::existingDayId($record['EmployeeID'], $record['WorkDate']);
        DtrRepo::upsertDay($existing !== '' ? $existing : newId('DTR'), $record);
    }

    return ['saved' => count($prepared), 'employees' => count($seen)];
}

/** Removes one day row. */
function apiDeleteDtrDay(array $p, array $user): array
{
    requireFields($p, ['EmployeeID', 'WorkDate']);
    requireEmployeeInScope($user, (string) $p['EmployeeID'], 'a daily time record');

    $workDate = (string) nullableDate($p['WorkDate'], 'Work date');
    DtrRepo::deleteDay((string) $p['EmployeeID'], $workDate);

    return ['EmployeeID' => $p['EmployeeID'], 'WorkDate' => $workDate];
}

/**
 * Imports parsed biometric punches.
 *
 * Takes rows that have already been read out of whatever the device exports,
 * rather than a file. Which device the city buys is not settled, and hardcoding
 * one vendor's CSV layout here would have to be undone when it is.
 *
 * RECONCILIATION: an existing MANUAL row is not silently replaced. A manual
 * entry is a claim somebody made and Phase 6 checks it against a bio exemption;
 * overwriting it with the device's version would erase the discrepancy that
 * check exists to find. Those dates come back as conflicts for a human.
 */
function apiImportBiometricLogs(array $p, array $user): array
{
    requireFields($p, ['PeriodID', 'punches']);

    $period = ReferenceRepo::period((string) $p['PeriodID']);
    if (!$period) throw new RuntimeException('That payroll period does not exist.');

    $punches = $p['punches'];
    if (!is_array($punches)) throw new RuntimeException('Punches must be a list.');

    $imported = 0;
    $conflicts = [];
    $seen = [];

    foreach ($punches as $punch) {
        if (!is_array($punch)) throw new RuntimeException('Each punch must be a record.');

        $employeeId = trim((string) ($punch['EmployeeID'] ?? ''));
        $workDate = nullableDate($punch['WorkDate'] ?? null, 'Work date');
        if ($employeeId === '' || $workDate === null) continue;

        if (!isset($seen[$employeeId])) {
            requireEmployeeInScope($user, $employeeId, 'a biometric import');
            $seen[$employeeId] = true;
        }
        if ($workDate < (string) $period['StartDate'] || $workDate > (string) $period['EndDate']) {
            continue;                       // not this period's business
        }

        $existingId = DtrRepo::existingDayId($employeeId, $workDate);
        if ($existingId !== '') {
            $existing = DtrRepo::daysForEmployeeScoped($user, $employeeId, (string) $p['PeriodID']);
            $match = array_values(array_filter($existing,
                fn(array $d) => (string) $d['WorkDate'] === $workDate));

            if ($match && ($match[0]['Source'] ?? '') === 'Manual') {
                $conflicts[] = ['EmployeeID' => $employeeId, 'WorkDate' => $workDate];
                continue;
            }
        }

        DtrRepo::upsertDay($existingId !== '' ? $existingId : newId('DTR'), [
            'EmployeeID' => $employeeId,
            'WorkDate' => $workDate,
            'PeriodID' => (string) $p['PeriodID'],
            'TimeIn1' => nullableTime($punch['TimeIn1'] ?? null, 'Time in'),
            'TimeOut1' => nullableTime($punch['TimeOut1'] ?? null, 'Time out'),
            'TimeIn2' => nullableTime($punch['TimeIn2'] ?? null, 'Afternoon time in'),
            'TimeOut2' => nullableTime($punch['TimeOut2'] ?? null, 'Afternoon time out'),
            'HoursWorked' => round2($punch['HoursWorked'] ?? 0),
            'OvertimeHours' => round2($punch['OvertimeHours'] ?? 0),
            'LateMinutes' => round2($punch['LateMinutes'] ?? 0),
            'UndertimeMinutes' => round2($punch['UndertimeMinutes'] ?? 0),
            'IsAbsent' => 0,
            'DayType' => 'Regular',
            'Source' => 'Biometric',
            'Remarks' => (string) ($punch['Remarks'] ?? ''),
        ]);
        $imported++;
    }

    return [
        'imported' => $imported,
        'conflicts' => $conflicts,
        'message' => $conflicts
            ? count($conflicts) . ' day(s) already had a hand-keyed entry and were left alone. '
                . 'Review them before overwriting - a manual entry is a claim the pre-audit checks.'
            : 'Imported ' . $imported . ' day(s).',
    ];
}

/* ==========================================================================
 * Shared
 * ======================================================================== */

/**
 * What counts as a whole working day, from settings.
 *
 * A setting rather than a constant because it differs by engagement, but read
 * here rather than inside PeriodTotals so that the derivation stays pure and
 * its fixtures can state the day length they mean.
 */
function standardDayHours(): float
{
    $hours = (float) getSetting('StandardDayHours', (string) PeriodTotals::STANDARD_DAY_HOURS);
    return $hours > 0 ? $hours : PeriodTotals::STANDARD_DAY_HOURS;
}

/**
 * Every date in a period, inclusive.
 *
 * @return string[] YYYY-MM-DD
 */
function dtrDateRange(string $start, string $end): array
{
    if ($start === '' || $end === '' || $end < $start) return [];

    $dates = [];
    $cursor = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);

    // A period is a fortnight or a month; the cap is a guard against a typo in
    // a period's dates turning a screen load into an unbounded loop.
    while ($cursor <= $last && count($dates) < 400) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }
    return $dates;
}
