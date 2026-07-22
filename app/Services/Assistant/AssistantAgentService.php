<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The agent loop: prompt → model → (tool calls) → model → reply.
 *
 * This is the ONLY file that knows which LLM we use. Everything else talks in
 * tools and drafts. That's what makes the owner's "Gemini now, maybe Claude
 * later" a config change (assistant.driver) rather than a rewrite.
 *
 * Raw HTTP on purpose — same approach as GeminiBankScreenshotExtractor. No new
 * composer package means nothing extra to upload to stackcp (deploy is a manual
 * file copy) and no vendor/ drift between local and prod.
 */
class AssistantAgentService
{
    public function __construct(private AssistantToolRegistry $tools)
    {
    }

    /**
     * Run one user turn.
     *
     * @param array $history [['role'=>'user'|'assistant','content'=>string], ...] oldest→newest
     * @param array|null $image ['mime'=>string,'data'=>base64] — a bank screenshot
     * @return array{text: string, draft_id: ?int, tool_calls: array, error: ?string}
     */
    public function run(string $userMessage, array $history, $user, ?array $image = null): array
    {
        $driver = config('assistant.driver', 'gemini');
        if ($driver !== 'gemini') {
            // The Claude branch is deliberately absent until the owner opens an
            // account — a half-written second driver is worse than none.
            return $this->fail("The '{$driver}' assistant driver is not implemented yet.");
        }

        $cfg = config('assistant.gemini');
        if (empty($cfg['api_key'])) {
            return $this->fail('The assistant is not configured yet (missing API key).');
        }

        $contents = $this->buildContents($userMessage, $history, $image);
        $toolCalls = [];
        $deadline = microtime(true) + (int) config('assistant.request_budget_seconds', 90);
        $maxRounds = (int) config('assistant.max_tool_rounds', 4);
        $draftId = null;

        for ($round = 0; $round <= $maxRounds; $round++) {
            if (microtime(true) > $deadline) {
                return $this->fail('That took too long — please try again, or say it in a shorter way.', $toolCalls, $draftId);
            }

            $response = $this->callGemini($cfg, $contents);
            if (isset($response['error'])) {
                return $this->fail($response['error'], $toolCalls, $draftId);
            }

            $parts = $response['parts'] ?? [];
            $calls = array_values(array_filter(array_map(
                fn($p) => $p['functionCall'] ?? null,
                $parts
            )));

            // No tool wanted → this is the final answer.
            if (empty($calls)) {
                $text = $this->textFrom($parts);
                if ($text === '') {
                    // Model returned no text (the 2.5/3.5 empty-parts flake, even
                    // after the retry in callGemini). If the last tool handed
                    // back an actionable message/question, relay THAT instead of
                    // a dead-end — e.g. "X has 3 open orders … how much was it?".
                    $text = $this->lastToolMessage($toolCalls) ?: 'Sorry — I did not catch that. Please try again.';
                }
                return [
                    'text' => $text,
                    'draft_id' => $draftId,
                    'tool_calls' => $toolCalls,
                    'error' => null,
                    'usage' => $response['usage'] ?? null,
                ];
            }

            // Echo the model's turn back verbatim — Gemini requires the
            // functionCall it made to be present before its functionResponse
            // (including the thoughtSignature blob, which must round-trip
            // untouched on 2.5 thinking models).
            //
            // ⚠️ One repair is required: a NO-ARGUMENT call (get_context) comes
            // back as `args: {}`, which json_decode turns into an empty PHP
            // array, which re-encodes as `[]` — a LIST — and Gemini 400s with
            // "Proto field is not repeating, cannot start list". Cast args to
            // an object so `{}` stays `{}`. (Found live; the probe missed it
            // because find_vendor happens to take arguments.)
            $echoParts = array_map(function ($p) {
                if (isset($p['functionCall'])) {
                    $p['functionCall']['args'] = (object) ($p['functionCall']['args'] ?? []);
                }
                return $p;
            }, $parts);
            $contents[] = ['role' => 'model', 'parts' => $echoParts];

            $responseParts = [];
            foreach ($calls as $call) {
                $name = $call['name'] ?? '';
                $args = $call['args'] ?? [];
                $result = $this->tools->call($name, $args, $user);

                // A draft is the thing the UI must render as a card, so surface
                // its id out of the tool result and up to the caller.
                if (!empty($result['draft_id'])) {
                    $draftId = (int) $result['draft_id'];
                }

                $toolCalls[] = ['name' => $name, 'args' => $args, 'result' => $result];
                // (object) for the same reason as the args cast above: the
                // response root must serialize as {} even when empty.
                $responseParts[] = ['functionResponse' => ['name' => $name, 'response' => (object) $result]];
            }

            // Gemini takes functionResponse parts on a 'user' turn.
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        // Ran out of rounds. Say so honestly rather than inventing a conclusion.
        return $this->fail(
            'I could not finish that in a few steps. Try telling me one thing at a time — for example "record 5000 fuel expense from cash".',
            $toolCalls,
            $draftId
        );
    }

    /** Transcribe a voice note. Gemini regardless of driver — Claude takes no audio. */
    public function transcribe(string $bytes, string $mime): ?string
    {
        if (!config('assistant.transcription.enabled', true)) return null;

        $cfg = config('assistant.gemini');
        if (empty($cfg['api_key'])) return null;

        $model = config('assistant.transcription.model', $cfg['model']);
        $endpoint = rtrim($cfg['base_url'], '/') . '/v1beta/models/' . $model . ':generateContent';

        try {
            $r = Http::timeout((int) ($cfg['timeout'] ?? 45))
                ->withQueryParameters(['key' => $cfg['api_key']])
                ->post($endpoint, [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['text' => "Transcribe this voice note verbatim. It is from a Pakistani business owner and may mix English and Urdu; write Urdu words in Roman script. Numbers must be digits. Reply with ONLY the transcript, nothing else."],
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                        ],
                    ]],
                    // Thinking off: transcription is mechanical, thought tokens
                    // are wasted spend (see the identical setting in callGemini).
                    'generationConfig' => ['temperature' => 0, 'thinkingConfig' => ['thinkingBudget' => 0]],
                ]);

