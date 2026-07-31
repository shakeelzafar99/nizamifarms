-- =============================================================================
-- Register the `app_launch_template` WhatsApp template in NF — Jul 2026
--
-- The template already exists and is APPROVED in Meta (WhatsApp Manager shows
-- "Active - Quality pending", which means sendable — the quality rating just
-- hasn't been established yet). Meta owns the real content; this table is NF's
-- local registry that drives the template PICKERS and, importantly,
-- `variable_count`, which the send code uses to size the body parameters.
--
-- Run on LOCAL then PROD. Idempotent (ON DUPLICATE KEY on the unique `name`).
--
-- ⚠ READ THIS BEFORE USING IT — the template has an IMAGE HEADER.
--   Read back from Meta's Graph API, this template's header is:
--       component HEADER format=IMAGE  example={"header_handle":[...]}
--   The image sits under `example` — it is the SAMPLE used for review, not a
--   fixed asset baked into the template. Meta requires the actual image as a
--   header PARAMETER on every send. VERIFIED by a live test send Jul-2026:
--   without the header Meta returns 132012 "Parameter format does not match
--   format in the created template" and delivers nothing; with it, the message
--   goes through. NF now attaches it automatically (see §0 + WhatsAppService).
--
--   HANDLED — no action needed when sending. `WhatsAppService::headerParamsForTemplate()`
--   reads `header_media_path` (§0), uploads the file to Meta ONCE, caches the
--   media_id for 20 days and attaches it to every send. Wired into the campaign
--   sender and the chat window, so both surfaces work. A 2,000-message campaign
--   costs one upload, not 2,000.
--
--   Attached by MEDIA_ID, never by link: Meta's fetch of a /public-storage link
--   403s on this host (error 131053) — see
--   WhatsAppWebController::buildInvoiceHeaderForSend. If a cached id ever goes
--   stale, the sender drops it on a media error and the next send re-uploads.
--
--   Both BUTTONS are static URL buttons (Play Store / App Store) and need no
--   send-time parameters — the media header was the only blocker.
-- =============================================================================

-- =========================================================================
-- §0  Header media support (general, not specific to this template)
-- =========================================================================
-- A template whose header is IMAGE/VIDEO/DOCUMENT must carry that media on
-- EVERY send. This column names the local file to attach, relative to
-- storage/app/. NULL (the default, and every existing row) = no media header,
-- so nothing changes for any template already in use.
--
-- The file is uploaded to Meta once and the returned media_id is cached and
-- reused for the whole batch — the image is identical for every recipient, so
-- there is no need to re-upload per message.
ALTER TABLE `t_wa_templates`
ADD COLUMN IF NOT EXISTS `header_media_path` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Local file (relative to storage/app/) to attach as this template''s media header. NULL = no media header. Uploaded to Meta once; the media_id is cached and reused.'
AFTER `header_text`;


-- =========================================================================
-- §1  The template row
-- =========================================================================
INSERT INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`,
     `header_text`, `header_media_path`, `body_text`, `footer_text`,
     `variable_count`, `has_buttons`, `button_labels`,
     `show_in`, `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`,
     `status`, `created_at`, `updated_at`)
VALUES
    ('app_launch_template',
     'App launch',
     'marketing',
     'en',
     NULL,                       -- header is an IMAGE, not text
     -- The approved header image, pulled from Meta's own header_handle so it is
     -- byte-identical to what was reviewed. Lives in storage/app/.
     'wa-template-headers/app_launch_template.png',
     -- Body copied verbatim from Meta (read back from the Graph API), so the
     -- picker preview matches what the customer actually receives.
     'Ordering just got faster.\n\nThe Nizami Farms app is live. Your past orders, every cut, and live delivery tracking in one place.\n\nSame butchers. Same premium quality. Same cash on delivery. Just fewer messages.\n\nTap below to download.',
     'Available on iOS and Android',
     -- Confirmed against Meta: the BODY component has NO `example`, i.e. zero
     -- {{n}} placeholders. This MUST match Meta: the sender pads/trims body
     -- params to this number and a mismatch is an instant rejection (132012).
     0,
     1,                          -- two buttons (see below)
     -- Both buttons are STATIC URL buttons (Play Store / App Store links), so
     -- they need no per-send parameters — only a media header does.
     '["Get it on Android","Get it on iPhone"]',
     -- Owner's requirement: keep it out of the everyday template pickers and
     -- offer it only in Qurbani mode. `is_qurbani_only = 1` is what enforces
     -- that (WhatsAppWebController hides qurbani-only templates from every
     -- non-Qurbani picker); show_in lists the Qurbani contexts.
     'qurbani_invoice,qurbani_orders',
     0,                          -- never a default
     1,                          -- active
     1,                          -- QURBANI-ONLY  <- hides it from other pickers
     0,
     'approved',
     NOW(), NOW())
ON DUPLICATE KEY UPDATE
    display_name      = VALUES(display_name),
    category          = VALUES(category),
    language          = VALUES(language),
    header_media_path = VALUES(header_media_path),
    footer_text       = VALUES(footer_text),
    button_labels     = VALUES(button_labels),
    body_text       = VALUES(body_text),
    variable_count  = VALUES(variable_count),
    has_buttons     = VALUES(has_buttons),
    show_in         = VALUES(show_in),
    is_active       = VALUES(is_active),
    is_qurbani_only = VALUES(is_qurbani_only),
    status          = VALUES(status),
    updated_at      = NOW();


-- =========================================================================
-- VERIFICATION
-- =========================================================================
-- SELECT name, display_name, category, variable_count, has_buttons,
--        show_in, is_active, is_qurbani_only, status
-- FROM t_wa_templates WHERE name = 'app_launch_template';
--
-- Expect: category=marketing, variable_count=0, is_qurbani_only=1, status=approved.
--
-- NOTE on where it will appear:
--   Chat / orders / customers / invoice pickers .. HIDDEN (is_qurbani_only = 1)
--   Qurbani-mode pickers ....................... shown
--   Campaigns template picker .................. SHOWN — that picker filters on
--       status='approved' only and ignores is_active / show_in / is_qurbani_only.
--       Convenient if you want it for the app-install campaign, but be aware it
--       is not the qurbani-only flag doing that.
