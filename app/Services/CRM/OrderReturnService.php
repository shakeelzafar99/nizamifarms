<?php

namespace App\Services\CRM;

use App\Exceptions\OrderReturnException;
use App\Models\CRM\CustomerCreditModel;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderLineItemModel;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderReturnItemModel;
use App\Models\CRM\OrderReturnModel;
use App\Models\CRM\OvernightItemModel;
use App\Models\CRM\OvernightLogModel;
use App\Models\CRM\ProductModel;
use App\Models\CRM\StoreInventoryAdjustmentModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Services\CustomerCreditService;
use App\Services\FIN\BalancePostingService;
use App\Services\FIN\TipsFundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RETURNED ORDERS — the one authority for "a delivered order came back".
 *
 * Two things happen when an order is returned, and they are deliberately
 * INDEPENDENT of each other:
 *
 *   MONEY  — decided by the manager on the web, executed here, inside the very
 *            same transaction as the status change. Never deferred.
 *   GOODS  — decided by the manager too, but only *promised* here. Stock moves
 *            when the store physically scans the packets back (scanBack()).
 *            A promise that never gets scanned leaves stock untouched, which is
 *            the safe direction: overstating stock is the expensive error.
 *
 * ── Why the status alone is not enough ───────────────────────────────────
 * `order_status = 'refunded'` (displayed "Returned") says the goods came back.
 * It cannot say whether the customer was refunded, credited, or never paid; nor
 * whether the meat went to the chiller or the bin. Every one of those is a
 * decision, so each is recorded on t_crm_order_return and read from there.
 *
 * ── Why 'refunded' and not a new 'returned' code ─────────────────────────
 * `refunded` already existed in t_crm_order_status_master, had never been used
 * by a single order, and had no code hook — but it IS already inside the live
 * Quantities exclusion setting and all ~40 hardcoded closed-status lists across
 * PHP, Blade, SQL and the mobile app. Reusing it means a returned order leaves
 * the open boards and tomorrow's quantities exactly like a cancelled one, with
 * no list to sweep and no list to miss.
 *
 * ── The money rule, in one line ──────────────────────────────────────────
 * If the money never really landed, UNWIND it (reverse the invoice). If it did
 * land, LEAVE the invoice alone — the sale and the cash genuinely happened on
 * their own dates — and post a counter-entry: cash back out (order_refund) or
 * an IOU to the customer (credit grant).
 *
 * ⚠ NEVER reverse an invoice whose cash has been settled. The rider already
 * handed that money over; reversing would silently re-open a balance he has
 * closed. Same rule LedgerAdjustmentModel applies to re-prices.
 */
class OrderReturnService
{
    /** The order_status code that means "Returned". See the docblock above. */
    public const STATUS_CODE = 'refunded';

    /** Frozen business unit — its stock is variant quantity, never overnight packets. */
    public const FROZEN_BUSINESS_UNIT_ID = OvernightStockService::FROZEN_BUSINESS_UNIT_ID;

    /** A line counts as fully back when it is within this of its ordered quantity. */
    private const QTY_EPSILON = 0.005;

    // =====================================================================
    // AVAILABILITY
    // =====================================================================

