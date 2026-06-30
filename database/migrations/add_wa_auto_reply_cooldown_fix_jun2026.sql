-- =============================================================================
-- WhatsApp Auto-Reply — cooldown + active-window fix (Jun 2026)
--
-- Fixes the reported "auto-reply kept firing / repeated the same message"
-- behaviour. Root causes were (a) a rule set to match_mode='always' never
-- expires, so a holiday greeting fired every day until manually disabled, and
-- (b) the only throttle was a rolling 6-hour window, so a customer who
-- messaged morning + evening got the same message twice a day across a
-- multi-day holiday.
--
-- This migration adds two things to t_wa_auto_replies:
--
--   1. cooldown_mode — replaces the rolling-hours-only throttle with a choice:
--        'daily'   (default) at most one auto-reply per customer per calendar day,
--        'once'    at most one auto-reply per customer for the whole active period,
--        'rolling' the legacy every-N-hours behaviour (N is now floored at 1 in
--                  code so a mis-set 0 can never disable the throttle and spam).
--
--   2. active_from / active_to — an optional inclusive date range so a holiday
--        rule AUTO-EXPIRES instead of firing forever. Applies to every
--        match_mode (time_window / specific_dates / always).
--
-- Run in MySQL Workbench against LOCAL and PROD. Uses the same
-- `ADD COLUMN IF NOT EXISTS` form already used successfully on the WhatsApp
-- tables (MySQL 8 / MariaDB 10.0+). If your MySQL errors with "Duplicate
-- column", that's harmless — the column already exists; move to the next.
-- No data is modified; no money/ledger impact.
-- =============================================================================

ALTER TABLE `t_wa_auto_replies`
  ADD COLUMN IF NOT EXISTS `cooldown_mode` VARCHAR(20) NOT NULL DEFAULT 'daily'
  COMMENT 'daily = one reply per customer per calendar day (default); once = one per customer per active period; rolling = every cooldown_hours.'
  AFTER `cooldown_hours`;

ALTER TABLE `t_wa_auto_replies`
  ADD COLUMN IF NOT EXISTS `active_from` DATE NULL DEFAULT NULL
  COMMENT 'Optional inclusive start date. Rule does not fire before this date. NULL = no lower bound.'
  AFTER `cooldown_mode`;

ALTER TABLE `t_wa_auto_replies`
  ADD COLUMN IF NOT EXISTS `active_to` DATE NULL DEFAULT NULL
  COMMENT 'Optional inclusive end date. Rule stops firing after this date so holiday rules auto-expire. NULL = no upper bound.'
  AFTER `active_from`;

-- Existing rows take the column default ('daily'), so the previously-misfiring
-- rules will send at most once per customer per day once re-enabled. To restore
-- the legacy every-6h behaviour on a specific rule, open it in the Auto-Reply
-- modal and choose "Every N hours".

-- Verify:
--   SHOW COLUMNS FROM t_wa_auto_replies LIKE 'cooldown_mode';
--   SHOW COLUMNS FROM t_wa_auto_replies LIKE 'active_from';
--   SHOW COLUMNS FROM t_wa_auto_replies LIKE 'active_to';
--   SELECT id, name, match_mode, cooldown_mode, cooldown_hours, active_from, active_to, enabled
--   FROM t_wa_auto_replies ORDER BY priority, id;
