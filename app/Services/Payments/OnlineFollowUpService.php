<?php

namespace App\Services\Payments;

use App\Models\CRM\OrderModel;
use App\Services\Payments\Signals\PaymentProofStatusService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Daily Closing "Payment Follow-ups" board (Aug-2026).
 *
 * Replaces the old "Online Payment - WhatsApp Messages (Today)" panel, which
 * listed EVERY online order delivered today in one undifferentiated pile keyed
 * to today() only. Two things made it unusable and it was abandoned (81%
 * coverage in Mar-2026 → 3% in Aug-2026):
 *
 *   1. ~two thirds of its "pending" rows had ALREADY paid — the panel shouted
 *      "33 Pending" when the real chase list was ~12. The badge became noise.
 *   2. an order not messaged before midnight vanished forever. There was no
 *      second chance at a customer who hadn't paid.
 *
 * This service answers both by splitting the same population into three tiers
 * and holding a row for a 3-day window instead of one calendar day:
 *
 *   TIER 1  chase     — no payment signal at all, money not approved. The only
 *                       tier with buttons. New customers (< 3 lifetime delivered
 *                       orders) are flagged for immediate action.
 *   TIER 2  proof_in  — a screenshot and/or a bank signal has landed but the
 *                       Online Approval hasn't been done yet. NOT a chase — the
 *                       approvals queue owns these. Collapsed in the UI.
 *   TIER 3  settled   — an approved online ledger row exists. Count only.
 *
 * WHY PROOF, NOT APPROVAL, MOVES A ROW OUT OF TIER 1: approval lags delivery by
 * days (of 20 orders delivered 14-Aug, 17 were still unapproved a week later).
 * Gating on approval would keep customers who paid on time in the chase list all
 * week. Proof is the signal that the customer has done their part.
 *
 * A row leaves Tier 1 only when proof arrives, the money is approved, or it ages
 * past the window — at which point the Online Approvals page (which already
 * lists it as an unapproved L1/L2 item, with its own invoice-bearing reminder)
 * takes over. Nothing falls through.
 *
 * Deliberately needs NO cron: the window is a query bound re-derived on every
 * page load. Prod has no scheduler (see memory: prod-has-no-scheduler-cron).
 *
 * Read-only. Shared by the web Daily Closing page and the mobile daily-closing
 * API so the two can never drift — they were copy-pasted before this.
 */
class OnlineFollowUpService
{
    /** How many days a chase row is held, including the delivery day itself. */
    public const WINDOW_DAYS = 3;

    /** Below this many lifetime delivered orders a customer needs chasing first. */
    public const NEW_CUSTOMER_ORDER_THRESHOLD = 3;

    /**
     * Payment methods that mean "customer owes us a bank transfer". Copied
     * verbatim from the panel this replaces so the population is unchanged.
     */
    public const ONLINE_PAYMENT_METHODS = [
        'online', 'Online', 'bank_transfer', 'card',
        'online_payment', 'direct_bank_transfer', 'bacs',
    ];

    /**
     * Templates that count as "we chased this order". Used to read the reminder
     * history back out of the WhatsApp send log.
     *
     * The RETIRED `delivery_confirmation_online` stays listed on purpose: it is
     * how every reminder sent before Aug-2026 is recorded, and dropping it would
     * silently reset those orders' reminder counts to zero.
     */
    public const REMINDER_TEMPLATES = [
        'delivery_confirmation_online',
        'delivery_confirmation_online_v2',
        'delivery_confirmation_cash',
        'payment_reminder_single',
        'payment_reminder_multiples',
    ];

    /**
     * Day 1 of the ladder: the delivery confirmation.
     *
     * Aug-2026 — now `_v2`, which carries a "Get bank details" quick-reply
     * button INSTEAD of printing the account numbers in the body (tapping it
     * replies with them; see WhatsAppService::maybeAnswerBankDetailsRequest).
     * Same 4 variables as the template it replaces, so every caller is unchanged.
     *
     * The `order_delivered_payment_confirmation` automation normally sends this
     * at the moment of delivery; the button here remains the manual path for
     * when the automation is off, skipped, or its send failed.
     */
    public const TEMPLATE_DAY_ONE = 'delivery_confirmation_online_v2';

    /** Day 2+: the outstanding-invoice reminder, invoice image auto-attached. */
    public const TEMPLATE_FOLLOW_UP = 'payment_reminder_single';

    /**
     * Sep-2026 — the multi-invoice reminder, used when one message covers
     * several of a customer's unpaid bills. Takes 3 body params like the single
     * one, but they mean different things: [first name, "NF-1, NF-2", TOTAL].
     *
     * ⚠ It declares NO media header, so it must NEVER be sent with an order_id:
     * order_id triggers the invoice-image attach and Meta rejects a header
     * component on a template that doesn't declare one. The orders it covers are
     * recorded through related_order_number(s) instead.
     */
    public const TEMPLATE_FOLLOW_UP_MULTI = 'payment_reminder_multiples';

    /**
     * Ledger approval statuses that still mean "this invoice is waiting for the
     * customer's money".
     *
     * ⚠⚠ `pending_l2` is deliberately ABSENT. BalancePostingService runs at L1 —
     * once an invoice clears L1 the money is already in the balances and L2 only
     * verifies it (the same reason this board's own `proof_l1_done` group has no
     * buttons). Listing an L1-approved invoice as an outstanding bill would ask a
     * customer for money we have already booked.
     */
    public const OPEN_BILL_STATUSES = ['pending', 'pending_l1'];

