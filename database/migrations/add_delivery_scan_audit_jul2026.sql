-- =====================================================
-- Migration: delivery-scan audit stamp (proof the rider scanned at delivery, and when)
-- Date: 2026-07-09
-- Purpose: the rider's package scan at delivery is otherwise only recorded when the order is
--          finally marked delivered. This adds a best-effort, scan-time stamp so management can
--          SEE that (and when) the rider scanned — independent of, and earlier than, delivery.
--          The phone pings /rider/orders/{id}/delivery-scan-mark right after a successful scan.
--          The on-device cache (mobile) is what actually prevents re-scans; this is for visibility.
--
-- Run ONCE on LOCAL and PROD (plain ADD COLUMN is not idempotent).
-- =====================================================

ALTER TABLE t_crm_prod_order
  ADD COLUMN delivery_scanned_at DATETIME NULL,   -- first time the rider completed the delivery scan
  ADD COLUMN delivery_scanned_by INT NULL;        -- user id who scanned

-- VERIFICATION:
-- SELECT id, order_number, delivery_scanned_at, delivery_scanned_by
-- FROM t_crm_prod_order WHERE delivery_scanned_at IS NOT NULL LIMIT 5;
