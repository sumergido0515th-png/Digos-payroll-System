-- ============================================================================
-- 0017_drop_employee_tier2_columns.sql
--
-- Phase 2. Completes the split 0015 began: EmployeeSensitive now holds these
-- columns, and this removes the originals from Employees.
--
-- THIS ONE IS DESTRUCTIVE AND CANNOT BE UNDONE BY THE MIGRATOR. MySQL commits
-- implicitly on DDL, so there is no rollback - the recovery path is a restore
-- from backups/. Before applying it, confirm 0015 actually copied everything:
--
--   SELECT COUNT(*) FROM Employees e
--     LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
--    WHERE s.EmployeeID IS NULL;      -- must be 0
--
-- WHY IT IS A SEPARATE MIGRATION FROM 0015
-- A single migration that copied and then dropped would, if the drop failed
-- halfway, have destroyed the originals while keeping no usable copy. Two
-- migrations make the window where both exist a deliberate, survivable state:
-- 0015 lands, the application is switched to read the new table, and only then
-- does this run.
--
-- EVERY READER WAS MOVED FIRST, which is the precondition for applying this:
--   app/Master.php        apiSaveEmployee writes both tiers via EmployeeRepo;
--                         list/get read Tier 2 only for a caller holding
--                         employee.sensitive
--   app/Payroll.php       computeLine's rates and the payslip mailer's address
--                         come from EmployeeRepo
--   app/PrintDoc.php      the Pag-IBIG list joins EmployeeSensitive
--   app/Reports.php       untouched - it only ever used fullName(), Tier 1
--
-- Employees keeps Position, OfficeCode, EmploymentType, ContractStart/End,
-- DateHired and the rest of the directory tier. What leaves is what SCHEMA.md
-- classified as restricted, and nothing else.
-- ============================================================================

ALTER TABLE Employees
  DROP COLUMN TIN,
  DROP COLUMN GSIS,
  DROP COLUMN PhilHealth,
  DROP COLUMN PagIBIG,
  DROP COLUMN CashCard,
  DROP COLUMN Birthdate,
  DROP COLUMN Gender,
  DROP COLUMN Address,
  DROP COLUMN Contact,
  DROP COLUMN Email,
  DROP COLUMN SalaryRate,
  DROP COLUMN DailyRate,
  DROP COLUMN HourlyRate,
  DROP COLUMN MonthlyRate;
