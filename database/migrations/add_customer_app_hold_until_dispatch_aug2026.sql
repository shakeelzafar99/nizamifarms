-- ============================================================================
-- Order Status Hub — "hold from the customer until dispatched" (Aug-2026)
-- ============================================================================
--
-- WHY
-- ---
-- Ops sets `out_for_delivery` EARLY (while the order is still being loaded at
-- the store). The customer app was told "Out for Delivery" at that moment, so
-- the customer opened a live map showing a rider parked at the shop with no
-- ETA. The real out-for-delivery moment is when Dispatch is pressed and the
-- route ETA is calculated.
--
-- This column lets the Status Hub mark a status as "the customer should not
-- see this step until the order is actually dispatched". While it is held the
-- customer app is told `processing` instead (config
-- customer_app.hold_until_dispatch_status); the instant the ETA is calculated
-- NF pushes the real status with the delivery window attached.
--
-- "Dispatched" = t_crm_prod_order.eta_calculated_at IS NOT NULL — the same
-- test the "Dispatch Next" wave already uses. Every path that un-dispatches an
-- order (backward status move, rider reassignment, cancel dispatch) already
-- clears that column, so the flag stays honest without any new bookkeeping.
--
-- SAFETY
-- ------
-- Additive and inert: DEFAULT 0 means every status keeps today's behaviour
-- until it is deliberately flagged. The application code is guarded with
-- Schema::hasColumn, so it is safe to run this BEFORE or AFTER the file
-- upload. Only `out_for_delivery` is flagged below, matching the live issue.
--
-- The rule applies only to orders in customer-app scope (SH-) — NF- and QUR
-- orders are untouched, because Qurbani dispatches through its own
-- qurbani_eta_calculated_at columns and would otherwise look "never dispatched".
--
-- RUN ON: local dev + production (hand-run, per DB-schema-changes-manual).
-- AFTER RUNNING: php artisan config:clear (or /xclean) so the rule cache and
-- config pick it up.
-- ============================================================================

ALTER TABLE t_crm_order_status_master
    ADD COLUMN customer_app_hold_until_dispatch TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Customer app sees a held-back status until the order is dispatched (ETA calculated)';

-- The one status this was built for.
UPDATE t_crm_order_status_master
   SET customer_app_hold_until_dispatch = 1
 WHERE status_code = 'out_for_delivery';

-- Verify (expect out_for_delivery = 1, everything else 0):
-- SELECT status_code, send_to_customer_app, customer_app_alias,
--        customer_app_hold_until_dispatch
--   FROM t_crm_order_status_master ORDER BY sequence_order;
