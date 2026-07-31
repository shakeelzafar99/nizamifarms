<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FIN\AccountModel;
use App\Services\Assistant\AssistantDraftService;
use App\Services\Assistant\BankSmsParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Bank-SMS capture (Phase 4 of NF-MESSAGES-ENHANCEMENTS-PLAN-JUL2026).
 *
 * The native SMS receiver (Phase 4b) POSTs each new bank SMS to /ingest; this
 * controller parses it (BankSmsParser — regex, no LLM), resolves the sender to
 * one of our banks, dedups, and stores it in t_ai_bank_sms as a to-sort row.
 * The inbox then turns a debit into a real DRAFT via the SAME
 * AssistantDraftService the chat uses — so an SMS-originated expense is
 * indistinguishable from a typed one and still goes through Confirm.
 *
 * NOTHING here posts to the ledger. A bank SMS is a to-do, not a transaction.
 */
class AssistantSmsController extends Controller
{
    public function __construct(
        private BankSmsParser $parser,
        private AssistantDraftService $drafts,
    ) {
    }

    /**
     * POST /api/assistant/sms/ingest — one new bank SMS from the device.
     * Idempotent: the same SMS re-posted returns the existing row.
     */
    public function ingest(Request $request)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'sender'      => 'nullable|string|max:64',
            'body'        => 'required|string|max:2000',
            'received_at' => 'nullable', // epoch millis or a date string
        ]);

        $sender = mb_strtoupper(trim((string) $request->input('sender', '')));
        $body   = (string) $request->input('body');
        $parsed = $this->parser->parse($body);

        // sms_at from the phone if given (never a DB column default — the local
        // MySQL clock differs from the app clock; see the audit note).
        $smsAt = $this->parseReceivedAt($request->input('received_at'));

        // Resolve the sender to one of our banks.
        $bank = $sender !== ''
            ? DB::table('t_ai_sms_senders')->where('sender_id', $sender)->where('is_active', 1)->first()
            : null;
        $receivingId = $bank->receiving_account_id ?? null;

        // REGISTERED BANKS ONLY (owner ruling 2026-07, live-prod feedback): the
        // device-side filter is content-based, so it also catches bank-looking
        // texts from numbers we don't bank with (promos, other banks, wallets).
        // Those are noise — if the sender isn't one of OUR seeded, active bank
        // numbers, drop the SMS entirely (store nothing, no inbox clutter, no
        // credit/debit parsing surfaced). A genuinely new bank is added under
        // Settings → Bank SMS senders, after which its SMS start flowing.
        if (!$receivingId) {
            return response()->json(['success' => true, 'skipped' => 'unregistered_sender']);
        }

        // Dedup across sender + amount + ref + day, so a re-delivered SMS (the
        // receiver can fire twice) doesn't create a second to-do.
        $dedup = sha1(implode('|', [
            $sender,
            (string) ($parsed['amount'] ?? ''),
            (string) ($parsed['reference'] ?? ''),
            $smsAt->toDateString(),
            mb_substr($body, 0, 40),
        ]));

        $existing = DB::table('t_ai_bank_sms')->where('dedup_hash', $dedup)->first();
        if ($existing) {
            return response()->json(['success' => true, 'duplicate' => true, 'sms' => $this->row($existing->id)]);
        }

        // Past the registered-sender gate above, the bank is always known.
        $status = 'new';

        $id = DB::table('t_ai_bank_sms')->insertGetId([
            'user_id'              => $user->id,
            'sender_id'            => $sender ?: null,
            'receiving_account_id' => $receivingId,
            'direction'            => $parsed['direction'],
            'amount'               => $parsed['amount'],
            'reference'            => $parsed['reference'],
            'counterparty'         => $parsed['counterparty'],
            'counterparty_account' => $parsed['counterparty_account'] ?? null,
            'raw_body'             => $body,
            'sms_at'               => $smsAt,
            'status'               => $status,
            'parse_method'         => $parsed['method'],
            'dedup_hash'           => $dedup,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        // The no-tap pipeline: auto-verify a credit against an existing proof,
        // auto-attach a mapped customer's credit, auto-ignore a remembered
        // personal merchant. Best-effort — failure leaves the SMS in the inbox.
        $auto = app(\App\Services\Assistant\BankSmsAutoActionService::class)
            ->handle(DB::table('t_ai_bank_sms')->where('id', $id)->first());

        return response()->json(['success' => true, 'sms' => $this->row($id), 'auto' => $auto]);
    }

    /**
     * POST /api/assistant/sms/{id}/draft — turn a debit SMS into a confirmation
     * card. Body: {type: expense|vendor_payment, expense_category?, vendor_id?}.
     * The amount + paying-bank come from the SMS; the category/vendor come from
     * the user's tap. Reuses AssistantDraftService so every guard still runs.
     */
    public function draft(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            'type'             => 'required|in:expense,vendor_payment,account_transfer',
            'expense_category' => 'nullable|string|max:255',
            'vendor_id'        => 'nullable|integer',
            'to_account_id'    => 'nullable|integer',
        ]);

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$sms) {
            return response()->json(['success' => false, 'message' => 'That SMS is no longer here.'], 404);
        }
        if (!in_array($sms->status, ['new', 'needs_sender'], true)) {
            return response()->json(['success' => false, 'message' => 'This SMS was already ' . $sms->status . '.'], 422);
        }
        // A credit is money IN — it can't be an expense/payment. Debit (money
        // out) and unknown (the user is asserting it's outgoing by tapping
        // Record) are both allowed.
        if ($sms->direction === 'credit') {
            return response()->json(['success' => false, 'message' => 'This looks like money received — it cannot be recorded as an expense or payment.'], 422);
        }
        if (!$sms->amount || $sms->amount <= 0) {
            return response()->json(['success' => false, 'message' => 'I could not read an amount from this SMS — record it from the assistant instead.'], 422);
        }
        if (!$sms->receiving_account_id) {
            return response()->json(['success' => false, 'message' => 'Tell me which bank this SMS is from first.', 'needs_sender' => true], 422);
        }

        // A bank-SMS debit left one of our BANK accounts, so the paying source
        // is the ONLINE (bank-category) account and the receiving bank is the
        // one this SMS came from.
        $online = AccountModel::getByCode('ONLINE');
        if (!$online) {
            return response()->json(['success' => false, 'message' => 'No ONLINE account is configured to record a bank payment against.'], 422);
        }

        $common = [
            'amount'                     => (float) $sms->amount,
            'payment_source_account_id'  => $online->id,
            'receiving_account_id'       => $sms->receiving_account_id,
            // This card originates from a bank SMS, not a chat turn — the draft
            // must NOT attach whatever image happens to be the latest chat
            // message (same rule the proof path applies via bank_sms_id).
            '_from_sms'                  => true,
        ];

        if ($request->input('type') === 'account_transfer') {
            // Money that left the bank but is STILL OURS — a rider/staff cash
            // float, NF food, another till. Not an expense (nothing was spent)
            // and not a vendor payment (nobody was owed): a ledger transfer.
            // FROM is the bank the SMS came out of, TO is what the user picked.
            $toId = (int) $request->input('to_account_id', 0);
            if (!$toId) {
                return response()->json(['success' => false, 'message' => 'Which account did the money go to?'], 422);
            }
            if ($toId === (int) $online->id) {
                return response()->json(['success' => false, 'message' => 'That is the same account the money left from.'], 422);
            }
            $result = $this->drafts->draftAccountTransfer([
                'from_account_id'      => $online->id,
                'to_account_id'        => $toId,
                'amount'               => (float) $sms->amount,
                // The SMS already names which of our banks it moved through, so
                // the card never has to ask (storeTransfer requires it).
                'receiving_account_id' => $sms->receiving_account_id,
                'mode'                 => 'online',
                'description'          => $this->smsNote($sms),
                // (no _from_sms needed: draftAccountTransfer never attaches the
                // latest chat image, unlike the expense/vendor builders.)
            ], $user);
        } elseif ($request->input('type') === 'expense') {
            $category = trim((string) $request->input('expense_category', ''));
            if ($category === '') {
                return response()->json(['success' => false, 'message' => 'What was this expense for?'], 422);
            }
            $result = $this->drafts->draftExpense($common + [
                'expense_category' => $category,
                'title'            => $category,
                'description'      => $this->smsNote($sms),
            ], $user);
        } else {
            $vendorId = (int) $request->input('vendor_id', 0);
            if (!$vendorId) {
                return response()->json(['success' => false, 'message' => 'Which vendor was paid?'], 422);
            }
            $result = $this->drafts->draftVendorPayment($common + [
                'vendor_id'   => $vendorId,
                'description' => $this->smsNote($sms),
            ], $user);
        }

        if (!empty($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        // Link + take it out of "to sort". A later cancel of this draft reverts
        // the SMS to 'new' (see AssistantDraftService::cancel).
        DB::table('t_ai_bank_sms')->where('id', $id)->update([
            'status'          => 'recorded',
            'linked_draft_id' => $result['draft_id'] ?? null,
            'updated_at'      => now(),
        ]);

        // Surface the card IN THE ASSISTANT CHAT — that's the one place Confirm
        // lives. Without an assistant message carrying this draft_id the card
        // would be orphaned (history only renders drafts attached to a message).
        if (!empty($result['draft_id'])) {
            $this->postDraftToChat($user, (int) $result['draft_id'], (string) ($result['summary'] ?? 'Ready to record'));
        }

        return response()->json([
            'success'  => true,
            'draft_id' => $result['draft_id'] ?? null,
            'message'  => 'Card ready — confirm it in the assistant.',
        ]);
    }

    /**
     * POST /api/assistant/sms/{id}/match-credit — a bank CREDIT alert (money IN)
     * becomes a customer payment-proof card. Body: {customer_id}.
     *
     * Same rules as every other proof (owner rulings): scoped to the customer's
     * Online-Approvals orders, confirm-card first, and NEVER auto-verified —
     * verification still needs the matcher's bank pairing.
     */
    public function matchCredit(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate(['customer_id' => 'required|integer']);

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$sms) {
            return response()->json(['success' => false, 'message' => 'That SMS is no longer here.'], 404);
        }
        if ($sms->direction !== 'credit') {
            return response()->json(['success' => false, 'message' => 'Only an incoming (credit) SMS can be matched to a customer payment.'], 422);
        }
        if ($sms->status !== 'new') {
            return response()->json(['success' => false, 'message' => 'This SMS was already ' . $sms->status . '.'], 422);
        }
        if (!$sms->amount || $sms->amount <= 0) {
            return response()->json(['success' => false, 'message' => 'I could not read an amount from this SMS.'], 422);
        }

        // SHOP customers take the shop-payment path (real FIFO payments, no
        // proof) — same branching rule as chat. The SMS's own resolved bank
        // pre-fills the receiving account, so the card needs zero extra taps.
        $customerId = (int) $request->input('customer_id');
        $isShop = DB::table('t_crm_prod_customer')->where('id', $customerId)->value('customer_type') === 'shop';

        $result = $isShop
            ? $this->drafts->draftShopPayment([
                'customer_id'          => $customerId,
                'amount'               => (float) $sms->amount,
                'reference'            => $sms->reference,
                'receiving_account_id' => $sms->receiving_account_id ? (int) $sms->receiving_account_id : null,
                '_amount_verified'     => true, // amount comes from the bank SMS itself
            ], $user)
            : $this->drafts->draftPaymentProof([
                'customer_id' => $customerId,
                'amount'      => (float) $sms->amount,
                'reference'   => $sms->reference,
                'bank_sms_id' => (int) $sms->id,
            ], $user);

        if (!empty($result['error'])) {
            return response()->json(['success' => false, 'message' => $result['error']], 422);
        }

        // If this SMS was amount-unique-attached to an order but Taimur picked a
        // DIFFERENT customer, the guess was wrong — detach it (back to a plain
        // held signal). The confirmed proof then pairs by reference and carries
        // the CORRECT order onto it. Same-customer confirms leave it in place.
        if ($sms->linked_signal_id) {
            $guess = \App\Models\FIN\PaymentSignal::find($sms->linked_signal_id);
            if ($guess && $guess->source === \App\Models\FIN\PaymentSignal::SOURCE_BANK_SMS
                && $guess->match_reason === 'amount_unique_sms'
                && !$guess->paired_signal_id
                && (int) $guess->matched_customer_id !== $customerId) {
                DB::table('t_fin_payment_signal_order')->where('signal_id', $guess->id)->delete();
                $guess->matched_order_id = null;
                $guess->matched_customer_id = null;
                $guess->status = \App\Models\FIN\PaymentSignal::STATUS_UNMATCHED;
                $guess->match_reason = 'bank_credit_unidentified';
                $guess->match_confidence = null;
                $guess->save();
            }
        }

        // Out of the "needs action" pile while a card is live; a cancel/expiry
        // sweeps it back to 'new' (revertOrphanedSms), and a confirm promotes
        // it to 'matched' (replayPaymentProof).
        DB::table('t_ai_bank_sms')->where('id', $id)->update([
            'status'          => 'recorded',
            'linked_draft_id' => $result['draft_id'] ?? null,
            'updated_at'      => now(),
        ]);

        if (!empty($result['draft_id'])) {
            $this->drafts->postCardToChat($user, (int) $result['draft_id'], (string) ($result['summary'] ?? 'Payment proof'), '🏦 From a bank credit SMS —');
        }

        return response()->json([
            'success'  => true,
            'draft_id' => $result['draft_id'] ?? null,
            'message'  => 'Card ready — confirm it in the assistant.',
        ]);
    }

    /** POST /api/assistant/sms/{id}/ignore */
    public function ignore(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();

        DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)
            ->whereIn('status', ['new', 'needs_sender'])
            ->update(['status' => 'ignored', 'updated_at' => now()]);

        // Ignoring a CREDIT is Taimur saying "this is not a customer payment" —
        // retract its bank signal so it can't corroborate anything later:
        //   • a plain HELD signal (unpaired, no order), and ALSO
        //   • an AMOUNT-UNIQUE GUESS (matched to an order by amount alone,
        //     still unpaired) — the ignore overrules the guess, and the blue
        //     "Bank confirmed" it put on that order must come off.
        // A signal that already PAIRED stays: that verification is real and the
        // ignore then only tidies the inbox row.
        if ($sms && $sms->direction === 'credit' && $sms->linked_signal_id) {
            $held = \App\Models\FIN\PaymentSignal::find($sms->linked_signal_id);
            if ($held && $held->source === \App\Models\FIN\PaymentSignal::SOURCE_BANK_SMS
                && !$held->paired_signal_id
                && (!$held->matched_order_id || $held->match_reason === 'amount_unique_sms')) {
                DB::table('t_fin_payment_signal_order')->where('signal_id', $held->id)->delete();
                $held->delete();
                DB::table('t_ai_bank_sms')->where('id', $id)->update(['linked_signal_id' => null]);
            }
        }

        // {remember: true} → future SMS from this merchant/account auto-ignore
        // (owner-approved Jul-20). They stay visible under the collapsed
        // "auto-ignored" row, so a wrong rule can always be caught + undone.
        $remembered = null;
        if ($request->boolean('remember') && $sms && ($sms->counterparty_account || $sms->counterparty)) {
            $mapId = app(\App\Services\Assistant\SmsCounterpartyMap::class)->save(
                $sms->counterparty_account,
                $sms->counterparty,
                'ignore',
                null,
                $sms->counterparty ?: $sms->counterparty_account,
                $user->id,
            );
            $remembered = $mapId ? ($sms->counterparty ?: $sms->counterparty_account) : null;
        }

        return response()->json(['success' => true, 'remembered' => $remembered]);
    }

    /**
     * POST /api/assistant/sms/{id}/restore — bring an auto-ignored SMS back to
     * the inbox. {forget: true} also deactivates the merchant rule that hid it,
     * so future charges there show up again.
     */
    public function restore(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();

        // Restore now also undoes an AUTOMATIC close (the reconcile sweeps), not
        // just an ignore — otherwise a wrong auto-close would be permanent, and
        // automation you cannot reverse is automation you cannot trust. A row
        // closed by a real confirmed DRAFT is excluded: that one represents
        // money actually recorded, so it is not the sweep's to undo.
        $undoable = ['already_recorded', 'already_in_ledger', 'order_approved', 'approved_in_queue'];
        $isAutoClosed = $sms
            && in_array($sms->status, ['recorded', 'matched'], true)
            && in_array((string) $sms->auto_reason, $undoable, true)
            && !$sms->linked_draft_id;

        if (!$sms || ($sms->status !== 'ignored' && !$isAutoClosed)) {
            return response()->json(['success' => false, 'message' => 'That SMS is not in the ignored or auto-handled pile.'], 422);
        }

        DB::table('t_ai_bank_sms')->where('id', $id)->update([
            'status'           => 'new',
            'auto_reason'      => null,
            // Release the ledger row so it can legitimately match another SMS.
            'linked_ledger_id' => null,
            'updated_at'       => now(),
        ]);

        // A restored auto-close must NOT be swallowed again on the next inbox
        // load. Park the ledger row it matched on an ignore-list keyed to this
        // SMS by reusing linked_ledger_id's absence + an explicit marker.
        if ($isAutoClosed && $sms->linked_ledger_id) {
            DB::table('t_ai_bank_sms')->where('id', $id)
                ->update(['auto_reason' => 'reconcile_rejected']);
        }

        // Back in the inbox = back in the pipeline — CREDITS only: a restored
        // credit re-holds/pairs exactly like a fresh ingest. Never for debits:
        // "Restore once" on a remembered merchant must not let the still-active
        // ignore rule swallow the row right back.
        if ($sms->direction === 'credit') {
            app(\App\Services\Assistant\BankSmsAutoActionService::class)
                ->handle(DB::table('t_ai_bank_sms')->where('id', $id)->first());
        }

        $forgot = false;
        if ($request->boolean('forget') && ($sms->counterparty_account || $sms->counterparty)) {
            // Look the rule up the SAME way the auto-ignore pipeline fires it
            // (account first, then unambiguous name — forSms). The old
            // byAccount-only lookup made NAME-keyed rules (banks that send no
            // account, e.g. HBL debits) un-forgettable: remember worked, the
            // rule kept auto-ignoring, but forget silently no-opped.
            $map = app(\App\Services\Assistant\SmsCounterpartyMap::class);
            $rule = $map->forSms($sms);
            if ($rule && $rule->entity_type === 'ignore') {
                $map->deactivate((int) $rule->id);
                $forgot = true;
            }
        }

        return response()->json(['success' => true, 'forgot_rule' => $forgot]);
    }

    /**
     * POST /api/assistant/sms/{id}/save-map — remember this SMS's counterparty
     * as a vendor / customer / expense category, so the next SMS from the same
     * account is recognized automatically. Body: {entity_type, entity_id?,
     * entity_label?}. Called from the "Save this for next time?" prompt after
     * a confirm, or directly from an inbox card.
     */
    public function saveMap(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate([
            // 'account' = one of OUR accounts (staff cash float, NF food, …).
            // Like vendor/expense it only ever PRE-FILLS a card — the auto-action
            // path still acts on 'ignore' alone, so no rule here can move money.
            'entity_type'  => 'required|in:vendor,customer,expense,ignore,account',
            'entity_id'    => 'nullable|integer',
            'entity_label' => 'nullable|string|max:120',
        ]);

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$sms) {
            return response()->json(['success' => false, 'message' => 'That SMS is no longer here.'], 404);
        }
        if (!$sms->counterparty_account && !$sms->counterparty) {
            return response()->json(['success' => false, 'message' => 'This SMS has no sender account or name I could remember.'], 422);
        }

        $map = app(\App\Services\Assistant\SmsCounterpartyMap::class);
        $mapId = $map->save(
            $sms->counterparty_account,
            $sms->counterparty,
            (string) $request->input('entity_type'),
            $request->filled('entity_id') ? (int) $request->input('entity_id') : null,
            $request->input('entity_label'),
            $user->id,
        );

        if (!$mapId) {
            return response()->json(['success' => false, 'message' => 'Nothing usable to remember on this SMS.'], 422);
        }

        $rule = DB::table('t_ai_counterparty_map')->where('id', $mapId)->first();
        return response()->json([
            'success' => true,
            'map_id'  => $mapId,
            'message' => 'Saved — I\'ll recognize '
                . ($sms->counterparty_account ?: $sms->counterparty)
                . ' as ' . $map->entityName($rule) . ' from now on.',
        ]);
    }

    /**
     * POST /api/assistant/sms/{id}/teach-sender — {receiving_account_id}. Maps
     * this SMS's sender to a bank, then re-resolves THIS sms and every other
     * unmapped one from the same sender (teach once, applies to all).
     */
    public function teachSender(Request $request, $id)
    {
        $user = Auth::user();
        if (!$this->allowed($user)) {
            return response()->json(['success' => false, 'message' => 'No permission'], 403);
        }

        $request->validate(['receiving_account_id' => 'required|integer']);
        $bankId = (int) $request->input('receiving_account_id');

        $sms = DB::table('t_ai_bank_sms')->where('id', $id)->where('user_id', $user->id)->first();
        if (!$sms || !$sms->sender_id) {
            return response()->json(['success' => false, 'message' => 'That SMS has no sender to teach.'], 422);
        }
        if (!DB::table('t_fin_online_receiving_accounts')->where('id', $bankId)->where('is_active', 1)->exists()) {
            return response()->json(['success' => false, 'message' => 'That bank does not exist.'], 422);
        }

        $sender = $sms->sender_id;
        $existing = DB::table('t_ai_sms_senders')->where('sender_id', $sender)->first();
        if ($existing) {
            DB::table('t_ai_sms_senders')->where('id', $existing->id)
                ->update(['receiving_account_id' => $bankId, 'is_active' => 1, 'updated_at' => now()]);
        } else {
            DB::table('t_ai_sms_senders')->insert([
                'sender_id' => $sender, 'receiving_account_id' => $bankId,
                'is_active' => 1, 'created_by' => $user->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Apply to every still-unmapped SMS from this sender (this user's).
        $flipped = DB::table('t_ai_bank_sms')
            ->where('user_id', $user->id)
            ->where('sender_id', $sender)
            ->where('status', 'needs_sender')
            ->pluck('id');
        DB::table('t_ai_bank_sms')->whereIn('id', $flipped)
            ->update(['receiving_account_id' => $bankId, 'status' => 'new', 'updated_at' => now()]);

        // The no-tap pipeline only runs at INGEST — these SMS were ingested
        // before their bank was known, so run it for them now. Without this, a
        // taught credit never auto-verifies even when its proof already waits.
        $auto = app(\App\Services\Assistant\BankSmsAutoActionService::class);
        foreach ($flipped as $fid) {
            $auto->handle(DB::table('t_ai_bank_sms')->where('id', $fid)->first());
        }

        return response()->json(['success' => true, 'sms' => $this->row($id)]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function allowed($user): bool
    {
        return $user && $user->hasMobilePermission('use_ai_assistant');
    }

    private function parseReceivedAt($value): \Illuminate\Support\Carbon
    {
        if ($value === null || $value === '') return now();
        // Epoch millis from Android's SmsMessage.getTimestampMillis().
        if (is_numeric($value)) {
            $secs = (int) ($value > 1e12 ? $value / 1000 : $value);
            // ⚠️ createFromTimestamp() defaults to UTC. The rest of the app —
            // now(), created_at, email/screenshot times — runs in the app
            // timezone (Asia/Karachi). Stored as a naive DATETIME, a UTC value
            // reads 5h behind everything else, which (a) shows wrong times in
            // the inbox and (b) makes the SMS lose every closest-time pairing
            // tie-break against an email (prod bug, Jul-2026). Create it in the
            // app timezone so sms_at is on the SAME wall clock as everyone else.
            try { return \Illuminate\Support\Carbon::createFromTimestamp($secs, config('app.timezone')); } catch (\Throwable $e) {}
        }
        try { return \Illuminate\Support\Carbon::parse((string) $value); } catch (\Throwable $e) {}
        return now();
    }

    /**
     * Drop an assistant message carrying the draft into the user's conversation
     * so the card renders in the chat (where Confirm/Cancel live). Mirrors how
     * a chat turn logs its own draft-bearing reply.
     */
    private function postDraftToChat($user, int $draftId, string $summary): void
    {
        try {
            $conversationId = DB::table('t_ai_conversations')->where('user_id', $user->id)->value('id');
            if (!$conversationId) {
                $conversationId = DB::table('t_ai_conversations')->insertGetId([
                    'user_id' => $user->id, 'title' => 'NF Assistant',
                    'last_message_at' => now(), 'created_at' => now(),
                ]);
            }
            DB::table('t_ai_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id'         => $user->id,
                'role'            => 'assistant',
                'content'         => '📩 From your bank SMS — ' . $summary . '. Tap Confirm to record it.',
                'draft_id'        => $draftId,
                'created_at'      => now(),
            ]);
            DB::table('t_ai_conversations')->where('id', $conversationId)
                ->update(['last_message_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Non-fatal: the draft still exists and the inbox already linked it.
        }
    }

    private function smsNote($sms): string
    {
        $bits = ['From bank SMS'];
        if ($sms->counterparty) $bits[] = $sms->counterparty;
        if ($sms->reference)    $bits[] = 'ref ' . $sms->reference;
        return mb_substr(implode(' · ', $bits), 0, 490);
    }

    private function row($id): array
    {
        $s = DB::table('t_ai_bank_sms as s')
            ->leftJoin('t_fin_online_receiving_accounts as b', 'b.id', '=', 's.receiving_account_id')
            ->where('s.id', $id)
            ->first(['s.id', 's.sender_id', 's.receiving_account_id', 's.direction', 's.amount',
                     's.counterparty', 's.reference', 's.status', 's.sms_at', 'b.name as bank_name']);

        return [
            'id'           => $s->id,
            'sender_id'    => $s->sender_id,
            'bank_name'    => $s->bank_name,
            'direction'    => $s->direction,
            'amount'       => $s->amount !== null ? round((float) $s->amount, 0) : null,
            'counterparty' => $s->counterparty,
            'reference'    => $s->reference,
            'status'       => $s->status,
            'needs_sender' => $s->status === 'needs_sender',
            'time'         => $s->sms_at ? substr((string) $s->sms_at, 11, 5) : null,
        ];
    }
}
