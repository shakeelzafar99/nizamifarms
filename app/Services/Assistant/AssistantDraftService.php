<?php

namespace App\Services\Assistant;

use App\Models\FIN\AccountModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Drafts, and the confirm-time replay. This is the file that makes "the AI
 * cannot move money" literally true.
 *
 * ═══ TWO HALVES ═══
 *
 * 1. draft*()   — called BY the model's tools. Validates and resolves ids, then
 *                 writes ONE row to t_ai_drafts. Touches no money. If anything
 *                 is missing or wrong it returns an error to the model so it
 *                 can ask a better question.
 *
 * 2. confirm()  — called by a HUMAN tapping Confirm. Replays the stored payload
 *                 through the EXISTING controllers:
 *                     expense        → Request\RequestController@store
 *                     vendor_payment → FIN\VendorController@recordPayment
 *                 We build a real Request and call the real method AS the real
 *                 user. That means every guard the business already relies on
 *                 still runs — approval config (an expense IS a t_req_master
 *                 request), the vendor-balance cap, the mandatory receiving
 *                 bank, the private-account rule, the Staff-Salaries block,
 *                 the backdate limit. **We re-implement none of it, so none of
 *                 it can drift.**
 *
 * That's also why an AI-created expense that needs L1/L2 approval still lands
 * in the same pending queue as any other. The assistant is a faster way to fill
 * the form — not a way around it.
 */
class AssistantDraftService
{
    // ── DRAFTING (model-facing; writes only to t_ai_drafts) ──────────────────

    public function draftExpense(array $args, $user): array
    {
        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        $category = trim((string) ($args['expense_category'] ?? ''));
        if ($category === '') {
            return ['error' => 'I need to know what the expense was for.'];
        }

        // Mirror the server's hard block early so the user gets a clear answer
        // from the assistant instead of a rejection after confirming.
        if (in_array(mb_strtolower($category), ['staff salaries', 'staff salary'], true)) {
            return ['error' => 'Staff salaries must be paid from the Payroll screen — I cannot record them as an expense.'];
        }

        $prefs = $this->prefs($user->id);
        $sourceId = (int) ($args['payment_source_account_id'] ?? $prefs['expense_payment_source_account_id'] ?? 0);
        if (!$sourceId) {
            return ['error' => 'Which account should this be paid from? Call get_context for the list, ask the user, and offer to remember it as their default.'];
        }

        $source = AccountModel::find($sourceId);
        if (!$source || !$source->is_active) {
            return ['error' => 'That payment account does not exist. Use get_context for valid ids.'];
        }

        // A bank source needs to say WHICH bank — the same rule the expense and
        // vendor-payment endpoints enforce. Resolution order: what they said →
        // their saved default → a ONE-TAP picker on the card itself (owner ask:
        // "if he forgets the bank, the card shows the options"). No text
        // question, no extra model call.
        //
        // Gated on the source being a BANK: a cash expense must never carry (or
        // display) a bank row, even when a saved bank default exists. The server
        // nulls a stray receiving id on cash anyway (store() checks the source
        // category) — but the card is what the user confirms, so it must not lie.
        $receivingId = $source->account_category === 'bank'
            ? (int) ($args['receiving_account_id'] ?? $prefs['expense_receiving_account_id'] ?? 0)
            : 0;
        $needsBankChoice = $source->account_category === 'bank' && !$receivingId;

        $businessUnitId = (int) ($args['business_unit_id'] ?? $prefs['expense_business_unit_id'] ?? 0) ?: null;
        $date = $this->cleanDate($args['expense_date'] ?? null);
        if ($date && $date > now()->toDateString()) {
            return ['error' => 'An expense date cannot be in the future.'];
        }

        $title = trim((string) ($args['title'] ?? '')) ?: $category;

        // category_id 3 = "expense" (Expense Reimbursement, form_type=expense).
        // Khaas (13) / Qurbani (14) are deliberately NOT reachable in A1 — they
        // carry their own BU + permission rules and deserve their own thinking.
        $choice = $needsBankChoice ? $this->bankChoice() : null;
        if ($needsBankChoice && !$choice) {
            return ['error' => 'No banks are configured, so I cannot pay this from a bank account. Suggest a cash source instead.'];
        }

        $payload = array_filter([
            'category_id' => 3,
            'title' => $title,
            'description' => trim((string) ($args['description'] ?? '')) ?: null,
            'amount' => round($amount, 2),
            'expense_category' => $category,
            'payment_source_account_id' => $sourceId,
            'receiving_account_id' => $receivingId ?: null,
            'business_unit_id' => $businessUnitId,
            'expense_date' => $date,
            '_pending_choice' => $choice,
        ], fn($v) => $v !== null);

        $display = array_values(array_filter([
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Category', 'value' => $category],
            ['label' => 'Paid from', 'value' => $source->account_name],
            $receivingId ? ['label' => 'Bank', 'value' => $this->bankName($receivingId)] : null,
            $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
            $businessUnitId ? ['label' => 'Business unit', 'value' => $this->buName($businessUnitId)] : null,
            ['label' => 'Date', 'value' => $date ?: now()->toDateString()],
        ]));

        // Never for SMS-originated cards: the "latest chat image" belongs to
        // some other conversation turn, not to this bank SMS.
        if (empty($args['_from_sms'])) {
            [$payload, $display] = $this->attachChatImage($payload, $display, $user);
        }

        return $this->store($user, 'expense',
            'Record expense: Rs ' . number_format($amount, 0) . ' — ' . $category,
            $payload, $display, $this->replacesId($args));
    }

