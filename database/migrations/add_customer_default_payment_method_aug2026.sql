-- =====================================================================
-- Per-customer DEFAULT PAYMENT METHOD (Aug-2026). Portable, hand-run on
-- local + prod.
--
-- ⚠ RUN THIS **BEFORE** UPLOADING THE PHP. CustomerController::update()
--   and OrderController::store() both write this column, so
--   code-without-schema = "Unknown column" on every customer save and on
--   any order created with the "set as default" tick.
--
-- Model: NULL = this customer has no remembered preference, so the order
-- forms fall back to the rules that were hardcoded before (qurbani admin
-- default -> shop-customer-online -> cash). A stored value is only ever
-- 'cash' or 'online' — the two choices the unified payment-method picker
-- offers everywhere in the system. The per-ORDER value stays whatever each
-- form has always stored ('cash_on_delivery' on the regular NF path,
-- 'cash' on the qurbani path); this column is the canonical *choice*, and
-- each form expands it to its own historical value. See
-- CustomerModel::normalizePaymentMethod(), which folds any messy stored
-- string down to these two using the same one rule as
-- PaymentChangeInvoiceHandler::isOnline().
--
-- Shopify is deliberately untouched: those orders arrive by webhook with
-- the method the customer picked at checkout and never pass through either
-- create form, so nothing here can override them.
--
-- Additive + nullable. "Duplicate column" on re-run = already applied.
-- No index: this column is only ever read for ONE already-loaded customer
-- (never filtered or grouped on), so an index would be dead weight.
-- =====================================================================

ALTER TABLE `t_crm_prod_customer`
  ADD COLUMN `default_payment_method` VARCHAR(20) NULL DEFAULT NULL
      COMMENT "Remembered payment choice for this customer: 'cash' | 'online'. NULL = no default, forms fall back to the qurbani/shop/cash rules"
      AFTER `customer_type`;

-- Verify (expect 1 column, all rows NULL immediately after the change):
-- SHOW COLUMNS FROM t_crm_prod_customer LIKE 'default_payment_method';
-- SELECT default_payment_method, COUNT(*) FROM t_crm_prod_customer GROUP BY 1;
