-- =============================================
-- Migration: Overnight Storage (Chiller / Freezer tracker)
-- Date: 2026-07-30
-- Description: Standalone tracker for what the NF STORE team places in the
--              chiller / freezer overnight. Regular NF products only — no
--              Khaas/BU linkage. Packets are scanned in via the Czerlop scale
--              barcode (1 scan = 1 packet row); items can be bulk-moved
--              between sections or taken out for use. Managers can VERIFY
--              (physically confirm) what's listed. An append-only log drives
--              history + per-day closing counts.
--
--              This feature touches NO other inventory quantity anywhere.
--
-- Safe + re-runnable. Run on LOCAL and PROD BEFORE uploading the web code.
-- STEP 4 upgrades a database that ran an EARLIER copy of this file (before the
-- verification feature existed) — harmless on a fresh install.
--
-- FK COMPATIBILITY NOTES:
--   - t_crm_prod_product.id = BIGINT UNSIGNED
--   - t_sys_user.id = INT (signed)
-- =============================================


-- =============================================
-- STEP 1: Overnight items — current state, ONE ROW PER PACKET
-- Rows are never deleted; taking out sets status='taken_out'.
-- verified_at/by = the LAST time a manager physically confirmed this packet.
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_overnight_item` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_prod_product — NULL if product later deleted (name snapshot survives)',
    `product_name` VARCHAR(255) NOT NULL COMMENT 'Snapshot of product title at entry time',
    `plu` INT NULL COMMENT 'Czerlop PLU decoded from the barcode; NULL for manual adds',
    `barcode` VARCHAR(20) NULL COMMENT 'Raw 13-digit scale barcode; NULL for manual adds',
    `quantity` DECIMAL(10,3) NOT NULL COMMENT 'Weight in kg (scans) or count (manual pcs)',
    `unit` VARCHAR(10) NOT NULL DEFAULT 'kg' COMMENT 'kg | pcs',
    `section` ENUM('chiller','freezer') NOT NULL,
    `status` ENUM('stored','taken_out') NOT NULL DEFAULT 'stored',
    `source` ENUM('scan','manual') NOT NULL DEFAULT 'scan',
    `verified_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last physical verification (manager); NULL = never verified',
    `verified_by` INT NULL COMMENT 'FK to t_sys_user — who last verified this packet',
    `entered_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `entered_by` INT NULL COMMENT 'FK to t_sys_user',
    `taken_out_at` TIMESTAMP NULL DEFAULT NULL,
    `taken_out_by` INT NULL COMMENT 'FK to t_sys_user',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_ovi_status_section` (`status`, `section`),
    INDEX `idx_ovi_entered` (`entered_at`),
    INDEX `idx_ovi_product` (`product_id`),
    INDEX `idx_ovi_verified` (`verified_at`),

    CONSTRAINT `fk_ovi_product` FOREIGN KEY (`product_id`)
        REFERENCES `t_crm_prod_product` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ovi_entered_by` FOREIGN KEY (`entered_by`)
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ovi_taken_out_by` FOREIGN KEY (`taken_out_by`)
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ovi_verified_by` FOREIGN KEY (`verified_by`)
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Overnight chiller/freezer packet tracker (NF store, standalone — touches no other inventory).';

SELECT '✅ Step 1: Created t_crm_overnight_item' as Status;


-- =============================================
-- STEP 2: Overnight log — APPEND-ONLY movement + verification ledger
--
-- MOVEMENT rows (action in 'in','move','out'):
--   in   -> only to_section set     (+1 to that section)
--   out  -> only from_section set   (-1 from that section)
--   move -> both set                (-1 from, +1 to)
-- A section's delta on any movement row is (to_section = s) - (from_section = s),
-- which drives the whole daily summary with no special-casing for moves.
--
-- VERIFY rows (action = 'verify'): ONE row per verification action (a manager
-- confirming a whole set of packets). item_id / product_name are NULL,
-- `section` holds the verified section, `item_count` how many packets, and
-- `quantity` the total kg verified.
-- ⚠ from_section / to_section MUST stay NULL on verify rows — they feed the
-- balance identity above, so a verified section would otherwise be silently
-- debited. Balance queries additionally filter action IN ('in','move','out').
--
-- No updated_at by design.
-- =============================================

CREATE TABLE IF NOT EXISTS `t_crm_overnight_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_overnight_item; NULL on verify rows (they cover a set, not one packet)',
    `action` ENUM('in','move','out','verify') NOT NULL,
    `from_section` ENUM('chiller','freezer') NULL COMMENT 'NULL for action=in and action=verify',
    `to_section` ENUM('chiller','freezer') NULL COMMENT 'NULL for action=out and action=verify',
    `section` ENUM('chiller','freezer') NULL COMMENT 'Verify rows only: which section was verified',
    `item_count` INT NULL COMMENT 'Verify rows only: how many packets were verified',
    `product_id` BIGINT UNSIGNED NULL COMMENT 'Denormalized from item; NULL on verify rows',
    `product_name` VARCHAR(255) NULL COMMENT 'Denormalized snapshot; NULL on verify rows',
    `quantity` DECIMAL(10,3) NOT NULL COMMENT 'Movement: the packet quantity. Verify: total kg verified',
    `unit` VARCHAR(10) NOT NULL DEFAULT 'kg',
    `source` ENUM('scan','manual') NULL COMMENT 'HOW the action was performed — in: scanned or typed; out: barcode-scanned or picked from the list. NULL for move/verify',
    `created_by` INT NULL COMMENT 'FK to t_sys_user',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_ovl_created` (`created_at`),
    INDEX `idx_ovl_item` (`item_id`),
    INDEX `idx_ovl_action` (`action`),

    CONSTRAINT `fk_ovl_item` FOREIGN KEY (`item_id`)
        REFERENCES `t_crm_overnight_item` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ovl_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `t_sys_user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only movement + verification ledger for overnight storage. No updated_at by design.';

