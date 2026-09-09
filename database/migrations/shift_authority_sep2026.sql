-- =====================================================================================
--  SHIFT AUTHORITY - who may change whose shift, and what waits for Taimur   (Sep-06-2026)
--  Plan: SHIFT-AUTHORITY-PLAN-SEP2026.md   (owner rulings locked 6-Sep, section 7)
--
--  THE RULE IN ONE LINE: you may change the shift of anyone ranked BELOW you on the
--  ladder, and not your own. Approver of a change = anyone ranked above BOTH the person
--  being changed and the person making the change.
--
--  ⭐⭐ WHY THIS IS SAFE TO RUN BEFORE THE PHP GOES UP. Everything below is either a NEW
--    table nothing reads yet, a NULLable column with a default that means todays
--    behaviour, or a guarded INSERT. No existing row changes meaning. Until the new PHP
--    is uploaded nothing writes these and nothing reads them.
--  ⭐ RUN IT FIRST ANYWAY. The PHP is schema-guarded and falls back to "no rules, but
--    manage_shifts is required" while the tables are missing - correct, but not the
--    behaviour the owner asked for.
--
--  ⚠⚠ DEFAULTS ARE DELIBERATELY WIDE OPEN (owner ruling 6-Sep): until Taimur sets a
--     rule, EVERY person is "all shifts, no approval needed". Nothing gets blocked on
--     day one except the two things that were always meant to be blocked - setting your
--     own shift, and reaching UP the ladder. PART 6 seeds only the ladder itself.
--
--  ⚠ DATETIME, never TIMESTAMP (prod renders TIMESTAMP +2h locally).
--  ⚠ No apostrophes and no semicolons inside any COMMENT string - the statement splitter
--    is not string-aware and either one cuts a statement in half.
--  ⚠ Plain ALTER / CREATE (no PREPARE) - shared StackCP hosting. A second run of PART 3
--    errors 1060 Duplicate column name, which is harmless and means it is already applied.
--    PARTS 1, 2 use CREATE TABLE IF NOT EXISTS and PARTS 4, 5, 6 are guarded - all re-runnable.
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT - read only. On prod every "found" should come back 0.
-- #####################################################################################
SELECT 'pre-flight: authority table already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_shift_authority';
-- EXPECT 0. If 1, PART 1 is already applied - skip it.

SELECT 'pre-flight: request table already there?' AS check_name, COUNT(*) AS found
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_shift_change_request';
-- EXPECT 0.

SELECT 'pre-flight: template approval columns?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 't_ops_shift_template'
   AND COLUMN_NAME IN ('approval_status','proposed_by','approved_by','approved_at',
                       'declined_by','declined_at','decline_reason');
-- EXPECT 0. If 7, PART 3 is already applied - skip it.

SELECT 'pre-flight: manage_shift_rules seeded?' AS check_name, COUNT(*) AS found
  FROM t_sys_role_permissions WHERE permission_key = 'manage_shift_rules';
-- EXPECT 0.

SELECT 'pre-flight: the three planners exist with these ids?' AS check_name,
       u.id, u.fullname, u.is_active
  FROM t_sys_user u WHERE u.id IN (68, 74, 79) ORDER BY u.id;
-- EXPECT 68 Taimur, 74 Farooq, 79 Shabib - all active.
-- ⚠⚠ IF THE IDS DIFFER ON PROD, FIX THE THREE INSERTS IN PART 6 BEFORE RUNNING IT.
--    Everything else in this file is id-independent.


