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
