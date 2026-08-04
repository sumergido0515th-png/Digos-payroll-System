<?php
/**
 * ============================================================================
 * EntitySpec.php - What may be imported, under which field names, and how a
 * cell of text becomes a value the save functions accept.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Import;

use RuntimeException;

/**
 * The catalogue of importable record types.
 *
 * Master data only, deliberately. Payroll itself is not here: a payroll line's
 * money is computed by `computeLine()` from rate, days and deductions, and
 * accepting totals from a spreadsheet would put numbers on a voucher that this
 * system never derived - which is the whole failure the pre-audit layer exists
 * to catch. `DtrDays` is not here either, because `apiImportBiometricLogs`
 * already owns that table and refuses to overwrite a hand-keyed day; a second
 * way in would quietly undo that rule.
 *
 * Each entry names the existing api* function that performs the write. Nothing
 * in the importer inserts a row itself - every imported record goes through the
 * same validation, the same scope check and the same audit entry as one typed
 * into the form, so an import cannot become a way around any of them.
 *
 * Pure: this file describes and converts, and never touches the database.
 */
final class EntitySpec
{
    /**
     * @return array<string,array{
     *     label:string, save:string, permission:string, key:string,
     *     required:string[], fields:array<string,string[]>, defaults:array<string,string>
     * }>
     */
    public static function all(): array
    {
        return [
            'offices' => [
                'label' => 'Offices',
                'save' => 'apiSaveOffice',
                'permission' => 'office.edit',
                'key' => 'OfficeCode',
                'required' => ['OfficeCode', 'OfficeName'],
                'defaults' => ['Status' => 'Active'],
                'fields' => [
                    'OfficeCode' => ['Office Code', 'Code', 'Office'],
                    'OfficeName' => ['Office Name', 'Name', 'Description'],
                    'Department' => ['Dept', 'Department Name'],
                    'Division' => [],
                    'Function' => ['Function Name', 'PPA', 'Function/PPA'],
                    'FunctionCode' => ['Function Code', 'PPA Code'],
                    'ParentOfficeCode' => ['Parent Office', 'Parent Office Code', 'Parent'],
                    'OfficeHead' => ['Head', 'Office Head', 'Head of Office'],
                    'Status' => ['Active'],
                ],
            ],

            'departments' => [
                'label' => 'Departments',
                'save' => 'apiSaveDepartment',
                'permission' => 'office.edit',
                'key' => 'DeptCode',
                'required' => ['DeptCode', 'DeptName'],
                'defaults' => ['Status' => 'Active'],
                'fields' => [
                    'DeptCode' => ['Department Code', 'Dept Code', 'Code'],
                    'DeptName' => ['Department Name', 'Dept Name', 'Name', 'Department'],
                    'OfficeCode' => ['Office Code', 'Office'],
                    'ParentDeptCode' => ['Parent Department', 'Parent Dept Code', 'Parent'],
                    'Head' => ['Department Head', 'Dept Head'],
                    'Status' => [],
                ],
            ],

            'functions' => [
                'label' => 'Functions / PPA',
                'save' => 'apiSaveFunction',
                'permission' => 'office.edit',
                'key' => 'FunctionCode',
                'required' => ['FunctionCode', 'FunctionName'],
                'defaults' => ['Status' => 'Active'],
                'fields' => [
                    'FunctionCode' => ['Function Code', 'PPA Code', 'Code'],
                    'FunctionName' => ['Function Name', 'PPA', 'Name', 'Fund'],
                    'Description' => ['Remarks', 'Particulars'],
                    'OwningOfficeCode' => ['Owning Office', 'Office Code', 'Office'],
                    'Status' => [],
                ],
            ],

            'timekeepers' => [
                'label' => 'Timekeepers',
                'save' => 'apiSaveTimekeeper',
                'permission' => 'timekeeper.edit',
                'key' => 'EmployeeName',
                'required' => ['EmployeeName', 'OfficeCode'],
                'defaults' => ['Status' => 'Active'],
                'fields' => [
                    'TimekeeperID' => ['Timekeeper ID', 'ID'],
                    'EmployeeName' => ['Name', 'Timekeeper', 'Timekeeper Name', 'Full Name'],
                    'OfficeCode' => ['Office Code', 'Office'],
                    'Department' => ['Dept'],
                    'Contact' => ['Contact No', 'Contact Number', 'Mobile', 'Phone'],
                    'Email' => ['Email Address', 'E-mail'],
                    'Status' => [],
                ],
            ],

            'employees' => [
                'label' => 'Employees',
                'save' => 'apiSaveEmployee',
                'permission' => 'employee.edit',
                'key' => 'EmployeeNo',
                'required' => ['LastName', 'FirstName', 'OfficeCode', 'EmploymentType', 'Position'],
                // A spreadsheet of JO/COS staff carries a daily rate and says so
                // in the heading, never a "rate basis" column - that concept
                // exists only in this system's form. Defaulting it here is what
                // lets a "Daily Rate" column map straight onto SalaryRate and
                // still derive the hourly and monthly figures correctly.
                'defaults' => ['Status' => 'Active', 'RateBasis' => 'Daily'],
                'fields' => [
                    'EmployeeID' => ['Employee ID', 'System ID'],
                    'EmployeeNo' => ['Employee No', 'Employee Number', 'Emp No', 'Item No', 'Plantilla No'],
                    'LastName' => ['Last Name', 'Surname', 'Apelyido', 'Family Name'],
                    'FirstName' => ['First Name', 'Given Name', 'Pangalan'],
                    'MiddleName' => ['Middle Name', 'Middle Initial', 'M.I.'],
                    'Suffix' => ['Name Suffix', 'Jr/Sr', 'Extension Name'],
                    'Birthdate' => ['Birth Date', 'Date of Birth', 'DOB', 'Birthday'],
                    'Gender' => ['Sex'],
                    'Address' => ['Home Address', 'Residence'],
                    'Contact' => ['Contact No', 'Contact Number', 'Mobile', 'Phone', 'Cellphone'],
                    'Email' => ['Email Address', 'E-mail'],
                    'OfficeCode' => ['Office Code', 'Office', 'Assigned Office'],
                    'Department' => ['Dept'],
                    'Division' => [],
                    'Function' => ['Function Name', 'PPA', 'Fund'],
                    'EmploymentType' => ['Employment Type', 'Nature of Appointment', 'Type', 'Appointment'],
                    'Position' => ['Position Title', 'Designation', 'Job Title'],
                    'SalaryRate' => ['Daily Rate', 'Rate', 'Salary', 'Salary Rate', 'Wage', 'Amount'],
                    'RateBasis' => ['Rate Basis', 'Basis'],
                    'DateHired' => ['Date Hired', 'Hire Date', 'Date Employed'],
                    'ContractStart' => ['Contract Start', 'Start Date', 'Effectivity From', 'From'],
                    'ContractEnd' => ['Contract End', 'End Date', 'Effectivity To', 'To'],
                    'TIN' => ['TIN No', 'Tax Identification Number'],
                    'GSIS' => ['GSIS No', 'GSIS Number', 'BP No'],
                    'PhilHealth' => ['PhilHealth No', 'PHIC', 'PhilHealth Number'],
                    'PagIBIG' => ['Pag-IBIG', 'Pag-IBIG No', 'HDMF', 'HDMF No'],
                    'CashCard' => ['Cash Card', 'Cash Card No', 'ATM', 'Account No', 'LBP Account'],
                    'SSSDeductionApproved' => ['SSS', 'SSS Deduction', 'SSS Deduction Approved'],
                    'BIRTaxPercent' => ['BIR', 'BIR Tax Percent', 'Tax Percent', 'Withholding Tax'],
                    'Status' => [],
                    'Remarks' => ['Notes', 'Comment'],
                ],
            ],

            'periods' => [
                'label' => 'Payroll Periods',
                'save' => 'apiSavePeriod',
                'permission' => 'period.edit',
                'key' => 'PayrollMonth',
                'required' => ['PayrollMonth', 'PayrollYear', 'StartDate', 'EndDate'],
                'defaults' => ['Status' => 'Open'],
                'fields' => [
                    'PeriodID' => ['Period ID', 'ID'],
                    'PayrollMonth' => ['Month', 'Payroll Month'],
                    'PayrollYear' => ['Year', 'Payroll Year'],
                    'StartDate' => ['Start Date', 'Period From', 'From'],
                    'EndDate' => ['End Date', 'Period To', 'To'],
                    'Status' => [],
                ],
            ],
        ];
    }

