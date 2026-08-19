<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "You just recorded a payment — is THIS the bank SMS for it?"
 *
 * The money-inbox sweep already closes a debit whose payment it can find on its
 * own (AssistantWorkspaceController::reconcileRecordedDebits). This service is
 * the same question asked from the other end, at the moment a human records the
 * payment by hand, and it exists because the sweep deliberately says nothing
 * when it is not certain:
 *   • it only closes an UNAMBIGUOUS match, so two look-alike debits stay open
 *     for ever — the person who just typed the entry is the one who knows;
 *   • it runs on money-box load, so a row can sit "unhandled" for hours after
 *     the payment it belongs to was entered;
 *   • a swept close is an inference. A human answering "yes, that's it" is
 *     evidence, and it is what makes the SMS ⇄ ledger link worth trusting.
 *
 * ⚠⚠ MATCHING BOUNDS ARE SHARED WITH THE SWEEP and must stay in step: same
 * bank, amount within Rs 1, date within a day, live rows only, and the same
 * identity rule — an SMS whose counterparty account is TAUGHT to a vendor can
 * only ever belong to that vendor's payment. If you change one, change both.
 * (Kept as two queries rather than one shared builder on purpose: the sweep is
 * money-critical, already verified, and reads SMS→ledger while this reads
 * ledger→SMS. A premature merge would have meant editing the sweep to build
 * this.)
 *
 * This service NEVER posts, reverses or edits money. Its entire authority is to
 * mark a bank SMS as handled and remember which ledger row it belongs to.
 */
class MoneyOutTagService
{
    /** How the user wants to be asked. Stored per-user in t_ai_user_prefs. */
    public const MODE_PROMPT = 'prompt';  // ask (default)
    public const MODE_AUTO   = 'auto';    // tag silently when there is exactly one
    public const MODE_OFF    = 'off';     // never ask; the sweep is the only closer

    public function mode(int $userId): string
    {
        try {
            $prefs = DB::table('t_ai_user_prefs')->where('user_id', $userId)->value('prefs_json');
            $mode = (string) (($prefs ? json_decode($prefs, true) : [])['tag_prompt_mode'] ?? self::MODE_PROMPT);
            return in_array($mode, [self::MODE_PROMPT, self::MODE_AUTO, self::MODE_OFF], true)
                ? $mode
                : self::MODE_PROMPT;
        } catch (\Throwable $e) {
            return self::MODE_PROMPT;
        }
    }

    /**
     * Open money-out SMS that could be this ledger row, newest first.
     *
     * @return array<int, array{id:int,amount:float,counterparty:?string,reference:?string,date:?string,time:?string,bank:?string}>
     */
    public function candidatesFor(int $ledgerId, int $userId): array
    {
        try {
            $ledger = DB::table('t_fin_ledger')->where('id', $ledgerId)
                ->first(['id', 'transaction_type', 'amount', 'transaction_date', 'mode', 'receiving_account_id', 'to_account_id']);

            // Only ONLINE money-out can have a bank SMS at all: cash never does,
            // and without a receiving bank there is nothing to match on.
            if (!$ledger
                || $ledger->mode !== 'online'
                || !$ledger->receiving_account_id
                || !in_array($ledger->transaction_type, ['vendor_payment', 'expense', 'transfer'], true)) {
                return [];
            }

            // Already spoken for by some SMS — nothing left to ask about.
            if (DB::table('t_ai_bank_sms')->where('linked_ledger_id', $ledgerId)->exists()) {
                return [];
            }

            $day = substr((string) $ledger->transaction_date, 0, 10);

            $rows = DB::table('t_ai_bank_sms as s')
                ->leftJoin('t_fin_online_receiving_accounts as b', 'b.id', '=', 's.receiving_account_id')
                ->where('s.user_id', $userId)
                ->where('s.status', 'new')
                ->whereIn('s.direction', ['debit', 'unknown'])
                ->where('s.receiving_account_id', $ledger->receiving_account_id)
                ->whereNull('s.linked_ledger_id')
                ->whereRaw('ABS(COALESCE(s.amount,0) - ?) <= 1', [$ledger->amount])
                ->whereRaw('ABS(DATEDIFF(DATE(s.sms_at), ?)) <= 1', [$day])
                // A row the human already pulled back from an auto-close is not
                // a candidate — they looked at it and said no.
                ->where(fn($q) => $q->whereNull('s.auto_reason')->orWhere('s.auto_reason', '<>', 'reconcile_rejected'))
                ->orderByDesc('s.sms_at')
                ->limit(10)
                ->get(['s.id', 's.amount', 's.counterparty', 's.counterparty_account',
                       's.reference', 's.sms_at', 'b.name as bank']);

            $map = app(SmsCounterpartyMap::class);
            $wantVendorId = $ledger->transaction_type === 'vendor_payment'
                ? (int) DB::table('t_fin_vendors')->where('account_id', $ledger->to_account_id)->value('id')
                : 0;

            return $rows->filter(function ($s) use ($map, $ledger, $wantVendorId) {
                // Identity veto, same rule as the sweep: a TAUGHT account
                // belongs to whoever it was taught for, and nothing else.
                // Untaught accounts stay candidates — that is exactly the case
                // a human is best placed to answer.
                if (empty($s->counterparty_account)) {
                    return true;
                }
                $rule = $map->byAccount($s->counterparty_account);
                if (!$rule || empty($rule->entity_id)) {
                    return true;
                }
                if ($rule->entity_type === 'vendor') {
                    return $wantVendorId > 0 && (int) $rule->entity_id === $wantVendorId;
                }
                if ($rule->entity_type === 'account') {
                    return $ledger->transaction_type === 'transfer'
                        && (int) $rule->entity_id === (int) $ledger->to_account_id;
                }
                // An expense-taught account can only be an expense.
                return $ledger->transaction_type === 'expense';
            })->map(fn($s) => [
                'id'           => (int) $s->id,
                'amount'       => round((float) $s->amount, 0),
                'counterparty' => $s->counterparty,
                'reference'    => $s->reference,
                'date'         => $s->sms_at ? substr((string) $s->sms_at, 0, 10) : null,
                'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
                'bank'         => $s->bank,
            ])->values()->all();
        } catch (\Throwable $e) {
            // A prompt that cannot be built must never break the save that
            // triggered it — the money is already recorded correctly.
            Log::warning('[MoneyOutTag] candidates: ' . $e->getMessage(), ['ledger' => $ledgerId]);
            return [];
        }
    }

