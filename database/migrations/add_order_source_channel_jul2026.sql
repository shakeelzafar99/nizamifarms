-- Jul-2026: Capture the Shopify order's `source_name` (e.g. ios_app / android_app / web)
-- so app-origin orders are identifiable in NF. Set by the customer app at order creation;
-- ingested by OrderModel::mapShopifyOrder(), carried onto the live order at conversion.
--
-- Manual apply (per workspace deploy rules): run on LOCAL and PROD before uploading the
-- matching web code. Portable ALTER TABLE — safe on shared hosting. NULL for all existing
-- rows (only orders received AFTER the code deploy will be populated).

ALTER TABLE t_crm_shopify_order
  ADD COLUMN order_source_channel VARCHAR(50) NULL AFTER external_source;

ALTER TABLE t_crm_prod_order
  ADD COLUMN order_source_channel VARCHAR(50) NULL AFTER external_source;
