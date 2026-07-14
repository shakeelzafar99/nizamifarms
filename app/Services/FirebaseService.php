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
     * Combined (debounced) store-transfer alerts. Instead of one push per moved
     * item, this batches all pending Warehouse->Store transfers per store into ONE
     * push once the batch has SETTLED (no new move for 5 min) or CAPPED (oldest is
     * 10 min old). Poll-driven — called from the store/khaas polling endpoints via
     * app()->terminating(), NOT a cron. A cache mutex keeps it to ~once per 25s no
     * matter how many pollers hit it. Each transfer is stamped alert_batched_at so
     * it's alerted at most once; already-accepted (status != pending) transfers are
     * never alerted. Targets the 'receive_store_transfer_alerts' permission group.
     */
    public function flushDueTransferAlerts(): void
    {
        // Run at most once per ~25s regardless of how many endpoints trigger us.
        if (!\Cache::add('store_transfer_alert_flush_lock', 1, 25)) {
            return;
        }

        try {
            $model = \App\Models\CRM\WarehouseTransferModel::class;
            $now = now();
            $settleBefore = $now->copy()->subMinutes(5);  // batch is "done" if newest move >= 5 min old
            $capBefore    = $now->copy()->subMinutes(10); // never wait longer than 10 min

            // Group pending, not-yet-alerted store transfers by business unit.
            $groups = $model::where('status', $model::STATUS_PENDING)
                ->where('to_location', 'store')
                ->whereNull('alert_batched_at')
                ->selectRaw('business_unit_id, COUNT(*) as cnt, MIN(created_at) as oldest, MAX(created_at) as newest')
                ->groupBy('business_unit_id')
                ->get();

            foreach ($groups as $g) {
                $newest = $g->newest ? \Carbon\Carbon::parse($g->newest) : null;
                $oldest = $g->oldest ? \Carbon\Carbon::parse($g->oldest) : null;
                $settled = $newest && $newest->lte($settleBefore);
                $capped  = $oldest && $oldest->lte($capBefore);
                if (!$settled && !$capped) {
                    continue; // batch still forming — wait for it to settle
                }

                $transfers = $model::where('status', $model::STATUS_PENDING)
                    ->where('to_location', 'store')
                    ->whereNull('alert_batched_at')
                    ->where('business_unit_id', $g->business_unit_id)
                    ->with('product:id,title')
                    ->orderBy('created_at')
                    ->get();
                if ($transfers->isEmpty()) {
                    continue;
                }

                $count = $transfers->count();
                if ($count === 1) {
                    $t0 = $transfers->first();
                    $qty = rtrim(rtrim(number_format((float) $t0->quantity, 2, '.', ''), '0'), '.');
                    $name = optional($t0->product)->title ?? 'Frozen product';
                    $body = "{$qty} × {$name} — tap to accept.";
                } else {
                    $body = "{$count} frozen items arrived at the store — tap to accept.";
                }

                $this->sendToPermissionGroup('receive_store_transfer_alerts', [
                    'title' => 'Frozen stock arrived',
                    'body'  => $body,
                ], [
                    'type'        => 'store_transfer_pending',
                    'transfer_id' => (string) $transfers->last()->id,
                    'count'       => (string) $count,
                ], 'store_transfers');

                // Stamp them so this batch never re-alerts.
                $model::whereIn('id', $transfers->pluck('id')->all())
                    ->update(['alert_batched_at' => $now]);
            }
        } catch (\Throwable $e) {
            Log::warning('flushDueTransferAlerts failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notify management that a NEW leave request was added and is pending review.
     * Fired at leave-create time (pending leaves only). Targets the
     * 'receive_leave_alerts' mobile-permission group.
     */
    public function notifyLeaveAdded(int $requestId, string $applicantName, ?string $dateRange = null, ?int $excludeUserId = null): void
    {
        $body = $dateRange
            ? "{$applicantName} applied for leave ({$dateRange}) — tap to review."
            : "{$applicantName} applied for leave — tap to review.";

        $this->sendToPermissionGroup('receive_leave_alerts', [
            'title' => 'New leave request',
            'body'  => $body,
        ], [
            'type'       => 'leave_added',
            'request_id' => (string) $requestId,
        ], 'leave_updates', $excludeUserId);
    }

    /**
     * Generic: send notifications to all users with a given permission
     */
    protected function sendToPermissionGroup(string $permissionCode, array $notification, array $data, string $channelId = 'whatsapp_messages', ?int $excludeUserId = null): void
    {
        $this->sendToPermissionGroups([$permissionCode], $notification, $data, $channelId, $excludeUserId);
    }

    /**
     * Generic: send notifications to all users that hold ANY of the given
     * mobile permissions. Used e.g. for WhatsApp messages where either a
     * full or limited viewer should receive the push.
     */
    protected function sendToPermissionGroups(array $permissionCodes, array $notification, array $data, string $channelId = 'whatsapp_messages', ?int $excludeUserId = null): void
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
                // Never notify the actor about their own action (e.g. the manager
                // who just created the leave, or the requester who initiated it).
                if ($excludeUserId !== null && (int) $tokenRecord->user_id === $excludeUserId) {
                    continue;
                }
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
     * ⭐ App-release broadcast: notify EVERY active device (all users, all
     * roles) that a new APK version is available. Pressed manually from the
     * Operations page AFTER the owner has uploaded the new APK + AppController
     * to production, so the download link always points at a live file.
     * Data payload mirrors /api/app/version so the mobile app can show its
     * standard update dialog on tap. Returns counts for the UI.
     */
    public function notifyAppUpdate(array $version): array
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'Firebase is not configured on this server'];
        }

        try {
            $tokens = DB::table('t_wa_device_tokens')
                ->where('is_active', 1)
                ->distinct()
                ->pluck('fcm_token')
                ->all();

            if (empty($tokens)) {
                return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => 'No active devices registered'];
            }

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                Log::error('Firebase: Failed to get access token for notifyAppUpdate');
                return ['total' => count($tokens), 'sent' => 0, 'failed' => count($tokens), 'error' => 'Could not authenticate with Firebase'];
            }

            $notification = [
                'title' => '🚀 App Update Available',
                'body'  => "Nizami Farms v{$version['name']} is ready to install. Tap to update.",
            ];
            // FCM v1 requires every data value to be a string.
            $data = [
                'type'         => 'app_update',
                'version_name' => (string) $version['name'],
                'version_code' => (string) $version['code'],
                'download_url' => (string) $version['download_url'],
            ];

            $sent = 0;
            foreach ($tokens as $fcmToken) {
                if ($this->sendToDevice($accessToken, $fcmToken, $notification, $data, 'app_updates')) {
                    $sent++;
                }
            }

            Log::info('Firebase: App update broadcast finished', [
                'version' => $version['name'], 'total' => count($tokens), 'sent' => $sent,
            ]);

            return ['total' => count($tokens), 'sent' => $sent, 'failed' => count($tokens) - $sent, 'error' => null];
        } catch (\Exception $e) {
            Log::error('Firebase: notifyAppUpdate failed', ['error' => $e->getMessage()]);
            return ['total' => 0, 'sent' => 0, 'failed' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a notification to a single device via FCM v1 API.
     * Returns true when FCM accepted the message (used for broadcast counts;
     * older callers simply ignore the return value).
     */
    protected function sendToDevice(string $accessToken, string $fcmToken, array $notification, array $data = [], string $channelId = 'whatsapp_messages'): bool
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
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning('Firebase: HTTP error sending to device', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * ⭐ Silent "wake up" data push to a rider's devices — the GPS defibrillator.
     * Data-only (NO notification block) + high priority so the mobile app's
     * background message handler runs (even when the app was swiped away / the
     * OS snoozed it) and restarts the location foreground service, WITHOUT showing
     * anything to the rider. Best-effort; returns how many devices accepted it.
     */
    public function pingRiderToWake(int $userId, array $data = []): int
    {
        if (!$this->projectId || !file_exists($this->credentialsPath)) {
            return 0;
        }

        try {
            $tokens = DB::table('t_wa_device_tokens')
                ->where('user_id', $userId)
                ->where('is_active', 1)
                ->pluck('fcm_token')
                ->all();

            if (empty($tokens)) return 0;

            $accessToken = $this->getAccessToken();
            if (!$accessToken) return 0;

            $payloadData = array_merge(['type' => 'location_ping'], $data);
            $sent = 0;
            foreach ($tokens as $fcmToken) {
                if ($this->sendDataOnly($accessToken, $fcmToken, $payloadData)) {
                    $sent++;
                }
            }
            return $sent;
        } catch (\Exception $e) {
            Log::warning('Firebase: pingRiderToWake failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Send a DATA-ONLY high-priority FCM message (no visible notification). FCM
     * requires every data value to be a string. Deactivates a dead token.
     */
    protected function sendDataOnly(string $accessToken, string $fcmToken, array $data): bool
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $stringData = [];
        foreach ($data as $k => $v) {
            $stringData[$k] = is_string($v) ? $v : (string) $v;
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data'  => $stringData,
                'android' => [
                    'priority' => 'high',
                    // No 'notification' block → silent; wakes the JS background handler.
                ],
            ],
        ];

        try {
            $response = Http::withToken($accessToken)->timeout(10)->post($url, $payload);
            if (!$response->successful()) {
                $code = $response->json('error.details.0.errorCode', '');
                Log::warning('Firebase: silent data push failed', [
                    'code'  => $code,
                    'error' => $response->json('error.message', 'Unknown'),
                ]);
                if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                    DB::table('t_wa_device_tokens')->where('fcm_token', $fcmToken)->update(['is_active' => 0]);
                }
                return false;
            }
            return true;
        } catch (\Exception $e) {
            Log::warning('Firebase: silent data push HTTP error', ['error' => $e->getMessage()]);
            return false;
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
