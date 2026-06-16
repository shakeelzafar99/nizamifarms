<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Customer-app webhook dispatcher (drains the t_app_webhook_events outbox).
 *
 * Extracted from the artisan command so the SAME drain logic can be fired
 * from three places:
 *
 *   1. app()->terminating() right after an order status change — the primary
 *      path in production, because we cannot rely on a `* * * * * schedule:run`
 *      cron existing on the shared host. The response is already flushed to the
 *      user before this runs, so nothing is "kept on hold".
 *   2. The scheduler (every minute) as a fallback + the retry heartbeat for
 *      environments that DO have cron.
 *   3. Manual replay via the artisan command (--event-uuid / --order).
 *
 * A Cache lock serialises auto-drains so two concurrent requests can't grab
 * and POST the same pending row twice. Mirrors the Qurbani WA worker pattern
 * the app already relies on.
 */
class CustomerAppWebhookDispatcher
{
    /** Lock key + TTL for the auto-drain (terminating + scheduler). */
    private const LOCK_KEY = 'customer_app_dispatch_lock';
    private const LOCK_TTL_SECONDS = 120;

    /**
     * How long a contending drain waits for the lock before giving up.
     *
     * Critical: we WAIT for the lock rather than bailing out immediately. If a
     * row is committed a split-second after another request's drain query ran,
     * bailing would strand it as pending/0-attempts until the next status
     * change. By blocking, the second request drains right after the first
     * releases and sweeps up anything the first missed. Safe to run after the
     * response is flushed (terminating) since the user isn't waiting on it.
     */
    private const LOCK_WAIT_SECONDS = 8;

    /**
     * Auto-drain used by the terminating() hook and the scheduler. Serialised
     * by a Cache lock so two concurrent requests can't grab and POST the same
     * pending row twice — but contenders WAIT (up to LOCK_WAIT_SECONDS) and
     * then drain, rather than skipping, so nothing gets stranded.
     *
     * $priorityOrder: when a status change fires this, we pass the order whose
     * status just changed so ITS rows are sent first — otherwise a large
     * backlog of older rows (low ids) would starve the in-focus order, which
     * sits at the top of the queue (high ids).
     */
    public function drainPendingSafely(?int $max = null, ?string $priorityOrder = null): array
    {
        if (!$this->isEnabled()) {
            return ['ran' => false, 'reason' => 'disabled'];
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        try {
            // block() acquires (waiting up to LOCK_WAIT_SECONDS), runs the
            // callback, then auto-releases the lock.
            return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($max, $priorityOrder) {
                return $this->drainPending($max, $priorityOrder);
            });
        } catch (LockTimeoutException $e) {
            // Lock held for the whole wait window => the queue is already being
            // actively flushed by other drains. Safe to skip this one.
            return ['ran' => false, 'reason' => 'locked'];
        }
    }

