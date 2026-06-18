<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Services\Payments\Email\ImapBankEmailFetcher;
use App\Services\Payments\PaymentSignalReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A simple, password-protected webpage to verify the payment-signals setup
 * (IMAP reachability + Gemini key presence) WITHOUT needing a command line.
 *
 * Access: must be logged in (route is inside the auth group) AND must pass
 * ?key=<PAYMENT_SIGNALS_CHECK_SECRET>. This keeps it usable by the owner
 * without depending on role internals.
 */
class PaymentDiagnosticsController extends Controller
{
    public function imapCheck(Request $request, ImapBankEmailFetcher $fetcher)
    {
        $secret = (string) config('payment_signals.check_secret', '');
        if ($secret === '' || $request->query('key') !== $secret) {
            abort(403, 'Forbidden. Append ?key=YOUR_CHECK_SECRET to the URL.');
        }

        // Manual "Run now" — processes pending WhatsApp screenshots and polls
        // bank emails INLINE (bypasses the auto-runner's 55s lock), so the
        // operator can test the full pipeline without waiting on cron or the
        // terminating() hook. Nothing here writes to money/ledger.
        $ranNow = false;
        $runOutput = null;
        if ($request->query('run') === '1') {
            $ranNow = true;
            if (!config('payment_signals.enabled')) {
                $runOutput = 'Feature switch is OFF (PAYMENT_SIGNALS_ENABLED). Nothing ran.';
            } else {
                try {
                    Artisan::call('payments:process-signals');
                    $out1 = trim(Artisan::output());
                    Artisan::call('payments:poll-bank-emails');
                    $out2 = trim(Artisan::output());
                    $runOutput = trim($out1 . "\n" . $out2);
                    if ($runOutput === '') {
                        $runOutput = 'Workers ran with no console output. Check the "Recent signals" table below for the result (a NEW row should now be matched / amount_mismatch / irrelevant).';
                    }
                } catch (\Throwable $e) {
                    $runOutput = 'ERROR while running: ' . $e->getMessage();
                }
            }
        }

        $checks = [];

        // 1. Feature switch
        $checks[] = [
            'name'   => 'Feature switch (PAYMENT_SIGNALS_ENABLED)',
            'ok'     => (bool) config('payment_signals.enabled'),
            'detail' => config('payment_signals.enabled') ? 'Enabled' : 'Disabled — set PAYMENT_SIGNALS_ENABLED=true when ready.',
        ];

        // 2. Gemini key presence
        $hasGemini = (bool) config('payment_signals.gemini.api_key');
        $checks[] = [
            'name'   => 'Gemini API key (GEMINI_API_KEY)',
            'ok'     => $hasGemini,
            'detail' => $hasGemini
                ? 'Present. Model: ' . config('payment_signals.gemini.model')
                : 'Missing — WhatsApp screenshot reading will be skipped.',
        ];

        // 3. PHP imap extension
        $imapExt = ImapBankEmailFetcher::extensionAvailable();
        $checks[] = [
            'name'   => 'PHP imap extension',
            'ok'     => $imapExt,
            'detail' => $imapExt
                ? 'Enabled.'
                : 'NOT enabled. Open a free Bluehost support ticket: "Please enable the PHP imap extension for my hosting account."',
        ];

        // 4. IMAP config presence
        $imap = config('payment_signals.imap');
        $imapConfigured = !empty($imap['host']) && !empty($imap['username']) && !empty($imap['password']);
        $checks[] = [
            'name'   => 'IMAP credentials (.env)',
            'ok'     => $imapConfigured,
            'detail' => $imapConfigured
                ? 'Host: ' . $imap['host'] . ':' . $imap['port'] . ' (' . $imap['encryption'] . '), user: ' . $imap['username']
                : 'Missing one of IMAP_HOST / IMAP_USERNAME / IMAP_PASSWORD.',
        ];

        // 5. Live IMAP connection
        $connectOk = false;
        $connectDetail = 'Skipped (extension or config missing).';
        $maxUid = null;
        if ($imapExt && $imapConfigured) {
            try {
                $conn = $fetcher->connect();
                $maxUid = $fetcher->currentMaxUid($conn);
                $connectOk = true;
                $connectDetail = 'Connected. Current highest message UID: ' . $maxUid
                    . '. Mailbox string: ' . $fetcher->mailboxString();
                @imap_close($conn);
            } catch (\Throwable $e) {
                $connectDetail = 'FAILED: ' . $e->getMessage();
            }
        }
        $checks[] = [
            'name'   => 'IMAP live connection',
            'ok'     => $connectOk,
            'detail' => $connectDetail,
        ];

        // 6. Tables present
        $tables = ['t_fin_payment_signal', 't_crm_customer_bank_alias', 't_fin_imap_cursor'];
        $missing = [];
        foreach ($tables as $t) {
            if (!$this->tableExists($t)) {
                $missing[] = $t;
            }
        }
        $checks[] = [
            'name'   => 'Database tables',
            'ok'     => empty($missing),
            'detail' => empty($missing)
                ? 'All 3 tables exist.'
                : 'Missing: ' . implode(', ', $missing) . '. Run create_payment_signal_and_alias_jun2026.sql.',
        ];

        // 7. Auto-runner heartbeat — confirms the workers are actually being
        // fired (by the web-request hooks and/or the scheduler) WITHOUT needing
        // terminal access. Stamped by PaymentSignalAutoRunner on each run.
        $lastTick  = \Illuminate\Support\Facades\Cache::get(\App\Services\Payments\PaymentSignalAutoRunner::LAST_TICK_KEY);
        $lastEntry = \Illuminate\Support\Facades\Cache::get(\App\Services\Payments\PaymentSignalAutoRunner::LAST_ENTRY_KEY);
        $heartbeatOk = false;
        if ($lastTick) {
            try {
                $heartbeatOk = \Carbon\Carbon::parse($lastTick)->gt(now()->subMinutes(3));
            } catch (\Throwable $e) {
                $heartbeatOk = false;
            }
        }
        if ($lastTick) {
            $heartbeatDetail = 'Last ran ' . \Carbon\Carbon::parse($lastTick)->diffForHumans()
                . ' (' . $lastTick . ').'
                . ($heartbeatOk
                    ? ' Healthy — workers are firing.'
                    : ' STALE (>3 min ago). Open the app / Messages so a web request can trigger it, then refresh.');
        } else {
            $heartbeatDetail = 'Never run yet. Open the app or the Messages page once (this triggers it), then refresh this page in ~30s. '
                . ($lastEntry ? 'Last poke: ' . $lastEntry . '.' : 'No pokes recorded yet.');
        }
        $checks[] = [
            'name'   => 'Auto-runner heartbeat (no cron needed)',
            'ok'     => $heartbeatOk,
            'detail' => $heartbeatDetail,
        ];

        // Recent signals so the operator can see, at a glance, whether the
        // image was captured and how it was matched — no SQL needed.
        $recentSignals = [];
        try {
            $recentSignals = \App\Models\FIN\PaymentSignal::query()
                ->orderByDesc('id')
                ->limit(10)
                ->get([
                    'id', 'source', 'status', 'extracted_amount', 'matched_order_id',
                    'matched_customer_id', 'match_reason', 'extraction_confidence',
                    'wa_conversation_id', 'created_at',
                ])
                ->toArray();
        } catch (\Throwable $e) {
            // table missing / other — leave empty
        }

        // Counts by status for a quick summary line.
        $statusCounts = [];
        try {
            $statusCounts = \App\Models\FIN\PaymentSignal::query()
                ->select('status', DB::raw('COUNT(*) as c'))
                ->groupBy('status')
                ->pluck('c', 'status')
                ->toArray();
        } catch (\Throwable $e) {
            // ignore
        }

        return view('fin.payments.imap-check', [
            'checks'        => $checks,
            'allOk'         => collect($checks)->every(fn ($c) => $c['ok']),
            'cursorMaxUid'  => $maxUid,
            'ranNow'        => $ranNow,
            'runOutput'     => $runOutput,
            'recentSignals' => $recentSignals,
            'statusCounts'  => $statusCounts,
            'checkSecret'   => $secret,
        ]);
    }

