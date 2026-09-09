-- =====================================================================
--  STORAGE (SUPPLIES STOCK) — Sep-2026
--  Run this BEFORE uploading the PHP, and before the APK.
--  Idempotent: safe to re-run (CREATE TABLE IF NOT EXISTS,
--  ADD COLUMN IF NOT EXISTS, INSERT ... WHERE NOT EXISTS).
--
--  WHAT IT IS
--  ----------
--  Packaging (bags, cups) is bought in bulk but used over 3-4 months. Today
--  the whole purchase lands as ONE expense in the purchase month, which skews
--  that month's profit and flatters the next three.
--
--  From here:
--    BOOK IN   paid-from account -> SUPPLIES_STOCK    type 'supply_purchase'
--              cash leaves NOW. NOT an expense, NOT in profit.
--    TAKE OUT  SUPPLIES_STOCK    -> EXP_<category>    type 'expense' (via a request)
--              one packet at a time, dated the day it was used, so the cost
--              lands in the month it was actually consumed.
--
--  The paying account moves EXACTLY ONCE, at purchase. A take-out never
--  touches it again — that is what stops the cash being counted twice. The
--  account is named in the take-out's description so every screen can still
--  read where the money originally came from.
--
--  ⚠⚠ account_category is 'supplies_stock' ON PURPOSE. Bank pools, the
--     payment-source picker, employee-cash formulas, daily closing and the
--     working-capital card all select accounts by category cash / bank /
--     employee_cash. A brand-new category is invisible to all of them by
--     construction — which is correct: unused bags are not spendable cash.
--     Do NOT "fix" it to cash to make it appear somewhere.
--
--  ⚠  transaction_type 'supply_purchase' is likewise invisible to every
--     expense/P&L query (they all filter transaction_type = 'expense'). The
--     PHP adds ONE "Supplies bought" line to Reports + HQ so the cash-out is
--     still visible. Ship that before the first real batch.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. The stock account.
--
--    Debit arithmetic (BalancePostingService::DEBIT_ARITHMETIC includes
--    'asset'), normal orientation:
--      supply_purchase (from = cash/bank, to = SUPPLIES_STOCK)
--          -> paying account -amount, stock +amount
--      expense         (from = SUPPLIES_STOCK, to = EXP_*)
--          -> stock -cost, expense +cost
--    When every packet of a batch is out, that batch's contribution is 0.
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_accounts`
  (`account_code`, `account_name`, `account_type`, `account_category`,
   `opening_balance`, `current_balance`, `is_active`, `is_private`,
   `business_unit_id`, `created_at`, `created_by`)
SELECT 'SUPPLIES_STOCK', 'Storage - Supplies stock', 'asset', 'supplies_stock',
       0.00, 0.00, 1, 0, 1, NOW(), 1
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `t_fin_accounts` WHERE `account_code` = 'SUPPLIES_STOCK'
 );


-- ---------------------------------------------------------------------
-- 2. The take-out approval switch (owner may turn approval off later).
--    '1' = every staff take-out queues for L1 (default, today's behaviour).
--    '0' = take-outs post immediately for everyone, no queue, no banner.
--    Read through ConfigModel::get() (1 h cache, cleared on set), toggled
--    from the Storage page.
--    Flipping it never strands an already-pending row: it only decides what
--    happens to the NEXT take-out.
-- ---------------------------------------------------------------------
INSERT INTO `t_fin_config`
  (`config_key`, `config_value`, `description`, `business_unit_id`)
SELECT 'supply_takeout_requires_approval', '1',
       'Storage take-outs: 1 = queue for approval (default), 0 = post immediately', 1
  FROM DUAL
 WHERE NOT EXISTS (
   SELECT 1 FROM `t_fin_config` WHERE `config_key` = 'supply_takeout_requires_approval'
 );


-- ---------------------------------------------------------------------
-- 3a. Supply products — what a manager defines once.
--
--     mode decides how a packet is identified and counted:
--       weight : Czerlop scale label (2 + 6-digit PLU + 5-digit grams + check)
--                -> one scan = one packet, kg from the label
--       scan   : a fixed product barcode (supplier EAN or a printed code)
--                -> one scan = one packet, qty 1
--       pieces : nothing to scan, the count is typed in and out
--
--     `plu` and `barcode` are UNIQUE but nullable — MySQL/MariaDB allow many
--     NULLs in a unique index, so only the products that actually use a code
--     are constrained. One code therefore resolves to exactly one product.
--
--     expense_category_name is a SNAPSHOT of the t_fin_config row's value.
--     It, not the FK, is what goes onto the expense request — so renaming or
--     deleting a config row can never break a take-out mid-flight.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_product` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `mode` ENUM('weight','scan','pieces') NOT NULL DEFAULT 'weight',
    `plu` INT(11) NULL COMMENT 'weight mode: Czerlop PLU programmed on the scale',
    `barcode` VARCHAR(40) NULL COMMENT 'scan mode: the fixed product barcode',
    `pieces_per_packet` INT(11) NULL COMMENT 'informational only, e.g. 50 cups per sleeve',
    `expense_config_id` INT(11) NOT NULL COMMENT 'FK to t_fin_config (EXPENSE_CATEGORY_* row)',
    `expense_category_name` VARCHAR(255) NOT NULL COMMENT 'snapshot of that row - what lands on the request',
    `business_unit_id` INT(11) NOT NULL DEFAULT 1,
    `low_stock_qty` DECIMAL(10,3) NULL COMMENT 'chip on the card when remaining falls below this',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT(11) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_sup_prod_plu` (`plu`),
    UNIQUE KEY `uq_sup_prod_barcode` (`barcode`),
    INDEX `idx_sup_prod_active` (`is_active`, `business_unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Storage supplies catalogue (packaging etc) - separate from sellable products.';


