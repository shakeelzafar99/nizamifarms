-- ============================================================================
-- Apr-2026: One-off cleanup for Qurbani online ledger entries that were
--           created by Taimur / Management / Admin via the MOBILE app and
--           ended up sitting in the L1 (or L2) approval queue.
--
-- Why: Until today, RiderController::addOrderPayment did not auto-approve
--      Qurbani online payments — even if the user was Taimur. The web path
--      (OrderController::addQurbaniPayment) has always auto-approved them,
--      so the two paths produced inconsistent rows. The controller fix
--      makes the mobile path match the web for trusted roles, but that
--      only affects NEW payments. This script retires the rows that are
--      already stuck in the queue.
--
-- Schema reference (verified against the live models / controllers):
--      t_fin_ledger ............. id, transaction_type, mode,
--                                 approval_status, balance_updated,
--                                 from_account_id, to_account_id, amount,
--                                 settlement_status, settled_amount,
--                                 settled_at, approved_by, approval_date,
--                                 created_by, order_id, comments, ...
--                                 (see App\Models\FIN\LedgerModel::$fillable)
--      t_fin_accounts ........... id, current_balance
--                                 (see App\Models\FIN\AccountModel)
--      t_crm_prod_order ......... id, order_number, customer_id, name,
--                                 qurbani_day, ...
--                                 (see App\Models\CRM\OrderModel)
--      t_crm_prod_customer ...... id, first_name, last_name
--                                 (full_name is a PHP accessor only — must
--                                  be built via CONCAT_WS in SQL)
--      t_sys_user ............... id, fullname
--      t_sys_user_role .......... user_id, role_id
--      t_sys_role ............... id, urole_name
--
-- Scope (intentionally narrow — same predicates as the new RiderController
-- guard for $isQurbaniOrder + $isManager):
--      • transaction_type IN ('order_payment', 'invoice')
--           - 'order_payment' = what RiderController::addOrderPayment writes
--             (line 17515: LedgerModel::TYPE_ORDER_PAYMENT). This is the
--             actual transaction type for the rows seen in the approvals
--             queue today.
--           - 'invoice' = what LedgerPostingService::postInvoiceFromOrder
--             writes on order delivery; included defensively in case any
--             Qurbani delivery flow posted via that path before payment.
--      • mode = 'online'
--      • approval_status IN ('pending', 'pending_l1', 'pending_l2')
--      • Qurbani order = order_number LIKE 'QUR%' OR qurbani_day IS NOT NULL
--      • created_by has role Taimur / *management* / Admin
--
-- Run order:
--   1. Run STEP 1 (SELECT) and review the rows. Save the output.
--   2. If the list looks right, run STEP 2 (UPDATE) inside a transaction.
--      Each UPDATE is reported by row count so you can sanity-check.
--   3. Re-run STEP 1 — it should return zero rows.
--
-- Idempotent: re-running STEP 2 after success is a no-op (the WHERE clause
--             excludes already-approved rows). Balance updates are gated
--             on balance_updated = 0 so they never double-apply.
-- ============================================================================


-- ----------------------------------------------------------------------------
-- STEP 1 — PREVIEW: which rows will be auto-approved?
--          Run this first. Eyeball the output. If anything looks wrong
--          (unexpected user, unexpected order, suspicious amount), STOP
--          and ping me before running STEP 2.
-- ----------------------------------------------------------------------------
SELECT
    l.id                AS ledger_id,
    o.order_number,
    l.amount,
    l.transaction_date,
    l.approval_status,
    l.balance_updated,                  -- 0 = balances NOT yet applied (pending_l1)
                                        -- 1 = balances already applied (pending_l2)
    l.from_account_id,
    l.to_account_id,
    u.fullname          AS created_by_user,
    GROUP_CONCAT(DISTINCT r.urole_name) AS created_by_roles,
    -- Mirror the customer-name priority used by LedgerPostingService:
    -- prefer the order's address name (o.name), fall back to first/last
    -- on the customer table. NB: t_crm_prod_customer has NO full_name
    -- column — full_name is a PHP accessor, so we must concat in SQL.
    COALESCE(
        NULLIF(TRIM(o.name), ''),
        NULLIF(TRIM(CONCAT_WS(' ', cust.first_name, cust.last_name)), '')
    ) AS customer_name,
    l.description
FROM t_fin_ledger l
JOIN t_crm_prod_order o
       ON o.id = l.order_id
LEFT JOIN t_crm_prod_customer cust
       ON cust.id = o.customer_id
JOIN t_sys_user u
       ON u.id = l.created_by
LEFT JOIN t_sys_user_role ur
       ON ur.user_id = u.id
LEFT JOIN t_sys_role r
       ON r.id = ur.role_id
