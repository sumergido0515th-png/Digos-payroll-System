<?php
/**
 * ============================================================================
 * Attachments.php - Phase 5. Evidence, bound to the dates it justifies.
 *
 * TWO IDEAS, AND THEY ARE BOTH ABOUT NOT DEFERRING THINGS.
 *
 * 1. COVERAGE IS CAPTURED AT UPLOAD TIME. The person uploading a scanned memo
 *    knows which employees and which dates it is about; the person printing a
 *    payroll three weeks later is guessing. Asking at upload is one extra
 *    field then and a definite answer forever after - which is what Phase 6
 *    rule #1 reads.
 *
 * 2. THE HASH IS CHECKED BEFORE THE FILE IS WRITTEN. The same scanned document
 *    uploaded twice under two control numbers makes one authority look like
 *    two pieces of evidence. The database's UNIQUE key is the guarantee; this
 *    check exists so the message names what is being duplicated instead of
 *    surfacing a constraint violation.
 *
 * Files are written to attachments/, outside the web root, next to backups/.
 * They are payroll justifications - scanned memoranda, medical certificates -
 * and a guessable URL under public/ would serve them to anyone who asked.
 * ============================================================================
 */

declare(strict_types=1);

use Digos\Domain\Coverage\CoverageMatrix;
use Digos\Domain\Resolver\HolidayResolver;
use Digos\Domain\Resolver\ShiftResolver;
use Digos\Repo\AttachmentRepo;
use Digos\Repo\DtrRepo;
use Digos\Repo\EmployeeDocumentRepo;
use Digos\Repo\EmployeeRepo;
use Digos\Repo\HolidayRepo;
use Digos\Repo\ReferenceRepo;
use Digos\Repo\WorkShiftRepo;

/** What an attachment can evidence. */
const ATTACHMENT_DOCUMENT_TYPES = ['Memorandum', 'BioExemption', 'TravelOrder', 'Leave', 'Other'];

/** 10 MB. A scan of a signed memo is well under this; a video is not evidence. */
const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

/**
 * Accepted types, by the file's own magic bytes.
 *
 * The browser's declared type and the file name are both attacker-controlled,
 * so neither decides. PDF and images only: these are scans of paper.
 */
const ATTACHMENT_SIGNATURES = [
    '%PDF-' => ['application/pdf', 'pdf'],
    "\xFF\xD8\xFF" => ['image/jpeg', 'jpg'],
    "\x89PNG\r\n\x1a\n" => ['image/png', 'png'],
];

/* ==========================================================================
 * Reading
 * ======================================================================== */

/** Attachments touching employees the caller may see. */
function apiListAttachments(array $p, array $user): array
{
    $rows = AttachmentRepo::listScoped($user, $p);

    return array_map(function (array $a) {
        $a['Url'] = 'attachment.php?id=' . urlencode($a['AttachmentID']);
        $a['SizeKb'] = (int) round($a['SizeBytes'] / 1024);
        return $a;
    }, $rows);
}

/** One attachment with the employees and dates it covers. */
function apiGetAttachment(array $p, array $user): array
{
    requireFields($p, ['AttachmentID']);

    $attachment = AttachmentRepo::findScoped($user, (string) $p['AttachmentID']);
    if (!$attachment) throw new RuntimeException('Attachment not found.');

    $attachment['Coverage'] = AttachmentRepo::coverageOf((string) $p['AttachmentID']);
    return $attachment;
}

/* ==========================================================================
 * Uploading
 * ======================================================================== */

/**
 * Stores an uploaded file and binds it to the dates it justifies.
 *
 * The order matters: validate, hash, check for a duplicate, and only then
 * write to disk. Writing first would leave an orphan file behind every
 * rejected duplicate.
 */