-- ---------------------------------------------------------------------
-- 3b. Batches — one purchase.
--
--     status:
--       confirmed  : normal, money posted (ledger_id set)
--       stock_only : opening stock already expensed in an earlier month -
--                    total_cost 0, no ledger, its take-outs charge nothing
--       voided     : booked in error, reversed (only while nothing consumed)
--
--     qty_remaining / cost_remaining are maintained inside the same
--     transaction as the packets. For weight/scan they must always equal the
--     in_stock packets' totals (see the verify block at the end); for pieces
--     there are no packet rows and these ARE the stock.
--
--     client_uuid makes a retried Save idempotent - a phone that loses the
--     reply and sends again gets the same batch, never a second one.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_batch` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT(11) NOT NULL,
    `mode` ENUM('weight','scan','pieces') NOT NULL COMMENT 'snapshot of the product mode at booking',
    `packet_count` INT(11) NULL COMMENT 'weight/scan: how many packets were entered',
    `qty_total` DECIMAL(12,3) NOT NULL COMMENT 'kg (weight) | packets (scan) | pieces (pieces)',
    `qty_remaining` DECIMAL(12,3) NOT NULL,
    `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `cost_remaining` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `unit_cost` DECIMAL(12,4) NULL COMMENT 'display only - the packet/piece cost is the truth',
    `purchase_date` DATE NOT NULL,
    `payment_source_account_id` INT(11) NULL COMMENT 'the PARENT account - which account paid',
    `receiving_account_id` INT(10) UNSIGNED NULL COMMENT 'which bank, when the source is a bank',
    `ledger_id` INT(11) NULL COMMENT 'the supply_purchase row; NULL for stock_only',
    `status` ENUM('confirmed','stock_only','voided') NOT NULL DEFAULT 'confirmed',
    `note` VARCHAR(255) NULL,
    `bill_image` VARCHAR(255) NULL,
    `booked_by` INT(11) NULL,
    `voided_by` INT(11) NULL,
    `voided_at` TIMESTAMP NULL DEFAULT NULL,
    `client_uuid` CHAR(36) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_sup_batch_uuid` (`client_uuid`),
    INDEX `idx_sup_batch_product` (`product_id`, `status`),
    INDEX `idx_sup_batch_date` (`purchase_date`),

    CONSTRAINT `fk_sup_batch_product` FOREIGN KEY (`product_id`)
        REFERENCES `t_fin_supply_product` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One bulk purchase of a supply product. FIFO source for take-outs.';


-- ---------------------------------------------------------------------
-- 3c. Packets — the physical unit that gets taken out (weight + scan modes).
--
--     cost = this packet's share of the batch price. Packets 1..N-1 are
--     rounded to the paisa and the LAST packet takes the remainder, so
--     SUM(cost) = total_cost exactly. Never n x rounded.
--
--     Two bags of the same weight print the SAME barcode - a barcode is not
--     a packet id. Take-out therefore matches by barcode and picks the
--     oldest matching packet (FIFO), which is also the correct cost.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_packet` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `batch_id` INT(11) NOT NULL,
    `product_id` INT(11) NOT NULL,
    `seq` INT(11) NOT NULL COMMENT '1..N within the batch',
    `barcode` VARCHAR(20) NULL COMMENT 'raw 13-digit scale label, or the product barcode; NULL if typed',
    `plu` INT(11) NULL,
    `qty` DECIMAL(10,3) NOT NULL COMMENT 'kg (weight) | 1 (scan)',
    `cost` DECIMAL(12,2) NOT NULL,
    `status` ENUM('in_stock','consumed','voided') NOT NULL DEFAULT 'in_stock',
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `consumed_by` INT(11) NULL,
    `takeout_id` INT(11) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_sup_packet_stock` (`product_id`, `status`),
    INDEX `idx_sup_packet_barcode` (`barcode`),
    INDEX `idx_sup_packet_batch` (`batch_id`, `status`),

    CONSTRAINT `fk_sup_packet_batch` FOREIGN KEY (`batch_id`)
        REFERENCES `t_fin_supply_batch` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sup_packet_product` FOREIGN KEY (`product_id`)
        REFERENCES `t_fin_supply_product` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One physical packet. The unit of consumption for weight/scan products.';


