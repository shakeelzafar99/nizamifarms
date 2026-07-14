-- =====================================================================================
-- Order Status Hub — Phase 0 schema + backfill (July 2026)
-- =====================================================================================
-- Run this ONCE on LOCAL, then ONCE on PRODUCTION (hand-run, as usual).
-- Plan: ORDER-STATUS-HUB-PLAN-JUL2026.md (workspace root).
--
-- WHAT THIS DOES
--   Adds behaviour columns to the order-status table so the new "Status Hub" screen
--   can own the rules that are currently hardcoded in many places. It is INTENTIONALLY
--   INERT: only two of the new columns (auto_prepares, and the setting read) are read by
--   the Phase-1 code, and they are backfilled to reproduce TODAY's behaviour EXACTLY.
--   Everything else is populated now so the Hub has correct starting data later, but no
--   running code reads it yet. Nothing about orders, quantities, or money changes when
--   you run this.
--
-- SAFETY
--   * All columns are ADDED with safe defaults — existing rows keep working untouched.
--   * counts_in_quantities is derived from THIS environment's own excluded-statuses
--     setting, so it mirrors exactly what your Quantities tab already excludes here.
--   * No rows are deleted. `priority`, `dispatch`, and the duplicate `on-hold` are LEFT
--     ACTIVE on purpose (owner marks them legacy later from the Hub, if ever).
--   * Run each ALTER only once (MariaDB has no "ADD COLUMN IF NOT EXISTS"). If you re-run
--     and see "Duplicate column name", the column is already there — skip that ALTER.
--
-- ROLLBACK (only if you must): the columns are additive; dropping them restores the
--   original schema:
--     ALTER TABLE t_crm_order_status_master
--       DROP COLUMN counts_in_quantities, DROP COLUMN auto_prepares, DROP COLUMN lane,
--       DROP COLUMN send_to_customer_app, DROP COLUMN customer_app_alias;
--     ALTER TABLE t_crm_prod_order_line_item DROP COLUMN prepared_source;
--     DELETE FROM t_crm_open_quantities_settings WHERE setting_key = 'bring_back_mode';
-- =====================================================================================

-- ---------------------------------------------------------------------------
-- 1) Columns on the status master
-- ---------------------------------------------------------------------------
ALTER TABLE t_crm_order_status_master
  ADD COLUMN counts_in_quantities TINYINT(1) NOT NULL DEFAULT 1 AFTER is_final,
  ADD COLUMN auto_prepares        TINYINT(1) NOT NULL DEFAULT 0 AFTER counts_in_quantities,
  ADD COLUMN lane                 VARCHAR(20) NOT NULL DEFAULT 'journey' AFTER auto_prepares,
  ADD COLUMN send_to_customer_app TINYINT(1) NOT NULL DEFAULT 1 AFTER lane,
  ADD COLUMN customer_app_alias   VARCHAR(50) DEFAULT NULL AFTER send_to_customer_app;

-- Provenance of a line item's "prepared" mark: 'auto' (set by a status change) vs
-- 'manual' (a staff member pressed Prepare). Lets a future "bring back" un-mark only the
-- auto ones and leave hand-packed bags alone. Existing marks stay NULL = treat as manual.
ALTER TABLE t_crm_prod_order_line_item
  ADD COLUMN prepared_source VARCHAR(10) DEFAULT NULL AFTER preparation_status;

-- ---------------------------------------------------------------------------
-- 2) Backfill — reproduce today's behaviour exactly (per environment)
-- ---------------------------------------------------------------------------

-- counts_in_quantities: 0 for every status THIS environment currently excludes from the
-- Quantities normal view (read straight from your own excluded_statuses setting), else 1.
-- Guarantees the Hub starts with the same "counted" set you already have.
-- (Verified working on the dev replica. IF prod's MySQL/MariaDB is older and errors on
--  JSON_CONTAINS/JSON_QUOTE, use this equivalent manual statement instead — it hardcodes
--  the current live setting value ["cancelled","refunded","pending"]:
--    UPDATE t_crm_order_status_master
--    SET counts_in_quantities = IF(status_code IN ('cancelled','refunded','pending'), 0, 1);
--  Check the live value first via the Quantities gear icon if unsure.)
UPDATE t_crm_order_status_master m
SET m.counts_in_quantities = CASE
    WHEN JSON_CONTAINS(
           COALESCE((SELECT s.setting_value
                       FROM t_crm_open_quantities_settings s
                      WHERE s.setting_key = 'excluded_statuses'), '[]'),
           JSON_QUOTE(m.status_code)
         ) = 1
    THEN 0 ELSE 1 END;

-- auto_prepares = "past the orange line": entering this status auto-marks items prepared
-- and the order leaves BOTH quantities views. This is the exact hardcoded set the
-- Prepared view uses today (same in code on every environment).
UPDATE t_crm_order_status_master
SET auto_prepares = 1
WHERE status_code IN ('out_for_delivery', 'delivered', 'completed');

-- lane groups the Hub UI (journey / off-track / legacy). INERT — nothing reads `lane` yet.
-- Off-track = never counted, closes the order. Legacy = kept for history, hidden from
-- pickers. Only `completed` is shelved now (all its orders are old); priority/dispatch/
-- the duplicate on-hold are deliberately LEFT on the journey lane and active.
UPDATE t_crm_order_status_master SET lane = 'offtrack' WHERE status_code IN ('cancelled', 'refunded');
-- 'completed' (490 old orders) and the hyphen 'on-hold' (22 uses ever, none since Feb-2026, the legacy
-- twin of the active 'on_hold') are retired to the Legacy shelf. Nothing deleted; both keep their history.
UPDATE t_crm_order_status_master SET lane = 'legacy'   WHERE status_code IN ('completed', 'on-hold');

-- customer_app_alias: what the customer app should show instead of the raw code. INERT
-- until the customer-app phase. `completed` is a legacy twin of delivered.
UPDATE t_crm_order_status_master SET customer_app_alias = 'delivered' WHERE status_code = 'completed';

-- bring_back_mode: when an order is moved back before the orange line, ASK the operator
-- whether to send the items back to the prepare list or keep them prepared. INERT until
-- the bring-back phase. Insert only if not already present.
INSERT INTO t_crm_open_quantities_settings (setting_key, setting_value, setting_type)
SELECT 'bring_back_mode', 'ask', 'other' FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM t_crm_open_quantities_settings WHERE setting_key = 'bring_back_mode'
);

-- ---------------------------------------------------------------------------
-- 3) Verify (eyeball the result — no changes, just a read)
-- ---------------------------------------------------------------------------
-- Expect: cancelled/refunded/pending (+ whatever your setting excludes) => counts=0;
--         out_for_delivery/delivered/completed => auto_prepares=1;
--         cancelled/refunded => lane offtrack; completed => lane legacy, alias delivered;
--         priority/dispatch/on-hold => lane journey, counts=1, still active.
SELECT status_code, status_name, is_active, is_final,
       counts_in_quantities, auto_prepares, lane, send_to_customer_app, customer_app_alias
FROM t_crm_order_status_master
ORDER BY sequence_order;
