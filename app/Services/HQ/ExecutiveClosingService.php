<?php

namespace App\Services\HQ;

use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Services\FIN\BankBalanceService;
use App\Services\QurbaniFinanceFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * HQ EXECUTIVE CLOSING SERVICE  (Phase 1 — read-only)
 * ===================================================
 * Powers the /hq executive dashboard: the "Monthly Closing" view and the
 * working-capital strip. This service is **strictly read-only** — it only
 * issues SELECT/aggregate queries and reuses existing read-only helpers
 * (BankBalanceService, AccountModel::getTotalEmployeeCashCalculatedBalance,
 * QurbaniFinanceFilter). It never writes, never mutates a balance, and does
 * not touch any operational endpoint. Safe to call from a cached AJAX route.
 *
 * Definitions (agreed with owner, Jul-2026) — kept deliberately explicit so
 * the numbers reconcile with existing surfaces:
 *
 *  • REVENUE is DELIVERED revenue, attributed to the day the order's status
 *    first/last became 'delivered' (t_crm_order_status_history.changed_at),
 *    NOT order_date. This is the "when did the money actually land" view the
 *    owner asked for. (The legacy /dashboard uses order_date; they differ on
 *    purpose.)
 *  • BUSINESS UNITS:
 *      - 'all'  = the regular ongoing business = NF + Khaas (Qurbani excluded).
 *      - 'nf'   = Nizami Farms portion of regular orders (line-item level).
 *      - 'kh'   = Khaas / frozen portion of regular orders (line-item level,
 *                 business_unit_id = Khaas). A single shared invoice is split
 *                 line by line; delivery charges & order discounts stay with NF
 *                 (owner rule) because NF = order total − Khaas lines.
 *      - 'qb'   = Qurbani. NOT a real business unit and NOT month-bound: it is
 *                 seasonal, so the Qurbani view is SEASON-TO-DATE (calendar
 *                 year). Qurbani has NO vendor purchases — its costs arrive as
 *                 expenses under the 'qurbani' request category. So Qurbani P&L
 *                 = revenue − qurbani expenses (no gross-profit step).
 *  • COGS (vendor purchases) and EXPENSES come from t_fin_ledger, counted at
 *    approval_status IN (approved, pending_l2) — balance applies at L1, so
 *    pending_l2 is effectively posted (same convention as BankBalanceService &
 *    the Finance monthly screen).
 *  • QURBANI is identified by QurbaniFinanceFilter (the single source of truth
 *    used by /qurbani/invoices and Monthly Reports), so Qurbani orders/expenses
 *    are cleanly separated and never double-counted against NF.
 */
class ExecutiveClosingService
{
    public const UNIT_ALL = 'all';
    public const UNIT_NF  = 'nf';
    public const UNIT_KH  = 'kh';
    public const UNIT_QB  = 'qb';

