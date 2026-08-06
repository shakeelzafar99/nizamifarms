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
        // receivingAccount: the physical bank tag, surfaced in the drawer so an online row says
        // WHICH bank at a glance. Eager-loaded — the list renders 50 rows.
        $query = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer', 'receivingAccount']);

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
        //
        // Split into BALANCE ACTIONS vs INVOICES (Aug-2026). A single blended "91 waiting" is how
        // one stuck Rs 20,000 transfer hid behind ~90 routine delivered-invoice approvals for two
        // days. The two queues live in different screens and move at completely different rates,
        // so the strip now names them separately and links each to the screen that clears it.
        // (The old blended L1/L2 counts are gone with the blended strip they fed — the split
        // below carries both numbers the strip now shows.)
        $pendingSplit = app(\App\Services\FIN\PendingLedgerActionsService::class)->summary();

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
            'pendingSplit'     => $pendingSplit,
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

        // ⏳ Per-account pending-action counts, so an account holding stuck money is visible from
        // the list instead of only after opening it. Invoice approvals are excluded — see
        // PendingLedgerActionsService.
        $pendingCounts = app(\App\Services\FIN\PendingLedgerActionsService::class)
            ->countsByAccount($companyAccounts->pluck('id')->all());

        return view('fin.hub.accounts', [
            'active'          => 'accounts',
            'scope'           => $scope,
            'canSeeKhaas'     => $canSeeKhaas,
            'canSeeMulti'     => $canSeeMulti,
            'companyAccounts' => $companyAccounts,
            'pendingCounts'   => $pendingCounts,
            'riders'          => $riders,
            'assets'          => $assets,
            'expenseCategories' => $this->expenseCategories(),
        ]);
    }

    /**
     * JSON: accounts (with balances) + our banks, for the in-Hub transfer modal.
     *
     * Only accounts money can actually SIT in are offered — company tills (cash), the online pool
     * (bank) and people's cash (employee_cash). Vendor payables, expense/revenue and equity
     * accounts were previously listed too, which made the picker long and invited postings that
     * belong to the dedicated vendor / expense / salary flows. Narrowing the list changes no
     * behaviour for the old transfer page, which builds its own list.
     *
     * Balances use getEffectiveBalance(): for employee_cash the stored column is retired and
     * drifted, so the old payload showed riders a wrong number here while every other Hub surface
     * showed the calculated one.
     */
    public function transferAccountsData()
    {
        $groups = [
            AccountModel::CATEGORY_CASH => 'Company cash',
            AccountModel::CATEGORY_BANK => 'Online / bank',
            AccountModel::CATEGORY_EMPLOYEE_CASH => 'Rider & staff cash',
        ];

        $accounts = AccountModel::where('is_active', 1)
            ->whereIn('account_category', array_keys($groups))
            ->visibleTo(auth()->user())
            ->orderBy('account_name', 'asc')
            ->get(['id', 'account_name', 'account_code', 'account_type', 'account_category', 'current_balance', 'opening_balance'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->account_name,
                'code' => $a->account_code,
                'type' => $a->account_type,
                'category' => $a->account_category,
                'group' => $groups[$a->account_category] ?? 'Other',
                'balance' => (float) $a->getEffectiveBalance(),
            ])->values();

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

        return response()->json([
            'accounts' => $accounts,
            'banks' => $banks,
            // Only Taimur may record money crossing the boundary of the books.
            'can_external' => $this->isTaimur(),
        ]);
    }

    /** True when the signed-in user holds the Taimur role (the finance-correction gate). */
    private function isTaimur(): bool
    {
        return auth()->user()?->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists() ?? false;
    }

    /** 403 JSON when the current user is not Taimur, else null. */
    private function requireTaimurJson(string $action): ?\Illuminate\Http\JsonResponse
    {
        if ($this->isTaimur()) {
            return null;
        }
        return response()->json([
            'success' => false,
            'message' => "Only the Taimur role can {$action}.",
        ], 403);
    }

    /**
     * Record money crossing the boundary of the books — an owner injection, an outside refund, or
     * a correction of a figure that was simply wrong ("Outside" in the transfer modal).
     *
     * This is the gap the Hub had: adjustBalance() refuses bank-category accounts and the per-bank
     * ⚖ Fix balance is attribution-only, so there was no way to add real outside money to the
     * online pool. Posting goes through ExternalFundsService (one equity<->account `adjustment`
     * row, applied via the canonical BalancePostingService gate).
     */
    public function externalMove(Request $request, \App\Services\FIN\ExternalFundsService $funds)
    {
        if ($resp = $this->requireTaimurJson('record outside money')) {
            return $resp;
        }

        $validated = $request->validate([
            'account_id' => 'required|integer|exists:t_fin_accounts,id',
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            // Backdating is legitimate (a deposit noticed late); future-dating is not — it would
            // move the balance today while sitting in a period that has not happened.
            'transaction_date' => 'required|date|before_or_equal:today',
            'description' => 'required|string|max:500',
            'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
            'source_name' => 'nullable|string|max:255',
            'allow_untagged' => 'nullable|boolean',
        ]);

        $account = AccountModel::find($validated['account_id']);
        $allowed = [AccountModel::CATEGORY_CASH, AccountModel::CATEGORY_BANK, AccountModel::CATEGORY_EMPLOYEE_CASH];
        if (!$account || !$account->is_active || !in_array($account->account_category, $allowed, true)) {
            return response()->json(['success' => false, 'message' => 'Outside money can only be recorded against a cash, bank or staff-cash account.'], 422);
        }
        if ($account->is_private && !$this->isTaimur()) {
            return response()->json(['success' => false, 'message' => 'That account is not available to you.'], 403);
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();
            $row = $funds->post(
                $account,
                (float) $validated['amount'],
                $validated['direction'],
                \Carbon\Carbon::parse($validated['transaction_date'])->toDateString(),
                $validated['description'],
                $validated['receiving_account_id'] ?? null,
                $validated['source_name'] ?? null,
                (bool) ($validated['allow_untagged'] ?? false)
            );
            \Illuminate\Support\Facades\DB::commit();
        } catch (\InvalidArgumentException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Log::error('Ledger Hub external move failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Could not record that. Nothing was saved.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $validated['direction'] === 'in' ? 'Outside money added.' : 'Money out recorded.',
            'ledger_id' => $row->id,
        ]);
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

        // ⏳ What is waiting on approval for THIS account — deliberately computed outside the
        // period window above, because money stuck for months is exactly what the window hides
        // (see PendingLedgerActionsService). Invoices are excluded there: they belong to Online
        // Approvals and would bury everything else.
        $pendingActions = app(\App\Services\FIN\PendingLedgerActionsService::class)
            ->forAccount($account, auth()->id());

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
            'pendingActions' => $pendingActions,
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

    // ── "Who uses this account" (Aug-2026) ────────────────────────────────────
    //
    // The account-usage tags in t_fin_account_users decide which payment sources a
    // person is offered, per purpose, and which one is pre-selected. Managed here
    // rather than on a users×accounts grid because there are ~9 spendable accounts
    // and ~30 users: a short list per account is readable, the grid is not — and it
    // matches how the owner talks about it ("Askari - Shabib is Shabib's account").
    //
    // Editing is gated on `manage_account_users` (Taimur + Shabib). Everyone else
    // who can open the account sees the list read-only, which is useful on its own
    // ("why can't I pay from this?").

    /** JSON: the people tagged on this account + everyone who could be added. */
    public function accountUsers(Request $request, $id)
    {
        $account = AccountModel::findOrFail($id);
        $this->guardAccountVisible($account);

        $rows = \App\Models\FIN\AccountUserModel::with('user')
            ->where('account_id', $account->id)
            ->get()
            ->map(function ($t) use ($account) {
                return [
                    'id'                => $t->id,
                    'user_id'           => $t->user_id,
                    'name'              => $t->user->fullname ?? ('User #' . $t->user_id),
                    'is_default'        => (bool) $t->is_default,
                    'can_expense'       => (bool) $t->can_expense,
                    'can_vendor'        => (bool) $t->can_vendor,
                    'can_advance'       => (bool) $t->can_advance,
                    'preferred_bank_id' => $t->preferred_bank_id,
                    // No unit warning here any more. Since Aug-6-2026 a tag works
                    // in EVERY unit the person can file against (the request's own
                    // unit stamps the books) — the account's home unit no longer
                    // limits who may spend from it, so there is nothing to warn
                    // about.
                ];
            })
            ->sortByDesc('is_default')->values();

        return response()->json([
            'success'    => true,
            'can_manage' => (bool) auth()->user()?->hasPermission('manage_account_users')
                            && !auth()->user()?->isReadOnly(),
            'is_bank'    => $account->account_category === AccountModel::CATEGORY_BANK,
            'bu_name'    => optional(\App\Models\FIN\BusinessUnitModel::find($account->business_unit_id))->name,
            'rows'       => $rows,
            'banks'      => app(\App\Services\FIN\PaymentSourceService::class)->banks(),
            'candidates' => \App\Models\SysAdmin\UserModel::whereNotIn(
                    'id', $rows->pluck('user_id')->all() ?: [0]
                )
                ->orderBy('fullname')
                ->get(['id', 'fullname'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->fullname]),
        ]);
    }

    /** Add or update one person's tag on this account. */
    public function saveAccountUser(Request $request, $id)
    {
        if (!auth()->user()?->hasPermission('manage_account_users') || auth()->user()?->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change who uses this account.'], 403);
        }

        $account = AccountModel::findOrFail($id);
        $this->guardAccountVisible($account);

        $data = $request->validate([
            'user_id'           => 'required|exists:t_sys_user,id',
            'can_expense'       => 'nullable|boolean',
            'can_vendor'        => 'nullable|boolean',
            'can_advance'       => 'nullable|boolean',
            'is_default'        => 'nullable|boolean',
            'preferred_bank_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
        ]);

        try {
            $tag = \App\Models\FIN\AccountUserModel::firstOrNew([
                'account_id' => $account->id,
                'user_id'    => (int) $data['user_id'],
            ]);
            $tag->can_expense = (bool) ($data['can_expense'] ?? false);
            $tag->can_vendor  = (bool) ($data['can_vendor'] ?? false);
            $tag->can_advance = (bool) ($data['can_advance'] ?? false);
            // A bank preference is meaningless on a cash account and would be a
            // confusing leftover if the account were ever re-categorised.
            $tag->preferred_bank_id = $account->account_category === AccountModel::CATEGORY_BANK
                ? ($data['preferred_bank_id'] ?? null)
                : null;
            $tag->created_by = $tag->exists ? $tag->created_by : auth()->id();
            $tag->updated_by = auth()->id();
            if (!$tag->exists) {
                $tag->is_default = false;
            }
            $tag->save();

            // Starring is a move, not a toggle: setDefault() clears this user's
            // other star IN THE SAME BUSINESS UNIT (one home account per book).
            if (!empty($data['is_default'])) {
                \App\Models\FIN\AccountUserModel::setDefault((int) $data['user_id'], $account->id);
            } elseif ($tag->is_default) {
                $tag->is_default = false;
                $tag->save();
            }

            return $this->accountUsers($request, $id);
        } catch (\Throwable $e) {
            \Log::error('saveAccountUser failed', ['account' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Could not save. Please try again.'], 500);
        }
    }

    /** Remove one person's tag from this account. */
    public function deleteAccountUser(Request $request, $id, $userId)
    {
        if (!auth()->user()?->hasPermission('manage_account_users') || auth()->user()?->isReadOnly()) {
            return response()->json(['success' => false, 'message' => 'Not authorised to change who uses this account.'], 403);
        }

        $account = AccountModel::findOrFail($id);
        $this->guardAccountVisible($account);

        \App\Models\FIN\AccountUserModel::where('account_id', $account->id)
            ->where('user_id', (int) $userId)
            ->delete();

        return $this->accountUsers($request, $id);
    }

    /** Same private-account rule accountDetail() enforces — don't leak via the API. */
    private function guardAccountVisible(AccountModel $account): void
    {
        $user = auth()->user();
        $isTaimur = $user && $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
        if ($account->is_private && !$isTaimur) {
            abort(403);
        }
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

        $rowsQuery = LedgerModel::with(['fromAccount', 'toAccount', 'createdBy', 'order.customer', 'receivingAccount'])
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
        //
        // Aug-2026 fix: In/Out now count only money that ACTUALLY MOVED. Previously every row in
        // the day was summed regardless of approval, so a day containing an unapproved Rs 20,000
        // transfer announced "In Rs. 20,000" while the Balance column beside it — correctly —
        // never budged. The header and the balance contradicted each other, and the header was
        // the one people read. Unapproved amounts are now carried separately and labelled as
        // pending rather than silently folded into the day's takings.
        //
        // ONLY rows still awaiting a decision are diverted. This list also carries rejected and
        // reversed rows, which have their own (pre-existing) treatment in these totals — they are
        // deliberately left exactly as they were, so the single behaviour change here is that
        // un-approved money stops being counted as money that arrived. Calling a rejected row
        // "pending" would trade one wrong label for another.
        //
        // pending_l2 is NOT awaiting in this sense: L1 already posted its balance, L2 only
        // verifies. balance_updated is consulted as a second opinion so a row posted by any other
        // route is never mis-filed as pending.
        $awaitingStatuses = [LedgerModel::STATUS_PENDING, LedgerModel::STATUS_PENDING_L1];

        $groups = [];
        foreach (array_reverse($items) as $it) {
            $d = \Carbon\Carbon::parse($it['row']->transaction_date)->toDateString();
            if (!isset($groups[$d])) {
                $groups[$d] = ['date' => $d, 'in' => 0.0, 'out' => 0.0, 'pending' => 0.0, 'items' => []];
            }

            $amount = (float) $it['row']->amount;
            $awaiting = in_array($it['row']->approval_status, $awaitingStatuses, true)
                && !$it['row']->balance_updated;

            if ($awaiting) {
                $groups[$d]['pending'] += $amount;
            } elseif ($it['is_in']) {
                $groups[$d]['in'] += $amount;
            } else {
                $groups[$d]['out'] += $amount;
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
        // Alphabetical (owner's preference): the list is used to FIND a vendor, and a name is what
        // you scan for. Case-insensitive + natural so "LaCarne" sits with the L's and any numbered
        // name sorts 2 before 10. The "total owed" tile above still carries the money headline.
        })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        // In a combined scope the list mixes two businesses, so split it into NF-then-Frozen
        // sections (alphabetical inside each) rather than one interleaved run. A single-unit scope
        // has nothing to separate and stays flat.
        $buNames = \App\Models\FIN\BusinessUnitModel::pluck('name', 'id')->all();
        $vendorGroups = null;
        if ($rows->pluck('bu')->unique()->count() > 1) {
            $vendorGroups = $rows->groupBy('bu')
                ->sortKeys() // BU 1 (NF) above BU 2 (Frozen)
                ->map(fn ($grp, $buId) => [
                    'label' => $buNames[$buId] ?? ('Business unit ' . $buId),
                    'rows'  => $grp->values(),
                ])->values()->all();
        }

        $totals = [
            'owed' => (float) $rows->sum('payable'),
            'with_balance' => $rows->filter(fn ($r) => $r['payable'] > 0.5)->count(),
            'purchases' => (float) $rows->sum('purchases'),
            'payments' => (float) $rows->sum('payments'),
        ];

        return view('fin.hub.vendors', [
            'active' => 'vendors', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'noVendors' => false, 'vendors' => $rows, 'vendorGroups' => $vendorGroups,
            'totals' => $totals, 'search' => $search,
            'startDate' => $startDate, 'endDate' => $endDate,
            // Create/edit-vendor modal selects.
            'businessUnits' => AccountModel::getUserAccessibleBusinessUnits(),
            'paymentSources' => AccountModel::getAccessibleCompanyAccounts(),
            'userDefaultBuId' => AccountModel::getUserDefaultBusinessUnitId(),
        ]);
    }

    /** Vendor detail: true payable + day-grouped purchases/payments ledger with carry-forward balance. */
    /**
     * A statement this size or smaller opens fully expanded — below it, collapsing costs the owner
     * clicks and saves nothing. Above it, all but the newest month load on demand.
     */
    private const VENDOR_EXPAND_ROW_BUDGET = 150;

    /**
     * A vendor's purchases and payments with a carry-forward running balance.
     *
     * ONE source of truth for the page AND the lazy-loaded month endpoint. The balance is walked
     * from the account's opening across the vendor's WHOLE history and only then filtered to the
     * window, so a filtered range still shows true balances (the old page restarted at zero) — and
     * a month opened on demand shows exactly what it would have shown rendered inline.
     *
     * @return array{items: list<array{row: LedgerModel, is_purchase: bool, running: float}>,
     *               purchases: float, payments: float, lastPayment: ?LedgerModel}
     */
    private function vendorLedger($account, ?string $startDate, ?string $endDate, float $opening): array
    {
        $out = ['items' => [], 'purchases' => 0.0, 'payments' => 0.0, 'lastPayment' => null];
        if (!$account) {
            return $out;
        }
        $hasRange = $startDate && $endDate;

        $all = LedgerModel::with(['fromAccount', 'createdBy', 'receivingAccount'])
            ->where(function ($q) use ($account) {
                $q->where('to_account_id', $account->id)->orWhere('from_account_id', $account->id);
            })
            ->whereIn('transaction_type', [LedgerModel::TYPE_VENDOR_PURCHASE, LedgerModel::TYPE_VENDOR_PAYMENT])
            ->orderBy('transaction_date', 'asc')->orderBy('created_at', 'asc')->orderBy('id', 'asc')
            ->get();

        $running = $opening;
        foreach ($all as $r) {
            $isPurchase = $r->transaction_type === LedgerModel::TYPE_VENDOR_PURCHASE;
            $running += $isPurchase ? (float) $r->amount : -(float) $r->amount;
            $inWindow = !$hasRange
                || ($r->transaction_date >= $startDate && $r->transaction_date <= $endDate . ' 23:59:59');
            if ($inWindow) {
                $out['items'][] = ['row' => $r, 'is_purchase' => $isPurchase, 'running' => $running];
                if ($isPurchase) {
                    $out['purchases'] += (float) $r->amount;
                } else {
                    $out['payments'] += (float) $r->amount;
                    $out['lastPayment'] = $r;
                }
            }
        }

        return $out;
    }

    /**
     * Group chronological ledger items into months, newest first.
     *
     * `closing` is the running balance of the month's LAST transaction — items arrive oldest-first,
     * so the final write wins and that is the month-end position.
     */
    private function vendorMonths(array $items): array
    {
        $byMonth = [];
        foreach ($items as $it) {
            $ym = \Carbon\Carbon::parse($it['row']->transaction_date)->format('Y-m');
            if (!isset($byMonth[$ym])) {
                $byMonth[$ym] = ['ym' => $ym, 'label' => \Carbon\Carbon::parse($ym . '-01')->format('F Y'),
                                 'purchases' => 0.0, 'payments' => 0.0, 'closing' => 0.0, 'count' => 0, 'items' => []];
            }
            if ($it['is_purchase']) {
                $byMonth[$ym]['purchases'] += (float) $it['row']->amount;
            } else {
                $byMonth[$ym]['payments'] += (float) $it['row']->amount;
            }
            $byMonth[$ym]['closing'] = (float) $it['running'];
            $byMonth[$ym]['count']++;
            $byMonth[$ym]['items'][] = $it;
        }
        foreach ($byMonth as &$m) {
            $m['net'] = $m['purchases'] - $m['payments'];
        }
        unset($m);

        return array_reverse(array_values($byMonth));
    }

    /** Day groups (newest first) for one month's chronological items. */
    private function vendorDayGroups(array $itemsAsc): array
    {
        $groups = [];
        foreach (array_reverse($itemsAsc) as $it) {
            $d = \Carbon\Carbon::parse($it['row']->transaction_date)->toDateString();
            if (!isset($groups[$d])) {
                $groups[$d] = ['date' => $d, 'purchases' => 0.0, 'payments' => 0.0, 'items' => []];
            }
            if ($it['is_purchase']) {
                $groups[$d]['purchases'] += (float) $it['row']->amount;
            } else {
                $groups[$d]['payments'] += (float) $it['row']->amount;
            }
            $groups[$d]['items'][] = $it;
        }

        return array_values($groups);
    }

    /**
     * HTML for one collapsed month, fetched when the owner opens it. Same computation and same
     * partial as the inline months, so nothing can drift between the two paths.
     */
    public function vendorMonth(Request $request, $id, $ym)
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $ym)) {
            abort(404);
        }
        $vendor = \App\Models\FIN\VendorModel::with('account')->findOrFail($id);

        // The window must match the page that asked, or the month's rows (and its balances) would
        // be computed against a different filter than the one on screen.
        $hasRange = $request->filled('start_date') && $request->filled('end_date');
        $ledger = $this->vendorLedger(
            $vendor->account,
            $hasRange ? $request->start_date : null,
            $hasRange ? $request->end_date : null,
            (float) (optional($vendor->account)->opening_balance ?? 0)
        );

        $month = collect($this->vendorMonths($ledger['items']))->firstWhere('ym', $ym);
        if (!$month) {
            return response('', 204);
        }

        return view('fin.hub.partials.vendor-day-groups', [
            'days' => $this->vendorDayGroups($month['items']),
            'vendor' => $vendor,
            'account' => $vendor->account,
            'oldUrl' => route('fin.vendors.show', $vendor->id),
        ]);
    }

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

        $ledger = $this->vendorLedger($account, $startDate, $endDate, $opening);
        $periodPurchases = $ledger['purchases'];
        $periodPayments  = $ledger['payments'];
        $lastPayment     = $ledger['lastPayment'];

        // Month sections, newest first. Only some carry their rows: a busy vendor renders ~3KB of
        // markup PER ROW (two JSON payloads for the drawer and the edit modal), so shipping every
        // month expanded was a megabyte of HTML. Small statements still open fully expanded — the
        // collapsing only kicks in where it actually buys something.
        $months = $this->vendorMonths($ledger['items']);
        $expandAll = count($ledger['items']) <= self::VENDOR_EXPAND_ROW_BUDGET;
        foreach ($months as $i => &$m) {
            $m['days'] = ($expandAll || $i === 0) ? $this->vendorDayGroups($m['items']) : null;
            unset($m['items']); // raw rows never reach the view unless that month is being rendered
        }
        unset($m);

        // Products for the in-Hub manager + the ⚖ purchase modal (by-weight vendors only). ALL of
        // them, inactive included — managing means seeing what's switched off; the weighted modal
        // keeps using the active-only JSON endpoint.
        $vendorProducts = $vendor->default_purchase_method === 'by_weight'
            ? \App\Models\FIN\VendorProductModel::forVendor($vendor->id)
                ->orderByDesc('is_default')->orderByDesc('is_active')->orderBy('product_name')->get()
            : collect();

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
            'months' => $months, 'expandAll' => $expandAll, 'rowCount' => count($ledger['items']),
            'hasRange' => $hasRange, 'startDate' => $startDate, 'endDate' => $endDate,
            'periodPurchases' => $periodPurchases, 'periodPayments' => $periodPayments, 'lastPayment' => $lastPayment,
            'paymentSources' => $paymentSources, 'receivingBanks' => $receivingBanks,
            'vendorProducts' => $vendorProducts,
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
        $poolAccountModels = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->when($isQ, fn ($q) => $q->where('account_code', 'LIKE', 'QURBANI%'))
            ->when(!$isQ, fn ($q) => $q->where('account_code', 'NOT LIKE', 'QURBANI%'))
            ->orderBy('account_name')
            ->get(['id', 'account_name', 'account_code', 'current_balance']);
        // `id` is carried so this page can link back to the account's own page —
        // the ONLINE tile on the accounts list lands HERE rather than there, so
        // without a link the account page (and the "who uses this account" panel
        // on it) is unreachable by clicking for the one account that most needs it.
        $onlineAccounts = $poolAccountModels
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->account_name, 'code' => $a->account_code, 'balance' => (float) $a->current_balance])->values();
        $ledgerOnlineBalance = (float) $onlineAccounts->sum('balance');

        // ⏳ Pending balance actions against the pool. This page is where the ONLINE tile on the
        // accounts list actually lands (the pool tile opens Banks, not the account page), so the
        // tile's "N waiting for approval" chip must find its list HERE — without this, the chip
        // points at a page that never shows them. Same card, same service, same endpoints as the
        // account page; invoices stay excluded (they belong to Online Approvals).
        $pendingActions = app(\App\Services\FIN\PendingLedgerActionsService::class)
            ->forAccounts($poolAccountModels, auth()->id());

        // Physical bank cards + tracked total + manual fixes — only in the operational (non-qurbani) scope.
        $status = in_array($request->get('status'), ['all', 'inactive'], true) ? $request->get('status') : 'active';
        $rows = [];
        $allBankRows = [];
        $sumBalances = 0.0;
        $activeCount = 0;
        $inactiveCount = 0;
        $netManualAdjustments = 0.0;
        $isBaselined = false;
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
            // EVERY bank (active + inactive) for the rebalance wizard — a reset that skipped the
            // inactive ones would leave their stale balances inside $sumBalances and re-open the gap.
            foreach ($allBanks as $bank) {
                $calc = $byBank[(int) $bank->id] ?? ['net' => 0.0, 'balance' => (float) $bank->opening_balance];
                $allBankRows[] = [
                    'id' => $bank->id, 'name' => $bank->name, 'short_code' => $bank->short_code,
                    'color_hex' => $bank->color_hex ?: '#3B82F6', 'is_active' => (bool) $bank->is_active,
                    'balance' => (float) $calc['balance'],
                ];
            }
            // Manual fixes must be counted the SAME way balancesByBank() counts them — i.e. only
            // those on/after each bank's opening_balance_date. Summing the whole table (the old
            // behaviour) was harmless while every bank had a NULL opening date, but the moment a
            // rebalance sets one it would subtract fixes that no longer feed any bank balance and
            // report a phantom gap.
            $netManualAdjustments = 0.0;
            foreach ($byBank as $b) {
                $netManualAdjustments += (float) ($b['adjustments'] ?? 0);
            }
            // ...minus the ones the rebalance already neutralised. A fix made earlier on the reset
            // day was subtracted when the baseline was formed, so it no longer pushes the bank away
            // from the pool — subtracting it here as well invented a fix-sized gap out of nothing.
            $netManualAdjustments = round($netManualAdjustments - $this->bakedInFixes(), 2);

            // Has a baseline ever been declared? Until it has, every figure on this screen mixes in
            // years of untracked history — the recon formula and the untagged pile are legacy noise,
            // not actionable money, and the view presents them as ONE calm "set up" notice instead.
            $isBaselined = (bool) \App\Models\FIN\ConfigModel::get('bank_rebalance_last_at', null)
                || $allBanks->contains(fn ($b) => $b->opening_balance_date !== null);
        }

        // Untagged "No bank" bucket — scoped (qurbani vs non-qurbani).
        [$unassigned, $unassignedCount] = $this->scopedUntagged($onlineIds, $qfMode);

        $reconGap = round(($sumBalances + $unassigned) - $ledgerOnlineBalance - $netManualAdjustments, 2);
        $reconStatus = abs($reconGap) < 1000 ? 'green' : (abs($reconGap) < 100000 ? 'amber' : 'red');

        $isTaimur = $this->isTaimur();

        // Combined statement of every online movement, tagged and untagged, so the tab explains
        // itself without drilling into a bank first.
        $days = (int) $request->get('days', 30);
        if (!in_array($days, [0, 30, 90, 365], true)) {
            $days = 30;
        }
        $feed = $this->buildOnlineFeed($onlineIds, $qfMode, $days);

        // What the No-bank bucket would still hold immediately after a reset run today — money that
        // is real and in the pool, so the wizard has to show it rather than pretend it disappears.
        $untaggedToday = 0.0;
        if (!$isQ && $isTaimur) {
            [$untaggedToday] = $this->scopedUntagged($onlineIds, $qfMode, now()->toDateString());
        }

        return view('fin.hub.banks', [
            'active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'isQ' => $isQ, 'pendingActions' => $pendingActions,
            'banks' => $rows, 'status' => $status, 'activeCount' => $activeCount, 'inactiveCount' => $inactiveCount,
            'sumBalances' => $sumBalances, 'unassigned' => $unassigned, 'unassignedCount' => $unassignedCount,
            'unassignedSince' => $balances->unassignedSince(),
            'onlineAccounts' => $onlineAccounts, 'ledgerOnlineBalance' => $ledgerOnlineBalance,
            'netManualAdjustments' => $netManualAdjustments, 'reconGap' => $reconGap, 'reconStatus' => $reconStatus,
            'isTaimur' => $isTaimur, 'isBaselined' => $isBaselined,
            'bankTransfersReady' => $this->bankTransfersReady(),
            'allBankRows' => $allBankRows,
            'rebalancedAt' => \App\Models\FIN\ConfigModel::get('bank_rebalance_last_at', null),
            'rebalancedBy' => \App\Models\FIN\ConfigModel::get('bank_rebalance_last_by', null),
            'feed' => $feed, 'days' => $days, 'untaggedToday' => round((float) $untaggedToday, 2),
        ]);
    }

    /**
     * Day-grouped feed of every online movement in the window — the Banks tab's default view.
     *
     * Deliberately REAL money only: attribution-layer ⚖ fixes are excluded here because they move
     * a bank's tracked number without moving the pool, so mixing them in would make the In/Out
     * totals lie about what the pool did. They remain visible on each bank's own statement.
     *
     * Rows whose BOTH sides are online chart accounts (e.g. ONLINE -> QURBANI_ONLINE) are the same
     * physical money and net to zero — shown as 'neutral', same rule BankBalanceService uses.
     */
    private function buildOnlineFeed(array $onlineIds, string $qfMode, int $days): array
    {
        $empty = ['groups' => [], 'count' => 0, 'truncated' => false, 'total_in' => 0.0, 'total_out' => 0.0];
        if (empty($onlineIds)) {
            return $empty;
        }

        $limit = 1000;
        $q = LedgerModel::with('receivingAccount')
            ->whereIn('approval_status', \App\Services\FIN\BankBalanceService::APPROVED_STATUSES)
            ->where(function ($w) use ($onlineIds) {
                $w->whereIn('to_account_id', $onlineIds)->orWhereIn('from_account_id', $onlineIds);
            });
        \App\Services\QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', $qfMode);
        if ($days > 0) {
            $q->whereDate('transaction_date', '>=', now()->subDays($days)->toDateString());
        }
        // Fetch one extra to detect truncation without a second COUNT query.
        $rows = $q->orderByDesc('transaction_date')->orderByDesc('id')->limit($limit + 1)->get();
        $truncated = $rows->count() > $limit;
        if ($truncated) {
            $rows = $rows->take($limit);
        }
        if ($rows->isEmpty()) {
            return $empty;
        }

        // Counterparty names for the types where the other side is who got paid.
        $cpTypes = [LedgerModel::TYPE_VENDOR_PAYMENT, LedgerModel::TYPE_SALARY_ADVANCE, LedgerModel::TYPE_SALARY_PAYMENT];
        $cpIds = $rows->filter(fn ($r) => in_array($r->transaction_type, $cpTypes, true))
            ->pluck('to_account_id')->filter()->unique();
        $cpNames = $cpIds->isNotEmpty() ? AccountModel::whereIn('id', $cpIds->all())->pluck('account_name', 'id') : collect();

        $groups = [];
        $totalIn = 0.0;
        $totalOut = 0.0;
        foreach ($rows as $r) {
            $toOnline = in_array((int) $r->to_account_id, $onlineIds, true);
            $fromOnline = in_array((int) $r->from_account_id, $onlineIds, true);
            $dir = ($toOnline && !$fromOnline) ? 'in' : (($fromOnline && !$toOnline) ? 'out' : 'neutral');
            $amount = (float) $r->amount;
            if ($dir === 'in') {
                $totalIn += $amount;
            } elseif ($dir === 'out') {
                $totalOut += $amount;
            }

            $bank = $r->receivingAccount;
            $d = optional($r->transaction_date)->toDateString() ?: 'unknown';
            if (!isset($groups[$d])) {
                $groups[$d] = ['date' => $d, 'in' => 0.0, 'out' => 0.0, 'items' => []];
            }
            if ($dir === 'in') {
                $groups[$d]['in'] += $amount;
            } elseif ($dir === 'out') {
                $groups[$d]['out'] += $amount;
            }
            $groups[$d]['items'][] = [
                'id' => $r->id,
                'date' => $d,
                'type' => $r->transaction_type,
                'description' => $r->description,
                'counterparty' => in_array($r->transaction_type, $cpTypes, true) ? ($cpNames[(int) $r->to_account_id] ?? null) : null,
                'amount' => $amount,
                'direction' => $dir,
                'bank_name' => $bank ? ($bank->short_code ?: $bank->name) : null,
                'bank_id' => $bank ? (int) $bank->id : null,
                'bank_color' => $bank ? ($bank->color_hex ?: '#3B82F6') : null,
            ];
        }

        return [
            'groups' => array_values($groups),
            'count' => $rows->count(),
            'truncated' => $truncated,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
        ];
    }

    /**
     * One-time (repeatable) rebalance: declare the true online pool and how it is spread across the
     * physical banks, then start tracking cleanly from that date.
     *
     * MECHANISM — no new tables, and nothing is deleted:
     *  - each bank's opening_balance / opening_balance_date become its baseline and today's date.
     *    BankBalanceService already ignores tagged movements AND manual fixes dated before a bank's
     *    opening date, so the whole pre-reset mess stops counting by itself;
     *  - bank_unassigned_tracking_since moves to the same date, so the "No bank" bucket restarts too;
     *  - if the declared pool differs from the ONLINE ledger balance, ONE equity adjustment is posted
     *    (via ExternalFundsService) dated the day BEFORE, so the correcting row itself can never
     *    appear inside any bank's post-reset statement.
     *
     * TWO SUBTLETIES THE ARITHMETIC HAS TO RESPECT (both found by testing):
     *  1. The cut-off is inclusive (transaction_date >= opening date) — it has to be, or rows posted
     *     later TODAY would move the pool while being invisible to every bank. So the figure the
     *     owner types (his statement balance right now) already CONTAINS today's recorded movements,
     *     and the stored baseline must be that figure MINUS them, or they count twice.
     *  2. Money already recorded today with no bank tag is real and sits in the pool, so it cannot
     *     just vanish at reset. It is surfaced as its own line and must be included in the total —
     *     which also nudges the owner to go and tag it.
     * Result: tracked banks + untagged == pool, by construction, and it stays that way.
     */
    public function rebalance(Request $request, \App\Services\FIN\BankBalanceService $balances, \App\Services\FIN\ExternalFundsService $funds)
    {
        if ($resp = $this->requireTaimurJson('rebalance the banks')) {
            return $resp;
        }

        $validated = $request->validate([
            'target_pool' => 'required|numeric',
            'allocations' => 'required|array|min:1',
            'allocations.*' => 'required|numeric',
            'note' => 'nullable|string|max:255',
        ]);

        // Deliberately not user-selectable: a backdated reset would need balances "as of" that past
        // date and would let every row in between land on top of them. "As of now" is the only
        // version an owner can actually verify against his banking app.
        $resetDate = now()->toDateString();
        $targetPool = round((float) $validated['target_pool'], 2);

        // Every bank must be accounted for — a missing one would keep its stale tracked balance
        // inside the tracked total and re-open the very gap this is closing.
        $allBanks = \App\Models\FIN\OnlineReceivingAccountModel::ordered()->get();
        $submitted = collect($validated['allocations'])->keys()->map(fn ($k) => (int) $k)->sort()->values();
        $expected = $allBanks->pluck('id')->map(fn ($v) => (int) $v)->sort()->values();
        if ($submitted->all() !== $expected->all()) {
            return response()->json([
                'success' => false,
                'message' => 'Every bank must be given a figure (0 is fine). Reload the page and try again.',
            ], 422);
        }

        $allocTotal = 0.0;
        foreach ($validated['allocations'] as $amt) {
            $allocTotal += round((float) $amt, 2);
        }
        $allocTotal = round($allocTotal, 2);

        // Untagged money already recorded today stays untagged after the reset (see subtlety 2).
        $onlineIds = $balances->onlineAccountIds();
        [$untaggedToday] = $this->scopedUntagged($onlineIds, \App\Services\QurbaniFinanceFilter::MODE_EXCLUDE, $resetDate);
        $untaggedToday = round((float) $untaggedToday, 2);

        $declared = round($allocTotal + $untaggedToday, 2);
        if (abs($declared - $targetPool) >= 0.5) {
            return response()->json([
                'success' => false,
                'message' => 'The banks add up to Rs. ' . number_format($allocTotal, 2)
                    . ($untaggedToday != 0.0 ? ' plus Rs. ' . number_format($untaggedToday, 2) . ' not yet tagged to a bank' : '')
                    . ', which is Rs. ' . number_format($declared, 2)
                    . ' — but the online total says Rs. ' . number_format($targetPool, 2) . '. They must match.',
            ], 422);
        }
        // Sub-rupee typing slack: owners type whole rupees while the ledger keeps paisa (the typed
        // total is often the display-rounded version of e.g. 100,913.53). The per-bank spread plus
        // today's untagged money is the authoritative declaration — snap the pool to it, so the
        // books end exact to the paisa instead of carrying a permanent few-paisa gap.
        $targetPool = $declared;

        // The operational pool = the non-qurbani online chart account(s). Qurbani keeps its own book
        // and is never distributed across physical banks, so it is out of scope here.
        $poolAccounts = AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->where('account_code', 'NOT LIKE', 'QURBANI%')
            ->get();
        if ($poolAccounts->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No online account found to rebalance.'], 422);
        }
        $currentPool = round((float) $poolAccounts->sum('current_balance'), 2);
        $delta = round($targetPool - $currentPool, 2);

        // What is already on the books for today, per bank — subtracted from the typed figure so the
        // day's movements are not counted twice (subtlety 1).
        $netsToday = $balances->netsSince($resetDate);

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();

            // 1. Correct the pool itself, if it is wrong. Dated the day BEFORE the reset so it sits
            //    outside every bank's post-reset window and outside the No-bank tracking floor.
            $correctionId = null;
            if (abs($delta) >= 0.01) {
                $target = $poolAccounts->firstWhere('account_code', 'ONLINE') ?: $poolAccounts->first();
                $note = trim((string) ($validated['note'] ?? '')) ?: 'bank rebalance';
                $row = $funds->post(
                    $target,
                    abs($delta),
                    $delta > 0 ? \App\Services\FIN\ExternalFundsService::DIR_IN : \App\Services\FIN\ExternalFundsService::DIR_OUT,
                    \Carbon\Carbon::parse($resetDate)->subDay()->toDateString(),
                    'Pool correction before rebalance — ' . $note,
                    null,
                    null,
                    true // deliberately untagged: it is pre-reset, so no bank should ever see it
                );
                $correctionId = $row->id;
            }

            // 2. Baseline every bank: the figure typed, minus what today already contributes to it.
            foreach ($allBanks as $bank) {
                $entered = round((float) $validated['allocations'][$bank->id], 2);
                $bank->opening_balance = round($entered - (float) ($netsToday[(int) $bank->id] ?? 0), 2);
                $bank->opening_balance_date = $resetDate;
                $bank->save();
            }

            // 3. Restart the No-bank bucket from the same date.
            \App\Models\FIN\ConfigModel::set('bank_unassigned_tracking_since', $resetDate,
                'Count untagged online rows only on/after this date (set by the Ledger Hub rebalance).');
            \App\Models\FIN\ConfigModel::set('bank_rebalance_last_at', $resetDate, 'Date of the last bank rebalance.');
            // The MOMENT, not just the day. Rows already on the books when the figures were typed are
            // baked into them (opening = typed − netsToday); the statement uses this to show those
            // rows as already-included instead of pretending they happened after the reset.
            \App\Models\FIN\ConfigModel::set('bank_rebalance_last_ts', now()->toDateTimeString(),
                'Exact time of the last bank rebalance (splits same-day rows before/after it).');
            \App\Models\FIN\ConfigModel::set('bank_rebalance_last_by',
                (string) (auth()->user()->fullname ?? ('user #' . auth()->id())),
                'Who ran the last bank rebalance.');

            \Illuminate\Support\Facades\DB::commit();
        } catch (\InvalidArgumentException $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Log::error('Ledger Hub rebalance failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Could not rebalance. Nothing was saved.'], 500);
        }

        \Log::info('Ledger Hub bank rebalance', [
            'by' => auth()->id(), 'reset_date' => $resetDate, 'target_pool' => $targetPool,
            'previous_pool' => $currentPool, 'correction_ledger_id' => $correctionId,
            'untagged_today' => $untaggedToday, 'nets_today' => $netsToday,
            'allocations' => $validated['allocations'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Banks rebalanced. Tracking starts from ' . \Carbon\Carbon::parse($resetDate)->format('M d, Y') . '.',
            'pool_corrected_by' => $delta,
        ]);
    }

    /**
     * Untagged online net + row count for a scope (qurbani vs non-qurbani), honouring the No-bank
     * tracking-since floor. Net = money into an online account − money out (both untagged).
     */
    private function scopedUntagged(array $onlineIds, string $qfMode, ?string $floorOverride = null): array
    {
        $since = $floorOverride ?? \App\Models\FIN\ConfigModel::get('bank_unassigned_tracking_since', null);
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
     * The openable record behind one statement row.
     *
     * Ledger rows link out to the full transaction page. Attribution rows (⚖ fix, ⇄ transfer leg,
     * ⟲ reset) have no ledger entry to link to — this record is the whole story for them, which is
     * why it carries the balance either side of the row and states, in words, whether the online
     * total moved.
     */
    private function statementRecord(array $it, array $bank, bool $isUnassigned, ?string $resetBy): ?array
    {
        // Pre-reset rows don't act on the balance, so they have no before/after to show.
        if (!empty($it['is_pre'])) {
            return null;
        }

        $money = fn ($n) => 'Rs. ' . number_format((float) $n, 2);
        $bankName = $bank['name'] ?? '—';
        $type = $it['type'] ?? '';
        $isXfer = $type === 'bank_transfer';
        $isFix = !empty($it['is_adjustment']) && !$isXfer;
        $isReset = !empty($it['is_reset']);
        $isIn = ($it['direction'] ?? '') === 'in';

        $rec = [
            'amount' => $money($it['amount'] ?? 0),
            'dir' => $it['direction'] ?? 'neutral',
            // On a bank's own statement every row belongs to that bank — naming it keeps the
            // drawer consistent with the same row opened from the Overview.
            'bank' => $isUnassigned ? null : ($bank['short_code'] ?? $bank['name'] ?? null),
            'sub' => trim((string) ($it['description'] ?? '')) ?: '—',
            'date' => !empty($it['date']) ? \Carbon\Carbon::parse($it['date'])->format('M d, Y') : '—',
            // When it was actually typed into the system — distinct from the transaction date,
            // which can be backdated.
            'entered' => !empty($it['created_at'])
                ? \Carbon\Carbon::parse($it['created_at'])->format('M d, Y · g:i A') : null,
            'by' => ($it['by'] ?? null) ?: '—',
            'from' => '—', 'fromsub' => '', 'to' => '—', 'tosub' => '',
            'statusLabel' => 'Recorded',
            'url' => null, 'pending' => false,
            // Balance either side of this row — the "how did the balance change" answer.
            'before' => isset($it['running_before']) ? $money($it['running_before']) : null,
            'after' => isset($it['running']) && $it['running'] !== null ? $money($it['running']) : null,
        ];

        if ($isReset) {
            return array_merge($rec, [
                'title' => '⟲ Balance reset',
                'mode' => $isUnassigned
                    ? 'Tracking restarted — untagged money counted from here'
                    : 'Baseline — clean tracking starts here',
                'to' => $isUnassigned ? 'No bank' : $bankName,
                'tosub' => 'declared balance',
                'statusLabel' => 'Baseline set',
                'by' => $resetBy ?: '—',
                'before' => null,
                'dir' => 'neutral',
            ]);
        }

        if ($isXfer) {
            $partner = $it['partner_bank'] ?? null;
            return array_merge($rec, [
                'title' => '⇄ Bank transfer',
                'mode' => 'Between our own banks — the online total did not change',
                'from' => $isIn ? ($partner ?: 'another bank') : $bankName,
                'fromsub' => $isIn ? 'sent from' : 'sent',
                'to' => $isIn ? $bankName : ($partner ?: 'another bank'),
                'tosub' => $isIn ? 'received' : 'received by',
                'statusLabel' => 'Moved between banks',
            ]);
        }

        if ($isFix) {
            return array_merge($rec, [
                'title' => '⚖ Balance fix',
                'mode' => 'Attribution only — no ledger entry, the online total did not change',
                'from' => $isIn ? 'correction' : $bankName,
                'to' => $isIn ? $bankName : 'correction',
                'statusLabel' => 'Manual correction',
            ]);
        }

        // Ordinary ledger movement — real money in or out of the pool, linkable to its full page.
        $status = (string) ($it['status'] ?? '');
        $pending = $status === LedgerModel::STATUS_PENDING_L2;

        return array_merge($rec, [
            'title' => ucfirst(str_replace('_', ' ', $type ?: 'transaction')),
            'mode' => $isIn ? 'Money into this bank' : (($it['direction'] ?? '') === 'out' ? 'Money out of this bank' : 'Between online accounts'),
            'from' => $isIn ? ($it['counterparty'] ?? '—') : $bankName,
            'to' => $isIn ? $bankName : ($it['counterparty'] ?? '—'),
            'tosub' => $isIn ? ($bank['short_code'] ?? '') : '',
            'fromsub' => $isIn ? '' : ($bank['short_code'] ?? ''),
            'statusLabel' => $pending ? 'Approved (L1) · awaiting L2' : 'Approved',
            'pending' => $pending,
            'url' => (!$isUnassigned || !empty($it['id'])) && !empty($it['id'])
                ? route('fin.ledger.show', $it['id']) : null,
        ]);
    }

    /**
     * Is the transfer_group column present? The pairing is what makes a bank-to-bank transfer safe,
     * so the feature stays switched off (button hidden, endpoint refuses) until the SQL has run —
     * rather than writing unlinked halves that a single delete could break apart.
     */
    private function bankTransfersReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $ready = \Illuminate\Support\Facades\Schema::hasColumn('t_fin_bank_balance_adjustment', 'transfer_group');
            } catch (\Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * ⇄ Move money between two of OUR banks.
     *
     * The online pool does not move — only its split does — so this is deliberately NOT a ledger
     * transaction. It is a matched pair of attribution rows (−X source, +X destination) sharing a
     * transfer_group. They sum to zero, so `sumBalances`, `netManualAdjustments`, the pool and the
     * reconciliation all come out exactly where they started.
     */
    public function bankTransfer(Request $request)
    {
        if (!$this->isTaimur()) {
            return response()->json(['success' => false, 'message' => 'Only the Taimur role can move money between banks.'], 403);
        }
        if (!$this->bankTransfersReady()) {
            return response()->json([
                'success' => false,
                'message' => 'Bank transfers need one small database update first '
                    . '(database/migrations/bank_transfer_group_jul2026.sql). Ask for it to be run, then try again.',
            ], 422);
        }

        $validated = $request->validate([
            'from_bank_id' => 'required|integer|exists:t_fin_online_receiving_accounts,id',
            'to_bank_id' => 'required|integer|different:from_bank_id|exists:t_fin_online_receiving_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'transfer_date' => 'required|date|before_or_equal:today',
            'note' => 'nullable|string|max:180',
        ], [
            'to_bank_id.different' => 'Pick two different banks.',
            'transfer_date.before_or_equal' => 'The transfer date cannot be in the future.',
        ]);

        $from = \App\Models\FIN\OnlineReceivingAccountModel::findOrFail($validated['from_bank_id']);
        $to   = \App\Models\FIN\OnlineReceivingAccountModel::findOrFail($validated['to_bank_id']);
        $date = \Carbon\Carbon::parse($validated['transfer_date'])->toDateString();
        $amount = round((float) $validated['amount'], 2);

        // A leg dated before its bank's baseline is ignored for that bank (BankBalanceService filters
        // on opening_balance_date) — the pair would stop summing to zero and the tracked total would
        // drift from the pool. Refuse rather than silently half-apply.
        foreach ([$from, $to] as $bank) {
            $opened = optional($bank->opening_balance_date)->toDateString();
            if ($opened && $date < $opened) {
                return response()->json([
                    'success' => false,
                    'message' => $bank->name . ' was rebalanced on ' . \Carbon\Carbon::parse($opened)->format('M d, Y')
                        . '. Use a date on or after that, or only one side of this transfer would count.',
                ], 422);
            }
        }

        $group = (string) \Illuminate\Support\Str::uuid();
        $label = trim((string) ($validated['note'] ?? ''));
        $suffix = $label !== '' ? ' — ' . $label : '';

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($from, $to, $amount, $date, $group, $suffix) {
                \App\Models\FIN\BankBalanceAdjustmentModel::create([
                    'receiving_account_id' => $from->id,
                    'amount' => -$amount,
                    'adjustment_date' => $date,
                    'note' => 'Transfer to ' . $to->name . $suffix,
                    'transfer_group' => $group,
                    'created_by' => auth()->id(),
                ]);
                \App\Models\FIN\BankBalanceAdjustmentModel::create([
                    'receiving_account_id' => $to->id,
                    'amount' => $amount,
                    'adjustment_date' => $date,
                    'note' => 'Transfer from ' . $from->name . $suffix,
                    'transfer_group' => $group,
                    'created_by' => auth()->id(),
                ]);
            });
        } catch (\Throwable $e) {
            \Log::error('Ledger Hub bank transfer failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Could not record the transfer. Nothing was saved.'], 500);
        }

        \Log::info('Ledger Hub bank transfer', [
            'by' => auth()->id(), 'from' => $from->id, 'to' => $to->id,
            'amount' => $amount, 'date' => $date, 'group' => $group,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Moved Rs. ' . number_format($amount, 2) . ' from ' . $from->name . ' to ' . $to->name . '.',
        ]);
    }

    /**
     * Manual ⚖ fixes that the last rebalance absorbed into the baselines — i.e. dated on the reset
     * date and recorded before the rebalance ran, for banks still carrying that reset as their
     * baseline. They cancel out of a bank's balance, so the reconciliation must not count them as
     * live attribution-only drift.
     */
    private function bakedInFixes(): float
    {
        $ts = \App\Models\FIN\ConfigModel::get('bank_rebalance_last_ts', null);
        if (!$ts) {
            return 0.0;
        }
        $resetDate = substr((string) $ts, 0, 10);

        try {
            return (float) (\Illuminate\Support\Facades\DB::selectOne(
                "SELECT COALESCE(SUM(a.amount), 0) AS n
                   FROM t_fin_bank_balance_adjustment a
                   JOIN t_fin_online_receiving_accounts b ON b.id = a.receiving_account_id
                  WHERE a.adjustment_date = ?
                    AND a.created_at IS NOT NULL AND a.created_at < ?
                    AND b.opening_balance_date = ?",
                [$resetDate, (string) $ts, $resetDate]
            )->n ?? 0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Net effect on ONE bank of the rows that were already recorded on the reset date BEFORE the
     * rebalance moment — i.e. exactly the amount the typed figure already contained, which the
     * rebalance subtracted to form the stored baseline.
     *
     * Mirrors BankBalanceService's direction rule (both sides online = same money = 0) and counts
     * manual ⚖ fixes too, because they feed a bank's balance the same way tagged movements do.
     */
    private function sameDayPreResetNet(int $bankId, string $resetDate, string $resetMoment, array $onlineIds): float
    {
        if (empty($onlineIds)) {
            return 0.0;
        }
        $in = implode(',', array_map('intval', $onlineIds));
        $statuses = "'" . implode("','", \App\Services\FIN\BankBalanceService::APPROVED_STATUSES) . "'";

        $net = (float) (\Illuminate\Support\Facades\DB::selectOne(
            "SELECT COALESCE(SUM(CASE WHEN l.to_account_id IN ($in) AND l.from_account_id IN ($in) THEN 0
                                      WHEN l.to_account_id IN ($in) THEN l.amount
                                      WHEN l.from_account_id IN ($in) THEN -l.amount
                                      ELSE 0 END), 0) AS net
             FROM t_fin_ledger l
             WHERE l.receiving_account_id = ?
               AND l.approval_status IN ($statuses)
               AND l.transaction_date = ?
               AND l.created_at IS NOT NULL AND l.created_at < ?",
            [$bankId, $resetDate, $resetMoment]
        )->net ?? 0);

        try {
            $net += (float) (\Illuminate\Support\Facades\DB::selectOne(
                "SELECT COALESCE(SUM(amount), 0) AS net
                 FROM t_fin_bank_balance_adjustment
                 WHERE receiving_account_id = ? AND adjustment_date = ?
                   AND created_at IS NOT NULL AND created_at < ?",
                [$bankId, $resetDate, $resetMoment]
            )->net ?? 0);
        } catch (\Throwable $e) {
            // Table not created yet — nothing to add.
        }

        return $net;
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
        // Rows before the bank's reset baseline don't count towards its balance (BankBalanceService
        // filters them out), so showing them with a running balance would be a lie. They are hidden
        // by default and revealed read-only via ?history=1 — nothing is ever actually lost.
        $showHistory = $request->boolean('history');

        // Set for a real bank whose baseline came from a rebalance: the exact moment it was declared,
        // and the figure that was typed. Rows already on the books at that moment are baked INTO the
        // typed figure (opening = typed − netsToday), so the statement must show them as already
        // included rather than as movements that happened after the reset.
        $resetMoment = null;
        $declaredAtReset = null;

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
            // The bucket's baseline is "nothing, from the tracking date onward".
            $resetDate = $cfgSince ? substr((string) $cfgSince, 0, 10) : null;
            $resetAmount = 0.0;
            $baseUntagged = function () use ($onlineIds, $qfMode) {
                $q = LedgerModel::whereNull('receiving_account_id')
                    ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2])
                    ->where(fn ($w) => $w->whereIn('to_account_id', $onlineIds)->orWhereIn('from_account_id', $onlineIds));
                \App\Services\QurbaniFinanceFilter::applyToLedgerQuery($q, 't_fin_ledger', $qfMode);
                return $q;
            };
            $q = $baseUntagged();
            if ($resetDate && !$showHistory) $q->whereDate('transaction_date', '>=', $resetDate);
            if ($since) $q->whereDate('transaction_date', '>=', $since);
            $rows = $q->orderByDesc('transaction_date')->orderByDesc('id')->limit(1000)->get();
            $adjRows = collect();
            $preCount = ($resetDate && !$showHistory)
                ? (clone $baseUntagged())->whereDate('transaction_date', '<', $resetDate)->count()
                : 0;
        } else {
            $model = \App\Models\FIN\OnlineReceivingAccountModel::findOrFail($id);
            $byBank = $balances->balancesByBank();
            $calc = $byBank[(int) $model->id] ?? ['net' => 0.0, 'balance' => (float) $model->opening_balance];
            $bank = ['id' => $model->id, 'name' => $model->name, 'short_code' => $model->short_code,
                     'color_hex' => $model->color_hex ?: '#3B82F6', 'account_last4' => $model->account_last4,
                     'is_active' => (bool) $model->is_active];
            $balance = (float) $calc['balance'];
            // Movement must account for EVERYTHING that moved this bank — tagged ledger rows and
            // attribution rows (⚖ fixes, ⇄ transfer legs) alike — or "opening + movement" stops
            // equalling the balance on screen. A 20k transfer out made that gap plainly visible.
            $net = (float) $calc['net'] + (float) ($calc['adjustments'] ?? 0);
            $resetDate = optional($model->opening_balance_date)->toDateString();
            $resetAmount = (float) $model->opening_balance;

            // The declared figure = baseline + whatever was already recorded that day before the
            // rebalance ran (that is precisely what the baseline was reduced by). Computed from its
            // own query, not from the visible window, so it stays right at every period setting.
            $ts = \App\Models\FIN\ConfigModel::get('bank_rebalance_last_ts', null);
            if ($resetDate && $ts && substr((string) $ts, 0, 10) === $resetDate) {
                $resetMoment = (string) $ts;
                $declaredAtReset = round($resetAmount + $this->sameDayPreResetNet($model->id, $resetDate, $resetMoment, $onlineIds), 2);
            }
            $opening = ['amount' => $declaredAtReset ?? $resetAmount, 'date' => $resetDate];
            $q = LedgerModel::with('createdBy')
                ->where('receiving_account_id', $model->id)
                ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2]);
            if ($resetDate && !$showHistory) $q->whereDate('transaction_date', '>=', $resetDate);
            if ($since) $q->whereDate('transaction_date', '>=', $since);
            $rows = $q->orderByDesc('transaction_date')->orderByDesc('id')->limit(1000)->get();
            $adjRows = collect();
            $preCount = 0;
            if ($resetDate && !$showHistory) {
                $preCount = LedgerModel::where('receiving_account_id', $model->id)
                    ->whereIn('approval_status', [LedgerModel::STATUS_APPROVED, LedgerModel::STATUS_PENDING_L2])
                    ->whereDate('transaction_date', '<', $resetDate)->count();
            }
            try {
                $aq = \App\Models\FIN\BankBalanceAdjustmentModel::where('receiving_account_id', $model->id);
                if ($resetDate && !$showHistory) $aq->whereDate('adjustment_date', '>=', $resetDate);
                if ($since) $aq->whereDate('adjustment_date', '>=', $since);
                $adjRows = $aq->orderByDesc('adjustment_date')->limit(200)->get();
                if ($resetDate && !$showHistory) {
                    $preCount += \App\Models\FIN\BankBalanceAdjustmentModel::where('receiving_account_id', $model->id)
                        ->whereDate('adjustment_date', '<', $resetDate)->count();
                }
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
                'amount' => (float) $r->amount, 'direction' => $dir, 'is_adjustment' => false, 'is_reset' => false,
                'created_at' => optional($r->created_at)->format('Y-m-d H:i:s'),
                'by' => optional($r->createdBy)->fullname,
                'status' => $r->approval_status,
                'signed' => $dir === 'in' ? (float) $r->amount : ($dir === 'out' ? -(float) $r->amount : 0.0),
            ];
        });

        // Who recorded each attribution row, and — for a ⇄ transfer leg — which bank sits on the
        // other side of it. Both are needed to answer "what actually happened here?" without a
        // ledger entry to fall back on.
        $adjBy = $adjRows->pluck('created_by')->filter()->unique();
        $adjByNames = $adjBy->isNotEmpty()
            ? \App\Models\SysAdmin\UserModel::whereIn('id', $adjBy->all())->pluck('fullname', 'id')
            : collect();
        // NB: built as a plain array, keyed by leg id. Collection::flatMap() collapses with
        // array_merge, which RENUMBERS integer keys — every partner lookup then missed and the
        // drawer said "another bank" instead of naming it.
        $xferPartner = [];
        $groups2 = $adjRows->pluck('transfer_group')->filter()->unique();
        if ($groups2->isNotEmpty()) {
            try {
                $legs = \App\Models\FIN\BankBalanceAdjustmentModel::whereIn('transfer_group', $groups2->all())
                    ->get(['id', 'receiving_account_id', 'transfer_group']);
                $names = \App\Models\FIN\OnlineReceivingAccountModel::whereIn(
                    'id', $legs->pluck('receiving_account_id')->unique()->all()
                )->pluck('name', 'id');
                foreach ($legs->groupBy('transfer_group') as $grp) {
                    foreach ($grp as $leg) {
                        $other = $grp->first(fn ($l) => (int) $l->id !== (int) $leg->id);
                        $xferPartner[(int) $leg->id] = $other
                            ? ($names[(int) $other->receiving_account_id] ?? null) : null;
                    }
                }
            } catch (\Throwable $e) { /* column may not exist yet */ }
        }
        // A bank-to-bank transfer leg is an adjustment mechanically, but it is NOT a correction —
        // labelling it "⚖ Adjustment" would read as though someone had overridden the balance.
        $adjItems = $adjRows->map(fn ($a) => [
            'id' => $a->id, 'date' => optional($a->adjustment_date)->toDateString(),
            'type' => ($a->transfer_group ?? null) ? 'bank_transfer' : 'adjustment',
            'description' => $a->note ?: 'Manual balance fix', 'counterparty' => null,
            'amount' => abs((float) $a->amount), 'direction' => (float) $a->amount >= 0 ? 'in' : 'out',
            'is_adjustment' => true, 'is_reset' => false,
            'created_at' => optional($a->created_at)->format('Y-m-d H:i:s'),
            'by' => $adjByNames[(int) $a->created_by] ?? null,
            'partner_bank' => $xferPartner[(int) $a->id] ?? null,
            'signed' => (float) $a->amount,
        ]);
        // Newest on top by WHEN IT WAS RECORDED, not just by date: date-only sorting left same-day
        // order to concat position (ledger rows first, then fixes/transfers), so a transfer made
        // after an advance could render below it — and the running column would then narrate the
        // day's balances in the wrong order. Day groups stay keyed by transaction date; created_at
        // only decides the order INSIDE a day. Null created_at (ancient rows) sorts oldest.
        $all = $items->concat($adjItems)->sort(function ($a, $b) {
            $byDate = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            if ($byDate !== 0) {
                return $byDate;
            }
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        })->values();

        // Split at the reset baseline. Only post-reset rows build the balance the bank actually
        // shows; pre-reset rows (visible only with ?history=1) are context, never arithmetic.
        $post = [];
        $pre = [];
        foreach ($all as $it) {
            $isPre = false;
            $sameDay = false;
            if ($resetDate && $it['date']) {
                if ($it['date'] < $resetDate) {
                    $isPre = true;
                } elseif ($resetMoment && $it['date'] === $resetDate
                          && !empty($it['created_at']) && $it['created_at'] < $resetMoment) {
                    // Recorded earlier the same day, before the figures were typed — already inside
                    // the declared balance, so it must not read as movement since the reset.
                    $isPre = true;
                    $sameDay = true;
                }
            }
            if ($isPre) {
                $it['running'] = null;
                $it['is_pre'] = true;
                $it['pre_same_day'] = $sameDay;
                $pre[] = $it;
            } else {
                $it['is_pre'] = false;
                $it['pre_same_day'] = false;
                $post[] = $it;
            }
        }

        // Running balance backward from the current balance (newest row = current balance). Because
        // balance = opening + post-reset movement, walking back across every post-reset row lands
        // exactly on the opening figure at the reset row — a built-in integrity check.
        $running = $balance;
        $withRun = [];
        foreach ($post as $it) {
            $it['running'] = $running;          // balance after this row
            $running -= $it['signed'];
            $it['running_before'] = $running;   // ...and before it, so a row can show its own effect
            $withRun[] = $it;
        }

        // The reset marker only belongs on screen when the window actually reaches back to it —
        // otherwise its running balance wouldn't line up with the rows above and would read as a bug.
        $showResetRow = $resetDate && ($since === null || $since <= $resetDate);
        if ($showResetRow) {
            // Anchor on the walk's own result: with same-day pre-reset rows excluded above, it lands
            // exactly on the declared figure, so the Balance column stays arithmetically continuous
            // whatever the classification. (Pre-timestamp resets have no split, so it lands on the
            // stored baseline instead — the old behaviour, unchanged.)
            $anchor = round($running, 2);
            $withRun[] = [
                'id' => null, 'date' => $resetDate, 'type' => 'reset',
                'description' => $isUnassigned
                    ? 'Tracking restarted — untagged money counted from here'
                    : ($declaredAtReset !== null
                        ? 'Balance reset — declared ' . number_format($anchor, 2)
                        : 'Balance reset — opening ' . number_format($anchor, 2)),
                'counterparty' => null, 'amount' => $anchor, 'direction' => 'neutral',
                'is_adjustment' => false, 'is_reset' => true, 'is_pre' => false, 'pre_same_day' => false,
                'created_at' => $resetMoment, // null for pre-timestamp resets — row simply shows no time
                'signed' => 0.0,
                'running' => $anchor,
            ];
        }
        foreach ($pre as $it) {
            $withRun[] = $it;
        }

        // Every row gets an openable record. Attribution rows have no ledger entry behind them, so
        // this IS their audit trail: what happened, which banks, who recorded it, and the balance
        // either side of it.
        $resetBy = \App\Models\FIN\ConfigModel::get('bank_rebalance_last_by', null);
        foreach ($withRun as &$it) {
            $it['drawer'] = $this->statementRecord($it, $bank, $isUnassigned, $resetBy);
        }
        unset($it);

        // Day groups (already DESC). Counted and historic sums are kept apart so a pre-reset day
        // never claims "In 0 · Out 0 · Even" while listing rows with real amounts on it.
        $groups = [];
        foreach ($withRun as $it) {
            $d = $it['date'] ?: 'unknown';
            if (!isset($groups[$d])) {
                $groups[$d] = ['date' => $d, 'in' => 0.0, 'out' => 0.0, 'pre_in' => 0.0, 'pre_out' => 0.0,
                               'counted' => 0, 'has_reset' => false, 'items' => []];
            }
            if (!empty($it['is_reset'])) $groups[$d]['has_reset'] = true;
            if (!empty($it['is_pre'])) {
                if ($it['direction'] === 'in') $groups[$d]['pre_in'] += $it['amount'];
                elseif ($it['direction'] === 'out') $groups[$d]['pre_out'] += $it['amount'];
            } else {
                if ($it['direction'] === 'in') $groups[$d]['in'] += $it['amount'];
                elseif ($it['direction'] === 'out') $groups[$d]['out'] += $it['amount'];
                if (empty($it['is_reset'])) $groups[$d]['counted']++;
            }
            $groups[$d]['items'][] = $it;
        }

        $assignBanks = $isUnassigned && $isTaimur
            ? \App\Models\FIN\OnlineReceivingAccountModel::active()->ordered()->get(['id', 'name', 'short_code'])
            : collect();

        $postCollection = collect($post);

        // "Since reset" must mean since the moment the figure was DECLARED — not since the start of
        // that day, which would count rows the declared figure already contains and read as if money
        // had moved after a reset that nothing has touched.
        if ($declaredAtReset !== null) {
            $net = round($balance - $declaredAtReset, 2);
        }

        return view('fin.hub.bank-detail', [
            'active' => 'banks', 'scope' => $scope, 'canSeeKhaas' => $canSeeKhaas, 'canSeeMulti' => $canSeeMulti,
            'bank' => $bank, 'isUnassigned' => $isUnassigned, 'balance' => $balance, 'net' => $net, 'opening' => $opening,
            'groups' => array_values($groups), 'count' => count($post), 'days' => $days, 'isTaimur' => $isTaimur,
            'bankTransfersReady' => $this->bankTransfersReady(),
            'totalIn' => $postCollection->where('direction', 'in')->sum('amount'),
            'totalOut' => $postCollection->where('direction', 'out')->sum('amount'),
            'assignBanks' => $assignBanks,
            'resetDate' => $resetDate, 'resetAmount' => $resetAmount,
            // Same-day pre-reset rows are always on screen (they belong to the reset day), so they are
            // not part of the "earlier rows, folded away" count.
            'showHistory' => $showHistory, 'preCount' => $preCount,
            'preShown' => count(array_filter($pre, fn ($p) => empty($p['pre_same_day']))),
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
