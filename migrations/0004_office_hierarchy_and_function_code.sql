-- ============================================================================
-- 0004_office_hierarchy_and_function_code.sql
--
-- Phase 1. Gives Offices and Departments a parent, and starts collapsing the
-- Function/PPA duplication onto the code.
--
-- THE FUNCTION PROBLEM
-- Functions is keyed by FunctionCode, but Offices and Payroll store
-- FunctionName as a free string, and what was typed there is sometimes the
-- code and sometimes the name - hence aliasFunctionIn/Out in app/Helpers.php
-- and functionLabel() in app/PrintDoc.php. This adds a real FunctionCode
-- column and backfills whatever resolves.
--
-- The backfill resolves against BOTH FunctionCode and FunctionName because
-- both have been typed into that column. What resolves to neither is left
-- NULL: measured on the live database, two offices hold '9999', which is not a
-- code, not a name, and not recoverable by guessing. A NULL surfaces at the
-- Phase 1 sign-off; a wrong function prints an amount under an appropriation
-- it was never charged to.
--
-- The FunctionName strings are NOT dropped. They are the only record of what
-- was actually entered, and Phase 1 freezes the schema without discarding
-- evidence. The drop belongs in the phase that has repointed every reader.
-- ============================================================================

ALTER TABLE Offices
  ADD COLUMN ParentOfficeCode VARCHAR(20) NULL AFTER OfficeCode,
  ADD COLUMN FunctionCode     VARCHAR(20) NULL AFTER FunctionName;

ALTER TABLE Departments
  ADD COLUMN ParentDeptCode VARCHAR(20) NULL AFTER DeptCode;

ALTER TABLE Functions
  ADD COLUMN OwningOfficeCode VARCHAR(20) NULL AFTER Description;

UPDATE Offices o
   SET o.FunctionCode = (
     SELECT f.FunctionCode FROM Functions f
      WHERE f.FunctionCode = TRIM(o.FunctionName)
         OR f.FunctionName = TRIM(o.FunctionName)
      LIMIT 1)
 WHERE TRIM(IFNULL(o.FunctionName, '')) <> '';

-- Self-referencing parents. ON DELETE SET NULL rather than CASCADE: deleting a
-- parent office must never silently delete the child offices whose payroll
-- history hangs off them.
ALTER TABLE Offices
  ADD CONSTRAINT fk_offices_parent
  FOREIGN KEY (ParentOfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE Offices
  ADD CONSTRAINT fk_offices_function
  FOREIGN KEY (FunctionCode) REFERENCES Functions (FunctionCode)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE Departments
  ADD CONSTRAINT fk_departments_parent
  FOREIGN KEY (ParentDeptCode) REFERENCES Departments (DeptCode)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE Functions
  ADD CONSTRAINT fk_functions_owning_office
  FOREIGN KEY (OwningOfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE SET NULL;