    /**
     * Build the board.
     *
     * @param  string|null  $riderFilter  't_fin_account' id, or 'all'
     * @return array|null  null when nothing was delivered in the window at all
     */
    public function build(?string $riderFilter = 'all'): ?array
    {
        // `?rider=` with an empty value arrives as null/'' — both mean "everyone".
        $riderFilter = ($riderFilter === null || $riderFilter === '') ? 'all' : $riderFilter;

        $windowStart = Carbon::today()->subDays(self::WINDOW_DAYS - 1);

        $orders = $this->fetchOrders($riderFilter, $windowStart);

        if ($orders->isEmpty()) {
            return null;
        }

        $orderIds = $orders->pluck('id')->map(fn ($id) => (int) $id)->all();

        $settledIds     = array_flip(PaymentProofStatusService::settledOrderIds($orderIds));
        $proofMap       = $this->proofMap($orderIds);
        $deliveryMap    = $this->deliveryTimestamps($orderIds);
        $customerCounts = $this->lifetimeOrderCounts($orders);
        $reminderMap    = $this->reminderHistory($orders);
        // The order's own unapproved invoice row, so a proof can be approved
        // from this screen instead of hopping to Online Approvals.
        $ledgerMap      = $this->pendingLedgerMap($orderIds);

        $chase   = [];
        $proofIn = [];
        $settledCount = 0;
        $settledAmount = 0.0;

        foreach ($orders as $order) {
            $id = (int) $order->id;

            if (isset($settledIds[$id])) {
                $settledCount++;
                $settledAmount += (float) $order->total_price;
                continue;
            }

            $proof = $proofMap[$id] ?? null;
            $row   = $this->buildRow($order, $deliveryMap, $customerCounts, $reminderMap, $proof);

            // What an approver can do with this order right now. Null when the
            // invoice row is missing (nothing to approve) — the UI then shows
            // no button rather than a dead one.
            $led = $ledgerMap[$id] ?? null;
            $row['ledger_id']    = $led['id']     ?? null;
            $row['ledger_stage'] = $led['stage']  ?? null;
            $row['awaiting_l2']  = ($led['stage'] ?? null) === 'pending_l2';
            $row['can_approve']  = $led !== null && !$row['awaiting_l2'];

            if (($proof['status'] ?? PaymentProofStatusService::NONE) === PaymentProofStatusService::NONE) {
                $chase[] = $row;
            } else {
                $proofIn[] = $row;
            }
        }

        // Sep-2026 — what ELSE this customer owes. The board is per ORDER, so a
        // customer with an older unpaid bill (or two rows on this very board)
        // looked like a one-invoice chase and got a one-invoice reminder. Only
        // Online Approvals knew about the rest, which is where the multi-invoice
        // reminder lives. This hands the same knowledge to the chase row.
        $chase = $this->attachOtherOpenBills($chase, $orderIds);

        $chase   = $this->sortChase($chase);
        $proofIn = $this->sortProofIn($proofIn);

        // Tier 2 splits by what is left to DO, not by what arrived:
        //   review  — proof landed, invoice still unapproved. The only group
        //             with an Approve button.
        //   l1_done — already approved at L1, so the money is ALREADY in the
        //             balances (BalancePostingService runs at L1; L2 only
        //             verifies). Kept visible for the record until L2 clears it,
        //             but with nothing to press.
        // Without this an order looked identical before and after approving it.
        $proofReview = array_values(array_filter($proofIn, fn ($r) => !$r['awaiting_l2']));
        $proofL1Done = array_values(array_filter($proofIn, fn ($r) => $r['awaiting_l2']));

        $newCustomerRows = array_values(array_filter($chase, fn ($r) => $r['is_new_customer']));

        // The chase tier splits again for display. Showing all three days of
        // established customers open was too much noise to read on a daily basis:
        //   PRIMARY   — new customers (any day) + everyone delivered TODAY. Open.
        //               Day 1 is the moment the confirmation-and-bank-details
        //               message is worth sending, and a new customer is worth
        //               chasing on every one of the three days.
        //   SECONDARY — established customers from day 2-3. Collapsed. They are
        //               already sitting in Online Approvals as unapproved L1/L2
        //               items with their own reminder button, so this panel does
        //               not need to shout about them a second and third time —
        //               but they stay one click away rather than disappearing.
        //   MESSAGED   — already messaged TODAY (by the automation or by hand).
        //               Collapsed and buttonless: the send button is disabled for
        //               the rest of the day anyway, so leaving these in the open
        //               group would fill it with rows that need no action. Once
        //               the delivered->payment-confirmation automation is on this
        //               is most of day 1, which is exactly the clutter to avoid.
        //               They return to the OPEN group tomorrow as day 2, where
        //               the button offers the invoice-bearing payment reminder.
        $isMessagedToday = fn ($r) => !empty($r['reminded_today']);
        $chaseMessaged = array_values(array_filter($chase, $isMessagedToday));
        $chaseOpen     = array_values(array_filter($chase, fn ($r) => !$isMessagedToday($r)));

        $isPrimary = fn ($r) => $r['is_new_customer'] || $r['day_number'] === 1;

        $chasePrimary   = array_values(array_filter($chaseOpen, $isPrimary));
        $chaseSecondary = array_values(array_filter($chaseOpen, fn ($r) => !$isPrimary($r)));

        return [
            'window_days'    => self::WINDOW_DAYS,
            'window_from'    => $windowStart->toDateString(),
            'generated_at'   => Carbon::now()->format('H:i'),

            // The template names, published so a CLIENT never has to hardcode
            // one. The mobile Daily Closing screen hardcoded the day-1 name and
            // kept sending the retired body (with our retired bank accounts)
            // long after it was replaced; every row already carries its own
            // `template`, and this covers the 422 fallback path too.
            'templates'      => [
                'day_one'         => self::TEMPLATE_DAY_ONE,
                'follow_up'       => self::TEMPLATE_FOLLOW_UP,
                'follow_up_multi' => self::TEMPLATE_FOLLOW_UP_MULTI,
            ],

            'chase'          => $chase,
            'chase_primary'   => $chasePrimary,
            'chase_secondary' => $chaseSecondary,
            'chase_messaged'  => $chaseMessaged,
            'proof_in'       => $proofIn,
            // The same rows, grouped by what is left to do. 'proof_in' is kept
            // whole because the legacy mobile shape below is built from it.
            'proof_review'   => $proofReview,
            'proof_l1_done'  => $proofL1Done,

            'chase_count'         => count($chase),
            'chase_amount'        => (int) round(array_sum(array_column($chase, 'amount'))),
            'chase_primary_count'    => count($chasePrimary),
            'chase_primary_amount'   => (int) round(array_sum(array_column($chasePrimary, 'amount'))),
            'chase_secondary_count'  => count($chaseSecondary),
            'chase_secondary_amount' => (int) round(array_sum(array_column($chaseSecondary, 'amount'))),
            'chase_messaged_count'   => count($chaseMessaged),
            'chase_messaged_amount'  => (int) round(array_sum(array_column($chaseMessaged, 'amount'))),
            'chase_auto_count'       => count(array_filter($chaseMessaged, fn ($r) => !empty($r['auto_messaged']))),
            'new_customer_count'  => count($newCustomerRows),
            'new_customer_amount' => (int) round(array_sum(array_column($newCustomerRows, 'amount'))),
            'proof_in_count'      => count($proofIn),
            'proof_in_amount'     => (int) round(array_sum(array_column($proofIn, 'amount'))),
            'proof_review_count'  => count($proofReview),
            'proof_review_amount' => (int) round(array_sum(array_column($proofReview, 'amount'))),
            'proof_l1_done_count' => count($proofL1Done),
            'proof_l1_done_amount'=> (int) round(array_sum(array_column($proofL1Done, 'amount'))),
            'settled_count'       => $settledCount,
            'settled_amount'      => (int) round($settledAmount),
            'total_count'         => $orders->count(),

            // Tier 2 broken out by what kind of proof landed, so "bank signal but
            // no screenshot" is visible as its own thing rather than lumped in.
            // Describes the REVIEW group its chips sit on — an L1-done row's
            // kind would otherwise appear on a group it is not in.
            'proof_in_breakdown'  => $this->proofBreakdown($proofReview),
        ];
    }

