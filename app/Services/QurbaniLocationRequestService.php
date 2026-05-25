<?php

namespace App\Services;

use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * QurbaniLocationRequestService — Phase 6 (May-2026)
 * ===================================================
 *
 * Drives the "Request Location" feature on the Qurbani Orders page.
 *
 * Lifecycle:
 *   1. Staff opens the Send Bulk modal → picks filters + customers →
 *      hits Send. queueBulk() creates one row per customer in
 *      t_crm_qurbani_loc_request with status='queued' under a single
 *      batch_id.
 *   2. Controller calls processQueued($batchId, $cap) inline so the
 *      browser sees rows progressively flip queued → sent. The cron
 *      worker (QurbaniLocationRequestProcess) is a safety-net
 *      heartbeat for any orphaned queues.
 *   3. Customer replies with a WhatsApp location pin → the inbound
 *      webhook (WhatsAppService::handleIncomingMessage) calls
 *      recordReply() which matches by Meta context.id and stamps
 *      replied_at + reply coordinates on the request row.
 *   4. Staff opens the Reviewer drawer → sees the replied-but-not-
 *      yet-saved rows → clicks Save or Save All. saveToCustomer()
 *      writes the lat/lng onto t_crm_prod_customer with a strict
 *      no-overwrite-newer-pin safety check.
 *
 * Safety guarantees:
 *   - saveToCustomer() ALWAYS uses the request row's customer_id
 *     (the one we stored when queueing), never the conversation's
 *     resolved customer_id. This is the single most important
 *     defence against "Customer B's pin saved onto Customer A".
 *   - Before writing, we re-read the target customer and skip if
 *     they already have a verified_location_saved_at NEWER than this
 *     request's sent_at — the human-pinned location wins over a
 *     stale WhatsApp reply.
 *   - recordReply() optionally cross-checks that the inbound
 *     conversation's customer_id matches the request's customer_id.
 *     On mismatch we log a warning and DON'T link — the location
 *     stays in t_wa_messages.metadata for manual triage.
 */
class QurbaniLocationRequestService
{
    public const TABLE = 't_crm_qurbani_loc_request';

    protected WhatsAppService $wa;

    public function __construct(WhatsAppService $wa)
    {
        $this->wa = $wa;
    }

    // -----------------------------------------------------------------
    // QUEUE — create rows ready for the sender worker.
    // -----------------------------------------------------------------