function apiUploadAttachment(array $p, array $user): array
{
    requireFields($p, ['FileName', 'data', 'ControlNo']);

    $binary = attachmentBytes((string) $p['data']);
    [$mime, $extension] = attachmentTypeOf($binary);

    $sha256 = hash('sha256', $binary);

    // Before anything is written. The duplicate is named so the person can see
    // what they are about to re-file rather than being told "no".
    $existing = AttachmentRepo::findByHash($sha256);
    if ($existing !== null) {
        throw new RuntimeException(sprintf(
            'This is the same file as "%s" (control no. %s), already uploaded on %s. '
            . 'Filing one document twice under two control numbers makes a single '
            . 'authority look like two pieces of evidence.',
            $existing['FileName'], $existing['ControlNo'] ?: '(none)',
            fmtDate($existing['UploadedAt'])));
    }

    $documentType = (string) ($p['DocumentType'] ?? 'Other');
    if (!in_array($documentType, ATTACHMENT_DOCUMENT_TYPES, true)) {
        throw new RuntimeException('Document type must be one of: '
            . implode(', ', ATTACHMENT_DOCUMENT_TYPES) . '.');
    }

    $coversFrom = nullableDate($p['CoversFrom'] ?? null, 'Covers from');
    $coversTo = nullableDate($p['CoversTo'] ?? null, 'Covers to');
    if ($coversFrom && $coversTo && $coversTo < $coversFrom) {
        throw new RuntimeException('The coverage period ends before it starts.');
    }

    $coverage = attachmentCoverageRows($p, $user, $coversFrom, $coversTo);
    if (!$coverage) {
        throw new RuntimeException(
            'Name at least one employee and one date this attachment covers. An '
            . 'attachment bound to nothing justifies nothing, and the whole point of '
            . 'asking now is that nobody has to guess later.');
    }

    $attachmentId = newId('ATT');
    $storedName = $attachmentId . '.' . $extension;

    if (!is_dir(ATTACHMENT_DIR) && !mkdir(ATTACHMENT_DIR, 0775, true) && !is_dir(ATTACHMENT_DIR)) {
        throw new RuntimeException('Cannot create the attachments folder. Check its permissions.');
    }
    if (file_put_contents(ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $storedName, $binary) === false) {
        throw new RuntimeException('Cannot save the file. Check the attachments folder permissions.');
    }

    try {
        AttachmentRepo::save($attachmentId, [
            'FileName' => mb_substr((string) $p['FileName'], 0, 255),
            'StoredName' => $storedName,
            'MimeType' => $mime,
            'SizeBytes' => strlen($binary),
            'Sha256' => $sha256,
            'ControlNo' => trim((string) $p['ControlNo']),
            'DocumentType' => $documentType,
            'DocumentID' => trim((string) ($p['DocumentID'] ?? '')) ?: null,
            'CoversFrom' => $coversFrom,
            'CoversTo' => $coversTo,
            'Status' => 'Active',
            'Remarks' => (string) ($p['Remarks'] ?? ''),
            'UploadedBy' => $user['Email'],
        ], $coverage);
    } catch (Throwable $e) {
        // The row is the record; the file without it is litter. Removing it
        // keeps a failed upload from leaving bytes on disk that nothing
        // references and no cleanup ever visits.
        @unlink(ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $storedName);
        throw $e;
    }

    return [
        'AttachmentID' => $attachmentId,
        'Sha256' => $sha256,
        'covered' => count($coverage),
    ];
}

/** Removes an attachment and its file. */
function apiDeleteAttachment(array $p, array $user): array
{
    requireFields($p, ['AttachmentID']);

    $attachment = AttachmentRepo::findScoped($user, (string) $p['AttachmentID']);
    if (!$attachment) throw new RuntimeException('Attachment not found.');

    AttachmentRepo::delete((string) $p['AttachmentID']);

    // basename() because StoredName is generated, but a tampered row must not
    // be able to reach outside the folder.
    @unlink(ATTACHMENT_DIR . DIRECTORY_SEPARATOR . basename((string) $attachment['StoredName']));

    return ['AttachmentID' => $p['AttachmentID']];
}

/* ==========================================================================
 * The coverage matrix
 * ======================================================================== */

/**
 * employee x day for a period, with what justifies each cell.
 *
 * Everything the matrix needs is loaded in one pass and handed to the pure
 * function - the day rows, the attachments, the travel orders, the exemptions,
 * the resolved day types and the shift-derived rest days. A per-cell lookup
 * would be one query per employee per date.
 */
function apiGetCoverageMatrix(array $p, array $user): array
{
    requireFields($p, ['PeriodID']);

    $period = ReferenceRepo::period((string) $p['PeriodID']);
    if (!$period) throw new RuntimeException('That payroll period does not exist.');

    $from = (string) $period['StartDate'];
    $to = (string) $period['EndDate'];
    $dates = dtrDateRange($from, $to);

    $employees = EmployeeRepo::listScoped($user,
        ['Status' => 'Active'] + array_intersect_key($p, array_flip(['OfficeCode', 'search'])));
    $rows = $employees['rows'] ?? $employees;
    $employeeIds = array_column($rows, 'EmployeeID');

    // Day types are resolved once per date, not once per cell: the holiday
    // calendar does not vary by employee, and the JO/COS divergence that does
    // is about pay rather than about whether the day needs justifying.
    $holidays = HolidayRepo::holidaysBetween($from, $to);
    $dayTypes = [];
    foreach ($dates as $date) {
        $declaration = HolidayResolver::mostSpecificDeclaration(
            $holidays, $date, officeScopeFor((string) ($p['OfficeCode'] ?? '')));

        $dayTypes[$date] = [
            'day_type' => $declaration === null
                ? HolidayResolver::DEFAULT_DAY_TYPE : (string) $declaration['DayType'],
            'holiday_name' => $declaration === null ? '' : (string) ($declaration['HolidayName'] ?? ''),
        ];
    }

    $matrix = CoverageMatrix::build(
        $employeeIds,
        $dates,
        DtrRepo::daysForPeriodScoped($user, (string) $p['PeriodID']),
        AttachmentRepo::coverageFor($employeeIds, $from, $to),
        EmployeeDocumentRepo::listTravelOrdersScoped($user),
        EmployeeDocumentRepo::listExemptionsScoped($user),
        $dayTypes,
        coverageRestDays($employeeIds, $dates, (string) ($p['ShiftCode'] ?? '')));

    return [
        'period' => $period,
        'dates' => $dates,
        'employees' => array_map(fn(array $e) => [
            'EmployeeID' => $e['EmployeeID'],
            'EmployeeName' => fullName($e),
            'OfficeCode' => $e['OfficeCode'],
        ], $rows),
        'cells' => $matrix['cells'],
        'gaps' => $matrix['gaps'],
        'counts' => $matrix['counts'],
    ];
}

