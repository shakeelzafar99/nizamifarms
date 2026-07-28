-- ============================================================================
-- Purge city-centroid geocodes (portable, hand-run local + prod).
--
-- WHY: GeocodingService's old Nominatim ladder ended at "city, Pakistan", so a
-- failed address quietly became a pin in the middle of the city. Three points
-- hold 1,611 customers between them, spanning completely unrelated sectors
-- (H-13, Sohan, Burma Town, F-11, E-9, Aabpara all on one pin). Dispatch, route
-- optimisation and delivery-region assignment all read those coordinates as if
-- they were real addresses.
--
--   33.69381180, 73.06515110  -> 1036 customers   (Islamabad centroid)
--   33.60370530, 73.04831320  ->  330 customers   (Rawalpindi)
--   33.59154460, 73.05372100  ->  245 customers   (Rawalpindi, 2nd variant)
--
-- SCOPE (owner ruling, Jul-26): only customers with NO verified pin.
--   1353 of the 1611 rows belong to customers who already have a verified pin.
--   Those are left alone: every consumer checks verified FIRST (including
--   RegionDetectionService, Priority 1), so the stale centroid is inert.
--   The remaining 258 split 256 (ordered within the last year) / 2 (older).
--
-- WHAT IS AND IS NOT TOUCHED:
--   nulled : geocoded_latitude, geocoded_longitude, geocoded_at
--   NEVER  : address1 / address2 / city  <- the written address is what the rider
--            navigates by and what re-geocoding needs. Do not delete it.
--   NEVER  : latitude / longitude (the team's verified pin)
--
-- AFTER THIS RUNS those customers fall to tier 'none', so the app asks for a pin
-- instead of trusting a fake one, and their next order re-geocodes them with
-- Google. Nulling geocoded_at restores clean retry semantics (geocodeCustomer
-- skips a customer that already has coordinates).
--
-- Safe to re-run (already-nulled rows simply stop matching).
-- ============================================================================

-- 0) PRE-COUNT — run this first and keep the numbers.
--    Expect roughly: 1611 total / 1353 keep / 256 A / 2 B (replica, Jul-26).
SELECT
  SUM(1)                                                                      AS centroid_rows_total,
  SUM(CASE WHEN cu.latitude IS NOT NULL THEN 1 ELSE 0 END)                    AS keep_has_verified_pin,
  SUM(CASE WHEN cu.latitude IS NULL AND EXISTS (
        SELECT 1 FROM t_crm_prod_order o WHERE o.customer_id = cu.id
          AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
      ) THEN 1 ELSE 0 END)                                                    AS purge_a_active_1y,
  SUM(CASE WHEN cu.latitude IS NULL AND NOT EXISTS (
        SELECT 1 FROM t_crm_prod_order o WHERE o.customer_id = cu.id
          AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
      ) THEN 1 ELSE 0 END)                                                    AS purge_b_dormant
FROM t_crm_prod_customer cu
WHERE (cu.geocoded_latitude, cu.geocoded_longitude) IN (
        (33.69381180, 73.06515110),
        (33.60370530, 73.04831320),
        (33.59154460, 73.05372100));


-- 1) STATEMENT A — no verified pin, ordered within the last year (~256 rows).
--    These are the ones that actually reach a dispatch screen.
UPDATE t_crm_prod_customer cu
SET cu.geocoded_latitude  = NULL,
    cu.geocoded_longitude = NULL,
    cu.geocoded_at        = NULL,
    cu.updated_at         = NOW()
WHERE cu.latitude IS NULL
  AND (cu.geocoded_latitude, cu.geocoded_longitude) IN (
        (33.69381180, 73.06515110),
        (33.60370530, 73.04831320),
        (33.59154460, 73.05372100))
  AND EXISTS (
        SELECT 1 FROM t_crm_prod_order o
        WHERE o.customer_id = cu.id
          AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY));


-- 2) STATEMENT B — no verified pin, dormant (~2 rows). Same effect; run it too
--    unless you specifically want dormant customers left as they are.
UPDATE t_crm_prod_customer cu
SET cu.geocoded_latitude  = NULL,
    cu.geocoded_longitude = NULL,
    cu.geocoded_at        = NULL,
    cu.updated_at         = NOW()
WHERE cu.latitude IS NULL
  AND (cu.geocoded_latitude, cu.geocoded_longitude) IN (
        (33.69381180, 73.06515110),
        (33.60370530, 73.04831320),
        (33.59154460, 73.05372100));


-- 3) VERIFY — expect remaining_no_verified = 0, and kept_with_verified ~1353
--    (untouched by design).
SELECT
  SUM(CASE WHEN cu.latitude IS NULL     THEN 1 ELSE 0 END) AS remaining_no_verified,
  SUM(CASE WHEN cu.latitude IS NOT NULL THEN 1 ELSE 0 END) AS kept_with_verified
FROM t_crm_prod_customer cu
WHERE (cu.geocoded_latitude, cu.geocoded_longitude) IN (
        (33.69381180, 73.06515110),
        (33.60370530, 73.04831320),
        (33.59154460, 73.05372100));

-- 4) Sanity: nobody lost their written address.
SELECT COUNT(*) AS customers_missing_address_and_pin
FROM t_crm_prod_customer
WHERE latitude IS NULL AND geocoded_latitude IS NULL
  AND (address1 IS NULL OR address1 = '');
