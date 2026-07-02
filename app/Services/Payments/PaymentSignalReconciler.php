<?php

namespace App\Services\Payments;

use App\Models\FIN\PaymentSignal;
use App\Services\Payments\BankTagBackfillService;
use App\Services\Payments\Email\BankEmailRouter;
use App\Services\Payments\Email\ImapBankEmailFetcher;
use App\Services\Payments\Signals\PaymentSignalMatcher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Catch-up / safety-net for the payment-proof pipeline.
 *
 * The live flow (WhatsApp webhook → queue signal → process; IMAP poll → ingest)
 * is the primary path. This reconciler re-scans for anything that path missed
 * and feeds it through the SAME extractor + matcher, so the result is identical
 * to a fresh arrival. It is:
 *
 *   • idempotent  — never creates a duplicate (unique wa_message_id / folder+uid
 *                   plus explicit NOT-EXISTS guards),
 *   • read-mostly — writes only to t_fin_payment_signal; never to ledger/orders,
 *   • non-interfering — does not touch the IMAP cursor or the auto-runner lock,
 *
 * so it is safe to trigger manually (Operations page button) or on a schedule.
 */
class PaymentSignalReconciler
{
    public function __construct(
        protected ImapBankEmailFetcher $fetcher,
        protected BankEmailRouter $router,
        protected PaymentSignalMatcher $matcher
    ) {
    }

    /**
     * Run both reconcilers and process anything newly created.
     *
     * @return array{whatsapp: array, email: array}
     */
    public function run(int $whatsappDays = 1, int $emailLookback = 50): array
    {
        $whatsapp = $this->reconcileWhatsApp($whatsappDays);

        // The WhatsApp pass only inserts 'new' rows; the Gemini read + match
        // happens in the existing worker. Drain the backlog now so the button
        // gives an immediate, complete result.
        if (($whatsapp['created'] ?? 0) > 0) {
            $this->drainWhatsAppQueue();
        }

        // Resolve who was detected + how each landed (after matching).
        $whatsapp['details'] = $this->whatsappDetails($whatsapp['created_ids'] ?? []);
        unset($whatsapp['created_ids']);

        $email = $this->reconcileEmail($emailLookback);

        // Re-evaluate recent unresolved proofs under the CURRENT rules (e.g. a
        // widened amount tolerance), so existing "amount differs" rows can flip to
        // matched/verified without re-sending the screenshot.
        $rematch = $this->rematchUnresolved();

        // Receiving-bank HISTORY backfill: re-read pending-approval proofs that
        // were extracted before the receiver-account prompt existed, so they
        // gain a bank tag. Button-triggered only (this run) — never automatic.
        // Batch-capped per click; the summary reports how many remain.
        $bankTags = app(BankTagBackfillService::class)->run();

        return ['whatsapp' => $whatsapp, 'email' => $email, 'rematch' => $rematch, 'bank_tags' => $bankTags];
    }

