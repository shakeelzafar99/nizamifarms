<?php

/*
|--------------------------------------------------------------------------
| Customer App Integration (Phase 1 — outbound order-status webhooks)
|--------------------------------------------------------------------------
|
| This config drives the outbound webhook that NF sends to the
| Vercel-hosted customer-app backend whenever an SH- order's status
| changes. The flow is:
|
|     OrderModel::changeStatus()
|         -> CustomerAppWebhookEmitter::emitStatusChange()
|         -> INSERT into t_app_webhook_events (outbox)
|
|     [scheduler, every minute]
|         -> php artisan app:dispatch-customer-webhooks
|         -> picks up due rows
|         -> signs body with HMAC-SHA256
|         -> POSTs to customer_app.url
|         -> marks sent or schedules retry
|
| Real values for `url` and `secret` go in .env only and are never
| committed.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false the emitter still inserts outbox rows but the dispatcher
    | command becomes a no-op. Use this for safe staging dry-runs (you can
    | inspect the would-be-sent payloads in t_app_webhook_events without
    | actually hitting the customer app).
    |
    */
    'enabled' => env('CUSTOMER_APP_WEBHOOKS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Customer-app webhook endpoint
    |--------------------------------------------------------------------------
    |
    | The Vercel backend exposes a single URL that accepts our signed
    | POSTs. See CUSTOMER_APP_INTEGRATION.md for the contract.
    |
    */
    'url'    => env('CUSTOMER_APP_WEBHOOK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Shared HMAC secret
    |--------------------------------------------------------------------------
    |
    | 32+ random bytes. Used by both sides to sign / verify request
    | bodies (header X-NF-Signature). Rotate by setting both old and new
    | on Vercel temporarily, swapping NF, then removing the old value
    | from Vercel.
    |
    */
    'secret' => env('CUSTOMER_APP_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    */
    'timeout_seconds'         => env('CUSTOMER_APP_WEBHOOK_TIMEOUT', 10),
    'connect_timeout_seconds' => env('CUSTOMER_APP_WEBHOOK_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Dispatcher batching
    |--------------------------------------------------------------------------
    |
    | Maximum number of pending events drained per scheduler tick. Keeps
    | a single tick bounded so a backlog can't exceed our minute-window.
    |
    */
    'batch_size' => env('CUSTOMER_APP_WEBHOOK_BATCH', 50),

    /*
    |--------------------------------------------------------------------------
    | Retry backoff
    |--------------------------------------------------------------------------
    |
    | Minutes from now to wait before re-attempting after each failed
    | delivery. Once attempts exceed this list's length the row is
    | flipped to status=dead and surfaces in logs.
    |
    | Default schedule: 1m -> 5m -> 30m -> 2h -> 12h -> 1d -> dead.
    |
    */
    'retry_minutes' => [1, 5, 30, 120, 720, 1440],

    /*
    |--------------------------------------------------------------------------
    | Order-number prefix filter
    |--------------------------------------------------------------------------
    |
    | Phase 1 only pushes events for orders whose order_number starts
    | with 'SH-'. Manual NF orders, Qurbani orders, and pre-conversion
    | Shopify staging records are silently skipped. Adding a future
    | phase = appending another prefix here.
    |
    */
    'order_prefixes' => ['SH-'],

    /*
    |--------------------------------------------------------------------------
    | Order-number stripping for the customer-app payload
    |--------------------------------------------------------------------------
    |
    | The Vercel customer app uses Shopify's bare order number (e.g.
    | "1234"), not NF's prefixed "SH-1234". The emitter strips this
    | prefix before placing the value into payload.data.order_number.
    | NF's full prefixed number is also sent as `nf_order_number` for
    | support correlation, so no information is lost.
    |
    */
    'strip_prefix_for_payload' => 'SH-',

    /*
    |--------------------------------------------------------------------------
    | First-event override
    |--------------------------------------------------------------------------
    |
    | The very first webhook we ever send for a given SH- order is
    | rewritten to status='accepted'. Every subsequent event passes
    | NF's raw order_status verbatim.
    |
    | Why: from the customer's UX perspective, "we accepted your
    | Shopify order" is the meaningful event — it doesn't matter that
    | NF internally calls that status 'new'. After acceptance the
    | customer app just mirrors whatever NF reports.
    |
    */
    'first_event_status_override' => 'accepted',

];
