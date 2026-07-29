<?php
/**
 * ============================================================================
 * Master.php - Master data: Employees, Offices, Departments, Functions,
 * Timekeepers and the combined lookups endpoint. Mirrors src/Employee.gs.
 * ============================================================================
 */

declare(strict_types=1);

/* ==========================================================================
 * Employees
 * ======================================================================== */

/** Lists employees with live search + filters + pagination. */
function apiListEmployees(array $p, array $user): array
{
    $sql = 'SELECT * FROM Employees WHERE 1=1';
    $params = [];
    foreach (['OfficeCode', 'Department', 'EmploymentType', 'Status'] as $f) {
        if (!empty($p[$f])) { $sql .= " AND `$f` = ?"; $params[] = $p[$f]; }
    }
    if (!empty($p['Function'])) { $sql .= ' AND FunctionName = ?'; $params[] = $p['Function']; }
    if (!empty($p['search'])) {
        $fields = ['EmployeeID', 'EmployeeNo', 'LastName', 'FirstName', 'MiddleName',
            'Position', 'Department', 'OfficeCode', 'Email', 'TIN', 'CashCard'];
        $sql .= ' AND (' . implode(' OR ', array_map(fn($f) => "`$f` LIKE ?", $fields)) . ')';
        foreach ($fields as $f) $params[] = '%' . $p['search'] . '%';
    }
    $sql .= ' ORDER BY LastName, FirstName';

    $rows = array_map(function ($e) {
        $e = aliasFunctionOut($e);
        $e['FullName'] = fullName($e);
        return $e;
    }, DB::rows($sql, $params));

    $page = max(1, (int) num($p['page'] ?? 1));
    $size = (int) num($p['pageSize'] ?? 25) ?: 25;
    return [
        'total' => count($rows),
        'page' => $page,
        'pageSize' => $size,
        'rows' => array_slice($rows, ($page - 1) * $size, $size),
    ];
}

/** Returns a single employee. */
function apiGetEmployee(array $p, array $user): array
{
    requireFields($p, ['EmployeeID']);
    $e = DB::row('SELECT * FROM Employees WHERE EmployeeID = ?', [$p['EmployeeID']]);
    if (!$e) throw new RuntimeException('Employee not found: ' . $p['EmployeeID']);
    $e = aliasFunctionOut($e);
    $e['FullName'] = fullName($e);
    return $e;
}