    /**
     * Drain pending + due-for-retry rows. No lock (callers that need
     * serialisation use drainPendingSafely).
     *
     * $priorityOrder floats one order_number's rows to the front of the batch
     * so the order whose status just changed is delivered first, ahead of any
     * older backlog. Within each group rows stay in id (chronological) order.
     */
    public function drainPending(?int $max = null, ?string $priorityOrder = null): array
    {
        $creds = $this->credentials();
        if ($creds === false) {
            return ['ran' => false, 'reason' => 'not_configured'];
        }

        $batch = (int) ($max ?? config('customer_app.batch_size', 50));
        $batch = max(1, $batch);

        $query = DB::table('t_app_webhook_events')
            ->where('target', 'customer_app')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            });

        if (!empty($priorityOrder)) {
            $query->orderByRaw('CASE WHEN order_number = ? THEN 0 ELSE 1 END', [$priorityOrder]);
        }

        $rows = $query->orderBy('id')
            ->limit($batch)
            ->get();

        return $this->dispatchRows($rows, $creds[0], $creds[1]);
    }

    /** Manual replay of a single event regardless of current status. */
    public function replayByEventUuid(string $uuid): array
    {
        $creds = $this->credentials();
        if ($creds === false) {
            return ['ran' => false, 'reason' => 'not_configured'];
        }

        $rows = DB::table('t_app_webhook_events')
            ->where('target', 'customer_app')
            ->where('event_uuid', $uuid)
            ->limit(1)
            ->get();

        return $this->dispatchRows($rows, $creds[0], $creds[1]);
    }

    /** Manual replay of every non-sent row for one NF order_number. */
    public function replayByOrder(string $orderNumber): array
    {
        $creds = $this->credentials();
        if ($creds === false) {
            return ['ran' => false, 'reason' => 'not_configured'];
        }

        $rows = DB::table('t_app_webhook_events')
            ->where('target', 'customer_app')
            ->where('order_number', $orderNumber)
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('id')
            ->get();

        return $this->dispatchRows($rows, $creds[0], $creds[1]);
    }

    public function isEnabled(): bool
    {
        return (bool) config('customer_app.enabled', true);
    }

    /**
     * @return array{0:string,1:string}|false  [url, secret] or false when unset.
     */
    private function credentials()
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $url    = (string) config('customer_app.url', '');
        $secret = (string) config('customer_app.secret', '');
        if ($url === '' || $secret === '') {
            return false;
        }
        return [$url, $secret];
    }

    private function dispatchRows($rows, string $url, string $secret): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $this->dispatchOne($row, $url, $secret) ? $sent++ : $failed++;
        }
        return ['ran' => true, 'pulled' => $rows->count(), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Try to send one row. Returns true on success. Records the outcome on
     * the outbox row either way.
     */
    private function dispatchOne(object $row, string $url, string $secret): bool
    {
        $body = $row->payload; // already JSON; do not re-encode

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $timeout        = (int) config('customer_app.timeout_seconds', 10);
        $connectTimeout = (int) config('customer_app.connect_timeout_seconds', 5);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders([
                    'Content-Type'    => 'application/json',
                    'X-NF-Signature'  => 't=' . $timestamp . ',v1=' . $signature,
                    'X-NF-Event-UUID' => $row->event_uuid,
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                DB::table('t_app_webhook_events')
                    ->where('id', $row->id)
                    ->update([
                        'status'          => 'sent',
                        'attempts'        => $row->attempts + 1,
                        'last_error'      => null,
                        'next_attempt_at' => null,
                        'sent_at'         => now(),
                    ]);

                Log::info('[customer-app] webhook sent', [
                    'event_uuid'   => $row->event_uuid,
                    'event_type'   => $row->event_type,
                    'order_number' => $row->order_number,
                    'http_status'  => $response->status(),
                ]);
                return true;
            }

            $this->markFailure(
                $row,
                'HTTP ' . $response->status() . ' ' . substr((string) $response->body(), 0, 500)
            );
            return false;
        } catch (\Throwable $e) {
            $this->markFailure($row, 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Bumps attempts, schedules the next retry from config retry_minutes, or
     * flips to 'dead' once the retry list is exhausted.
     */
    private function markFailure(object $row, string $error): void
    {
        $retrySchedule = (array) config('customer_app.retry_minutes', [1, 5, 30, 120, 720, 1440]);
        $nextAttempts  = $row->attempts + 1;

        if ($nextAttempts >= count($retrySchedule) + 1) {
            DB::table('t_app_webhook_events')
                ->where('id', $row->id)
                ->update([
                    'status'          => 'dead',
                    'attempts'        => $nextAttempts,
                    'last_error'      => $error,
                    'next_attempt_at' => null,
                ]);

            Log::error('Customer-app webhook permanently failed (dead)', [
                'event_uuid'   => $row->event_uuid,
                'event_type'   => $row->event_type,
                'order_number' => $row->order_number,
                'attempts'     => $nextAttempts,
                'last_error'   => $error,
            ]);
            return;
        }

        // attempts = 1 (just tried for the first time) -> retry_minutes[0].
        $waitMinutes = $retrySchedule[$nextAttempts - 1] ?? end($retrySchedule);

        DB::table('t_app_webhook_events')
            ->where('id', $row->id)
            ->update([
                'status'          => 'pending',
                'attempts'        => $nextAttempts,
                'last_error'      => $error,
                'next_attempt_at' => now()->addMinutes((int) $waitMinutes),
            ]);

        Log::warning('[customer-app] webhook attempt failed — will retry', [
            'event_uuid'    => $row->event_uuid,
            'event_type'    => $row->event_type,
            'order_number'  => $row->order_number,
            'attempt'       => $nextAttempts,
            'retry_in_mins' => $waitMinutes,
            'error'         => $error,
        ]);
    }
}
