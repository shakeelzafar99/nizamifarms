-- ============================================================
-- Reset ALL Khaas inventory to 0 (test data cleanup)
-- Date: 2026-02-23
-- Resets: variant inventory_quantity, product total_inventory,
--         warehouse inventory, and clears transfer history
-- ============================================================

-- Get the Khaas business unit ID
-- SELECT id, name, code FROM t_fin_business_unit WHERE code = 'KHAAS';

-- 1. Reset variant inventory_quantity to 0 for all Khaas products
UPDATE t_crm_prod_product_variant pv
JOIN t_crm_prod_product p ON p.id = pv.product_id
JOIN t_fin_business_unit bu ON bu.id = p.business_unit_id AND bu.code = 'KHAAS'
SET pv.inventory_quantity = 0;

-- 2. Reset product total_inventory to 0 for all Khaas products
UPDATE t_crm_prod_product p
JOIN t_fin_business_unit bu ON bu.id = p.business_unit_id AND bu.code = 'KHAAS'
SET p.total_inventory = 0;

-- 3. Reset warehouse inventory to 0 for all Khaas BU records
UPDATE t_crm_warehouse_inventory wi
JOIN t_fin_business_unit bu ON bu.id = wi.business_unit_id AND bu.code = 'KHAAS'
SET wi.quantity = 0;

-- 4. Delete warehouse inventory logs for Khaas BU (test data)
DELETE wil FROM t_crm_warehouse_inventory_log wil
JOIN t_fin_business_unit bu ON bu.id = wil.business_unit_id AND bu.code = 'KHAAS';

-- 5. Delete all warehouse transfers for Khaas BU (test data)
DELETE wt FROM t_crm_warehouse_transfer wt
JOIN t_fin_business_unit bu ON bu.id = wt.business_unit_id AND bu.code = 'KHAAS';
