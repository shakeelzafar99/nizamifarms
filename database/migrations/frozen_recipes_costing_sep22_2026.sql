-- #####################################################################################
--  ❄🧾 FROZEN RECIPES · RECEIPT CAPTURE · PURCHASE-TO-BATCH COSTING      22-Sep-2026
--
--  ONE migration for all four phases, so nothing later needs a second SQL window.
--  Owner rulings (21-Sep): recipe entered as a REFERENCE BATCH; consumption is driven
--  by every warehouse stock_in (plan-made AND typed-in by hand) exactly as "Made" is;
--  cost = quantity used x the month's AVERAGE purchase rate; gas/electricity/salaries
--  stay an allocation from the Month Review's fixed bucket, never a recipe line.
--
--  Nothing here touches money. t_fin_ledger, t_fin_supply_*, t_fin_cost_type_map and
--  every balance engine are untouched; this file only adds a QUANTITY layer beside them.
--
--  ⚠ ALTER TABLE is NOT rolled back inside a transaction, so every statement below is
--    written to be safely re-runnable on a database that already has part of it.
--  ⚠ Dates are DATE columns on purpose — the dev replica renders TIMESTAMP shifted.
--  ⚠ COLLATE is pinned to utf8mb4_unicode_ci on every new table: the server default is
--    general_ci and a mixed-collation join fails with error 1267.
--
--  Safe to re-run.
-- #####################################################################################


-- -------------------------------------------------------------------------------------
-- PRE-FLIGHT — read only. Run these first and read the answers.
-- -------------------------------------------------------------------------------------
SELECT 'pre-flight: tables already present' AS check_name, COUNT(*) AS found
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('t_crm_khaas_ingredient','t_crm_khaas_recipe','t_crm_khaas_recipe_line',
                      't_crm_khaas_batch_consumption','t_crm_khaas_ingredient_opening',
                      't_fin_receipt_draft');
-- EXPECT 0 on a first run, 6 on a re-run.

SELECT 'pre-flight: frozen business unit' AS check_name, id, code, name
  FROM t_fin_business_units WHERE code = 'KHAAS';
-- EXPECT one row, id 2, name "Frozen".

SELECT 'pre-flight: permission keys already seeded' AS check_name, permission_code
  FROM t_sys_mobile_permission
 WHERE permission_code IN ('manage_khaas_recipes','view_khaas_costing');
-- EXPECT 0 rows on a first run.

SELECT 'pre-flight: who sees Month Review costs TODAY' AS check_name, r.id AS role_id, r.urole_name
  FROM t_sys_role r
  JOIN t_sys_role_mobile_permission rmp ON rmp.role_id = r.id
  JOIN t_sys_mobile_permission mp ON mp.id = rmp.mobile_permission_id
 WHERE mp.permission_code IN ('view_khaas_month_review','view_khaas_sales_report')
 GROUP BY r.id, r.urole_name ORDER BY r.id;
-- ⚠⚠ READ THIS. canSeeMonthCosts() is (view_khaas_month_review OR view_khaas_sales_report),
--    so role 17 (khaas / Qasim) ALREADY sees the cost half of Month Review today. PART 8
--    below seeds the NEW ingredient-rupees key to roles 10/14/18 only, per the owner's
--    ruling "Qasim sees quantities only". That HIDES the new ingredient cost from him
--    while leaving everything he sees today untouched. Tick role 17 on
--    Roles -> Permissions if he should see ingredient cost too.


