<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Qurbani Location Request — WhatsApp template-driven location collection
    |--------------------------------------------------------------------------
    |
    | Backs the "Request Location" feature on the Qurbani Orders page (web +
    | mobile). See QurbaniLocationRequestService for the full pipeline.
    |
    */
    'location_request' => [
        // WhatsApp template name (must exist in Meta Business Manager and
        // — for the Messages UI to surface it — in t_wa_templates too).
        // The template body MUST take exactly ONE `{{1}}` body parameter,
        // which the service fills with the customer's display name.
        'template_name' => env('QURBANI_LOC_REQ_TEMPLATE', 'qurbani_location'),
        'template_language' => env('QURBANI_LOC_REQ_TEMPLATE_LANG', 'en'),

        // When an inbound location reply matches a request row (via
        // Meta context.id, or fallback by phone+recent), should we also
        // immediately write the lat/lng onto t_crm_prod_customer?
        // Default OFF — staff review and bulk-save from the Reviewer
        // drawer so we never push the wrong pin to the wrong customer
        // unattended.
        'auto_save_on_reply' => env('QURBANI_LOC_REQ_AUTO_SAVE', false),

        // Fallback match window — when the inbound reply has no
        // context.id (customer typed location without quoting the
        // template), the service looks for the most recent `sent` row
        // for that phone within this many days.
        'fallback_match_days' => (int) env('QURBANI_LOC_REQ_FALLBACK_DAYS', 7),

        // Hybrid worker tuning (see QurbaniLocationRequestProcess).
        // The synchronous /bulk/{batchId}/start endpoint drains up to
        // SYNC_HTTP_CAP rows per HTTP call so we never blow the PHP
        // request timeout. The cron worker drains up to CRON_MAX rows
        // per minute as a safety net for orphaned queues.
        'sync_http_cap' => (int) env('QURBANI_LOC_REQ_SYNC_HTTP_CAP', 100),
        'cron_max_per_run' => (int) env('QURBANI_LOC_REQ_CRON_MAX', 25),

        // Per-send pacing between WhatsApp API calls inside the worker
        // loop (microseconds). 200_000 µs = 200ms = ~5 sends/sec,
        // matching the Campaigns module's existing rhythm so we stay
        // gentle on Meta's quality rating.
        'send_pacing_us' => (int) env('QURBANI_LOC_REQ_PACING_US', 200000),

        // Circuit breaker — if we hit N consecutive Meta rate-limit /
        // throttle errors inside one batch, pause for COOLDOWN_S
        // seconds before retrying. Prevents a runaway bad batch from
        // hammering Meta and tanking our sender quality.
        'rate_limit_breaker_after' => (int) env('QURBANI_LOC_REQ_BREAKER_AFTER', 4),
        'rate_limit_cooldown_s' => (int) env('QURBANI_LOC_REQ_COOLDOWN_S', 300),

        // Safety on the save step. If the target customer already has
        // a verified_location_saved_at NEWER than the request's
        // sent_at, the save is SKIPPED — the staff's freshly-pinned
        // location wins over an older WhatsApp reply.
        'skip_save_if_newer_pin_exists' => true,
    ],
];
