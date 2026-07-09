-- =====================================================
-- Migration: dispatch package-scan (store hand-over to rider)
-- Date: 2026-07-07
-- Purpose: before an out-for-delivery order leaves the store, the manager scans EVERY
--          package QR. Only when all packets are scanned does the order show a persistent
--          "Ready to leave" tick. Per-packet + persistent (survives refresh, records who/when).
--          Independent of the rider's delivery scan — this is a store-side checkpoint.
--
-- Run ONCE on LOCAL and PROD (plain ADD COLUMN is not idempotent).
-- =====================================================

ALTER TABLE t_crm_prod_order
  ADD COLUMN dispatch_scanned_packets VARCHAR(255) NULL,  -- JSON array of scanned packet indices, e.g. [1,2,3]
  ADD COLUMN dispatch_scanned_at DATETIME NULL,           -- set when ALL packets scanned = ready to leave
  ADD COLUMN dispatch_scanned_by INT NULL;                -- user id who completed the dispatch scan

-- Enh-1: store-side "N orders not scanned for hand-over" awareness banner. Off by default; the
-- owner turns it on from Ops → Delivery scan settings. The dispatch popup at the Dispatch button
-- fires regardless of this flag; this only controls the always-visible banner in the pinned view.
ALTER TABLE t_sys_config
  ADD COLUMN dispatch_scan_banner_enabled TINYINT(1) NOT NULL DEFAULT 0;

-- Mobile permission that shows the "Scan to hand over" button (store/pinned-rider view).
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('dispatch_scan', 'Dispatch Scan (hand over)', 'store_mode',
        'Can scan each package at dispatch to mark an order ready to leave the store.', 72)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE LOWER(r.urole_name) IN ('admin')
AND p.permission_code = 'dispatch_scan'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFICATION:
-- SELECT id, order_number, expected_packets, dispatch_scanned_packets, dispatch_scanned_at
-- FROM t_crm_prod_order WHERE dispatch_scanned_at IS NOT NULL LIMIT 5;