-- -------------------------------------------------------------------------------------
-- PART 1 — INGREDIENT MASTER.
--
-- One row per thing that goes into a frozen product. base_unit is the ONLY unit any
-- quantity is ever stored in (g / ml / pcs), so nothing is converted twice; display_unit
-- is cosmetic and drives how the number is shown back (kg, L, g, ml, pcs).
--
-- storage_product_id is set for MEAT only: that ingredient's bought/used figures come
-- from the real storage ledger (t_crm_khaas_storage_log), never from a recipe standard.
-- ⚠ t_crm_khaas_storage_inventory holds MORE THAN ONE row per source_product_id (one per
--   variant), so any reader must SUM across them. Verified on the replica 21-Sep-2026.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_khaas_ingredient` (
  `id`                  INT(11) NOT NULL AUTO_INCREMENT,
  `business_unit_id`    INT(11) NOT NULL DEFAULT 2 COMMENT 'FK t_fin_business_units; 2 = Frozen',
  `name`                VARCHAR(150) NOT NULL,
  `name_urdu`           VARCHAR(150) DEFAULT NULL COMMENT 'Roman Urdu label for the phone',
  `base_unit`           ENUM('g','ml','pcs') NOT NULL DEFAULT 'g' COMMENT 'the ONLY unit quantities are stored in',
  `display_unit`        VARCHAR(10) NOT NULL DEFAULT 'kg' COMMENT 'kg | L | g | ml | pcs - how it is shown',
  `kind`                ENUM('meat','vegetable','dairy','dry','packaging','other') NOT NULL DEFAULT 'other',
  `storage_product_id`  BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'meat only: FK t_crm_prod_product, the storage raw material',
  `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`          INT(11) DEFAULT NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_khaas_ingredient_name` (`business_unit_id`,`name`),
  KEY `idx_khaas_ingredient_active` (`business_unit_id`,`is_active`),
  KEY `idx_khaas_ingredient_storage` (`storage_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Things that go into a frozen product. base_unit g/ml/pcs is the storage unit for every quantity.';


-- -------------------------------------------------------------------------------------
-- PART 2 — RECIPE HEADER, versioned.
--
-- Qasim thinks in batches, not packets: "9 kg chicken makes 80 packs". So the recipe is
-- entered against a REFERENCE BATCH and the per-pack figure is DERIVED
-- (qty_per_basis / basis_packets) and never stored — one number to correct, not two.
--
-- A correction creates a NEW VERSION rather than editing in place, because consumption
-- rows already written keep pointing at the version they were made with. History is not
-- silently restated; "Re-apply recipe for this month" is the deliberate, explicit way to
-- restate it.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_khaas_recipe` (
  `id`             INT(11) NOT NULL AUTO_INCREMENT,
  `product_id`     BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK t_crm_prod_product - the finished frozen product',
  `version`        INT(11) NOT NULL DEFAULT 1,
  `basis_packets`  INT(11) NOT NULL COMMENT 'packs the reference batch yields; the denominator',
  `effective_from` DATE NOT NULL COMMENT 'a stock_in on/after this date uses this version',
  `is_current`     TINYINT(1) NOT NULL DEFAULT 1,
  `note`           VARCHAR(255) DEFAULT NULL,
  `created_by`     INT(11) DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_khaas_recipe_version` (`product_id`,`version`),
  KEY `idx_khaas_recipe_current` (`product_id`,`is_current`),
  KEY `idx_khaas_recipe_effective` (`product_id`,`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One version of one product recipe, expressed as a reference batch.';


-- -------------------------------------------------------------------------------------
-- PART 3 — RECIPE LINES.
-- qty_per_basis is in the ingredient's BASE unit for the WHOLE reference batch.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_khaas_recipe_line` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `recipe_id`     INT(11) NOT NULL,
  `ingredient_id` INT(11) NOT NULL,
  `qty_per_basis` DECIMAL(14,3) NOT NULL COMMENT 'base units for the whole reference batch',
  `is_optional`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'seasoning etc - excluded from the shortfall warning',
  `sort_order`    INT(11) NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_khaas_recipe_line` (`recipe_id`,`ingredient_id`),
  KEY `idx_khaas_recipe_line_ing` (`ingredient_id`),
  CONSTRAINT `fk_khaas_recipe_line_recipe` FOREIGN KEY (`recipe_id`)
      REFERENCES `t_crm_khaas_recipe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One ingredient inside one recipe version.';


-- -------------------------------------------------------------------------------------
-- PART 4 — CONSUMPTION: what a stock_in SHOULD have used.
--
-- ⭐⭐ Written for EVERY warehouse stock_in of a product that has a recipe - whether the
--    packs came from an accepted production plan (reference_type='batch') or were typed
--    straight into the warehouse by hand. That is deliberate and matches the standing
--    ruling that "Made" = stock_in, not completed batches. source_kind records which,
--    so Month Review can say "449 made: 338 through a plan, 111 entered directly".
--
-- ⚠ A later count or adjustment does NOT delete these rows. Those rows have never
--   carried a link to what they correct, and Month Review already shows counts on their
--   own line. Restating a month is the explicit "Re-apply" action, nothing else.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_khaas_batch_consumption` (
  `id`               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `warehouse_log_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK t_crm_warehouse_inventory_log - the stock_in that caused it',
  `business_unit_id` INT(11) NOT NULL,
  `product_id`       BIGINT(20) UNSIGNED NOT NULL,
  `batch_id`         BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'FK t_crm_product_batch when the stock_in came from a plan',
  `source_kind`      ENUM('plan','direct') NOT NULL DEFAULT 'direct' COMMENT 'plan = reference_type was batch',
  `recipe_id`        INT(11) NOT NULL COMMENT 'the version that was current on made_on',
  `ingredient_id`    INT(11) NOT NULL,
  `packets`          INT(11) NOT NULL COMMENT 'packs this stock_in brought in',
  `qty_base`         DECIMAL(16,3) NOT NULL COMMENT 'packets x per-pack quantity, in the ingredient base unit',
  `made_on`          DATE NOT NULL COMMENT 'the stock_in date - what the month buckets on',
  `created_by`       INT(11) DEFAULT NULL,
  `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_khaas_consumption` (`warehouse_log_id`,`ingredient_id`),
  KEY `idx_khaas_consumption_month` (`business_unit_id`,`made_on`),
  KEY `idx_khaas_consumption_ing` (`ingredient_id`,`made_on`),
  KEY `idx_khaas_consumption_product` (`product_id`,`made_on`),
  KEY `idx_khaas_consumption_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Ingredient quantities a warehouse stock_in should have consumed, per the recipe.';


-- -------------------------------------------------------------------------------------
-- PART 5 — OPENING STOCK AND PHYSICAL COUNTS.
--
-- Remaining is cumulative (a January bag of salt is still on the shelf in March), so it
-- needs a start line. Without a row here an ingredient reports "not tracked" rather than
-- a wrong number.
--
-- A count RE-ANCHORS the running figure on its date: remaining becomes the counted
-- number, plus what was bought strictly after it, minus what was used strictly after it.
-- ⚠ Unlike the Supplies count, it does NOT write a shortfall off as consumption. There,
--   the money is already spent on a packet that has gone; here, inventing consumption
--   would inflate an estimate and nobody could separate the invented part afterwards.
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_crm_khaas_ingredient_opening` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `ingredient_id` INT(11) NOT NULL,
  `kind`          ENUM('opening','count') NOT NULL DEFAULT 'opening',
  `counted_on`    DATE NOT NULL,
  `qty_base`      DECIMAL(16,3) NOT NULL,
  `rupees`        DECIMAL(12,2) DEFAULT NULL COMMENT 'optional value of that opening stock',
  `note`          VARCHAR(255) DEFAULT NULL,
  `created_by`    INT(11) DEFAULT NULL,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_khaas_opening_ing` (`ingredient_id`,`counted_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Opening stock line and later physical counts, per ingredient.';


-- -------------------------------------------------------------------------------------
-- PART 6 — RECEIPT DRAFTS (the AI-filled purchase card).
--
-- The photo is stored and the draft row written BEFORE the model is called, so a model
-- failure leaves a draft with a picture and an empty card - never a lost receipt.
-- client_uuid is minted when the card OPENS, not on Submit, so a retry after a timeout
-- cannot book the same purchase twice (the lesson from the Supplies round-2 fix).
-- -------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_receipt_draft` (
  `id`          INT(11) NOT NULL AUTO_INCREMENT,
  `client_uuid` CHAR(36) DEFAULT NULL,
  `vendor_id`   INT(11) DEFAULT NULL,
  `image_path`  VARCHAR(500) DEFAULT NULL,
  `model`       VARCHAR(80) DEFAULT NULL COMMENT 'which model read it, for cost tracking',
  `raw_json`    LONGTEXT DEFAULT NULL COMMENT 'exactly what the model returned',
  `parsed_json` LONGTEXT DEFAULT NULL COMMENT 'the card after matching, as last seen by the user',
  `status`      ENUM('draft','submitted','discarded','failed') NOT NULL DEFAULT 'draft',
  `ledger_id`   INT(11) DEFAULT NULL COMMENT 'the purchase it became',
  `tokens_in`   INT(11) DEFAULT NULL,
  `tokens_out`  INT(11) DEFAULT NULL,
  `extract_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'how many times the model was asked to read this draft - the rate limit counts THIS, not rows, because one uuid is deliberately reused across retries',
  `error`       VARCHAR(500) DEFAULT NULL,
  `created_by`  INT(11) DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_receipt_draft_uuid` (`client_uuid`),
  KEY `idx_receipt_draft_user` (`created_by`,`status`),
  KEY `idx_receipt_draft_vendor` (`vendor_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A photographed receipt read by the vision model, awaiting human confirmation.';


-- A database that already ran an earlier copy of this file gets the counter added here.
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 't_fin_receipt_draft'
        AND column_name = 'extract_count') = 0,
    'ALTER TABLE `t_fin_receipt_draft`
        ADD COLUMN `extract_count` INT(11) NOT NULL DEFAULT 0 COMMENT "reads of this draft - what the rate limit counts" AFTER `tokens_out`',
    'SELECT "PART 6b: t_fin_receipt_draft already has extract_count" AS note'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -------------------------------------------------------------------------------------
-- PART 7 — THE LINK FROM A PURCHASE TO AN INGREDIENT.
--
-- ingredient_id + pack_qty_base on the vendor CATALOGUE answers "one unit of this
-- catalogue product is how many base units of that ingredient": a 1 L canola pack is
-- 1000 ml, a kg of onions is 1000 g, one egg tray of 30 is 30 pcs.
-- The purchase LINE then carries a stamped copy, so a later catalogue re-tag never
-- rewrites history (the opposite choice from category_level_1, deliberately - a category
-- is an opinion you may revise, a quantity is a fact about that day).
--
-- Both nullable: every existing row, every untagged product and every old APK keep working.
-- -------------------------------------------------------------------------------------
SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 't_fin_vendor_products'
        AND column_name = 'ingredient_id') = 0,
    'ALTER TABLE `t_fin_vendor_products`
        ADD COLUMN `ingredient_id` INT(11) NULL DEFAULT NULL COMMENT "FK t_crm_khaas_ingredient" AFTER `category_level_1`,
        ADD COLUMN `pack_qty_base` DECIMAL(14,3) NULL DEFAULT NULL COMMENT "base units of that ingredient in ONE unit of this product" AFTER `ingredient_id`',
    'SELECT "PART 7a: t_fin_vendor_products already has ingredient_id" AS note'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 't_fin_vendor_products'
        AND index_name = 'idx_vendor_products_ingredient') = 0,
    'CREATE INDEX `idx_vendor_products_ingredient` ON `t_fin_vendor_products` (`ingredient_id`)',
    'SELECT "PART 7b: index already present" AS note'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 't_fin_vendor_purchase_items'
        AND column_name = 'ingredient_id') = 0,
    'ALTER TABLE `t_fin_vendor_purchase_items`
        ADD COLUMN `ingredient_id` INT(11) NULL DEFAULT NULL COMMENT "stamped from the catalogue at save time" AFTER `vendor_product_id`,
        ADD COLUMN `qty_base` DECIMAL(16,3) NULL DEFAULT NULL COMMENT "quantity x pack_qty_base, in the ingredient base unit" AFTER `unit`',
    'SELECT "PART 7c: t_fin_vendor_purchase_items already has ingredient_id" AS note'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = 't_fin_vendor_purchase_items'
        AND index_name = 'idx_purchase_items_ingredient') = 0,
    'CREATE INDEX `idx_purchase_items_ingredient` ON `t_fin_vendor_purchase_items` (`ingredient_id`)',
    'SELECT "PART 7d: index already present" AS note'));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -------------------------------------------------------------------------------------
