-- ============================================================================
-- Digos City Government - Employee Payroll Management System (PHP/MySQL)
-- Database schema + first-run seed data.
--   mysql -u root -p < schema.sql
-- Column names deliberately match the frontend field names (PascalCase).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS digos_payroll
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE digos_payroll;

CREATE TABLE IF NOT EXISTS Employees (
  EmployeeID     VARCHAR(40) PRIMARY KEY,
  EmployeeNo     VARCHAR(40)  DEFAULT '',
  TIN            VARCHAR(30)  DEFAULT '',
  GSIS           VARCHAR(30)  DEFAULT '',
  PhilHealth     VARCHAR(30)  DEFAULT '',
  PagIBIG        VARCHAR(30)  DEFAULT '',
  LastName       VARCHAR(80)  NOT NULL,
  FirstName      VARCHAR(80)  NOT NULL,
  MiddleName     VARCHAR(80)  DEFAULT '',
  Suffix         VARCHAR(20)  DEFAULT '',
  Birthdate      DATE         NULL,
  Gender         VARCHAR(10)  DEFAULT '',
  Address        VARCHAR(255) DEFAULT '',
  Contact        VARCHAR(40)  DEFAULT '',
  Email          VARCHAR(120) DEFAULT '',
  OfficeCode     VARCHAR(20)  NOT NULL,
  Department     VARCHAR(120) DEFAULT '',
  Division       VARCHAR(120) DEFAULT '',
  FunctionName   VARCHAR(120) DEFAULT '',
  EmploymentType VARCHAR(30)  NOT NULL,
  Position       VARCHAR(120) NOT NULL,
  SalaryRate     DECIMAL(12,2) DEFAULT 0,
  DailyRate      DECIMAL(12,2) DEFAULT 0,
  HourlyRate     DECIMAL(12,2) DEFAULT 0,
  MonthlyRate    DECIMAL(12,2) DEFAULT 0,
  DateHired      DATE NULL,
  ContractStart  DATE NULL,
  ContractEnd    DATE NULL,
  Status         VARCHAR(20) DEFAULT 'Active',
  PhotoURL       VARCHAR(255) DEFAULT '',
  SignatureURL   VARCHAR(255) DEFAULT '',
  Remarks        VARCHAR(255) DEFAULT '',
  CreatedAt      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_office (OfficeCode), INDEX idx_status (Status),
  INDEX idx_name (LastName, FirstName)
);

