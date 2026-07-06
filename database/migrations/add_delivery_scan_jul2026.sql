-- =====================================================
-- Migration: Phase 3 delivery package-scan
-- Date: 2026-07-05
-- Purpose: two web-controlled flags (on the single company-config row) that make the
--          rider's package scan optional (default) or mandatory, with an optional
--          bypass; plus an audit column on the order for recorded bypasses.
--
-- DEFAULT OFF: require_delivery_scan = 0 means the delivery flow behaves exactly as
-- today. Nothing changes for riders until the owner turns it on from the web.
--
-- Run ONCE on LOCAL and PROD (plain ADD COLUMN is not idempotent).
-- =====================================================

ALTER TABLE t_sys_config
  ADD COLUMN require_delivery_scan TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN allow_delivery_scan_bypass TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE t_crm_prod_order
  ADD COLUMN delivery_scan_bypass_reason VARCHAR(255) NULL;

-- VERIFICATION:
-- SELECT require_delivery_scan, allow_delivery_scan_bypass FROM t_sys_config WHERE id = 1;