    /**
     * The orders in scope: delivered (per status history) inside the window,
     * on an online payment method.
     *
     * Uses status history rather than a delivery_date column because
     * delivery_date is a computed accessor, not a real column — the same reason
     * the panel this replaces did it this way.
     */
    private function fetchOrders(string $riderFilter, Carbon $windowStart): Collection
    {
        $query = OrderModel::query()
            ->whereIn('order_status', ['delivered', 'completed'])
            ->whereIn('payment_method', self::ONLINE_PAYMENT_METHODS)
            // Shop (B2B) customers are collected completely differently: they run
            // a rolling balance settled FIFO from the Shop tab of Online
            // Approvals, not per-order. Chasing them one delivery at a time
            // contradicts how the business actually bills them, so they are out
            // of this panel entirely — same rule, and the same predicate, that
            // keeps them out of the regular Online Approvals queues
            // (ApprovalController::excludeShopCustomers).
            //
            // Orders with no customer record at all are KEPT: whereDoesntHave is
            // true when there is no related row, which is the behaviour we want
            // (an unlinked walk-up order still owes us money).
            ->whereDoesntHave('customer', function ($c) {
                $c->where('customer_type', \App\Models\CRM\CustomerModel::TYPE_SHOP);
            })
            ->whereExists(function ($q) use ($windowStart) {
                $q->select(\DB::raw(1))
                    ->from('t_crm_order_status_history as h')
                    ->whereColumn('h.order_id', 't_crm_prod_order.id')
                    ->where('h.status_code', 'delivered')
                    ->where('h.changed_at', '>=', $windowStart->copy()->startOfDay())
                    ->where('h.changed_at', '<', Carbon::today()->copy()->addDay()->startOfDay());
            })
            ->with(['customer', 'assignedRider']);

        if ($riderFilter !== 'all') {
            $riderAccount = \App\Models\FIN\AccountModel::find($riderFilter);
            // An account with no linked user can't own orders. Returning an
            // impossible predicate (rather than ignoring the filter) keeps the
            // panel honest: a filter that can't match shows nothing, instead of
            // silently showing everyone.
            $query->where('assigned_rider_user_id', $riderAccount->user_id ?? 0);
        }

        return $query->orderBy('id', 'desc')->get();
    }

    /** Payment-proof status per order. Never fatal — no signals = no badges. */
    /**
     * Each order's own unapproved online invoice row: [order_id => [id, stage]].
     *
     * ⚠ INVOICE-QUEUE TYPES ONLY, exactly as PaymentProofStatusService::settledOrderIds
     * filters. A banked overpayment also writes a mode=online ledger row tagged
     * with the order; approving THAT from here would be a different transaction
     * entirely. Same trap that once hid the proof badge — see memory:
     * credit-ledger-rows-contaminate-order-queries.
     *
     * Orders already fully approved never reach this method: they were removed
     * as "settled" before the loop. One query for the whole board.
     */
    private function pendingLedgerMap(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }

