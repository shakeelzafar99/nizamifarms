<?php

namespace App\Services\Payments\Signals;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LAST RESORT ONLY — asks a language model which of a SHORT list of customers a
 * bank payer name refers to, when every deterministic rule has already failed.
 *
 * Why it exists: banks write names in ways string matching cannot reconcile —
 * spelling variants of the same family (Siddique / Siddiqui / Siddiqi all exist
 * in this customer book), nicknames, transliterations, reordered parts. Those
 * are a small minority of credits, but each one is real money sitting
 * unexplained.
 *
 * ── The three rules that keep this honest and nearly free ──────────────────
 *  1. IT NEVER SEARCHES. The candidate list is built here, from customers who
 *     already have an order that plausibly matches this payment. The model only
 *     ever picks from that list or says NONE — it cannot invent a customer, and
 *     it never sees the customer book.
 *  2. NONE IS A REAL ANSWER, and the expected one whenever the payer is a
 *     third party (a husband, a relative, an office account) — which is 16% of
 *     payments here. A model that always picks something would be worse than
 *     useless; the prompt makes refusal the safe default.
 *  3. ONE QUESTION PER CREDIT, EVER (`t_ai_bank_sms.ai_name_checked_at`). The
 *     resweep revisits held credits repeatedly; without that stamp the same
 *     unresolvable names would be re-asked forever. On the Aug-2026 backlog
 *     only 5 of 30 held credits reached this class at all.
 *
 * Its verdict is a GUESS like any other (PaymentSignal::REASON_NAME_AI): blue
 * chip, "confirm the payer", fully retractable, and it never auto-approves.
 */
class PayerNameArbiter
{
    /** Never send more than this many candidates — keeps the prompt tiny. */
    private const MAX_CANDIDATES = 6;

    /**
     * Decide who paid, or null for "cannot say".
     *
     * @param  array<int, array{customer_id:int, customer_name:string}> $candidates
     * @return array{customer_id:int, customer_name:string}|null
     */
    public function choose(?string $payerName, array $candidates): ?array
    {
        $payer = trim((string) $payerName);
        $candidates = array_values(array_slice($candidates, 0, self::MAX_CANDIDATES));

        if ($payer === '' || count($candidates) < 1) {
            return null;
        }
        // One candidate is not a choice — if the deterministic rules could not
        // commit to it, an AI rubber-stamp adds no evidence, only cost.
        if (count($candidates) < 2) {
            return null;
        }

        $cfg = (array) config('payment_signals.gemini', []);
        $apiKey = $cfg['api_key'] ?? '';
        if (!$apiKey) {
            return null;
        }

        $list = '';
        foreach ($candidates as $i => $c) {
            $list .= ($i + 1) . '. ' . $c['customer_name'] . "\n";
        }

        $prompt = <<<TXT
A bank transfer was received. The bank recorded the sender's account name as:

"{$payer}"

Below are the only customers this payment could belong to. Decide whether the
sender name refers to ONE of them.

{$list}
Answer with that customer's number, or 0 if you cannot tell.

Rules:
- Names may be spelled differently by the bank (Siddique/Siddiqui/Siddiqi),
  shortened, reordered, or carry titles such as Hafiz, Malik, Syed, Mr or Mrs.
  Those are still the same person.
- An initial may stand for a first name: "S.KHAN" can be "Sana Khan".
- People often pay for someone else (a spouse, a relative, an employee). If the
  sender name is simply a DIFFERENT person, answer 0. Do not force a match.
- If two or more candidates fit equally well, answer 0.
- When in any doubt, answer 0. Answering 0 is always safe; a wrong number
  assigns money to the wrong customer.
TXT;

        $endpoint = rtrim((string) ($cfg['base_url'] ?? 'https://generativelanguage.googleapis.com'), '/')
            . '/v1beta/models/' . ($cfg['model'] ?? 'gemini-2.5-flash') . ':generateContent';

        try {
            $response = Http::timeout((int) ($cfg['timeout'] ?? 30))
                ->withQueryParameters(['key' => $apiKey])
                ->post($endpoint, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature'      => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema'   => [
                            'type'       => 'OBJECT',
                            'properties' => [
                                'choice' => ['type' => 'INTEGER'],
                                'reason' => ['type' => 'STRING'],
                            ],
                            'required' => ['choice'],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            // The failing URL carries ?key=<API_KEY>; never let it reach a log.
            Log::warning('PayerNameArbiter: request failed', ['error' => $this->redactKey($e->getMessage())]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning('PayerNameArbiter: non-200', ['status' => $response->status()]);
            return null;
        }

        $parsed = json_decode((string) data_get($response->json(), 'candidates.0.content.parts.0.text'), true);
        $choice = (int) ($parsed['choice'] ?? 0);

        // 0 = "cannot tell", and anything outside the list is treated the same.
        if ($choice < 1 || $choice > count($candidates)) {
            return null;
        }

        $picked = $candidates[$choice - 1];
        Log::info('PayerNameArbiter matched a payer name', [
            'payer'    => $payer,
            'customer' => $picked['customer_name'],
            'reason'   => mb_substr((string) ($parsed['reason'] ?? ''), 0, 160),
        ]);
        return $picked;
    }

    private function redactKey(string $text): string
    {
        return preg_replace('/([?&]key=)[^&\s]+/i', '$1[REDACTED]', $text) ?? 'error';
    }
}
