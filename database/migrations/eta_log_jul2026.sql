-- ============================================================================
-- ETA / dispatch event log (2026-07-16)
-- Portable, plain DDL. Run on LOCAL and PROD (owner's manual workflow).
--
-- WHY THIS EXISTS
-- Every lateness surface compares delivered_at against t_crm_prod_order
-- .estimated_delivery_at — and RiderController::calculateDeliveryEtas OVERWRITES
-- that column on every dispatch. So a rider running late could simply press
-- Re-dispatch, receive fresh (later) times, and never be flagged late. The
-- original commitment existed nowhere queryable. This table is that missing
-- memory: one append-only row per order per dispatch.
--
-- THE RULE IT ENABLES ("the yardstick only resets when management resets it"):
--   * the FIRST dispatch of the day = the PROMISE, and lateness is measured
--     against it
--   * a STORE-initiated dispatch, or a rider dispatch that FOLLOWS a
--     store-initiated cancel-dispatch, sets a NEW promise (management
--     sanctioned the re-plan)
--   * a RIDER-initiated re-dispatch still updates the live customer-facing
--     ETAs, but does NOT move the promise.
-- This is why cancels are logged too, and why is_rider_self is on every row:
-- a rider cancelling his OWN dispatch must not launder the promise.
-- Derived in PHP at read time by App\Services\Riders\EtaPromiseService.
--
-- The writer is FAIL-SAFE: if this table does not exist yet, logging silently
-- no-ops and dispatch works exactly as before — so it is SAFE to upload the PHP
-- before running this script. Reports fall back to today's behaviour (compare
-- against the current ETA) for any order with no rows, which is also what makes
-- historical orders render sensibly forever.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `t_ops_eta_log` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`              BIGINT UNSIGNED NOT NULL,
  `rider_id`              INT             NOT NULL,
  `event`                 VARCHAR(20)     NOT NULL DEFAULT 'dispatch', -- 'dispatch' | 'cancel'
  `batch_ts`              DATETIME        NULL,                        -- = eta_calculated_at, groups one wave (NULL on cancel)
  `estimated_delivery_at` DATETIME        NULL,                        -- the time promised to this stop by THIS dispatch
  `position`              INT             NULL,                        -- 1-based stop position within the batch
  `calculated_by`         INT             NULL,                        -- who pressed it
  `is_rider_self`         TINYINT(1)      NOT NULL DEFAULT 0,          -- pressed by the rider himself (calculated_by == rider_id)
  `is_mid_run`            TINYINT(1)      NOT NULL DEFAULT 0,          -- system's mid-run signal at press time
  `delivered_before`      INT             NOT NULL DEFAULT 0,          -- stops he had ALREADY delivered today when he pressed
  `scope`                 VARCHAR(20)     NULL,                        -- 'all' | 'undispatched'
  `created_at`            DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_eta_log_order` (`order_id`, `created_at`),                  -- promise derivation (per order, chronological)
  KEY `idx_eta_log_rider_day` (`rider_id`, `created_at`)               -- daily-issues "mid-run route changes"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