SELECT '✅ Step 2: Created t_crm_overnight_log' as Status;


-- =============================================
-- STEP 3: Mobile permissions (gate WEB page + MOBILE screen + all endpoints)
--   access_overnight_storage -> can see and use the tracker (scan, move, take out)
--   verify_overnight_storage -> can additionally VERIFY (managers only)
-- Granted to Admin + Taimur by default; owner grants store roles via the
-- web Roles screen. No code registration needed for mobile permissions.
-- =============================================

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('access_overnight_storage', 'Overnight Storage', 'store_mode',
        'Can view and manage the overnight chiller/freezer tracker (web page + mobile screen).', 75)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('verify_overnight_storage', 'Verify Overnight Storage', 'store_mode',
        'Can confirm/verify the overnight chiller/freezer quantities. Recorded with the verifier''s name.', 76)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

-- ACCESS: every role that can already use STORE MODE gets the tracker, plus admin/Taimur
-- by name. Store staff are the people physically loading the chiller/freezer, so tying
-- the grant to `access_store_mode` keeps the two in step instead of naming roles.
-- (This is a snapshot taken when the file runs — a store role created LATER must be
-- granted from the web Roles screen, or just re-run this file.)
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE p.permission_code = 'access_overnight_storage'
AND (
    LOWER(r.urole_name) IN ('admin', 'taimur')
    OR EXISTS (
        SELECT 1
        FROM t_sys_role_mobile_permission rp2
        JOIN t_sys_mobile_permission p2 ON p2.id = rp2.mobile_permission_id
        WHERE rp2.role_id = r.id AND p2.permission_code = 'access_store_mode'
    )
)
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFY: same audience as access (owner's call — the store supervisors ARE the people
-- who physically check the chiller/freezer). It stays a SEPARATE permission so it can be
-- taken away from anyone who should only load items, without touching their access:
-- untick "Verify Overnight Storage" for that role in the web Roles screen.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE p.permission_code = 'verify_overnight_storage'
AND (
    LOWER(r.urole_name) IN ('admin', 'taimur')
    OR EXISTS (
        SELECT 1
        FROM t_sys_role_mobile_permission rp2
        JOIN t_sys_mobile_permission p2 ON p2.id = rp2.mobile_permission_id
        WHERE rp2.role_id = r.id AND p2.permission_code = 'access_store_mode'
    )
)
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

SELECT '✅ Step 3: Created overnight permissions + granted access to all store-mode roles' as Status;


-- =============================================
-- STEP 4: Upgrade a DB that ran an EARLIER copy of this file
-- (before verification existed). No-ops on a fresh install.
-- =============================================

