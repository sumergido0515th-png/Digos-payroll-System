<?php
/**
 * ============================================================================
 * Calendar.php - Phase 4's imperative shell.
 *
 * The resolvers are pure and live in app/Domain/Resolver/. This file is the
 * part that loads rows and calls them, which is the whole "pure core,
 * imperative shell" split CLAUDE.md describes: everything here is I/O, and
 * every decision is somewhere testable against fixtures.
 *
 * apiResolveDay is the endpoint a screen calls to ask "what was this date, for
 * this person, and what is it worth?". It answers with the legal basis
 * attached, because a pre-audit finding without one is not actionable.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Resolver\AuthorityResolver;
use Digos\Domain\Resolver\HolidayResolver;
use Digos\Domain\Resolver\ShiftResolver;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\HolidayRepo;
use Digos\Repo\MemorandumRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\WorkShiftRepo;

/* ==========================================================================
 * The calendar
 * ======================================================================== */

/** Declarations, filtered. */
function apiListHolidays(array $p, array $user): array
{
    return HolidayRepo::listHolidays($p);
}

/** Every pay-rule version, with its legal basis. */
function apiListHolidayPayRules(array $p, array $user): array
{
    return HolidayRepo::payRules();
}

/** Creates or updates a declaration. */
function apiSaveHoliday(array $p, array $user): array
{
    requireFields($p, ['HolidayDate', 'DayType', 'LegalBasis']);

    $isNew = empty($p['HolidayID']);
    $holidayId = $isNew ? newId('HOL') : (string) $p['HolidayID'];

    if (!$isNew && !HolidayRepo::findHoliday($holidayId)) {
        throw new RuntimeException('That holiday declaration does not exist.');
    }

    $dayType = (string) $p['DayType'];
    if (!in_array($dayType, HolidayResolver::DAY_TYPES, true)) {
        throw new RuntimeException('Day type must be one of: '
            . implode(', ', HolidayResolver::DAY_TYPES) . '.');
    }

    $scopeLevel = (string) ($p['ScopeLevel'] ?? 'National');
    if (!in_array($scopeLevel, HolidayResolver::SCOPE_LEVELS, true)) {
        throw new RuntimeException('Scope must be one of: '
            . implode(', ', HolidayResolver::SCOPE_LEVELS) . '.');
    }

    $scopeCode = trim((string) ($p['ScopeCode'] ?? '')) ?: null;
    if ($scopeLevel !== 'National' && $scopeCode === null) {
        throw new RuntimeException(
            "A $scopeLevel declaration has to name which one. Without it the "
            . 'resolver cannot tell whether it applies to this city.');
    }

    $date = (string) nullableDate($p['HolidayDate'], 'Holiday date');

    if (HolidayRepo::declarationExists($date, $scopeLevel, $scopeCode, $dayType,
            $isNew ? '' : $holidayId)) {
        throw new RuntimeException(
            "$date is already declared a $dayType at that scope. A second identical "
            . 'declaration is a double entry, not a second fact.');
    }

    $start = nullableTime($p['StartTime'] ?? null, 'Start time');
    $end = nullableTime($p['EndTime'] ?? null, 'End time');
    if (($start === null) !== ($end === null)) {
        throw new RuntimeException(
            'A partial-day declaration needs both a start and an end time. One alone '
            . 'cannot say how much of the day it covered.');
    }
    if ($start !== null && $end !== null && $end <= $start) {
        throw new RuntimeException('The declaration ends before it starts.');
    }

    $record = [
        'HolidayDate' => $date,
        'HolidayName' => (string) ($p['HolidayName'] ?? ''),
        'DayType' => $dayType,
        'ScopeLevel' => $scopeLevel,
        'ScopeCode' => $scopeCode,
        'StartTime' => $start,
        'EndTime' => $end,
        'LegalBasis' => (string) $p['LegalBasis'],
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
    ];
    if ($isNew) $record['CreatedBy'] = $user['Email'];

    HolidayRepo::saveHoliday($holidayId, $record, $isNew);

    return ['HolidayID' => $holidayId, 'HolidayDate' => $date, 'DayType' => $dayType];
}

/** Removes a declaration. */
function apiDeleteHoliday(array $p, array $user): array
{
    requireFields($p, ['HolidayID']);

    if (!HolidayRepo::findHoliday((string) $p['HolidayID'])) {
        throw new RuntimeException('That holiday declaration does not exist.');
    }

    HolidayRepo::deleteHoliday((string) $p['HolidayID']);
    return ['HolidayID' => $p['HolidayID']];
}

/* ==========================================================================
 * Resolution
 * ======================================================================== */

/**
 * What a date was for an employee, and what authorised anything on it.
 *
 * Both resolvers in one call because the screen asks one question. The shift
 * is resolved first: it decides whether the date was a rest day, and a rest
 * day is a property of the person's schedule rather than of the date, which is
 * why it is not in the Holidays table.
 */
function apiResolveDay(array $p, array $user): array
{
    requireFields($p, ['EmployeeID', 'Date']);

    $employee = EmployeeRepo::findScoped($user, (string) $p['EmployeeID']);
    if (!$employee) throw new RuntimeException('Employee not found.');

    $date = (string) nullableDate($p['Date'], 'Date');
    $worked = !empty($p['Worked']);

    $shift = null;
    $shiftCode = trim((string) ($p['ShiftCode'] ?? ''));
    if ($shiftCode !== '') {
        $shift = ShiftResolver::versionOn(WorkShiftRepo::versionsOf($shiftCode), $date);
    }

    $holiday = HolidayResolver::resolve(
        HolidayRepo::holidaysBetween($date, $date),
        HolidayRepo::payRules(),
        $date,
        (string) ($employee['EmploymentTypeCode'] ?? ''),
        $worked,
        officeScopeFor((string) ($employee['OfficeCode'] ?? '')),
        standardDayHours());

    $authority = AuthorityResolver::resolve(
        MemorandumRepo::activeForDate($date),
        MemorandumRepo::coverageForDate($date),
        (string) $p['EmployeeID'],
        $date,
        (string) ($p['AuthorityType'] ?? ''),
        $shift ?? [],
        ['From' => $p['ClaimedFrom'] ?? '', 'To' => $p['ClaimedTo'] ?? '']);

    return [
        'Date' => $date,
        'EmployeeID' => $p['EmployeeID'],
        'EmploymentTypeCode' => $employee['EmploymentTypeCode'] ?? '',
        'holiday' => $holiday,
        'authority' => $authority,
        'shift' => $shift,
        'rest_day' => ShiftResolver::isRestDay($shift, $date),
    ];
}

/**
 * The geographic scope an office sits in.
 *
 * Region and province are constants for this installation - it is one city's
 * payroll system - and the city comes from the office's own record so that a
 * future merger or renaming is a data change rather than a code change. The
 * barangay is deliberately absent: no office record carries one yet, and
 * inventing a value would make barangay declarations silently apply or
 * silently not.
 *
 * @return array<string, string>
 */
function officeScopeFor(string $officeCode): array
{
    $scope = [
        'Region' => getSetting('ScopeRegion', 'XI'),
        'Province' => getSetting('ScopeProvince', 'Davao del Sur'),
        'City' => getSetting('ScopeCity', 'Digos'),
    ];

    $office = $officeCode === '' ? [] : ReferenceRepo::office($officeCode);
    if (!empty($office['City'])) $scope['City'] = (string) $office['City'];

    return $scope;
}
