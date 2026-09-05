-- =====================================================================================
--  LINK A SERVICE RECORD TO ITS BILL  (Sep-3-2026)
--  Plan: SERVICE-MONEY-LINK-PLAN-SEP2026.md  §B
--
--  OWNER ASK (3-Sep): a manager recording a service should be able to add the money too,
--  optionally — "sometimes if they are just entering the meter they can ignore".
--
--  ⭐⭐ WHY A LINK COLUMN AND NOT JUST TWO ROWS. Both the service log and approved
--    maintenance CLAIMS feed one countdown engine (VehicleService::serviceEvidenceByType),
--    and beatsEvidence() keeps the best per type. So one job recorded twice shows TWICE in
--    Past services, and if the two meters differ by a kilometre the higher one silently wins
--    with nothing on screen saying they are the same job. Today that is rare because managers
--    do not file claims from this screen. An amount box that quietly filed a SEPARATE claim
--    would have manufactured that duplicate at scale.
--
--    So: one job = one row. The log records that the WORK happened; the claim records the
--    MONEY; this column ties them together, and the history and the evidence engine both
--    collapse the pair back into one.
--
--  ⭐ THE LOG STAYS THE TRUTH FOR "THE WORK HAPPENED". A claim only becomes evidence once it
--    is APPROVED — so a service-as-claim-only design would leave a bike reading "overdue" for
--    days after it was serviced, until somebody cleared the bill. The log resets the clock
--    immediately, which is correct. The two halves are deliberately allowed to be at
--    different stages.
--
--  ⚠ NO BEHAVIOUR CHANGE ON RUNNING THIS FILE. The column is NULL for every existing row and
--    every row written by the current code. It only starts carrying a value once the amount
--    box ships.
--
--  ⚠ SAFE TO RE-RUN, BUT READ THIS: the ALTER below is plain (no PREPARE — shared StackCP
--    hosting). A second run errors with 1060 Duplicate column name, which is harmless and
--    means it is already applied. The pre-flight tells you before you start.
--  ⚠ No apostrophes and no semicolons inside any COMMENT string (naive splitters are not
--    string-aware).
-- =====================================================================================


-- #####################################################################################
--  PRE-FLIGHT — expect found = 0 on prod.
-- #####################################################################################
SELECT 'pre-flight: request_id column?' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 't_fleet_service_log'
   AND COLUMN_NAME  = 'request_id';


-- #####################################################################################
--  THE CHANGE
-- #####################################################################################
ALTER TABLE `t_fleet_service_log`
  ADD COLUMN `request_id` INT NULL DEFAULT NULL
    COMMENT 'The maintenance claim that paid for this service, when the manager entered an amount. NULL means no bill was filed with it.',
  ADD INDEX `idx_fsl_request` (`request_id`);


-- #####################################################################################
--  VERIFY — expect found = 1, and linked = 0 on a fresh apply.
-- #####################################################################################
SELECT 'verify: request_id column' AS check_name, COUNT(*) AS found
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE()
   AND TABLE_NAME   = 't_fleet_service_log'
   AND COLUMN_NAME  = 'request_id';

SELECT 'verify: rows already linked' AS check_name, COUNT(*) AS linked
  FROM `t_fleet_service_log`
 WHERE `request_id` IS NOT NULL;