    /** One entity definition, or a refusal naming what is on offer. */
    public static function get(string $entity): array
    {
        $all = self::all();
        if (!isset($all[$entity])) {
            throw new RuntimeException('Unknown import type: ' . $entity
                . '. Choose one of: ' . implode(', ', array_keys($all)) . '.');
        }
        return $all[$entity];
    }

    /** Fields whose text needs converting before a save function sees it. */
    private const DATE_FIELDS = ['Birthdate', 'DateHired', 'ContractStart', 'ContractEnd',
        'StartDate', 'EndDate'];

    private const MONEY_FIELDS = ['SalaryRate', 'BIRTaxPercent'];

    private const BOOLEAN_FIELDS = ['SSSDeductionApproved'];

    /**
     * Converts one cell into the value its save function expects.
     *
     * Every conversion here either succeeds or throws - none of them fall back
     * to a default. A birthdate this could not read is a refusal naming the row,
     * not a NULL that reaches the database and quietly makes someone not a
     * senior citizen.
     */
    public static function coerce(string $field, string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';

        if (in_array($field, self::DATE_FIELDS, true)) return self::date($value, $field);
        if (in_array($field, self::MONEY_FIELDS, true)) return self::number($value, $field);
        if (in_array($field, self::BOOLEAN_FIELDS, true)) return self::boolean($value) ? '1' : '';

        if ($field === 'EmploymentType') return self::employmentType($value);
        if ($field === 'Status') return self::status($value);
        if ($field === 'Gender') return self::gender($value);
        if ($field === 'PayrollYear') {
            if (!preg_match('/^\d{4}$/', $value)) {
                throw new RuntimeException('"' . $value . '" is not a four-digit year.');
            }
            return $value;
        }

        // Codes are stored upper-case by every save function; normalising here
        // means a lookup against an office typed in lower case still matches.
        if (str_ends_with($field, 'Code')) return strtoupper($value);

        return $value;
    }

