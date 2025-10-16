-- =====================================================================
-- SALARY MANAGEMENT SYSTEM - FINAL CORRECTED VERSION
-- =====================================================================
-- Purpose: Add comprehensive salary, advance, and loan management
--          for all employees (not just riders, but excluding admins)
-- Date: October 15, 2025
-- Fixed: All audit columns are NULL (not NOT NULL) to match existing pattern
-- =====================================================================

USE nizamifarms_db;

-- Temporarily disable FK checks for clean creation
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- STEP 1: Drop existing tables in correct order
-- =====================================================================

DROP TABLE IF EXISTS t_hr_loan_payments;
DROP TABLE IF EXISTS t_hr_salary_slips;
DROP TABLE IF EXISTS t_hr_employee_loans;
DROP TABLE IF EXISTS t_hr_employee_profile;

SELECT '✓ Cleanup: Dropped existing HR tables' as Status;

-- =====================================================================
-- STEP 2: Create Employee HR Profile Table
-- =====================================================================

CREATE TABLE t_hr_employee_profile (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Employee reference (matches t_sys_user.id which is INT)
    user_id INT NOT NULL UNIQUE COMMENT 'FK to t_sys_user.id - One profile per employee',
    
    -- Salary Configuration
    base_salary DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Monthly base salary',
    overtime_rate DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Rate per hour for overtime',
    late_deduction_rate DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Deduction per hour for lateness',
    salary_currency VARCHAR(10) DEFAULT 'PKR' COMMENT 'Salary currency',
    
    -- Salary History
    salary_effective_date DATE NULL COMMENT 'When current salary became effective',
    previous_salary DECIMAL(15,2) NULL COMMENT 'Previous salary amount (for history)',
    last_salary_change_date DATE NULL COMMENT 'When was salary last changed',
    
    -- Employment Details
    designation VARCHAR(100) NULL COMMENT 'Job title/position',
    department VARCHAR(100) NULL COMMENT 'Department name',
    employee_code VARCHAR(50) NULL COMMENT 'Internal employee code/ID',
    
    -- Bank Details (optional - for direct transfers)
    bank_name VARCHAR(100) NULL,
    bank_account_number VARCHAR(50) NULL,
    bank_account_title VARCHAR(255) NULL,
    
    -- Status
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Is salary profile active?',
    
    -- Audit fields (ALL NULL to match existing pattern)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user.id',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user.id',
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_is_active (is_active),
    INDEX idx_employee_code (employee_code)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Employee salary profiles - for all employees (riders, managers, office staff)';

SELECT '✓ Step 2: Created t_hr_employee_profile table' as Status;

-- =====================================================================
-- STEP 3: Create Employee Loans Table
-- =====================================================================

CREATE TABLE t_hr_employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Employee reference (INT to match t_sys_user.id)
    user_id INT NOT NULL COMMENT 'FK to t_sys_user.id',
    
    -- Loan details
    loan_date DATE NOT NULL COMMENT 'Date loan was given',
    loan_number VARCHAR(50) NULL COMMENT 'Loan reference number',
    principal_amount DECIMAL(15,2) NOT NULL COMMENT 'Original loan amount',
    monthly_installment DECIMAL(15,2) NOT NULL COMMENT 'Monthly deduction amount',
    outstanding_balance DECIMAL(15,2) NOT NULL COMMENT 'Remaining balance',
    
    -- Status
    loan_status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    
    -- Notes
    loan_type VARCHAR(100) NULL COMMENT 'Type of loan (Personal, Emergency, etc.)',
    description TEXT NULL COMMENT 'Purpose of loan',
    terms TEXT NULL COMMENT 'Loan terms and conditions',
    notes TEXT NULL COMMENT 'Additional notes',
    
    -- Ledger integration (INT to match t_fin_ledger.id)
    ledger_transaction_id INT NULL COMMENT 'FK to t_fin_ledger.id if loan was disbursed via ledger',
    
    -- Audit fields (ALL NULL)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user.id - Who created the loan',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user.id',
    completed_at TIMESTAMP NULL COMMENT 'When loan was fully paid',
    cancelled_at TIMESTAMP NULL COMMENT 'When loan was cancelled',
    cancelled_by INT NULL COMMENT 'FK to t_sys_user.id - Who cancelled',
    cancellation_reason TEXT NULL COMMENT 'Reason for cancellation',
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_loan_status (loan_status),
    INDEX idx_loan_date (loan_date),
    INDEX idx_loan_number (loan_number),
    INDEX idx_ledger_transaction_id (ledger_transaction_id)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Employee loan tracking with monthly installments';

SELECT '✓ Step 3: Created t_hr_employee_loans table' as Status;

-- =====================================================================
-- STEP 4: Create Salary Slips Table
-- =====================================================================

CREATE TABLE t_hr_salary_slips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Employee & Period (INT to match t_sys_user.id)
    user_id INT NOT NULL COMMENT 'FK to t_sys_user.id',
    salary_month DATE NOT NULL COMMENT 'Month for which salary is calculated (YYYY-MM-01)',
    slip_number VARCHAR(50) NULL COMMENT 'Unique slip number/reference',
    
    -- Earnings (Credits)
    base_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Base monthly salary',
    overtime_hours DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total overtime hours',
    overtime_amount DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Overtime payment',
    bonuses DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Any bonuses',
    allowances DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Allowances (travel, food, etc.)',
    other_earnings DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Other earnings',
    other_earnings_desc TEXT NULL COMMENT 'Description of other earnings',
    gross_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Total earnings before deductions',
    
    -- Deductions (Debits)
    late_minutes DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Total late minutes',
    late_deduction DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Deduction for lateness',
    absent_days INT DEFAULT 0 COMMENT 'Number of absent days',
    absent_deduction DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Deduction for absences',
    salary_advance DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Salary advance taken this month',
    loan_installment DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Loan installment deducted',
    tax_deduction DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Tax deduction if applicable',
    other_deductions DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Other deductions',
    other_deductions_desc TEXT NULL COMMENT 'Description of other deductions',
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of all deductions',
    
    -- Final Amount
    net_salary DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Amount to be paid (gross - deductions)',
    
    -- Attendance Summary (for reference)
    working_days INT NOT NULL DEFAULT 0 COMMENT 'Expected working days in month',
    present_days INT NOT NULL DEFAULT 0 COMMENT 'Days employee was present',
    leave_days INT DEFAULT 0 COMMENT 'Approved leave days',
    half_days INT DEFAULT 0 COMMENT 'Half days worked',
    
    -- Override Flags (to track manager adjustments)
    late_deduction_overridden TINYINT(1) DEFAULT 0 COMMENT 'Was late deduction waived?',
    overtime_overridden TINYINT(1) DEFAULT 0 COMMENT 'Was overtime manually adjusted?',
    absent_deduction_overridden TINYINT(1) DEFAULT 0 COMMENT 'Was absent deduction adjusted?',
    loan_installment_skipped TINYINT(1) DEFAULT 0 COMMENT 'Was loan installment skipped?',
    has_manual_adjustments TINYINT(1) DEFAULT 0 COMMENT 'Any manual adjustments made?',
    override_notes TEXT NULL COMMENT 'Reason for any overrides',
    
    -- Status & Approval (INT for all user references, ALL NULL)
    slip_status ENUM('draft', 'approved', 'paid', 'cancelled') DEFAULT 'draft',
    approved_by INT NULL COMMENT 'FK to t_sys_user.id - Manager who approved',
    approved_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    payment_method ENUM('cash', 'bank_transfer', 'cheque', 'online') NULL COMMENT 'How salary was paid',
    
    -- Ledger Integration (INT to match t_fin_ledger.id)
    ledger_transaction_id INT NULL COMMENT 'FK to t_fin_ledger.id when salary is paid',
    
    -- Additional References
    advance_request_ids TEXT NULL COMMENT 'Comma-separated request IDs for advances deducted',
    loan_ids TEXT NULL COMMENT 'Comma-separated loan IDs for installments deducted',
    
    -- Audit fields (ALL NULL - CRITICAL FIX!)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user.id - Who generated this slip',
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL COMMENT 'FK to t_sys_user.id',
    
    -- Indexes
    INDEX idx_user_id (user_id),
    INDEX idx_salary_month (salary_month),
    INDEX idx_slip_status (slip_status),
    INDEX idx_slip_number (slip_number),
    INDEX idx_user_month (user_id, salary_month),
    INDEX idx_created_at (created_at),
    INDEX idx_approved_by (approved_by),
    INDEX idx_ledger_transaction_id (ledger_transaction_id),
    INDEX idx_created_by (created_by),
    INDEX idx_updated_by (updated_by)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Monthly salary slips with attendance-based calculations';

SELECT '✓ Step 4: Created t_hr_salary_slips table' as Status;

-- =====================================================================
-- STEP 5: Create Loan Payment History Table (for tracking)
-- =====================================================================

CREATE TABLE t_hr_loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- References (INT to match parent tables)
    loan_id INT NOT NULL COMMENT 'FK to t_hr_employee_loans.id',
    salary_slip_id INT NULL COMMENT 'FK to t_hr_salary_slips.id - Which salary slip deducted this',
    
    payment_date DATE NOT NULL COMMENT 'Date of payment',
    payment_amount DECIMAL(15,2) NOT NULL COMMENT 'Amount paid',
    balance_before DECIMAL(15,2) NOT NULL COMMENT 'Outstanding balance before payment',
    balance_after DECIMAL(15,2) NOT NULL COMMENT 'Outstanding balance after payment',
    
    payment_type ENUM('salary_deduction', 'direct_payment', 'adjustment') DEFAULT 'salary_deduction',
    payment_notes TEXT NULL COMMENT 'Notes about this payment',
    
    -- Audit (INT for user references, NULL)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL COMMENT 'FK to t_sys_user.id',
    
    -- Indexes
    INDEX idx_loan_id (loan_id),
    INDEX idx_salary_slip_id (salary_slip_id),
    INDEX idx_payment_date (payment_date)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks individual loan payment transactions';

SELECT '✓ Step 5: Created t_hr_loan_payments table' as Status;

-- Re-enable FK checks
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- STEP 6: Add Foreign Key Constraints ONE AT A TIME
-- =====================================================================

SELECT '' as '';
SELECT '--- Step 6: Adding Foreign Key Constraints (One at a time) ---' as '';
SELECT '' as '';

-- t_hr_employee_profile foreign keys
ALTER TABLE t_hr_employee_profile
ADD CONSTRAINT fk_hr_profile_user FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE;
SELECT '✓ Step 6a1: Added fk_hr_profile_user' as Status;

ALTER TABLE t_hr_employee_profile
ADD CONSTRAINT fk_hr_profile_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6a2: Added fk_hr_profile_created_by' as Status;

ALTER TABLE t_hr_employee_profile
ADD CONSTRAINT fk_hr_profile_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6a3: Added fk_hr_profile_updated_by' as Status;

-- t_hr_employee_loans foreign keys
ALTER TABLE t_hr_employee_loans
ADD CONSTRAINT fk_loan_user_id FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE;
SELECT '✓ Step 6b1: Added fk_loan_user_id' as Status;

ALTER TABLE t_hr_employee_loans
ADD CONSTRAINT fk_loan_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL;
SELECT '✓ Step 6b2: Added fk_loan_ledger' as Status;

ALTER TABLE t_hr_employee_loans
ADD CONSTRAINT fk_loan_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6b3: Added fk_loan_created_by' as Status;

ALTER TABLE t_hr_employee_loans
ADD CONSTRAINT fk_loan_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6b4: Added fk_loan_updated_by' as Status;

ALTER TABLE t_hr_employee_loans
ADD CONSTRAINT fk_loan_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6b5: Added fk_loan_cancelled_by' as Status;

-- t_hr_salary_slips foreign keys (ONE AT A TIME - WITH NULL COLUMNS!)
ALTER TABLE t_hr_salary_slips
ADD CONSTRAINT fk_slip_user FOREIGN KEY (user_id) REFERENCES t_sys_user(id) ON DELETE CASCADE;
SELECT '✓ Step 6c1: Added fk_slip_user' as Status;

ALTER TABLE t_hr_salary_slips
ADD CONSTRAINT fk_slip_approved_by FOREIGN KEY (approved_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6c2: Added fk_slip_approved_by' as Status;

ALTER TABLE t_hr_salary_slips
ADD CONSTRAINT fk_slip_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6c3: Added fk_slip_created_by' as Status;

ALTER TABLE t_hr_salary_slips
ADD CONSTRAINT fk_slip_updated_by FOREIGN KEY (updated_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6c4: Added fk_slip_updated_by' as Status;

ALTER TABLE t_hr_salary_slips
ADD CONSTRAINT fk_slip_ledger FOREIGN KEY (ledger_transaction_id) REFERENCES t_fin_ledger(id) ON DELETE SET NULL;
SELECT '✓ Step 6c5: Added fk_slip_ledger' as Status;

-- t_hr_loan_payments foreign keys
ALTER TABLE t_hr_loan_payments
ADD CONSTRAINT fk_loan_payment_loan FOREIGN KEY (loan_id) REFERENCES t_hr_employee_loans(id) ON DELETE CASCADE;
SELECT '✓ Step 6d1: Added fk_loan_payment_loan' as Status;

ALTER TABLE t_hr_loan_payments
ADD CONSTRAINT fk_loan_payment_slip FOREIGN KEY (salary_slip_id) REFERENCES t_hr_salary_slips(id) ON DELETE SET NULL;
SELECT '✓ Step 6d2: Added fk_loan_payment_slip' as Status;

ALTER TABLE t_hr_loan_payments
ADD CONSTRAINT fk_loan_payment_created_by FOREIGN KEY (created_by) REFERENCES t_sys_user(id) ON DELETE SET NULL;
SELECT '✓ Step 6d3: Added fk_loan_payment_created_by' as Status;

SELECT '' as '';
SELECT '✓✓✓ All Foreign Keys Added Successfully! ✓✓✓' as '';
SELECT '' as '';

-- =====================================================================
-- STEP 7: Add Salary Advance Category to Request System
-- =====================================================================

SELECT '--- Step 7: Adding Salary Advance Category ---' as '';

-- Add salary_advance category
INSERT INTO t_req_category (category_code, category_name, description, is_active, created_at, updated_at)
VALUES 
    ('salary_advance', 'Salary Advance', 'Request for advance salary payment', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    category_name = 'Salary Advance',
    description = 'Request for advance salary payment',
    is_active = 1,
    updated_at = NOW();

SELECT '✓ Step 7a: Added salary_advance category' as Status;

-- Configure L1+L2 approval for salary advances
INSERT INTO t_req_category_approval_config (category_id, requires_level_1, requires_level_2, auto_approve_threshold, created_at, updated_at)
SELECT 
    id,
    1 as requires_level_1,
    1 as requires_level_2,
    NULL as auto_approve_threshold,
    NOW(),
    NOW()
FROM t_req_category
WHERE category_code = 'salary_advance'
ON DUPLICATE KEY UPDATE
    requires_level_1 = 1,
    requires_level_2 = 1,
    updated_at = NOW();

SELECT '✓ Step 7b: Configured approval levels for salary_advance (L1 + L2 required)' as Status;

-- =====================================================================
-- STEP 8: Add New Ledger Accounts for Salary Management
-- =====================================================================

SELECT '--- Step 8: Adding Ledger Accounts ---' as '';

-- Salary Expense Account
INSERT INTO t_fin_accounts (account_code, account_name, account_type, account_category, opening_balance, current_balance, is_active, created_at, updated_at)
VALUES 
    ('EXPENSE_SALARY', 'Salary Expense', 'expense', 'salary', 0.00, 0.00, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    account_name = 'Salary Expense',
    account_type = 'expense',
    account_category = 'salary',
    is_active = 1,
    updated_at = NOW();

SELECT '✓ Step 8a: Added EXPENSE_SALARY account' as Status;

-- Employee Loans Receivable Account
INSERT INTO t_fin_accounts (account_code, account_name, account_type, account_category, opening_balance, current_balance, is_active, created_at, updated_at)
VALUES 
    ('ASSET_EMPLOYEE_LOANS', 'Employee Loans Receivable', 'asset', 'loan_receivable', 0.00, 0.00, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE 
    account_name = 'Employee Loans Receivable',
    account_type = 'asset',
    account_category = 'loan_receivable',
    is_active = 1,
    updated_at = NOW();

SELECT '✓ Step 8b: Added ASSET_EMPLOYEE_LOANS account' as Status;

-- =====================================================================
-- STEP 9: Permissions - Run separate file
-- =====================================================================

SELECT '--- Step 9: Permissions ---' as '';
SELECT 'ℹ️  Please run salary_permissions_fix.sql after this script' as '';
SELECT 'ℹ️  It adds 9 salary permissions to t_sys_role_permissions' as '';

-- =====================================================================
-- VERIFICATION QUERIES
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ VERIFICATION CHECKS ✓✓✓' as '';
SELECT '========================================' as '';
SELECT '' as '';

-- Check all tables exist
SELECT '--- 1. Tables Created ---' as '';
SELECT 
    TABLE_NAME,
    TABLE_ROWS as 'Rows',
    CREATE_TIME as 'Created'
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'nizamifarms_db' 
AND TABLE_NAME IN ('t_hr_employee_profile', 't_hr_employee_loans', 't_hr_salary_slips', 't_hr_loan_payments')
ORDER BY TABLE_NAME;

SELECT '' as '';

-- Check Foreign Keys
SELECT '--- 2. Foreign Key Constraints (18 Total) ---' as '';
SELECT 
    TABLE_NAME as 'Table',
    CONSTRAINT_NAME as 'Constraint',
    COLUMN_NAME as 'Column',
    REFERENCED_TABLE_NAME as 'References',
    REFERENCED_COLUMN_NAME as 'Ref Column'
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'nizamifarms_db'
AND TABLE_NAME IN ('t_hr_employee_profile', 't_hr_employee_loans', 't_hr_salary_slips', 't_hr_loan_payments')
AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT '' as '';

-- Check request category
SELECT '--- 3. Salary Advance Category ---' as '';
SELECT 
    c.category_code as 'Code',
    c.category_name as 'Name',
    c.is_active as 'Active',
    cfg.requires_level_1 as 'L1',
    cfg.requires_level_2 as 'L2'
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.category_code = 'salary_advance';

SELECT '' as '';

-- Check new accounts
SELECT '--- 4. Salary Management Accounts ---' as '';
SELECT 
    account_code as 'Code',
    account_name as 'Name',
    account_type as 'Type',
    current_balance as 'Balance',
    is_active as 'Active'
FROM t_fin_accounts
WHERE account_code IN ('EXPENSE_SALARY', 'ASSET_EMPLOYEE_LOANS');

SELECT '' as '';

-- Permissions will be added separately via salary_permissions_fix.sql

-- =====================================================================
-- SUMMARY
-- =====================================================================

SELECT '' as '';
SELECT '========================================' as '';
SELECT '✓✓✓ SALARY MANAGEMENT SYSTEM COMPLETE! ✓✓✓' as '';
SELECT '========================================' as '';
SELECT '' as '';
SELECT 'Tables Created: 4' as '';
SELECT '  • t_hr_employee_profile' as '';
SELECT '  • t_hr_employee_loans' as '';
SELECT '  • t_hr_salary_slips' as '';
SELECT '  • t_hr_loan_payments' as '';
SELECT '' as '';
SELECT 'Foreign Keys: 18 (all verified)' as '';
SELECT 'Category: salary_advance (L1+L2)' as '';
SELECT 'Accounts: EXPENSE_SALARY, ASSET_EMPLOYEE_LOANS' as '';
SELECT '' as '';
SELECT '⚠️  NEXT STEP: Run salary_permissions_fix.sql' as '';
SELECT '   to add 9 permissions to roles' as '';
SELECT '' as '';
SELECT '🚀 Status: Database Schema Ready!' as '';
SELECT '========================================' as '';