        try {
            return \App\Models\FIN\LedgerModel::query()
                ->whereIn('order_id', $orderIds)
                ->where('mode', 'online')
                ->whereIn('transaction_type', [
                    \App\Models\FIN\LedgerModel::TYPE_INVOICE,
                    \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT,
                ])
                ->whereIn('approval_status', ['pending', 'pending_l1', 'pending_l2'])
                // Oldest first, so ->keyBy keeps the LAST (newest) per order if
                // an order somehow carries two open rows.
                ->orderBy('id')
                ->get(['id', 'order_id', 'approval_status'])
                ->keyBy('order_id')
                ->map(fn ($r) => ['id' => (int) $r->id, 'stage' => $r->approval_status])
                ->all();
        } catch (\Throwable $e) {
            // A lookup failure must not blank the board — it only costs the
            // Approve button.
            \Log::warning('OnlineFollowUp: pending ledger lookup failed (non-critical)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Sep-2026 — attach each chase row's OTHER unpaid online invoices.
     *
     * WHY: this board is built per ORDER inside a 3-day window, so a customer
     * with an older unpaid bill showed up here as a single-invoice chase and got
     * the single-invoice reminder. The second bill was visible only in Online
     * Approvals, which groups by customer and switches to the multi-invoice
     * template on its own. The operator on this screen could not know. On the
     * replica 5 of 8 chase rows had another open bill, and one customer had TWO
     * rows on the same board — so this is the normal case, not an edge case.
     *
     * WHAT COUNTS AS AN OPEN BILL: the same predicate Online Approvals uses for
     * its L1 queue — an unapproved online invoice-queue ledger row (see
     * OPEN_BILL_STATUSES for why pending_l2 is excluded).
     *
     * ⚠ Rs 0 rows are dropped. There are live Rs 0 invoices on this board (see
     * memory: zero-amount-invoices-immortal-on-daily-closing) and there is
     * nothing to collect on one — offering it as a bill to chase would put "Rs 0"
     * into a customer's reminder.
     *
     * ⚠ Bills that already carry payment proof are RETURNED but flagged
     * `has_proof`, and the UI leaves them unticked. They are excluded from the
     * send by default (asking for money the customer has already sent is the
     * exact mistake this panel exists to prevent) while still being visible, so
     * the operator can see the customer's whole position before deciding.
     *
     * @param  array  $boardOrderIds  every order on this board, so a bill that is
     *                                also a row here can be marked `in_window`.
     */
    private function attachOtherOpenBills(array $chase, array $boardOrderIds): array
    {
        // Every row gets the keys whether or not it has other bills, so no
        // consumer — blade, mobile, JSON — has to guard for their absence.
        foreach ($chase as &$blankRow) {
            $blankRow['other_open_bills']        = [];
            $blankRow['other_bills_count']       = 0;
            $blankRow['other_bills_open_count']  = 0;
            $blankRow['other_bills_open_amount'] = 0;
            $blankRow['other_bills_proof_count'] = 0;
            $blankRow['combined_total']          = $blankRow['amount'];
        }
        unset($blankRow);

        $customerIds = array_values(array_unique(array_filter(array_column($chase, 'customer_id'))));

        if (empty($customerIds)) {
            return $chase;
        }

        try {
            $bills = \DB::table('t_fin_ledger as l')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
                ->whereIn('o.customer_id', $customerIds)
                ->where('l.mode', 'online')
                ->whereIn('l.transaction_type', [
                    \App\Models\FIN\LedgerModel::TYPE_INVOICE,
                    \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT,
                ])
                ->whereIn('l.approval_status', self::OPEN_BILL_STATUSES)
                // request_id rows are reimbursements/expenses riding the same
                // table, not customer invoices — the same filter Online
                // Approvals' L1 query uses.
                ->whereNull('l.request_id')
                // An invoice can be posted before delivery; only a delivered
                // order is money the customer actually owes us today.
                ->whereIn('o.order_status', ['delivered', 'completed'])
                ->where('l.amount', '>', 0)
                ->orderBy('o.id')
                ->get(['l.amount', 'l.approval_status', 'o.id as order_id', 'o.order_number', 'o.customer_id']);
        } catch (\Throwable $e) {
            // Never blank the board for this — it only costs the extra context.
            \Log::warning('OnlineFollowUp: other-open-bills lookup failed (non-critical)', ['error' => $e->getMessage()]);
            return $chase;
        }

        if ($bills->isEmpty()) {
            return $chase;
        }

        $billOrderIds = $bills->pluck('order_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
        $deliveredMap = $this->deliveryTimestamps($billOrderIds);
        $billProofs   = $this->proofMap($billOrderIds);
        $onBoard      = array_flip(array_map('intval', $boardOrderIds));

        $byCustomer = [];
        foreach ($bills as $bill) {
            $byCustomer[(int) $bill->customer_id][] = $bill;
        }

        $today = Carbon::today();

        foreach ($chase as &$row) {
            $candidates = $byCustomer[$row['customer_id']] ?? [];
            $others     = [];

            foreach ($candidates as $bill) {
                $billOrderId = (int) $bill->order_id;

                // The row's own invoice is not an "other" bill.
                if ($billOrderId === $row['id']) {
                    continue;
                }

                $deliveredAt = ($rec = $deliveredMap->get($billOrderId))
                    ? Carbon::parse($rec->changed_at)
                    : null;

                // Unclamped on purpose: the whole point is to surface bills that
                // have aged PAST the board's 3-day window, so a "Day 4" must be
                // allowed to say so rather than being pinned at 3.
                $ageDays = $deliveredAt
                    ? (int) $deliveredAt->copy()->startOfDay()->diffInDays($today)
                    : null;

                $proof    = $billProofs[$billOrderId] ?? null;
                $hasProof = $proof && ($proof['status'] ?? PaymentProofStatusService::NONE) !== PaymentProofStatusService::NONE;

                $others[] = [
                    'id'            => $billOrderId,
                    'order_number'  => $bill->order_number,
                    'amount'        => (int) round((float) $bill->amount),
                    'delivery_date' => $deliveredAt ? $deliveredAt->format('M d, Y') : null,
                    'age_days'      => $ageDays,
                    'age_label'     => $this->ageLabel($ageDays),
                    // TRUE when this bill is also a row on this very board (a
                    // customer with two deliveries inside the window). The UI
                    // says so, and a send has to grey BOTH rows out.
                    'in_window'     => isset($onBoard[$billOrderId]),
                    'has_proof'     => $hasProof,
                    'proof_label'   => $hasProof ? ($proof['label'] ?? null) : null,
                    'ledger_stage'  => $bill->approval_status,
                ];
            }

            if (empty($others)) {
                continue;
            }

            // Oldest first: the most overdue bill is the one worth naming first
            // in a message, and it is the one the operator will be asked about.
            usort($others, fn ($a, $b) => ($b['age_days'] ?? 0) <=> ($a['age_days'] ?? 0));

            $open = array_values(array_filter($others, fn ($b) => !$b['has_proof']));

            $row['other_open_bills']        = $others;
            $row['other_bills_count']       = count($others);
            $row['other_bills_open_count']  = count($open);
            $row['other_bills_open_amount'] = (int) array_sum(array_column($open, 'amount'));
            $row['other_bills_proof_count'] = count($others) - count($open);
            // What the "all bills" reminder would ask for: this invoice plus the
            // other bills that are ticked by default.
            $row['combined_total']          = $row['amount'] + $row['other_bills_open_amount'];
        }
        unset($row);

        return $chase;
    }

    /**
     * Stamp "last reminded at" on one or more online orders.
     *
     * Sep-2026 — one reminder can now cover SEVERAL of a customer's unpaid bills,
     * so the stamp has to land on every order the message named. Otherwise the
     * bills that were not the primary still read "never reminded" and get chased
     * again tomorrow for money already asked for once.
     *
     * Lives here rather than in either controller because the web Daily Closing
     * page and the mobile API both do this, and they were copy-pasted once
     * before (see the class docblock).
     *
     * Validates each order independently and reports per order: a set where one
     * id is bad must still stamp the rest, because the WhatsApp message has
     * ALREADY gone out by the time this is called — refusing the whole batch
     * would lose the record of a send that really happened.
     *
     * @return array{stamped: array<int,string>, skipped: array<int,string>}
     */
    public function stampReminded(array $orderIds, ?int $userId): array
    {
        $stamped = [];
        $skipped = [];

        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));

        foreach ($ids as $id) {
            $order = \App\Models\CRM\OrderModel::find($id);

            if (!$order) {
                $skipped[$id] = 'Order not found';
                continue;
            }

            if (!in_array($order->order_status, ['delivered', 'completed'])) {
                $skipped[$id] = 'Order must be delivered first';
                continue;
            }

            $paymentMethod = strtolower($order->payment_method ?? 'cash');
            if (in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod'])) {
                $skipped[$id] = 'This is not an online payment order';
                continue;
            }

            $order->online_message_sent_at = now();
            $order->online_message_sent_by = $userId;
            $order->save();

            $stamped[$id] = $order->online_message_sent_at->format('h:i A');
        }

        if (!empty($stamped)) {
            \Log::info('OnlineFollowUp: reminder stamped', [
                'order_ids' => array_keys($stamped),
                'skipped'   => $skipped,
                'user_id'   => $userId,
            ]);
        }

        return ['stamped' => $stamped, 'skipped' => $skipped];
    }

    /** "today" / "yesterday" / "4 days ago". Phrased once, for both clients. */
    private function ageLabel(?int $ageDays): ?string
    {
        if ($ageDays === null) {
            return null;
        }

        return match (true) {
            $ageDays <= 0 => 'today',
            $ageDays === 1 => 'yesterday',
            default => $ageDays . ' days ago',
        };
    }

    private function proofMap(array $orderIds): array
    {
        if (!config('payment_signals.enabled')) {
            return [];
        }

        try {
            // suppressSettled: false — this is a RECORD surface. Settled orders
            // are removed by tier, not by hiding their badge.
            return app(PaymentProofStatusService::class)->forOrders($orderIds, suppressSettled: false);
        } catch (\Throwable $e) {
            \Log::warning('OnlineFollowUp: proof lookup failed (non-critical)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** order_id => the moment it was marked delivered. */
    private function deliveryTimestamps(array $orderIds): Collection
    {
        if (empty($orderIds)) {
            return collect();
        }

        // An order can carry more than one 'delivered' history row (re-delivery,
        // status corrections). The FIRST is when the customer actually got it,
        // which is what the day counter and the message must both use.
        return \DB::table('t_crm_order_status_history')
            ->whereIn('order_id', $orderIds)
            ->where('status_code', 'delivered')
            ->orderBy('changed_at')
            ->get(['order_id', 'changed_at'])
            ->keyBy('order_id');
    }

    /**
     * customer_id => lifetime delivered/completed order count, used for the
     * new-customer flag. One grouped query, not per row.
     */
    private function lifetimeOrderCounts(Collection $orders): Collection
    {
        $customerIds = $orders->pluck('customer_id')->filter()->unique()->values();

        if ($customerIds->isEmpty()) {
            return collect();
        }

        return \DB::table('t_crm_prod_order')
            ->whereIn('customer_id', $customerIds)
            ->whereIn('order_status', ['delivered', 'completed'])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS c')
            ->pluck('c', 'customer_id');
    }

    /**
     * order_number => ['count' => n, 'last_at' => Carbon] read from the WhatsApp
     * send log.
     *
     * NOTE: t_wa_messages.related_order_number was only ever stamped on INVOICE
     * sends until Aug-2026 — saveOutboundMessage() never set it, and the column
     * isn't in MessageModel::$fillable, so every one of the 852 reminders sent
     * before this change logged NULL. Both were fixed alongside this service, so
     * counts build up from now on. Historic rows fall back to the
     * online_message_sent_at stamp, which is why the UI shows "reminded <when>"
     * and only adds "xN" once N > 1.
     */
    private function reminderHistory(Collection $orders): Collection
    {
        $numbers = $orders->pluck('order_number')->filter()->unique()->values();

        if ($numbers->isEmpty()) {
            return collect();
        }

        try {
            if (!\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
                return collect();
            }

            $history = \DB::table('t_wa_messages')
                ->whereIn('related_order_number', $numbers)
                ->whereIn('template_name', self::REMINDER_TEMPLATES)
                ->where('direction', 'outbound')
                ->where('status', '!=', 'failed')
                ->groupBy('related_order_number')
                ->selectRaw('related_order_number, COUNT(*) AS c, MAX(created_at) AS last_at')
                ->get()
                ->mapWithKeys(fn ($r) => [$r->related_order_number => [
                    'count'   => (int) $r->c,
                    'last_at' => $r->last_at,
                ]]);

            return $this->mergeMultiInvoiceHistory($history, $numbers);
        } catch (\Throwable $e) {
            \Log::warning('OnlineFollowUp: reminder history lookup failed', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * Fold in the reminders that covered SEVERAL invoices at once.
     *
     * ⚠⚠ `related_order_number` is one varchar — it can only ever name the
     * message's primary order, so a multi-invoice reminder was invisible to the
     * query above for every other bill it covered. (Before Sep-2026 it was worse:
     * not one of the 33 multi sends in 60 days carried ANY order at all, so the
     * whole template was missing from this history.) The full list now rides in
     * `t_wa_messages.metadata` under `related_order_numbers`, which needs no
     * schema change.
     *
     * Read as a small bounded FETCH, deliberately not a `LIKE '%…%'` scan:
     * metadata is an unindexed longtext, and there is no index a wildcard search
     * could use. Multi sends run at roughly 33 per 60 days, and every order on
     * this board was delivered inside the window, so a reminder about one cannot
     * predate the window — which makes this handful of rows both cheap and
     * complete.
     */
    private function mergeMultiInvoiceHistory(Collection $history, Collection $numbers): Collection
    {
        if (!\Schema::hasColumn('t_wa_messages', 'metadata')) {
            return $history;
        }

        $wanted = array_flip($numbers->all());

        $rows = \DB::table('t_wa_messages')
            ->where('template_name', self::TEMPLATE_FOLLOW_UP_MULTI)
            ->where('direction', 'outbound')
            ->where('status', '!=', 'failed')
            ->where('created_at', '>=', Carbon::today()->subDays(self::WINDOW_DAYS - 1)->startOfDay())
            ->whereNotNull('metadata')
            ->get(['metadata', 'created_at', 'related_order_number']);

        foreach ($rows as $row) {
            $meta = json_decode((string) $row->metadata, true);
            $list = is_array($meta) ? ($meta['related_order_numbers'] ?? null) : null;

            if (!is_array($list)) {
                continue;
            }

            foreach ($list as $number) {
                if (!is_string($number) || !isset($wanted[$number])) {
                    continue;
                }

                // The primary order is already counted by the indexed query —
                // counting it again here would read "×2" for a single send.
                if ($number === $row->related_order_number) {
                    continue;
                }

                $prev = $history->get($number, ['count' => 0, 'last_at' => null]);

                $history->put($number, [
                    'count'   => $prev['count'] + 1,
                    'last_at' => ($prev['last_at'] === null || $row->created_at > $prev['last_at'])
                        ? $row->created_at
                        : $prev['last_at'],
                ]);
            }
        }

        return $history;
    }

    /** One display row. */
    private function buildRow($order, Collection $deliveryMap, Collection $customerCounts, Collection $reminderMap, ?array $proof): array
    {
        $id = (int) $order->id;

        $deliveredAt = ($rec = $deliveryMap->get($id))
            ? Carbon::parse($rec->changed_at)
            : Carbon::today();

        // Day 1 = delivered today. Bounded to the window so a stray history row
        // outside it can't render a "Day 9" chip.
        $dayNumber = min(
            self::WINDOW_DAYS,
            max(1, $deliveredAt->copy()->startOfDay()->diffInDays(Carbon::today()) + 1)
        );

        $lifetimeOrders = (int) ($customerCounts[$order->customer_id] ?? 0);

        $lastRemindedAt = $order->online_message_sent_at
            ? Carbon::parse($order->online_message_sent_at)
            : null;

        $history       = $reminderMap->get($order->order_number);
        $reminderCount = $history ? (int) $history['count'] : 0;

        // The WA log is authoritative once stamped; before Aug-2026 it wasn't
        // written at all, so a legacy online_message_sent_at still counts as one.
        if ($reminderCount === 0 && $lastRemindedAt) {
            $reminderCount = 1;
        }

        return [
            'id'             => $id,
            'order_number'   => $order->order_number,
            // Needed to find the customer's OTHER open bills. Grouped by id, not
            // by the display name Online Approvals groups on: two customers can
            // share a name, and one customer's name can be spelled two ways
            // across the order address and the customer record.
            'customer_id'    => $order->customer_id ? (int) $order->customer_id : null,
            'customer_name'  => $this->customerName($order),
            'customer_phone' => $this->customerPhone($order),
            'rider_name'     => $order->assignedRider->fullname ?? 'Unassigned',
            'rider_user_id'  => $order->assigned_rider_user_id,
            'amount'         => (int) round($order->total_price),

            'delivery_date'  => $deliveredAt->format('M d, Y'),
            'delivery_time'  => $deliveredAt->format('h:i A'),
            'day_number'     => $dayNumber,
            'is_last_day'    => $dayNumber >= self::WINDOW_DAYS,

            'lifetime_orders' => $lifetimeOrders,
            'is_new_customer' => $lifetimeOrders < self::NEW_CUSTOMER_ORDER_THRESHOLD,
            'customer_ordinal' => $this->ordinal($lifetimeOrders),

            'reminder_count'   => $reminderCount,
            'last_reminded_at' => $lastRemindedAt?->format('h:i A'),
            'last_reminded_on' => $lastRemindedAt?->format('M d'),
            'reminded_today'   => $lastRemindedAt ? $lastRemindedAt->isToday() : false,
            // TRUE when the delivered-payment-confirmation AUTOMATION sent it,
            // not a person. Every one of the 1,809 historic manual sends stamped
            // a user id, and only the automation's afterSent hook writes NULL,
            // so sent_at-with-no-sent_by is an unambiguous system marker.
            'auto_messaged'    => $lastRemindedAt !== null && $order->online_message_sent_by === null,
            'reminded_label'   => $this->remindedLabel($lastRemindedAt, $reminderCount),

            // Day 1 confirms delivery; day 2+ chases an outstanding invoice.
            // Both templates are already approved by Meta — nothing new needed.
            'template'       => $dayNumber === 1 ? self::TEMPLATE_DAY_ONE : self::TEMPLATE_FOLLOW_UP,
            'button_label'   => $reminderCount > 0 ? 'Remind again' : 'Send reminder',

            'payment_proof'  => $proof,
        ];
    }

    /** "reminded today 6:12 PM" / "reminded Aug 21 x2" / null when never. */
    private function remindedLabel(?Carbon $at, int $count): ?string
    {
        if (!$at) {
            return null;
        }

        $when = $at->isToday()
            ? 'today ' . $at->format('h:i A')
            : ($at->isYesterday() ? 'yesterday ' . $at->format('h:i A') : $at->format('M d'));

        return 'reminded ' . $when . ($count > 1 ? " ×{$count}" : '');
    }

    /** 0 lifetime orders shouldn't read "0th" — treat as the first. */
    private function ordinal(int $count): string
    {
        return match (max(1, $count)) {
            1 => '1st order',
            2 => '2nd order',
            3 => '3rd order',
            default => $count . 'th order',
        };
    }

    /**
     * Chase order = what to do first: new customers, then the rows about to age
     * out of the window, then biggest money. Rows already reminded today sink
     * below rows not yet touched at the same urgency.
     */
    private function sortChase(array $rows): array
    {
        usort($rows, function ($a, $b) {
            return [$b['is_new_customer'], $a['reminded_today'], $b['day_number'], $b['amount']]
                <=> [$a['is_new_customer'], $b['reminded_today'], $a['day_number'], $a['amount']];
        });

        return $rows;
    }

    /** Tier 2 has no actions — newest delivery first reads as a log. */
    private function sortProofIn(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$a['day_number'], -$a['amount']] <=> [$b['day_number'], -$b['amount']]);

        return $rows;
    }

    /** Counts per proof status so Tier 2's summary can name what landed. */
    private function proofBreakdown(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $status = $row['payment_proof']['status'] ?? PaymentProofStatusService::NONE;

            if (!isset($out[$status])) {
                $out[$status] = [
                    'status' => $status,
                    'label'  => PaymentProofStatusService::label($status),
                    'color'  => PaymentProofStatusService::color($status),
                    'count'  => 0,
                    'amount' => 0,
                ];
            }

            $out[$status]['count']++;
            $out[$status]['amount'] += $row['amount'];
        }

        // Verified → screenshot → bank-only → mismatch, so the strongest
        // evidence reads first.
        $rank = [
            PaymentProofStatusService::VERIFIED        => 0,
            PaymentProofStatusService::PROOF_RECEIVED  => 1,
            PaymentProofStatusService::BANK_CONFIRMED  => 2,
            PaymentProofStatusService::AMOUNT_MISMATCH => 3,
        ];
        uasort($out, fn ($a, $b) => ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9));

        return array_values($out);
    }

    private function customerName($order): string
    {
        $fromAddress = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));

        if ($fromAddress !== '') {
            return $fromAddress;
        }

        if ($order->customer) {
            $name = trim($order->customer->first_name . ' ' . $order->customer->last_name);
            if ($name !== '') {
                return $name;
            }
        }

        return 'N/A';
    }

    private function customerPhone($order): string
    {
        if (!empty($order->address_phone)) {
            return (string) $order->address_phone;
        }

        if ($order->customer) {
            return (string) ($order->customer->phone_original ?? $order->customer->phone ?? '');
        }

        return '';
    }

    /**
     * A handful of counters describing everything the Daily Closing page would
     * show differently if it were reloaded right now. Deliberately AGGREGATE
     * ONLY — no rows, no relations, no proof payloads — because this is polled
     * on a timer and build() costs ~15 queries / ~270ms, which is not.
     *
     * The page renders its own baseline into the DOM at load; the poller diffs
     * against it and offers a refresh. It reports NEW ACTIVITY SINCE LOAD, not
     * "your filtered view changed" — deliveries and proofs are what actually go
     * stale on this screen, and they don't depend on the filter bar.
     *
     * Returns ints only, so it is safe to embed and cheap to compare.
     */
    public function heartbeat(): array
    {
        $windowStart = Carbon::today()->subDays(self::WINDOW_DAYS - 1)->startOfDay();

        // Deliveries counted straight off the status-history table rather than
        // off orders. Two reasons: it is ONE indexed table with no join, no
        // whereExists and no shop check (5ms vs 71ms for the order-side version,
        // measured — this is polled, so that matters); and it counts CASH
        // deliveries too, which is the other half of what goes stale on this page
        // (a cash delivery posts an invoice row to the rider's employee-cash
        // account and the outstanding list won't show it until reload).
        //
        // It is a CHANGE DETECTOR, not a display figure — the page never renders
        // this number, it only compares it with the one it was born with.
        $deliveries = \DB::table('t_crm_order_status_history')
            ->where('status_code', 'delivered')
            ->where('changed_at', '>=', $windowStart)
            ->count();

        // Payment proofs matched to anything recent, and online money approved in
        // the last few days. Both counted straight off their own tables — no
        // per-order fan-out.
        $proofs = 0;
        if (config('payment_signals.enabled')) {
            try {
                $proofs = \DB::table('t_fin_payment_signal')
                    ->whereIn('status', ['matched', 'amount_mismatch'])
                    ->where('updated_at', '>=', $windowStart)
                    ->count();
            } catch (\Throwable $e) {
                $proofs = 0;
            }
        }

        $settled = \App\Models\FIN\LedgerModel::query()
            ->where('mode', 'online')
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->where('approval_date', '>=', $windowStart)
            ->count();

        // Sep-2026 — the OTHER pane on this screen. Petrol and maintenance
        // requests are raised by riders from their phones all day, so the
        // Requests pane goes stale exactly the same way the Messages pane does,
        // and the operator had no way to know a new one had arrived.
        //
        // ⚠ Counted WITHOUT the category relation the display query joins: this
        // is polled, and a plain indexed COUNT on status is the cheap half. A
        // non-expense pending request moving would cost one wasted panel
        // re-render, which is harmless — this is a change DETECTOR, never a
        // figure the page renders.
        $requests = 0;
        try {
            $requests = \App\Models\Request\RequestModel::query()
                ->where('status', 'pending')
                ->whereIn('expense_category', ['Petrol', 'Maintenance'])
                ->count();
        } catch (\Throwable $e) {
            $requests = 0;
        }

        return [
            'deliveries' => (int) $deliveries,
            'proofs'     => (int) $proofs,
            'settled'    => (int) $settled,
            'requests'   => (int) $requests,
        ];
    }

    /**
     * The `online_message_tracking` shape the INSTALLED mobile app expects:
     * rider-grouped sent/pending lists, keyed by its own field names.
     *
     * WHY THIS EXISTS: the web page updates the moment the owner uploads it, but
     * the mobile app only changes with a new APK build and deploy here is manual
     * (root CLAUDE.md). Returning the legacy keys alongside the new tier keys
     * means the APK already on riders' phones keeps working untouched while the
     * web page moves to tiers. Delete once DailyClosingScreen consumes the tiers.
     *
     * Behaviour note for the old APK: the population is now the 3-day window
     * rather than today only, so it will list a few more rows than before and its
     * hard-coded "(Today)" heading is stale until the next build. Nothing breaks
     * — the shape is identical.
     *
     * @param  array  $board  the return of build()
     */
    public function legacyMobilePayload(array $board): array
    {
        $rows = array_merge($board['chase'], $board['proof_in']);

        $wasReminded = fn ($r) => $r['reminder_count'] > 0;

        $byRider = collect($rows)
            ->groupBy('rider_name')
            ->map(function (Collection $riderRows, $riderName) use ($wasReminded) {
                $sent    = $riderRows->filter($wasReminded)->values();
                $pending = $riderRows->reject($wasReminded)->values();

                return [
                    'rider_name'    => $riderName,
                    'rider_user_id' => $riderRows->first()['rider_user_id'] ?? null,
                    'sent_count'    => $sent->count(),
                    'pending_count' => $pending->count(),
                    'total_amount'  => (int) $riderRows->sum('amount'),
                    'message_sent'  => $sent->map(fn ($r) => [
                        'id'              => $r['id'],
                        'order_number'    => $r['order_number'],
                        'customer_name'   => $r['customer_name'],
                        'amount'          => $r['amount'],
                        'message_sent_at' => $r['last_reminded_at'],
                    ])->values()->all(),
                    'message_pending' => $pending->map(fn ($r) => [
                        'id'             => $r['id'],
                        'order_number'   => $r['order_number'],
                        'customer_name'  => $r['customer_name'],
                        'customer_phone' => $r['customer_phone'],
                        'rider_name'     => $r['rider_name'],
                        'delivery_date'  => $r['delivery_date'],
                        'delivery_time'  => $r['delivery_time'],
                        'amount'         => $r['amount'],
                        'payment_proof'  => $r['payment_proof'],
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        $sent    = collect($rows)->filter($wasReminded);
        $pending = collect($rows)->reject($wasReminded);

        return [
            'total_online_delivered' => count($rows),
            'message_sent_count'     => $sent->count(),
            'message_pending_count'  => $pending->count(),
            'message_sent_amount'    => (int) $sent->sum('amount'),
            'message_pending_amount' => (int) $pending->sum('amount'),
            'by_rider'               => $byRider,
        ];
    }
}