-- PART 8 — PERMISSIONS.
--
-- ⚠⚠ permission_group is NOT NULL with NO default. Omitting it fails on a strict
--    connection and silently stores '' on stackcp's client, which lands the key in NO
--    group on Roles -> Permissions. Set explicitly to khaas_mode, matching its siblings.
--
-- manage_khaas_recipes -> 10 Management, 14 Taimur, 17 khaas (Qasim), 18 Shabib.
--    Qasim is IN: he is the one who knows the recipe, and the owner asked for the recipe
--    to be editable from his phone.
-- view_khaas_costing  -> 10, 14, 18 only, per the ruling "Qasim sees quantities only".
--    ⚠ See the pre-flight note: this HIDES the new ingredient rupees from him while
--      leaving the Month Review cost tiles he sees today exactly as they are.
-- -------------------------------------------------------------------------------------
INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at)
SELECT 'manage_khaas_recipes', 'Manage Frozen Recipes', 'khaas_mode',
       'Add ingredients and edit the recipe behind each frozen product', 64, 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'manage_khaas_recipes');

INSERT INTO t_sys_mobile_permission
       (permission_code, permission_name, permission_group, description, display_order, is_active, created_at)
SELECT 'view_khaas_costing', 'View Frozen Ingredient Cost', 'khaas_mode',
       'See estimated ingredient cost per pack and the rupees on the Ingredients panel', 65, 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'view_khaas_costing');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
  FROM t_sys_mobile_permission mp
  JOIN t_sys_role r ON r.id IN (10, 14, 17, 18)
 WHERE mp.permission_code = 'manage_khaas_recipes'
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission rmp
                    WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id);

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT r.id, mp.id, NOW()
  FROM t_sys_mobile_permission mp
  JOIN t_sys_role r ON r.id IN (10, 14, 18)
 WHERE mp.permission_code = 'view_khaas_costing'
   AND NOT EXISTS (SELECT 1 FROM t_sys_role_mobile_permission rmp
                    WHERE rmp.role_id = r.id AND rmp.mobile_permission_id = mp.id);


