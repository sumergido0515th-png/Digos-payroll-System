-- ============================================================================
-- 0020_attachments.sql
--
-- Phase 5. The evidence, bound to the dates it justifies.
--
-- WHY THE BINDING IS A TABLE AND NOT A DATE RANGE ON THE FILE
-- An attachment with "covers 1-15 July" is a claim about a fortnight. Phase 6
-- rule #1 asks a much narrower question: "was THIS employee's manual DTR entry
-- on THIS date covered by something?" A range cannot answer that without
-- re-deriving, per employee, which of those fifteen days the person was
-- actually named on - and the whole point of capturing coverage AT UPLOAD TIME
-- rather than at print time is that the answer stops being derived at all.
--
-- So AttachmentCoverage is one row per employee per date. It is bigger than a
-- range and it is the shape the question is asked in.
--
-- THE HASH IS UNIQUE, AND THAT IS THE FEATURE.
-- Sha256 carries a UNIQUE key so the database refuses a byte-identical file
-- however it is labelled. The failure this prevents is specific and common:
-- the same scanned memo uploaded again under a second control number, which
-- makes one document look like two pieces of evidence and a single authority
-- look like corroboration. The application checks first so the message is
-- useful, but the constraint is what makes the guarantee true.
--
-- FILES LIVE OUTSIDE THE WEB ROOT, in attachments/, next to backups/. These
-- are payroll justifications - scanned memoranda, medical certificates - and a
-- guessable URL under public/ would serve them to anyone who tried. They are
-- streamed by a route that checks scope instead.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Attachments (
  AttachmentID  VARCHAR(40)  NOT NULL,

  -- What the person uploading called it, kept for display only. StoredName is
  -- what is on disk: a generated id, so a file called "../../config.php" is
  -- a display string rather than a path.
  FileName      VARCHAR(255) NOT NULL,
  StoredName    VARCHAR(120) NOT NULL,
  MimeType      VARCHAR(100) NOT NULL DEFAULT '',
  SizeBytes     INT          NOT NULL DEFAULT 0,

  -- SHA-256 of the bytes, hex. UNIQUE - see the note above.
  Sha256        CHAR(64)     NOT NULL,

  -- Captured at upload, never deferred to print time.
  ControlNo     VARCHAR(60)  NOT NULL DEFAULT '',

  -- Memorandum | BioExemption | TravelOrder | Leave | Other. Kept as a string
  -- rather than a foreign key to one of four tables, because an attachment can
  -- legitimately justify something with no row of its own yet.
  DocumentType  VARCHAR(30)  NOT NULL DEFAULT 'Other',

  -- The document it evidences, when there is one. Deliberately not a foreign
  -- key: it points into one of several tables depending on DocumentType, and a
  -- constraint could only ever check one of them.
  DocumentID    VARCHAR(40)  NULL,

  CoversFrom    DATE         NULL,
  CoversTo      DATE         NULL,

  Status        VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks       VARCHAR(255) NOT NULL DEFAULT '',
  UploadedBy    VARCHAR(120) NULL,
  UploadedAt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (AttachmentID),

  -- The dedup guarantee. A byte-identical file cannot be stored twice under
  -- two control numbers, whatever the application does.
  UNIQUE KEY uq_attachment_hash (Sha256),
  KEY idx_attachment_control (ControlNo),
  KEY idx_attachment_document (DocumentType, DocumentID)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- The binding: which employee, on which date, this attachment justifies.
--
-- One row per employee per covered date. Phase 6 rule #1 reads exactly this:
-- a manual DTR entry with no matching row here is unjustified, and that is the
-- red cell on the coverage matrix.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS AttachmentCoverage (
  AttachmentID VARCHAR(40) NOT NULL,
  EmployeeID   VARCHAR(40) NOT NULL,
  CoveredDate  DATE        NOT NULL,

  PRIMARY KEY (AttachmentID, EmployeeID, CoveredDate),

  -- Indexed the way the matrix asks: give me everything covering these people
  -- across this fortnight.
  KEY idx_coverage_employee_date (EmployeeID, CoveredDate),

  CONSTRAINT fk_coverage_attachment
    FOREIGN KEY (AttachmentID) REFERENCES Attachments (AttachmentID)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_coverage_employee
    FOREIGN KEY (EmployeeID) REFERENCES Employees (EmployeeID)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Charset and collation deliberately unspecified - see the note in 0003.
