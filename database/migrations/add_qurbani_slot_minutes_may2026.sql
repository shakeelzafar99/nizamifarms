-- =====================================================
-- Qurbani slot start/end time columns
-- Date: May 2026
-- Description:
--   Adds two SMALLINT columns to t_crm_prod_order_line_item that
--   hold the parsed slot times as MINUTES SINCE MIDNIGHT (e.g.
--   10:30 AM = 630, 7 PM = 1140). Derived from the existing
--   qurbani_slot string via App\Services\QurbaniSlotParser.
--
--   Why minutes-since-midnight (not TIME)?
--     * Cheap integer comparisons → fast at-risk queries.
--     * Eid orders never cross midnight (per repo owner) so a
--       single value works for both start and end.
--     * Range slots like "Afternoon 10 AM to 2 PM" map to
--       (start=600, end=840). Single-time slots like "Afternoon
--       2 PM" map to (start=840, end=900) — i.e. start + 60 — so
--       the dashboard's late-detection still has a usable
--       end-of-window. The repo owner can tighten/loosen this
--       per-slot later by editing the slot string.
--     * NULL when slot string is empty or unrecognisable; the
--       backfill command + model hook log these so they can be
--       cleaned up in one batch.
--
--   Sync points (so the columns never drift):
--     1. One-shot backfill: php artisan qurbani:backfill-slot-minutes
--     2. Per-row re-parse: OrderLineItemModel::saving() boot hook
--        re-derives the values whenever qurbani_slot is dirty.
--
--   Indexed on qurbani_slot_end_minute alone — the dashboard's
--   hot query is "items whose slot end is within X minutes" and
--   that uses end_minute as the leading predicate.
--
-- INSTRUCTIONS:
--   Run once on production. Safe to re-run — IF NOT EXISTS guards
--   on each ADD COLUMN make this idempotent at the column level
--   (MySQL 8+ syntax). For older MySQL, the second run will throw
--   "duplicate column name" — that's harmless, ignore it.
-- =====================================================


-- ---- 1) Line item columns -------------------------------------
-- Materialized view used by fast dashboard queries (indexed).
ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN IF NOT EXISTS `qurbani_slot_start_minute` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Slot start as minutes since 00:00. e.g. 10:00 AM = 600, 2:00 PM = 840. Auto-derived from qurbani_slot via QurbaniSlotParser, or copied from t_crm_qurbani_field_options when an explicit override is set there. NULL when slot is empty/unparseable.'
        AFTER `qurbani_slot`,
    ADD COLUMN IF NOT EXISTS `qurbani_slot_end_minute` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Slot end as minutes since 00:00. For range slots (e.g. "10 AM to 2 PM") this is the parsed end. For single-time slots (e.g. "2 PM") we synthesize start+60 so the at-risk math has a window. NULL when slot is empty/unparseable.'
        AFTER `qurbani_slot_start_minute`;


-- Index added separately so it's idempotent and survives re-runs
-- (CREATE INDEX IF NOT EXISTS works on MySQL 8+; if the index
-- already exists on older MySQL the second run will error — harmless).
CREATE INDEX IF NOT EXISTS `idx_li_qurbani_slot_end_minute`
    ON `t_crm_prod_order_line_item` (`qurbani_slot_end_minute`);


-- ---- 2) Settings lookup table columns -------------------------
-- Source-of-truth for slot timings. The Qurbani Settings page lets
-- the user view the parser-suggested values and override per-slot
-- when the default 60-min synthetic window for single-time slots
-- isn't what they want, or for any other custom mapping. When the
-- user saves these, the controller cascades the values down to
-- t_crm_prod_order_line_item rows that use the same slot string.
--
-- Only meaningful for rows where field_name = 'qurbani_slot'. The
-- columns exist on every row but stay NULL for non-slot field
-- types (Day / Region / etc) — no harm done.
ALTER TABLE `t_crm_qurbani_field_options`
    ADD COLUMN IF NOT EXISTS `slot_start_minute` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Source-of-truth slot start (minutes since 00:00) for rows with field_name=qurbani_slot. NULL means "use parser auto-detect for line items". Edit via the Qurbani Settings UI.'
        AFTER `option_value`,
    ADD COLUMN IF NOT EXISTS `slot_end_minute` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Source-of-truth slot end (minutes since 00:00) for rows with field_name=qurbani_slot. NULL means "use parser auto-detect for line items". Edit via the Qurbani Settings UI.'
        AFTER `slot_start_minute`;


-- =====================================================
-- Verify
-- =====================================================

SELECT
    TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
        (TABLE_NAME = 't_crm_prod_order_line_item'
         AND COLUMN_NAME IN ('qurbani_slot', 'qurbani_slot_start_minute', 'qurbani_slot_end_minute'))
     OR (TABLE_NAME = 't_crm_qurbani_field_options'
         AND COLUMN_NAME IN ('option_value', 'slot_start_minute', 'slot_end_minute'))
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Backfill check (after running `php artisan qurbani:backfill-slot-minutes`):
--
-- SELECT qurbani_slot,
--        qurbani_slot_start_minute,
--        qurbani_slot_end_minute,
--        COUNT(*) AS row_count
-- FROM t_crm_prod_order_line_item
-- WHERE qurbani_slot IS NOT NULL AND qurbani_slot <> ''
-- GROUP BY qurbani_slot, qurbani_slot_start_minute, qurbani_slot_end_minute
-- ORDER BY qurbani_slot;
--
-- SELECT COUNT(*) AS unparseable
-- FROM t_crm_prod_order_line_item
-- WHERE qurbani_slot IS NOT NULL AND qurbani_slot <> ''
--   AND qurbani_slot_end_minute IS NULL;
