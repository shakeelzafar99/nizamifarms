-- =====================================================================
-- Geocoding: address-clean evaluation log
-- Jul-28-2026
-- =====================================================================
--
-- WHY THIS TABLE EXISTS
-- Customers write addresses like "House no# 373, Street no# 25, Sector
-- E11-4". Google cannot parse the "no#" tokens: it discards the house and
-- street entirely, answers with the SECTOR CENTROID and flags the result
-- partial_match — which our anti-centroid rule then (correctly) refuses to
-- store. The same address with those tokens removed matches ROOFTOP, i.e.
-- the exact house. So GeocodingService now cleans the address before it
-- queries Google.
--
-- Cleaning changes what we ask Google, so it must be auditable. This table
-- records every geocode attempt: what the customer wrote, what we actually
-- sent, whether cleaning changed it, what Google answered, and what we
-- decided to do with that answer. It is the evidence base for judging
-- whether the cleaning rules help or hurt, per trigger.
--
-- The written address itself (t_crm_prod_customer.address1) is NEVER
-- rewritten — the rider reads that at the door. Cleaning applies only to
-- the Google query.
--
-- SAFE TO RUN BEFORE THE PHP IS UPLOADED: nothing reads this table yet, and
-- the writer is wrapped so a missing table can never break geocoding.
-- Idempotent — re-running it changes nothing.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `t_crm_geocode_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Who/what this attempt was for. Both nullable: the batch tool and the
  -- URL-fallback path have a customer but no order.
  -- order_id is a t_crm_prod_order id — with ONE known exception: rows with
  -- trigger_source='store_button' written by an OLD-APK phone on the Shopify
  -- screen may carry a t_crm_shopify_order STAGING id (the old endpoint cannot
  -- tell them apart; the new preview endpoint nulls staging ids and uses
  -- trigger 'store_preview_shopify' instead). Analyse by customer_id — always
  -- unambiguous — and never join store_button order_ids against prod orders.
  `customer_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `order_id` BIGINT UNSIGNED NULL DEFAULT NULL,

  -- What triggered it: order_create | shopify_convert | address_edit |
  -- store_button | store_preview | store_preview_shopify | customer_api |
  -- batch | url_fallback | unknown
  `trigger_source` VARCHAR(32) NOT NULL DEFAULT 'unknown',

  -- The address exactly as it is written on the customer record.
  `address_original` VARCHAR(255) NULL DEFAULT NULL,
  -- What we actually sent to Google (cleaned, and with the city appended
  -- when the address did not already name it).
  `address_query` VARCHAR(300) NULL DEFAULT NULL,
  -- 1 when cleaning changed the text. The whole point of the table: compare
  -- outcomes where this is 1 against where it is 0.
  `was_cleaned` TINYINT(1) NOT NULL DEFAULT 0,
  -- 1 when the cleaned query found nothing and we retried with the
  -- customer's original wording. Should be rare; if it is not, a cleaning
  -- rule is too aggressive.
  `used_original_fallback` TINYINT(1) NOT NULL DEFAULT 0,

  -- Google's raw answer, kept as-is so a rejection can be re-judged later
  -- without re-querying (and without paying for the call again).
  `google_status` VARCHAR(32) NULL DEFAULT NULL,
  `matched_address` VARCHAR(300) NULL DEFAULT NULL,
  `location_type` VARCHAR(32) NULL DEFAULT NULL,
  `result_types` VARCHAR(255) NULL DEFAULT NULL,
  `partial_match` TINYINT(1) NOT NULL DEFAULT 0,

  -- Our verdict. precision_tier is NULL whenever the answer was refused.
  `precision_tier` VARCHAR(16) NULL DEFAULT NULL,
  `latitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `longitude` DECIMAL(10,7) NULL DEFAULT NULL,
  `km_from_office` DECIMAL(7,2) NULL DEFAULT NULL,

  -- accepted | rejected_vague | rejected_too_far | zero_results | api_error
  -- | no_key | transport_error | cache_hit | cache_miss_known_bad
  `outcome` VARCHAR(32) NOT NULL DEFAULT 'unknown',

  `created_by` INT NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_geocode_log_customer` (`customer_id`),
  KEY `idx_geocode_log_created` (`created_at`),
  KEY `idx_geocode_log_outcome` (`outcome`),
  KEY `idx_geocode_log_cleaned` (`was_cleaned`, `outcome`),
  KEY `idx_geocode_log_trigger` (`trigger_source`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- HOW TO EVALUATE IT LATER (read-only, run whenever)
-- =====================================================================
--
-- 1) Did cleaning help? Acceptance rate for cleaned vs untouched addresses:
--
-- SELECT was_cleaned,
--        COUNT(*)                                            AS attempts,
--        SUM(outcome = 'accepted')                           AS accepted,
--        ROUND(100 * SUM(outcome = 'accepted') / COUNT(*), 1) AS accept_pct,
--        SUM(precision_tier = 'exact')                       AS exact_pins
-- FROM t_crm_geocode_log
-- WHERE outcome NOT IN ('cache_hit', 'no_key', 'transport_error')
-- GROUP BY was_cleaned;
--
-- 2) Where cleaning was needed but STILL failed — the next rules to write:
--
-- SELECT address_original, address_query, matched_address, location_type,
--        partial_match, outcome, created_at
-- FROM t_crm_geocode_log
-- WHERE was_cleaned = 1 AND outcome <> 'accepted'
-- ORDER BY created_at DESC
-- LIMIT 50;
--
-- 3) Did cleaning ever LOSE an address it should have found? Any row here
--    is a cleaning rule doing harm — investigate each one:
--
-- SELECT * FROM t_crm_geocode_log
-- WHERE used_original_fallback = 1 AND outcome = 'accepted'
-- ORDER BY created_at DESC;
--
-- 4) Addresses still unplaceable, worst first — the call-the-customer list:
--
-- SELECT address_original, COUNT(*) AS tries, MAX(created_at) AS last_try
-- FROM t_crm_geocode_log
-- WHERE outcome IN ('rejected_vague', 'zero_results')
-- GROUP BY address_original
-- ORDER BY tries DESC, last_try DESC
-- LIMIT 50;
-- =====================================================================
