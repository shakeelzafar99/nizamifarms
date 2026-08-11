-- ============================================================================
-- Vendor purchases / payments: MULTIPLE bill & receipt images (Aug-2026)
-- ============================================================================
--
-- WHY
-- ---
-- A vendor purchase could carry exactly ONE photo: t_fin_ledger.bill_image, a
-- single path. A day's delivery routinely comes with several slips, and staff
-- were having to pick one and drop the rest. This adds a child table so a
-- ledger row can hold any number of images, on web and on mobile, for both the
-- by-total and the by-weight flows (and for payments, same mechanism).
--
-- ⭐⭐ THE CONTRACT — READ THIS BEFORE CHANGING ANY IMAGE CODE
-- -----------------------------------------------------------
-- The TABLE is the truth. `t_fin_ledger.bill_image` STAYS, forever, as a
-- mirror of the FIRST image (sort_order 0). Nothing that reads bill_image had
-- to change, which is the whole point:
--
--   * the currently-installed APK reads bill_image and knows nothing else;
--   * the old vendor page, the old ledger page and the Assistant draft flow
--     all read/write bill_image;
--   * the report's 📎 chip keys off bill_image.
--
-- So: every writer that sets images must ALSO keep bill_image pointing at the
-- first one, and every deleter must clear both. Break that and an old client
-- silently shows no attachment at all.
--
-- SAFETY
-- ------
-- Purely additive. Creating the table changes nothing on its own — the code is
-- guarded with Schema::hasTable and falls back to single-image behaviour when
-- the table is absent, so this can be run BEFORE or AFTER the file upload.
-- The backfill is re-runnable (it skips rows already copied).
--
-- ORDER OF OPERATIONS: run this SQL, upload the web files, then ship the APK.
-- A new APK against an old backend would silently drop the extra images.
-- ============================================================================

-- 1) The child table. ledger_id is INT to match t_fin_ledger.id (int(11)).
CREATE TABLE IF NOT EXISTS `t_fin_ledger_images` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ledger_id` INT NOT NULL,
    `image_path` VARCHAR(500) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_lim_ledger` (`ledger_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Backfill: every ledger row that already has a photo gets its row here, so
--    the table is complete from day one and the UI never has to read from two
--    places. Re-runnable — the NOT EXISTS skips anything already copied.
INSERT INTO `t_fin_ledger_images` (`ledger_id`, `image_path`, `sort_order`, `created_by`, `created_at`, `updated_at`)
SELECT l.`id`, l.`bill_image`, 0, l.`created_by`, NOW(), NOW()
FROM `t_fin_ledger` l
WHERE l.`bill_image` IS NOT NULL
  AND l.`bill_image` <> ''
  AND NOT EXISTS (
      SELECT 1 FROM `t_fin_ledger_images` i WHERE i.`ledger_id` = l.`id`
  );

-- 3) Check (expect: images >= backfilled, and every legacy path represented).
-- SELECT
--   (SELECT COUNT(*) FROM t_fin_ledger WHERE bill_image IS NOT NULL AND bill_image <> '') AS legacy_rows,
--   (SELECT COUNT(DISTINCT ledger_id) FROM t_fin_ledger_images) AS rows_with_images,
--   (SELECT COUNT(*) FROM t_fin_ledger_images) AS total_images;

-- ROLLBACK (the column was never touched, so dropping the table restores the
-- previous behaviour exactly):
-- DROP TABLE IF EXISTS `t_fin_ledger_images`;