    /**
     * "Yes, that's it" — close the SMS against this ledger row.
     *
     * Deliberately re-checks that the SMS is still open and the row still
     * unclaimed: the prompt may have been sitting on someone's screen while the
     * sweep, another device, or a clubbed confirm dealt with the same row.
     *
     * @return array{ok:bool, message:string}
     */
    public function tag(int $smsId, int $ledgerId, int $userId): array
    {
        try {
            $sms = DB::table('t_ai_bank_sms')->where('id', $smsId)->where('user_id', $userId)->first();
            if (!$sms) {
                return ['ok' => false, 'message' => 'That bank SMS is no longer here.'];
            }
            if ($sms->status !== 'new') {
                return ['ok' => false, 'message' => 'That SMS was already ' . $sms->status . '.'];
            }
            if (!DB::table('t_fin_ledger')->where('id', $ledgerId)->exists()) {
                return ['ok' => false, 'message' => 'That ledger entry no longer exists.'];
            }
            if (DB::table('t_ai_bank_sms')->where('linked_ledger_id', $ledgerId)->exists()) {
                return ['ok' => false, 'message' => 'Another bank SMS is already filed against that entry.'];
            }

            DB::table('t_ai_bank_sms')->where('id', $smsId)->update([
                'status'           => 'recorded',
                'auto_reason'      => 'entry_tagged',
                'linked_ledger_id' => $ledgerId,
                'updated_at'       => now(),
            ]);

            return ['ok' => true, 'message' => 'Filed against this entry — it has left your money box.'];
        } catch (\Throwable $e) {
            Log::warning('[MoneyOutTag] tag: ' . $e->getMessage(), ['sms' => $smsId, 'ledger' => $ledgerId]);
            return ['ok' => false, 'message' => 'Could not file that bank SMS.'];
        }
    }

    /**
     * What a just-saved entry should tell the UI: nothing, a question, or "I
     * filed it". Honours the user's mode, and in AUTO only acts on a single
     * unambiguous candidate — several is exactly when a human should choose,
     * whatever the setting says.
     *
     * @return array{action:string, ledger_id:int, candidates:array, message?:string}|null
     */
    public function afterEntry(int $ledgerId, int $userId): ?array
    {
        $mode = $this->mode($userId);
        if ($mode === self::MODE_OFF) {
            return null;
        }

        $candidates = $this->candidatesFor($ledgerId, $userId);
        if (empty($candidates)) {
            return null;
        }

        if ($mode === self::MODE_AUTO && count($candidates) === 1) {
            $done = $this->tag((int) $candidates[0]['id'], $ledgerId, $userId);
            return $done['ok']
                ? ['action' => 'tagged', 'ledger_id' => $ledgerId, 'candidates' => $candidates,
                   'message' => 'Bank SMS for Rs ' . number_format($candidates[0]['amount'], 0)
                              . ' filed against this entry.']
                : null;
        }

        return ['action' => 'ask', 'ledger_id' => $ledgerId, 'candidates' => $candidates];
    }
}
