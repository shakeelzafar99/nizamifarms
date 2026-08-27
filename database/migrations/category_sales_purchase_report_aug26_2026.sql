-- =====================================================================
--  Category Sales-vs-Purchase report  (Aug-26-2026)
--
--  Adds a Level-1 category tag to the PURCHASE side so vendor purchases
--  can be compared against sales, which are already categorised by
--  t_crm_prod_product.attribute_1 (Category Level 1).
--
--  Two columns, because purchases arrive in two shapes:
--    1. by_weight vendors  -> itemised into t_fin_vendor_purchase_items,
--       every item FK'd to a t_fin_vendor_products row. Tagging those
--       62 catalogue rows categorises ALL itemised history at once.
--    2. by_total vendors   -> a single ledger amount, no items at all.
--       Those fall back to the vendor's default category.
--
--  Resolution order at read time (never stored on the item):
--      vendor_product.category_level_1
--   -> vendor.default_category_level_1
--   -> 'Untagged'
--
--  NOTE ON RUN ORDER: run this BEFORE uploading the PHP/Blade files.
--  The report reads both columns and will 500 without them.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Schema
-- ---------------------------------------------------------------------

ALTER TABLE `t_fin_vendor_products`
    ADD COLUMN `category_level_1` VARCHAR(50) NULL DEFAULT NULL AFTER `product_name`;

ALTER TABLE `t_fin_vendors`
    ADD COLUMN `default_category_level_1` VARCHAR(50) NULL DEFAULT NULL AFTER `default_purchase_method`;

CREATE INDEX `idx_vendor_products_cat1` ON `t_fin_vendor_products` (`category_level_1`);
CREATE INDEX `idx_vendors_default_cat1` ON `t_fin_vendors` (`default_category_level_1`);


