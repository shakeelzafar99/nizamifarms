-- =====================================================
-- Add khaas_business_unit_id to t_crm_shopify_order
-- Purpose: Carry the BU ID through the approval queue
--          so conversion doesn't need to re-derive it
-- =====================================================

ALTER TABLE `t_crm_shopify_order`
ADD COLUMN IF NOT EXISTS `khaas_business_unit_id` INT NULL DEFAULT NULL
COMMENT 'Business unit for khaas_storage orders — set at placement, read at conversion'
AFTER `converted`;

-- Backfill any existing unconverted khaas_storage orders (use config lookup)
UPDATE `t_crm_shopify_order` so
SET so.`khaas_business_unit_id` = COALESCE(
    (SELECT c.`business_unit_id`
     FROM `t_fin_config` c
     WHERE c.`config_key` = 'KHAAS_STORAGE_CUSTOMER_ID'
       AND c.`config_value` = CAST(so.`customer_id` AS CHAR)
     LIMIT 1),
    2  -- fallback to Khaas BU
)
WHERE so.`external_source` = 'khaas_storage'
  AND so.`converted` = 0
  AND so.`khaas_business_unit_id` IS NULL;

SELECT '✅ khaas_business_unit_id added to t_crm_shopify_order' AS Status;
