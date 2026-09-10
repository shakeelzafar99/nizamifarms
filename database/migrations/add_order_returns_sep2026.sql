-- =====================================================
-- Migration: RETURNED ORDERS (status "Returned", money reversal/refund/credit,
--            goods put-back with store scan-back)
-- Date: 2026-09-09
-- Plan:  RETURNED-ORDER-STATUS-PLAN-SEP2026.md
--
-- RUN THIS **BEFORE** UPLOADING THE PHP.
-- (The PHP is dormant-safe if you don't — OrderReturnService::tableReady() makes
--  reads answer "no returns" and writes refuse with a clear message — but the
--  feature simply does nothing until this has run.)
--
-- Everything here is additive and idempotent. No existing column is altered,
-- no existing row is deleted.
-- =====================================================


-- -----------------------------------------------------------------
-- 1. The return record — ONE row per returned order.
--    Holds what the manager decided about the money and the goods, so every
--    report reads the OUTCOME from here and never infers it from the status.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS t_crm_order_return (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id          BIGINT UNSIGNED NOT NULL,
    reason            VARCHAR(255) NULL,

    -- goods
    goods_action      VARCHAR(20)  NOT NULL DEFAULT 'restock',  -- restock | wasted
    meat_section      VARCHAR(20)  NULL,                        -- freezer | chiller | NULL (no meat / wasted)

    -- money
    money_state       VARCHAR(4)   NULL,       -- S1..S6, the state the server detected
    money_action      VARCHAR(20)  NOT NULL DEFAULT 'none',     -- reversed | refund | credit | none
    amount            DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- what the customer is owed / was unwound
    refund_ledger_id  BIGINT UNSIGNED NULL,    -- t_fin_ledger.id of the order_refund row
    credit_grant_id   BIGINT UNSIGNED NULL,    -- t_crm_customer_credit.id of the grant
    regrant_id        BIGINT UNSIGNED NULL,    -- grant that gave back balance the customer had SPENT
    tip_returned      TINYINT(1)   NOT NULL DEFAULT 0,
    tip_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,

    -- workflow
    decided_by        INT NULL,
    decided_at        DATETIME NULL,
    completed_at      DATETIME NULL,           -- goods finished being scanned back
    completed_by      INT NULL,
    completed_short   TINYINT(1) NOT NULL DEFAULT 0,
    short_reason      VARCHAR(255) NULL,
    notes             VARCHAR(500) NULL,
    -- the order's lines AS THEY WERE when the return was decided (JSON). The
    -- put-back measures against this, never the live line items, so an order
    -- edited afterwards cannot change how much stock comes back.
    lines_snapshot    TEXT NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NULL DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_order_return_order (order_id),   -- one return per order, enforced by the DB
    KEY idx_order_return_completed (completed_at),
    KEY idx_order_return_decided (decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------
-- 2. One row per PACKET scanned back into stock.
--    Mirrors t_crm_overnight_item's shape on purpose (same fields, same
--    server-authoritative barcode re-decode) so the two read alike.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS t_crm_order_return_item (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_id         BIGINT UNSIGNED NOT NULL,
    order_id          BIGINT UNSIGNED NOT NULL,
    line_item_id      BIGINT UNSIGNED NULL,
    product_id        BIGINT UNSIGNED NULL,
    business_unit_id  INT NULL,
    plu               INT NULL,
    barcode           VARCHAR(20) NULL,
    quantity          DECIMAL(10,3) NOT NULL DEFAULT 0.000,
    unit              VARCHAR(5) NOT NULL DEFAULT 'pcs',        -- kg | pcs
    destination       VARCHAR(20) NOT NULL,                     -- store_stock | freezer | chiller
    overnight_item_id BIGINT UNSIGNED NULL,                     -- link when it became an overnight packet
    scanned_by        INT NULL,
    scanned_at        DATETIME NULL,
    created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_return_item_return (return_id),
    KEY idx_return_item_order (order_id),
    KEY idx_return_item_line (line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------
-- 3. The status row. `refunded` has NEVER been used by an order (verified:
--    0 rows) and has no code hook, so it is safe to adopt as "Returned".
--    It is ALREADY inside the Quantities exclusion setting and every
--    closed-status list, which is exactly why we reuse it.
-- -----------------------------------------------------------------
UPDATE t_crm_order_status_master
SET status_name          = 'Returned',
    description          = 'Delivered order returned by the customer (money and stock handled on the return form)',
    lane                 = 'offtrack',
    is_final             = 1,
    is_active            = 1,
    counts_in_quantities = 0,
    auto_prepares        = 0,
    show_in_mobile       = 0,          -- web only: a return is set by Shabib/Taimur, not from a phone
    send_to_customer_app = 1,
    customer_app_alias   = 'refunded',
    color_class          = 'bg-purple-100',
    updated_at           = NOW()
WHERE status_code = 'refunded';


-- -----------------------------------------------------------------
-- 4. WEB permission: who may put an order into Returned.
--    ⚠⚠ There is NO role called 'admin' in t_sys_role — the admin roles are
--    'Management' and 'Taimur'. An IN ('admin') grant silently matches nobody
--    (that exact bug is in add_dispatch_scan_jul2026.sql). Named explicitly here.
--    The PHP ALSO falls back to the config email list / taimur|shabib roles, so
--    Shabib is covered even though he shares the Management role.
-- -----------------------------------------------------------------
INSERT INTO t_sys_role_permissions (role_id, permission_key, permission_name, is_allowed, created_by, created_at, updated_at)
SELECT r.id, 'return_orders', 'Return a Delivered Order', 1, 1, NOW(), NOW()
FROM t_sys_role r
WHERE LOWER(r.urole_name) IN ('management', 'taimur')
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_permissions rp
    WHERE rp.role_id = r.id AND rp.permission_key = 'return_orders'
);


-- -----------------------------------------------------------------
-- 5. MOBILE permission: who sees the "scan it back in" banner and screen.
--    Granted to every role that already holds access_store_mode (the overnight
--    pattern) — the people physically at the shelf.
-- -----------------------------------------------------------------
INSERT INTO t_sys_mobile_permission (permission_code, permission_name, permission_group, description, display_order)
VALUES ('scan_returns', 'Scan Returns Back Into Stock', 'store_mode',
        'Can see returned orders waiting to be put back and scan their packets into the chiller, freezer or store stock.', 74)
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name),
                        permission_group = VALUES(permission_group),
                        description      = VALUES(description),
                        display_order    = VALUES(display_order);

INSERT INTO t_sys_role_mobile_permission (role_id, mobile_permission_id)
SELECT r.id, p.id
FROM t_sys_role r
CROSS JOIN t_sys_mobile_permission p
WHERE p.permission_code = 'scan_returns'
AND (
    LOWER(r.urole_name) IN ('management', 'taimur')
    OR EXISTS (
        SELECT 1
        FROM t_sys_role_mobile_permission rp2
        JOIN t_sys_mobile_permission p2 ON p2.id = rp2.mobile_permission_id
        WHERE rp2.role_id = r.id AND p2.permission_code = 'access_store_mode'
    )
)
AND NOT EXISTS (
    SELECT 1 FROM t_sys_role_mobile_permission rp3
    WHERE rp3.role_id = r.id AND rp3.mobile_permission_id = p.id
);


-- =====================================================
-- VERIFICATION (run after):
--   SELECT status_code, status_name, lane, is_final, show_in_mobile, send_to_customer_app
--     FROM t_crm_order_status_master WHERE status_code = 'refunded';
--   SELECT r.urole_name FROM t_sys_role_permissions rp
--     JOIN t_sys_role r ON r.id = rp.role_id
--    WHERE rp.permission_key = 'return_orders' AND rp.is_allowed = 1;
--   SELECT COUNT(*) FROM t_sys_role_mobile_permission rp
--     JOIN t_sys_mobile_permission p ON p.id = rp.mobile_permission_id
--    WHERE p.permission_code = 'scan_returns';
--   SHOW CREATE TABLE t_crm_order_return;
-- =====================================================
