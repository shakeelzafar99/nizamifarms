<?php

namespace App\Services;

use App\Models\CRM\CustomerCreditModel;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderDiscountModel;
use App\Models\CRM\OrderModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use App\Services\FIN\BalancePostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer credit ("bucket") — THE only writer of t_crm_customer_credit.
 *
 * The idea: when a customer pays us more than an invoice, the extra is held as
 * their credit instead of vanishing. Later it pays for part of another order.
 *
 * ── The three rules this class exists to guarantee ────────────────────────
 *  1. A balance is SUM(amount) over the counting rows. There is no stored
 *     balance column anywhere, so a balance can never drift from its history.
 *  2. A balance can never go negative. Every consume is capped, under a row
 *     lock, against the balance AND the order total.
 *  3. Money is only ever posted to the ledger at the moment it really moves:
 *     a grant when it is approved, a consume when the order is DELIVERED.
 *     Applying credit to an undelivered order only RESERVES it — no ledger
 *     row at all — so the create/edit/cancel churn touches no money.
 *
 * ── Ledger shapes (verified against BalancePostingService's convention) ───
 *   grant   : from CUSTOMER_CREDIT (liability +) → to bank/cash (asset +)
 *             "the money is in our bank, but we owe it to the customer"
 *   consume : from Sales Revenue (revenue +)    → to CUSTOMER_CREDIT (liability −)
 *             "we owe them less, because they took goods for it"
 *   Because the order's own invoice posts at the ALREADY-REDUCED total, the
 *   invoice plus the consume add up to the full value of the sale — total
 *   revenue in the ledger stays correct.
 *
 * ⚠ SHOP customers are excluded by owner decision (Aug-2026): they settle
 *   orders through their own incremental payment flow and must not be mixed
 *   into this one.
 */
class CustomerCreditService
{
    public const ACCOUNT_CODE        = 'CUSTOMER_CREDIT';
    public const LEDGER_TYPE_GRANT   = 'customer_credit_grant';
    public const LEDGER_TYPE_CONSUME = 'customer_credit_consume';

    /** Below this, an overpayment is rounding noise, not credit worth tracking. */
    public const MIN_GRANT = 10.00;

    // =====================================================================
    // READS
    // =====================================================================

    /**
     * Is the feature's table actually there?
     *
     * Deploys here are manual and the PHP can reach the server before the SQL
     * is run. Rather than 500-ing every order save in that window, the whole
     * feature stays dormant: reads answer "no balance", so nothing is offered
     * and nothing breaks. Once the SQL runs it wakes up on its own.
     *
     * Cached per request — this is called on read paths that run per page load.
     */
    public function tableReady(): bool
    {
        static $ready = null;

        if ($ready === null) {
            try {
                $ready = \Schema::hasTable('t_crm_customer_credit');
            } catch (\Throwable $e) {
                $ready = false;
            }

            if (!$ready) {
                Log::warning('Customer credit is dormant — t_crm_customer_credit is missing. Run customer_credit_bucket_aug2026.sql.');
            }
        }

        return $ready;
    }

    /**
     * Follow the merge chain to the customer that actually owns the balance.
     * A merged-away customer's credit must answer on the surviving record.
     */
    public function resolveCustomerId(?int $customerId): ?int
    {
        if (!$customerId) {
            return null;
        }

        $seen = [];
        $id   = $customerId;

        // Bounded walk — a cycle in the data must not hang a page load.
        for ($hop = 0; $hop < 10; $hop++) {
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;

            $next = DB::table('t_crm_prod_customer')->where('id', $id)->value('merged_into_customer_id');
            if (!$next) {
                return (int) $id;
            }
            $id = (int) $next;
        }

        return (int) $id;
    }

    /**
     * Can this customer hold credit at all?
     * Shop customers are deliberately out (owner decision) — they settle via
     * their own incremental payment flow.
     */
    public function isEligible(?CustomerModel $customer): bool
    {
        return $customer !== null && !$customer->isShop();
    }

    public function isEligibleId(?int $customerId): bool
    {
        $id = $this->resolveCustomerId($customerId);

        return $id !== null && $this->isEligible(CustomerModel::find($id));
    }

    /**
     * The balance a manager may spend right now, in paisa (integer — money
     * comparisons must never ride on float equality).
     *
     * Counts active rows AND reserved consumes: money already applied to an
     * undelivered order is spoken for and must not be spendable twice.
     */
    public function balancePaisaFor(int $customerId, bool $lock = false): int
    {
        if (!$this->tableReady()) {
            return 0;
        }

        $id = $this->resolveCustomerId($customerId);
        if (!$id) {
            return 0;
        }

        $q = DB::table('t_crm_customer_credit')
            ->where('customer_id', $id)
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES);

        if ($lock) {
            $q->lockForUpdate();
        }

        $sum = 0.0;
        foreach ($q->get(['amount']) as $row) {
            $sum += (float) $row->amount;
        }

        return (int) round($sum * 100);
    }

    /** The spendable balance in rupees. */
    public function balanceFor(int $customerId): float
    {
        return round($this->balancePaisaFor($customerId) / 100, 2);
    }

    /**
     * Everything a screen needs to show the bucket: the number, whether there
     * is anything to offer, and the recent history behind it.
     */
    public function summaryFor(?int $customerId, int $historyLimit = 10): array
    {
        $id = $this->tableReady() ? $this->resolveCustomerId($customerId) : null;

        $empty = [
            'eligible'         => false,
            'customer_id'      => $id,
            'balance'          => 0.0,
            'balance_display'  => '0.00',
            'has_balance'      => false,
            'pending_total'    => 0.0,
            'pending_count'    => 0,
            'history'          => [],
        ];

        if (!$id) {
            return $empty;
        }

        $customer = CustomerModel::find($id);
        if (!$this->isEligible($customer)) {
            return $empty;
        }

        $balance = $this->balanceFor($id);

        // Grants still waiting for approval. Shown SEPARATELY and never added
        // to the balance — approvals money is not yet ours to spend.
        $pending = DB::table('t_crm_customer_credit')
            ->where('customer_id', $id)
            ->where('status', CustomerCreditModel::STATUS_PENDING)
            ->get(['amount']);

        return [
            'eligible'        => true,
            'customer_id'     => $id,
            'balance'         => $balance,
            'balance_display' => number_format($balance, 2),
            'has_balance'     => $balance >= 0.01,
            'pending_total'   => round((float) $pending->sum('amount'), 2),
            'pending_count'   => $pending->count(),
            'history'         => $this->historyFor($id, $historyLimit),
        ];
    }

    /**
     * Recent credit events, newest first, with the order number resolved so
     * the manager sees "from order SH-1234 on 12-Aug" rather than a raw id.
     */
    public function historyFor(int $customerId, int $limit = 10): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $id = $this->resolveCustomerId($customerId);
        if (!$id) {
            return [];
        }

        $rows = CustomerCreditModel::where('customer_id', $id)
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        $orderNumbers = [];
        $orderIds     = $rows->pluck('order_id')->filter()->unique()->values()->all();
        if (!empty($orderIds)) {
            $orderNumbers = DB::table('t_crm_prod_order')
                ->whereIn('id', $orderIds)
                ->pluck('order_number', 'id')
                ->all();
        }

        // Who did what. A money history that cannot name its actors is not an
        // audit trail — one batched lookup for every id on the page.
        $userIds = $rows->pluck('created_by')
            ->merge($rows->pluck('approved_by'))
            ->merge($rows->pluck('voided_by'))
            ->filter()->unique()->values()->all();
        $names = empty($userIds) ? [] : DB::table('t_sys_user')
            ->whereIn('id', $userIds)->pluck('fullname', 'id')->all();
        $nameOf = fn ($id) => $id ? ($names[$id] ?? ('User #' . $id)) : null;

        return $rows->map(function (CustomerCreditModel $r) use ($orderNumbers, $nameOf) {
            $amount = (float) $r->amount;

            return [
                'entered_by_name' => $nameOf($r->created_by),
                'approved_by_name' => $nameOf($r->approved_by),
                'voided_by_name'  => $nameOf($r->voided_by),
                'voided_reason'   => $r->voided_reason,
                'id'           => $r->id,
                'entry_type'   => $r->entry_type,
                'type_label'   => $r->type_label,
                'status'       => $r->status,
                'amount'       => round($amount, 2),
                'amount_abs'   => number_format(abs($amount), 2),
                'is_credit'    => $amount > 0,
                'counts'       => in_array($r->status, CustomerCreditModel::SPENDABLE_STATUSES, true),
                'source'       => $r->source,
                'order_id'     => $r->order_id,
                'order_number' => $r->order_id ? ($orderNumbers[$r->order_id] ?? null) : null,
                'reason'       => $r->reason,
                'date'         => optional($r->created_at)->format('d-M-Y'),
                'date_full'    => optional($r->created_at)->format('d M Y, g:i A'),
            ];
        })->all();
    }

    /** The live (reserved or active) consume row for an order, if any. */
    public function liveConsumeForOrder(int $orderId, bool $lock = false): ?CustomerCreditModel
    {
        if (!$this->tableReady()) {
            return null;
        }

        $q = CustomerCreditModel::where('order_id', $orderId)
            ->where('entry_type', CustomerCreditModel::TYPE_CONSUME)
            ->whereIn('status', CustomerCreditModel::SPENDABLE_STATUSES);

        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    // =====================================================================
    // GRANTS — money INTO the bucket
    // =====================================================================

    /**
     * May this user's own grants skip the approval queue?
     *
     * Owner decision (Aug-2026): when Shabib or Taimur add balance it is
     * approved by default — making the approver file a request and then
     * approve his own request is pure friction, and the "please approve"
     * banner nagging him about his own entry proved it.
     *
     * Who counts:
     *  · anyone with LEVEL 2 approval rights — they could approve it with one
     *    more click anyway (Taimur), OR
     *  · the same pair the manual-payment gate recognises (see
     *    PaymentSignalsController::canRecordManualPayment): the config email
     *    list / the taimur|shabib role. ⚠ This leg is NOT redundant — Shabib's
     *    login holds the shared "Management" role and NO L2 level, so only his
     *    email identifies him.
     *
     * Everyone else's grants stay pending and go through the queue.
     */
    public function userCanAutoApproveGrant(?\App\Models\User $user): bool
    {
        if (!$user) {
            return false;
        }

        if (\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel((int) $user->id, 2)) {
            return true;
        }

        $allowed = array_filter(array_map(
            fn ($e) => strtolower(trim($e)),
            explode(',', (string) config('payment_signals.manual_entry_emails', ''))
        ));
        if ($allowed && in_array(strtolower((string) $user->email), $allowed, true)) {
            return true;
        }

        return $user->roles()
            ->whereRaw('LOWER(urole_name) IN (?, ?)', ['taimur', 'shabib'])
            ->exists();
    }

    /**
     * Record extra money received from a customer as a grant.
     *
     * Normally PENDING — it does not count toward any balance, flows into the
     * approvals queue, and only becomes real money when an approver clicks
     * approve. But when the CREATOR is an approver themselves (Shabib/Taimur —
     * see userCanAutoApproveGrant), it is approved on the spot: same ledger
     * posting, same audit trail (created_by AND approved_by are stamped), just
     * no queue step. Check the returned row's `status` to know which happened.
     *
     * @param array{mode?:string,receiving_account_id?:int,order_id?:int,source?:string,
     *              source_payment_id?:int,signal_id?:int,reason?:string} $meta
     */
    public function requestGrant(int $customerId, float $amount, int $userId, array $meta = []): CustomerCreditModel
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('Account balance is not set up yet — the customer-credit SQL still needs to be run.');
        }

        $id = $this->resolveCustomerId($customerId);
        if (!$id) {
            throw new \RuntimeException('Customer not found.');
        }

        $customer = CustomerModel::find($id);
        if (!$this->isEligible($customer)) {
            throw new \RuntimeException('Shop customers do not use account balance — record the payment against their invoices instead.');
        }

        $amount = round($amount, 2);
        if ($amount < self::MIN_GRANT) {
            throw new \RuntimeException('Amount must be at least Rs ' . number_format(self::MIN_GRANT, 0) . '.');
        }

        // ⚠⚠ One grant per proof, and the check SPANS THE PAIR. A validated
        // manual claim and its bank SMS are two rows describing ONE payment;
        // without this, granting against each mate would hand the customer the
        // same extra twice.
        if (!empty($meta['signal_id'])) {
            $ids = [(int) $meta['signal_id']];
            $mate = DB::table('t_fin_payment_signal')->where('id', $meta['signal_id'])->value('paired_signal_id');
            if ($mate) {
                $ids[] = (int) $mate;
            }
            $taken = DB::table('t_crm_customer_credit')
                ->whereIn('signal_id', $ids)
                ->where('status', '!=', CustomerCreditModel::STATUS_VOIDED)
                ->exists();
            if ($taken) {
                throw new \RuntimeException('That payment has already been added to the customer\'s balance.');
            }
        }

        $credit = CustomerCreditModel::create([
            'customer_id'          => $id,
            'entry_type'           => CustomerCreditModel::TYPE_GRANT,
            'amount'               => $amount,          // positive
            'status'               => CustomerCreditModel::STATUS_PENDING,
            'order_id'             => $meta['order_id'] ?? null,
            'source'               => $meta['source'] ?? CustomerCreditModel::SOURCE_MANUAL,
            'source_payment_id'    => $meta['source_payment_id'] ?? null,
            'signal_id'            => $meta['signal_id'] ?? null,
            'receiving_account_id' => $meta['receiving_account_id'] ?? null,
            'reason'               => $meta['reason'] ?? null,
            'created_by'           => $userId,
        ]);

        Log::info('Customer credit grant requested', [
            'credit_id'   => $credit->id,
            'customer_id' => $id,
            'amount'      => $amount,
            'source'      => $credit->source,
            'by'          => $userId,
        ]);

        // Approver adding money themselves → approved on the spot. If the
        // ledger posting fails (account missing, etc.) the grant is NOT lost —
        // it simply stays pending in the queue, which is the safe direction.
        if ($this->userCanAutoApproveGrant(\App\Models\User::find($userId))) {
            $mode = ($meta['mode'] ?? null) === LedgerModel::MODE_CASH
                ? LedgerModel::MODE_CASH
                : LedgerModel::MODE_ONLINE;
            try {
                return $this->approveGrant($credit->id, $userId, $mode);
            } catch (\Throwable $e) {
                Log::warning('Customer credit auto-approve failed — grant left pending', [
                    'credit_id' => $credit->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $credit;
    }

    /**
     * Approve a pending grant: it becomes spendable balance and the money is
     * recorded in the ledger (bank up, "we owe the customer" up).
     *
     * @param string $mode 'online' or 'cash' — where the money physically landed.
     */
    public function approveGrant(int $creditId, int $userId, string $mode = LedgerModel::MODE_ONLINE): CustomerCreditModel
    {
        return DB::transaction(function () use ($creditId, $userId, $mode) {
            $credit = CustomerCreditModel::where('id', $creditId)->lockForUpdate()->first();

            if (!$credit) {
                throw new \RuntimeException('Credit entry not found.');
            }
            if ($credit->entry_type !== CustomerCreditModel::TYPE_GRANT) {
                throw new \RuntimeException('Only a grant can be approved.');
            }
            if ($credit->status === CustomerCreditModel::STATUS_ACTIVE) {
                return $credit; // idempotent — a double click must not post twice
            }
            if ($credit->status !== CustomerCreditModel::STATUS_PENDING) {
                throw new \RuntimeException('This credit entry is ' . $credit->status . ' and cannot be approved.');
            }

            $ledger = $this->postGrantLedger($credit, $userId, $mode);

            $credit->status                = CustomerCreditModel::STATUS_ACTIVE;
            $credit->approved_by           = $userId;
            $credit->approved_at           = now();
            $credit->ledger_transaction_id = $ledger?->id;
            $credit->save();

            Log::info('Customer credit grant approved', [
                'credit_id'  => $credit->id,
                'amount'     => $credit->amount,
                'ledger_id'  => $ledger?->id,
                'by'         => $userId,
            ]);

            return $credit;
        });
    }

    /** Reject a pending grant — it never becomes balance. */
    public function rejectGrant(int $creditId, int $userId, ?string $reason = null): CustomerCreditModel
    {
        return DB::transaction(function () use ($creditId, $userId, $reason) {
            $credit = CustomerCreditModel::where('id', $creditId)->lockForUpdate()->first();

            if (!$credit) {
                throw new \RuntimeException('Credit entry not found.');
            }
            if ($credit->status !== CustomerCreditModel::STATUS_PENDING) {
                throw new \RuntimeException('Only a pending entry can be rejected.');
            }

            $credit->status        = CustomerCreditModel::STATUS_VOIDED;
            $credit->voided_by     = $userId;
            $credit->voided_at     = now();
            $credit->voided_reason = $reason ?: 'Rejected at approval';
            $credit->save();

            return $credit;
        });
    }

    // =====================================================================
    // CONSUME — money OUT of the bucket, onto an order
    // =====================================================================

    /**
     * Apply credit to an order.
     *
     * Pre-delivery this only RESERVES the money (no ledger row) and writes the
     * sentinel discount line that actually reduces what the customer pays.
     * If the order is already delivered, the consume is finalised immediately
     * instead, because the value has already left the building.
     *
     * The amount is capped — never trust a client number with money.
     *
     * @return array{applied:float,balance_after:float,credit_id:int}
     */
    public function applyToOrder(int $orderId, float $requestedAmount, int $userId): array
    {
        return DB::transaction(function () use ($orderId, $requestedAmount, $userId) {
            /** @var OrderModel|null $order */
            $order = OrderModel::where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                throw new \RuntimeException('Order not found.');
            }

            $this->assertOrderCanTakeCredit($order);

            $customerId = $this->resolveCustomerId($order->customer_id);
            if (!$customerId) {
                throw new \RuntimeException('This order has no customer, so it cannot use an account balance.');
            }
            if (!$this->isEligible(CustomerModel::find($customerId))) {
                throw new \RuntimeException('Shop customers do not use account balance.');
            }

            if ($this->liveConsumeForOrder($order->id, true)) {
                throw new \RuntimeException('This order is already using the account balance. Remove it first to change the amount.');
            }

            // --- the cap: balance, and what is left to pay on the order ---
            $balancePaisa   = $this->balancePaisaFor($customerId, true);
            $orderTotal     = (int) round(((float) $order->total_price) * 100);
            $requestedPaisa = (int) round(round($requestedAmount, 2) * 100);

            if ($balancePaisa <= 0) {
                throw new \RuntimeException('This customer has no available balance.');
            }
            if ($requestedPaisa <= 0) {
                throw new \RuntimeException('Enter an amount greater than zero.');
            }

            $appliedPaisa = min($requestedPaisa, $balancePaisa, $orderTotal);
            if ($appliedPaisa <= 0) {
                throw new \RuntimeException('There is nothing left to pay on this order.');
            }
            $applied = round($appliedPaisa / 100, 2);

            // An order that is already delivered has consumed the value now;
            // anything earlier is only a reservation until it is delivered.
            $alreadyDelivered = in_array($order->order_status, ['delivered', 'completed'], true);

            $credit = CustomerCreditModel::create([
                'customer_id' => $customerId,
                'entry_type'  => CustomerCreditModel::TYPE_CONSUME,
                'amount'      => -1 * $applied,     // negative
                'status'      => $alreadyDelivered
                    ? CustomerCreditModel::STATUS_ACTIVE
                    : CustomerCreditModel::STATUS_RESERVED,
                'order_id'    => $order->id,
                'source'      => CustomerCreditModel::SOURCE_MANUAL,
                'reason'      => 'Applied to order ' . $order->order_number,
                'created_by'  => $userId,
            ]);

            if ($alreadyDelivered) {
                $ledger = $this->postConsumeLedger($credit, $order, $userId);
                $credit->ledger_transaction_id = $ledger?->id;
                $credit->approved_by           = $userId;
                $credit->approved_at           = now();
                $credit->save();
            }

            $this->writeSentinelDiscount($order, $applied, $userId);
            $this->applyDiscountToOrderTotals($order, $applied);

            Log::info('Customer credit applied to order', [
                'credit_id'    => $credit->id,
                'order_id'     => $order->id,
                'order_number' => $order->order_number,
                'amount'       => $applied,
                'state'        => $credit->status,
                'by'           => $userId,
            ]);

            return [
                'applied'       => $applied,
                'balance_after' => $this->balanceFor($customerId),
                'credit_id'     => $credit->id,
            ];
        });
    }

    /**
     * Take the credit back off an order and return it to the customer's balance.
     *
     * Used when a manager un-applies it, and automatically when an order is
     * cancelled. If the consume had already posted to the ledger (delivered
     * order), that posting is reversed too.
     *
     * @param bool $adjustTotals false when the caller is mid-rebuild of the
     *        order's discount rows and will recompute the totals itself.
     */
    public function releaseFromOrder(int $orderId, ?int $userId, string $reason, bool $adjustTotals = true): ?float
    {
        return DB::transaction(function () use ($orderId, $userId, $reason, $adjustTotals) {
            $credit = $this->liveConsumeForOrder($orderId, true);
            if (!$credit) {
                return null;
            }

            // Once the order's INVOICE has passed L1 its (credit-reduced) amount
            // is in the account balances — un-applying the credit here would
            // raise the order total while the posted invoice stays at the lower
            // figure, and the two would never agree again. So a locked invoice
            // blocks release. Cancellation still works: changeStatus REVERSES
            // the invoice before this runs, and a reversed row passes.
            $invoiceLedgerId = DB::table('t_crm_prod_order')->where('id', $orderId)->value('ledger_transaction_id');
            if ($invoiceLedgerId) {
                $st = DB::table('t_fin_ledger')->where('id', $invoiceLedgerId)->value('approval_status');
                $releasable = in_array($st, [
                    LedgerModel::STATUS_PENDING,
                    LedgerModel::STATUS_PENDING_L1,
                    LedgerModel::STATUS_REVERSED,
                    LedgerModel::STATUS_REJECTED,
                ], true) || $st === null;
                if (!$releasable) {
                    throw new \RuntimeException(
                        'This order\'s invoice has already been approved — the applied balance is locked. '
                        . 'Revert the invoice to pending first, or cancel the order.'
                    );
                }
            }

            $released = round(abs((float) $credit->amount), 2);

            // A consume that reached the ledger (delivered) must be unwound
            // there as well, or the liability stays cleared for money we are
            // handing back.
            if ($credit->ledger_transaction_id) {
                $ledger = LedgerModel::find($credit->ledger_transaction_id);
                if ($ledger && $ledger->approval_status !== LedgerModel::STATUS_REVERSED) {
                    (new BalancePostingService())->reverse($ledger);
                    $ledger->approval_status = LedgerModel::STATUS_REVERSED;
                    $ledger->comments = trim((string) $ledger->comments) !== ''
                        ? $ledger->comments . ' | REVERSED: ' . $reason
                        : 'REVERSED: ' . $reason;
                    $ledger->save();
                }
            }

            $credit->status        = CustomerCreditModel::STATUS_VOIDED;
            $credit->voided_by     = $userId;
            $credit->voided_at     = now();
            $credit->voided_reason = $reason;
            $credit->save();

            // Take the sentinel line off the order so the order and the bucket
            // tell the same story. Leaving it would discount an order that no
            // longer has credit behind it.
            $removed = $this->removeSentinelDiscount($orderId);
            if ($adjustTotals && $removed > 0) {
                $order = OrderModel::where('id', $orderId)->lockForUpdate()->first();
                if ($order) {
                    $this->applyDiscountToOrderTotals($order, -1 * $removed);
                }
            }

            Log::info('Customer credit released from order', [
                'credit_id' => $credit->id,
                'order_id'  => $orderId,
                'amount'    => $released,
                'reason'    => $reason,
                'by'        => $userId,
            ]);

            return $released;
        });
    }

    /**
     * Delivery: a reservation becomes a real consume and posts to the ledger.
     * Called from OrderModel::changeStatus inside its own transaction.
     */
    public function finaliseForOrder(OrderModel $order, ?int $userId = null): ?float
    {
        $credit = $this->liveConsumeForOrder($order->id, true);
        if (!$credit || $credit->status !== CustomerCreditModel::STATUS_RESERVED) {
            return null;
        }

        $ledger = $this->postConsumeLedger($credit, $order, $userId);

        $credit->status                = CustomerCreditModel::STATUS_ACTIVE;
        $credit->approved_by           = $userId;
        $credit->approved_at           = now();
        $credit->ledger_transaction_id = $ledger?->id;
        $credit->save();

        Log::info('Customer credit finalised on delivery', [
            'credit_id'    => $credit->id,
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
            'amount'       => abs((float) $credit->amount),
            'ledger_id'    => $ledger?->id,
        ]);

        return round(abs((float) $credit->amount), 2);
    }

    /**
     * Money this order has already received that a cancellation would leave
     * sitting in our accounts owned by nobody.
     *
     * Cancelling voids the payment rows, but a payment whose ledger entry is
     * already settled cannot be reversed — so the rupees stay with us while the
     * order they belonged to disappears. That is the money the manager is asked
     * about ("move it to the customer's balance?"). Nothing here changes
     * anything; it only describes the choice.
     *
     * @return array{amount:float,can_credit:bool,customer_name:?string,reason:?string}
     */
    public function strandedPaymentInfoFor(OrderModel $order): array
    {
        $none = ['amount' => 0.0, 'can_credit' => false, 'customer_name' => null, 'reason' => null];

        if (!$this->tableReady()) {
            return $none;
        }

        $ledgerIds = DB::table('t_crm_order_payments')
            ->where('order_id', $order->id)
            ->where('status', 'active')
            ->whereNotNull('ledger_transaction_id')
            ->pluck('ledger_transaction_id');

        if ($ledgerIds->isEmpty()) {
            return $none;
        }

        $amount = 0.0;
        foreach ($ledgerIds as $ledgerId) {
            $ledger = LedgerModel::find($ledgerId);
            if (!$ledger || $ledger->approval_status === LedgerModel::STATUS_REVERSED) {
                continue;
            }
            // Only rows the cancellation cannot reverse are actually stranded.
            if ($ledger->settlement_status === 'settled' || ((float) $ledger->settled_amount) > 0) {
                $amount += (float) $ledger->amount;
            }
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return $none;
        }

        $customerId = $this->resolveCustomerId($order->customer_id);
        if (!$customerId) {
            return array_merge($none, ['amount' => $amount, 'reason' => 'This order has no customer record.']);
        }
        if (!$this->isEligible(CustomerModel::find($customerId))) {
            return array_merge($none, [
                'amount' => $amount,
                'reason' => 'Shop customers settle through their own invoices, so this cannot become an account balance.',
            ]);
        }

        return [
            'amount'        => $amount,
            'can_credit'    => true,
            'customer_name' => $this->customerLabel($customerId),
            'reason'        => null,
        ];
    }

    // =====================================================================
    // ADJUST — the manual zero-out
    // =====================================================================

    /**
     * Set a customer's balance to zero.
     *
     * The owner's chosen escape hatch instead of refunds: no money leaves the
     * business, the balance is simply written off, and the write-off is a
     * normal row in the history with who/when/why on it.
     */
    public function zeroOut(int $customerId, int $userId, string $reason): float
    {
        return DB::transaction(function () use ($customerId, $userId, $reason) {
            $id = $this->resolveCustomerId($customerId);
            if (!$id) {
                throw new \RuntimeException('Customer not found.');
            }

            $balancePaisa = $this->balancePaisaFor($id, true);
            if ($balancePaisa <= 0) {
                throw new \RuntimeException('This customer has no balance to clear.');
            }

            $amount = round($balancePaisa / 100, 2);

            $credit = CustomerCreditModel::create([
                'customer_id' => $id,
                'entry_type'  => CustomerCreditModel::TYPE_ADJUST,
                'amount'      => -1 * $amount,
                'status'      => CustomerCreditModel::STATUS_ACTIVE,
                'source'      => CustomerCreditModel::SOURCE_ZERO_OUT,
                'reason'      => $reason,
                'created_by'  => $userId,
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            // The liability must go too, or the books keep saying we hold money
            // the customer can no longer spend. The other side is revenue: an
            // unclaimed advance we are keeping is income to the business.
            $ledger = $this->postLedgerRow(
                type: self::LEDGER_TYPE_CONSUME,
                description: 'Customer credit written off — ' . $this->customerLabel($id) . ' (' . $reason . ')',
                fromAccount: ConfigModel::getSalesRevenueAccount(),
                toAccount: $this->creditAccount(),
                amount: $amount,
                userId: $userId,
                mode: null,
                receivingAccountId: null,
                orderId: null
            );

            if ($ledger) {
                $credit->ledger_transaction_id = $ledger->id;
                $credit->save();
            }

            Log::info('Customer credit zeroed out', [
                'customer_id' => $id,
                'amount'      => $amount,
                'reason'      => $reason,
                'by'          => $userId,
            ]);

            return $amount;
        });
    }

    // =====================================================================
    // MERGE
    // =====================================================================

    /**
     * Move a merged-away customer's credit rows onto the surviving customer.
     * Without this a merge would strand the balance on a hidden record.
     */
    public function repointOnMerge(int $fromCustomerId, int $toCustomerId): int
    {
        if (!$this->tableReady()) {
            return 0;
        }

        return DB::table('t_crm_customer_credit')
            ->where('customer_id', $fromCustomerId)
            ->update(['customer_id' => $toCustomerId, 'updated_at' => now()]);
    }

    // =====================================================================
    // INTERNALS
    // =====================================================================

    /**
     * Credit may only be applied while the order's invoice has not yet been
     * posted to balances. Past that point the invoice amount is already in the
     * ledger and quietly discounting it would drift the books — the same
     * boundary the payment-balancing discount uses.
     */
    private function assertOrderCanTakeCredit(OrderModel $order): void
    {
        if ($order->order_status === 'cancelled') {
            throw new \RuntimeException('This order is cancelled.');
        }

        if ($order->ledger_transaction_id) {
            $status = DB::table('t_fin_ledger')->where('id', $order->ledger_transaction_id)->value('approval_status');
            $preL1  = [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1];
            if (!in_array($status, $preL1, true)) {
                throw new \RuntimeException('This invoice has already been approved — the balance can no longer be applied to it.');
            }
        }
    }

    /** The sentinel discount row is server-owned; the client can never write it. */
    private function writeSentinelDiscount(OrderModel $order, float $amount, int $userId): void
    {
        OrderDiscountModel::create([
            'order_id'        => $order->id,
            'discount_title'  => 'Account balance applied',
            'discount_amount' => $amount,
            'discount_type'   => 'fixed',
            'coupon_code'     => CustomerCreditModel::DISCOUNT_CODE,
            'display_order'   => 998,
            'notes'           => 'Paid from the customer\'s account balance (Rs ' . number_format($amount, 2) . ').',
            'created_by'      => $userId,
        ]);
    }

    /** @return float the total amount removed */
    private function removeSentinelDiscount(int $orderId): float
    {
        $rows = OrderDiscountModel::where('order_id', $orderId)
            ->where('coupon_code', CustomerCreditModel::DISCOUNT_CODE)
            ->get();

        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += (float) $row->discount_amount;
            $row->delete();
        }

        return round($sum, 2);
    }

    /**
     * Move the order's money columns by a discount delta (positive = a new
     * discount, negative = one being taken away), and mirror it onto an
     * unposted invoice ledger row the same way the balancing discount does.
     */
    private function applyDiscountToOrderTotals(OrderModel $order, float $delta): void
    {
        $order->discount_total = round((float) $order->discount_total + $delta, 2);
        $order->total_price    = round((float) $order->total_price - $delta, 2);
        $order->save();

        if (method_exists($order, 'recalculatePaymentStatus')) {
            $order->recalculatePaymentStatus();
        }

        // Mirror the change onto an invoice ledger row that has NOT yet been
        // posted to balances, so the eventual approval posts the real amount.
        // A row past pre-L1 is already in the account balances and is left alone
        // (assertOrderCanTakeCredit refuses to get us here in the first place).
        if ($order->ledger_transaction_id) {
            $row = DB::table('t_fin_ledger')
                ->where('id', $order->ledger_transaction_id)
                ->first(['amount', 'approval_status']);

            $preL1 = [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1];
            if ($row && in_array($row->approval_status, $preL1, true)) {
                DB::table('t_fin_ledger')
                    ->where('id', $order->ledger_transaction_id)
                    ->update([
                        'amount'     => max(0, round((float) $row->amount - $delta, 2)),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function creditAccount(): ?AccountModel
    {
        return AccountModel::getByCode(self::ACCOUNT_CODE);
    }

    /**
     * Where the customer's money physically sits.
     * Mirrors LedgerPostingService's destination logic so credit lands in the
     * same account an invoice for the same order would have.
     */
    private function holdingAccount(?OrderModel $order, string $mode): ?AccountModel
    {
        if ($mode === LedgerModel::MODE_ONLINE) {
            return ConfigModel::getOnlineBankAccount();
        }

        if ($order && $order->assigned_rider_user_id) {
            $rider = \App\Models\SysAdmin\UserModel::find($order->assigned_rider_user_id);
            if ($rider) {
                return AccountModel::createEmployeeCashAccount($rider->id, $rider->fullname ?? $rider->name);
            }
        }

        return AccountModel::getByCode('NF_CASH');
    }

    private function postGrantLedger(CustomerCreditModel $credit, int $userId, string $mode): ?LedgerModel
    {
        $order = $credit->order_id ? OrderModel::find($credit->order_id) : null;
        $from  = $this->creditAccount();

        // Where the other side of the entry goes depends on where the money
        // already is:
        //
        //  · cancellation — the customer paid, we banked it, and the ledger
        //    already called it revenue. Nothing new arrives; we are only
        //    re-labelling revenue we can no longer keep as money we owe. So the
        //    other side is Sales Revenue (un-recognised) and the bank is left
        //    exactly as it is. Posting to a bank here would count the same
        //    rupees twice, because the original payment row is still applied.
        //
        //  · everything else — genuinely new money landing in an account.
        $isReclassification = $credit->source === CustomerCreditModel::SOURCE_CANCELLATION;
        $to = $isReclassification
            ? ConfigModel::getSalesRevenueAccount()
            : $this->holdingAccount($order, $mode);

        if (!$from || !$to) {
            Log::error('Customer credit grant could not post — account missing', [
                'credit_id'      => $credit->id,
                'credit_account' => $from?->id,
                'holding'        => $to?->id,
            ]);

            throw new \RuntimeException('The Customer Credit account is not set up. Run the customer-credit SQL first.');
        }

        $label = $this->customerLabel($credit->customer_id);
        $desc  = 'Customer credit — ' . $label;
        if ($order) {
            $desc .= ' (extra on order #' . $order->order_number . ')';
        }

        return $this->postLedgerRow(
            type: self::LEDGER_TYPE_GRANT,
            description: $desc,
            fromAccount: $from,
            toAccount: $to,
            amount: round((float) $credit->amount, 2),
            userId: $userId,
            mode: $mode,
            receivingAccountId: $credit->receiving_account_id,
            orderId: $credit->order_id
        );
    }

    private function postConsumeLedger(CustomerCreditModel $credit, OrderModel $order, ?int $userId): ?LedgerModel
    {
        $from = ConfigModel::getSalesRevenueAccount();
        $to   = $this->creditAccount();

        if (!$from || !$to) {
            Log::error('Customer credit consume could not post — account missing', [
                'credit_id' => $credit->id,
                'order_id'  => $order->id,
            ]);

            throw new \RuntimeException('The Customer Credit or Sales Revenue account is missing.');
        }

        return $this->postLedgerRow(
            type: self::LEDGER_TYPE_CONSUME,
            description: 'Account balance used on order #' . $order->order_number . ' (' . $this->customerLabel($credit->customer_id) . ')',
            fromAccount: $from,
            toAccount: $to,
            amount: round(abs((float) $credit->amount), 2),
            userId: $userId,
            mode: null,
            receivingAccountId: null,
            orderId: $order->id
        );
    }

    /**
     * One place that writes a ledger row for this feature. Rows are approved
     * on creation (the approval already happened in this feature's own flow)
     * and applied through the canonical balance engine.
     */
    private function postLedgerRow(
        string $type,
        string $description,
        ?AccountModel $fromAccount,
        ?AccountModel $toAccount,
        float $amount,
        ?int $userId,
        ?string $mode,
        ?int $receivingAccountId,
        ?int $orderId
    ): ?LedgerModel {
        if (!$fromAccount || !$toAccount || $amount <= 0) {
            return null;
        }

        $ledger = LedgerModel::create([
            'transaction_date'     => now(),
            'transaction_type'     => $type,
            'description'          => $description,
            'from_account_id'      => $fromAccount->id,
            'to_account_id'        => $toAccount->id,
            'amount'               => $amount,
            'mode'                 => $mode,
            'receiving_account_id' => $receivingAccountId,
            'approval_status'      => LedgerModel::STATUS_APPROVED,
            'approval_date'        => now(),
            'approved_by'          => $userId,
            'settlement_status'    => 'open',
            'settled_amount'       => 0.00,
            'order_id'             => $orderId,
            'balance_updated'      => 0,   // the engine owns this
            'created_by'           => $userId ?? 1,
        ]);

        (new BalancePostingService())->apply($ledger);

        return $ledger;
    }

    private function customerLabel(int $customerId): string
    {
        $c = CustomerModel::find($customerId);
        if (!$c) {
            return 'Customer #' . $customerId;
        }

        $name = trim((string) ($c->full_name ?? '')) ?: trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));

        return $name !== '' ? $name : ('Customer #' . $customerId);
    }
}
