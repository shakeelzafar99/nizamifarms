<?php

namespace App\Services\FIN;

use App\Models\FIN\AccountModel;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\LedgerModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The staff tip pool.
 *
 * A tip rides INSIDE the invoice (`total = subtotal − discount + shipping +
 * tip`), so the invoice ledger row books it into Sales Revenue along with
 * everything else. It is not our income: it is money we hold until it is handed
 * over. Owner ruling (Sep-3-2026): from TIPS_FUND_START_DATE every tip is moved
 * out of revenue into the TIPS_FUND liability, and spending it is a payout from
 * a real cash/bank account — never an expense.
 *
 *     fund balance = opening + collected − paid out
 *
 * ⭐⭐ ONE CHOKE POINT. `BalancePostingService::apply()/reverse()` is the single
 * place every invoice row's balance effect passes through — seventeen files
 * post invoice rows (cash delivery, web L1, mobile L1, reversals, corrections).
 * The collection hook lives there, so a door nobody remembered, and any door
 * added later, is covered for free. Never post a companion row from a
 * controller.
 *
 * ⭐ IDEMPOTENT BY CONSTRUCTION. `syncForOrder` compares what the order SHOULD
 * have collected against what it HAS collected and moves the difference. Run it
 * once, run it a hundred times, run it after an edit or a reversal — the answer
 * converges to exactly one live collected row per order. That is what makes the
 * backfill command safe to re-run.
 */
class TipsFundService
{
    /** Ignore rounding dust. */
    public const EPSILON = 0.01;

    /** Smallest payout worth recording. */
    public const MIN_PAYOUT = 1.00;

    private static ?bool $ready = null;

    // ------------------------------------------------------------------
    //  Setup / guards
    // ------------------------------------------------------------------

