-- ============================================================================
-- 0007_payroll_actor_keys.sql
--
-- Phase 1, settled revision 2. Gives Payroll real foreign keys to the users who
-- prepared and approved it.
--
-- PreparedBy and ApprovedBy are display-name strings. A segregation-of-duties
-- check cannot be written against them: two users may share a full name, a
-- rename orphans the link, and a string is trivially forged by anything that
-- can write the row. Phase 2 requires "preparer cannot self-approve" enforced
-- in code, and Phase 7 requires it enforced at the permission layer rather
-- than hidden in the UI. Neither is expressible until identity is a key.
--
-- Both string columns stay. The printed form must show the name as it was
-- rendered at the time, and a user renamed or removed afterwards must not
-- retroactively change what a printed payroll said. The string is the
-- historical record; the key is the identity. They answer different questions
-- and Phase 8's payload hash depends on the first not drifting.
--
-- The backfill links only unambiguous exact matches. Where two users share a
-- full name the row is left NULL, because guessing which one prepared a
-- payroll is precisely the assertion an SoD check must not be built on.
-- ============================================================================

ALTER TABLE Payroll
  ADD COLUMN PreparedByUser VARCHAR(120) NULL AFTER PreparedBy,
  ADD COLUMN ApprovedByUser VARCHAR(120) NULL AFTER ApprovedBy;

UPDATE Payroll p
   SET p.PreparedByUser = (
     SELECT u.Email FROM Users u WHERE u.FullName = TRIM(p.PreparedBy) LIMIT 1)
 WHERE TRIM(IFNULL(p.PreparedBy, '')) <> ''
   AND (SELECT COUNT(*) FROM Users u2 WHERE u2.FullName = TRIM(p.PreparedBy)) = 1;

UPDATE Payroll p
   SET p.ApprovedByUser = (
     SELECT u.Email FROM Users u WHERE u.FullName = TRIM(p.ApprovedBy) LIMIT 1)
 WHERE TRIM(IFNULL(p.ApprovedBy, '')) <> ''
   AND (SELECT COUNT(*) FROM Users u2 WHERE u2.FullName = TRIM(p.ApprovedBy)) = 1;

-- ON DELETE SET NULL, never CASCADE: removing a user account must not delete
-- the payrolls they prepared. The audit trail outlives the account.
ALTER TABLE Payroll
  ADD CONSTRAINT fk_payroll_prepared_by_user
  FOREIGN KEY (PreparedByUser) REFERENCES Users (Email)
  ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE Payroll
  ADD CONSTRAINT fk_payroll_approved_by_user
  FOREIGN KEY (ApprovedByUser) REFERENCES Users (Email)
  ON UPDATE CASCADE ON DELETE SET NULL;
