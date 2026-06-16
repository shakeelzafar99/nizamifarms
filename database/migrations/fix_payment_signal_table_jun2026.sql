-- =====================================================
-- FIX: t_fin_payment_signal missing `updated_at` (and any other
--      columns) on production.
-- Date: June 16, 2026
--
-- WHY
-- ---
-- The production table was created from an earlier version of
-- create_payment_signal_and_alias_jun2026.sql that did not include the
-- `updated_at` column. Because that file uses CREATE TABLE IF NOT EXISTS,
-- re-uploading it does NOT alter the existing table, so inserts kept failing:
--
--   SQLSTATE[42S22]: Unknown column 'updated_at' in 'INSERT INTO' ...
--
-- The Eloquent model (App\Models\FIN\PaymentSignal) has $timestamps = true,
-- so it always writes created_at + updated_at.
--
-- SAFE TO DROP: every insert has been failing, so the table holds NO rows.
-- We drop and recreate it with the exact, current schema. The alias and
-- cursor tables are untouched.
--
-- AFTER RUNNING: re-send the WhatsApp payment screenshot and check:
--   SELECT * FROM t_fin_payment_signal ORDER BY id DESC LIMIT 5;
-- =====================================================

DROP TABLE IF EXISTS `t_fin_payment_signal`;

CREATE TABLE `t_fin_payment_signal` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source`                        ENUM('whatsapp','email') NOT NULL
                                    COMMENT 'Where this signal came from.',

    -- ── Source linkage (one set, depending on source) ──
    `wa_message_id`                 BIGINT UNSIGNED NULL
                                    COMMENT 't_wa_messages.id of the inbound image. No FK.',
    `wa_conversation_id`            BIGINT UNSIGNED NULL
                                    COMMENT 't_wa_conversations.id (gives us the customer). No FK.',
    `image_path`                    VARCHAR(500) NULL
                                    COMMENT 'media_url copied from t_wa_messages for quick display.',
    `email_uid`                     VARCHAR(100) NULL
                                    COMMENT 'IMAP UID — unique within a folder. Drives idempotency.',
    `email_folder`                  VARCHAR(50) NULL
                                    COMMENT 'IMAP folder the email was read from (e.g. INBOX).',
    `email_from`                    VARCHAR(255) NULL,
    `email_subject`                 VARCHAR(500) NULL,
    `email_received_at`             DATETIME NULL,

    -- ── Extracted fields (ANY may be NULL on a weak extraction) ──
    `extracted_amount`              DECIMAL(12,2) NULL,
    `extracted_ref`                 VARCHAR(100) NULL
                                    COMMENT 'Bank transaction / reference number.',
    `extracted_sender_name`         VARCHAR(255) NULL
                                    COMMENT "Payer's bank-account name (often differs from NF customer name).",
    `extracted_sender_account_masked` VARCHAR(50) NULL
                                    COMMENT 'e.g. 0312xxx8227.',
    `extracted_sender_bank`         VARCHAR(50) NULL
                                    COMMENT "Payer's bank, e.g. MEEZAN / HBL.",
    `extracted_to_account_short`    VARCHAR(20) NULL
                                    COMMENT 'OUR receiving bank short_code (maps to t_fin_online_receiving_accounts.short_code).',
    `extracted_txn_datetime`        DATETIME NULL,
    `extraction_confidence`         DECIMAL(3,2) NULL
                                    COMMENT '0.00-1.00 confidence reported by the extractor.',
    `extraction_raw_text`           MEDIUMTEXT NULL
                                    COMMENT 'Raw Gemini JSON / raw email body. The DB copy we "play with".',
    `extractor_version`             VARCHAR(50) NULL
                                    COMMENT "e.g. gemini-2.5-flash@v1 or meezan-email@v1.",

    -- ── Match result (set by PaymentSignalMatcher; writes nowhere else) ──
    `status`                        ENUM('new','matched','unmatched','amount_mismatch','duplicate','irrelevant')
                                    NOT NULL DEFAULT 'new'
                                    COMMENT 'new=just extracted; matched=tied to one order; amount_mismatch=proof received but amount differs; unmatched=customer/order not found; duplicate=same ref already seen; irrelevant=not a payment screenshot.',
    `matched_order_id`              BIGINT UNSIGNED NULL
                                    COMMENT 't_crm_prod_order.id this signal was tied to. No FK.',
    `matched_customer_id`           BIGINT UNSIGNED NULL
                                    COMMENT 't_crm_prod_customer.id. No FK.',
    `match_confidence`              DECIMAL(3,2) NULL,
    `match_reason`                  VARCHAR(100) NULL
                                    COMMENT 'last_order_balance | single_unpaid_match | multiple_candidates | bulk_sum_hint | alias_lookup | none.',
    `paired_signal_id`              INT UNSIGNED NULL
                                    COMMENT 'The opposite-source signal (WA<->email) that corroborates this one.',

    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    -- Idempotency: never ingest the same email or WA image twice.
    UNIQUE KEY `uq_email` (`email_folder`, `email_uid`),
    UNIQUE KEY `uq_wa_message` (`wa_message_id`),
    KEY `idx_status` (`status`),
    KEY `idx_matched_order` (`matched_order_id`),
    KEY `idx_matched_customer` (`matched_customer_id`),
    KEY `idx_amount_ref` (`extracted_amount`, `extracted_ref`),
    KEY `idx_sender_name` (`extracted_sender_name`),
    KEY `idx_source_status` (`source`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verify:
-- SHOW COLUMNS FROM t_fin_payment_signal LIKE 'updated_at';   -- expect 1 row
-- SELECT COUNT(*) FROM t_fin_payment_signal;                  -- expect 0