-- ---------------------------------------------------------------------
-- 3d. Take-outs — one take-out = one expense request.
--
--     status:
--       pending   : request queued for approval
--       approved  : request approved, expense posted
--       rejected  : approver said no  -> stock restored
--       undone    : scanner cancelled -> stock restored
--       no_charge : stock_only batch (or a cost that rounds to 0) - nothing billed
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_takeout` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT(11) NOT NULL,
    `qty` DECIMAL(12,3) NOT NULL,
    `unit` VARCHAR(10) NOT NULL DEFAULT 'kg' COMMENT 'kg | packet | pcs',
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `source` ENUM('scan','manual') NOT NULL DEFAULT 'scan',
    `status` ENUM('pending','approved','rejected','undone','no_charge') NOT NULL DEFAULT 'pending',
    `request_id` INT(11) NULL COMMENT 'the t_req_master expense request; NULL when no_charge',
    `note` VARCHAR(255) NULL,
    `taken_by` INT(11) NULL,
    `taken_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `settled_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'when it reached approved/rejected/undone',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_sup_takeout_status` (`status`, `taken_at`),
    INDEX `idx_sup_takeout_request` (`request_id`),
    INDEX `idx_sup_takeout_user` (`taken_by`, `taken_at`),

    CONSTRAINT `fk_sup_takeout_product` FOREIGN KEY (`product_id`)
        REFERENCES `t_fin_supply_product` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One packet/quantity taken out of storage for use. Drives one expense request.';


-- ---------------------------------------------------------------------
-- 3e. Take-out legs — which batch(es) the quantity came from.
--     A pieces take-out may span two batches at different unit costs
--     (owner ruling: allowed). One request, one leg per batch.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_takeout_leg` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `takeout_id` INT(11) NOT NULL,
    `batch_id` INT(11) NOT NULL,
    `packet_id` INT(11) NULL COMMENT 'weight/scan modes; NULL for pieces',
    `qty` DECIMAL(12,3) NOT NULL,
    `cost` DECIMAL(12,2) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_sup_leg_takeout` (`takeout_id`),
    INDEX `idx_sup_leg_batch` (`batch_id`),

    CONSTRAINT `fk_sup_leg_takeout` FOREIGN KEY (`takeout_id`)
        REFERENCES `t_fin_supply_takeout` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sup_leg_batch` FOREIGN KEY (`batch_id`)
        REFERENCES `t_fin_supply_batch` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Which batch each part of a take-out came from (FIFO split).';


-- ---------------------------------------------------------------------
-- 3f. Append-only movement log. Never updated, never deleted.
--     No updated_at by design.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `t_fin_supply_log` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `action` ENUM('in','out','undo','void','correct','count') NOT NULL,
    `product_id` INT(11) NULL,
    `product_name` VARCHAR(150) NULL COMMENT 'snapshot, survives a product rename',
    `batch_id` INT(11) NULL,
    `packet_id` INT(11) NULL,
    `takeout_id` INT(11) NULL,
    `qty` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    `unit` VARCHAR(10) NOT NULL DEFAULT 'kg',
    `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `source` ENUM('scan','manual') NULL,
    `note` VARCHAR(255) NULL,
    `created_by` INT(11) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_sup_log_created` (`created_at`),
    INDEX `idx_sup_log_product` (`product_id`, `created_at`),
    INDEX `idx_sup_log_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only storage movement ledger. No updated_at by design.';