    /**
     * Parses a date into the Y-m-d the DATE columns take.
     *
     * A slashed date is read month-first, matching `fmtDate()`'s m/d/Y default
     * and therefore what this system prints everywhere else. When the first
     * number is above 12 it cannot be a month, and rather than silently
     * switching to day-first - which would mean two files with the same layout
     * importing differently - the row is refused and asked for YYYY-MM-DD. A
     * date is not worth guessing at: it decides contract validity, and Phase 6
     * refuses a payroll over an expired contract.
     */
    private static function date(string $value, string $field): string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $iso)) {
            return self::assemble((int) $iso[1], (int) $iso[2], (int) $iso[3], $value, $field);
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$#', $value, $parts)) {
            $year = (int) $parts[3];
            if ($year < 100) $year += $year < 70 ? 2000 : 1900;

            if ((int) $parts[1] > 12) {
                throw new RuntimeException('the date "' . $value . '" in ' . $field
                    . ' is ambiguous - this system reads slashed dates as month/day/year. '
                    . 'Write it as YYYY-MM-DD.');
            }
            return self::assemble($year, (int) $parts[1], (int) $parts[2], $value, $field);
        }

        // "15 March 2026" and "March 15, 2026" are unambiguous whichever way
        // round they are written, because the month is spelled out.
        $timestamp = strtotime($value);
        if ($timestamp !== false && preg_match('/[a-z]{3}/i', $value)) {
            return date('Y-m-d', $timestamp);
        }

        throw new RuntimeException('"' . $value . '" in ' . $field . ' is not a date this can read. '
            . 'Write it as YYYY-MM-DD.');
    }

    /** Rejects a date that parses but does not exist, such as 31 February. */
    private static function assemble(int $year, int $month, int $day, string $value, string $field): string
    {
        if (!checkdate($month, $day, $year)) {
            throw new RuntimeException('"' . $value . '" in ' . $field . ' is not a real date.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Strips the formatting a spreadsheet puts on money.
     *
     * "PHP 12,450.00" and "(500.00)" both arrive from real exports. Parentheses
     * are the accounting notation for a negative, and a negative rate is
     * refused rather than imported as its absolute value.
     */
    private static function number(string $value, string $field): string
    {
        $negative = (bool) preg_match('/^\(.*\)$/', $value);
        $clean = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        if ($clean === '' || !is_numeric($clean)) {
            throw new RuntimeException('"' . $value . '" in ' . $field . ' is not a number.');
        }
        if ($negative || (float) $clean < 0) {
            throw new RuntimeException('"' . $value . '" in ' . $field . ' is negative.');
        }

        return $clean;
    }

    /** The many ways a spreadsheet says yes. */
    private static function boolean(string $value): bool
    {
        return in_array(mb_strtolower($value), ['1', 'y', 'yes', 'true', 'oo', 'x', 'approved', 'active'], true);
    }

    /**
     * Maps what offices write onto the two employment types this system stores.
     *
     * Kept consistent with `employmentTypeCode()` in Master.php, which classifies
     * the same words into `EmploymentTypes` keys. Plantilla is recognised only
     * to refuse it clearly: `apiSaveEmployee` accepts Job Order and Contract of
     * Service alone, and this system is for JO/COS personnel.
     */
    private static function employmentType(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'JO', 'JOB ORDER', 'JOBORDER', 'JOB-ORDER' => 'Job Order',
            'COS', 'CONTRACT OF SERVICE', 'CONTRACT-OF-SERVICE', 'CONTRACTUAL' => 'Contract of Service',
            'PLANTILLA', 'REGULAR', 'PERMANENT' => throw new RuntimeException(
                '"' . $value . '" is a plantilla appointment. This system covers Job Order and '
                . 'Contract of Service personnel only.'),
            default => throw new RuntimeException('"' . $value
                . '" is not an employment type. Use "Job Order" or "Contract of Service".'),
        };
    }

    /** Status columns arrive spelled and cased every possible way. */
    private static function status(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'ACTIVE', 'A', 'YES', '1' => 'Active',
            'INACTIVE', 'I', 'NO', '0', 'SEPARATED', 'RESIGNED' => 'Inactive',
            'OPEN' => 'Open',
            'CLOSED' => 'Closed',
            'LOCKED' => 'Locked',
            default => $value,
        };
    }

    /** Gender, as the employee form's own two options. */
    private static function gender(string $value): string
    {
        return match (strtoupper(trim($value))) {
            'M', 'MALE', 'LALAKI' => 'Male',
            'F', 'FEMALE', 'BABAE' => 'Female',
            default => $value,
        };
    }
}
