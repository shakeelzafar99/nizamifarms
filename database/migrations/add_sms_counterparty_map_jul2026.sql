-- ## SECTION 10/10 - NF Messages: SMS counterparty memory + sender seeds (Jul-20)
-- ## source file: nizamifarms/database/migrations/add_sms_counterparty_map_jul2026.sql
-- #########################################################################

-- ============================================================================
-- Bank-SMS counterparty tagging + auto-verify support.
--   1. Two new nullable columns on t_ai_bank_sms:
--        counterparty_account = the OTHER party's masked account fragment parsed
--                               from the SMS (e.g. AKBL*9400, MBL*4602,
--                               PK56UNIL01090, EASYPAISA*8150, or a debit-card
--                               merchant key). The stable identity key.
--        auto_reason          = why the system acted on this SMS without a tap
--                               (mapped_customer | proof_pair | remembered_merchant)
--   2. t_ai_counterparty_map — remembers "this account/name belongs to X":
--        entity_type vendor|customer|expense|ignore. Account-key hits are high
--        confidence; name-only rows are SUGGEST-only in code (names collide).
--   3. Seeds the four bank sender numbers from Taimur's phone so no manual
--      teach is needed: 8079=Meezan, 8870=Askari, 14250=HBL, 8287=Alfalah.
--      Keyed on account_last4 (deterministic), NOT on bank names.
-- "Duplicate column" on re-run = already applied (ignore).
-- ============================================================================
ALTER TABLE t_ai_bank_sms
  ADD COLUMN IF NOT EXISTS counterparty_account VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS auto_reason VARCHAR(40) NULL;

CREATE TABLE IF NOT EXISTS `t_ai_counterparty_map` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_key` VARCHAR(64) NULL COMMENT 'normalized counterparty account fragment; NULL for name-only rows',
    `name_key` VARCHAR(120) NULL COMMENT 'normalized upper counterparty name',
    `entity_type` VARCHAR(12) NOT NULL COMMENT 'vendor | customer | expense | ignore',
    `entity_id` BIGINT UNSIGNED NULL COMMENT 'vendor/customer id; NULL for expense/ignore',
    `entity_label` VARCHAR(120) NULL COMMENT 'expense category name or ignored-merchant label',
    `hit_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'times this rule auto-recognized an SMS',
    `last_seen_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ai_map_account` (`account_key`),
    INDEX `idx_ai_map_name` (`name_key`),
    INDEX `idx_ai_map_entity` (`entity_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sender seeds — idempotent (NOT EXISTS guard), resolved by account_last4 so a
-- renamed bank never mis-seeds. If a SELECT inserts 0 rows, that receiving
-- account is missing/inactive — check t_fin_online_receiving_accounts.
INSERT INTO t_ai_sms_senders (sender_id, receiving_account_id, label, is_active, created_at, updated_at)
SELECT '8079', a.id, 'Meezan Bank alerts', 1, NOW(), NOW()
FROM t_fin_online_receiving_accounts a
WHERE a.account_last4 = '4237' AND a.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM t_ai_sms_senders s WHERE s.sender_id = '8079')
LIMIT 1;

INSERT INTO t_ai_sms_senders (sender_id, receiving_account_id, label, is_active, created_at, updated_at)
SELECT '8870', a.id, 'Askari Bank alerts', 1, NOW(), NOW()
FROM t_fin_online_receiving_accounts a
WHERE a.account_last4 = '0971' AND a.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM t_ai_sms_senders s WHERE s.sender_id = '8870')
LIMIT 1;

INSERT INTO t_ai_sms_senders (sender_id, receiving_account_id, label, is_active, created_at, updated_at)
SELECT '14250', a.id, 'HBL alerts', 1, NOW(), NOW()
FROM t_fin_online_receiving_accounts a
WHERE a.account_last4 = '4403' AND a.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM t_ai_sms_senders s WHERE s.sender_id = '14250')
LIMIT 1;

INSERT INTO t_ai_sms_senders (sender_id, receiving_account_id, label, is_active, created_at, updated_at)
SELECT '8287', a.id, 'Bank Alfalah alerts', 1, NOW(), NOW()
FROM t_fin_online_receiving_accounts a
WHERE a.account_last4 = '4343' AND a.is_active = 1
  AND NOT EXISTS (SELECT 1 FROM t_ai_sms_senders s WHERE s.sender_id = '8287')
LIMIT 1;

-- Verify:
--   SELECT sender_id, receiving_account_id, label FROM t_ai_sms_senders;  -- expect the 4 rows
--   DESCRIBE t_ai_counterparty_map;
--   SELECT COUNT(*) FROM t_ai_bank_sms WHERE counterparty_account IS NOT NULL;  -- 0 until SMS arrive

-- ============================================================================
-- t_fin_payment_signal.source is an ENUM('whatsapp','email') — widen it so a
-- bank credit SMS can be a first-class bank-side confirmation. Safe: purely
-- additive to the enum, existing values untouched. Re-run = no-op result.
-- ============================================================================
ALTER TABLE t_fin_payment_signal
  MODIFY COLUMN source ENUM('whatsapp','email','bank_sms') NOT NULL;

-- Verify:
--   SHOW COLUMNS FROM t_fin_payment_signal WHERE Field = 'source';  -- expect the 3 values
