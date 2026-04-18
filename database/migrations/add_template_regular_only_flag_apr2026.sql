-- =====================================================
-- Migration: WhatsApp templates — is_regular_only flag
-- Created: April 2026
-- Safe to re-run (uses IF NOT EXISTS)
-- =====================================================
--
-- Companion to `is_qurbani_only` (added in add_campaign_qurbani_year_and_replies_apr2026.sql).
-- Three mutually-meaningful states for each template:
--   is_qurbani_only=0, is_regular_only=0  → Common: shows in both regular AND qurbani pickers
--                                            (marketing/broadcast templates go here)
--   is_qurbani_only=1, is_regular_only=0  → Qurbani-only: hidden from regular-order pickers
--   is_qurbani_only=0, is_regular_only=1  → Regular-only: hidden from qurbani-order pickers
--
-- Backward compatible: all existing templates default to Common so nothing
-- disappears from any picker until the user explicitly tags them.

ALTER TABLE `t_wa_templates`
ADD COLUMN IF NOT EXISTS `is_regular_only` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'When 1, the template only shows up in regular-order pickers (non-qurbani); hidden from qurbani_invoice/qurbani_orders contexts regardless of show_in. Mutually exclusive with is_qurbani_only.'
AFTER `is_qurbani_only`;

-- ========= VERIFICATION =========
-- SHOW COLUMNS FROM t_wa_templates LIKE 'is_regular_only';