-- ---------------------------------------------------------------------
-- 4. Link the expense request back to its take-out.
--
--    This is what lets approve / reject / cancel / delete find the packet
--    and restore stock, and what the approval guard keys on to REFUSE an
--    approver's payment-source override (the Fleet strip and mobile Daily
--    Closing both preselect NF Cash - without the guard, approving a
--    take-out would take the cash a SECOND time).
-- ---------------------------------------------------------------------
ALTER TABLE `t_req_master`
  ADD COLUMN IF NOT EXISTS `supply_takeout_id` INT(11) NULL
      COMMENT 'Storage take-out this expense request was raised for';

-- CREATE INDEX IF NOT EXISTS (not ALTER TABLE ... ADD INDEX) on purpose: ADD INDEX
-- errors 1061 on a second run, which would ABORT the file here and skip the
-- permission grants below. This form is a clean no-op instead.
CREATE INDEX IF NOT EXISTS `idx_req_supply_takeout` ON `t_req_master` (`supply_takeout_id`);


-- ---------------------------------------------------------------------
-- 5. Mobile permissions. One permission gates the web page, the mobile
--    screen and every endpoint (same pattern as Overnight Storage).
--
--      access_supplies_storage  - see Storage, take packets out   (store team)
--      manage_supplies_storage  - book stock in, define products  (managers)
--      receive_supply_alerts    - the approval banner + push      (approvers)
-- ---------------------------------------------------------------------
INSERT INTO `t_sys_mobile_permission`
       (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`)
SELECT 'access_supplies_storage', 'Storage (Supplies)', 'store_mode',
       'Can open Storage and take packets out for use (raises an expense request).', 77 FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `t_sys_mobile_permission` WHERE `permission_code` = 'access_supplies_storage'
);

INSERT INTO `t_sys_mobile_permission`
       (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`)
SELECT 'manage_supplies_storage', 'Manage Storage stock', 'store_mode',
       'Can book stock IN (packets + total price + which account paid), define supply products, and void a batch.', 78 FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `t_sys_mobile_permission` WHERE `permission_code` = 'manage_supplies_storage'
);

INSERT INTO `t_sys_mobile_permission`
       (`permission_code`, `permission_name`, `permission_group`, `description`, `display_order`)
SELECT 'receive_supply_alerts', 'Storage take-out alerts', 'Notifications',
       'Gets the banner and push when a store take-out is waiting for approval.', 216 FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `t_sys_mobile_permission` WHERE `permission_code` = 'receive_supply_alerts'
);


-- ACCESS: everyone who can already use STORE MODE, plus admin/Taimur by name.
-- The store team are the people physically taking packaging off the shelf, so
-- tying the grant to access_store_mode keeps the two in step instead of naming
-- roles. (Snapshot at run time - a store role created LATER is granted from the
-- web Roles screen, or by re-running this section.)
INSERT INTO `t_sys_role_mobile_permission` (`role_id`, `mobile_permission_id`)
SELECT r.id, p.id
  FROM `t_sys_role` r
  CROSS JOIN `t_sys_mobile_permission` p
 WHERE p.permission_code = 'access_supplies_storage'
   AND (
       LOWER(r.urole_name) IN ('admin', 'taimur')
       OR EXISTS (
           SELECT 1 FROM `t_sys_role_mobile_permission` rp2
             JOIN `t_sys_mobile_permission` p2 ON p2.id = rp2.mobile_permission_id
            WHERE rp2.role_id = r.id AND p2.permission_code = 'access_store_mode'
       )
   )
   AND NOT EXISTS (
       SELECT 1 FROM `t_sys_role_mobile_permission` x
        WHERE x.role_id = r.id AND x.mobile_permission_id = p.id
   );

