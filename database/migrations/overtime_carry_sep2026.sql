-- ============================================================================
-- Carried overtime — the minutes the 9-hour rule used to throw away (Sep 2026)
--
-- Overtime becomes WHOLE bonus leave days: floor(minutes / 540). Everything under
-- that line was discarded — on real Jun-Aug 2026 attendance, 70.7 hours, 22% of
-- all overtime worked. It also rewarded stopping once you passed a multiple of
-- 9 hours. Now the remainder carries into the next month.
--
-- Owner rulings (Sep-2 2026):
--   * Carried minutes NEVER expire — they end only by being used, or by someone
--     explicitly forfeiting them.
--   * Skipping a month's overtime forfeits the carry too, but the screen must
--     say so before the manager clicks.
--   * The carry must show WHICH MONTH the minutes were earned in.
--   * Consumed oldest-first (the same FIFO rule salary advances use).
--   * Starts with August 2026; nothing earlier is credited.
--
-- ⭐ SHAPE: one row per DECIDED month, not one row per surviving lot. Which
--    months the leftover minutes belong to is DERIVED by replaying these rows in
--    order — a pure function of (minutes earned, days granted) per month. That
--    matters because a single month's leftovers can be spent across two later
--    months, and a partial spend has no natural owner to record it against; the
--    replay never has that problem. It also means the breakdown can never drift
--    out of step with the days actually granted.
--
-- Run once on LOCAL, then on PROD (manual). Guarded by Schema::hasTable, so
-- before this runs payroll behaves exactly as it does today (floor, no carry).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_hr_overtime_carry` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT NOT NULL,
  -- The month whose overtime decision this row records.
  `month`          VARCHAR(7) NOT NULL COMMENT 'YYYY-MM the decision was made FOR',
  `minutes_earned` INT NOT NULL DEFAULT 0 COMMENT 'overtime measured for that month',
  `carried_in`     INT NOT NULL DEFAULT 0 COMMENT 'minutes brought forward (context; the replay recomputes)',
  `days_granted`   INT NOT NULL DEFAULT 0 COMMENT 'whole bonus leave days this month granted',
  `carried_out`    INT NOT NULL DEFAULT 0 COMMENT 'minutes left after the grant (context)',
  -- 'apply' banks the minutes and grants the days; 'waive' grants nothing AND
  -- forfeits everything carried so far (owner ruling — the UI warns first).
  `decision`       VARCHAR(10) NOT NULL DEFAULT 'apply',
  -- A manual forfeit stamps every open row at once. In the replay a stamped row
  -- contributes nothing and clears the queue, which is exactly what a forfeit means.
  `forfeited_at`   DATETIME NULL,
  `forfeited_by`   INT NULL,
  `forfeit_reason` VARCHAR(255) NULL,
  `decided_by`     INT NULL,
  `created_at`     DATETIME NULL,
  `updated_at`     DATETIME NULL,
  UNIQUE KEY `uq_ot_carry_user_month` (`user_id`, `month`),
  KEY `idx_ot_carry_user` (`user_id`, `month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