-- #####################################################################################
--  PART 1 - the ladder + the per-person rules. ONE row per person who has a rule.
--  Anybody with NO row here is: rank 0 (changes nobody), all shifts allowed, no approval.
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_shift_authority` (
  `user_id` INT(11) NOT NULL,
  `rank` TINYINT(4) NOT NULL DEFAULT 0 COMMENT 'Higher changes lower. 0 = changes nobody.',
  `needs_approval` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a change to THIS persons shift waits for someone above',
  `allowed_template_ids` TEXT DEFAULT NULL COMMENT 'JSON array of t_ops_shift_template ids. NULL = every shift.',
  `note` VARCHAR(255) DEFAULT NULL,
  `updated_by` INT(11) DEFAULT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_rank` (`rank`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shift authority - who may change whose shift, and which shifts they may be put on';


-- #####################################################################################
--  PART 2 - a shift change that is WAITING for someone above to say yes.
--  ⭐ It stores the whole assign payload verbatim. On approval the approvers own
--    session replays it through the SAME ShiftController engine, so history, re-stamp,
--    the rider push and the WhatsApp all happen exactly as they do today - only later.
-- #####################################################################################
CREATE TABLE IF NOT EXISTS `t_ops_shift_change_request` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT 'whose shift would change',
  `shift_template_id` INT(11) NOT NULL,
  `mode` VARCHAR(20) NOT NULL DEFAULT 'until_changed' COMMENT 'until_changed | one_day | date_range',
  `effective_from` DATE NOT NULL,
  `effective_to` DATE DEFAULT NULL,
  `location_id` INT(11) DEFAULT NULL,
  `set_default_location` TINYINT(1) NOT NULL DEFAULT 0,
  `requested_by` INT(11) NOT NULL,
  `requested_at` DATETIME NOT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'web' COMMENT 'web | mobile',
  `status` VARCHAR(20) NOT NULL DEFAULT 'proposed'
      COMMENT 'proposed | approved | declined | lapsed | withdrawn',
  `decided_by` INT(11) DEFAULT NULL,
  `decided_at` DATETIME DEFAULT NULL,
  `decline_reason` VARCHAR(255) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_from` (`effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Shift changes waiting for approval from someone higher on the ladder';


-- #####################################################################################
--  PART 3 - a shift TYPE can now be PROPOSED instead of created.
--  ⭐⭐ DEFAULT 'approved' IS THE WHOLE SAFETY STORY: every one of the 9 existing
--     templates becomes approved the moment the column appears, so every picker,
--     every resolution and every report keeps working with no data fix.
--  ⚠ A second run errors 1060 - harmless, means it is already applied.
-- #####################################################################################
ALTER TABLE `t_ops_shift_template`
  ADD COLUMN `approval_status` VARCHAR(20) NOT NULL DEFAULT 'approved'
      COMMENT 'approved | proposed | declined - only approved may be assigned',
  ADD COLUMN `proposed_by` INT(11) DEFAULT NULL,
  ADD COLUMN `approved_by` INT(11) DEFAULT NULL,
  ADD COLUMN `approved_at` DATETIME DEFAULT NULL,
  ADD COLUMN `declined_by` INT(11) DEFAULT NULL,
  ADD COLUMN `declined_at` DATETIME DEFAULT NULL,
  ADD COLUMN `decline_reason` VARCHAR(255) DEFAULT NULL;

ALTER TABLE `t_ops_shift_template` ADD INDEX `idx_approval_status` (`approval_status`);


-- #####################################################################################
--  PART 4 - the two switches, in the existing key/value config table.
--   SHIFT_SELF_ASSIGN        N = nobody on the ladder sets their own shift (the default
--                                the owner asked for). The TOP of the ladder is exempt
--                                in code - there is nobody above him to ask.
--   SHIFT_TYPE_CREATE_POLICY approval = anyone with manage_shifts may PROPOSE a shift
--                                type and the top of the ladder approves it.
--                                Other values: top_only | anyone.
-- #####################################################################################
INSERT INTO t_fin_config (config_key, config_value, description, created_at)
SELECT 'SHIFT_SELF_ASSIGN', 'N',
       'Y = managers on the shift ladder may set their own shift. N = they may not (the top of the ladder always may).',
       NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'SHIFT_SELF_ASSIGN');

INSERT INTO t_fin_config (config_key, config_value, description, created_at)
SELECT 'SHIFT_TYPE_CREATE_POLICY', 'approval',
       'Who may create a shift TYPE - top_only, approval (propose then the top of the ladder approves), or anyone.',
       NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_fin_config WHERE config_key = 'SHIFT_TYPE_CREATE_POLICY');