-- MANAGE + ALERTS: admin / Taimur / Management ONLY — that is Taimur (role 'Taimur')
-- and Shabib (role 'Management') today.
--
-- ⭐ Owner ruling (Sep-7): TAKING packets OUT is for every store-mode user (the grant
--    above). PUTTING stock IN spends real money and stays with Taimur and Shabib.
--    So this is deliberately NOT tied to access_store_mode, and deliberately NOT tied
--    to "holds approval level 1" either — that would have swept in the 'khaas' role
--    (Qasim, saad, Sabir), who have no store mode at all, and the empty 'expense fund'
--    role. Name-based here, matching the house pattern used elsewhere for this trio.
INSERT INTO `t_sys_role_mobile_permission` (`role_id`, `mobile_permission_id`)
SELECT r.id, p.id
  FROM `t_sys_role` r
  CROSS JOIN `t_sys_mobile_permission` p
 WHERE p.permission_code IN ('manage_supplies_storage', 'receive_supply_alerts')
   AND LOWER(r.urole_name) IN ('admin', 'taimur', 'management')
   AND NOT EXISTS (
       SELECT 1 FROM `t_sys_role_mobile_permission` x
        WHERE x.role_id = r.id AND x.mobile_permission_id = p.id
   );

-- Upgrade a DB that ran an EARLIER copy of this file, before price correction and
-- stock counts existed. No-op on a fresh install (the CREATE above already has them).
ALTER TABLE `t_fin_supply_log`
  MODIFY COLUMN `action` ENUM('in','out','undo','void','correct','count') NOT NULL;


-- Self-correction for a DB that ran an EARLIER copy of this file, when the grant was
-- keyed on "holds approval level 1" and so reached 'khaas' and 'expense fund'.
-- Harmless no-op on a fresh install. Only ever removes the two roles that were wrong;
-- anything the owner ticked by hand on the Roles screen is untouched.
DELETE rp FROM `t_sys_role_mobile_permission` rp
  JOIN `t_sys_mobile_permission` p ON p.id = rp.mobile_permission_id
  JOIN `t_sys_role` r ON r.id = rp.role_id
 WHERE p.permission_code IN ('manage_supplies_storage', 'receive_supply_alerts')
   AND LOWER(r.urole_name) IN ('khaas', 'expense fund');


-- =====================================================================
--  VERIFY (run after, all should read as described)
-- =====================================================================
-- -- the account exists, balance 0 to start:
-- SELECT id, account_code, account_name, account_type, account_category, current_balance
--   FROM t_fin_accounts WHERE account_code = 'SUPPLIES_STOCK';
--
-- -- the switch is ON:
-- SELECT config_key, config_value FROM t_fin_config
--  WHERE config_key = 'supply_takeout_requires_approval';
--
-- -- six tables, all empty:
-- SELECT 'product' t, COUNT(*) n FROM t_fin_supply_product
-- UNION ALL SELECT 'batch',   COUNT(*) FROM t_fin_supply_batch
-- UNION ALL SELECT 'packet',  COUNT(*) FROM t_fin_supply_packet
-- UNION ALL SELECT 'takeout', COUNT(*) FROM t_fin_supply_takeout
-- UNION ALL SELECT 'leg',     COUNT(*) FROM t_fin_supply_takeout_leg
-- UNION ALL SELECT 'log',     COUNT(*) FROM t_fin_supply_log;
--
-- -- who got what (expect: store roles on access; Taimur/Management on all three):
-- SELECT r.urole_name, p.permission_code
--   FROM t_sys_role_mobile_permission rp
--   JOIN t_sys_role r ON r.id = rp.role_id
--   JOIN t_sys_mobile_permission p ON p.id = rp.mobile_permission_id
--  WHERE p.permission_code LIKE '%supplies%' OR p.permission_code = 'receive_supply_alerts'
--  ORDER BY p.permission_code, r.urole_name;
--
-- -- the request column is there:
-- SHOW COLUMNS FROM t_req_master LIKE 'supply_takeout_id';
--
-- -- ONCE LIVE, this must always return 0 rows (batch counters agree with packets;
-- -- pieces batches are excluded because they have no packet rows):
-- SELECT b.id, b.qty_remaining, SUM(p.qty) packet_qty, b.cost_remaining, SUM(p.cost) packet_cost
--   FROM t_fin_supply_batch b
--   JOIN t_fin_supply_packet p ON p.batch_id = b.id AND p.status = 'in_stock'
--  WHERE b.mode <> 'pieces' AND b.status <> 'voided'
--  GROUP BY b.id
-- HAVING ABS(b.qty_remaining - SUM(p.qty)) > 0.0005
--     OR ABS(b.cost_remaining - SUM(p.cost)) > 0.005;