    /**
     * ⭐ Dormant until the SQL has run.
     *
     * PHP is uploaded by hand and can land before the migration. Without this,
     * the first delivery afterwards would throw inside a money transaction and
     * a rider would be told his delivery failed. Instead the feature simply
     * does nothing until the account exists.
     */
    public function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        try {
            return self::$ready = (bool) $this->account();
        } catch (\Throwable $e) {
            return self::$ready = false;
        }
    }

    /** Test seam. */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    public function account(): ?AccountModel
    {
        return AccountModel::where('account_code', AccountModel::CODE_TIPS_FUND)
            ->where('is_active', 1)
            ->first();
    }

    /** Date from which tips stop being income. Shared with the report layer. */
    public function cutoff(): string
    {
        return ProfitRevenueSql::cutoff();
    }

    /** What the pool is holding right now. */
    public function balance(): float
    {
        return round((float) ($this->account()->current_balance ?? 0), 2);
    }

    // ------------------------------------------------------------------
    //  Collection
    // ------------------------------------------------------------------

    /**
     * Called by the balance engine for every invoice row it applies or reverses.
     *
     * ⚠ Must never throw: it runs inside live money transactions, and a
     * reporting companion that can break a delivery is worse than a missing
     * companion. A failure is logged loudly and `tips:backfill` repairs it.
     */
    public function syncForInvoiceRow(LedgerModel $row): void
    {
        if ($row->transaction_type !== LedgerModel::TYPE_INVOICE || empty($row->order_id)) {
            return;
        }

        try {
            $this->syncForOrder((int) $row->order_id, $row->approved_by ?: $row->created_by);
        } catch (\Throwable $e) {
            Log::error('Tips Fund: could not sync an invoice — run `php artisan tips:backfill`', [
                'ledger_id' => $row->id,
                'order_id'  => $row->order_id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bring this order's collected tip in line with what it should be.
     *
     * Returns the signed amount moved (0 = already correct).
     */
    public function syncForOrder(int $orderId, ?int $userId = null): float
    {
        if (!$this->ready()) {
            return 0.0;
        }

        return DB::transaction(function () use ($orderId, $userId) {
            // Serialise concurrent applies for the same order, or two callers
            // could each see "nothing collected yet" and both post.
            $order = DB::table('t_crm_prod_order')->where('id', $orderId)
                ->lockForUpdate()
                ->first(['id', 'order_number', 'tip_amount', 'order_status', 'ledger_transaction_id']);

            if (!$order) {
                return 0.0;
            }

            $desired  = $this->desiredTipFor($order);
            $existing = $this->collectedFor($orderId);

            if (abs($desired - $existing) < self::EPSILON) {
                return 0.0;
            }

            // Reverse whatever is there and post the truth. Converging to ONE
            // live row per order keeps the statement readable and makes the
            // arithmetic obvious when someone audits an order later.
            $this->reverseCollected($orderId, $userId);

            if ($desired < self::EPSILON) {
                return -$existing;
            }

            $this->postCollected($order, $desired, $userId);

            return $desired - $existing;
        });
    }

    /**
     * What this order's tip SHOULD be sitting in the fund.
     *
     * Zero unless every one of these holds:
     *   · the order is not cancelled and carries a tip;
     *   · it was FIRST delivered on/after the cutoff — the same instant the
     *     report layer uses, so profit and the fund can never disagree about
     *     which tips left the P&L (see ProfitRevenueSql::tipExcluded);
     *   · its invoice row exists and has actually been applied to balances.
     *     Until then the tip has not been booked as revenue, so there is
     *     nothing to move out of revenue yet. Online invoices waiting at Level
     *     1 are counted as `pending` on the Tips page rather than collected —
     *     shown, not hidden.
     */
    private function desiredTipFor(object $order): float
    {
        if (strtolower((string) $order->order_status) === 'cancelled') {
            return 0.0;
        }

        $tip = round((float) ($order->tip_amount ?? 0), 2);
        if ($tip < self::EPSILON) {
            return 0.0;
        }

        if (!$this->deliveredOnOrAfterCutoff((int) $order->id)) {
            return 0.0;
        }

        return $this->invoiceRowApplied($order) ? $tip : 0.0;
    }

    /** The order's FIRST delivery, compared on the date alone (prod runs +2h). */
    private function deliveredOnOrAfterCutoff(int $orderId): bool
    {
        $first = DB::table('t_crm_order_status_history')
            ->where('order_id', $orderId)
            ->where('status_code', 'delivered')
            ->min('changed_at');

        if (!$first) {
            return false;
        }

        return Carbon::parse($first)->format('Y-m-d') >= $this->cutoff();
    }

    /** The live invoice row for this order, if it has reached the balances. */
    private function invoiceRow(object $order): ?LedgerModel
    {
        $row = $order->ledger_transaction_id
            ? LedgerModel::find($order->ledger_transaction_id)
            : null;

        if (!$row || $row->transaction_type !== LedgerModel::TYPE_INVOICE) {
            $row = LedgerModel::where('order_id', $order->id)
                ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->orderBy('id')
                ->first();
        }

        return $row;
    }

    private function invoiceRowApplied(object $order): bool
    {
        $row = $this->invoiceRow($order);

        return $row
            && $row->approval_status !== LedgerModel::STATUS_REVERSED
            && (bool) $row->balance_updated;
    }

    /** Tip already sitting in the fund for this order. */
    public function collectedFor(int $orderId): float
    {
        return round((float) DB::table('t_fin_ledger')
            ->where('order_id', $orderId)
            ->where('transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->sum('amount'), 2);
    }

    private function reverseCollected(int $orderId, ?int $userId): void
    {
        $rows = LedgerModel::where('order_id', $orderId)
            ->where('transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            (new BalancePostingService())->reverse($row);
            $row->approval_status = LedgerModel::STATUS_REVERSED;
            $row->updated_by      = $userId;
            $row->save();
        }
    }

    /**
     * Move the tip out of Sales Revenue and into the pool.
     *
     * from = TIPS_FUND (liability) → +tip owed
     * to   = Sales Revenue (income) → revenue reduced by the tip
     *
     * The invoice row itself is untouched: the customer's invoice, the
     * receivable and the bank attribution all stay exactly as they were.
     */
    private function postCollected(object $order, float $tip, ?int $userId): LedgerModel
    {
        $invoice = $this->invoiceRow($order);

        return $this->postRow(
            type: LedgerModel::TYPE_TIP_COLLECTED,
            description: 'Tip on order #' . $order->order_number,
            fromAccount: $this->account(),
            toAccount: ConfigModel::getSalesRevenueAccount(),
            amount: $tip,
            userId: $userId,
            orderId: (int) $order->id,
            // The invoice's own date, so the fund's month matches the month the
            // sale was reported in.
            when: $invoice?->transaction_date ? Carbon::parse($invoice->transaction_date) : now()
        );
    }

    /**
     * Collect every tip the pool should already be holding.
     *
     * ⭐ Idempotent: each order goes through syncForOrder, which moves only the
     * difference, so running it twice collects nothing twice. Shared by the
     * artisan command AND the Taimur-only button on the Hub Tips page — prod
     * has no shell, so the button is the door that actually gets used.
     *
     * @return array{moved:float,changed:int,already:int,waiting:int,failed:int,
     *               pending:float,balance:float,items:array<int,array{order:string,amount:float}>}
     */
    public function backfill(bool $dryRun = false): array
    {
        $this->assertReady();

        $cutoff = $this->cutoff();

        // Candidates: tipped, non-cancelled orders FIRST delivered on/after the
        // cutoff. Qurbani orders never post an invoice row, so syncForOrder
        // refuses them — the same rule that keeps their tips in Qurbani revenue.
        $orders = DB::table('t_crm_prod_order as o')
            ->joinSub(
                DB::table('t_crm_order_status_history')
                    ->select('order_id')
                    ->selectRaw('MIN(changed_at) AS first_delivered_at')
                    ->where('status_code', 'delivered')
                    ->groupBy('order_id'),
                'd', 'd.order_id', '=', 'o.id'
            )
            ->where('o.tip_amount', '>', 0)
            ->whereRaw("LOWER(COALESCE(o.order_status,'')) <> 'cancelled'")
            ->whereRaw('DATE(d.first_delivered_at) >= ?', [$cutoff])
            ->orderBy('o.id')
            ->get(['o.id', 'o.order_number', 'o.tip_amount']);

        $out = ['moved' => 0.0, 'changed' => 0, 'already' => 0, 'waiting' => 0, 'failed' => 0,
                'items' => [], 'cutoff' => $cutoff, 'candidates' => $orders->count()];

        foreach ($orders as $o) {
            $held = $this->collectedFor((int) $o->id);

            if ($dryRun) {
                $applied = LedgerModel::where('order_id', $o->id)
                    ->where('transaction_type', LedgerModel::TYPE_INVOICE)
                    ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                    ->where('balance_updated', 1)
                    ->exists();
                $delta = round(($applied ? round((float) $o->tip_amount, 2) : 0.0) - $held, 2);
            } else {
                try {
                    $delta = $this->syncForOrder((int) $o->id);
                } catch (\Throwable $e) {
                    $out['failed']++;
                    Log::error('Tips Fund backfill: order failed', ['order_id' => $o->id, 'error' => $e->getMessage()]);
                    continue;
                }
            }

            if (abs($delta) < self::EPSILON) {
                $held > 0 ? $out['already']++ : $out['waiting']++;
                continue;
            }

            $out['moved'] += $delta;
            $out['changed']++;
            $out['items'][] = ['order' => (string) $o->order_number, 'amount' => round($delta, 2)];
        }

        $out['moved']   = round($out['moved'], 2);
        $out['pending'] = $this->pendingTips();
        $out['balance'] = $this->balance();

        return $out;
    }

    // ------------------------------------------------------------------
    //  Opening balance
    // ------------------------------------------------------------------

    /** The opening-balance row, if one has been set. */
    public function openingRow(): ?LedgerModel
    {
        $account = $this->account();
        if (!$account) {
            return null;
        }

        return LedgerModel::where('transaction_type', LedgerModel::TYPE_OPENING_BALANCE)
            ->where('from_account_id', $account->id)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->orderBy('id')
            ->first();
    }

    /**
     * What the pool already held when tracking started (Taimur, once).
     *
     * from = TIPS_FUND (liability) → the pool goes up
     * to   = Opening Balance Equity → the money existed before we tracked it
     */
    public function setOpeningBalance(float $amount, int $userId, ?string $note = null): LedgerModel
    {
        $this->assertReady();

        $amount = round($amount, 2);
        if ($amount < self::EPSILON) {
            throw new \RuntimeException('Enter the amount the tip pool is holding today.');
        }

        if ($this->openingRow()) {
            throw new \RuntimeException(
                'An opening balance has already been set. Record a payout instead, or ask for the existing one to be undone.'
            );
        }

        $equity = ConfigModel::getOpeningEquityAccount();
        if (!$equity) {
            throw new \RuntimeException('The Opening Balance Equity account is missing.');
        }

        return DB::transaction(function () use ($amount, $userId, $note, $equity) {
            return $this->postRow(
                type: LedgerModel::TYPE_OPENING_BALANCE,
                description: 'Tips Fund opening balance' . ($note ? ' — ' . $note : ''),
                fromAccount: $this->account(),
                toAccount: $equity,
                amount: $amount,
                userId: $userId,
                orderId: null,
                when: now()
            );
        });
    }

    // ------------------------------------------------------------------
    //  Payouts
    // ------------------------------------------------------------------

    /**
     * Hand tip money over.
     *
     * ⭐ The pool is ONE number, not a set of buckets. Tips arrive in whatever
     * account the customer paid into, but a payout draws the whole amount from
     * whichever real account is chosen — it is never split to match where the
     * tips came from. The cash left in the other account is an ordinary
     * internal difference, exactly like paying any bill from the bank while the
     * till holds cash.
     *
     * from = the real cash/bank/staff-cash account → money leaves
     * to   = TIPS_FUND (liability) → we owe that much less
     *
     * @param array{amount:float,from_account_id:int,receiving_account_id?:int|null,
     *              reason:string,given_to?:string|null,date?:string|null} $data
     */
    public function payout(array $data, int $userId): LedgerModel
    {
        $this->assertReady();

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount < self::MIN_PAYOUT) {
            throw new \RuntimeException('Enter how much is being paid out.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new \RuntimeException('Say what this payout is for.');
        }

        $from = AccountModel::find($data['from_account_id'] ?? 0);
        if (!$from || !$from->is_active) {
            throw new \RuntimeException('Choose which account the money is coming from.');
        }

        // Only real money can pay a tip out. Anything else (the fund itself, a
        // vendor payable, an expense account) would move the liability without
        // any cash actually leaving.
        $allowed = [
            AccountModel::CATEGORY_CASH,
            AccountModel::CATEGORY_BANK,
            AccountModel::CATEGORY_EMPLOYEE_CASH,
        ];
        if (!in_array($from->account_category, $allowed, true)) {
            throw new \RuntimeException('Tips can only be paid from a cash, bank or staff-cash account.');
        }

        // ⚠ A bank movement that does not name WHICH bank is invisible to the
        // per-bank balances and nothing downstream goes back and guesses.
        $receiving = $data['receiving_account_id'] ?? null;
        if ($from->account_category === AccountModel::CATEGORY_BANK && !$receiving) {
            throw new \RuntimeException('Select which bank the money is going out of.');
        }

        return DB::transaction(function () use ($amount, $from, $receiving, $reason, $data, $userId) {
            // Lock the fund row so two payouts cannot both pass the balance
            // check and overdraw the pool between them.
            $account = AccountModel::lockForUpdate()->find($this->account()->id);
            $available = round((float) $account->current_balance, 2);

            if ($amount > $available + self::EPSILON) {
                throw new \RuntimeException(
                    'The tip pool only holds Rs ' . number_format($available, 2) . '.'
                );
            }

            $givenTo = trim((string) ($data['given_to'] ?? ''));
            $desc = 'Tip payout — ' . $reason . ($givenTo !== '' ? ' (to ' . $givenTo . ')' : '');

            return $this->postRow(
                type: LedgerModel::TYPE_TIP_PAYOUT,
                description: $desc,
                fromAccount: $from,
                toAccount: $account,
                amount: $amount,
                userId: $userId,
                orderId: null,
                when: !empty($data['date']) ? Carbon::parse($data['date']) : now(),
                mode: $from->account_category === AccountModel::CATEGORY_BANK
                    ? LedgerModel::MODE_ONLINE
                    : LedgerModel::MODE_CASH,
                receivingAccountId: $receiving ? (int) $receiving : null
            );
        });
    }

    /** Undo a payout that was entered wrongly. Money returns to the pool. */
    public function undoPayout(int $ledgerId, int $userId): void
    {
        $this->assertReady();

        DB::transaction(function () use ($ledgerId, $userId) {
            $row = LedgerModel::lockForUpdate()->find($ledgerId);

            if (!$row || $row->transaction_type !== LedgerModel::TYPE_TIP_PAYOUT) {
                throw new \RuntimeException('That is not a tip payout.');
            }
            if ($row->approval_status === LedgerModel::STATUS_REVERSED) {
                throw new \RuntimeException('That payout has already been undone.');
            }

            (new BalancePostingService())->reverse($row);
            $row->approval_status = LedgerModel::STATUS_REVERSED;
            $row->updated_by      = $userId;
            $row->save();
        });
    }

    // ------------------------------------------------------------------
    //  Reading
    // ------------------------------------------------------------------

    /**
     * Headline numbers. `pending` is tip money on delivered orders whose
     * invoice has not been approved yet — real, but not in the pool until the
     * invoice posts.
     */
    public function summary(?Carbon $start = null, ?Carbon $end = null): array
    {
        if (!$this->ready()) {
            return ['ready' => false, 'balance' => 0.0, 'opening' => 0.0,
                    'collected' => 0.0, 'paid_out' => 0.0, 'pending' => 0.0, 'uncollected' => 0.0,
                    'collected_online' => 0.0, 'collected_cash' => 0.0,
                    'opening_set' => false, 'cutoff' => $this->cutoff()];
        }

        $window = function ($q) use ($start, $end) {
            if ($start && $end) {
                $q->whereBetween('transaction_date', [$start, $end]);
            }
            return $q;
        };

        $collected = (float) $window(LedgerModel::where('transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED))->sum('amount');

        $paidOut = (float) $window(LedgerModel::where('transaction_type', LedgerModel::TYPE_TIP_PAYOUT)
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED))->sum('amount');

        // How the collected tips arrived. Informational only — it never
        // constrains a payout, which draws from one chosen account.
        $split = $window(DB::table('t_fin_ledger as l')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->where('l.transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
            ->where('l.approval_status', '!=', LedgerModel::STATUS_REVERSED))
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(o.payment_method,'')) IN ('online','bank_transfer','card','online_payment') THEN l.amount ELSE 0 END),0) online_amt")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(o.payment_method,'')) NOT IN ('online','bank_transfer','card','online_payment') THEN l.amount ELSE 0 END),0) cash_amt")
            ->first();

        $opening = $this->openingRow();

        return [
            'ready'            => true,
            'balance'          => $this->balance(),
            'opening'          => round((float) ($opening->amount ?? 0), 2),
            'opening_set'      => (bool) $opening,
            'collected'        => round($collected, 2),
            'collected_online' => round((float) ($split->online_amt ?? 0), 2),
            'collected_cash'   => round((float) ($split->cash_amt ?? 0), 2),
            'paid_out'         => round($paidOut, 2),
            'pending'          => $this->pendingTips(),
            'uncollected'      => $this->uncollectedTips(),
            'cutoff'           => $this->cutoff(),
        ];
    }

    /**
     * Tips on delivered orders that the pool has NOT collected yet, because the
     * invoice is still waiting for approval. Shown so the Tips page never
     * quietly disagrees with the Reports tab.
     */
    public function pendingTips(): float
    {
        return $this->notYetCollected(false);
    }

    /**
     * Tips on delivered orders whose invoice IS approved but that the pool has
     * not collected yet — invoices delivered before the code existed, or after
     * the start date was moved. These do NOT collect themselves: pressing
     * "Collect missing tips" (or `tips:backfill`) does it. Shown as its own
     * message so nobody waits for something that is never going to happen.
     */
    public function uncollectedTips(): float
    {
        return $this->notYetCollected(true);
    }

    /**
     * Tipped, non-cancelled orders first delivered on/after the cutoff with no
     * live collected row — split by whether their invoice has reached the
     * balances. ⚠ Both halves must be told apart: "waiting on approval" fixes
     * itself, "not collected yet" needs the button.
     */
    private function notYetCollected(bool $invoiceApplied): float
    {
        if (!$this->ready()) {
            return 0.0;
        }

        $q = DB::table('t_crm_prod_order as o')
            ->join(DB::raw('(SELECT order_id, MIN(changed_at) AS first_delivered_at
                             FROM t_crm_order_status_history
                             WHERE status_code = \'delivered\' GROUP BY order_id) AS d'),
                   'd.order_id', '=', 'o.id')
            ->where('o.tip_amount', '>', 0)
            ->whereRaw('DATE(d.first_delivered_at) >= ?', [$this->cutoff()])
            ->whereRaw("LOWER(COALESCE(o.order_status,'')) <> 'cancelled'")
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_fin_ledger as tc')
                  ->whereColumn('tc.order_id', 'o.id')
                  ->where('tc.transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
                  ->where('tc.approval_status', '!=', LedgerModel::STATUS_REVERSED);
            });

        $applied = function ($q) {
            $q->select(DB::raw(1))->from('t_fin_ledger as inv')
              ->whereColumn('inv.order_id', 'o.id')
              ->where('inv.transaction_type', LedgerModel::TYPE_INVOICE)
              ->where('inv.approval_status', '!=', LedgerModel::STATUS_REVERSED)
              ->where('inv.balance_updated', 1);
        };
        $invoiceApplied ? $q->whereExists($applied) : $q->whereNotExists($applied);

        return round((float) $q->sum('o.tip_amount'), 2);
    }

    /**
     * Month by month, from the cutoff to now — the pool's own figures next to
     * what the Reports tab says left profit that month, so the two can be
     * compared at a glance.
     *
     *   reports_tips  = tips on orders FIRST delivered in the month, with the
     *                   Reports tab's own filters (delivered/completed, not the
     *                   Shopify staging source, not Qurbani, on/after cutoff) —
     *                   i.e. that month's `tips_excluded` on the Reports card.
     *   collected     = tip_collected rows dated in the month.
     *   paid_out      = tip_payout rows dated in the month.
     *
     * The two tip figures differ ONLY when an invoice is still awaiting
     * approval (collects itself later) or has not been collected yet (needs
     * the button) — `gap` says by how much.
     *
     * @return array<int,array<string,mixed>> newest month first
     */
    public function monthly(): array
    {
        if (!$this->ready()) {
            return [];
        }

        $cutoff = Carbon::parse($this->cutoff())->startOfMonth();
        $cursor = Carbon::now()->startOfMonth();
        $out = [];

        while ($cursor->gte($cutoff)) {
            $start = $cursor->copy()->startOfMonth();
            $end   = $cursor->copy()->endOfMonth();

            $rq = DB::table('t_crm_prod_order as o')
                ->joinSub(
                    DB::table('t_crm_order_status_history')->select('order_id')
                        ->selectRaw('MIN(changed_at) AS first_delivered_at')
                        ->where('status_code', 'delivered')->groupBy('order_id'),
                    'd', 'd.order_id', '=', 'o.id'
                )
                ->whereBetween('d.first_delivered_at', [$start, $end])
                ->whereRaw('DATE(d.first_delivered_at) >= ?', [$this->cutoff()])
                ->whereIn('o.order_status', ['delivered', 'completed'])
                ->where(function ($w) {
                    $w->whereNull('o.external_source')->orWhere('o.external_source', '!=', 'shopify');
                })
                ->where('o.tip_amount', '>', 0);
            \App\Services\QurbaniFinanceFilter::applyToOrderQuery($rq, 'o', \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE);
            $reportsTips = round((float) $rq->sum('o.tip_amount'), 2);

            $collected = round((float) LedgerModel::where('transaction_type', LedgerModel::TYPE_TIP_COLLECTED)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereBetween('transaction_date', [$start, $end])->sum('amount'), 2);
            $paidOut = round((float) LedgerModel::where('transaction_type', LedgerModel::TYPE_TIP_PAYOUT)
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->whereBetween('transaction_date', [$start, $end])->sum('amount'), 2);

            $out[] = [
                'ym'           => $start->format('Y-m'),
                'label'        => $start->format('F Y'),
                'start'        => $start->format('Y-m-d'),
                'end'          => $end->format('Y-m-d'),
                'reports_tips' => $reportsTips,
                'collected'    => $collected,
                'paid_out'     => $paidOut,
                'gap'          => round($reportsTips - $collected, 2),
            ];
            $cursor->subMonth();
        }

        return $out;
    }

    /**
     * Every movement, newest first, with a running balance.
     *
     * @return array<int,array<string,mixed>>
     */
    public function statement(?Carbon $start = null, ?Carbon $end = null, int $limit = 500): array
    {
        if (!$this->ready()) {
            return [];
        }

        $account = $this->account();

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
            ->leftJoin('t_fin_accounts as fa', 'fa.id', '=', 'l.from_account_id')
            ->where(function ($w) use ($account) {
                $w->where('l.from_account_id', $account->id)
                  ->orWhere('l.to_account_id', $account->id);
            })
            ->where('l.approval_status', '!=', LedgerModel::STATUS_REVERSED);

        if ($start && $end) {
            $q->whereBetween('l.transaction_date', [$start, $end]);
        }

        $rows = $q->orderBy('l.transaction_date')->orderBy('l.id')
            ->limit($limit)
            ->get([
                'l.id', 'l.transaction_date', 'l.transaction_type', 'l.description',
                'l.amount', 'l.order_id', 'l.from_account_id',
                'o.order_number', 'o.payment_method', 'u.fullname as by_name',
                'fa.account_name as from_account_name',
            ]);

        // Collected and opening ADD to the pool; a payout takes from it.
        // A windowed statement starts from what the pool ALREADY held before
        // the window, or "Pool after" would restart from zero every month.
        $running = 0.0;
        if ($start) {
            $before = DB::table('t_fin_ledger')
                ->where(function ($w) use ($account) {
                    $w->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id);
                })
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->where('transaction_date', '<', $start)
                ->selectRaw("COALESCE(SUM(CASE WHEN transaction_type = ? THEN -amount ELSE amount END),0) AS net",
                    [LedgerModel::TYPE_TIP_PAYOUT])
                ->value('net');
            $running = round((float) $before, 2);
        }
        $out = [];
        foreach ($rows as $r) {
            $isIn = $r->transaction_type !== LedgerModel::TYPE_TIP_PAYOUT;
            $amount = round((float) $r->amount, 2);
            $running = round($running + ($isIn ? $amount : -$amount), 2);

            $out[] = [
                'id'           => (int) $r->id,
                'date'         => (string) $r->transaction_date,
                'type'         => $r->transaction_type,
                'type_label'   => LedgerModel::typeLabel($r->transaction_type),
                'description'  => (string) $r->description,
                'order_id'     => $r->order_id ? (int) $r->order_id : null,
                'order_number' => $r->order_number,
                'paid_by'      => $r->payment_method,
                'from_account' => $r->from_account_name,
                'by'           => $r->by_name,
                'in'           => $isIn ? $amount : 0.0,
                'out'          => $isIn ? 0.0 : $amount,
                'running'      => $running,
            ];
        }

        return array_reverse($out);
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    private function assertReady(): void
    {
        if (!$this->ready()) {
            throw new \RuntimeException(
                'The Tips Fund account is not set up yet. Run the tips-fund SQL first.'
            );
        }
    }

    /**
     * The ONE place this feature writes a ledger row.
     *
     * Rows are approved on creation — the approval already happened in this
     * feature's own flow (an invoice was approved, or Taimur/Shabib pressed the
     * button) — and applied through the canonical balance engine so the
     * account locking and the per-bank attribution rules are the same as
     * everywhere else.
     */
    private function postRow(
        string $type,
        string $description,
        ?AccountModel $fromAccount,
        ?AccountModel $toAccount,
        float $amount,
        ?int $userId,
        ?int $orderId,
        Carbon $when,
        ?string $mode = null,
        ?int $receivingAccountId = null
    ): LedgerModel {
        if (!$fromAccount || !$toAccount) {
            throw new \RuntimeException('The Tips Fund or Sales Revenue account is missing.');
        }

        $ledger = LedgerModel::create([
            'transaction_date'     => $when,
            'transaction_type'     => $type,
            'description'          => $description,
            'from_account_id'      => $fromAccount->id,
            'to_account_id'        => $toAccount->id,
            'amount'               => round($amount, 2),
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
}