-- -------------------------------------------------------------------------------------
-- PART 9 — SEED THE MEAT INGREDIENTS from the recipes that already exist.
--
-- The 11 rows in t_crm_khaas_product_recipe map a frozen product to a storage raw
-- material. Those mappings KEEP working exactly as they do (they are what deducts kg of
-- meat when a plan is accepted); this part only creates the matching ingredient rows so
-- the new recipe form opens with the meat line already there and the bought-vs-used
-- panel can show meat from the storage ledger.
--
-- Named off the storage product, with the "KW - " / "DKC - " supplier prefix and the
-- trailing measurement noise trimmed, because Qasim reads these on a phone.
-- -------------------------------------------------------------------------------------
-- ⚠⚠ MEAT ONLY. The storage list also contains potato ("KW - Potato (Aloo) per kg"),
--    and PART 10 below seeds "Potato (Aaloo)" from the Vegetable Supplies catalogue.
--    Seeding both produced TWO potatoes with two spellings — caught on the device,
--    22-Sep — which is exactly the confusion this feature exists to remove: Qasim
--    picks one in the recipe, tags the other on the vendor product, and the cost
--    silently reads zero because they never meet.
--    Potato is bought from a vendor like any other vegetable, so it belongs to PART 10
--    and is excluded here. A storage link also changes how the panel reports an
--    ingredient (ledger-exact, vendor purchases ignored), which is right for meat and
--    wrong for a vegetable.
--
-- ⭐ Names are trimmed for a phone screen: the supplier prefix, the "per kg" and the
--    "- Net weight" noise all go, and so does the trailing "(Chicken Qeema Samosa)"
--    that repeats the product the material is for.
INSERT INTO t_crm_khaas_ingredient
       (business_unit_id, name, base_unit, display_unit, kind, storage_product_id, is_active, created_at)
