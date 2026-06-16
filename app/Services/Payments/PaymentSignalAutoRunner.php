<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Drives the online-payment auto-matching workers WITHOUT relying on a
 * `* * * * * schedule:run` cron — because the shared stackcp host can't be
 * counted on to run one. Mirrors the QurbaniWaAutoSender / CustomerAppWebhook
 * pattern the app already depends on:
 *
 *   • fireFromRequest() registers an app()->terminating() callback so the
 *     work runs AFTER the HTTP response is flushed (zero added latency).
 *   • A 55s Cache::add lock coalesces every poke (WhatsApp webhook, the
 *     15s store unread-count poll, the scheduler fallback) down to at most
 *     one effective run per ~minute.
 *
 * What it runs each tick:
 *   1. payments:process-signals  — read pending WhatsApp screenshots (Gemini)
 *                                   and match them.
 *   2. payments:poll-bank-emails — poll the mailbox for new bank credit emails
 *                                   and match them.
 *
 * Heartbeat cache keys (surfaced on the /admin/payments/imap-check page so the
 * operator can confirm the worker is alive without a cron or SQL):
 *   • payment_signals_last_entry_at — stamped on EVERY entry (even master-off).
 *   • payment_signals_last_tick_at  — stamped only when the lock is won (a real run).
 */
class PaymentSignalAutoRunner
{
    /** Short enough that a quiet minute still fires; long enough to absorb the 15s poll noise. */
    private const LOCK_TTL_SECONDS = 55;

    private const LOCK_KEY        = 'payment_signals_auto_lock';
    public const LAST_ENTRY_KEY  = 'payment_signals_last_entry_at';
    public const LAST_TICK_KEY   = 'payment_signals_last_tick_at';

    /**
     * Register a terminating() callback that runs the workers after the
     * response is flushed. Safe to call from any request handler — it's
     * fire-and-forget and never throws into the caller.
     */
    public function fireFromRequest(): void
    {
        // Cheap pre-check: don't even register the hook when the feature is off.
        if (!config('payment_signals.enabled')) {
            return;
        }

        try {
            app()->terminating(function () {
                try {
                    $this->processNow();
                } catch (\Throwable $e) {
                    // Worker errors must NEVER affect the request.
                    Log::warning('PaymentSignalAutoRunner (terminating) failed', ['error' => $e->getMessage()]);
                }
            });
        } catch (\Throwable $e) {
            // Some environments (testing) can't register terminating callbacks.
            Log::debug('PaymentSignalAutoRunner hook skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Run the two payment workers once, guarded by a global 55s lock.
     * Returns a small status array; callers should never block on it.
     *
     * @return array{ran:bool,reason:?string}
     */
    public function processNow(): array
    {
        // Heartbeat: stamp every entry so the diagnostics page can tell the
        // worker is being poked even on a quiet (no-signal) minute.
        try {
            Cache::put(self::LAST_ENTRY_KEY, now()->toDateTimeString(), now()->addDays(7));
        } catch (\Throwable $e) {
            // Cache failures must never break the worker.
        }

        if (!config('payment_signals.enabled')) {
            return ['ran' => false, 'reason' => 'master_off'];
        }

        // Cache::add is atomic — only the first caller in the 55s window wins.
        $gotLock = Cache::add(self::LOCK_KEY, now()->toIso8601String(), self::LOCK_TTL_SECONDS);
        if (!$gotLock) {
            return ['ran' => false, 'reason' => 'locked'];
        }

        try {
            Cache::put(self::LAST_TICK_KEY, now()->toDateTimeString(), now()->addDays(7));
        } catch (\Throwable $e) {
            // ignore
        }

        // Process WhatsApp screenshots first (cheap query when none pending),
        // then poll the mailbox. Each is isolated so one failing never blocks
        // the other.
        try {
            Artisan::call('payments:process-signals');
        } catch (\Throwable $e) {
            Log::warning('PaymentSignalAutoRunner: process-signals failed', ['error' => $e->getMessage()]);
        }

        try {
            Artisan::call('payments:poll-bank-emails');
        } catch (\Throwable $e) {
            Log::warning('PaymentSignalAutoRunner: poll-bank-emails failed', ['error' => $e->getMessage()]);
        }

        return ['ran' => true, 'reason' => null];
    }
}
