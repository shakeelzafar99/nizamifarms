-- ============================================================================
-- Apr-2026 — One-time inbox cleanup: mark every conversation whose last
-- activity was more than 3 days ago as "read by everyone".
--
-- Why: the WhatsApp badge / Unread filter were inflated by a long tail of
-- one-message threads from before staff started replying systematically.
-- We want a fresh starting point. Going forward, the per-user / global-read
-- logic in WhatsAppWebController::getUnreadCount keeps the count honest.
--
-- How: t_wa_conversations.global_read_at is the super-reader marker. The
-- badge & unread-filter queries already exclude every message whose
-- created_at <= c.global_read_at, so setting global_read_at = NOW() on a
-- conversation hides every message in that thread that exists *as of now*.
-- Any inbound message that arrives after this script runs will land with
-- created_at > global_read_at and will correctly count as unread again.
--
-- Idempotency: the WHERE clause skips rows whose global_read_at is already
-- newer than last_message_at — re-running only ever moves the marker
-- forward in time, never backwards, so it's safe to re-run on a whim.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- STEP 1 — PREVIEW: how many conversations will flip, and what's the time
--          range. Run this on its own and eyeball the output.
-- ----------------------------------------------------------------------------
SELECT
    COUNT(*)              AS rows_to_update,
    MIN(last_message_at)  AS oldest_last_message,
    MAX(last_message_at)  AS newest_last_message
FROM t_wa_conversations
WHERE last_message_at IS NOT NULL
  AND last_message_at < (NOW() - INTERVAL 3 DAY)
  AND (global_read_at IS NULL OR global_read_at < last_message_at);

-- Optional drill-down — sample the 50 oldest conversations that are about
-- to be marked, in case you want to spot-check that nothing important is
-- still genuinely waiting on a reply.
SELECT
    c.id,
    c.wa_phone,
    c.wa_contact_name,
    CONCAT_WS(' ', cust.first_name, cust.last_name) AS customer_name,
    c.last_message_at,
    c.global_read_at
FROM t_wa_conversations c
LEFT JOIN t_crm_prod_customer cust ON cust.id = c.customer_id
WHERE c.last_message_at IS NOT NULL
  AND c.last_message_at < (NOW() - INTERVAL 3 DAY)
  AND (c.global_read_at IS NULL OR c.global_read_at < c.last_message_at)
ORDER BY c.last_message_at ASC
LIMIT 50;

-- ----------------------------------------------------------------------------
-- STEP 2 — APPLY. Only run after STEP 1 looks reasonable.
--
-- We zero unread_count too (the legacy column is dormant once the per-user
-- reads table exists, but we keep it consistent so any older codepath
-- still reading it returns the same answer).
-- ----------------------------------------------------------------------------
UPDATE t_wa_conversations
SET global_read_at = NOW(),
    unread_count   = 0
WHERE last_message_at IS NOT NULL
  AND last_message_at < (NOW() - INTERVAL 3 DAY)
  AND (global_read_at IS NULL OR global_read_at < last_message_at);

-- ----------------------------------------------------------------------------
-- STEP 3 — VERIFY. Mirrors the badge query's logic minus the per-user
--          last_read_at filter, so this is a *lower bound* on what each
--          user's badge will read after the next poll. The actual badge
--          for a specific user can only be lower (because their personal
--          last_read_at marker hides additional messages on top).
--
--          Anything you see here is a thread that's had inbound activity
--          in the last 3 days AND has no outbound reply after that
--          inbound — i.e. a real "needs attention" thread.
-- ----------------------------------------------------------------------------
SELECT COUNT(DISTINCT m.conversation_id) AS still_unread_conversations
FROM t_wa_messages m
LEFT JOIN t_wa_conversations c ON c.id = m.conversation_id
WHERE m.direction = 'inbound'
  AND (c.global_read_at IS NULL OR m.created_at > c.global_read_at)
  AND NOT EXISTS (
      SELECT 1
      FROM t_wa_messages m2
      WHERE m2.conversation_id = m.conversation_id
        AND m2.direction = 'outbound'
        AND m2.created_at > m.created_at
  );
