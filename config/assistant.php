<?php

/**
 * NF Assistant configuration.
 *
 * ⚠️ env() IS ONLY SAFE INSIDE CONFIG FILES. Once `php artisan config:cache`
 * runs in production, env() returns NULL everywhere else — that trap already
 * broke dispatch once. Read these values via config('assistant.*') ONLY.
 *
 * Brain: Gemini for now (owner ruling 2026-07-16 — no Anthropic account yet).
 * `driver` + `model` are deliberately config values so switching to Claude
 * later is an .env change, not a rewrite: the agent service branches on driver.
 */
return [

    // 'gemini' today. 'claude' when/if the owner opens an Anthropic account —
    // AssistantAgentService::callModel() is the only place that branches.
    'driver' => env('ASSISTANT_DRIVER', 'gemini'),

    'gemini' => [
        // Reuses the SAME key as the payment-proof OCR (payment_signals.gemini).
        // Kept as its own env lookup so the assistant can be pointed at a
        // separate key/quota later without touching the OCR pipeline.
        'api_key'  => env('ASSISTANT_GEMINI_API_KEY', env('GEMINI_API_KEY', '')),
        // gemini-3.5-flash, NOT 2.5-flash: Google 404s 2.5-flash for any
        // freshly-created project ("no longer available to new users"), which
        // is exactly what the assistant's own key/project is. 3.5-flash is the
        // current GA flash — verified callable with our tool + thinkingBudget
        // payload on the new project (2026-07-19). The OLD operational key is
        // grandfathered onto 2.5-flash; its OCR pipeline reads a different
        // config, so this change is isolated to the assistant.
        'model'    => env('ASSISTANT_GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),
        'timeout'  => env('ASSISTANT_TIMEOUT', 45),
    ],

    // Reserved for the Claude driver. Nothing reads these until driver=claude.
    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model'   => env('ASSISTANT_CLAUDE_MODEL', 'claude-sonnet-5'),
        'timeout' => env('ASSISTANT_TIMEOUT', 45),
    ],

    // Voice notes: Claude cannot accept audio, and the codebase has no STT at
    // all, so transcription always goes through Gemini regardless of `driver`.
    'transcription' => [
        'enabled' => env('ASSISTANT_TRANSCRIBE', true),
        // Same reason as the agent model above — 2.5-flash 404s on a new
        // project. 3.5-flash is multimodal (accepts audio) so voice notes work.
        'model'   => env('ASSISTANT_TRANSCRIBE_MODEL', 'gemini-3.5-flash'),
        // 6 min ≈ the voice-note cap the messaging screen already enforces.
        'max_seconds' => 360,
    ],

    /**
     * Hard ceiling on tool round-trips per user message. The agent loop stops
     * here and answers with what it has.
     *
     * Why 4: the A1 flows need at most 3 (find_vendor → list_payment_accounts →
     * draft_vendor_payment). 4 leaves one spare for a retry. A runaway loop on
     * a shared PHP host is worse than a slightly worse answer.
     */
    'max_tool_rounds' => env('ASSISTANT_MAX_TOOL_ROUNDS', 4),

    /**
     * Wall-clock budget for one /assistant/message request, in seconds. Kept
     * under typical shared-hosting limits (stackcp) so a slow model can't hit
     * a hard 500 mid-turn.
     */
    'request_budget_seconds' => env('ASSISTANT_REQUEST_BUDGET', 90),

    /**
     * How long a confirmation card stays confirmable.
     *
     * Short ON PURPOSE: a draft is a snapshot of balances and context at the
     * moment it was drafted. Confirming a 2-hour-old "pay vendor X 50,000"
     * could post against a balance that has since moved. 15 minutes is long
     * enough to think, short enough to stay true.
     */
    'draft_ttl_minutes' => env('ASSISTANT_DRAFT_TTL', 15),

    /**
     * Payment-proof cards only: 24 h. Taimur forwards a proof and reviews
     * later (owner ask 2026-07-19) — and it's safe, because confirming a proof
     * re-runs the matcher fresh; nothing stale can be committed.
     */
    'proof_draft_ttl_minutes' => env('ASSISTANT_PROOF_DRAFT_TTL', 1440),

    /**
     * Chat history sent to the model, in messages. Small: this is a
     * command-shaped assistant ("record 5000 fuel"), not a long conversation,
     * and every extra turn is tokens on every request.
     */
    'history_messages' => env('ASSISTANT_HISTORY', 20),

    /**
     * Defaults the agent may ask about once and then remember per user
     * (owner ruling: Taimur sets his own on first use). Stored in
     * t_ai_user_prefs. Listed here so the agent's prompt and the prefs tool
     * agree on the key names.
     */
    'pref_keys' => [
        'expense_payment_source_account_id',
        'expense_business_unit_id',
        'vendor_payment_source_account_id',
        // Which of OUR banks an online payment goes out from — the owner's
        // "he'll mostly pay from Online + one bank" case. With these set,
        // "pay lacarne 5000" needs zero follow-up questions.
        'expense_receiving_account_id',
        'vendor_payment_receiving_account_id',
        // ⭐ Not an account — an ENUM: prompt | auto | off. Governs whether
        // recording a payment by hand asks "is this bank SMS the one?"
        // (MoneyOutTagService). Settable by voice too ("stop asking me about
        // bank SMS"), which is why it lives in the same allowlist.
        'tag_prompt_mode',
    ],

    /** Allowed values for the enum preferences above. */
    'pref_enums' => [
        'tag_prompt_mode' => ['prompt', 'auto', 'off'],
    ],
];
