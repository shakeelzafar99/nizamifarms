-- ============================================================================
-- Clear stale QURBANI order-level rider assignments  (Aug-2026, data-only)
-- ============================================================================
--
-- WHY
--   Qurbani orders are completed on the LINE-ITEM track
--   (t_crm_prod_order_line_item.qurbani_item_status / qurbani_delivered_at).
--   Nothing rolls that up to t_crm_prod_order.order_status, so finished
--   qurbani orders stay at 'new' forever.
--
--   Rider mode's regular orders list (OrderController::filter, tab=open)
--   deliberately does NOT exclude qurbani for mobile requests, so any
--   qurbani order still carrying an ORDER-LEVEL rider keeps showing on that
--   rider's phone. As of the Aug-9 replica that is 15 orders — 14 of them on
--   Taimur (his whole Open tab) and 1 on salahudin. All 15 are fully paid and
--   date from the May-2026 season; the newest qurbani order is 2026-05-26.
--
--   NOTE the two DIFFERENT assignments — only the first one is touched here:
--     t_crm_prod_order.assigned_rider_user_id                  <- CLEARED
--     t_crm_prod_order_line_item.qurbani_assigned_rider_user_id <- UNTOUCHED
--   The second is the qurbani DELIVERY assignment and still drives the
--   Qurbani rider delivery screen / reporting. Do not clear it.
--
-- WHY NOT ALSO CLOSE THE ORDERS
--   DashboardAnalyticsService::getMonthlyTrends (~line 598) sums total_price
--   straight off order_status with NO qurbani exclusion. Marking the 113 open
--   qurbani orders 'delivered' would inject ~PKR 12.8M of phantom revenue into
--   the Apr/May-2026 trend chart (Apr 4,883,155 + May 7,922,615). So this
--   script changes the ASSIGNMENT ONLY and leaves order_status alone.
--
-- SAFE TO RE-RUN. Set-based, so it also catches rows that appeared after the
-- replica sync. Second run matches 0 rows.
--
-- Run on LOCAL first, then PROD. No app files change; no cache clear needed.
-- ============================================================================


-- ---------------------------------------------------------------------------
-- STEP 0 — PREVIEW. Run this alone first and eyeball the list.
--          Expect ~15 rows, all QUR26-*, all order_status = 'new'.
-- ---------------------------------------------------------------------------
SELECT o.id,
       o.order_number,
       u.fullname                AS rider,
       o.order_status,
       o.order_date,
       o.qurbani_day,
       o.qurbani_delivery_type,
       o.total_price
FROM t_crm_prod_order o
LEFT JOIN t_sys_user u ON u.id = o.assigned_rider_user_id
WHERE o.assigned_rider_user_id IS NOT NULL
  AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND (o.external_source <> 'shopify' OR o.external_source IS NULL)
  AND (
        (o.qurbani_day            IS NOT NULL AND o.qurbani_day            <> '')
     OR (o.qurbani_slot           IS NOT NULL AND o.qurbani_slot           <> '')
     OR (o.qurbani_region         IS NOT NULL AND o.qurbani_region         <> '')
     OR (o.qurbani_delivery_type  IS NOT NULL AND o.qurbani_delivery_type  <> '')
     OR EXISTS (SELECT 1
                  FROM t_crm_prod_order_line_item li
                  JOIN t_crm_prod_product p ON p.id = li.product_id
                 WHERE li.order_id = o.id
                   AND LOWER(COALESCE(p.attribute_1, '')) = 'qurbani')
  )
ORDER BY u.fullname, o.order_date;


-- ---------------------------------------------------------------------------
-- STEP 1 — BACKUP. Snapshots exactly what STEP 2/3 will change, so the whole
--          thing is reversible (see ROLLBACK at the bottom). Keep these tables.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS bak_qurbani_rider_unassign_aug2026;
CREATE TABLE bak_qurbani_rider_unassign_aug2026 AS
SELECT o.id                       AS order_id,
       o.order_number,
       o.assigned_rider_user_id   AS old_rider_user_id,
       o.order_status             AS old_order_status,
       NOW()                      AS backed_up_at
