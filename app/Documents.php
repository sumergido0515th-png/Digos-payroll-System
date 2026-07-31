<?php
/**
 * ============================================================================
 * Documents.php - Phase 3. The four authority documents, CRUD only.
 *
 * Memorandum, Bio Exemption, Travel Order, Work Shift, and Contracts. Storage
 * and listing under scope; no business logic, deliberately.
 *
 * WHAT IS NOT HERE, ON PURPOSE
 * Nothing resolves a memo's effectivity. A memo can say "recurring, Mondays and
 * Wednesdays, 18:00-22:00, from March, superseding memo 41" and this module
 * stores every part of that without asking what it means. Working out which
 * memo covers which employee at which datetime - intersecting the biometric
 * span with the memo window with the shift, walking the supersession chain and
 * truncating what it replaced - is Phase 4's resolveAuthority(), a pure
 * function tested against fixtures. Spreading that logic across four CRUD
 * screens is how it becomes untestable.
 *
 * Validation here is therefore about the record being well formed, not about
 * whether the authority it asserts is sound.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Repo\ContractRepo;
use Digos\Repo\EmployeeDocumentRepo;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\MemorandumRepo;
use Digos\Repo\ScopeGateway;
use Digos\Repo\WorkShiftRepo;

/** How EffectivityType may be spelled. Phase 4 branches on exactly these. */
const MEMO_EFFECTIVITY_TYPES = ['Range', 'Specific', 'Recurring', 'Window', 'OpenEnded'];

/** What kind of authority a memorandum confers. */
const MEMO_AUTHORITY_TYPES = ['Overtime', 'Detail', 'Travel', 'FlexiTime', 'Suspension', 'Other'];

/* ==========================================================================
 * Memorandum
 * ======================================================================== */

/** Memoranda the caller may see. */
function apiListMemoranda(array $p, array $user): array
{
    return MemorandumRepo::listScoped($user, $p);
}

/** One memorandum with its covered employees. */
function apiGetMemorandum(array $p, array $user): array
{
    requireFields($p, ['MemoID']);

    $memo = MemorandumRepo::findScoped($user, $p['MemoID']);
    if (!$memo) throw new RuntimeException('Memorandum not found.');

    $memo['CoveredEmployees'] = MemorandumRepo::coveredEmployeesScoped($user, $p['MemoID']);
    return $memo;
}

/**
 * Creates or updates a memorandum.
 *
 * The office on the memo is checked against the caller's scope before the write,
 * not after: a user who could not read an office's memoranda must not be able
 * to create one there either, and permits() answers that from the same grants
 * the reads use.
 */