    /**
     * Create one queued request row for a single customer.
     *
     * Returns the new row id. No WhatsApp call is made — the worker
     * (or the inline /bulk/start drain) is responsible for sending.
     */
    public function queueOne(array $params): int
    {
        $customerId = (int) ($params['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new \InvalidArgumentException('customer_id required');
        }

        $phone = $this->resolveCustomerPhone($customerId, $params['wa_phone'] ?? null);
        if (!$phone) {
            throw new \InvalidArgumentException('No usable phone for customer');
        }

        return (int) DB::table(self::TABLE)->insertGetId([
            'customer_id'           => $customerId,
            'order_id'              => $params['order_id'] ?? null,
            'line_item_id'          => $params['line_item_id'] ?? null,
            'wa_phone'              => $phone,
            'qurbani_day'           => $params['qurbani_day'] ?? null,
            'qurbani_slot'          => $params['qurbani_slot'] ?? null,
            'qurbani_region'        => $params['qurbani_region'] ?? null,
            'qurbani_sub_region'    => $params['qurbani_sub_region'] ?? null,
            'qurbani_delivery_type' => $params['qurbani_delivery_type'] ?? null,
            'category_level_2'      => $params['category_level_2'] ?? null,
            'batch_id'              => $params['batch_id'] ?? null,
            'batch_label'           => $params['batch_label'] ?? null,
            'status'                => 'queued',
            'queued_at'             => now(),
            'sent_by'               => $params['sent_by'] ?? null,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);
    }

    /**
     * Create a batch and queue many requests in one shot.
     *
     * $rows is an array of associative arrays each carrying customer_id
     * and the same context fields queueOne() accepts. Returns
     * ['batch_id' => uuid, 'batch_label' => label, 'queued' => N,
     *  'skipped_no_phone' => N, 'skipped_duplicate' => N].
     *
     * Dedup-within-batch: if the same customer appears twice (e.g. they
     * have two open orders) we only queue ONE row — the customer is
     * one human and one WhatsApp send is enough.
     */
    public function queueBulk(array $rows, string $batchLabel, ?int $userId = null): array
    {
        $batchId = (string) Str::uuid();
        $now     = now();

        $insert = [];
        $seenCustomers = [];
        $skippedNoPhone = 0;
        $skippedDup = 0;

        foreach ($rows as $r) {
            $cid = (int) ($r['customer_id'] ?? 0);
            if ($cid <= 0) { continue; }

            if (isset($seenCustomers[$cid])) {
                $skippedDup++;
                continue;
            }
            $seenCustomers[$cid] = true;

            $phone = $this->resolveCustomerPhone($cid, $r['wa_phone'] ?? null);
            if (!$phone) {
                $skippedNoPhone++;
                continue;
            }

            $insert[] = [
                'customer_id'           => $cid,
                'order_id'              => $r['order_id'] ?? null,
                'line_item_id'          => $r['line_item_id'] ?? null,
                'wa_phone'              => $phone,
                'qurbani_day'           => $r['qurbani_day'] ?? null,
                'qurbani_slot'          => $r['qurbani_slot'] ?? null,
                'qurbani_region'        => $r['qurbani_region'] ?? null,
                'qurbani_sub_region'    => $r['qurbani_sub_region'] ?? null,
                'qurbani_delivery_type' => $r['qurbani_delivery_type'] ?? null,
                'category_level_2'      => $r['category_level_2'] ?? null,
                'batch_id'              => $batchId,
                'batch_label'           => $batchLabel,
                'status'                => 'queued',
                'queued_at'             => $now,
                'sent_by'               => $userId,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        // Insert in chunks of 200 — InnoDB single statements above
        // ~500 rows get sluggish and we want to keep this snappy from
        // an interactive controller call.
        $chunks = array_chunk($insert, 200);
        foreach ($chunks as $chunk) {
            DB::table(self::TABLE)->insert($chunk);
        }

        return [
            'batch_id'         => $batchId,
            'batch_label'      => $batchLabel,
            'queued'           => count($insert),
            'skipped_no_phone' => $skippedNoPhone,
            'skipped_duplicate'=> $skippedDup,
        ];
    }

    // -----------------------------------------------------------------
    // SEND — drain the queue and call WhatsApp.
    // -----------------------------------------------------------------

    /**
     * Drain up to $limit queued rows and dispatch the WhatsApp template
     * for each. Returns counters so the caller can update a progress UI.
     *
     * Atomicity: each row is locked + flipped to status='sending'
     * inside a transaction BEFORE the API call. Crash-safe: a
     * subsequent worker invocation will pick up any rows stuck in
     * 'sending' older than 5 minutes via the secondary sweep below.
     */
    public function processQueued(?string $batchId = null, int $limit = 25): array
    {
        $cfg = config('qurbani.location_request', []);
        $templateName = $cfg['template_name'] ?? 'qurbani_location';
        $templateLang = $cfg['template_language'] ?? 'en';
        $pacingUs     = (int) ($cfg['send_pacing_us'] ?? 200000);
        $breakerAfter = (int) ($cfg['rate_limit_breaker_after'] ?? 4);
        $cooldownS    = (int) ($cfg['rate_limit_cooldown_s'] ?? 300);

        // First — recover any orphaned 'sending' rows older than 5min.
        DB::table(self::TABLE)
            ->where('status', 'sending')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update(['status' => 'queued', 'updated_at' => now()]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $consecutiveRateLimits = 0;

        for ($i = 0; $i < $limit; $i++) {
            // Pop one row atomically.
            $row = DB::transaction(function () use ($batchId) {
                $q = DB::table(self::TABLE)
                    ->where('status', 'queued')
                    ->orderBy('id', 'asc')
                    ->limit(1)
                    ->lockForUpdate();
                if ($batchId) { $q->where('batch_id', $batchId); }
                $r = $q->first();
                if (!$r) { return null; }

                DB::table(self::TABLE)
                    ->where('id', $r->id)
                    ->update(['status' => 'sending', 'updated_at' => now()]);
                return $r;
            });

            if (!$row) { break; }

            // Defensive late-binding check: if the customer has gained
            // a verified lat/lng pin between when this row was queued
            // and now (e.g. a rider pinned them in the field, an admin
            // pasted coordinates, or another teammate ran a regular
            // order flow that wrote the pin), the request is moot —
            // skip the Meta call entirely. Doesn't matter WHEN the pin
            // was set (newer or older than queue time): if they're
            // verified at send time, asking again is just noise. The
            // eligible query already pre-filters this, but races between
            // browsing the modal and actually draining the queue make
            // this guard worth keeping.
            $cust = DB::table('t_crm_prod_customer')
                ->select('id', 'first_name', 'last_name', 'latitude', 'longitude', 'verified_location_saved_at')
                ->where('id', $row->customer_id)
                ->first();

            if ($cust && $cust->latitude && $cust->longitude
                && (float) $cust->latitude !== 0.0 && (float) $cust->longitude !== 0.0) {
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status'        => 'skipped',
                    'error_message' => 'Customer already verified via another channel — skipped',
                    'updated_at'    => now(),
                ]);
                $skipped++;
                continue;
            }

            $customerName = $cust
                ? trim(($cust->first_name ?? '') . ' ' . ($cust->last_name ?? ''))
                : '';
            if ($customerName === '') { $customerName = 'Customer'; }

            // Send the template — body param {{1}} = customer display name.
            try {
                $resp = $this->wa->sendTemplateMessage(
                    $row->wa_phone,
                    $templateName,
                    $templateLang,
                    [trim($customerName) ?: 'Customer']
                );
            } catch (\Throwable $e) {
                Log::error('QurbaniLocReq: send exception', [
                    'row_id' => $row->id, 'error' => $e->getMessage(),
                ]);
                $resp = ['success' => false, 'error' => $e->getMessage()];
            }

            if (!empty($resp['success'])) {
                // Sniff the Meta message id — Cloud-API responses normally
                // expose it at messages[0].id but some SDK shapes nest it
                // under data.messages[0].id, so we try both.
                $waMsgId = $resp['messages'][0]['id']
                    ?? ($resp['data']['messages'][0]['id'] ?? null);

                // Persist the outbound template into the conversation
                // timeline so it shows in the /messages page chat with
                // the standard "Template: qurbani_location" badge.
                // Without this, the Reviewer sees a customer's reply but
                // there's no visible "we sent the request" entry above
                // it — which is confusing and was the original bug.
                // Pattern mirrored from QurbaniWaAutoSender::sendTemplate.
                $conversationId = null;
                try {
                    $conv = $this->wa->findOrCreateConversation($row->wa_phone);
                    $conversationId = $conv->id;
                    $savedMsg = $this->wa->saveOutboundMessage(
                        $conv->id,
                        $resp,
                        'template',
                        $templateName, // content column — what the chat list shows
                        $row->sent_by ?: null,
                        $templateName,
                        ['1' => trim($customerName) ?: 'Customer']
                    );
                    // saveOutboundMessage stores the wa_message_id on the
                    // MessageModel row from the API response; prefer that
                    // value because it's the canonical persisted one.
                    if ($savedMsg && $savedMsg->wa_message_id) {
                        $waMsgId = $savedMsg->wa_message_id;
                    }
                } catch (\Throwable $persistErr) {
                    // Non-fatal: the Meta send already succeeded, so we
                    // don't want to flip the row back to failed. The
                    // request still works for matching (we'll fall back
                    // to phone+window matching if context.id is missing).
                    Log::warning('QurbaniLocReq: outbound persist failed (non-fatal)', [
                        'row_id'  => $row->id,
                        'phone'   => $row->wa_phone,
                        'error'   => $persistErr->getMessage(),
                    ]);
                }

                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status'             => 'sent',
                    'sent_at'            => now(),
                    'sent_wa_message_id' => $waMsgId,
                    'error_code'         => null,
                    'error_message'      => null,
                    'updated_at'         => now(),
                ]);
                $sent++;
                $consecutiveRateLimits = 0;
            } else {
                $errMsg = (string) ($resp['error'] ?? 'Unknown error');
                $errCode = $resp['error_code'] ?? null;
                $isRateLimit = $this->looksLikeRateLimit($errCode, $errMsg);

                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status'        => 'failed',
                    'error_code'    => $errCode ? (string) $errCode : null,
                    'error_message' => Str::limit($errMsg, 480, ''),
                    'updated_at'    => now(),
                ]);
                $failed++;

                if ($isRateLimit) {
                    $consecutiveRateLimits++;
                    if ($consecutiveRateLimits >= $breakerAfter) {
                        Log::warning('QurbaniLocReq: rate-limit breaker tripped — cooling down', [
                            'consecutive' => $consecutiveRateLimits,
                            'cooldown_s'  => $cooldownS,
                        ]);
                        break;
                    }
                } else {
                    $consecutiveRateLimits = 0;
                }
            }

            if ($pacingUs > 0) { usleep($pacingUs); }
        }

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Best-effort detector for Meta throttle / rate-limit errors so the
     * circuit-breaker can kick in. Codes 130429 / 131056 / 80007 are the
     * canonical Cloud-API rate-limit signals.
     */
    protected function looksLikeRateLimit($code, string $msg): bool
    {
        $rateLimitCodes = ['130429', '131056', '80007', '4', '32', '613'];
        if ($code !== null && in_array((string) $code, $rateLimitCodes, true)) {
            return true;
        }
        $needle = strtolower($msg);
        return str_contains($needle, 'rate limit')
            || str_contains($needle, 'too many')
            || str_contains($needle, 'throttle');
    }