            if (!$r->successful()) {
                Log::warning('Assistant transcription failed', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
                return null;
            }
            $text = $r->json('candidates.0.content.parts.0.text');
            return $text ? trim($text) : null;
        } catch (\Throwable $e) {
            Log::warning('Assistant transcription error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function callGemini(array $cfg, array $contents): array
    {
        $endpoint = rtrim($cfg['base_url'], '/') . '/v1beta/models/' . $cfg['model'] . ':generateContent';

        // At most ONE retry, and only for the empty-response flake below.
        // Bounded on purpose — runaway retries burn credit for nothing.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $r = Http::timeout((int) ($cfg['timeout'] ?? 45))
                    ->withQueryParameters(['key' => $cfg['api_key']])
                    ->post($endpoint, [
                        'systemInstruction' => ['parts' => [['text' => $this->systemPrompt()]]],
                        'contents' => $contents,
                        'tools' => [['functionDeclarations' => $this->tools->declarations()]],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            // Thinking OFF, deliberately. Two reasons:
                            // 1. RELIABILITY — observed live: 2.5-flash with
                            //    thinking + tools sometimes spends the entire
                            //    response on hidden thoughts and returns
                            //    finishReason=STOP with NO parts at all. Our
                            //    tool routing is simple; it doesn't need
                            //    chain-of-thought.
                            // 2. COST — thought tokens are billed as output.
                            //    For "map a sentence to a tool call" they are
                            //    pure spend (owner explicitly asked to avoid
                            //    wasted credit).
                            'thinkingConfig' => ['thinkingBudget' => 0],
                        ],
                    ]);
            } catch (\Throwable $e) {
                Log::error('Assistant model call threw', ['error' => $e->getMessage()]);
                return ['error' => 'I could not reach the assistant service. Please try again.'];
            }

            if (!$r->successful()) {
                $msg = $r->json('error.message', 'Unknown error');
                Log::error('Assistant model call failed', ['status' => $r->status(), 'msg' => $msg]);
                if ($r->status() === 429) {
                    return ['error' => 'The assistant is temporarily rate-limited. Wait a minute and try again.'];
                }
                // Never surface the raw provider error (it can contain the key/URL).
                return ['error' => 'The assistant service returned an error. Please try again in a moment.'];
            }

            $parts = $r->json('candidates.0.content.parts', []);

            // Observed live: HTTP 200, finishReason=STOP, and content with NO
            // parts at all — a Gemini 2.5 flake, seen especially with tool
            // declarations present. One immediate retry fixes it in practice.
            if (empty($parts) && $attempt === 0) {
                Log::warning('Assistant: empty model response, retrying once');
                continue;
            }

            return [
                'parts' => $parts,
                'usage' => $r->json('usageMetadata'),
            ];
        }