function apiSaveMemorandum(array $p, array $user): array
{
    requireFields($p, ['ControlNo', 'Subject']);

    $isNew = empty($p['MemoID']);
    $memoId = $isNew ? newId('MEMO') : (string) $p['MemoID'];

    if (!$isNew && !MemorandumRepo::findScoped($user, $memoId)) {
        // Same wording for absent and out of scope, as everywhere else.
        throw new RuntimeException('Memorandum not found.');
    }

    $controlNo = trim((string) $p['ControlNo']);
    if (MemorandumRepo::controlNoTaken($controlNo, $isNew ? '' : $memoId)) {
        throw new RuntimeException(
            "Control number $controlNo is already used by another memorandum. "
            . 'Two memoranda filed under one number cannot be told apart later.');
    }

    $officeCode = trim((string) ($p['OfficeCode'] ?? '')) ?: null;
    $functionCode = trim((string) ($p['FunctionCode'] ?? '')) ?: null;

    ScopeGateway::requirePermits($user, 'Memorandum',
        ['OfficeCode' => $officeCode, 'FunctionCode' => $functionCode],
        $officeCode === null ? 'citywide memoranda' : "memoranda for office $officeCode");

    $effectivityType = (string) ($p['EffectivityType'] ?? 'Range');
    if (!in_array($effectivityType, MEMO_EFFECTIVITY_TYPES, true)) {
        throw new RuntimeException('Effectivity must be one of: '
            . implode(', ', MEMO_EFFECTIVITY_TYPES) . '.');
    }

    $authorityType = (string) ($p['AuthorityType'] ?? 'Other');
    if (!in_array($authorityType, MEMO_AUTHORITY_TYPES, true)) {
        throw new RuntimeException('Authority type must be one of: '
            . implode(', ', MEMO_AUTHORITY_TYPES) . '.');
    }

    $employeeIds = memoCoveredEmployeeIds($p, $user);

    foreach (['SupersedesID', 'AmendsID', 'RevokedByID'] as $link) {
        $target = trim((string) ($p[$link] ?? ''));
        if ($target === '') continue;
        if ($target === $memoId) {
            throw new RuntimeException('A memorandum cannot supersede, amend or revoke itself.');
        }
        if (!MemorandumRepo::exists($target)) {
            throw new RuntimeException("The memorandum referenced in $link does not exist.");
        }
    }

    $record = [
        'ControlNo' => $controlNo,
        'Subject' => (string) $p['Subject'],
        'DateIssued' => nullableDate($p['DateIssued'] ?? null, 'Date issued'),
        'DateApproved' => nullableDate($p['DateApproved'] ?? null, 'Date approved'),
        'DateReceived' => nullableDate($p['DateReceived'] ?? null, 'Date received'),
        'OfficeCode' => $officeCode,
        'FunctionCode' => $functionCode,
        'EffectivityType' => $effectivityType,
        'EffectivityStart' => nullableDate($p['EffectivityStart'] ?? null, 'Effectivity start'),
        'EffectivityEnd' => nullableDate($p['EffectivityEnd'] ?? null, 'Effectivity end'),
        'TimeFrom' => nullableTime($p['TimeFrom'] ?? null, 'Time from'),
        'TimeTo' => nullableTime($p['TimeTo'] ?? null, 'Time to'),
        'SpecificDates' => trim((string) ($p['SpecificDates'] ?? '')) ?: null,
        'RecurrenceDays' => trim((string) ($p['RecurrenceDays'] ?? '')) ?: null,
        'AuthorityType' => $authorityType,
        'SupersedesID' => trim((string) ($p['SupersedesID'] ?? '')) ?: null,
        'AmendsID' => trim((string) ($p['AmendsID'] ?? '')) ?: null,
        'RevokedByID' => trim((string) ($p['RevokedByID'] ?? '')) ?: null,
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
    ];

    if ($record['EffectivityStart'] && $record['EffectivityEnd']
        && $record['EffectivityEnd'] < $record['EffectivityStart']) {
        throw new RuntimeException('Effectivity ends before it starts.');
    }

    if ($isNew) $record['CreatedBy'] = $user['Email'];

    MemorandumRepo::save($memoId, $record, $employeeIds, $isNew);

    return ['MemoID' => $memoId, 'ControlNo' => $controlNo, 'Covered' => count($employeeIds)];
}

/** Removes a memorandum, unless another one points at it. */
function apiDeleteMemorandum(array $p, array $user): array
{
    requireFields($p, ['MemoID']);

    if (!MemorandumRepo::findScoped($user, $p['MemoID'])) {
        throw new RuntimeException('Memorandum not found.');
    }

    // The chain columns are ON DELETE SET NULL, so the database would allow
    // this and quietly forget what the survivor superseded. Refusing with the
    // list is more useful than finding that out during an audit.
    $referencing = MemorandumRepo::referencedBy((string) $p['MemoID']);
    if ($referencing) {
        throw new RuntimeException(
            'This memorandum is referenced by ' . count($referencing) . ' other memorandum(s): '
            . implode(', ', array_column($referencing, 'ControlNo'))
            . '. Remove or repoint those first, or the record of what they replaced is lost.');
    }

    MemorandumRepo::delete((string) $p['MemoID']);
    return ['MemoID' => $p['MemoID']];
}

/**
 * The covered employee ids from the payload, each checked against scope.
 *
 * Checked one by one rather than trusting the list: naming an employee in
 * another office on a memo the caller may write is how coverage becomes a way
 * to reach across scope.
 *
 * @return string[]
 */
function memoCoveredEmployeeIds(array $p, array $user): array
{
    $raw = $p['EmployeeIDs'] ?? [];
    if (is_string($raw)) $raw = array_filter(array_map('trim', explode(',', $raw)));
    if (!is_array($raw)) throw new RuntimeException('Covered employees must be a list.');

    $ids = [];
    foreach ($raw as $employeeId) {
        $employeeId = trim((string) $employeeId);
        if ($employeeId === '') continue;

        if (!EmployeeRepo::findScoped($user, $employeeId)) {
            throw new RuntimeException(
                "Employee $employeeId is not one you have access to, so they cannot be "
                . 'added to this memorandum.');
        }
        $ids[] = $employeeId;
    }
    return $ids;
}

