-- =====================================================================
-- HQ Executive Dashboard — optional performance index (Jul-2026)
-- =====================================================================
-- The dashboard attributes revenue to the day an order became 'delivered',
-- read from t_crm_order_status_history. This composite index lets the
-- per-month delivered-date lookup use an index range instead of scanning
-- the whole history table.
--
-- SAFE + ADDITIVE: only creates an index. No data change, no schema change
-- to columns. The dashboard works without it (just a little slower on a
-- cold, uncached load). Run on LOCAL first, then PROD, like other manual
-- migrations. Idempotent (checks existence first).
-- =====================================================================

SET @idx := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 't_crm_order_status_history'
      AND INDEX_NAME   = 'idx_osh_delivered_lookup'
);
SET @sql := IF(@idx = 0,
    'CREATE INDEX idx_osh_delivered_lookup ON t_crm_order_status_history (status_code, changed_at, order_id)',
    'SELECT ''idx_osh_delivered_lookup already exists'' AS status');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT '✅ HQ dashboard index ready' AS status;
