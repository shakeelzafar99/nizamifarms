<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\ConversationReadModel;
use App\Models\WhatsApp\MessageModel;
use App\Models\CRM\OrderModel;
use App\Services\QurbaniClassifier;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class WhatsAppWebController extends Controller
{
    /**
     * Resolve the current user's WhatsApp-view access:
     *   - 'allowed'  : true when either full or limited view permission is granted
     *   - 'limited'  : true when ONLY the limited permission is granted
     *                  (full permission overrides limited)
     *   - 'cutoff'   : Carbon timestamp; inbound data older than this must be
     *                  hidden from limited users. Start of yesterday.
     *
     * The "limited" flavour restricts the user to conversations / messages
     * from today and day-1 only. This is enforced in every read endpoint
     * below so the restriction cannot be bypassed by crafting requests.
     */
    protected function resolveWhatsAppAccess(): array
    {
        $user = auth()->user();
        $hasFull = $user && $user->hasMobilePermission('view_whatsapp_messages');
        $hasLimited = $user && $user->hasMobilePermission('view_whatsapp_messages_limited');

        return [
            'allowed' => $hasFull || $hasLimited,
            'limited' => !$hasFull && $hasLimited,
            // Start of yesterday (local app timezone): this is the earliest
            // message/conversation timestamp limited users are allowed to see.
            'cutoff'  => now()->subDay()->startOfDay(),
        ];
    }

    public function index()
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            abort(403, 'You do not have permission to view WhatsApp Messages.');
        }
        return view('pages.messages.index', [
            'waIsLimited' => $access['limited'],
            'waCutoffAt'  => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
        ]);
    }

    public function getConversations(Request $request)
    {
        if (!Schema::hasTable('t_wa_conversations')) {
            return response()->json(['success' => true, 'conversations' => []]);
        }

        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $userId = auth()->id();
        $hasReadsTable = Schema::hasTable('t_wa_conversation_reads');
        $hasQurbaniCol = Schema::hasColumn('t_wa_conversations', 'is_qurbani');

        $query = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city')
            ->orderByDesc('last_message_at');

        // Limited view: only conversations whose most recent activity is within
        // the last two days (today + yesterday). Older conversations are
        // invisible and their messages cannot be fetched (see getMessages).
        if ($access['limited']) {
            $query->where('last_message_at', '>=', $access['cutoff']);
        }

        // Qurbani tab shows only conversations auto-flagged as qurbani.
        if ($request->filter === 'qurbani' && $hasQurbaniCol) {
            $query->where('is_qurbani', 1);
        }

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('wa_phone', 'like', "%{$search}%")
                  ->orWhere('wa_contact_name', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('phone_normalized', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                  });
            });
        }

        // Fetch conversations + last-read timestamps for this user in one shot.
        $lastReadMap = [];
        $convs = $query->limit(150)->get();
        $convIds = $convs->pluck('id')->all();

        if ($hasReadsTable && $userId && !empty($convIds)) {
            $lastReadMap = ConversationReadModel::where('user_id', $userId)
                ->whereIn('conversation_id', $convIds)
                ->pluck('last_read_at', 'conversation_id')
                ->map(fn($v) => $v ? \Carbon\Carbon::parse($v) : null)
                ->all();
        }

        // Unread-for-this-user count = inbound messages created after my last_read_at.
        // Batched with one grouped query to avoid N+1.
        $unreadByConv = [];
        if (!empty($convIds)) {
            $msgQ = DB::table('t_wa_messages')
                ->selectRaw('conversation_id, COUNT(*) as cnt')
                ->whereIn('conversation_id', $convIds)
                ->where('direction', 'inbound')
                ->groupBy('conversation_id');

            // Per-conversation unread for THIS user. Two rules:
            //   1. Inbound message is newer than my last_read_at for this convo.
            //   2. No staff has replied AFTER this inbound message. Any outbound
            //      (by anyone) effectively marks prior inbounds as "handled" for
            //      all staff — this is the "reply = read for everyone" rule.
            if ($hasReadsTable && $userId) {
                $msgQuery = DB::table('t_wa_messages as m')
                    ->selectRaw('m.conversation_id, COUNT(*) as cnt')
                    ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                        $j->on('r.conversation_id', '=', 'm.conversation_id')
                          ->where('r.user_id', '=', $userId);
                    })
                    ->whereIn('m.conversation_id', $convIds)
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
                // aren't allowed to read. Mirror the visible-window filter.
                if ($access['limited']) {
                    $msgQuery->where('m.created_at', '>=', $access['cutoff']);
                }

                $rows = $msgQuery->groupBy('m.conversation_id')->get();
                foreach ($rows as $r) {
                    $unreadByConv[$r->conversation_id] = (int) $r->cnt;
                }
            } else {
                // Fallback: legacy unread_count column per conversation.
                foreach ($convs as $c) {
                    $unreadByConv[$c->id] = (int) $c->unread_count;
                }
            }
        }

        // Last message preview per conversation in one grouped query.
        $lastMsgByConv = [];
        if (!empty($convIds)) {
            $latestIds = DB::table('t_wa_messages')
                ->selectRaw('MAX(id) as id, conversation_id')
                ->whereIn('conversation_id', $convIds)
                ->groupBy('conversation_id')
                ->pluck('id');
            if ($latestIds->isNotEmpty()) {
                $lastMsgByConv = MessageModel::whereIn('id', $latestIds)
                    ->get()
                    ->keyBy('conversation_id');
            }
        }

        $conversations = $convs->map(function ($conv) use ($unreadByConv, $lastMsgByConv, $hasQurbaniCol) {
            $lastMsg = $lastMsgByConv[$conv->id] ?? null;
            $unread = $unreadByConv[$conv->id] ?? 0;

            return [
                'id' => $conv->id,
                'customer_id' => $conv->customer_id,
                'customer_name' => $conv->customer ? $conv->customer->full_name : ($conv->wa_contact_name ?: $conv->wa_phone),
                'customer_city' => $conv->customer?->city ?? '',
                'wa_phone' => $conv->wa_phone,
                'status' => $conv->status,
                'unread_count' => $unread,
                'is_qurbani' => $hasQurbaniCol ? (bool) $conv->is_qurbani : false,
                'qurbani_flag_reason' => $hasQurbaniCol ? ($conv->qurbani_flag_reason ?? null) : null,
                'last_message_at' => $conv->last_message_at,
                'last_message_preview' => $lastMsg?->content ? \Illuminate\Support\Str::limit($lastMsg->content, 80) : ($lastMsg?->type ?? ''),
                'last_message_direction' => $lastMsg?->direction ?? '',
                'session_active' => $conv->isSessionActive(),
                'session_expires_at' => $conv->last_customer_message_at
                    ? $conv->last_customer_message_at->addHours(24)->toIso8601String()
                    : null,
            ];
        });

        // "Unread" filter applied after compute so it uses per-user counts.
        if ($request->filter === 'unread') {
            $conversations = $conversations->filter(fn($c) => ($c['unread_count'] ?? 0) > 0)->values();
        }

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
            'is_limited' => $access['limited'],
            'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
        ]);
    }

    public function getMessages(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $conversation = ConversationModel::with('customer:id,first_name,last_name,phone_normalized,city')->findOrFail($conversationId);

        // Limited users cannot access conversations whose most recent activity
        // is older than the cutoff. Returning 404 (not 403) so the UI treats
        // them as "not visible" consistently with the list-level filter above.
        if ($access['limited'] && $conversation->last_message_at && $conversation->last_message_at->lt($access['cutoff'])) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        $query = MessageModel::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc');

        // And within an allowed conversation, limited users still only see
        // messages from today + yesterday.
        if ($access['limited']) {
            $query->where('created_at', '>=', $access['cutoff']);
        }

        if ($request->before) {
            $query->where('id', '<', $request->before);
        }

        $limit = $request->limit ?? 50;
        $messages = $query->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;
        if ($hasMore) $messages = $messages->slice(0, $limit);

        $totalOrders = $conversation->customer_id
            ? \App\Models\CRM\OrderModel::where('customer_id', $conversation->customer_id)->count()
            : 0;

        // Build "seen by" list: who has read this conversation (excluding me) + when.
        $seenBy = [];
        if (Schema::hasTable('t_wa_conversation_reads')) {
            $currentUserId = auth()->id();
            $seenBy = ConversationReadModel::where('conversation_id', $conversation->id)
                ->when($currentUserId, fn($q) => $q->where('user_id', '!=', $currentUserId))
                ->orderByDesc('last_read_at')
                ->limit(10)
                ->get()
                ->map(function ($r) {
                    // Our custom User model lives on `t_sys_user` where the
                    // display column is `fullname` (not Laravel's default `name`),
                    // so `$user->name` is always null — falling back to
                    // "User #<id>" which looked awful in the UI.
                    $user = \App\Models\User::find($r->user_id);
                    return [
                        'user_id' => $r->user_id,
                        'name' => $user?->fullname ?? $user?->email ?? ('User #' . $r->user_id),
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
                'customer_name' => $conversation->customer ? $conversation->customer->full_name : ($conversation->wa_contact_name ?: $conversation->wa_phone),
                'customer_city' => $conversation->customer?->city ?? '',
                'customer_orders' => $totalOrders,
                'wa_phone' => $conversation->wa_phone,
                'status' => $conversation->status,
                'unread_count' => $conversation->unread_count,
                'is_qurbani' => $hasQurbaniCol ? (bool) $conversation->is_qurbani : false,
                'qurbani_flag_reason' => $hasQurbaniCol ? ($conversation->qurbani_flag_reason ?? null) : null,
                'session_active' => $conversation->isSessionActive(),
                'session_expires_at' => $conversation->last_customer_message_at
                    ? $conversation->last_customer_message_at->addHours(24)->toIso8601String()
                    : null,
                'seen_by' => $seenBy,
            ],
            'messages' => $messages->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'direction' => $msg->direction,
                    'type' => $msg->type,
                    'content' => $msg->content,
                    'template_name' => $msg->template_name,
                    'media_url' => $msg->media_public_url,
                    'media_mime_type' => $msg->media_mime_type,
                    'status' => $msg->status,
                    'error_message' => $msg->error_message,
                    'sender_name' => $msg->sender?->name ?? null,
                    'metadata' => $msg->metadata ? json_decode($msg->metadata, true) : null,
                    'created_at' => $msg->created_at?->toIso8601String(),
                ];
            })->values(),
            'has_more' => $hasMore,
            'is_limited' => $access['limited'],
            'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
        ]);
    }

    public function sendMessage(Request $request, $conversationId)
    {
        $request->validate(['message' => 'required|string|max:4096']);

        $conversation = ConversationModel::findOrFail($conversationId);
        $service = app(WhatsAppService::class);

        if (!$service->isSessionActive($conversation)) {
            return response()->json([
                'success' => false,
                'session_expired' => true,
                'message' => 'The 24-hour messaging window has expired. Please use a template message.',
            ], 422);
        }

        $result = $service->sendTextMessage($conversation->wa_phone, $request->message);

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 422);
        }

        $service->saveOutboundMessage($conversation->id, $result, 'text', $request->message, auth()->id());
        return response()->json(['success' => true]);
    }

    public function sendTemplate(Request $request)
    {
        $request->validate([
            'phone' => 'required',
            'template_name' => 'required',
            'conversation_id' => 'nullable|integer',
        ]);

        $service = app(WhatsAppService::class);
        $bodyParams = $request->body_params ?? [];
        $phone = $service->formatPhone($request->phone);

        $result = $service->sendTemplateMessage($phone, $request->template_name, 'en', $bodyParams);

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send template'], 422);
        }

        $conversation = $request->conversation_id
            ? ConversationModel::find($request->conversation_id)
            : $service->findOrCreateConversation($phone);

        if ($conversation) {
            $paramText = implode(', ', $bodyParams);
            $service->saveOutboundMessage(
                $conversation->id,
                $result,
                'template',
                "Template: {$request->template_name}" . ($paramText ? " ({$paramText})" : ''),
                auth()->id(),
                $request->template_name,
                $bodyParams
            );
        }
        return response()->json(['success' => true]);
    }

    public function markRead(Request $request, $conversationId)
    {
        $userId = auth()->id();
        $now = now();

        // Per-user read state (new model).
        if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
            ConversationReadModel::updateOrCreate(
                ['user_id' => $userId, 'conversation_id' => $conversationId],
                ['last_read_at' => $now]
            );
        }

        // Legacy column is still zeroed out so global badge sums stay sane.
        // (Push notifications and the mobile badge use this to avoid loading
        // the full per-user matrix.)
        ConversationModel::where('id', $conversationId)->update(['unread_count' => 0]);

        // Push blue-ticks to the customer — now that a human actually saw it.
        try {
            app(WhatsAppService::class)->markInboundAsReadOnWhatsApp((int) $conversationId);
        } catch (\Exception $e) {
            Log::debug('markRead: WA receipt failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Global unread badge. Counts "any conversation with any unread
     * inbound message for the current user". This is exposed for the
     * top-bar badge in the layout.
     */
    public function getUnreadCount()
    {
        if (!Schema::hasTable('t_wa_conversations')) {
            return response()->json(['success' => true, 'unread_count' => 0]);
        }

        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            // No view permission → badge is always 0.
            return response()->json(['success' => true, 'unread_count' => 0]);
        }

        $userId = auth()->id();
        if (!$userId || !Schema::hasTable('t_wa_conversation_reads')) {
            // Fallback: legacy summed column.
            $count = ConversationModel::where('unread_count', '>', 0)->sum('unread_count');
            return response()->json(['success' => true, 'unread_count' => (int) $count]);
        }

        $query = DB::table('t_wa_messages as m')
            ->leftJoin('t_wa_conversation_reads as r', function ($j) use ($userId) {
                $j->on('r.conversation_id', '=', 'm.conversation_id')
                  ->where('r.user_id', '=', $userId);
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

        // Limited users' badge only counts messages inside their visible window.
        if ($access['limited']) {
            $query->where('m.created_at', '>=', $access['cutoff']);
        }

        return response()->json(['success' => true, 'unread_count' => (int) $query->count()]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Qurbani Messages tab – settings & rescan
    // ─────────────────────────────────────────────────────────────────

    public function getQurbaniSettings(QurbaniClassifier $classifier)
    {
        return response()->json([
            'success' => true,
            'settings' => $classifier->getSettings(),
        ]);
    }

    public function updateQurbaniSettings(Request $request, QurbaniClassifier $classifier)
    {
        $request->validate([
            'enabled'  => 'sometimes|boolean',
            'keywords' => 'sometimes',
            'lookback' => 'sometimes|integer|min:1|max:20',
            'year'     => 'sometimes|nullable|integer|min:2000|max:2100',
        ]);

        $settings = $classifier->updateSettings($request->only(['enabled', 'keywords', 'lookback', 'year']));
        return response()->json(['success' => true, 'settings' => $settings]);
    }

    public function rescanQurbani(QurbaniClassifier $classifier)
    {
        try {
            $stats = $classifier->rescanAll();
            return response()->json(['success' => true] + $stats);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function getTemplates(Request $request)
    {
        if (!Schema::hasTable('t_wa_templates')) {
            return response()->json(['success' => true, 'templates' => []]);
        }
        $query = \App\Models\WhatsApp\TemplateModel::where('status', 'approved');

        // Hide inactive templates from every picker (soft-disable). Settings
        // page passes include_inactive=1 so the manager can still see/edit them.
        $includeInactive = $request->boolean('include_inactive', false);
        if (!$includeInactive && Schema::hasColumn('t_wa_templates', 'is_active')) {
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

            // Scope filtering based on whether this picker is a Qurbani picker
            // or a regular picker. Qurbani pages explicitly request contexts
            // that start with "qurbani_" (or equal "qurbani").
            //   - Non-qurbani picker → hide qurbani-only templates
            //   - Qurbani picker     → hide regular-only templates
            // Templates with both flags = 0 are "Common" and appear everywhere.
            $isQurbaniCtx = false;
            foreach ($contexts as $c) {
                if ($c !== '' && (str_starts_with($c, 'qurbani_') || $c === 'qurbani')) {
                    $isQurbaniCtx = true;
                    break;
                }
            }
            if (!$isQurbaniCtx && Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) {
                $query->where('is_qurbani_only', 0);
            }
            if ($isQurbaniCtx && Schema::hasColumn('t_wa_templates', 'is_regular_only')) {
                $query->where('is_regular_only', 0);
            }
        }

        $templates = $query->orderBy('display_name')->get();
        return response()->json(['success' => true, 'templates' => $templates]);
    }

    public function storeTemplate(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'display_name' => 'required|string|max:255',
            'body_text' => 'required|string',
            'category' => 'nullable|in:utility,marketing,authentication',
            'variable_count' => 'nullable|integer|min:0|max:10',
            'has_buttons' => 'nullable|boolean',
            'button_labels' => 'nullable|array',
            'header_text' => 'nullable|string|max:255',
            'show_in' => 'nullable|string|max:100',
            'footer_text' => 'nullable|string|max:60',
        ]);

        $isDefault = $request->boolean('is_default', false);
        $showIn = $request->show_in ?? 'messages,orders,customers,shopify';
        $isActive = $request->boolean('is_active', true);
        $isQurbaniOnly = $request->boolean('is_qurbani_only', false);
        $isRegularOnly = $request->boolean('is_regular_only', false);
        // Mutually exclusive: if both are sent as 1 (shouldn't happen with the
        // radio UI, but be defensive), prefer qurbani-only and clear regular-only.
        if ($isQurbaniOnly && $isRegularOnly) {
            $isRegularOnly = false;
        }

        if ($isDefault) {
            $this->clearDefaultsForContexts($showIn);
        }

        $attrs = [
            'name' => $request->name,
            'display_name' => $request->display_name,
            'body_text' => $request->body_text,
            'category' => $request->category ?? 'utility',
            'language' => 'en',
            'status' => 'approved',
            'variable_count' => $request->variable_count ?? 0,
            'has_buttons' => $request->has_buttons ?? false,
            'button_labels' => $request->button_labels ? json_encode($request->button_labels) : null,
            'header_text' => $request->header_text,
            'footer_text' => $request->footer_text,
            'show_in' => $showIn,
            'is_default' => $isDefault,
        ];
        if (Schema::hasColumn('t_wa_templates', 'is_active')) $attrs['is_active'] = $isActive;
        if (Schema::hasColumn('t_wa_templates', 'is_qurbani_only')) $attrs['is_qurbani_only'] = $isQurbaniOnly;
        if (Schema::hasColumn('t_wa_templates', 'is_regular_only')) $attrs['is_regular_only'] = $isRegularOnly;

        $template = \App\Models\WhatsApp\TemplateModel::create($attrs);

        return response()->json(['success' => true, 'template' => $template]);
    }

    public function updateTemplate(Request $request, $id)
    {
        $template = \App\Models\WhatsApp\TemplateModel::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'display_name' => 'sometimes|required|string|max:255',
            'body_text' => 'sometimes|required|string',
            'category' => 'sometimes|nullable|in:utility,marketing,authentication',
            'variable_count' => 'sometimes|nullable|integer|min:0|max:10',
            'has_buttons' => 'sometimes|nullable|boolean',
            'button_labels' => 'sometimes|nullable|array',
            'header_text' => 'sometimes|nullable|string|max:255',
            'footer_text' => 'sometimes|nullable|string|max:60',
            'show_in' => 'sometimes|required|string|max:100',
        ]);

        $isDefault = $request->has('is_default')
            ? $request->boolean('is_default')
            : (bool) $template->is_default;
        $showIn = $request->has('show_in') ? $request->show_in : $template->show_in;

        if ($isDefault) {
            $this->clearDefaultsForContexts($showIn, $template->id);
        }

        if ($request->has('name')) $template->name = $request->name;
        if ($request->has('display_name')) $template->display_name = $request->display_name;
        if ($request->has('body_text')) $template->body_text = $request->body_text;
        if ($request->has('category')) $template->category = $request->category ?? 'utility';
        if ($request->has('variable_count')) $template->variable_count = (int) ($request->variable_count ?? 0);
        if ($request->has('has_buttons')) $template->has_buttons = (bool) $request->has_buttons;
        if ($request->has('button_labels')) {
            $template->button_labels = $request->button_labels
                ? json_encode($request->button_labels)
                : null;
        }
        if ($request->has('header_text')) $template->header_text = $request->header_text;
        if ($request->has('footer_text')) $template->footer_text = $request->footer_text;

        $template->show_in = $showIn;
        $template->is_default = $isDefault;

        if (Schema::hasColumn('t_wa_templates', 'is_active') && $request->has('is_active')) {
            $template->is_active = $request->boolean('is_active');
        }
        if (Schema::hasColumn('t_wa_templates', 'is_qurbani_only') && $request->has('is_qurbani_only')) {
            $template->is_qurbani_only = $request->boolean('is_qurbani_only');
        }
        if (Schema::hasColumn('t_wa_templates', 'is_regular_only') && $request->has('is_regular_only')) {
            $template->is_regular_only = $request->boolean('is_regular_only');
        }
        // Mutually exclusive flags: clearing one implicit when the other is set to 1.
        if ((bool) $template->is_qurbani_only && (bool) ($template->is_regular_only ?? false)) {
            // Prefer the one the user just toggled on. If only one of them was in
            // the request, trust it; otherwise keep qurbani_only and clear regular_only.
            if ($request->has('is_regular_only') && !$request->has('is_qurbani_only')) {
                $template->is_qurbani_only = false;
            } else {
                $template->is_regular_only = false;
            }
        }

        $template->save();
        return response()->json(['success' => true, 'template' => $template]);
    }

    public function deleteTemplate($id)
    {
        $template = \App\Models\WhatsApp\TemplateModel::findOrFail($id);
        $template->delete();
        return response()->json(['success' => true]);
    }

    private function clearDefaultsForContexts(string $showIn, ?int $excludeId = null)
    {
        $contexts = array_map('trim', explode(',', $showIn));
        $invoiceContexts = array_intersect($contexts, ['invoice', 'qurbani_invoice']);
        if (empty($invoiceContexts)) return;

        $query = \App\Models\WhatsApp\TemplateModel::where('is_default', true);
        if ($excludeId) $query->where('id', '!=', $excludeId);
        $others = $query->get();

        foreach ($others as $other) {
            $otherContexts = array_map('trim', explode(',', $other->show_in));
            if (!empty(array_intersect($otherContexts, $invoiceContexts))) {
                $other->is_default = false;
                $other->save();
            }
        }
    }

    /**
     * Get orders for a customer (for invoice picker)
     */
    public function getCustomerOrders(Request $request, $customerId)
    {
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
    }

    /**
     * Get invoice image URL.
     * If a cached image exists, returns it immediately.
     * Otherwise returns the invoice page URL so the client can render & capture it.
     */
    public function getInvoiceImageUrl(Request $request, $orderId)
    {
        try {
            $dir = 'whatsapp-invoices';
            $disk = 'public';

            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($orderId);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            $filename = 'Invoice-' . $orderNum;
            $storagePath = $dir . '/' . $filename . '.png';

            if (Storage::disk($disk)->exists($storagePath)) {
                $base = request()->getSchemeAndHttpHost();
                return response()->json([
                    'success' => true,
                    'image_url' => rtrim($base, '/') . '/public-storage/' . $storagePath,
                    'order_number' => $orderNum,
                ]);
            }

            return response()->json([
                'success' => true,
                'needs_capture' => true,
                'invoice_url' => url('/orders/' . $orderId . '/invoice'),
                'order_number' => $orderNum,
                'order_id' => $orderId,
            ]);

        } catch (\Exception $e) {
            Log::error('Invoice image generation failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Receive a base64 PNG from the browser after html2canvas capture.
     */
    public function uploadInvoiceImage(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'image_data' => 'required|string',
        ]);

        try {
            $dir = 'whatsapp-invoices';
            $disk = 'public';

            $order = app(\App\Http\Controllers\CRM\OrderController::class)->findOrderPublic($request->order_id);
            $orderNum = $order->order_number ?? ('NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            $filename = 'Invoice-' . $orderNum;
            $storagePath = $dir . '/' . $filename . '.png';

            $imageData = $request->image_data;
            if (str_contains($imageData, ',')) {
                $imageData = explode(',', $imageData, 2)[1];
            }
            $decoded = base64_decode($imageData);
            if (!$decoded) {
                return response()->json(['success' => false, 'message' => 'Invalid image data'], 422);
            }

            Storage::disk($disk)->put($storagePath, $decoded);

            $base = request()->getSchemeAndHttpHost();
            return response()->json([
                'success' => true,
                'image_url' => rtrim($base, '/') . '/public-storage/' . $storagePath,
                'order_number' => $orderNum,
            ]);

        } catch (\Exception $e) {
            Log::error('Invoice image upload failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send invoice via WhatsApp template with image header
     */
    public function sendInvoice(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer',
            'phone' => 'required|string',
            'template_name' => 'required|string',
            'body_params' => 'nullable|array',
            'conversation_id' => 'nullable|integer',
        ]);

        try {
            $service = app(WhatsAppService::class);
            $phone = $service->formatPhone($request->phone);

            $imgResponse = $this->getInvoiceImageUrl($request, $request->order_id);
            $imgData = json_decode($imgResponse->getContent(), true);

            if (!($imgData['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $imgData['message'] ?? 'Failed to generate invoice image'], 500);
            }

            if ($imgData['needs_capture'] ?? false) {
                return response()->json(['success' => false, 'message' => 'Please preview the invoice first to generate the image.'], 422);
            }

            $imageUrl = $imgData['image_url'];
            $orderNumber = $imgData['order_number'];

            $headerParams = [
                ['type' => 'image', 'image' => ['link' => $imageUrl]],
            ];
            $bodyParams = $request->body_params ?? [];

            $result = $service->sendTemplateMessage($phone, $request->template_name, 'en', $bodyParams, $headerParams);

            if (!($result['success'] ?? false)) {
                return response()->json(['success' => false, 'message' => $result['error'] ?? 'Failed to send'], 422);
            }

            $conversation = $request->conversation_id
                ? ConversationModel::find($request->conversation_id)
                : $service->findOrCreateConversation($phone);

            if ($conversation) {
                $paramText = implode(', ', $bodyParams);
                $service->saveOutboundMessage(
                    $conversation->id,
                    $result,
                    'template',
                    "Invoice #{$orderNumber}" . ($paramText ? " ({$paramText})" : ''),
                    auth()->id(),
                    $request->template_name,
                    $bodyParams
                );
            }

            return response()->json(['success' => true, 'order_number' => $orderNumber]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to send invoice', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