    /** Ledger rows whose balance has effectively posted (L1 onward). */
    private const POSTED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2,
    ];

    /** Order statuses that count as delivered revenue. */
    private const DELIVERED_STATUSES = ['delivered', 'completed'];

    private ?int $khaasIdCache = null;

    public function __construct(private BankBalanceService $bankBalances)
    {
    }

    // =====================================================================
    //  PUBLIC ENTRY POINTS
    // =====================================================================

    /**
     * The full "Monthly Closing" KPI block for one unit + period, with the
     * previous period's figures for deltas. Cached 5 min.
     *
     * @param string $unit  one of UNIT_*
     * @param int    $year
     * @param int    $month 1-12 (ignored for Qurbani, which is season/year)
     */
    public function closing(string $unit, int $year, int $month, bool $fresh = false): array
    {
        $unit = $this->normalizeUnit($unit);
        $key  = "hq_closing_{$unit}_{$year}_{$month}";

        if ($fresh) {
            // Refresh button: drop this block's cache AND the underlying window
            // aggregates so the recompute really re-reads the database.
            Cache::forget($key);
            Cache::forget('hq_qurbani_order_ids');
            [$s, $e] = $this->period($unit, $year, $month);
            [$ps, $pe] = $this->previousPeriod($unit, $year, $month);
            Cache::forget('hq_raw_' . $s->getTimestamp() . '_' . $e->getTimestamp());
            Cache::forget('hq_raw_' . $ps->getTimestamp() . '_' . $pe->getTimestamp());
        }

        return Cache::remember($key, $this->ttlFor($unit, $year, $month), function () use ($unit, $year, $month) {
            [$start, $end, $label, $isOpen] = $this->period($unit, $year, $month);
            $cur  = $this->computeClosing($unit, $start, $end);

            // Previous period (previous month, or previous year for Qurbani).
            [$pStart, $pEnd] = $this->previousPeriod($unit, $year, $month);
            $prev = $this->computeClosing($unit, $pStart, $pEnd);

            $workingDays = $this->workingDays($start, $end);

            return [
                'unit'          => $unit,
                'period_label'  => $label,
                'window'        => [
                    'start' => $start->toDateString(),
                    'end'   => $end->toDateString(),
                ],
                'is_open'       => $isOpen,
                'is_season'     => $unit === self::UNIT_QB,
                'working_days'  => $workingDays,
                'current'       => $cur,
                'previous'      => $prev,
                'derived'       => [
                    'aov'                   => $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 0) : 0,
                    'gp_margin'             => $cur['revenue'] > 0 ? round($cur['gross_profit'] / $cur['revenue'] * 100, 1) : 0,
                    'np_margin'             => $cur['revenue'] > 0 ? round($cur['net_profit'] / $cur['revenue'] * 100, 1) : 0,
                    'revenue_per_work_day'  => $workingDays > 0 ? round($cur['revenue'] / $workingDays, 0) : 0,
                    'orders_per_work_day'   => $workingDays > 0 ? round($cur['orders'] / $workingDays, 1) : 0,
                    'net_per_work_day'      => $workingDays > 0 ? round($cur['net_profit'] / $workingDays, 0) : 0,
                ],
            ];
        });
    }

    /**
     * Cache TTL policy: a CLOSED period's numbers barely change → cache 24h;
     * the open (current) period stays fresh at 5 min. Cuts both dashboard
     * latency and DB load on the shared host — the Refresh button's fresh=1
     * still bypasses everything.
     */
    private function ttlFor(string $unit, int $year, int $month): int
    {
        $now = Carbon::now();
        $isOpen = $unit === self::UNIT_QB
            ? ($year === $now->year)
            : ($year === $now->year && $month === $now->month);
        return $isOpen ? 300 : 86400;
    }

    /**
     * Six-month (or six-period) trend of revenue + net profit for a unit,
     * ending at the given year/month. Cached 5 min.
     */
    public function trend(string $unit, int $year, int $month, int $periods = 6, bool $fresh = false): array
    {
        $unit = $this->normalizeUnit($unit);
        $key  = "hq_trend_{$unit}_{$year}_{$month}_{$periods}";
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, $this->ttlFor($unit, $year, $month), function () use ($unit, $year, $month, $periods) {
            $out = [];
            $cursor = Carbon::create($year, $month, 1);
            for ($i = $periods - 1; $i >= 0; $i--) {
                $p = $cursor->copy()->subMonths($i);
                [$start, $end] = $this->period($unit, $p->year, $p->month);
                // Light path: trend only needs revenue + net profit, so skip the
                // heavy per-unit kg / distinct-customer / new-customer counts.
                $pt = $this->trendPoint($unit, $start, $end);
                $out[] = [
                    'label'      => $p->format('M y'),
                    'revenue'    => $pt['revenue'],
                    'net_profit' => $pt['net_profit'],
                ];
            }
            return $out;
        });
    }

    /**
     * Company-wide working-capital snapshot (LIVE, as of now). Deliberately
     * NOT unit-split: cash, banks, rider cash and receivables are shared
     * across units, so this is a whole-company health strip. Cached 5 min.
     */
    /**
     * Working capital, SCOPED (owner rule, round 3): the Qurbani money lives in
     * its own accounts and must never appear in the NF / Khaas / Overall view —
     * and the Qurbani view must show ONLY Qurbani money.
     *   scope 'regular' (unit all/nf/kh): all cash+online EXCEPT QURBANI_*,
     *     receivables = approvals + shop + rider, payables, assets, health.
     *   scope 'qurbani' (unit qb): QURBANI_CASH + QURBANI_ONLINE + this
     *     season's order balances; no vendors, no assets, no health tile.
     */
    public function workingCapital(string $unit = self::UNIT_NF, bool $fresh = false): array
    {
        $u = $this->normalizeUnit($unit);
        if ($u === self::UNIT_QB) {
            if ($fresh) Cache::forget('hq_working_capital_qurbani');
            return Cache::remember('hq_working_capital_qurbani', 300, fn () => $this->qurbaniWorkingCapital());
        }
        // Regular scope: the shared cash/online/receivables/payables are the same
        // across NF/Khaas/all; only the FIXED ASSETS card follows the unit
        // selector, so we cache one payload per regular unit.
        $key = "hq_working_capital_regular_{$u}";
        if ($fresh) Cache::forget($key);
        return Cache::remember($key, 300, fn () => $this->regularWorkingCapital($u));
    }

    /** Qurbani-only position: its own cash + online accounts + season balances. */
    private function qurbaniWorkingCapital(): array
    {
        $cashRows = AccountModel::where('account_category', AccountModel::CATEGORY_CASH)
            ->where('is_active', 1)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, true))
            ->get(['account_name', 'current_balance']);
        $cash = (float) $cashRows->sum('current_balance');

        $onlineAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, true))
            ->get(['account_name', 'current_balance'])
            ->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])
            ->all();
        $online = array_sum(array_column($onlineAccounts, 'balance'));

        $season = $this->qurbaniSeasonReceivable();
        $receivables = $season['amount'];
        $net = $cash + $online + $receivables; // no vendor payables — Qurbani has no vendors

        return [
            'scope'       => 'qurbani',
            'as_of'       => Carbon::now()->toDateTimeString(),
            'cash'        => round($cash, 0),
            'cash_total'  => round($cash, 0),
            'cash_accounts' => $cashRows->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])->skip(1)->values()->all(),
            'cash_label'  => $cashRows->first()->account_name ?? 'Qurbani Cash',
            'online'      => round($online, 0),
            'online_accounts' => $onlineAccounts,
            'receivables' => [
                'total'   => round($receivables, 0),
                'season'  => round($season['amount'], 0),
                'season_count' => $season['count'],
                'regular' => 0, 'regular_count' => 0, 'shop' => 0, 'shop_count' => 0,
                'rider' => 0, 'overdue' => 0,
            ],
            'payables'    => 0,
            'assets'      => null,
            'net'         => round($net, 0),
            'health'      => [],
        ];
    }

    /** WHERE fragment: qurbani-owned accounts (code/name carry QURBANI). */
    private function qurbaniAccountWhere($q, bool $isQurbani): void
    {
        if ($isQurbani) {
            $q->where(function ($w) {
                $w->where('account_code', 'like', 'QURBANI%')
                  ->orWhereRaw("LOWER(account_name) LIKE '%qurbani%'");
            });
        } else {
            $q->where(function ($w) {
                $w->where(function ($x) {
                    $x->where('account_code', 'not like', 'QURBANI%')
                      ->whereRaw("LOWER(account_name) NOT LIKE '%qurbani%'");
                })->orWhereNull('account_code');
            });
        }
    }

    /** Current season's delivered qurbani orders not fully paid (QUR{yy}-). */
    private function qurbaniSeasonReceivable(): array
    {
        $prefix = 'QUR' . Carbon::now()->format('y') . '%';
        $row = DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->whereColumn('o.total_paid', '<', 'o.total_price')
            ->selectRaw('COUNT(*) n, COALESCE(SUM(o.total_price - COALESCE(o.total_paid,0)),0) amt')
            ->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    /** Regular (NF + Khaas) position — every QURBANI_* account excluded. */
    private function regularWorkingCapital(string $unit = self::UNIT_ALL): array
    {
        // --- Cash in hand. Headline = NF_CASH (the actual till, owner rule);
        //     other REGULAR cash accounts (Khaas till, expense fund) are shown
        //     as labelled rows. QURBANI_CASH is excluded — it belongs to the
        //     Qurbani view only (owner rule, round 3). ---
        $cashRows = AccountModel::where('account_category', AccountModel::CATEGORY_CASH)
            ->where('is_active', 1)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, false))
            ->orderByRaw("CASE WHEN account_code = 'NF_CASH' THEN 0 ELSE 1 END")
            ->get(['account_code', 'account_name', 'current_balance']);
        $nfCash = 0.0; $cashTotal = 0.0; $cashList = [];
        foreach ($cashRows as $a) {
            $bal = (float) $a->current_balance;
            $cashTotal += $bal;
            if ($a->account_code === 'NF_CASH') { $nfCash = $bal; continue; }
            if (abs($bal) >= 1) {
                $cashList[] = ['name' => $a->account_name, 'balance' => round($bal, 0)];
            }
        }

        // --- Online. Headline = the REGULAR online ledger (the ONLINE account;
        //     QURBANI_ONLINE excluded — Qurbani view only). The per-bank rows
        //     are an unreconciled SEGREGATION and live in the drill. ---
        $onlineAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->where(fn ($q) => $this->qurbaniAccountWhere($q, false))
            ->orderByRaw("CASE WHEN account_code = 'ONLINE' THEN 0 ELSE 1 END")
            ->get(['account_name', 'current_balance'])
            ->map(fn ($a) => ['name' => $a->account_name, 'balance' => round((float) $a->current_balance, 0)])
            ->all();
        $online = array_sum(array_column($onlineAccounts, 'balance'));

        // The FULL pool (incl. Qurbani Online) — only for the health check,
        // because the per-bank segregation spans both online accounts.
        $allOnlinePool = (float) AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->sum('current_balance');
        $banksById = $this->bankBalances->balancesByBank();
        $bankMeta  = DB::table('t_fin_online_receiving_accounts')
            ->get(['id', 'name', 'short_code', 'color_hex'])->keyBy('id');
        $banks = [];
        $trackedSum = 0.0;
        foreach ($banksById as $id => $b) {
            $trackedSum += $b['balance'];
            if (abs($b['balance']) < 1) continue; // hide zero rows; drill shows all
            $meta = $bankMeta[$id] ?? null;
            $banks[] = [
                'name'    => $meta->name ?? ('Bank #' . $id),
                'short'   => $meta->short_code ?? '',
                'color'   => $meta->color_hex ?? '#64748b',
                'balance' => round($b['balance'], 0),
            ];
        }
        usort($banks, fn ($a, $b) => $b['balance'] <=> $a['balance']);
        $unassigned = round($this->bankBalances->unassignedNet(), 0);

        // --- Payables: what we owe vendors (positive = we owe). ---
        $payables = (float) AccountModel::where('account_category', AccountModel::CATEGORY_VENDOR_PAYABLE)
            ->where('is_active', 1)
            ->sum('current_balance');

        // --- Receivables: mirrors ONLINE APPROVALS (owner rule — legacy
        //     orders are considered closed). regular = pending invoice
        //     ledger rows (All Pending); shop = the Shop tab's outstanding;
        //     + rider cash to settle. Qurbani is NOT here. ---
        $regular = $this->regularPendingReceivable();
        $shop    = $this->shopOutstandingReceivable();
        $rider   = round(AccountModel::getTotalEmployeeCashCalculatedBalance(), 0);
        $receivables = $regular['amount'] + $shop['amount'] + $rider;

        // --- Fixed assets (info card — NOT part of net working capital).
        //     Follows the unit selector: NF → NF assets, Khaas → frozen, all →
        //     both (owner rule, round 4). ---
        $assets = ['count' => 0, 'value' => 0, 'units' => []];
        try {
            $khaasId = $this->khaasBusinessUnitId();
            $aq = DB::table('t_fin_assets as a')
                ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
                ->where('a.status', 'active');
            if ($unit === self::UNIT_NF) {
                $aq->where(function ($w) { $w->where('a.business_unit_id', 1)->orWhereNull('a.business_unit_id'); });
            } elseif ($unit === self::UNIT_KH) {
                $aq->where('a.business_unit_id', $khaasId ?? -1);
            }
            $rows = $aq->groupBy('a.business_unit_id')
                ->selectRaw('COALESCE(MAX(bu.name),"Nizami Farms") unit, COUNT(*) n, COALESCE(SUM(a.current_book_value),0) v')
                ->get();
            foreach ($rows as $r) {
                $assets['count'] += (int) $r->n;
                $assets['value'] += (float) $r->v;
                $assets['units'][] = ['unit' => $r->unit, 'count' => (int) $r->n, 'value' => round((float) $r->v, 0)];
            }
            $assets['value'] = round($assets['value'], 0);
        } catch (\Throwable $e) { /* assets module optional */ }

        $net = $cashTotal + $online + $receivables - $payables;

        return [
            'scope'       => 'regular',
            'as_of'       => Carbon::now()->toDateTimeString(),
            'cash'        => round($nfCash, 0),
            'cash_total'  => round($cashTotal, 0),
            'cash_accounts' => $cashList,
            'cash_label'  => 'NF Cash (Main Till)',
            'online'      => round($online, 0),
            'online_accounts' => $onlineAccounts,
            'online_tracked' => round($trackedSum, 0),
            'online_unassigned' => $unassigned,
            'banks'       => $banks,
            'receivables' => [
                'total'    => round($receivables, 0),
                'regular'  => round($regular['amount'], 0),
                'regular_count' => $regular['count'],
                'shop'     => round($shop['amount'], 0),
                'shop_count' => $shop['count'],
                'rider'    => $rider,
                'overdue'  => round($regular['overdue'], 0),
            ],
            'payables'    => round($payables, 0),
            'assets'      => $assets,
            'net'         => round($net, 0),
            'health'      => $this->healthChecks($trackedSum + $unassigned, $allOnlinePool),
        ];
    }

    /** Fixed assets → drill: the active assets themselves (unit-scoped). */
    public function assetsList(string $unit = self::UNIT_ALL): array
    {
        $unit = $this->normalizeUnit($unit);
        try {
            $q = DB::table('t_fin_assets as a')
                ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
                ->where('a.status', 'active');
            if ($unit === self::UNIT_NF) {
                $q->where(function ($w) { $w->where('a.business_unit_id', 1)->orWhereNull('a.business_unit_id'); });
            } elseif ($unit === self::UNIT_KH) {
                $khaasId = $this->khaasBusinessUnitId();
                $q->where('a.business_unit_id', $khaasId ?? -1);
            }
            return $q
                ->orderByDesc('a.current_book_value')
                ->limit(300)
                ->get([
                    'a.asset_name', 'a.purchase_date', 'a.current_book_value',
                    DB::raw('COALESCE(bu.name, "Nizami Farms") as unit'),
                    'a.location',
                ])
                ->map(fn ($r) => [
                    'asset'    => $r->asset_name,
                    'unit'     => $r->unit,
                    'location' => $r->location ?: '—',
                    'bought'   => $r->purchase_date ? Carbon::parse($r->purchase_date)->format('M y') : '—',
                    'value'    => round((float) $r->current_book_value),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Health → drill: the delivered orders (last 30 days) that have no invoice
     * ledger entry. These are the ones the health tile flags — money delivered
     * that never got booked to the ledger. Read-only list so the owner (or
     * finance) can chase each one.
     */
    public function missingInvoices(): array
    {
        try {
            return $this->missingInvoiceBase()
                ->orderByDesc('d.delivered_at')->limit(300)
                ->get([
                    'o.order_number', 'o.total_price', 'o.total_paid', 'o.payment_method',
                    'd.delivered_at',
                    DB::raw('TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,""))) as name'),
                ])
                ->map(fn ($r) => [
                    'order_number' => $r->order_number,
                    'customer'     => trim($r->name) !== '' ? trim($r->name) : 'Walk-in',
                    'type'         => 'regular',
                    'delivered'    => $r->delivered_at ? Carbon::parse($r->delivered_at)->format('M d') : '—',
                    'amount'       => round((float) $r->total_price),
                    'paid'         => $this->paymentLabel($r->payment_method, (float) $r->total_paid, (float) $r->total_price),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Shared base for the missing-invoice count + list: delivered REGULAR
     * (non-shop, non-Shopify) orders in the last 30 days that have no invoice
     * ledger entry. Shop customers are excluded — they settle incrementally
     * and by design never post a full invoice.
     */
    private function missingInvoiceBase()
    {
        return DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub(Carbon::now()->subDays(30), Carbon::now()), 'd', 'd.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
            })
            ->where(function ($w) {
                $w->whereNull('c.customer_type')->orWhere('c.customer_type', '!=', 'shop');
            })
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))->from('t_fin_ledger as l')
                    ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                    ->whereColumn('l.order_id', 'o.id');
            });
    }

    /**
     * Regular-customer receivable = the Online Approvals "All Pending" number:
     * pending / pending_l1 / pending_l2 online ledger rows with no request,
     * excluding shop-customer orders. EXACT mirror of ApprovalController's
     * L1+L2 tabs so this card always reconciles with that screen.
     */
    private function regularPendingReceivable(): array
    {
        $base = DB::table('t_fin_ledger as l')
            ->whereIn('l.approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_PENDING_L2,
            ])
            ->whereNull('l.request_id')
            ->where('l.mode', LedgerModel::MODE_ONLINE)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_crm_prod_order as so')
                    ->join('t_crm_prod_customer as sc', 'sc.id', '=', 'so.customer_id')
                    ->whereColumn('so.id', 'l.order_id')
                    ->where('sc.customer_type', 'shop');
            });

        $row = (clone $base)->selectRaw('COUNT(*) n, COALESCE(SUM(l.amount),0) amt')->first();
        $overdue = (float) (clone $base)
            ->where('l.transaction_date', '<', Carbon::now()->subDays(30))
            ->sum('l.amount');

        return ['amount' => (float) $row->amt, 'count' => (int) $row->n, 'overdue' => $overdue];
    }

    /**
     * Shop receivable = the Online Approvals Shop tab total: delivered online
     * orders of shop customers with no booked (approved / pending_l2) invoice;
     * outstanding = total − paid. EXACT mirror of getOnlineShopItems.
     */
    private function shopOutstandingReceivable(): array
    {
        $row = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('c.customer_type', 'shop')
            ->whereIn('o.payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->where('o.order_status', 'delivered')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_fin_ledger as l')
                    ->whereColumn('l.order_id', 'o.id')
                    ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                    ->whereIn('l.approval_status', [
                        LedgerModel::STATUS_APPROVED,
                        LedgerModel::STATUS_PENDING_L2,
                    ]);
            })
            ->selectRaw('COUNT(*) n, COALESCE(SUM(GREATEST(o.total_price - COALESCE(o.total_paid,0), 0)),0) amt')
            ->first();
        return ['amount' => (float) $row->amt, 'count' => (int) $row->n];
    }

    // =====================================================================
    //  GROWTH  (Phase 2) — the "customer engine". All read-only, cached.
    //  Two layers: SALES QUALITY (AOV / new / repeat — unit-aware, from the
    //  closing block) and the CUSTOMER ENGINE (active 30/60/90, recency ladder,
    //  cohort retention — computed on the WHOLE regular customer base, because a
    //  customer is one relationship whether they buy fresh or frozen). Qurbani
    //  gets a simplified seasonal variant (rolling windows don't fit a season).
    // =====================================================================

    public function growth(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_growth_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->growthCompute($unit, $year, $month));
    }

    private function growthCompute(string $unit, int $year, int $month): array
    {
        $closing = $this->closing($unit, $year, $month); // cached; unit-aware
        $cur = $closing['current']; $prev = $closing['previous'];
        $aov     = $cur['orders'] > 0 ? round($cur['revenue'] / $cur['orders'], 0) : 0;
        $aovPrev = $prev['orders'] > 0 ? round($prev['revenue'] / $prev['orders'], 0) : 0;
        $repeat     = $cur['customers'] > 0 ? ($cur['customers'] - $cur['new_customers']) / $cur['customers'] : 0;
        $repeatPrev = $prev['customers'] > 0 ? ($prev['customers'] - $prev['new_customers']) / $prev['customers'] : 0;

        $isQb = $unit === self::UNIT_QB;
        return [
            'unit'         => $unit,
            'is_season'    => $isQb,
            'period_label' => $closing['period_label'],
            'sales' => [
                'aov'           => $aov,
                'aov_prev'      => $aovPrev,
                'new_customers' => $cur['new_customers'],
                'new_prev'      => $prev['new_customers'],
                'repeat'        => round($repeat * 100, 1),
                'repeat_prev'   => round($repeatPrev * 100, 1),
                'customers'     => $cur['customers'],
                'returning'     => max($cur['customers'] - $cur['new_customers'], 0),
            ],
            'engine' => $isQb ? $this->qurbaniGrowthEngine($year) : $this->regularGrowthEngine(),
            'cohort' => $isQb ? null : $this->cohortRetention(),
        ];
    }

    /**
     * Active-customer counts + the recency ladder for the whole regular customer
     * base, computed from one grouped "last delivered order per customer" query.
     * Cached for the day (the windows are relative to today).
     */
    private function regularGrowthEngine(): array
    {
        return Cache::remember('hq_growth_engine_' . Carbon::now()->format('Y-m-d'), 3600, function () {
            $ids = $this->qurbaniOrderIds();
            $rows = DB::table('t_crm_prod_order as o')
                ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->where(function ($w) {
                    $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
                })
                ->whereNotNull('o.customer_id')
                ->when(!empty($ids), fn ($q) => $q->whereNotIn('o.id', $ids))
                ->groupBy('o.customer_id')
                ->selectRaw('o.customer_id, MAX(d.delivered_at) last_del')
                ->get();

            $today = Carbon::now();
            $a30 = $a60 = $a90 = 0;
            // bands: 0-30, 31-60, 61-90, 91-180, 180+
            $bands = [0, 0, 0, 0, 0];
            foreach ($rows as $r) {
                if (!$r->last_del) continue;
                $days = Carbon::parse($r->last_del)->diffInDays($today);
                if ($days <= 30) { $a30++; $bands[0]++; }
                elseif ($days <= 60) { $bands[1]++; }
                elseif ($days <= 90) { $bands[2]++; }
                elseif ($days <= 180) { $bands[3]++; }
                else { $bands[4]++; }
                if ($days <= 60) $a60++;
                if ($days <= 90) $a90++;
            }
            $total = count($rows);
            $bandDefs = [
                ['key' => '0-30',   'label' => '0–30 days',    'count' => $bands[0], 'winback' => false],
                ['key' => '31-60',  'label' => '31–60 days',   'count' => $bands[1], 'winback' => false],
                ['key' => '61-90',  'label' => '61–90 days',   'count' => $bands[2], 'winback' => true],
                ['key' => '91-180', 'label' => '91–180 days',  'count' => $bands[3], 'winback' => false],
                ['key' => '180+',   'label' => '180+ · lapsed','count' => $bands[4], 'winback' => false],
            ];
            return [
                'active_30' => $a30, 'active_60' => $a60, 'active_90' => $a90,
                'total_customers' => $total,
                'bands' => $bandDefs,
            ];
        });
    }

    /** Qurbani seasonal growth: season buyers + returning-from-last-season. */
    private function qurbaniGrowthEngine(int $year): array
    {
        $prefix = 'QUR' . substr((string) $year, -2) . '%';
        $prevPrefix = 'QUR' . substr((string) ($year - 1), -2) . '%';

        $buyers = (int) DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->where('o.order_status', '<>', 'cancelled')
            ->distinct()->count('o.customer_id');
        // returning = this season's buyers who also bought last season
        $returning = (int) DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->where('o.order_status', '<>', 'cancelled')
            ->whereIn('o.customer_id', function ($q) use ($prevPrefix) {
                $q->select('customer_id')->from('t_crm_prod_order')
                    ->where('order_number', 'like', $prevPrefix)
                    ->where('order_status', '<>', 'cancelled');
            })
            ->distinct()->count('o.customer_id');

        return [
            'season'        => true,
            'buyers'        => $buyers,
            'returning'     => $returning,
            'new_to_season' => max($buyers - $returning, 0),
            'retention_pct' => $buyers > 0 ? round($returning / $buyers * 100, 1) : 0,
        ];
    }

    /**
     * Cohort retention — for the last 6 acquisition months, the % of each
     * cohort that placed another delivered order in month +1/+2/+3. Cells for
     * months that haven't finished yet are null (shown as "—"). Regular base.
     */
    private function cohortRetention(): array
    {
        return Cache::remember('hq_cohort_' . Carbon::now()->format('Y-m'), 3600, function () {
            $ids = $this->qurbaniOrderIds();
            $start = Carbon::now()->subMonths(5)->startOfMonth();
            $custs = DB::table('t_crm_prod_customer')
                ->whereNotNull('first_order_date')
                ->where('first_order_date', '>=', $start)
                ->get(['id', 'first_order_date']);
            if ($custs->isEmpty()) return ['months' => []];

            $custIds = $custs->pluck('id')->all();
            $orderMonths = DB::table('t_crm_prod_order as o')
                ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
                ->whereIn('o.customer_id', $custIds)
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->when(!empty($ids), fn ($q) => $q->whereNotIn('o.id', $ids))
                ->selectRaw("o.customer_id cid, DATE_FORMAT(d.delivered_at, '%Y-%m') ym")
                ->distinct()->get();
            $byCust = [];
            foreach ($orderMonths as $r) { $byCust[$r->cid][$r->ym] = true; }

            $nowMonth = Carbon::now()->format('Y-m'); // current month is incomplete
            $coh = [];
            foreach ($custs as $c) {
                $fm = Carbon::parse($c->first_order_date)->startOfMonth();
                $key = $fm->format('Y-m');
                $coh[$key]['size'] = ($coh[$key]['size'] ?? 0) + 1;
                for ($k = 1; $k <= 3; $k++) {
                    $mk = $fm->copy()->addMonths($k)->format('Y-m');
                    if (!empty($byCust[$c->id][$mk])) {
                        $coh[$key]['m' . $k] = ($coh[$key]['m' . $k] ?? 0) + 1;
                    }
                }
            }
            ksort($coh);
            $out = [];
            foreach ($coh as $key => $v) {
                $fm = Carbon::createFromFormat('Y-m', $key)->startOfMonth();
                $size = (int) $v['size'];
                $cells = [];
                for ($k = 1; $k <= 3; $k++) {
                    $mk = $fm->copy()->addMonths($k)->format('Y-m');
                    // A follow-on month that is the current (incomplete) month or
                    // later hasn't fully happened → show as null.
                    $cells[] = ($mk >= $nowMonth || $size === 0)
                        ? null
                        : round(($v['m' . $k] ?? 0) / $size * 100);
                }
                $out[] = [
                    'label' => $fm->format('M y'),
                    'size'  => $size,
                    'cells' => $cells,
                ];
            }
            // Only the last 6 acquisition months, and drop the current (still
            // filling) month's own cohort row if it's tiny/incomplete-only.
            return ['months' => array_slice($out, -6)];
        });
    }

    /** Recency band → drill: the customers in one band (win-back campaign list). */
    public function recencyList(string $band): array
    {
        $map = [
            '0-30'   => [0, 30],   '31-60' => [31, 60], '61-90' => [61, 90],
            '91-180' => [91, 180], '180+'  => [181, 100000],
        ];
        [$min, $max] = $map[$band] ?? [61, 90];
        $ids = $this->qurbaniOrderIds();

        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub(), 'd', 'd.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
            })
            ->whereNotNull('o.customer_id')
            ->when(!empty($ids), fn ($qq) => $qq->whereNotIn('o.id', $ids))
            ->groupBy('o.customer_id')
            ->havingRaw('DATEDIFF(NOW(), MAX(d.delivered_at)) BETWEEN ? AND ?', [$min, $max]);

        return $q->select(
            'o.customer_id',
            DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
            DB::raw('MAX(COALESCE(c.phone_original, c.phone)) as phone'),
            DB::raw('MAX(c.customer_type) as type'),
            DB::raw('MAX(d.delivered_at) as last_del'),
            DB::raw('COUNT(DISTINCT o.id) as orders'),
            DB::raw('SUM(o.total_price) as spent')
        )->orderByDesc('spent')->limit(400)->get()
            ->map(fn ($r) => [
                'customer'  => trim($r->name) !== '' ? trim($r->name) : 'Walk-in',
                'phone'     => $r->phone ?: '—',
                'type'      => $r->type ?: 'regular',
                'last'      => $r->last_del ? Carbon::parse($r->last_del)->format('M d, y') : '—',
                'days'      => $r->last_del ? (int) Carbon::parse($r->last_del)->diffInDays(Carbon::now()) : 0,
                'orders'    => (int) $r->orders,
                'spent'     => round((float) $r->spent),
            ])->all();
    }

    // =====================================================================
    //  DRILL-DOWNS  (Level 1 summary → Level 2 detail). All read-only.
    //  Each reuses the SAME definitions as the KPI cards, so totals reconcile.
    // =====================================================================

    /** Revenue → Level 1: per delivered-day summary for the unit + month. */
    public function revenueDaily(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_revdaily_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->revenueDailyCompute($unit, $year, $month));
    }

    private function revenueDailyCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();
        $dayExpr = DB::raw('DATE(d.delivered_at) as day');

        // Overall per-day (order-level) + Khaas per-day (line-level); derive NF.
        $kh = (int) ($khaasId ?: -1);
        $pieceCase = self::pieceCaseSql($kh);
        $overall = $this->deliveredBase($start, $end, $qmode)
            ->select($dayExpr, DB::raw('COUNT(DISTINCT o.id) as orders'), DB::raw('SUM(o.total_price) as revenue'))
            ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');
        $overallQty = $this->deliveredLines($start, $end, $qmode)
            ->select(DB::raw('DATE(d.delivered_at) as day'),
                     DB::raw("SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END) as kg"),
                     DB::raw("SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END) as pcs"))
            ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');

        $khRev = collect();
        if ($khaasId && $unit !== self::UNIT_QB) {
            $khRev = $this->deliveredLines($start, $end, $qmode)->where('p.business_unit_id', $khaasId)
                ->select(DB::raw('DATE(d.delivered_at) as day'), DB::raw('SUM(li.line_total) as rev'),
                         DB::raw("SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END) as kg"),
                         DB::raw("SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END) as pcs"),
                         DB::raw('COUNT(DISTINCT o.id) as orders'))
                ->groupBy(DB::raw('DATE(d.delivered_at)'))->get()->keyBy('day');
        }

        $rows = [];
        foreach ($overall as $day => $o) {
            $oRev = (float) $o->revenue; $oOrd = (int) $o->orders;
            $q = $overallQty[$day] ?? null;
            $oKg = (float) ($q->kg ?? 0); $oPcs = (float) ($q->pcs ?? 0);
            $k = $khRev[$day] ?? null;
            $kRev = (float) ($k->rev ?? 0); $kKg = (float) ($k->kg ?? 0);
            $kPcs = (float) ($k->pcs ?? 0); $kOrd = (int) ($k->orders ?? 0);
            if ($unit === self::UNIT_KH) {
                if ($kOrd === 0) continue;
                $rows[] = ['day' => $day, 'orders' => $kOrd, 'kg' => round($kKg), 'pcs' => round($kPcs), 'revenue' => round($kRev)];
            } elseif ($unit === self::UNIT_NF) {
                $rows[] = ['day' => $day, 'orders' => $oOrd, 'kg' => round(max($oKg - $kKg, 0)), 'pcs' => round(max($oPcs - $kPcs, 0)), 'revenue' => round($oRev - $kRev)];
            } else {
                $rows[] = ['day' => $day, 'orders' => $oOrd, 'kg' => round($oKg), 'pcs' => round($oPcs), 'revenue' => round($oRev)];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['day'], $b['day']));
        return $rows;
    }

    /** Revenue → Level 2: the orders delivered on one day for the unit. */
    public function revenueOrdersForDay(string $unit, string $date): array
    {
        $unit = $this->normalizeUnit($unit);
        $start = Carbon::parse($date)->startOfDay();
        $end   = Carbon::parse($date)->endOfDay();
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();

        $q = $this->deliveredBase($start, $end, $qmode)
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id');

        // For Khaas, limit to orders that carry a Khaas line.
        if ($unit === self::UNIT_KH && $khaasId) {
            $q->whereExists(function ($sub) use ($khaasId) {
                $sub->select(DB::raw(1))->from('t_crm_prod_order_line_item as li2')
                    ->join('t_crm_prod_product as p2', 'p2.id', '=', 'li2.product_id')
                    ->whereColumn('li2.order_id', 'o.id')
                    ->where('p2.business_unit_id', $khaasId);
            });
        }

        $rows = $q->select(
            'o.id', 'o.order_number', 'o.total_price', 'o.total_paid', 'o.payment_method',
            'o.customer_id', 'c.first_name', 'c.last_name', 'c.customer_type'
        )->orderBy('o.order_number')->limit(500)->get();

        // kg per order (unit-aware).
        $orderIds = $rows->pluck('id')->all();
        $kgByOrder = [];
        if (!empty($orderIds)) {
            $lq = DB::table('t_crm_prod_order_line_item as li')
                ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id')
                ->whereIn('li.order_id', $orderIds);
            if ($unit === self::UNIT_KH && $khaasId) {
                $lq->where('p.business_unit_id', $khaasId);
            } elseif ($unit === self::UNIT_NF && $khaasId) {
                $lq->where(function ($w) use ($khaasId) {
                    $w->where('p.business_unit_id', '!=', $khaasId)->orWhereNull('p.business_unit_id');
                });
            }
            $kgByOrder = $lq->select('li.order_id', DB::raw('SUM(li.quantity) as kg'))
                ->groupBy('li.order_id')->pluck('kg', 'li.order_id')->all();
        }

        return $rows->map(function ($r) use ($kgByOrder) {
            $name = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
            return [
                'order_number' => $r->order_number,
                'customer'     => $name !== '' ? $name : 'Walk-in',
                'type'         => $r->customer_type ?? 'regular',
                'kg'           => round((float) ($kgByOrder[$r->id] ?? 0), 1),
                'amount'       => round((float) $r->total_price),
                'paid'         => $this->paymentLabel($r->payment_method, (float) $r->total_paid, (float) $r->total_price),
            ];
        })->all();
    }

    /** Vendor purchases → Level 1: per-vendor for the unit + month. */
    public function vendorBreakdown(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        if ($unit === self::UNIT_QB) {
            return []; // Qurbani has no vendors.
        }
        return Cache::remember("hq_d_vendors_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->vendorBreakdownCompute($unit, $year, $month));
    }

    private function vendorBreakdownCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_fin_accounts as a', 'a.id', '=', 'l.to_account_id')
            ->leftJoin('t_fin_vendors as v', 'v.account_id', '=', 'a.id')
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end]);
        QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
        $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');

        $rows = $q->select(
            DB::raw('COALESCE(v.vendor_name, a.account_name, "Unknown") as vendor'),
            'a.id as account_id',
            DB::raw('COUNT(*) as bills'),
            DB::raw('SUM(l.amount) as purchases'),
            DB::raw('MAX(a.current_balance) as balance')
        )->groupBy('vendor', 'a.id')->orderByDesc('purchases')->get();

        return $rows->map(fn ($r) => [
            'vendor'     => $r->vendor,
            'account_id' => (int) $r->account_id,
            'bills'      => (int) $r->bills,
            'purchases'  => round((float) $r->purchases),
            'balance'    => round((float) $r->balance),
        ])->all();
    }

    /** Vendor purchases → Level 2: the bills for one vendor account + month. */
    public function vendorDetail(int $accountId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end   = $start->copy()->endOfMonth()->endOfDay();
        return DB::table('t_fin_ledger as l')
            ->where('l.transaction_type', LedgerModel::TYPE_VENDOR_PURCHASE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->where('l.to_account_id', $accountId)
            ->whereBetween('l.transaction_date', [$start, $end])
            ->orderBy('l.transaction_date')
            ->get(['l.transaction_date as date', 'l.description', 'l.amount'])
            ->map(fn ($r) => [
                'date'   => Carbon::parse($r->date)->format('M d'),
                'note'   => $r->description ?: '—',
                'amount' => round((float) $r->amount),
            ])->all();
    }

    /** Expenses → Level 1: per-category for the unit + month. */
    public function expenseByCategory(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_expcat_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->expenseByCategoryCompute($unit, $year, $month));
    }

    private function expenseByCategoryCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_req_master as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('t_req_category as rc', 'rc.id', '=', 'r.category_id')
            ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end]);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');
        }

        $rows = $q->select(
            DB::raw('COALESCE(rc.category_name, "Uncategorised") as category'),
            DB::raw('COUNT(*) as entries'),
            DB::raw('SUM(l.amount) as amount')
        )->groupBy('category')->orderByDesc('amount')->get();

        $total = $rows->sum('amount') ?: 1;
        return $rows->map(fn ($r) => [
            'category' => $r->category,
            'entries'  => (int) $r->entries,
            'amount'   => round((float) $r->amount),
            'pct'      => round((float) $r->amount / $total * 100, 1),
        ])->all();
    }

    /** Expenses → Level 2: entries in one category for the unit + month. */
    public function expenseDetail(string $unit, int $year, int $month, string $category): array
    {
        $unit = $this->normalizeUnit($unit);
        [$start, $end] = $this->period($unit, $year, $month);

        $q = DB::table('t_fin_ledger as l')
            ->leftJoin('t_req_master as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('t_req_category as rc', 'rc.id', '=', 'r.category_id')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'l.created_by')
            ->where('l.transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('l.approval_status', self::POSTED_STATUSES)
            ->whereBetween('l.transaction_date', [$start, $end])
            ->where(DB::raw('COALESCE(rc.category_name, "Uncategorised")'), $category);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 'l', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit, 'l.business_unit_id');
        }

        return $q->orderBy('l.transaction_date')->limit(500)
            ->get(['l.transaction_date as date', 'l.description', 'l.amount', 'u.fullname as who'])
            ->map(fn ($r) => [
                'date'   => Carbon::parse($r->date)->format('M d'),
                'who'    => $r->who ?: 'System',
                'note'   => $r->description ?: '—',
                'amount' => round((float) $r->amount),
            ])->all();
    }

    /** Customers → Level 1: customers who ordered in the unit + month. */
    public function customersList(string $unit, int $year, int $month): array
    {
        $unit = $this->normalizeUnit($unit);
        return Cache::remember("hq_d_cust_{$unit}_{$year}_{$month}", $this->ttlFor($unit, $year, $month),
            fn () => $this->customersListCompute($unit, $year, $month));
    }

    private function customersListCompute(string $unit, int $year, int $month): array
    {
        [$start, $end] = $this->period($unit, $year, $month);
        $qmode = $unit === self::UNIT_QB ? QurbaniFinanceFilter::MODE_INCLUDE : QurbaniFinanceFilter::MODE_EXCLUDE;
        $khaasId = $this->khaasBusinessUnitId();

        $q = $this->deliveredBase($start, $end, $qmode)
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id');
        if ($unit === self::UNIT_KH && $khaasId) {
            $q->whereExists(function ($sub) use ($khaasId) {
                $sub->select(DB::raw(1))->from('t_crm_prod_order_line_item as li2')
                    ->join('t_crm_prod_product as p2', 'p2.id', '=', 'li2.product_id')
                    ->whereColumn('li2.order_id', 'o.id')->where('p2.business_unit_id', $khaasId);
            });
        }

        return $q->select(
            'o.customer_id',
            DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
            DB::raw('MAX(c.customer_type) as type'),
            DB::raw('COUNT(DISTINCT o.id) as orders'),
            DB::raw('SUM(o.total_price) as spent'),
            DB::raw('MIN(c.first_order_date) as first_delivery')
        )->groupBy('o.customer_id')->orderByDesc('spent')->limit(500)->get()
            ->map(function ($r) use ($start, $end) {
                $isNew = $r->first_delivery && Carbon::parse($r->first_delivery)->between($start, $end);
                return [
                    'customer'  => $r->name ?: 'Walk-in',
                    'type'      => $r->type ?: 'regular',
                    'orders'    => (int) $r->orders,
                    'spent'     => round((float) $r->spent),
                    'first'     => $r->first_delivery ? Carbon::parse($r->first_delivery)->format('M y') : '—',
                    'is_new'    => (bool) $isNew,
                ];
            })->all();
    }

    /**
     * Working capital → receivables drill. Mirrors ONLINE APPROVALS: regular =
     * pending invoices grouped by customer; shop = the Shop tab's outstanding
     * grouped by customer; plus rider cash and current-season Qurbani balances.
     */
    public function receivablesAged(string $unit = self::UNIT_NF): array
    {
        $now = Carbon::now();

        // Qurbani view: this season's unpaid delivered orders, per customer.
        if ($this->normalizeUnit($unit) === self::UNIT_QB) {
            $prefix = 'QUR' . $now->format('y') . '%';
            $customers = DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.order_number', 'like', $prefix)
                ->whereIn('o.order_status', self::DELIVERED_STATUSES)
                ->whereColumn('o.total_paid', '<', 'o.total_price')
                ->select(
                    DB::raw('COALESCE(o.customer_id, 0) as cid'),
                    DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                    DB::raw('COUNT(*) as invoices'),
                    DB::raw('SUM(o.total_price - COALESCE(o.total_paid,0)) as outstanding'),
                    DB::raw('MIN(o.order_date) as oldest')
                )
                ->groupBy(DB::raw('COALESCE(o.customer_id, 0)'))
                ->orderByDesc('outstanding')->limit(200)->get()
                ->map(fn ($r) => $this->recvRow($r->name, 'qurbani', (float) $r->outstanding, $r->oldest, $now, (int) $r->invoices))
                ->all();
            return ['customers' => $customers, 'rider' => 0];
        }

        // Regular: pending online invoices grouped by customer.
        $regular = DB::table('t_fin_ledger as l')
            ->leftJoin('t_crm_prod_order as o', 'o.id', '=', 'l.order_id')
            ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereIn('l.approval_status', [
                LedgerModel::STATUS_PENDING,
                LedgerModel::STATUS_PENDING_L1,
                LedgerModel::STATUS_PENDING_L2,
            ])
            ->whereNull('l.request_id')
            ->where('l.mode', LedgerModel::MODE_ONLINE)
            ->where(function ($w) {
                $w->whereNull('c.customer_type')->orWhere('c.customer_type', '!=', 'shop');
            })
            ->select(
                DB::raw('COALESCE(o.customer_id, 0) as cid'),
                DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                DB::raw('COUNT(*) as invoices'),
                DB::raw('SUM(l.amount) as outstanding'),
                DB::raw('MIN(l.transaction_date) as oldest')
            )
            ->groupBy(DB::raw('COALESCE(o.customer_id, 0)'))
            ->orderByDesc('outstanding')->limit(200)->get()
            ->map(fn ($r) => $this->recvRow($r->name, 'regular', (float) $r->outstanding, $r->oldest, $now, (int) $r->invoices))
            ->all();

        // Shop: outstanding delivered online orders grouped by customer.
        $shop = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('c.customer_type', 'shop')
            ->whereIn('o.payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->where('o.order_status', 'delivered')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('t_fin_ledger as l')
                    ->whereColumn('l.order_id', 'o.id')
                    ->where('l.transaction_type', LedgerModel::TYPE_INVOICE)
                    ->whereIn('l.approval_status', [
                        LedgerModel::STATUS_APPROVED,
                        LedgerModel::STATUS_PENDING_L2,
                    ]);
            })
            ->select(
                'o.customer_id as cid',
                DB::raw('MAX(TRIM(CONCAT(COALESCE(c.first_name,""), " ", COALESCE(c.last_name,"")))) as name'),
                DB::raw('COUNT(*) as invoices'),
                DB::raw('SUM(GREATEST(o.total_price - COALESCE(o.total_paid,0), 0)) as outstanding'),
                DB::raw('MIN(o.order_date) as oldest')
            )
            ->groupBy('o.customer_id')
            ->havingRaw('outstanding > 0')
            ->orderByDesc('outstanding')->limit(200)->get()
            ->map(fn ($r) => $this->recvRow($r->name, 'shop', (float) $r->outstanding, $r->oldest, $now, (int) $r->invoices))
            ->all();

        $customers = array_merge($shop, $regular);
        usort($customers, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        $rider = round(AccountModel::getTotalEmployeeCashCalculatedBalance(), 0);

        return ['customers' => $customers, 'rider' => $rider];
    }

    private function recvRow(?string $name, string $type, float $amount, $oldest, Carbon $now, int $invoices): array
    {
        return [
            'who'      => ($name !== null && trim($name) !== '') ? trim($name) : 'Customer',
            'type'     => $type,
            'invoices' => $invoices,
            'amount'   => round($amount),
            'oldest'   => $oldest ? Carbon::parse($oldest)->format('M d') : '—',
            'age'      => $oldest ? (int) Carbon::parse($oldest)->diffInDays($now) : 0,
        ];
    }

    /** Working capital → payables by vendor (who we owe). */
    public function payablesByVendor(): array
    {
        return DB::table('t_fin_accounts as a')
            ->leftJoin('t_fin_vendors as v', 'v.account_id', '=', 'a.id')
            ->leftJoin('t_fin_business_units as bu', 'bu.id', '=', 'a.business_unit_id')
            ->where('a.account_category', AccountModel::CATEGORY_VENDOR_PAYABLE)
            ->where('a.is_active', 1)
            ->where('a.current_balance', '>', 0)
            ->orderByDesc('a.current_balance')->limit(300)
            ->get([
                DB::raw('COALESCE(v.vendor_name, a.account_name) as vendor'),
                DB::raw('COALESCE(bu.name, "Nizami Farms") as unit'),
                'a.current_balance as balance',
            ])
            ->map(fn ($r) => [
                'vendor'  => $r->vendor,
                'unit'    => $r->unit,
                'balance' => round((float) $r->balance),
            ])->all();
    }

    private function paymentLabel(?string $method, float $paid, float $total): string
    {
        if ($paid >= $total && $total > 0) {
            return in_array($method, ['cash', 'cash_on_delivery'], true) ? 'cash' : 'online';
        }
        if ($paid > 0) return 'partial';
        return in_array($method, ['cash', 'cash_on_delivery'], true) ? 'cash (due)' : 'unpaid';
    }

    // =====================================================================
    //  CORE CLOSING COMPUTATION (one unit, one window)
    // =====================================================================

    private function computeClosing(string $unit, Carbon $start, Carbon $end): array
    {
        if ($unit === self::UNIT_QB) {
            return $this->qurbaniClosing($start, $end);
        }

        // All the delivered-order metrics for the window in ONE cached bundle
        // (2 aggregate queries), shared by every non-Qurbani unit + the trend.
        $r = $this->rawWindow($start, $end);

        switch ($unit) {
            case self::UNIT_KH:
                $revenue = $r['rev_kh']; $orders = $r['ord_kh']; $kg = $r['kg_kh'];
                $pcs = $r['pcs_kh'];
                $customers = $r['cust_kh']; $new = $r['new_kh'];
                $vendor = $r['vendor_kh']; $expense = $r['expense_kh'];
                break;
            case self::UNIT_NF:
                // NF = regular business minus the Khaas slice. Delivery charges &
                // order-level discounts live in the order total, so they land in
                // NF here (owner rule).
                $revenue = $r['rev_all'] - $r['rev_kh']; $orders = $r['ord_nf'];
                $kg = max($r['kg_all'] - $r['kg_kh'], 0);
                $pcs = max($r['pcs_all'] - $r['pcs_kh'], 0);
                $customers = $r['cust_nf']; $new = $r['new_nf'];
                $vendor = $r['vendor_nf']; $expense = $r['expense_nf'];
                break;
            default: // UNIT_ALL
                $revenue = $r['rev_all']; $orders = $r['ord_all']; $kg = $r['kg_all'];
                $pcs = $r['pcs_all'];
                $customers = $r['cust_all']; $new = $r['new_all'];
                $vendor = $r['vendor_all']; $expense = $r['expense_all'];
                break;
        }

        $grossProfit = $revenue - $vendor;
        $netProfit   = $grossProfit - $expense;

        return [
            'revenue'          => round($revenue, 0),
            'orders'           => (int) $orders,
            'kg'               => round($kg, 0),
            'pieces'           => round($pcs, 0),
            'customers'        => (int) $customers,
            'new_customers'    => (int) $new,
            'vendor_purchases' => round($vendor, 0),
            'gross_profit'     => round($grossProfit, 0),
            'expenses'         => round($expense, 0),
            'net_profit'       => round($netProfit, 0),
        ];
    }

    /**
     * SQL CASE condition: TRUE when a line item is sold per PIECE rather than
     * by weight — Khaas packs, or the item name says per piece / pcs / pieces /
     * dozen. Everything else's quantity is genuine kg. (Name-based heuristic —
     * a proper unit flag on products would be cleaner; owner informed.)
     */
    private static function pieceCaseSql(int $khaasId): string
    {
        return "(p.business_unit_id = $khaasId"
            . " OR LOWER(li.name) LIKE '%per piece%'"
            . " OR LOWER(li.name) LIKE '%pcs%'"
            . " OR LOWER(li.name) LIKE '%pieces%'"
            . " OR LOWER(li.name) LIKE '%dozen%')";
    }

    /**
     * All delivered-order + ledger metrics for a NON-Qurbani window, computed in
     * a small fixed number of queries and cached by window. Returns overall +
     * Khaas + NF slices so every unit derives from one computation.
     */
    private function rawWindow(Carbon $start, Carbon $end): array
    {
        $key = 'hq_raw_' . $start->getTimestamp() . '_' . $end->getTimestamp();
        // Closed windows (fully in the past) cache for 24h; the open one 5 min.
        $ttl = $end->lt(Carbon::now()->startOfDay()) ? 86400 : 300;
        return Cache::remember($key, $ttl, function () use ($start, $end) {
            $qmode = QurbaniFinanceFilter::MODE_EXCLUDE;
            $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
            $s = $start->toDateTimeString();
            $e = $end->toDateTimeString();

            // Q1 — order-level totals.
            $o = $this->deliveredBase($start, $end, $qmode)
                ->selectRaw('COALESCE(SUM(o.total_price),0) rev, COUNT(DISTINCT o.id) ord, COUNT(DISTINCT o.customer_id) cust')
                ->first();

            // Q2 — line-level split (Khaas vs NF) + new-customer flags. $kh is an
            // int and $s/$e are self-formatted datetimes, so direct interpolation
            // is injection-safe and avoids binding-order issues with the filter.
            // Weight vs pieces: a line counts as PIECES when its product is a
            // Khaas pack or its name says per piece / pcs / pieces / dozen;
            // otherwise quantity is genuine kg (verified against June data).
            $pieceCase = self::pieceCaseSql($kh);
            $l = $this->deliveredLines($start, $end, $qmode)
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->selectRaw(
                    "COALESCE(SUM(CASE WHEN $pieceCase THEN 0 ELSE li.quantity END),0) kg_all,
                     COALESCE(SUM(CASE WHEN $pieceCase THEN li.quantity ELSE 0 END),0) pcs_all,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh AND NOT ($pieceCase) THEN li.quantity ELSE 0 END),0) kg_kh,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh AND $pieceCase THEN li.quantity ELSE 0 END),0) pcs_kh,
                     COALESCE(SUM(CASE WHEN p.business_unit_id = $kh THEN li.line_total ELSE 0 END),0) rev_kh,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh THEN o.id END) ord_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) THEN o.id END) ord_nf,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh THEN o.customer_id END) cust_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) THEN o.customer_id END) cust_nf,
                     COUNT(DISTINCT CASE WHEN c.first_order_date BETWEEN '$s' AND '$e' THEN o.customer_id END) new_all,
                     COUNT(DISTINCT CASE WHEN p.business_unit_id = $kh AND c.first_order_date BETWEEN '$s' AND '$e' THEN o.customer_id END) new_kh,
                     COUNT(DISTINCT CASE WHEN (p.business_unit_id <> $kh OR p.business_unit_id IS NULL) AND c.first_order_date BETWEEN '$s' AND '$e' THEN o.customer_id END) new_nf"
                )->first();

            // Vendor purchases + expenses, grouped by BU, Qurbani excluded.
            [$vendorAll, $vendorKh] = $this->ledgerByUnit(LedgerModel::TYPE_VENDOR_PURCHASE, $start, $end, $kh);
            [$expenseAll, $expenseKh] = $this->ledgerByUnit(LedgerModel::TYPE_EXPENSE, $start, $end, $kh);

            return [
                'rev_all'   => (float) $o->rev,
                'ord_all'   => (int) $o->ord,
                'cust_all'  => (int) $o->cust,
                'kg_all'    => (float) $l->kg_all,
                'pcs_all'   => (float) $l->pcs_all,
                'kg_kh'     => (float) $l->kg_kh,
                'pcs_kh'    => (float) $l->pcs_kh,
                'rev_kh'    => (float) $l->rev_kh,
                'ord_kh'    => (int) $l->ord_kh,
                'ord_nf'    => (int) $l->ord_nf,
                'cust_kh'   => (int) $l->cust_kh,
                'cust_nf'   => (int) $l->cust_nf,
                'new_all'   => (int) $l->new_all,
                'new_kh'    => (int) $l->new_kh,
                'new_nf'    => (int) $l->new_nf,
                'vendor_all' => $vendorAll,
                'vendor_kh'  => $vendorKh,
                'vendor_nf'  => $vendorAll - $vendorKh,
                'expense_all' => $expenseAll,
                'expense_kh'  => $expenseKh,
                'expense_nf'  => $expenseAll - $expenseKh,
            ];
        });
    }

    /** Sum a ledger transaction type over a window, grouped into [all, khaas]. Qurbani excluded. */
    private function ledgerByUnit(string $type, Carbon $start, Carbon $end, int $khaasId): array
    {
        $q = LedgerModel::query()
            ->where('transaction_type', $type)
            ->whereIn('approval_status', self::POSTED_STATUSES)
            ->whereBetween('transaction_date', [$start, $end]);
        QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);

        $rows = $q->groupBy('business_unit_id')
            ->selectRaw('business_unit_id as bu, SUM(amount) as amt')->get();

        $all = 0.0; $kh = 0.0;
        foreach ($rows as $r) {
            $all += (float) $r->amt;
            if ((int) $r->bu === $khaasId) $kh = (float) $r->amt;
        }
        return [$all, $kh];
    }

    /**
     * Lightweight revenue + net-profit for the trend line. Avoids the heavy
     * line-level distinct-count query; only revenue (+ Khaas slice when needed)
     * and the two ledger sums.
     */
    private function trendPoint(string $unit, Carbon $start, Carbon $end): array
    {
        if ($unit === self::UNIT_QB) {
            $c = $this->qurbaniClosing($start, $end);
            return ['revenue' => $c['revenue'], 'net_profit' => $c['net_profit']];
        }
        $kh = (int) ($this->khaasBusinessUnitId() ?: -1);
        $revAll = (float) $this->deliveredBase($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE)->sum('o.total_price');
        [$vendAll, $vendKh] = $this->ledgerByUnit(LedgerModel::TYPE_VENDOR_PURCHASE, $start, $end, $kh);
        [$expAll, $expKh]   = $this->ledgerByUnit(LedgerModel::TYPE_EXPENSE, $start, $end, $kh);

        if ($unit === self::UNIT_ALL) {
            $rev = $revAll; $vend = $vendAll; $exp = $expAll;
        } else {
            $revKh = (float) $this->deliveredLines($start, $end, QurbaniFinanceFilter::MODE_EXCLUDE)
                ->where('p.business_unit_id', $kh)->sum('li.line_total');
            if ($unit === self::UNIT_KH) {
                $rev = $revKh; $vend = $vendKh; $exp = $expKh;
            } else { // NF
                $rev = $revAll - $revKh; $vend = $vendAll - $vendKh; $exp = $expAll - $expKh;
            }
        }
        return ['revenue' => round($rev, 0), 'net_profit' => round($rev - $vend - $exp, 0)];
    }

    /**
     * Qurbani closing — seasonal + PRE-BOOKED. Unlike the daily business,
     * Qurbani orders are sold weeks ahead and delivered on the Eid days, with
     * animal costs bought upfront. So the season is measured on a BOOKED basis
     * (all non-cancelled QUR{yy} orders) matched against the season's costs —
     * that is the true season P&L. "Delivered so far" is carried as secondary
     * fulfilment context. (Measuring delivered revenue against all-season
     * expenses understated profit; this fixes both that and the qty.)
     * Season identity = the QUR{yy} order-number prefix (yy from the window).
     */
    private function qurbaniClosing(Carbon $start, Carbon $end): array
    {
        $prefix = 'QUR' . $start->format('y') . '%';
        $s = $start->toDateTimeString();
        $e = $end->toDateTimeString();

        // BOOKED (non-cancelled) — the season's business.
        $booked = DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->where('o.order_status', '<>', 'cancelled')
            ->selectRaw('COALESCE(SUM(o.total_price),0) rev, COUNT(DISTINCT o.id) ord, COUNT(DISTINCT o.customer_id) cust')
            ->first();
        $bookedQty = (float) DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->where('o.order_number', 'like', $prefix)
            ->where('o.order_status', '<>', 'cancelled')
            ->sum('li.quantity');
        $newC = (int) DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->where('o.order_number', 'like', $prefix)
            ->where('o.order_status', '<>', 'cancelled')
            ->whereRaw("c.first_order_date BETWEEN ? AND ?", [$s, $e])
            ->distinct()->count('o.customer_id');

        // DELIVERED so far — fulfilment context.
        $deliv = DB::table('t_crm_prod_order as o')
            ->where('o.order_number', 'like', $prefix)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->selectRaw('COALESCE(SUM(o.total_price),0) rev, COUNT(DISTINCT o.id) ord')
            ->first();
        $delivQty = (float) DB::table('t_crm_prod_order_line_item as li')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'li.order_id')
            ->where('o.order_number', 'like', $prefix)
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->sum('li.quantity');

        $expense = $this->expenses(self::UNIT_QB, $start, $end);
        $revenue = (float) $booked->rev;
        return [
            'revenue'          => round($revenue, 0),
            'orders'           => (int) $booked->ord,
            'kg'               => 0,
            'pieces'           => round($bookedQty, 0),
            'customers'        => (int) $booked->cust,
            'new_customers'    => $newC,
            'vendor_purchases' => 0,
            'gross_profit'     => round($revenue, 0),
            'expenses'         => round($expense, 0),
            'net_profit'       => round($revenue - $expense, 0),
            // secondary — delivered so far this season
            'delivered_revenue' => round((float) $deliv->rev, 0),
            'delivered_orders'  => (int) $deliv->ord,
            'delivered_pieces'  => round($delivQty, 0),
        ];
    }

    // =====================================================================
    //  QUERY HELPERS  (each returns a FRESH builder — no shared state)
    // =====================================================================

    /**
     * Delivered, non-Shopify orders whose delivery timestamp falls in the
     * window. Delivery time = MAX(changed_at) of the 'delivered' status-history
     * rows (mirrors OrderModel::getDeliveryDateAttribute). Qurbani included or
     * excluded per $qmode.
     */
    private function deliveredBase(Carbon $start, Carbon $end, string $qmode)
    {
        // Bounding the delivered-date subquery to the window keeps the grouped
        // scan to that window's rows only — the key to fast per-window queries.
        $q = DB::table('t_crm_prod_order as o')
            ->joinSub($this->deliveredDatesSub($start, $end), 'd', 'd.order_id', '=', 'o.id')
            ->whereIn('o.order_status', self::DELIVERED_STATUSES)
            ->where(function ($w) {
                $w->where('o.external_source', '!=', 'shopify')
                  ->orWhereNull('o.external_source');
            });
        $this->applyQurbani($q, $qmode);
        return $q;
    }

    /**
     * Include / exclude Qurbani orders via a cached id set instead of a
     * correlated EXISTS. The canonical QurbaniFinanceFilter runs ONCE (cached);
     * every window then filters with a fast whereIn/whereNotIn. This is what
     * makes the seasonal (year-wide) Qurbani view usable.
     */
    private function applyQurbani($q, string $qmode, string $col = 'o.id'): void
    {
        $ids = $this->qurbaniOrderIds();
        if ($qmode === QurbaniFinanceFilter::MODE_INCLUDE) {
            empty($ids) ? $q->whereRaw('1=0') : $q->whereIn($col, $ids);
        } else {
            if (!empty($ids)) $q->whereNotIn($col, $ids);
        }
    }

    /** All Qurbani order ids (canonical definition), resolved once and cached. */
    private function qurbaniOrderIds(): array
    {
        return Cache::remember('hq_qurbani_order_ids', 300, function () {
            $q = DB::table('t_crm_prod_order as o');
            QurbaniFinanceFilter::applyToOrderQuery($q, 'o', QurbaniFinanceFilter::MODE_INCLUDE);
            return $q->pluck('o.id')->map(fn ($v) => (int) $v)->all();
        });
    }

    /** deliveredBase joined to its line items + products (for kg / unit split). */
    private function deliveredLines(Carbon $start, Carbon $end, string $qmode)
    {
        return $this->deliveredBase($start, $end, $qmode)
            ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
            ->leftJoin('t_crm_prod_product as p', 'p.id', '=', 'li.product_id');
    }

    /**
     * Per-order delivered timestamp (latest 'delivered' status change). When a
     * window is given, the scan is bounded to delivered events in that window —
     * far cheaper than grouping the whole history table.
     */
    private function deliveredDatesSub(?Carbon $start = null, ?Carbon $end = null)
    {
        $q = DB::table('t_crm_order_status_history')
            ->select('order_id', DB::raw('MAX(changed_at) as delivered_at'))
            ->where('status_code', 'delivered');
        if ($start && $end) {
            $q->whereBetween('changed_at', [$start, $end]);
        }
        return $q->groupBy('order_id');
    }

    /**
     * Expenses for the unit + window. For Qurbani we INCLUDE only qurbani-category
     * rows; for NF/Khaas we EXCLUDE them so qurbani costs never double-count as NF.
     * (Used by the Qurbani closing + reused conceptually; NF/Khaas closings get
     * their expense totals from rawWindow's grouped query instead.)
     */
    private function expenses(string $unit, Carbon $start, Carbon $end): float
    {
        $q = LedgerModel::where('transaction_type', LedgerModel::TYPE_EXPENSE)
            ->whereIn('approval_status', self::POSTED_STATUSES)
            ->whereBetween('transaction_date', [$start, $end]);

        if ($unit === self::UNIT_QB) {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_INCLUDE);
        } else {
            QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_EXCLUDE);
            $this->scopeLedgerToUnit($q, $unit);
        }
        return (float) $q->sum('amount');
    }

    /**
     * Adds a business_unit_id filter for NF/Khaas; 'all' = NF+Khaas (no filter).
     * $col MUST be table-qualified when the query joins other tables that also
     * carry business_unit_id (t_req_master, t_fin_accounts, t_fin_vendors) —
     * an unqualified column there is ambiguous and 500s the drill.
     */
    private function scopeLedgerToUnit($q, string $unit, string $col = 't_fin_ledger.business_unit_id'): void
    {
        if ($unit === self::UNIT_NF) {
            $q->where($col, 1);
        } elseif ($unit === self::UNIT_KH) {
            $khaasId = $this->khaasBusinessUnitId();
            $q->where($col, $khaasId ?? -1);
        }
        // UNIT_ALL: no BU filter (qurbani already excluded upstream).
    }

    // =====================================================================
    //  WORKING CAPITAL SUB-QUERIES
    // =====================================================================


    /**
     * Closing-health signals for the working-capital strip. Each is defensive
     * (try/catch → skip) so a missing table never breaks the dashboard.
     *
     * @param float $segregated tracked per-bank balances + unassigned bucket
     * @param float $onlinePool sum of the ONLINE-pool chart accounts
     */
    private function healthChecks(float $segregated, float $onlinePool): array
    {
        $out = [];

        // 1) Delivered REGULAR orders (last 30d) with no invoice ledger entry.
        //    Shop customers are excluded — they settle incrementally and by
        //    design never get a full invoice posting, so they are not anomalies.
        try {
            $missing = $this->missingInvoiceBase()->count();
            $out[] = [
                'ok'    => $missing === 0,
                'label' => $missing === 0
                    ? 'Every delivered order (30d) has an invoice entry'
                    : "{$missing} delivered orders (30d) missing an invoice entry",
                // drill key → the frontend makes this row clickable to the list.
                'drill' => $missing > 0 ? 'missing_invoices' : null,
            ];
        } catch (\Throwable $e) { /* skip */ }

        // 2) Per-bank segregation (tracked + unassigned) vs the ONLINE pool.
        //    Both numbers come from the Bank Balances page's own definitions;
        //    a gap means opening balances / true-ups need a look.
        try {
            $diff = abs($onlinePool - $segregated);
            $out[] = [
                'ok'    => $diff < 1000,
                'label' => $diff < 1000
                    ? 'Per-bank segregation reconciles with the ONLINE pool'
                    : 'Per-bank segregation is Rs ' . number_format($diff) . ' off the ONLINE pool (check openings / true-ups on Bank Balances)',
            ];
        } catch (\Throwable $e) { /* skip */ }

        // 3) Payment proofs unmatched > 7 days.
        try {
            $stale = DB::table('t_fin_payment_signal')
                ->whereIn('status', ['new', 'unmatched'])
                ->where('created_at', '<', Carbon::now()->subDays(7))
                ->count();
            $out[] = [
                'ok'    => $stale === 0,
                'label' => $stale === 0
                    ? 'No unmatched payment proofs older than 7 days'
                    : "{$stale} payment proofs unmatched for over 7 days",
            ];
        } catch (\Throwable $e) { /* skip */ }

        return $out;
    }

    // =====================================================================
    //  PERIOD / UTILITY
    // =====================================================================

    /**
     * Resolve the [start, end, label, isOpen] for a unit + year/month.
     * Qurbani = whole calendar year (season-to-date if the current year).
     */
    private function period(string $unit, int $year, int $month): array
    {
        $now = Carbon::now();
        if ($unit === self::UNIT_QB) {
            $start = Carbon::create($year, 1, 1)->startOfDay();
            $isOpen = $year === $now->year;
            $end = $isOpen ? $now->copy() : Carbon::create($year, 12, 31)->endOfDay();
            return [$start, $end, "Qurbani {$year} season", $isOpen];
        }
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $start->copy()->endOfMonth();
        $isOpen = ($year === $now->year && $month === $now->month);
        $end = $isOpen && $now->lt($monthEnd) ? $now->copy() : $monthEnd->endOfDay();
        return [$start, $end, $start->format('F Y'), $isOpen];
    }

    private function previousPeriod(string $unit, int $year, int $month): array
    {
        if ($unit === self::UNIT_QB) {
            $s = Carbon::create($year - 1, 1, 1)->startOfDay();
            $e = Carbon::create($year - 1, 12, 31)->endOfDay();
            return [$s, $e];
        }
        $ref = Carbon::create($year, $month, 1)->subMonth();
        return [$ref->copy()->startOfMonth(), $ref->copy()->endOfMonth()->endOfDay()];
    }

    /** Calendar days in the window minus active public holidays. */
    private function workingDays(Carbon $start, Carbon $end): int
    {
        $days = $start->diffInDays($end) + 1;
        try {
            $holidays = DB::table('t_ops_public_holidays')
                ->where('is_active', 1)
                ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
                ->count();
            $days -= $holidays;
        } catch (\Throwable $e) { /* table optional */ }
        return max($days, 1);
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return in_array($unit, [self::UNIT_ALL, self::UNIT_NF, self::UNIT_KH, self::UNIT_QB], true)
            ? $unit : self::UNIT_ALL;
    }

    /** Khaas business-unit id, resolved case-insensitively and cached. */
    private function khaasBusinessUnitId(): ?int
    {
        if ($this->khaasIdCache !== null) {
            return $this->khaasIdCache ?: null;
        }
        try {
            $id = DB::table('t_fin_business_units')
                ->whereRaw("LOWER(code) = 'khaas'")
                ->orWhereRaw("LOWER(name) = 'khaas'")
                ->value('id');
            $this->khaasIdCache = (int) ($id ?: 0);
        } catch (\Throwable $e) {
            $this->khaasIdCache = 0;
        }
        return $this->khaasIdCache ?: null;
    }
}
