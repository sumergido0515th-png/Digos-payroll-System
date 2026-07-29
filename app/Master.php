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
        'Birthdate' => $p['Birthdate'] ?: null, 'Gender' => $p['Gender'] ?? '',
        'Address' => $p['Address'] ?? '', 'Contact' => $p['Contact'] ?? '',
        'Email' => $p['Email'] ?? '',
        'OfficeCode' => $p['OfficeCode'], 'Department' => $p['Department'] ?? '',
        'Division' => $p['Division'] ?? '', 'FunctionName' => $p['Function'] ?? '',
        'EmploymentType' => $p['EmploymentType'],
        'EmploymentTypeCode' => employmentTypeCode($p['EmploymentType']),
        'Position' => $p['Position'],
        'SalaryRate' => $rates['salaryRate'], 'DailyRate' => $rates['dailyRate'],
        'HourlyRate' => $rates['hourlyRate'], 'MonthlyRate' => $rates['monthlyRate'],
        'DateHired' => $p['DateHired'] ?: null,
        'ContractStart' => $p['ContractStart'] ?: null,
        'ContractEnd' => $p['ContractEnd'] ?: null,
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
        'OfficeHead' => $p['OfficeHead'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
    if (DB::row('SELECT OfficeCode FROM Offices WHERE OfficeCode = ?', [$code])) {
        DB::update('Offices', $record, 'OfficeCode', $code);
        return ['updated' => true, 'OfficeCode' => $code];
    }
    $record['OfficeCode'] = $code;
    DB::insert('Offices', $record);
    return ['created' => true, 'OfficeCode' => $code];
}

/** Deletes an office when no employee references it. */
function apiDeleteOffice(array $p, array $user): array
{
    requireFields($p, ['OfficeCode']);
    $used = (int) DB::scalar('SELECT COUNT(*) FROM Employees WHERE OfficeCode = ?', [$p['OfficeCode']]);
    if ($used) throw new RuntimeException("$used employee(s) are assigned to this office. Reassign them first.");
    return ['deleted' => DB::exec('DELETE FROM Offices WHERE OfficeCode = ?', [$p['OfficeCode']])];
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
        'Head' => $p['Head'] ?? '',
        'Status' => $p['Status'] ?? 'Active',
    ];
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
    return ['deleted' => DB::exec('DELETE FROM Functions WHERE FunctionCode = ?', [$p['FunctionCode']])];
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
