-- ============================================================================
-- demo-seed.sql - Synthetic demonstration data. NOT REAL PERSONNEL.
--
-- Every name, address, birthdate, contact number and government identifier in
-- this file is fabricated. The identifier blocks deliberately use impossible
-- ranges (TIN/GSIS/PhilHealth/Pag-IBIG/cash card all begin 9000...) so that no
-- value here can collide with a real record, and so that anything that leaks
-- out of a demo instance is obviously not personal data. Email addresses use
-- the reserved .invalid TLD, which can never be delivered - a demo instance
-- must not be able to mail a real person.
--
-- WHY THIS IS NOT IN THE DEPLOYMENT PACKAGE
-- tools/build-deploy.php ships app/, views/, public/ and migrations/ only, so
-- seeds/ never reaches a server unless someone imports it deliberately. That
-- separation is the point: a production deployment must not be able to inherit
-- fabricated employees by accident, and a demo instance must not be one
-- forgotten step away from looking authoritative.
--
-- USAGE
-- Import through phpMyAdmin AFTER dist/deploy-schema.sql, into the same
-- database. INSERT IGNORE throughout, so re-running changes nothing.
--
-- WHAT IS DELIBERATELY ABSENT
-- No Payroll or PayrollDetails rows. Money on a payroll line is computed by
-- computeLine() in app/Payroll.php from rate, days, overtime and deductions;
-- seeding totals by hand would bake in numbers the application never produced
-- and would hide a computation regression rather than expose it. Create the
-- payroll through the UI - that is the flow a walkthrough is meant to show.
--
-- Each office below holds at most 15 employees, matching the
-- MaxEmployeesPerPayroll setting and the printed form's row geometry.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Offices and departments
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `Offices` (`OfficeCode`, `OfficeName`, `Department`, `FunctionName`, `OfficeHead`, `Status`) VALUES
  ('CMO', 'City Mayor''s Office',        'General Services',  'GEN', 'DEMO Office Head',  'Active'),
  ('CTO', 'City Treasurer''s Office',    'Finance',           'GEN', 'DEMO Office Head',  'Active'),
  ('CHO', 'City Health Office',          'Health Services',   'GEN', 'DEMO Office Head',  'Active');

INSERT IGNORE INTO `Departments` (`DeptCode`, `DeptName`, `OfficeCode`, `Head`, `Status`) VALUES
  ('GS',  'General Services',  'CMO', 'DEMO Department Head', 'Active'),
  ('FIN', 'Finance',           'CTO', 'DEMO Department Head', 'Active'),
  ('HS',  'Health Services',   'CHO', 'DEMO Department Head', 'Active');

-- ---------------------------------------------------------------------------
-- Timekeepers - one per office, the role that prepares a batch
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `Timekeepers` (`TimekeeperID`, `EmployeeName`, `OfficeCode`, `Department`, `Contact`, `Email`, `Status`) VALUES
  ('TK-DEMO-001', 'DEMO Timekeeper - CMO', 'CMO', 'General Services', '0900-000-0001', 'tk.cmo@example.invalid', 'Active'),
  ('TK-DEMO-002', 'DEMO Timekeeper - CTO', 'CTO', 'Finance',          '0900-000-0002', 'tk.cto@example.invalid', 'Active'),
  ('TK-DEMO-003', 'DEMO Timekeeper - CHO', 'CHO', 'Health Services',  '0900-000-0003', 'tk.cho@example.invalid', 'Active');

-- ---------------------------------------------------------------------------
-- Employees - 12 across three offices, Job Order and Contract of Service
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `Employees`
  (`EmployeeID`, `EmployeeNo`, `TIN`, `GSIS`, `PhilHealth`, `PagIBIG`, `CashCard`,
   `LastName`, `FirstName`, `MiddleName`, `Birthdate`, `Gender`, `Address`, `Contact`, `Email`,
   `OfficeCode`, `Department`, `FunctionName`, `EmploymentType`, `Position`,
   `DailyRate`, `DateHired`, `ContractStart`, `ContractEnd`, `Status`)