/* ==========================================================================
 * Bio exemption
 * ======================================================================== */

/** Bio exemptions the caller may see. */
function apiListBioExemptions(array $p, array $user): array
{
    return EmployeeDocumentRepo::listExemptionsScoped($user, $p);
}

/** Creates or updates a bio exemption. */
function apiSaveBioExemption(array $p, array $user): array
{
    requireFields($p, ['EmployeeID']);

    $isNew = empty($p['ExemptionID']);
    $exemptionId = $isNew ? newId('BEX') : (string) $p['ExemptionID'];

    requireEmployeeInScope($user, (string) $p['EmployeeID'], 'a bio exemption');

    if (!$isNew && !EmployeeDocumentRepo::findExemptionScoped($user, $exemptionId)) {
        throw new RuntimeException('Bio exemption not found.');
    }

    $validFrom = nullableDate($p['ValidFrom'] ?? null, 'Valid from');
    $validTo = nullableDate($p['ValidTo'] ?? null, 'Valid to');
    if ($validFrom && $validTo && $validTo < $validFrom) {
        throw new RuntimeException('The exemption ends before it starts.');
    }

    $record = [
        'EmployeeID' => (string) $p['EmployeeID'],
        'ReasonCode' => (string) ($p['ReasonCode'] ?? ''),
        'Reason' => (string) ($p['Reason'] ?? ''),
        'ValidFrom' => $validFrom,
        'ValidTo' => $validTo,
        'ProofType' => (string) ($p['ProofType'] ?? ''),
        'ProofRef' => (string) ($p['ProofRef'] ?? ''),
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
    ];
    if ($isNew) $record['CreatedBy'] = $user['Email'];

    EmployeeDocumentRepo::saveExemption($exemptionId, $record, $isNew);
    return ['ExemptionID' => $exemptionId];
}

/** Removes a bio exemption. */
function apiDeleteBioExemption(array $p, array $user): array
{
    requireFields($p, ['ExemptionID']);

    if (!EmployeeDocumentRepo::findExemptionScoped($user, $p['ExemptionID'])) {
        throw new RuntimeException('Bio exemption not found.');
    }

    EmployeeDocumentRepo::deleteExemption((string) $p['ExemptionID']);
    return ['ExemptionID' => $p['ExemptionID']];
}

/* ==========================================================================
 * Travel order
 * ======================================================================== */

/** Travel orders the caller may see. */
function apiListTravelOrders(array $p, array $user): array
{
    return EmployeeDocumentRepo::listTravelOrdersScoped($user, $p);
}

/** Creates or updates a travel order. */
function apiSaveTravelOrder(array $p, array $user): array
{
    requireFields($p, ['TravelOrderNo', 'EmployeeID']);

    $isNew = empty($p['TravelOrderID']);
    $travelOrderId = $isNew ? newId('TO') : (string) $p['TravelOrderID'];

    requireEmployeeInScope($user, (string) $p['EmployeeID'], 'a travel order');

    if (!$isNew && !EmployeeDocumentRepo::findTravelOrderScoped($user, $travelOrderId)) {
        throw new RuntimeException('Travel order not found.');
    }

    $number = trim((string) $p['TravelOrderNo']);
    if (EmployeeDocumentRepo::travelOrderNoTaken($number, $isNew ? '' : $travelOrderId)) {
        throw new RuntimeException("Travel order number $number is already in use.");
    }

    $depart = nullableDate($p['DepartDate'] ?? null, 'Departure date');
    $return = nullableDate($p['ReturnDate'] ?? null, 'Return date');
    if ($depart && $return && $return < $depart) {
        throw new RuntimeException('The return date is before the departure date.');
    }

    $record = [
        'TravelOrderNo' => $number,
        'EmployeeID' => (string) $p['EmployeeID'],
        'Destination' => (string) ($p['Destination'] ?? ''),
        'Purpose' => (string) ($p['Purpose'] ?? ''),
        'DepartDate' => $depart,
        'ReturnDate' => $return,
        'PerDiem' => !empty($p['PerDiem']) ? 1 : 0,
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
    ];
    if ($isNew) $record['CreatedBy'] = $user['Email'];

    EmployeeDocumentRepo::saveTravelOrder($travelOrderId, $record, $isNew);
    return ['TravelOrderID' => $travelOrderId, 'TravelOrderNo' => $number];
}