-- ---------------------------------------------------------------------
-- 1b. Per-user preference store (which categories each user shows)
--
--     Same generic table the sidebar "Customize menu" uses. It ships with
--     its own script (create_user_setting_table_jul12_2026.sql) which may
--     or may not have been run on prod yet, so it is repeated here.
--     IF NOT EXISTS makes that safe either way - if the table is already
--     there this is a no-op and no data is touched.
--
--     The report also guards on Schema::hasTable(), so if this section is
--     skipped entirely the report still works - it just shows every
--     category and the picker says preferences are unavailable.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `t_sys_user_setting` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       BIGINT UNSIGNED NOT NULL,
    `setting_key`   VARCHAR(100)    NOT NULL,
    `setting_value` TEXT            NULL,
    `created_at`    TIMESTAMP       NULL DEFAULT NULL,
    `updated_at`    TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_setting` (`user_id`, `setting_key`),
    KEY `idx_user_setting_user` (`user_id`)
);


-- ---------------------------------------------------------------------
-- 2. Backfill: vendor product catalogue  (62 rows)
--
--    Keyed on vendor_id + product_name (NOT raw id) so it stays correct
--    even if the ids differ from the dev replica.
--
--    These values are a SUGGESTION seeded from the product names. They
--    are all editable in the UI afterwards. Where a purchase item is
--    offal or trotters the tag follows where SALES puts that item
--    (Beef Liver and trotters live under 'Raheel' on the sales side),
--    because the whole point of the column is to make the two sides
--    line up. Retag freely if you disagree - nothing else depends on it.
-- ---------------------------------------------------------------------

-- Vendor 1: Jilani Meat (Taimoor Ali)  [mixed: mutton + veal + offal]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Mutton' WHERE `vendor_id`=1 AND `product_name` IN ('1. Mutton Whole (Jilani Meat)','2. Mutton Dasti / Choice (Jilani Meat)');
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef'   WHERE `vendor_id`=1 AND `product_name` IN ('3. Veal boneless _ Jilani','4. Veal Undercut','4. Veal Raan Haddi Wali','5. Veal bone Nalli','Veal Bong');
UPDATE `t_fin_vendor_products` SET `category_level_1`='Raheel' WHERE `vendor_id`=1 AND `product_name` IN ('Beef Liver (Kaleji)','Mutton Liver (Kaleji)','Veal Trotters','Mutton Paaye','Cow Brain');

-- Vendor 2: Imran Qureshi (Beef)  [all veal]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef' WHERE `vendor_id`=2;

-- Vendor 4: Ghousia Mutton (Wahab Qureshi)
UPDATE `t_fin_vendor_products` SET `category_level_1`='Mutton' WHERE `vendor_id`=4;

-- Vendor 7: Ghousia Beef (Rasheed Qureshi)  [all veal]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef' WHERE `vendor_id`=7;

-- Vendor 8: Iqbal Meat Shop (Shahbaz)
UPDATE `t_fin_vendor_products` SET `category_level_1`='Mutton' WHERE `vendor_id`=8;

-- Vendor 9: MS Enterprises (Undercut)
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef' WHERE `vendor_id`=9;

-- Vendor 10: Asad (Saidpur)  [live-weight sadqa / aqeeqa animals]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Aqeeqa' WHERE `vendor_id`=10 AND `product_name`='Aqeeqa Goat Live Weight';
UPDATE `t_fin_vendor_products` SET `category_level_1`='Sadqa'  WHERE `vendor_id`=10 AND `product_name` IN ('Sadqa Goat Live Weight','Sadqa Sheep Live Weight','Slaughtering Charges');
UPDATE `t_fin_vendor_products` SET `category_level_1`='Mutton' WHERE `vendor_id`=10 AND `product_name`='Mutton Fat - Asad';

-- Vendor 12: Butt Sb. (Goga Butt)  [trotters]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Raheel' WHERE `vendor_id`=12;

-- Vendor 13: Meat Inn (Hafeez Qureshi)  [mixed]
UPDATE `t_fin_vendor_products` SET `category_level_1`='Mutton' WHERE `vendor_id`=13 AND `product_name`='Mutton - Meat Inn';
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef'   WHERE `vendor_id`=13 AND `product_name` IN ('Veal Boneless - Meat Inn','Veal Raan (Haddi Wali) - Meat Inn','Veal Bone (Nalli)');

-- Vendor 37: Raju (Beef)
UPDATE `t_fin_vendor_products` SET `category_level_1`='Beef' WHERE `vendor_id`=37;

-- Vendor 44: ASTEH
UPDATE `t_fin_vendor_products` SET `category_level_1`='Sadqa' WHERE `vendor_id`=44;

-- Non-meat catalogues (vegetables, cheese, bbq fuel)
UPDATE `t_fin_vendor_products` SET `category_level_1`='Other' WHERE `vendor_id` IN (21,23,39);


-- ---------------------------------------------------------------------
-- 3. Backfill: vendor defaults
--
--    ONLY for vendors that bill as a lump sum (no line items), because
--    the default is what a non-itemised purchase falls back to.
--
--    ⚠ Deliberately NOT set for MIXED vendors (1 Jilani, 7 Ghousia Beef,
--      10 Asad, 13 Meat Inn). They are itemised, so they never need the
--      fallback - and giving them one would silently mis-file any
--      lump-sum row they ever record.
-- ---------------------------------------------------------------------

UPDATE `t_fin_vendors` SET `default_category_level_1`='Chicken' WHERE `id` IN (5,14);   -- LaCarne, Sajid (Desi Chicken)
UPDATE `t_fin_vendors` SET `default_category_level_1`='Raheel'  WHERE `id`=6;           -- Raheel (Paaye)
UPDATE `t_fin_vendors` SET `default_category_level_1`='Beef'    WHERE `id` IN (2,9,37); -- Imran Qureshi, MS Enterprises, Raju
UPDATE `t_fin_vendors` SET `default_category_level_1`='Mutton'  WHERE `id` IN (4,8);    -- Ghousia Mutton, Iqbal Meat Shop
UPDATE `t_fin_vendors` SET `default_category_level_1`='Sadqa'   WHERE `id`=44;          -- ASTEH
UPDATE `t_fin_vendors` SET `default_category_level_1`='Raheel'  WHERE `id`=12;          -- Butt Sb. (trotters)

-- Non-meat / overhead / internal vendors. Tagged 'Other' so they are
-- visible but never pollute a meat category.
UPDATE `t_fin_vendors` SET `default_category_level_1`='Other'
 WHERE `id` IN (11,15,20,21,22,23,24,25,28,30,38,39,40,41,43,45);

-- Vendor 16 (Meat Hub / Faizan Firdous) is deliberately left UNTAGGED -
-- it bills lump-sum and the name does not say which meat it is. It will
-- show under 'Untagged' in the report until you set it on the Vendors page.
