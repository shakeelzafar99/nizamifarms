-- ============================================================================
-- QURBANI 2025 SEASON BACKFILL  (Jul 2026)
-- Makes the May–Jun 2025 Qurbani season visible to the canonical Qurbani
-- filter (QurbaniFinanceFilter) — Reports "Qurbani 2025" block, the HQ Qurbani
-- view's 2025 season pill, and QUR26's "returning from last season".
--
-- WHY name-based: all 539 line items of the 2025 season reference product ids
-- that no longer exist in t_crm_prod_product (old Shopify-era catalog), so the
-- filter's product-tag leg can never match them. The items' NAMES are
-- unambiguous ("Cow Share (Hissa) Day 1..3", "Goat (Bakra) with slaughtering
-- (Day 1..4)", "Qurbani Hissa/Goat Charity - Rajanpur").
--
-- WHAT IT TOUCHES: two columns on 2025-season orders only —
--   qurbani_day (was NULL on every matched row) and total_paid (was 0).
-- It does NOT touch products, the ledger, payment records, or any 2026 data.
--
-- VERIFIED on the Jul-2026 replica before writing this script:
--   • 443 orders / 423 customers / Rs 38,402,445 booked / 402 delivered /
--     41 cancelled match inside the window; ALL had qurbani_day, qurbani_slot,
--     qurbani_region, qurbani_delivery_type = NULL beforehand.
--   • All had total_paid = 0 (the column predates them). Owner ruling Jul-16:
--     delivered 2025 Qurbani orders are considered PAID.
--   • The name patterns match nothing outside Apr–Jul 2025 except the QUR26
--     season itself (already tagged) and one Oct-2025 stray — both excluded by
--     the date window.
--
-- EXCLUDED BY OWNER RULING (Jul-16): the separate "Qurbani Paaye – Delivery 10
--   days after Eid" order (id 4604, Rs 2,000, Jun-24-2025) is NOT a Qurbani
--   order — it was a follow-up trotters delivery to a Qurbani customer, so it
--   stays a normal order. Paaye bought ON a Qurbani order (12 orders) is just a
--   line item there and is correctly included via that order's Day.
--   Sadqa / Aqeeqa are a different service and are never matched here (Q8).
--
-- EXPECTED RESULT: 443 orders get qurbani_day 'Day 1..4'
--   (Day 1 = 183, Day 2 = 173, Day 3 = 85, Day 4 = 2);
--   402 delivered orders get total_paid = total_price (sum 35,167,695).
--
-- KNOWN, ACCEPTED CONSEQUENCES (owner ruling Jul-16):
--   • May/Jun-2025 monthly Reports revenue drops ~Rs 38M — it moves into the
--     "Qurbani 2025" year block (same segregation rule as 2026).
--   • Growth history recomputes: 2025 Qurbani deliveries stop counting as
--     regular first-deliveries (this also cleans ~205 Qurbani-only buyers out
--     of the churn/win-back bands, and makes QUR26 "returning" real).
--
-- SAFE TO RE-RUN: yes — each UPDATE only touches rows still in their
-- pre-backfill state. Run on LOCAL first, verify, then PROD. Rollback at end.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- STEP 1 — stamp qurbani_day (parsed from the item name) on 2025 season orders
-- ---------------------------------------------------------------------------
UPDATE t_crm_prod_order o
JOIN (
    SELECT li.order_id,
           MIN(CASE
                 WHEN LOWER(li.name) LIKE '%day 1%' THEN 1
                 WHEN LOWER(li.name) LIKE '%day 2%' THEN 2
                 WHEN LOWER(li.name) LIKE '%day 3%' THEN 3
                 WHEN LOWER(li.name) LIKE '%day 4%' THEN 4
                 ELSE NULL
               END) AS day_no
    FROM t_crm_prod_order_line_item li
    WHERE LOWER(li.name) REGEXP 'qurban|hissa|cow share|(goat|bakra|lamb|dumba).*day [1-4]|charity.*rajanpur'
    GROUP BY li.order_id
) q ON q.order_id = o.id
SET o.qurbani_day = CONCAT('Day ', q.day_no)
WHERE o.order_date >= '2025-04-01' AND o.order_date < '2025-08-01'
  AND o.qurbani_day IS NULL AND o.qurbani_slot IS NULL
  AND o.qurbani_region IS NULL AND o.qurbani_delivery_type IS NULL
  AND q.day_no IS NOT NULL;   -- no parsable Day = paaye-only follow-up → left alone

-- ---------------------------------------------------------------------------
-- STEP 2 — delivered 2025 Qurbani orders are considered PAID (owner ruling Q6)
--          Order-level marker only; no payment rows, no ledger entries.
-- ---------------------------------------------------------------------------
UPDATE t_crm_prod_order o
SET o.total_paid = o.total_price
WHERE o.order_date >= '2025-04-01' AND o.order_date < '2025-08-01'
  AND o.qurbani_day LIKE 'Day %'
  AND o.order_status IN ('delivered', 'completed')
  AND (o.total_paid IS NULL OR o.total_paid = 0);

-- ============================================================================
-- VERIFY (run after; expected values in comments)
-- ============================================================================
-- A) Season now visible: expect 443 / 38402445 / 402 / 41
SELECT COUNT(*)                                         AS orders_flagged,   -- 443
       ROUND(SUM(total_price))                          AS booked,           -- 38402445
       SUM(order_status IN ('delivered','completed'))   AS delivered,        -- 402
       SUM(order_status = 'cancelled')                  AS cancelled         -- 41