/** Creates or updates an employee with derived rates. */
function apiSaveEmployee(array $p, array $user): array
{
    requireFields($p, ['LastName', 'FirstName', 'EmploymentType', 'Position', 'OfficeCode']);
    if (!in_array($p['EmploymentType'], ['Job Order', 'Contract of Service'], true)) {
        throw new RuntimeException('Employment Type must be "Job Order" or "Contract of Service".');
    }
    if (!empty($p['Email']) && !isEmail($p['Email'])) throw new RuntimeException('Invalid email address.');
    if (!empty($p['ContractStart']) && !empty($p['ContractEnd']) && $p['ContractStart'] > $p['ContractEnd']) {
        throw new RuntimeException('Contract End must fall on or after Contract Start.');
    }

    if (!empty($p['EmployeeNo'])) {
        $clash = DB::row('SELECT EmployeeID FROM Employees WHERE EmployeeNo = ? AND EmployeeID <> ?',
            [$p['EmployeeNo'], $p['EmployeeID'] ?? '']);
        if ($clash) throw new RuntimeException('Employee Number ' . $p['EmployeeNo'] . ' is already in use.');
    }

    $rates = deriveRates($p);
    $isNew = empty($p['EmployeeID']);
    $record = [
        'EmployeeNo' => $p['EmployeeNo'] ?? '',
        'TIN' => $p['TIN'] ?? '', 'GSIS' => $p['GSIS'] ?? '',
        'PhilHealth' => $p['PhilHealth'] ?? '', 'PagIBIG' => $p['PagIBIG'] ?? '',
        'CashCard' => $p['CashCard'] ?? '',
        'LastName' => $p['LastName'], 'FirstName' => $p['FirstName'],
        'MiddleName' => $p['MiddleName'] ?? '', 'Suffix' => $p['Suffix'] ?? '',
        // The date fields take `?? '' ?:` rather than a bare `?:` so that an
        // omitted key and an empty string both become NULL. A DATE column
        // rejects '' under STRICT_ALL_TABLES, and every caller other than the
        // SPA - a test, a tool, a future import - is entitled to leave an
        // optional field out entirely rather than send it blank.
        'Birthdate' => ($p['Birthdate'] ?? '') ?: null, 'Gender' => $p['Gender'] ?? '',
        'Address' => $p['Address'] ?? '', 'Contact' => $p['Contact'] ?? '',
        'Email' => $p['Email'] ?? '',
        'OfficeCode' => $p['OfficeCode'], 'Department' => $p['Department'] ?? '',
        'Division' => $p['Division'] ?? '', 'FunctionName' => $p['Function'] ?? '',
        'EmploymentType' => $p['EmploymentType'],
        'EmploymentTypeCode' => employmentTypeCode($p['EmploymentType']),
        'Position' => $p['Position'],
        'SalaryRate' => $rates['salaryRate'], 'DailyRate' => $rates['dailyRate'],
        'HourlyRate' => $rates['hourlyRate'], 'MonthlyRate' => $rates['monthlyRate'],
        'DateHired' => ($p['DateHired'] ?? '') ?: null,
        'ContractStart' => ($p['ContractStart'] ?? '') ?: null,
        'ContractEnd' => ($p['ContractEnd'] ?? '') ?: null,
        'Status' => $p['Status'] ?? 'Active',
        'PhotoURL' => $p['PhotoURL'] ?? '', 'SignatureURL' => $p['SignatureURL'] ?? '',
        'Remarks' => $p['Remarks'] ?? '',
    ];

    if ($isNew) {
        $record['EmployeeID'] = newId('EMP');
        DB::insert('Employees', $record);
        return ['created' => true, 'EmployeeID' => $record['EmployeeID']];
    }
    if (!DB::update('Employees', $record, 'EmployeeID', $p['EmployeeID'])) {
        // rowCount can be 0 on a no-change update; verify existence explicitly.
        $exists = DB::row('SELECT EmployeeID FROM Employees WHERE EmployeeID = ?', [$p['EmployeeID']]);
        if (!$exists) throw new RuntimeException('Employee not found: ' . $p['EmployeeID']);
    }
    return ['updated' => true, 'EmployeeID' => $p['EmployeeID']];
}

/** Deletes an employee unless referenced by a payroll line. */
function apiDeleteEmployee(array $p, array $user): array
{
    requireFields($p, ['EmployeeID']);
    $used = (int) DB::scalar('SELECT COUNT(*) FROM PayrollDetails WHERE EmployeeID = ?', [$p['EmployeeID']]);
    if ($used) {
        throw new RuntimeException("This employee appears on $used payroll line(s) and cannot be deleted. "
            . 'Set the status to Inactive instead.');
    }
    return ['deleted' => DB::exec('DELETE FROM Employees WHERE EmployeeID = ?', [$p['EmployeeID']])];
}

/**
 * Maps the free-text `EmploymentType` the form posts onto the `EmploymentTypes`
 * key, or null when it matches nothing known.
 *
 * Both columns are written on save. `EmploymentType` stays because the printed
 * form and the SPA read it; `EmploymentTypeCode` is what Phase 4's resolvers
 * branch on, and they receive the `EmploymentTypes` row rather than comparing
 * type names, which is only possible if the key is populated.
 *
 * The mapping is deliberately identical to the backfill in
 * `migrations/0003_employment_types.sql`. If one changes the other must too, or
 * rows created before and after the change classify differently.
 */
function employmentTypeCode(?string $employmentType): ?string
{
    return match (strtoupper(trim((string) $employmentType))) {
        'JO', 'JOB ORDER' => 'JO',
        'COS', 'CONTRACT OF SERVICE' => 'COS',
        'PLANTILLA', 'REGULAR', 'PERMANENT' => 'PLA',
        default => null,
    };
}

/**
 * Derives daily/hourly/monthly rates from the entered basis
 * (JO/COS staff are normally paid a daily rate).
 */
