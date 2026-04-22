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

        // Two search modes, driven by the UI toggle next to the input:
        //   - 'customers' (default, legacy behaviour): matches name / phone /
        //     WA contact name on the conversation + linked customer row.
        //   - 'chats':    full-text-ish LIKE over t_wa_messages.content, so the
        //                 user can find a conversation by something that was
        //                 actually said ("cuts", "refund", etc.). We pre-fetch
        //                 matching message rows so we can surface a highlighted
        //                 snippet back to the UI and only return conversations
        //                 that actually matched.
        //
        // For chat-mode we also honour the limited-access cutoff so a limited
        // user can never peek at old messages via a content search.
        $searchMode = $request->input('search_mode', 'customers');
        $matchByConvId = []; // conversation_id => [snippet, match_count]
        if ($search = $request->search) {
            if ($searchMode === 'chats') {
                $msgQuery = DB::table('t_wa_messages')
                    ->select('conversation_id', 'content', 'created_at', 'direction')
                    ->where('content', 'like', "%{$search}%")
                    ->orderByDesc('created_at');
                if ($access['limited']) {
                    $msgQuery->where('created_at', '>=', $access['cutoff']);
                }
                // Cap the scan so a pathological query (e.g. "a") can't
                // blow up memory. 600 rows is plenty to populate the 150
                // conversation list on the left with previews.
                $matches = $msgQuery->limit(600)->get();

                foreach ($matches as $m) {
                    if (!isset($matchByConvId[$m->conversation_id])) {
                        $matchByConvId[$m->conversation_id] = [
                            'snippet' => $this->buildChatSnippet((string) $m->content, (string) $search),
                            'snippet_at' => $m->created_at,
                            'snippet_direction' => $m->direction,
                            'count' => 1,
                        ];
                    } else {
                        $matchByConvId[$m->conversation_id]['count']++;
                    }
                }
                $matchedConvIds = array_keys($matchByConvId);
                if (empty($matchedConvIds)) {
                    // Short-circuit: no content match → empty list. Skip
                    // the expensive downstream queries entirely.
                    return response()->json([
                        'success' => true,
                        'conversations' => [],
                        'is_limited' => $access['limited'],
                        'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
                        'search_mode' => 'chats',
                    ]);
                }
                $query->whereIn('id', $matchedConvIds);
            } else {
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

                // Super-reader's global_read_at — inbound messages older
                // than this are read for everyone.
                if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                    $msgQuery->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                             ->where(function ($w) {
                                 $w->whereNull('c.global_read_at')
                                   ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                             });
                }

                // Limited users shouldn't see unread counts for messages they
                // aren't allowed to read. Mirror the visible-window filter.
                if ($access['limited']) {
                    $msgQuery->where('m.created_at', '>=', $access['cutoff']);
                }

                $rows = $msgQuery->groupBy('m.conversation_id')->get();
                foreach ($rows as $r) {
                    $unreadByConv[$r->conversation_id] = (int) $r->cnt;
                }

                // Post-process: any conversation the user has explicitly
                // Mark-Unread-ed gets at least 1 unread, even if there are
                // no eligible inbound messages left after the outbound
                // filter. Cleared automatically on the next markRead.
                if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                    $forcedIds = DB::table('t_wa_conversation_reads')
                        ->where('user_id', $userId)
                        ->whereIn('conversation_id', $convIds)
                        ->whereNotNull('forced_unread_at')
                        ->pluck('conversation_id');
                    foreach ($forcedIds as $fid) {
                        if (empty($unreadByConv[$fid])) {
                            $unreadByConv[$fid] = 1;
                        }
                    }
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

        // Labels: one batched query, keyed by conversation_id. Also counts
        // per-conversation unread @mentions for the current viewer (Phase 2).
        $labelsByConv = [];
        $mentionsByConv = [];
        $hasLabelsTable = Schema::hasTable('t_wa_labels') && Schema::hasTable('t_wa_conversation_labels');
        $hasSeenCol = $hasLabelsTable && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
        if ($hasLabelsTable && !empty($convIds)) {
            $cols = ['cl.conversation_id', 'l.id', 'l.name', 'l.color', 'l.user_id'];
            if ($hasSeenCol) $cols[] = 'cl.mention_seen_at';
            $labelRows = DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                ->whereIn('cl.conversation_id', $convIds)
                ->orderBy('l.name')
                ->get($cols);
            foreach ($labelRows as $lr) {
                $labelsByConv[$lr->conversation_id][] = [
                    'id'      => (int) $lr->id,
                    'name'    => $lr->name,
                    'color'   => $lr->color,
                    'user_id' => $lr->user_id ? (int) $lr->user_id : null,
                ];
                if ($hasSeenCol
                    && $lr->user_id
                    && (int) $lr->user_id === (int) $userId
                    && empty($lr->mention_seen_at)) {
                    $mentionsByConv[$lr->conversation_id] =
                        ($mentionsByConv[$lr->conversation_id] ?? 0) + 1;
                }
            }
        }

        $conversations = $convs->map(function ($conv) use ($unreadByConv, $lastMsgByConv, $hasQurbaniCol, $matchByConvId, $labelsByConv, $mentionsByConv) {
            $lastMsg = $lastMsgByConv[$conv->id] ?? null;
            $unread = $unreadByConv[$conv->id] ?? 0;

            // Chat-mode extras: when this conversation was pulled in by a
            // content search we ship back the matched snippet + how many
            // messages matched so the UI can render it inline.
            $match = $matchByConvId[$conv->id] ?? null;

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
                'match_snippet' => $match['snippet'] ?? null,
                'match_count' => $match['count'] ?? null,
                'match_direction' => $match['snippet_direction'] ?? null,
                'session_active' => $conv->isSessionActive(),
                'session_expires_at' => $conv->last_customer_message_at
                    ? $conv->last_customer_message_at->addHours(24)->toIso8601String()
                    : null,
                'labels' => $labelsByConv[$conv->id] ?? [],
                // Phase 2: unread @mentions targeted at the viewer.
                'mentions_count' => (int) ($mentionsByConv[$conv->id] ?? 0),
            ];
        });

        // "Unread" filter applied after compute so it uses per-user counts.
        if ($request->filter === 'unread') {
            $conversations = $conversations->filter(fn($c) => ($c['unread_count'] ?? 0) > 0)->values();
        }

        // Phase 2: "@me" filter — only conversations with unread mentions
        // targeted at the current user.
        if ($request->boolean('assigned_to_me')) {
            $conversations = $conversations->filter(fn($c) => ($c['mentions_count'] ?? 0) > 0)->values();
        }

        // Optional filter by label id — driven by the label filter pill
        // on the inbox. Applied after compute so it honours the "labels"
        // field we just built.
        if ($labelFilter = $request->input('label_id')) {
            $labelFilter = (int) $labelFilter;
            $conversations = $conversations->filter(function ($c) use ($labelFilter) {
                foreach ($c['labels'] ?? [] as $l) {
                    if ((int) $l['id'] === $labelFilter) return true;
                }
                return false;
            })->values();
        }

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
            'is_limited' => $access['limited'],
            'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
            'search_mode' => $searchMode,
        ]);
    }

    /**
     * Build a short, UI-friendly snippet around the first occurrence of the
     * search term inside a message body. Returned unescaped — the frontend
     * escapes + highlights the match span. Keeps ~40 chars of context on
     * either side so operators can recognise the conversation at a glance
     * without leaking the whole message into the sidebar.
     */
    private function buildChatSnippet(string $content, string $needle): string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));
        if ($needle === '' || $content === '') {
            return \Illuminate\Support\Str::limit($content, 90);
        }
        $pos = mb_stripos($content, $needle);
        if ($pos === false) {
            return \Illuminate\Support\Str::limit($content, 90);
        }
        $padding = 40;
        $start = max(0, $pos - $padding);
        $length = mb_strlen($needle) + ($padding * 2);
        $slice = mb_substr($content, $start, $length);
        $prefix = $start > 0 ? '…' : '';
        $suffix = ($start + $length) < mb_strlen($content) ? '…' : '';
        return $prefix . $slice . $suffix;
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
        $convLabels = $this->labelsForConversation($conversation->id);

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
                'labels' => $convLabels,
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
        $user = auth()->user();
        $now = now();

        // Per-user read state (new model). Always clear forced_unread_at on
        // markRead — opening the chat is the explicit signal that the user
        // no longer wants this conv forced to the top of the unread list.
        if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
            $payload = ['last_read_at' => $now];
            if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                $payload['forced_unread_at'] = null;
            }
            ConversationReadModel::updateOrCreate(
                ['user_id' => $userId, 'conversation_id' => $conversationId],
                $payload
            );
        }

        // Clear unread @mentions for this user on this conversation (Phase 2).
        if ($userId && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
            DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                ->where('cl.conversation_id', $conversationId)
                ->where('l.user_id', $userId)
                ->whereNull('cl.mention_seen_at')
                ->update(['cl.mention_seen_at' => $now]);
        }

        // Legacy column is still zeroed out so global badge sums stay sane.
        // (Push notifications and the mobile badge use this to avoid loading
        // the full per-user matrix.)
        $updates = ['unread_count' => 0];

        // Super-reader (Taimur role) — reading marks read for everyone.
        if (app(WhatsAppService::class)->isSuperReader($user) &&
            Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $updates['global_read_at'] = $now;
        }
        ConversationModel::where('id', $conversationId)->update($updates);

        // Push blue-ticks to the customer — now that a human actually saw it.
        try {
            app(WhatsAppService::class)->markInboundAsReadOnWhatsApp((int) $conversationId);
        } catch (\Exception $e) {
            Log::debug('markRead: WA receipt failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Mark a conversation UNREAD for this user — and for everyone if the
     * current user is a super-reader. Mirrors the mobile API's behaviour.
     */
    public function markUnread(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $userId = auth()->id();
        $user = auth()->user();

        // Upsert the user's read row with forced_unread_at = NOW(). We do
        // NOT modify last_read_at — the existing schema has it NOT NULL
        // and the forced_unread_at flag alone is sufficient to make the
        // unread calc report the conv as unread regardless of the
        // outbound-reply filter. Cleared automatically on next markRead.
        if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
            $hasForcedCol = Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at');
            if ($hasForcedCol) {
                $existing = ConversationReadModel::where('user_id', $userId)
                    ->where('conversation_id', $conversationId)
                    ->first();
                if ($existing) {
                    $existing->forced_unread_at = now();
                    $existing->save();
                } else {
                    // First time this user touches the conv — seed a row
                    // with epoch last_read_at so the NOT NULL constraint
                    // is satisfied.
                    ConversationReadModel::create([
                        'user_id'          => $userId,
                        'conversation_id'  => $conversationId,
                        'last_read_at'     => '1970-01-01 00:00:00',
                        'forced_unread_at' => now(),
                    ]);
                }
            } else {
                // Pre-migration fallback.
                ConversationReadModel::where('user_id', $userId)
                    ->where('conversation_id', $conversationId)
                    ->delete();
            }
        }

        // Keep the legacy counter at ≥ 1 so push-badge consumers still
        // show a dot on this conversation.
        $current = (int) (ConversationModel::where('id', $conversationId)->value('unread_count') ?? 0);
        $updates = ['unread_count' => max(1, $current)];
        if (app(WhatsAppService::class)->isSuperReader($user) &&
            Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $updates['global_read_at'] = null;
        }
        ConversationModel::where('id', $conversationId)->update($updates);

        return response()->json(['success' => true]);
    }

    // =========================================================================
    // LABELS (Phase 1 + Phase 2 mention helpers)
    // =========================================================================

    /**
     * Phase 2 helper: users eligible to be @mentioned (anyone with WA view
     * permission). Gated by manage_whatsapp_labels since only admins pick
     * users from this list when creating a user-mention label.
     */
    public function getLabelUsers(Request $request)
    {
        $me = auth()->user();
        if (!$me || !method_exists($me, 'hasMobilePermission') ||
            !$me->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $rows = DB::table('t_sys_user as u')
            ->join('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
            ->join('t_sys_role_mobile_permission as rmp', 'rmp.role_id', '=', 'ur.role_id')
            ->join('t_sys_mobile_permission as mp', 'mp.id', '=', 'rmp.mobile_permission_id')
            ->whereIn('mp.permission_code', ['view_whatsapp_messages', 'view_whatsapp_messages_limited'])
            ->where('u.is_active', 1)
            ->distinct()
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname', 'u.email'])
            ->map(fn ($r) => [
                'id'       => (int) $r->id,
                'fullname' => $r->fullname,
                'email'    => $r->email,
            ]);
        return response()->json(['success' => true, 'users' => $rows]);
    }

    /**
     * Phase 2 — total unread @mentions for the current user.
     */
    public function getMentionsCount(Request $request)
    {
        $me = auth()->user();
        if (!$me) return response()->json(['success' => true, 'mentions_count' => 0]);
        if (!Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
            return response()->json(['success' => true, 'mentions_count' => 0]);
        }
        $count = DB::table('t_wa_conversation_labels as cl')
            ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
            ->where('l.user_id', $me->id)
            ->whereNull('cl.mention_seen_at')
            ->count();
        return response()->json(['success' => true, 'mentions_count' => (int) $count]);
    }

    public function getLabels(Request $request)
    {
        if (!Schema::hasTable('t_wa_labels')) {
            return response()->json(['success' => true, 'labels' => [], 'can_manage' => false]);
        }
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $labels = \App\Models\WhatsApp\LabelModel::orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('name')->get()
            ->map(fn($l) => [
                'id' => $l->id, 'name' => $l->name, 'color' => $l->color,
                'user_id' => $l->user_id, 'is_system' => (bool) $l->is_system,
            ]);

        $canManage = auth()->user() && method_exists(auth()->user(), 'hasMobilePermission')
            ? auth()->user()->hasMobilePermission('manage_whatsapp_labels')
            : false;

        return response()->json(['success' => true, 'labels' => $labels, 'can_manage' => $canManage]);
    }

    public function createLabel(Request $request)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $data = $request->validate([
            'name'    => 'required|string|max:60',
            'color'   => 'nullable|string|max:20',
            'user_id' => 'nullable|integer|exists:t_sys_user,id',
        ]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'Name required'], 422);
        }
        $exists = \App\Models\WhatsApp\LabelModel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        if ($exists) {
            return response()->json(['success' => false, 'message' => 'A label with that name already exists.'], 422);
        }
        $label = \App\Models\WhatsApp\LabelModel::create([
            'name' => $name, 'color' => $data['color'] ?? '#6B7280',
            'user_id' => $data['user_id'] ?? null, 'is_system' => 0,
            'created_by' => auth()->id(),
        ]);
        return response()->json(['success' => true, 'label' => $label]);
    }

    public function updateLabel(Request $request, $id)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $label = \App\Models\WhatsApp\LabelModel::find($id);
        if (!$label) return response()->json(['success' => false, 'message' => 'Label not found'], 404);

        $data = $request->validate([
            'name'    => 'sometimes|required|string|max:60',
            'color'   => 'sometimes|nullable|string|max:20',
            'user_id' => 'sometimes|nullable|integer|exists:t_sys_user,id',
        ]);
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if ($name === '') return response()->json(['success' => false, 'message' => 'Name required'], 422);
            $clash = \App\Models\WhatsApp\LabelModel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->where('id', '!=', $id)->exists();
            if ($clash) return response()->json(['success' => false, 'message' => 'Another label already has this name.'], 422);
            $label->name = $name;
        }
        if (array_key_exists('color', $data))   $label->color = $data['color'] ?: '#6B7280';
        if (array_key_exists('user_id', $data)) $label->user_id = $data['user_id'];
        $label->save();
        return response()->json(['success' => true, 'label' => $label]);
    }

    public function deleteLabel(Request $request, $id)
    {
        if (!auth()->user() || !method_exists(auth()->user(), 'hasMobilePermission') ||
            !auth()->user()->hasMobilePermission('manage_whatsapp_labels')) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $label = \App\Models\WhatsApp\LabelModel::find($id);
        if (!$label) return response()->json(['success' => false, 'message' => 'Label not found'], 404);
        $label->delete();
        return response()->json(['success' => true]);
    }

    public function applyLabel(Request $request, $conversationId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        $data = $request->validate(['label_id' => 'required|integer|exists:t_wa_labels,id']);
        $conv = ConversationModel::find($conversationId);
        if (!$conv) {
            return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        // Was this label already applied? We push-notify only on first apply
        // so re-toggling doesn't spam the mentioned user.
        $existing = \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
            ->where('label_id', $data['label_id'])
            ->first();
        $isNewApply = ($existing === null);

        $hasSeenCol = Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
        $payload = ['applied_by' => auth()->id(), 'applied_at' => now()];
        if ($hasSeenCol) $payload['mention_seen_at'] = null;

        \App\Models\WhatsApp\ConversationLabelModel::updateOrCreate(
            ['conversation_id' => $conversationId, 'label_id' => $data['label_id']],
            $payload
        );

        // Fire FCM mention push (Phase 2) — only on first apply and not a
        // self-mention. Non-fatal on any failure.
        try {
            $label = \App\Models\WhatsApp\LabelModel::find($data['label_id']);
            $me = auth()->user();
            if ($isNewApply && $label && $label->user_id && (int) $label->user_id !== (int) ($me->id ?? 0)) {
                app(\App\Services\FirebaseService::class)->notifyWhatsAppMention(
                    (int) $label->user_id,
                    $me->fullname ?? ('User #' . ($me->id ?? 0)),
                    $conv->display_name ?: ($conv->wa_contact_name ?: $conv->wa_phone),
                    (int) $conversationId
                );
            }
        } catch (\Exception $e) {
            Log::debug('applyLabel: mention push failed (non-fatal)', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
    }

    public function removeLabel(Request $request, $conversationId, $labelId)
    {
        $access = $this->resolveWhatsAppAccess();
        if (!$access['allowed']) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }
        \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
            ->where('label_id', $labelId)->delete();
        return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
    }

    /**
     * Fetch labels currently applied to a conversation in the same shape
     * used elsewhere in this controller's responses.
     */
    protected function labelsForConversation($conversationId): array
    {
        if (!Schema::hasTable('t_wa_labels') || !Schema::hasTable('t_wa_conversation_labels')) {
            return [];
        }
        return DB::table('t_wa_conversation_labels as cl')
            ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
            ->where('cl.conversation_id', $conversationId)
            ->orderBy('l.name')
            ->get(['l.id', 'l.name', 'l.color', 'l.user_id'])
            ->map(fn($r) => [
                'id'      => (int) $r->id,
                'name'    => $r->name,
                'color'   => $r->color,
                'user_id' => $r->user_id ? (int) $r->user_id : null,
            ])->all();
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

        // Super-reader global read marker — honoured so Taimur's reads zero
        // the badge for all users. Only active once the migration has run.
        if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $query->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                  ->where(function ($w) {
                      $w->whereNull('c.global_read_at')
                        ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                  });
        }

        // Limited users' badge only counts messages inside their visible window.
        if ($access['limited']) {
            $query->where('m.created_at', '>=', $access['cutoff']);
        }

        $count = (int) $query->count();

        // Ensure forced-unread conversations contribute to the badge even
        // when they have no eligible inbound messages left.
        if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
            $forcedCount = DB::table('t_wa_conversation_reads')
                ->where('user_id', $userId)
                ->whereNotNull('forced_unread_at')
                ->count();
            if ($forcedCount > 0) {
                $count = max($count, $forcedCount);
            }
        }

        return response()->json(['success' => true, 'unread_count' => $count]);
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
