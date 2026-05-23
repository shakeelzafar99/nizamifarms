-- =============================================================
-- Qurbani Location Requests — May-2026
-- =============================================================
-- New operational table that backs the "Request Location" feature
-- on the Qurbani Orders page (web + mobile).
--
-- One row per outbound WhatsApp `qurbani_location` template send.
-- Tracks the full lifecycle:
--   queued → sending → sent → (replied) → saved_to_customer
--
-- Linked back to the inbound WhatsApp location reply via
-- `sent_wa_message_id` (= Meta's `context.id` on the reply payload).
-- Falls back to (wa_phone + recent send window) when context.id is
-- missing from the reply.
--
-- Save flow is INTENTIONALLY two-step:
--   1. Inbound location reply → row gets `replied_*` fields set.
--   2. Staff reviews in the Reviewer drawer and clicks Save (or
--      Save-All) → row's `saved_to_customer=1` AND the customer's
--      `t_crm_prod_customer.latitude/longitude/verified_location_*`
--      fields are updated via QurbaniLocationRequestService::
--      saveToCustomer(). Auto-save is OFF by default (see
--      config/qurbani.php → location_request.auto_save_on_reply).
--
-- Safety guarantees enforced by the service layer (not the DB):
--   • saveToCustomer() ALWAYS uses the request row's customer_id —
--     not the conversation's resolved customer_id — to avoid
--     accidentally writing one customer's pin onto another.
--   • If the target customer already has a `verified_location_saved_at`
--     newer than the request's `sent_at`, the save is SKIPPED (with
--     audit note) so the newer manual pin is never clobbered.
--
-- Nothing in t_crm_prod_customer or t_wa_messages is modified by
-- this migration — those flows continue exactly as before.
-- =============================================================

CREATE TABLE IF NOT EXISTS t_crm_qurbani_loc_request (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- WHO we're requesting the location FOR.
    -- order_id is the qurbani order tying this request to a specific
    -- order (NULL when the request was generated against a customer
    -- standalone — currently always set, kept NULLable for forward-
    -- compat with future use cases).
    customer_id         INT UNSIGNED NOT NULL,
    order_id            INT UNSIGNED NULL,
    line_item_id        BIGINT UNSIGNED NULL,
    wa_phone            VARCHAR(20) NOT NULL,   -- normalised to 92XXXXXXXXXX

    -- Filter context — denormalised so we can show a per-batch
    -- breakdown ("Bahria Phase 8 / Day 1 / 11-3 PM: 47 sent, 19
    -- replied, 17 saved") without re-joining to the order/line item
    -- tables (which may have been updated since the request was sent).
    qurbani_day         VARCHAR(50)  NULL,
    qurbani_slot        VARCHAR(50)  NULL,
    qurbani_region      VARCHAR(100) NULL,
    qurbani_sub_region  VARCHAR(100) NULL,
    qurbani_delivery_type VARCHAR(50) NULL,
    category_level_2    VARCHAR(100) NULL,

    -- Bulk batch grouping.
    -- batch_id   = UUID assigned when staff hits "Send Bulk" — NULL
    --              when the request was an individual one-off send
    --              from a card-level button.
    -- batch_label = human-readable summary so the Reviewer drawer can
    --               group rows by batch ("Bulk: Bahria Phase 8 — Day 1").
    batch_id            CHAR(36)     NULL,
    batch_label         VARCHAR(120) NULL,

    -- Send lifecycle.
    -- status flow: queued → sending → (sent | failed | skipped).
    -- sending is a transient state held only while the WhatsApp API
    -- call is in flight, so the worker can mark a row "in progress"
    -- and another worker won't grab it again.
    status              ENUM('queued','sending','sent','failed','skipped')
                        NOT NULL DEFAULT 'queued',
    queued_at           TIMESTAMP NULL,
    sent_at             TIMESTAMP NULL,
    -- Meta's `messages[0].id` from the send response, e.g.
    -- "wamid.HBgM...". This is the key that matches `context.id` on
    -- the customer's reply, so recordReply() can link it back.
    sent_wa_message_id  VARCHAR(120) NULL,
    sent_by             INT NULL,    -- t_sys_user.id of staff who triggered (NULL for cron worker)
    error_code          VARCHAR(64)  NULL,
    error_message       VARCHAR(500) NULL,

    -- Reply tracking — populated by WhatsAppService::handleIncomingMessage
    -- via QurbaniLocationRequestService::recordReply().
    replied_at          TIMESTAMP NULL,
    reply_wa_message_id VARCHAR(120) NULL,
    reply_latitude      DECIMAL(10,7) NULL,
    reply_longitude     DECIMAL(10,7) NULL,
    reply_name          VARCHAR(255) NULL,    -- WhatsApp location.name
    reply_address       VARCHAR(500) NULL,    -- WhatsApp location.address

    -- Save lifecycle (the second half of the two-step).
    -- save_skipped_reason is used when saveToCustomer() decides not
    -- to overwrite (e.g. customer already has a newer manual pin);
    -- the row still flips to saved_to_customer=1 to clear the
    -- reviewer queue, with the reason recorded for audit.
    saved_to_customer    TINYINT(1) NOT NULL DEFAULT 0,
    saved_at             TIMESTAMP NULL,
    saved_by             INT NULL,
    save_skipped_reason  VARCHAR(255) NULL,

    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indices tuned for the queries the service runs hot:
    INDEX idx_qlr_status        (status, queued_at),
    INDEX idx_qlr_batch         (batch_id),
    INDEX idx_qlr_customer      (customer_id, sent_at),
    INDEX idx_qlr_wa_msg        (sent_wa_message_id),  -- webhook lookup key
    INDEX idx_qlr_phone_recent  (wa_phone, sent_at),   -- fallback match
    INDEX idx_qlr_pending_save  (saved_to_customer, replied_at),
    INDEX idx_qlr_line_item     (line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- Optional: seed t_wa_templates so the qurbani_location template
-- shows up in the existing template manager / Messages UI.
-- Skip this INSERT if you'd rather register it manually through
-- the Messages → Templates admin screen.
-- =============================================================
-- NOTE: The actual approval still has to happen in Meta Business
-- Manager — this row just makes Laravel aware of the template name
-- so the Messages page picker can offer it. Replace the body_text
-- with the exact wording you submit to Meta for approval.
INSERT IGNORE INTO t_wa_templates (name, language, category, body_text, status, created_at, updated_at)
VALUES (
    'qurbani_location',
    'en',
    'utility',
    'Assalam o Alaikum {{1}}, please share your delivery location for your upcoming Qurbani order. Tap 📎 → Location and send your pin. — Nizami Farms',
    'approved',
    NOW(),
    NOW()
);