function deriveRates(array $p): array
{
    $daysPerMonth = num(getSetting('WorkingDaysPerMonth', '22')) ?: 22;
    $hoursPerDay = num(getSetting('WorkingHoursPerDay', '8')) ?: 8;
    $rate = num($p['SalaryRate'] ?? 0);
    $basis = $p['RateBasis'] ?? 'Daily';

    $hourly = null;
    if ($basis === 'Monthly') {
        $monthly = $rate;
        $daily = $monthly / $daysPerMonth;
    } elseif ($basis === 'Hourly') {
        $hourly = $rate;
        $daily = $hourly * $hoursPerDay;
        $monthly = $daily * $daysPerMonth;
    } else {
        $daily = $rate;
        $monthly = $daily * $daysPerMonth;
    }
    $hourly ??= $daily / $hoursPerDay;

    return [
        'salaryRate' => round2($rate),
        'dailyRate' => round2($daily),
        'hourlyRate' => round2($hourly),
        'monthlyRate' => round2($monthly),
    ];
}

/* ==========================================================================
 * Offices / Departments / Functions
 * ======================================================================== */

/** Lists offices with live search. */
function apiListOffices(array $p, array $user): array
{
    $rows = array_map('aliasFunctionOut', DB::rows('SELECT * FROM Offices ORDER BY OfficeName'));
    return array_values(array_filter($rows, function ($o) use ($p) {
        if (!empty($p['Status']) && $o['Status'] !== $p['Status']) return false;
        return rowMatches($o, ['OfficeCode', 'OfficeName', 'Department', 'Division', 'OfficeHead'],
            $p['search'] ?? '');
    }));
}

