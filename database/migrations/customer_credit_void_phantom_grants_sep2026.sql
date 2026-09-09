-- =====================================================================
-- Remove the FIVE phantom customer-balance grants          (Sep-07-2026)
-- =====================================================================
--
-- WHAT WENT WRONG
-- ---------------
-- Each of these five orders was paid ONCE, but the transfer was reported by
-- three channels: a bank SMS and a WhatsApp screenshot (which paired with each
-- other) plus a bank EMAIL that could not pair, because Meezan's RAAST credit
-- emails carry no reference number (695 of 695 have NULL extracted_ref).
-- The old overpay arithmetic therefore counted the orphaned email as a SECOND
-- payment, so the "extra" it offered was the entire invoice — and it was
-- banked as customer balance.
--
--   credit  order       order total   added to balance   real extra
--   ------  ----------  -----------   ----------------   ----------
--     #2    SH-22408      13,444.70          13,445.30         0.30
--     #3    SH-22376       3,733.90           3,734.10         0.10
--     #4    NF-19355      37,831.00          37,831.00         0.00
--     #9    SH-22437       6,000.00           6,000.00         0.00
--    #11    SH-22519       4,934.50           4,935.50         0.50
--                                     ----------------
--                                            65,945.90   (of 85,623.10 held)
--
-- ⚠ The Rs 0.90 of genuinely-extra money is NOT re-added by this script. It is
--   below the Rs 10 minimum grant, so it was never real balance anyway.
--
-- ⚠ NOT INCLUDED — these two need a human decision, not a script:
--     #6  NF-19341  Rs 8,260.60  — customer 1891's Rs 10,850 transfer looks
--                                  like it covered NF-19341 AND SH-22295,
--                                  which separately received grant #5.
--     #10 NF-19381  Rs 7,288.50  — two screenshots (1,131 + 10,000) against a
--                                  Rs 3,842.50 order.
--   Both are flagged on the Customer Balances screen. Decide, then use the
--   "Remove" button there rather than editing SQL.
--
--
-- WHY THIS VOIDS RATHER THAN DELETES
-- ----------------------------------
-- Deleting the rows would be wrong twice over:
--   1. Each grant already posted a ledger row that RAISED the bank balance and
--      the CUSTOMER_CREDIT liability. Deleting the credit row leaves that
--      posting behind, so the books would still say we hold the money — the
--      Balances screen's reconciliation check would go red by Rs 65,945.90.
--   2. It destroys the evidence. Money that was added and taken away should
--      read as exactly that, with who did it and why.
--
-- So this does precisely what the app's own "Remove this entry" button does
-- (CustomerCreditService::voidEntry), verified against it on a replica:
--   · marks the credit row `voided` with actor, timestamp and reason;
--   · posts the MIRROR ledger row (from/to swapped) instead of deleting the
--     original, so the two net to nil and the history stays intact;
--   · lowers both affected account balances by the same amount.
--
-- ⭐ EASIEST ROUTE: don't run this at all. Open /customers/balances, tick
--    "Needs review only", and press "Remove" on each of the five. It performs
--    the identical change with a typed reason. This script exists for doing all
--    five at once.
--
-- ⚠ RUN THE WEB UPLOAD FIRST is NOT required — this script is standalone and
--    touches no schema. But DO deploy the Aug-27 + Sep-04 overpay fixes before
--    anyone banks another overpayment, or new phantoms will appear.
-- =====================================================================


-- ---------------------------------------------------------------------
-- STEP 0 — who is doing this. Set to the person running it.
-- ---------------------------------------------------------------------
SET @actor := (SELECT id FROM t_sys_user WHERE email = 'taimur@nizamifarms.com' LIMIT 1);
SET @reason := 'Phantom overpayment - one transfer reported by three channels, whole invoice banked in error';

-- Refuses to run as an unknown user rather than writing NULL into the trail.
SELECT IF(@actor IS NULL,
          'STOP: no user matched that email — set @actor before continuing',
          CONCAT('Acting as user #', @actor)) AS actor_check;


-- ---------------------------------------------------------------------
-- STEP 1 — collect the targets.
--
-- Matched by ORDER NUMBER + EXACT AMOUNT, never by credit id: ids are not
-- guaranteed to line up between a replica and production, an order number is.
-- Only rows that are still live grants are eligible, so re-running this after
-- it has worked selects nothing.
--
-- TEMPORARY tables do not cause an implicit commit, and this is created BEFORE
-- the transaction opens, so the transaction below contains only DML.
-- ---------------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS nf_phantom_targets;