SELECT 2,
       LEFT(TRIM(
         REGEXP_REPLACE(
           REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p.title,
             'KW - ', ''), 'DKC - ', ''), ' per kg - Net weight', ''), '- Net weight', ''), ' per kg', ''),
           '[[:space:]]*\\([^()]*(Samosa|Kabab|Kebab|Roll)[^()]*\\)[[:space:]]*$', '')
       ), 150),
       'g', 'kg', 'meat', p.id, 1, NOW()
  FROM (SELECT DISTINCT r.storage_product_id AS pid
          FROM t_crm_khaas_product_recipe r
         WHERE r.storage_product_id IS NOT NULL AND r.is_active = 1) AS src
  JOIN t_crm_prod_product p ON p.id = src.pid
 WHERE p.title NOT LIKE '%Potato%'
   AND p.title NOT LIKE '%Aloo%'
   AND NOT EXISTS (SELECT 1 FROM t_crm_khaas_ingredient i WHERE i.storage_product_id = p.id)
   AND NOT EXISTS (SELECT 1 FROM t_crm_khaas_ingredient i2
                    WHERE i2.business_unit_id = 2
                      AND i2.name = LEFT(TRIM(
                          REGEXP_REPLACE(
                            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(p.title,
                              'KW - ', ''), 'DKC - ', ''), ' per kg - Net weight', ''), '- Net weight', ''), ' per kg', ''),
                            '[[:space:]]*\\([^()]*(Samosa|Kabab|Kebab|Roll)[^()]*\\)[[:space:]]*$', '')
                        ), 150));


