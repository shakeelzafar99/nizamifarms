-- ============================================================================
-- Bank-SMS timestamp UTC -> PKT backfill (one-time, Jul-2026)
-- ============================================================================
--
-- WHY
--   AssistantSmsController::parseReceivedAt used Carbon::createFromTimestamp()
--   which returns UTC, while the whole app (now(), created_at, email/screenshot
--   times) runs in Asia/Karachi. So every existing bank-SMS timestamp is stored
--   5 hours BEHIND real Pakistan time. Symptoms: wrong times in the money
--   inbox, and the SMS losing closest-time pairing tie-breaks against emails.
--
-- WHAT THIS DOES
--   Shifts the existing (UTC) values +5h to Pakistan time, for BOTH the raw SMS
--   rows and the payment signals they produced.
--
-- ⚠️ RUN EXACTLY ONCE, and BEFORE uploading the fixed AssistantSmsController.php.
--   Order matters: this fixes OLD rows (all UTC because the code isn't live
--   yet); the code fix makes NEW rows correct. If you run this AFTER the code
--   is live, brand-new (already-correct) rows would be double-shifted. If in
--   doubt, run the CHECK query first — a correct row has sms_at within a few
--   minutes of created_at; a UTC row is ~5h behind it.
--
-- CHECK FIRST (should show a ~5h gap on existing rows — that's the bug):
--   SELECT id, sms_at, created_at, TIMESTAMPDIFF(MINUTE, sms_at, created_at) AS gap_min
--   FROM t_ai_bank_sms ORDER BY id DESC LIMIT 10;
-- ============================================================================

UPDATE t_ai_bank_sms
   SET sms_at = DATE_ADD(sms_at, INTERVAL 5 HOUR)
 WHERE sms_at IS NOT NULL;

UPDATE t_fin_payment_signal
   SET extracted_txn_datetime = DATE_ADD(extracted_txn_datetime, INTERVAL 5 HOUR)
 WHERE source = 'bank_sms'
   AND extracted_txn_datetime IS NOT NULL;

-- VERIFY (gap should now be near 0 for recent live rows):
--   SELECT id, sms_at, created_at, TIMESTAMPDIFF(MINUTE, sms_at, created_at) AS gap_min
--   FROM t_ai_bank_sms ORDER BY id DESC LIMIT 10;
