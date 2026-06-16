-- =====================================================
-- Online Payment Auto-Matching — Staging + Alias + IMAP Cursor
-- Date: June 15, 2026
--
-- WHAT THIS IS
-- ------------
-- Three NEW, fully-decoupled tables that power "smart sticky-notes" on
-- the existing Online Approvals, Open Orders, Messages, and Daily Closing
-- screens. NONE of these tables touch money: the ledger
-- (t_fin_ledger), order payments (t_crm_order_payments), and account
-- balances (t_fin_accounts) are NEVER written by this feature. The
-- approval flow still creates payments exactly as it does today — this
-- feature only READS the existing tables and DECORATES the UI.
--
-- 1. t_fin_payment_signal      — every payment "signal" we extract, from
--                                a WhatsApp screenshot (Gemini Vision) or
--                                a bank confirmation email (IMAP + regex).
--                                A signal is a read-only fact: "amount X,
--                                ref Y, from sender Z, at time T". The
--                                matcher later tags it to an order.
--
-- 2. t_crm_customer_bank_alias — learned mapping of a customer's
--                                bank-account NAME (which differs from
--                                their NF name) to their customer_id.
--                                Populated ONLY when a human approves a
--                                payment, so future bank emails alone can
--                                identify the customer even if they forget
--                                to send a WhatsApp screenshot.
--
-- 3. t_fin_imap_cursor         — remembers the last email UID we processed
--                                per folder, so the poller starts from
--                                "now" at deploy time and never reprocesses
--                                or replays historical mail.
--
-- DESIGN NOTES (mirrors t_app_webhook_events convention, May 2026)
-- ----------------------------------------------------------------
-- * No FK constraints. These are decoupled caches; they must survive
--   order/customer pruning and must never fail this manual migration on a
--   column-type mismatch. We index the lookup columns instead.
-- * order_id / customer_id are BIGINT UNSIGNED to match
--   t_crm_prod_order.id and t_crm_prod_customer.id.
-- * wa_message_id is BIGINT UNSIGNED to match t_wa_messages.id.
-- * Re-runnable: every CREATE uses IF NOT EXISTS.
-- =====================================================


-- -----------------------------------------------------
-- 1. Payment signals (the read-only extracted facts)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_payment_signal` (
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


-- -----------------------------------------------------
-- 2. Customer bank-name aliases (learned on approval)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_customer_bank_alias` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id`         BIGINT UNSIGNED NOT NULL
                          COMMENT 't_crm_prod_customer.id. No FK (decoupled cache).',
    `bank_account_name`   VARCHAR(255) NOT NULL
                          COMMENT 'Name on the payer bank account, e.g. "HAFIZ WASI UL HAQ ZUBERI". Stored as-seen; matched case/space-insensitively in code.',
    `bank_account_masked` VARCHAR(50) NULL
                          COMMENT 'e.g. 0312xxx8227, when available.',
    `sender_bank`         VARCHAR(50) NULL
                          COMMENT 'Payer bank, e.g. MEEZAN / HBL.',
    `confirmed_via`       ENUM('whatsapp_match','approver','manual') NOT NULL
                          COMMENT 'How this alias was established. approver = learned when a human approved a payment.',
    `confirmed_by`        BIGINT UNSIGNED NULL
                          COMMENT 't_sys_user.id who confirmed. No FK.',
    `confirmed_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `use_count`           INT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'How many times this alias later helped identify a customer.',
    `last_used_at`        TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    -- One alias per (customer, name, bank). Re-confirmations bump use_count.
    UNIQUE KEY `uq_alias` (`customer_id`, `bank_account_name`, `sender_bank`),
    KEY `idx_bank_account_name` (`bank_account_name`),
    KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------
-- 3. IMAP cursor (start-from-now, no historical replay)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_imap_cursor` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `mailbox`         VARCHAR(255) NOT NULL COMMENT 'IMAP username / account.',
    `folder`          VARCHAR(100) NOT NULL DEFAULT 'INBOX',
    `last_seen_uid`   INT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT 'Highest IMAP UID already processed. Initialised to the current max UID at first run so history is skipped.',
    `last_polled_at`  DATETIME NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mailbox_folder` (`mailbox`, `folder`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- Verification (run after the CREATEs above)
-- =====================================================
-- SHOW CREATE TABLE t_fin_payment_signal;
-- SHOW CREATE TABLE t_crm_customer_bank_alias;
-- SHOW CREATE TABLE t_fin_imap_cursor;
--
-- Expect 3 tables, all empty:
-- SELECT
--   (SELECT COUNT(*) FROM t_fin_payment_signal)     AS signals,
--   (SELECT COUNT(*) FROM t_crm_customer_bank_alias) AS aliases,
--   (SELECT COUNT(*) FROM t_fin_imap_cursor)         AS cursors;
