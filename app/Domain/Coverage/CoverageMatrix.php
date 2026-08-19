<?php
/**
 * ============================================================================
 * CoverageMatrix - employee x day, and what justifies each cell.
 *
 * The screen this produces has one job: make an unjustified day impossible to
 * miss. Everything else about it is arrangement.
 *
 * Pure: no DB::, no $_SESSION, no clock, no file I/O. The caller loads the day
 * rows, the attachments, the travel orders, the exemptions and the resolved
 * day types; this decides what colour each cell is. That is what makes the
 * exit gate - "a red cell corresponds to a real, verifiable gap in a test
 * dataset" - a fixture test rather than somebody squinting at a screen.
 *
 * WHAT MAKES A CELL RED
 * Only one thing: the employee was recorded as working, the record was keyed
 * by hand rather than read from a device, and nothing on file says why. Every
 * other state is a reason, and the reasons are checked in a fixed order so
 * that a day with both a travel order and an exemption reports the same thing
 * every time rather than whichever query returned first.
 *
 * WHAT IS DELIBERATELY NOT RED
 * A biometric day needs no justification - the device is the evidence. A rest
 * day, a holiday not worked and a day with no record at all are not gaps
 * either: nobody claimed anything, so there is nothing to justify. Colouring
 * those red would bury the handful of real findings under a wall of noise,
 * which is the failure mode this screen exists to avoid.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Coverage;

final class CoverageMatrix
{
    /**
     * Cell states, in the order they are checked.
     *
     * The order IS the precedence. A manual entry on a day that also has a
     * travel order reports TRAVEL_ORDER, because the travel order is why the
     * punch is missing and saying so is more useful than saying "manual".
     */
    public const BIOMETRIC = 'biometric';
    public const TRAVEL_ORDER = 'travel_order';
    public const BIO_EXEMPTION = 'bio_exemption';
    public const ATTACHMENT = 'attachment';
    public const HOLIDAY = 'holiday';
    public const REST_DAY = 'rest_day';
    public const ABSENT = 'absent';
    public const NO_RECORD = 'no_record';
    public const UNJUSTIFIED = 'unjustified';

    /** The one state that is a finding. */
    public const RED = self::UNJUSTIFIED;

    /**
     * Builds the matrix.
     *
     * @param string[] $employeeIds
     * @param string[] $dates YYYY-MM-DD
     * @param array<int, array<string, mixed>> $days DtrDays rows
     * @param array<int, array<string, mixed>> $coverage AttachmentCoverage rows
     * @param array<int, array<string, mixed>> $travelOrders TravelOrders rows
     * @param array<int, array<string, mixed>> $exemptions BioExemptions rows
     * @param array<string, array<string, mixed>> $dayTypes date => HolidayResolver answer
     * @param array<string, bool> $restDays "employeeId|date" => true
     * @return array{cells: array<string, array<string, array<string, mixed>>>,
     *               gaps: array<int, array<string, string>>,
     *               counts: array<string, int>}
     */
    public static function build(
        array $employeeIds,
        array $dates,
        array $days,
        array $coverage = [],
        array $travelOrders = [],
        array $exemptions = [],
        array $dayTypes = [],
        array $restDays = []
    ): array {
        $dayByKey = self::index($days, 'EmployeeID', 'WorkDate');
        $coveredByKey = self::attachmentIndex($coverage);

        $cells = [];
        $gaps = [];
        $counts = [];

        foreach ($employeeIds as $employeeId) {
            foreach ($dates as $date) {
                $cell = self::classify(
                    $employeeId, $date,
                    $dayByKey["$employeeId|$date"] ?? null,
                    $coveredByKey["$employeeId|$date"] ?? [],
                    $travelOrders, $exemptions,
                    $dayTypes[$date] ?? null,
                    !empty($restDays["$employeeId|$date"]));

                $cells[$employeeId][$date] = $cell;
                $counts[$cell['state']] = ($counts[$cell['state']] ?? 0) + 1;

                if ($cell['state'] === self::UNJUSTIFIED) {
                    $gaps[] = ['EmployeeID' => $employeeId, 'Date' => $date,
                        'reason' => $cell['reason']];
                }
            }
        }

        return ['cells' => $cells, 'gaps' => $gaps, 'counts' => $counts];
    }

    /**
     * One cell.
     *
     * @param array<string, mixed>|null $day the DtrDays row, if any
     * @param string[] $attachmentIds attachments bound to this employee and date
     * @param array<string, mixed>|null $dayType the resolved holiday answer
     * @return array<string, mixed>
     */
    public static function classify(
        string $employeeId,
        string $date,
        ?array $day,
        array $attachmentIds,
        array $travelOrders,
        array $exemptions,
        ?array $dayType,
        bool $isRestDay
    ): array {
        $worked = $day !== null && (float) ($day['HoursWorked'] ?? 0) > 0;
        $source = (string) ($day['Source'] ?? '');

        // A device record is its own evidence. Checked first because it is by
        // far the commonest cell and needs no further lookups.
        if ($worked && $source !== '' && $source !== 'Manual') {
            return self::cell(self::BIOMETRIC, 'Read from the biometric device.');
        }

        // Nothing was claimed. Not a gap - there is nothing to justify.
        if ($day === null) {
            if ($isRestDay) return self::cell(self::REST_DAY, 'Rest day under the shift in force.');
            if (self::isNonWorkingHoliday($dayType)) {
                return self::cell(self::HOLIDAY, self::holidayReason($dayType));
            }
            return self::cell(self::NO_RECORD, 'No day record was keyed.');
        }

        if (!empty($day['IsAbsent'])) {
            return self::cell(self::ABSENT, 'Recorded as absent.');
        }

        if (!$worked) {
            if ($isRestDay) return self::cell(self::REST_DAY, 'Rest day under the shift in force.');
            if (self::isNonWorkingHoliday($dayType)) {
                return self::cell(self::HOLIDAY, self::holidayReason($dayType));
            }
            return self::cell(self::NO_RECORD, 'No hours were recorded for this day.');
        }

        // From here the employee is recorded as having worked, by hand. The
        // question is whether anything on file says why the punch is missing.
        $travel = self::travelOrderCovering($travelOrders, $employeeId, $date);
        if ($travel !== null) {
            return self::cell(self::TRAVEL_ORDER,
                'Travel order ' . $travel['TravelOrderNo'] . ' to ' . $travel['Destination'] . '.',
                ['TravelOrderID' => $travel['TravelOrderID']]);
        }

        $exemption = self::exemptionCovering($exemptions, $employeeId, $date);
        if ($exemption !== null) {
            return self::cell(self::BIO_EXEMPTION,
                'Bio exemption: ' . ($exemption['ReasonCode'] ?: $exemption['Reason']) . '.',
                ['ExemptionID' => $exemption['ExemptionID']]);
        }

        if ($attachmentIds) {
            return self::cell(self::ATTACHMENT,
                count($attachmentIds) . ' attachment(s) bound to this date.',
                ['AttachmentIDs' => $attachmentIds]);
        }

        return self::cell(self::UNJUSTIFIED,
            'Hours were keyed by hand and nothing on file explains the missing punch.');
    }

    /**
     * Whether a resolved day type means the day was not a working one.
     *
     * SpecialWorking is deliberately excluded: it is an ordinary working day
     * with a ceremonial name, and treating it as a holiday would excuse a
     * manual entry that still needs justifying.
     *
     * @param array<string, mixed>|null $dayType
     */
    private static function isNonWorkingHoliday(?array $dayType): bool
    {
        if ($dayType === null) return false;

        return in_array((string) ($dayType['day_type'] ?? ''),
            ['RegularHoliday', 'SpecialNonWorking', 'LocalHoliday', 'WorkSuspension'], true);
    }

    /** @param array<string, mixed>|null $dayType */
    private static function holidayReason(?array $dayType): string
    {
        $name = trim((string) ($dayType['holiday_name'] ?? ''));
        $type = (string) ($dayType['day_type'] ?? 'Holiday');

        return $name === '' ? $type . '.' : "$type - $name.";
    }

    /**
     * A travel order covering the employee on the date.
     *
     * An order with no return date covers only the departure day; treating a
     * missing return as open-ended would excuse every day after it forever.
     *
     * @return array<string, mixed>|null
     */
    private static function travelOrderCovering(array $travelOrders, string $employeeId, string $date): ?array
    {
        foreach ($travelOrders as $order) {
            if ((string) ($order['EmployeeID'] ?? '') !== $employeeId) continue;
            if (($order['Status'] ?? 'Active') !== 'Active') continue;

            $from = (string) ($order['DepartDate'] ?? '');
            if ($from === '') continue;

            $to = trim((string) ($order['ReturnDate'] ?? '')) ?: $from;
            if ($date >= $from && $date <= $to) return $order;
        }
        return null;
    }

    /**
     * A bio exemption covering the employee on the date.
     *
     * An open-ended exemption (no ValidTo) does run indefinitely - unlike a
     * travel order, that is what an exemption for a permanent condition means.
     *
     * @return array<string, mixed>|null
     */
    private static function exemptionCovering(array $exemptions, string $employeeId, string $date): ?array
    {
        foreach ($exemptions as $exemption) {
            if ((string) ($exemption['EmployeeID'] ?? '') !== $employeeId) continue;
            if (($exemption['Status'] ?? 'Active') !== 'Active') continue;

            $from = trim((string) ($exemption['ValidFrom'] ?? ''));
            $to = trim((string) ($exemption['ValidTo'] ?? ''));

            if ($from !== '' && $date < $from) continue;
            if ($to !== '' && $date > $to) continue;

            return $exemption;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function cell(string $state, string $reason, array $extra = []): array
    {
        return array_merge(['state' => $state, 'reason' => $reason], $extra);
    }

    /**
     * Rows keyed by "employee|date".
     *
     * @return array<string, array<string, mixed>>
     */
    private static function index(array $rows, string $employeeKey, string $dateKey): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[((string) ($row[$employeeKey] ?? '')) . '|' . ((string) ($row[$dateKey] ?? ''))] = $row;
        }
        return $out;
    }

    /**
     * Attachment ids keyed by "employee|date".
     *
     * @return array<string, string[]>
     */
    private static function attachmentIndex(array $coverage): array
    {
        $out = [];
        foreach ($coverage as $row) {
            $key = ((string) ($row['EmployeeID'] ?? '')) . '|' . ((string) ($row['CoveredDate'] ?? ''));
            $out[$key][] = (string) ($row['AttachmentID'] ?? '');
        }
        return $out;
    }
}
