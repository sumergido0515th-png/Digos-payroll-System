-- ============================================================================
-- 0012_print_logo.sql
--
-- Adds PrintLogoUrl - the seal printed on documents (currently the CAFOA
-- header), separate from OfficeLogoUrl which is the screen logo shown in the
-- sidebar and on the sign-in page.
--
-- They are separate uploads because they are separate images in practice. The
-- screen logo is viewed at 70-100px on a backlit display and a flat, simplified
-- crest reads best. The printed seal goes onto a voucher at 62px through a
-- laser printer, where fine linework survives and mid greys fill in, and it is
-- the version an auditor expects to see on the document. Sharing one file
-- meant whichever was uploaded last was wrong in the other place.
--
-- Empty means fall back to OfficeLogoUrl, so an installation that has only
-- ever set one logo keeps printing exactly what it printed before this ran.
-- ============================================================================

INSERT IGNORE INTO Settings (KeyName, Value) VALUES ('PrintLogoUrl', '');
