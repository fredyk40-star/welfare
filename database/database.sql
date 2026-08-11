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
    UNIQUE KEY unique_billing (member_id, billing_cycle_month, billing_cycle_year),
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

-- Insert default treasurer account (password: Welfare2024!)
-- Password hash corresponds to 'Welfare2024!' in the canonical seed.
INSERT IGNORE INTO members (member_id, full_name, email, password, two_fa_secret, dob, gender, phone, address, emergency_contact_name, emergency_contact_relationship, emergency_contact_phone)
VALUES ('GYF-ADMIN', 'System Treasurer', 'treasurer@gyf.org',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        NULL, '1990-01-01', 'Male', '0000000000', 'Admin Address', 'Emergency Contact', 'Relationship', '0000000000');
