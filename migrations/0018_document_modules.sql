-- ============================================================================
-- 0018_document_modules.sql
--
-- Phase 3. The four authority documents, storage only.
--
-- These are the records a pre-audit is conducted against: a memorandum that
-- authorises overtime, a bio exemption that excuses a missing punch, a travel
-- order that explains an absence, a work shift that says what "late" means.
-- Phase 6 asks whether a payroll line is covered by one of them. It cannot ask
-- until they exist.
--
-- RAW ENTRY ONLY. Effectivity is stored exactly as it was entered - a range, a
-- list of dates, a recurrence, a time window - and nothing here interprets it.
-- Resolution is Phase 4's `resolveAuthority`, deliberately, because working out
-- which memo covers a given employee at a given datetime is the hardest logic
-- in the system and it belongs in one tested pure function rather than spread
-- across four CRUD screens.
--
-- WHAT IS SCOPED AND WHAT IS NOT
-- Memorandum carries OfficeCode and FunctionCode and is registered in
-- ScopeEntity: a memo belongs to the office that issued it. BioExemptions and
-- TravelOrders carry neither, on purpose - they are about a person, so their
-- scope is that person's, and they are read through a join to Employees rather
-- than by copying an office code that would then need keeping in step. Denormal
-- ising it would create a second answer to "whose row is this?", and the two
-- would eventually disagree.
--
-- WorkShifts is unscoped reference data, like Offices and PayrollPeriods. A
-- shift definition is a rule about hours, not somebody's record.
--
-- No native JSON type: the deployment target is MariaDB 10.4, where JSON is an
-- alias for LONGTEXT with no validation. Lists are stored as delimited text and
-- the format is documented on the column.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Memorandum - the authority document.
--
-- Supersession is three separate columns because they are three different
-- events. `SupersedesID` replaces an earlier memo outright; `AmendsID` changes
-- part of one that stays in force; `RevokedByID` is set on the memo being
-- withdrawn and points forward at the instrument that withdrew it. Phase 4
-- walks these to truncate a superseded window, and it has to be able to tell
-- "replaced" from "amended" to know whether the earlier window ends or narrows.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Memorandum (
  MemoID          VARCHAR(40)  NOT NULL,
  ControlNo       VARCHAR(60)  NOT NULL,
  Subject         VARCHAR(255) NOT NULL DEFAULT '',

  -- Three dates, three different facts. Issued is when it was signed, approved
  -- when authority attached, received when the office learned of it. A memo
  -- approved after the work it authorises is a Phase 6 finding, and that
  -- finding needs all three to exist separately.
  DateIssued      DATE         NULL,
  DateApproved    DATE         NULL,
  DateReceived    DATE         NULL,

  -- Scope. NULL OfficeCode means citywide, which a memo from the Mayor's
  -- office legitimately is.
  OfficeCode      VARCHAR(20)  NULL,
  FunctionCode    VARCHAR(20)  NULL,

  -- How to read the effectivity columns below. One of:
  --   Range      EffectivityStart .. EffectivityEnd, whole days
  --   Specific   SpecificDates, a comma-separated list of YYYY-MM-DD
  --   Recurring  RecurrenceDays, comma-separated ISO weekday numbers (1=Mon)
  --   Window     as Range, but only between TimeFrom and TimeTo each day
  --   OpenEnded  EffectivityStart onwards, no end
  -- Stored, never interpreted here. Phase 4 owns the interpretation.
  EffectivityType VARCHAR(20)  NOT NULL DEFAULT 'Range',
  EffectivityStart DATE        NULL,
  EffectivityEnd  DATE         NULL,
  TimeFrom        TIME         NULL,
  TimeTo          TIME         NULL,
  SpecificDates   TEXT         NULL,
  RecurrenceDays  VARCHAR(40)  NULL,

  -- What kind of authority this memo confers, so a rule can ask for the right
  -- one: Overtime, Detail, Travel, FlexiTime, Suspension, Other.
  AuthorityType   VARCHAR(30)  NOT NULL DEFAULT 'Other',

  SupersedesID    VARCHAR(40)  NULL,
  AmendsID        VARCHAR(40)  NULL,
  RevokedByID     VARCHAR(40)  NULL,

  Status          VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks         VARCHAR(255) NOT NULL DEFAULT '',
  CreatedBy       VARCHAR(120) NULL,
  CreatedAt       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (MemoID),

  -- A control number is the document's identity in the paper world. Two rows
  -- sharing one is either a duplicate entry or two different memos filed under
  -- the same number, and both need catching at entry rather than at audit.
  UNIQUE KEY uq_memo_control_no (ControlNo),
  KEY idx_memo_office (OfficeCode),
  KEY idx_memo_window (EffectivityStart, EffectivityEnd),

  CONSTRAINT fk_memo_office
    FOREIGN KEY (OfficeCode) REFERENCES Offices (OfficeCode)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_memo_function
    FOREIGN KEY (FunctionCode) REFERENCES Functions (FunctionCode)
    ON UPDATE CASCADE ON DELETE RESTRICT,

  -- SET NULL on the chain links, never CASCADE. Deleting a superseded memo must
  -- not delete the one that superseded it - the survivor is the document still
  -- in force, and losing it because its predecessor was tidied away would
  -- remove live authority.
  CONSTRAINT fk_memo_supersedes
    FOREIGN KEY (SupersedesID) REFERENCES Memorandum (MemoID)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memo_amends
    FOREIGN KEY (AmendsID) REFERENCES Memorandum (MemoID)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_memo_revoked_by
    FOREIGN KEY (RevokedByID) REFERENCES Memorandum (MemoID)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Who a memorandum covers.
--
-- A junction table rather than a list column, because Phase 4 resolves
-- authority per employee per datetime and that is a lookup, not a scan of every
-- memo's text. CASCADE both ways: coverage has no meaning without either end.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS MemorandumEmployees (
  MemoID     VARCHAR(40) NOT NULL,
  EmployeeID VARCHAR(40) NOT NULL,

  PRIMARY KEY (MemoID, EmployeeID),
  KEY idx_memo_emp_employee (EmployeeID),

  CONSTRAINT fk_memo_emp_memo
    FOREIGN KEY (MemoID) REFERENCES Memorandum (MemoID)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_memo_emp_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Bio exemption - why a biometric punch is legitimately missing.
--
-- Phase 6 rule #1 checks manual DTR entries against a covering exemption, which
-- is why 3B has to keep manual and biometric entries distinguishable. Without
-- this table that rule has nothing to accept as an excuse and would flag every
-- field worker.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS BioExemptions (
  ExemptionID VARCHAR(40)  NOT NULL,
  EmployeeID  VARCHAR(40)  NOT NULL,

  -- Free-form on purpose at this phase: the reason vocabulary is an LGU policy
  -- decision, not a schema one, and guessing at codes now would mean migrating
  -- them later. Phase 6 reads ReasonCode, it does not enumerate it.
  ReasonCode  VARCHAR(40)  NOT NULL DEFAULT '',
  Reason      VARCHAR(255) NOT NULL DEFAULT '',

  ValidFrom   DATE         NULL,
  ValidTo     DATE         NULL,

  -- The alternate evidence, since the whole point is that the usual evidence
  -- is absent. The attachment itself lands in Phase 5; this is the reference.
  ProofType   VARCHAR(40)  NOT NULL DEFAULT '',
  ProofRef    VARCHAR(255) NOT NULL DEFAULT '',

  Status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks     VARCHAR(255) NOT NULL DEFAULT '',
  CreatedBy   VARCHAR(120) NULL,
  CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (ExemptionID),
  KEY idx_bioex_employee (EmployeeID),
  KEY idx_bioex_window (ValidFrom, ValidTo),

  CONSTRAINT fk_bioex_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Travel order.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS TravelOrders (
  TravelOrderID VARCHAR(40)  NOT NULL,
  TravelOrderNo VARCHAR(60)  NOT NULL,
  EmployeeID    VARCHAR(40)  NOT NULL,
  Destination   VARCHAR(200) NOT NULL DEFAULT '',
  Purpose       VARCHAR(255) NOT NULL DEFAULT '',
  DepartDate    DATE         NULL,
  ReturnDate    DATE         NULL,

  -- Whether per diem was claimed. A flag rather than an amount: what it costs
  -- is an accounting question this system does not answer, but whether it was
  -- claimed is what makes a travel day different from an absence.
  PerDiem       TINYINT(1)   NOT NULL DEFAULT 0,

  Status        VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks       VARCHAR(255) NOT NULL DEFAULT '',
  CreatedBy     VARCHAR(120) NULL,
  CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (TravelOrderID),
  UNIQUE KEY uq_travel_order_no (TravelOrderNo),
  KEY idx_travel_employee (EmployeeID),
  KEY idx_travel_window (DepartDate, ReturnDate),

  CONSTRAINT fk_travel_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Work shift - VERSIONED.
--
-- One row is one version of one shift. Editing a shift inserts a new row and
-- closes the old one's EffectiveTo; it never updates the times in place. This
-- is the same reasoning as Contracts in 0005: a payroll prepared last quarter
-- was computed against the shift in force then, and overwriting the times
-- destroys the only record of what "late" meant on those days.
--
-- ShiftCode is the stable identity across versions; ShiftID identifies the
-- version. Phase 4 resolves "which version was effective on this date".
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS WorkShifts (
  ShiftID       VARCHAR(40)  NOT NULL,
  ShiftCode     VARCHAR(20)  NOT NULL,
  VersionNo     INT          NOT NULL DEFAULT 1,
  ShiftName     VARCHAR(120) NOT NULL DEFAULT '',

  TimeIn        TIME         NULL,
  TimeOut       TIME         NULL,
  BreakMinutes  INT          NOT NULL DEFAULT 0,

  -- Comma-separated ISO weekday numbers, 1=Monday .. 7=Sunday. '6,7' is the
  -- ordinary weekend. Empty means no rest day, which a shift worker has.
  RestDays      VARCHAR(20)  NOT NULL DEFAULT '',

  -- The night differential window. Both NULL means the shift earns none.
  NightDiffFrom TIME         NULL,
  NightDiffTo   TIME         NULL,

  EffectiveFrom DATE         NOT NULL,
  EffectiveTo   DATE         NULL,

  SupersedesID  VARCHAR(40)  NULL,
  Status        VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks       VARCHAR(255) NOT NULL DEFAULT '',
  CreatedBy     VARCHAR(120) NULL,
  CreatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (ShiftID),

  -- Two rows claiming to be the same version of the same shift is the bug this
  -- table's whole design exists to prevent, so the database refuses it rather
  -- than trusting the application to increment correctly.
  UNIQUE KEY uq_shift_code_version (ShiftCode, VersionNo),
  KEY idx_shift_effective (ShiftCode, EffectiveFrom),

  CONSTRAINT fk_shift_supersedes
    FOREIGN KEY (SupersedesID) REFERENCES WorkShifts (ShiftID)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Charset and collation deliberately unspecified - see the note in 0003.