FROM t_crm_prod_order o
WHERE o.assigned_rider_user_id IS NOT NULL
  AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND (o.external_source <> 'shopify' OR o.external_source IS NULL)
  AND (
        (o.qurbani_day            IS NOT NULL AND o.qurbani_day            <> '')
     OR (o.qurbani_slot           IS NOT NULL AND o.qurbani_slot           <> '')
     OR (o.qurbani_region         IS NOT NULL AND o.qurbani_region         <> '')
     OR (o.qurbani_delivery_type  IS NOT NULL AND o.qurbani_delivery_type  <> '')
     OR EXISTS (SELECT 1
                  FROM t_crm_prod_order_line_item li
                  JOIN t_crm_prod_product p ON p.id = li.product_id
                 WHERE li.order_id = o.id
                   AND LOWER(COALESCE(p.attribute_1, '')) = 'qurbani')
  );

-- The exact open assignment-history rows STEP 3 will close (captured by row id
-- so the rollback can restore precisely these and nothing else — order 12555
-- has two history rows for two different riders).
DROP TABLE IF EXISTS bak_qurbani_rider_hist_aug2026;
CREATE TABLE bak_qurbani_rider_hist_aug2026 AS
SELECT h.id AS history_id, h.order_id, h.rider_user_id, NOW() AS backed_up_at
FROM t_ops_order_rider_history h
JOIN bak_qurbani_rider_unassign_aug2026 b
  ON b.order_id = h.order_id
 AND b.old_rider_user_id = h.rider_user_id
WHERE h.is_current = 1;


-- ---------------------------------------------------------------------------
-- STEP 2 — Clear the order-level rider. This is what removes the order from
--          the rider's phone. order_status is deliberately NOT touched.
-- ---------------------------------------------------------------------------
UPDATE t_crm_prod_order o
JOIN bak_qurbani_rider_unassign_aug2026 b ON b.order_id = o.id
SET o.assigned_rider_user_id = NULL;


-- ---------------------------------------------------------------------------
-- STEP 3 — Close the matching assignment-history rows so history doesn't keep
--          claiming a rider who is no longer assigned.
--          Delivery counts are unaffected: none of these orders has a
--          'delivered' row in t_crm_order_status_history (verified — 0 rows),
--          and the attendance delivered-per-day join requires one.
-- ---------------------------------------------------------------------------
UPDATE t_ops_order_rider_history h
JOIN bak_qurbani_rider_hist_aug2026 b ON b.history_id = h.id
SET h.is_current    = 0,
    h.unassigned_at = NOW();


-- ---------------------------------------------------------------------------
-- STEP 4 — VERIFY. First query must return 0. Second shows what was cleared.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS should_be_zero
FROM t_crm_prod_order o
WHERE o.assigned_rider_user_id IS NOT NULL
  AND o.order_status NOT IN ('delivered', 'completed', 'cancelled', 'refunded')
  AND (o.external_source <> 'shopify' OR o.external_source IS NULL)
  AND (
        (o.qurbani_day            IS NOT NULL AND o.qurbani_day            <> '')
     OR (o.qurbani_slot           IS NOT NULL AND o.qurbani_slot           <> '')
     OR (o.qurbani_region         IS NOT NULL AND o.qurbani_region         <> '')
     OR (o.qurbani_delivery_type  IS NOT NULL AND o.qurbani_delivery_type  <> '')
     OR EXISTS (SELECT 1
                  FROM t_crm_prod_order_line_item li
                  JOIN t_crm_prod_product p ON p.id = li.product_id
                 WHERE li.order_id = o.id
                   AND LOWER(COALESCE(p.attribute_1, '')) = 'qurbani')
  );

SELECT u.fullname AS rider, COUNT(*) AS orders_cleared
FROM bak_qurbani_rider_unassign_aug2026 b
LEFT JOIN t_sys_user u ON u.id = b.old_rider_user_id
GROUP BY u.fullname
ORDER BY orders_cleared DESC;


-- ---------------------------------------------------------------------------
-- ROLLBACK (only if needed — restores both tables exactly)
-- ---------------------------------------------------------------------------
-- UPDATE t_crm_prod_order o
-- JOIN bak_qurbani_rider_unassign_aug2026 b ON b.order_id = o.id
-- SET o.assigned_rider_user_id = b.old_rider_user_id;
--
-- UPDATE t_ops_order_rider_history h
-- JOIN bak_qurbani_rider_hist_aug2026 b ON b.history_id = h.id
-- SET h.is_current = 1, h.unassigned_at = NULL;
