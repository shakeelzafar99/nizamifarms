<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Webhook verification (GET) - Meta sends a challenge when you register the webhook URL.
     * Must respond with the hub.challenge value if the verify token matches.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('whatsapp.verify_token')) {
            Log::info('WhatsApp webhook verified successfully');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token_match' => $token === config('whatsapp.verify_token'),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Receive incoming messages and status updates (POST).
     * Meta sends all webhook events here.
     */
    public function receive(Request $request)
    {
        // Verify signature
        $signature = $request->header('X-Hub-Signature-256', '');
        $payload = $request->getContent();

        $service = new WhatsAppService();

        if (!$service->verifyWebhookSignature($payload, $signature)) {
            Log::warning('WhatsApp webhook: Invalid signature');
            return response('Invalid signature', 403);
        }

        // Must respond 200 quickly to avoid Meta retries
        $data = json_decode($payload, true);

        if (!$data) {
            Log::warning('WhatsApp webhook: Empty or invalid JSON payload', [
                'content_length' => strlen($payload),
                'raw_start' => mb_substr($payload, 0, 200),
            ]);
            return response('OK', 200);
        }

        Log::info('WhatsApp webhook received', [
            'entries' => count($data['entry'] ?? []),
        ]);

        try {
            $service->processIncomingWebhook($data);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook processing error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload_snippet' => mb_substr($payload, 0, 500),
            ]);
        }

        return response('OK', 200);
    }
}