    // -----------------------------------------------------------------
    // RECEIVE — webhook-side: incoming location → match a request.
    // -----------------------------------------------------------------

    /**
     * Hook called from WhatsAppService::handleIncomingMessage when an
     * inbound 'location' message arrives. Returns the matched request
     * id (int) or null if no match.
     *
     * Matching strategy:
     *   1. PRIMARY: $contextId === sent_wa_message_id (the customer
     *      quoted our template — exact match).
     *   2. FALLBACK: most recent sent row for this $phone within the
     *      config'd window, NOT yet replied.
     *
     * Safety: if $conversationCustomerId is supplied AND it doesn't
     * match the request row's customer_id, we DON'T link. The location
     * still sits in t_wa_messages.metadata for manual triage.
     */
    public function recordReply(
        ?string $contextId,
        string $phone,
        float $lat,
        float $lng,
        string $name = '',
        string $address = '',
        ?int $conversationCustomerId = null,
        ?string $replyWaMessageId = null
    ): ?int {
        $request = null;

        // Primary match: context.id.
        if ($contextId) {
            $request = DB::table(self::TABLE)
                ->where('sent_wa_message_id', $contextId)
                ->whereNull('replied_at')
                ->orderBy('id', 'desc')
                ->first();
        }

        // Fallback: phone + recent unreplied send.
        if (!$request) {
            $windowDays = (int) (config('qurbani.location_request.fallback_match_days', 7));
            $request = DB::table(self::TABLE)
                ->where('wa_phone', $phone)
                ->where('status', 'sent')
                ->whereNull('replied_at')
                ->where('sent_at', '>=', now()->subDays($windowDays))
                ->orderBy('sent_at', 'desc')
                ->first();
        }

        if (!$request) { return null; }

        // Safety cross-check: don't link if the conversation resolved
        // to a different customer than the one we queued for. This
        // catches the rare case where a customer record was merged or
        // a phone got reassigned between queue and reply.
        if ($conversationCustomerId
            && (int) $conversationCustomerId !== (int) $request->customer_id) {
            Log::warning('QurbaniLocReq: customer mismatch on reply — not linking', [
                'request_id'              => $request->id,
                'request_customer_id'     => $request->customer_id,
                'conversation_customer_id'=> $conversationCustomerId,
                'phone'                   => $phone,
            ]);
            return null;
        }

        // If the customer became verified through another channel
        // BEFORE this WhatsApp reply landed (regular order flow,
        // rider pin, etc.), the reply is moot — record it on the
        // row for audit but immediately auto-dismiss so the Reviewer
        // drawer doesn't show a stale "needs save" entry. The reply
        // also stays in t_wa_messages.metadata for manual triage.
        $cust = DB::table('t_crm_prod_customer')
            ->select('latitude', 'longitude', 'verified_location_saved_at')
            ->where('id', $request->customer_id)
            ->first();
        $alreadyVerified = $cust && $cust->latitude && $cust->longitude
            && (float) $cust->latitude !== 0.0 && (float) $cust->longitude !== 0.0;

        $update = [
            'replied_at'          => now(),
            'reply_wa_message_id' => $replyWaMessageId,
            'reply_latitude'      => $lat,
            'reply_longitude'     => $lng,
            'reply_name'          => Str::limit($name, 250, ''),
            'reply_address'       => Str::limit($address, 490, ''),
            'updated_at'          => now(),
        ];
        if ($alreadyVerified) {
            $update['saved_to_customer']   = 1;
            $update['saved_at']            = now();
            $update['save_skipped_reason'] = 'auto-dismissed on reply: customer already verified via another channel';
            Log::info('QurbaniLocReq: reply auto-dismissed (customer already verified)', [
                'request_id'  => $request->id,
                'customer_id' => $request->customer_id,
            ]);
        }

        DB::table(self::TABLE)->where('id', $request->id)->update($update);

        // Optional auto-save (default OFF — staff reviews and saves).
        if (config('qurbani.location_request.auto_save_on_reply')) {
            try {
                $this->saveToCustomer((int) $request->id, null);
            } catch (\Throwable $e) {
                Log::warning('QurbaniLocReq: auto-save failed (non-fatal)', [
                    'request_id' => $request->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return (int) $request->id;
    }

    /**
     * Handle an inbound TEXT message that contains a Google Maps URL.
     * Resolves shortened links (maps.app.goo.gl / goo.gl) by following
     * the redirect, extracts the lat/lng from the final URL, and feeds
     * those into the standard recordReply() flow so the row appears in
     * the Reviewer drawer alongside real WhatsApp pin replies.
     *
     * Why this exists: customers sometimes share their location as a
     * Google Maps share-link inside the message body instead of using
     * WhatsApp's native location-pin attachment. Without this hook
     * those replies just sat in t_wa_messages as plain text and
     * Reviewer never saw them.
     *
     * Returns the matched request id, or null if no maps URL was found,
     * no coords could be extracted, or no outstanding request matched.
     * All resolve / parse failures are swallowed and logged at debug —
     * the message itself is still saved in the conversation by the
     * caller, so we never lose visibility.
     */
    public function recordUrlReply(
        ?string $contextId,
        string $phone,
        string $messageBody,
        ?int $conversationCustomerId = null,
        ?string $replyWaMessageId = null
    ): ?int {
        $url = $this->extractMapsUrl($messageBody);
        if (!$url) { return null; }

        $resolved = $this->resolveShortMapsUrl($url);
        $coords = $this->parseCoordsFromMapsUrl($resolved);

        if (!$coords) {
            // We saw a maps URL but couldn't get lat/lng out of it. Log
            // for triage — the manual "Set as Verified Location"
            // button on the message screen still handles this case.
            Log::info('QurbaniLocReq: maps URL detected but coords not extracted', [
                'phone'       => $phone,
                'url'         => $url,
                'resolved'    => $resolved,
            ]);
            return null;
        }

        // Reuse the standard recordReply() pipeline so we get the same
        // context.id matching, customer cross-check, auto-dismiss on
        // already-verified customers, and reply-coords persistence
        // for free. We stash the source URL in reply_address with a
        // recognisable prefix so the Reviewer can show it.
        return $this->recordReply(
            $contextId,
            $phone,
            (float) $coords['lat'],
            (float) $coords['lng'],
            'Shared via Google Maps link',
            'URL: ' . substr($resolved, 0, 450),
            $conversationCustomerId,
            $replyWaMessageId
        );
    }

    /**
     * Pull the first Google Maps URL out of a free-text message body.
     * Recognises the same domain shortlist the mobile chat uses
     * (StoreOpenOrdersScreen.js GMAPS_REGEX) so behaviour stays
     * consistent across surfaces.
     */
    protected function extractMapsUrl(?string $body): ?string
    {
        if (empty($body)) { return null; }
        $re = '~https?://(?:maps\.google\.com|www\.google\.com/maps|goo\.gl/maps|maps\.app\.goo\.gl|g\.co/maps)[^\s)\]"<]*~i';
        if (preg_match($re, $body, $m)) {
            return $m[0];
        }
        return null;
    }

    /**
     * Follow a shortened Google Maps link to its final URL using cURL.
     * If the URL isn't shortened we return it as-is. On any network
     * error we return the input unchanged — the caller will then try
     * to parse coords from the original URL and silently no-op if
     * that fails.
     *
     * Note: This mirrors RiderController::resolveGoogleMapsUrl. We
     * keep a local copy rather than refactor into a shared trait
     * because the rider helper is private and we don't want to
     * widen its visibility in a hot operational controller.
     */
    protected function resolveShortMapsUrl(string $url): string
    {
        $shortDomains = ['goo.gl', 'maps.app.goo.gl', 'g.co'];
        $isShort = false;
        foreach ($shortDomains as $d) {
            if (stripos($url, $d) !== false) { $isShort = true; break; }
        }
        if (!$isShort) { return $url; }

        if (!function_exists('curl_init')) {
            return $url;
        }

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NizamiFarms-LocResolver/1.0)');
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400 && !empty($finalUrl)) {
                return (string) $finalUrl;
            }
        } catch (\Throwable $e) {
            Log::debug('QurbaniLocReq: resolveShortMapsUrl failed (non-fatal)', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
        }
        return $url;
    }

    /**
     * Extract lat/lng coordinates from a (resolved) Google Maps URL.
     * Recognises three common patterns:
     *   - ?q=LAT,LNG / ?ll=LAT,LNG
     *   - /@LAT,LNG,ZOOM in the path
     *   - /place/LAT,LNG/...
     * Returns ['lat' => float, 'lng' => float] or null if no
     * coordinates were found (e.g. the URL is a place-name share
     * with no coords in the query string).
     */
    protected function parseCoordsFromMapsUrl(string $url): ?array
    {
        if (empty($url)) { return null; }
        if (preg_match('/[?&](?:q|ll|destination)=(-?\d+\.\d+),(-?\d+\.\d+)/i', $url, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }
        if (preg_match('~/place/(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) {
            return ['lat' => (float) $m[1], 'lng' => (float) $m[2]];
        }
        return null;
    }

    // -----------------------------------------------------------------
    // SAVE — push the captured reply onto t_crm_prod_customer.
    // -----------------------------------------------------------------

    /**
     * Save a single request row's captured location onto the customer's
     * verified_location_* fields. Returns:
     *   ['saved' => bool, 'skipped_reason' => ?string, 'customer_id' => int]
     *
     * Hard safety rules (in order):
     *   1. Request must have a reply (replied_at + lat + lng).
     *   2. customer_id is read FROM THE REQUEST ROW — never from a
     *      conversation lookup. This is the single most important
     *      defence against cross-customer pin contamination.
     *   3. If $force is false (default) and the customer already has
     *      a verified_location_saved_at newer than the request's
     *      sent_at, we mark the row saved_to_customer=1 with a
     *      skip-reason, but DON'T overwrite the customer.
     *   4. If the customer record has been deleted / archived
     *      between queue and save, we mark saved_to_customer=1 with
     *      skip-reason ('customer missing'). The row leaves the
     *      reviewer queue but no DB write happens.
     */
    public function saveToCustomer(int $requestId, ?int $userId, bool $force = false): array
    {
        $row = DB::table(self::TABLE)->where('id', $requestId)->first();
        if (!$row) {
            return ['saved' => false, 'skipped_reason' => 'request not found', 'customer_id' => 0];
        }
        if ($row->saved_to_customer) {
            return ['saved' => false, 'skipped_reason' => 'already processed', 'customer_id' => (int) $row->customer_id];
        }
        if (!$row->replied_at || $row->reply_latitude === null || $row->reply_longitude === null) {
            return ['saved' => false, 'skipped_reason' => 'no reply captured', 'customer_id' => (int) $row->customer_id];
        }

        $customer = DB::table('t_crm_prod_customer')
            ->select('id', 'first_name', 'last_name', 'latitude', 'longitude',
                     'verified_location_url', 'verified_location_saved_at')
            ->where('id', $row->customer_id)
            ->first();

        if (!$customer) {
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'saved_to_customer'    => 1,
                'saved_at'             => now(),
                'saved_by'             => $userId,
                'save_skipped_reason'  => 'customer missing',
                'updated_at'           => now(),
            ]);
            return ['saved' => false, 'skipped_reason' => 'customer missing', 'customer_id' => (int) $row->customer_id];
        }

        // No-overwrite-newer-pin guard.
        if (!$force
            && config('qurbani.location_request.skip_save_if_newer_pin_exists', true)
            && $customer->verified_location_saved_at
            && $row->sent_at
            && strtotime($customer->verified_location_saved_at) > strtotime($row->sent_at)) {
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'saved_to_customer'    => 1,
                'saved_at'             => now(),
                'saved_by'             => $userId,
                'save_skipped_reason'  => 'customer has newer pin (' . $customer->verified_location_saved_at . ')',
                'updated_at'           => now(),
            ]);
            return [
                'saved'          => false,
                'skipped_reason' => 'customer has newer pin',
                'customer_id'    => (int) $row->customer_id,
            ];
        }

        // OK — write the pin and audit.
        DB::transaction(function () use ($row, $userId) {
            DB::table('t_crm_prod_customer')
                ->where('id', $row->customer_id)
                ->update([
                    'latitude'                   => $row->reply_latitude,
                    'longitude'                  => $row->reply_longitude,
                    'verified_location_saved_by' => $userId,
                    'verified_location_saved_at' => now(),
                    'updated_by'                 => $userId,
                    'updated_at'                 => now(),
                ]);
            DB::table(self::TABLE)->where('id', $row->id)->update([
                'saved_to_customer' => 1,
                'saved_at'          => now(),
                'saved_by'          => $userId,
                'save_skipped_reason' => null,
                'updated_at'        => now(),
            ]);
        });

        Log::info('QurbaniLocReq: saved location to customer', [
            'request_id'  => $row->id,
            'customer_id' => $row->customer_id,
            'lat'         => $row->reply_latitude,
            'lng'         => $row->reply_longitude,
            'saved_by'    => $userId,
        ]);

        return ['saved' => true, 'skipped_reason' => null, 'customer_id' => (int) $row->customer_id];
    }

