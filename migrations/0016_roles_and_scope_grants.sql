-- ============================================================================
-- 0016_roles_and_scope_grants.sql
--
-- Phase 2. The scope model: who may see which office's rows, and for how long.
--
-- WHY A TABLE AND NOT A COLUMN
-- Users.OfficeCode already exists and is exactly the shape that does not work:
-- one office, no expiry, no distinction between reading and writing, no record
-- of who granted it. Scope in this system is four dimensions (office, function,
-- employment type, fiscal year), a user can legitimately hold more than one
-- grant, and a grant that never expires is how a temporary detail becomes
-- permanent access.
--
-- Users.OfficeCode STAYS, as the user's home office for display, and is never
-- read for scope. Nothing enforces that but this comment and the fact that the
-- gateway does not look at it - so if a later session finds itself reaching for
-- Users.OfficeCode to answer "may this user see this row?", the answer is
-- ScopeGrants and the reach is the bug.
--
-- NULL MEANS WILDCARD, on every dimension. That is what makes one row able to
-- express "everything" (all four NULL) and "CMO only" (OfficeCode = 'CMO')
-- without a separate concept for each. The gateway's rule is the mirror image:
-- no applicable grant denies everything. An absent grant must never widen.
--
-- The role remap is here rather than in its own migration because the two are
-- one model: a grant carries RoleCode, and writing grants against role names
-- that are about to change means writing them twice.
-- ============================================================================

CREATE TABLE IF NOT EXISTS ScopeGrants (
  GrantID            VARCHAR(40)  NOT NULL,
  UserEmail          VARCHAR(120) NOT NULL,

  -- The grant applies only when the user is acting in this role. NULL = any
  -- role they hold. Present so one person carrying two roles does not carry
  -- the union of both roles' scope.
  RoleCode           VARCHAR(40)  NULL,

  -- The four scope dimensions. NULL = wildcard on that dimension.
  OfficeCode         VARCHAR(20)  NULL,
  FunctionCode       VARCHAR(20)  NULL,
  EmploymentTypeCode VARCHAR(20)  NULL,
  FiscalYear         INT          NULL,

  -- Read and write are separate because they routinely differ: an Office Head
  -- reads their office's payroll and does not prepare it.
  CanRead            TINYINT(1)   NOT NULL DEFAULT 1,
  CanWrite           TINYINT(1)   NOT NULL DEFAULT 0,

  -- Expiring by default is the intent; NULL on either side is unbounded that
  -- way, which a permanent assignment legitimately is.
  ValidFrom          DATE         NULL,
  ValidTo            DATE         NULL,

  GrantedBy          VARCHAR(120) NULL,
  GrantedAt          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  Remarks            VARCHAR(255) NULL,

  PRIMARY KEY (GrantID),
  KEY idx_scope_grants_user (UserEmail),

  -- CASCADE from the user: a grant is meaningless once its holder is gone, and
  -- leaving orphans behind is how a deleted account keeps its access if the
  -- email is ever reissued.
  CONSTRAINT fk_scope_grants_user
    FOREIGN KEY (UserEmail) REFERENCES Users (Email)
    ON UPDATE CASCADE ON DELETE CASCADE,

  -- SET NULL, not CASCADE: who granted access is audit history, and it must
  -- survive that person leaving. Losing the grant itself because its granter
  -- was deleted would revoke access as a side effect of an unrelated action.
  CONSTRAINT fk_scope_grants_granted_by
    FOREIGN KEY (GrantedBy) REFERENCES Users (Email)
    ON UPDATE CASCADE ON DELETE SET NULL,

  -- RESTRICT on the scope dimensions. SET NULL would silently widen a grant
  -- from one office to every office the moment that office was deleted, which
  -- is the exact failure this table exists to prevent.
  CONSTRAINT fk_scope_grants_office
    FOREIGN KEY (OfficeCode) REFERENCES Offices (OfficeCode)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_scope_grants_function
    FOREIGN KEY (FunctionCode) REFERENCES Functions (FunctionCode)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_scope_grants_employment_type
    FOREIGN KEY (EmploymentTypeCode) REFERENCES EmploymentTypes (TypeCode)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Role remap.
--
-- The plan's seven roles replace the six hardcoded ones. This is a remap of
-- intent, not a rename: each old role is matched to the new one holding the
-- nearest set of actions, and the reasoning is recorded here because it is a
-- judgement call somebody will want to re-examine.
--
--   Administrator  -> Admin
--   HR             -> HRMO
--   Payroll Officer-> Payroll In-Charge  (payroll.edit/submit, never approve)
--   Accounting     -> Pre-Auditor        (the only role holding payroll.approve)
--   Timekeeper     -> Encoder            (edit and submit, no approval)
--   Viewer         -> Internal Auditor   (read-only oversight)
--
-- Office Head is new and nothing maps onto it; it is granted deliberately, not
-- inherited. The matrix itself lives in PERMISSIONS in app/Auth.php - this
-- migration only moves the existing rows onto the new names, and an unmatched
-- role is left alone rather than guessed at, where it will fail loudly at
-- sign-in instead of silently acquiring somebody else's permissions.
-- ----------------------------------------------------------------------------
UPDATE Users SET Role = 'Admin'             WHERE Role = 'Administrator';
UPDATE Users SET Role = 'HRMO'              WHERE Role = 'HR';
UPDATE Users SET Role = 'Payroll In-Charge' WHERE Role = 'Payroll Officer';
UPDATE Users SET Role = 'Pre-Auditor'       WHERE Role = 'Accounting';
UPDATE Users SET Role = 'Encoder'           WHERE Role = 'Timekeeper';
UPDATE Users SET Role = 'Internal Auditor'  WHERE Role = 'Viewer';

-- ----------------------------------------------------------------------------
-- Seed: every Admin gets an unrestricted, non-expiring grant.
--
-- Without this the gateway's deny-by-default locks the only accounts that could
-- issue grants out of the system the moment it goes live - including out of the
-- screen for issuing grants. Every dimension NULL is the wildcard, and the
-- deterministic GrantID keeps a re-run from inserting a second copy.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO ScopeGrants
  (GrantID, UserEmail, RoleCode, CanRead, CanWrite, GrantedBy, Remarks)
SELECT CONCAT('SG-SEED-', UPPER(SUBSTRING(MD5(u.Email), 1, 12))),
       u.Email, NULL, 1, 1, NULL,
       'Seeded by migration 0016 so administrators are not locked out at cutover.'
  FROM Users u
 WHERE u.Role = 'Admin';
