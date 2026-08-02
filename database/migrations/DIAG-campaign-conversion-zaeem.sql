-- =============================================================================
-- DIAGNOSTIC (READ-ONLY): why isn't Zaeem's order counting as a campaign win?
-- Run on PROD. Every statement is a SELECT — nothing is modified.
--
-- The campaign counts an order as a conversion only when ALL of these hold:
--   1. same customer_id as the campaign recipient
--   2. order_date  >  the moment the campaign message was sent
--   3. order_date <=  sent_at + tracking_window_days
--   4. order_status IN ('delivered','completed')      <-- the usual culprit
--   5. (if tracking_type='app_orders') order_source_channel in ios_app/android_app
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1) The campaign row for this customer: when was he messaged?
-- ---------------------------------------------------------------------------
SELECT c.id            AS campaign_id,
       c.name,
       c.tracking_type,
       c.tracking_window_days,
       cc.customer_id,
       cc.status       AS campaign_status,
       cc.sent_at,
       cc.delivered_at,
       cc.read_at
FROM t_crm_campaigns c
JOIN t_crm_campaign_customers cc ON cc.campaign_id = c.id
JOIN t_crm_prod_customer cust    ON cust.id = cc.customer_id
WHERE c.name = 'app launch wave 1'
  AND cust.phone_normalized LIKE '%3345474000%';

-- ---------------------------------------------------------------------------
-- 2) His orders, with EACH condition evaluated separately.
--    Read the last four columns — the one showing 0 is the reason.
-- ---------------------------------------------------------------------------
SELECT o.id,
       o.order_number,
       o.order_date,
       o.order_status,
       o.total_price,
       o.order_source_channel,
       cc.sent_at,
       (o.order_date >  cc.sent_at)                                              AS cond_after_send,
       (o.order_date <= DATE_ADD(cc.sent_at, INTERVAL c.tracking_window_days DAY)) AS cond_in_window,
       (o.order_status IN ('delivered','completed'))                             AS cond_status_ok,
       (o.order_source_channel IN ('ios_app','android_app'))                     AS is_app_order
FROM t_crm_campaigns c
JOIN t_crm_campaign_customers cc ON cc.campaign_id = c.id
JOIN t_crm_prod_customer cust    ON cust.id = cc.customer_id
JOIN t_crm_prod_order o          ON o.customer_id = cc.customer_id
WHERE c.name = 'app launch wave 1'
  AND cust.phone_normalized LIKE '%3345474000%'
  AND o.order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY o.order_date DESC;

-- ---------------------------------------------------------------------------
-- 3) Is the order perhaps still in the SHOPIFY STAGING table (not yet
--    converted to a live order)? Campaign stats only read live orders.
-- ---------------------------------------------------------------------------
SELECT id, order_number, order_date, order_status, order_source_channel, converted
FROM t_crm_shopify_order
WHERE address_phone LIKE '%3345474000%'
  AND order_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY order_date DESC;

-- ---------------------------------------------------------------------------
-- 4) Whole-campaign view: how many attributed orders are being EXCLUDED
--    purely because they aren't delivered yet? This is the number that tells
--    you whether the "delivered only" rule is hiding real wins right now.
-- ---------------------------------------------------------------------------
SELECT o.order_status,
       COUNT(DISTINCT o.id)  AS orders,
       COUNT(DISTINCT cc.customer_id) AS customers,
       SUM(o.total_price)    AS value
FROM t_crm_campaigns c
JOIN t_crm_campaign_customers cc ON cc.campaign_id = c.id AND cc.status = 'sent'
JOIN t_crm_prod_order o
      ON o.customer_id = cc.customer_id
     AND o.order_date  > cc.sent_at
     AND o.order_date <= DATE_ADD(cc.sent_at, INTERVAL c.tracking_window_days DAY)
WHERE c.name = 'app launch wave 1'
GROUP BY o.order_status
ORDER BY orders DESC;
