-- ============================================================================
-- 0013_backfill_rows_written_after_phase1.sql
--
-- Fills EmploymentTypeCode, PreparedByUser and ApprovedByUser on the rows
-- created between the Phase 1 backfills and this migration.
--
-- WHY THESE ARE NULL AT ALL, which matters more than the rows themselves:
-- 0003 and 0007 backfilled the rows that existed when they ran, but nothing
-- in app/ ever wrote the new columns afterwards. Every employee and payroll
-- created through the UI since then arrived with them empty, so the frozen
-- model was quietly decaying with each new record - three payrolls and one
-- employee by the time this was measured.
--
-- The write paths are the actual fix and are corrected in the same change as
-- this file: apiSaveEmployee() now derives EmploymentTypeCode via
-- employmentTypeCode() in app/Master.php, apiSavePayroll() writes
-- PreparedByUser, and payrollTransition() writes ApprovedByUser on approval.
-- This migration only cleans up the rows written while that gap was open. If
-- a later audit finds these columns NULL again, the write path has regressed
-- - do not simply write another backfill.
--
-- Every UPDATE below is guarded twice: it touches only rows that are already
-- NULL, so a value anybody has since corrected by hand is never overwritten;
-- and the actor lookups require the display name to match exactly one user,
-- the same rule 0007 used. An ambiguous or unmatched name stays NULL, which
-- is visible at sign-off - a wrong identity on a segregation-of-duties check
-- is not.
-- ============================================================================

-- Employment type. Mapping identical to 0003 and to employmentTypeCode() in
-- app/Master.php; an unrecognised type stays NULL rather than guessing.
UPDATE Employees
   SET EmploymentTypeCode = CASE
     WHEN UPPER(TRIM(EmploymentType)) IN ('JO', 'JOB ORDER')                   THEN 'JO'
     WHEN UPPER(TRIM(EmploymentType)) IN ('COS', 'CONTRACT OF SERVICE')        THEN 'COS'
     WHEN UPPER(TRIM(EmploymentType)) IN ('PLANTILLA', 'REGULAR', 'PERMANENT') THEN 'PLA'
   END
 WHERE EmploymentTypeCode IS NULL;

-- Preparer identity.
UPDATE Payroll p
   SET p.PreparedByUser = (
     SELECT u.Email FROM Users u WHERE u.FullName = TRIM(p.PreparedBy) LIMIT 1)
 WHERE p.PreparedByUser IS NULL
   AND TRIM(IFNULL(p.PreparedBy, '')) <> ''
   AND (SELECT COUNT(*) FROM Users u2 WHERE u2.FullName = TRIM(p.PreparedBy)) = 1;

-- Approver identity. A payroll that has not been approved has no approver and
-- correctly keeps NULL here - the blank ApprovedBy guard below is what leaves
-- Draft rows alone.
UPDATE Payroll p
   SET p.ApprovedByUser = (
     SELECT u.Email FROM Users u WHERE u.FullName = TRIM(p.ApprovedBy) LIMIT 1)
 WHERE p.ApprovedByUser IS NULL
   AND TRIM(IFNULL(p.ApprovedBy, '')) <> ''
   AND (SELECT COUNT(*) FROM Users u2 WHERE u2.FullName = TRIM(p.ApprovedBy)) = 1;
