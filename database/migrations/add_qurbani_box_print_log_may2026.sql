-- =====================================================
-- Qurbani Box Print Log
-- Date: May 17, 2026
-- Description:
--   Per-packet print log used by the new Qurbani Orders page's
--   "Print Box Labels" feature. Each row = one printed packet on a
--   physical A4 sheet.
--
--   A "packet" = one unit of quantity in a Qurbani line item. A line
--   item with quantity=2 produces 2 packets, each at its own position
--   in its smart-box bundle. The bundle key + position are computed
--   on the fly by QurbaniBundleService::computeBundles().
--
--   Granularity is (line_item_id, position) so the team can:
--     - Re-print a specific packet without re-printing the whole bundle.
--     - Track "pending vs printed" precisely (a 4-bundle where only 1/4
--       and 2/4 have been printed shows up as "2 of 4 printed").
--     - Audit who printed what and when.
--
--   bundle_size + bundle_key are SNAPSHOTTED at print time so we can
--   detect later if the bundle was modified (e.g., another line item
--   added to the same slot, growing the denominator) — that triggers
--   a "stale print" warning in the UI so the team knows to reprint.
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_crm_qurbani_box_print` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL COMMENT 'FK t_crm_prod_order.id',
    `line_item_id` INT NOT NULL COMMENT 'FK t_crm_prod_order_line_item.id',
    `position` INT NOT NULL COMMENT 'Packet position within bundle (1-indexed)',
    `bundle_size` INT NOT NULL COMMENT 'SNAPSHOT of bundle_size at print time. May differ from current if bundle changed.',
    `bundle_key` VARCHAR(255) NOT NULL COMMENT 'SNAPSHOT of bundle_key at print time. Used to detect bundle drift.',
    `printed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `printed_by` INT NOT NULL COMMENT 'FK t_sys_user.id',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- One row per (line_item, position). Re-prints UPDATE this row via
    -- INSERT ... ON DUPLICATE KEY UPDATE so we always have the latest
    -- print timestamp without flooding the table with duplicates.
    UNIQUE KEY `uk_line_item_position` (`line_item_id`, `position`),
    INDEX `idx_order` (`order_id`),
    INDEX `idx_bundle` (`bundle_key`),
    INDEX `idx_printed_by` (`printed_by`),
    INDEX `idx_printed_at` (`printed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- Verify
-- ============================================================================

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_qurbani_box_print';

SHOW INDEX FROM t_crm_qurbani_box_print;
