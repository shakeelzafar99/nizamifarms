-- ============================================================================
-- Absences: deduct, park, or excuse  (September 2026)
--
-- Until now an absent day had exactly one outcome: the salary was cut, silently
-- and automatically. The owner wants three:
--
--   CUT     the pay is cut now. Today's behaviour, and still the DEFAULT — an
--           undecided month deducts exactly as it always has.
--   PARK    no cut now. The days stay OWED and can be settled later by overtime
--           days the employee earns, by his own leave, by a cut, or by excusing.
--   EXCUSE  no cut, nothing owed. The absence is forgiven outright.
--
-- One row per employee-month decision. `days_absent` is FROZEN at the moment of
-- the decision — like `minutes_earned` on the overtime carry — so a later
-- attendance edit can never silently change what was agreed.
--
-- Outstanding days on a PARKED row:
--     days_absent - days_covered_ot - days_covered_leave - days_cut - days_excused
-- Settled oldest month first, the same FIFO rule advances and carried overtime
-- already use.
--
-- Owner rulings (Sep-2 2026):
--   * Overtime days cover parked absences automatically — that is why they were
--     parked. The manager can still charge or excuse them afterwards.
--   * A later cut uses the CURRENT day rate, not the rate frozen at parking, and
--     the manager can edit the amount when he cuts.
--   * Parked days may also be covered from the employee's own leave balance.
--   * On leaving, open days are cut in the final pay, or waived.
--   * Undecided at Pay = CUT.
--   * A month left undecided keeps nagging after it ends, into the next month.
--
-- Run once on LOCAL, then on PROD (manual). Every read is Schema-guarded, so
-- before this runs payroll behaves exactly as it does today.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_hr_absence_decision` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`             INT NOT NULL,
  `month`               VARCHAR(7) NOT NULL COMMENT 'YYYY-MM the absences happened in',
  `days_absent`         DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'FROZEN at the decision',
  `decision`            VARCHAR(10) NOT NULL COMMENT 'cut | park | excuse',
  -- Recorded for the audit trail only. A later cut deliberately uses the CURRENT
  -- rate (owner ruling), so this is history, never arithmetic.
  `day_rate_at_decision` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `amount_cut_now`      DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'deducted in that month itself (decision=cut)',
  -- How a PARKED row has since been settled.
  `days_covered_ot`     DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'covered by bonus days earned from overtime',
  `days_covered_leave`  DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'covered from the employee own leave balance',
  `days_cut`            DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'charged later',
  `days_excused`        DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'forgiven later',
  `amount_cut_later`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cut_in_month`        VARCHAR(7) NULL COMMENT 'YYYY-MM whose pay carries the later cut',
  `cut_paid_at`         DATETIME NULL COMMENT 'set when that pay actually went out',
  `decided_by`          INT NULL,
  `decided_at`          DATETIME NULL,
  `notes`               VARCHAR(255) NULL,
  `created_at`          DATETIME NULL,
  `updated_at`          DATETIME NULL,
  UNIQUE KEY `uq_absdec_user_month` (`user_id`, `month`),
  KEY `idx_absdec_open` (`user_id`, `decision`, `month`),
  KEY `idx_absdec_cut_month` (`cut_in_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A later cut has to reach the payslip as its own line, or the receipt would say
-- "absent deduction 0" while money was taken. Kept apart from `absent_deduction`
-- (this month's own absences) so HQ's gross = net + advances stays correct and the
-- employee page can show "August absence charged: 2 days".
ALTER TABLE `t_hr_payroll_payment`
  ADD COLUMN `held_absence_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0
  COMMENT 'Cut taken this month for absences PARKED in an earlier month';

-- The leave ledger has to be able to say WHY a bonus day did not become leave. Without
-- this the insert fails outright: `source` is an ENUM, so an unlisted value is rejected
-- (MySQL truncates it to '' and errors), and coverage could never be recorded.
ALTER TABLE `t_hr_leave_grant`
  MODIFY COLUMN `source` ENUM('manual','overtime','late_penalty','half_day','absence_cover')
  NOT NULL DEFAULT 'manual';
