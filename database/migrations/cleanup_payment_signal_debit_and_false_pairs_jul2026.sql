-- ============================================================================
-- CLEANUP — phantom "credit" signals from Meezan OUTGOING (debit) alerts,
--           plus false screenshot<->email pairings from the old loose matcher.
-- Date: July 13, 2026
--
-- WHY THIS IS SAFE
-- ----------------
-- The payment-signals feature NEVER writes to the ledger, order payments, or
-- account balances (see create_payment_signal_and_alias_jun2026.sql). It only
-- DECORATES the approval screens with proof badges. This script therefore
-- CANNOT move money — it only corrects those badges: it removes phantom credits
-- (your OWN outgoing Meezan transfers that were mis-read as customer payments)
-- and drops false "Verified" greens produced by pairing unrelated signals.
--
-- DEPLOY TOGETHER WITH the code changes (all three must ship, in any order):
--   * app/Services/Payments/Email/Parsers/MeezanCreditEmailParser.php  (stops NEW debits being ingested)
--   * app/Services/Payments/Signals/PaymentSignalMatcher.php           (pairing now needs same amount + same bank)
--   * app/Services/Payments/Signals/PaymentProofStatusService.php      (Verified now needs a genuine pair)
--
-- HOW TO RUN: LOCAL first — run the two preview SELECTs, eyeball them, then run
-- steps 1-4 — then repeat on PROD. Re-runnable; running twice is a harmless no-op.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- PREVIEW (optional, read-only) — what step 1-3 will neutralise:
-- ---------------------------------------------------------------------------
-- SELECT id, status, extracted_amount, extracted_sender_bank, paired_signal_id,
--        matched_order_id, match_reason, email_received_at
-- FROM   t_fin_payment_signal
-- WHERE  source = 'email'
--   AND  extraction_raw_text LIKE '%sent from your account%'
-- ORDER  BY id DESC;
--
-- PREVIEW — what step 4 will unpair (pairs whose two amounts disagree by >1.00):
-- SELECT a.id AS a_id, a.source AS a_src, a.extracted_amount AS a_amt,
--        b.id AS b_id, b.source AS b_src, b.extracted_amount AS b_amt,
--        ABS(a.extracted_amount - b.extracted_amount) AS diff
-- FROM   t_fin_payment_signal a
-- JOIN   t_fin_payment_signal b ON b.id = a.paired_signal_id
-- WHERE  a.extracted_amount IS NOT NULL AND b.extracted_amount IS NOT NULL
--   AND  ABS(a.extracted_amount - b.extracted_amount) > 1.00;

-- ---------------------------------------------------------------------------
-- 1) Unpair the WhatsApp partner welded onto each debit-email signal, so the
--    screenshot's order no longer reads "Verified" off a bogus credit.
-- ---------------------------------------------------------------------------
UPDATE t_fin_payment_signal wa
JOIN   t_fin_payment_signal em ON em.paired_signal_id = wa.id
SET    wa.paired_signal_id = NULL
WHERE  em.source = 'email'
  AND  em.extraction_raw_text LIKE '%sent from your account%';

-- ---------------------------------------------------------------------------
-- 2) Drop any combined-payment links owned by a debit-email signal (removes it
--    from every invoice it was spread across).
-- ---------------------------------------------------------------------------
DELETE l
FROM   t_fin_payment_signal_order l
JOIN   t_fin_payment_signal em ON em.id = l.signal_id
WHERE  em.source = 'email'
  AND  em.extraction_raw_text LIKE '%sent from your account%';

-- ---------------------------------------------------------------------------
-- 3) Neutralise the debit-email signals themselves: they are NOT customer
--    credits, so they must tag no order and drive no badge.
-- ---------------------------------------------------------------------------
UPDATE t_fin_payment_signal
SET    status = 'irrelevant',
       paired_signal_id = NULL,
       matched_order_id = NULL,
       match_reason = 'debit_not_credit'
WHERE  source = 'email'
  AND  extraction_raw_text LIKE '%sent from your account%';

-- ---------------------------------------------------------------------------
-- 4) Break any surviving false pair whose two sides disagree on amount by more
--    than 1.00 (the old matcher paired on the loose invoice tolerance). A real
--    pair is one transfer -> identical amounts to the rupee. Combined/bulk pairs
--    (same transfer, same amount) are NOT touched. Clears BOTH directions.
-- ---------------------------------------------------------------------------
UPDATE t_fin_payment_signal a
JOIN   t_fin_payment_signal b ON b.id = a.paired_signal_id
SET    a.paired_signal_id = NULL
WHERE  a.extracted_amount IS NOT NULL
  AND  b.extracted_amount IS NOT NULL
  AND  ABS(a.extracted_amount - b.extracted_amount) > 1.00;

-- ---------------------------------------------------------------------------
-- VERIFY (each should return 0):
-- ---------------------------------------------------------------------------
-- SELECT COUNT(*) AS debit_credits_left
-- FROM   t_fin_payment_signal
-- WHERE  source = 'email'
--   AND  extraction_raw_text LIKE '%sent from your account%'
--   AND  status <> 'irrelevant';
--
-- SELECT COUNT(*) AS mismatched_pairs_left
-- FROM   t_fin_payment_signal a
-- JOIN   t_fin_payment_signal b ON b.id = a.paired_signal_id
-- WHERE  a.extracted_amount IS NOT NULL AND b.extracted_amount IS NOT NULL
--   AND  ABS(a.extracted_amount - b.extracted_amount) > 1.00;
