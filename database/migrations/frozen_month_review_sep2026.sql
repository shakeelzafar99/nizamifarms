-- =====================================================================
--  Frozen · Month Review  (Sep-04-2026)
--
--  Adds the COST TYPE map used by /khaas/month-review, plus the
--  permission that gates the money half of that screen.
--
--  WHY A MAP TABLE AND NOT A COLUMN ON t_fin_vendors:
--    money reaches the Frozen unit from FOUR different shapes —
--      vendor_purchase  (t_fin_ledger -> vendor account)
--      expense          (t_fin_ledger -> t_req_master.expense_category)
--      salaries         (SalaryCostService: payroll + open advances)
--      asset_purchase   (t_fin_ledger)
--    A column on vendors could only ever classify the first one. One
--    map keyed by (source_kind, source_key) classifies all four, and
--    keeps the classification OUT of the money tables entirely.
--
--  RESOLUTION IS AT READ TIME. Nothing is ever stamped on a ledger row,
--  so re-tagging a vendor re-files its whole history instantly — the
--  same principle as the Category Report's Level-1 tag. Anything with
--  no row resolves to 'unclassified' and is SHOWN in its own bucket,
--  never silently dropped (money reports must not shed rows).
--
--  RUN ORDER: run this BEFORE uploading the PHP/Blade files. The page
--  degrades gracefully (Schema::hasTable guard) but the classification
--  will read as 'unclassified' until the table exists.
--
--  Safe to re-run: every statement is IF NOT EXISTS / INSERT ... SELECT
--  guarded by NOT EXISTS.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Schema
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `t_fin_cost_type_map` (
    `id`               INT(11) NOT NULL AUTO_INCREMENT,
    `business_unit_id` INT(11) NOT NULL,
    `source_kind`      VARCHAR(30)  NOT NULL COMMENT 'vendor | expense_category | salary | asset_purchase',
    `source_key`       VARCHAR(150) NOT NULL COMMENT 'vendor id, expense_category name, or * for the whole kind',
    `cost_type`        VARCHAR(20)  NOT NULL COMMENT 'product | fixed | one_time',
    `updated_by`       INT(11) NULL DEFAULT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cost_type_source` (`business_unit_id`, `source_kind`, `source_key`),
    KEY `idx_cost_type_bu` (`business_unit_id`, `cost_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ⚠ COLLATE must match the tables this is joined/compared against
-- (t_fin_vendors and t_sys_mobile_permission are utf8mb4_unicode_ci).
-- Without it MySQL defaults to utf8mb4_general_ci and every comparison
-- below fails with "Illegal mix of collations" (error 1267).


-- ---------------------------------------------------------------------
-- 2. Seed — Frozen (BU 2)
--
--    Seeded BY VENDOR NAME, not by hard-coded id, so the same file is
--    safe on dev and prod even if the auto-increment ids ever diverge.
--    The owner can change any of these from the screen afterwards; the
--    map is the only thing that decides, so nothing here is permanent.
-- ---------------------------------------------------------------------

-- 2a. PRODUCT cost — what goes into a pack.
INSERT INTO `t_fin_cost_type_map`
    (`business_unit_id`, `source_kind`, `source_key`, `cost_type`)
SELECT 2, 'vendor', CAST(v.`id` AS CHAR), 'product'
FROM `t_fin_vendors` v
WHERE v.`business_unit_id` = 2
  AND v.`vendor_name` IN (
        'Nizami Farms',            -- raw meat, auto-posted by the Meat Order receipt
        'Imtiaz Store',
        'AR Packages',
        'Gas',
        'B.B.Q',
        'Faizoo',
        'Vegetable Supplies',
        'Grocery',
        'Tazo cheese',
        'Ideal Foods',
        'grocery nankari bazaar',
        'grocery punjab cnc',
        'grocery save mart',
        'Abu Bakar (Arsalan Traders)'
  )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM `t_fin_cost_type_map`) m
        WHERE m.`business_unit_id` = 2
          AND m.`source_kind` = 'vendor'
          AND m.`source_key` = CAST(v.`id` AS CHAR)
  );

-- 2b. FIXED cost — running the warehouse, not the pack.
INSERT INTO `t_fin_cost_type_map`
    (`business_unit_id`, `source_kind`, `source_key`, `cost_type`)
