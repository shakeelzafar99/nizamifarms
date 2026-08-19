-- Movement log for payment signals (owner ask, Aug-17-2026): every attach,
-- re-point and release of a signal's order/customer, so "what payments changed
-- hands" is answerable. Written by a PaymentSignal model hook; the code no-ops
-- until this table exists, so run order does not matter (code first is fine).
-- Portable plain SQL; safe to re-run (IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS t_fin_payment_signal_moves (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  signal_id        BIGINT UNSIGNED NOT NULL,
  source           VARCHAR(20)  NULL,
  amount           DECIMAL(12,2) NULL,
  payer_name       VARCHAR(190) NULL,
  from_customer_id INT NULL,
  from_order_id    INT NULL,
  to_customer_id   INT NULL,
  to_order_id      INT NULL,
  from_reason      VARCHAR(64) NULL,
  to_reason        VARCHAR(64) NULL,
  moved_by         INT NULL,
  created_at       DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_moves_signal (signal_id),
  KEY idx_moves_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
