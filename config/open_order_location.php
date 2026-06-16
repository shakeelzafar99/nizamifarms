<?php

return [
    // Master switch for the Open Orders "Get Customer Locations" feature.
    'enabled' => env('OPEN_ORDER_LOC_ENABLED', true),

    // WhatsApp template name(s) used to REQUEST a location for regular (non-qurbani)
    // open orders. The inbound auto-save only fires when the customer's most recent
    // location-request send used one of these — this is what keeps the open-orders
    // flow cleanly separated from the qurbani flow ('qurbani_location').
    'request_templates' => ['location', 'location_request'],

    // Default template pre-selected in the picker (staff can change it in the modal).
    'default_template' => env('OPEN_ORDER_LOC_TEMPLATE', 'location'),
    'default_language' => env('OPEN_ORDER_LOC_TEMPLATE_LANG', 'en'),

    // Pacing between individual sends (microseconds). 200000 = 200ms (~5/sec).
    'send_pacing_us' => (int) env('OPEN_ORDER_LOC_PACING_US', 200000),

    // The browser sends the selected customers to the server in chunks of this size
    // so no single request runs too long on shared hosting.
    'chunk_size' => (int) env('OPEN_ORDER_LOC_CHUNK', 20),

    // Customers messaged within this many hours are de-selected by default in the modal.
    'recently_messaged_hours' => (int) env('OPEN_ORDER_LOC_RECENT_HOURS', 24),

    // When a customer replies with a pin, only auto-save it if a location request
    // was sent to them within this window (days). Stops random/old pins from saving.
    'autosave_window_days' => (int) env('OPEN_ORDER_LOC_AUTOSAVE_DAYS', 14),

    // The "recently auto-saved" results bucket shows pins saved within this window (days).
    'resolved_recent_days' => (int) env('OPEN_ORDER_LOC_RESOLVED_DAYS', 7),

    // Auto-save resolved pins to the customer (keeping a visible audit via timestamps).
    'auto_save_on_reply' => env('OPEN_ORDER_LOC_AUTOSAVE', true),
];
