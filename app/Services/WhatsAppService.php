<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\MessageModel;
use App\Services\FirebaseService;

class WhatsAppService
{
    protected string $apiBase;
    protected string $phoneNumberId;
    protected string $accessToken;
    protected string $appSecret;
    protected string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api_version') ?? 'v21.0';
        $this->apiBase = (config('whatsapp.api_base_url') ?? 'https://graph.facebook.com') . '/' . $this->apiVersion;
        $this->phoneNumberId = config('whatsapp.phone_number_id') ?? '';
        $this->accessToken = config('whatsapp.access_token') ?? '';
        $this->appSecret = config('whatsapp.app_secret') ?? '';
    }

    /**
     * Verify webhook signature from Meta
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->appSecret)) {
            Log::warning('WhatsApp: App secret not configured, skipping signature verification');
            return true;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->appSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Send a text message
     */
    public function sendTextMessage(string $to, string $text): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Send a template message.
     * $headerParams can be:
     *   - Array of strings for text header variables
     *   - Array with a single ['type' => 'image', 'image' => ['link' => '...']] for image header
     *   - Array with a single ['type' => 'document', 'document' => ['link' => '...', 'filename' => '...']] for document header
     */
    public function sendTemplateMessage(string $to, string $templateName, string $language = 'en', array $bodyParams = [], array $headerParams = [], array $buttons = []): array
    {
        $components = [];

        if (!empty($headerParams)) {
            $isRichHeader = isset($headerParams[0]['type']) && in_array($headerParams[0]['type'], ['image', 'document', 'video']);
            if ($isRichHeader) {
                $components[] = ['type' => 'header', 'parameters' => $headerParams];
            } else {
                $headerParameters = array_map(fn($p) => ['type' => 'text', 'text' => $p], $headerParams);
                $components[] = ['type' => 'header', 'parameters' => $headerParameters];
            }
        }

        if (!empty($bodyParams)) {
            $bodyParameters = array_map(fn($p) => ['type' => 'text', 'text' => $p], $bodyParams);
            $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
        }

        if (!empty($buttons)) {
            foreach ($buttons as $index => $buttonPayload) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'quick_reply',
                    'index' => (string) $index,
                    'parameters' => [['type' => 'payload', 'payload' => $buttonPayload]],
                ];
            }
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Send a document (e.g. invoice PDF) with optional caption
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'link' => $documentUrl,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Send an image with optional caption
     */
    public function sendImage(string $to, string $imageUrl, string $caption = ''): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => ['link' => $imageUrl],
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Upload a local media file to Meta's resumable media endpoint.
     * Returns the Meta media_id string (used afterwards as audio.id /
     * document.id in a /messages call), or null on failure.
     *
     * This is the OUTBOUND counterpart of downloadMedia(). We keep the
     * flow deliberately simple: one multipart POST, no chunking. Voice
     * notes recorded on the mobile app stay well under Meta's 16 MB
     * audio cap (~1 MB per minute at AAC 128 kbps, and the mobile side
     * is capped at 5 minutes). If we ever add larger media we can swap
     * this for Meta's resumable upload API without changing callers.
     *
     * The caller owns the local file — we don't delete it here because
     * the outbound message row needs to keep a pointer for replay in
     * our own UI.
     */
    public function uploadMediaToWhatsApp(string $localPath, string $mimeType): ?string
    {
        try {
            if (!file_exists($localPath)) {
                Log::error('WhatsApp: uploadMediaToWhatsApp - file missing', ['path' => $localPath]);
                return null;
            }

            $url = "{$this->apiBase}/{$this->phoneNumberId}/media";

            // messaging_product + type are required by Meta; the file is
            // attached under the field name "file".
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->attach('file', file_get_contents($localPath), basename($localPath), ['Content-Type' => $mimeType])
                ->asMultipart()
                ->post($url, [
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ]);

            if (!$response->successful()) {
                Log::error('WhatsApp: Media upload failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'mime' => $mimeType,
                ]);
                return null;
            }

            $mediaId = $response->json('id');
            if (!$mediaId) {
                Log::error('WhatsApp: Media upload returned no id', ['body' => $response->body()]);
                return null;
            }

            return (string) $mediaId;
        } catch (\Exception $e) {
            Log::error('WhatsApp: uploadMediaToWhatsApp exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send an audio message (voice note) using an already-uploaded
     * Meta media_id. The recipient sees a playable audio message
     * inside WhatsApp. With AAC/M4A they get a standard audio player;
     * with OGG/OPUS it renders as the waveform voice-note bubble —
     * same API call either way, just different source MIME.
     */
    public function sendAudio(string $to, string $mediaId): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'audio',
            'audio'             => ['id' => $mediaId],
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Mark a message as read (sends blue ticks to customer)
     */
    public function markAsRead(string $messageId): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Download media from WhatsApp (voice notes, images, documents, etc.)
     * Returns local file path or null on failure.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        try {
            // Step 1: Get the media URL
            $response = Http::withToken($this->accessToken)
                ->get("{$this->apiBase}/{$mediaId}");

            if (!$response->successful()) {
                Log::error('WhatsApp: Failed to get media URL', ['media_id' => $mediaId, 'response' => $response->body()]);
                return null;
            }

            $mediaUrl = $response->json('url');
            $mimeType = $response->json('mime_type', 'application/octet-stream');

            // Step 2: Download the actual file
            $fileResponse = Http::withToken($this->accessToken)
                ->get($mediaUrl);

            if (!$fileResponse->successful()) {
                Log::error('WhatsApp: Failed to download media file', ['media_id' => $mediaId]);
                return null;
            }

            // Determine extension from mime type
            $extension = $this->getExtensionFromMime($mimeType);
            $filename = $mediaId . '.' . $extension;
            $path = config('whatsapp.media_path') . '/' . date('Y/m') . '/' . $filename;

            Storage::disk(config('whatsapp.media_disk'))->put($path, $fileResponse->body());

            return $path;

        } catch (\Exception $e) {
            Log::error('WhatsApp: Media download failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Process an incoming webhook payload from Meta
     */
    public function processIncomingWebhook(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $contacts = $value['contacts'] ?? [];
                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];

                // Process incoming messages
                foreach ($messages as $msg) {
                    $this->handleIncomingMessage($msg, $contacts);
                }

                // Process status updates (sent, delivered, read, failed)
                foreach ($statuses as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }
    }

    /**
     * Handle a single incoming message
     */
    /**
     * A rider tapping the "confirm" quick-reply on the shift_reminder template →
     * acknowledge their shift change(s), identical to the in-app confirm endpoint
     * (sets acknowledged_at on ALL their pending rows). Resolution:
     *   1. Precise: the tapped template's context.id → the shift_assigned automation
     *      log → its dedup_key "shift:{userId}:{assignmentId}". If context.id is
     *      present but is NOT a shift template, we stop (the tap was on something
     *      else — never phone-guess in that case).
     *   2. Fallback (no context.id at all): match the sender's number to a rider by
     *      last-10 digits of t_ops_rider_profile.phone.
     * Guard: only acts when a NOTIFIED, UNACKNOWLEDGED, not-past assignment exists,
     * so a stray "confirm" tap acknowledges nothing.
     */
    protected function maybeConfirmShiftFromReply(string $from, array $msg, string $content, string $type): void
    {
        // Is this a "confirm"?
        //  • button/interactive: label or payload contains it.
        //  • typed text: the message must essentially BE the word — "confirm",
        //    "Confirmed", "ok/yes confirm" (+ punctuation/emoji). A longer sentence
        //    ("I cannot confirm tomorrow") deliberately does NOT match.
        if ($type === 'text') {
            $c = trim($content);
            if ($c === '' || mb_strlen($c) > 40
                || !preg_match('/^\W*((ok(ay)?|yes|g|ji)\s+)?confirm(ed)?\W*$/i', $c)) {
                return;
            }
        } else {
            $payload = $type === 'button'
                ? (string) ($msg['button']['payload'] ?? '')
                : (string) ($msg['interactive']['button_reply']['id'] ?? '');
            if (stripos($content . ' ' . $payload, 'confirm') === false) {
                return;
            }
        }

        // Resolve WHICH rider. Precise path first: the replied-to template's
        // context.id → our automation log. If that maps to a DIFFERENT rule's
        // template (an order confirm etc.) → stop, it's positively not a shift
        // reply. If it maps to NOTHING (manual send / no context) → fall through
        // to the phone match.
        $userId = null;
        $ctxId = $msg['context']['id'] ?? null;
        if ($ctxId && \Illuminate\Support\Facades\Schema::hasTable('t_wa_automation_log')) {
            $log = \Illuminate\Support\Facades\DB::table('t_wa_automation_log')
                ->where('wa_message_id', $ctxId)
                ->orderByDesc('id')->first();
            if ($log) {
                if (($log->rule_key ?? '') !== 'shift_assigned') {
                    return; // reply to some other automation's template — not ours
                }
                if (preg_match('/^shift:(\d+):/', (string) ($log->dedup_key ?? ''), $m)) {
                    $userId = (int) $m[1];
                }
            }
        }
        if (!$userId) {
            // Best-effort phone match (last 10 digits, driver-agnostic) against the
            // unified rider phone.
            $last10 = substr(preg_replace('/\D/', '', $from), -10);
            if (strlen($last10) !== 10) {
                return;
            }
            $profiles = \Illuminate\Support\Facades\DB::table('t_ops_rider_profile')
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->get(['user_id', 'phone']);
            foreach ($profiles as $p) {
                if (substr(preg_replace('/\D/', '', (string) $p->phone), -10) === $last10) {
                    $userId = (int) $p->user_id;
                    break;
                }
            }
        }
        if (!$userId) {
            return;
        }

        // Only acknowledge when a pending (notified, unacknowledged) row exists —
        // the SAME condition the in-app confirm uses (acknowledgeShift), so both
        // channels agree. Deliberately NOT date-restricted: a single-day change
        // confirmed the next morning is still a legitimate confirm (the earlier
        // "effective_to >= today" guard silently dropped exactly those, so a
        // WhatsApp tap did nothing while the in-app confirm worked). The
        // notified_at + unacknowledged pair already guarantees a real pending
        // change, so a stray "confirm" from a rider with nothing pending no-ops.
        $q = \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('notified_at')->whereNull('acknowledged_at');
        $count = (clone $q)->count();
        if ($count === 0) {
            return;
        }
        $q->update(['acknowledged_at' => now()]);
        Log::info('Shift confirmed via WhatsApp reply', [
            'user_id' => $userId, 'rows' => $count, 'from' => $from, 'type' => $type,
        ]);

        // Audit row in the automations Activity log. Safe: dedup only counts
        // sent/failed rows, so this can never block a future send.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('t_wa_automation_log')) {
                // NOTE: t_wa_automation_log has created_at only (no updated_at).
                \Illuminate\Support\Facades\DB::table('t_wa_automation_log')->insert([
                    'rule_key' => 'shift_assigned',
                    'trigger_event' => 'shift.confirmed',
                    'dedup_key' => 'shift:' . $userId . ':confirm',
                    'status' => 'confirmed_reply',
                    'wa_message_id' => $msg['id'] ?? null,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // audit only — never let it affect the ack
        }
    }

    protected function handleIncomingMessage(array $msg, array $contacts): void
    {
        try {
            $waMessageId = $msg['id'] ?? null;
            $from = $msg['from'] ?? null;
            $timestamp = $msg['timestamp'] ?? null;
            $type = $msg['type'] ?? 'text';

            if (!$waMessageId || !$from) {
                return;
            }

            // Skip duplicate messages
            if (MessageModel::where('wa_message_id', $waMessageId)->exists()) {
                return;
            }

            // Get contact name from the contacts array
            $contactName = null;
            foreach ($contacts as $contact) {
                if (($contact['wa_id'] ?? '') === $from) {
                    $contactName = $contact['profile']['name'] ?? null;
                    break;
                }
            }

            // Find or create conversation
            $conversation = $this->findOrCreateConversation($from, $contactName);

            // Extract content based on message type
            $content = null;
            $mediaUrl = null;
            $mediaMimeType = null;
            $metadata = null;

            switch ($type) {
                case 'text':
                    $content = $msg['text']['body'] ?? '';
                    break;

                case 'image':
                    $content = $msg['image']['caption'] ?? '';
                    $mediaPath = $this->downloadMedia($msg['image']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['image']['mime_type'] ?? 'image/jpeg';
                    break;

                case 'audio':
                    $mediaPath = $this->downloadMedia($msg['audio']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['audio']['mime_type'] ?? 'audio/ogg';
                    $content = '[Voice Note]';
                    break;

                case 'video':
                    $content = $msg['video']['caption'] ?? '[Video]';
                    $mediaPath = $this->downloadMedia($msg['video']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['video']['mime_type'] ?? 'video/mp4';
                    break;

                case 'document':
                    $content = $msg['document']['caption'] ?? $msg['document']['filename'] ?? '[Document]';
                    $mediaPath = $this->downloadMedia($msg['document']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['document']['mime_type'] ?? 'application/pdf';
                    break;

                case 'location':
                    $lat = $msg['location']['latitude'] ?? 0;
                    $lng = $msg['location']['longitude'] ?? 0;
                    $locName = $msg['location']['name'] ?? '';
                    $locAddress = $msg['location']['address'] ?? '';
                    $content = $locName ?: $locAddress ?: 'Shared location';
                    $metadata = json_encode([
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'name' => $locName,
                        'address' => $locAddress,
                    ]);
                    break;

                case 'reaction':
                    $content = $msg['reaction']['emoji'] ?? '';
                    $metadata = json_encode(['reacted_message_id' => $msg['reaction']['message_id'] ?? null]);
                    $type = 'reaction';
                    break;

                case 'button':
                    $content = $msg['button']['text'] ?? '';
                    $metadata = json_encode(['button_payload' => $msg['button']['payload'] ?? null]);
                    break;

                case 'interactive':
                    $interactive = $msg['interactive'] ?? [];
                    if (isset($interactive['button_reply'])) {
                        $content = $interactive['button_reply']['title'] ?? '';
                        $metadata = json_encode(['button_id' => $interactive['button_reply']['id'] ?? null]);
                    } elseif (isset($interactive['list_reply'])) {
                        $content = $interactive['list_reply']['title'] ?? '';
                        $metadata = json_encode(['list_id' => $interactive['list_reply']['id'] ?? null]);
                    }
                    break;

                default:
                    $content = "[Unsupported message type: {$type}]";
                    break;
            }

            // Save the message
            $savedMessage = MessageModel::create([
                'conversation_id' => $conversation->id,
                'wa_message_id' => $waMessageId,
                'direction' => 'inbound',
                'type' => $type,
                'content' => $content,
                'media_url' => $mediaUrl,
                'media_mime_type' => $mediaMimeType,
                'metadata' => $metadata,
                'status' => 'received',
                'created_at' => $timestamp ? date('Y-m-d H:i:s', (int)$timestamp) : now(),
            ]);

            // Jun-2026 — link a quick-reply button/list tap back to the SPECIFIC
            // order it answers (Confirm / Split / Cancel on an order-received
            // template). WhatsApp gives us context.id (the template they tapped);
            // we map it to the order via the automation log and stamp the
            // order_number so the Shopify approval view shows the choice on the
            // exact order. Fully guarded + non-fatal (falls back to per-customer).
            if (in_array($type, ['button', 'interactive'], true) && $savedMessage) {
                try {
                    $ctxId = $msg['context']['id'] ?? null;
                    if ($ctxId && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
                        $orderNo = \App\Services\WhatsApp\OrderReplyService::resolveOrderForReplyContext($ctxId);
                        if ($orderNo) {
                            \Illuminate\Support\Facades\DB::table('t_wa_messages')
                                ->where('id', $savedMessage->id)
                                ->update(['related_order_number' => $orderNo]);
                        }
                    }
                } catch (\Throwable $linkErr) {
                    Log::debug('WhatsApp: reply→order link skipped (non-fatal)', ['error' => $linkErr->getMessage()]);
                }
            }

            // Jul-2026 — shift-change confirm: a quick-reply "confirm" TAP or a short
            // TYPED "confirm" from a rider acknowledges their pending shift change
            // (same acknowledged_at as the in-app confirm). Guarded + non-fatal; only
            // acts when the sender maps to a rider WITH a pending change, so stray
            // messages do nothing. (Typed replies matter: riders often type the word
            // instead of tapping the button.)
            if ($savedMessage && in_array($type, ['button', 'interactive', 'text'], true)) {
                try {
                    $this->maybeConfirmShiftFromReply($from, $msg, (string) $content, $type);
                } catch (\Throwable $shiftErr) {
                    Log::debug('WhatsApp: shift-confirm skipped (non-fatal)', ['error' => $shiftErr->getMessage()]);
                }
            }

            // Jun-2026 — Online payment auto-matching. If this inbound is an
            // image (a likely bank-transfer screenshot) from a customer who has
            // a pending online order, drop a cheap "payment signal" row. The
            // expensive Gemini read + matching happens later in the scheduled
            // payments:process-signals command, keeping this webhook fast.
            // Fully gated by the feature switch; non-fatal on any error.
            try {
                $this->maybeQueuePaymentSignal($conversation, $savedMessage, $type, $mediaMimeType, $mediaUrl);
                // Drive the payment workers off this webhook (after the response
                // is flushed) since the shared host can't be trusted to run the
                // scheduler. Self-throttled to ~1 run/min by a Cache lock.
                app(\App\Services\Payments\PaymentSignalAutoRunner::class)->fireFromRequest();
            } catch (\Exception $paySigErr) {
                Log::debug('WhatsApp: payment-signal queue skipped (non-fatal)', ['error' => $paySigErr->getMessage()]);
            }

            // Update conversation
            $conversation->update([
                'last_message_at' => now(),
                'last_customer_message_at' => now(),
                'unread_count' => $conversation->unread_count + 1,
                'status' => 'active',
            ]);

            // Campaign reply tracking: if this conversation is linked to a known
            // customer, stamp replied_at on any active campaign rows where the
            // customer was already sent a message (within that campaign's
            // tracking window). Non-fatal on any error.
            try {
                if ($conversation->customer_id) {
                    $this->stampCampaignReplies((int) $conversation->customer_id);
                }
            } catch (\Exception $campaignErr) {
                Log::debug('WhatsApp: Campaign reply tracking failed (non-fatal)', ['error' => $campaignErr->getMessage()]);
            }

            // Qurbani auto-classification runs AFTER the inbound message is
            // persisted so the new message is part of the lookback window.
            // Non-fatal on any error.
            try {
                app(\App\Services\QurbaniClassifier::class)->classify($conversation->fresh());
            } catch (\Exception $classifyErr) {
                Log::debug('WhatsApp: Qurbani classify failed (non-fatal)', ['error' => $classifyErr->getMessage()]);
            }

            // Phase 6 (May-2026) — Qurbani location-request reply linking.
            // If this inbound message is a location pin, try to match it
            // to an outstanding t_crm_qurbani_loc_request row so it shows
            // up in the Reviewer drawer on the Orders page. We pass the
            // conversation's customer_id as a SAFETY cross-check — the
            // service refuses to link if it disagrees with the request's
            // stored customer_id (prevents Customer B's pin landing on
            // Customer A). Non-fatal on any error.
            if ($type === 'location') {
                try {
                    app(\App\Services\QurbaniLocationRequestService::class)->recordReply(
                        $msg['context']['id'] ?? null,
                        $from,
                        (float) ($msg['location']['latitude'] ?? 0),
                        (float) ($msg['location']['longitude'] ?? 0),
                        (string) ($msg['location']['name'] ?? ''),
                        (string) ($msg['location']['address'] ?? ''),
                        $conversation->customer_id ? (int) $conversation->customer_id : null,
                        $waMessageId
                    );
                } catch (\Exception $locReqErr) {
                    Log::debug('WhatsApp: QurbaniLocReq link failed (non-fatal)', [
                        'error' => $locReqErr->getMessage(),
                    ]);
                }

                // Jun-2026 — Open Orders "Get Customer Locations": auto-save a
                // native pin onto the customer when their most recent location
                // request was a regular (non-qurbani) one and they have no pin
                // yet. Gated inside the service so the qurbani flow is untouched.
                try {
                    app(\App\Services\Location\OpenOrderLocationService::class)->autoSaveInbound(
                        $conversation,
                        (float) ($msg['location']['latitude'] ?? 0),
                        (float) ($msg['location']['longitude'] ?? 0),
                        null
                    );
                } catch (\Throwable $ooLocErr) {
                    Log::debug('WhatsApp: OpenOrderLoc pin auto-save failed (non-fatal)', [
                        'error' => $ooLocErr->getMessage(),
                    ]);
                }
            }

            // Phase 6 (May-2026) — Maps-URL fallback. Some customers reply
            // with a "https://maps.app.goo.gl/..." link as plain text
            // instead of attaching a real WhatsApp location pin. The
            // service follows the shortlink, extracts lat/lng, and feeds
            // the result through the same reply pipeline so the row lands
            // in the Reviewer drawer next to the genuine pins. If the
            // URL doesn't yield coords we silently no-op — the message
            // itself is already saved above and staff can still use the
            // "Set as Verified Location" button on /messages to handle
            // it manually. Non-fatal on any error.
            if ($type === 'text' && !empty($content)) {
                try {
                    app(\App\Services\QurbaniLocationRequestService::class)->recordUrlReply(
                        $msg['context']['id'] ?? null,
                        $from,
                        (string) $content,
                        $conversation->customer_id ? (int) $conversation->customer_id : null,
                        $waMessageId
                    );
                } catch (\Exception $urlReplyErr) {
                    Log::debug('WhatsApp: QurbaniLocReq URL link failed (non-fatal)', [
                        'error' => $urlReplyErr->getMessage(),
                    ]);
                }

                // Jun-2026 — Open Orders "Get Customer Locations": resolve a
                // Maps URL / plain coordinates sent as text and auto-save it
                // (same gating as the native-pin path above).
                try {
                    app(\App\Services\Location\OpenOrderLocationService::class)->autoSaveInboundText(
                        $conversation,
                        (string) $content
                    );
                } catch (\Throwable $ooLocTextErr) {
                    Log::debug('WhatsApp: OpenOrderLoc text auto-save failed (non-fatal)', [
                        'error' => $ooLocTextErr->getMessage(),
                    ]);
                }
            }

            // NOTE: Read receipts are NO LONGER sent automatically on webhook
            // receipt. They are sent only when a team member actually reads the
            // message in the UI (web or mobile). This prevents customers from
            // seeing the blue double-tick the instant their message hits our
            // server. The send is triggered from markConversationReadForUser().

            // Send push notification to staff
            try {
                $senderName = $contactName ?? $conversation->display_name ?? $from;
                $preview = mb_substr($content ?? '[Media]', 0, 200);
                app(FirebaseService::class)->notifyNewWhatsAppMessage($senderName, $preview, $conversation->id);
            } catch (\Exception $pushErr) {
                Log::debug('WhatsApp: Push notification failed (non-fatal)', ['error' => $pushErr->getMessage()]);
            }

            // Auto-reply (Apr-2026). Fires inside the 24h customer-care window
            // that this very inbound just opened, so it's a free-form text
            // send (no Meta template approval required). All checks —
            // feature toggle, time/day/date matching, per-conversation
            // cooldown — live inside maybeSendAutoReply. Non-fatal on any
            // error because a failed auto-reply must NEVER prevent the
            // inbound from being saved or notifying staff.
            try {
                $this->maybeSendAutoReply($conversation, $from, $type);
            } catch (\Exception $autoErr) {
                Log::debug('WhatsApp: Auto-reply skipped (non-fatal)', ['error' => $autoErr->getMessage()]);
            }

            Log::info('WhatsApp: Incoming message saved', [
                'conversation_id' => $conversation->id,
                'from' => $from,
                'type' => $type,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to process incoming message', [
                'error' => $e->getMessage(),
                'message_data' => $msg,
            ]);
        }
    }

    /**
     * Jun-2026 — Create a pending "payment signal" row for an inbound image
     * that is likely a bank-transfer screenshot, so the scheduled
     * payments:process-signals command can read it with Gemini and match it to
     * a pending online order.
     *
     * Cheap by design: this only INSERTs a row (no Gemini call here). It is
     * heavily pre-filtered to keep cost down — we skip unless the conversation
     * is mapped to a customer who has at least one unpaid/partial online order
     * within the match window. The actual image read is deferred.
     */
    protected function maybeQueuePaymentSignal($conversation, $savedMessage, string $type, ?string $mimeType, ?string $mediaUrl): void
    {
        if (!config('payment_signals.enabled')) {
            return;
        }
        if (!$savedMessage) {
            return;
        }

        // Only images (an image-typed message, or a document whose MIME is an
        // image). Non-images are silently ignored (no log — far too noisy).
        $isImage = $type === 'image'
            || ($type === 'document' && $mimeType && str_starts_with($mimeType, 'image/'));
        if (!$isImage) {
            return;
        }

        // From here on we KNOW it's an image, so every skip is worth a one-line
        // debug note — this is what makes "why didn't X get a signal?" instantly
        // answerable from the log.
        $logCtx = ['wa_message_id' => $savedMessage->id, 'conversation_id' => $conversation->id];

        // The media must have downloaded (image_path is required for the Gemini read).
        if (!$mediaUrl) {
            Log::debug('PaymentSignal: image skipped — media download failed (no image_path)', $logCtx);
            return;
        }

        // Must be a mapped customer (the conversation has to resolve to a customer).
        $customerId = $conversation->customer_id ?? null;
        if (!$customerId) {
            Log::debug('PaymentSignal: image skipped — conversation not mapped to a customer', $logCtx);
            return;
        }

        // Pre-filter: customer must have a still-owed order in the window.
        $windowDays = (int) config('payment_signals.match_window_days', 30);
        $methods = config('payment_signals.online_payment_methods', ['online', 'bank_transfer']);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);

        $pendingQuery = \DB::table('t_crm_prod_order')
            ->where('customer_id', $customerId)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            ->where('order_date', '>=', now()->subDays($windowDays)->startOfDay());

        // A bank screenshot is itself proof of an online payment, so by default
        // we do NOT require the order to already be tagged online/bank_transfer
        // (delivered orders are often created as 'cash' and paid online later).
        if (config('payment_signals.require_online_payment_method', false)) {
            $pendingQuery->whereIn('payment_method', $methods);
        }

        if (!$pendingQuery->exists()) {
            Log::debug('PaymentSignal: image skipped — no unpaid/partial order in window for customer', $logCtx + [
                'customer_id' => $customerId,
                'window_days' => $windowDays,
                'require_online_method' => (bool) config('payment_signals.require_online_payment_method', false),
            ]);
            return;
        }

        // Idempotency: one signal per WhatsApp message.
        $alreadyQueued = \App\Models\FIN\PaymentSignal::where('wa_message_id', $savedMessage->id)->exists();
        if ($alreadyQueued) {
            return;
        }

        \App\Models\FIN\PaymentSignal::create([
            'source'             => \App\Models\FIN\PaymentSignal::SOURCE_WHATSAPP,
            'wa_message_id'      => $savedMessage->id,
            'wa_conversation_id' => $conversation->id,
            'image_path'         => $mediaUrl,
            'status'             => \App\Models\FIN\PaymentSignal::STATUS_NEW,
        ]);

        Log::info('PaymentSignal: queued WhatsApp image for matching', $logCtx + ['customer_id' => $customerId]);
    }

    /**
     * Handle status update (sent, delivered, read, failed)
     */
    protected function handleStatusUpdate(array $status): void
    {
        try {
            $waMessageId = $status['id'] ?? null;
            $newStatus = $status['status'] ?? null;

            if (!$waMessageId || !$newStatus) {
                return;
            }

            $message = MessageModel::where('wa_message_id', $waMessageId)->first();
            if (!$message) {
                return;
            }

            $statusMap = [
                'sent' => 'sent',
                'delivered' => 'delivered',
                'read' => 'read',
                'failed' => 'failed',
            ];

            $mappedStatus = $statusMap[$newStatus] ?? null;
            if (!$mappedStatus) {
                return;
            }

            $updates = ['status' => $mappedStatus, 'status_updated_at' => now()];

            if ($mappedStatus === 'failed') {
                $errors = $status['errors'] ?? [];
                if (!empty($errors)) {
                    $updates['error_code'] = $errors[0]['code'] ?? null;
                    $updates['error_message'] = $errors[0]['title'] ?? ($errors[0]['message'] ?? null);
                }
                Log::error('WhatsApp: Message delivery failed', [
                    'wa_message_id' => $waMessageId,
                    'conversation_id' => $message->conversation_id,
                    'error_code' => $updates['error_code'] ?? null,
                    'error_message' => $updates['error_message'] ?? null,
                    'errors' => $errors,
                ]);
            }

            $message->update($updates);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to process status update', [
                'error' => $e->getMessage(),
                'status_data' => $status,
            ]);
        }
    }

    /**
     * For a customer who just sent us an inbound message, stamp replied_at
     * on any t_crm_campaign_customers rows where:
     *   - this customer was sent a campaign message (status='sent')
     *   - replied_at is still null
     *   - now() is within the campaign's tracking_window_days after sent_at
     * This lets the campaign stats page compute reply rates.
     */
    protected function stampCampaignReplies(int $customerId): void
    {
        $now = now();

        $rows = \DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_campaigns as c', 'cc.campaign_id', '=', 'c.id')
            ->where('cc.customer_id', $customerId)
            ->where('cc.status', 'sent')
            ->whereNull('cc.replied_at')
            ->whereNotNull('cc.sent_at')
            ->whereRaw('DATE_ADD(cc.sent_at, INTERVAL c.tracking_window_days DAY) >= ?', [$now])
            ->select('cc.id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        \DB::table('t_crm_campaign_customers')
            ->whereIn('id', $rows->pluck('id')->all())
            ->update(['replied_at' => $now]);
    }

    /**
     * Find existing conversation or create a new one by phone number
     */
    public function findOrCreateConversation(string $waPhone, ?string $contactName = null): ConversationModel
    {
        // Normalize: ensure phone starts with country code, no +
        $waPhone = ltrim($waPhone, '+');

        $conversation = ConversationModel::where('wa_phone', $waPhone)->first();

        if ($conversation) {
            if ($contactName && !$conversation->wa_contact_name) {
                $conversation->update(['wa_contact_name' => $contactName]);
            }
            return $conversation;
        }

        // Try to match to an existing customer by phone
        $normalizedPhone = substr($waPhone, -10); // Last 10 digits (Pakistan format)
        $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->first();

        return ConversationModel::create([
            'customer_id' => $customer?->id,
            'wa_phone' => $waPhone,
            'wa_contact_name' => $contactName,
            'status' => 'active',
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Try to link a customer to a conversation that currently has no linked customer.
     * Looks up the customer by the conversation's phone (last 10 digits, ignoring merged ones).
     * Safe to call repeatedly — it only updates if a match is found and conversation is unlinked.
     *
     * Returns the customer_id that was linked, or null if nothing changed.
     */
    public function relinkConversationCustomer(ConversationModel $conversation): ?int
    {
        if ($conversation->customer_id) {
            return $conversation->customer_id;
        }
        if (!$conversation->wa_phone) {
            return null;
        }

        try {
            $normalizedPhone = substr(ltrim($conversation->wa_phone, '+'), -10);
            // Prefer the most recently updated unmerged customer matching this phone,
            // so newly-created customers (which is the common case for late-link) win.
            $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id')
                ->orderByDesc('updated_at')
                ->first();

            if ($customer) {
                $conversation->update(['customer_id' => $customer->id]);
                Log::info('WhatsApp: Auto-linked conversation to customer', [
                    'conversation_id' => $conversation->id,
                    'wa_phone' => $conversation->wa_phone,
                    'customer_id' => $customer->id,
                ]);
                return $customer->id;
            }
        } catch (\Exception $e) {
            Log::debug('WhatsApp: relinkConversationCustomer failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Save an outbound message to the database after sending
     */
    public function saveOutboundMessage(int $conversationId, array $apiResponse, string $type, string $content, ?int $sentBy = null, ?string $templateName = null, ?array $templateParams = null, bool $forcedDedupOverride = false): ?MessageModel
    {
        $waMessageId = $apiResponse['messages'][0]['id'] ?? null;

        if (!$waMessageId) {
            return null;
        }

        $payload = [
            'conversation_id' => $conversationId,
            'wa_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => $type,
            'content' => $content,
            'template_name' => $templateName,
            'template_params' => $templateParams ? json_encode($templateParams) : null,
            'status' => 'sent',
            'sent_by' => $sentBy,
            'created_at' => now(),
        ];

        // Audit column added by add_marketing_dedup_apr2026.sql. Only stamp
        // the flag when (a) the column actually exists in this DB instance
        // (so we degrade gracefully on databases that haven't run the
        // migration yet) and (b) the operator actually overrode the rule —
        // we don't want every outbound row carrying a redundant 0.
        if ($forcedDedupOverride && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'forced_dedup_override')) {
            $payload['forced_dedup_override'] = 1;
        }

        $message = MessageModel::create($payload);

        // Update conversation
        ConversationModel::where('id', $conversationId)->update([
            'last_message_at' => now(),
        ]);

        // When a qurbani-flagged template is sent, the conversation immediately
        // qualifies for the Qurbani tab. Re-classify so the UI picks it up on
        // the very next poll.
        if ($type === 'template' && $templateName) {
            try {
                $conv = ConversationModel::find($conversationId);
                if ($conv) {
                    app(\App\Services\QurbaniClassifier::class)->classify($conv);
                }
            } catch (\Exception $e) {
                Log::debug('WhatsApp: Qurbani re-classify on outbound failed (non-fatal)', ['error' => $e->getMessage()]);
            }
        }

        return $message;
    }

    /**
     * Called when a staff member opens/polls a conversation in the UI.
     * Sends read receipts (blue ticks) to the customer for ALL inbound
     * messages we haven't already marked as read.
     *
     * Unlike the old behaviour (firing on webhook receipt), this only
     * happens when a human actually sees the thread. Safe to call repeatedly;
     * we track which WhatsApp message IDs have been reported via the
     * `read_reported_at` column on t_wa_messages.
     */
    public function markInboundAsReadOnWhatsApp(int $conversationId): int
    {
        $reported = 0;

        $hasReportedAt = \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'read_reported_at');

        $query = MessageModel::where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->whereNotNull('wa_message_id');

        // Only consider columns that exist (new install vs. existing upgrade).
        if ($hasReportedAt) {
            $query->whereNull('read_reported_at');
        } else {
            // Fallback: if column doesn't exist yet, cap lookback to the last
            // 50 inbound messages to avoid hammering the API after a big
            // backlog. Once the migration runs, this branch won't be hit.
            $query->orderByDesc('id')->limit(50);
        }

        $messages = $query->get(['id', 'wa_message_id']);
        if ($messages->isEmpty()) {
            return 0;
        }

        // May-2026 — Cache-based dead-wamid sentinel.
        // EVEN with the read_reported_at column missing (legacy installs
        // that haven't run the May-2026 migration yet) we need to stop
        // hammering Meta's API with the same dead wamids every minute.
        // Strategy: every wamid that gets a 400 "Message ID does not
        // exist" goes into Cache with a 7-day TTL (Meta's wamid
        // retention is ~7 days, so after that the situation can't
        // recover anyway). Subsequent polls short-circuit the API call.
        //
        // This complements (does NOT replace) the DB column — once the
        // migration runs, the whereNull filter culls them server-side
        // and this Cache check becomes a no-op for stamped messages.
        $deadKey = fn(string $wamid) => 'wa_dead_wamid:' . md5($wamid);

        foreach ($messages as $m) {
            // Skip wamids we already know are dead (per process-wide
            // Cache). This is the line that actually fixes the user's
            // observed loop when the column hasn't been migrated yet.
            if (\Illuminate\Support\Facades\Cache::has($deadKey($m->wa_message_id))) {
                continue;
            }

            // May-2026 — ALWAYS stamp read_reported_at after an attempt,
            // regardless of API outcome. The old code only stamped on
            // exception or success — but WhatsAppService::sendRequest()
            // doesn't throw, it returns ['success' => false, ...].
            // So a "Message ID does not exist" 400 (common for test
            // phone wamids from earlier WABA contexts, or messages
            // >7 days old) silently looped forever: every conversation
            // open re-tried the same 9 dead wamids, spamming Meta and
            // filling the error log with (#100) Invalid parameter
            // messages every minute. Stamping on failure = give up
            // after one try, which is the correct policy: Meta won't
            // suddenly accept a wamid it just said doesn't exist.
            $apiOk = false;
            $errMsg = null;
            try {
                $result = $this->markAsRead($m->wa_message_id);
                $apiOk = !empty($result['success']);
                if (!$apiOk) {
                    $errMsg = $result['error'] ?? 'unknown';
                }
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
            }

            if ($hasReportedAt) {
                MessageModel::where('id', $m->id)->update(['read_reported_at' => now()]);
            }

            if ($apiOk) {
                $reported++;
            } else {
                // Mirror the DB stamp into Cache for 7 days so legacy
                // installs without the column also stop retrying.
                \Illuminate\Support\Facades\Cache::put($deadKey($m->wa_message_id), 1, now()->addDays(7));
                Log::debug('WhatsApp: markAsRead failed (stamped to skip future retries)', [
                    'wa_message_id' => $m->wa_message_id,
                    'error'         => $errMsg,
                    'db_stamped'    => $hasReportedAt,
                    'cache_stamped' => true,
                ]);
            }
        }

        return $reported;
    }

    /**
     * Check if a conversation's 24-hour window is still open
     */
    public function isSessionActive(ConversationModel $conversation): bool
    {
        if (!$conversation->last_customer_message_at) {
            return false;
        }

        return $conversation->last_customer_message_at->diffInHours(now()) < 24;
    }

    /**
     * Returns true when the given user can mark a conversation read for
     * EVERYONE (not just themselves). Granted via the
     * `whatsapp_super_reader` mobile permission — assigned to the Taimur
     * role by the labels/super-reader migration. Used by markRead /
     * markUnread to decide whether to stamp the conversation's
     * `global_read_at` column.
     *
     * Accepts either a User instance or null (e.g. unauthenticated web
     * request) and returns false for null.
     */
    public function isSuperReader($user): bool
    {
        if (!$user) {
            return false;
        }
        // hasMobilePermission exists on both web and store users; it
        // walks the role → t_sys_role_mobile_permission link. Safe to
        // call even if the permission row doesn't exist yet (returns false).
        try {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission('whatsapp_super_reader');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns true when the given user is allowed to bypass the
     * "marketing template already sent recently" guard via the
     * "Send Anyway" prompt. Granted via the
     * `whatsapp_marketing_dedup_override` mobile permission, seeded for
     * Taimur, Management, and Admin roles by the marketing-dedup migration.
     *
     * Without this permission the chat UI shows a hard block message and
     * the server-side guard rejects the send with HTTP 409 even when
     * `force=true` is passed (so an old or scripted client cannot bypass
     * the rule). Always returns false for null users (unauthenticated
     * web requests).
     */
    public function canOverrideMarketingDedup($user): bool
    {
        if (!$user) {
            return false;
        }
        try {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission('whatsapp_marketing_dedup_override');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Read the global "marketing template dedup window" (in days) from
     * `t_fin_config`. Cached for a single request via a static so the
     * settings lookup doesn't fire on every send-time check.
     *
     * Returns 0 when the setting is missing/blank/explicitly zero, which
     * the caller treats as "feature disabled" (no pre-flight check, no
     * server-side guard, no pinned indicator).
     */
    public function getMarketingDedupWindowDays(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $value = \Illuminate\Support\Facades\DB::table('t_fin_config')
                ->where('config_key', 'wa_marketing_dedup_window_days')
                ->value('config_value');
            $cached = max(0, (int) ($value ?? 0));
        } catch (\Exception $e) {
            $cached = 0;
        }
        return $cached;
    }

    // =========================================================================
    // Auto-Reply (Apr-2026)
    // =========================================================================

    /**
     * Read the global auto-reply master switch. Returns false when the
     * setting row is missing, blank, or "0" — anything else is treated as
     * enabled. Cached per-request so the inbound webhook doesn't hit the
     * config table N times when a customer sends a burst of messages.
     */
    public function isAutoReplyEnabled(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $value = \Illuminate\Support\Facades\DB::table('t_fin_config')
                ->where('config_key', 'wa_auto_reply_enabled')
                ->value('config_value');
            $cached = !empty($value) && $value !== '0' && strtolower((string) $value) !== 'false';
        } catch (\Exception $e) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Decide whether the inbound on this conversation should trigger an
     * auto-reply, and if so, send + record it.
     *
     * The flow is intentionally defensive — every short-circuit is a
     * deliberate "don't reply" branch documented in line comments below.
     * We do NOT throw on any failure path; the caller wraps us in try/catch
     * but we'd rather skip silently and let the inbound message still be
     * persisted normally.
     *
     * @param mixed  $conversation The Eloquent ConversationModel for the inbound.
     * @param string $from         E164-normalized phone number we send back to.
     * @param string $type         Inbound message type ('text', 'image', etc.).
     *                             We don't filter on this today, but it's
     *                             passed through so future rules can opt out
     *                             of auto-replying to e.g. 'reaction' pings.
     */
    protected function maybeSendAutoReply($conversation, string $from, string $type): void
    {
        if (!$this->isAutoReplyEnabled()) {
            return;
        }

        // Reactions are conversational noise (👍, ❤). Auto-replying to them
        // creates an awkward feedback loop and burns Meta session credits
        // for no value, so we skip them outright.
        if ($type === 'reaction') {
            return;
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('t_wa_auto_replies')) {
            // Migration hasn't been run yet — fail closed.
            return;
        }

        // Match the first enabled rule (lowest priority number wins).
        $rule = $this->findMatchingAutoReplyRule(now());
        if (!$rule) {
            return;
        }

        // Per-conversation throttle so a customer is never pelted with the same
        // auto-reply (Jun-2026 fix). THREE modes — see cooldown_mode on the rule:
        //   - 'daily'   : at most one auto-reply to this conversation per
        //                 calendar day. The safe default for out-of-hours /
        //                 holiday rules and the fix for the "same message every
        //                 few hours across a multi-day holiday" complaint.
        //   - 'rolling' : at most one auto-reply every N hours. Legacy behaviour;
        //                 N is floored at 1 so a mis-set 0 can NEVER disable the
        //                 throttle (the old code skipped the throttle entirely
        //                 when cooldown_hours was 0).
        //   - 'once'    : at most one auto-reply for THIS rule per active period
        //                 (since active_from, else ever) — e.g. a single holiday
        //                 greeting per customer.
        // 'daily'/'rolling' look across ALL rules (stacked rules can't double
        // send); 'once' is scoped to this rule. Defaults to 'daily' when the
        // column is absent, so behaviour is sane even before the SQL is run.
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'auto_reply_rule_id')) {
            $mode = $rule->cooldown_mode ?? 'daily';
            $throttleQ = \Illuminate\Support\Facades\DB::table('t_wa_messages')
                ->where('conversation_id', $conversation->id);

            if ($mode === 'once') {
                $throttleQ->where('auto_reply_rule_id', (int) $rule->id);
                if (!empty($rule->active_from)) {
                    $throttleQ->where('created_at', '>=', \Carbon\Carbon::parse($rule->active_from)->startOfDay());
                }
            } elseif ($mode === 'rolling') {
                $hours = max(1, (int) ($rule->cooldown_hours ?? 6));
                $throttleQ->whereNotNull('auto_reply_rule_id')
                          ->where('created_at', '>=', now()->subHours($hours));
            } else { // 'daily' (default)
                $throttleQ->whereNotNull('auto_reply_rule_id')
                          ->where('created_at', '>=', now()->startOfDay());
            }

            if ($throttleQ->exists()) {
                Log::debug('WhatsApp auto-reply: throttled, skipping', [
                    'conversation_id' => $conversation->id,
                    'rule_id'         => $rule->id,
                    'cooldown_mode'   => $mode,
                ]);
                return;
            }
        }

        // Send the WhatsApp text. If the API rejects it (e.g. the 24h window
        // expired between the inbound landing on our webhook and us replying)
        // we log and bail — we never store a failed auto-reply row because
        // the cooldown would then suppress legitimate retries on the next
        // inbound.
        try {
            $resp = $this->sendTextMessage($from, (string) $rule->message);
        } catch (\Exception $e) {
            Log::warning('WhatsApp auto-reply: send failed', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'error'           => $e->getMessage(),
            ]);
            return;
        }

        if (empty($resp['messages'][0]['id'])) {
            Log::warning('WhatsApp auto-reply: API returned no message id', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'response'        => $resp,
            ]);
            return;
        }

        $waMessageId = $resp['messages'][0]['id'];
        $payload = [
            'conversation_id' => $conversation->id,
            'wa_message_id'   => $waMessageId,
            'direction'       => 'outbound',
            'type'            => 'text',
            'content'         => (string) $rule->message,
            'status'          => 'sent',
            'sent_by'         => null, // System send.
            'created_at'      => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'auto_reply_rule_id')) {
            $payload['auto_reply_rule_id'] = (int) $rule->id;
        }

        try {
            MessageModel::create($payload);
            // IMPORTANT: only `last_message_at` is touched here. We
            // deliberately do NOT modify any of the read-state fields:
            //   - t_wa_conversations.unread_count          (legacy fallback)
            //   - t_wa_conversation_reads.last_read_at     (per-user read state)
            //   - t_wa_conversation_reads.forced_unread_at (manual "mark unread")
            // ...so the customer's inbound message stays unread for the staff
            // user even after we auto-respond. Otherwise an out-of-hours reply
            // would silently clear the unread badge and the operator would
            // miss real customer requests in the morning. WhatsApp's blue
            // ticks (read receipts) are also untouched — those only fire
            // from markConversationReadForUser() which is called when a
            // human actually opens the chat.
            ConversationModel::where('id', $conversation->id)->update(['last_message_at' => now()]);
        } catch (\Exception $e) {
            Log::warning('WhatsApp auto-reply: failed to persist outbound row', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return the single auto-reply rule that should fire at $now, or null.
     * Public so the settings UI can preview "what would fire right now"
     * without sending anything.
     *
     * Evaluation order:
     *   1. enabled = 1
     *   2. ORDER BY priority ASC, id ASC (deterministic on ties)
     *   3. First rule whose match_mode + criteria accept $now wins.
     */
    public function findMatchingAutoReplyRule(\Carbon\Carbon $now)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_wa_auto_replies')) {
            return null;
        }
        $rules = \Illuminate\Support\Facades\DB::table('t_wa_auto_replies')
            ->where('enabled', 1)
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rules as $rule) {
            if ($this->autoReplyRuleMatches($rule, $now)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Test a single rule row against $now. Pulled out so unit tests / the
     * settings UI can call it directly without standing up the inbox.
     *
     * Important: the rule row is an stdClass from a raw DB::table() query,
     * so we read fields with the array-like ?? null pattern to tolerate
     * older schemas.
     */
    public function autoReplyRuleMatches($rule, \Carbon\Carbon $now): bool
    {
        // Universal active-window gate (Jun-2026) — applies to EVERY match_mode.
        // Lets a holiday rule auto-expire instead of firing forever, which was
        // the root cause of an "always" Eid rule replying every day until it
        // was manually disabled. Dates are inclusive; NULL = unbounded on that
        // side. substr(0,10) tolerates either DATE or DATETIME storage.
        $today = $now->toDateString();
        $from = !empty($rule->active_from) ? substr((string) $rule->active_from, 0, 10) : null;
        $to   = !empty($rule->active_to)   ? substr((string) $rule->active_to,   0, 10) : null;
        if ($from && $today < $from) {
            return false;
        }
        if ($to && $today > $to) {
            return false;
        }

        $mode = $rule->match_mode ?? 'time_window';

        if ($mode === 'always') {
            return true;
        }

        if ($mode === 'specific_dates') {
            $list = $this->parseAutoReplyDateList($rule->specific_dates ?? null);
            return in_array($now->format('Y-m-d'), $list, true);
        }

        // time_window (default).
        $days = $this->parseAutoReplyDaysOfWeek($rule->days_of_week ?? null);
        if (!empty($days) && !in_array((int) $now->dayOfWeek, $days, true)) {
            return false;
        }

        $start = $rule->start_time ?? null;
        $end   = $rule->end_time ?? null;

        // Both null → match every minute of the selected days (acts like a
        // "whole-day rule, restricted to these weekdays").
        if (!$start && !$end) {
            return true;
        }
        if (!$start || !$end) {
            // Half-configured row — treat as non-matching to avoid surprises.
            return false;
        }

        $current = $now->format('H:i:s');
        if ($start <= $end) {
            // Same-day window, e.g. 09:00 → 18:00 (replies fire OUTSIDE
            // working hours, so the rule is typically the inverse: 18:00 →
            // 09:00 next day, which is the cross-midnight branch below).
            return ($current >= $start && $current < $end);
        }
        // Cross-midnight window, e.g. 18:00 → 09:00.
        return ($current >= $start || $current < $end);
    }

    /**
     * Days_of_week is stored as a CSV of 0..6 ints (0=Sun..6=Sat). Parse
     * tolerantly: blank means "every day", garbage entries are dropped.
     */
    protected function parseAutoReplyDaysOfWeek($raw): array
    {
        if ($raw === null || $raw === '') {
            return []; // Empty = no restriction.
        }
        $out = [];
        foreach (explode(',', (string) $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || !ctype_digit($piece)) {
                continue;
            }
            $n = (int) $piece;
            if ($n >= 0 && $n <= 6) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Specific_dates is stored as a JSON array of YYYY-MM-DD strings. Parse
     * tolerantly: invalid JSON → empty list (no match) so a typo in the
     * settings UI never blocks all auto-replies.
     */
    protected function parseAutoReplyDateList($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $d) {
            if (!is_string($d)) {
                continue;
            }
            $d = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Server-side guard for the "marketing template already sent recently"
     * rule. Called from every outbound-template entry point (web + mobile)
     * BEFORE the actual send, so an old client or scripted request cannot
     * bypass the dedup by simply not running the pre-flight UI check.
     *
     * Returns:
     *   - null  → OK to send (template is utility/auth, feature disabled,
     *             no recent send found, OR override granted to the user).
     *   - array → block / require explicit confirmation. The caller should
     *             return HTTP 409 with this payload as the JSON body so
     *             the chat UI can show the confirm dialog.
     *
     * Override semantics:
     *   - $force = true  AND user has whatsapp_marketing_dedup_override →
     *              recorded as override on the outbound row, send proceeds.
     *   - $force = true  but user lacks the permission → still blocked
     *              (the UI hides the "Send Anyway" button for these users
     *              but we double-guard server-side).
     *   - $force = false AND there is a recent send → blocked with the
     *              UI confirm payload.
     *
     * @param int|null    $conversationId The conversation we're sending into.
     *                                    May be null when the caller only
     *                                    has a phone number (e.g. one-off
     *                                    sendInvoice flows); we'll resolve
     *                                    or create the conversation first.
     * @param string|null $phone          Used as a fallback to resolve the
     *                                    conversation when $conversationId
     *                                    is null. Already E164-formatted.
     * @param string      $templateName   Cloud API template key, NOT the
     *                                    display name.
     * @param mixed       $user           The auth user. May be null for
     *                                    unauthenticated requests, in
     *                                    which case override is impossible.
     * @param bool        $force          User explicitly chose Send Anyway.
     */
    public function enforceMarketingDedup(?int $conversationId, ?string $phone, string $templateName, $user, bool $force): ?array
    {
        $windowDays = $this->getMarketingDedupWindowDays();
        if ($windowDays <= 0) {
            return null; // Feature disabled globally.
        }

        $filter = app(\App\Services\CampaignFilterService::class);
        if (!$filter->isMarketingTemplate($templateName)) {
            return null; // Utility / auth templates are never deduped.
        }

        // Resolve the conversation if the caller only had a phone.
        if ((!$conversationId || $conversationId <= 0) && $phone) {
            try {
                $conv = $this->findOrCreateConversation($phone);
                if ($conv) {
                    $conversationId = (int) $conv->id;
                }
            } catch (\Exception $e) {
                // If we can't resolve a conversation we can't dedup, so
                // fall through and let the send proceed — better to ship
                // a possibly-duplicate marketing template than to lose
                // the send entirely on a transient lookup failure.
                return null;
            }
        }
        if (!$conversationId || $conversationId <= 0) {
            return null;
        }

        $hit = $filter->recentMarketingTemplateSendForConversation(
            (int) $conversationId,
            $templateName,
            $windowDays
        );
        if (!$hit) {
            return null; // No recent matching send.
        }

        // Recent send found. Decide between block and override.
        $canOverride = $this->canOverrideMarketingDedup($user);

        if ($force && $canOverride) {
            return null; // Authorised override — let the send proceed.
        }

        return [
            'blocked'               => true,
            'reason'                => 'recent_marketing_send',
            'template_name'         => $hit['template_name'],
            'template_display_name' => $hit['template_display_name'],
            'sent_at'               => $hit['sent_at'],
            'sent_at_human'         => $hit['sent_at_human'],
            'window_days'           => $hit['window_days'],
            'can_override'          => $canOverride,
            'message'               => $canOverride
                ? sprintf(
                    'This customer was sent the marketing template "%s" %s. Send it again?',
                    $hit['template_display_name'],
                    $hit['sent_at_human']
                  )
                : sprintf(
                    'This customer was sent the marketing template "%s" %s. Re-sending the same marketing template within %d days is not allowed for your role.',
                    $hit['template_display_name'],
                    $hit['sent_at_human'],
                    $hit['window_days']
                  ),
        ];
    }

    /**
     * Format phone number for WhatsApp API (must be 92XXXXXXXXXX).
     *
     * Pakistan-only by design (last-10-digits rule): collapses every way staff
     * free-type a PK number (+92 345..., 0345..., 345..., 0092 345...) to
     * 92XXXXXXXXXX.
     *
     * Jul-2026: the early-return now requires EXACTLY 12 digits. A valid PK
     * E.164 number is exactly 92 + 10 digits; anything longer that happens to
     * start with "92" is half-normalized junk (e.g. a client formatter turned
     * "00923215793000" into "920923215793000") and previously sailed through
     * to Meta and failed 131026. Now it falls into the same last-10 rule and
     * self-heals to the correct 92XXXXXXXXXX.
     *
     * International numbers are NOT handled here on purpose — dial-time
     * resolution of known international customers lives in resolveDialPhone().
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $digits = substr($digits, -10);

        return '92' . $digits;
    }

    /**
     * Resolve the number to actually DIAL for a customer-facing send.
     *
     * formatPhone() is a PK-only formatter, so it mangles the rare
     * international customer (971..., etc). Rather than teach it country
     * codes, this prefers a KNOWN-GOOD full number the system already holds:
     *
     *   1. An explicitly-passed conversation's wa_phone — that number came
     *      from Meta's own webhook, so it is proven deliverable.
     *   2. A conversation whose wa_phone ends in the same last-10 digits AND
     *      that has at least one INBOUND message. The inbound requirement is
     *      load-bearing: outbound-only conversations can be junk rows created
     *      by a past mangled send (e.g. wa_phone 920923215793000), and
     *      last_customer_message_at can NOT be used instead — it is seeded at
     *      creation time even for outbound-created conversations.
     *   3. The matched customer's phone_original, when it clearly carries a
     *      non-PK country code (11+ digits, no leading 0/92 after stripping a
     *      00 international prefix).
     *   4. Otherwise formatPhone()'s result — byte-for-byte today's behavior.
     *
     * For Pakistani numbers this is a NO-OP by construction: their proven
     * conversation number IS the formatPhone() output. The last-10 identity
     * always comes from the caller's input, so manually typing a different
     * number still redirects the send — this only fixes the country-code
     * interpretation, never the identity.
     *
     * FAIL-OPEN: any lookup error returns the formatted number (old behavior).
     */
    public function resolveDialPhone(string $phone, $conversation = null): string
    {
        $formatted = $this->formatPhone($phone);

        try {
            if ($conversation && !empty($conversation->wa_phone)) {
                return ltrim((string) $conversation->wa_phone, '+');
            }

            $digits = preg_replace('/\D/', '', $phone);
            $last10 = substr($digits, -10);
            if (strlen($last10) < 10) {
                return $formatted;
            }

            $candidates = \App\Models\WhatsApp\ConversationModel::where('wa_phone', 'like', '%' . $last10)
                ->orderBy('id')
                ->limit(10)
                ->get();
            foreach ($candidates as $cand) {
                if (empty($cand->wa_phone)) {
                    continue;
                }
                $hasInbound = \App\Models\WhatsApp\MessageModel::where('conversation_id', $cand->id)
                    ->where('direction', 'inbound')
                    ->exists();
                if ($hasInbound) {
                    return ltrim((string) $cand->wa_phone, '+');
                }
            }

            $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $last10)
                ->whereNull('merged_into_customer_id')
                ->first();
            if ($customer) {
                $orig = preg_replace('/\D/', '', (string) ($customer->phone_original ?? ''));
                if (str_starts_with($orig, '00')) {
                    $orig = substr($orig, 2);
                }
                if (strlen($orig) >= 11 && !str_starts_with($orig, '0') && !str_starts_with($orig, '92')) {
                    return $orig;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('resolveDialPhone fell back to formatPhone', ['error' => $e->getMessage()]);
        }

        return $formatted;
    }

    protected function sendRequest(array $payload): array
    {
        try {
            $url = "{$this->apiBase}/{$this->phoneNumberId}/messages";

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post($url, $payload);

            $data = $response->json();

            if (!$response->successful()) {
                Log::error('WhatsApp API error', [
                    'status' => $response->status(),
                    'response' => $data,
                    'payload_type' => $payload['type'] ?? 'unknown',
                ]);

                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? 'Unknown API error',
                    'error_code' => $data['error']['code'] ?? null,
                ];
            }

            // Log cost for tracking
            $this->logCost($payload, $data);

            return array_merge(['success' => true], $data);

        } catch (\Exception $e) {
            Log::error('WhatsApp API exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function logCost(array $payload, array $response): void
    {
        try {
            $type = $payload['type'] ?? 'text';
            $category = $type === 'template' ? 'utility' : 'service';
            $templateName = $payload['template']['name'] ?? null;
            $to = $payload['to'] ?? null;

            \DB::table('t_wa_cost_log')->insert([
                'wa_phone' => $to,
                'message_type' => $type,
                'template_name' => $templateName,
                'category' => $category,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't fail the message send just because cost logging failed
        }
    }

    protected function getExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/ogg; codecs=opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];

        return $map[$mimeType] ?? 'bin';
    }
}
