-- ============================================================================
-- 0019_holidays_and_pay_rules.sql
--
-- Phase 4. What a date was, and what that was worth.
--
-- TWO TABLES BECAUSE THEY ARE TWO DIFFERENT KINDS OF FACT, changing on
-- different schedules and decided by different people:
--
--   Holidays        "2026-08-21 was a Special Non-Working day, nationally."
--                   A proclamation. New rows every year, forever.
--
--   HolidayPayRules "A JO who does not work a regular holiday is not paid
--                    for it."
--                   A policy. Changes rarely, and when it does, the old
--                   version still governs payrolls already prepared.
--
-- Folding them together would mean restating the pay rule on every one of the
-- twenty-odd proclamations a year, and a change in policy would then require
-- editing history rather than adding a version.
--
-- LEGAL BASIS IS NOT NULL ON BOTH. A pre-audit finding that says "this should
-- have been paid 2x" is worth very little; one that cites the issuance is
-- auditable. The column being mandatory is what stops a row being added
-- without one.
--
-- SCOPE IS A LEVEL PLUS A CODE, resolved most-specific-wins:
--   National -> Region -> Province -> City -> Barangay
-- A city holiday overrides a national one for that city. Storing a single
-- OfficeCode instead could not express "the whole province" at all, and a
-- local holiday ordinance applies to a city, not to an office.
--
-- WORK SUSPENSIONS ARE TIME-GRANULAR. "Work is suspended from 12:00" is the
-- ordinary form of a typhoon suspension, and a table that could only say
-- "2026-09-02 was suspended" would pay a full day to somebody who worked the
-- morning and went home. StartTime and EndTime NULL mean the whole day.
-- ============================================================================

