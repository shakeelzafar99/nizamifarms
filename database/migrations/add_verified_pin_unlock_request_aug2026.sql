-- =====================================================================
-- Verified-pin UNLOCK REQUEST — the rider's "please unlock this" signal
-- (Aug-2026). Portable, hand-run on local + prod.
--
-- ⚠ RUN THIS **BEFORE** UPLOADING THE PHP. Every verified-pin save writes
--   these columns (they are cleared by the same helper that clears the
--   unlock grant), so code-without-schema = "Unknown column" on every pin
--   save. This is the exact failure the pin LOCK hit on Jul-19-2026.
--
-- Model: when a rider presses "verify" on a LOCKED pin the server refuses
-- (423) and stamps the request below. Store mode and the web orders page
-- each raise a banner from it. The request is CLEARED by every action that
-- resolves it — unlock granted, pin saved (grant consumed), relock, or an
-- explicit dismiss — which is what stops dead banners lingering.
--
-- Additive + nullable. "Duplicate column" on re-run = already applied.
-- =====================================================================

ALTER TABLE `t_crm_prod_customer`
  ADD COLUMN `verified_pin_unlock_requested_at` DATETIME NULL DEFAULT NULL
      COMMENT 'When a rider asked for this locked pin to be unlocked; NULL = no open request',
  ADD COLUMN `verified_pin_unlock_requested_by` INT NULL DEFAULT NULL
      COMMENT 'Rider user id who asked (audit + banner shows his name)',
  ADD COLUMN `verified_pin_unlock_request_order_id` INT NULL DEFAULT NULL
      COMMENT 'Order he was standing on when he asked, so the banner can name it. Nullable';

-- The banners poll every 30s for the (normally empty) set of open requests.
-- Almost every row is NULL, so this keeps it a narrow range scan instead of
-- a full table scan four times a minute.
ALTER TABLE `t_crm_prod_customer`
  ADD INDEX `idx_pin_unlock_requested_at` (`verified_pin_unlock_requested_at`);

-- Verify (expect 3 columns + 1 index):
-- SHOW COLUMNS FROM t_crm_prod_customer LIKE 'verified_pin_unlock_request%';
-- SHOW INDEX FROM t_crm_prod_customer WHERE Key_name = 'idx_pin_unlock_requested_at';
