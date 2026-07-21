<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantAgentService;
use App\Services\Assistant\AssistantDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * NF Assistant — the mobile-facing endpoints.
 *
 * Auth: Sanctum (inherited from the routes/api.php group) + the
 * `use_ai_assistant` mobile permission, checked on EVERY method. Launch = the
 * CEO only; widening it later is a role checkbox, not a code change.
 *
 * Money safety lives in AssistantDraftService — the model can only produce a
 * draft; /confirm is the sole path that writes, and it replays the draft
 * through the existing endpoints. See NF-ASSISTANT-AGENT-PLAN-JUL2026.md.
 */
class AssistantController extends Controller
{
    public function __construct(
        private AssistantAgentService $agent,
        private AssistantDraftService $drafts,
    ) {
    }

    /** POST /api/assistant/message — one turn: text and/or voice and/or image. */
    public function message(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'message' => 'nullable|string|max:4000',
            'audio'   => 'nullable|file|max:8192',  // 8 MB — mirrors the voice-note cap
            'image'   => 'nullable|image|max:8192',
        ]);

        if (!$request->filled('message') && !$request->hasFile('audio') && !$request->hasFile('image')) {
            return response()->json(['success' => false, 'message' => 'Say something first.'], 422);
        }

        $conversationId = $this->conversationFor($user->id);
        $text = trim((string) $request->input('message', ''));
        $inputType = 'text';
        $mediaPath = null;
        $transcript = null;

        // ── voice → text (Gemini; the codebase has no other STT) ──
        if ($request->hasFile('audio')) {
            $inputType = 'voice';
            $file = $request->file('audio');
            $bytes = file_get_contents($file->getRealPath());
            $mime = $file->getMimeType() ?: 'audio/aac';

            // ⚠️ Normalize container mimes before Gemini. PHP sniffs an
            // m4a/mp4 container as video/mp4, and Gemini then routes it to
            // its VIDEO pipeline → 400 "The video is corrupted … 0 Frames
            // found" (observed live). The app now records raw AAC, but keep
            // this backstop for older builds / odd Android labels; Gemini's
            // supported audio list is aac/mp3/wav/ogg/flac/aiff.
            if (in_array($mime, ['video/mp4', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'application/octet-stream'], true)) {
                Log::info('Assistant: normalizing audio mime for transcription', ['from' => $mime]);
                $mime = 'audio/aac';
            }

            $mediaPath = 'assistant/' . date('Y/m') . '/voice-' . uniqid() . '.' . ($file->getClientOriginalExtension() ?: 'm4a');
            Storage::disk(config('whatsapp.media_disk', 'public'))->put($mediaPath, $bytes);

            $transcript = $this->agent->transcribe($bytes, $mime);
            if (!$transcript) {
                $this->logMessage($conversationId, $user->id, 'user', '[voice note]', $inputType, $mediaPath, null, null);
                return response()->json([
                    'success' => false,
                    'message' => 'I could not hear that clearly — please try again or type it.',
                ], 422);
            }
            // The transcript IS the user's message from here on.
            $text = trim($text . ' ' . $transcript);
        }

        // ── screenshot → vision input ──
        $image = null;
        if ($request->hasFile('image')) {
            $inputType = $inputType === 'voice' ? 'voice' : 'image';
            $file = $request->file('image');
            $bytes = file_get_contents($file->getRealPath());
            $imgPath = 'assistant/' . date('Y/m') . '/img-' . uniqid() . '.' . ($file->getClientOriginalExtension() ?: 'jpg');
            Storage::disk(config('whatsapp.media_disk', 'public'))->put($imgPath, $bytes);
            $mediaPath = $mediaPath ?: $imgPath;
            $image = ['mime' => $file->getMimeType() ?: 'image/jpeg', 'data' => base64_encode($bytes)];
        }

        // ⚠️ ORDER MATTERS: history must be read BEFORE logging this turn's
        // user message, because run() appends the message itself. Logging
        // first fed the model every user message TWICE in a row — which is
        // how "Lacarne" became a search for "LacarneLacarne" (observed live).
        $history = $this->history($conversationId);
        $this->logMessage($conversationId, $user->id, 'user', $text ?: '[image]', $inputType, $mediaPath, $transcript, null);

        $result = $this->agent->run($text, $history, $user, $image);

        $draft = $result['draft_id'] ? $this->draftPayload((int) $result['draft_id']) : null;

        // ── Honesty guard (the "stuck vendor payment" bug, screenshot-proven):
        // the model once SAID "confirm below" without having called a draft
        // tool, so no card existed and the user's "Yes" went nowhere. If the
        // reply points at a card we aren't attaching, attach the newest live
        // one (a re-phrasing of an existing card is legitimate); if none
        // exists, replace the claim with the truth.
        if (!$draft && $this->mentionsConfirmCard($result['text'] ?? '')) {
            $pending = DB::table('t_ai_drafts')
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->where(function ($w) {
                    $w->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('id')
                ->first(['id']);

            if ($pending) {
                $result['draft_id'] = (int) $pending->id;
                $draft = $this->draftPayload((int) $pending->id);
            } else {
                // SELF-HEAL: one corrective re-run instead of an apology. The
                // model already gathered the details (observed live: it found
                // the vendor + accounts, then stopped one step short) — a
                // pointed correction usually completes the draft. Costs one
                // extra model call, and only on turns that already failed.
                Log::warning('Assistant claimed a card without drafting one — retrying', [
                    'user' => $user->id, 'reply' => mb_substr((string) $result['text'], 0, 300),
                ]);

                $retryHistory = array_merge($history, [
                    ['role' => 'user', 'content' => $text ?: '[image]'],
                    ['role' => 'assistant', 'content' => (string) $result['text']],
                ]);
                $retry = $this->agent->run(
                    'SYSTEM CHECK (not the user speaking): your last reply claimed a confirmation card, '
                    . 'but no draft tool succeeded, so NO card exists on the user\'s screen. '
                    . 'Call the correct draft tool NOW with the details already gathered. '
                    . 'If something required is genuinely missing, ask for it plainly and do not mention any card.',
                    $retryHistory, $user, null
                );

                if ($retry['draft_id']) {
                    $retry['tool_calls'] = array_merge($result['tool_calls'] ?? [], $retry['tool_calls'] ?? []);
                    $result = $retry;
                    $draft = $this->draftPayload((int) $retry['draft_id']);
                } elseif (!$this->mentionsConfirmCard($retry['text'] ?? '')) {
                    // No draft but an honest reply (e.g. a real question) — use it.
                    $retry['tool_calls'] = array_merge($result['tool_calls'] ?? [], $retry['tool_calls'] ?? []);
                    $result = $retry;
                } else {
                    $result['text'] = 'I have not actually prepared that yet — my mistake. '
                        . 'Tell me once more what to record and I will put the card up properly.';
                }
            }
        }

        $this->logMessage(
            $conversationId, $user->id, 'assistant', $result['text'], null, null, null,
            $result['draft_id'] ?? null,
            $result['tool_calls'] ?? [],
            $result['error'] ?? null,
            $result['usage'] ?? null
        );

        DB::table('t_ai_conversations')->where('id', $conversationId)
            ->update(['last_message_at' => now(), 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'reply' => $result['text'],
            'transcript' => $transcript,   // shown under the user's bubble so he can see what we heard
            'draft' => $draft,             // → the confirmation card
        ]);
    }

    /** POST /api/assistant/confirm/{draft} — the ONLY path that writes money. */
    public function confirm(Request $request, $draftId)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $result = $this->drafts->confirm((int) $draftId, $user);
        $conversationId = $this->conversationFor($user->id);

        // Record the outcome in the transcript so the chat reads as a full
        // history ("Recorded. Request #1234") rather than a card that silently
        // changed state.
        $this->logMessage($conversationId, $user->id, 'assistant',
            ($result['ok'] ? '✅ ' : '⚠️ ') . ($result['message'] ?? ''), null, null, null, (int) $draftId);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? '',
            'draft' => $this->draftPayload((int) $draftId),
            // "Save this account for next time?" — set when the confirmed draft
            // came from a bank SMS with an unmapped counterparty (one-tap ask).
            // (Only confirm() ever sets it; null elsewhere.)
            'remember_prompt' => $result['remember_prompt'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * POST /api/assistant/draft/{draft}/choose — one-tap answer to a card's
     * pending choice (which bank). Pure DB write, no model call, so it costs
     * nothing and cannot hallucinate.
     */
    public function choose(Request $request, $draftId)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate(['option_id' => 'required|integer']);

        $result = $this->drafts->choose((int) $draftId, $user, (int) $request->input('option_id'));

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? '',
            'draft' => $this->draftPayload((int) $draftId),
            // "Save this account for next time?" — set when the confirmed draft
            // came from a bank SMS with an unmapped counterparty (one-tap ask).
            // (Only confirm() ever sets it; null elsewhere.)
            'remember_prompt' => $result['remember_prompt'] ?? null,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    /** POST /api/assistant/cancel/{draft} */
    public function cancel(Request $request, $draftId)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $result = $this->drafts->cancel((int) $draftId, $user);

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? '',
            'draft' => $this->draftPayload((int) $draftId),
        ]);
    }

    /**
     * POST /api/assistant/new-topic — drop the working context (the visible
     * transcript stays). Inserts a break marker that history() cuts at, plus a
     * visible divider bubble. No model call.
     */
    public function newTopic(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $conversationId = $this->conversationFor($user->id);
        $this->logMessage($conversationId, $user->id, 'system', '[topic_break]');
        $notice = '🧹 Fresh start — I won\'t use the earlier messages as context. Waiting cards still work.';
        $this->logMessage($conversationId, $user->id, 'assistant', $notice);

        return response()->json(['success' => true, 'message' => $notice]);
    }

    /** GET /api/assistant/history — the transcript for the assistant chat. */
    public function history_endpoint(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $conversationId = $this->conversationFor($user->id);
        $rows = DB::table('t_ai_messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])   // tool rows are audit, not chat
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'role', 'content', 'input_type', 'transcript', 'draft_id', 'created_at']);

        $messages = $rows->reverse()->values()->map(function ($m) {
            return [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'input_type' => $m->input_type,
                'transcript' => $m->transcript,
                'draft' => $m->draft_id ? $this->draftPayload((int) $m->draft_id) : null,
                'created_at' => $m->created_at,
            ];
        })->all();

        return response()->json(['success' => true, 'messages' => $messages]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function allowed($user): bool
    {
        return $user && $user->hasMobilePermission('use_ai_assistant');
    }

    private function conversationFor(int $userId): int
    {
        $id = DB::table('t_ai_conversations')->where('user_id', $userId)->value('id');
        if ($id) return (int) $id;

        return (int) DB::table('t_ai_conversations')->insertGetId([
            'user_id' => $userId,
            'title' => 'NF Assistant',
            'last_message_at' => now(),
            'created_at' => now(),
        ]);
    }

    /**
     * Recent turns, oldest→newest, for the model's context window.
     *
     * Scoped to TODAY and to after the last "new topic" break: the visible
     * thread is one long chat, but the model starts each day (or each 🧹)
     * fresh. Observed live: a failed-lookup loop early in the thread kept
     * poisoning later turns — the model repeated its own earlier mistakes.
     * Waiting cards survive the cut via the get_pending_draft tool.
     */
    private function history(int $conversationId): array
    {
        $limit = (int) config('assistant.history_messages', 20);

        $breakId = (int) DB::table('t_ai_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'system')
            ->where('content', '[topic_break]')
            ->max('id');

        return DB::table('t_ai_messages')
            ->where('conversation_id', $conversationId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('id', '>', $breakId)
            ->where('created_at', '>=', now()->startOfDay())
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->values()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    private function draftPayload(int $draftId): ?array
    {
        $d = DB::table('t_ai_drafts')->where('id', $draftId)->first();
        if (!$d) return null;

        // Expired drafts should read as expired the moment they're fetched,
        // without waiting for a confirm attempt to discover it.
        $status = $d->status;
        if ($status === 'pending' && $d->expires_at && now()->gt($d->expires_at)) {
            $status = 'expired';
        }

        // A live pending choice (which bank?) renders as option buttons on the
        // card; Confirm stays disabled until one is tapped.
        $payload = json_decode($d->payload_json, true) ?: [];
        $choices = ($status === 'pending' && !empty($payload['_pending_choice']))
            ? $payload['_pending_choice']
            : null;

        return [
            'id' => $d->id,
            'type' => $d->type,
            'status' => $status,
            'summary' => $d->summary,
            'rows' => json_decode($d->display_json, true) ?: [],
            'choices' => $choices,
            'expires_at' => $d->expires_at,
            'result_type' => $d->result_type,
            'result_id' => $d->result_id,
            'error' => $d->error,
        ];
    }

    /**
     * Does this reply point the user at a confirmation card? Deliberately
     * broad — a false positive just attaches an existing card or swaps in an
     * honest sentence, both harmless; a false negative reproduces the bug.
     */
    private function mentionsConfirmCard(string $text): bool
    {
        // "on the card / tap the bank" caught a live escape: the model said
        // "Please tap the bank on the card to confirm" with no card in
        // existence, and the original narrower list missed it.
        return (bool) preg_match(
            '/confirm below|card below|tap confirm|press confirm|awaiting your confirmation'
            . '|waiting for your confirmation|confirmation card|ready to record|ready to pay'
            . '|ready for your confirmation|on the card|tap the bank|bank buttons/i',
            $text
        );
    }

    private function logMessage(
        int $conversationId, int $userId, string $role, ?string $content,
        ?string $inputType = null, ?string $mediaPath = null, ?string $transcript = null,
        ?int $draftId = null, array $toolCalls = [], ?string $error = null, $usage = null
    ): void {
        try {
            DB::table('t_ai_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'role' => $role,
                'content' => $content,
                'input_type' => $inputType,
                'media_path' => $mediaPath,
                'transcript' => $transcript,
                'tool_calls_json' => $toolCalls ? json_encode($toolCalls) : null,
                'draft_id' => $draftId,
                'model' => config('assistant.' . config('assistant.driver') . '.model'),
                'tokens_in' => $usage['promptTokenCount'] ?? null,
                'tokens_out' => $usage['candidatesTokenCount'] ?? null,
                'error' => $error,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A logging failure must never break the user's turn.
            Log::warning('Assistant message log failed', ['error' => $e->getMessage()]);
        }
    }
}
