-- =====================================================
-- Qurbani Route Optimization & Dispatch
-- Date: May 17, 2026
-- Description:
--   Adds the columns + lock discriminator needed for per-rider route
--   planning, dispatch, and ETA tracking inside Qurbani Mode. Mirrors
--   the regular-orders flow (delivery_priority + estimated_delivery_at)
--   but lives on the line item so we can sequence at the BUNDLE level
--   (one stop per smart-box bundle, not per individual line item).
--
--   New columns on t_crm_prod_order_line_item:
--     qurbani_delivery_priority      Sequence within the rider's route
--                                    (1 = first stop). All line items in
--                                    the same smart-box bundle SHARE the
--                                    same priority — the bundle is the
--                                    delivery stop, not the line item.
--     qurbani_estimated_delivery_at  Clock-time ETA (e.g., 2026-05-17 14:42)
--                                    Computed by the dispatch endpoint and
--                                    fixed until the next dispatch press.
--     qurbani_eta_calculated_at      Audit timestamp — when the ETA above
--                                    was last computed (so the UI can show
--                                    "ETA last refreshed N min ago").
--     qurbani_dispatched_at          When manager (or rider) pressed
--                                    Dispatch. Used as the canonical
--                                    "route was committed" marker.
--     qurbani_dispatched_by          User id who dispatched.
--     qurbani_started_delivery_at    Rider-only — stamps when the rider
--                                    presses Start Delivery on their app.
--
--   t_crm_route_lock change:
--     Adds `mode` VARCHAR(20) DEFAULT 'regular' so a rider can have both a
--     regular-orders lock and a qurbani lock without colliding. The old
--     unique key on rider_id is replaced with a composite (rider_id, mode).
--     Existing rows are backfilled to mode='regular' so the regular-orders
--     dispatch flow keeps working unchanged.
--
-- INSTRUCTIONS: Run each ALTER one at a time. Safe to re-run because
-- MySQL throws on duplicate column names — copy-paste only the parts that
-- haven't run yet.
-- Apply in production with: mysql ... < add_qurbani_route_dispatch_may2026.sql
-- =====================================================


-- ============================================================================
-- SECTION 1: Per-line-item route + dispatch columns
-- ============================================================================

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_delivery_priority` INT NULL
    COMMENT 'Sequence within the assigned riders route (1=first stop). Shared by all line items in the same smart-box bundle.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_estimated_delivery_at` TIMESTAMP NULL
    COMMENT 'Clock-time ETA computed at last dispatch press. Fixed until next dispatch.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_eta_calculated_at` TIMESTAMP NULL
    COMMENT 'When qurbani_estimated_delivery_at was last computed.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_dispatched_at` TIMESTAMP NULL
    COMMENT 'When the route was last dispatched (ETAs committed).';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_dispatched_by` INT NULL
    COMMENT 'User id who dispatched (manager or rider). FK to t_sys_user.id.';

ALTER TABLE `t_crm_prod_order_line_item`
    ADD COLUMN `qurbani_started_delivery_at` TIMESTAMP NULL
    COMMENT 'When the rider pressed Start Delivery on their app.';

CREATE INDEX `idx_li_qurbani_priority` ON `t_crm_prod_order_line_item` (`qurbani_assigned_rider_user_id`, `qurbani_delivery_priority`);


-- ============================================================================
-- SECTION 2: Route lock mode discriminator
-- ============================================================================

ALTER TABLE `t_crm_route_lock`
    ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'regular'
    COMMENT 'Lock scope. regular = orders mode, qurbani = qurbani mode. Allows the same rider to have one lock per scope.';

UPDATE `t_crm_route_lock` SET `mode` = 'regular' WHERE `mode` IS NULL OR `mode` = '';

-- Drop the old unique key on rider_id (single-mode) and replace with
-- composite so each rider can have at most one lock per mode.
ALTER TABLE `t_crm_route_lock` DROP INDEX `uk_rider`;
ALTER TABLE `t_crm_route_lock` ADD UNIQUE KEY `uk_rider_mode` (`rider_id`, `mode`);


-- ============================================================================
-- SECTION 3: Verify
-- ============================================================================

SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 't_crm_prod_order_line_item'
  AND COLUMN_NAME IN (
    'qurbani_delivery_priority',
    'qurbani_estimated_delivery_at',
    'qurbani_eta_calculated_at',
    'qurbani_dispatched_at',
    'qurbani_dispatched_by',
    'qurbani_started_delivery_at'
  )
ORDER BY ORDINAL_POSITION;

SHOW INDEX FROM t_crm_route_lock;
