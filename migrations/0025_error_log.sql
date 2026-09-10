-- ============================================================================
-- 0025_error_log.sql
--
-- Out-of-phase, requested directly: a place for PHP and browser errors that
-- happen outside a RuntimeException to land somewhere other than a server
-- log file nobody is watching. Belongs to no phase, the same shape as the
-- branding images (0010) and the newId() counter fix.
--
-- Shaped after Logs (0001), not after the newId()-keyed application tables:
-- this is an append-only operational record, not a business entity anything
-- else references, so it gets the one documented exception to "IDs are
-- newId() strings" - a BIGINT AUTO_INCREMENT primary key - and, like
-- Logs.User, UserEmail is a plain string with no foreign key. A JS error can
-- happen from a session whose Users row is mid-edit or already gone by the
-- time anyone reads this table, and diagnostic data is not worth a delete
-- ever refusing on.
-- ============================================================================

CREATE TABLE IF NOT EXISTS ErrorLog (
  ErrorLogID BIGINT       NOT NULL AUTO_INCREMENT,
  Source     VARCHAR(10)  NOT NULL DEFAULT 'php',
  Message    TEXT         NOT NULL,
  File       VARCHAR(255) NOT NULL DEFAULT '',
  Line       INT          NOT NULL DEFAULT 0,
  Trace      TEXT,
  Url        VARCHAR(255) NOT NULL DEFAULT '',
  UserEmail  VARCHAR(120) NOT NULL DEFAULT '',
  UserAgent  VARCHAR(255) NOT NULL DEFAULT '',
  CreatedAt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (ErrorLogID),
  INDEX idx_errorlog_created (CreatedAt),
  INDEX idx_errorlog_source (Source)
) ENGINE=InnoDB;
