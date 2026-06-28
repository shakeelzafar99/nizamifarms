-- Barcode qty scanning (Phase 1): add Czerlop product-id tag to products.
-- Portable. Run on LOCAL and PRODUCTION. Idempotent-ish: re-running the ADD will
-- error if the column already exists (safe to ignore in that case).
--
-- czerlop_product_id = the scale PLU embedded in the weight barcode (digits 2-7).
-- NON-unique on purpose: one Czerlop product (one scale button) maps to several
-- system products (premium / regular / cut SKU variants).

ALTER TABLE `t_crm_prod_product`
  ADD COLUMN `czerlop_product_id` INT NULL DEFAULT NULL AFTER `weight_factor`,
  ADD INDEX `idx_czerlop_product_id` (`czerlop_product_id`);
