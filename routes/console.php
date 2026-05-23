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
