-- ============================================================================
-- 0003_employment_types.sql
--
-- Phase 1. Turns the free-text Employees.EmploymentType into a reference table
-- carrying the computation flags that Phases 4 and 6 branch on.
--
-- WHY THE FLAGS LIVE HERE
-- "JO/COS vs Plantilla holiday pay divergence" is a Phase 4 exit-gate fixture,
-- and Phase 6 has a rule for holiday premium applied to JO/COS without
-- contractual basis. Both need to ask what an employment type is entitled to.
-- Encoding that as columns here keeps the resolvers pure: they receive the row
-- rather than reaching for a setting or hardcoding the type name.
--
-- Defaults are the conservative reading of JO/COS engagement: paid for days
-- actually worked, no holiday pay, no leave credits. They are seeded per type
-- below rather than relied on, so a wrong default cannot pass silently.
--
-- The legacy EmploymentType string is left in place and still authoritative
-- for the SPA, which reads it directly. EmploymentTypeCode is added alongside
-- it; the columns are reconciled when the UI moves to the code.
-- ============================================================================

CREATE TABLE IF NOT EXISTS EmploymentTypes (
  TypeCode        VARCHAR(20)  PRIMARY KEY,
  TypeName        VARCHAR(80)  NOT NULL,
  RateBasis       VARCHAR(20)  NOT NULL DEFAULT 'Daily',
  EarnsHolidayPay TINYINT(1)   NOT NULL DEFAULT 0,
  EarnsOvertime   TINYINT(1)   NOT NULL DEFAULT 1,
  EarnsNightDiff  TINYINT(1)   NOT NULL DEFAULT 0,
  EarnsLeave      TINYINT(1)   NOT NULL DEFAULT 0,
  Remarks         VARCHAR(255) NOT NULL DEFAULT '',
  Status          VARCHAR(20)  NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;
-- No CHARSET or COLLATE here on purpose. The baseline tables declare neither,
-- so they take the database default - and on a shared host the database is
-- created by the provider's panel, which picks that default. Naming a
-- collation here would make this table disagree with Employees on any server
-- whose default differs, and a foreign key between columns of different
-- collations fails with errno 150. Inheriting means both sides always match,
-- whatever the host chose.

INSERT IGNORE INTO EmploymentTypes
  (TypeCode, TypeName, RateBasis, EarnsHolidayPay, EarnsOvertime, EarnsNightDiff, EarnsLeave, Remarks) VALUES
  ('JO',  'Job Order',           'Daily',   0, 1, 0, 0,
   'No employer-employee relationship. Paid for days actually worked.'),
  ('COS', 'Contract of Service', 'Daily',   0, 1, 0, 0,
   'Contracted output or service. Entitlements follow the contract, not the Civil Service.'),
  ('PLA', 'Plantilla',           'Monthly', 1, 1, 1, 1,
   'Regular appointment. Full statutory entitlements.');

ALTER TABLE Employees
  ADD COLUMN EmploymentTypeCode VARCHAR(20) NULL AFTER EmploymentType;

-- Map what is already stored. Anything unrecognised is deliberately left NULL
-- rather than guessed: a wrong employment type changes what an employee is
-- paid, and a NULL is visible to the Phase 1 sign-off while a wrong guess is
-- not. The Status='Active' filter is not applied - historical rows must map
-- even if the type is later retired.
UPDATE Employees SET EmploymentTypeCode = CASE
    WHEN UPPER(TRIM(EmploymentType)) IN ('JO', 'JOB ORDER')           THEN 'JO'
    WHEN UPPER(TRIM(EmploymentType)) IN ('COS', 'CONTRACT OF SERVICE') THEN 'COS'
    WHEN UPPER(TRIM(EmploymentType)) IN ('PLANTILLA', 'REGULAR', 'PERMANENT') THEN 'PLA'
    ELSE NULL
  END;

ALTER TABLE Employees
  ADD CONSTRAINT fk_employees_employment_type
  FOREIGN KEY (EmploymentTypeCode) REFERENCES EmploymentTypes (TypeCode)
  ON UPDATE CASCADE ON DELETE RESTRICT;