CREATE TABLE IF NOT EXISTS Holidays (
  HolidayID   VARCHAR(40)  NOT NULL,
  HolidayDate DATE         NOT NULL,
  HolidayName VARCHAR(160) NOT NULL DEFAULT '',

  -- One of: RegularHoliday, SpecialNonWorking, SpecialWorking, LocalHoliday,
  -- WorkSuspension. RestDay is deliberately NOT here - a rest day comes from
  -- the employee's shift, not from a calendar, and putting it in this table
  -- would make it a property of the date for everybody at once.
  DayType     VARCHAR(30)  NOT NULL,

  -- National | Region | Province | City | Barangay
  ScopeLevel  VARCHAR(20)  NOT NULL DEFAULT 'National',

  -- The named region/province/city/barangay. NULL for National, which needs
  -- no qualifier.
  ScopeCode   VARCHAR(60)  NULL,

  -- Partial-day suspensions. Both NULL is the whole day.
  StartTime   TIME         NULL,
  EndTime     TIME         NULL,

  LegalBasis  VARCHAR(255) NOT NULL,
  Status      VARCHAR(20)  NOT NULL DEFAULT 'Active',
  Remarks     VARCHAR(255) NOT NULL DEFAULT '',
  CreatedBy   VARCHAR(120) NULL,
  CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (HolidayID),

  -- One declaration per date per scope per kind. A date legitimately carries
  -- two rows - a national regular holiday and a local suspension - and the
  -- resolver picks between them; what it must not carry is the same
  -- declaration twice, which is a double entry rather than a second fact.
  UNIQUE KEY uq_holiday_date_scope (HolidayDate, ScopeLevel, ScopeCode, DayType),
  KEY idx_holiday_date (HolidayDate)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- The pay rule: day type x employment type x worked? -> paid, multiplier.
--
-- VERSIONED BY EFFECTIVITY, like WorkShifts and Contracts and for the same
-- reason: a payroll prepared last year was computed under last year's policy,
-- and a pre-audit that re-checks it has to ask what the rule was then. Editing
-- a multiplier in place would make every historical payroll retroactively
-- wrong or retroactively right, and neither is a fact about what happened.
--
-- EmploymentTypeCode NULL is the fallback for types with no specific rule,
-- which keeps the table from needing a row per type per day type per worked
-- flag when most of them agree.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS HolidayPayRules (
  RuleID             VARCHAR(40)   NOT NULL,
  DayType            VARCHAR(30)   NOT NULL,

  -- NULL = applies to any employment type without a more specific rule.
  EmploymentTypeCode VARCHAR(20)   NULL,

  -- Whether the employee actually worked the day. The two answers differ far
  -- more than the day type does: the whole JO/COS divergence lives here.
  Worked             TINYINT(1)    NOT NULL DEFAULT 0,

  Paid               TINYINT(1)    NOT NULL DEFAULT 0,

  -- Of a normal day's pay. 1.000 is an ordinary day, 2.000 a regular holiday
  -- worked, 0.000 unpaid. DECIMAL rather than a float because a multiplier
  -- that is 1.2999999 produces a peso difference somebody has to explain.
  Multiplier         DECIMAL(6,3)  NOT NULL DEFAULT 0.000,

  LegalBasis         VARCHAR(255)  NOT NULL,
  EffectiveFrom      DATE          NOT NULL,
  EffectiveTo        DATE          NULL,
  Notes              VARCHAR(255)  NOT NULL DEFAULT '',
  CreatedAt          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (RuleID),
  UNIQUE KEY uq_pay_rule (DayType, EmploymentTypeCode, Worked, EffectiveFrom),
  KEY idx_pay_rule_lookup (DayType, EffectiveFrom)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- Seed: the rules as they stand for this city's JO/COS personnel.
--
-- THE DIVERGENCE THIS SYSTEM EXISTS TO GET RIGHT is the "not worked" rows. An
-- employee with an employer-employee relationship is paid for a regular
-- holiday they did not work; a Job Order or Contract of Service worker is not,
-- because they are engaged for services actually rendered. Paying a JO for an
-- unworked holiday is a disallowance, and it is the single easiest mistake to
-- make when the payroll is prepared by hand.
--
-- EffectiveFrom is 2016-01-01 rather than today: these rules governed the
-- payrolls already in the database, and dating them from now would leave every
-- historical date with no applicable rule at all.
--
-- THESE FIGURES NEED CONFIRMING against the city's own issuances before
-- go-live. They are the national default, and an LGU may be more generous by
-- ordinance - which is exactly why this is a table with a legal basis and an
-- effectivity rather than a constant in PHP.
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO HolidayPayRules
  (RuleID, DayType, EmploymentTypeCode, Worked, Paid, Multiplier, LegalBasis, EffectiveFrom, Notes)
VALUES
  -- Ordinary working day, for completeness: the resolver returns a rule for
  -- every date, and a date with no rule would otherwise be indistinguishable
  -- from a date whose rule says "unpaid".
  ('HPR-REG-WORKED',    'RegularDay',        NULL, 1, 1, 1.000,
   'Ordinary working day - no holiday premium applies.', '2016-01-01', ''),
  ('HPR-REG-NOTWORKED', 'RegularDay',        NULL, 0, 0, 0.000,
   'No work, no pay for an ordinary day not worked.', '2016-01-01', ''),

  -- Regular holiday. Worked: 200%. Not worked: 100% where holiday pay is due,
  -- nothing for JO/COS.
  ('HPR-RH-WORKED',     'RegularHoliday',    NULL, 1, 1, 2.000,
   'Labor Code art. 94 - 200% for the first eight hours worked on a regular holiday.',
   '2016-01-01', ''),
  ('HPR-RH-NOTWORKED',  'RegularHoliday',    NULL, 0, 1, 1.000,
   'Labor Code art. 94 - 100% for a regular holiday not worked, where the employee is entitled to holiday pay.',
   '2016-01-01', 'Fallback for engagements with an employer-employee relationship.'),
  ('HPR-RH-NW-JO',      'RegularHoliday',    'JO',  0, 0, 0.000,
   'COA/DBM rules on Job Order engagements - paid for services actually rendered; no holiday pay for a day not worked.',
   '2016-01-01', 'THE divergence: paying this is a disallowance.'),
  ('HPR-RH-NW-COS',     'RegularHoliday',    'COS', 0, 0, 0.000,
   'COA/DBM rules on Contract of Service engagements - paid for services actually rendered; no holiday pay for a day not worked.',
   '2016-01-01', 'THE divergence: paying this is a disallowance.'),

  -- Special non-working day. Worked: 130%. Not worked: no work, no pay for
  -- everyone, which is the national default rather than a JO/COS specialty.
  ('HPR-SNW-WORKED',    'SpecialNonWorking', NULL, 1, 1, 1.300,
   'Labor advisory on special (non-working) days - additional 30% for the first eight hours worked.',
   '2016-01-01', ''),
  ('HPR-SNW-NOTWORKED', 'SpecialNonWorking', NULL, 0, 0, 0.000,
   'Special (non-working) day - the no-work-no-pay principle applies unless a more favourable policy exists.',
   '2016-01-01', ''),

  -- Special working day: an ordinary day in every respect but its name.
  ('HPR-SW-WORKED',     'SpecialWorking',    NULL, 1, 1, 1.000,
   'Special working day - treated as an ordinary working day; no premium.', '2016-01-01', ''),
  ('HPR-SW-NOTWORKED',  'SpecialWorking',    NULL, 0, 0, 0.000,
   'Special working day not worked - no work, no pay.', '2016-01-01', ''),

  -- Local holiday declared by ordinance. Treated as a special non-working day
  -- unless the ordinance says otherwise, which is what the versioning is for.
  ('HPR-LH-WORKED',     'LocalHoliday',      NULL, 1, 1, 1.300,
   'Local holiday by city ordinance - treated as a special (non-working) day absent a contrary provision.',
   '2016-01-01', 'Confirm against the specific ordinance before go-live.'),
  ('HPR-LH-NOTWORKED',  'LocalHoliday',      NULL, 0, 0, 0.000,
   'Local holiday not worked - no work, no pay absent a contrary provision.',
   '2016-01-01', 'Confirm against the specific ordinance before go-live.'),

  -- Work suspension. The "not worked" row is PAID because a suspension is the
  -- government sending people home rather than the employee choosing not to
  -- attend - but for JO/COS the no-work-no-pay principle still governs, and
  -- that is again the divergence.
  ('HPR-WS-WORKED',     'WorkSuspension',    NULL, 1, 1, 1.000,
   'Work suspension - hours actually worked during a suspension are paid at the ordinary rate.',
   '2016-01-01', ''),
  ('HPR-WS-NOTWORKED',  'WorkSuspension',    NULL, 0, 1, 1.000,
   'Work suspension - engagements with an employer-employee relationship are not docked for a suspension.',
   '2016-01-01', ''),
  ('HPR-WS-NW-JO',      'WorkSuspension',    'JO',  0, 0, 0.000,
   'Job Order engagement - no services rendered during a suspension means nothing is payable.',
   '2016-01-01', ''),
  ('HPR-WS-NW-COS',     'WorkSuspension',    'COS', 0, 0, 0.000,
   'Contract of Service engagement - no services rendered during a suspension means nothing is payable.',
   '2016-01-01', '');

-- Charset and collation deliberately unspecified - see the note in 0003.
