-- ============================================================================
-- 0011_watermark_default_opacity.sql
--
-- Raises the default WatermarkOpacity from 0.06 to 0.20.
--
-- 0010 seeded 0.06, which is about right for a solid dark crest on the light
-- theme and too faint for everything else - a thin line drawing, or any image
-- behind the dark theme's near-black page, simply does not read at it. 0.20 is
-- still inside the readable ceiling that app/Settings.php enforces
-- (WATERMARK_MAX_OPACITY), and the watermark sits behind opaque cards, so the
-- figures on the dashboard are unaffected either way.
--
-- The UPDATE is deliberately conditional on the old default: an installation
-- that has already tuned this from the Settings screen has made a decision
-- about its own image, and a migration changing a default must not overwrite
-- it. This is also why the change is a new migration rather than an edit to
-- 0010 - that one is already applied, and tools/migrate.php checksums it.
-- ============================================================================

INSERT IGNORE INTO Settings (KeyName, Value) VALUES ('WatermarkOpacity', '0.20');

UPDATE Settings SET Value = '0.20' WHERE KeyName = 'WatermarkOpacity' AND Value = '0.06';
