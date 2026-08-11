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

    /*
    |--------------------------------------------------------------------------
    | Hold-until-dispatch substitute status
    |--------------------------------------------------------------------------
    |
    | A status flagged `customer_app_hold_until_dispatch` in the Status Hub is
    | not shown to the customer until the order is really dispatched (an ETA
    | has been calculated). Until then the customer app is told THIS value
    | instead.
    |
    | Why it exists: ops sets `out_for_delivery` early, while the order is
    | still being loaded at the store. Announcing that immediately gave the
    | customer a live map of a rider parked at the shop and no ETA. Now they
    | keep seeing `processing` until Dispatch is pressed, at which point NF
    | pushes the real status WITH the delivery window.
    |
    | Must be a value the customer app already understands (see §4.1 of
    | CUSTOMER_APP_INTEGRATION.md) — `processing` maps to their "preparing"
    | stage. It is passed through the alias map like any other status, so
    | aliasing `processing` later keeps working.
    |
    */
    'hold_until_dispatch_status' => env('CUSTOMER_APP_HOLD_UNTIL_DISPATCH_STATUS', 'processing'),

    /*
    |==========================================================================
    | PHASE 2 — ETA window + live rider tracking
    |==========================================================================
    |
    | Everything below is additive and safe to ship before the customer-app
    | backend consumes it. The ETA window rides as an extra optional field on
    | the existing status webhook (their parser ignores unknown fields). The
    | tracking endpoint is a passive pull API on our side. The ONLY thing that
    | actively pushes new data to them is `emit_eta_updates`, which is OFF by
    | default so it cannot disturb Phase 1 status testing.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | ETA window (the coarse, human "expectedDeliveryWindow")
    |--------------------------------------------------------------------------
    |
    | When we know an order's Google-route ETA (estimated_delivery_at), we
    | turn it into a short, already-formatted display string the customer app
    | shows in the status pill, e.g. "Today, 4:00-4:30 PM". This is a coarse
    | *promise* window, distinct from the precise live `eta` on the tracking
    | feed.
    |
    | band_minutes:     width of the window built around the point ETA.
    |                    Default 30 minutes.
    | round_to_minutes: the start is floored to the nearest this-many minutes
    |                    (NOT to the band). Default 10, so an ETA of 11:57
    |                    floors to 11:50 and the window is "11:50 AM-12:20 PM"
    |                    (start 11:50 + 30-min band). An ETA of 4:12 -> "4:10-4:40 PM".
    |
    */
    'eta_window_enabled'        => env('CUSTOMER_APP_ETA_WINDOW_ENABLED', true),
    'eta_window_band_minutes'   => env('CUSTOMER_APP_ETA_WINDOW_BAND_MINUTES', 30),
    'eta_window_round_to_minutes' => env('CUSTOMER_APP_ETA_WINDOW_ROUND_TO_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | order.eta_updated push (DEFAULT OFF)
    |--------------------------------------------------------------------------
    |
    | When true, recalculating an order's ETA (RiderController::
    | calculateDeliveryEtas) queues an `order.eta_updated` webhook so the
    | customer app can refresh the delivery window without us touching the
    | status timeline.
    |
    | Keep this FALSE until the customer-app backend explicitly branches on
    | event_type === 'order.eta_updated'. If they don't, their generic
    | status handler could append a duplicate timeline row. Flip to true
    | only after they confirm they handle it.
    |
    */
    'emit_eta_updates' => env('CUSTOMER_APP_EMIT_ETA_UPDATES', false),

    /*
    |--------------------------------------------------------------------------
    | Inbound auth — the live-tracking pull endpoint
    |--------------------------------------------------------------------------
    |
    | The customer-app backend calls
    |   GET /api/customer-app/orders/{orderNumber}/tracking
    | server-to-server with `Authorization: Bearer <token>`. This token is a
    | separate secret from the webhook signing secret, so the signing secret
    | is never sent in a header. Set it in .env on both sides.
    |
    */
    'inbound_token' => env('CUSTOMER_APP_INBOUND_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Tracking feed freshness
    |--------------------------------------------------------------------------
    |
    | A rider GPS fix older than this many minutes is treated as "no live
    | fix" — the tracking endpoint returns { "tracking": null } so the app
    | falls back to the timeline instead of drawing a stale marker.
    |
    */
    'tracking_gps_staleness_minutes' => env('CUSTOMER_APP_TRACKING_STALENESS', 30),

];