    /**
     * Bulk-save every replied-but-not-yet-saved row matching a filter.
     * $filter accepts: ['batch_id' => uuid] OR ['ids' => [int,...]].
     *
     * Returns ['saved' => N, 'skipped' => N, 'details' => [...per row...]].
     */
    public function saveAllReplied(array $filter, ?int $userId, bool $force = false): array
    {
        $q = DB::table(self::TABLE)
            ->where('saved_to_customer', 0)
            ->whereNotNull('replied_at')
            ->whereNotNull('reply_latitude')
            ->whereNotNull('reply_longitude');

        if (!empty($filter['batch_id'])) {
            $q->where('batch_id', $filter['batch_id']);
        }
        if (!empty($filter['ids']) && is_array($filter['ids'])) {
            $q->whereIn('id', array_map('intval', $filter['ids']));
        }

        $ids = $q->pluck('id')->all();

        $saved = 0; $skipped = 0; $details = [];
        foreach ($ids as $id) {
            $res = $this->saveToCustomer((int) $id, $userId, $force);
            if ($res['saved']) { $saved++; }
            else { $skipped++; }
            $details[] = ['id' => $id] + $res;
        }
        return ['saved' => $saved, 'skipped' => $skipped, 'details' => $details];
    }

    /**
     * Mark a replied row as dismissed (won't be saved). Used when staff
     * decides a reply is junk or duplicate. We just stamp save_skipped_reason
     * with the user's note and flip saved_to_customer=1 to remove from queue.
     */
    public function dismissReply(int $requestId, ?int $userId, ?string $reason = null): bool
    {
        return DB::table(self::TABLE)
            ->where('id', $requestId)
            ->where('saved_to_customer', 0)
            ->update([
                'saved_to_customer'   => 1,
                'saved_at'            => now(),
                'saved_by'            => $userId,
                'save_skipped_reason' => 'dismissed by user' . ($reason ? ': ' . Str::limit($reason, 200, '') : ''),
                'updated_at'          => now(),
            ]) > 0;
    }

