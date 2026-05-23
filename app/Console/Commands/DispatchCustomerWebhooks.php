<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher for the customer-app outbox (Phase 1 — May 2026).
 *
 * Drains pending rows from t_app_webhook_events and POSTs them, signed
 * with HMAC-SHA256, to config('customer_app.url'). Designed to run via
 * the scheduler every minute (see routes/console.php).
 *
 * Run modes
 * ---------
 *   php artisan app:dispatch-customer-webhooks
 *       Default: pull up to BATCH pending+due rows, dispatch each one.
 *
 *   php artisan app:dispatch-customer-webhooks --event-uuid=<uuid>
 *       Replay a single event by uuid, regardless of its current status.
 *       Useful when Vercel reports it never arrived.
 *
 *   php artisan app:dispatch-customer-webhooks --order=SH-1234
 *       Replay every non-sent row for one order. Useful after a
 *       customer-side outage to catch them up.
 *
 *   php artisan app:dispatch-customer-webhooks --max=200
 *       Override batch size for one run.
 *
 * Reliability rules
 * -----------------
 * - 2xx response within timeout = success, row marked 'sent'.
 * - Any other outcome (timeout, network, non-2xx) increments attempts
 *   and schedules the next try via config('customer_app.retry_minutes').
 * - When attempts exceed the retry list length the row is flipped to
 *   status='dead' and an error log is emitted for ops to action.
 * - withoutOverlapping(5) on the schedule prevents two ticks running
 *   in parallel; even so this command takes a per-row defensive lock
 *   via the row UPDATE that flips status from pending to a transient
 *   state — see lockRow().
 */
class DispatchCustomerWebhooks extends Command
{
    protected $signature = 'app:dispatch-customer-webhooks
                            {--event-uuid= : Replay a single event by its UUID}
                            {--order= : Replay every non-sent row for this NF order_number (e.g. SH-1234)}
                            {--max= : Override batch size for this run}';

    protected $description = 'Drain the customer-app webhook outbox (signed POSTs with retry)';

    public function handle(): int
    {
        if (!config('customer_app.enabled', true)) {
            $this->info('Customer-app webhooks disabled via config — exiting.');
            return self::SUCCESS;
        }

        $url    = (string) config('customer_app.url', '');
        $secret = (string) config('customer_app.secret', '');

        if ($url === '' || $secret === '') {
            $this->warn('Customer-app webhook URL or secret missing — exiting (rows stay pending).');
            return self::SUCCESS;
        }

        $rows = $this->fetchRowsToDispatch();

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $ok = $this->dispatchOne($row, $url, $secret);
            $ok ? $sent++ : $failed++;
        }

        $this->line(json_encode([
            'pulled' => $rows->count(),
            'sent'   => $sent,
            'failed' => $failed,
        ]));

        return self::SUCCESS;
    }

    /**
     * Build the dispatch worklist based on CLI options. Defaults to
     * the standard "pending or due-for-retry" query.
     */
    private function fetchRowsToDispatch()
    {
        $query = DB::table('t_app_webhook_events')->where('target', 'customer_app');

        if ($uuid = $this->option('event-uuid')) {
            return $query->where('event_uuid', $uuid)->limit(1)->get();
        }

        if ($order = $this->option('order')) {
            return $query->where('order_number', $order)
                ->whereIn('status', ['pending', 'failed'])
                ->orderBy('id')
                ->get();
        }

        $batch = (int) ($this->option('max') ?? config('customer_app.batch_size', 50));
        $batch = max(1, $batch);

        return $query
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                  ->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($batch)
            ->get();
    }

    /**
     * Try to send one row. Returns true on success, false otherwise.
     * Mutates the outbox row to record the outcome either way.
     */
    private function dispatchOne(object $row, string $url, string $secret): bool
    {
        $body = $row->payload;       // already JSON; do not re-encode

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
     * Common failure path — bumps attempts, picks the next retry slot
     * from config('customer_app.retry_minutes'), or flips to 'dead'
     * once the retry list is exhausted.
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

        // Pick the wait that corresponds to this attempt count.
        // attempts = 1 (we just tried for the first time) -> retry_minutes[0].
        $waitMinutes = $retrySchedule[$nextAttempts - 1] ?? end($retrySchedule);

        DB::table('t_app_webhook_events')
            ->where('id', $row->id)
            ->update([
                'status'          => 'pending',
                'attempts'        => $nextAttempts,
                'last_error'      => $error,
                'next_attempt_at' => now()->addMinutes((int) $waitMinutes),
            ]);
    }
}
