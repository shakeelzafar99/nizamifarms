-- Barcode qty scanning (Phase 2): track HOW a line-item quantity was last set,
-- so web + mobile can show a "barcode" vs "manual" badge.
-- Portable. Run on LOCAL and PRODUCTION. (Re-running the ADD errors if the column
-- already exists — safe to ignore in that case.)

ALTER TABLE `t_crm_prod_order_line_item`
  ADD COLUMN `quantity_source` VARCHAR(10) NULL DEFAULT NULL AFTER `quantity`,
  ADD COLUMN `quantity_updated_by` INT NULL DEFAULT NULL AFTER `quantity_source`,
  ADD COLUMN `quantity_updated_at` DATETIME NULL DEFAULT NULL AFTER `quantity_updated_by`,
  ADD COLUMN `quantity_scanned_barcode` VARCHAR(20) NULL DEFAULT NULL AFTER `quantity_updated_at`;

-- quantity_source: 'manual' or 'barcode' (NULL = legacy / never explicitly set).
-- quantity_scanned_barcode: the raw EAN-13 read, kept for audit/traceability.