    /**
     * Re-run the matcher over recent signals that are still `amount_mismatch`,
     * applying the current rules. Skips human-dismissed combinations and processes
     * each WhatsApp⇄email pair once. Writes only to the signal tables.
     *
     * @return array{candidates:int, rematched:int, now_matched:int}
     */
    public function rematchUnresolved(int $sinceDays = 30, int $limit = 300): array
    {
        if (!config('payment_signals.enabled')) {
            return ['candidates' => 0, 'rematched' => 0, 'now_matched' => 0, 'disabled' => true];
        }

        $since = now()->subDays(max(0, $sinceDays))->startOfDay();

        $signals = PaymentSignal::query()
            ->where('status', PaymentSignal::STATUS_AMOUNT_MISMATCH)
            // Never resurrect a combination a human explicitly rejected.
            ->where(function ($q) {
                $q->whereNull('match_reason')
                    ->orWhere('match_reason', '!=', 'combined_dismissed');
            })
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $done       = [];   // signal ids already handled (skip a pair's other side)
        $rematched  = 0;
        $nowMatched = 0;

        foreach ($signals as $signal) {
            if (isset($done[$signal->id])) {
                continue;
            }
            $done[$signal->id] = true;
            if ($signal->paired_signal_id) {
                $done[$signal->paired_signal_id] = true;
            }

            try {
                $result = $this->matcher->rematch($signal->fresh());
                $rematched++;
                if ($result && $result->status === PaymentSignal::STATUS_MATCHED) {
                    $nowMatched++;
                }
            } catch (\Throwable $e) {
                Log::warning('Reconciler: rematch skipped', [
                    'signal_id' => $signal->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return ['candidates' => $signals->count(), 'rematched' => $rematched, 'now_matched' => $nowMatched];
    }

    // ------------------------------------------------------------------
    // WHATSAPP
    // ------------------------------------------------------------------

    /**
     * Find inbound image messages in the window that should have a signal but
     * don't, and create the 'new' rows (mirrors the webhook pre-filter, minus
     * the optional payment_method gate).
     *
     * @return array{candidates:int, created:int, skipped_no_order:int}
     */
    public function reconcileWhatsApp(int $sinceDays = 1): array
    {
        if (!config('payment_signals.enabled')) {
            return ['candidates' => 0, 'created' => 0, 'skipped_no_order' => 0, 'disabled' => true];
        }

        $since = now()->subDays(max(0, $sinceDays))->startOfDay();
        $windowDays = (int) config('payment_signals.match_window_days', 30);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);
        $methods = config('payment_signals.online_payment_methods', ['online', 'bank_transfer']);
        $requireMethod = (bool) config('payment_signals.require_online_payment_method', false);

        // Inbound images (or image documents) with media, mapped to a customer,
        // and not already signalled.
        $rows = DB::table('t_wa_messages as m')
            ->join('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.direction', 'inbound')
            ->where(function ($q) {
                $q->where('m.type', 'image')
                    ->orWhere(function ($q2) {
                        $q2->where('m.type', 'document')->where('m.media_mime_type', 'like', 'image/%');
                    });
            })
            ->whereNotNull('m.media_url')
            ->whereNotNull('c.customer_id')
            ->where('m.created_at', '>=', $since)
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('t_fin_payment_signal as s')
                    ->whereColumn('s.wa_message_id', 'm.id');
            })
            ->orderBy('m.id')
            ->get(['m.id as message_id', 'm.conversation_id', 'm.media_url', 'c.customer_id', 'm.created_at']);

        $candidates = $rows->count();
        if ($candidates === 0) {
            return ['candidates' => 0, 'created' => 0, 'skipped_no_order' => 0];
        }

        // Which of these customers actually have a still-owed order in window?
        $customerIds = $rows->pluck('customer_id')->unique()->values()->all();
        $qualifyingQuery = DB::table('t_crm_prod_order')
            ->whereIn('customer_id', $customerIds)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            ->where('order_date', '>=', now()->subDays($windowDays)->startOfDay());
        if ($requireMethod) {
            $qualifyingQuery->whereIn('payment_method', $methods);
        }
        $qualifying = array_flip(
            $qualifyingQuery->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->all()
        );

        $created = 0;
        $skippedNoOrder = 0;
        $createdIds = [];
        foreach ($rows as $r) {
            if (!isset($qualifying[(int) $r->customer_id])) {
                $skippedNoOrder++;
                continue;
            }
            try {
                $signal = PaymentSignal::create([
                    'source'             => PaymentSignal::SOURCE_WHATSAPP,
                    'wa_message_id'      => $r->message_id,
                    'wa_conversation_id' => $r->conversation_id,
                    'image_path'         => $r->media_url,
                    'status'             => PaymentSignal::STATUS_NEW,
                ]);
                $createdIds[] = $signal->id;
                $created++;
            } catch (\Throwable $e) {
                // Unique key race (already created by the live flow) — ignore.
                Log::debug('Reconciler: whatsapp signal insert skipped', [
                    'wa_message_id' => $r->message_id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'candidates'       => $candidates,
            'created'          => $created,
            'skipped_no_order' => $skippedNoOrder,
            'created_ids'      => $createdIds,
        ];
    }

    /**
     * Build a human-friendly, per-customer list for the signals just created
     * (after they've been read + matched), so the operator sees exactly who was
     * detected and how it landed. @return array<int, array<string, mixed>>
     */
    private function whatsappDetails(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $signals = PaymentSignal::query()->whereIn('id', $ids)->orderByDesc('id')->get();

        $convs = DB::table('t_wa_conversations')
            ->whereIn('id', $signals->pluck('wa_conversation_id')->filter()->unique()->all() ?: [0])
            ->get(['id', 'wa_contact_name', 'wa_phone'])
            ->keyBy('id');

        $orderIds = $signals->pluck('matched_order_id')->filter()->unique()->all();
        $orders = $orderIds
            ? DB::table('t_crm_prod_order')->whereIn('id', $orderIds)->pluck('order_number', 'id')
            : collect();

        return $signals->map(function (PaymentSignal $s) use ($convs, $orders) {
            $c = $convs->get($s->wa_conversation_id);
            return [
                'customer'     => $c->wa_contact_name ?? null,
                'phone'        => $c->wa_phone ?? null,
                'amount'       => $s->extracted_amount,
                'status'       => $s->status,
                'order_number' => $s->matched_order_id ? ($orders[$s->matched_order_id] ?? null) : null,
                'received_at'  => optional($s->created_at)->format('Y-m-d H:i'),
            ];
        })->values()->all();
    }

    /** Process pending WhatsApp signals through Gemini until the backlog clears. */
    private function drainWhatsAppQueue(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $pending = PaymentSignal::query()
                ->where('source', PaymentSignal::SOURCE_WHATSAPP)
                ->where('status', PaymentSignal::STATUS_NEW)
                ->count();
            if ($pending === 0) {
                break;
            }
            try {
                Artisan::call('payments:process-signals');
            } catch (\Throwable $e) {
                Log::warning('Reconciler: process-signals failed', ['error' => $e->getMessage()]);
                break;
            }
            // Stop if a hard failure (e.g. Gemini down) left the backlog unchanged.
            $stillPending = PaymentSignal::query()
                ->where('source', PaymentSignal::SOURCE_WHATSAPP)
                ->where('status', PaymentSignal::STATUS_NEW)
                ->count();
            if ($stillPending >= $pending) {
                break;
            }
        }
    }

    // ------------------------------------------------------------------
    // EMAIL
    // ------------------------------------------------------------------

    /**
     * Re-scan the most recent emails (ignoring the poll cursor) and ingest any
     * bank credit confirmations we don't already have, then match them. Does
     * NOT advance the cursor — the live poller owns that.
     *
     * @return array{scanned:int, created:int, skipped_route:int, skipped_parse:int, skipped_dup:int}
     */
    public function reconcileEmail(int $lookback = 50): array
    {
        $base = ['scanned' => 0, 'created' => 0, 'skipped_route' => 0, 'skipped_parse' => 0, 'skipped_dup' => 0, 'details' => []];

        if (!config('payment_signals.enabled')) {
            return $base + ['disabled' => true];
        }
        $imap = config('payment_signals.imap');
        if (empty($imap['host']) || empty($imap['username']) || empty($imap['password'])) {
            return $base + ['note' => 'IMAP not configured'];
        }
        if (!ImapBankEmailFetcher::extensionAvailable()) {
            return $base + ['note' => 'PHP imap extension unavailable'];
        }

        $folder = $imap['folder'] ?? 'INBOX';
        $conn = null;
        try {
            $conn = $this->fetcher->connect();
            $emails = $this->fetcher->fetchRecent($conn, $lookback);
            $base['scanned'] = count($emails);

            foreach ($emails as $email) {
                // Isolate each email so one bad row never aborts the scan.
                try {
                    $route = $this->router->route($email['from'], $email['subject']);
                    if (!$route) {
                        $base['skipped_route']++;
                        continue;
                    }
                    $parsed = $route['parser']->parse($email['subject'], $email['body']);
                    if (!$parsed) {
                        $base['skipped_parse']++;
                        continue;
                    }

                    $exists = PaymentSignal::query()
                        ->where('email_folder', $folder)
                        ->where('email_uid', (string) $email['uid'])
                        ->exists();
                    if ($exists) {
                        $base['skipped_dup']++;
                        continue;
                    }

                    $signal = PaymentSignal::create([
                        'source'                          => PaymentSignal::SOURCE_EMAIL,
                        'email_uid'                       => (string) $email['uid'],
                        'email_folder'                    => $folder,
                        'email_from'                      => mb_substr($email['from'], 0, 255),
                        'email_subject'                   => mb_substr($email['subject'], 0, 500),
                        'email_received_at'               => $this->parseDate($email['date']),
                        'extracted_amount'                => $parsed['amount'],
                        'extracted_ref'                   => $parsed['reference'],
                        'extracted_sender_name'           => $parsed['sender_name'],
                        'extracted_sender_account_masked' => $parsed['sender_account_masked'],
                        'extracted_sender_bank'           => $route['short_code'],
                        'extracted_txn_datetime'          => $this->parseDate($parsed['txn_datetime'] ?? null),
                        'extraction_confidence'           => $parsed['confidence'],
                        'extraction_raw_text'             => mb_substr($email['body'], 0, 60000),
                        'extractor_version'               => $route['parser']->version(),
                        'status'                          => PaymentSignal::STATUS_NEW,
                    ]);

                    $matched = $this->matcher->match($signal->fresh());
                    $orderNumber = $matched && $matched->matched_order_id
                        ? DB::table('t_crm_prod_order')->where('id', $matched->matched_order_id)->value('order_number')
                        : null;
                    $receivedAt = $this->parseDate($email['date']);
                    $base['details'][] = [
                        'sender_name'  => $parsed['sender_name'] ?? null,
                        'amount'       => $parsed['amount'] ?? null,
                        'status'       => $matched ? $matched->status : PaymentSignal::STATUS_NEW,
                        'order_number' => $orderNumber,
                        'received_at'  => $receivedAt ? substr($receivedAt, 0, 16) : null,
                    ];
                    $base['created']++;
                } catch (\Throwable $e) {
                    Log::warning('Reconciler: email ingest skipped', [
                        'uid' => $email['uid'] ?? null, 'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Reconciler: email reconcile failed', ['error' => $e->getMessage()]);
            $base['error'] = $e->getMessage();
        } finally {
            if ($conn) {
                $this->fetcher->drainErrors();
                @imap_close($conn);
                $this->fetcher->drainErrors();
            }
        }

        return $base;
    }

    private function parseDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
