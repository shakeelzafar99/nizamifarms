-- ============================================================================
-- Day review — verify overtime / waive late minutes on the day it happens
-- (September 2026)
--
-- Today a manager judges a month's overtime weeks after the fact, from a list of
-- dates, at month close. This adds an EARLY layer: as each day lands, the days
-- that actually need a judgement are offered to Shabib / Taimur with the evidence
-- beside them, so that at month end the decision is one click instead of a review.
--
-- ⭐⭐ THE RULE THAT SHAPES EVERYTHING (owner, Sep-6 2026):
--     an UNREVIEWED day counts IN FULL, exactly as it does today.
-- A manager who never gets to a day changes nothing — riders are never penalised
-- (or rewarded) for a manager's negligence. Only an `adjusted` or `waived` verdict
-- moves a number; `verified` records "I looked, the engine is right" and is
-- arithmetically identical to no row at all.
--
-- Verdicts
--   verified   the computed figure is correct. effective_minutes = computed_minutes.
--   adjusted   the manager sets the real figure (overtime only). Reason required.
--   waived     late  — `waived_minutes` come off that day (genuine, pre-informed
--                      lateness: he told the manager, his phone was dead).
--              overtime — the day was not overtime at all; effective = 0.
--              Reason required.
--
-- ⚠ The monthly late buffer (LATE_MONTHLY_BUFFER_MINS, 150) is a SEPARATE rule and
--   is NOT touched here. Waived minutes are removed from the day first; whatever is
--   left goes into the month total and meets the buffer exactly as before.
--
-- `source_hash` is what makes a review honest. It fingerprints the attendance
-- fields the figure was derived from. If anyone later edits that day — types a new
-- checkout, grants or clears a bypass — the hash no longer matches, the review is
-- marked superseded and the day comes BACK into the queue flagged "changed since
-- you verified it". A verified day can never be quietly edited out from under its
-- verdict, and a stale verdict never keeps moving money.
--
-- Owner rulings (Sep-6 2026):
--   * Reviewers = Shabib + Taimur, gated by the existing `manage_payroll`. No new
--     permission.
--   * Late waives are counted in MINUTES.
--   * Overtime is still GRANTED at month close through the existing leave-actions
--     decision. Reviewing early does not move the grant; it makes it one click.
--   * Only ACTIONABLE days are queued (overtime > 0 or late > 0, not yet reviewed).
--     A clean day never appears — there is nothing to do on it.
--   * Riders see none of this for now.
--   * Nothing before DAY_REVIEW_START is reviewable; back-dates on or after it are,
--     so September can be completed retroactively.
--
-- Run once on LOCAL, then on PROD (manual). Every read is Schema-guarded, so before
-- this runs, payroll and attendance behave exactly as they do today.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_hr_day_review` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`           INT NOT NULL,
  `review_date`       DATE NOT NULL COMMENT 'the day being judged',
  `kind`              ENUM('overtime','late') NOT NULL,
  `computed_minutes`  INT NOT NULL DEFAULT 0 COMMENT 'engine figure at review time, FROZEN',
  `verdict`           ENUM('verified','adjusted','waived') NOT NULL,
  `effective_minutes` INT NOT NULL DEFAULT 0 COMMENT 'what the day is worth after the verdict',
  `waived_minutes`    INT NOT NULL DEFAULT 0 COMMENT 'late only: minutes forgiven',
  `reason`            VARCHAR(200) NULL COMMENT 'required unless verdict = verified',
  `evidence`          TEXT NULL COMMENT 'JSON snapshot of the card the manager judged',
  `source_hash`       CHAR(32) NOT NULL COMMENT 'md5 of the attendance fields behind the figure',
  `reviewed_by`       INT NOT NULL,
  `reviewed_at`       DATETIME NOT NULL,
  `superseded_at`     DATETIME NULL COMMENT 'set when the day was edited after the review',
  `superseded_note`   VARCHAR(200) NULL COMMENT 'what changed, in words',
  UNIQUE KEY `uq_day_review` (`user_id`, `review_date`, `kind`),
  KEY `idx_day_review_date` (`review_date`),
  KEY `idx_day_review_open` (`user_id`, `superseded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The first day that can be reviewed. Nothing before it is queued or decidable, so
-- turning this on does not drag months of history into the queue.
INSERT INTO `t_fin_config` (`config_key`, `config_value`)
SELECT 'DAY_REVIEW_START', '2026-09-01'
WHERE NOT EXISTS (SELECT 1 FROM `t_fin_config` WHERE `config_key` = 'DAY_REVIEW_START');

-- ── Part 2 — keep the waiver on the salary slip ──────────────────────────────
-- A slip is a FROZEN receipt. `late_minutes` on it is now net of anything a manager
-- waived, so without these two columns a slip would record "120 minutes late" for a
-- month the engine measured at 400 and the 280 forgiven minutes would be lost forever.
-- The deduction is correct either way; this is so the receipt can explain itself.
-- Nullable and additive: existing slips keep reading exactly as they do today.
ALTER TABLE `t_hr_salary_slips`
  ADD COLUMN IF NOT EXISTS `late_waived_minutes` INT NULL COMMENT 'minutes a manager forgave (day review)' AFTER `late_minutes`;
ALTER TABLE `t_hr_salary_slips`
  ADD COLUMN IF NOT EXISTS `late_raw_minutes` INT NULL COMMENT 'what the month actually was, before waivers' AFTER `late_waived_minutes`;

-- ── Verify ──────────────────────────────────────────────────────────────────
-- SELECT COUNT(*) AS day_review_rows FROM t_hr_day_review;
-- SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'DAY_REVIEW_START';