    /**
     * Manual catch-up button (Operations page). Finds WhatsApp images and bank
     * emails the live flow missed today, creates their signals, and runs the
     * SAME extraction + matcher so the result is identical to a fresh arrival.
     *
     * Idempotent and non-interfering: writes only to t_fin_payment_signal,
     * never touches the IMAP cursor, ledger, or orders. Returns a JSON summary.
     */
    public function reconcile(Request $request, PaymentSignalReconciler $reconciler)
    {
        if (!config('payment_signals.enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'Payment-proof matching is OFF (PAYMENT_SIGNALS_ENABLED). Nothing ran.',
            ]);
        }

        $whatsappDays  = max(0, min(7, (int) $request->input('whatsapp_days', 1)));
        $emailLookback = max(1, min(200, (int) $request->input('email_lookback', 50)));

        try {
            $result = $reconciler->run($whatsappDays, $emailLookback);

            $wa = $result['whatsapp'] ?? [];
            $em = $result['email'] ?? [];
            $summary = sprintf(
                'WhatsApp: %d image(s) without a signal found, %d new created. Email: %d scanned, %d new ingested.',
                (int) ($wa['candidates'] ?? 0),
                (int) ($wa['created'] ?? 0),
                (int) ($em['scanned'] ?? 0),
                (int) ($em['created'] ?? 0)
            );

            return response()->json([
                'success' => true,
                'message' => $summary,
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Reconcile failed: ' . $e->getMessage(),
            ]);
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