FROM t_crm_prod_order
WHERE order_date >= '2025-04-01' AND order_date < '2025-08-01'
  AND qurbani_day LIKE 'Day %';

-- B) Paid marker: expect 402 rows / 35167695
SELECT COUNT(*) AS delivered_marked_paid, ROUND(SUM(total_paid)) AS paid_sum
FROM t_crm_prod_order
WHERE order_date >= '2025-04-01' AND order_date < '2025-08-01'
  AND qurbani_day LIKE 'Day %'
  AND order_status IN ('delivered','completed') AND total_paid > 0;

-- C) Day split: expect Day 1 = 183, Day 2 = 173, Day 3 = 85, Day 4 = 2
SELECT qurbani_day, COUNT(*) AS n FROM t_crm_prod_order
WHERE order_date >= '2025-04-01' AND order_date < '2025-08-01'
  AND qurbani_day IS NOT NULL GROUP BY qurbani_day ORDER BY qurbani_day;

-- D) Eyeball only (NOT pass/fail). This script only ever writes inside
--    Apr–Jul 2025, so any Day-stamped non-QUR order outside that window is a
--    PRE-EXISTING Qurbani order this script cannot have created. On the
--    Jul-2026 replica this returns exactly one row — SH-19637 (2026-04-28,
--    cancelled): a real 2026 Qurbani order that was booked under an SH- number
--    instead of a QUR26- one. Expected; the HQ season lookup now finds it too.
SELECT id, order_number, DATE(order_date) AS ordered, order_status, qurbani_day
FROM t_crm_prod_order
WHERE qurbani_day LIKE 'Day %' AND order_number NOT LIKE 'QUR%'
  AND (order_date < '2025-04-01' OR order_date >= '2025-08-01');

-- E) The paaye follow-up order stayed a normal order: expect qurbani_day NULL, total_paid 0
SELECT id, order_number, qurbani_day, total_paid FROM t_crm_prod_order WHERE id = 4604;

-- ============================================================================
-- ROLLBACK (only if needed — reverses everything this script did)
-- ============================================================================
-- UPDATE t_crm_prod_order SET total_paid = 0
--   WHERE order_date >= '2025-04-01' AND order_date < '2025-08-01' AND qurbani_day LIKE 'Day %';
-- UPDATE t_crm_prod_order SET qurbani_day = NULL
--   WHERE order_date >= '2025-04-01' AND order_date < '2025-08-01' AND qurbani_day LIKE 'Day %';
-- ============================================================================