-- 4a. t_crm_overnight_item.verified_at
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_item' AND COLUMN_NAME = 'verified_at') = 0,
    "ALTER TABLE t_crm_overnight_item ADD COLUMN verified_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Last physical verification (manager); NULL = never verified' AFTER source, ADD INDEX idx_ovi_verified (verified_at)",
    "SELECT 'item.verified_at already present' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4b. t_crm_overnight_item.verified_by (+ FK)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_item' AND COLUMN_NAME = 'verified_by') = 0,
    "ALTER TABLE t_crm_overnight_item ADD COLUMN verified_by INT NULL COMMENT 'FK to t_sys_user — who last verified this packet' AFTER verified_at, ADD CONSTRAINT fk_ovi_verified_by FOREIGN KEY (verified_by) REFERENCES t_sys_user (id) ON DELETE SET NULL ON UPDATE CASCADE",
    "SELECT 'item.verified_by already present' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4c. log.action ENUM must include 'verify'
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log'
       AND COLUMN_NAME = 'action' AND COLUMN_TYPE LIKE '%verify%') = 0,
    "ALTER TABLE t_crm_overnight_log MODIFY COLUMN action ENUM('in','move','out','verify') NOT NULL",
    "SELECT 'log.action already has verify' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4d. log.section (verify rows)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME = 'section') = 0,
    "ALTER TABLE t_crm_overnight_log ADD COLUMN section ENUM('chiller','freezer') NULL COMMENT 'Verify rows only: which section was verified' AFTER to_section",
    "SELECT 'log.section already present' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4e. log.item_count (verify rows)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME = 'item_count') = 0,
    "ALTER TABLE t_crm_overnight_log ADD COLUMN item_count INT NULL COMMENT 'Verify rows only: how many packets were verified' AFTER section",
    "SELECT 'log.item_count already present' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4f. log.item_id + log.product_name must allow NULL (verify rows have neither)
SET @sql = (SELECT IF(
    (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME = 'item_id') = 'NO',
    "ALTER TABLE t_crm_overnight_log MODIFY COLUMN item_id BIGINT UNSIGNED NULL COMMENT 'FK to t_crm_overnight_item; NULL on verify rows (they cover a set, not one packet)'",
    "SELECT 'log.item_id already nullable' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql = (SELECT IF(
    (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME = 'product_name') = 'NO',
    "ALTER TABLE t_crm_overnight_log MODIFY COLUMN product_name VARCHAR(255) NULL COMMENT 'Denormalized snapshot; NULL on verify rows'",
    "SELECT 'log.product_name already nullable' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4g. log.source — records whether an in/out was done by barcode scan or by hand
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME = 'source') = 0,
    "ALTER TABLE t_crm_overnight_log ADD COLUMN source ENUM('scan','manual') NULL COMMENT 'HOW the action was performed — in: scanned or typed; out: barcode-scanned or picked from the list. NULL for move/verify' AFTER unit",
    "SELECT 'log.source already present' AS Skipped"));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SELECT '✅ Step 4: Verification + source columns present' as Status;


-- =============================================
-- VERIFICATION
-- =============================================

SELECT '✅ Migration add_overnight_storage_jul2026.sql completed successfully' as Status;

-- Show the new tables
SELECT TABLE_NAME, TABLE_COMMENT
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME LIKE 't_crm_overnight%'
ORDER BY TABLE_NAME;

-- Confirm the verification schema landed
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND ((TABLE_NAME = 't_crm_overnight_item' AND COLUMN_NAME IN ('verified_at','verified_by'))
  OR (TABLE_NAME = 't_crm_overnight_log' AND COLUMN_NAME IN ('action','section','item_count','item_id','product_name','source')))
ORDER BY TABLE_NAME, COLUMN_NAME;

-- Show who holds the permissions (expect at least Admin + Taimur on both)
SELECT r.urole_name, p.permission_code
FROM t_sys_role_mobile_permission rpm
JOIN t_sys_role r ON r.id = rpm.role_id
JOIN t_sys_mobile_permission p ON p.id = rpm.mobile_permission_id
WHERE p.permission_code IN ('access_overnight_storage', 'verify_overnight_storage')
ORDER BY p.permission_code, r.urole_name;
