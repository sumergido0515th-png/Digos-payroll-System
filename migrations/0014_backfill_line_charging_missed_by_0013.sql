-- ============================================================================
-- 0014_backfill_line_charging_missed_by_0013.sql
--
-- Finishes what 0013 started. 0013 backfilled the rows written while the Phase
-- 1 write-path gap was open - EmploymentTypeCode, PreparedByUser,
-- ApprovedByUser - but not the two columns 0006 added to PayrollDetails. Two
-- payroll lines were left with ChargedOfficeCode NULL: both belong to payrolls
-- created after 0006 ran and before app/Payroll.php was taught to write the
-- column, so neither backfill ever covered them.
--
-- This matters beyond tidiness. SCHEMA.md tells a later auditor that a NULL in
-- these columns means the write path has regressed. That would have been the
-- wrong conclusion here: the write path is correct (Payroll.php sets
-- ChargedOfficeCode from the payroll header on every save) and the backfill was
-- the thing with the hole. Leaving the rows NULL leaves that trap armed.
--
-- The rule below is the same one the write path applies - the line is charged
-- to the batch's office - so the backfill states what the system was already
-- assuming rather than inventing a charge. Only NULL rows are touched, so a
-- line anybody has since charged elsewhere by hand is never overwritten.
--
-- FunctionCode is deliberately copied only where the header actually has one.
-- Every header is NULL today (the offices carry no Function/PPA yet - see
-- SCHEMA.md, Known-unresolved data), so this clause does nothing now and does
-- the right thing once the offices are coded. A guessed appropriation prints an
-- amount against a fund it was never charged to; a NULL is visible.
-- ============================================================================

UPDATE PayrollDetails d
  JOIN Payroll p ON p.PayrollNo = d.PayrollNo
   SET d.ChargedOfficeCode = p.OfficeCode
 WHERE d.ChargedOfficeCode IS NULL;

UPDATE PayrollDetails d
  JOIN Payroll p ON p.PayrollNo = d.PayrollNo
   SET d.FunctionCode = p.FunctionCode
 WHERE d.FunctionCode IS NULL
   AND p.FunctionCode IS NOT NULL;
