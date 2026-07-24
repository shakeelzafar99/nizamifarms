-- Jul-2026: Mark customers who have migrated to the mobile app, so they can be
-- excluded from campaigns and counted on HQ. "On the app" = has placed at least
-- one order whose Shopify source_name is ios_app / android_app (the only signal
-- available — we can't detect installs without an order). Set automatically at
-- order ingest by OrderModel::storeOrderFromApi(). Forward-looking: existing app
-- users get flagged on their next app order.
--
-- Manual apply (per workspace deploy rules): run on LOCAL and PROD before uploading
-- the matching web code. Portable ALTER TABLE. All existing rows default to 0.

ALTER TABLE t_crm_prod_customer
  ADD COLUMN is_on_mobile_app TINYINT(1) NOT NULL DEFAULT 0 AFTER customer_type,
  ADD COLUMN mobile_app_first_seen_at DATETIME NULL AFTER is_on_mobile_app;

-- Optional: speeds up the campaign "exclude app customers" filter on large lists.
CREATE INDEX idx_customer_on_mobile_app ON t_crm_prod_customer (is_on_mobile_app);