-- -------------------------------------------------------------------------------------
-- PART 10 — SEED THE VEGETABLE + CHEESE INGREDIENTS and tag the catalogues that exist.
--
-- Vendor 21 "Vegetable Supplies" already has an 11-item catalogue in kg (its rates are
-- Rs 1 placeholders, which is fine - the rate used for costing comes from what was
-- actually PAID on the purchase line, not from the catalogue).
-- Vendor 23 "Tazo cheese" sells a 400 g pack.
--
-- ⚠ The Level-1 category tag cannot carry this: its live vocabulary is Mutton / Beef /
--   Chicken / Raheel / Sadqa / Aqeeqa / Other, and the Aug-26 seed deliberately files
--   vegetables, cheese and charcoal under "Other". Ingredient is a NEW axis.
-- -------------------------------------------------------------------------------------
INSERT INTO t_crm_khaas_ingredient (business_unit_id, name, base_unit, display_unit, kind, is_active, created_at)
SELECT 2, v.name, 'g', 'kg', 'vegetable', 1, NOW() FROM (
    SELECT 'Cabbage (Band Gobi)' AS name UNION ALL SELECT 'Carrot (Gajar)'
    UNION ALL SELECT 'Onions (Piyaaz)'        UNION ALL SELECT 'Potato (Aaloo)'
    UNION ALL SELECT 'Green Onion (Hari Piyaaz)' UNION ALL SELECT 'Garlic (Lehsan)'
    UNION ALL SELECT 'Ginger (Adrak)'         UNION ALL SELECT 'Green Chilli (Hari Mirch)'
    UNION ALL SELECT 'Corriander (Dhaniya)'   UNION ALL SELECT 'Mint (Podina)'
    UNION ALL SELECT 'Capsicum (Shimla Mirch)'
) v
 WHERE NOT EXISTS (SELECT 1 FROM t_crm_khaas_ingredient i
                    WHERE i.business_unit_id = 2 AND i.name = v.name);