VALUES
  ('EMP-DEMO-001', 'DEMO-0001', '900-000-000-001', '9000000001', '9000-0000-0001', '9000-0000-0001', '9000000000000001',
   'DELA CRUZ', 'Juan', 'Santos', '1992-03-14', 'Male', 'Zone I, Digos City', '0900-000-1001', 'demo001@example.invalid',
   'CMO', 'General Services', 'GEN', 'Job Order', 'Administrative Aide',
   520.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-002', 'DEMO-0002', '900-000-000-002', '9000000002', '9000-0000-0002', '9000-0000-0002', '9000000000000002',
   'REYES', 'Maria', 'Lopez', '1995-07-22', 'Female', 'Aplaya, Digos City', '0900-000-1002', 'demo002@example.invalid',
   'CMO', 'General Services', 'GEN', 'Job Order', 'Clerk',
   500.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-003', 'DEMO-0003', '900-000-000-003', '9000000003', '9000-0000-0003', '9000-0000-0003', '9000000000000003',
   'BAUTISTA', 'Pedro', 'Ramos', '1988-11-02', 'Male', 'San Jose, Digos City', '0900-000-1003', 'demo003@example.invalid',
   'CMO', 'General Services', 'GEN', 'Job Order', 'Driver',
   560.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-004', 'DEMO-0004', '900-000-000-004', '9000000004', '9000-0000-0004', '9000-0000-0004', '9000000000000004',
   'GARCIA', 'Ana', 'Mendoza', '1998-02-09', 'Female', 'Cogon, Digos City', '0900-000-1004', 'demo004@example.invalid',
   'CMO', 'General Services', 'GEN', 'Contract of Service', 'Encoder',
   610.00, '2026-01-05', '2026-01-01', '2026-06-30', 'Active'),

  ('EMP-DEMO-005', 'DEMO-0005', '900-000-000-005', '9000000005', '9000-0000-0005', '9000-0000-0005', '9000000000000005',
   'TORRES', 'Jose', 'Aquino', '1990-09-30', 'Male', 'Ruparan, Digos City', '0900-000-1005', 'demo005@example.invalid',
   'CMO', 'General Services', 'GEN', 'Job Order', 'Utility Worker',
   480.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-006', 'DEMO-0006', '900-000-000-006', '9000000006', '9000-0000-0006', '9000-0000-0006', '9000000000000006',
   'VILLANUEVA', 'Rosa', 'Cruz', '1993-05-18', 'Female', 'Zone II, Digos City', '0900-000-1006', 'demo006@example.invalid',
   'CTO', 'Finance', 'GEN', 'Job Order', 'Revenue Collection Clerk',
   540.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-007', 'DEMO-0007', '900-000-000-007', '9000000007', '9000-0000-0007', '9000-0000-0007', '9000000000000007',
   'SANTOS', 'Mark', 'Diaz', '1996-12-01', 'Male', 'Tres de Mayo, Digos City', '0900-000-1007', 'demo007@example.invalid',
   'CTO', 'Finance', 'GEN', 'Job Order', 'Administrative Aide',
   520.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-008', 'DEMO-0008', '900-000-000-008', '9000000008', '9000-0000-0008', '9000-0000-0008', '9000000000000008',
   'FERNANDEZ', 'Liza', 'Navarro', '1991-08-25', 'Female', 'Matti, Digos City', '0900-000-1008', 'demo008@example.invalid',
   'CTO', 'Finance', 'GEN', 'Contract of Service', 'Bookkeeping Assistant',
   650.00, '2026-01-05', '2026-01-01', '2026-06-30', 'Active'),

  ('EMP-DEMO-009', 'DEMO-0009', '900-000-000-009', '9000000009', '9000-0000-0009', '9000-0000-0009', '9000000000000009',
   'CASTILLO', 'Ramon', 'Flores', '1987-04-11', 'Male', 'Dawis, Digos City', '0900-000-1009', 'demo009@example.invalid',
   'CHO', 'Health Services', 'GEN', 'Job Order', 'Nursing Attendant',
   530.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-010', 'DEMO-0010', '900-000-000-010', '9000000010', '9000-0000-0010', '9000-0000-0010', '9000000000000010',
   'MORALES', 'Grace', 'Salvador', '1994-10-07', 'Female', 'Igpit, Digos City', '0900-000-1010', 'demo010@example.invalid',
   'CHO', 'Health Services', 'GEN', 'Job Order', 'Midwife Aide',
   545.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  ('EMP-DEMO-011', 'DEMO-0011', '900-000-000-011', '9000000011', '9000-0000-0011', '9000-0000-0011', '9000000000000011',
   'RIVERA', 'Nestor', 'Gonzales', '1989-06-16', 'Male', 'Zone III, Digos City', '0900-000-1011', 'demo011@example.invalid',
   'CHO', 'Health Services', 'GEN', 'Job Order', 'Sanitation Aide',
   495.00, '2026-01-05', '2026-01-01', '2026-12-31', 'Active'),

  -- Contract already expired: exercises the "contract expired before period end"
  -- condition the Phase 6 rule engine is specified to flag.
  ('EMP-DEMO-012', 'DEMO-0012', '900-000-000-012', '9000000012', '9000-0000-0012', '9000-0000-0012', '9000000000000012',
   'ALVAREZ', 'Teresa', 'Yap', '1997-01-28', 'Female', 'Colorado, Digos City', '0900-000-1012', 'demo012@example.invalid',
   'CHO', 'Health Services', 'GEN', 'Contract of Service', 'Encoder',
   600.00, '2026-01-05', '2026-01-01', '2026-06-30', 'Active');

-- ---------------------------------------------------------------------------
-- One open payroll period and the payroll-number counter for the year
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `PayrollPeriods` (`PeriodID`, `PayrollMonth`, `PayrollYear`, `StartDate`, `EndDate`, `Status`) VALUES
  ('PRD-DEMO-001', 'July', 2026, '2026-07-01', '2026-07-15', 'Open');

INSERT IGNORE INTO `Counters` (`YearNo`, `LastNo`) VALUES (2026, 0);