CREATE TEMPORARY TABLE nf_phantom_targets (
    credit_id       BIGINT UNSIGNED NOT NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    amount          DECIMAL(12,2)   NOT NULL,
    ledger_id       BIGINT UNSIGNED NULL,
    from_account_id INT             NULL,
    to_account_id   INT             NULL,
    order_number    VARCHAR(64)     NULL,
    ledger_desc     VARCHAR(255)    NULL,
    ledger_mode     VARCHAR(20)     NULL,
    PRIMARY KEY (credit_id)
);

INSERT INTO nf_phantom_targets
SELECT c.id, c.customer_id, c.amount, c.ledger_transaction_id,
       l.from_account_id, l.to_account_id, o.order_number, l.description, l.mode
FROM   t_crm_customer_credit c
JOIN   t_crm_prod_order      o ON o.id = c.order_id
LEFT   JOIN t_fin_ledger     l ON l.id = c.ledger_transaction_id
JOIN (
        SELECT 'SH-22408' AS order_number, 13445.30 AS amount
  UNION SELECT 'SH-22376',                  3734.10
  UNION SELECT 'NF-19355',                 37831.00
  UNION SELECT 'SH-22437',                  6000.00
  UNION SELECT 'SH-22519',                  4935.50
) t ON t.order_number = o.order_number AND t.amount = c.amount
WHERE  c.entry_type = 'grant'
  AND  c.status     = 'active';


-- ---------------------------------------------------------------------
-- STEP 2 — LOOK AT THIS BEFORE GOING FURTHER.
--
-- Expect EXACTLY 5 rows totalling 65,945.90. If you see a different number,
-- STOP: production has moved since this was written (someone may already have
-- removed one, or spent it on an order). Nothing has changed yet at this point.
-- ---------------------------------------------------------------------
SELECT credit_id, order_number, customer_id, amount, ledger_id, from_account_id, to_account_id
FROM   nf_phantom_targets
ORDER  BY credit_id;

-- Fewer than 5 is FINE and expected if some were already removed by hand from
-- the Customer Balances screen — this only ever touches what is still live.
-- Zero means there is nothing left to do. More than 5 is impossible unless the
-- match list was edited, and means STOP.
SELECT COUNT(*) AS rows_still_live, COALESCE(SUM(amount), 0) AS total_to_remove,
       CASE
         WHEN COUNT(*) = 0 THEN 'NOTHING TO DO — all five are already removed'
         WHEN COUNT(*) > 5 THEN 'STOP — more rows matched than this script was written for'
         ELSE CONCAT('OK — ', COUNT(*), ' of 5 still live, Rs ', FORMAT(SUM(amount), 2), ' will be removed')
       END AS verdict
FROM   nf_phantom_targets;

-- Which of the five have already gone, and who removed them.
SELECT o.order_number, c.amount, c.status, u.fullname AS removed_by, c.voided_at, c.voided_reason
FROM   t_crm_customer_credit c
JOIN   t_crm_prod_order o ON o.id = c.order_id
LEFT   JOIN t_sys_user  u ON u.id = c.voided_by
WHERE  o.order_number IN ('SH-22408','SH-22376','NF-19355','SH-22437','SH-22519')
  AND  c.entry_type = 'grant'
ORDER  BY c.id;

-- Balances as they stand now, to compare against afterwards.
SELECT 'BEFORE' AS phase, account_code, current_balance
FROM   t_fin_accounts
WHERE  account_code IN ('CUSTOMER_CREDIT', 'ONLINE')
ORDER  BY account_code;


-- ---------------------------------------------------------------------
-- STEP 3 — the change itself. All three parts together or none of them.
-- ---------------------------------------------------------------------
START TRANSACTION;

-- 3a. The mirror ledger rows. from/to are SWAPPED against the original, which
--     is how the app reverses a posting: the original stays readable and the
--     pair nets to zero. balance_updated = 1 because 3b applies the effect.
INSERT INTO t_fin_ledger
    (transaction_date, transaction_type, description,
     from_account_id, to_account_id, amount, mode,
     approval_status, approval_date, approved_by,
     settlement_status, settled_amount, order_id,
     balance_updated, created_by, created_at, updated_at)
SELECT NOW(), 'customer_credit_grant',
       LEFT(CONCAT('Reversal — ', COALESCE(t.ledger_desc, 'Customer credit'), ' (', @reason, ')'), 255),
       t.to_account_id,      -- swapped
       t.from_account_id,    -- on purpose
       t.amount, t.ledger_mode,
       'approved', NOW(), @actor,
       'open', 0.00,
       (SELECT c.order_id FROM t_crm_customer_credit c WHERE c.id = t.credit_id),
       1, @actor, NOW(), NOW()
