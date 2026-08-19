-- =====================================================
-- Migration: delivery-scan packet-count enforcement + manager-approved "scan help" bypass
-- Date: 2026-08-17
--
-- WHY: order NF-19218 was delivered 1-of-2 with "Require scan before delivery" ON. The rider's
--      scan modal let a stale "NF-19218|1/1" label LOWER the target from 2 to 1, auto-completed
--      itself, and the backend only ever logged `match:false` before delivering. The count is now
--      enforced server-side, and a rider who genuinely cannot scan every packet asks a manager
--      instead of self-serving a bypass.
--
-- Two things are added:
--   1. delivery_scanned_packets — the rider's scan now records WHICH packet indices he scanned,
--      mirroring dispatch_scanned_packets. Previously only the TIME was kept (delivery_scanned_at),
--      so a short rider scan left no reconstructable evidence while the store side did.
--   2. scan_bypass_* — a per-order request/approve cycle. The old global self-serve toggle
--      (t_sys_config.allow_delivery_scan_bypass) is left untouched and keeps working.
--
-- Run ONCE on LOCAL and PROD (plain ADD COLUMN is not idempotent). Single statement = all or
-- nothing, so a partial apply cannot happen.
-- =====================================================

ALTER TABLE t_crm_prod_order
  ADD COLUMN delivery_scanned_packets VARCHAR(255) NULL,      -- JSON array of packet indices the rider scanned, e.g. [1,2]
  ADD COLUMN scan_bypass_status       VARCHAR(20)  NULL,       -- NULL | pending | approved | denied
  ADD COLUMN scan_bypass_reason       VARCHAR(255) NULL,       -- rider's typed reason
  ADD COLUMN scan_bypass_packets      INT          NULL,       -- how many packets he actually has in hand
  ADD COLUMN scan_bypass_expected     INT          NULL,       -- expected_packets AT REQUEST TIME (staleness guard)
  ADD COLUMN scan_bypass_requested_at DATETIME     NULL,
  ADD COLUMN scan_bypass_requested_by INT          NULL,       -- the rider; only HE may consume the approval
  ADD COLUMN scan_bypass_decided_at   DATETIME     NULL,
  ADD COLUMN scan_bypass_decided_by   INT          NULL,       -- the manager who approved/denied
  ADD COLUMN scan_bypass_used_at      DATETIME     NULL;       -- set when consumed = SINGLE USE

-- Managers awaiting a decision are found by status; keep that lookup cheap on a large table.
CREATE INDEX idx_prod_order_scan_bypass_status ON t_crm_prod_order (scan_bypass_status);

-- Mobile permission that shows the Approve/Deny banner in store mode.
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('approve_scan_bypass', 'Approve Delivery Scan Bypass', 'store_mode',
        'Can approve or deny a rider''s request to deliver with fewer packets than the store set.', 73)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name), description = VALUES(description), display_order = VALUES(display_order);

-- ⚠ There is NO role literally named 'admin' in t_sys_role (verified on the replica Aug-17) — an
-- IN ('admin') grant would insert ZERO rows and nobody could approve. The admin roles here are
-- 'Management' (Shabib) and 'Taimur'; 'supervisor 2' is Farooq (owner's ruling: admins + Farooq).
-- Grant more people later from the Roles screen — this permission is the single gate.
INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE LOWER(r.urole_name) IN ('management', 'taimur', 'supervisor 2')
AND p.permission_code = 'approve_scan_bypass'
ON DUPLICATE KEY UPDATE mobile_permission_id = VALUES(mobile_permission_id);

-- VERIFICATION:
-- SELECT id, order_number, expected_packets, actual_packets, delivery_scanned_packets,
--        scan_bypass_status, scan_bypass_packets, scan_bypass_expected, scan_bypass_used_at
-- FROM t_crm_prod_order WHERE scan_bypass_status IS NOT NULL LIMIT 10;
--
-- ROLLBACK (if ever needed — the app tolerates the columns being absent):
-- ALTER TABLE t_crm_prod_order
--   DROP COLUMN delivery_scanned_packets, DROP COLUMN scan_bypass_status,
--   DROP COLUMN scan_bypass_reason, DROP COLUMN scan_bypass_packets,
--   DROP COLUMN scan_bypass_expected, DROP COLUMN scan_bypass_requested_at,
--   DROP COLUMN scan_bypass_requested_by, DROP COLUMN scan_bypass_decided_at,
--   DROP COLUMN scan_bypass_decided_by, DROP COLUMN scan_bypass_used_at;
