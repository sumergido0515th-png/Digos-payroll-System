-- ============================================================================
-- 0010_branding_watermark.sql
--
-- Adds the branding settings behind the uploadable office logo and the new
-- page watermark.
--
--   WatermarkUrl      background seal shown behind the dashboard and the
--                     sign-in page. Empty means no watermark, which is the
--                     default: an installation that has not uploaded one must
--                     look exactly as it did before.
--   WatermarkOpacity  how strongly it prints on screen, 0.02 - 0.25. It is a
--                     setting rather than a constant because the right value
--                     depends on the image - a thin line drawing disappears
--                     at the opacity a solid seal needs. The upper bound is
--                     enforced in app/Settings.php as well; above it, page
--                     text sitting over the image stops being comfortably
--                     readable, which defeats the point of a watermark.
--
-- OfficeLogoUrl already exists (seeded by 0001) and is reused as-is, so the
-- logo an installation has already set survives this migration.
-- ============================================================================

INSERT IGNORE INTO Settings (KeyName, Value) VALUES
  ('WatermarkUrl', ''),
  ('WatermarkOpacity', '0.06');