FROM   nf_phantom_targets t
WHERE  t.ledger_id IS NOT NULL;

-- 3b. Move the account balances.
--     A grant posts BOTH its accounts upward (the liability we owe the
--     customer, and the bank holding the cash), so reversing it takes the same
--     amount off BOTH. Summed per account first, so an account appearing on
--     several rows is adjusted once by the correct total.
UPDATE t_fin_accounts a
JOIN (
    SELECT acc, SUM(amt) AS amt
    FROM (
        SELECT from_account_id AS acc, amount AS amt FROM nf_phantom_targets WHERE ledger_id IS NOT NULL
        UNION ALL
        SELECT to_account_id,          amount       FROM nf_phantom_targets WHERE ledger_id IS NOT NULL
    ) legs
    WHERE acc IS NOT NULL
    GROUP BY acc
) d ON d.acc = a.id
SET a.current_balance = a.current_balance - d.amt;

-- 3c. Void the credit rows — the balance is SUM() over the counting statuses,
--     so this is what actually removes the money from every screen.
UPDATE t_crm_customer_credit c
JOIN   nf_phantom_targets t ON t.credit_id = c.id
SET    c.status        = 'voided',
       c.voided_by     = @actor,
       c.voided_at     = NOW(),
       c.voided_reason = LEFT(@reason, 255),
       c.updated_at    = NOW()
WHERE  c.status = 'active';

COMMIT;


-- ---------------------------------------------------------------------
-- STEP 4 — verify. All four checks must say OK.
-- ---------------------------------------------------------------------

-- 4a. The five rows are voided and no longer count.
SELECT c.id, o.order_number, c.amount, c.status, c.voided_by, c.voided_at
FROM   t_crm_customer_credit c
LEFT   JOIN t_crm_prod_order o ON o.id = c.order_id
JOIN   nf_phantom_targets t ON t.credit_id = c.id
ORDER  BY c.id;

-- 4b. Held balance. If all five went in one run it is 19,677.20; if some were
--     already removed by hand it will already have been lower at step 2.
--     The check that always holds is 4c.
SELECT COALESCE(SUM(amount), 0) AS held_now
FROM   t_crm_customer_credit
WHERE  status IN ('active', 'reserved');

-- 4c. ⭐ The books must still agree with the bucket. This is the same check the
--     Customer Balances screen shows as its green dot.
SELECT (SELECT COALESCE(SUM(amount), 0) FROM t_crm_customer_credit WHERE status = 'active') AS bucket_posted,
       (SELECT current_balance FROM t_fin_accounts WHERE account_code = 'CUSTOMER_CREDIT')  AS ledger_account,
       IF(ABS((SELECT COALESCE(SUM(amount), 0) FROM t_crm_customer_credit WHERE status = 'active')
            - (SELECT current_balance FROM t_fin_accounts WHERE account_code = 'CUSTOMER_CREDIT')) < 0.01,
          'OK — bucket and ledger agree', 'STOP — they disagree, do not continue') AS verdict;

-- 4d. Bank should be down by exactly 65,945.90 from the STEP 2 figure.
SELECT 'AFTER' AS phase, account_code, current_balance
FROM   t_fin_accounts
WHERE  account_code IN ('CUSTOMER_CREDIT', 'ONLINE')
ORDER  BY account_code;

DROP TEMPORARY TABLE IF EXISTS nf_phantom_targets;


-- =====================================================================
-- IF YOU NEED TO UNDO THIS
-- =====================================================================
-- Nothing is deleted, so it is reversible — but do it deliberately, not in a
-- panic. Put the credit rows back to active, drop the mirror ledger rows, and
-- add the amounts back onto both accounts:
--
--   START TRANSACTION;
--   UPDATE t_crm_customer_credit SET status='active', voided_by=NULL,
--          voided_at=NULL, voided_reason=NULL
--    WHERE id IN (<the credit_ids printed at step 2>);
--   -- then remove the reversal rows created above (check them first):
--   SELECT id, description, amount FROM t_fin_ledger
--    WHERE transaction_type='customer_credit_grant'
--      AND description LIKE 'Reversal —%' AND DATE(created_at)=CURDATE();
--   -- and add each one's amount back to BOTH its from_account_id and
--   -- to_account_id in t_fin_accounts, then delete those ledger rows.
--   COMMIT;
--
-- Then re-run check 4c: bucket and ledger must agree again.
-- =====================================================================
