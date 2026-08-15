-- GYF Welfare Management System Database Schema
-- Canonical schema for the Vercel web deployment.
-- Kept in sync with the live TiDB Cloud cluster.
-- Run order: database.sql -> tables.sql -> init_indexes.sql
-- (All statements use IF NOT EXISTS / DROP INDEX IF EXISTS and are safe to re-run.)

-- Members table (matches live schema)
CREATE TABLE IF NOT EXISTS members (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id VARCHAR(20) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    dob DATE NOT NULL,
    gender ENUM('Male','Female','Other') NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    address TEXT NOT NULL,
    occupation VARCHAR(100) DEFAULT NULL,
    emergency_contact_name VARCHAR(100) NOT NULL,
    emergency_contact_relationship VARCHAR(50) NOT NULL,
    emergency_contact_phone VARCHAR(20) NOT NULL,
    passport_photo VARCHAR(255) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    two_fa_secret VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY member_id (member_id),
    UNIQUE KEY email (email),
    UNIQUE KEY phone (phone),
    KEY idx_members_email (email),
    KEY idx_members_member_id (member_id),
    KEY idx_members_2fa (two_fa_secret)
);

-- Settings table (singleton row enforced by id=1)
CREATE TABLE IF NOT EXISTS settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    annual_amount DECIMAL(10,2) NOT NULL DEFAULT 240.00,
    monthly_amount DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY singleton (id)
);

-- Per-year welfare targets (calendar-year specific)
CREATE TABLE IF NOT EXISTS yearly_targets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    year INT NOT NULL,
    annual_amount DECIMAL(10,2) NOT NULL DEFAULT 240.00,
    monthly_amount DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_year (year)
);

-- Seed current year target from settings if missing
INSERT INTO yearly_targets (year, annual_amount, monthly_amount)
SELECT YEAR(CURDATE()), annual_amount, monthly_amount FROM settings WHERE id = 1
ON DUPLICATE KEY UPDATE annual_amount = VALUES(annual_amount), monthly_amount = VALUES(monthly_amount);

-- Transactions table (matches live schema, including `status` and `notes`)
CREATE TABLE IF NOT EXISTS transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    receipt_no VARCHAR(50) NOT NULL,
    member_id VARCHAR(20) NOT NULL,
    treasurer_id VARCHAR(20) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash','Mobile Money','Bank Transfer','Card') NOT NULL,
    billing_cycle_month INT DEFAULT NULL,
    billing_cycle_year INT NOT NULL,
    notes TEXT DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'active',
    transaction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY receipt_no (receipt_no),
    KEY idx_member_transactions (member_id),
    KEY idx_treasurer_transactions (treasurer_id),
    KEY idx_billing_cycle (billing_cycle_year, billing_cycle_month),
    KEY idx_transactions_member_date (member_id, transaction_date),
    KEY idx_transactions_billing_cycle (billing_cycle_year, billing_cycle_month),
    KEY idx_transactions_status (status),
    KEY idx_transactions_receipt_no (receipt_no),
    KEY idx_transactions_member_year (member_id, billing_cycle_year, status)
);

-- Audit logs table (core table referenced by logAudit(), checkRateLimit(), etc.)
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(20) NOT NULL,
    action TEXT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT DEFAULT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_logs (user_id),
    KEY idx_timestamp (timestamp),
    KEY idx_audit_logs_timestamp (timestamp),
    KEY idx_audit_logs_user_action (user_id, action(50), timestamp),
    KEY idx_audit_logs_ip (ip_address, timestamp)
);

-- Password resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    member_id VARCHAR(20) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_member_id (member_id),
    KEY idx_token (token),
    KEY idx_password_resets_member (member_id, expires_at),
    KEY idx_password_resets_token (token)
);

-- Insert default settings (id=1 singleton)
INSERT INTO settings (id, annual_amount, monthly_amount) VALUES (1, 240.00, 20.00)
ON DUPLICATE KEY UPDATE annual_amount = VALUES(annual_amount), monthly_amount = VALUES(monthly_amount);


-- Member status and deletion tracking
ALTER TABLE members 
ADD COLUMN IF NOT EXISTS status ENUM('active','suspended','deactivated','deleted') NOT NULL DEFAULT 'active' AFTER phone,
ADD COLUMN IF NOT EXISTS deletion_count INT NOT NULL DEFAULT 0 AFTER status,
ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMP NULL AFTER deletion_count,
ADD COLUMN IF NOT EXISTS suspended_by VARCHAR(20) NULL AFTER suspended_at,
ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL AFTER suspended_by,
ADD COLUMN IF NOT EXISTS deleted_by VARCHAR(20) NULL AFTER deleted_at,
ADD INDEX IF NOT EXISTS idx_members_status (status);



-- Executive tier columns
ALTER TABLE members 
ADD COLUMN IF NOT EXISTS executive_level ENUM('none','gold','silver') NOT NULL DEFAULT 'none' AFTER deleted_by,
ADD COLUMN IF NOT EXISTS executive_promoted_at TIMESTAMP NULL AFTER executive_level,
ADD COLUMN IF NOT EXISTS executive_promoted_by VARCHAR(20) NULL AFTER executive_promoted_at,
ADD INDEX IF NOT EXISTS idx_executive_level (executive_level);

-- Executive target amounts (global defaults)
ALTER TABLE settings 
ADD COLUMN IF NOT EXISTS executive_gold_annual DECIMAL(10,2) NOT NULL DEFAULT 500.00 AFTER monthly_amount,
ADD COLUMN IF NOT EXISTS executive_gold_monthly DECIMAL(10,2) NOT NULL DEFAULT 50.00 AFTER executive_gold_annual,
ADD COLUMN IF NOT EXISTS executive_silver_annual DECIMAL(10,2) NOT NULL DEFAULT 350.00 AFTER executive_gold_monthly,
ADD COLUMN IF NOT EXISTS executive_silver_monthly DECIMAL(10,2) NOT NULL DEFAULT 35.00 AFTER executive_silver_annual;

-- Seed executive targets into settings if missing
INSERT INTO settings (id, annual_amount, monthly_amount, executive_gold_annual, executive_gold_monthly, executive_silver_annual, executive_silver_monthly)
SELECT 1, 240.00, 20.00, 500.00, 50.00, 350.00, 35.00
ON DUPLICATE KEY UPDATE 
    executive_gold_annual = COALESCE(VALUES(executive_gold_annual), executive_gold_annual),
    executive_gold_monthly = COALESCE(VALUES(executive_gold_monthly), executive_gold_monthly),
    executive_silver_annual = COALESCE(VALUES(executive_silver_annual), executive_silver_annual),
    executive_silver_monthly = COALESCE(VALUES(executive_silver_monthly), executive_silver_monthly);

-- Per-year executive targets
ALTER TABLE yearly_targets 
ADD COLUMN IF NOT EXISTS executive_gold_annual DECIMAL(10,2) NOT NULL DEFAULT 500.00 AFTER monthly_amount,
ADD COLUMN IF NOT EXISTS executive_gold_monthly DECIMAL(10,2) NOT NULL DEFAULT 50.00 AFTER executive_gold_annual,
ADD COLUMN IF NOT EXISTS executive_silver_annual DECIMAL(10,2) NOT NULL DEFAULT 350.00 AFTER executive_gold_monthly,
ADD COLUMN IF NOT EXISTS executive_silver_monthly DECIMAL(10,2) NOT NULL DEFAULT 35.00 AFTER executive_silver_annual;
