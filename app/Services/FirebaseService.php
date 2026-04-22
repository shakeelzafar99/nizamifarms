<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class FirebaseService
{
    protected string $projectId;
    protected ?string $credentialsPath;

    public function __construct()
    {
        $this->projectId = config('whatsapp.firebase_project_id', '');
        $credFile = config('whatsapp.firebase_credentials_path', 'firebase-service-account.json');
        $this->credentialsPath = base_path($credFile);
    }

    /**
     * Send a push notification to all active devices of users who have the
     * WhatsApp view permission — either full (view_whatsapp_messages) or
     * limited (view_whatsapp_messages_limited). Limited users are included
     * because the push is always about a fresh (today) inbound message, which
     * falls inside their "today + yesterday" visibility window, so opening
     * the conversation will show the message fine.
     */
    public function notifyNewWhatsAppMessage(string $senderName, string $preview, ?int $conversationId = null): void
    {
        $this->sendToPermissionGroups(['view_whatsapp_messages', 'view_whatsapp_messages_limited'], [
            'title' => "New message from {$senderName}",
            'body' => mb_substr($preview, 0, 200),
        ], [
            'type' => 'whatsapp_message',
            'conversation_id' => (string)($conversationId ?? ''),
            'sender' => $senderName,
        ], 'whatsapp_messages');
    }

    /**
     * Send a push notification to a SINGLE user's active devices. Used for
     * targeted notifications like WhatsApp conversation @mentions where
     * we want only the mentioned staff member to be pinged — not the
     * whole permission group. Best-effort: silently no-ops if the user
     * has no active device tokens or Firebase isn't configured.
     */
    public function notifyUser(int $userId, array $notification, array $data = [], string $channelId = 'whatsapp_messages'): void
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            Log::debug('Firebase: Skipping user push (not configured)', ['user_id' => $userId]);
            return;
        }

        try {
            $tokens = DB::table('t_wa_device_tokens')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->pluck('fcm_token')
                ->all();

            if (empty($tokens)) return;

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token for notifyUser', ['user_id' => $userId]);
                return;
            }

            foreach ($tokens as $fcmToken) {
                $this->sendToDevice($accessToken, $fcmToken, $notification, $data, $channelId);
            }
        } catch (\Exception $e) {
            Log::error('Firebase: notifyUser failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Convenience wrapper: tell a specific user they have been mentioned
     * on a WhatsApp conversation. Picks a consistent notification shape
     * so mobile can deep-link straight into the conversation and highlight
     * the mention on arrival.
     */
    public function notifyWhatsAppMention(
        int $mentionedUserId,
        string $mentionedByName,
        string $conversationDisplayName,
        int $conversationId
    ): void {
        $this->notifyUser($mentionedUserId, [
            'title' => "{$mentionedByName} tagged you",
            'body'  => "on the chat with {$conversationDisplayName}",
        ], [
            'type'            => 'whatsapp_mention',
            'conversation_id' => (string) $conversationId,
            'mentioned_by'    => $mentionedByName,
        ], 'whatsapp_messages');
    }

    /**
     * Send a push notification when a new production plan/demand is created
     */
    public function notifyNewPlan(string $createdByName, string $demandDate, int $demandId, float $totalKg): void
    {
        $dateFormatted = date('M d', strtotime($demandDate));

        $this->sendToPermissionGroup('access_khaas_mode', [
            'title' => "New Plan for {$dateFormatted}",
            'body' => "{$createdByName} submitted a production plan ({$totalKg} kg) for {$dateFormatted}",
        ], [
            'type' => 'khaas_plan',
            'demand_id' => (string) $demandId,
            'demand_date' => $demandDate,
        ], 'khaas_planning');
    }

    /**
     * Generic: send notifications to all users with a given permission
     */
    protected function sendToPermissionGroup(string $permissionCode, array $notification, array $data, string $channelId = 'whatsapp_messages'): void
    {
        $this->sendToPermissionGroups([$permissionCode], $notification, $data, $channelId);
    }

    /**
     * Generic: send notifications to all users that hold ANY of the given
     * mobile permissions. Used e.g. for WhatsApp messages where either a
     * full or limited viewer should receive the push.
     */
    protected function sendToPermissionGroups(array $permissionCodes, array $notification, array $data, string $channelId = 'whatsapp_messages'): void
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            Log::debug('Firebase: Skipping push notification (not configured)');
            return;
        }

        try {
            $tokens = $this->getActiveTokensForPermissions($permissionCodes);

            if (empty($tokens)) {
                return;
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token');
                return;
            }

            foreach ($tokens as $tokenRecord) {
                $this->sendToDevice($accessToken, $tokenRecord->fcm_token, $notification, $data, $channelId);
            }
        } catch (\Exception $e) {
            Log::error('Firebase: Push notification failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get all active FCM tokens for users who have a specific mobile permission
     */
    protected function getActiveTokensForPermission(string $permissionCode): array
    {
        return $this->getActiveTokensForPermissions([$permissionCode]);
    }

    /**
     * Get all active FCM tokens for users who hold ANY of the given
     * mobile permissions (distinct by device token).
     */
    protected function getActiveTokensForPermissions(array $permissionCodes): array
    {
        if (empty($permissionCodes)) return [];

        return DB::table('t_wa_device_tokens as dt')
            ->join('t_sys_user_role as ur', 'ur.user_id', '=', 'dt.user_id')
            ->join('t_sys_role_mobile_permission as rmp', 'rmp.role_id', '=', 'ur.role_id')
            ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
            ->whereIn('mp.permission_code', $permissionCodes)
            ->where('dt.is_active', 1)
            ->select('dt.fcm_token', 'dt.user_id')
            ->distinct()
            ->get()
            ->all();
    }

    /**
     * Send a notification to a single device via FCM v1 API
     */
    protected function sendToDevice(string $accessToken, string $fcmToken, array $notification, array $data = [], string $channelId = 'whatsapp_messages'): void
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'notification' => $notification,
                'data' => $data,
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => $channelId,
                        'sound' => 'default',
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, $payload);

            if (!$response->successful()) {
                $error = $response->json('error.message', 'Unknown');
                $code = $response->json('error.details.0.errorCode', '');
                Log::warning("Firebase: FCM send failed [{$code}]: {$error}");

                // If token is invalid/expired, deactivate it
                if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    DB::table('t_wa_device_tokens')
                        ->where('fcm_token', $fcmToken)
                        ->update(['is_active' => 0]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Firebase: HTTP error sending to device', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get an OAuth2 access token using the service account credentials.
     * Caches the token for 50 minutes (they expire in 60).
     */
    protected function getAccessToken(): ?string
    {
        return Cache::remember('firebase_access_token', 3000, function () {
            try {
                $credentials = json_decode(file_get_contents($this->credentialsPath), true);

                if (!$credentials || !isset($credentials['client_email'], $credentials['private_key'], $credentials['token_uri'])) {
                    Log::error('Firebase: Invalid service account credentials file');
                    return null;
                }

                $now = time();
                $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
                $claim = base64_encode(json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $credentials['token_uri'],
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]));

                $headerClaim = str_replace(['+', '/', '='], ['-', '_', ''], $header)
                    . '.' . str_replace(['+', '/', '='], ['-', '_', ''], $claim);

                $privateKey = openssl_pkey_get_private($credentials['private_key']);
                if (!$privateKey) {
                    Log::error('Firebase: Failed to load private key');
                    return null;
                }

                openssl_sign($headerClaim, $signature, $privateKey, OPENSSL_ALGO_SHA256);
                $jwt = $headerClaim . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

                $response = Http::asForm()->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);

                if ($response->successful()) {
                    return $response->json('access_token');
                }

                Log::error('Firebase: Token exchange failed', ['response' => $response->json()]);
                return null;

            } catch (\Exception $e) {
                Log::error('Firebase: Access token generation failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }
}