/** Creates or updates an office (OfficeCode is the key). */
function apiSaveOffice(array $p, array $user): array
{
    requireFields($p, ['OfficeCode', 'OfficeName']);
    $code = strtoupper(trim((string) $p['OfficeCode']));
    $record = [
        'OfficeName' => $p['OfficeName'],
        'Department' => $p['Department'] ?? '',
        'Division' => $p['Division'] ?? '',
        'FunctionName' => $p['Function'] ?? '',
        // The Function/PPA the office charges to, as the key rather than the
        // display string. Until this was written the column could only ever be
        // set by the 0004 backfill, so an office created afterwards had no
        // chargeable function at all - and Phase 6's CAFOA rules check exactly
        // this. NULL rather than '' because it is a foreign key to Functions.
        'FunctionCode' => ($p['FunctionCode'] ?? '') ?: null,
        'ParentOfficeCode' => ($p['ParentOfficeCode'] ?? '') ?: null,
        'OfficeHead' => $p['OfficeHead'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
    if (($record['ParentOfficeCode'] ?? null) === $code) {
        throw new RuntimeException('An office cannot be its own parent office.');
    }
    if (DB::row('SELECT OfficeCode FROM Offices WHERE OfficeCode = ?', [$code])) {
        DB::update('Offices', $record, 'OfficeCode', $code);
        return ['updated' => true, 'OfficeCode' => $code];
    }
    $record['OfficeCode'] = $code;
    DB::insert('Offices', $record);
    return ['created' => true, 'OfficeCode' => $code];
}

/** Deletes an office when nothing references it. */
function apiDeleteOffice(array $p, array $user): array
{
    requireFields($p, ['OfficeCode']);
    $code = $p['OfficeCode'];

    // Every reference has to be checked here, not just employees. Migration
    // 0009 made these real foreign keys, so the database now refuses the
    // delete on its own - but what it raises is SQLSTATE 23000 ... errno 1451,
    // and api.php returns the exception message straight to the browser. A
    // timekeeper reading "a foreign key constraint fails" learns nothing about
    // what to do next; the checks below say which records are in the way.
    referenceGuard($code, [
        ['SELECT COUNT(*) FROM Employees WHERE OfficeCode = ?',
            '%d employee(s) are assigned to this office. Reassign them first.'],
        ['SELECT COUNT(*) FROM Payroll WHERE OfficeCode = ?',
            '%d payroll transaction(s) are charged to this office. An office with payroll history cannot be deleted - set it to Inactive instead.'],
        ['SELECT COUNT(*) FROM PayrollDetails WHERE ChargedOfficeCode = ?',
            '%d payroll line(s) are charged to this office. Set it to Inactive instead.'],
        ['SELECT COUNT(*) FROM Timekeepers WHERE OfficeCode = ?',
            '%d timekeeper(s) are assigned to this office. Reassign them first.'],
        ['SELECT COUNT(*) FROM Offices WHERE ParentOfficeCode = ?',
            '%d office(s) report to this one as their parent. Reassign them first.'],
        ['SELECT COUNT(*) FROM Functions WHERE OwningOfficeCode = ?',
            '%d Function/PPA code(s) are owned by this office. Reassign them first.'],
    ]);
    return ['deleted' => DB::exec('DELETE FROM Offices WHERE OfficeCode = ?', [$code])];
}

/**
 * Refuses a delete that other records still point at, in the caller's words.
 *
 * Each entry is [count query taking the key once, message with one %d]. The
 * first non-zero count wins - listing every obstacle at once reads as a wall
 * of text, and clearing the first usually clears the rest.
 */
function referenceGuard(string $key, array $checks): void
{
    foreach ($checks as [$sql, $message]) {
        $n = (int) DB::scalar($sql, [$key]);
        if ($n) throw new RuntimeException(sprintf($message, $n));
    }
}

/** Lists departments with live search. */
function apiListDepartments(array $p, array $user): array
{
    return array_values(array_filter(
        DB::rows('SELECT * FROM Departments ORDER BY DeptName'),
        fn($d) => rowMatches($d, ['DeptCode', 'DeptName', 'OfficeCode', 'Head'], $p['search'] ?? '')));
}

/** Creates or updates a department. */
function apiSaveDepartment(array $p, array $user): array
{
    requireFields($p, ['DeptCode', 'DeptName']);
    $code = strtoupper(trim((string) $p['DeptCode']));
    $record = [
        'DeptName' => $p['DeptName'],
        'OfficeCode' => $p['OfficeCode'] ?? '',
        'ParentDeptCode' => ($p['ParentDeptCode'] ?? '') ?: null,
        'Head' => $p['Head'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
    if ($record['ParentDeptCode'] === $code) {
        throw new RuntimeException('A department cannot be its own parent department.');
    }
    if (DB::row('SELECT DeptCode FROM Departments WHERE DeptCode = ?', [$code])) {
        DB::update('Departments', $record, 'DeptCode', $code);
        return ['updated' => true];
    }
    $record['DeptCode'] = $code;
    DB::insert('Departments', $record);
    return ['created' => true];
}

/** Deletes a department. */
function apiDeleteDepartment(array $p, array $user): array
{
    requireFields($p, ['DeptCode']);
    return ['deleted' => DB::exec('DELETE FROM Departments WHERE DeptCode = ?', [$p['DeptCode']])];
}

/** Lists funding functions (General Fund, SEF, Trust Fund, ...). */
function apiListFunctions(array $p, array $user): array
{
    return array_values(array_filter(
        DB::rows('SELECT * FROM Functions ORDER BY FunctionName'),
        fn($f) => rowMatches($f, ['FunctionCode', 'FunctionName', 'Description'], $p['search'] ?? '')));
}

/** Creates or updates a funding function. */
function apiSaveFunction(array $p, array $user): array
{
    requireFields($p, ['FunctionCode', 'FunctionName']);
    $code = strtoupper(trim((string) $p['FunctionCode']));
    $record = [
        'FunctionName' => $p['FunctionName'],
        'Description' => $p['Description'] ?? '',
        'OwningOfficeCode' => ($p['OwningOfficeCode'] ?? '') ?: null,
        'Status' => $p['Status'] ?? 'Active',
    ];
    if (DB::row('SELECT FunctionCode FROM Functions WHERE FunctionCode = ?', [$code])) {
        DB::update('Functions', $record, 'FunctionCode', $code);
        return ['updated' => true];
    }
    $record['FunctionCode'] = $code;
    DB::insert('Functions', $record);
    return ['created' => true];
}

/** Deletes a funding function. */
function apiDeleteFunction(array $p, array $user): array
{
    requireFields($p, ['FunctionCode']);
    $code = $p['FunctionCode'];

    // Unlike the office keys, every foreign key onto Functions is ON DELETE
    // SET NULL, so the database raises nothing at all: it quietly blanks
    // FunctionCode on the offices, payrolls and payroll lines charged to this
    // fund - including approved ones. Losing which appropriation paid an
    // approved payroll is not recoverable from anything else in the schema,
    // and it is exactly what Phase 6's CAFOA rules read.
    referenceGuard($code, [
        ['SELECT COUNT(*) FROM Payroll WHERE FunctionCode = ?',
            '%d payroll transaction(s) are charged to this Function/PPA. Deleting it would erase which appropriation paid them - set it to Inactive instead.'],
        ['SELECT COUNT(*) FROM PayrollDetails WHERE FunctionCode = ?',
            '%d payroll line(s) are charged to this Function/PPA. Set it to Inactive instead.'],
        ['SELECT COUNT(*) FROM Offices WHERE FunctionCode = ?',
            '%d office(s) charge to this Function/PPA. Assign them a different one first.'],
    ]);
    return ['deleted' => DB::exec('DELETE FROM Functions WHERE FunctionCode = ?', [$code])];
}

/* ==========================================================================
 * Timekeepers
 * ======================================================================== */

/** Lists timekeepers with live search and office filter. */
function apiListTimekeepers(array $p, array $user): array
{
    return array_values(array_filter(
        DB::rows('SELECT * FROM Timekeepers ORDER BY EmployeeName'),
        function ($t) use ($p) {
            if (!empty($p['OfficeCode']) && $t['OfficeCode'] !== $p['OfficeCode']) return false;
            if (!empty($p['Status']) && $t['Status'] !== $p['Status']) return false;
            return rowMatches($t, ['TimekeeperID', 'EmployeeName', 'OfficeCode', 'Department', 'Email'],
                $p['search'] ?? '');
        }));
}

/** Creates or updates a timekeeper. */
function apiSaveTimekeeper(array $p, array $user): array
{
    requireFields($p, ['EmployeeName', 'OfficeCode']);
    if (!empty($p['Email']) && !isEmail($p['Email'])) throw new RuntimeException('Invalid email address.');

    $record = [
        'EmployeeName' => $p['EmployeeName'],
        'OfficeCode' => $p['OfficeCode'],
        'Department' => $p['Department'] ?? '',
        'Contact' => $p['Contact'] ?? '',
        'Email' => $p['Email'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
    if (!empty($p['TimekeeperID'])) {
        DB::update('Timekeepers', $record, 'TimekeeperID', $p['TimekeeperID']);
        return ['updated' => true, 'TimekeeperID' => $p['TimekeeperID']];
    }
    $record['TimekeeperID'] = newId('TK');
    DB::insert('Timekeepers', $record);
    return ['created' => true, 'TimekeeperID' => $record['TimekeeperID']];
}

/** Deletes a timekeeper unless referenced by a payroll. */
function apiDeleteTimekeeper(array $p, array $user): array
{
    requireFields($p, ['TimekeeperID']);
    $used = (int) DB::scalar('SELECT COUNT(*) FROM Payroll WHERE TimekeeperID = ?', [$p['TimekeeperID']]);
    if ($used) throw new RuntimeException('This timekeeper is referenced by existing payrolls and cannot be deleted.');
    return ['deleted' => DB::exec('DELETE FROM Timekeepers WHERE TimekeeperID = ?', [$p['TimekeeperID']])];
}

/* ==========================================================================
 * Lookups
 * ======================================================================== */

/** Every dropdown list the UI needs, in one round trip. */
function apiGetLookups(array $p, array $user): array
{
    return [
        'offices' => array_map('aliasFunctionOut',
            DB::rows("SELECT * FROM Offices WHERE Status <> 'Inactive' ORDER BY OfficeName")),
        'departments' => DB::rows('SELECT * FROM Departments ORDER BY DeptName'),
        'functions' => DB::rows('SELECT * FROM Functions ORDER BY FunctionName'),
        'timekeepers' => DB::rows("SELECT * FROM Timekeepers WHERE Status <> 'Inactive' ORDER BY EmployeeName"),
        'periods' => DB::rows('SELECT * FROM PayrollPeriods ORDER BY StartDate DESC'),
        'employmentTypes' => ['Job Order', 'Contract of Service'],
        'statuses' => ['Active', 'Inactive'],
        'payrollStatuses' => ['Draft', 'Pending', 'Approved', 'Released', 'Cancelled'],
        'roles' => array_keys(PERMISSIONS),
    ];
}