    // -----------------------------------------------------------------
    // QUERIES — used by controller + sidebar badge.
    // -----------------------------------------------------------------

    /**
     * Auto-dismiss any pending-review rows where the customer has
     * already become verified via ANOTHER channel (a manual pin on
     * the regular orders flow, a rider pin in the field, an admin
     * paste of coordinates, etc.).
     *
     * Why this exists: the toolbar badge + Reviewer drawer should
     * genuinely reflect "customers still needing a pin". If a
     * customer got pinned through any other code path between when
     * we sent them the template and when they replied, their
     * WhatsApp pin is redundant — re-saving would just overwrite
     * the (presumably more accurate) manual pin. So we silently
     * dismiss the row with an audit reason, no human action needed.
     *
     * Idempotent — safe to call before every read. Cheap, because
     * the WHERE clause's saved_to_customer=0 + replied_at IS NOT NULL
     * index match makes it touch only the few dozen relevant rows.
     */
    protected function autoDismissAlreadyVerified(): int
    {
        // We rely on a JOIN-style UPDATE for atomicity. Falls back
        // to a two-step (SELECT ids → UPDATE) on engines that don't
        // support multi-table UPDATE syntax, but MySQL/MariaDB —
        // which this repo uses — do, so this is fine.
        $now = now();
        return DB::table(self::TABLE . ' as r')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'r.customer_id')
            ->where('r.saved_to_customer', 0)
            ->whereNotNull('r.replied_at')
            ->whereNotNull('c.latitude')
            ->whereNotNull('c.longitude')
            ->where('c.latitude', '<>', 0)
            ->where('c.longitude', '<>', 0)
            ->update([
                'r.saved_to_customer'   => 1,
                'r.saved_at'            => $now,
                // saved_by stays NULL — this is a system auto-dismiss,
                // not a user action. The reason string is how audit
                // traces it back.
                'r.save_skipped_reason' => 'auto-dismissed: customer already verified via another channel',
                'r.updated_at'          => $now,
            ]);
    }

    /**
     * Topline summary for the toolbar badge: how many sent, replied
     * (and not yet saved), and the most recent batch.
     *
     * Scoped to the last 30 days by default so the badge doesn't keep
     * remembering ancient batches forever.
     */
    public function summary(int $windowDays = 30): array
    {
        // Run the auto-cleanup before counting so the badge always
        // matches reality — otherwise the badge could read "12 pending"
        // when 4 of those customers are already verified.
        try { $this->autoDismissAlreadyVerified(); } catch (\Throwable $e) {
            Log::debug('QurbaniLocReq: autoDismissAlreadyVerified failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        $since = now()->subDays($windowDays);

        $sent = (int) DB::table(self::TABLE)
            ->where('status', 'sent')
            ->where('sent_at', '>=', $since)
            ->count();

        $replied = (int) DB::table(self::TABLE)
            ->whereNotNull('replied_at')
            ->where('replied_at', '>=', $since)
            ->count();

        $pendingReview = (int) DB::table(self::TABLE)
            ->where('saved_to_customer', 0)
            ->whereNotNull('replied_at')
            ->whereNotNull('reply_latitude')
            ->where('replied_at', '>=', $since)
            ->count();

        $saved = (int) DB::table(self::TABLE)
            ->where('saved_to_customer', 1)
            ->whereNull('save_skipped_reason')
            ->where('saved_at', '>=', $since)
            ->count();

        $latestBatch = DB::table(self::TABLE)
            ->whereNotNull('batch_id')
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->select('batch_id', 'batch_label', 'created_at')
            ->first();

        return [
            'window_days'    => $windowDays,
            'sent'           => $sent,
            'replied'        => $replied,
            'pending_review' => $pendingReview,
            'saved'          => $saved,
            'latest_batch'   => $latestBatch ? [
                'id'    => $latestBatch->batch_id,
                'label' => $latestBatch->batch_label,
                'at'    => $latestBatch->created_at,
            ] : null,
        ];
    }

    /**
     * List rows that have a reply but haven't been saved yet — what the
     * Reviewer drawer renders. Joins t_crm_prod_customer for display
     * fields and to surface "customer already has a newer pin" warnings
     * in the UI.
     *
     * We run autoDismissAlreadyVerified() first so the list shown to
     * staff is genuinely "customers still needing a pin". Anyone who
     * picked up a verified location through any other channel (regular
     * order flow, rider pin, admin paste) is silently cleared from the
     * queue with an audit reason. The drawer also defensively re-filters
     * by `c.latitude IS NULL OR c.longitude IS NULL` so the rendering
     * stays sane even if the dismiss UPDATE failed for any reason.
     */
    public function pendingReview(array $filter = [], int $limit = 200): array
    {
        try { $this->autoDismissAlreadyVerified(); } catch (\Throwable $e) {
            Log::debug('QurbaniLocReq: autoDismissAlreadyVerified failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        $q = DB::table(self::TABLE . ' as r')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'r.customer_id')
            ->where('r.saved_to_customer', 0)
            ->whereNotNull('r.replied_at')
            ->whereNotNull('r.reply_latitude')
            ->whereNotNull('r.reply_longitude')
            // Defensive filter: even if autoDismissAlreadyVerified()
            // hasn't run yet (or failed), don't render replies for
            // customers who already have a verified lat/lng pin.
            ->where(function ($w) {
                $w->whereNull('c.latitude')
                  ->orWhereNull('c.longitude')
                  ->orWhere('c.latitude', '=', 0)
                  ->orWhere('c.longitude', '=', 0);
            });

        if (!empty($filter['batch_id'])) { $q->where('r.batch_id', $filter['batch_id']); }
        if (!empty($filter['region']))   { $q->where('r.qurbani_region', $filter['region']); }
        if (!empty($filter['day']))      { $q->where('r.qurbani_day', $filter['day']); }
        if (!empty($filter['days']))     {
            $q->where('r.replied_at', '>=', now()->subDays((int) $filter['days']));
        }

        $rows = $q->orderBy('r.replied_at', 'desc')
            ->limit($limit)
            ->get([
                'r.id', 'r.customer_id', 'r.order_id', 'r.line_item_id',
                'r.wa_phone', 'r.qurbani_day', 'r.qurbani_slot',
                'r.qurbani_region', 'r.qurbani_sub_region',
                'r.category_level_2', 'r.batch_id', 'r.batch_label',
                'r.sent_at', 'r.replied_at',
                'r.reply_latitude', 'r.reply_longitude',
                'r.reply_name', 'r.reply_address',
                DB::raw("TRIM(CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,''))) as customer_name"),
                'c.latitude as cust_lat', 'c.longitude as cust_lng',
                'c.verified_location_saved_at as cust_pinned_at',
            ]);

        return $rows->map(function ($r) {
            $hasNewerPin = $r->cust_pinned_at && $r->sent_at
                && strtotime($r->cust_pinned_at) > strtotime($r->sent_at);
            return [
                'id'             => (int) $r->id,
                'customer_id'    => (int) $r->customer_id,
                'customer_name'  => $r->customer_name,
                'order_id'       => $r->order_id,
                'line_item_id'   => $r->line_item_id,
                'wa_phone'       => $r->wa_phone,
                'context'        => array_filter([
                    'region'     => $r->qurbani_region,
                    'sub_region' => $r->qurbani_sub_region,
                    'day'        => $r->qurbani_day,
                    'slot'       => $r->qurbani_slot,
                    'category'   => $r->category_level_2,
                ]),
                'batch_id'       => $r->batch_id,
                'batch_label'    => $r->batch_label,
                'sent_at'        => $r->sent_at,
                'replied_at'     => $r->replied_at,
                'lat'            => (float) $r->reply_latitude,
                'lng'            => (float) $r->reply_longitude,
                'reply_name'     => $r->reply_name,
                'reply_address'  => $r->reply_address,
                'existing_pin'   => $hasNewerPin ? [
                    'lat'       => $r->cust_lat ? (float) $r->cust_lat : null,
                    'lng'       => $r->cust_lng ? (float) $r->cust_lng : null,
                    'pinned_at' => $r->cust_pinned_at,
                ] : null,
                'has_newer_pin'  => $hasNewerPin,
            ];
        })->all();
    }

    /**
     * Per-batch breakdown for the bulk-send progress panel.
     *
     * Calls autoDismissAlreadyVerified() first so the "reviewable"
     * count surfaced on the bulk modal dashboard agrees with what the
     * Reviewer drawer will actually render (no off-by-N confusion).
     */
    public function batchDetails(string $batchId): array
    {
        try { $this->autoDismissAlreadyVerified(); } catch (\Throwable $e) {
            Log::debug('QurbaniLocReq: autoDismissAlreadyVerified failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        $rows = DB::table(self::TABLE)
            ->where('batch_id', $batchId)
            ->get(['id', 'status', 'replied_at', 'saved_to_customer', 'save_skipped_reason']);

        $total      = $rows->count();
        $queued     = $rows->where('status', 'queued')->count();
        $sending    = $rows->where('status', 'sending')->count();
        $sent       = $rows->where('status', 'sent')->count();
        $failed     = $rows->where('status', 'failed')->count();
        $skipped    = $rows->where('status', 'skipped')->count();
        $replied    = $rows->whereNotNull('replied_at')->count();
        $saved      = $rows->where('saved_to_customer', 1)
                           ->whereNull('save_skipped_reason')->count();
        $reviewable = $rows->where('saved_to_customer', 0)
                           ->whereNotNull('replied_at')->count();

        $info = DB::table(self::TABLE)
            ->where('batch_id', $batchId)
            ->select('batch_label', 'created_at')
            ->orderBy('id')->limit(1)->first();

        return [
            'batch_id'    => $batchId,
            'batch_label' => $info->batch_label ?? null,
            'created_at'  => $info->created_at ?? null,
            'total'       => $total,
            'queued'      => $queued,
            'sending'     => $sending,
            'sent'        => $sent,
            'failed'      => $failed,
            'skipped'     => $skipped,
            'replied'     => $replied,
            'saved'       => $saved,
            'reviewable'  => $reviewable,
        ];
    }

    /**
     * Look up a customer's most recent active request (any status) so the
     * Qurbani Orders card can show "Sent X ago, waiting for reply" or
     * "Replied — pending review" inline next to the Request-Location button.
     */
    public function latestForCustomers(array $customerIds): array
    {
        if (empty($customerIds)) { return []; }

        $rows = DB::table(self::TABLE)
            ->whereIn('customer_id', $customerIds)
            ->select(
                'customer_id', 'id', 'status', 'sent_at', 'queued_at',
                'replied_at', 'saved_to_customer', 'save_skipped_reason',
                'reply_latitude'
            )
            ->orderBy('customer_id')
            ->orderBy('id', 'desc')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            // Keep only the FIRST (most recent) hit per customer.
            if (!isset($out[$r->customer_id])) {
                $derived = $this->deriveDisplayStatus($r);
                $out[$r->customer_id] = [
                    'request_id'   => (int) $r->id,
                    'status'       => $r->status,
                    'display'      => $derived,
                    'sent_at'      => $r->sent_at,
                    'queued_at'    => $r->queued_at,
                    'replied_at'   => $r->replied_at,
                    'saved'        => (int) $r->saved_to_customer === 1
                                       && empty($r->save_skipped_reason),
                ];
            }
        }
        return $out;
    }

    protected function deriveDisplayStatus($row): string
    {
        if ($row->replied_at) {
            if ((int) $row->saved_to_customer === 1) {
                return empty($row->save_skipped_reason) ? 'saved' : 'dismissed';
            }
            return 'replied_pending';
        }
        if ($row->status === 'sent')    { return 'sent_no_reply'; }
        if ($row->status === 'failed')  { return 'failed'; }
        if ($row->status === 'sending') { return 'sending'; }
        if ($row->status === 'skipped') { return 'skipped'; }
        return 'queued';
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Resolve the WhatsApp-formatted phone (92XXXXXXXXXX) for a customer,
     * preferring whatever the caller supplied and falling back to the
     * customer's stored phone. Returns null if nothing usable found.
     */
    protected function resolveCustomerPhone(int $customerId, ?string $supplied = null): ?string
    {
        if ($supplied) {
            $p = $this->wa->formatPhone($supplied);
            if ($p && strlen($p) >= 12) { return $p; }
        }
        $cust = DB::table('t_crm_prod_customer')
            ->select('phone')
            ->where('id', $customerId)
            ->first();
        if (!$cust || empty($cust->phone)) { return null; }
        $p = $this->wa->formatPhone($cust->phone);
        return ($p && strlen($p) >= 12) ? $p : null;
    }
}
