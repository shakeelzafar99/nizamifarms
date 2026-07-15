<?php

namespace App\Http\Controllers\FIN\Hub;

use App\Http\Controllers\Controller;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Services\FIN\LedgerKpiService;
use App\Services\QurbaniFinanceFilter;
use Illuminate\Http\Request;

/**
 * Ledger Hub — a modern, unified read/write surface over the existing finance data.
 *
 * PARALLEL RUN: every existing finance page (fin.ledger.*, fin.employee.*, fin.vendors.*,
 * fin.bank-balances, ...) keeps working untouched. The Hub is additive — new routes under
 * /finance/hub, new views under resources/views/fin/hub. It READS via the existing models/services
 * and WRITES via the existing endpoints (approve/reject/transfer/etc.), so a change made here shows
 * up on the old pages instantly and vice-versa. Nothing here modifies old-view behavior.
 *
 * Business-unit scope (?scope=ops|nf|khaas|qurbani): NF (BU 1) and Khaas (BU 2) are real business
 * units and accounts are tagged per-BU, so the transaction list is filtered by the accounts a row
 * touches. Qurbani is NOT a BU — it is filtered via QurbaniFinanceFilter. "ops" = both units,
 * no BU filter (parity with the old Overall Ledger list).
 */
class HubController extends Controller
{
    private const SCOPES = ['ops', 'nf', 'khaas', 'qurbani'];