/** Removes a travel order. */
function apiDeleteTravelOrder(array $p, array $user): array
{
    requireFields($p, ['TravelOrderID']);

    if (!EmployeeDocumentRepo::findTravelOrderScoped($user, $p['TravelOrderID'])) {
        throw new RuntimeException('Travel order not found.');
    }

    EmployeeDocumentRepo::deleteTravelOrder((string) $p['TravelOrderID']);
    return ['TravelOrderID' => $p['TravelOrderID']];
}

/* ==========================================================================
 * Work shift - versioned
 * ======================================================================== */

/** Current shift versions, or every version when IncludeSuperseded is set. */
function apiListWorkShifts(array $p, array $user): array
{
    return WorkShiftRepo::listAll($p);
}

/** Every version of one shift code, so a historical rate can be explained. */
function apiGetWorkShiftHistory(array $p, array $user): array
{
    requireFields($p, ['ShiftCode']);
    return WorkShiftRepo::versionsOf((string) $p['ShiftCode']);
}

/**
 * Creates a shift, or a new version of one.
 *
 * There is no "update" here and that is the whole design. Editing the times of
 * a shift in place would rewrite what "late" meant on days already paid, and
 * Phase 4 resolves a DTR row against the version in force on its date - which
 * only exists while the earlier versions do.
 */
function apiSaveWorkShift(array $p, array $user): array
{
    requireFields($p, ['ShiftCode', 'EffectiveFrom']);

    $shiftCode = strtoupper(trim((string) $p['ShiftCode']));
    $effectiveFrom = (string) nullableDate($p['EffectiveFrom'], 'Effective from');

    $record = [
        'ShiftName' => (string) ($p['ShiftName'] ?? ''),
        'TimeIn' => nullableTime($p['TimeIn'] ?? null, 'Time in'),
        'TimeOut' => nullableTime($p['TimeOut'] ?? null, 'Time out'),
        'BreakMinutes' => (int) ($p['BreakMinutes'] ?? 0),
        'RestDays' => restDayList($p['RestDays'] ?? ''),
        'NightDiffFrom' => nullableTime($p['NightDiffFrom'] ?? null, 'Night differential from'),
        'NightDiffTo' => nullableTime($p['NightDiffTo'] ?? null, 'Night differential to'),
        'EffectiveTo' => nullableDate($p['EffectiveTo'] ?? null, 'Effective to'),
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
        'CreatedBy' => $user['Email'],
    ];

    if (WorkShiftRepo::currentVersion($shiftCode) === null) {
        $record['EffectiveFrom'] = $effectiveFrom;
        WorkShiftRepo::createFirstVersion(newId('SHFT'), $shiftCode, $record);

        return ['ShiftCode' => $shiftCode, 'VersionNo' => 1, 'Created' => true];
    }

    $created = WorkShiftRepo::supersede($shiftCode, newId('SHFT'), $record, $effectiveFrom);

    return ['ShiftCode' => $shiftCode, 'VersionNo' => $created['VersionNo'], 'Created' => false];
}

/**
 * Rest days as a canonical comma-separated ISO weekday list.
 *
 * Accepts an array or a string so the SPA can send either. Validated because
 * Phase 4 reads this to decide whether a worked day was a rest day, and a typo
 * that silently becomes "no rest days" pays somebody a plain rate for a Sunday.
 */
function restDayList(mixed $raw): string
{
    if (is_string($raw)) $raw = array_filter(array_map('trim', explode(',', $raw)));
    if (!is_array($raw)) throw new RuntimeException('Rest days must be a list of weekday numbers.');

    $days = [];
    foreach ($raw as $day) {
        $day = (int) $day;
        if ($day < 1 || $day > 7) {
            throw new RuntimeException(
                'Rest days are weekday numbers from 1 (Monday) to 7 (Sunday).');
        }
        $days[$day] = true;
    }
    ksort($days);
    return implode(',', array_keys($days));
}