CREATE TABLE IF NOT EXISTS Offices (
  OfficeCode VARCHAR(20) PRIMARY KEY,
  OfficeName VARCHAR(160) NOT NULL,
  Department VARCHAR(120) DEFAULT '',
  Division   VARCHAR(120) DEFAULT '',
  FunctionName VARCHAR(120) DEFAULT '',
  OfficeHead VARCHAR(120) DEFAULT '',
  Status     VARCHAR(20) DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS Departments (
  DeptCode  VARCHAR(20) PRIMARY KEY,
  DeptName  VARCHAR(160) NOT NULL,
  OfficeCode VARCHAR(20) DEFAULT '',
  Head      VARCHAR(120) DEFAULT '',
  Status    VARCHAR(20) DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS Functions (
  FunctionCode VARCHAR(20) PRIMARY KEY,
  FunctionName VARCHAR(120) NOT NULL,
  Description  VARCHAR(255) DEFAULT '',
  Status       VARCHAR(20) DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS Timekeepers (
  TimekeeperID VARCHAR(40) PRIMARY KEY,
  EmployeeName VARCHAR(160) NOT NULL,
  OfficeCode   VARCHAR(20) NOT NULL,
  Department   VARCHAR(120) DEFAULT '',
  Contact      VARCHAR(40) DEFAULT '',
  Email        VARCHAR(120) DEFAULT '',
  Status       VARCHAR(20) DEFAULT 'Active'
);

CREATE TABLE IF NOT EXISTS PayrollPeriods (
  PeriodID     VARCHAR(40) PRIMARY KEY,
  PayrollMonth VARCHAR(20) NOT NULL,
  PayrollYear  INT NOT NULL,
  StartDate    DATE NOT NULL,
  EndDate      DATE NOT NULL,
  Status       VARCHAR(20) DEFAULT 'Open',
  CreatedAt    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Payroll (
  PayrollNo       VARCHAR(30) PRIMARY KEY,
  PeriodID        VARCHAR(40) NOT NULL,
  OfficeCode      VARCHAR(20) NOT NULL,
  Department      VARCHAR(120) DEFAULT '',
  FunctionName    VARCHAR(120) DEFAULT '',
  TimekeeperID    VARCHAR(40) DEFAULT '',
  DateCreated     DATETIME DEFAULT CURRENT_TIMESTAMP,
  PreparedBy      VARCHAR(160) DEFAULT '',
  Remarks         VARCHAR(255) DEFAULT '',
  Status          VARCHAR(20) DEFAULT 'Draft',
  TotalGross      DECIMAL(14,2) DEFAULT 0,
  TotalDeductions DECIMAL(14,2) DEFAULT 0,
  TotalNet        DECIMAL(14,2) DEFAULT 0,
  ApprovedBy      VARCHAR(160) DEFAULT '',
  ApprovedAt      DATETIME NULL,
  ReleasedAt      DATETIME NULL,
  PdfFileId       VARCHAR(120) DEFAULT '',
  INDEX idx_period (PeriodID), INDEX idx_pstatus (Status)
);

CREATE TABLE IF NOT EXISTS PayrollDetails (
  DetailID         VARCHAR(40) PRIMARY KEY,
  PayrollNo        VARCHAR(30) NOT NULL,
  LineNo           INT NOT NULL,
  EmployeeID       VARCHAR(40) NOT NULL,
  EmployeeName     VARCHAR(200) NOT NULL,
  Position         VARCHAR(120) DEFAULT '',
  SalaryRate       DECIMAL(12,2) DEFAULT 0,
  DaysWorked       DECIMAL(6,2) DEFAULT 0,
  HoursWorked      DECIMAL(6,2) DEFAULT 0,
  OvertimeHours    DECIMAL(6,2) DEFAULT 0,
  LateMinutes      DECIMAL(8,2) DEFAULT 0,
  UndertimeMinutes DECIMAL(8,2) DEFAULT 0,
  AbsentDays       DECIMAL(6,2) DEFAULT 0,
  GrossPay         DECIMAL(12,2) DEFAULT 0,
  Tax              DECIMAL(12,2) DEFAULT 0,
  CashAdvance      DECIMAL(12,2) DEFAULT 0,
  OtherDeductions  DECIMAL(12,2) DEFAULT 0,
  TotalDeductions  DECIMAL(12,2) DEFAULT 0,
  NetPay           DECIMAL(12,2) DEFAULT 0,
  Remarks          VARCHAR(255) DEFAULT '',
  INDEX idx_payroll (PayrollNo), INDEX idx_emp (EmployeeID)
);

CREATE TABLE IF NOT EXISTS Users (
  Email        VARCHAR(120) PRIMARY KEY,
  FullName     VARCHAR(160) NOT NULL,
  Role         VARCHAR(40) NOT NULL,
  OfficeCode   VARCHAR(20) DEFAULT '',
  Status       VARCHAR(20) DEFAULT 'Active',
  PasswordHash VARCHAR(255) NOT NULL,
  LastLogin    DATETIME NULL,
  CreatedAt    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Logs (
  LogID     BIGINT AUTO_INCREMENT PRIMARY KEY,
  Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  User      VARCHAR(120) DEFAULT '',
  Action    VARCHAR(60) DEFAULT '',
  Module    VARCHAR(60) DEFAULT '',
  Details   TEXT,
  IPAddress VARCHAR(60) DEFAULT '',
  INDEX idx_time (Timestamp)
);

CREATE TABLE IF NOT EXISTS Settings (
  KeyName   VARCHAR(60) PRIMARY KEY,
  Value     TEXT,
  UpdatedAt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS Backup (
  BackupID  VARCHAR(40) PRIMARY KEY,
  Timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  FileID    VARCHAR(120) NOT NULL,
  FileName  VARCHAR(160) NOT NULL,
  User      VARCHAR(120) DEFAULT '',
  Type      VARCHAR(30) DEFAULT 'Manual'
);

CREATE TABLE IF NOT EXISTS Counters (
  YearNo INT PRIMARY KEY,
  LastNo INT NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------------
-- Seed data (safe to re-run: INSERT IGNORE never overwrites)
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO Settings (KeyName, Value) VALUES
  ('GovernmentName', 'CITY GOVERNMENT OF DIGOS'),
  ('GovernmentSubtitle', 'Digos City, Davao del Sur'),
  ('OfficeLogoUrl', ''),
  ('PayrollPrefix', 'PR'),
  ('DefaultTaxRate', '0'),
  ('OvertimeMultiplier', '1.25'),
  ('WorkingDaysPerMonth', '22'),
  ('WorkingHoursPerDay', '8'),
  ('MaxEmployeesPerPayroll', '15'),
  ('SessionTimeoutMinutes', '30'),
  ('SystemTheme', 'light'),
  ('BackupSchedule', 'weekly'),
  ('SignatoryPreparedBy', ''),
  ('SignatoryPreparedByTitle', 'Timekeeper'),
  ('SignatoryCertifiedBy', ''),
  ('SignatoryCertifiedByTitle', 'City Treasurer'),
  ('SignatoryApprovedBy', ''),
  ('SignatoryApprovedByTitle', 'City Mayor'),
  ('SignatoryFundsAvailable', ''),
  ('SignatoryFundsAvailableTitle', 'City Accountant'),
  ('SignatoryBudgetOfficer', ''),
  ('SignatoryBudgetOfficerTitle', 'City Budget Officer'),
  ('PagibigEmployerId', ''),
  ('GovernmentAddress', ''),
  ('GovernmentContact', ''),
  ('GovernmentEmail', ''),
  ('CafoaExpenseCode', '');

INSERT IGNORE INTO Functions (FunctionCode, FunctionName, Description, Status) VALUES
  ('GEN', 'General Fund', 'General Fund funded personnel', 'Active'),
  ('SEF', 'Special Education Fund', 'SEF funded personnel', 'Active'),
  ('TF',  'Trust Fund', 'Trust Fund funded personnel', 'Active');

-- Bootstrap administrator: admin@digos.gov.ph / ChangeMe!123
-- (hash generated with password_hash(); CHANGE THIS PASSWORD after first login)
INSERT IGNORE INTO Users (Email, FullName, Role, Status, PasswordHash) VALUES
  ('admin@digos.gov.ph', 'System Administrator', 'Administrator', 'Active',
   '$2y$12$raxsLgp6tJjH.PYi0YMIreK8pn82TPQ.RoZM6it9NjJLyRH2zzepS');