-- #####################################################################################
--  PART 5 - the permission that opens the Shift rules page.
--  ⚠⚠ ROLE 14 (Taimur) ONLY - owner ruling 6-Sep. Deliberately NOT role 10 Management
--     (Shabib) and NOT role 20 (supervisor 2 / Farooq). Shabib, who does the uploads,
--     will NOT see this page. Giving it to him later is one is_allowed = 1 on the
--     Roles - Permissions screen, no code change.
--  Every OTHER role is seeded is_allowed = 0 so the key is visible on that screen and
--  can simply be ticked (same shape as the workshop round).
-- #####################################################################################
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_at)
SELECT r.id, 'manage_shift_rules', 'Shift rules - set who may change whose shift',
       CASE WHEN r.id = 14 THEN 1 ELSE 0 END, NOW()
  FROM t_sys_role r
 WHERE NOT EXISTS (
        SELECT 1 FROM t_sys_role_permissions p
         WHERE p.role_id = r.id AND p.permission_key = 'manage_shift_rules');

-- The mobile half of the same key. Phase 1 does not use it (the rules page is web only),
-- but seeding it now means the phone never has to be told about a new permission later.
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, description, is_active, created_at)
SELECT 'manage_shift_rules', 'Shift rules',
       'Set who may change whose shift, and approve shift changes', 1, NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_sys_mobile_permission WHERE permission_code = 'manage_shift_rules');

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id, created_at)
SELECT 14, mp.id, NOW()
  FROM t_sys_mobile_permission mp
 WHERE mp.permission_code = 'manage_shift_rules'
   AND NOT EXISTS (
        SELECT 1 FROM t_sys_role_mobile_permission rmp
         WHERE rmp.role_id = 14 AND rmp.mobile_permission_id = mp.id);


-- #####################################################################################
--  PART 6 - seed the ladder itself.
--  ⚠⚠ THIS IS THE ONLY PART THAT NAMES REAL PEOPLE. Check the pre-flight ids first.
--  Taimur 3 - changes everyone, including himself (top of the ladder).
--  Shabib 2 - changes everyone except Taimur and himself. Changes to HIS shift wait
--             for Taimur.
--  Farooq 1 - changes everyone except Taimur, Shabib and himself. Changes to HIS shift
--             wait for Shabib or Taimur.
--  allowed_template_ids stays NULL for all three = every shift (owner ruling: nothing
--  is restricted until Taimur restricts it).
-- #####################################################################################
INSERT INTO t_ops_shift_authority (user_id, `rank`, needs_approval, allowed_template_ids, note, updated_at)
SELECT 68, 3, 0, NULL, 'Top of the ladder', NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_ops_shift_authority WHERE user_id = 68);

INSERT INTO t_ops_shift_authority (user_id, `rank`, needs_approval, allowed_template_ids, note, updated_at)
SELECT 79, 2, 1, NULL, 'Changes to his shift wait for Taimur', NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_ops_shift_authority WHERE user_id = 79);

INSERT INTO t_ops_shift_authority (user_id, `rank`, needs_approval, allowed_template_ids, note, updated_at)
SELECT 74, 1, 1, NULL, 'Changes to his shift wait for Shabib or Taimur', NOW()
 WHERE NOT EXISTS (SELECT 1 FROM t_ops_shift_authority WHERE user_id = 74);


-- #####################################################################################
--  POST-FLIGHT - read only. Confirm what landed.
-- #####################################################################################
SELECT 'ladder' AS what, a.user_id, u.fullname, a.`rank`, a.needs_approval, a.allowed_template_ids
  FROM t_ops_shift_authority a JOIN t_sys_user u ON u.id = a.user_id
 ORDER BY a.`rank` DESC;
-- EXPECT 3 rows - Taimur 3, Shabib 2 (needs_approval 1), Farooq 1 (needs_approval 1).

SELECT 'switches' AS what, config_key, config_value FROM t_fin_config
 WHERE config_key IN ('SHIFT_SELF_ASSIGN','SHIFT_TYPE_CREATE_POLICY');
-- EXPECT N and approval.

SELECT 'who can open Shift rules' AS what, r.id, r.urole_name, p.is_allowed
  FROM t_sys_role_permissions p JOIN t_sys_role r ON r.id = p.role_id
 WHERE p.permission_key = 'manage_shift_rules' AND p.is_allowed = 1;
-- EXPECT exactly ONE row - role 14 Taimur.

SELECT 'templates all still assignable' AS what, COUNT(*) AS approved_count
  FROM t_ops_shift_template WHERE approval_status = 'approved';
-- EXPECT 9 (every existing template) - if this is not the full count, STOP.
