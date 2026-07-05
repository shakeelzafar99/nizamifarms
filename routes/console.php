<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Delete old meter pictures (older than 60 days) - runs daily at 2 AM
Schedule::command('attendance:delete-old-meter-pictures')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        \Log::info('Old meter pictures cleanup completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Old meter pictures cleanup failed');
    });

// Phase 3 (May-2026) — Qurbani auto-WhatsApp messages.
// Cron-fallback runner. The worker is normally fired by the
// manager-poll terminating() hook, but on quiet hours (e.g. very
// early morning, before any manager opens the planner) we'd miss
// slaughter-delay-triggered sends. This every-minute scheduler
// guarantees a heartbeat. The worker itself uses a 55s Cache lock
// so this cron + any incidental terminating() poll never
// double-fire — at most one effective run per ~minute globally.
//
// Self-throttles to a no-op when the master switch is off, so
// outside the event day this costs ~1 SQL config lookup per minute.
Schedule::command('qurbani:wa-process')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Phase 6 (May-2026) — Qurbani location-request safety-net worker.
// The bulk-send UI normally drives the queue inline (the browser
// polls /bulk/{batchId}/start in chunks while staff watches the
// progress bar). This cron is the safety net: it picks up
// orphaned queued rows when staff close the tab mid-send, and
// recovers stuck "sending" rows that have been in-flight longer
// than 5 minutes. Uses a 55s Cache lock so the cron + any
// concurrent HTTP-driven drain never double-fire on the same row.
Schedule::command('qurbani:loc-request-process')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Phase 1 (May-2026) — Customer-app order-status webhook dispatcher.
// Drains rows from t_app_webhook_events (the outbox written by
// CustomerAppWebhookEmitter inside OrderModel::changeStatus). Each
// pending or due-for-retry row is signed with HMAC-SHA256 and POSTed
// to config('customer_app.url'). On 2xx the row is marked sent; on
// any other outcome the row is rescheduled with exponential backoff,
// or flipped to status='dead' once retries are exhausted.
//
// Self-throttles to a no-op when the master switch is off (or when
// URL/secret are missing), so it costs only a tiny config lookup
// before the integration is configured in production.
Schedule::command('app:dispatch-customer-webhooks')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Jun-2026 — Online payment auto-matching.
//
// 1. Read pending WhatsApp bank screenshots through Gemini Vision and match
//    them to pending online orders. The webhook only inserts a cheap signal
//    row; this command does the (paid) image read off the request thread.
// 2. Poll the support@ mailbox for new bank credit-confirmation emails and
//    match those too.
//
// Both self-throttle to a near no-op (one config read) when
// config('payment_signals.enabled') is false or the relevant credentials are
// missing, so they cost almost nothing before the feature is configured.
//
// Jul-2026 — route the cron THROUGH PaymentSignalAutoRunner (via the thin
// payments:auto-tick command) instead of running the two worker commands
// directly. The workers select pending signals oldest-first with no per-row
// lock of their own, so a cron run firing at the same second as an in-flight
// request drain (WhatsApp webhook / store unread-count poll) could read the
// SAME screenshots twice — duplicate (paid) Gemini calls and a second IMAP
// connect per minute. processNow() owns the shared 55s Cache lock, so at most
// one effective run per ~minute happens globally; a locked tick is a cheap
// no-op. runInBackground keeps a slow Gemini batch from delaying the other
// every-minute tasks in the same schedule:run.
Schedule::command('payments:auto-tick')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Jun-2026 — Auto location-request drain worker.
// Flushes t_crm_location_auto_queue (rows written when a Shopify order is
// accepted or a new NF order is created). Self-throttles to a no-op when the
// master switch (location_auto_send_enabled) is off OR when outside the daily
// send window, so it costs ~1-2 config lookups per minute before/while disabled.
// Orders queued outside the window are flushed automatically when it next opens.
Schedule::command('location:auto-process')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->runInBackground();

// Rider Reports are computed REAL-TIME on open (RiderReportsController) — no
// scheduled job, no stored tables, so there is no staleness to reconcile.
