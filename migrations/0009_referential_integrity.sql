-- ============================================================================
-- 0009_referential_integrity.sql
--
-- Phase 1. The baseline schema had no foreign key anywhere. This adds the ones
-- that hold the core records together.
--
-- The delete rules are the whole point of this migration, so they are chosen
-- rather than defaulted:
--
--   RESTRICT on anything a payroll depends on. Deleting an employee, an office
--   or a period that a payroll references would leave a printed, possibly
--   already-submitted document pointing at nothing. Payroll history is
--   evidence; it is not the master data's to invalidate.
--
--   CASCADE only from Payroll to PayrollDetails, which are parts of one
--   document rather than independent records. Deleting the payroll deletes its
--   lines because a line has no meaning without its header.
--
-- Employees.OfficeCode and Payroll.OfficeCode are NOT NULL, so these
-- constraints also close the case where an office code was typed that never
-- existed - which the schema previously allowed silently.
-- ============================================================================

ALTER TABLE Employees
  ADD CONSTRAINT fk_employees_office
  FOREIGN KEY (OfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE Timekeepers
  ADD CONSTRAINT fk_timekeepers_office
  FOREIGN KEY (OfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE Payroll
  ADD CONSTRAINT fk_payroll_office
  FOREIGN KEY (OfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE Payroll
  ADD CONSTRAINT fk_payroll_period
  FOREIGN KEY (PeriodID) REFERENCES PayrollPeriods (PeriodID)
  ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE PayrollDetails
  ADD CONSTRAINT fk_details_payroll
  FOREIGN KEY (PayrollNo) REFERENCES Payroll (PayrollNo)
  ON UPDATE CASCADE ON DELETE CASCADE;

ALTER TABLE PayrollDetails
  ADD CONSTRAINT fk_details_employee
  FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
  ON UPDATE CASCADE ON DELETE RESTRICT;
