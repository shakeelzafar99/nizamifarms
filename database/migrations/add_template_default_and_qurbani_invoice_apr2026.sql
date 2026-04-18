-- Migration: Add is_default column and qurbani_invoice context support to templates
-- Run on both dev and prod

-- 1. Add is_default column (only one template should be default per invoice context)
ALTER TABLE `t_wa_templates`
ADD COLUMN IF NOT EXISTS `is_default` TINYINT(1) NOT NULL DEFAULT 0
COMMENT 'Whether this template is the default for its invoice context'
AFTER `show_in`;

-- NOTE: To mark a template as a qurbani invoice template, add 'qurbani_invoice'
-- to its show_in field via the Manage Templates UI.
-- Example: show_in = 'messages,orders,qurbani_invoice'
-- The is_default flag determines which template auto-selects in the dropdown.
