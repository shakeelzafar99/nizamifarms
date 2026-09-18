-- =============================================================================
-- Storage (Supplies) — Round 3: weighed stock becomes a POOL.
-- Sep-17-2026.  Run BEFORE uploading the web files.
--
-- Four nullable columns, no data migration, no backfill. Re-running is safe:
-- every statement is guarded on information_schema, so a second run is a no-op.
--
-- WHY EACH ONE:
--   scanned_barcode / barcode — on Sep-17 Taimur took out a 26.97 kg bale and nobody
--     could tell whether he had scanned the bale's own tray label or picked it off a
--     list after scanning a small packet. The raw code was recorded NOWHERE. These two
--     columns mean that question is always answerable, and they feed the "this label
--     went out 3 minutes ago" guard.
--   packet_barcode / packet_kg — the inner packets may carry a vendor barcode that has
--     no weight inside it (unlike a Czerlop scale label). Then one scan = one packet of
--     this nominal weight out of the pool.
-- =============================================================================

-- ── t_fin_supply_takeout.scanned_barcode ────────────────────────────────────
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 't_fin_supply_takeout'
        AND COLUMN_NAME = 'scanned_barcode') > 0,
    'SELECT ''t_fin_supply_takeout.scanned_barcode already there''',
    'ALTER TABLE `t_fin_supply_takeout`
       ADD COLUMN `scanned_barcode` VARCHAR(40) NULL
       COMMENT ''the code actually read - NULL when the weight was typed''
       AFTER `source`'
));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Lets the "same label taken twice in 10 minutes" guard be one indexed lookup.
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 't_fin_supply_takeout'
        AND INDEX_NAME = 'idx_sup_takeout_code') > 0,
    'SELECT ''idx_sup_takeout_code already there''',
    'ALTER TABLE `t_fin_supply_takeout`
       ADD INDEX `idx_sup_takeout_code` (`product_id`, `scanned_barcode`, `taken_at`)'
));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── t_fin_supply_log.barcode ────────────────────────────────────────────────
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 't_fin_supply_log'
        AND COLUMN_NAME = 'barcode') > 0,
    'SELECT ''t_fin_supply_log.barcode already there''',
    'ALTER TABLE `t_fin_supply_log`
       ADD COLUMN `barcode` VARCHAR(40) NULL
       COMMENT ''the code read for this movement, in or out''
       AFTER `source`'
));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── t_fin_supply_product.packet_barcode / packet_kg ─────────────────────────
SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 't_fin_supply_product'
        AND COLUMN_NAME = 'packet_barcode') > 0,
    'SELECT ''t_fin_supply_product.packet_barcode already there''',
    'ALTER TABLE `t_fin_supply_product`
       ADD COLUMN `packet_barcode` VARCHAR(40) NULL
       COMMENT ''weight mode: fixed barcode on the INNER packet, when it carries no weight''
       AFTER `barcode`'
));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 't_fin_supply_product'
        AND COLUMN_NAME = 'packet_kg') > 0,
    'SELECT ''t_fin_supply_product.packet_kg already there''',
    'ALTER TABLE `t_fin_supply_product`
       ADD COLUMN `packet_kg` DECIMAL(10,3) NULL
       COMMENT ''weight mode: kg one packet_barcode scan takes out of the pool''
       AFTER `packet_barcode`'
));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ── what it looks like afterwards ───────────────────────────────────────────
SELECT 'supplies_pool_sep17_2026 applied' AS status;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND ((TABLE_NAME = 't_fin_supply_takeout' AND COLUMN_NAME = 'scanned_barcode')
     OR (TABLE_NAME = 't_fin_supply_log'     AND COLUMN_NAME = 'barcode')
     OR (TABLE_NAME = 't_fin_supply_product' AND COLUMN_NAME IN ('packet_barcode', 'packet_kg')))
 ORDER BY TABLE_NAME, COLUMN_NAME;
