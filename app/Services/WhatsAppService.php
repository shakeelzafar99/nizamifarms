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
            MessageModel::create([
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

            // Send read receipt back to WhatsApp
            $this->markAsRead($waMessageId);

            // Send push notification to staff
            try {
                $senderName = $contactName ?? $conversation->display_name ?? $from;
                $preview = mb_substr($content ?? '[Media]', 0, 200);
                app(FirebaseService::class)->notifyNewWhatsAppMessage($senderName, $preview, $conversation->id);
            } catch (\Exception $pushErr) {
                Log::debug('WhatsApp: Push notification failed (non-fatal)', ['error' => $pushErr->getMessage()]);
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
     * Save an outbound message to the database after sending
     */
    public function saveOutboundMessage(int $conversationId, array $apiResponse, string $type, string $content, ?int $sentBy = null, ?string $templateName = null, ?array $templateParams = null): ?MessageModel
    {
        $waMessageId = $apiResponse['messages'][0]['id'] ?? null;

        if (!$waMessageId) {
            return null;
        }

        $message = MessageModel::create([
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
        ]);

        // Update conversation
        ConversationModel::where('id', $conversationId)->update([
            'last_message_at' => now(),
        ]);

        return $message;
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
     * Format phone number for WhatsApp API (must be 92XXXXXXXXXX)
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '92') && strlen($digits) >= 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $digits = substr($digits, -10);

        return '92' . $digits;
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
