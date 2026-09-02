<?php

namespace App\Services\Assistant;

use App\Models\FIN\AccountModel;
use App\Services\FIN\PaymentSourceService;
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
            return ['error' => 'Staff salaries must be paid from the Payroll screen — I cannot record them as an expense. '
                . 'If this was money given to ONE employee early, that is a salary advance: use find_employee then draft_salary_advance.'];
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

        // Mirror the server's payment-source gate BEFORE building the card. The
        // account and the business unit have to agree — a saved default is for one
        // set of books and does not follow the user into another — and finding that
        // out from a 403 after confirming is how a real Frozen expense was lost on
        // Aug-5-2026.
        if ($blocked = $this->sourceRefusal($user, $source, $businessUnitId, PaymentSourceService::PURPOSE_EXPENSE)) {
            return ['error' => $blocked];
        }

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
            // Provenance, and what earns this card the long TTL in store().
            '_from_sms' => !empty($args['_from_sms']) ?: null,
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
            [$payload, $display] = $this->attachChatImage($payload, $display, $user, $this->replacesId($args));
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
            // Provenance, and what earns this card the long TTL in store().
            '_from_sms' => !empty($args['_from_sms']) ?: null,
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

    /**
     * A SALARY ADVANCE — money handed to an employee now and recovered from a specific
     * month's pay. Drafts only; the money moves when a human taps Confirm.
     *
     * ⭐⭐ The month is the whole point of this card. An advance belongs to ONE payroll
     * month: that month's pay recovers it and that month's wage bill is charged for it.
     * It DEFAULTS to the current month (owner ruling) and is always shown on the card, so
     * a manager never has to guess which month he is spending. If he then says "no, make
     * it August", the model re-drafts with `payroll_month` + `replaces_draft_id` and the
     * old card is cancelled — he corrects in place instead of starting over.
     *
     * ⚠ Confirm replays through the PayrollController, NOT PayrollService: the permission
     * (`manage_payroll`) lives on the controller, so calling the service directly would
     * hand every assistant user the ability to pay staff. See replaySalaryAdvance.
     */
    public function draftSalaryAdvance(array $args, $user): array
    {
        $amount = (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        $userId = (int) ($args['user_id'] ?? 0);
        if (!$userId) {
            return ['error' => 'Which employee is this for? Call find_employee first — never guess a user_id.'];
        }

        // Resolve against the SAME population the Payroll screen shows (monthly + custom),
        // so the card can never offer someone the grid would not.
        $payroll = new \App\Services\HR\PayrollService();
        $month = trim((string) ($args['payroll_month'] ?? '')) ?: now()->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return ['error' => 'That payroll month is not valid — use YYYY-MM.'];
        }

        // Mirror the server ceiling: an advance can run one month ahead (given forward when
        // this month is already paid) but no further — beyond that it is a loan, not early
        // salary. Checked HERE so the card never offers a month the confirm would refuse.
        $maxMonth = now()->startOfMonth()->addMonth()->format('Y-m');
        if ($month > $maxMonth) {
            return ['error' => 'An advance can only be given up to ' . date('F Y', strtotime($maxMonth . '-01'))
                . ' — one month ahead at most. Ask him which nearer month he means.'];
        }

        $match = null;
        foreach ($payroll->advanceEligibleEmployees(null, $month) as $e) {
            if ($e['user_id'] === $userId) { $match = $e; break; }
        }
        if (!$match) {
            return ['error' => 'That employee is not on the Payroll screen, so I cannot give them an advance. '
                . 'Call find_employee to see who is, or add them to Payroll first.'];
        }

        // Mirror every server refusal HERE, so the card never promises money the confirm
        // would reject. A running-balance (khata) employee genuinely has no such thing as
        // an advance — money handed to them is a PAYMENT against their balance.
        if ($match['balance_tracked']) {
            return ['error' => $match['name'] . ' is on a running balance (khata), so this is not an advance — '
                . 'record a payment on their card in Payroll instead. I cannot draft that here.'];
        }
        if ($match['month_paid']) {
            return ['error' => $match['name'] . "'s salary for " . date('F Y', strtotime($month . '-01'))
                . ' is already paid, so an advance could never be recovered from it. '
                . 'Ask whether he wants it against the next month instead.'];
        }

        // Funding: NF Cash, or an online transfer from one of our banks. Same two choices
        // the Payroll screen offers — the accounts themselves are fixed by config, so the
        // only question is cash-vs-online and, for online, WHICH bank.
        $funding = mb_strtolower(trim((string) ($args['funding'] ?? 'cash')));
        if (!in_array($funding, ['cash', 'online'], true)) {
            return ['error' => "Funding must be 'cash' or 'online'."];
        }

        $bankId = (int) ($args['bank_id'] ?? 0);
        $needsBankChoice = $funding === 'online' && !$bankId;
        $choice = $needsBankChoice ? $this->bankChoice('Which bank did the advance go from?') : null;
        if ($needsBankChoice && !$choice) {
            return ['error' => 'No banks are configured, so this cannot be paid online. Suggest cash instead.'];
        }
        if ($bankId && $funding === 'online' && !$this->bankExists($bankId)) {
            return ['error' => 'That bank does not exist. Use get_context for valid ids.'];
        }

        // The day the money actually left. It only differs from today when the advance is
        // for a PAST month (entered late — the transfer really happened back then), and it
        // must fall inside that month, which is exactly what the server re-checks.
        $curMonth = now()->format('Y-m');
        $moneyDate = $this->cleanDate($args['money_date'] ?? null);
        if ($month < $curMonth) {
            $moneyDate = $moneyDate ?: date('Y-m-t', strtotime($month . '-01'));
            if (substr($moneyDate, 0, 7) !== $month) {
                return ['error' => 'The payment date for a ' . date('F Y', strtotime($month . '-01'))
                    . ' advance has to be a day in that month.'];
            }
            if ($moneyDate > now()->toDateString()) {
                return ['error' => 'The payment date cannot be in the future.'];
            }
        } else {
            // Current or next month — the money is moving today whichever month recovers it.
            $moneyDate = now()->toDateString();
        }

        $monthLabel = date('F Y', strtotime($month . '-01'));
        $isFuture = $month > $curMonth;

        $payload = array_filter([
            'user_id'       => $userId,
            'amount'        => round($amount, 2),
            'funding'       => $funding,
            'bank_id'       => $funding === 'online' ? ($bankId ?: null) : null,
            'note'          => trim((string) ($args['note'] ?? '')) ?: null,
            'payroll_month' => $month,
            'money_date'    => $moneyDate,
            '_pending_choice' => $choice,
            '_from_sms'     => !empty($args['_from_sms']) ?: null,
        ], fn($v) => $v !== null);

        $display = array_values(array_filter([
            ['label' => 'Employee', 'value' => $match['name']
                . ($match['schedule'] === 'custom' ? ' (custom schedule)' : '')],
            ['label' => 'Amount', 'value' => 'Rs ' . number_format($amount, 0)],
            ['label' => 'Paid from', 'value' => $funding === 'cash' ? 'NF Cash' : 'Online / bank transfer'],
            $funding === 'online' && $bankId ? ['label' => 'Bank', 'value' => $this->bankName($bankId)] : null,
            $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
            // The two date rows are the point of the card — never collapse them into one.
            ['label' => 'Deducted from', 'value' => $monthLabel . ' pay'
                . ($isFuture ? ' (next month)' : ($month === $curMonth ? ' (this month)' : ' (back-dated)'))],
            ['label' => 'Money left on', 'value' => $moneyDate],
            $match['open_advance_total'] > 0
                ? ['label' => 'Already open', 'value' => 'Rs ' . number_format($match['open_advance_total'], 0)]
                : null,
            $match['schedule'] === 'custom'
                ? ['label' => 'Note', 'value' => 'Custom schedule — recovered by pay period, not by month']
                : null,
        ]));

        // A screenshot of the transfer the user just shared belongs on this card, the same
        // way it does on an expense. Never for SMS-raised cards (that image is another turn).
        if (empty($args['_from_sms'])) {
            [$payload, $display] = $this->attachChatImage($payload, $display, $user, $this->replacesId($args));
        }

        return $this->store($user, 'salary_advance',
            'Salary advance: Rs ' . number_format($amount, 0) . ' to ' . $match['name'] . ' (' . $monthLabel . ')',
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

        // ⭐ CLUBBED CARD. Several transfers to ONE vendor become ONE card whose
        // amount IS their sum — deliberately routed through this same method
        // rather than a parallel builder, so every guard below (vendor exists,
        // balance cap, source account, bank picker, duplicate notice) applies to
        // a batch exactly as it does to a single payment. A second
        // implementation would be a second place for those rules to drift.
        //
        // ⚠ The draft TYPE stays 'vendor_payment'. The shipped APK switches on
        // it in three places — including `isOut`, which decides money-out from
        // money-in — so a NEW type would render a clubbed vendor payment as
        // money IN until the next build. The batch travels as payload metadata.
        $transfers = $this->cleanTransfers($args['_transfers'] ?? null);

        $amount = $transfers
            ? round(array_sum(array_column($transfers, 'amount')), 2)
            : (float) ($args['amount'] ?? 0);
        if ($amount <= 0) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        // The server caps a payment at the outstanding balance. Surfacing it
        // here turns a post-confirm rejection into a useful sentence.
        $balance = round((float) ($vendor->current_balance ?? 0), 2);

        // ⭐⭐ A CLUB THAT OVERSHOOTS IS A QUESTION, NOT A DEAD END (owner, Aug-17).
        // When several transfers total more than we owe, there are exactly two
        // real explanations and Taimur is the only one who can tell them apart:
        // some of these were ALREADY recorded by hand (the SMS just stayed in the
        // box), or a purchase genuinely hasn't been entered yet. So the card is
        // still built — with a chip per transfer so he can drop the ones already
        // on the books and record the rest. Refusing outright would hide the
        // whole night behind an error message and tell him nothing about which
        // transfer is the problem.
        //
        // A SINGLE payment over the balance keeps the old refusal: there is
        // nothing to choose between, so the honest answer is still "no".
        $overBalance = $amount > $balance;
        if ($overBalance && !$transfers) {
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

        // The drop-a-transfer picker (see the over-balance note above). It uses
        // the SAME `_pending_choice` slot as the bank picker, which is what makes
        // it render as chips on the SHIPPED app with no build: the client just
        // draws `options[].name` and posts the id back. It also inherits that
        // slot's safety property — Confirm stays disabled until it is answered,
        // so an over-balance club can never be confirmed straight into a
        // rejection. ⚠ The bank question wins when both apply: without a bank
        // there is nothing to record at all, and only one choice can be live.
        if ($overBalance && $transfers && !$choice) {
            $choice = [
                'field'   => '_drop_transfer',
                'label'   => 'Rs ' . number_format($amount, 0) . ' is more than we owe (Rs '
                           . number_format($balance, 0) . '). Drop any already recorded:',
                'options' => array_map(fn($t) => [
                    'id'   => (int) $t['sms_id'],
                    'name' => 'Drop Rs ' . number_format($t['amount'], 0)
                            . (!empty($t['time']) ? ' · ' . $t['time'] : '')
                            . (!empty($t['reference']) ? ' · ' . $t['reference'] : ''),
                ], $transfers),
            ];
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
            '_transfers' => $transfers,
            // Provenance, and what earns this card the long TTL in store() —
            // also how pendingVendorCard() tells an SMS-raised card (clubbable)
            // from one Taimur typed in chat (never rewritten underneath him).
            '_from_sms' => !empty($args['_from_sms']) ?: null,
        ], fn($v) => $v !== null);

        // A look-alike payment already on the books is worth a sentence, never a
        // refusal: splitting one payment into several same-amount transfers is
        // routine here (RAAST caps), so the honest answer is "check this", not
        // "no". Shown as a card row, which every client already renders.
        // ⭐ A CLUBBED card is the split, all of it, on one card — so the
        // look-alikes it contains are the point, not a warning. Only its own
        // total is worth checking against what is already on the books.
        $dupNotice = $transfers
            ? null
            : $this->duplicatePaymentNotice($vendorId, $vendor->vendor_name, $amount, $date,
                $user, $this->replacesId($args));

        // One row per transfer, so a clubbed card reads like the bank's own
        // night — each time and TID visible, nothing merged away.
        $transferRows = [];
        foreach (($transfers ?? []) as $i => $t) {
            $transferRows[] = [
                'label' => 'Transfer ' . ($i + 1),
                'value' => implode(' · ', array_filter([
                    'Rs ' . number_format($t['amount'], 0),
                    $t['time'] ?? null,
                    !empty($t['reference']) ? 'TID ' . $t['reference'] : null,
                ])),
            ];
        }

        $display = array_values(array_filter(array_merge(
            [['label' => 'Vendor', 'value' => $vendor->vendor_name]],
            $transferRows,
            [
                ['label' => $transfers ? 'Total (' . count($transfers) . ' transfers)' : 'Amount',
                 'value' => 'Rs ' . number_format($amount, 0)],
                ['label' => 'Paid from', 'value' => $source->account_name],
                $receivingId ? ['label' => 'Bank', 'value' => $this->bankName($receivingId)] : null,
                $needsBankChoice ? ['label' => 'Bank', 'value' => 'Choose below'] : null,
                ['label' => 'Date', 'value' => $date],
                // ⚠ Never a bare minus (the standing rule from the payroll
                // khata): "Rs -270,380 outstanding" reads like a number nobody
                // can act on. Say what it actually means instead.
                $overBalance
                    ? ['label' => 'Over what we owe',
                       'value' => 'Rs ' . number_format($amount - $balance, 0) . ' more than the Rs '
                                . number_format($balance, 0) . ' outstanding']
                    : ['label' => 'Outstanding after', 'value' => 'Rs ' . number_format($balance - $amount, 0)],
                $dupNotice ? ['label' => '⚠ Check', 'value' => $dupNotice] : null,
            ]
        )));

        if (empty($args['_from_sms'])) {
            [$payload, $display] = $this->attachChatImage($payload, $display, $user, $this->replacesId($args));
        }

        $summary = $transfers
            ? 'Pay ' . $vendor->vendor_name . ': ' . count($transfers) . ' transfers, Rs ' . number_format($amount, 0)
            : 'Pay ' . $vendor->vendor_name . ': Rs ' . number_format($amount, 0);

        return $this->store($user, 'vendor_payment', $summary,
            $payload, $display, $this->replacesId($args));
    }

    /**
     * Normalise a clubbed-card transfer list, or null when there isn't one.
     *
     * Every entry must carry a positive amount and the id of the bank SMS it
     * came from — the SMS id is what lets the confirm stamp each ledger row back
     * onto the message that proves it. A list of ONE is not a batch: it is an
     * ordinary payment, and saying so here keeps the single-payment path
     * completely untouched by this feature.
     *
     * @return array<int, array{sms_id:int, amount:float, reference:?string, time:?string, date:?string}>|null
     */
    private function cleanTransfers($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($raw as $t) {
            $smsId  = (int) ($t['sms_id'] ?? 0);
            $amount = round((float) ($t['amount'] ?? 0), 2);
            // The same SMS twice would double-record real money.
            if ($smsId <= 0 || $amount <= 0 || isset($seen[$smsId])) {
                continue;
            }
            $seen[$smsId] = true;
            $out[] = [
                'sms_id'    => $smsId,
                'amount'    => $amount,
                'reference' => $t['reference'] ?? null,
                'time'      => $t['time'] ?? null,
                'date'      => $t['date'] ?? null,
            ];
        }
        return count($out) > 1 ? $out : null;
    }

    /**
     * "Is this the same payment again?" — a sentence for the card when a
     * near-identical vendor payment is already recorded (or already sitting on
     * another card), or null.
     *
     * ⚠ WARNING, NEVER A BLOCK. Same vendor, same amount, same day is exactly
     * what a legitimate split transfer looks like (Aug-10: four Rs 150,000
     * transfers to one vendor inside two minutes). Refusing would break the
     * real case to prevent a rarer one; a line on the card lets the human — who
     * knows what he sent — decide in a second.
     *
     * @param int|null $excludeDraftId  The card this one corrects ("make it
     *   4,000"): still pending at this moment, and warning about it would be
     *   telling the user their own correction is a duplicate.
     */
    private function duplicatePaymentNotice(int $vendorId, string $vendorName, float $amount,
        string $date, $user, ?int $excludeDraftId = null): ?string
    {
        try {
            $accountId = DB::table('t_fin_vendors')->where('id', $vendorId)->value('account_id');
            if ($accountId) {
                $prior = DB::table('t_fin_ledger')
                    ->where('transaction_type', 'vendor_payment')
                    ->where('to_account_id', $accountId)
                    ->whereNotIn('approval_status', ['rejected', 'reversed'])
                    ->whereRaw('ABS(amount - ?) <= 1', [$amount])
                    ->whereRaw('ABS(DATEDIFF(transaction_date, ?)) <= 1', [$date])
                    ->orderByDesc('transaction_date')
                    ->first(['transaction_date']);

                if ($prior) {
                    $when = \Illuminate\Support\Carbon::parse($prior->transaction_date);
                    return 'Rs ' . number_format($amount, 0) . ' to ' . $vendorName
                        . ' is already recorded for ' . $when->format('j M')
                        . ' — confirm this is a separate transfer.';
                }
            }

            // A second card for the same payment is the other way to pay twice.
            $pending = DB::table('t_ai_drafts')
                ->where('user_id', $user->id)
                ->where('type', 'vendor_payment')
                ->where('status', 'pending')
                ->when($excludeDraftId, fn($q) => $q->where('id', '<>', $excludeDraftId))
                ->get(['payload_json']);

            foreach ($pending as $p) {
                $payload = json_decode($p->payload_json, true) ?: [];
                if ((int) ($payload['vendor_id'] ?? 0) === $vendorId
                    && abs((float) ($payload['amount'] ?? 0) - $amount) <= 1) {
                    return 'Another card for Rs ' . number_format($amount, 0) . ' to ' . $vendorName
                        . ' is already waiting to be confirmed — don\'t confirm both unless these are two transfers.';
                }
            }
        } catch (\Throwable $e) {
            // A missing warning must never cost the user their card.
            Log::warning('[duplicatePaymentNotice] ' . $e->getMessage(), ['vendor' => $vendorId]);
        }
        return null;
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

        // ⭐ A WEIGHED day from a WhatsApp purchase log: many weighings, one
        // day, one entry. Same method as the plain by-total purchase so every
        // guard here applies to both, and the draft TYPE stays
        // `vendor_purchase` — the shipped APK switches on it (same reasoning as
        // the clubbed payment card).
        $lines = $this->cleanPurchaseLines($args['_lines'] ?? null);

        // ⭐ TYPED / PHOTOGRAPHED PURCHASES ARE PRICED FROM THE CATALOGUE TOO
        // (owner-reported, Aug-2026). `items` is what the user actually says —
        // "13.5 mutton whole", "89 veal raan haddi, 1.2 veal mix" — and the
        // rate is looked up HERE, from t_fin_vendor_products, never guessed by
        // the model. Before this, catalogue pricing existed only inside the
        // WhatsApp-log reader, so a slip or a typed line either got an invented
        // rate (13.5 kg booked at 1,650 against a 2,575 catalogue — ledger
        // 19595, deleted and re-entered by hand) or was refused five times for
        // want of a rate we already had. Anything not confidently matched goes
        // to _unplaced and the card ASKS with chips, exactly like the log path.
        // Weighings whose product we could not name ("Chakki . 650"). They are
        // asked about one at a time with chips — the SAME `_pending_choice`
        // slot the bank picker and the drop-chips use, so it renders on the
        // shipped app with no build, and Confirm stays blocked until answered.
        // ⚠⚠ Blocking is the point: an unplaced weighing is meat that was
        // bought, so confirming without it would under-record the day AND
        // (because dedup compares the shape of a day) make the same screenshot
        // look new next time.
        $unplaced = $this->cleanUnplaced($args['_unplaced'] ?? null);

        // ⚠ BOTH SHAPES AT ONCE IS A MODEL MISTAKE, AND A SILENT ONE. `_lines`
        // comes from read_purchase_log already priced; `items` is what the user
        // said. Sending both is ambiguous — merge and we may double-count the
        // day, ignore and we may drop a correction he just made. Neither may
        // happen quietly with money involved, so refuse and say which to use.
        if ($lines && !empty($args['items'])) {
            return ['error' => 'You passed BOTH _lines and items. Use ONE: _lines exactly as '
                . 'read_purchase_log returned them, OR items for what the user typed or read off a slip. '
                . 'To correct a line from a log card, re-send the full _lines with that line fixed.'];
        }

        if (!$lines && !empty($args['items'])) {
            [$lines, $itemUnplaced] = $this->priceItems($vendorId, $args['items']);
            // Items we could not place join anything the caller already had, so
            // one card asks about all of them in turn.
            $unplaced = $this->cleanUnplaced(array_merge($unplaced ?? [], $itemUnplaced));
        }

        $amount = $lines
            ? round(array_sum(array_map(fn($l) => $l['quantity'] * $l['rate'], $lines)), 2)
            : (float) ($args['amount'] ?? 0);
        // Zero is legitimate ONLY while chips are still pending — a card that
        // is all questions has nothing to total yet. It cannot be confirmed in
        // that state (the pending choice blocks it), so no zero-value purchase
        // can ever post.
        if ($amount <= 0 && !$unplaced) {
            return ['error' => 'I need an amount greater than zero.'];
        }

        $date = $this->cleanDate($args['transaction_date'] ?? null) ?: now()->toDateString();
        if ($date > now()->toDateString()) {
            return ['error' => 'A purchase date cannot be in the future.'];
        }

        $balance = round((float) ($vendor->current_balance ?? 0), 2);

        // ⭐⭐ DEDUP APPLIES HOWEVER THE DAY ARRIVES (owner ask, Aug-2026: "whether
        // he sends the screenshot or types, they should all work"). The 2-day
        // look-back lived ONLY inside read_purchase_log, so a day already
        // recorded from a screenshot could be TYPED again — or a photographed
        // slip re-entered — and it double-booked the khata and the stock in
        // silence. Same service, same rules, now on every purchase card.
        //
        // ⚠ Deliberately quieter than the screenshot path: it warns only when
        // the day looks like the SAME purchase, or a look-alike sits within two
        // days. It does NOT warn merely because the vendor already has an entry
        // that date — 16% of vendor-days since June genuinely carry more than
        // one purchase (Jilani alone has 3-in-a-day), so that warning would fire
        // on legitimate entries, and noise is what gets ignored.
        $dupNotice = null;
        try {
            $verdict = app(PurchaseLogService::class)
                ->dedupeVerdict($vendorId, $date, $lines ?? [], $unplaced ?? []);
            $seen = $verdict['existing'] ?? null;
            if ($seen && in_array($verdict['verdict'], ['skip', 'ask_near'], true)) {
                $dupNotice = $verdict['verdict'] === 'skip'
                    ? 'This day already has Rs ' . number_format($seen['amount'], 0) . ' from this vendor ('
                      . $seen['lines'] . ' lines) — a second purchase, or already entered?'
                    : $seen['date'] . ' has a very similar purchase (Rs ' . number_format($seen['amount'], 0)
                      . ') — the same one on the wrong date, or a genuinely new day?';
            }
        } catch (\Throwable $e) {
            $dupNotice = null; // a check we cannot run must never block a real entry
        }

        $payload = array_filter([
            'vendor_id' => $vendorId,
            'amount' => round($amount, 2),
            'transaction_date' => $date,
            'description' => trim((string) ($args['description'] ?? '')) ?: null,
            '_lines' => $lines,
            '_unplaced' => $unplaced,
            '_group_title' => trim((string) ($args['group_title'] ?? '')) ?: null,
        ], fn($v) => $v !== null);

        if ($unplaced) {
            $first = $unplaced[0];
            $products = app(PurchaseLogService::class)->vendorProducts($vendorId);
            // ⚠ NO CATALOGUE = NO CHIPS. Sajid (Desi Chicken) has zero products,
            // so "desi chicken 8070" as items would raise a card whose ONLY
            // button is "skip" — a dead end that blocks Confirm forever. This
            // vendor's purchases are by-total; say so instead of trapping him.
            if (empty($products)) {
                return ['error' => $vendor->vendor_name . ' has no product catalogue, so I cannot price '
                    . 'items for them. Ask the user for the TOTAL amount and record it as a plain purchase '
                    . '(amount only, the products in the description).'];
            }
            $payload['_pending_choice'] = [
                'field'   => '_place_line',
                'label'   => 'What was "' . $first['text'] . '"? (' . $first['quantity'] . ')',
                'options' => array_merge(
                    array_map(fn($p) => ['id' => (int) $p->id, 'name' => $p->product_name], $products),
                    [['id' => 0, 'name' => '✕ Not a purchase — skip this line']]
                ),
            ];
        }

        // One row per weighing, each showing its own rate and where the rate
        // came from — a catalog figure must never look like a settled fact on
        // a product whose price actually moves (Cow Brain: 300/350/800/1000).
        // Taimur corrects one by replying ("cow brain 350"), which re-drafts
        // through replaces_draft_id.
        $lineRows = [];
        foreach (($lines ?? []) as $i => $l) {
            $note = !empty($l['rate_varies']) ? ' ⚠ rate varies' : '';
            $lineRows[] = [
                'label' => 'Line ' . ($i + 1) . ' · ' . $l['product_name'],
                'value' => rtrim(rtrim(number_format($l['quantity'], 3, '.', ''), '0'), '.')
                         . ' ' . $l['unit'] . ' × Rs ' . number_format($l['rate'], 0)
                         . ' = Rs ' . number_format($l['quantity'] * $l['rate'], 0) . $note,
            ];
        }

        // Unplaced weighings are SHOWN, not hidden — the total is knowingly
        // short until they are placed, and saying so is what stops a
        // half-recorded day being confirmed by reflex.
        foreach ($unplaced ?? [] as $u) {
            $lineRows[] = ['label' => '❓ ' . $u['text'],
                           'value' => $u['quantity'] . ' — tell me what this is (below)'];
        }

        $display = array_values(array_filter(array_merge(
            [['label' => 'Vendor', 'value' => $vendor->vendor_name]],
            $lineRows,
            [
                ['label' => $lines ? 'Total (' . count($lines) . ' weighings)' : 'Purchase amount',
                 'value' => 'Rs ' . number_format($amount, 0)
                          . ($unplaced ? ' so far' : '')],
                ['label' => 'Date', 'value' => $date],
                ['label' => 'We will owe them', 'value' => 'Rs ' . number_format($balance + $amount, 0)],
                $dupNotice ? ['label' => '⚠ Check', 'value' => $dupNotice] : null,
                $lines ? ['label' => 'To change a rate',
                          'value' => 'reply e.g. "cow brain 350" and I will redo this card'] : null,
            ]
        )));

        // The screenshot IS the receipt for a weighed day — attaching it is the
        // whole point, and attachChatImage already does exactly that.
        [$payload, $display] = $this->attachChatImage($payload, $display, $user, $this->replacesId($args));

        $summary = $lines
            ? 'Purchase from ' . $vendor->vendor_name . ': ' . count($lines)
              . ' weighings, Rs ' . number_format($amount, 0) . ' (' . $date . ')'
            : 'Purchase from ' . $vendor->vendor_name . ': Rs ' . number_format($amount, 0);

        $stored = $this->store($user, 'vendor_purchase', $summary,
            $payload, $display, $this->replacesId($args));

        // A possible duplicate must be SAID, not just drawn on the card — he
        // confirms from the chat as often as from the rows.
        if ($dupNotice && empty($stored['error'])) {
            $stored['possible_duplicate'] = $dupNotice;
            $stored['note'] = ($stored['note'] ?? '')
                . ' ⚠ POSSIBLE DUPLICATE: ' . $dupNotice
                . ' Say this to the user in one short line and let them decide before they confirm.';
        }
        return $stored;
    }

    /**
     * Normalise weighed purchase lines, or null when this is a plain by-total
     * purchase. Every line needs a real vendor PRODUCT id, a positive quantity
     * and a positive rate — a line missing any of those would post a nonsense
     * item, so it is dropped rather than guessed at.
     *
     * ⚠⚠ `product_id` is a `t_fin_vendor_products` id — the vendor's own
     * purchase catalogue. It is NOT a sales-catalogue (`t_pdm_*`) product, and
     * the two must never be mixed: they are different price lists for
     * different sides of the business.
     *
     * @return array<int, array{product_id:int, product_name:string, unit:string,
     *         quantity:float, rate:float, rate_varies:bool}>|null
     */
    /**
     * Price what the user SAID against the vendor's own catalogue.
     *
     * Each item is {product, quantity, rate?}. The rate is looked up here and
     * only overridden when the user actually named one ("cow brain 350") — the
     * model must never supply a price it invented. A product we cannot match
     * confidently is NOT guessed: it becomes an unplaced line so the card asks
     * with chips, which is also how the answer gets remembered for next time.
     *
     * @return array{0: array|null, 1: array}  [priced lines, unplaced]
     */
    private function priceItems(int $vendorId, $items): array
    {
        if (!is_array($items)) {
            return [null, []];
        }
        $svc = app(PurchaseLogService::class);
        $lines = [];
        $unplaced = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['product'] ?? ''));
            $qty  = round((float) ($item['quantity'] ?? 0), 3);
            if ($qty <= 0) {
                continue; // a line with no weight is not a purchase line
            }

            $product = $name !== '' ? $svc->resolveProduct($vendorId, $name) : null;
            if (!$product) {
                $unplaced[] = [
                    'text'     => ($name !== '' ? $name : 'item') . ' ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
                    'quantity' => $qty,
                ];
                continue;
            }

            // A rate the user NAMED wins (the Cow Brain exception); otherwise
            // the catalogue decides. Never the model.
            $told = round((float) ($item['rate'] ?? 0), 2);
            $rate = $told > 0 ? $told : $svc->rateFor($product);
            if ($rate <= 0) {
                $unplaced[] = ['text' => $product->product_name . ' ' . $qty, 'quantity' => $qty];
                continue;
            }

            $lines[] = [
                'product_id'   => (int) $product->id,
                'product_name' => $product->product_name,
                'unit'         => $product->unit,
                'quantity'     => $qty,
                'rate'         => $rate,
                'rate_varies'  => $told > 0 ? false : ($svc->rateSourceFor($product)['varies'] ?? false),
                // What he called it — so confirming teaches the alias.
                'text'         => $name,
            ];
        }

        return [$lines ?: null, $unplaced];
    }

    private function cleanPurchaseLines($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $l) {
            $pid  = (int) ($l['product_id'] ?? 0);
            $qty  = round((float) ($l['quantity'] ?? 0), 3);
            $rate = round((float) ($l['rate'] ?? 0), 2);
            if ($pid <= 0 || $qty <= 0 || $rate <= 0) {
                continue;
            }
            $out[] = [
                'product_id'   => $pid,
                'product_name' => (string) ($l['product_name'] ?? ''),
                'unit'         => (string) ($l['unit'] ?? 'kg'),
                'quantity'     => $qty,
                'rate'         => $rate,
                'rate_varies'  => (bool) ($l['rate_varies'] ?? false),
                // The chat message this line came from. Kept so a CONFIRM can
                // teach "what the butcher's word means" (incl. corrections) —
                // replayWeightedPurchase whitelists its item fields, so this
                // never reaches the endpoint.
                'text'         => mb_substr(trim((string) ($l['text'] ?? '')), 0, 80),
            ];
        }
        return $out ?: null;
    }

    /**
     * Weighings read off the chat whose product we could not name. Each keeps
     * its own text and quantity so the chip question can quote the message
     * exactly as the butcher wrote it.
     *
     * @return array<int, array{text:string, quantity:float}>|null
     */
    private function cleanUnplaced($raw): ?array
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $u) {
            $qty = round((float) ($u['quantity'] ?? 0), 3);
            $txt = trim((string) ($u['text'] ?? ''));
            if ($qty > 0 && $txt !== '') {
                $out[] = ['text' => mb_substr($txt, 0, 80), 'quantity' => $qty];
            }
        }
        return $out ?: null;
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
        // ⭐ A card RAISED FROM A BANK SMS gets the same long TTL, for the same
        // reason: nobody typed it, so nobody is standing there to confirm it.
        // An auto-raised card on a 15-minute clock would expire before Taimur
        // next looked at his phone, bounce its SMS back to the money box, and
        // raise itself again — a loop that looks like the feature not working.
        // Equally safe: an SMS card's numbers come from the bank and cannot go
        // stale, and recordPayment re-checks the live balance at confirm.
        $ttl = in_array($type, ['payment_proof', 'shop_payment'], true) || !empty($payload['_from_sms'])
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
            // Two different questions can occupy this slot now, so tell the
            // model which one it is rather than always saying "bank".
            $note = ($payload['_pending_choice']['field'] ?? null) === '_drop_transfer'
                ? 'NOTHING has been recorded yet. These transfers total MORE than we owe this vendor, so the card is on screen with a chip per transfer — the user must first drop any that are already recorded, then tap Confirm. Tell them that in one sentence. Do NOT list the transfers in text and do NOT say it is saved.'
                : 'NOTHING has been recorded yet. The card is on screen with BANK BUTTONS — the user must first tap which bank it went from, then tap Confirm. Tell them to pick the bank on the card. Do NOT list the banks in text and do NOT say it is saved.';
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

        // A card still waiting on a choice is not confirmable — the whole point
        // of a picker is that the money can't move until it is answered. The
        // message comes from the card's OWN question: it is not always the bank
        // one any more (a clubbed card can be asking which transfers to drop),
        // and telling someone to "pick a bank" that isn't on screen is worse
        // than saying nothing.
        if (!empty($payload['_pending_choice'])) {
            $label = trim((string) ($payload['_pending_choice']['label'] ?? ''));
            return ['ok' => false, 'message' => $label !== ''
                ? $label . ' — the options are on the card.'
                : 'Answer the question on the card first.'];
        }

        // Read the batch BEFORE the underscore strip below eats it — it is card
        // metadata (never a form field), but the commit genuinely needs it.
        $transfers = $this->cleanTransfers($payload['_transfers'] ?? null);
        $rawPayload = $payload;   // weighed purchase lines are read from here too

        // ⚠⚠ LAST LOOK BEFORE MONEY MOVES: was one of these transfers recorded
        // BY HAND while the card sat waiting? SMS-raised cards live for a day,
        // and Taimur sometimes enters a payment on the web in between. Only
        // rows created AFTER this card was raised are considered — anything
        // older was already handled at ingest (no card is raised for a debit
        // that is already on the books), so the two guards split time cleanly
        // and an old same-amount row can never falsely trip this one.
        if ($draft->type === 'vendor_payment' && !empty($payload['_from_sms'])) {
            $already = $this->transferRecordedSinceDraft($draft, $payload, $transfers);
            if ($already !== null) {
                if ($transfers) {
                    // Take that transfer off the card; the card rebuilds around
                    // the rest.
                    $dropped = $this->dropTransfer($draft, $payload, (int) $already['sms_id'], $user);
                    // File the SMS against the row that already records it, HERE
                    // and not "when the sweep next runs" — the rebuilt card may
                    // be confirmed seconds later, and an unclaimed row lying
                    // around would trip this same guard again on a transfer that
                    // is genuinely fine. (Same close the sweep would write, with
                    // the same Restore path if it is ever wrong.)
                    if (($dropped['ok'] ?? false) && !empty($already['ledger_id'])) {
                        DB::table('t_ai_bank_sms')->where('id', (int) $already['sms_id'])
                            ->where('status', 'new')
                            ->update([
                                'status'           => 'recorded',
                                'auto_reason'      => 'already_recorded',
                                'linked_ledger_id' => (int) $already['ledger_id'],
                                'updated_at'       => now(),
                            ]);
                    }
                    return [
                        'ok' => false,
                        'message' => 'Rs ' . number_format($already['amount'], 0)
                            . ($already['reference'] ? ' (TID ' . $already['reference'] . ')' : '')
                            . ' was already recorded — I took it off the card and filed it. '
                            . ($dropped['ok'] ? 'Confirm the new card below for the rest.' : $dropped['message']),
                        'draft_id' => $dropped['draft_id'] ?? null,
                    ];
                }

                // A single-payment card whose one transfer is already recorded
                // has nothing left to do: retire the card and file the SMS in
                // one move, instead of telling the user to go do both by hand.
                DB::table('t_ai_drafts')->where('id', $draftId)->update([
                    'status' => 'cancelled', 'cancelled_at' => now(),
                    'error' => 'Already recorded by hand after the card was raised.',
                    'updated_at' => now(),
                ]);
                DB::table('t_ai_bank_sms')->where('linked_draft_id', $draftId)
                    ->where('status', 'recorded')
                    ->update([
                        'auto_reason'      => 'already_recorded',
                        'linked_draft_id'  => null,
                        'linked_ledger_id' => (int) ($already['ledger_id'] ?? 0) ?: null,
                        'updated_at'       => now(),
                    ]);
                return [
                    'ok' => false,
                    'message' => 'This payment was already recorded after the card was raised — '
                        . 'I removed the card and filed the bank SMS against that entry. Nothing to confirm.',
                ];
            }
        }

        // Underscore-prefixed keys are card metadata, never form fields.
        $payload = array_filter($payload, fn($k) => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY);

        try {
            $result = match ($draft->type) {
                'expense'         => $this->replayExpense($payload, $user),
                'vendor_payment'  => $transfers
                    ? $this->replayVendorPaymentBatch($payload, $transfers, $user)
                    : $this->replayVendorPayment($payload, $user),
                'vendor_purchase' => ($purchaseLines = $this->cleanPurchaseLines($rawPayload['_lines'] ?? null))
                    ? $this->replayWeightedPurchase($payload, $purchaseLines, $user)
                    : $this->replayVendorPurchase($payload, $user),
                'payment_proof'   => $this->replayPaymentProof($payload, $user),
                'shop_payment'    => $this->replayShopPayment($payload, $user),
                'account_transfer' => $this->replayAccountTransfer($payload, $user),
                'salary_advance'  => $this->replaySalaryAdvance($payload, $user),
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

        // ⭐ A CONFIRMED purchase is the assistant's lesson — twice over. Only on
        // confirm: a card he cancels is not an endorsement.
        //   • The GROUP belongs to that vendor (teachVendor upserts on the key,
        //     so confirming a re-asked group also RE-teaches a wrong one), and
        //   • every line's chat word means the product he confirmed it as —
        //     chip placements ("Chakki" → a product) and corrections (he moved
        //     an auto-placed line to another product) both land as per-vendor
        //     WAPROD aliases, so next time the word places itself.
        if ($draft->type === 'vendor_purchase') {
            try {
                $svc = app(PurchaseLogService::class);
                if (!empty($rawPayload['_group_title'])) {
                    $svc->teachVendor(
                        (string) $rawPayload['_group_title'],
                        (int) ($rawPayload['vendor_id'] ?? 0),
                        $user ? (int) $user->id : null
                    );
                }
                if (!empty($rawPayload['_lines']) && is_array($rawPayload['_lines'])) {
                    $svc->teachProductAliases(
                        (int) ($rawPayload['vendor_id'] ?? 0),
                        $rawPayload['_lines'],
                        $user ? (int) $user->id : null
                    );
                }
            } catch (\Throwable $e) {
                Log::warning('[purchaseLogTeach] ' . $e->getMessage(), ['draft' => $draftId]);
            }
        }

        // ⭐ Stamp the ledger row onto the bank SMS that proves it, so "what was
        // tagged to what" stays answerable — and so the money-inbox sweep counts
        // that row as CLAIMED and can never hand it to a look-alike debit.
        // Only fills a BLANK: a clubbed confirm has already given each SMS its
        // own row, and overwriting those with the batch's first id would flatten
        // exactly the detail this exists to keep.
        if (($result['result_type'] ?? null) === 'ledger' && !empty($result['result_id'])) {
            DB::table('t_ai_bank_sms')
                ->where('linked_draft_id', $draftId)
                ->whereNull('linked_ledger_id')
                ->update(['linked_ledger_id' => $result['result_id'], 'updated_at' => now()]);
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
     *   • ⚠ and (Aug-2026) an SMS whose account is TAUGHT to a different vendor
     *     is not a candidate at all, however well the numbers line up — the same
     *     identity rule the money-inbox sweep now applies. Paying Jilani must
     *     never adopt the SMS for a transfer to Imran Qureshi.
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
                ->limit(5)
                ->get(['id', 'counterparty_account']);

            // Drop any SMS that a taught account key assigns to somebody else.
            // (Filtered here rather than in SQL so the exactly-one test below is
            // made on what actually qualifies — the limit is raised to match.)
            $candidates = $candidates->reject(
                fn($c) => $this->smsBelongsElsewhere($c->counterparty_account ?? null, $draftType, $payload)
            )->values();

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
     * Is this SMS's counterparty account taught to someone OTHER than the party
     * this draft pays? True only when both sides are known and they disagree —
     * an untaught account, or a draft with no counterparty identity (an
     * expense), can never be proven wrong and so is never rejected here.
     */
    private function smsBelongsElsewhere(?string $accountKey, string $draftType, array $payload): bool
    {
        if (!$accountKey) {
            return false;
        }
        try {
            $rule = app(SmsCounterpartyMap::class)->byAccount($accountKey);
            if (!$rule || empty($rule->entity_id)) {
                return false;
            }
            if ($draftType === 'vendor_payment' && $rule->entity_type === 'vendor') {
                return (int) $rule->entity_id !== (int) ($payload['vendor_id'] ?? 0);
            }
            if ($draftType === 'account_transfer' && $rule->entity_type === 'account') {
                return (int) $rule->entity_id !== (int) ($payload['to_account_id'] ?? 0);
            }
            return false;
        } catch (\Throwable $e) {
            return false; // a check that cannot run must not block an adoption
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

        // Dropping a transfer off a clubbed card is not "filling in a field" —
        // it rebuilds the card around a smaller set. Handled apart from the
        // bank picker below, which answers a question once and is done.
        if (($choice['field'] ?? null) === '_drop_transfer') {
            return $this->dropTransfer($draft, $payload, (int) $optionId, $user);
        }
        if (($choice['field'] ?? null) === '_place_line') {
            return $this->placeLine($draft, $payload, (int) $optionId, $user);
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

    /**
     * Take one transfer off a clubbed card and rebuild it around the rest.
     *
     * The dropped transfer is not discarded — its bank SMS goes back to the
     * money box as unsorted, because "not on this card" is not the same as
     * "handled". If it was already recorded by hand, the inbox sweep closes it
     * there on its own; if it wasn't, it keeps asking.
     *
     * ⭐ Rebuilt by calling draftVendorPayment() again rather than by editing
     * this card in place: the total, the per-transfer rows, the outstanding
     * line and the question of whether a picker is still needed all follow from
     * the same code that built it the first time. Patching the JSON by hand
     * would be a second, quietly diverging version of every one of those rules.
     */
    private function dropTransfer(object $draft, array $payload, int $smsId, $user): array
    {
        $transfers = $payload['_transfers'] ?? [];
        $keep = array_values(array_filter($transfers, fn($t) => (int) ($t['sms_id'] ?? 0) !== $smsId));

        if (count($keep) === count($transfers)) {
            return ['ok' => false, 'message' => 'That transfer is not on this card.'];
        }
        if (empty($keep)) {
            return ['ok' => false, 'message' => 'That would leave nothing to record — cancel the card instead.'];
        }

        // Release it BEFORE rebuilding: store() re-points the surviving SMS rows
        // onto the new card, and this one must not be among them.
        DB::table('t_ai_bank_sms')->where('id', $smsId)->where('linked_draft_id', $draft->id)
            ->update(['status' => 'new', 'linked_draft_id' => null, 'auto_reason' => null, 'updated_at' => now()]);

        $rebuilt = $this->draftVendorPayment([
            'vendor_id'                 => (int) ($payload['vendor_id'] ?? 0),
            'payment_source_account_id' => $payload['payment_source_account_id'] ?? null,
            'receiving_account_id'      => $payload['receiving_account_id'] ?? null,
            'transaction_date'          => $payload['transaction_date'] ?? null,
            'description'               => $payload['description'] ?? null,
            '_from_sms'                 => true,
            // One survivor is an ordinary single payment, and cleanTransfers()
            // collapses it to exactly that.
            '_transfers'                => $keep,
            'amount'                    => count($keep) === 1 ? (float) $keep[0]['amount'] : null,
            'replaces_draft_id'         => (int) $draft->id,
        ], $user);

        if (!empty($rebuilt['error'])) {
            // Put the transfer back so the card is never left short of a
            // transfer that is also no longer in the money box.
            DB::table('t_ai_bank_sms')->where('id', $smsId)
                ->update(['status' => 'recorded', 'linked_draft_id' => $draft->id, 'updated_at' => now()]);
            return ['ok' => false, 'message' => $rebuilt['error']];
        }

        $left = count($keep);
        return [
            'ok' => true,
            'message' => 'Dropped — it is back in your money box. '
                . ($left === 1 ? 'One transfer' : $left . ' transfers')
                . ' left on the card.',
            'draft_id' => $rebuilt['draft_id'] ?? null,
        ];
    }

    /**
     * The first transfer on this card that a ledger row created AFTER the card
     * already records — or null. Vendor-scoped, sweep bounds (bank + amount ±1 +
     * date ±1), unclaimed rows only. For a single-payment card the payload
     * itself is treated as the one transfer.
     *
     * @return array{sms_id:int, amount:float, reference:?string}|null
     */
    private function transferRecordedSinceDraft(object $draft, array $payload, ?array $transfers): ?array
    {
        try {
            $vendorAccount = DB::table('t_fin_vendors')
                ->where('id', (int) ($payload['vendor_id'] ?? 0))->value('account_id');
            $bankId = (int) ($payload['receiving_account_id'] ?? 0);
            if (!$vendorAccount || !$bankId) {
                return null; // cannot scope — never block a confirm on a guess
            }

            $probe = $transfers ?: [[
                'sms_id'    => 0,
                'amount'    => (float) ($payload['amount'] ?? 0),
                'reference' => null,
                'date'      => $payload['transaction_date'] ?? null,
            ]];

            $matched = []; // ledger ids already used by an earlier transfer in this loop
            foreach ($probe as $t) {
                $day = $t['date'] ?? ($payload['transaction_date'] ?? now()->toDateString());
                $row = DB::table('t_fin_ledger as l')
                    ->where('l.transaction_type', 'vendor_payment')
                    ->where('l.to_account_id', $vendorAccount)
                    ->where('l.mode', 'online')
                    ->whereNotIn('l.approval_status', ['rejected', 'reversed'])
                    ->where('l.receiving_account_id', $bankId)
                    ->whereRaw('ABS(l.amount - ?) <= 1', [(float) $t['amount']])
                    ->whereRaw('ABS(DATEDIFF(l.transaction_date, ?)) <= 1', [$day])
                    ->where('l.created_at', '>=', $draft->created_at)
                    ->whereNotExists(function ($s) {
                        $s->select(DB::raw(1))->from('t_ai_bank_sms as b')
                          ->whereColumn('b.linked_ledger_id', 'l.id');
                    })
                    ->when($matched, fn($q) => $q->whereNotIn('l.id', $matched))
                    ->value('l.id');

                if ($row) {
                    // N identical transfers vs M new rows: each row excuses ONE
                    // transfer, exactly like the sweep's count-pairing.
                    $matched[] = $row;
                    return [
                        'sms_id'    => (int) ($t['sms_id'] ?? 0),
                        'amount'    => (float) $t['amount'],
                        'reference' => $t['reference'] ?? null,
                        'ledger_id' => (int) $row,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[transferRecordedSinceDraft] ' . $e->getMessage(), ['draft' => $draft->id]);
        }
        return null;
    }

    /**
     * Answer "what was this weighing?" — put the unplaced line onto a product
     * (or drop it) and rebuild the card around the result.
     *
     * Rebuilt by calling draftVendorPurchase() again rather than patching the
     * JSON: the total, the line rows, the rate flags and whether another
     * question is still owed all follow from the one place that computes them.
     * If more unplaced weighings remain, the new card simply asks the next.
     */
    private function placeLine(object $draft, array $payload, int $productId, $user): array
    {
        $unplaced = $payload['_unplaced'] ?? [];
        if (empty($unplaced)) {
            return ['ok' => false, 'message' => 'Nothing on this card is waiting to be identified.'];
        }
        $target = array_shift($unplaced);   // always the one the chips asked about
        $lines  = $payload['_lines'] ?? [];

        if ($productId > 0) {
            $product = DB::table('t_fin_vendor_products')
                ->where('id', $productId)
                ->where('vendor_id', (int) ($payload['vendor_id'] ?? 0))
                ->first(['id', 'product_name', 'unit', 'rate_per_unit']);
            // ⚠ The product must belong to THIS vendor — vendor catalogues are
            // per-vendor, and a chip id from a stale card must never price a
            // line off another vendor's list.
            if (!$product) {
                return ['ok' => false, 'message' => 'That product is not on this vendor\'s list.'];
            }
            $svc  = app(PurchaseLogService::class);
            $rate = $svc->rateFor($product);
            if ($rate <= 0) {
                return ['ok' => false, 'message' => 'That product has no rate yet — set one on the vendor screen first.'];
            }
            $lines[] = [
                'product_id'   => (int) $product->id,
                'product_name' => $product->product_name,
                'unit'         => $product->unit,
                'quantity'     => (float) $target['quantity'],
                'rate'         => $rate,
                'rate_varies'  => $svc->rateSourceFor($product)['varies'] ?? false,
                // What the butcher wrote — a chip tap here IS a placement the
                // confirm will learn from (WAPROD alias), so next time the same
                // word places itself.
                'text'         => (string) ($target['text'] ?? ''),
            ];
        }

        // Skipping the ONLY line leaves nothing to record — that is a cancel,
        // not a rebuild. Without this the rebuild fails on the zero-amount
        // guard and the card sits there repeating "I need an amount" at a user
        // who just told us the whole thing was not a purchase.
        if (empty($lines) && empty($unplaced)) {
            DB::table('t_ai_drafts')->where('id', $draft->id)->update([
                'status' => 'cancelled', 'cancelled_at' => now(),
                'error' => 'Nothing left on the card after skipping.', 'updated_at' => now(),
            ]);
            return ['ok' => true, 'message' => 'Skipped — nothing left to record, so I removed the card.'];
        }

        $rebuilt = $this->draftVendorPurchase([
            'vendor_id'         => (int) ($payload['vendor_id'] ?? 0),
            'transaction_date'  => $payload['transaction_date'] ?? null,
            'description'       => $payload['description'] ?? null,
            'group_title'       => $payload['_group_title'] ?? null,
            '_lines'            => $lines,
            '_unplaced'         => $unplaced,
            'replaces_draft_id' => (int) $draft->id,
        ], $user);

        if (!empty($rebuilt['error'])) {
            return ['ok' => false, 'message' => $rebuilt['error']];
        }

        $left = count($unplaced);
        return [
            'ok' => true,
            'message' => ($productId > 0 ? 'Added to the card.' : 'Skipped that line.')
                . ($left ? ' ' . $left . ' more to identify.' : ' Nothing left to identify — tap Confirm.'),
            'draft_id' => $rebuilt['draft_id'] ?? null,
        ];
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

    /**
     * Commit a CLUBBED vendor card: one ledger row per real bank transfer.
     *
     * ⭐⭐ ONE ROW PER TRANSFER, not one merged row (owner ruling). Four RAAST
     * transfers of Rs 150,000 are four movements the bank can show you; the
     * khata reading as a single Rs 600,000 line would be a story we invented.
     * Each row is also stamped back onto the SMS that proves it, which is what
     * makes "what was tagged to what" answerable later — and what stops the
     * money-inbox sweep from re-claiming those rows for some other look-alike.
     *
     * ⚠⚠ ALL OR NOTHING. Posted inside one transaction: a batch that fails on
     * the third transfer must not leave two recorded and a card that still says
     * three. The vendor balance is also checked against the TOTAL before any
     * row is written, because the endpoint caps each payment individually — pay
     * 150k against a 179k balance and the second call would fail on its own.
     * Failing early with a clear sentence beats failing halfway.
     */
    private function replayVendorPaymentBatch(array $payload, array $transfers, $user): array
    {
        $vendorId = (int) ($payload['vendor_id'] ?? 0);
        $total    = round(array_sum(array_column($transfers, 'amount')), 2);

        $vendor = DB::table('t_fin_vendors as v')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'v.account_id')
            ->where('v.id', $vendorId)
            ->first(['v.vendor_name', 'a.current_balance']);
        if (!$vendor) {
            return ['ok' => false, 'message' => 'That vendor no longer exists.'];
        }

        $balance = round((float) ($vendor->current_balance ?? 0), 2);
        if ($total > $balance) {
            return ['ok' => false, 'message' => 'These ' . count($transfers) . ' transfers total Rs '
                . number_format($total, 0) . ', but we only owe ' . $vendor->vendor_name
                . ' Rs ' . number_format($balance, 0)
                . '. Record the purchases first, then confirm this card again.'];
        }

        try {
            $posted = DB::transaction(function () use ($payload, $transfers, $user) {
                $rows = [];
                foreach ($transfers as $i => $t) {
                    $one = $payload;
                    $one['amount'] = $t['amount'];
                    if (!empty($t['date'])) {
                        $one['transaction_date'] = $t['date'];
                    }
                    // Keep each row individually identifiable in the khata.
                    if (!empty($t['reference'])) {
                        $one['description'] = trim(($payload['description'] ?? '') . ' · ref ' . $t['reference']);
                    }

                    $r = $this->replayVendorPayment($one, $user);
                    if (!($r['ok'] ?? false)) {
                        // Abort the whole night — see the all-or-nothing note.
                        throw new \RuntimeException(
                            'Transfer ' . ($i + 1) . ' of ' . count($transfers) . ' was refused: '
                            . ($r['message'] ?? 'rejected') . ' — nothing was recorded.'
                        );
                    }
                    $rows[] = ['sms_id' => $t['sms_id'], 'ledger_id' => $r['result_id'] ?? null];
                }

                // Stamp each ledger row onto its own SMS: the audit trail, and
                // the claim that stops reconcileRecordedDebits handing that row
                // to a different look-alike debit later.
                foreach ($rows as $row) {
                    if ($row['ledger_id']) {
                        DB::table('t_ai_bank_sms')->where('id', $row['sms_id'])
                            ->update(['linked_ledger_id' => $row['ledger_id'], 'updated_at' => now()]);
                    }
                }
                return $rows;
            });
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $ids = array_values(array_filter(array_column($posted, 'ledger_id')));

        return [
            'ok' => true,
            'message' => count($posted) . ' payments to ' . $vendor->vendor_name
                . ' recorded (Rs ' . number_format($total, 0) . ').',
            'result_type' => 'ledger',
            // The first row keeps the existing single-id contract; the full set
            // lives alongside it for anything that needs the whole batch.
            'result_id'   => $ids[0] ?? null,
            'result_json' => ['ledger_ids' => $ids, 'transfers' => count($posted), 'total' => $total],
        ];
    }

    /**
     * Commit a WEIGHED day — the WhatsApp purchase log.
     *
     * Goes through `VendorController::recordWeightedPurchase`, the same
     * endpoint the Ledger Hub's weighted modal posts to, so the ledger row,
     * the line items, the balance posting and the bill image are all produced
     * by the existing money code rather than a second copy of it.
     *
     * ⚠ `items[].product_id` is a `t_fin_vendor_products` id (the vendor's own
     * purchase catalogue). The customer-facing catalogue is a different table
     * entirely and is not involved in a purchase.
     */
    private function replayWeightedPurchase(array $payload, array $lines, $user): array
    {
        $payload = $this->stampAssistant($payload);
        $vendorId = (int) $payload['vendor_id'];

        $items = array_map(fn($l) => [
            'product_id'   => $l['product_id'],
            'product_name' => $l['product_name'],
            'quantity'     => $l['quantity'],
            'unit'         => $l['unit'],
            'rate'         => $l['rate'],
        ], $lines);

        $body = array_filter([
            'items'             => $items,
            'adjustment_amount' => 0,
            'transaction_date'  => $payload['transaction_date'] ?? now()->toDateString(),
            'description'       => $payload['description'] ?? null,
        ], fn($v) => $v !== null);

        // The screenshot travels as the bill image, exactly like a photographed
        // bill on the vendor screen.
        $files = $this->attachmentFiles($payload, 'bill_image');

        $request = Request::create(
            "/api/vendors/{$vendorId}/weighted-purchase",
            'POST',
            $body,
            [], $files, ['HTTP_ACCEPT' => 'application/json']
        );
        $request->setUserResolver(fn() => $user);
        $this->actAs($user);

        $response = app(\App\Http\Controllers\FIN\VendorController::class)
            ->recordWeightedPurchase($request, $vendorId);

        $data = method_exists($response, 'getContent')
            ? (json_decode($response->getContent(), true) ?: [])
            : [];

        if (($data['success'] ?? false) !== true) {
            return ['ok' => false, 'message' => $data['message'] ?? 'The purchase was rejected.'];
        }

        return [
            'ok' => true,
            'message' => $data['message'] ?? (count($items) . ' weighings recorded.'),
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
    /**
     * Give the salary advance for real.
     *
     * ⚠⚠ Deliberately replays into `HR\PayrollController::giveAdvance`, NOT
     * `PayrollService::giveAdvance`. The `manage_payroll` permission lives on the
     * CONTROLLER — the service has no check of its own — so calling the service here would
     * let anyone with assistant access pay staff. Going through the controller means only
     * the people who can give an advance on the Payroll screen can confirm one here, and
     * it is the confirming user's own permission that is tested (actAs), never "the system".
     *
     * It also re-validates the month at confirm: a card raised this morning for a month that
     * has since been PAID is refused rather than creating an unrecoverable advance.
     */
    private function replaySalaryAdvance(array $payload, $user): array
    {
        // ⚠ giveAdvance's free-text field is `note`, not `description` — stamping the default
        // key would have left assistant-given advances unmarked, the one row type you could
        // not tell the AI had created. The endpoint caps note at 255, so trim to fit rather
        // than have the validator reject the confirm.
        $payload = $this->stampAssistant($payload, 'note');
        if (isset($payload['note']) && mb_strlen($payload['note']) > 255) {
            $payload['note'] = mb_substr($payload['note'], 0, 255);
        }

        $request = Request::create('/hr/payroll/give-advance', 'POST',
            array_filter($payload, fn($v) => $v !== null));
        $request->setUserResolver(fn() => $user);
        $this->actAs($user); // denyIfNotAllowed() + the actor id both read auth()

        $controller = app(\App\Http\Controllers\HR\PayrollController::class);
        try {
            $response = $controller->giveAdvance($request);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?: 'The advance was rejected.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'The advance could not be recorded: ' . $e->getMessage()];
        }

        $data = json_decode($response->getContent(), true) ?: [];
        if (empty($data['success'])) {
            // 403 from the permission gate, or one of the month guards.
            return ['ok' => false, 'message' => $data['message'] ?? 'The advance was rejected.'];
        }

        // The advance is a request row; hand back its id so the card can link to it.
        $req = DB::table('t_req_master')
            ->where('requester_user_id', (int) ($payload['user_id'] ?? 0))
            ->whereNotNull('ledger_transaction_id')
            ->orderByDesc('id')->first(['id', 'request_number']);

        return [
            'ok' => true,
            'message' => $data['message'] ?? 'Advance given.',
            'result_type' => 'request',
            'result_id' => $req->id ?? null,
            'reference' => $req->request_number ?? null,
        ];
    }

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

    /**
     * ⭐ Every order this customer could plausibly have been paying, for the
     * "whose payment is this?" picker: the approvals queue first, then orders
     * that still owe money, then ones already approved in the last month.
     *
     * That last group is why this exists. The queue-only rule (Jul-2026) is
     * right for AUTOMATIC matching, but it made manual correction impossible in
     * exactly the case where correction matters most: a credit tagged to the
     * wrong customer, whose real owner's invoice has since been approved. The
     * picker offered no orders at all and the approver was stuck with a wrong
     * tag they could see but not fix.
     *
     * Attaching a proof to a settled order is pure record-keeping — no money
     * moves, no balance changes — so a zero balance is not a reason to refuse.
     *
     * @return array<int, array{id:int, order_number:string, balance:float, group:string, order_date:string}>
     */
    public function proofTargetOrders(int $customerId, int $approvedWithinDays = 30): array
    {
        $queueIds = $this->approvalsQueueOrderIds($customerId);

        $rows = DB::table('t_crm_prod_order as o')
            ->leftJoin('t_fin_ledger as l', function ($j) {
                $j->on('l.order_id', '=', 'o.id')->where('l.transaction_type', '=', 'invoice');
            })
            ->where('o.customer_id', $customerId)
            ->where('o.order_status', '!=', 'cancelled')
            ->where(function ($w) use ($queueIds, $approvedWithinDays) {
                if ($queueIds) {
                    $w->orWhereIn('o.id', $queueIds);
                }
                // Still owed for — includes orders with no invoice row yet,
                // which is the pay-before-delivery case.
                $w->orWhere(function ($q) {
                    $q->whereIn('o.payment_status', ['unpaid', 'partial'])
                      ->whereRaw('(o.total_price - COALESCE(o.total_paid,0)) > 0.01');
                });
                // Recently approved — settled, but a credit can still belong here.
                $w->orWhere(function ($q) use ($approvedWithinDays) {
                    $q->where('l.approval_status', 'approved')
                      ->where('l.approval_date', '>=', now()->subDays($approvedWithinDays)->toDateString());
                });
            })
            ->distinct()
            ->orderByDesc('o.order_date')
            ->limit(40)
            ->get(['o.id', 'o.order_number', 'o.total_price', 'o.total_paid', 'o.order_date', 'l.approval_status']);

        return $rows->map(function ($o) use ($queueIds) {
            $balance = round((float) $o->total_price - (float) ($o->total_paid ?? 0), 2);
            return [
                'id'           => (int) $o->id,
                'order_number' => $o->order_number,
                'balance'      => $balance,
                'order_date'   => (string) $o->order_date,
                'group'        => in_array((int) $o->id, $queueIds, true)
                    ? 'awaiting_approval'
                    : (($o->approval_status ?? null) === 'approved' ? 'already_approved' : 'open'),
            ];
        })->values()->all();
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

        // ⭐ LOOK BACK A FEW TURNS, NOT JUST THIS ONE (owner-reported, Aug-2026).
        // This read only the newest user message, so the screenshot was lost the
        // moment a word was typed after it — "Sajid desi chicken" [photo] then
        // "only for today" recorded with no attachment, and every corrected card
        // (Al Shifa → ASTEH, twice) dropped the proof it was drafted from.
        // A few turns back within the same 10-minute window is still plainly
        // "the picture we are talking about".
        $rows = DB::table('t_ai_messages')
            ->where('conversation_id', $convId)
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->orderByDesc('id')
            ->limit(6)
            ->get(['media_path', 'input_type', 'created_at']);

        $row = null;
        foreach ($rows as $candidate) {
            if (!$candidate->media_path) {
                continue;
            }
            // Must be an IMAGE (not a voice note's audio path).
            if (($candidate->input_type ?? '') !== 'image'
                && !str_contains($candidate->media_path, 'img-')) {
                continue;
            }
            // ⚠ SPENT IMAGES ARE NOT REUSED. Once a screenshot is attached to a
            // card the user CONFIRMED, it belongs to that record — pulling it
            // onto the next, unrelated card would file a Petrol expense with
            // last transfer's receipt on it. A cancelled/replaced card's image
            // is still fair game: that is the correction case.
            // ⚠⚠ Matched on the BASENAME, deliberately: json_encode stores the
            // path with escaped slashes ("assistant\/2026\/..."), so a LIKE on
            // the raw path matches NOTHING — proven live. The filename part is
            // uniqid-based and slash-free, so it survives the escaping.
            $spent = DB::table('t_ai_drafts')
                ->where('user_id', $userId)
                ->where('status', 'confirmed')
                ->where('payload_json', 'like', '%' . basename($candidate->media_path) . '%')
                ->exists();
            if (!$spent) {
                $row = $candidate;
            }
            break; // only ever the newest image — an older one is not "this" one
        }

        if (!$row) return null;
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
    private function attachChatImage(array $payload, array $display, $user, ?int $replacesDraftId = null): array
    {
        $img = $this->currentTurnImage((int) $user->id);

        // ⭐ A CORRECTED CARD KEEPS ITS PROOF (owner-reported, Aug-2026).
        // "This is a vendor payment" re-drafts from scratch, and the screenshot
        // the first card carried used to vanish — the ledger row then had no
        // receipt at all (drafts 159→160 and 165→166, both Al Shifa/ASTEH).
        // Inheriting from the card being replaced also covers a correction made
        // long after the image was sent, where the time window has closed.
        if (!$img && $replacesDraftId) {
            $img = $this->attachmentOfDraft($replacesDraftId, (int) $user->id);
        }

        if ($img) {
            $payload['attachment_path'] = $img;           // non-underscore: survives to replay
            $display[] = ['label' => 'Attachment', 'value' => '📎 Screenshot'];
        }
        return [$payload, $display];
    }

    /** The screenshot a draft carries, if it is this user's and still readable. */
    private function attachmentOfDraft(int $draftId, int $userId): ?string
    {
        $row = DB::table('t_ai_drafts')
            ->where('id', $draftId)
            ->where('user_id', $userId)
            ->first(['payload_json']);
        if (!$row) {
            return null;
        }
        $path = (json_decode($row->payload_json, true) ?: [])['attachment_path'] ?? null;
        if (!$path) {
            return null;
        }
        try {
            return Storage::disk(config('whatsapp.media_disk', 'public'))->exists($path) ? $path : null;
        } catch (\Throwable $e) {
            return null;
        }
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
        $raw = trim((string) $value);

        // ⭐ HE WRITES DATES DAY-FIRST (owner-reported, Aug-2026). "Lacarne
        // 8.8.26" was recorded as 21-Aug — twice — and the ledger row had to be
        // edited by hand afterwards (row 19590, corrected to 2026-08-08).
        // Carbon reads 8/8/26 American-style and 13.8.26 not at all, so d.m.y
        // is settled HERE before it ever reaches the parser. Day-first is the
        // only reading that is ever right for this user; where the two agree
        // (8.8) it costs nothing, and where they differ it is the correct one.
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2}|\d{4})$/', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += 2000;
            }
            // If the first number cannot be a month but the second can, the
            // model sent it month-first — read it that way rather than reject a
            // date whose meaning is unambiguous.
            if ($mo > 12 && $d <= 12) {
                [$d, $mo] = [$mo, $d];
            }
            if ($d >= 1 && $d <= 31 && $mo >= 1 && $mo <= 12 && checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
            return null; // a real date was meant and it did not exist — don't guess
        }

        try {
            return \Carbon\Carbon::parse($raw)->toDateString();
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

    /** Is this a real, active bank? Checked before a card names one it cannot pay from. */
    private function bankExists(int $id): bool
    {
        return DB::table('t_fin_online_receiving_accounts')
            ->where('id', $id)->where('is_active', 1)->exists();
    }

    private function buName(int $id): string
    {
        return (string) (DB::table('t_fin_business_units')->where('id', $id)->value('name') ?? ('#' . $id));
    }

    /**
     * "Can this person really pay for THESE books out of THAT account?" — asked
     * with the same service RequestController::store() enforces, before the card
     * is built rather than after it is confirmed.
     *
     * Returns null when it is allowed, otherwise a sentence the model can say out
     * loud, naming what CAN pay for that unit — a refusal without an alternative
     * just makes the user guess.
     *
     * ⚠ Expenses only. Vendor payments are NOT gated by the account tags on the
     *   server (FIN\VendorController::recordPayment records whatever account it is
     *   given), and refusing here would block something that currently works.
     */
    private function sourceRefusal($user, ?AccountModel $source, ?int $businessUnitId, string $purpose): ?string
    {
        if (!$source) {
            return null;
        }

        // Employee-cash and other non-money categories are not payment sources at
        // all and are gated elsewhere — this rule is about cash/bank accounts.
        if (!in_array($source->account_category, [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK], true)) {
            return null;
        }

        $svc = app(PaymentSourceService::class);
        $bu  = $businessUnitId ?: 1;

        if ($svc->allows($user, $source->id, $bu, $purpose)) {
            return null;
        }

        $alternatives = collect($svc->sourcesFor($user, $bu, $purpose))
            ->pluck('display_name')->filter()->take(4)->implode(', ');

        return $source->account_name . ' cannot pay for ' . $this->buName($bu) . '.'
            . ($alternatives !== ''
                ? ' For ' . $this->buName($bu) . ' use: ' . $alternatives . '. Ask the user which one, and do not record this until they choose.'
                : ' Nothing this user is set up for can pay for those books — tell them to ask Taimur or Shabib to add them'
                  . ' (Ledger Hub → the account → "Who uses this account").');
    }
}
