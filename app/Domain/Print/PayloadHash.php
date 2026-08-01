<?php
/**
 * ============================================================================
 * PayloadHash - what an Official print is provably tied to.
 *
 * Pure: no DB::, no clock, no file I/O. Given the same three inputs it always
 * returns the same digest, which is the entire mechanism - the imperative
 * shell (app/Payroll.php, app/PrintDoc.php) is responsible for gathering those
 * inputs the same way at approval time and at print time.
 *
 * WHAT GOES IN, AND WHY THOSE THREE
 *   lines               PayrollDetails rows: employees, rates, days, hours,
 *                        deductions, net. This alone is everything an Official
 *                        print actually renders.
 *   attachmentCoverage   AttachmentRepo::coverageFor() rows for the payroll's
 *                        employees over its period. The evidence Phase 5 bound
 *                        to those lines can be deleted or edited without ever
 *                        touching PayrollDetails, which would otherwise make a
 *                        payroll's justification silently vanish after
 *                        approval while its printed figures stayed identical.
 *   holidays             HolidayRepo::holidaysBetween() rows for the period.
 *                        A holiday declared or withdrawn after approval
 *                        changes whether the pre-audit's own findings would
 *                        still hold, even though it does not retroactively
 *                        change the frozen numbers on PayrollDetails.
 *
 * shift_versions FROM THE PLAN IS DELIBERATELY NOT HERE. See migration 0024's
 * header for why: there is no per-employee shift assignment to derive it from
 * without a caller supplying one, and hashing a caller-supplied value would
 * make the hash depend on who asked rather than what the payroll is.
 *
 * CANONICALISATION MATTERS MORE THAN THE ALGORITHM. A hash that changed
 * because PHP happened to return two columns in a different order would be a
 * false tamper alarm on every single payroll, which is worse than not having
 * one - it teaches whoever reads the alert to stop trusting it. Every array is
 * therefore recursively key-sorted before encoding.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Print;

final class PayloadHash
{
    /**
     * @param array<int, array<string, mixed>> $lines PayrollDetails rows
     * @param array<int, array<string, mixed>> $attachmentCoverage AttachmentRepo::coverageFor() rows
     * @param array<int, array<string, mixed>> $holidays HolidayRepo::holidaysBetween() rows
     */
    public static function compute(array $lines, array $attachmentCoverage, array $holidays): string
    {
        $payload = [
            'lines' => array_map([self::class, 'normalizeLine'], self::sortRows($lines, 'LineNo')),
            'attachments' => array_map(
                [self::class, 'normalizeCoverage'],
                self::sortRows($attachmentCoverage, 'AttachmentID', 'EmployeeID', 'CoveredDate')
            ),
            'holidays' => array_map([self::class, 'normalizeHoliday'], self::sortRows($holidays, 'HolidayDate')),
        ];

        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * Content fields only - DetailID and PayrollNo are identity, not content,
     * and excluding them is what lets a suspension-split's clean lines (a
     * fresh DetailID, the same PayrollNo they always had) hash identically to
     * what was approved before the split.
     */
    private static function normalizeLine(array $line): array
    {
        return [
            'EmployeeID' => (string) ($line['EmployeeID'] ?? ''),
            'ChargedOfficeCode' => (string) ($line['ChargedOfficeCode'] ?? ''),
            'FunctionCode' => (string) ($line['FunctionCode'] ?? ''),
            'SalaryRate' => self::money($line['SalaryRate'] ?? 0),
            'DaysWorked' => self::money($line['DaysWorked'] ?? 0),
            'HoursWorked' => self::money($line['HoursWorked'] ?? 0),
            'OvertimeHours' => self::money($line['OvertimeHours'] ?? 0),
            'LateMinutes' => self::money($line['LateMinutes'] ?? 0),
            'UndertimeMinutes' => self::money($line['UndertimeMinutes'] ?? 0),
            'AbsentDays' => self::money($line['AbsentDays'] ?? 0),
            'GrossPay' => self::money($line['GrossPay'] ?? 0),
            'Tax' => self::money($line['Tax'] ?? 0),
            'CashAdvance' => self::money($line['CashAdvance'] ?? 0),
            'OtherDeductions' => self::money($line['OtherDeductions'] ?? 0),
            'TotalDeductions' => self::money($line['TotalDeductions'] ?? 0),
            'NetPay' => self::money($line['NetPay'] ?? 0),
        ];
    }

    private static function normalizeCoverage(array $row): array
    {
        return [
            'AttachmentID' => (string) ($row['AttachmentID'] ?? ''),
            'EmployeeID' => (string) ($row['EmployeeID'] ?? ''),
            'CoveredDate' => (string) ($row['CoveredDate'] ?? ''),
        ];
    }

    private static function normalizeHoliday(array $row): array
    {
        return [
            'HolidayDate' => (string) ($row['HolidayDate'] ?? ''),
            'DayType' => (string) ($row['DayType'] ?? ''),
            'ScopeLevel' => (string) ($row['ScopeLevel'] ?? ''),
            'ScopeCode' => (string) ($row['ScopeCode'] ?? ''),
            'StartTime' => (string) ($row['StartTime'] ?? ''),
            'EndTime' => (string) ($row['EndTime'] ?? ''),
            'Status' => (string) ($row['Status'] ?? ''),
        ];
    }

    /** Fixed 2-decimal string so 0 and 0.00 and "0" all hash the same. */
    private static function money(mixed $n): string
    {
        return number_format((float) (is_string($n) ? str_replace(',', '', $n) : $n), 2, '.', '');
    }

    /** @return array<int, array<string, mixed>> */
    private static function sortRows(array $rows, string ...$keys): array
    {
        usort($rows, function (array $a, array $b) use ($keys) {
            foreach ($keys as $key) {
                $cmp = (string) ($a[$key] ?? '') <=> (string) ($b[$key] ?? '');
                if ($cmp !== 0) return $cmp;
            }
            return 0;
        });
        return array_values($rows);
    }

    /** Recursively key-sorted JSON, so field order never affects the digest. */
    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            if (!$isList) ksort($value);
            $parts = array_map(
                fn($k, $v) => ($isList ? '' : json_encode((string) $k) . ':') . self::canonicalJson($v),
                array_keys($value), $value
            );
            return $isList ? '[' . implode(',', $parts) . ']' : '{' . implode(',', $parts) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }
}