SELECT 2, 'vendor', CAST(v.`id` AS CHAR), 'fixed'
FROM `t_fin_vendors` v
WHERE v.`business_unit_id` = 2
  AND v.`vendor_name` IN (
        'Sabir Bhai',                    -- daily staff food (doodh, paratha, biscuits)
        'NF Shop Food',
        'Other Supplies',
        'Warehouse Live-In Expenses'
  )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM `t_fin_cost_type_map`) m
        WHERE m.`business_unit_id` = 2
          AND m.`source_kind` = 'vendor'
          AND m.`source_key` = CAST(v.`id` AS CHAR)
  );

-- 2c. ONE-TIME cost — building the place, not running it.
INSERT INTO `t_fin_cost_type_map`
    (`business_unit_id`, `source_kind`, `source_key`, `cost_type`)
SELECT 2, 'vendor', CAST(v.`id` AS CHAR), 'one_time'
FROM `t_fin_vendors` v
WHERE v.`business_unit_id` = 2
  AND v.`vendor_name` IN (
        'Kitchen Extension - Warehouse'  -- cement, sand, loading, labour
  )
  AND NOT EXISTS (
        SELECT 1 FROM (SELECT * FROM `t_fin_cost_type_map`) m
        WHERE m.`business_unit_id` = 2
          AND m.`source_kind` = 'vendor'
          AND m.`source_key` = CAST(v.`id` AS CHAR)
  );

-- 2d. The non-vendor kinds. '*' is the catch-all for the whole kind;
--     a specific expense_category row overrides it.
INSERT INTO `t_fin_cost_type_map`
    (`business_unit_id`, `source_kind`, `source_key`, `cost_type`)
SELECT * FROM (
    SELECT 2 AS a, 'salary'           AS b, '*'                   AS c, 'fixed'    AS d
    UNION ALL SELECT 2, 'asset_purchase',   '*',                        'one_time'
    UNION ALL SELECT 2, 'expense_category', '*',                        'fixed'
    UNION ALL SELECT 2, 'expense_category', 'Utility Bills - IESCO',    'fixed'
    UNION ALL SELECT 2, 'expense_category', 'Staff Salaries',           'fixed'
) seed
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM `t_fin_cost_type_map`) m
    WHERE m.`business_unit_id` = seed.a
      AND m.`source_kind` = seed.b
      AND m.`source_key` = seed.c
);


-- ---------------------------------------------------------------------
-- 3. Permission — who may see the MONEY half of the screen
--
--    The production half needs only access_khaas_mode (anyone in Frozen
--    mode already sees those numbers on the Inventory Report). The cost
--    sections include staff salaries, so they get their own key.
--
--    Granted to: Management (10), Taimur (14), khaas/Qasim (17),
--    Shabib (18). Deliberately NOT Adnan (16) — the owner wants him on
--    the Frozen REPORTING views later, which is a separate decision.
-- ---------------------------------------------------------------------

INSERT INTO `t_sys_mobile_permission`
    (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`, `is_active`)
SELECT 'view_khaas_month_review', 'View Khaas Month Review', 'khaas_mode',
       'See the Frozen month review: packs made, and cost split into product / fixed / one-time (includes salaries).',
       0, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT * FROM `t_sys_mobile_permission`) p
    WHERE p.`permission_code` = 'view_khaas_month_review'
);

INSERT INTO `t_sys_role_mobile_permission` (`role_id`, `mobile_permission_id`)
SELECT r.`id`, mp.`id`
FROM `t_sys_role` r
CROSS JOIN `t_sys_mobile_permission` mp
WHERE mp.`permission_code` = 'view_khaas_month_review'
  AND r.`id` IN (10, 14, 17, 18)
  AND NOT EXISTS (
      SELECT 1 FROM (SELECT * FROM `t_sys_role_mobile_permission`) x
      WHERE x.`role_id` = r.`id` AND x.`mobile_permission_id` = mp.`id`
  );


-- ---------------------------------------------------------------------
-- 4. Verify
-- ---------------------------------------------------------------------
-- SELECT m.cost_type, COUNT(*) rows_mapped
--   FROM t_fin_cost_type_map m WHERE m.business_unit_id = 2
--  GROUP BY m.cost_type;
--
-- SELECT r.urole_name
--   FROM t_sys_role_mobile_permission rp
--   JOIN t_sys_role r ON r.id = rp.role_id
--   JOIN t_sys_mobile_permission mp ON mp.id = rp.mobile_permission_id
--  WHERE mp.permission_code = 'view_khaas_month_review';
