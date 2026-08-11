-- =====================================================
-- Migration: Per-user sort preferences for Open Order Quantities
-- Date: August 11, 2026
-- One row per user. `prefs` = JSON keyed by hierarchy FIELD name (not level
-- index, so it survives hierarchy changes), e.g.:
--   {"attribute_1":{"mode":"custom","sequence":["Chicken","Beef","Mutton"]},
--    "attribute_2":{"mode":"alpha"}}
-- mode: qty_desc (default when absent) | alpha | custom
--
-- Used by GET/POST /api/rider/store/open-quantities/sort-prefs.
-- Sorting itself happens on the PHONE at display time — the tree endpoint
-- is untouched and never reads this table.
--
-- ⚠ Run on prod BEFORE shipping the APK that calls these endpoints.
--   (Uploading the web files first is safe — old APKs never call them.)
-- =====================================================

CREATE TABLE IF NOT EXISTS `t_crm_open_quantities_user_sort` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `prefs` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-user display sort preferences for Open Order Quantities (mobile)';

-- ROLLBACK:
-- DROP TABLE IF EXISTS `t_crm_open_quantities_user_sort`;