    /**
     * Is the feature's SQL actually in place?
     *
     * Deploys are manual and the PHP reaches the server before the SQL is run.
     * Rather than 500-ing an order save in that window the whole feature stays
     * dormant: reads answer "no returns", writes refuse with a clear message.
     * Same pattern as CustomerCreditService::tableReady().
     */
    public function tableReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = \Schema::hasTable('t_crm_order_return')
                    && \Schema::hasTable('t_crm_order_return_item');
            } catch (\Throwable $e) {
                $ready = false;
            }

            if (!$ready) {
                Log::warning('Order returns are dormant — t_crm_order_return is missing. Run add_order_returns_sep2026.sql.');
            }
        }

        return $ready;
    }

    /**
     * May this user put an order into Returned?
     *
     * ⭐⭐ THE ROLE PERMISSION IS THE WHOLE ANSWER — deliberately no email list and
     * no hardcoded role names.
     *
     * An earlier cut copied the `config email → taimur|shabib role` fallback pair
     * from CustomerCreditService. That pair exists there because auto-approving a
     * credit grant has NO permission of its own, and Shabib's login holds the
     * shared "Management" role, so nothing else could identify him.
     *
     * Returns are not in that position: `return_orders` is a real permission, it
     * is on the Roles screen, and the migration grants it to Management (Shabib)
     * and Taimur — so both of them already pass on this one line. The fallback was
     * pure redundancy, and worse than redundant: an admin revoking `return_orders`
     * in the Roles UI would have believed they had removed the power to reverse
     * invoices and pay refunds, while a name in a config file quietly kept it.
     * A permission you cannot revoke is not a permission.
     *
     * Consequence to keep in mind: if the migration has not run, nobody can take a
     * return. That is the safe direction, and the SQL is step 1 of the deploy.
     */
    public function userCanReturn(?\App\Models\User $user): bool
    {
        return $user
            && method_exists($user, 'hasPermission')
            && $user->hasPermission('return_orders');
    }

    // =====================================================================
    // READS
    // =====================================================================

    /**
     * ⭐⭐ Once an order has a return record it is FROZEN for every money door:
     * no status change out of Returned, no cancel, no re-deliver, no rider or
     * payment-method change, no re-price. Each of those reverses or re-posts the
     * invoice, and the return's counter-entry (refund row / credit grant) would
     * then stand against a row that no longer exists — money moved twice.
     * One indexed lookup, dormant-safe.
     */
    public function isLocked(?int $orderId): bool
    {
        return $orderId !== null && $this->forOrder((int) $orderId) !== null;
    }

    public const LOCK_MESSAGE = 'This order has been returned. Its money and stock were settled by the return, so it cannot be changed again.';

    public function forOrder(int $orderId): ?OrderReturnModel
    {
        if (!$this->tableReady()) {
            return null;
        }

        return OrderReturnModel::where('order_id', $orderId)->first();
    }

    /**
     * Everything the manager's dialog needs: what the money looks like, which
     * answers are legal for that state, which one we recommend, and what goods
     * are on the order.
     *
     * Pure read — nothing here changes anything.
     */
    public function previewFor(OrderModel $order): array
    {
        if (!$this->tableReady()) {
            return [
                'eligible' => false,
                'blocked'  => 'Returns are not set up yet — the returns SQL still needs to be run.',
            ];
        }

        if ($existing = $this->forOrder((int) $order->id)) {
            return [
                'eligible' => false,
                'blocked'  => 'This order has already been returned.',
                'existing' => $this->summarise($existing),
            ];
        }

        if (strtolower((string) $order->order_status) !== 'delivered') {
            return [
                'eligible' => false,
                'blocked'  => 'Only a delivered order can be returned. Cancel it instead.',
            ];
        }

        $money = $this->detectMoney($order);

        if ($money['state'] === OrderReturnModel::STATE_INCREMENTAL) {
            return [
                'eligible' => false,
                'blocked'  => $money['blocked'],
            ];
        }

        return [
            'eligible'       => true,
            'order_id'       => (int) $order->id,
            'order_number'   => $order->order_number,
            'customer_name'  => $this->customerLabel($order),
            'order_total'    => round((float) $order->total_price, 2),
            'money'          => [
                'state'          => $money['state'],
                'state_label'    => $money['state_label'],
                'paid'           => $money['paid'],
                'amount'         => $money['amount'],
                'options'        => $money['options'],
                'default'        => $money['default'],
                'refund_source'  => $money['refund_source'],
                'credit_applied' => $money['credit_applied'],
            ],
            'tip'            => [
                'amount'    => $money['tip'],
                'collected' => $money['tip_collected'],
                'ask'       => $money['tip_ask'],
                'note'      => $money['tip_note'],
            ],
            'goods'          => $this->goodsBreakdown($order),
        ];
    }

    /**
     * Where the order's money actually is right now.
     *
     * ⭐ The client never gets to assert this. It is recomputed here on the
     * write path too, and an answer that is not legal for the recomputed state
     * is refused — a stale dialog must not be able to post a reversal against
     * cash that has since been settled.
     *
     * @return array{state:string,state_label:string,paid:bool,amount:float,options:array,
     *               default:string,refund_source:?array,credit_applied:float,tip:float,
     *               tip_collected:float,tip_ask:bool,tip_note:?string,invoice:?LedgerModel,
     *               blocked:?string}
     */
    public function detectMoney(OrderModel $order): array
    {
        $tips          = app(TipsFundService::class);
        $tip           = round((float) ($order->tip_amount ?? 0), 2);
        $tipCollected  = 0.0;
        try {
            $tipCollected = $tips->collectedFor((int) $order->id);
        } catch (\Throwable $e) {
            // Tips Fund not set up — treat as nothing collected.
        }

        $credit        = app(CustomerCreditService::class);
        $consume       = $credit->liveConsumeForOrder((int) $order->id);
        $creditApplied = $consume ? round(abs((float) $consume->amount), 2) : 0.0;

        $base = [
            'state'          => OrderReturnModel::STATE_ONLINE_PENDING,
            'state_label'    => '',
            'paid'           => false,
            'amount'         => 0.0,
            'options'        => [],
            'default'        => OrderReturnModel::MONEY_NONE,
            'refund_source'  => null,
            'credit_applied' => $creditApplied,
            'tip'            => $tip,
            'tip_collected'  => $tipCollected,
            'tip_ask'        => false,
            'tip_note'       => null,
            'invoice'        => null,
            'blocked'        => null,
        ];

        // ── S6: settled through the incremental payment flow (shop / Qurbani /
        //    pre-paid). Their money lives in t_crm_order_payments, whose settled
        //    rows have no reversal path and no credit bucket behind them. Owner
        //    ruling Sep-2026: refuse rather than guess.
        if ($order->hasPreReceivedPayments()) {
            return array_merge($base, [
                'state'   => OrderReturnModel::STATE_INCREMENTAL,
                'blocked' => 'This order was paid through the incremental payment flow (shop / Qurbani). '
                    . 'Record the refund against its payments by hand first, then cancel the order.',
            ]);
        }

        $invoice = $this->invoiceRowFor($order);

        // No invoice, or one already unwound → there is no money to answer for.
        if (!$invoice || $invoice->approval_status === LedgerModel::STATUS_REVERSED
            || $invoice->approval_status === LedgerModel::STATUS_REJECTED) {
            return array_merge($base, [
                'state'       => OrderReturnModel::STATE_ONLINE_PENDING,
                'state_label' => 'No money was booked against this order',
                'options'     => [$this->option(OrderReturnModel::MONEY_NONE, 'Nothing to do — no money was taken', true)],
                'default'     => OrderReturnModel::MONEY_NONE,
                'invoice'     => $invoice,
            ]);
        }

        $amount        = round((float) $invoice->amount, 2);
        $settledAmount = round((float) ($invoice->settled_amount ?? 0), 2);
        $isCash        = $invoice->mode === LedgerModel::MODE_CASH;
        $canCredit     = $credit->tableReady() && $credit->isEligibleId($order->customer_id ? (int) $order->customer_id : null);

        if ($isCash) {
            if ($invoice->isSettledWithCash()) {
                $state = OrderReturnModel::STATE_CASH_SETTLED;
                $label = 'Cash — already handed over to the office';
            } elseif ($settledAmount > 0) {
                $state = OrderReturnModel::STATE_CASH_PARTIAL;
                $label = 'Cash — Rs ' . number_format($settledAmount, 2) . ' already handed over, the rest still with the rider';
            } else {
                $state = OrderReturnModel::STATE_CASH_UNSETTLED;
                $label = 'Cash — still with the rider, not yet settled';
            }
        } else {
            $applied = (bool) $invoice->balance_updated
                || in_array($invoice->approval_status, [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2], true);
            $state = $applied ? OrderReturnModel::STATE_ONLINE_PAID : OrderReturnModel::STATE_ONLINE_PENDING;
            $label = $applied
                ? 'Online — the payment is in the bank'
                : 'Online — still waiting at Level 1, no money confirmed yet';
        }

        $paid    = in_array($state, [
            OrderReturnModel::STATE_CASH_SETTLED,
            OrderReturnModel::STATE_CASH_PARTIAL,
            OrderReturnModel::STATE_ONLINE_PAID,
        ], true);
        $options = [];
        $default = null;

        // ── Reverse: only while nothing has been settled. Once cash reaches the
        //    office (or the online payment is banked), reversing would unwind a
        //    balance somebody has already closed.
        $canReverse = in_array($state, [
            OrderReturnModel::STATE_CASH_UNSETTLED,
            OrderReturnModel::STATE_ONLINE_PENDING,
        ], true);

        if ($canReverse) {
            $options[] = $this->option(
                OrderReturnModel::MONEY_REVERSED,
                $isCash
                    ? 'Not paid, or the rider handed the cash straight back — unwind the invoice'
                    : 'No payment was confirmed — unwind the invoice',
                true,
                'The order\'s invoice is reversed. It leaves the rider\'s cash and Daily Closing, and any tip goes back automatically.'
            );
            $default = OrderReturnModel::MONEY_REVERSED;
        }

        // ── Refund: hand the money back out of the account it came into.
        // ⚠ Only when money actually LANDED. Offering a till refund on an unsettled
        // or unconfirmed invoice would pay real cash out of the main till against
        // money the company never received, while the invoice stayed standing.
        $source = $paid ? $this->refundSourceFor($order, $invoice, $state) : null;
        if ($source && $amount > 0.009) {
            $options[] = $this->option(
                OrderReturnModel::MONEY_REFUND,
                'Refund the money from ' . $source['name'],
                $default === null,
                'Records money leaving ' . $source['name'] . '. The invoice stays as it is — the sale and the cash really happened.'
            );
            if ($default === null) {
                $default = OrderReturnModel::MONEY_REFUND;
            }
        }

        // ── Credit: keep the money, owe it to the customer.
        if ($canCredit && $amount >= CustomerCreditService::MIN_GRANT && $paid) {
            $options[] = $this->option(
                OrderReturnModel::MONEY_CREDIT,
                'Keep it as account balance for the customer',
                false,
                'The money stays with us and is added to the customer\'s balance to spend on a later order.'
            );
        }

        if (!$options) {
            $options[] = $this->option(OrderReturnModel::MONEY_NONE, 'Nothing to do', true);
            $default   = OrderReturnModel::MONEY_NONE;
        }

        // ── The tip question (owner ruling Sep-2026: ask, never assume).
        //    On a reversal it is not a question — reversing the invoice hands the
        //    tip back on its own through the Tips Fund hook.
        $tipAsk  = $tipCollected > 0.009 && $default !== OrderReturnModel::MONEY_REVERSED;
        $tipNote = null;
        if ($tipCollected > 0.009 && $default === OrderReturnModel::MONEY_REVERSED) {
            $tipNote = 'The Rs ' . number_format($tipCollected, 2) . ' tip goes back automatically when the invoice is reversed.';
        }

        return array_merge($base, [
            'state'         => $state,
            'state_label'   => $label,
            'paid'          => $paid,
            'amount'        => $amount,
            'options'       => $options,
            'default'       => $default,
            'refund_source' => $source,
            'tip_ask'       => $tipAsk,
            'tip_note'      => $tipNote,
            'invoice'       => $invoice,
        ]);
    }

    private function option(string $key, string $label, bool $recommended = false, ?string $help = null): array
    {
        return [
            'key'         => $key,
            'label'       => $label,
            'recommended' => $recommended,
            'help'        => $help,
        ];
    }

    /**
     * The account a refund should physically leave from — "the account the money
     * came into", which is the whole point of the owner's rule.
     */
    private function refundSourceFor(OrderModel $order, LedgerModel $invoice, string $state): ?array
    {
        $account = null;

        if ($state === OrderReturnModel::STATE_ONLINE_PAID) {
            // The bank the invoice landed in.
            $account = $invoice->to_account_id ? AccountModel::find($invoice->to_account_id) : null;
            $account = $account ?: ConfigModel::getOnlineBankAccount();
        } else {
            // Cash: follow the settlement to the till it was deposited into.
            $depositId = $invoice->settled_via_ledger_id;

            if (!$depositId && \Schema::hasTable('t_fin_invoice_settlements')) {
                $depositId = DB::table('t_fin_invoice_settlements')
                    ->where('invoice_ledger_id', $invoice->id)
                    ->orderByDesc('id')
                    ->value('settlement_deposit_id');
            }

            if ($depositId) {
                $toId = DB::table('t_fin_ledger')->where('id', $depositId)->value('to_account_id');
                $account = $toId ? AccountModel::find($toId) : null;
            }

            // Never a rider's own cash account: a refund is paid out of a company
            // till, not out of money a rider is still holding for us.
            if ($account && $account->account_category === AccountModel::CATEGORY_EMPLOYEE_CASH) {
                $account = null;
            }

            $account = $account ?: ConfigModel::getNFCashAccount();
        }

        if (!$account) {
            return null;
        }

        return [
            'account_id' => (int) $account->id,
            'name'       => (string) $account->account_name,
            'mode'       => $state === OrderReturnModel::STATE_ONLINE_PAID
                ? LedgerModel::MODE_ONLINE
                : LedgerModel::MODE_CASH,
        ];
    }

    /**
     * The order's live invoice row. Falls back to a lookup by order_id because a
     * re-posted invoice does not always leave ledger_transaction_id pointing at
     * the live row (the same fallback TipsFundService uses).
     */
    private function invoiceRowFor(OrderModel $order): ?LedgerModel
    {
        $row = $order->ledger_transaction_id ? LedgerModel::find($order->ledger_transaction_id) : null;

        if (!$row || $row->transaction_type !== LedgerModel::TYPE_INVOICE) {
            $row = LedgerModel::where('order_id', $order->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->orderBy('id')
                ->first();
        }

        return $row;
    }

    /**
     * What is physically on the order, split the way the put-back needs it.
     *
     * Frozen (BU 2) goes back to store stock; everything else is fresh and goes
     * to a chiller/freezer shelf as an overnight packet. A line with no Czerlop
     * PLU cannot be scale-scanned at all, so it is flagged for a manual tick —
     * 95 of the 448 products have no PLU, so this is not a rare corner.
     */
    public function goodsBreakdown(OrderModel $order): array
    {
        $lines = OrderLineItemModel::where('order_id', $order->id)->get();
        if ($lines->isEmpty()) {
            return ['lines' => [], 'frozen_count' => 0, 'fresh_count' => 0, 'has_meat' => false];
        }

        $products = ProductModel::whereIn('id', $lines->pluck('product_id')->filter()->unique()->values())
            ->get(['id', 'title', 'czerlop_product_id', 'business_unit_id', 'weight_factor'])
            ->keyBy('id');

        $out = [];
        $frozen = 0;
        $fresh  = 0;

        foreach ($lines as $line) {
            $qty = round((float) ($line->quantity ?? 0), 3);
            if ($qty <= 0) {
                continue;
            }

            $product  = $line->product_id ? $products->get($line->product_id) : null;
            $isFrozen = $product && (int) $product->business_unit_id === self::FROZEN_BUSINESS_UNIT_ID;
            $plu      = $product && $product->czerlop_product_id ? (int) $product->czerlop_product_id : null;

            $out[] = [
                'line_item_id'     => (int) $line->id,
                'product_id'       => $line->product_id ? (int) $line->product_id : null,
                'name'             => $line->name ?: ($product->title ?? 'Item'),
                'quantity'         => $qty,
                'business_unit_id' => $product ? (int) $product->business_unit_id : null,
                'is_frozen'        => $isFrozen,
                'plu'              => $plu,
                'scannable'        => $plu !== null,
                'weight_factor'    => $product ? (float) ($product->weight_factor ?: 1.0) : 1.0,
                'destination'      => $isFrozen
                    ? OrderReturnItemModel::DEST_STORE_STOCK
                    : null, // filled from the manager's chiller/freezer answer
            ];

            $isFrozen ? $frozen++ : $fresh++;
        }

        return [
            'lines'        => $out,
            'frozen_count' => $frozen,
            'fresh_count'  => $fresh,
            'has_meat'     => $fresh > 0,
        ];
    }

    // =====================================================================
    // WRITE — called from OrderModel::changeStatus, inside ITS transaction
    // =====================================================================

    /**
     * Execute the manager's return decision.
     *
     * ⚠ Runs inside the changeStatus transaction, by which time the order row
     * has ALREADY been saved as 'refunded'. That matters: TipsFundService reads
     * the current status, so the tip converges correctly without extra work.
     *
     * Throws on anything it cannot do safely — the caller lets that abort the
     * whole status change, which is the only correct outcome. A returned order
     * whose money silently failed is worse than a return that did not happen.
     *
     * @param array{money_action?:string,goods_action?:string,meat_section?:string,
     *              reason?:string,return_tip?:bool,notes?:string} $answers
     */
    public function applyOnStatusChange(OrderModel $order, array $answers, ?int $userId): OrderReturnModel
    {
        if (!$this->tableReady()) {
            throw new OrderReturnException('Returns are not set up yet — run add_order_returns_sep2026.sql before returning an order.');
        }

        // Idempotent: a double submit must not post the money twice.
        if ($existing = OrderReturnModel::where('order_id', $order->id)->lockForUpdate()->first()) {
            return $existing;
        }

        $money = $this->detectMoney($order);

        if ($money['state'] === OrderReturnModel::STATE_INCREMENTAL) {
            throw new OrderReturnException($money['blocked']);
        }

        // ⭐ The server decides what is legal, not the page. A dialog opened
        //    before the rider settled must not be able to reverse settled cash.
        $chosen  = (string) ($answers['money_action'] ?? $money['default']);
        $allowed = array_column($money['options'], 'key');
        if (!in_array($chosen, $allowed, true)) {
            throw new OrderReturnException(
                'That is not a valid choice for this order any more (' . $money['state_label'] . '). '
                . 'Close the form, reopen it and choose again.'
            );
        }

        $goodsAction = ($answers['goods_action'] ?? OrderReturnModel::GOODS_RESTOCK) === OrderReturnModel::GOODS_WASTED
            ? OrderReturnModel::GOODS_WASTED
            : OrderReturnModel::GOODS_RESTOCK;

        $goods       = $this->goodsBreakdown($order);
        $meatSection = null;
        if ($goodsAction === OrderReturnModel::GOODS_RESTOCK && $goods['has_meat']) {
            $meatSection = ($answers['meat_section'] ?? OrderReturnModel::SECTION_FREEZER) === OrderReturnModel::SECTION_CHILLER
                ? OrderReturnModel::SECTION_CHILLER
                : OrderReturnModel::SECTION_FREEZER;
        }

        // Reversal returns the tip on its own; otherwise it is the manager's call.
        $returnTip = $chosen === OrderReturnModel::MONEY_REVERSED
            ? $money['tip_collected'] > 0.009
            : (!empty($answers['return_tip']) && $money['tip_collected'] > 0.009);

        // ── The record is written FIRST, before any money moves. TipsFundService
        //    asks this row whether the tip is going back, so it has to exist by
        //    the time the postings run.
        $return = OrderReturnModel::create([
            'order_id'     => (int) $order->id,
            'reason'       => $this->trim($answers['reason'] ?? null, 255),
            'goods_action' => $goodsAction,
            'meat_section' => $meatSection,
            'money_state'  => $money['state'],
            'money_action' => $chosen,
            'amount'       => 0,
            'tip_returned' => $returnTip,
            'tip_amount'   => $returnTip ? $money['tip_collected'] : 0,
            'decided_by'   => $userId,
            'decided_at'   => now(),
            'notes'        => $this->trim($answers['notes'] ?? null, 500),
            // ⭐ What was on the order AT THE MOMENT OF THE RETURN. The put-back
            // measures against this, never against the live line items: an order
            // edited after the return (quantity raised, a line deleted) must not
            // change how much stock comes back.
            'lines_snapshot' => json_encode($goods['lines']),
        ]);

        $outcome = $this->executeMoney($order, $return, $money, $chosen, $returnTip, $userId);

        $return->amount           = $outcome['amount'];
        $return->refund_ledger_id = $outcome['refund_ledger_id'];
        $return->credit_grant_id  = $outcome['credit_grant_id'];
        $return->regrant_id       = $outcome['regrant_id'];
        $return->save();

        // Nothing to put back → the return is finished the moment it is taken.
        if ($goodsAction === OrderReturnModel::GOODS_WASTED || empty($goods['lines'])) {
            $return->completed_at = now();
            $return->completed_by = $userId;
            $return->save();
        }

        Log::info('Order returned', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'return_id'    => $return->id,
            'money_state'  => $money['state'],
            'money_action' => $chosen,
            'amount'       => $outcome['amount'],
            'tip_returned' => $returnTip,
            'goods'        => $goodsAction,
            'meat_section' => $meatSection,
            'by'           => $userId,
        ]);

        return $return;
    }

    /**
     * The postings. One branch per money answer; every one of them goes through
     * BalancePostingService, never a hand-written balance edit.
     *
     * @return array{amount:float,refund_ledger_id:?int,credit_grant_id:?int,regrant_id:?int}
     */
    private function executeMoney(
        OrderModel $order,
        OrderReturnModel $return,
        array $money,
        string $chosen,
        bool $returnTip,
        ?int $userId
    ): array {
        $out = ['amount' => 0.0, 'refund_ledger_id' => null, 'credit_grant_id' => null, 'regrant_id' => null];

        /** @var LedgerModel|null $invoice */
        $invoice = $money['invoice'];
        $credit  = app(CustomerCreditService::class);

        if ($chosen === OrderReturnModel::MONEY_NONE) {
            // Still hand back any account balance the customer had spent.
            $out['regrant_id'] = $this->giveBackSpentBalance($order, $money, $invoice, $userId);
            return $out;
        }

        // ── REVERSE ──────────────────────────────────────────────────────
        // The money never really landed. Unwind the invoice; the Tips Fund hook
        // inside the engine gives the tip back on its own, and releaseFromOrder
        // then works because a reversed invoice passes its "releasable" test —
        // exactly the order cancellation relies on.
        if ($chosen === OrderReturnModel::MONEY_REVERSED) {
            if ($invoice && $invoice->approval_status !== LedgerModel::STATUS_REVERSED) {
                if ($invoice->isSettledWithCash()) {
                    throw new OrderReturnException('This invoice has already been settled — it cannot be reversed. Refund the money instead.');
                }
                if (round((float) ($invoice->settled_amount ?? 0), 2) > 0) {
                    throw new OrderReturnException('This invoice is part-settled — it cannot be reversed. Refund the money instead.');
                }

                (new BalancePostingService())->reverse($invoice);
                $invoice->approval_status = LedgerModel::STATUS_REVERSED;
                $invoice->comments = trim((string) $invoice->comments) !== ''
                    ? $invoice->comments . "\n\nREVERSED: Order #{$order->order_number} was returned"
                    : "REVERSED: Order #{$order->order_number} was returned";
                $invoice->save();
            }

            $out['amount'] = round((float) ($money['amount'] ?? 0), 2);

            // Release the balance the customer had spent on this order.
            try {
                $released = $credit->releaseFromOrder(
                    (int) $order->id,
                    $userId,
                    'Order ' . $order->order_number . ' returned'
                );
                if ($released) {
                    $order->refresh();
                }
            } catch (\Throwable $e) {
                // Loud, but never fatal: the return itself is sound, the customer's
                // balance just needs a manual nudge. Same stance cancellation takes.
                Log::error('Could not release customer credit on return', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            $order->payment_status = 'refunded';
            $order->save();

            return $out;
        }

        // ── REFUND and CREDIT both KEEP the invoice ──────────────────────
        // The sale happened and the cash moved; the counter-entry is what makes
        // the books whole. The only question is where the money goes.

        // The tip rides inside the invoice total. If the customer is getting the
        // tip back too, take it out of the fund first and refund the gross;
        // otherwise refund the invoice LESS the tip, which stays in the fund for
        // the rider. Both arithmetics leave revenue at exactly zero.
        $amount = round((float) $money['amount'], 2);
        if ($returnTip) {
            $this->releaseTip((int) $order->id, $userId);
        } else {
            $amount = round($amount - (float) $money['tip_collected'], 2);
        }

        if ($amount < 0) {
            $amount = 0.0;
        }
        $out['amount'] = $amount;

        if ($chosen === OrderReturnModel::MONEY_REFUND && $amount > 0.009) {
            $source = $money['refund_source'];
            if (!$source) {
                throw new OrderReturnException('Could not work out which account the money should be refunded from.');
            }
            $out['refund_ledger_id'] = $this->postRefundRow($order, $invoice, $source, $amount, $userId);
        }

        // ⚠ The bucket has a floor (Rs 10) — below it a grant is refused. The
        // option was offered on the PRE-tip amount, so a tiny order that is
        // almost all tip can fall under the floor here. Say so plainly rather
        // than recording a credit the customer would never receive.
        if ($chosen === OrderReturnModel::MONEY_CREDIT && $amount < CustomerCreditService::MIN_GRANT) {
            throw new OrderReturnException(
                'After keeping the tip there is only Rs ' . number_format($amount, 2)
                . ' left, which is below the Rs ' . number_format(CustomerCreditService::MIN_GRANT, 0)
                . ' minimum for account balance. Give the tip back too, or refund the money instead.'
            );
        }

        if ($chosen === OrderReturnModel::MONEY_CREDIT && $amount >= CustomerCreditService::MIN_GRANT) {
            $grant = $credit->requestGrant(
                (int) $order->customer_id,
                $amount,
                $userId ?? 1,
                [
                    'order_id' => (int) $order->id,
                    'source'   => CustomerCreditModel::SOURCE_RETURN,
                    'reason'   => 'Returned order ' . $order->order_number,
                ]
            );
            $out['credit_grant_id'] = (int) $grant->id;
        }

        // The customer's own balance that was spent on this order comes back
        // regardless of which answer was given — it was never our money.
        $out['regrant_id'] = $this->giveBackSpentBalance($order, $money, $invoice, $userId);

        // ⭐ A new value in a column that until now held only paid/partial/unpaid.
        // Checked before adopting it: the column is a varchar, the counters that
        // bucket by exact value simply stop counting it, the orders page renders
        // it as a red "REFUNDED" pill (which is right), and the ONE query that
        // treats "not paid" as still-owing is the SHOP tab — which a return can
        // never reach, because shop orders are refused one. Saying `unpaid` (what
        // cancellation writes) would be a lie: they did pay, and got it back.
        $order->payment_status = 'refunded';
        $order->save();

        return $out;
    }

    /**
     * Hand back account balance the customer had SPENT on this order.
     *
     * Two shapes, because the invoice decides which is safe:
     *  · invoice reversed / never posted → release the consume (the cancellation
     *    path; it also strips the sentinel discount line);
     *  · invoice still standing → a fresh GRANT of the same amount. Releasing
     *    would raise the order total while the posted invoice stayed at the lower
     *    figure, and the two would never agree again — which is exactly why
     *    releaseFromOrder refuses in that case.
     */
    private function giveBackSpentBalance(OrderModel $order, array $money, ?LedgerModel $invoice, ?int $userId): ?int
    {
        $applied = round((float) ($money['credit_applied'] ?? 0), 2);
        if ($applied <= 0) {
            return null;
        }

        $credit = app(CustomerCreditService::class);

        $invoiceStanding = $invoice
            && !in_array($invoice->approval_status, [
                LedgerModel::STATUS_REVERSED,
                LedgerModel::STATUS_REJECTED,
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
            ], true);

        if (!$invoiceStanding) {
            try {
                $credit->releaseFromOrder((int) $order->id, $userId, 'Order ' . $order->order_number . ' returned');
            } catch (\Throwable $e) {
                Log::error('Could not release spent balance on return', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }

            return null;
        }

        if ($applied < CustomerCreditService::MIN_GRANT) {
            Log::info('Spent balance too small to re-grant on return', [
                'order_id' => $order->id,
                'amount'   => $applied,
            ]);

            return null;
        }

        try {
            $grant = $credit->requestGrant(
                (int) $order->customer_id,
                $applied,
                $userId ?? 1,
                [
                    'order_id' => (int) $order->id,
                    'source'   => CustomerCreditModel::SOURCE_RETURN,
                    'reason'   => 'Account balance spent on returned order ' . $order->order_number,
                ]
            );

            return (int) $grant->id;
        } catch (\Throwable $e) {
            Log::error('Could not re-grant spent balance on return', [
                'order_id' => $order->id,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Take this order's tip back out of the Tips Fund.
     *
     * Done through syncForOrder so the fund keeps ONE authority for what it
     * should be holding: the return row written moments ago makes
     * TipsFundService::desiredTipFor() answer zero, and the service converges.
     */
    private function releaseTip(int $orderId, ?int $userId): void
    {
        try {
            app(TipsFundService::class)->syncForOrder($orderId, $userId);
        } catch (\Throwable $e) {
            Log::error('Could not return the tip on a returned order — run `php artisan tips:backfill`', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * The refund row: money leaves the account it arrived in, revenue is
     * un-recognised. from = that account (asset −), to = Sales Revenue (income +,
     * i.e. less negative). The invoice is untouched.
     *
     * Approved on the spot when the person taking the return could approve the row
     * anyway — the same friction rule the credit bucket settled on, but asked the
     * role-driven way: the row is created `pending_l1`, and LedgerController's
     * guardApprovalRights(…, 1) admits exactly "holds L1 or L2". So that is what we
     * ask. Both are set per role on the Roles screen, which means this can be
     * revoked like anything else — no names in code, no emails in config.
     * For anyone else the refund waits at Level 1, so somebody has to confirm the
     * money really went back before it moves a balance.
     */
    private function postRefundRow(OrderModel $order, ?LedgerModel $invoice, array $source, float $amount, ?int $userId): int
    {
        $revenue = ConfigModel::getSalesRevenueAccount();
        $from    = AccountModel::find($source['account_id']);

        if (!$revenue || !$from) {
            throw new OrderReturnException('The Sales Revenue or refund account is missing — the refund cannot be posted.');
        }

        $selfApprove = $userId && (
            \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel((int) $userId, 1)
            || \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel((int) $userId, 2)
        );

        $status = $selfApprove ? LedgerModel::STATUS_APPROVED : LedgerModel::STATUS_PENDING_L1;

        $row = LedgerModel::create([
            'transaction_date' => now(),
            'transaction_type' => LedgerModel::TYPE_ORDER_REFUND,
            'description'      => 'Refund — returned order #' . $order->order_number . ' (' . $this->customerLabel($order) . ')',
            'from_account_id'  => $from->id,
            'to_account_id'    => $revenue->id,
            'amount'           => $amount,
            'mode'             => $source['mode'],
            'approval_status'  => $status,
            'balance_updated'  => 0, // the engine owns this
            'settlement_status' => 'open',
            'settled_amount'   => 0.00,
            'approval_date'    => $selfApprove ? now() : null,
            'approved_by'      => $selfApprove ? $userId : null,
            'order_id'         => (int) $order->id,
            // Carry the invoice's bank tag so the per-bank balances see the money
            // leaving the bank it landed in, not the untagged bucket.
            'receiving_account_id' => $invoice?->receiving_account_id,
            'created_by'       => $userId ?? 1,
        ]);

        if ($selfApprove) {
            (new BalancePostingService())->apply($row);
        }

        return (int) $row->id;
    }

    // =====================================================================
    // STORE SIDE — putting the goods back
    // =====================================================================

    /**
     * Returns still waiting for the store to scan them back.
     * Drives both the mobile banner and the web strip.
     */
    public function pendingPutBacks(int $limit = 20): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $rows = OrderReturnModel::with('order')
            ->where('goods_action', OrderReturnModel::GOODS_RESTOCK)
            ->whereNull('completed_at')
            ->orderBy('decided_at')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($rows as $return) {
            $order = $return->order;
            if (!$order) {
                continue;
            }

            $progress = $this->progressFor($return, $order);

            $out[] = [
                'return_id'     => (int) $return->id,
                'order_id'      => (int) $order->id,
                'order_number'  => $order->order_number,
                'customer_name' => $this->customerLabel($order),
                'reason'        => $return->reason,
                'meat_section'  => $return->meat_section,
                'decided_at'    => optional($return->decided_at)->format('Y-m-d H:i:s'),
                'waiting_hours' => $return->decided_at ? (int) $return->decided_at->diffInHours(now()) : null,
                'lines_total'   => count($progress['lines']),
                'lines_done'    => $progress['done'],
                'scanned_count' => $progress['scanned_count'],
                'complete'      => $progress['complete'],
            ];
        }

        return $out;
    }

    /** Everything the scan screen needs for one return. */
    public function putBackDetail(int $returnId): array
    {
        $return = OrderReturnModel::find($returnId);
        if (!$return) {
            throw new OrderReturnException('Return not found.');
        }

        $order = OrderModel::find($return->order_id);
        if (!$order) {
            throw new OrderReturnException('Order not found.');
        }

        $progress = $this->progressFor($return, $order);

        return [
            'return_id'     => (int) $return->id,
            'order_id'      => (int) $order->id,
            'order_number'  => $order->order_number,
            'customer_name' => $this->customerLabel($order),
            'reason'        => $return->reason,
            'meat_section'  => $return->meat_section,
            'completed_at'  => optional($return->completed_at)->format('Y-m-d H:i:s'),
            'complete'      => $progress['complete'],
            'lines'         => $progress['lines'],
        ];
    }

    /**
     * Per-line "how much is back so far", in the line's OWN units.
     *
     * A scale label carries kilograms; the line may be counted in packs. The
     * product's weight_factor is the divisor between them — the same one the
     * delivery-side weight scan uses, so both sides agree about what one scan is
     * worth.
     */
    private function progressFor(OrderReturnModel $return, OrderModel $order): array
    {
        $goods = $this->linesFor($return, $order);
        $items = OrderReturnItemModel::where('return_id', $return->id)->get();

        $lines = [];
        $done  = 0;

        foreach ($goods['lines'] as $line) {
            $mine = $items->where('line_item_id', $line['line_item_id']);

            $back = 0.0;
            foreach ($mine as $item) {
                $qty = (float) $item->quantity;
                $back += $item->unit === 'kg' && $line['weight_factor'] > 0
                    ? $qty / $line['weight_factor']
                    : $qty;
            }
            $back = round($back, 3);

            $complete = $back >= ((float) $line['quantity'] - self::QTY_EPSILON);
            if ($complete) {
                $done++;
            }

            $lines[] = array_merge($line, [
                'destination' => $line['is_frozen']
                    ? OrderReturnItemModel::DEST_STORE_STOCK
                    : ($return->meat_section ?? OrderReturnModel::SECTION_FREEZER),
                'back'        => $back,
                'remaining'   => max(0, round((float) $line['quantity'] - $back, 3)),
                'complete'    => $complete,
                'scans'       => $mine->count(),
            ]);
        }

        return [
            'lines'         => $lines,
            'done'          => $done,
            'scanned_count' => $items->count(),
            'complete'      => $lines !== [] && $done === count($lines),
        ];
    }

    /**
     * The lines a put-back is measured against: the snapshot taken when the
     * return was decided. Live line items only as a fallback for a return taken
     * before the snapshot column existed.
     */
    private function linesFor(OrderReturnModel $return, OrderModel $order): array
    {
        $snap = $return->lines_snapshot;
        if (is_string($snap) && $snap !== '') {
            $snap = json_decode($snap, true);
        }
        if (is_array($snap) && $snap !== []) {
            return ['lines' => $snap];
        }

        return $this->goodsBreakdown($order);
    }

    /**
     * Scan ONE packet back in.
     *
     * The barcode is re-decoded HERE and the server's reading wins — the client
     * only reports what it saw. A PLU that is not on the order is refused: this
     * is the whole point of scanning rather than ticking, and it is the same
     * check the delivery-side scanner makes.
     *
     * @return array{ok:bool,message:string,line_item_id:?int,quantity:float,destination:string,complete:bool}
     */
    public function scanBack(int $returnId, string $barcode, ?string $destination, int $userId): array
    {
        if (!$this->tableReady()) {
            throw new OrderReturnException('Returns are not set up yet.');
        }

        return DB::transaction(function () use ($returnId, $barcode, $destination, $userId) {
            $return = OrderReturnModel::where('id', $returnId)->lockForUpdate()->first();
            if (!$return) {
                throw new OrderReturnException('Return not found.');
            }
            if ($return->goods_action !== OrderReturnModel::GOODS_RESTOCK) {
                throw new OrderReturnException('These goods were written off — there is nothing to put back.');
            }
            if ($return->completed_at) {
                throw new OrderReturnException('This return is already finished.');
            }

            $order = OrderModel::find($return->order_id);
            if (!$order) {
                throw new OrderReturnException('Order not found.');
            }

            $decoder = app(WeightBarcodeDecoder::class);
            $decoded = $decoder->decode($barcode);
            if (!$decoded) {
                throw new OrderReturnException('That is not a valid scale barcode (' . ($decoder->rejectionReason($barcode) ?? 'unreadable') . ').');
            }

            $progress = $this->progressFor($return, $order);

            // Which line does this PLU belong to? Prefer one still short.
            $candidates = array_values(array_filter(
                $progress['lines'],
                fn ($l) => $l['plu'] !== null && (int) $l['plu'] === (int) $decoded['plu']
            ));

            if (!$candidates) {
                throw new OrderReturnException('Not in this order (PLU ' . $decoded['plu'] . ', ' . number_format($decoded['weight_kg'], 3) . ' kg).');
            }

            // The same physical label twice is the easiest way to inflate kg stock.
            $dup = OrderReturnItemModel::where('return_id', $return->id)
                ->where('barcode', $decoded['raw'])
                ->exists();
            if ($dup) {
                throw new OrderReturnException('That packet has already been scanned back.');
            }

            $line = null;
            foreach ($candidates as $c) {
                if (!$c['complete']) {
                    $line = $c;
                    break;
                }
            }
            if (!$line) {
                throw new OrderReturnException($candidates[0]['name'] . ' is already fully back — nothing more to add.');
            }

            $dest = $line['is_frozen']
                ? OrderReturnItemModel::DEST_STORE_STOCK
                : $this->normaliseSection($destination ?? $return->meat_section);

            $item = OrderReturnItemModel::create([
                'return_id'        => (int) $return->id,
                'order_id'         => (int) $order->id,
                'line_item_id'     => $line['line_item_id'],
                'product_id'       => $line['product_id'],
                'business_unit_id' => $line['business_unit_id'],
                'plu'              => (int) $decoded['plu'],
                'barcode'          => $decoded['raw'],
                'quantity'         => $decoded['weight_kg'],
                'unit'             => 'kg',
                'destination'      => $dest,
                'scanned_by'       => $userId,
                'scanned_at'       => now(),
                'created_at'       => now(),
            ]);

            $this->putOneBack($item, $line, $order, $userId);

            $after = $this->progressFor($return->fresh(), $order);

            return [
                'ok'           => true,
                'message'      => $line['name'] . ' — ' . number_format($decoded['weight_kg'], 3) . ' kg back to ' . $this->sectionLabel($dest),
                'line_item_id' => $line['line_item_id'],
                'quantity'     => (float) $decoded['weight_kg'],
                'destination'  => $dest,
                'complete'     => $after['complete'],
                'lines'        => $after['lines'],
            ];
        });
    }

    /**
     * Put a line back WITHOUT a scan — for the 95 products that carry no Czerlop
     * PLU and therefore cannot be scale-scanned at all. Overnight allows the same
     * manual route, and refusing it here would simply strand those items.
     */
    public function markBackManually(int $returnId, int $lineItemId, ?float $quantity, ?string $destination, int $userId): array
    {
        if (!$this->tableReady()) {
            throw new OrderReturnException('Returns are not set up yet.');
        }

        return DB::transaction(function () use ($returnId, $lineItemId, $quantity, $destination, $userId) {
            $return = OrderReturnModel::where('id', $returnId)->lockForUpdate()->first();
            if (!$return) {
                throw new OrderReturnException('Return not found.');
            }
            if ($return->goods_action !== OrderReturnModel::GOODS_RESTOCK) {
                throw new OrderReturnException('These goods were written off — there is nothing to put back.');
            }
            if ($return->completed_at) {
                throw new OrderReturnException('This return is already finished.');
            }

            $order = OrderModel::find($return->order_id);
            if (!$order) {
                throw new OrderReturnException('Order not found.');
            }

            $progress = $this->progressFor($return, $order);
            $line     = null;
            foreach ($progress['lines'] as $l) {
                if ((int) $l['line_item_id'] === $lineItemId) {
                    $line = $l;
                    break;
                }
            }
            if (!$line) {
                throw new OrderReturnException('That item is not on this order.');
            }

            // Manual is for items that CANNOT be scanned. A scannable line must be
            // scanned — the whole point of the scan is that the label proves it.
            if (!empty($line['scannable'])) {
                throw new OrderReturnException($line['name'] . ' has a scale label — scan it instead of ticking it.');
            }

            $qty = $quantity !== null ? round((float) $quantity, 3) : (float) $line['remaining'];
            if ($qty <= 0 || (float) $line['remaining'] <= 0) {
                throw new OrderReturnException('Nothing left to put back on that line.');
            }
            // Never more than is still outstanding.
            $qty = min($qty, round((float) $line['remaining'], 3));

            $dest = $line['is_frozen']
                ? OrderReturnItemModel::DEST_STORE_STOCK
                : $this->normaliseSection($destination ?? $return->meat_section);

            $item = OrderReturnItemModel::create([
                'return_id'        => (int) $return->id,
                'order_id'         => (int) $order->id,
                'line_item_id'     => $line['line_item_id'],
                'product_id'       => $line['product_id'],
                'business_unit_id' => $line['business_unit_id'],
                'plu'              => $line['plu'],
                'barcode'          => null,
                'quantity'         => $qty,
                'unit'             => 'pcs', // line units — progressFor adds these straight
                'destination'      => $dest,
                'scanned_by'       => $userId,
                'scanned_at'       => now(),
                'created_at'       => now(),
            ]);

            $this->putOneBack($item, $line, $order, $userId);

            $after = $this->progressFor($return->fresh(), $order);

            return [
                'ok'       => true,
                'message'  => $line['name'] . ' marked back into ' . $this->sectionLabel($dest),
                'complete' => $after['complete'],
                'lines'    => $after['lines'],
            ];
        });
    }

    /**
     * The stock move for ONE returned packet.
     *
     * Fresh  → a real overnight packet in the chosen section, with its `in` log
     *          row, so it ages, gets verified and is taken out through the board
     *          the store already uses every morning.
     * Frozen → nothing until the line is fully back, then the line's own
     *          restoreInventory(). Frozen stock is a per-line variant quantity,
     *          not a packet, so a part-scanned line must not move it: writing up
     *          stock that is not all there is the error that costs money.
     */
    private function putOneBack(OrderReturnItemModel $item, array $line, OrderModel $order, int $userId): void
    {
        if ($item->destination === OrderReturnItemModel::DEST_STORE_STOCK) {
            $this->restoreFrozenLineIfComplete($item, $line, $order, $userId);

            return;
        }

        // ⚠ Frozen products are refused by the overnight rail on purpose (their
        //    freezer figure is derived from store inventory). This branch only
        //    ever sees fresh goods, but the guard stays as a rail, not a comment.
        if ((int) ($item->business_unit_id ?? 0) === self::FROZEN_BUSINESS_UNIT_ID) {
            throw new OrderReturnException('Frozen items go back to store stock, not to an overnight shelf.');
        }

        $overnight = OvernightItemModel::create([
            'product_id'   => $item->product_id,
            'product_name' => $line['name'],
            'plu'          => $item->plu,
            'barcode'      => $item->barcode,
            'quantity'     => $item->quantity,
            'unit'         => $item->unit === 'kg' ? 'kg' : 'pcs',
            'section'      => $item->destination,
            'status'       => 'stored',
            'source'       => $item->barcode ? 'scan' : 'manual',
            'entered_at'   => now(),
            'entered_by'   => $userId,
        ]);

        OvernightLogModel::create([
            'item_id'      => $overnight->id,
            'action'       => 'in',
            'from_section' => null,
            'to_section'   => $item->destination,
            'product_id'   => $overnight->product_id,
            'product_name' => $overnight->product_name,
            'quantity'     => $item->quantity,
            'unit'         => $overnight->unit,
            'source'       => $item->barcode ? 'scan' : 'manual',
            'created_by'   => $userId,
            'created_at'   => now(),
        ]);

        $item->overnight_item_id = $overnight->id;
        $item->save();
    }

    /**
     * Frozen: give the variant its quantity back, but only once the WHOLE line
     * is accounted for. restoreInventory() is all-or-nothing per line and
     * idempotent on inventory_deducted, so a second call is a no-op.
     */
    private function restoreFrozenLineIfComplete(OrderReturnItemModel $item, array $line, OrderModel $order, int $userId): void
    {
        $lineItem = OrderLineItemModel::find($item->line_item_id);
        if (!$lineItem) {
            return;
        }

        // Recount including the row just written.
        $items = OrderReturnItemModel::where('return_id', $item->return_id)
            ->where('line_item_id', $item->line_item_id)
            ->get();

        $back = 0.0;
        foreach ($items as $row) {
            $qty = (float) $row->quantity;
            $back += $row->unit === 'kg' && $line['weight_factor'] > 0
                ? $qty / $line['weight_factor']
                : $qty;
        }

        if ($back < ((float) $line['quantity'] - self::QTY_EPSILON)) {
            return; // still short — stock stays where it is
        }

        // ⚠ restoreInventory() would restore the line's CURRENT quantity. The
        // snapshot is what left; an order edited after the return must not write
        // up more (or less) stock than that. Same steps as restoreInventory(),
        // with the snapshot amount, idempotent on the same flag.
        if (!$lineItem->inventory_deducted) {
            return; // already restored, or nothing to restore
        }
        $restoreQty = round((float) $line['quantity'], 3);
        $variantId  = $lineItem->variant_id;
        if (!$variantId && !empty($lineItem->sku)) {
            $variantId = DB::table('t_crm_prod_product_variant')->where('sku', $lineItem->sku)->value('id');
        }
        if (!$variantId || $restoreQty <= 0) {
            return;
        }
        $before = DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('inventory_quantity');
        DB::table('t_crm_prod_product_variant')->where('id', $variantId)->increment('inventory_quantity', $restoreQty);
        $productId = DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('product_id');
        if ($productId) {
            $total = DB::table('t_crm_prod_product_variant')->where('product_id', $productId)->sum('inventory_quantity');
            DB::table('t_crm_prod_product')->where('id', $productId)->update(['total_inventory' => $total]);
        }
        $lineItem->inventory_deducted = 0;
        $lineItem->save();
        if (abs($restoreQty - (float) $lineItem->quantity) > 0.0005) {
            Log::warning('Returned frozen line restored from the return snapshot, not the edited line', [
                'order_id' => $order->id, 'line_item_id' => $lineItem->id,
                'snapshot_qty' => $restoreQty, 'line_qty_now' => (float) $lineItem->quantity,
            ]);
        }

        // Cancellation's restore writes no audit row; a return's does, so the
        // Frozen stock log can explain where the quantity came from.
        try {
            if (\Schema::hasTable('t_crm_store_inventory_adjustment')) {
                $after = DB::table('t_crm_prod_product_variant')->where('id', $variantId)->value('inventory_quantity');
                StoreInventoryAdjustmentModel::create([
                    'product_id'         => $lineItem->product_id,
                    'product_variant_id' => $variantId,
                    'business_unit_id'   => $item->business_unit_id,
                    'change_type'        => 'store_stock_in',
                    'quantity_before'    => $before,
                    'quantity_change'    => $restoreQty,
                    'quantity_after'     => $after,
                    'notes'              => 'Returned order ' . $order->order_number,
                    'created_by'         => $userId,
                    'created_at'         => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // The stock move is the important half; the audit row must never undo it.
            Log::warning('Could not write the store adjustment row for a returned frozen line', [
                'order_id'     => $order->id,
                'line_item_id' => $lineItem->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Finish a put-back. "Short" is allowed and recorded with a reason — a packet
     * that never came back is a fact, and forcing the store to fake a scan to
     * clear a banner would be worse than recording the truth.
     */
    public function complete(int $returnId, int $userId, bool $short = false, ?string $reason = null): array
    {
        if (!$this->tableReady()) {
            throw new OrderReturnException('Returns are not set up yet.');
        }

        return DB::transaction(function () use ($returnId, $userId, $short, $reason) {
            $return = OrderReturnModel::where('id', $returnId)->lockForUpdate()->first();
            if (!$return) {
                throw new OrderReturnException('Return not found.');
            }
            if ($return->completed_at) {
                return ['ok' => true, 'already' => true, 'message' => 'Already finished.'];
            }

            $order = OrderModel::find($return->order_id);
            if (!$order) {
                throw new OrderReturnException('Order not found.');
            }

            $progress = $this->progressFor($return, $order);

            if (!$progress['complete'] && !$short) {
                throw new OrderReturnException('Some items are still not back. Finish scanning, or use "Finish short" and say why.');
            }
            if (!$progress['complete'] && trim((string) $reason) === '') {
                throw new OrderReturnException('Say why the rest could not be put back — it is the only record of it.');
            }

            $return->completed_at    = now();
            $return->completed_by    = $userId;
            $return->completed_short = !$progress['complete'];
            $return->short_reason    = $progress['complete'] ? null : $this->trim($reason, 255);
            $return->save();

            Log::info('Return put-back completed', [
                'return_id' => $return->id,
                'order_id'  => $return->order_id,
                'short'     => $return->completed_short,
                'by'        => $userId,
            ]);

            return [
                'ok'      => true,
                'short'   => (bool) $return->completed_short,
                'message' => $return->completed_short
                    ? 'Recorded as finished short.'
                    : 'All items are back in stock.',
            ];
        });
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    public function summarise(OrderReturnModel $return): array
    {
        return [
            'return_id'     => (int) $return->id,
            'order_id'      => (int) $return->order_id,
            'money_action'  => $return->money_action,
            'money_state'   => $return->money_state,
            'amount'        => round((float) $return->amount, 2),
            'tip_returned'  => (bool) $return->tip_returned,
            'tip_amount'    => round((float) $return->tip_amount, 2),
            'goods_action'  => $return->goods_action,
            'meat_section'  => $return->meat_section,
            'reason'        => $return->reason,
            'decided_at'    => optional($return->decided_at)->format('Y-m-d H:i:s'),
            'completed_at'  => optional($return->completed_at)->format('Y-m-d H:i:s'),
            'completed_short' => (bool) $return->completed_short,
            'awaiting'      => $return->awaitingPutBack(),
        ];
    }

    private function normaliseSection(?string $section): string
    {
        return $section === OrderReturnModel::SECTION_CHILLER
            ? OrderReturnItemModel::DEST_CHILLER
            : OrderReturnItemModel::DEST_FREEZER;
    }

    private function sectionLabel(string $dest): string
    {
        return match ($dest) {
            OrderReturnItemModel::DEST_STORE_STOCK => 'Frozen stock',
            OrderReturnItemModel::DEST_CHILLER     => 'the chiller',
            default                                => 'the freezer',
        };
    }

    private function customerLabel(OrderModel $order): string
    {
        if (!empty($order->name) && trim($order->name) !== '') {
            return trim($order->name);
        }

        $customer = $order->customer_id ? CustomerModel::find($order->customer_id) : null;
        if ($customer) {
            $name = trim((string) ($customer->full_name ?? $customer->name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        $joined = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));

        return $joined !== '' ? $joined : 'Unknown Customer';
    }

    private function trim(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
