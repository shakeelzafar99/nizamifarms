-- ============================================================================
-- Phase G — Payroll (July 2026)
-- Records each salary PAYMENT made from the new Payroll screen: one row per
-- employee per month. Gives the grid its Paid/Unpaid status, blocks double-pay
-- (UNIQUE user+month), and preserves the exact figures that were paid.
--
-- Run once on LOCAL, then on PROD (manual). Idempotent: IF NOT EXISTS.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_hr_payroll_payment` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED NOT NULL,
  `pay_month`        VARCHAR(7)  NOT NULL COMMENT 'Y-m, e.g. 2026-07',
  `base_salary`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `working_days`     INT NOT NULL DEFAULT 0,
  `present_days`     INT NOT NULL DEFAULT 0,
  `absent_days`      INT NOT NULL DEFAULT 0,
  `leave_days`       INT NOT NULL DEFAULT 0,
  `absent_deduction` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `late_minutes`     INT NOT NULL DEFAULT 0,
  `late_deduction`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `late_leave_deduct` INT NOT NULL DEFAULT 0,
  `bonus_leaves`     INT NOT NULL DEFAULT 0,
  `advance_total`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `net_salary`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `funding`          VARCHAR(16) NOT NULL DEFAULT 'cash' COMMENT 'cash | online',
  `bank_id`          BIGINT UNSIGNED NULL COMMENT 't_fin_online_receiving_accounts.id when online',
  `ledger_id`        BIGINT UNSIGNED NULL COMMENT 't_fin_ledger.id of the salary_payment entry',
  `status`           VARCHAR(16) NOT NULL DEFAULT 'paid',
  `notes`            VARCHAR(255) NULL,
  `paid_at`          DATETIME NULL,
  `paid_by`          BIGINT UNSIGNED NULL,
  `created_at`       TIMESTAMP NULL DEFAULT NULL,
  `updated_at`       TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_month` (`user_id`, `pay_month`),
  KEY `idx_month` (`pay_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
