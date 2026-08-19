-- ============================================================================
-- 0005_contracts.sql
--
-- Phase 1. Gives engagements their own entity, so rate history survives.
--
-- Today Employees carries one ContractStart/ContractEnd pair and one set of
-- rates, overwritten in place on every edit. A renewal at a new rate destroys
-- the old one, which means a payroll printed last quarter can no longer be
-- reconciled against the rate that was in force when it was prepared. Phase 6
-- has a rule for "daily rate != contract rate" and it has nothing to compare
-- against until contracts are rows rather than columns.
--
-- The backfill creates exactly one contract per employee from what is stored
-- now. That is all the history that exists - this migration cannot invent what
-- was overwritten before it ran. Everything from here forward accumulates.
--
-- ContractID is deterministic (CON- + EmployeeID) rather than newId(), so the
-- backfill is re-runnable and a second run inserts nothing. Contracts created
-- by the application afterwards use newId('CON') as normal.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Contracts (
  ContractID VARCHAR(60)  PRIMARY KEY,
  EmployeeID VARCHAR(40)  NOT NULL,
  TypeCode   VARCHAR(20)  NULL,
  RateBasis  VARCHAR(20)  NOT NULL DEFAULT 'Daily',
  Rate       DECIMAL(12,2) NOT NULL DEFAULT 0,
  StartDate  DATE         NULL,
  EndDate    DATE         NULL,
  Status     VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks    VARCHAR(255) NOT NULL DEFAULT '',
  CreatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contract_employee (EmployeeID),
  INDEX idx_contract_window (StartDate, EndDate),
  CONSTRAINT fk_contracts_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_contracts_type
    FOREIGN KEY (TypeCode) REFERENCES EmploymentTypes (TypeCode)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;
-- Charset and collation deliberately unspecified - see the note in 0003.

-- One contract per employee from the columns being superseded. Employees with
-- no dates and no rate are skipped: an empty contract asserts an engagement
-- that the record does not actually evidence.
INSERT IGNORE INTO Contracts
  (ContractID, EmployeeID, TypeCode, RateBasis, Rate, StartDate, EndDate, Status, Remarks)
SELECT
  CONCAT('CON-', e.EmployeeID),
  e.EmployeeID,
  e.EmploymentTypeCode,
  'Daily',
  e.DailyRate,
  e.ContractStart,
  e.ContractEnd,
  CASE WHEN e.Status = 'Active' THEN 'Active' ELSE 'Inactive' END,
  'Backfilled from Employees by migration 0005.'
FROM Employees e
WHERE e.ContractStart IS NOT NULL
   OR e.ContractEnd IS NOT NULL
   OR e.DailyRate > 0;
