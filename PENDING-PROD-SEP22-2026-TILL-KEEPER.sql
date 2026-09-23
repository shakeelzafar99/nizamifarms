-- ============================================================================
-- TILL KEEPER — Sep-22-2026
-- Run this ONCE on prod BEFORE uploading the web files, then /api/public/xclean,
-- then build the APK.
--
-- ⚠⚠ ALTER TABLE is NOT rolled back inside a transaction. Run each statement and
--    check the result before moving to the next. Nothing here drops or rewrites
--    existing data — 4 additive columns/tables + 1 backfill + 1 seed row.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. WHOSE HAND POSTED THE ROW.
--    t_fin_ledger.created_by is the REQUESTER on expense rows
--    (LedgerPostingService: 'created_by' => $request->requester_user_id), so it
--    names the person the expense belongs to, not who pressed the button.
--    entered_by is the logged-in actor, stamped by LedgerModel::creating.
--    NULL = console / system / pre-migration row → reads as "System".
-- ---------------------------------------------------------------------------
ALTER TABLE `t_fin_ledger`
  ADD COLUMN `entered_by` INT(11) NULL DEFAULT NULL AFTER `created_by`,
  ADD INDEX `idx_ledger_entered_by` (`entered_by`);

-- ---------------------------------------------------------------------------
-- 2. BACKFILL the actor from the audit log (ledger audit starts 2026-07-04).
--    t_sys_audit_log.user_id on a 'created' row IS the logged-in actor.
--    Rows older than the audit log keep entered_by NULL and fall back to
--    created_by when read.
-- ---------------------------------------------------------------------------
UPDATE `t_fin_ledger` l
  JOIN `t_sys_audit_log` g
    ON g.`entity_type` = 'ledger'
   AND g.`action`      = 'created'
   AND g.`entity_id`   = l.`id`
   SET l.`entered_by`  = g.`user_id`
 WHERE l.`entered_by` IS NULL
   AND g.`user_id` IS NOT NULL;

-- Expect: ~6,300 rows matched. Check before/after:
--   SELECT COUNT(*) AS with_actor FROM t_fin_ledger WHERE entered_by IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 3. THE CASH PILL's unread watermark. One row per person, upserted.
--    Everything newer than last_seen_ledger_id that is not their own hand is
--    "unread" for them.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_ledger_watch` (
  `user_id`              INT(11)  NOT NULL,
  `last_seen_ledger_id`  INT(11)  NOT NULL DEFAULT 0,
  `last_seen_at`         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. THE TILL COUNT — the keeper's own figure, and the SEAL.
--
--    last_ledger_id = MAX(t_fin_ledger.id) at the instant of the count, captured
--    inside the same transaction under a row lock on the account. Anything with a
--    higher id arrived AFTER the count; if it is also dated on/before the count
--    day, it rewrote history behind the keeper's back and the ledger says so.
--
--    ⚠ counted_at is DATETIME, matching the rider engine's
--      t_ops_attendance.cash_confirmed_at, and is written by PHP in Asia/Karachi.
--      Prod runs one clock so `l.updated_at > c.counted_at` (TIMESTAMP vs DATETIME)
--      is exact there. On the DEV REPLICA add `SET SESSION time_zone='+01:00';`
--      before reading, or the drift columns read 2h out.
--
--    Purely a record: no ledger row, no approval item, no money moves — the same
--    charter as RiderController::confirmCash.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_cash_count` (
  `id`              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_id`      INT(11)        NOT NULL,
  `user_id`         INT(11)        NOT NULL,
  `counted_amount`  DECIMAL(15,2)  NOT NULL,
  `system_balance`  DECIMAL(15,2)  NOT NULL,
  `difference`      DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
  `last_ledger_id`  INT(11)        NOT NULL DEFAULT 0,
  `counted_at`      DATETIME       NOT NULL,
  `source`          VARCHAR(12)    NOT NULL DEFAULT 'hub',
  `attendance_id`   BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `note`            VARCHAR(200)   NULL DEFAULT NULL,
  `created_at`      TIMESTAMP      NULL DEFAULT current_timestamp(),
  `updated_at`      TIMESTAMP      NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cashcount_acct_at` (`account_id`, `counted_at`),
  KEY `idx_cashcount_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. WHO HOLDS THE CASH. Deliberately NOT is_default — that means "his default
--    payment source", which Taimur legitimately is on his own accounts.
--    Only offered on account_category = 'cash'.
-- ---------------------------------------------------------------------------
ALTER TABLE `t_fin_account_users`
  ADD COLUMN `is_keeper` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_default`;

-- ---------------------------------------------------------------------------
-- 6. SEED: Shabib holds NF Cash (Main Till).
--    Account 1 = NF_CASH, user 79 = Shabib. Verified on the replica.
--    (He is already tagged on the account, so this is an UPDATE, not an INSERT.)
-- ---------------------------------------------------------------------------
UPDATE `t_fin_account_users`
   SET `is_keeper` = 1
 WHERE `account_id` = (SELECT `id` FROM `t_fin_accounts` WHERE `account_code` = 'NF_CASH')
   AND `user_id`    = (SELECT `id` FROM `t_sys_user` WHERE `fullname` = 'Shabib' LIMIT 1);

-- ---------------------------------------------------------------------------
-- 7. SEED: Taimur answers for the ONLINE account.
--    Owner (Sep-22): "isn't Taimur holding the online account?" — he is: default on it,
--    624 of its 1,627 rows in 60 days. A BANK keeper is WATCHED by the cash pill (told when
--    somebody else moves the account — Shabib's transfers out, online salaries) but is
--    NEVER asked to count it; counting is cash-only. See TillCountService::keeperAccounts.
-- ---------------------------------------------------------------------------
UPDATE `t_fin_account_users`
   SET `is_keeper` = 1
 WHERE `account_id` = (SELECT `id` FROM `t_fin_accounts` WHERE `account_code` = 'ONLINE')
   AND `user_id`    = (SELECT `id` FROM `t_sys_user` WHERE `fullname` = 'Taimur' LIMIT 1);

-- Verify: expect exactly 2 rows — Shabib/NF_CASH and Taimur/ONLINE
--   SELECT u.fullname, a.account_code, a.account_category
--     FROM t_fin_account_users au
--     JOIN t_sys_user u ON u.id = au.user_id
--     JOIN t_fin_accounts a ON a.id = au.account_id
--    WHERE au.is_keeper = 1;