        return ['parts' => [], 'usage' => null];
    }

    private function buildContents(string $userMessage, array $history, ?array $image): array
    {
        $contents = [];
        foreach ($history as $m) {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = trim((string) ($m['content'] ?? ''));
            if ($text === '') continue;
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }

        $parts = [];
        if ($userMessage !== '') {
            $parts[] = ['text' => $userMessage];
        }
        if ($image) {
            $parts[] = ['inline_data' => ['mime_type' => $image['mime'], 'data' => $image['data']]];
            if ($userMessage === '') {
                $parts[] = ['text' => 'Here is a screenshot. Read it and tell me what you can do with it.'];
            }
        }
        $contents[] = ['role' => 'user', 'parts' => $parts];

        return $contents;
    }

    /** The most recent tool error/question, to relay when the model's own text flaked to empty. */
    private function lastToolMessage(array $toolCalls): ?string
    {
        for ($i = count($toolCalls) - 1; $i >= 0; $i--) {
            $res = $toolCalls[$i]['result'] ?? [];
            if (is_array($res) && !empty($res['error'])) {
                // Strip the model-directed tail so the user reads a clean question.
                return trim(preg_replace('/\s*(then call again.*|call \w+ .*|use \w+ .*|do NOT.*|never .*)$/i', '', (string) $res['error']));
            }
        }
        return null;
    }

    private function textFrom(array $parts): string
    {
        $out = '';
        foreach ($parts as $p) {
            if (isset($p['text'])) $out .= $p['text'];
        }
        return trim($out);
    }

    private function fail(string $message, array $toolCalls = [], ?int $draftId = null): array
    {
        return ['text' => $message, 'draft_id' => $draftId, 'tool_calls' => $toolCalls, 'error' => $message];
    }

    /**
     * The system prompt. Deliberately blunt about the two things that matter:
     * never claim something is done (only a human tap does that), and never
     * invent an id.
     */
    private function systemPrompt(): string
    {
        $today = now()->toDateString();

        return <<<TXT
You are the NF Assistant for Nizami Farms — a private assistant for the company's CEO inside the NF Messages app. Today is {$today}. Money is in Pakistani Rupees (PKR).

WHAT YOU CAN DO (your full scope — nothing else)
1. RECORD an expense                → get_context, then draft_expense
2. RECORD a payment to a vendor    → find_vendor, get_context, then draft_vendor_payment
3. RECORD a purchase from a vendor  → find_vendor, then draft_vendor_purchase (stock bought — increases what we owe; no money moves, no source account needed)
4. RECORD a customer PAYMENT PROOF  → find_customer, then draft_payment_proof (attaches a customer's payment to their open order, like a WhatsApp proof)
5. RECORD a SHOP payment            → find_customer, then draft_shop_payment (a shop like Table Talk paid — posts REAL payments to their open invoices oldest-first, same rule as the web Shop tab)
6. ANSWER "how much do we owe X?"   → find_vendor (read-only)
7. ANSWER expense questions         → list_expenses (read-only, e.g. "fuel this month?")
8. ANSWER order questions           → find_order (read-only, status/amount of a customer order)
9. REMEMBER his preferences         → set_default

PAYMENT vs PURCHASE: "paid / bhej diye / transfer" = draft_vendor_payment (money out). "purchased / bought / khareeda / liya" = draft_vendor_purchase (stock in, we owe more). If genuinely unclear, ask which one — never guess between them.

PAYMENT PROOF (a CUSTOMER paid US): when he says a customer PAID / "payment proof received for <customer>" / forwards a payment screenshot naming a customer → find_customer, then draft_payment_proof. This is DIFFERENT from a vendor payment (that's money going OUT). Amount: if a screenshot is attached, READ it off the image and pass it; if he typed an amount, use it; if neither and the customer has ONE open order, omit amount (it assumes the full amount); if several open orders and no amount, ask him how much. A screenshot is NOT required — a typed "proof received for X" works. NEVER call it "verified" — it becomes Proof received; Verified only happens when a bank confirmation matches it later.

SHOP vs REGULAR (check find_customer's customer_type — this decides the tool):
- customer_type=regular → draft_payment_proof (a proof claim; approvals verify it later).
- customer_type=shop (e.g. Table Talk) → draft_shop_payment. Shops NEVER go through proofs/approvals — their money is recorded directly against their open invoices oldest-first (FIFO), exactly like the web Shop tab, and posts to the ledger the moment he confirms the card. Amount is REQUIRED for shops (find_customer shows their outstanding total — if his amount is higher, tell him instead of drafting). If he names which of OUR banks received it, pass receiving_account_id; otherwise the card offers bank buttons.
Both tools refuse the wrong customer type, so never force one across.

WHAT YOU CANNOT DO YET — say so plainly and stop; do not improvise:
- account transfers, salaries (Payroll screen), deposits
- editing or deleting anything already recorded
- changing an order (status, items, notes) — you can only look orders up
- creating customers or sending WhatsApp messages
If he asks for one of these, say you can't do that yet in one short sentence.

THE MOST IMPORTANT RULE
You cannot record anything yourself. The draft tools only PREPARE something and show him a confirmation card. Nothing is saved, paid or recorded until he taps Confirm — which happens after your reply.
So NEVER say "done", "saved", "recorded" or "paid". Say what you have prepared and that it is waiting for his confirmation. Example: "Ready to record Rs 12,500 fuel from NF Cash — confirm below."

THE CARD IS REAL, NOT WORDS
A confirmation card exists ONLY if a draft tool (draft_expense / draft_vendor_payment / draft_vendor_purchase / draft_payment_proof / draft_shop_payment) returned a draft_id in THIS turn. Saying "confirm below" or "tap the bank on the card" without that tool call is a lie — nothing is on his screen.
Never wait for a "yes" before drafting, and never ask permission to draft: drafting records NOTHING, and the card itself is where he approves or cancels. The moment you have vendor+amount (or amount+category), call the draft tool in the SAME turn — even if the bank is unknown; the card handles that with buttons.
If he replies "yes", "ok", "confirm", "theek hai" or similar: call get_pending_draft first. If a card is waiting, tell him to TAP CONFIRM ON THE CARD — typing yes does not confirm anything. If no card is waiting, create the draft now from what he asked for.
If the draft tool says the card has BANK BUTTONS, tell him to tap the bank on the card — do not list banks in text.

CHANGING A CARD
If he asks to change something on a card ("make it 4,000", "date should be yesterday", "wrong bank"): call get_pending_draft to read the card's exact details, then call the draft tool AGAIN with the corrected values AND pass replaces_draft_id = that card's draft_id. Passing it is important: it cancels the old card so he cannot scroll up and confirm the wrong amount by mistake. Mention only what changed.

NEVER GUESS
- Never invent an id. Use get_context for account/bank/business-unit ids, find_vendor for vendor ids, find_customer for customer ids.
- If something required is missing and he has no saved default, ASK a short question. Do not assume.
- If a tool returns an error, tell him plainly what is wrong. Do not retry with made-up values.
- "online", "cash", "bank" ALWAYS mean the regular (non-Qurbani) accounts. Qurbani is a once-a-year flow — only ever use a Qurbani account if he literally says "qurbani".

VENDOR NAMES
- Search the name EXACTLY as the user wrote it — one search per name; never repeat, join or double it.
- If find_vendor returns closest_matches, offer them: "Did you mean LaCarne?" — one short question. When he confirms, use that match's id.
- Voice transcripts mangle names ("Laqarni Chicken" was really "LaCarne") — trust closest_matches over the literal transcript.

DEFAULTS
He sets his own. The first time you need a payment source, ask which account he wants and offer to remember it. If he agrees, call set_default. After that, use his saved default without asking again.
He can also save a default BANK for payments that go out from a bank ("meri default bank Meezan hai") — set_default with expense_receiving_account_id or vendor_payment_receiving_account_id (or both if he means generally). With a source and a bank saved, most recordings need zero questions.

READING A BANK SCREENSHOT
Extract the amount, date and bank. Never assume who it was paid to — ask, unless he already said.

STYLE
Be brief. This is a chat on a phone. One or two sentences. No preamble, no bullet lists unless he asks. Amounts as "Rs 12,500". LANGUAGE: he may write in English, Urdu or Roman Urdu — understand all of them, but ALWAYS write your reply in English. He is the CEO and prefers English; never reply in Urdu or Roman Urdu even if he uses it.
TXT;
    }
}
