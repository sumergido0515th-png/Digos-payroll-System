-- ============================================================================
-- 0022_employee_deduction_elections.sql
--
-- Two employee-level deduction elections, added to EmployeeSensitive alongside
-- the other Tier 2 payee data (TIN, GSIS, PhilHealth, PagIBIG, CashCard):
--
--   SSSDeductionApproved - whether the employee has approved an SSS deduction
--   from their pay. JO/COS personnel are not compulsorily SSS members the way
--   Plantilla staff are GSIS members, so this is consent, not a given.
--
--   BIRDeductionRange - the withholding tax bracket or amount BIR applies to
--   this employee. Free text rather than an enum: the office's own paperwork
--   names these differently across employees (a bracket label, a specific
--   peso figure), and a lookup table for the real BIR withholding schedule is
--   a bigger piece of work than this migration is scoped to attempt.
--
-- Classified as Tier 2 for the same reason CashCard was in 0002/0015: this is
-- payee financial data, not directory data, so `employee.sensitive` gates it
-- like everything else in this table.
--
-- Current-state columns, not versioned. If a later audit needs to know when
-- consent was given or withdrawn - plausible, since this is a monetary
-- authorization - that is a bigger change than two columns and should be its
-- own migration when the need is concrete rather than spent here on a guess.
-- ============================================================================

ALTER TABLE EmployeeSensitive
  ADD COLUMN SSSDeductionApproved TINYINT(1) NOT NULL DEFAULT 0 AFTER PagIBIG,
  ADD COLUMN BIRDeductionRange    VARCHAR(50) NULL             AFTER SSSDeductionApproved;
