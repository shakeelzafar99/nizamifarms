-- =====================================================
-- Migration: receipt printout field config
-- Date: 2026-07-05
-- Purpose: one JSON blob on the single config row that stores which fields the thermal
--          receipt shows (e.g. hide prices for a delivery slip). NULL = show everything
--          (today's layout), so this is backward-compatible.
--
-- Keys (all default ON when absent): show_prices, show_phone, show_address,
-- show_tagline, show_footer. Extensible — add keys without another migration.
--
-- Run ONCE on LOCAL and PROD.
-- =====================================================

ALTER TABLE t_sys_config ADD COLUMN receipt_print_config TEXT NULL;

-- VERIFICATION:
-- SELECT receipt_print_config FROM t_sys_config WHERE id = 1;
