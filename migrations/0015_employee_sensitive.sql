-- ============================================================================
-- 0015_employee_sensitive.sql
--
-- Phase 2, first migration. Splits Employees into the two tiers Phase 1 froze
-- in SCHEMA.md but deliberately did not move.
--
-- WHY THE SPLIT WAITED FOR THIS PHASE
-- Separating the columns buys nothing on its own - it is the gateway that
-- enforces the boundary, and Phase 1 had no gateway. Creating the table then
-- and backfilling it would have produced two sources of truth that drift;
-- creating it empty would have been decoration. It lands here, in the phase
-- that makes it mean something. Until it did, every role holding
-- `employee.view` could read salary rates and government ID numbers - GAP_MAP
-- finding 4, open since Phase 0.
--
-- WHY THIS MIGRATION IS ONLY HALF THE MOVE
-- It creates and backfills; 0016 drops the columns from Employees, and only
-- after the application has been switched to read the new table. MySQL commits
-- implicitly on DDL, so a migration that both copies and drops cannot be rolled
-- back if the drop fails - it would have destroyed the originals and kept no
-- copy. Two migrations means the window where both exist is a deliberate,
-- survivable state rather than an accident.
--
-- No CHARSET or COLLATE, deliberately. A foreign key between VARCHAR columns of
-- differing collations fails with errno 150, and the baseline tables take the
-- database default - which on a shared host the provider's panel chose. An
-- early Phase 1 draft hardcoded utf8mb4_unicode_ci, worked against a copy of
-- the live database and failed on a fresh install. Inheriting the default means
-- both sides always agree. See SCHEMA.md > Collation.
-- ============================================================================

CREATE TABLE IF NOT EXISTS EmployeeSensitive (
  EmployeeID   VARCHAR(40)   NOT NULL,

  -- Government identifiers.
  TIN          VARCHAR(30)   NULL,
  GSIS         VARCHAR(30)   NULL,
  PhilHealth   VARCHAR(30)   NULL,
  PagIBIG      VARCHAR(30)   NULL,

  -- Payee payment data. Added out of sequence at 0002 on request; Phase 1
  -- classified it here, alongside the government numbers, rather than in the
  -- directory tier where it arrived.
  CashCard     VARCHAR(30)   NULL,

  -- Personal data.
  Birthdate    DATE          NULL,
  Gender       VARCHAR(10)   NULL,
  Address      VARCHAR(255)  NULL,
  Contact      VARCHAR(40)   NULL,
  Email        VARCHAR(120)  NULL,

  -- Compensation. computeLine() in app/Payroll.php reads DailyRate and
  -- HourlyRate, so the payroll engine is a legitimate reader of this tier and
  -- is given an explicit path to it rather than an exception to the rule.
  SalaryRate   DECIMAL(12,2) NULL,
  DailyRate    DECIMAL(12,2) NULL,
  HourlyRate   DECIMAL(12,2) NULL,
  MonthlyRate  DECIMAL(12,2) NULL,

  UpdatedAt    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (EmployeeID),

  -- CASCADE, unlike most of 0009's rules. This is not independent data whose
  -- loss would invalidate payroll history: it is one half of one employee
  -- record, and a directory row without its restricted half is not a state
  -- worth being able to reach.
  CONSTRAINT fk_employee_sensitive_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per existing employee, so the split is complete the moment it lands.
-- INSERT IGNORE rather than a plain INSERT: re-running against a database where
-- some rows already exist must not fail, and the WHERE below already excludes
-- them.
INSERT IGNORE INTO EmployeeSensitive (
  EmployeeID, TIN, GSIS, PhilHealth, PagIBIG, CashCard,
  Birthdate, Gender, Address, Contact, Email,
  SalaryRate, DailyRate, HourlyRate, MonthlyRate)
SELECT e.EmployeeID, e.TIN, e.GSIS, e.PhilHealth, e.PagIBIG, e.CashCard,
       e.Birthdate, e.Gender, e.Address, e.Contact, e.Email,
       e.SalaryRate, e.DailyRate, e.HourlyRate, e.MonthlyRate
  FROM Employees e
  LEFT JOIN EmployeeSensitive s ON s.EmployeeID = e.EmployeeID
 WHERE s.EmployeeID IS NULL;
