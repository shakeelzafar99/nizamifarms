-- ============================================================
-- ONLINE RECEIVING ACCOUNTS - Feb 10, 2026
-- Tracks which bank/account received online payments
-- ============================================================

-- PART A: Create the receiving accounts lookup table
-- ============================================================
CREATE TABLE IF NOT EXISTS t_fin_online_receiving_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    short_code VARCHAR(20) NOT NULL,
    color_hex VARCHAR(7) DEFAULT '#3B82F6',
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PART B: Add receiving_account_id to the ledger table
-- ============================================================
-- This nullable FK allows tagging which bank received the online payment during approval
ALTER TABLE t_fin_ledger ADD COLUMN IF NOT EXISTS receiving_account_id INT UNSIGNED NULL AFTER bill_image;

-- Add foreign key (safe: only if not exists)
-- If your MySQL version doesn't support IF NOT EXISTS for constraints, remove the next line and run manually
ALTER TABLE t_fin_ledger ADD CONSTRAINT fk_ledger_receiving_account 
    FOREIGN KEY (receiving_account_id) REFERENCES t_fin_online_receiving_accounts(id) ON DELETE SET NULL;

-- PART C: Seed some common bank accounts (adjust names as needed)
-- ============================================================
INSERT INTO t_fin_online_receiving_accounts (name, short_code, color_hex, is_active, sort_order) VALUES
    ('HBL', 'HBL', '#1E40AF', 1, 1),
    ('Meezan Bank', 'MEEZAN', '#059669', 1, 2),
    ('JazzCash', 'JAZZ', '#DC2626', 1, 3),
    ('Easypaisa', 'EP', '#16A34A', 1, 4),
    ('Bank Alfalah', 'ALFA', '#7C3AED', 1, 5);
