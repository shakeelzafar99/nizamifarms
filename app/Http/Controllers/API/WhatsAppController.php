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

    /**
     * Apr-2026: DB-wide unread-conversation-id resolver — mirrors
     * Web\WhatsAppWebController::unreadConversationIdsForCurrentUser
     * byte-for-byte so mobile and web report the same unread set. See
     * the web doc-comment for the full rationale; in short, this is the
     * canonical "what is currently unread for this user" query, used by
     * the unread-filter prefetch and by markAllRead.
     */
    protected function unreadConversationIdsForUser($user, array $access): array
    {
        $userId = $user ? $user->id : null;
        if (!$userId || !Schema::hasTable('t_wa_conversation_reads')) {
            return [];
        }

        $q = DB::table('t_wa_messages as m')
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

        if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
            $q->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
              ->where(function ($w) {
                  $w->whereNull('c.global_read_at')
                    ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
              });
        }

        if ($access['limited']) {
            $q->where('m.created_at', '>=', $access['cutoff']);
        }

        $organicIds = $q->distinct()->pluck('m.conversation_id')->all();

        $forcedIds = [];
        if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
            $forcedIds = DB::table('t_wa_conversation_reads')
                ->where('user_id', $userId)
                ->whereNotNull('forced_unread_at')
                ->pluck('conversation_id')
                ->all();
        }

        return array_values(array_unique(array_merge(
            array_map('intval', $organicIds),
            array_map('intval', $forcedIds)
        )));
    }

    // =========================================================================
    // CONVERSATIONS
    // =========================================================================

    /**
     * Build a UI-friendly snippet around the first occurrence of the
     * search term inside a message body. Returned raw — the mobile UI
     * escapes and highlights the match. Keeps ~40 chars of context on
     * either side so operators can recognise the conversation quickly.
     * Matches the web's buildChatSnippet() behaviour for parity.
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

            // Unread tab — Apr-2026: prefilter to a DB-wide list of this
            // user's unread conversation ids so the Unread tab spans the
            // whole DB (was: a client-side filter on the loaded 200 only).
            // Mirrors the web controller. See its comment for full rationale.
            $unreadOnly = ($filter === 'unread');
            $unreadIds = [];
            if ($unreadOnly) {
                $unreadIds = $this->unreadConversationIdsForUser($user, $access);
                if (empty($unreadIds)) {
                    return response()->json([
                        'success' => true,
                        'conversations' => [],
                        'is_limited' => $access['limited'],
                        'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
                        'has_more' => false,
                        'next_cursor' => null,
                        'total_unread' => 0,
                    ]);
                }
                $query->whereIn('id', $unreadIds);
            }

            // Two search modes, parity with the web:
            //   - 'customers' (default): matches conversation + linked
            //     customer name / phone / contact name.
            //   - 'chats': LIKE over t_wa_messages.content so operators
            //     can find a conversation by something that was actually
            //     said. We pre-fetch matching rows so the mobile UI can
            //     show a highlighted snippet underneath each match.
            $searchMode = $request->get('search_mode', 'customers');
            $matchByConvId = []; // conv_id => [snippet, count, direction]
            if ($search) {
                if ($searchMode === 'chats') {
                    $msgQuery = DB::table('t_wa_messages')
                        ->select('conversation_id', 'content', 'created_at', 'direction')
                        ->where('content', 'LIKE', "%{$search}%")
                        ->orderByDesc('created_at');
                    if ($access['limited']) {
                        $msgQuery->where('created_at', '>=', $access['cutoff']);
                    }
                    // Cap the scan to keep memory sane on ultra-short
                    // queries like "a".
                    $matches = $msgQuery->limit(600)->get();

                    foreach ($matches as $m) {
                        if (!isset($matchByConvId[$m->conversation_id])) {
                            $matchByConvId[$m->conversation_id] = [
                                'snippet' => $this->buildChatSnippet((string) $m->content, (string) $search),
                                'direction' => $m->direction,
                                'count' => 1,
                            ];
                        } else {
                            $matchByConvId[$m->conversation_id]['count']++;
                        }
                    }

                    $matchedConvIds = array_keys($matchByConvId);
                    if (empty($matchedConvIds)) {
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
            }

            // Apr-2026: cursor pagination. First page (no cursor) returns
            // up to 200 most-recent; subsequent pages pass `before_last_message_at`
            // and request 100 older rows. Hard ceiling 300 to keep the
            // batched downstream queries predictable. Page numbers are
            // deliberately avoided because tail conversations bubble up
            // when they get new activity.
            $isPagedFetch = (bool) $request->input('before_last_message_at');
            $perPage = (int) ($request->input('limit') ?: ($isPagedFetch ? 100 : 200));
            $perPage = max(1, min($perPage, 300));

            // For Unread tab we already restricted candidates to actual
            // unread ids — return them all in one shot (cap 500) and skip
            // cursor pagination. Operator needs to see every unread row.
            if ($unreadOnly) {
                $perPage = min(count($unreadIds), 500);
                $isPagedFetch = false;
            }

            if ($isPagedFetch) {
                try {
                    $cursor = \Carbon\Carbon::parse($request->input('before_last_message_at'));
                    // <= rather than strict <. The boundary conversation
                    // can slide out of the live head between cursor capture
                    // and Load More click — strict < would lose it. The
                    // frontend dedupes by id, so the one duplicate row at
                    // the boundary is invisible to the user. See web
                    // controller for the full rationale.
                    $query->where('last_message_at', '<=', $cursor);
                } catch (\Exception $e) {
                    $isPagedFetch = false;
                }
            }

            $conversations = $query->orderByDesc('last_message_at')
                ->limit($perPage)
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
            // Rules (all must hold for an inbound message to be unread for user X):
            //   1. direction='inbound' — only customer messages count.
            //   2. created_at > my own last_read_at (or I have no read row).
            //   3. created_at > conversation.global_read_at — set when a
            //      super-reader (Taimur) marks the thread read. Makes the
            //      conversation appear read for EVERYONE from that moment,
            //      without touching each user's personal read row.
            //   4. No staff has replied AFTER this inbound message. Any
            //      outbound reply "handles" prior inbounds for all staff.
            $hasGlobalReadCol = Schema::hasColumn('t_wa_conversations', 'global_read_at');
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

                // Super-reader global read marker — only apply if the column
                // exists (idempotent across pre/post migration installs).
                if ($hasGlobalReadCol) {
                    $unreadQ->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                            ->where(function ($w) {
                                $w->whereNull('c.global_read_at')
                                  ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                            });
                }

                // Limited users shouldn't see unread counts for messages they
                // aren't allowed to read.
                if ($access['limited']) {
                    $unreadQ->where('m.created_at', '>=', $access['cutoff']);
                }

                $rows = $unreadQ->groupBy('m.conversation_id')->get();
                foreach ($rows as $r) {
                    $unreadByConv[$r->conversation_id] = (int) $r->cnt;
                }

                // Post-process: any conversation the user has explicitly
                // "Mark as Unread"-ed should show at least 1 unread, even
                // when there are no eligible inbound messages left (e.g.
                // staff already replied to everything). We use a simple
                // indexed lookup on the user's own read rows.
                if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                    $forcedIds = DB::table('t_wa_conversation_reads')
                        ->where('user_id', $userId)
                        ->whereIn('conversation_id', $conversationIds)
                        ->whereNotNull('forced_unread_at')
                        ->pluck('conversation_id');
                    foreach ($forcedIds as $fid) {
                        if (empty($unreadByConv[$fid])) {
                            $unreadByConv[$fid] = 1;
                        }
                    }
                }
            } else {
                foreach ($conversations as $c) {
                    $unreadByConv[$c->id] = (int) $c->unread_count;
                }
            }

            // Batch-fetch labels applied to each visible conversation so we
            // can render chips on inbox rows without an N+1 query. One join,
            // keyed by conversation_id. Also track per-conversation unread
            // mention count for the CURRENT user (Phase 2) so mobile can
            // show a small "@" ping on inbox rows without another round-trip.
            $labelsByConv = [];
            $mentionsByConv = [];
            $hasLabelsTable = Schema::hasTable('t_wa_labels') && Schema::hasTable('t_wa_conversation_labels');
            $hasSeenCol = $hasLabelsTable && Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
            if ($hasLabelsTable && !empty($conversationIds)) {
                $cols = ['cl.conversation_id', 'l.id', 'l.name', 'l.color', 'l.user_id'];
                if ($hasSeenCol) $cols[] = 'cl.mention_seen_at';

                $labelRows = DB::table('t_wa_conversation_labels as cl')
                    ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                    ->whereIn('cl.conversation_id', $conversationIds)
                    ->orderBy('l.name')
                    ->get($cols);
                foreach ($labelRows as $lr) {
                    $labelsByConv[$lr->conversation_id][] = [
                        'id'      => (int) $lr->id,
                        'name'    => $lr->name,
                        'color'   => $lr->color,
                        'user_id' => $lr->user_id ? (int) $lr->user_id : null,
                    ];
                    // Count unread mentions targeted at the CURRENT viewer.
                    if ($hasSeenCol
                        && $lr->user_id
                        && (int) $lr->user_id === (int) $userId
                        && empty($lr->mention_seen_at)) {
                        $mentionsByConv[$lr->conversation_id] =
                            ($mentionsByConv[$lr->conversation_id] ?? 0) + 1;
                    }
                }
            }

            // Failed-send indicator: per conversation, flag the row if its
            // most recent outbound message (last 7 days) has status='failed'.
            // Mirrors the web inbox surfaces — operator can spot failures
            // without opening every chat. Clears automatically once the
            // next outbound for that conversation lands successfully.
            $failedByConv = [];
            if (!empty($conversationIds)) {
                $cutoff = now()->subDays(7);
                $latestOutboundIds = DB::table('t_wa_messages')
                    ->selectRaw('MAX(id) as id, conversation_id')
                    ->whereIn('conversation_id', $conversationIds)
                    ->where('direction', 'outbound')
                    ->where('created_at', '>=', $cutoff)
                    ->groupBy('conversation_id')
                    ->pluck('id');
                if ($latestOutboundIds->isNotEmpty()) {
                    $failedRows = DB::table('t_wa_messages')
                        ->whereIn('id', $latestOutboundIds)
                        ->where('status', 'failed')
                        ->get(['conversation_id', 'error_message', 'template_name']);
                    foreach ($failedRows as $fr) {
                        $failedByConv[$fr->conversation_id] = [
                            'error_message' => $fr->error_message ?: 'Send failed',
                            'template_name' => $fr->template_name,
                        ];
                    }
                }
            }

            $result = $conversations->map(function ($conv) use ($lastMessages, $unreadByConv, $hasQurbaniCol, $matchByConvId, $labelsByConv, $mentionsByConv, $failedByConv) {
                $lastMsg = $lastMessages->get($conv->id);
                $failed = $failedByConv[$conv->id] ?? null;
                // Chat-mode extras: ship the matched snippet + count
                // so the mobile UI can render a highlighted preview.
                $match = $matchByConvId[$conv->id] ?? null;
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
                    'match_snippet' => $match['snippet'] ?? null,
                    'match_count' => $match['count'] ?? null,
                    'match_direction' => $match['direction'] ?? null,
                    'assigned_to' => $conv->assigned_to,
                    'labels' => $labelsByConv[$conv->id] ?? [],
                    // Phase 2: unread @mentions targeted at the viewer.
                    'mentions_count' => (int) ($mentionsByConv[$conv->id] ?? 0),
                    // Apr-2026: failed-send indicator on inbox rows.
                    'last_send_failed' => $failed !== null,
                    'last_send_error'  => $failed['error_message'] ?? null,
                    'last_send_template' => $failed['template_name'] ?? null,
                ];
            });

            if ($filter === 'unread') {
                $result = $result->filter(fn($c) => ($c['unread_count'] ?? 0) > 0)->values();
            }

            // Phase 2 filter: "@me" — conversations where the current user
            // has at least one unread mention. Cheap because mentions_count
            // is already computed above.
            if ($request->boolean('assigned_to_me')) {
                $result = $result->filter(fn($c) => ($c['mentions_count'] ?? 0) > 0)->values();
            }

            // Optional filter by label_id — keeps the inbox focused when the
            // user taps a label chip or picks one from the filter dropdown.
            if ($labelFilter = $request->input('label_id')) {
                $labelFilter = (int) $labelFilter;
                $result = $result->filter(function ($c) use ($labelFilter) {
                    foreach ($c['labels'] ?? [] as $l) {
                        if ((int) $l['id'] === $labelFilter) return true;
                    }
                    return false;
                })->values();
            }

            // Pagination cursor for "Load more chats". Mirrors the web
            // contract — frontend only needs `next_cursor` to chain pages,
            // and `has_more` to decide whether to render the button. We
            // compute these from the raw conversation batch (before client-
            // side filters like Unread/@me) so older rows aren't hidden
            // just because none of the loaded ones happened to match the
            // current filter.
            $nextCursor = null;
            $hasMore    = $conversations->count() >= $perPage;
            if ($hasMore && !$conversations->isEmpty()) {
                $oldest = $conversations->last();
                $nextCursor = $oldest->last_message_at
                    ? \Carbon\Carbon::parse($oldest->last_message_at)->toIso8601String()
                    : null;
            }

            // Unread mode is single-shot — close out has_more and surface
            // the DB-wide unread total for any UI that wants to display it
            // (e.g. mobile "X unread" header, future bulk-read confirm).
            if ($unreadOnly) {
                $hasMore = false;
                $nextCursor = null;
            }

            $resp = [
                'success' => true,
                'conversations' => $result,
                'is_limited' => $access['limited'],
                'cutoff_at' => $access['limited'] ? $access['cutoff']->toIso8601String() : null,
                'search_mode' => $searchMode,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
                'is_paged_fetch' => $isPagedFetch,
            ];
            if ($unreadOnly) {
                $resp['total_unread'] = count($unreadIds);
            }
            return response()->json($resp);

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
                    'sender_name' => $msg->sender ? ($msg->sender->fullname ?? $msg->sender->name) : null,
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
            $convLabels = $this->labelsForConversation($conversation->id);

            // Defensive implicit mark-as-read on chat-open / poll. The
            // explicit POST /mark-read runs the same write but the mobile
            // can race it (e.g. user backs out before the await resolves)
            // — leaving a previously "Marked Unread" conv stuck unread.
            // Doing it here too guarantees the per-user read row is up to
            // date by the time we ship the messages payload back. We
            // skip the WhatsApp blue-tick receipt API on purpose; that
            // side-effect stays on the explicit POST so we don't spam it
            // on every poll tick.
            if ($user && !$before && Schema::hasTable('t_wa_conversation_reads')) {
                try {
                    $payload = ['last_read_at' => now()];
                    if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                        $payload['forced_unread_at'] = null;
                    }
                    ConversationReadModel::updateOrCreate(
                        ['user_id' => $user->id, 'conversation_id' => $conversation->id],
                        $payload
                    );
                    ConversationModel::where('id', $conversation->id)->update(['unread_count' => 0]);
                } catch (\Exception $e) {
                    Log::debug('getMessages: implicit markRead skipped', ['error' => $e->getMessage()]);
                }
            }

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
                    'labels' => $convLabels,
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
     * Send a voice note (audio message) in a conversation.
     *
     * Accepts a multipart upload from the mobile app with field name
     * `audio`, saves it alongside inbound media under
     * `storage/app/public/whatsapp-media/YYYY/MM/outbound-<uuid>.<ext>`,
     * uploads the bytes to Meta's /media endpoint to get a media_id,
     * sends a WhatsApp `type=audio` message, and persists the outbound
     * row so the message appears in the thread with the same playback
     * flow inbound audio already uses (via /public-storage/{path}).
     *
     * Safety rails:
     *   - `send_whatsapp_messages` permission required (same as text send).
     *   - 24-hour session window enforced (Meta rejects free-form audio
     *     outside the window anyway; we short-circuit here to give a
     *     friendlier error with a template prompt).
     *   - 5 minute / 8 MB mobile-side cap is mirrored server-side to
     *     protect us from a rogue/very-large upload on shared hosting.
     *   - Only audio MIME types accepted.
     *   - Nothing is persisted or sent if any step fails; the local
     *     file is removed on failure so we don't leak orphan media.
     */
    public function sendVoiceNote(Request $request, $conversationId)
    {
        $savedPath = null;
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('send_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                // 'audio' is the uploaded file. Mime rule intentionally lax:
                // Android's MediaRecorder can tag the same AAC stream as
                // audio/mp4, audio/aac, audio/3gpp, or application/octet-stream
                // depending on the device. We filter on the *content* below
                // via getMimeType(), so we just need any file here.
                'audio' => 'required|file|max:8192', // 8 MB cap (Laravel validates in KB)
                'duration_ms' => 'nullable|integer|min:0|max:360000', // ≤ 6 min, informational only
            ]);

            $conversation = ConversationModel::find($conversationId);
            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            // Same 24-hour gate as text sends. Meta will reject outside
            // this window; we return early with a session_expired flag
            // so the mobile app can offer the template picker instead.
            if (!$this->whatsapp->isSessionActive($conversation)) {
                return response()->json([
                    'success' => false,
                    'message' => '24-hour window expired. Use a template message to re-initiate.',
                    'session_expired' => true,
                ], 422);
            }

            $file = $request->file('audio');
            if (!$file || !$file->isValid()) {
                return response()->json(['success' => false, 'message' => 'Invalid audio upload'], 422);
            }

            // Actual MIME from the uploaded bytes (not the client-provided
            // label). We only accept recognised audio containers — anything
            // else (image/exe/etc) gets rejected before we touch Meta.
            $detected = strtolower((string) $file->getMimeType());
            $allowed  = [
                'audio/mp4', 'audio/aac', 'audio/x-m4a', 'audio/m4a',
                'audio/mpeg', 'audio/mp3',
                'audio/ogg', 'audio/opus',
                'audio/3gpp', 'audio/amr',
                'video/mp4', // Android often reports .m4a as video/mp4; still audio-only
            ];
            if (!in_array($detected, $allowed, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unsupported audio format: ' . ($detected ?: 'unknown'),
                ], 422);
            }

            // Pick a sensible Meta-facing MIME. WhatsApp accepts audio/mp4
            // and audio/aac for M4A payloads; we normalise video/mp4 →
            // audio/mp4 so Meta's type-check is happy.
            $metaMime = $detected === 'video/mp4' ? 'audio/mp4' : $detected;

            // Reuse the inbound media folder — /public-storage/{path} already
            // streams files out of this location without needing a symlink
            // (shared-hosting safe, same path attendance photos use).
            $extension  = $file->getClientOriginalExtension() ?: ($file->extension() ?: 'm4a');
            $filename   = 'outbound-' . \Illuminate\Support\Str::uuid()->toString() . '.' . strtolower($extension);
            $relative   = config('whatsapp.media_path') . '/' . date('Y/m') . '/' . $filename;
            $savedPath  = $file->storeAs(
                dirname($relative),
                basename($relative),
                config('whatsapp.media_disk')
            );
            if (!$savedPath) {
                return response()->json(['success' => false, 'message' => 'Failed to store audio file'], 500);
            }

            // Absolute path on disk so WhatsAppService can read the bytes.
            $absolutePath = Storage::disk(config('whatsapp.media_disk'))->path($savedPath);

            // Step 1 — upload to Meta, get back a media_id.
            $mediaId = $this->whatsapp->uploadMediaToWhatsApp($absolutePath, $metaMime);
            if (!$mediaId) {
                // Don't leak the orphan file on failure.
                Storage::disk(config('whatsapp.media_disk'))->delete($savedPath);
                $savedPath = null;
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to upload audio to WhatsApp. Please try again.',
                ], 502);
            }

            // Step 2 — send the WhatsApp message referencing the media_id.
            $response = $this->whatsapp->sendAudio($conversation->wa_phone, $mediaId);
            if (!($response['success'] ?? false)) {
                Storage::disk(config('whatsapp.media_disk'))->delete($savedPath);
                $savedPath = null;
                return response()->json([
                    'success' => false,
                    'message' => $response['error'] ?? 'WhatsApp rejected the audio message',
                ], 500);
            }

            // Step 3 — persist the outbound message so our UI can replay it.
            // We go through MessageModel directly (not saveOutboundMessage)
            // because that helper doesn't know about media_url. Mirrors the
            // inbound audio row shape exactly so rendering code can stay
            // direction-agnostic.
            $waMessageId = $response['messages'][0]['id'] ?? null;
            $message = MessageModel::create([
                'conversation_id' => $conversation->id,
                'wa_message_id'   => $waMessageId,
                'direction'       => 'outbound',
                'type'            => 'audio',
                'content'         => '[Voice Note]',
                'media_url'       => $savedPath,
                'media_mime_type' => $metaMime,
                'status'          => 'sent',
                'sent_by'         => $user->id,
                'created_at'      => now(),
            ]);

            ConversationModel::where('id', $conversation->id)->update(['last_message_at' => now()]);

            return response()->json([
                'success' => true,
                'message_id'    => $message->id,
                'wa_message_id' => $waMessageId,
                'media_url'     => $message->media_public_url,
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            // Laravel's validator already returns a 422 with a readable
            // error — pass it through unchanged.
            throw $ve;
        } catch (\Exception $e) {
            // Best-effort cleanup of any partially-saved file so we don't
            // leave orphans after a server error.
            if ($savedPath) {
                try { Storage::disk(config('whatsapp.media_disk'))->delete($savedPath); } catch (\Exception $ignored) {}
            }
            Log::error('WhatsApp: sendVoiceNote failed', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);
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
                'header_params' => 'nullable|array',
                'customer_id' => 'nullable|integer',
                'conversation_id' => 'nullable|integer',
                'force' => 'nullable|boolean',
            ]);

            $phone = $this->whatsapp->formatPhone($request->input('phone'));
            $templateName = $request->input('template_name');
            $bodyParams = $request->input('body_params', []);
            $headerParams = $request->input('header_params', []);
            $force = filter_var($request->input('force', false), FILTER_VALIDATE_BOOLEAN);

            // Marketing-dedup guard. Returns null if OK to send, or a payload
            // describing the recent prior send (relayed as 409 Conflict so
            // the mobile chat UI can show the Alert.alert confirm).
            $dedup = $this->whatsapp->enforceMarketingDedup(
                $request->input('conversation_id'),
                $phone,
                $templateName,
                $user,
                $force
            );
            if ($dedup) {
                return response()->json(['success' => false] + $dedup, 409);
            }

            $response = $this->whatsapp->sendTemplateMessage($phone, $templateName, 'en', $bodyParams, $headerParams);

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
                $bodyParams,
                $force && $this->whatsapp->canOverrideMarketingDedup($user)
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
     * Pre-flight check for the mobile chat-window template picker. Returns
     * the same payload shape as the 409 blocked response from sendTemplate.
     * Always returns HTTP 200 so the mobile client doesn't have to parse
     * status codes — `recently_sent=false` means OK, true means show the
     * Alert.alert confirm.
     */
    public function templateRecentSendCheck(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'conversation_id' => 'nullable|integer',
                'phone'           => 'nullable|string',
                'template_name'   => 'required|string',
            ]);

            $filter = app(\App\Services\CampaignFilterService::class);
            $window = $this->whatsapp->getMarketingDedupWindowDays();
            $isMarketing = $filter->isMarketingTemplate($request->input('template_name'));

            $base = [
                'success'         => true,
                'is_marketing'    => $isMarketing,
                'window_days'     => $window,
                'recently_sent'   => false,
                'feature_enabled' => $window > 0,
            ];

            if ($window <= 0 || !$isMarketing) {
                return response()->json($base);
            }

            $conversationId = (int) $request->input('conversation_id', 0);
            if (!$conversationId && $request->filled('phone')) {
                $phone = $this->whatsapp->formatPhone($request->input('phone'));
                $conv = ConversationModel::where('wa_phone', $phone)->first();
                if ($conv) $conversationId = (int) $conv->id;
            }
            if (!$conversationId) {
                return response()->json($base);
            }

            $hit = $filter->recentMarketingTemplateSendForConversation(
                $conversationId,
                $request->input('template_name'),
                $window
            );
            if (!$hit) {
                return response()->json($base);
            }

            $canOverride = $this->whatsapp->canOverrideMarketingDedup($user);

            return response()->json($base + [
                'recently_sent'         => true,
                'reason'                => 'recent_marketing_send',
                'template_name'         => $hit['template_name'],
                'template_display_name' => $hit['template_display_name'],
                'sent_at'               => $hit['sent_at'],
                'sent_at_human'         => $hit['sent_at_human'],
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
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp: templateRecentSendCheck failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mobile mirror of WhatsAppWebController::recentMarketingTemplates —
     * powers the pinned strip in the mobile chat header.
     */
    public function recentMarketingTemplates(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('view_whatsapp_messages')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $window = $this->whatsapp->getMarketingDedupWindowDays();
            if ($window <= 0) {
                return response()->json([
                    'success'         => true,
                    'feature_enabled' => false,
                    'window_days'     => 0,
                    'templates'       => [],
                ]);
            }

            $templates = app(\App\Services\CampaignFilterService::class)
                ->recentMarketingTemplatesForConversation((int) $conversationId, $window);

            return response()->json([
                'success'         => true,
                'feature_enabled' => true,
                'window_days'     => $window,
                'templates'       => $templates,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp: recentMarketingTemplates failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a conversation as read for this user and send blue-tick receipts
     * to the customer. If this user is a super-reader (Taimur), the read
     * also applies globally — any inbound message older than "now" becomes
     * read for every staff member via t_wa_conversations.global_read_at.
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
                // Always clear forced_unread_at on markRead — opening the
                // chat is the explicit signal that the user no longer wants
                // this conv forced to the top of the unread list.
                $readPayload = ['last_read_at' => $now];
                if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                    $readPayload['forced_unread_at'] = null;
                }
                ConversationReadModel::updateOrCreate(
                    ['user_id' => $user->id, 'conversation_id' => $conversationId],
                    $readPayload
                );
            }

            // Clear any unread @mentions for this user on this conversation
            // (user-mention labels where mention_seen_at IS NULL). We do it
            // with a direct join to avoid fetching label IDs client-side.
            if (Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
                DB::table('t_wa_conversation_labels as cl')
                    ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                    ->where('cl.conversation_id', $conversationId)
                    ->where('l.user_id', $user->id)
                    ->whereNull('cl.mention_seen_at')
                    ->update(['cl.mention_seen_at' => $now]);
            }

            // Super-reader: also stamp conversation-wide so other staff see
            // the thread as read without touching their individual rows.
            $updates = ['unread_count' => 0];
            if ($this->whatsapp->isSuperReader($user) &&
                Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                $updates['global_read_at'] = $now;
            }
            ConversationModel::where('id', $conversationId)->update($updates);

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
     * Bulk Mark-All-Read — super-reader only. Mirrors the web endpoint
     * Web\WhatsAppWebController::markAllRead. See its doc-comment for
     * the full behaviour, permission rules and rationale (no blue ticks
     * on bulk, idempotent, single transaction).
     */
    public function markAllRead(Request $request)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            if (!$this->whatsapp->isSuperReader($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mark-all-read is restricted to super-readers.',
                ], 403);
            }

            if (!Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                return response()->json([
                    'success' => false,
                    'message' => 'global_read_at column missing — run the labels/super-reader migration first.',
                ], 500);
            }

            $unreadIds = $this->unreadConversationIdsForUser($user, $access);
            if (empty($unreadIds)) {
                return response()->json(['success' => true, 'cleared' => 0]);
            }

            $now = now();
            DB::transaction(function () use ($unreadIds, $now, $user) {
                foreach (array_chunk($unreadIds, 500) as $chunk) {
                    ConversationModel::whereIn('id', $chunk)->update([
                        'global_read_at' => $now,
                        'unread_count'   => 0,
                    ]);
                }

                $userId = $user ? $user->id : null;
                if ($userId && Schema::hasTable('t_wa_conversation_reads')) {
                    $hasForced = Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at');
                    $rows = [];
                    foreach ($unreadIds as $cid) {
                        $row = [
                            'user_id'         => $userId,
                            'conversation_id' => $cid,
                            'last_read_at'    => $now,
                            'updated_at'      => $now,
                            'created_at'      => $now,
                        ];
                        if ($hasForced) {
                            $row['forced_unread_at'] = null;
                        }
                        $rows[] = $row;
                    }
                    $updateCols = $hasForced
                        ? ['last_read_at', 'forced_unread_at', 'updated_at']
                        : ['last_read_at', 'updated_at'];
                    foreach (array_chunk($rows, 500) as $chunk) {
                        ConversationReadModel::upsert(
                            $chunk,
                            ['user_id', 'conversation_id'],
                            $updateCols
                        );
                    }
                }
            });

            Log::info('WhatsApp mark-all-read (mobile)', [
                'user_id'   => $user ? $user->id : null,
                'user_name' => $user ? $user->name : null,
                'cleared'   => count($unreadIds),
            ]);

            return response()->json([
                'success' => true,
                'cleared' => count($unreadIds),
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp mark-all-read failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a conversation as UNREAD for this user.
     *
     * For a regular user we delete their t_wa_conversation_reads row — the
     * next unread calc will then consider all inbound messages after 1970
     * (effectively "everything I haven't seen yet since the last outbound
     * reply"). We don't nuke their row entirely if the conversation has no
     * inbound messages; we still need the legacy unread_count bump so the
     * list shows a dot on their next fetch.
     *
     * For a super-reader we additionally clear the conversation's
     * global_read_at. The semantic: "Taimur is telling the team this
     * deserves another look" — if he clears it, everyone should see it
     * as unread again.
     */
    public function markUnread(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $conversation = ConversationModel::find($conversationId);
            if (!$conversation) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            // Upsert the user's read row with forced_unread_at = NOW(). We
            // DO NOT touch last_read_at — the existing schema has it NOT
            // NULL, and the forced_unread_at flag alone is enough to make
            // the unread calc report this conv as unread regardless of the
            // "has an outbound reply after this inbound" rule. On the next
            // markRead we clear forced_unread_at automatically.
            $hasForcedCol = Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at');
            if (Schema::hasTable('t_wa_conversation_reads') && $hasForcedCol) {
                // Seed a fresh row for users who have never opened the conv
                // before — last_read_at is required, so use the epoch.
                $existing = ConversationReadModel::where('user_id', $user->id)
                    ->where('conversation_id', $conversationId)
                    ->first();
                if ($existing) {
                    $existing->forced_unread_at = now();
                    $existing->save();
                } else {
                    ConversationReadModel::create([
                        'user_id'          => $user->id,
                        'conversation_id'  => $conversationId,
                        'last_read_at'     => '1970-01-01 00:00:00',
                        'forced_unread_at' => now(),
                    ]);
                }
            } elseif (Schema::hasTable('t_wa_conversation_reads')) {
                // Pre-migration fallback — best effort, no forced flag available.
                ConversationReadModel::where('user_id', $user->id)
                    ->where('conversation_id', $conversationId)
                    ->delete();
            }

            // Re-seed the legacy counter so badge consumers that only read
            // unread_count (push badge-sum endpoint) still show a dot even
            // when the user has already replied.
            $updates = ['unread_count' => max(1, (int) $conversation->unread_count)];
            if ($this->whatsapp->isSuperReader($user) &&
                Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                $updates['global_read_at'] = null;
            }
            ConversationModel::where('id', $conversationId)->update($updates);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('markUnread failed', ['error' => $e->getMessage(), 'conv' => $conversationId]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // LABELS (Phase 1 — shared library + apply/remove; Phase 2 adds user
    // mentions via t_wa_labels.user_id + mention_seen_at on the pivot)
    // =========================================================================

    /**
     * Phase 2 helper — return the list of staff users eligible to be
     * targets of a user-mention label (i.e. anyone with the WhatsApp view
     * permission). Only admins who can manage labels need this, so it's
     * gated the same way. Returns id + display name (fullname), already
     * sorted by name. Lightweight enough to run on every open of the
     * label-create form.
     */
    public function getLabelUsers(Request $request)
    {
        try {
            $me = Auth::user();
            if (!$me || !$me->hasMobilePermission('manage_whatsapp_labels')) {
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
        } catch (\Exception $e) {
            Log::error('getLabelUsers failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Phase 2 — count of unread @mentions for the current user, across
     * ALL conversations. Used by the mobile shell to show a red "@" dot
     * on the WhatsApp tab icon, independent of the plain unread badge.
     */
    public function getMentionsCount(Request $request)
    {
        try {
            $me = Auth::user();
            if (!$me || !$this->resolveWhatsAppAccess($me)['allowed']) {
                return response()->json(['success' => true, 'mentions_count' => 0]);
            }
            if (!Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at')) {
                return response()->json(['success' => true, 'mentions_count' => 0]);
            }
            $count = DB::table('t_wa_conversation_labels as cl')
                ->join('t_wa_labels as l', 'l.id', '=', 'cl.label_id')
                ->where('l.user_id', $me->id)
                ->whereNull('cl.mention_seen_at')
                ->count();
            return response()->json(['success' => true, 'mentions_count' => (int) $count]);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'mentions_count' => 0]);
        }
    }

    /**
     * List every label in the workspace. Used to populate the Labels sheet
     * on mobile. Ordered: user-mention labels first (so `@user` tags surface
     * quickly), then alphabetical.
     */
    public function getLabels(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || !$this->resolveWhatsAppAccess($user)['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }
            if (!Schema::hasTable('t_wa_labels')) {
                return response()->json(['success' => true, 'labels' => []]);
            }

            $labels = \App\Models\WhatsApp\LabelModel::orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('name')
                ->get()
                ->map(function ($l) {
                    return [
                        'id'        => $l->id,
                        'name'      => $l->name,
                        'color'     => $l->color,
                        'user_id'   => $l->user_id,
                        'is_system' => (bool) $l->is_system,
                    ];
                });

            return response()->json([
                'success' => true,
                'labels' => $labels,
                'can_manage' => (bool) $user->hasMobilePermission('manage_whatsapp_labels'),
            ]);
        } catch (\Exception $e) {
            Log::error('getLabels failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new label. Gated by `manage_whatsapp_labels` so random users
     * can't pollute the shared library. Enforces case-insensitive uniqueness
     * on name so we never end up with "VIP" and "vip" side by side.
     */
    public function createLabel(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('manage_whatsapp_labels')) {
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
                'name'       => $name,
                'color'      => $data['color'] ?? '#6B7280',
                'user_id'    => $data['user_id'] ?? null,
                'is_system'  => 0,
                'created_by' => $user->id,
            ]);

            return response()->json(['success' => true, 'label' => $label]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error('createLabel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing label's name / colour / user binding. Same manage
     * perm as create. We allow editing system labels (the seeded defaults)
     * because users may want to retheme them — deletion is what we protect.
     */
    public function updateLabel(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('manage_whatsapp_labels')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $label = \App\Models\WhatsApp\LabelModel::find($id);
            if (!$label) {
                return response()->json(['success' => false, 'message' => 'Label not found'], 404);
            }

            $data = $request->validate([
                'name'    => 'sometimes|required|string|max:60',
                'color'   => 'sometimes|nullable|string|max:20',
                'user_id' => 'sometimes|nullable|integer|exists:t_sys_user,id',
            ]);

            if (isset($data['name'])) {
                $name = trim($data['name']);
                if ($name === '') {
                    return response()->json(['success' => false, 'message' => 'Name required'], 422);
                }
                $clash = \App\Models\WhatsApp\LabelModel::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->where('id', '!=', $id)->exists();
                if ($clash) {
                    return response()->json(['success' => false, 'message' => 'Another label already has this name.'], 422);
                }
                $label->name = $name;
            }
            if (array_key_exists('color', $data))   $label->color = $data['color'] ?: '#6B7280';
            if (array_key_exists('user_id', $data)) $label->user_id = $data['user_id'];

            $label->save();
            return response()->json(['success' => true, 'label' => $label]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error('updateLabel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a label from the library. Also cascades through
     * t_wa_conversation_labels via the FK so no orphan pivot rows are left.
     * Admins can delete even system labels — the system flag is advisory.
     */
    public function deleteLabel(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user || !$user->hasMobilePermission('manage_whatsapp_labels')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $label = \App\Models\WhatsApp\LabelModel::find($id);
            if (!$label) {
                return response()->json(['success' => false, 'message' => 'Label not found'], 404);
            }

            $label->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('deleteLabel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Apply a label to a conversation. Open to anyone with the base
     * view permission — labelling is a core inbox action, not an admin
     * one. Idempotent: re-applying a label is a no-op, not an error.
     */
    public function applyLabel(Request $request, $conversationId)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $data = $request->validate([
                'label_id' => 'required|integer|exists:t_wa_labels,id',
            ]);

            $conv = ConversationModel::find($conversationId);
            if (!$conv) {
                return response()->json(['success' => false, 'message' => 'Conversation not found'], 404);
            }

            // Check existing pivot first so we can tell a fresh apply from a
            // re-apply. We only push-notify on the FIRST application — otherwise
            // tapping the label toggle twice would spam the user.
            $existing = \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
                ->where('label_id', $data['label_id'])
                ->first();

            $isNewApply = ($existing === null);

            // Idempotent upsert. Reset mention_seen_at on re-apply so a
            // re-tag after the mentioned user cleared it surfaces as unread
            // again — operators may re-tag to re-escalate.
            $hasSeenCol = Schema::hasColumn('t_wa_conversation_labels', 'mention_seen_at');
            $payload = ['applied_by' => $user->id, 'applied_at' => now()];
            if ($hasSeenCol) {
                $payload['mention_seen_at'] = null;
            }
            \App\Models\WhatsApp\ConversationLabelModel::updateOrCreate(
                ['conversation_id' => $conversationId, 'label_id' => $data['label_id']],
                $payload
            );

            // If this is a user-mention label (user_id set) AND the applier
            // is not the same person being tagged (no self-mention pings),
            // fire an FCM push + leave the pivot's mention_seen_at NULL so
            // the recipient's inbox shows a red dot until they open it.
            try {
                $label = \App\Models\WhatsApp\LabelModel::find($data['label_id']);
                if ($isNewApply && $label && $label->user_id && (int) $label->user_id !== (int) $user->id) {
                    app(\App\Services\FirebaseService::class)->notifyWhatsAppMention(
                        (int) $label->user_id,
                        $user->fullname ?? ('User #' . $user->id),
                        $conv->display_name ?: ($conv->wa_contact_name ?: $conv->wa_phone),
                        (int) $conversationId
                    );
                }
            } catch (\Exception $e) {
                // Never let a notification failure break the API call.
                Log::debug('applyLabel: mention push failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            throw $ve;
        } catch (\Exception $e) {
            Log::error('applyLabel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove a label from a conversation. Returns the remaining labels so
     * the caller can update its chip list without a full refetch.
     */
    public function removeLabel(Request $request, $conversationId, $labelId)
    {
        try {
            $user = Auth::user();
            $access = $this->resolveWhatsAppAccess($user);
            if (!$access['allowed']) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            \App\Models\WhatsApp\ConversationLabelModel::where('conversation_id', $conversationId)
                ->where('label_id', $labelId)
                ->delete();

            return response()->json(['success' => true, 'labels' => $this->labelsForConversation($conversationId)]);
        } catch (\Exception $e) {
            Log::error('removeLabel failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Internal helper — returns the labels currently applied to one
     * conversation in the same shape used in list / detail responses.
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
                // Legacy fallback: approximate "conversations with unread"
                // by counting rows where the rolling counter is non-zero.
                $count = ConversationModel::where('unread_count', '>', 0)
                    ->where('status', '!=', 'closed')
                    ->count();
                return response()->json(['success' => true, 'unread_count' => (int) $count]);
            }

            // Apr-2026: badge counts CONVERSATIONS-with-unread, not raw
            // messages. See WhatsAppWebController::getUnreadCount for the
            // full rationale — keeping mobile in lockstep so the home-tab
            // badge matches what the operator sees on the inbox.
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

            // Honour the super-reader's global_read_at marker when counting
            // the badge — otherwise the red dot would stay on even after
            // Taimur clears the thread for everyone.
            if (Schema::hasColumn('t_wa_conversations', 'global_read_at')) {
                $countQ->leftJoin('t_wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                       ->where(function ($w) {
                           $w->whereNull('c.global_read_at')
                             ->orWhereColumn('m.created_at', '>', 'c.global_read_at');
                       });
            }

            // Limited users' badge only counts messages inside their window.
            if ($access['limited']) {
                $countQ->where('m.created_at', '>=', $access['cutoff']);
            }

            $count = (int) $countQ
                ->selectRaw('COUNT(DISTINCT m.conversation_id) as cnt')
                ->value('cnt');

            // Forced-unread bucket: one row per conversation explicitly
            // Mark-Unread-ed and not yet reopened. Already conversation-
            // grained, so the existing max() merge still does the right
            // thing now that the genuine count is also conversation-grained.
            if (Schema::hasColumn('t_wa_conversation_reads', 'forced_unread_at')) {
                $forcedCount = DB::table('t_wa_conversation_reads')
                    ->where('user_id', $user->id)
                    ->whereNotNull('forced_unread_at')
                    ->count();
                if ($forcedCount > 0) {
                    $count = max($count, $forcedCount);
                }
            }

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
