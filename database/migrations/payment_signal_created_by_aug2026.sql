-- =====================================================================
-- Manual payment entry — attribution column
-- Aug-2026. Run this ONCE on production BEFORE uploading the PHP files.
--
-- Why: t_fin_payment_signal records WHAT was claimed but never WHO typed it.
-- Assistant-created rows can be traced only indirectly, by joining
-- t_ai_drafts on result_id. The owner wants a manual entry to say
-- "recorded by <name>" on its face, so the row carries the user itself.
--
-- Existing rows stay NULL: they were not manually entered, and the
-- assistant rows keep their t_ai_drafts attribution. Nothing back-fills.
--
-- One column. No index (it is displayed, never filtered on).
-- =====================================================================

ALTER TABLE `t_fin_payment_signal`
    ADD COLUMN `created_by` INT NULL DEFAULT NULL AFTER `extractor_version`;

-- ---------------------------------------------------------------------
-- Verify
-- ---------------------------------------------------------------------
-- SHOW COLUMNS FROM t_fin_payment_signal LIKE 'created_by';
