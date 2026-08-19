-- ============================================================================
-- 0002_add_employee_cashcard.sql
--
-- Adds Employees.CashCard - the payee's ATM / cash card number, for JO and COS
-- personnel paid by card rather than by signing for cash.
--
-- The printed payroll form has always carried a column headed "Signature or
-- Thumbmark/Cash Card Number" (app/PrintDoc.php), but there was nowhere to
-- store the number that column asks for, so it was filled in by hand. This
-- column is master data only; the printed form still leaves that cell blank
-- for the payee to sign.
--
-- Sized and defaulted like the other payee identifiers alongside it (TIN,
-- GSIS, PhilHealth, PagIBIG): VARCHAR with an empty-string default, so the
-- whole identifier block behaves the same way through DB::insert.
-- ============================================================================

ALTER TABLE Employees
  ADD COLUMN CashCard VARCHAR(30) DEFAULT '' AFTER PagIBIG;