WHERE l.transaction_type IN ('order_payment', 'invoice')
  AND l.mode = 'online'
  AND l.approval_status IN ('pending', 'pending_l1', 'pending_l2')
  AND (o.order_number LIKE 'QUR%' OR o.qurbani_day IS NOT NULL)
  AND l.created_by IN (
      SELECT ur2.user_id
      FROM t_sys_user_role ur2
      JOIN t_sys_role r2 ON r2.id = ur2.role_id
      WHERE LOWER(r2.urole_name) = 'taimur'
         OR LOWER(r2.urole_name) = 'admin'
         OR LOWER(r2.urole_name) LIKE '%management%'
  )
GROUP BY l.id, o.order_number, l.amount, l.transaction_date,
         l.approval_status, l.balance_updated, l.from_account_id,
         l.to_account_id, u.fullname, o.name, cust.first_name,
         cust.last_name, l.description
ORDER BY l.transaction_date DESC, l.id DESC;


-- ----------------------------------------------------------------------------
-- STEP 2 — UPDATE: approve the rows above + apply balances where missing.
--
-- Run the WHOLE block inside a transaction. If anything looks off after
-- the first two UPDATEs, ROLLBACK; otherwise COMMIT.
--
-- The temp table approach pins the exact ledger_id list at the start so
-- the three UPDATE statements all act on the same set of rows even if
-- new payments are created mid-script.
-- ----------------------------------------------------------------------------
START TRANSACTION;

-- Pin the candidate set into a temp table.
DROP TEMPORARY TABLE IF EXISTS tmp_qurbani_online_to_approve;
CREATE TEMPORARY TABLE tmp_qurbani_online_to_approve AS
SELECT l.id AS ledger_id
FROM t_fin_ledger l
JOIN t_crm_prod_order o ON o.id = l.order_id
WHERE l.transaction_type IN ('order_payment', 'invoice')
  AND l.mode = 'online'
  AND l.approval_status IN ('pending', 'pending_l1', 'pending_l2')
  AND (o.order_number LIKE 'QUR%' OR o.qurbani_day IS NOT NULL)
  AND l.created_by IN (
      SELECT ur2.user_id
      FROM t_sys_user_role ur2
      JOIN t_sys_role r2 ON r2.id = ur2.role_id
      WHERE LOWER(r2.urole_name) = 'taimur'
         OR LOWER(r2.urole_name) = 'admin'
         OR LOWER(r2.urole_name) LIKE '%management%'
  );

-- Quick sanity check — should match the row count from STEP 1.
SELECT COUNT(*) AS rows_to_approve FROM tmp_qurbani_online_to_approve;

-- 2a. For rows whose balances were never applied (balance_updated = 0,
--     i.e. they were sitting in pending_l1), apply the balance moves now.
--     Mirrors LedgerPostingService::postInvoiceFromOrder lines 185-188:
--          $salesAccount->current_balance -= $amount;   // from_account
--          $toAccount->current_balance    += $amount;   // to_account
--     This is the same shape the controller uses for fresh auto-approved
--     online payments, so the resulting account balances match what they
--     would have been if RiderController had auto-approved at creation.
UPDATE t_fin_accounts a
JOIN t_fin_ledger l ON l.from_account_id = a.id
JOIN tmp_qurbani_online_to_approve t ON t.ledger_id = l.id
SET a.current_balance = a.current_balance - l.amount
WHERE l.balance_updated = 0;

UPDATE t_fin_accounts a
JOIN t_fin_ledger l ON l.to_account_id = a.id
JOIN tmp_qurbani_online_to_approve t ON t.ledger_id = l.id
SET a.current_balance = a.current_balance + l.amount
WHERE l.balance_updated = 0;

-- 2b. Flip the ledger rows to approved + settled. We set approved_by =
--     created_by because the spec is "Taimur's payments auto-approve" —
--     the same user who created the row is the approver of record.
--     approval_date is cast as a DATE in the model, so NOW() is fine
--     (MySQL will truncate to date when storing).
UPDATE t_fin_ledger l
JOIN tmp_qurbani_online_to_approve t ON t.ledger_id = l.id
SET l.approval_status   = 'approved',
    l.approval_date     = NOW(),
    l.approved_by       = l.created_by,
    l.balance_updated   = 1,
    l.settlement_status = 'settled',
    l.settled_amount    = l.amount,
    l.settled_at        = COALESCE(l.settled_at, NOW()),
    l.comments          = CONCAT(IFNULL(l.comments, ''),
                                 ' | Auto-approved by apr2026 cleanup '
                                 '(Qurbani online + Taimur/Mgmt/Admin)')
WHERE l.approval_status IN ('pending', 'pending_l1', 'pending_l2');

-- Final tally so you can confirm before committing.
SELECT
    SUM(approval_status = 'approved')                              AS now_approved,
    SUM(approval_status IN ('pending','pending_l1','pending_l2'))  AS still_pending
FROM t_fin_ledger
WHERE id IN (SELECT ledger_id FROM tmp_qurbani_online_to_approve);

DROP TEMPORARY TABLE IF EXISTS tmp_qurbani_online_to_approve;

-- If everything looks right above, run:
COMMIT;
-- Otherwise:
-- ROLLBACK;
