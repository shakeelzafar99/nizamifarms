-- ============================================================================
-- Qurbani cohort sizes by year + how many of each cohort were brand-new
-- customers in that year.
--
-- Mirrors the "Qurbani customer mix by year" stacked-bar on the Vercel
-- prototype.
--
-- READ ME — TODOs
--   1. `is_qurbani`: I am GUESSING this column exists on `t_crm_prod_product`.
--      If it doesn't, replace with the real flag/category. See CONTEXT.md.
--   2. Year extraction is in `Asia/Karachi` (UTC+5) — the DB stores UTC.
--      Don't change to plain `YEAR(o.created_at)`.
--   3. `status_name NOT IN ('cancelled','refunded')` — confirm the exact
--      strings against `t_crm_prod_order_status_master`.
-- ============================================================================

WITH qurbani_orders AS (
    SELECT  o.id              AS order_id,
            o.customer_id     AS customer_id,
            o.created_at      AS created_at,
            YEAR(CONVERT_TZ(o.created_at, '+00:00', '+05:00')) AS order_year
    FROM    t_crm_prod_order o
    JOIN    t_crm_prod_order_line_item li
              ON  li.order_id = o.id
    JOIN    t_crm_prod_product p
              ON  p.id = li.product_id
             AND p.is_qurbani = 1                      -- TODO: confirm column
    JOIN    t_crm_prod_order_status_master s
              ON  s.id = o.status_id                   -- TODO: confirm join key
    WHERE   s.name NOT IN ('cancelled', 'refunded')    -- TODO: confirm names
    GROUP BY o.id, o.customer_id, o.created_at
),
first_order AS (
    -- Customer's first non-cancelled order overall (any product),
    -- used to decide whether their Qurbani order made them "new".
    SELECT  o.customer_id,
            MIN(YEAR(CONVERT_TZ(o.created_at, '+00:00', '+05:00'))) AS first_year
    FROM    t_crm_prod_order o
    JOIN    t_crm_prod_order_status_master s
              ON  s.id = o.status_id                   -- TODO: confirm join key
    WHERE   s.name NOT IN ('cancelled', 'refunded')    -- TODO: confirm names
    GROUP BY o.customer_id
)
SELECT  q.order_year                              AS year,
        COUNT(DISTINCT q.customer_id)             AS cohort_size,
        SUM(CASE WHEN f.first_year = q.order_year THEN 1 ELSE 0 END)
                                                  AS new_customers,
        SUM(CASE WHEN f.first_year < q.order_year THEN 1 ELSE 0 END)
                                                  AS returning_customers
FROM    qurbani_orders q
JOIN    first_order   f
          ON f.customer_id = q.customer_id
WHERE   q.order_year BETWEEN 2022 AND YEAR(CONVERT_TZ(NOW(), '+00:00', '+05:00'))
GROUP BY q.order_year
ORDER BY q.order_year;