/**
 * Rest days per employee per date, from the shift in force on each date.
 *
 * One shift for everybody at this phase: employees have no shift assignment
 * column yet, so the caller names one and it applies to all. When assignment
 * lands this becomes a per-employee lookup and nothing else here changes -
 * which is why the key is already "employee|date".
 *
 * @param string[] $employeeIds
 * @param string[] $dates
 * @return array<string, bool>
 */
function coverageRestDays(array $employeeIds, array $dates, string $shiftCode): array
{
    if ($shiftCode === '') return [];

    $versions = WorkShiftRepo::versionsOf($shiftCode);
    if (!$versions) return [];

    $restByDate = [];
    foreach ($dates as $date) {
        $restByDate[$date] = ShiftResolver::isRestDay(
            ShiftResolver::versionOn($versions, $date), $date);
    }

    $out = [];
    foreach ($employeeIds as $employeeId) {
        foreach ($restByDate as $date => $isRest) {
            if ($isRest) $out["$employeeId|$date"] = true;
        }
    }
    return $out;
}

/* ==========================================================================
 * Shared
 * ======================================================================== */

/**
 * The coverage rows from the payload.
 *
 * Employees are checked against scope one by one, for the same reason
 * memorandum coverage is: binding an attachment to somebody else's employee
 * would make evidence a way to write into a scope you cannot read.
 *
 * Dates come either as an explicit list or as the CoversFrom..CoversTo range.
 * The explicit list wins when both are given - it is the more specific
 * statement, and a range is a convenience for the common case of a continuous
 * absence.
 *
 * @return array<int, array{EmployeeID: string, CoveredDate: string}>
 */
function attachmentCoverageRows(array $p, array $user, ?string $from, ?string $to): array
{
    $employeeIds = $p['EmployeeIDs'] ?? [];
    if (is_string($employeeIds)) {
        $employeeIds = array_filter(array_map('trim', explode(',', $employeeIds)));
    }
    if (!is_array($employeeIds)) throw new RuntimeException('Covered employees must be a list.');

    $dates = $p['CoveredDates'] ?? [];
    if (is_string($dates)) $dates = array_filter(array_map('trim', explode(',', $dates)));
    if (!is_array($dates)) throw new RuntimeException('Covered dates must be a list.');

    if (!$dates && $from !== null) {
        $dates = dtrDateRange($from, $to ?? $from);
    }

    $rows = [];
    foreach ($employeeIds as $employeeId) {
        $employeeId = trim((string) $employeeId);
        if ($employeeId === '') continue;

        requireEmployeeInScope($user, $employeeId, 'an attachment');

        foreach ($dates as $date) {
            $date = (string) nullableDate($date, 'Covered date');
            $rows[] = ['EmployeeID' => $employeeId, 'CoveredDate' => $date];
        }
    }

    return $rows;
}

/** The decoded bytes of an uploaded data: URL. */
function attachmentBytes(string $data): string
{
    if (!preg_match('~^data:[\w./+-]+;base64,~', $data, $m)) {
        throw new RuntimeException('The file could not be read. Please choose it again.');
    }

    $binary = base64_decode(substr($data, strlen($m[0])), true);
    if ($binary === false || $binary === '') {
        throw new RuntimeException('The file could not be read. Please choose it again.');
    }
    if (strlen($binary) > ATTACHMENT_MAX_BYTES) {
        throw new RuntimeException('That file is larger than '
            . (ATTACHMENT_MAX_BYTES / 1024 / 1024) . ' MB. Scan it at a lower resolution.');
    }

    return $binary;
}

/**
 * The file's type, from its own leading bytes.
 *
 * Never from the declared MIME type or the extension: both come from the
 * client. A file that says it is a PDF and begins with something else is not
 * one, and storing it under a .pdf name would hand the next reader whatever it
 * actually is.
 *
 * @return array{0: string, 1: string} [mime, extension]
 */
function attachmentTypeOf(string $binary): array
{
    foreach (ATTACHMENT_SIGNATURES as $signature => $type) {
        if (str_starts_with($binary, $signature)) return $type;
    }

    throw new RuntimeException(
        'Only PDF, JPG and PNG files can be attached. These are scans of paper - '
        . 'if the file is something else, print it and scan it.');
}