    /**
     * Money RECEIVED from a SHOP customer — recorded as REAL order payments
     * (FIFO oldest-first via the shared ShopBulkPaymentService, the exact
     * service behind the web Shop tab and the mobile bulk endpoint), NOT as a
     * payment proof: shop invoices never sit in Online Approvals, and their
     * ledger entries post AUTO-APPROVED + settled the moment this is executed.
     * That is precisely why this card is never auto-confirmed — Taimur's
     * Confirm IS the approval gate for this money.
     *
     * Invoice selection mirrors the web Shop tab (ApprovalController::
     * getOnlineShopItems): delivered, online-method, not booked under the
     * regular invoice flow, with a balance — oldest first (the service's own
     * FIFO key), taking the smallest set the amount can cover per the bulk
     * rule. The service RE-VALIDATES everything against LIVE balances at
     * confirm (row locks, per-invoice balance, range rule), so a long-lived
     * card can reject but can never mis-post.
     */
    public function draftShopPayment(array $args, $user): array
    {
        $customerId = (int) ($args['customer_id'] ?? 0);
        $customer = $customerId
            ? DB::table('t_crm_prod_customer')->where('id', $customerId)
                ->first(['id', 'first_name', 'last_name', 'company', 'customer_type'])
            : null;
        if (!$customer) {
            return ['error' => 'That customer id does not exist. Use find_customer first — never guess a customer id.'];
        }
        $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''))
            ?: ($customer->company ?: ('Customer #' . $customerId));

        if (($customer->customer_type ?? 'regular') !== 'shop') {
            return ['error' => $name . ' is a REGULAR customer — record money received from them as a payment proof (draft_payment_proof). Shop payments are only for shop customers.'];
        }

        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need the amount received (greater than zero).'];
        }

        // AMOUNT PROVENANCE GUARD (same rule as proofs — Fahim Rs-100): unless
        // this draft's amount came from a server-side source (a bank SMS via
        // matchCredit/raiseShopCard, or a forwarded screenshot — those callers
        // pass _amount_verified), it must appear in the user's own recent words.
        // Shop payments post REAL ledger money on confirm, so a hallucinated
        // amount here is worse than anywhere else.
        if (empty($args['_amount_verified'])
            && !$this->amountAppearsInTurn((int) $user->id, $amount)) {
            return ['error' => 'I could not find Rs ' . number_format($amount, 0)
                . ' anywhere in what the user actually said — NEVER guess an amount. Ask him how much the shop paid, then call again with what he answers.'];
        }

        $invoices = $this->openShopInvoices($customerId);
        if (empty($invoices)) {
            return ['error' => $name . ' has no open shop invoices right now — nothing to record this payment against. Double-check the shop name.'];
        }

        // Selection in integer paisa (same math as the service): oldest-first,
        // smallest prefix the amount fits into. sum(prefix-1) < T <= sum(prefix).
        $amountCents = (int) round($amount * 100);
        $sumAllCents = array_sum(array_column($invoices, 'cents'));
        if ($amountCents > $sumAllCents) {
            return ['error' => 'Rs ' . number_format($amount, 0) . ' is MORE than ' . $name
                . '\'s total outstanding (Rs ' . number_format($sumAllCents / 100, 0)
                . ' across ' . count($invoices) . ' invoice(s)). Confirm the amount with the user.'];
        }
        $selected = [];
        $run = 0;
        foreach ($invoices as $inv) {
            $selected[] = $inv;
            $run += $inv['cents'];
            if ($run >= $amountCents) {
                break;
            }
        }

        // Per-invoice allocation for the card — the same rule the service will
        // apply (all but the newest in full; the newest takes the remainder).
        $n = count($selected);
        $lines = [];
        $before = 0;
        foreach ($selected as $i => $inv) {
            $slice = $i < $n - 1 ? $inv['cents'] : ($amountCents - $before);
            $before += $inv['cents'];
            $lines[] = $inv['order_number'] . ' Rs ' . number_format($slice / 100, 0)
                . ($slice < $inv['cents'] ? ' (partial — Rs ' . number_format(($inv['cents'] - $slice) / 100, 0) . ' left)' : '');
        }

        // The receiving bank is MANDATORY for shop payments (online-only money).
        // Explicit (e.g. from the credit SMS's own bank) → done; else a one-tap
        // picker on the card — never a dead-end question.
        $receivingId = (int) ($args['receiving_account_id'] ?? 0);
        $needsBankChoice = !$receivingId;
        $choice = $needsBankChoice ? $this->bankChoice('Which bank received it?') : null;
        if ($needsBankChoice && !$choice) {
            return ['error' => 'No receiving banks are configured — a shop payment cannot be recorded without one.'];
        }

        $payload = array_filter([
            'customer_id' => $customerId,
            'order_ids' => array_column($selected, 'id'),
            'amount' => round($amount, 2),
            'receiving_account_id' => $receivingId ?: null,
            'reference' => trim((string) ($args['reference'] ?? '')) ?: null,
            '_pending_choice' => $choice,
        ], fn($v) => $v !== null);

        $display = array_values(array_filter([
            ['label' => 'Shop', 'value' => $name],
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Applied to', 'value' => implode('  ·  ', $lines)],
            $receivingId ? ['label' => 'Bank', 'value' => $this->bankName($receivingId)] : null,
            $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
            !empty($payload['reference']) ? ['label' => 'Reference', 'value' => $payload['reference']] : null,
            ['label' => 'Outstanding after', 'value' => 'Rs ' . number_format(($sumAllCents - $amountCents) / 100, 0)],
        ]));

        return $this->store($user, 'shop_payment',
            'Shop payment ' . $name . ': Rs ' . number_format($amount, 0),
            $payload, $display, $this->replacesId($args));
    }

    /**
     * The shop's payable invoices, EXACTLY as the web Shop tab defines them
     * (ApprovalController::getOnlineShopItems — delivered, online method, no
     * approved/pending_l2 invoice booked under the regular flow) and sorted by
     * the SAME FIFO key ShopBulkPaymentService allocates by (delivery_date
     * falling back to order_date, tie-broken by id — delivery_date is a model
     * accessor, so the sort happens in PHP on loaded models).
     * Returns [{id, order_number, cents}] oldest-first, balance > 0 only.
     */
    private function openShopInvoices(int $customerId): array
    {
        $onlineMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment'];

        $orders = \App\Models\CRM\OrderModel::query()
            ->where('customer_id', $customerId)
            ->whereIn('payment_method', $onlineMethods)
            ->where('order_status', 'delivered')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('t_fin_ledger')
                    ->whereColumn('t_fin_ledger.order_id', 't_crm_prod_order.id')
                    ->where('t_fin_ledger.transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
                    ->whereIn('t_fin_ledger.approval_status', [
                        \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                        \App\Models\FIN\LedgerModel::STATUS_PENDING_L2,
                    ]);
            })
            ->get();

        $fifoKey = function ($o): int {
            $d = $o->delivery_date ?: $o->order_date;
            if (!$d) {
                return 0;
            }
            try {
                return \Carbon\Carbon::parse($d)->timestamp;
            } catch (\Throwable $e) {
                return optional($o->order_date)->timestamp ?? 0;
            }
        };

        $rows = [];
        foreach ($orders as $o) {
            $cents = (int) round(((float) $o->total_price - (float) ($o->total_paid ?? 0)) * 100);
            if ($cents <= 0) {
                continue;
            }
            $rows[] = ['id' => (int) $o->id, 'order_number' => $o->order_number, 'cents' => $cents, '_k' => $fifoKey($o)];
        }
        usort($rows, fn($a, $b) => $a['_k'] !== $b['_k'] ? ($a['_k'] <=> $b['_k']) : ($a['id'] <=> $b['id']));
        return array_map(fn($r) => ['id' => $r['id'], 'order_number' => $r['order_number'], 'cents' => $r['cents']], $rows);
    }

    /**
     * Move money between OUR OWN accounts (e.g. Online bank → Cash, HBL → Meezan)
     * — recorded through the SAME web flow (LedgerController@storeTransfer,
     * TYPE_TRANSFER). Mirrors its rules exactly: the two accounts must differ,
     * a transfer touching a bank must name which bank it goes through, and — the
     * key rule — an ONLINE transfer goes to the approval queue (STATUS_PENDING)
     * while a cash transfer posts immediately (STATUS_APPROVED). The assistant
     * NEVER bypasses that: Taimur's Confirm is the equivalent of submitting the
     * web form; approval still happens where it always did.
     *
     * NOT a bank-tag correction of an already-recorded payment — that is editing
     * a posted entry, which the assistant does not do. This only creates a NEW
     * transfer between accounts.
     */
    public function draftAccountTransfer(array $args, $user): array
    {
        $fromId = (int) ($args['from_account_id'] ?? 0);
        $toId   = (int) ($args['to_account_id'] ?? 0);
        if (!$fromId || !$toId) {
            return ['error' => 'I need both a FROM account and a TO account. Call get_context for the ids.'];
        }
        if ($fromId === $toId) {
            return ['error' => 'The from and to accounts must be different.'];
        }
        $from = AccountModel::find($fromId);
        $to   = AccountModel::find($toId);
        if (!$from || !$from->is_active) {
            return ['error' => 'That FROM account does not exist. Use get_context for valid ids.'];
        }
        if (!$to || !$to->is_active) {
            return ['error' => 'That TO account does not exist. Use get_context for valid ids.'];
        }

        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        // A transfer touching a BANK account must name which of OUR banks it
        // goes through (storeTransfer enforces this) — resolve it, or offer the
        // one-tap picker so the card is never a dead-end.
        $touchesBank = $from->account_category === 'bank' || $to->account_category === 'bank';
        $receivingId = $touchesBank ? (int) ($args['receiving_account_id'] ?? 0) : 0;
        $needsBankChoice = $touchesBank && !$receivingId;
        $choice = $needsBankChoice ? $this->bankChoice('Which bank does this transfer go through?') : null;
        if ($needsBankChoice && !$choice) {
            return ['error' => 'No banks are configured, so I cannot record a bank transfer.'];
        }

        // Mode drives the approval flow, exactly as the web: a transfer touching
        // a bank is ONLINE (→ approval queue); a pure cash-to-cash move is CASH
        // (→ posts immediately). The user may override with an explicit mode.
        $mode = strtolower(trim((string) ($args['mode'] ?? '')));
        if (!in_array($mode, ['cash', 'online'], true)) {
            $mode = $touchesBank ? 'online' : 'cash';
        }

        $date = $this->cleanDate($args['transaction_date'] ?? null) ?: now()->toDateString();
        $desc = trim((string) ($args['description'] ?? ''))
            ?: ('Transfer ' . $from->account_name . ' -> ' . $to->account_name);

        $payload = array_filter([
            'from_account_id' => $fromId,
            'to_account_id' => $toId,
            'amount' => round($amount, 2),
            'mode' => $mode,
            'receiving_account_id' => $receivingId ?: null,
            'transaction_date' => $date,
            'description' => $desc,
            '_pending_choice' => $choice,
        ], fn($v) => $v !== null);

        $display = array_values(array_filter([
            ['label' => 'From', 'value' => $from->account_name],
            ['label' => 'To', 'value' => $to->account_name],
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            $receivingId ? ['label' => 'Bank', 'value' => $this->bankName($receivingId)] : null,
            $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
            ['label' => 'Date', 'value' => $date],
            ['label' => 'On confirm', 'value' => $mode === 'online'
                ? 'Goes to approval (online transfer)'
                : 'Posts immediately (cash transfer)'],
        ]));

        return $this->store($user, 'account_transfer',
            'Transfer Rs ' . number_format($amount, 0) . ': ' . $from->account_name . ' -> ' . $to->account_name,
            $payload, $display, $this->replacesId($args));
    }

    public function draftVendorPayment(array $args, $user): array
    {
        $vendorId = (int) ($args['vendor_id'] ?? 0);
        $vendor = $vendorId
            ? DB::table('t_fin_vendors as v')
                ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
                ->where('v.id', $vendorId)
                ->first(['v.id', 'v.vendor_name', 'v.business_unit_id', 'a.current_balance'])
            : null;

        if (!$vendor) {
            return ['error' => 'That vendor id does not exist. Use find_vendor first — never guess an id.'];
        }

        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        // The server caps a payment at the outstanding balance. Surfacing it
        // here turns a post-confirm rejection into a useful sentence.
        $balance = round((float) ($vendor->current_balance ?? 0), 2);
        if ($amount > $balance) {
            return ['error' => 'That is more than we owe ' . $vendor->vendor_name
                . ' (outstanding Rs ' . number_format($balance, 0) . '). The server will reject it. Confirm the amount with the user.'];
        }

        $prefs = $this->prefs($user->id);
        $sourceId = (int) ($args['payment_source_account_id'] ?? $prefs['vendor_payment_source_account_id'] ?? 0);
        if (!$sourceId) {
            return ['error' => 'Which account should this be paid from? Call get_context, ask the user, and offer to remember it.'];
        }

        $source = AccountModel::find($sourceId);
        if (!$source || !$source->is_active) {
            return ['error' => 'That payment account does not exist. Use get_context for valid ids.'];
        }

        // Same resolution order as expenses: explicit → saved default → one-tap
        // picker on the card. Never a dead-end text question. And same gate:
        // receiving only exists when the source IS a bank (recordPayment nulls
        // it on cash; the card must match what will actually be recorded).
        $receivingId = $source->account_category === 'bank'
            ? (int) ($args['receiving_account_id'] ?? $prefs['vendor_payment_receiving_account_id'] ?? 0)
            : 0;
        $needsBankChoice = $source->account_category === 'bank' && !$receivingId;

        $choice = $needsBankChoice ? $this->bankChoice() : null;
        if ($needsBankChoice && !$choice) {
            return ['error' => 'No banks are configured, so I cannot pay this from a bank account. Suggest a cash source instead.'];
        }

        $date = $this->cleanDate($args['transaction_date'] ?? null) ?: now()->toDateString();

        $payload = array_filter([
            'vendor_id' => $vendorId,
            'amount' => round($amount, 2),
            'payment_source_account_id' => $sourceId,
            'receiving_account_id' => $receivingId ?: null,
            'transaction_date' => $date,
            'description' => trim((string) ($args['description'] ?? '')) ?: null,
            '_pending_choice' => $choice,
        ], fn($v) => $v !== null);

        $display = array_values(array_filter([
            ['label' => 'Vendor', 'value' => $vendor->vendor_name],
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Paid from', 'value' => $source->account_name],
            $receivingId ? ['label' => 'Bank', 'value' => $this->bankName($receivingId)] : null,
            $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
            ['label' => 'Date', 'value' => $date],
            ['label' => 'Outstanding after', 'value' => 'Rs ' . number_format($balance - $amount, 0)],
        ]));

        if (empty($args['_from_sms'])) {
            [$payload, $display] = $this->attachChatImage($payload, $display, $user);
        }

        return $this->store($user, 'vendor_payment',
            'Pay ' . $vendor->vendor_name . ': Rs ' . number_format($amount, 0),
            $payload, $display, $this->replacesId($args));
    }

    /**
     * A purchase = stock bought from a vendor. It only increases what we owe
     * (t_fin_ledger vendor_purchase via VendorController@recordPurchase) — no
     * money moves, so there is no source account, bank, or balance cap here.
     */
    public function draftVendorPurchase(array $args, $user): array
    {
        $vendorId = (int) ($args['vendor_id'] ?? 0);
        $vendor = $vendorId
            ? DB::table('t_fin_vendors as v')
                ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
                ->where('v.id', $vendorId)
                ->first(['v.id', 'v.vendor_name', 'a.current_balance'])
            : null;

        if (!$vendor) {
            return ['error' => 'That vendor id does not exist. Use find_vendor first — never guess an id.'];
        }

        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        $date = $this->cleanDate($args['transaction_date'] ?? null) ?: now()->toDateString();
        if ($date > now()->toDateString()) {
            return ['error' => 'A purchase date cannot be in the future.'];
        }

        $balance = round((float) ($vendor->current_balance ?? 0), 2);

        $payload = array_filter([
            'vendor_id' => $vendorId,
            'amount' => round($amount, 2),
            'transaction_date' => $date,
            'description' => trim((string) ($args['description'] ?? '')) ?: null,
        ], fn($v) => $v !== null);

        $display = [
            ['label' => 'Vendor', 'value' => $vendor->vendor_name],
            ['label' => 'Purchase amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Date', 'value' => $date],
            ['label' => 'We will owe them', 'value' => 'Rs ' . number_format($balance + $amount, 0)],
        ];

        [$payload, $display] = $this->attachChatImage($payload, $display, $user);

        return $this->store($user, 'vendor_purchase',
            'Purchase from ' . $vendor->vendor_name . ': Rs ' . number_format($amount, 0),
            $payload, $display, $this->replacesId($args));
    }

    /**
     * A customer PAYMENT PROOF. Builds a WhatsApp-type screenshot signal (the
     * amount + the customer Taimur named + any attached screenshot) that, on
     * confirm, runs through the EXISTING PaymentSignalMatcher and shows in
     * Online Approvals as "proof received". Verification (→ Verified) still
     * requires a bank confirmation via the matcher's pairing — a forward alone
     * never verifies (owner ruling 2026-07-19).
     */
    public function draftPaymentProof(array $args, $user): array
    {
        $customerId = (int) ($args['customer_id'] ?? 0);
        $customer = $customerId
            ? DB::table('t_crm_prod_customer')->where('id', $customerId)->first(['id', 'first_name', 'last_name', 'customer_type'])
            : null;
        if (!$customer) {
            return ['error' => 'That customer id does not exist. Use find_customer first — never guess a customer id.'];
        }
        $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: ('Customer #' . $customerId);

        // SHOP money never goes through the proof/approvals flow — their
        // invoices aren't in Online Approvals at all; payments are recorded
        // directly (FIFO) via draft_shop_payment. Hard wall, both directions.
        if (($customer->customer_type ?? 'regular') === 'shop') {
            return ['error' => $name . ' is a SHOP customer — money received from shops is recorded with draft_shop_payment (real FIFO payments), never as a payment proof.'];
        }

        $matcher = app(\App\Services\Payments\Signals\PaymentSignalMatcher::class);
        $amount = (float) ($args['amount'] ?? 0);

        // Owner ruling 2026-07-19: a proof may only attach to an order that is
        // still in the Online Approvals queue (its invoice awaiting approval) —
        // not every order the customer happens to still owe for. Scope the
        // matcher to exactly those orders.
        $onlyIds = $this->approvalsQueueOrderIds($customerId);
        if (empty($onlyIds)) {
            return ['error' => $name . ' has no invoices awaiting approval to attach a proof to — check Online Approvals.'];
        }

        // Amount-agnostic scan first, so we can apply the single-order rule.
        // A zero-balance order (already settled) is never a proof target — drop
        // it so the "ask which order" list and the single-order rule are clean.
        $scan = $matcher->preview($customerId, $amount > 0 ? $amount : 0.01, null, $onlyIds);
        $open = array_values(array_filter($scan['open_orders'] ?? [], fn($o) => ($o['balance'] ?? 0) > 0.01));
        if (count($open) === 0) {
            return ['error' => $name . ' has no open invoice with a balance in Online Approvals — nothing to record.'];
        }

        // Owner rule: one open order → assume its full amount; several → ask.
        if ($amount <= 0) {
            if (count($open) === 1) {
                $amount = (float) $open[0]['balance'];
            } else {
                $list = implode(', ', array_map(
                    fn($o) => $o['order_number'] . ' (Rs ' . number_format($o['balance'], 0) . ')', $open));
                return ['error' => $name . ' has ' . count($open) . ' open orders: ' . $list
                    . '. Ask how much the payment was (or which order), then call again with the amount.'];
            }
        }

        $preview = $matcher->preview($customerId, $amount, null, $onlyIds);
        // Where the proof came from decides both the image and the card's label:
        //  • explicit image_path  → forwarded to the business WhatsApp (5c)
        //  • bank_sms_id          → a bank CREDIT alert (5b): no image, and we
        //    must NOT grab an unrelated screenshot out of the chat
        //  • otherwise            → the screenshot he just sent the assistant
        $fromSms = !empty($args['bank_sms_id']);
        $imagePath = ($args['image_path'] ?? null)
            ?: ($fromSms ? null : $this->latestProofImage((int) $user->id));
        $reference = trim((string) ($args['reference'] ?? '')) ?: null;

        // AMOUNT PROVENANCE GUARD (Fahim Rs-100): a model-passed amount with no
        // screenshot and no bank SMS must exist in the user's own recent words,
        // or we refuse and make the model ASK instead of guessing. The assumed
        // single-open-order amount above is server-computed and exempt.
        if ((float) ($args['amount'] ?? 0) > 0 && !$fromSms && !$imagePath
            && !$this->amountAppearsInTurn((int) $user->id, (float) $args['amount'])) {
            return ['error' => 'I could not find Rs ' . number_format((float) $args['amount'], 0)
                . ' anywhere in what the user actually said — NEVER guess an amount. Ask him how much the payment was, then call again with what he answers.'];
        }
        $proofLabel = $fromSms
            ? 'Bank credit SMS'
            : ($imagePath
                ? (!empty($args['image_path']) ? 'Forwarded screenshot' : 'Screenshot attached')
                : 'No screenshot (your word)');

        $display = array_values(array_filter([
            ['label' => 'Customer', 'value' => $name],
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Proof', 'value' => $proofLabel],
            ['label' => 'Will attach to', 'value' => $this->describeProofTarget($preview)],
            $reference ? ['label' => 'Reference', 'value' => $reference] : null,
        ]));

        $payload = array_filter([
            'customer_id'   => $customerId,
            'customer_name' => $name,
            'amount'        => round($amount, 2),
            'reference'     => $reference,
            'image_path'    => $imagePath,
            // Carried so the confirm can close the loop on the credit SMS that
            // started this (mark it matched + stamp the signal id).
            'bank_sms_id'   => ($args['bank_sms_id'] ?? null) ?: null,
        ], fn($v) => $v !== null);

        return $this->store($user, 'payment_proof',
            'Payment proof for ' . $name . ': Rs ' . number_format($amount, 0),
            $payload, $display, $this->replacesId($args));
    }

    /** The card being corrected, if the model declared one. */
    private function replacesId(array $args): ?int
    {
        return ((int) ($args['replaces_draft_id'] ?? 0)) ?: null;
    }

    /**
     * Put a card into the user's assistant chat so it has a home (that's where
     * Confirm/Cancel live) — used by cards the MODEL didn't produce in a turn:
     * bank-SMS cards and forwarded payment proofs. Also makes them show up in
     * the money inbox's "waiting for your confirm" list.
     */
    public function postCardToChat($user, int $draftId, string $summary, string $prefix = '📩'): void
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
                'content'         => $prefix . ' ' . $summary . '. Tap Confirm to record it.',
                'draft_id'        => $draftId,
                'created_at'      => now(),
            ]);
            DB::table('t_ai_conversations')->where('id', $conversationId)
                ->update(['last_message_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Non-fatal: the card exists and the inbox will still list it.
            Log::warning('postCardToChat failed', ['draft' => $draftId, 'error' => $e->getMessage()]);
        }
    }

    /** A plain assistant message (used when a forwarded proof needs a question). */
    public function postMessageToChat($user, string $text): void
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
                'conversation_id' => $conversationId, 'user_id' => $user->id,
                'role' => 'assistant', 'content' => $text, 'created_at' => now(),
            ]);
            DB::table('t_ai_conversations')->where('id', $conversationId)
                ->update(['last_message_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('postMessageToChat failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param int|null $replacesDraftId  The card this one CORRECTS ("make it
     *   4,000"). That card is cancelled here so it can't still be confirmed
     *   from further up the chat — the real mis-tap risk. Deliberately
     *   EXPLICIT: several pending cards are a legitimate state (one per bank
     *   SMS, or an expense + a vendor payment), so we never guess which to kill.
     */
    private function store($user, string $type, string $summary, array $payload, array $display, ?int $replacesDraftId = null): array
    {
        // Payment-proof cards live much longer (owner ask: he forwards a proof
        // and reviews later, not instantly). Safe: confirm re-runs the matcher
        // FRESH at confirm time, so a day-old card can't act on stale numbers.
        // Shop-payment cards get the same long TTL for the same review-later
        // workflow (SMS-raised cards) — equally safe, because the service
        // re-locks and re-validates LIVE balances at confirm: a stale card can
        // reject, but it can never double-pay or mis-post.
        // Other money-moving cards keep the short TTL — their numbers ARE the draft.
        $ttl = in_array($type, ['payment_proof', 'shop_payment'], true)
            ? (int) config('assistant.proof_draft_ttl_minutes', 1440)
            : (int) config('assistant.draft_ttl_minutes', 15);

        $id = DB::table('t_ai_drafts')->insertGetId([
            'user_id' => $user->id,
            'type' => $type,
            'status' => 'pending',
            'summary' => $summary,
            'payload_json' => json_encode($payload),
            'display_json' => json_encode($display),
            'expires_at' => now()->addMinutes($ttl),
            'created_at' => now(),
        ]);

        // Supersede the card this one corrects, so the OLD amount can no longer
        // be confirmed by scrolling up. Any bank SMS is re-pointed at the new
        // card first — otherwise cancelling would bounce it back to "to sort"
        // while a live card for it exists.
        if ($replacesDraftId) {
            $old = DB::table('t_ai_drafts')
                ->where('id', $replacesDraftId)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->first();
            if ($old) {
                DB::table('t_ai_bank_sms')
                    ->where('linked_draft_id', $old->id)
                    ->update(['linked_draft_id' => $id, 'updated_at' => now()]);
                DB::table('t_ai_drafts')->where('id', $old->id)->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => now(),
                    'error'        => 'Replaced by a corrected card.',
                    'updated_at'   => now(),
                ]);
            }
        }

        $note = 'NOTHING has been recorded yet. The user now sees a confirmation card and must tap Confirm. Tell them what you have prepared and that it is waiting for their confirmation — do NOT say it is saved, paid or recorded.';
        if (!empty($payload['_pending_choice'])) {
            $note = 'NOTHING has been recorded yet. The card is on screen with BANK BUTTONS — the user must first tap which bank it went from, then tap Confirm. Tell them to pick the bank on the card. Do NOT list the banks in text and do NOT say it is saved.';
        }

        return [
            'draft_id' => $id,
            'status' => 'awaiting_confirmation',
            'summary' => $summary,
            'shown_to_user' => $display,
            // The model must not claim the thing is done. It isn't.
            'note' => $note,
        ];
    }

    // ── CONFIRM (human-facing; the ONLY path that writes money) ──────────────

    /**
     * Replay a draft through the real endpoint, as the real user.
     * @return array{ok: bool, message: string, draft: object|null}
     */
    public function confirm(int $draftId, $user): array
    {
        $draft = DB::table('t_ai_drafts')->where('id', $draftId)->first();

        if (!$draft)                       return ['ok' => false, 'message' => 'That confirmation is no longer available.'];
        // Ownership: a draft can only ever be confirmed by the person it was
        // drafted for. Not "who is logged in" — who it belongs to.
        if ((int) $draft->user_id !== (int) $user->id) {
            return ['ok' => false, 'message' => 'That confirmation belongs to someone else.'];
        }
        if ($draft->status !== 'pending')  return ['ok' => false, 'message' => 'This was already ' . $draft->status . '.'];
        if ($draft->expires_at && now()->gt($draft->expires_at)) {
            DB::table('t_ai_drafts')->where('id', $draftId)->update(['status' => 'expired', 'updated_at' => now()]);
            // An SMS-originated card that expired must NOT swallow its SMS —
            // put it back in the money inbox's to-sort pile (same as cancel).
            DB::table('t_ai_bank_sms')
                ->where('linked_draft_id', $draftId)
                ->where('status', 'recorded')
                ->update(['status' => 'new', 'linked_draft_id' => null, 'updated_at' => now()]);
            return ['ok' => false, 'message' => 'This confirmation expired — please ask me again so I can check the latest numbers.'];
        }

        $payload = json_decode($draft->payload_json, true) ?: [];

        // A card still waiting on its bank buttons is not confirmable — the
        // whole point of the picker is that the money can't move without a bank.
        if (!empty($payload['_pending_choice'])) {
            return ['ok' => false, 'message' => 'Pick which bank it went from first — the options are on the card.'];
        }

        // Underscore-prefixed keys are card metadata, never form fields.
        $payload = array_filter($payload, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);

        try {
            $result = match ($draft->type) {
                'expense'         => $this->replayExpense($payload, $user),
                'vendor_payment'  => $this->replayVendorPayment($payload, $user),
                'vendor_purchase' => $this->replayVendorPurchase($payload, $user),
                'payment_proof'   => $this->replayPaymentProof($payload, $user),
                'shop_payment'    => $this->replayShopPayment($payload, $user),
                'account_transfer' => $this->replayAccountTransfer($payload, $user),
                default           => ['ok' => false, 'message' => 'Unsupported draft type.'],
            };
        } catch (\Throwable $e) {
            Log::error('Assistant confirm failed', ['draft' => $draftId, 'error' => $e->getMessage()]);
            DB::table('t_ai_drafts')->where('id', $draftId)->update([
                'status' => 'failed', 'error' => $e->getMessage(), 'updated_at' => now(),
            ]);
            return ['ok' => false, 'message' => 'Could not record that: ' . $e->getMessage()];
        }

        if (!($result['ok'] ?? false)) {
            // The existing endpoint refused (e.g. balance moved since drafting,
            // or a permission the drafter didn't model). Keep the draft usable
            // so the user can adjust rather than start over.
            DB::table('t_ai_drafts')->where('id', $draftId)->update([
                'error' => $result['message'] ?? 'rejected', 'updated_at' => now(),
            ]);
            return $result;
        }

        DB::table('t_ai_drafts')->where('id', $draftId)->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'result_type' => $result['result_type'] ?? null,
            'result_id' => $result['result_id'] ?? null,
            'result_json' => isset($result['result_json']) ? json_encode($result['result_json']) : null,
            'error' => null,
            'updated_at' => now(),
        ]);

        // A confirmed payment-proof card closes the loop on the credit SMS
        // linked to it: 'recorded' → 'matched' + the signal id. Keyed on
        // linked_draft_id (not the payload) so it also works when this card
        // REPLACED the one the SMS originally produced.
        if (($result['result_type'] ?? null) === 'payment_signal' && !empty($result['result_id'])) {
            DB::table('t_ai_bank_sms')
                ->where('linked_draft_id', $draftId)
                ->where('status', 'recorded')
                ->update([
                    'status'           => 'matched',
                    'linked_signal_id' => $result['result_id'],
                    'updated_at'       => now(),
                ]);
        }

        // A payment recorded from CHAT usually has a bank SMS for the very same
        // transaction sitting unsorted in the money inbox (Taimur typed it out
        // instead of tapping the SMS). Adopt that SMS onto this draft so it
        // leaves the to-sort pile — and so the remember-prompt below can offer
        // its account key. Conservative: exact bank + amount, one candidate only.
        $this->adoptMatchingSms($draftId, $draft->type, $payload, $user);

        // "Save this for next time?" — when the confirmed draft came from a bank
        // SMS whose counterparty isn't mapped yet, offer to remember the account
        // → entity link so the next SMS from it is recognized automatically.
        // (Prompt-first per the owner's ruling; the client shows a one-tap ask.)
        $prompt = $this->rememberPromptFor($draftId, $draft->type, $payload);
        if ($prompt) {
            $result['remember_prompt'] = $prompt;
        }

        return $result;
    }

    /**
     * Link a just-confirmed money-OUT draft to the bank SMS that describes the
     * same transaction, when the draft did not come from an SMS in the first
     * place (i.e. Taimur recorded it by typing in the chat).
     *
     * WHY: the SMS inbox and the chat never spoke to each other, so every
     * chat-recorded bank payment left its own SMS sitting in "needs sorting"
     * forever (observed in prod: the Jul-27 Imran Qureshi payment was recorded
     * from chat while its Meezan SMS stayed unsorted). Adopting the SMS both
     * clears the pile AND lets rememberPromptFor() offer that SMS's account key
     * — so a typed payment still teaches the system the vendor's account.
     *
     * SAFETY — this only ever re-labels an SMS row; it moves no money and posts
     * nothing. It still refuses unless the match is unambiguous:
     *   • money-out drafts only (expense / vendor_payment),
     *   • the draft must have a receiving bank — a CASH payment has no bank SMS,
     *     and `receiving_account_id` is present in the payload only when the
     *     paying source was a bank account,
     *   • same bank, amount within Rs 1,
     *   • the SMS landed within a day either side of the date the payment is
     *     BOOKED FOR — not of "now". Taimur routinely records a payment a day
     *     or two after making it (a now()-anchored window silently missed the
     *     real Jul-27 Imran payment when confirmed on Jul-29),
     *   • EXACTLY ONE candidate. Two same-amount debits on the same bank in
     *     that span is precisely where a wrong guess would mislabel a vendor,
     *     so ambiguity does nothing and the rows stay in the inbox for the
     *     human. Widening the window therefore fails safe: more ambiguity means
     *     more no-ops, never more wrong guesses.
     */
    private function adoptMatchingSms(int $draftId, string $draftType, array $payload, $user): void
    {
        try {
            if (!in_array($draftType, ['expense', 'vendor_payment', 'account_transfer'], true)) {
                return;
            }
            $bankId = (int) ($payload['receiving_account_id'] ?? 0);
            $amount = (float) ($payload['amount'] ?? 0);
            if ($bankId <= 0 || $amount <= 0) {
                return; // cash payment (no bank SMS exists) or nothing to match
            }
            // Already SMS-originated → it has its SMS; never steal a second one.
            if (DB::table('t_ai_bank_sms')->where('linked_draft_id', $draftId)->exists()) {
                return;
            }

            // vendor_payment carries transaction_date, expense carries
            // expense_date; both are 'Y-m-d'. Fall back to today.
            $anchor = $payload['transaction_date'] ?? $payload['expense_date'] ?? null;
            try {
                $anchor = $anchor ? \Illuminate\Support\Carbon::parse($anchor) : now();
            } catch (\Throwable) {
                $anchor = now();
            }

            $candidates = DB::table('t_ai_bank_sms')
                ->where('user_id', $user->id)
                ->where('status', 'new')
                ->whereIn('direction', ['debit', 'unknown'])
                ->where('receiving_account_id', $bankId)
                ->whereRaw('ABS(COALESCE(amount,0) - ?) <= 1', [$amount])
                ->where('sms_at', '>=', $anchor->copy()->subDay()->startOfDay())
                ->where('sms_at', '<=', $anchor->copy()->addDay()->endOfDay())
                ->limit(2)
                ->get(['id']);

            if ($candidates->count() !== 1) {
                return;
            }

            DB::table('t_ai_bank_sms')->where('id', $candidates->first()->id)->update([
                'status'          => 'recorded',
                'linked_draft_id' => $draftId,
                'updated_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            // Housekeeping only — a failure here must never affect the confirm.
            Log::warning('[adoptMatchingSms] ' . $e->getMessage(), ['draft' => $draftId]);
        }
    }

    /**
     * Build the save-mapping prompt for a just-confirmed, SMS-originated draft:
     * {sms_id, account, name, entity_type, entity_id, entity_label, ask} — or
     * null (no SMS, nothing identifiable, or already mapped).
     */
    private function rememberPromptFor(int $draftId, string $draftType, array $payload): ?array
    {
        try {
            $sms = DB::table('t_ai_bank_sms')->where('linked_draft_id', $draftId)->first();
            if (!$sms || (!$sms->counterparty_account && !$sms->counterparty)) {
                return null;
            }

            [$entityType, $entityId, $entityLabel] = match ($draftType) {
                'payment_proof',
                'shop_payment'    => ['customer', (int) ($payload['customer_id'] ?? 0) ?: null, null],
                'vendor_payment',
                'vendor_purchase' => ['vendor', (int) ($payload['vendor_id'] ?? 0) ?: null, null],
                'expense'         => ['expense', null, $payload['category'] ?? $payload['expense_category'] ?? null],
                // A transfer teaches the DESTINATION ("this account is always
                // Qasim's float"). from_account_id is always our own bank here,
                // so it carries no information worth remembering.
                'account_transfer' => ['account', (int) ($payload['to_account_id'] ?? 0) ?: null, null],
                default           => [null, null, null],
            };
            if (!$entityType || (!$entityId && !$entityLabel)) {
                return null;
            }

            // Already mapped to the SAME entity → nothing to ask. Mapped to a
            // DIFFERENT one → still ask (re-teach is a valid correction).
            $existing = app(SmsCounterpartyMap::class)->byAccount($sms->counterparty_account);
            if ($existing && $existing->entity_type === $entityType
                && (int) $existing->entity_id === (int) $entityId
                && ($entityId || $existing->entity_label === $entityLabel)) {
                return null;
            }

            $who = $entityId
                ? app(SmsCounterpartyMap::class)->entityName((object) ['entity_type' => $entityType, 'entity_id' => $entityId, 'entity_label' => null])
                : $entityLabel;
            $identity = $sms->counterparty_account ?: $sms->counterparty;

            return [
                'sms_id'       => (int) $sms->id,
                'account'      => $sms->counterparty_account,
                'name'         => $sms->counterparty,
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'entity_label' => $entityLabel,
                'ask'          => 'Remember ' . $identity . ' as ' . $who . '? Next time I\'ll recognize it automatically.',
            ];
        } catch (\Throwable) {
            return null; // the prompt is a nicety — never break a confirm over it
        }
    }

    /**
     * One-tap answer to a card's pending choice (today: which bank). Writes the
     * chosen id into the payload, rewrites the card's Bank row, clears the
     * pending-choice meta — after which Confirm works normally. No model call.
     */
    public function choose(int $draftId, $user, int $optionId): array
    {
        $draft = DB::table('t_ai_drafts')->where('id', $draftId)->where('user_id', $user->id)->first();
        if (!$draft) return ['ok' => false, 'message' => 'Not found.'];
        if ($draft->status !== 'pending') return ['ok' => false, 'message' => 'Already ' . $draft->status . '.'];
        if ($draft->expires_at && now()->gt($draft->expires_at)) {
            DB::table('t_ai_drafts')->where('id', $draftId)->update(['status' => 'expired', 'updated_at' => now()]);
            return ['ok' => false, 'message' => 'This card expired — ask me again.'];
        }

        $payload = json_decode($draft->payload_json, true) ?: [];
        $choice = $payload['_pending_choice'] ?? null;
        if (!$choice) {
            return ['ok' => false, 'message' => 'This card is not waiting for a choice.'];
        }

        $picked = collect($choice['options'] ?? [])->firstWhere('id', $optionId);
        if (!$picked) {
            return ['ok' => false, 'message' => 'That is not one of the options on this card.'];
        }

        $payload[$choice['field']] = (int) $picked['id'];
        unset($payload['_pending_choice']);

        $display = json_decode($draft->display_json, true) ?: [];
        $rewrote = false;
        foreach ($display as &$row) {
            if (($row['label'] ?? '') === 'Bank') {
                $row['value'] = $picked['name'];
                $rewrote = true;
                break;
            }
        }
        unset($row);
        if (!$rewrote) {
            $display[] = ['label' => 'Bank', 'value' => $picked['name']];
        }

        DB::table('t_ai_drafts')->where('id', $draftId)->update([
            'payload_json' => json_encode($payload),
            'display_json' => json_encode($display),
            'updated_at' => now(),
        ]);

        return ['ok' => true, 'message' => $picked['name'] . ' selected — tap Confirm to record it.'];
    }

    public function cancel(int $draftId, $user): array
    {
        $draft = DB::table('t_ai_drafts')->where('id', $draftId)->where('user_id', $user->id)->first();
        if (!$draft) return ['ok' => false, 'message' => 'Not found.'];
        if ($draft->status !== 'pending') return ['ok' => false, 'message' => 'Already ' . $draft->status . '.'];

        DB::table('t_ai_drafts')->where('id', $draftId)
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);

        // If this draft came from a bank SMS (Phase 4), put that SMS back in the
        // "to sort" pile — cancelling the card means it still needs handling, so
        // it must not silently vanish from the money inbox.
        DB::table('t_ai_bank_sms')
            ->where('linked_draft_id', $draftId)
            ->where('status', 'recorded')
            ->update(['status' => 'new', 'linked_draft_id' => null, 'updated_at' => now()]);

        return ['ok' => true, 'message' => 'Cancelled — nothing was recorded.'];
    }

    /**
     * Build a real Request and hand it to the real controller.
     *
     * Why not duplicate the logic here: expenses are t_req_master rows whose
     * approval requirements, ledger posting (LedgerPostingService) and balance
     * updates (BalancePostingService) all live behind this one method. Calling
     * it means an assistant expense is indistinguishable from a hand-typed one
     * — including sitting in the approval queue when the category demands it.
     */
    /**
     * PROVENANCE STANDARD (owner ruling Jul-2026): every transaction the
     * assistant records must carry a human-visible "via NF Assistant" marker in
     * its description/notes, so ANY screen that lists it (ledger, expenses,
     * requests, vendor history, reports) shows where it came from — with zero
     * per-screen work. The structured trace (t_ai_drafts.result_type/result_id)
     * exists alongside for the Assistant View. Apply this to every future
     * replay too.
     */
    private function stampAssistant(array $payload, string $key = 'description'): array
    {
        $existing = trim((string) ($payload[$key] ?? ''));
        $payload[$key] = $existing === '' ? 'via NF Assistant'
            : (str_contains($existing, 'NF Assistant') ? $existing : $existing . ' · via NF Assistant');
        return $payload;
    }

    private function replayExpense(array $payload, $user): array
    {
        $payload = $this->stampAssistant($payload);
        // Forward the attached screenshot exactly like the web bill-photo upload
        // (RequestController@store reads 'attachment_image'). No image → posts as
        // before. Must run BEFORE array_filter so attachment_path is stripped.
        $files = $this->attachmentFiles($payload, 'attachment_image');
        $request = Request::create('/requests', 'POST', array_filter($payload, fn($v) => $v !== null), [], $files);
        $request->setUserResolver(fn() => $user); // acts AS Taimur, not as "the system"

        // ⚠️ RequestController@store resolves the actor via the GLOBAL auth()
        // helper — `auth()->user()` — not $request->user(). So setUserResolver
        // alone is not enough: its permission checks (payment-source rules,
        // private accounts, backdate limits) would hit null and throw.
        // In a real /assistant/confirm request auth() already holds this same
        // user, so this is a no-op there; setting it explicitly makes the
        // dependency visible and keeps the path testable outside HTTP.
        $this->actAs($user);

        $controller = app(\App\Http\Controllers\Request\RequestController::class);
        $response = $controller->store($request);
        $data = json_decode($response->getContent(), true) ?: [];

        if (!($data['success'] ?? false)) {
            return ['ok' => false, 'message' => $data['message'] ?? 'The expense was rejected.'];
        }

        $requestId = $data['request']['id'] ?? $data['request_id'] ?? null;

        return [
            'ok' => true,
            'message' => $data['message'] ?? 'Expense recorded.',
            'result_type' => 'request',
            'result_id' => $requestId,
            'result_json' => $data,
        ];
    }

    private function replayVendorPayment(array $payload, $user): array
    {
        $payload = $this->stampAssistant($payload);
        $vendorId = $payload['vendor_id'];
        unset($payload['vendor_id']); // it's a route param, not a body field

        // Forward the attached screenshot the same way the web form does —
        // recordPayment reads 'receipt_image'. Strips attachment_path first.
        $files = $this->attachmentFiles($payload, 'receipt_image');

        // ⚠️ recordPayment() returns JSON only when
        //      $request->expectsJson() || $request->is('api/*')
        // and otherwise REDIRECTS to the vendor page. Every one of its exit
        // points branches that way — including the rejections we most need to
        // read (balance exceeded, missing receiving bank). Build the request on
        // the api/* path AND send Accept: application/json so we always get the
        // JSON branch, and never mistake a redirect for silent success.
        $request = Request::create(
            "/api/vendors/{$vendorId}/payment",
            'POST',
            array_filter($payload, fn($v) => $v !== null),
            [], $files, ['HTTP_ACCEPT' => 'application/json']
        );
        $request->setUserResolver(fn() => $user);
        $this->actAs($user); // same reason as replayExpense — the controllers use auth()

        $controller = app(\App\Http\Controllers\FIN\VendorController::class);
        $response = $controller->recordPayment($request, $vendorId);

        $data = method_exists($response, 'getContent')
            ? (json_decode($response->getContent(), true) ?: [])
            : [];

        if (($data['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => $data['message'] ?? 'The payment was rejected.'];
        }

        return [
            'ok' => true,
            'message' => $data['message'] ?? 'Payment recorded.',
            'result_type' => 'ledger',
            'result_id' => $data['transaction_id'] ?? $data['ledger_id'] ?? null,
            'result_json' => $data,
        ];
    }

    private function replayVendorPurchase(array $payload, $user): array
    {
        $payload = $this->stampAssistant($payload);
        $vendorId = $payload['vendor_id'];
        unset($payload['vendor_id']); // route param, not a body field

        // recordPurchase reads 'bill_image'. Strips attachment_path first.
        $files = $this->attachmentFiles($payload, 'bill_image');

        // Same api/*-path + Accept:json contract as replayVendorPayment —
        // recordPurchase branches to a redirect otherwise.
        $request = Request::create(
            "/api/vendors/{$vendorId}/purchase",
            'POST',
            array_filter($payload, fn($v) => $v !== null),
            [], $files, ['HTTP_ACCEPT' => 'application/json']
        );
        $request->setUserResolver(fn() => $user);
        $this->actAs($user);

        $controller = app(\App\Http\Controllers\FIN\VendorController::class);
        $response = $controller->recordPurchase($request, $vendorId);

        $data = method_exists($response, 'getContent')
            ? (json_decode($response->getContent(), true) ?: [])
            : [];

        if (($data['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => $data['message'] ?? 'The purchase was rejected.'];
        }

        return [
            'ok' => true,
            'message' => $data['message'] ?? 'Purchase recorded.',
            'result_type' => 'ledger',
            'result_id' => $data['transaction_id'] ?? $data['ledger_id'] ?? null,
            'result_json' => $data,
        ];
    }

    /**
     * Commit a payment proof: create a WhatsApp-type PaymentSignal and run it
     * through the real matcher. Attaches to the customer's open order(s) exactly
     * like a customer-sent screenshot and lands in Online Approvals for the
     * normal L1/L2 verify. Never verifies here — that stays with bank pairing.
     */
    /**
     * Execute a confirmed shop payment through the SHARED ShopBulkPaymentService
     * — the identical code path behind the web Shop tab and the mobile bulk
     * endpoint, so FIFO allocation, validation, ledger rows and balance posting
     * cannot drift. The service takes the acting user directly (Taimur), locks
     * the orders, and re-validates live balances; any business-rule failure
     * surfaces verbatim as the card's error.
     */
    private function replayShopPayment(array $payload, $user): array
    {
        try {
            $result = app(\App\Services\Payments\ShopBulkPaymentService::class)->execute([
                'order_ids'            => $payload['order_ids'] ?? [],
                'amount'               => $payload['amount'] ?? 0,
                'receiving_account_id' => $payload['receiving_account_id'] ?? null,
                'reference'            => $payload['reference'] ?? null,
                'notes'                => 'Recorded via NF Assistant',
            ], $user);
        } catch (\App\Exceptions\ShopBulkPaymentException $e) {
            // Business-rule failure (stale balances, overpayment, …) — safe and
            // useful to show verbatim; the user just asks again for a fresh card.
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $parts = collect($result['allocations'] ?? [])
            ->map(fn($a) => $a['order_number'] . ($a['fully_paid']
                ? ' cleared'
                : ' Rs ' . number_format($a['balance_after'], 0) . ' still due'))
            ->implode(', ');

        return [
            'ok'          => true,
            'message'     => 'Recorded Rs ' . number_format($result['total_amount'], 0)
                . ' from ' . $result['customer']
                . ($result['bank'] ? ' via ' . $result['bank'] : '')
                . ' — ' . $parts . '. Posted to the ledger (auto-approved, like every shop payment).',
            'result_type' => 'shop_bulk_payment',
            'result_id'   => null,
            'result_json' => $result,
        ];
    }

    /**
     * Execute a confirmed account transfer through the web LedgerController@
     * storeTransfer — the SAME code the web transfer form posts to, so the
     * approval flow (online → pending, cash → posted), the bank tag, and the
     * balance maths are all inherited, never re-implemented.
     *
     * ⚠️ storeTransfer is a WEB action: it REDIRECTS (no JSON) and throws
     * ValidationException on a bad request. So success is detected by the NEW
     * transfer ledger row it commits, not by a response body.
     */
    private function replayAccountTransfer(array $payload, $user): array
    {
        $payload = $this->stampAssistant($payload); // description gets "· via NF Assistant"

        $request = Request::create('/finance/ledger/transfer', 'POST',
            array_filter($payload, fn($v) => $v !== null));
        $request->setUserResolver(fn() => $user);
        $this->actAs($user); // storeTransfer uses auth()->id() for created_by

        $before = (int) (\App\Models\FIN\LedgerModel::max('id') ?? 0);
        $controller = app(\App\Http\Controllers\FIN\LedgerController::class);
        try {
            $controller->storeTransfer($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?: 'The transfer was rejected.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'The transfer could not be recorded: ' . $e->getMessage()];
        }

        // storeTransfer redirects; success = the transfer row it committed.
        $ledger = \App\Models\FIN\LedgerModel::where('id', '>', $before)
            ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_TRANSFER)
            ->where('created_by', $user->id)
            ->where('from_account_id', $payload['from_account_id'])
            ->where('to_account_id', $payload['to_account_id'])
            ->orderByDesc('id')
            ->first();

        if (!$ledger) {
            // The only non-validation failure in storeTransfer is a missing bank
            // tag — which the draft's picker prevents. Anything else is a rare
            // rollback; report it plainly rather than a false "done".
            return ['ok' => false, 'message' => 'The transfer was not recorded — please check the bank selection and try again.'];
        }

        $pending = $ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_PENDING;
        return [
            'ok' => true,
            'message' => $pending
                ? 'Transfer created — it is now pending approval (online transfers go through approval, same as the web).'
                : 'Transfer completed and posted to the ledger.',
            'result_type' => 'ledger',
            'result_id' => $ledger->id,
            'result_json' => ['approval_status' => $ledger->approval_status],
        ];
    }

    private function replayPaymentProof(array $payload, $user): array
    {
        // How the proof reached us, encoded on the signal so the web approvals
        // modal can show provenance ("Recorded via NF Assistant …") instead of
        // implying a customer sent it. Kept in extractor_version — no schema
        // change, and nothing keys on the exact assistant value.
        $method = !empty($payload['bank_sms_id']) ? 'credit_sms'
            : (!empty($payload['image_path']) ? 'screenshot' : 'typed');

        $signal = \App\Models\FIN\PaymentSignal::create([
            'source'                 => \App\Models\FIN\PaymentSignal::SOURCE_WHATSAPP,
            'image_path'             => $payload['image_path'] ?? null,
            'extracted_amount'       => $payload['amount'],
            'extracted_ref'          => $payload['reference'] ?? null,
            'extracted_sender_name'  => $payload['customer_name'] ?? null,
            'extracted_txn_datetime' => now(),
            'matched_customer_id'    => $payload['customer_id'],
            'extractor_version'      => 'assistant_' . $method . '@v1',
            'status'                 => \App\Models\FIN\PaymentSignal::STATUS_NEW,
        ]);

        // Scope the match to the customer's Approvals-queue orders — the SAME
        // restriction the draft previewed with, so confirm can't attach to an
        // order the card didn't show (owner ruling 2026-07-19).
        $onlyIds = $this->approvalsQueueOrderIds((int) $payload['customer_id']);
        app(\App\Services\Payments\Signals\PaymentSignalMatcher::class)->match($signal, $onlyIds);
        $signal->refresh();

        $orderNo = $signal->matched_order_id
            ? DB::table('t_crm_prod_order')->where('id', $signal->matched_order_id)->value('order_number')
            : null;

        $ok = $signal->status !== \App\Models\FIN\PaymentSignal::STATUS_UNMATCHED;
        // NOTE: the credit-SMS loop (marking the SMS matched + stamping the
        // signal id) is closed in confirm() via linked_draft_id, NOT here via
        // the payload — a card that REPLACED the original ("actually it's for
        // customer Y") has no bank_sms_id in its payload, but the supersede
        // logic re-pointed the SMS's linked_draft_id at it, so the link is the
        // one identity that survives corrections.
        $msg = match ($signal->status) {
            \App\Models\FIN\PaymentSignal::STATUS_MATCHED =>
                'Proof received on ' . ($orderNo ?: 'the order') . '. It now shows in Online Approvals — Verified once a bank confirmation matches it.',
            \App\Models\FIN\PaymentSignal::STATUS_AMOUNT_MISMATCH =>
                'Proof received, but the amount doesn\'t exactly match an open order — attached to ' . ($orderNo ?: 'the newest order') . ' and flagged for review in Online Approvals.',
            \App\Models\FIN\PaymentSignal::STATUS_DUPLICATE =>
                'This proof looks like a duplicate of one already recorded — nothing new was added.',
            default =>
                'Recorded, but I could not attach it to an order — please check Online Approvals.',
        };

        return [
            'ok'          => $ok,
            'message'     => $msg,
            'result_type' => 'payment_signal',
            'result_id'   => $signal->id,
            'result_json' => ['status' => $signal->status, 'order_number' => $orderNo],
        ];
    }

    /**
     * The customer's orders that are currently in the Online Approvals queue —
     * i.e. whose invoice ledger entry is still awaiting approval (pending /
     * pending_l1 / pending_l2). A proof only ever attaches to one of these
     * (owner ruling 2026-07-19). Empty = nothing to attach to.
     */
    public function approvalsQueueOrderIds(int $customerId): array
    {
        return DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->where('o.customer_id', $customerId)
            ->where('l.transaction_type', 'invoice')
            ->whereIn('l.approval_status', ['pending', 'pending_l1', 'pending_l2'])
            ->distinct()
            ->pluck('l.order_id')
            ->map(fn($id) => (int) $id)
            ->all();
    }

    /** Where a previewed proof would land, for the confirmation card. */
    private function describeProofTarget(array $preview): string
    {
        $nums = array_map(fn($o) => $o['order_number'], $preview['orders'] ?? []);
        return match ($preview['status'] ?? '') {
            'matched'         => $nums ? ('Order ' . $nums[0]) : 'the matching order',
            'combined'        => 'Split across ' . implode(' + ', $nums),
            'ambiguous'       => 'the newest open order (several could match — review in approvals)',
            'amount_mismatch' => 'the newest open order (amount differs — flagged for review)',
            default           => 'an open order',
        };
    }

    /**
     * The screenshot the user just sent, if any — the most recent image in
     * their assistant conversation, but only within the last few minutes so an
     * OLD screenshot never silently attaches to a typed proof.
     */
    private function latestProofImage(int $userId): ?string
    {
        $convId = DB::table('t_ai_conversations')->where('user_id', $userId)->value('id');
        if (!$convId) return null;

        $row = DB::table('t_ai_messages')
            ->where('conversation_id', $convId)
            ->where('role', 'user')
            ->whereNotNull('media_path')
            ->where(function ($w) {
                $w->where('input_type', 'image')->orWhere('media_path', 'like', '%img-%');
            })
            ->orderByDesc('id')
            ->first(['media_path', 'created_at']);

        if (!$row || !$row->media_path) return null;
        if ($row->created_at && \Carbon\Carbon::parse($row->created_at)->lt(now()->subMinutes(10))) {
            return null;
        }
        return $row->media_path;
    }

    /**
     * The image sent WITH THIS TURN's message, for attaching to an expense /
     * vendor payment / purchase (owner ask: "attach the screenshot wherever you
     * post"). Deliberately stricter than latestProofImage: it takes the LATEST
     * user message and only returns its image — so recording an expense with NO
     * image in its message never grabs a stale screenshot from earlier in the
     * chat. Also skips a file over the controllers' 5 MB limit (so the card's
     * "📎 attached" hint and the real attach can never disagree).
     * The chat controller logs this turn's user message (with media_path)
     * BEFORE the agent runs the tools, so the "latest" row IS this turn's.
     */
    private function currentTurnImage(int $userId): ?string
    {
        $convId = DB::table('t_ai_conversations')->where('user_id', $userId)->value('id');
        if (!$convId) return null;

        $row = DB::table('t_ai_messages')
            ->where('conversation_id', $convId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->first(['media_path', 'input_type', 'created_at']);

        if (!$row || !$row->media_path) return null;
        // Must be an IMAGE (not a voice note's audio path).
        if (($row->input_type ?? '') !== 'image' && !str_contains($row->media_path, 'img-')) {
            return null;
        }
        if ($row->created_at && \Carbon\Carbon::parse($row->created_at)->lt(now()->subMinutes(10))) {
            return null;
        }
        try {
            $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
            if (!$disk->exists($row->media_path) || $disk->size($row->media_path) > 5 * 1024 * 1024) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        return $row->media_path;
    }

    /**
     * Append the current-turn screenshot (if any) to a draft's payload+display,
     * so at confirm the replay can forward it to the underlying controller as a
     * real uploaded file. Used by expense / vendor-payment / vendor-purchase
     * drafts. Returns [payload, display].
     */
    private function attachChatImage(array $payload, array $display, $user): array
    {
        $img = $this->currentTurnImage((int) $user->id);
        if ($img) {
            $payload['attachment_path'] = $img;           // non-underscore: survives to replay
            $display[] = ['label' => 'Attachment', 'value' => '📎 Screenshot'];
        }
        return [$payload, $display];
    }

    /**
     * Turn a stored image path into the $files array Request::create() needs, so
     * a replayed controller sees it exactly like a browser upload. Removes
     * attachment_path from $payload (it is NOT a form field). Returns [] when
     * there is no usable image, so the record still posts (just without the
     * attachment). $field = the controller's file input name.
     */
    private function attachmentFiles(array &$payload, string $field): array
    {
        $path = $payload['attachment_path'] ?? null;
        unset($payload['attachment_path']);
        if (!$path) {
            return [];
        }
        try {
            $disk = Storage::disk(config('whatsapp.media_disk', 'public'));
            if (!$disk->exists($path)) {
                return [];
            }
            $abs = $disk->path($path);
            $uploaded = new \Illuminate\Http\UploadedFile(
                $abs,
                basename($abs),
                mime_content_type($abs) ?: 'image/jpeg',
                null,
                true, // $test = synthetic (not a real HTTP upload) → isValid()/store() work
            );
            return [$field => $uploaded];
        } catch (\Throwable $e) {
            \Log::warning('[assistant attachment] ' . $e->getMessage(), ['path' => $path]);
            return [];
        }
    }

    /**
     * PROVENANCE GUARD FOR AMOUNTS (Fahim Rs-100 incident, 2026-07-21): the
     * model invented `amount: 100` when the user typed "payment received" with
     * NO amount — a prompt rule alone cannot stop a silent hallucination, so
     * the server verifies it. An amount the model passes must appear in the
     * user's own recent words (typed text or voice transcript, last 3 user
     * messages, digit-normalized so "15,050" == 15050). Callers exempt the
     * cases with a REAL non-chat source: a screenshot (the model read it off
     * the image), a bank SMS (server-side amount), or a server-computed value.
     */
    private function amountAppearsInTurn(int $userId, float $amount): bool
    {
        $convId = DB::table('t_ai_conversations')->where('user_id', $userId)->value('id');
        if (!$convId) {
            return false;
        }
        $rows = DB::table('t_ai_messages')
            ->where('conversation_id', $convId)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['content', 'transcript']);

        // Digit-normalize both sides: strip everything but digits and dots,
        // then look for the amount as an integer ("15050") and as typed with
        // separators removed. 15050.00 → also try "15050".
        $needles = array_unique(array_filter([
            (string) (int) round($amount),
            rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.'),
        ]));

        foreach ($rows as $r) {
            $hay = preg_replace('/[,\s]+/', '', (string) $r->content . ' ' . (string) $r->transcript);
            foreach ($needles as $n) {
                if ($n !== '' && str_contains($hay, $n)) {
                    return true;
                }
            }
        }
        return false;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Make the global auth() helper resolve to this user for the replay.
     *
     * The controllers we replay into read `auth()->user()`, not
     * `$request->user()`. In a real /assistant/confirm request auth() already
     * holds this same Sanctum user, so this is a no-op; it exists so the path
     * is explicit and testable outside HTTP.
     *
     * Guarded by instanceof because only the authenticatable model
     * (App\Models\User) can be handed to the guard — passing a plain Eloquent
     * model fatals. If it isn't authenticatable we leave auth() alone and let
     * the controller's own check fail loudly rather than corrupt auth state.
     */
    private function actAs($user): void
    {
        if ($user instanceof \Illuminate\Contracts\Auth\Authenticatable) {
            Auth::setUser($user);
        }
    }

    private function prefs(int $userId): array
    {
        $row = DB::table('t_ai_user_prefs')->where('user_id', $userId)->value('prefs_json');
        return $row ? (json_decode($row, true) ?: []) : [];
    }

    private function cleanDate($value): ?string
    {
        if (!$value) return null;
        try {
            return \Carbon\Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The one-tap bank options embedded in a draft when the source is a bank
     * but nobody said which. Null when no banks exist (caller falls back to an
     * error). Snapshotted into the payload so the card's buttons stay valid for
     * exactly what choose() will accept.
     */
    private function bankChoice(?string $label = null): ?array
    {
        $banks = DB::table('t_fin_online_receiving_accounts')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn($b) => ['id' => (int) $b->id, 'name' => $b->name])
            ->values()
            ->all();

        if (!$banks) return null;

        return [
            'field' => 'receiving_account_id',
            // Outgoing money asks "from"; a shop payment asks "received it".
            'label' => $label ?: 'Which bank did it go from?',
            'options' => $banks,
        ];
    }

    private function bankName(int $id): string
    {
        return (string) (DB::table('t_fin_online_receiving_accounts')->where('id', $id)->value('name') ?? ('#' . $id));
    }

    private function buName(int $id): string
    {
        return (string) (DB::table('t_fin_business_units')->where('id', $id)->value('name') ?? ('#' . $id));
    }
}
