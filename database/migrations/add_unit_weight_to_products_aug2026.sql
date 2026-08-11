-- =====================================================
-- Migration: Add Unit Weight (kg) to Products
-- Date: August 11, 2026
-- Purpose: Open Order Quantities shows a READ-ONLY "Weight (kg)" figure
--          alongside the existing Qty. Weight = qty * unit_weight_kg.
--          A "500 grams" product has unit_weight_kg = 0.5, so 21 packs = 10.5 kg.
--
-- ⚠️ THIS IS **NOT** `weight_factor`.
--    weight_factor = the net-yield DIVISOR used when weighing/scanning
--    (stored qty = entered kg / weight_factor, e.g. 0.93 for Breast Butterfly).
--    Changing weight_factor changes what gets billed. Leave it alone.
--    unit_weight_kg is display-only and never feeds any qty/price calculation.
--
-- ⚠️ RUN THIS **BEFORE** UPLOADING THE PHP FILES.
--    The quantities endpoints SELECT this column; without it they return 500.
--    Re-running gives "Duplicate column name" — that just means it is already done.
-- =====================================================

-- 1) The column ------------------------------------------------------------
ALTER TABLE `t_crm_prod_product`
ADD COLUMN `unit_weight_kg` DECIMAL(8,3) NOT NULL DEFAULT 1.000
COMMENT 'Kg represented by ONE qty unit (0.5 = 500g pack). Display-only, used by Open Order Quantities. NOT weight_factor.'
AFTER `weight_factor`;

-- 2) Backfill: 500-gram pack products -> 0.5 -------------------------------
-- Matches titles containing "500 gram" / "500 gms" / "500g",
-- EXCLUDING titles where "per kg" appears BEFORE the 500 mention. Those two
-- are sold per kilo and the 500g is only a piece/pack size, so they stay 1.000:
--   id 363  "Beef New York Steak per kg - 500 gms per steak - net weight"
--   id 1400 "Mutton (DT) LEAN Shoulder (Dasti) cut per kg - Net weight (Twin Packs - 500 gram x 2 packs)"
-- (Verified on the local prod replica: 58 matched, 2 excluded, 56 updated.)
UPDATE `t_crm_prod_product`
SET `unit_weight_kg` = 0.500
WHERE (
        `title` LIKE '%500 gram%'
     OR `title` LIKE '%500 gms%'
     OR `title` LIKE '%500g%'
      )
  AND `title` NOT LIKE '%per kg%500%';

-- =====================================================
-- VERIFICATION (run after)
-- =====================================================
-- SELECT COUNT(*) AS half_kg_products FROM t_crm_prod_product WHERE unit_weight_kg = 0.500;
-- SELECT id, title, weight_factor, unit_weight_kg FROM t_crm_prod_product
--  WHERE unit_weight_kg <> 1.000 ORDER BY title;
-- -- anything with 500 in the title still left at 1.000 (review these by eye):
-- SELECT id, title, unit_weight_kg FROM t_crm_prod_product
--  WHERE title LIKE '%500%' AND unit_weight_kg = 1.000;

-- =====================================================
-- ROLLBACK
-- =====================================================
-- ALTER TABLE `t_crm_prod_product` DROP COLUMN `unit_weight_kg`;
