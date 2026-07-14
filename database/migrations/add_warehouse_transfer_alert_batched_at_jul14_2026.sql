-- ============================================================================
-- Add alert_batched_at to t_crm_warehouse_transfer
-- Nizami Farms · Combined (debounced) store-transfer push alerts · 2026-07-14
--
-- WHAT / WHY
--   Frozen Warehouse->Store transfers no longer push one notification per item.
--   Instead a poll-driven flush batches all transfers for a store into ONE push
--   once the batch settles (no new move for 5 min) or caps out (oldest >= 10 min).
--   This column records when a transfer was included in a sent batch alert, so
--   every transfer is alerted AT MOST ONCE (NULL = not yet alerted).
--
-- SAFETY
--   * Idempotent — guarded add, safe to run more than once.
--   * Column only, nullable, no default backfill needed (existing rows stay NULL;
--     the flush only alerts rows whose status is still 'pending', so old already-
--     accepted transfers never fire — see the NEW-only design).
--   * Run on LOCAL first, then PROD. No downtime.
-- ============================================================================

-- Plain ALTER (runs on shared StackCP / phpMyAdmin — no PREPARE/EXECUTE needed).
-- Run it ONCE. If it errors with "Duplicate column name 'alert_batched_at'", the
-- column is already there — that's harmless, just ignore it.
ALTER TABLE t_crm_warehouse_transfer
    ADD COLUMN alert_batched_at DATETIME NULL DEFAULT NULL AFTER status;

-- Verify (optional)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_warehouse_transfer'
  AND COLUMN_NAME = 'alert_batched_at';
-- ============================================================================
