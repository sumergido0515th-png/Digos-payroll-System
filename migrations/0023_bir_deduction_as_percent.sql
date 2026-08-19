-- ============================================================================
-- 0023_bir_deduction_as_percent.sql
--
-- Corrects 0022's BIRDeductionRange. It landed as free text meant to hold
-- whichever bracket label or peso figure an office's paperwork used; the
-- actual requirement is narrower and more useful: the withholding rate
-- itself, as a percentage BIR applies to this employee's pay.
--
-- 0022 is already applied and checksummed, so the correction is a new
-- migration rather than an edit to it - the same rule that governs every
-- other applied migration in this project.
--
-- DECIMAL(5,2) rather than an integer: BIR's revised withholding table uses
-- fractional rates (e.g. 15%, 20%, 25%), and 5,2 leaves headroom above 100
-- that the application layer rejects rather than the column - a rate over
-- 100 is a data-entry mistake worth a clear error, not a silent truncation
-- under STRICT_ALL_TABLES.
-- ============================================================================

ALTER TABLE EmployeeSensitive
  DROP COLUMN BIRDeductionRange,
  ADD COLUMN BIRTaxPercent DECIMAL(5,2) NULL AFTER SSSDeductionApproved;
