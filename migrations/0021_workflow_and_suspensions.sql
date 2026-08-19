-- ============================================================================
-- 0021_workflow_and_suspensions.sql
--
-- Phase 7. The state machine the plan actually names, and the record a
-- suspension leaves behind.
--
-- STATUS VALUES ARE RENAMED, DATA AND ALL, the same way migration 0016 remapped
-- role names rather than leaving old values for the application to translate
-- forever. The old five-state flow (Draft/Pending/Approved/Released/Cancelled)
-- becomes the plan's own vocabulary:
--
--   Draft     -> DRAFT
--   Pending   -> FOR_PRE_AUDIT
--   Approved  -> PRE_AUDIT_APPROVED
--   Released  -> SUBMITTED
--   Cancelled -> CANCELLED
--
-- Released mapped to SUBMITTED rather than to an intermediate FOR_PRINTING or
-- PRINTED: a Released payroll in the old system was already handed out, and
-- reconstructing which of the new print sub-states it passed through is not
-- something this migration can know. SUBMITTED is the terminal state and is
-- true of every payroll that reached Released under the old flow.
--
-- FOR_PRINTING and PRINTED are new states with no old equivalent - they did
-- not exist to rename. Nothing before this migration was ever in them.
--
-- Status widens from VARCHAR(20) to VARCHAR(30): RETURNED_TO_PREPARER alone is
-- 21 characters, longer than the column that held every value until now.
-- ============================================================================

UPDATE Payroll SET Status = 'DRAFT'              WHERE Status = 'Draft';
UPDATE Payroll SET Status = 'FOR_PRE_AUDIT'       WHERE Status = 'Pending';
UPDATE Payroll SET Status = 'PRE_AUDIT_APPROVED'  WHERE Status = 'Approved';
UPDATE Payroll SET Status = 'SUBMITTED'           WHERE Status = 'Released';
UPDATE Payroll SET Status = 'CANCELLED'           WHERE Status = 'Cancelled';

ALTER TABLE Payroll
  MODIFY Status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',

  -- When this payroll last entered FOR_PRE_AUDIT. The pre-auditor worklist
  -- sorts its queue by how long a payroll has been waiting, and "waiting
  -- since" is not derivable from DateCreated (a Draft can sit unedited for
  -- weeks before anyone submits it) or from ApprovedAt (which does not exist
  -- until review is already over).
  ADD COLUMN SubmittedAt DATETIME NULL AFTER DateCreated,

  -- Set on a payroll created by splitting suspended employees out of another
  -- one - see the note on Suspensions.EmployeeID below. NULL for an ordinary
  -- payroll. Self-referencing rather than a new lookup table because a
  -- supplemental payroll supplements exactly one original, never several.
  ADD COLUMN SupplementsPayrollNo VARCHAR(30) NULL AFTER PdfFileId,
  ADD CONSTRAINT fk_payroll_supplements
    FOREIGN KEY (SupplementsPayrollNo) REFERENCES Payroll (PayrollNo)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- ----------------------------------------------------------------------------
-- Sequential numbering needs a second, independent series.
--
-- Counters had one counter per year because only PayrollNo ever needed one.
-- Suspension numbers (NsNo) are their own human-facing serial - a Notice of
-- Suspension is a real document with its own filing sequence - and sharing
-- the payroll counter would make issuing a suspension silently skip a payroll
-- number, or vice versa. Existing rows default to Series='PAYROLL', which is
-- exactly what they have always been.
-- ----------------------------------------------------------------------------
ALTER TABLE Counters
  ADD COLUMN Series VARCHAR(20) NOT NULL DEFAULT 'PAYROLL' AFTER YearNo,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (YearNo, Series);

-- ----------------------------------------------------------------------------
-- Suspensions - the Notice of Suspension record.
--
-- EMPLOYEE-SCOPED BY DEFAULT. EmployeeID NULL means the whole batch is held;
-- a value means only that employee's line is. This is what lets one person's
-- unresolved finding hold their own pay without stopping fourteen coworkers on
-- the same payroll from being paid on time - the batch splits (see
-- SupplementsPayrollNo above), the clean lines proceed, and the suspended ones
-- travel with this row to the supplemental payroll that continues auditing
-- them.
--
-- RuleID is nullable because a suspension is not always automatic. BLOCKER
-- findings raise one on their own; RuleID NULL is a pre-auditor's own
-- judgment call, which the plan calls out as something the workflow must
-- allow for even when no rule caught it.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Suspensions (
  NsNo           VARCHAR(30)  NOT NULL,
  PayrollNo      VARCHAR(30)  NOT NULL,
  EmployeeID     VARCHAR(40)  NULL,
  GroundCode     VARCHAR(40)  NOT NULL,
  RuleID         VARCHAR(20)  NULL,
  Particulars    VARCHAR(500) NOT NULL DEFAULT '',
  RequiredAction VARCHAR(500) NOT NULL DEFAULT '',
  Deadline       DATE         NULL,

  -- Open | Settled | Waived. Waived exists separately from Settled because
  -- "the issue was fixed" and "an authority decided it did not need fixing"
  -- are different facts and a later reader should be able to tell them apart.
  Status         VARCHAR(20)  NOT NULL DEFAULT 'Open',

  RaisedBy       VARCHAR(120) NULL,
  RaisedAt       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  SettledBy      VARCHAR(120) NULL,
  SettledAt      DATETIME     NULL,
  SettlementRef  VARCHAR(255) NOT NULL DEFAULT '',

  PRIMARY KEY (NsNo),
  KEY idx_suspension_payroll (PayrollNo),
  KEY idx_suspension_employee (EmployeeID),
  KEY idx_suspension_status (Status),

  -- CASCADE from the payroll: a suspension has no meaning once the batch it
  -- was raised against is gone, and Payroll rows are never deleted outright
  -- (Cancelled is a status, not a removal) - so this only fires if a Draft
  -- with open suspensions is deleted, which apiDeletePayroll already refuses
  -- for other reasons.
  CONSTRAINT fk_suspension_payroll
    FOREIGN KEY (PayrollNo) REFERENCES Payroll (PayrollNo)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_suspension_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Charset and collation deliberately unspecified - see the note in 0003.