    /** Tab 1 — Overview: every money movement, one table, one set of rules. */
    public function overview(Request $request)
    {
        $startDate = $request->start_date ?: now()->startOfMonth()->format('Y-m-d');
        $endDate   = $request->end_date ?: now()->endOfMonth()->format('Y-m-d');

        // --- Business-unit scope ---------------------------------------------------------------
        $accessibleBUs = AccountModel::getUserAccessibleBusinessUnits();
        $canSeeKhaas   = $accessibleBUs->contains(fn ($bu) => (int) $bu->id === 2);
        $canSeeMulti   = $accessibleBUs->count() >= 2;

        $scope = in_array($request->get('scope'), self::SCOPES, true) ? $request->get('scope') : 'ops';
        // Guard: a single-NF user can only ever be in NF scope.
        if (!$canSeeMulti && in_array($scope, ['ops', 'khaas', 'qurbani'], true)) {
            $scope = $canSeeKhaas ? $scope : 'nf';
        }

        // --- Base query (mirrors LedgerController::index filters) ------------------------------
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer']);

        $user = auth()->user();
        $isTaimur = $user && $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
        if (!$isTaimur) {
            $privateIds = AccountModel::where('is_private', 1)->pluck('id')->toArray();
            if (!empty($privateIds)) {
                $query->whereNotIn('from_account_id', $privateIds)
                      ->whereNotIn('to_account_id', $privateIds);
            }
        }

        // Date range — vendor payments key off posted_date, everything else off transaction_date.
        if ($startDate) {
            $query->where(function ($q) use ($startDate) {
                $q->where(function ($s) use ($startDate) {
                    $s->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                      ->where(function ($d) use ($startDate) {
                          $d->where('posted_date', '>=', $startDate)
                            ->orWhere(function ($f) use ($startDate) {
                                $f->whereNull('posted_date')->where('transaction_date', '>=', $startDate);
                            });
                      });
                })->orWhere(function ($s) use ($startDate) {
                    $s->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                      ->where('transaction_date', '>=', $startDate);
                });
            });
        }
        if ($endDate) {
            $query->where(function ($q) use ($endDate) {
                $q->where(function ($s) use ($endDate) {
                    $s->where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
                      ->where(function ($d) use ($endDate) {
                          $d->where('posted_date', '<=', $endDate)
                            ->orWhere(function ($f) use ($endDate) {
                                $f->whereNull('posted_date')->where('transaction_date', '<=', $endDate);
                            });
                      });
                })->orWhere(function ($s) use ($endDate) {
                    $s->where('transaction_type', '!=', LedgerModel::TYPE_VENDOR_PAYMENT)
                      ->where('transaction_date', '<=', $endDate);
                });
            });
        }

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->type);
        }
        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }
        if ($request->filled('status')) {
            $query->where('approval_status', $request->status);
        }
        if ($request->filled('account_id')) {
            $accountId = $request->account_id;
            $query->where(function ($q) use ($accountId) {
                $q->where('from_account_id', $accountId)->orWhere('to_account_id', $accountId);
            });
        }
        if ($request->filled('search')) {
            $query->where('description', 'LIKE', "%{$request->search}%");
        }

        // --- Apply scope -----------------------------------------------------------------------
        if ($scope === 'nf' || $scope === 'khaas') {
            $buId = $scope === 'nf' ? 1 : 2;
            $buAccountIds = AccountModel::where('business_unit_id', $buId)->pluck('id')->toArray();
            $query->where(function ($q) use ($buAccountIds) {
                $q->whereIn('from_account_id', $buAccountIds)->orWhereIn('to_account_id', $buAccountIds);
            });
        } elseif ($scope === 'qurbani') {
            $query->tap(function ($q) {
                QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', QurbaniFinanceFilter::MODE_INCLUDE);
            });
        }

        $ledger = $query->orderBy('transaction_date', 'desc')
                        ->orderBy('created_at', 'desc')
                        ->paginate(50)
                        ->appends($request->query());

        // --- Sidebar data ----------------------------------------------------------------------
        $accounts = AccountModel::where('is_active', 1)
            ->visibleTo($user)
            ->orderBy('account_name', 'asc')
            ->get();

        $transactionTypes = [
            LedgerModel::TYPE_INVOICE => 'Invoice',
            LedgerModel::TYPE_ORDER_PAYMENT => 'Order Payment',
            LedgerModel::TYPE_EMPLOYEE_DEPOSIT => 'Deposit',
            LedgerModel::TYPE_EXPENSE => 'Expense',
            LedgerModel::TYPE_VENDOR_PURCHASE => 'Vendor Purchase',
            LedgerModel::TYPE_VENDOR_PAYMENT => 'Vendor Payment',
            LedgerModel::TYPE_SALARY_ADVANCE => 'Salary Advance',
            LedgerModel::TYPE_SALARY_PAYMENT => 'Salary Payment',
            LedgerModel::TYPE_SETTLEMENT => 'Expense Settlement',
            LedgerModel::TYPE_TRANSFER => 'Account Transfer',
            LedgerModel::TYPE_ADJUSTMENT => 'Adjustment',
            LedgerModel::TYPE_OPENING_BALANCE => 'Opening Balance',
        ];

        // Pending strip (global signal, not scope-filtered).
        $pendingL1 = LedgerModel::whereIn('approval_status', [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1])->count();
        $pendingL2 = LedgerModel::where('approval_status', LedgerModel::STATUS_PENDING_L2)->count();

        // Operational period figures (Qurbani excluded) — feed the pending sub-lines on the Online
        // and Riders cards (pending L1/L2, pending deposits/expenses).
        $kpis = $scope === 'qurbani' ? null : app(LedgerKpiService::class)->compute($startDate, $endDate);

        // Scoped P&L for the Sales card — reuses the HQ closing computation so the numbers reconcile
        // with the HQ dashboard. Frozen (Khaas) revenue = product line-item split; NF = total −
        // Frozen; Qurbani = revenue − qurbani-category expenses (no vendor purchases).
        $unitMap = ['ops' => 'all', 'nf' => 'nf', 'khaas' => 'kh', 'qurbani' => 'qb'];
        $pnl = null;
        try {
            $svc = app(\App\Services\HQ\ExecutiveClosingService::class);
            if ($scope === 'qurbani') {
                // Qurbani is a SEASON, not a month: its revenue is booked-season regardless of the
                // window, so expenses must span the same season or the two won't reconcile (the bug
                // that showed full-season revenue against ~zero July expenses). Use the calendar year
                // — matching the Qurbani Expenses page — so revenue − expenses = the season net.
                $y = \Carbon\Carbon::parse($startDate);
                $pnl = $svc->unitPnl('qb', $y->copy()->startOfYear(), $y->copy()->endOfYear());
            } else {
                $pnl = $svc->unitPnl(
                    $unitMap[$scope] ?? 'all',
                    \Carbon\Carbon::parse($startDate)->startOfDay(),
                    \Carbon\Carbon::parse($endDate)->endOfDay()
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Ledger Hub: P&L compute failed, Sales card hidden. ' . $e->getMessage());
        }

        // Position cards: headline = balance right now, sub-lines = movement in the period.
        $positions = $this->buildPositions($scope, $startDate, $endDate);

        return view('fin.hub.overview', [
            'active'           => 'overview',
            'scope'            => $scope,
            'canSeeKhaas'      => $canSeeKhaas,
            'canSeeMulti'      => $canSeeMulti,
            'ledger'           => $ledger,
            'accounts'         => $accounts,
            'transactionTypes' => $transactionTypes,
            'kpis'             => $kpis,
            'pnl'              => $pnl,
            'positions'        => $positions,
            'pendingL1'        => $pendingL1,
            'pendingL2'        => $pendingL2,
            'startDate'        => $startDate,
            'endDate'          => $endDate,
            'filters'          => $request->only(['type', 'mode', 'status', 'account_id', 'search']),
        ]);
    }

    /**
     * Position cards for the Overview: "where the money stands right now" (stored balances = the
     * operational truth for company accounts; CALCULATED balances for rider cash — same formula the
     * mobile app and the daily-close sheet use) + "what moved this period" sub-lines.
     *
     * Scope notes (verified against the replica, 2026-07-14):
     * - QURBANI_CASH / QURBANI_ONLINE are tagged business_unit_id = 1, so they would silently
     *   pollute the NF till/online sums — they are excluded by account_code here and shown ONLY in
     *   the Qurbani scope. Qurbani is a separate book, not a business unit.
     * - Period movement uses balance_updated = 1 ("currently applied" — the balance engine keeps
     *   this flag truthful), so the sub-lines describe money that actually moved the balances.
     */
    private function buildPositions(string $scope, string $startDate, string $endDate): array
    {
        $applied = function ($accountIds, string $direction) use ($startDate, $endDate): float {
            if (empty($accountIds)) {
                return 0.0;
            }
            return (float) LedgerModel::whereIn($direction === 'in' ? 'to_account_id' : 'from_account_id', $accountIds)
                ->where('balance_updated', 1)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount');
        };

        if ($scope === 'qurbani') {
            $cards = [];
            foreach ([['QURBANI_CASH', 'Qurbani Cash'], ['QURBANI_ONLINE', 'Qurbani Online']] as [$code, $label]) {
                $acc = AccountModel::where('account_code', $code)->first();
                if (!$acc) {
                    continue;
                }
                $cards[] = [
                    'label'   => $label,
                    'balance' => (float) $acc->current_balance,
                    'in'      => $applied([$acc->id], 'in'),
                    'out'     => $applied([$acc->id], 'out'),
                ];
            }
            return ['qurbani' => $cards, 'tills' => null, 'online' => null, 'riders' => null, 'vendors' => null];
        }

        $buIds = $scope === 'nf' ? [1] : ($scope === 'khaas' ? [2] : [1, 2]);

        // --- Cash tills (Qurbani books excluded — they live in the Qurbani scope) ---
        $tillAccounts = AccountModel::where('is_active', 1)
            ->where('account_category', AccountModel::CATEGORY_CASH)
            ->whereIn('business_unit_id', $buIds)
            ->where('account_code', 'NOT LIKE', 'QURBANI%')
            ->orderByDesc('current_balance')
            ->get(['id', 'account_code', 'account_name', 'current_balance']);
        $tillIds = $tillAccounts->pluck('id')->all();

        // --- Online / bank pool (same Qurbani exclusion) ---
        $bankAccounts = AccountModel::where('is_active', 1)
            ->where('account_category', AccountModel::CATEGORY_BANK)
            ->whereIn('business_unit_id', $buIds)
            ->where('account_code', 'NOT LIKE', 'QURBANI%')
            ->orderByDesc('current_balance')
            ->get(['id', 'account_code', 'account_name', 'current_balance']);
        $bankIds = $bankAccounts->pluck('id')->all();

        // --- Rider cash: CALCULATED balances (the stored column is retired for employee_cash) ---
        $riders = null;
        if ($scope !== 'khaas') { // Khaas has store staff, no delivery riders
            $riderAccounts = AccountModel::where('is_active', 1)
                ->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->get();
            $calcTotal = 0.0;
            $holding = 0;
            foreach ($riderAccounts as $acc) {
                $calc = (float) $acc->getCalculatedBalance();
                $calcTotal += $calc;
                if ($calc > 0.005) {
                    $holding++;
                }
            }
            $riders = ['total' => $calcTotal, 'holding' => $holding, 'count' => $riderAccounts->count()];
        }

        // --- Vendors: TRUE current payable (sum of vendor accounts), period purchases/payments ---
        $vendors = null;
        $vendorAccounts = AccountModel::where('is_active', 1)
            ->where('account_category', AccountModel::CATEGORY_VENDOR_PAYABLE)
            ->whereIn('business_unit_id', $buIds)
            ->get(['id', 'current_balance']);
        if ($vendorAccounts->isNotEmpty()) {
            $vendorIds = $vendorAccounts->pluck('id')->all();
            $periodSum = function (string $type) use ($vendorIds, $startDate, $endDate): float {
                return (float) LedgerModel::where('transaction_type', $type)
                    ->whereIn('to_account_id', $vendorIds)
                    ->where('balance_updated', 1)
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->sum('amount');
            };
            $vendors = [
                'payable'   => (float) $vendorAccounts->sum('current_balance'),
                'purchases' => $periodSum(LedgerModel::TYPE_VENDOR_PURCHASE),
                'payments'  => $periodSum(LedgerModel::TYPE_VENDOR_PAYMENT),
            ];
        }

        return [
            'qurbani' => null,
            'tills'   => [
                'accounts' => $tillAccounts,
                'total'    => (float) $tillAccounts->sum('current_balance'),
                'in'       => $applied($tillIds, 'in'),
                'out'      => $applied($tillIds, 'out'),
            ],
            'online'  => $bankAccounts->isEmpty() ? null : [
                'accounts' => $bankAccounts,
                'total'    => (float) $bankAccounts->sum('current_balance'),
                'in'       => $applied($bankIds, 'in'),
                'out'      => $applied($bankIds, 'out'),
            ],
            'riders'  => $riders,
            'vendors' => $vendors,
        ];
    }

    /** Tab 2 — Accounts: company tills + riders' cash board + assets summary (all scope-aware). */
    public function accounts(Request $request)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);
        $user = auth()->user();
        $buIds = $scope === 'nf' ? [1] : ($scope === 'khaas' ? [2] : [1, 2]);

        // --- Company accounts strip (cash + bank). Qurbani books only in Qurbani scope. ---
        $companyQuery = AccountModel::where('is_active', 1)
            ->whereIn('account_category', [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK])
            ->visibleTo($user);
        if ($scope === 'qurbani') {
            $companyQuery->where('account_code', 'LIKE', 'QURBANI%');
        } else {
            $companyQuery->whereIn('business_unit_id', $buIds)
                         ->where('account_code', 'NOT LIKE', 'QURBANI%');
        }
        $companyAccounts = $companyQuery->orderByDesc('current_balance')->get();

        // --- Riders board (employee_cash = NF operations). Hidden for Frozen/Qurbani. ---
        $riders = in_array($scope, ['ops', 'nf'], true) ? $this->buildRidersBoard() : null;

        // --- Assets summary (active book value by category). Not in Qurbani scope. ---
        $assets = null;
        if ($scope !== 'qurbani') {
            $assetRows = \App\Models\FIN\AssetModel::where('status', \App\Models\FIN\AssetModel::STATUS_ACTIVE)
                ->whereIn('business_unit_id', $buIds)
                ->with('category')
                ->get(['id', 'category_id', 'current_book_value', 'business_unit_id']);
            if ($assetRows->isNotEmpty()) {
                $byCat = $assetRows->groupBy(fn ($a) => optional($a->category)->category_name ?? optional($a->category)->name ?? 'Uncategorised')
                    ->map(fn ($grp) => ['count' => $grp->count(), 'value' => (float) $grp->sum('current_book_value')])
                    ->sortByDesc('value');
                $assets = [
                    'total' => (float) $assetRows->sum('current_book_value'),
                    'count' => $assetRows->count(),
                    'by_cat' => $byCat,
                ];
            }
        }

        return view('fin.hub.accounts', [
            'active'          => 'accounts',
            'scope'           => $scope,
            'canSeeKhaas'     => $canSeeKhaas,
            'canSeeMulti'     => $canSeeMulti,
            'companyAccounts' => $companyAccounts,
            'riders'          => $riders,
            'assets'          => $assets,
            'expenseCategories' => $this->expenseCategories(),
        ]);
    }

    /** JSON: accounts (with balances) + our banks, for the in-Hub transfer modal. */
    public function transferAccountsData()
    {
        $accounts = AccountModel::where('is_active', 1)
            ->visibleTo(auth()->user())
            ->orderBy('account_name', 'asc')
            ->get(['id', 'account_name', 'account_code', 'account_type', 'account_category', 'current_balance'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->account_name,
                'type' => $a->account_type,
                'category' => $a->account_category,
                'balance' => (float) $a->current_balance,
            ]);

        $bankBalances = app(\App\Services\FIN\BankBalanceService::class)->balancesByBank();
        $banks = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance'])
            ->map(fn ($acc) => [
                'id' => $acc->id,
                'name' => $acc->name,
                'short_code' => $acc->short_code,
                'color_hex' => $acc->color_hex,
                'balance' => $bankBalances[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ])->values();

        return response()->json(['accounts' => $accounts, 'banks' => $banks]);
    }

    /** Expense categories for the short-cash settle path (server-rendered, no GET endpoint exists). */
    private function expenseCategories(): array
    {
        $cats = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%')
            ->pluck('config_value')->toArray();
        return !empty($cats) ? $cats : ['Petrol', 'Rent', 'Office Supplies'];
    }

    /** Shared account-detail screen — renders ANY account (rider or company). */
    public function accountDetail(Request $request, $id)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);
        $account = AccountModel::findOrFail($id);

        // Private-account guard: non-Taimur can't open a private account directly.
        $user = auth()->user();
        $isTaimur = $user && $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
        if ($account->is_private && !$isTaimur) {
            abort(403);
        }

        $isEmployee = $account->account_category === AccountModel::CATEGORY_EMPLOYEE_CASH;
        $isAsset    = $account->account_type === AccountModel::TYPE_ASSET;
        $balance    = $account->getEffectiveBalance();

        // Qurbani accounts hold seasonal activity (months old), so default to a season-length window;
        // everything else defaults to 60 days. A period selector on the page overrides this.
        $isQurbaniAcct = str_starts_with((string) $account->account_code, 'QURBANI');
        $daysParam = (string) $request->get('days', $isQurbaniAcct ? '365' : '60');
        if ($daysParam === 'all') {
            $days = 36500;
            $daysLabel = 'all time';
        } else {
            $days = max(7, min((int) $daysParam, 3650));
            $daysLabel = 'last ' . $days . ' days';
        }
        $ledger = $this->buildAccountLedger($account, $days, $isEmployee, $isAsset);

        // Rider extras: open invoices + last deposit.
        $riderMeta = null;
        if ($isEmployee) {
            $open = LedgerModel::where('to_account_id', $account->id)
                ->whereIn('transaction_type', [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT])
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->selectRaw('COUNT(*) c, COALESCE(SUM(amount - COALESCE(settled_amount,0)),0) t')
                ->first();
            $lastDep = LedgerModel::where('from_account_id', $account->id)
                ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->max('transaction_date');
            // The actual open invoices — so the balance is explainable ("how is he holding this?").
            $openInvoices = LedgerModel::with('order.customer')
                ->where('to_account_id', $account->id)
                ->whereIn('transaction_type', [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT])
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->orderBy('transaction_date', 'asc')
                ->get();
            $riderMeta = [
                'open_count' => (int) $open->c,
                'open_total' => (float) $open->t,
                'last_deposit' => $lastDep,
                'invoices' => $openInvoices,
            ];
        }

        return view('fin.hub.account-detail', [
            'active'      => 'accounts',
            'scope'       => $scope,
            'canSeeKhaas' => $canSeeKhaas,
            'canSeeMulti' => $canSeeMulti,
            'account'     => $account,
            'isEmployee'  => $isEmployee,
            'isAsset'     => $isAsset,
            'balance'     => $balance,
            'ledger'      => $ledger,
            'riderMeta'   => $riderMeta,
            'days'        => $days,
            'daysLabel'   => $daysLabel,
            'daysSel'     => $daysParam,
            'expenseCategories' => $this->expenseCategories(),
            'oldUrl'      => $isEmployee || in_array($account->account_code, ['NF_CASH', 'EXP_FUND', 'ONLINE'], true)
                                ? route('fin.employee.show', $account->id)
                                : route('fin.accounts.show', $account->id),
        ]);
    }

    /** Resolve + guard the scope param against the user's accessible business units. */
    private function resolveScope(Request $request): array
    {
        $accessibleBUs = AccountModel::getUserAccessibleBusinessUnits();
        $canSeeKhaas = $accessibleBUs->contains(fn ($bu) => (int) $bu->id === 2);
        $canSeeMulti = $accessibleBUs->count() >= 2;
        $scope = in_array($request->get('scope'), self::SCOPES, true) ? $request->get('scope') : 'ops';
        if (!$canSeeMulti && in_array($scope, ['ops', 'khaas', 'qurbani'], true)) {
            $scope = $canSeeKhaas ? $scope : 'nf';
        }
        return [$scope, $canSeeKhaas, $canSeeMulti];
    }

    /** Riders cash board: per-rider calculated balance, open invoices, last deposit, state. */
    private function buildRidersBoard(): array
    {
        $accounts = AccountModel::where('is_active', 1)
            ->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
            ->get();
        if ($accounts->isEmpty()) {
            return ['rows' => [], 'held_total' => 0, 'holding' => 0, 'need' => 0];
        }
        $ids = $accounts->pluck('id')->all();

        $openAgg = LedgerModel::whereIn('to_account_id', $ids)
            ->whereIn('transaction_type', [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT])
            ->whereIn('settlement_status', ['open', 'partial'])
            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
            ->groupBy('to_account_id')
            ->selectRaw('to_account_id, COUNT(*) c, MIN(transaction_date) oldest')
            ->get()->keyBy('to_account_id');

        $lastDepAgg = LedgerModel::whereIn('from_account_id', $ids)
            ->where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
            ->where('approval_status', LedgerModel::STATUS_APPROVED)
            ->groupBy('from_account_id')
            ->selectRaw('from_account_id, MAX(transaction_date) last_dep')
            ->get()->keyBy('from_account_id');

        $today = \Carbon\Carbon::today()->toDateString();
        $rows = [];
        $heldTotal = 0.0;
        $holding = 0;
        $need = 0;
        foreach ($accounts as $acc) {
            $calc = (float) $acc->getCalculatedBalance();
            $open = $openAgg->get($acc->id);
            $openCount = $open ? (int) $open->c : 0;
            $oldest = $open->oldest ?? null;
            $lastDep = optional($lastDepAgg->get($acc->id))->last_dep;

            if ($calc < -1) {
                $state = 'flag';
                $stateLabel = 'Under review';
            } elseif ($calc <= 1) {
                $state = 'clean';
                $stateLabel = 'Clean';
            } elseif ($oldest && \Carbon\Carbon::parse($oldest)->toDateString() < $today) {
                $state = 'settle';
                $stateLabel = 'Needs settlement';
                $need++;
            } else {
                $state = 'holding';
                $stateLabel = 'Holding · today’s runs';
            }
            if ($calc > 1) {
                $heldTotal += $calc;
                $holding++;
            }

            $rows[] = [
                'id' => $acc->id,
                'name' => $acc->account_name,
                'calc' => $calc,
                'open_count' => $openCount,
                'last_deposit' => $lastDep,
                'state' => $state,
                'state_label' => $stateLabel,
            ];
        }
        usort($rows, fn ($a, $b) => $b['calc'] <=> $a['calc']);

        return ['rows' => $rows, 'held_total' => $heldTotal, 'holding' => $holding, 'need' => $need];
    }

    /**
     * Day-grouped ledger for one account over the last $days. Company (asset) accounts get a running
     * balance column (opening_balance + applied rows, asset sign); employee accounts do NOT (their
     * truth is the calculated header — running balance on the stored column is the retired D10 bug).
     */
    private function buildAccountLedger(AccountModel $account, int $days, bool $isEmployee, bool $isAsset): array
    {
        $start = \Carbon\Carbon::today()->subDays($days)->startOfDay();

        $rowsQuery = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer'])
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id);
            })
            ->where('transaction_date', '>=', $start);
        if ($isEmployee) {
            $rowsQuery->whereNotIn('transaction_type', AccountModel::EXCLUDED_EMPLOYEE_CASH_TYPES);
        }
        $rows = $rowsQuery->orderBy('transaction_date', 'asc')->orderBy('created_at', 'asc')->get();

        // Running balance for asset company accounts: seed with the balance before the window.
        $running = null;
        if ($isAsset && !$isEmployee) {
            $preIn = LedgerModel::where('to_account_id', $account->id)->where('balance_updated', 1)
                ->where('transaction_date', '<', $start)->sum('amount');
            $preOut = LedgerModel::where('from_account_id', $account->id)->where('balance_updated', 1)
                ->where('transaction_date', '<', $start)->sum('amount');
            $running = (float) $account->opening_balance + (float) $preIn - (float) $preOut;
        }

        // Build per-row view models with direction + optional running balance, then group by day.
        $items = [];
        foreach ($rows as $r) {
            $isIn = (int) $r->to_account_id === (int) $account->id;
            $signed = $isIn ? (float) $r->amount : -(float) $r->amount;
            if ($running !== null && $r->balance_updated) {
                $running += $signed;
            }
            $items[] = [
                'row' => $r,
                'is_in' => $isIn,
                'running' => $running,
            ];
        }

        // Group by date (DESC), each group carries in/out/net.
        $groups = [];
        foreach (array_reverse($items) as $it) {
            $d = \Carbon\Carbon::parse($it['row']->transaction_date)->toDateString();
            if (!isset($groups[$d])) {
                $groups[$d] = ['date' => $d, 'in' => 0.0, 'out' => 0.0, 'items' => []];
            }
            if ($it['is_in']) {
                $groups[$d]['in'] += (float) $it['row']->amount;
            } else {
                $groups[$d]['out'] += (float) $it['row']->amount;
            }
            $groups[$d]['items'][] = $it;
        }
        foreach ($groups as &$g) {
            $g['net'] = $g['in'] - $g['out'];
        }
        unset($g);

        return ['groups' => array_values($groups), 'has_running' => $running !== null, 'count' => count($items)];
    }

    /** Tab 3 — Vendors: what NF owes each supplier, scope-aware, with a period pulse. */
    public function vendors(Request $request)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);

        // Qurbani has no vendors (its costs are expenses, not vendor purchases).
        if ($scope === 'qurbani') {
            return view('fin.hub.vendors', [
                'active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
                'noVendors' => true, 'vendors' => collect(), 'totals' => null, 'search' => '',
                'startDate' => null, 'endDate' => null,
            ]);
        }

        $buIds = $scope === 'nf' ? [1] : ($scope === 'khaas' ? [2] : [1, 2]);
        $startDate = $request->start_date ?: now()->startOfMonth()->format('Y-m-d');
        $endDate   = $request->end_date ?: now()->endOfMonth()->format('Y-m-d');
        $search    = trim((string) $request->get('search', ''));

        $q = \App\Models\FIN\VendorModel::with(['account'])
            ->where('is_active', 1)
            ->whereIn('business_unit_id', $buIds);
        if ($search !== '') {
            // Grouped so the search never bypasses the active/BU filters (fixes the old orWhere leak).
            $q->where(function ($w) use ($search) {
                $w->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%");
            });
        }
        $vendorList = $q->orderBy('vendor_name', 'asc')->get();

        $acctIds = $vendorList->pluck('account.id')->filter()->all();
        // Period purchases/payments per vendor account, in one query each.
        $periodBy = function (string $type) use ($acctIds, $startDate, $endDate) {
            if (empty($acctIds)) return collect();
            return LedgerModel::where('transaction_type', $type)
                ->whereIn('to_account_id', $acctIds)
                ->where('approval_status', LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->groupBy('to_account_id')
                ->selectRaw('to_account_id, SUM(amount) amt')
                ->pluck('amt', 'to_account_id');
        };
        $purch = $periodBy(LedgerModel::TYPE_VENDOR_PURCHASE);
        $pay   = $periodBy(LedgerModel::TYPE_VENDOR_PAYMENT);
        $lastPay = empty($acctIds) ? collect() : LedgerModel::where('transaction_type', LedgerModel::TYPE_VENDOR_PAYMENT)
            ->whereIn('to_account_id', $acctIds)
            ->groupBy('to_account_id')->selectRaw('to_account_id, MAX(transaction_date) d')
            ->pluck('d', 'to_account_id');

        $rows = $vendorList->map(function ($v) use ($purch, $pay, $lastPay) {
            $aid = optional($v->account)->id;
            $bal = (float) (optional($v->account)->current_balance ?? 0);
            return [
                'id' => $v->id,
                'name' => $v->vendor_name,
                'code' => optional($v->account)->account_code,
                'method' => $v->default_purchase_method,
                'payable' => $bal,
                'purchases' => (float) ($aid ? ($purch[$aid] ?? 0) : 0),
                'payments' => (float) ($aid ? ($pay[$aid] ?? 0) : 0),
                'last_pay' => $aid ? ($lastPay[$aid] ?? null) : null,
                // For the edit-vendor modal prefill + delete gating.
                'contact_person' => $v->contact_person,
                'contact_email' => $v->contact_email,
                'contact_phone' => $v->contact_phone,
                'pay_source' => $v->default_payment_source_id,
                'bu' => $v->business_unit_id,
                'deletable' => abs($bal) < 0.005,
            ];
        })->sortByDesc('payable')->values();

        $totals = [
            'owed' => (float) $rows->sum('payable'),
            'with_balance' => $rows->filter(fn ($r) => $r['payable'] > 0.5)->count(),
            'purchases' => (float) $rows->sum('purchases'),
            'payments' => (float) $rows->sum('payments'),
        ];

        return view('fin.hub.vendors', [
            'active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'noVendors' => false, 'vendors' => $rows, 'totals' => $totals, 'search' => $search,
            'startDate' => $startDate, 'endDate' => $endDate,
            // Create/edit-vendor modal selects.
            'businessUnits' => AccountModel::getUserAccessibleBusinessUnits(),
            'paymentSources' => AccountModel::getAccessibleCompanyAccounts(),
            'userDefaultBuId' => AccountModel::getUserDefaultBusinessUnitId(),
        ]);
    }

    /** Vendor detail: true payable + day-grouped purchases/payments ledger with carry-forward balance. */
    public function vendorDetail(Request $request, $id)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);
        $vendor = \App\Models\FIN\VendorModel::with('account')->findOrFail($id);
        $account = $vendor->account;

        // Honest date range: default ALL history (fixes the old page's month-display-but-all-data
        // illusion). A range can be applied via ?start_date/&end_date.
        $hasRange = $request->filled('start_date') && $request->filled('end_date');
        $startDate = $hasRange ? $request->start_date : null;
        $endDate   = $hasRange ? $request->end_date : null;

        $payable = (float) $vendor->getBalance();
        $opening = (float) (optional($account)->opening_balance ?? 0);

        $groups = [];
        $periodPurchases = 0.0;
        $periodPayments = 0.0;
        $lastPayment = null;
        if ($account) {
            $all = LedgerModel::with(['fromAccount', 'createdBy'])
                ->where(function ($q) use ($account) {
                    $q->where('to_account_id', $account->id)->orWhere('from_account_id', $account->id);
                })
                ->whereIn('transaction_type', [LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::TYPE_VENDOR_PAYMENT])
                ->orderBy('transaction_date', 'asc')->orderBy('created_at', 'asc')->orderBy('id', 'asc')
                ->get();

            // Carry-forward running balance from opening across ALL rows; then (optionally) show only
            // the window — so a filtered view's Balance column stays correct (old page restarted at 0).
            $running = $opening;
            $items = [];
            foreach ($all as $r) {
                $isPurchase = $r->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
                $running += $isPurchase ? (float) $r->amount : -(float) $r->amount;
                $inWindow = !$hasRange || ($r->transaction_date >= $startDate && $r->transaction_date <= $endDate . ' 23:59:59');
                if ($inWindow) {
                    $items[] = ['row' => $r, 'is_purchase' => $isPurchase, 'running' => $running];
                    if ($isPurchase) $periodPurchases += (float) $r->amount; else $periodPayments += (float) $r->amount;
                    if (!$isPurchase) $lastPayment = $r;
                }
            }
            foreach (array_reverse($items) as $it) {
                $d = \Carbon\Carbon::parse($it['row']->transaction_date)->toDateString();
                if (!isset($groups[$d])) $groups[$d] = ['date' => $d, 'purchases' => 0.0, 'payments' => 0.0, 'items' => []];
                if ($it['is_purchase']) $groups[$d]['purchases'] += (float) $it['row']->amount;
                else $groups[$d]['payments'] += (float) $it['row']->amount;
                $groups[$d]['items'][] = $it;
            }
            $groups = array_values($groups);
        }

        // Pay-from options (vendor's BU, minus the expense fund) + our banks for the payment modal.
        $vendorBu = (int) $vendor->business_unit_id;
        $paymentSources = AccountModel::getAccessibleCompanyAccounts()
            ->filter(fn ($a) => $a->account_code !== 'EXP_FUND' && (int) $a->business_unit_id === $vendorBu)
            ->values();
        if ($paymentSources->isEmpty()) {
            $paymentSources = AccountModel::getAccessibleCompanyAccounts()
                ->filter(fn ($a) => $a->account_code !== 'EXP_FUND')->values();
        }
        $bankBalances = app(\App\Services\FIN\BankBalanceService::class)->balancesByBank();
        $receivingBanks = \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()
            ->get(['id', 'name', 'short_code', 'color_hex', 'opening_balance'])
            ->map(fn ($acc) => [
                'id' => $acc->id, 'name' => $acc->name, 'short_code' => $acc->short_code,
                'color_hex' => $acc->color_hex,
                'balance' => $bankBalances[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance,
            ])->values();

        return view('fin.hub.vendor-detail', [
            'active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'vendor' => $vendor, 'account' => $account, 'payable' => $payable,
            'groups' => $groups, 'hasRange' => $hasRange, 'startDate' => $startDate, 'endDate' => $endDate,
            'periodPurchases' => $periodPurchases, 'periodPayments' => $periodPayments, 'lastPayment' => $lastPayment,
            'paymentSources' => $paymentSources, 'receivingBanks' => $receivingBanks,
            'oldUrl' => route('fin.vendors.show', $vendor->id),
        ]);
    }

    /**
     * Tab 4 — Banks: per-bank distribution of the single ONLINE pool. Mirrors
     * BankBalancesController::index's computation (BankBalanceService is the shared truth) so the
     * numbers match the old page exactly. Per-bank balances are COMPUTED from tagged movements;
     * the ⚖ balance-fix is an attribution-only adjustment that never touches the ledger/ONLINE.
     */
    public function banks(Request $request, \App\Services\FIN\BankBalanceService $balances)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);

        // Banks are SCOPED (verified from data 2026-07-14): the physical banks (Meezan, HBL…) hold
        // ONLY non-qurbani online money (qurbani-tagged-to-banks = 0); qurbani online sits entirely
        // untagged in its own book. So NF/Frozen/ops scope = the ONLINE pool + physical banks + the
        // non-qurbani untagged; Qurbani scope = the QURBANI_ONLINE pool + qurbani untagged, with no
        // physical bank cards (qurbani is not distributed across physical banks). NF and Frozen map
        // to the same ONLINE pool (there is no separate Khaas online chart account).
        $isQ = ($scope === 'qurbani');
        $onlineIds = $balances->onlineAccountIds();
        $qfMode = $isQ ? \App\Services\QurbaniFinanceFilter::MODE_INCLUDE : \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE;

        // Pool = the online chart account(s) for this scope.
        $onlineAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->when($isQ, fn ($q) => $q->where('account_code', 'LIKE', 'QURBANI%'))
            ->when(!$isQ, fn ($q) => $q->where('account_code', 'NOT LIKE', 'QURBANI%'))
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_code', 'current_balance'])
            ->map(fn ($a) => ['name' => $a->account_name, 'code' => $a->account_code, 'balance' => (float) $a->current_balance])->values();
        $ledgerOnlineBalance = (float) $onlineAccounts->sum('balance');

        // Physical bank cards + tracked total + manual fixes — only in the operational (non-qurbani) scope.
        $status = in_array($request->get('status'), ['all', 'inactive'], true) ? $request->get('status') : 'active';
        $rows = [];
        $sumBalances = 0.0;
        $activeCount = 0;
        $inactiveCount = 0;
        $netManualAdjustments = 0.0;
        if (!$isQ) {
            $byBank = $balances->balancesByBank();
            $allBanks = \App\Models\FIN\OnlineReceivingAccountModel::ordered()->get();
            $activeCount = $allBanks->where('is_active', true)->count();
            $inactiveCount = $allBanks->where('is_active', false)->count();
            foreach ($allBanks as $bank) {
                $sumBalances += $byBank[(int) $bank->id]['balance'] ?? (float) $bank->opening_balance;
            }
            $displayBanks = $allBanks->filter(function ($bank) use ($status) {
                if ($status === 'all') return true;
                if ($status === 'inactive') return !$bank->is_active;
                return (bool) $bank->is_active;
            });
            foreach ($displayBanks as $bank) {
                $calc = $byBank[(int) $bank->id] ?? ['net' => 0.0, 'balance' => (float) $bank->opening_balance];
                $rows[] = [
                    'id' => $bank->id, 'name' => $bank->name, 'short_code' => $bank->short_code,
                    'account_last4' => $bank->account_last4, 'color_hex' => $bank->color_hex ?: '#3B82F6',
                    'is_active' => (bool) $bank->is_active, 'opening_balance' => (float) $bank->opening_balance,
                    'opening_balance_date' => optional($bank->opening_balance_date)->toDateString(),
                    'net_movement' => $calc['net'], 'balance' => $calc['balance'],
                ];
            }
            $netManualAdjustments = (float) \Illuminate\Support\Facades\DB::table('t_fin_bank_balance_adjustment')->sum('amount');
        }

        // Untagged "No bank" bucket — scoped (qurbani vs non-qurbani).
        [$unassigned, $unassignedCount] = $this->scopedUntagged($onlineIds, $qfMode);

        $reconGap = round(($sumBalances + $unassigned) - $ledgerOnlineBalance - $netManualAdjustments, 2);
        $reconStatus = abs($reconGap) < 1000 ? 'green' : (abs($reconGap) < 100000 ? 'amber' : 'red');

        $user = auth()->user();
        $isTaimur = $user && $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();

        return view('fin.hub.banks', [
            'active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'isQ' => $isQ,
            'banks' => $rows, 'status' => $status, 'activeCount' => $activeCount, 'inactiveCount' => $inactiveCount,
            'sumBalances' => $sumBalances, 'unassigned' => $unassigned, 'unassignedCount' => $unassignedCount,
            'unassignedSince' => $balances->unassignedSince(),
            'onlineAccounts' => $onlineAccounts, 'ledgerOnlineBalance' => $ledgerOnlineBalance,
            'netManualAdjustments' => $netManualAdjustments, 'reconGap' => $reconGap, 'reconStatus' => $reconStatus,
            'isTaimur' => $isTaimur,
        ]);
    }

    /**
     * Untagged online net + row count for a scope (qurbani vs non-qurbani), honouring the No-bank
     * tracking-since floor. Net = money into an online account − money out (both untagged).
     */
    private function scopedUntagged(array $onlineIds, string $qfMode): array
    {
        $since = \App\Models\FIN\ConfigModel::get('bank_unassigned_tracking_since', null);
        $sinceDate = $since ? substr((string) $since, 0, 10) : null;
        $base = function () use ($onlineIds, $sinceDate, $qfMode) {
            $q = LedgerModel::whereNull('receiving_account_id')
                ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2]);
            if ($sinceDate) $q->whereDate('transaction_date', '>=', $sinceDate);
            \App\Services\QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', $qfMode);
            return $q;
        };
        $in = (float) $base()->whereIn('to_account_id', $onlineIds)->whereNotIn('from_account_id', $onlineIds)->sum('amount');
        $out = (float) $base()->whereIn('from_account_id', $onlineIds)->whereNotIn('to_account_id', $onlineIds)->sum('amount');
        $count = $base()->where(fn ($w) => $w->whereIn('to_account_id', $onlineIds)->orWhereIn('from_account_id', $onlineIds))->count();
        return [$in - $out, $count];
    }

    /**
     * Bank detail — the bank's statement as an INLINE day-grouped table (consistent with the
     * Account/Vendor detail pages, not a modal). {id} = a bank id, or 'unassigned' for the No-bank
     * bucket. Running balance is derived BACKWARD from the known current balance (BankBalanceService),
     * so it never needs a pre-window seed.
     */
    public function bankDetail(Request $request, $id, \App\Services\FIN\BankBalanceService $balances)
    {
        [$scope, $canSeeKhaas, $canSeeMulti] = $this->resolveScope($request);
        $onlineIds = $balances->onlineAccountIds();
        $user = auth()->user();
        $isTaimur = $user && $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();

        $days = (int) $request->get('days', 30);
        if (!in_array($days, [0, 30, 90, 365], true)) $days = 30;
        $since = $days > 0 ? now()->subDays($days)->toDateString() : null;

        $isUnassigned = ($id === 'unassigned');
        if ($isUnassigned) {
            // Scope the untagged bucket the same way the Banks list does.
            $qfMode = ($scope === 'qurbani')
                ? \App\Services\QurbaniFinanceFilter::MODE_INCLUDE
                : \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE;
            $bank = ['id' => null, 'name' => 'No bank', 'short_code' => 'NO BANK', 'color_hex' => '#B45309'];
            [$balance] = $this->scopedUntagged($onlineIds, $qfMode);
            $opening = null; $net = $balance;
            $cfgSince = \App\Models\FIN\ConfigModel::get('bank_unassigned_tracking_since', null);
            $q = LedgerModel::whereNull('receiving_account_id')
                ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2])
                ->where(fn ($w) => $w->whereIn('to_account_id', $onlineIds)->orWhereIn('from_account_id', $onlineIds));
            \App\Services\QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', $qfMode);
            if ($cfgSince) $q->whereDate('transaction_date', '>=', substr((string) $cfgSince, 0, 10));
            if ($since) $q->whereDate('transaction_date', '>=', $since);
            $rows = $q->orderByDesc('transaction_date')->orderByDesc('id')->limit(1000)->get();
            $adjRows = collect();
        } else {
            $model = \App\Models\FIN\OnlineReceivingAccountModel::findOrFail($id);
            $byBank = $balances->balancesByBank();
            $calc = $byBank[(int) $model->id] ?? ['net' => 0.0, 'balance' => (float) $model->opening_balance];
            $bank = ['id' => $model->id, 'name' => $model->name, 'short_code' => $model->short_code,
                     'color_hex' => $model->color_hex ?: '#3B82F6', 'account_last4' => $model->account_last4,
                     'is_active' => (bool) $model->is_active];
            $balance = (float) $calc['balance']; $net = (float) $calc['net'];
            $opening = ['amount' => (float) $model->opening_balance, 'date' => optional($model->opening_balance_date)->toDateString()];
            $q = LedgerModel::where('receiving_account_id', $model->id)
                ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2]);
            if ($since) $q->whereDate('transaction_date', '>=', $since);
            $rows = $q->orderByDesc('transaction_date')->orderByDesc('id')->limit(1000)->get();
            $adjRows = collect();
            try {
                $aq = \App\Models\FIN\BankBalanceAdjustmentModel::where('receiving_account_id', $model->id);
                if ($since) $aq->whereDate('adjustment_date', '>=', $since);
                $adjRows = $aq->orderByDesc('adjustment_date')->limit(200)->get();
            } catch (\Throwable $e) { /* table may not exist */ }
        }

        // Shape into unified statement items {date, type, description, counterparty, amount, direction, signed}.
        $cpTypes = [LedgerModel::TYPE_VENDOR_PAYMENT, LedgerModel::TYPE_SALARY_ADVANCE, LedgerModel::TYPE_SALARY_PAYMENT];
        $cpIds = $rows->filter(fn ($r) => in_array($r->transaction_type, $cpTypes, true))->pluck('to_account_id')->filter()->unique();
        $cpNames = $cpIds->isNotEmpty() ? AccountModel::whereIn('id', $cpIds->all())->pluck('account_name', 'id') : collect();

        $items = $rows->map(function ($r) use ($onlineIds, $cpTypes, $cpNames) {
            $toOnline = in_array((int) $r->to_account_id, $onlineIds, true);
            $fromOnline = in_array((int) $r->from_account_id, $onlineIds, true);
            $dir = ($toOnline && !$fromOnline) ? 'in' : (($fromOnline && !$toOnline) ? 'out' : 'neutral');
            return [
                'id' => $r->id, 'date' => optional($r->transaction_date)->toDateString(),
                'type' => $r->transaction_type, 'description' => $r->description,
                'counterparty' => in_array($r->transaction_type, $cpTypes, true) ? ($cpNames[(int) $r->to_account_id] ?? null) : null,
                'amount' => (float) $r->amount, 'direction' => $dir, 'is_adjustment' => false,
                'signed' => $dir === 'in' ? (float) $r->amount : ($dir === 'out' ? -(float) $r->amount : 0.0),
            ];
        });
        $adjItems = $adjRows->map(fn ($a) => [
            'id' => $a->id, 'date' => optional($a->adjustment_date)->toDateString(), 'type' => 'adjustment',
            'description' => $a->note ?: 'Manual balance fix', 'counterparty' => null,
            'amount' => abs((float) $a->amount), 'direction' => (float) $a->amount >= 0 ? 'in' : 'out',
            'is_adjustment' => true, 'signed' => (float) $a->amount,
        ]);
        $all = $items->concat($adjItems)->sortByDesc('date')->values();

        // Running balance backward from the current balance (newest row = current balance).
        $running = $balance;
        $withRun = [];
        foreach ($all as $it) {
            $it['running'] = $running;
            $running -= $it['signed'];
            $withRun[] = $it;
        }
        // Day groups (already DESC).
        $groups = [];
        foreach ($withRun as $it) {
            $d = $it['date'] ?: 'unknown';
            if (!isset($groups[$d])) $groups[$d] = ['date' => $d, 'in' => 0.0, 'out' => 0.0, 'items' => []];
            if ($it['direction'] === 'in') $groups[$d]['in'] += $it['amount'];
            elseif ($it['direction'] === 'out') $groups[$d]['out'] += $it['amount'];
            $groups[$d]['items'][] = $it;
        }

        $assignBanks = $isUnassigned && $isTaimur
            ? \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()->get(['id', 'name', 'short_code'])
            : collect();

        return view('fin.hub.bank-detail', [
            'active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'bank' => $bank, 'isUnassigned' => $isUnassigned, 'balance' => $balance, 'net' => $net, 'opening' => $opening,
            'groups' => array_values($groups), 'count' => count($withRun), 'days' => $days, 'isTaimur' => $isTaimur,
            'totalIn' => $all->where('direction', 'in')->sum('amount'), 'totalOut' => $all->where('direction', 'out')->sum('amount'),
            'assignBanks' => $assignBanks,
        ]);
    }

    /** Tab 5 — Health. Built in the next phase (H4). */
    public function health(Request $request)
    {
        return $this->placeholder('health', 'Health', 'Drift, untagged money and posting issues.',
            '/finance/ledger', 'Overall Ledger (Audit)', $request);
    }

    private function placeholder(string $active, string $title, string $blurb, string $oldUrl, string $oldLabel, Request $request)
    {
        $scope = in_array($request->get('scope'), self::SCOPES, true) ? $request->get('scope') : 'ops';
        $accessibleBUs = AccountModel::getUserAccessibleBusinessUnits();

        return view('fin.hub.placeholder', [
            'active'      => $active,
            'scope'       => $scope,
            'canSeeKhaas' => $accessibleBUs->contains(fn ($bu) => (int) $bu->id === 2),
            'canSeeMulti' => $accessibleBUs->count() >= 2,
            'title'       => $title,
            'blurb'       => $blurb,
            'oldUrl'      => $oldUrl,
            'oldLabel'    => $oldLabel,
        ]);
    }
}
