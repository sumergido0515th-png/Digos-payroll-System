-- ============================================================================
-- 0008_dtr_days.sql
--
-- Phase 1, settled revision 1. One row per employee per date.
--
-- This is the plan's largest unstated dependency, recorded in GAP_MAP. Today
-- PayrollDetails stores DaysWorked, HoursWorked, OvertimeHours, LateMinutes,
-- UndertimeMinutes and AbsentDays as period totals keyed by hand, and there is
-- no per-date record anywhere in the system. Phase 4's time-window
-- intersection, Phase 5's employee x day coverage matrix, and Phase 6's
-- calendar and shift rules all compute per date. None of them have an input
-- until this table exists. Phase 3B fills it.
--
-- Source is load-bearing, not descriptive. Phase 6's first rule checks that a
-- manual DTR entry is covered by an approved Bio Exemption, and it cannot be
-- written if a manually keyed day and a biometric capture are indistinguishable
-- once stored. Recording provenance at write time is the only moment it is
-- knowable.
--
-- The UNIQUE on (EmployeeID, WorkDate) is what makes the coverage matrix well
-- defined: one cell, one row, no ambiguity about which of two rows for the same
-- day is authoritative.
--
-- Times are nullable because an absence, a holiday and a rest day are all real
-- rows with no clock events. The row's existence is the record that the date
-- was accounted for at all - which is exactly what a red cell on the Phase 5
-- matrix means when it is missing.
-- ============================================================================

CREATE TABLE IF NOT EXISTS DtrDays (
  DtrDayID         VARCHAR(40)  PRIMARY KEY,
  EmployeeID       VARCHAR(40)  NOT NULL,
  WorkDate         DATE         NOT NULL,
  PeriodID         VARCHAR(40)  NULL,
  TimeIn1          TIME         NULL,
  TimeOut1         TIME         NULL,
  TimeIn2          TIME         NULL,
  TimeOut2         TIME         NULL,
  HoursWorked      DECIMAL(6,2) NOT NULL DEFAULT 0,
  OvertimeHours    DECIMAL(6,2) NOT NULL DEFAULT 0,
  LateMinutes      DECIMAL(8,2) NOT NULL DEFAULT 0,
  UndertimeMinutes DECIMAL(8,2) NOT NULL DEFAULT 0,
  IsAbsent         TINYINT(1)   NOT NULL DEFAULT 0,
  DayType          VARCHAR(30)  NOT NULL DEFAULT 'Regular',
  Source           VARCHAR(20)  NOT NULL DEFAULT 'Manual',
  Remarks          VARCHAR(255) NOT NULL DEFAULT '',
  CreatedAt        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dtr_employee_date (EmployeeID, WorkDate),
  INDEX idx_dtr_period (PeriodID),
  INDEX idx_dtr_date (WorkDate),
  CONSTRAINT fk_dtr_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_dtr_period
    FOREIGN KEY (PeriodID) REFERENCES PayrollPeriods (PeriodID)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
-- Charset and collation deliberately unspecified - see the note in 0003.
