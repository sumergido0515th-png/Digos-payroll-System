-- ============================================================================
-- 0006_payroll_line_charging.sql
--
-- Phase 1. Makes the charged office and function first-class on the payroll
-- LINE, not derived from the employee's home office.
--
-- WHY THE LINE AND NOT THE HEADER
-- An employee's home office is where they are assigned; the charged office is
-- which appropriation pays for the work. They are usually the same and
-- occasionally not - detail to another office, work funded by a different
-- function - and when they differ, deriving the charge from the home office
-- silently bills the wrong appropriation.
--
-- It also matters for Phase 2. Scope is enforced per office, and a scope check
-- that reads the employee's home office answers a different question from the
-- one being asked: "may this user see this charge?" Phase 6 has a rule for
-- "payroll contains lines outside preparer's scope" which needs the charge on
-- the line to be checkable at all.
--
-- Backfill copies the header down, because that is exactly what the system has
-- been assuming; making the assumption explicit is the point. Existing rows
-- are unchanged in meaning - they are now merely stated rather than inferred.
-- ============================================================================

ALTER TABLE Payroll
  ADD COLUMN FunctionCode VARCHAR(20) NULL AFTER FunctionName;

ALTER TABLE PayrollDetails
  ADD COLUMN ChargedOfficeCode VARCHAR(20) NULL AFTER EmployeeID,
  ADD COLUMN FunctionCode      VARCHAR(20) NULL AFTER ChargedOfficeCode;

-- Header function: resolve the free string the same way 0004 did for Offices,
-- then fall back to the office's now-resolved code.
UPDATE Payroll p
   SET p.FunctionCode = (
     SELECT f.FunctionCode FROM Functions f
      WHERE f.FunctionCode = TRIM(p.FunctionName)
         OR f.FunctionName = TRIM(p.FunctionName)
      LIMIT 1)
 WHERE TRIM(IFNULL(p.FunctionName, '')) <> '';

UPDATE Payroll p
  JOIN Offices o ON o.OfficeCode = p.OfficeCode
   SET p.FunctionCode = o.FunctionCode
 WHERE p.FunctionCode IS NULL
   AND o.FunctionCode IS NOT NULL;

UPDATE PayrollDetails d
  JOIN Payroll p ON p.PayrollNo = d.PayrollNo
   SET d.ChargedOfficeCode = p.OfficeCode,
       d.FunctionCode      = p.FunctionCode;

ALTER TABLE Payroll
  ADD CONSTRAINT fk_payroll_function
  FOREIGN KEY (FunctionCode) REFERENCES Functions (FunctionCode)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE PayrollDetails
  ADD CONSTRAINT fk_details_charged_office
  FOREIGN KEY (ChargedOfficeCode) REFERENCES Offices (OfficeCode)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE PayrollDetails
  ADD CONSTRAINT fk_details_function
  FOREIGN KEY (FunctionCode) REFERENCES Functions (FunctionCode)
  ON UPDATE CASCADE ON DELETE SET NULL;