/* ==========================================================================
 * Contracts
 * ======================================================================== */

/** Contracts the caller may see. */
function apiListContracts(array $p, array $user): array
{
    return ContractRepo::listScoped($user, $p);
}

/** Every contract for one employee, so a rate change can be explained. */
function apiGetContractHistory(array $p, array $user): array
{
    requireFields($p, ['EmployeeID']);
    requireEmployeeInScope($user, (string) $p['EmployeeID'], 'contract history');

    return ContractRepo::historyFor((string) $p['EmployeeID']);
}

/**
 * Records an engagement: the first one, or a renewal.
 *
 * A renewal adds a row and closes the previous one. It never edits the rate in
 * place - that is the overwrite 0005 exists to prevent, and Phase 6's "daily
 * rate != contract rate" rule has nothing to compare against once history is
 * gone.
 */
function apiSaveContract(array $p, array $user): array
{
    requireFields($p, ['EmployeeID', 'Rate', 'StartDate']);

    $employeeId = (string) $p['EmployeeID'];
    requireEmployeeInScope($user, $employeeId, 'a contract');

    $startDate = (string) nullableDate($p['StartDate'], 'Start date');
    $endDate = nullableDate($p['EndDate'] ?? null, 'End date');
    if ($endDate && $endDate < $startDate) {
        throw new RuntimeException('The contract ends before it starts.');
    }

    $rate = round2($p['Rate']);
    if ($rate <= 0) throw new RuntimeException('The contract rate must be more than zero.');

    $record = [
        'EmployeeID' => $employeeId,
        'TypeCode' => trim((string) ($p['TypeCode'] ?? '')) ?: null,
        'RateBasis' => (string) ($p['RateBasis'] ?? 'Daily'),
        'Rate' => $rate,
        'EndDate' => $endDate,
        'Status' => (string) ($p['Status'] ?? 'Active'),
        'Remarks' => (string) ($p['Remarks'] ?? ''),
    ];

    $history = ContractRepo::historyFor($employeeId);

    if (!$history) {
        $contractId = newId('CON');
        ContractRepo::create($contractId, array_merge($record, ['StartDate' => $startDate]));

        return ['ContractID' => $contractId, 'Renewed' => false];
    }

    $contractId = newId('CON');
    ContractRepo::renew($employeeId, $contractId, $record, $startDate);

    return ['ContractID' => $contractId, 'Renewed' => true, 'Superseded' => count($history)];
}

/** Corrects a contract's remarks or status; never its rate or dates. */
function apiAmendContract(array $p, array $user): array
{
    requireFields($p, ['ContractID']);

    if (!ContractRepo::findScoped($user, $p['ContractID'])) {
        throw new RuntimeException('Contract not found.');
    }

    $changed = ContractRepo::amend((string) $p['ContractID'], $p);
    if ($changed === 0) {
        throw new RuntimeException(
            'Only the remarks and status of a contract can be corrected. To change a '
            . 'rate or a date, record the correct engagement - the old one has to stay '
            . 'so that payrolls prepared against it can still be reconciled.');
    }

    return ['ContractID' => $p['ContractID']];
}

/* ==========================================================================
 * Shared validation
 * ======================================================================== */

/**
 * Refuses when the employee is outside the caller's scope.
 *
 * Uses the same "not found" wording for absent and out of scope, so a caller
 * cannot confirm another office's employee exists by attaching a document to
 * them.
 */
function requireEmployeeInScope(array $user, string $employeeId, string $what): void
{
    if (EmployeeRepo::findScoped($user, $employeeId)) return;

    throw new RuntimeException(
        "Employee not found, so $what cannot be recorded for them.");
}

/**
 * A date column's value, or null.
 *
 * Validated rather than passed through: STRICT_ALL_TABLES rejects a malformed
 * date with a PDO error nobody in a timekeeping office can act on, and the
 * message this throws instead names the field.
 */
function nullableDate(mixed $value, string $label): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') return null;

    $parsed = date_create_immutable($value);
    if ($parsed === false) throw new RuntimeException("$label is not a date the system can read.");

    return $parsed->format('Y-m-d');
}

/** A time column's value, or null. */
function nullableTime(mixed $value, string $label): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') return null;

    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
        throw new RuntimeException("$label must be a time like 08:00 or 17:30.");
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}