INSERT INTO t_crm_khaas_ingredient (business_unit_id, name, base_unit, display_unit, kind, is_active, created_at)
SELECT 2, 'Cheese', 'g', 'kg', 'dairy', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_crm_khaas_ingredient WHERE business_unit_id = 2 AND name = 'Cheese');

-- Tag vendor 21's catalogue: its unit is kg, so ONE unit = 1000 g of that ingredient.
UPDATE t_fin_vendor_products vp
  JOIN t_crm_khaas_ingredient i ON i.business_unit_id = 2 AND i.name = vp.product_name
   SET vp.ingredient_id = i.id, vp.pack_qty_base = 1000.000
 WHERE vp.vendor_id = 21 AND vp.ingredient_id IS NULL;

-- Tazo cheese is sold as a 400 g pack, so ONE unit = 400 g.
UPDATE t_fin_vendor_products vp
  JOIN t_crm_khaas_ingredient i ON i.business_unit_id = 2 AND i.name = 'Cheese'
   SET vp.ingredient_id = i.id, vp.pack_qty_base = 400.000
 WHERE vp.vendor_id = 23 AND vp.product_name LIKE '%Cheese%' AND vp.ingredient_id IS NULL;


-- #####################################################################################
--  POST-FLIGHT — read only. Confirm what landed.
-- #####################################################################################
SELECT 'tables created' AS what, COUNT(*) AS n FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name IN ('t_crm_khaas_ingredient','t_crm_khaas_recipe','t_crm_khaas_recipe_line',
                      't_crm_khaas_batch_consumption','t_crm_khaas_ingredient_opening','t_fin_receipt_draft');
-- EXPECT 6.

SELECT 'columns added' AS what, COUNT(*) AS n FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND ((table_name = 't_fin_vendor_products'       AND column_name IN ('ingredient_id','pack_qty_base'))
     OR (table_name = 't_fin_vendor_purchase_items' AND column_name IN ('ingredient_id','qty_base')));
-- EXPECT 4.

SELECT 'ingredients seeded' AS what, kind, COUNT(*) AS n
  FROM t_crm_khaas_ingredient WHERE business_unit_id = 2 GROUP BY kind ORDER BY kind;
-- EXPECT meat 6, vegetable 11, dairy 1 = 18 rows, and 12 catalogue products tagged.
-- (NOT 12 vegetables — PART 9 deliberately EXCLUDES the storage potato so it cannot
--  collide with PART 10's "Potato (Aaloo)". Rehearsed against a FRESH PROD COPY on
--  22-Sep and these are the exact figures. There is NO "Cooking oil" row: the SQL does
--  not seed it and does not tag Grocery's oil product — Qasim adds both by hand.)

SELECT 'catalogue products now tagged' AS what, v.vendor_name, vp.product_name, i.name AS ingredient,
       vp.unit, vp.pack_qty_base
  FROM t_fin_vendor_products vp
  JOIN t_fin_vendors v ON v.id = vp.vendor_id
  JOIN t_crm_khaas_ingredient i ON i.id = vp.ingredient_id
 ORDER BY v.vendor_name, vp.product_name;
-- EXPECT the 11 Vegetable Supplies rows at 1000 g and the Tazo cheese pack at 400 g.

SELECT 'permissions' AS what, mp.permission_code, r.id AS role_id, r.urole_name
  FROM t_sys_mobile_permission mp
  JOIN t_sys_role_mobile_permission rmp ON rmp.mobile_permission_id = mp.id
  JOIN t_sys_role r ON r.id = rmp.role_id
 WHERE mp.permission_code IN ('manage_khaas_recipes','view_khaas_costing')
 ORDER BY mp.permission_code, r.id;
-- EXPECT manage_khaas_recipes -> 10,14,17,18 ; view_khaas_costing -> 10,14,18.

SELECT 'everyone must log in again' AS reminder,
       'Permissions come from the login snapshot, so Qasim/Taimur/Shabib sign out and in once.' AS note;
