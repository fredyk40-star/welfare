-- Performance Optimization Indexes
-- Run this SQL to add indexes for better query performance
-- This script is safe to run multiple times

-- Transactions table indexes
DROP INDEX IF EXISTS idx_transactions_member_date ON transactions;
DROP INDEX IF EXISTS idx_transactions_billing_cycle ON transactions;
DROP INDEX IF EXISTS idx_transactions_status ON transactions;
DROP INDEX IF EXISTS idx_transactions_receipt_no ON transactions;
DROP INDEX IF EXISTS idx_transactions_member_year ON transactions;

CREATE INDEX idx_transactions_member_date ON transactions(member_id, transaction_date DESC);
CREATE INDEX idx_transactions_billing_cycle ON transactions(billing_cycle_year, billing_cycle_month);
CREATE INDEX idx_transactions_status ON transactions(status);
CREATE INDEX idx_transactions_receipt_no ON transactions(receipt_no);
CREATE INDEX idx_transactions_member_year ON transactions(member_id, billing_cycle_year, status);

-- Audit logs indexes
DROP INDEX IF EXISTS idx_audit_logs_timestamp ON audit_logs;
DROP INDEX IF EXISTS idx_audit_logs_user_action ON audit_logs;
DROP INDEX IF EXISTS idx_audit_logs_ip ON audit_logs;

CREATE INDEX idx_audit_logs_timestamp ON audit_logs(timestamp DESC);
CREATE INDEX idx_audit_logs_user_action ON audit_logs(user_id, action(50), timestamp);
CREATE INDEX idx_audit_logs_ip ON audit_logs(ip_address, timestamp);

-- Password resets indexes
DROP INDEX IF EXISTS idx_password_resets_member ON password_resets;
DROP INDEX IF EXISTS idx_password_resets_token ON password_resets;

CREATE INDEX idx_password_resets_member ON password_resets(member_id, expires_at);
CREATE INDEX idx_password_resets_token ON password_resets(token);

-- Members table indexes
DROP INDEX IF EXISTS idx_members_email ON members;
DROP INDEX IF EXISTS idx_members_member_id ON members;
DROP INDEX IF EXISTS idx_members_2fa ON members;

CREATE INDEX idx_members_email ON members(email);
CREATE INDEX idx_members_member_id ON members(member_id);
CREATE INDEX idx_members_2fa ON members(two_fa_secret);
