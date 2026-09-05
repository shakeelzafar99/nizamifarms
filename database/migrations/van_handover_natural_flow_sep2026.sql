-- =====================================================================================
--  VAN HANDOVER — NATURAL FLOW  (Sep-05-2026)
--  Plan: VAN-HANDOVER-NATURAL-FLOW-PLAN-SEP2026.md  (phases B1 + C2)
--
--  WHY (Sep-3, Thu): a rider scanned two van boxes through the order page's "Mark Delivered"
--  scanner at the meet-up instead of the Collect scanner. The web side of the fix needs NO
--  schema. These two nullable columns back the two doors that make the flow self-correcting:
--
--    1. t_ops_van_handover.arrival_notified_at
--       The driver presses "I'm here" and every rider who still owes a scan gets ONE push
--       ("Van X par pahunch gayi - apne orders scan karein"). The column is the once-only
--       latch: without it the code sends nothing at all (rather than risk repeating).
--
--    2. t_crm_prod_order.handover_help_note
--       "Label scan nahi ho raha" - the rider asks the store from his order page. The note
--       shows amber on the store Van tab and the web van card until a manager records the
--       no-scan handover, the rider scans after all, or the box is taken off the van.
--
--  ⚠ NO BEHAVIOUR CHANGES ON RUNNING THIS FILE ALONE. Both columns are read through
--    Schema::hasColumn guards; the code simply starts using them once they exist.
--  ⚠ Plain ALTERs, no PREPARE (shared StackCP hosting). A second run errors with
--    1060 Duplicate column name - harmless, means already applied. Nothing is dropped.
--  ⚠ No apostrophes and no semicolons inside COMMENT strings.
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT - expect found = 0 for both on prod.
-- #####################################################################################
SELECT 'pre-flight: arrival_notified_at column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_van_handover'
   AND COLUMN_NAME = 'arrival_notified_at';

SELECT 'pre-flight: handover_help_note column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_crm_prod_order'
   AND COLUMN_NAME = 'handover_help_note';


-- #####################################################################################
--  PART 1 - once-only latch for the arrival push
-- #####################################################################################
ALTER TABLE `t_ops_van_handover`
  ADD COLUMN `arrival_notified_at` DATETIME NULL DEFAULT NULL
  COMMENT 'When the riders owed a scan were pushed that the van reached this stop - set once';


-- #####################################################################################
--  PART 2 - the rider asked the store for a no-scan handover
-- #####################################################################################
ALTER TABLE `t_crm_prod_order`
  ADD COLUMN `handover_help_note` VARCHAR(190) NULL DEFAULT NULL
  COMMENT 'Rider could not scan the label at the van - his reason, cleared by scan, override or unload';


-- #####################################################################################
--  VERIFY - expect found = 1 for both.
-- #####################################################################################
SELECT 'verify: arrival_notified_at column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_ops_van_handover'
   AND COLUMN_NAME = 'arrival_notified_at';

SELECT 'verify: handover_help_note column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME = 't_crm_prod_order'
   AND COLUMN_NAME = 'handover_help_note';
