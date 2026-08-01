-- ============================================================================
-- 0024_print_gating.sql
--
-- Phase 8. Ties the print action to what pre-audit actually approved.
--
-- PayloadHash is computed once, at the moment a payroll (or the clean half of
-- a split) reaches PRE_AUDIT_APPROVED, over exactly the data an Official print
-- renders: the PayrollDetails lines themselves (employees, rates, days,
-- deductions, net), the attachment coverage justifying those lines, and the
-- holidays declared over the period. Recomputed at Official print time and
-- compared - a mismatch means something the approval was based on changed
-- afterward, and the print is refused rather than silently rendering figures
-- nobody re-approved.
--
-- Deliberately narrower than the plan's own list: shift_versions is not
-- hashed. There is no per-employee shift assignment anywhere in the data
-- model to derive "the" shift for a payroll without a caller supplying one
-- (preAuditContext() already treats ShiftCode as optional caller input, not
-- something derivable from the payroll alone), so hashing it would either be
-- arbitrary or require inventing that assignment here, out of scope for this
-- migration. Recorded rather than silently dropped.
--
-- PrintLog is the print serial + reprint-reason record, one row per print
-- attempt of any form. IsOfficial distinguishes a genuine Official print from
-- a Draft/preview render - only the former ever needs a serial or a reprint
-- reason, but every attempt is logged so the history of what was printed,
-- when, and by whom is never reconstructed from the audit log's free-text
-- summaries alone.
--
-- ReprintReason is enforced in the application layer (PrintLogRepo), not by a
-- NOT NULL here: it is required only on the SECOND and later Official print of
-- a given payroll+form, and a column-level constraint cannot express "required
-- sometimes."
--
-- Print serials reuse Counters' (YearNo, Series) composite key from 0021 -
-- Series = 'PRINT' - rather than a new sequence table, the same reasoning
-- that gave suspensions their own 'SUSPENSION' series.
-- ============================================================================

ALTER TABLE Payroll
  ADD COLUMN PayloadHash VARCHAR(64) NULL AFTER SupplementsPayrollNo;

CREATE TABLE IF NOT EXISTS PrintLog (
  PrintLogID         VARCHAR(40)  NOT NULL,
  PayrollNo          VARCHAR(30)  NOT NULL,
  Form               VARCHAR(20)  NOT NULL,
  IsOfficial         TINYINT(1)   NOT NULL DEFAULT 0,
  PrintSerial        VARCHAR(30)  NULL,
  ReprintReason      VARCHAR(255) NOT NULL DEFAULT '',
  PayloadHashAtPrint VARCHAR(64)  NULL,
  PrintedBy          VARCHAR(120) NOT NULL DEFAULT '',
  PrintedByUser      VARCHAR(120) NULL,
  PrintedAt          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (PrintLogID),
  KEY idx_printlog_payroll (PayrollNo),

  -- CASCADE: a print log with no payroll behind it documents nothing. Payroll
  -- rows are never deleted outright (Cancelled is a status), so this only
  -- fires for a Draft's preview log if the Draft itself is deleted.
  CONSTRAINT fk_printlog_payroll
    FOREIGN KEY (PayrollNo) REFERENCES Payroll (PayrollNo)
    ON UPDATE CASCADE ON DELETE CASCADE,

  -- SET NULL, unlike the payroll FK above: the identity of who printed a
  -- document a user account is later removed for should not vanish the
  -- record that it was printed. PrintedBy (the display name) carries the
  -- historical fact either way.
  CONSTRAINT fk_printlog_printedby
    FOREIGN KEY (PrintedByUser) REFERENCES Users (Email)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
