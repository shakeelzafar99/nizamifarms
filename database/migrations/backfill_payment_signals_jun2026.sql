-- =====================================================
-- ONE-TIME BACKFILL: create missing WhatsApp payment-signal rows
-- Date: June 18, 2026
--
-- WHY
-- ---
-- Some inbound WhatsApp images never got a t_fin_payment_signal row because
-- the webhook pre-filter required the customer's order to be tagged
-- payment_method online/bank_transfer. Many delivered orders are created as
-- 'cash' and paid online afterwards, so those screenshots were skipped.
--
-- The code fix removes that requirement going forward. This script backfills
-- the rows that were missed so they get picked up and matched too.
--
-- WHAT IT DOES
-- ------------
-- Inserts a 'new' signal row for every inbound IMAGE message (today) that:
--   * has a downloaded media file (image_path),
--   * belongs to a conversation mapped to a customer,
--   * whose customer has an unpaid/partial, non-cancelled order in the last
--     30 days (ANY payment_method — the screenshot itself is the proof),
--   * and does NOT already have a signal row.
--
-- It writes ONLY to t_fin_payment_signal (status='new'). The existing worker
-- (payments:process-signals) then reads each through Gemini and matches it —
-- exactly as if the image had just arrived. Nothing here touches money/ledger.
--
-- SAFE TO RE-RUN: the NOT EXISTS guard + the uq_wa_message unique key prevent
-- duplicates. Widen the date by changing CURDATE() to e.g. (NOW() - INTERVAL 3 DAY).
--
-- AFTER RUNNING:
--   SELECT id, status, matched_order_id FROM t_fin_payment_signal
--   WHERE source='whatsapp' ORDER BY id DESC LIMIT 20;
--   (rows start as 'new', then flip to matched / amount_mismatch / irrelevant)
-- =====================================================

INSERT INTO `t_fin_payment_signal`
    (`source`, `wa_message_id`, `wa_conversation_id`, `image_path`, `status`, `created_at`, `updated_at`)
SELECT
    'whatsapp',
    m.id,
    m.conversation_id,
    m.media_url,
    'new',
    NOW(),
    NOW()
FROM `t_wa_messages` m
JOIN `t_wa_conversations` c ON c.id = m.conversation_id
WHERE m.direction = 'inbound'
  AND (m.type = 'image' OR (m.type = 'document' AND m.media_mime_type LIKE 'image/%'))
  AND m.media_url IS NOT NULL
  AND c.customer_id IS NOT NULL
  AND m.created_at >= CURDATE()              -- today only; widen if needed
  AND NOT EXISTS (
        SELECT 1 FROM `t_fin_payment_signal` s
        WHERE s.wa_message_id = m.id
  )
  AND EXISTS (
        SELECT 1 FROM `t_crm_prod_order` o
        WHERE o.customer_id = c.customer_id
          AND o.payment_status IN ('unpaid', 'partial')
          AND o.order_status <> 'cancelled'
          AND o.order_date >= (NOW() - INTERVAL 30 DAY)
  );
