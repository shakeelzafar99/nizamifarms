<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\ConversationReadModel;
use App\Models\WhatsApp\MessageModel;
use App\Models\CRM\OrderModel;
use App\Services\QurbaniClassifier;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class WhatsAppController extends Controller
{
    protected WhatsAppService $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }

    /**
     * Resolve the authenticated user's WhatsApp-view access level.
     *   - allowed: has full OR limited view permission
     *   - limited: has ONLY the limited permission (restricted to today+day-1)
     *   - cutoff : start of yesterday, the earliest timestamp visible to limited users
     *
     * Mirrors Web\WhatsAppWebController::resolveWhatsAppAccess so behaviour
     * between web and mobile stays consistent.
     */
    protected function resolveWhatsAppAccess($user): array
    {
        $hasFull    = $user && $user->hasMobilePermission('view_whatsapp_messages');
        $hasLimited = $user && $user->hasMobilePermission('view_whatsapp_messages_limited');
        return [
            'allowed' => $hasFull || $hasLimited,
            'limited' => !$hasFull && $hasLimited,
            'cutoff'  => now()->subDay()->startOfDay(),
        ];
    }

    // =========================================================================
    // CONVERSATIONS
    // =========================================================================

    /**
     * List all conversations with last message preview
     */
    public function getConversations(Request $request)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $filter = $request->get('filter', 'all'); // all, unread, mine, qurbani
            $search = $request->get('search', '');

            $userId = $user->id;
            $hasReadsTable = Schema::hasTable('t_wa_conversation_reads');
            $hasQurbaniCol = Schema::hasColumn('t_wa_conversations', 'is_qurbani');

            $query = ConversationModel::with(['customer:id,first_name,last_name,phone_normalized,city'])
                ->where('status', '!=', 'closed');

            // Limited users only see conversations whose most recent activity
            // is within the last two days (today + yesterday). Matches the web.
            if ($access['limited']) {
                $query->where('last_message_at', '>=', $access['cutoff']);
            }

            if ($filter === 'mine') {
                $query->where('assigned_to', $user->id);
            } elseif ($filter === 'qurbani' && $hasQurbaniCol) {
                $query->where('is_qurbani', 1);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('wa_phone', 'LIKE', "%{$search}%")
                      ->orWhere('wa_contact_name', 'LIKE', "%{$search}%")
                      ->orWhereHas('customer', function ($cq) use ($search) {
                          $cq->where('first_name', 'LIKE', "%{$search}%")
                             ->orWhere('last_name', 'LIKE', "%{$search}%")
                             ->orWhere('phone_normalized', 'LIKE', "%{$search}%")
                             ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                      });
                });
            }

            $conversations = $query->orderByDesc('last_message_at')
                ->limit(150)
                ->get();

            $conversationIds = $conversations->pluck('id')->toArray();
            $lastMessages = MessageModel::whereIn('conversation_id', $conversationIds)
                ->whereIn('id', function ($q) use ($conversationIds) {
                    $q->selectRaw('MAX(id)')
                      ->from('t_wa_messages')
                      ->whereIn('conversation_id', $conversationIds)
                      ->groupBy('conversation_id');
                })
                ->get()
                ->keyBy('conversation_id');

            // Per-user unread counts (see web controller for rule details).
            $unreadByConv = [];
            if ($hasReadsTable && !empty($conversationIds)) {
                $unreadQ = DB::table('t_wa_messages as m')
                    ->selectRaw('m.conversation_id, COUNT(*) as cnt')
                    ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                        $j->on('r.conversation_id', '=', 'm.conversation_id')
                          ->where('r.user_id', '=', $userId);
                    })
                    ->whereIn('m.conversation_id', $conversationIds)
                    ->where('m.direction', 'inbound')
                    ->where(function ($w) {
                        $w->whereNull('r.last_read_at')
                          ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
                    })
                    ->whereRaw('NOT EXISTS (
                        SELECT 1 FROM t_wa_messages m2
                        WHERE m2.conversation_id = m.conversation_id
                          AND m2.direction = \'outbound\'
                          AND m2.created_at > m.created_at
                    )');

                // Limited users shouldn't see unread counts for messages they
                // aren't allowed to read.
                if ($access['limited']) {
                    $unreadQ->where('m.created_at', '>=', $access['cutoff']);
                }

                $rows = $unreadQ->groupBy('m.conversation_id')->get();
                foreach ($rows as $r) {
                    $unreadByConv[$r->conversation_id] = (int) $r->cnt;
                }
            } else {
                foreach ($conversations as $c) {
                    $unreadByConv[$c->id] = (int) $c->unread_count;
                }
            }

            $result = $conversations->map(function ($conv) use ($lastMessages, $unreadByConv, $hasQurbaniCol) {
                $lastMsg = $lastMessages->get($conv->id);
                return [
                    'id' => $conv->id,
                    'customer_id' => $conv->customer_id,
                    'customer_name' => $conv->display_name,
                    'customer_city' => $conv->customer?->city,
                    'wa_phone' => $conv->wa_phone,
                    'wa_contact_name' => $conv->wa_contact_name,
                    'unread_count' => $unreadByConv[$conv->id] ?? 0,
                    'is_qurbani' => $hasQurbaniCol ? (bool) $conv->is_qurbani : false,
                    'qurbani_flag_reason' => $hasQurbaniCol ? ($conv->qurbani_flag_reason ?? null) : null,
                    'session_active' => $conv->isSessionActive(),
                    'last_message_at' => $conv->last_message_at?->toIso8601String(),
                    'last_message_preview' => $lastMsg ? mb_substr($lastMsg->content ?? '', 0, 80) : null,
                    'last_message_direction' => $lastMsg?->direction,
                    'last_message_type' => $lastMsg?->type,
                    'assigned_to' => $conv->assigned_to,
                ];
            });

            if ($filter === 'unread') {
                $result = $result->filter(fn($c) => ($c['unread_count'] ?? 0) > 0)->values();
            }

            return response()->json([
                'success' => true,
                'conversations' => $result,
                'is_limited' => $access['limited'],
                'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to get conversations', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get messages for a conversation
     */
    public function getMessages(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $conversation = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city,total_orders,total_spent')
                ->find($conversationId);

            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            // Limited users cannot reach conversations whose most recent
            // activity is older than the cutoff. 404 so the mobile app treats
            // them as "not visible" (same as the list filter).
            if ($access['limited'] && $conversation->last_message_at && $conversation->last_message_at->lt($access['cutoff'])) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            $before = $request->get('before'); // for pagination - load older messages
            $limit = $request->get('limit', 50);

            $query = MessageModel::where('conversation_id', $conversationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            // Limited users see only today + yesterday messages within an
            // otherwise-allowed conversation.
            if ($access['limited']) {
                $query->where('created_at', '>=', $access['cutoff']);
            }

            if ($before) {
                $query->where('id', '<', $before);
            }

            $messages = $query->limit($limit)->get();

            $result = $messages->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'wa_message_id' => $msg->wa_message_id,
                    'direction' => $msg->direction,
                    'type' => $msg->type,
                    'content' => $msg->content,
                    'template_name' => $msg->template_name,
                    'media_url' => $msg->media_public_url,
                    'media_mime_type' => $msg->media_mime_type,
                    'status' => $msg->status,
                    'sent_by' => $msg->sent_by,
                    'sender_name' => $msg->sender ? $msg->sender->name : null,
                    'error_message' => $msg->error_message,
                    'metadata' => $msg->metadata ? json_decode($msg->metadata, true) : null,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            })->reverse()->values(); // Reverse so oldest is first in the array

            $seenBy = [];
            if (Schema::hasTable('t_wa_conversation_reads')) {
                $seenBy = ConversationReadModel::where('conversation_id', $conversation->id)
                    ->where('user_id', '!=', $user->id)
                    ->orderByDesc('last_read_at')
                    ->limit(10)
                    ->get()
                    ->map(function ($r) {
                        // t_sys_user uses `fullname` (not `name`) for the display
                        // column — `->name` would always be null and we'd show
                        // "User #<id>" on the mobile seen-by badge.
                        $u = \App\Models\User::find($r->user_id);
                        return [
                            'user_id' => $r->user_id,
                            'name' => $u?->fullname ?? $u?->email ?? ('User #' . $r->user_id),
                            'last_read_at' => $r->last_read_at?->toIso8601String(),
                        ];
                    })->all();
            }

            $hasQurbaniCol = Schema::hasColumn('t_wa_conversations', 'is_qurbani');

            return response()->json([
                'success' => true,
                'conversation' => [
                    'id' => $conversation->id,
                    'customer_id' => $conversation->customer_id,
                    'customer_name' => $conversation->display_name,
                    'customer_city' => $conversation->customer?->city,
                    'customer_orders' => $conversation->customer?->total_orders,
                    'customer_spent' => $conversation->customer?->total_spent,
                    'wa_phone' => $conversation->wa_phone,
                    'is_qurbani' => $hasQurbaniCol ? (bool) $conversation->is_qurbani : false,
                    'qurbani_flag_reason' => $hasQurbaniCol ? ($conversation->qurbani_flag_reason ?? null) : null,
                    'session_active' => $conversation->isSessionActive(),
                    'session_expires_at' => $conversation->last_customer_message_at
                        ? $conversation->last_customer_message_at->addHours(24)->toIso8601String()
                        : null,
                    'assigned_to' => $conversation->assigned_to,
                    'seen_by' => $seenBy,
                ],
                'messages' => $result,
                'has_more' => $messages->count() === $limit,
                'is_limited' => $access['limited'],
                'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to get messages', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a free-form text message in a conversation
     */
    public function sendMessage(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate(['message' => 'required|string|max:4096']);

            $conversation = ConversationModel::find($conversationId);
            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            if (!$conversation->isSessionActive()) {
                return response()->json([
                    'success' => false,
                    'message' => '24-hour window expired. Use a template message to re-initiate.',
                    'session_expired' => true,
                ], 422);
            }

            $response = $this->whatsapp->sendTextMessage($conversation->wa_phone, $request->input('message'));

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send message',
                ], 500);
            }

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'text',
                $request->input('message'),
                $user->id
            );

            return response()->json([
                'success' => true,
                'message_id' => $message?->id,
                'wa_message_id' => $message?->wa_message_id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send message', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a template message (can initiate conversations outside 24h window)
     */
    public function sendTemplate(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'phone' => 'required|string',
                'template_name' => 'required|string',
                'body_params' => 'nullable|array',
                'customer_id' => 'nullable|integer',
                'conversation_id' => 'nullable|integer',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));
            $templateName = $request->input('template_name');
            $bodyParams = $request->input('body_params', []);

            $response = $this->whatsapp->sendTemplateMessage($phone, $templateName, 'en', $bodyParams);

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send template',
                ], 500);
            }

            // Find or create conversation
            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            // Link to customer if provided and not already linked
            if ($request->input('customer_id') && !$conversation->customer_id) {
                $conversation->update(['customer_id' => $request->input('customer_id')]);
            }

            // Build human-readable content from template
            $templateDisplay = $this->buildTemplateDisplayText($templateName, $bodyParams);

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'template',
                $templateDisplay,
                $user->id,
                $templateName,
                $bodyParams
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send template', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a conversation as read for this user and send blue-tick receipts
     * to the customer.
     */
    public function markRead(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $now = now();
            if (Schema::hasTable('t_wa_conversation_reads')) {
                ConversationReadModel::updateOrCreate(
                    ['user_id' => $user->id, 'conversation_id' => $conversationId],
                    ['last_read_at' => $now]
                );
            }

            ConversationModel::where('id', $conversationId)->update(['unread_count' => 0]);

            try {
                $this->whatsapp->markInboundAsReadOnWhatsApp((int) $conversationId);
            } catch (\Exception $e) {
                Log::debug('markRead: WA receipt failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Total unread for THIS user across all conversations. Mobile badge.
     */
    public function getUnreadCount(Request $request)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => true, 'unread_count' => 0]);
            }

            if (!Schema::hasTable('t_wa_conversation_reads')) {
                $count = ConversationModel::where('unread_count', '>', 0)
                    ->where('status', '!=', 'closed')
                    ->sum('unread_count');
                return response()->json(['success' => true, 'unread_count' => (int) $count]);
            }

            $countQ = DB::table('t_wa_messages as m')
                ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($user) {
                    $j->on('r.conversation_id', '=', 'm.conversation_id')
                      ->where('r.user_id', '=', $user->id);
                })
                ->where('m.direction', 'inbound')
                ->where(function ($w) {
                    $w->whereNull('r.last_read_at')
                      ->orWhereColumn('m.created_at', '>', 'r.last_read_at');
                })
                ->whereRaw('NOT EXISTS (
                    SELECT 1 FROM t_wa_messages m2
                    WHERE m2.conversation_id = m.conversation_id
                      AND m2.direction = \'outbound\'
                      AND m2.created_at > m.created_at
                )');

            // Limited users' badge only counts messages inside their window.
            if ($access['limited']) {
                $countQ->where('m.created_at', '>=', $access['cutoff']);
            }

            $count = $countQ->count();

            return response()->json(['success' => true, 'unread_count' => (int) $count]);

        } catch (\Exception $e) {
            return response()->json(['success' => true, 'unread_count' => 0]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Qurbani tab settings (mobile-accessible)
    // ─────────────────────────────────────────────────────────────────

    public function getQurbaniSettings(Request $request, QurbaniClassifier $classifier)
    {
        try {
            return response()->json([
                'success' => true,
                'settings' => $classifier->getSettings(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateQurbaniSettings(Request $request, QurbaniClassifier $classifier)
    {
        try {
            $request->validate([
                'enabled'  => 'sometimes|boolean',
                'keywords' => 'sometimes',
                'lookback' => 'sometimes|integer|min:1|max:20',
                'year'     => 'sometimes|nullable|integer|min:2000|max:2100',
            ]);

            $settings = $classifier->updateSettings($request->only(['enabled', 'keywords', 'lookback', 'year']));
            return response()->json(['success' => true, 'settings' => $settings]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function rescanQurbani(Request $request, QurbaniClassifier $classifier)
    {
        try {
            $stats = $classifier->rescanAll();
            return response()->json(['success' => true] + $stats);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get WhatsApp message history for a specific customer
     */
    public function getCustomerMessages(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            // Allow both full and limited view users. getMessages() is called
            // below, which in turn applies the limited-view date cutoff itself,
            // so we don't need to re-enforce it here.
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $conversation = ConversationModel::where('customer_id', $customerId)->first();

            if (!$conversation) {
                return response()->json([
                    'success' => true,
                    'conversation' => null,
                    'messages' => [],
                ]);
            }

            // Delegate to getMessages with the conversation ID
            $request->merge(['limit' => $request->get('limit', 50)]);
            return $this->getMessages($request, $conversation->id);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a message to a customer (creates conversation if needed)
     */
    public function sendToCustomer(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $customer = \App\Models\CRM\CustomerModel::find($customerId);
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
            }

            $phone = $this->whatsapp->formatPhone($customer->phone_normalized ?? $customer->phone);

            $conversation = $this->whatsapp->findOrCreateConversation($phone);
            if (!$conversation->customer_id) {
                $conversation->update(['customer_id' => $customerId]);
            }

            // If sending a template
            if ($request->has('template_name')) {
                $request->merge([
                    'phone' => $phone,
                    'customer_id' => $customerId,
                    'conversation_id' => $conversation->id,
                ]);
                return $this->sendTemplate($request);
            }

            // If sending a text (session must be active)
            $request->validate(['message' => 'required|string|max:4096']);
            return $this->sendMessage($request, $conversation->id);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a free-form text message to a phone number (creates conversation if needed)
     */
    public function sendToPhone(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'phone' => 'required|string',
                'message' => 'required|string|max:4096',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));
            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            if (!$conversation->isSessionActive()) {
                return response()->json([
                    'success' => false,
                    'message' => '24-hour window expired. Use a template message.',
                    'session_expired' => true,
                ], 422);
            }

            $response = $this->whatsapp->sendTextMessage($phone, $request->input('message'));

            if (!($response['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to send message',
                ], 500);
            }

            $message = $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $response,
                'text',
                $request->input('message'),
                $user->id
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'message_id' => $message?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send to phone', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get available templates for sending
     */
    public function getTemplates(Request $request)
    {
        try {
            $query = DB::table('t_wa_templates')
                ->where('status', 'approved');

            // Hide soft-disabled templates from every mobile picker.
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_active')) {
                $query->where('is_active', 1);
            }

            $context = $request->query('context');
            if ($context) {
                $contexts = array_map('trim', explode(',', $context));
                $query->where(function ($q) use ($contexts) {
                    foreach ($contexts as $ctx) {
                        if ($ctx === '') continue;
                        $q->orWhereRaw("FIND_IN_SET(?, show_in) > 0", [$ctx]);
                    }
                });

                // Scope filtering:
                //   - Non-qurbani picker → hide qurbani-only templates
                //   - Qurbani picker     → hide regular-only templates
                // Templates with both flags = 0 are "Common" and show in both.
                $isQurbaniCtx = false;
                foreach ($contexts as $c) {
                    if ($c !== '' && (str_starts_with($c, 'qurbani_') || $c === 'qurbani')) {
                        $isQurbaniCtx = true;
                        break;
                    }
                }
                if (!$isQurbaniCtx && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) {
                    $query->where('is_qurbani_only', 0);
                }
                if ($isQurbaniCtx && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'is_regular_only')) {
                    $query->where('is_regular_only', 0);
                }
            }

            $templates = $query->orderBy('display_name')->get();

            return response()->json(['success' => true, 'templates' => $templates]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get cost tracking summary
     */
    public function getCostSummary(Request $request)
    {
        try {
            $user = Auth::user();

            $todayCount = DB::table('t_wa_cost_log')->whereDate('created_at', today())->count();
            $monthCount = DB::table('t_wa_cost_log')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->count();

            $byCategory = DB::table('t_wa_cost_log')
                ->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month)
                ->selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category');

            return response()->json([
                'success' => true,
                'today' => $todayCount,
                'this_month' => $monthCount,
                'by_category' => $byCategory,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Build human-readable text from a template name + params for display
     */
    /**
     * Register or update a device's FCM token for push notifications
     */
    public function registerDevice(Request $request)
    {
        try {
            $user = Auth::user();
            $request->validate([
                'fcm_token' => 'required|string|max:500',
                'device_type' => 'nullable|string|in:android,ios',
            ]);

            $token = $request->input('fcm_token');
            $deviceType = $request->input('device_type', 'android');

            // Deactivate any old entries with this token (could be from another user)
            DB::table('t_wa_device_tokens')
                ->where('fcm_token', $token)
                ->where('user_id', '!=', $user->id)
                ->update(['is_active' => 0]);

            // Upsert: insert or update for this user + token combination
            DB::table('t_wa_device_tokens')->updateOrInsert(
                ['user_id' => $user->id, 'fcm_token' => $token],
                [
                    'device_type' => $deviceType,
                    'is_active' => 1,
                    'updated_at' => now(),
                ]
            );

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('FCM: Failed to register device', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Deactivate a device's FCM token so the backend stops sending pushes
     * to it. Called by the mobile app on logout (before the auth token is
     * cleared) so that push notifications immediately stop for a logged-out
     * device without waiting for the token to expire.
     *
     * Behaviour:
     *  - If a specific fcm_token is provided: only that token is marked
     *    inactive, and only when it currently belongs to the authenticated
     *    user (prevents one user from deactivating another user's device
     *    tokens on a shared device).
     *  - If no fcm_token is provided: ALL tokens for the current user are
     *    marked inactive (safe fallback when the mobile app can't resolve
     *    its current token, e.g. Firebase is mid-refresh).
     *
     * Tokens are marked is_active = 0 rather than deleted so that (a) we
     * retain history and (b) a fresh registerDevice call re-activates the
     * row cleanly via updateOrInsert on next login.
     */
    public function unregisterDevice(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Not authenticated'], 401);
            }

            $request->validate([
                'fcm_token' => 'nullable|string|max:500',
            ]);

            $token = $request->input('fcm_token');

            $query = DB::table('t_wa_device_tokens')->where('user_id', $user->id);
            if (!empty($token)) {
                $query->where('fcm_token', $token);
            }

            $affected = $query->update([
                'is_active' => 0,
                'updated_at' => now(),
            ]);

            Log::info('FCM: Device token(s) unregistered on logout', [
                'user_id' => $user->id,
                'scoped_to_token' => !empty($token),
                'rows_updated' => $affected,
            ]);

            return response()->json([
                'success' => true,
                'deactivated' => $affected,
            ]);
        } catch (\Exception $e) {
            Log::error('FCM: Failed to unregister device', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send a test push notification to the current user's device (dev only)
     */
    public function testNotification(Request $request)
    {
        try {
            $user = Auth::user();
            $senderName = $request->input('sender_name', 'Ahmed Khan');
            $message = $request->input('message', 'Hi, I want to place an order for tomorrow delivery');

            $firebase = app(\App\Services\FirebaseService::class);
            $projectId = config('whatsapp.firebase_project_id', '');
            $credFile = config('whatsapp.firebase_credentials_path', 'firebase-service-account.json');
            $credPath = base_path($credFile);

            if (!$projectId) {
                return response()->json(['success' => false, 'message' => 'FIREBASE_PROJECT_ID not set in .env']);
            }
            if (!file_exists($credPath)) {
                return response()->json(['success' => false, 'message' => "Firebase credentials file not found at: {$credFile}. Place the JSON file at: {$credPath}"]);
            }

            $tokenCount = DB::table('t_wa_device_tokens')
                ->where('is_active', 1)
                ->where('user_id', $user->id)
                ->count();

            if ($tokenCount === 0) {
                return response()->json(['success' => false, 'message' => 'No active FCM device token found for your user. Make sure you logged in on the mobile app after Firebase was set up.']);
            }

            $firebase->notifyNewWhatsAppMessage($senderName, $message, null);

            return response()->json(['success' => true, 'message' => "Test notification sent to {$tokenCount} device(s). Check your notification bar."]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get orders for a customer (for invoice picker)
     */
    public function getCustomerOrders(Request $request, $customerId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $orders = OrderModel::where('customer_id', $customerId)
                ->with(['lineItems:id,order_id,name,quantity,unit_price,line_total', 'assignedRider:id,fullname'])
                ->orderBy('order_date', 'desc')
                ->limit(20)
                ->get(['id', 'order_number', 'order_date', 'order_status', 'total_price', 'customer_id', 'assigned_rider_user_id', 'estimated_delivery_at']);

            return response()->json([
                'success' => true,
                'orders' => $orders->map(function($o) {
                    $eta = null;
                    if ($o->estimated_delivery_at && strtolower($o->order_status) === 'out_for_delivery') {
                        $eta = \Carbon\Carbon::parse($o->estimated_delivery_at)->format('h:i A');
                    }
                    return [
                        'id' => $o->id,
                        'order_number' => $o->order_number,
                        'order_date' => $o->order_date,
                        'status' => $o->order_status,
                        'total' => $o->total_price,
                        'items_count' => $o->lineItems->count(),
                        'items_summary' => $o->lineItems->take(3)->pluck('name')->join(', '),
                        'rider_name' => $o->assignedRider?->fullname,
                        'eta' => $eta,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate invoice image URL for an order
     */
    public function getInvoiceImageUrl(Request $request, $orderId)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $webController = app(\App\Http\Controllers\Web\WhatsAppWebController::class);
            return $webController->getInvoiceImageUrl($request, $orderId);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Upload invoice image captured by client
     */
    public function uploadInvoiceImage(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $webController = app(\App\Http\Controllers\Web\WhatsAppWebController::class);
            return $webController->uploadInvoiceImage($request);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send invoice via WhatsApp template with image header
     */
    public function sendInvoice(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'order_id' => 'required|integer',
                'phone' => 'required|string',
                'template_name' => 'required|string',
                'body_params' => 'nullable|array',
                'customer_id' => 'nullable|integer',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));

            $imgResponse = $this->getInvoiceImageUrl($request, $request->input('order_id'));
            $imgData = json_decode($imgResponse->getContent(), true);

            if (!($imgData['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $imgData['message'] ?? 'Failed to generate invoice image'], 500);
            }

            $imageUrl = $imgData['image_url'];
            $orderNumber = $imgData['order_number'];

            $headerParams = [
                ['type' => 'image', 'image' => ['link' => $imageUrl]],
            ];
            $bodyParams = $request->input('body_params', []);

            $result = $this->whatsapp->sendTemplateMessage($phone, $request->input('template_name'), 'en', $bodyParams, $headerParams);

            if (!($result['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 500);
            }

            $conversation = $this->whatsapp->findOrCreateConversation($phone);

            if ($request->input('customer_id') && !$conversation->customer_id) {
                $conversation->update(['customer_id' => $request->input('customer_id')]);
            }

            $this->whatsapp->saveOutboundMessage(
                $conversation->id,
                $result,
                'template',
                "Invoice #{$orderNumber}",
                $user->id,
                $request->input('template_name'),
                $bodyParams
            );

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'order_number' => $orderNumber,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send invoice', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    protected function buildTemplateDisplayText(string $templateName, array $params): string
    {
        $templateBodies = [
            'capacity_full' => "Dear {{1}},\n\nWe received your order (Order No: {{2}}) on our website today.\n\nAs we are fully occupied today, your order can be delivered fresh tomorrow. Would you like to confirm delivery for tomorrow?\n\nThank you for choosing Nizami Farms.",
            'next_day_delivery' => "Dear {{1}},\n\nAn order was received on our website today - Order No: {{2}}\n\nThis order can be delivered to you fresh tomorrow. Would you like to have this order delivered tomorrow? Please confirm.\n\nThank you for ordering from Nizami Farms",
            'meatless_days' => "Dear {{1}},\n\nThank you for placing your order {{2}} with Nizami Farms! We confirm receipt of your order.\n\nPlease note that Tuesday and Wednesday are non-meat days, and our operations are closed. Your order will be delivered on Thursday.\n\nTo confirm, kindly reply to this message. We will process your order for Thursday delivery.\n\nBest regards,\nNizami Farms Team",
            'location_request' => "Dear {{1}},\n\nCould you please share your Google Maps location pin for delivery? Simply tap the attach icon > Location > Send your current location.\n\nThis helps our rider reach you without any delays.\n\nThank you,\nNizami Farms Team",
            'customer_greeting' => "Assalam-o-Alaikum {{1}},\n\nThis is Nizami Farms. How can we help you today?\n\nBest regards,\nNizami Farms Team",
            'delivery_confirmation_online' => "Dear {{1}},\n\nWe are happy to confirm that your order #{{2}} has been successfully delivered on {{3}} by our rider {{4}}.\n\nYour payment method is Online Bank Transfer. Please share a screenshot of the transfer here once the transaction has been made.\n\nAccount Title: \"Nizami Farms\"\n- Bank: Habib Bank Limited (HBL)\n   Account no: 23297901934403\n   IBAN: PK35HABB0023297901934403\n\n- Bank: Meezan Bank Limited\n   Account no: 03050106554237\n   IBAN: PK75MEZN0003050106554237\n\nThank you for choosing Nizami Farms!",
            'delivery_confirmation' => "Dear {{1}},\n\nYour order {{2}} is now out for delivery! Our rider will contact you upon arrival at your location.\n\nPayment options:\n- Cash on delivery\n- Online transfer\n\nAccount Title: \"Nizami Farms\"\n• Bank: Habib Bank Limited (HBL)\n   Account no: 23297901934403\n   IBAN: PK35HABB0023297901934403\n\n• Bank: Meezan Bank Limited\n   Account no: 03050106554237\n   IBAN: PK75MEZN0003050106554237\n\n(Please share the transfer slip with us to ensure a smooth delivery process)\n\nThank you for choosing Nizami Farms!",
        ];

        $body = $templateBodies[$templateName] ?? "[Template: {$templateName}]";

        // Replace placeholders with actual params
        foreach ($params as $index => $value) {
            $placeholder = '{{' . ($index + 1) . '}}';
            $body = str_replace($placeholder, $value, $body);
        }

        return $body;
    }
}
