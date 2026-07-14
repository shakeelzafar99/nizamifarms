<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\OnlineReceivingAccountModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Bank Balances" — a per-bank view over the SINGLE ONLINE ledger account.
 *
 * The online money still lives in one chart-of-accounts row (so the manager
 * keeps one window on every online transaction). Each individual bank's balance
 * is COMPUTED, not stored: opening balance (set per bank, as of a date) plus the
 * net of every online movement TAGGED to that bank since that date.
 *
 * A movement is tagged via t_fin_ledger.receiving_account_id. Direction is read
 * from the double-entry accounts: money landing IN an online bank account is an
 * inflow (+), money leaving one (e.g. an online expense) is an outflow (−).
 *
 * Untagged online movements land in an "Unassigned" bucket so the totals stay
 * honest — nothing is silently dropped.
 */
class BankBalancesController extends Controller
{
    /** Only balance rows that have actually moved money count. */
    private const APPROVED_STATUSES = [
        LedgerModel::STATUS_APPROVED,
        LedgerModel::STATUS_PENDING_L2, // balance is applied at L1, verified at L2
    ];

    /** IDs of the chart-of-accounts rows that represent "online" money (ONLINE, QURBANI_ONLINE). */
    private function onlineAccountIds(): array
    {
        return AccountModel::where('account_category', AccountModel::CATEGORY_BANK)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    public function index(Request $request, \App\Services\FIN\BankBalanceService $balances)
    {
        $onlineIds = $balances->onlineAccountIds();
        $byBank = $balances->balancesByBank();

        // Which cards to show: active (default) | all | inactive.
        $status = in_array($request->get('status'), ['all', 'inactive'], true)
            ? $request->get('status')
            : 'active';

        $allBanks = OnlineReceivingAccountModel::ordered()->get();
        $activeCount = $allBanks->where('is_active', true)->count();
        $inactiveCount = $allBanks->where('is_active', false)->count();

        // Reconciliation total spans ALL banks — money can still sit in a
        // deactivated bank, so the "tracked" figure must not drop it.
        $sumBalances = 0.0;
        foreach ($allBanks as $bank) {
            $sumBalances += $byBank[(int) $bank->id]['balance'] ?? (float) $bank->opening_balance;
        }

        // Displayed cards honour the status filter.
        $displayBanks = $allBanks->filter(function ($bank) use ($status) {
            if ($status === 'all') {
                return true;
            }
            if ($status === 'inactive') {
                return !$bank->is_active;
            }
            return (bool) $bank->is_active; // default: active only
        });

        $rows = [];
        foreach ($displayBanks as $bank) {
            $calc = $byBank[(int) $bank->id] ?? ['net' => 0.0, 'balance' => (float) $bank->opening_balance];
            $rows[] = [
                'id' => $bank->id,
                'name' => $bank->name,
                'short_code' => $bank->short_code,
                'account_last4' => $bank->account_last4,
                'color_hex' => $bank->color_hex ?: '#3B82F6',
                'is_active' => (bool) $bank->is_active,
                'opening_balance' => (float) $bank->opening_balance,
                'opening_balance_date' => optional($bank->opening_balance_date)->toDateString(),
                'net_movement' => $calc['net'],
                'balance' => $calc['balance'],
            ];
        }

        // Untagged online movements (received/spent online but no bank picked).
        $unassigned = $balances->unassignedNet();
        $unassignedCount = $this->unassignedQuery($onlineIds)->count();

        // The ONLINE chart-of-accounts rows themselves — the pool of money that
        // must be distributed across the physical banks. Shown prominently so
        // the manager knows the total to allocate, and broken down per account.
        $onlineAccounts = $onlineIds
            ? AccountModel::whereIn('id', $onlineIds)
                ->orderBy('account_name')
                ->get(['id', 'account_name', 'account_code', 'current_balance'])
                ->map(fn ($a) => [
                    'name' => $a->account_name,
                    'code' => $a->account_code,
                    'balance' => (float) $a->current_balance,
                ])->values()
            : collect();
        $ledgerOnlineBalance = (float) $onlineAccounts->sum('balance');

        // ⭐ [Ledger L2] Reconciliation tripwire. In a fully-reconciled system,
        // (tracked-across-banks + untagged) should equal the ledger online balance.
        // Manual ⚖ per-bank adjustments deliberately move a bank toward its real
        // statement (Taimur's backdoor), so they are SUBTRACTED — a legitimate manual
        // correction must never trip the alarm. What remains is the "unexplained gap":
        // bank opening balances not yet set + the ONLINE account's stored-balance drift,
        // both resolved at the one-time re-baseline (NOT by draining the untagged bucket,
        // which shifts money from untagged to a bank 1:1 and doesn't change this number).
        $netManualAdjustments = (float) \DB::table('t_fin_bank_balance_adjustment')->sum('amount');
        $reconGap = round(($sumBalances + $unassigned) - $ledgerOnlineBalance - $netManualAdjustments, 2);
        // Green under Rs 1k, amber under Rs 100k, red beyond — tune later if needed.
        $reconStatus = abs($reconGap) < 1000 ? 'green' : (abs($reconGap) < 100000 ? 'amber' : 'red');

        // Only the Taimur role can fix untagged rows (assign a bank).
        $isTaimur = auth()->user()?->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists() ?? false;

        return view('fin.bank-balances.index', [
            'banks' => $rows,
            'status' => $status,
            'activeCount' => $activeCount,
            'inactiveCount' => $inactiveCount,
            'sumBalances' => $sumBalances,
            'unassigned' => $unassigned,
            'unassignedCount' => $unassignedCount,
            'unassignedSince' => $balances->unassignedSince(),
            'trackedPlusUnassigned' => $sumBalances + $unassigned,
            'onlineAccounts' => $onlineAccounts,
            'ledgerOnlineBalance' => $ledgerOnlineBalance,
            'netManualAdjustments' => $netManualAdjustments,
            'reconGap' => $reconGap,
            'reconStatus' => $reconStatus,
            'isTaimur' => $isTaimur,
        ]);
    }

    /**
     * Statement for one bank (JSON) — what came in and what went out.
     * ?days=30|90|365|0 (0 = all history). Default 30.
     */
    public function transactions(Request $request, $id)
    {
        $bank = OnlineReceivingAccountModel::findOrFail($id);
        $onlineIds = $this->onlineAccountIds();
        [$since, $days] = $this->sinceFromRequest($request);

        $q = LedgerModel::where('receiving_account_id', $bank->id)
            ->whereIn('approval_status', self::APPROVED_STATUSES);
        if ($since) {
            $q->whereDate('transaction_date', '>=', $since);
        }
        $rows = $q->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'transaction_date', 'transaction_type', 'description', 'amount', 'from_account_id', 'to_account_id']);

        $data = $this->mapStatementRows($rows, $onlineIds);

        // Merge in manual adjustments (they count in the card balance, so the
        // statement must show them too). try/catch: table may not exist yet.
        try {
            $adjQ = \App\Models\FIN\BankBalanceAdjustmentModel::where('receiving_account_id', $bank->id);
            if ($since) {
                $adjQ->whereDate('adjustment_date', '>=', $since);
            }
            $adjRows = $adjQ->orderByDesc('adjustment_date')->limit(200)->get()
                ->map(fn ($a) => [
                    'id' => 'adj-' . $a->id,
                    'adjustment_id' => $a->id,
                    'date' => optional($a->adjustment_date)->toDateString(),
                    'type' => 'adjustment',
                    'description' => $a->note ?: 'Manual balance adjustment',
                    'counterparty' => null,
                    'amount' => abs((float) $a->amount),
                    'direction' => (float) $a->amount >= 0 ? 'in' : 'out',
                    'is_adjustment' => true,
                ]);
            $data = $data->concat($adjRows)->sortByDesc('date')->values();
        } catch (\Throwable $e) {
            // Adjustments table not created yet — statement shows ledger rows only.
        }

        return response()->json([
            'success' => true,
            'bank' => [
                'id' => $bank->id,
                'name' => $bank->name,
                'short_code' => $bank->short_code,
                'color_hex' => $bank->color_hex ?: '#3B82F6',
            ],
            'since' => $since,
            'days' => $days,
            'transactions' => $data,
            'total_in' => $data->where('direction', 'in')->sum('amount'),
            'total_out' => $data->where('direction', 'out')->sum('amount'),
        ]);
    }

    /**
     * Record a manual per-bank balance adjustment (Taimur only). Positive adds
     * to the bank's balance, negative reduces it. Attribution-layer only —
     * never a ledger row, never touches account balances or money.
     */
    public function storeAdjustment(Request $request, $bankId)
    {
        if ($resp = $this->requireTaimur('record balance adjustments')) {
            return $resp;
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|not_in:0',
            'note' => 'nullable|string|max:255',
            'adjustment_date' => 'nullable|date',
        ]);

        $bank = OnlineReceivingAccountModel::findOrFail($bankId);

        $adj = \App\Models\FIN\BankBalanceAdjustmentModel::create([
            'receiving_account_id' => $bank->id,
            'amount' => round((float) $validated['amount'], 2),
            'adjustment_date' => $validated['adjustment_date'] ?? now()->toDateString(),
            'note' => $validated['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Adjustment recorded.',
            'adjustment_id' => $adj->id,
        ]);
    }

    /** Remove a mis-entered adjustment (Taimur only). */
    public function deleteAdjustment($adjustmentId)
    {
        if ($resp = $this->requireTaimur('delete balance adjustments')) {
            return $resp;
        }

        $adj = \App\Models\FIN\BankBalanceAdjustmentModel::findOrFail($adjustmentId);
        $adj->delete();

        return response()->json(['success' => true, 'message' => 'Adjustment removed.']);
    }

    /** 403 JSON when the current user is not Taimur, else null. */
    private function requireTaimur(string $action): ?\Illuminate\Http\JsonResponse
    {
        $isTaimur = auth()->user()?->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists() ?? false;
        if (!$isTaimur) {
            return response()->json([
                'success' => false,
                'message' => "Only the Taimur role can {$action}.",
            ], 403);
        }
        return null;
    }

    /**
     * Statement of UNTAGGED online movements ("No bank") so the manager can see
     * what slipped through and fix it. Same shape as transactions().
     */
    public function unassignedTransactions(Request $request)
    {
        $onlineIds = $this->onlineAccountIds();
        [$since, $days] = $this->sinceFromRequest($request);

        $q = $this->unassignedQuery($onlineIds);
        if ($since) {
            $q->whereDate('transaction_date', '>=', $since);
        }
        $rows = $q->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'transaction_date', 'transaction_type', 'description', 'amount', 'from_account_id', 'to_account_id']);

        $data = $this->mapStatementRows($rows, $onlineIds);

        return response()->json([
            'success' => true,
            'bank' => [
                'id' => null,
                'name' => 'No bank',
                'short_code' => 'NO BANK',
                'color_hex' => '#B45309',
            ],
            'since' => $since,
            'days' => $days,
            'transactions' => $data,
            'total_in' => $data->where('direction', 'in')->sum('amount'),
            'total_out' => $data->where('direction', 'out')->sum('amount'),
        ]);
    }

    /**
     * Fix an untagged online movement: assign its receiving bank (Taimur only).
     * Only fills the tag when it is currently NULL and the row really touches an
     * online account — never overwrites an existing tag, never touches money.
     */
    public function assignBank(Request $request, $ledgerId)
    {
        $isTaimur = auth()->user()?->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists() ?? false;
        if (!$isTaimur) {
            return response()->json(['success' => false, 'message' => 'Only the Taimur role can assign banks.'], 403);
        }

        $request->validate([
            'receiving_account_id' => 'required|integer|exists:t_fin_online_receiving_accounts,id',
        ]);

        $ledger = LedgerModel::findOrFail($ledgerId);
        if ($ledger->receiving_account_id) {
            return response()->json(['success' => false, 'message' => 'This entry already has a bank.'], 422);
        }
        $onlineIds = $this->onlineAccountIds();
        $touchesOnline = in_array((int) $ledger->from_account_id, $onlineIds, true)
            || in_array((int) $ledger->to_account_id, $onlineIds, true);
        if (!$touchesOnline) {
            return response()->json(['success' => false, 'message' => 'This entry does not touch an online account.'], 422);
        }

        $ledger->receiving_account_id = (int) $request->receiving_account_id;
        // Name the bank in the description (same convention as at creation).
        $short = OnlineReceivingAccountModel::find($ledger->receiving_account_id)?->short_code;
        if ($short && !str_contains((string) $ledger->description, '· via ')) {
            $ledger->description = trim((string) $ledger->description) . " · via {$short}";
        }
        $ledger->comments = trim(($ledger->comments ?? '') . " | Bank assigned via Bank Balances by user " . auth()->id());
        $ledger->save();

        return response()->json(['success' => true, 'message' => 'Bank assigned.']);
    }

    /** Parse ?days= into [sinceDate|null, days]. 0 = all history. */
    private function sinceFromRequest(Request $request): array
    {
        $days = (int) $request->input('days', 30);
        if (!in_array($days, [0, 30, 90, 365], true)) {
            $days = 30;
        }
        return [$days > 0 ? now()->subDays($days)->toDateString() : null, $days];
    }

    /**
     * Untagged, balance-applied movements touching an online account. Honours
     * the optional "start tracking No-bank from" date (config) as a hard floor.
     */
    private function unassignedQuery(array $onlineIds)
    {
        $q = LedgerModel::whereNull('receiving_account_id')
            ->whereIn('approval_status', self::APPROVED_STATUSES)
            ->where(function ($q) use ($onlineIds) {
                $q->whereIn('to_account_id', $onlineIds)
                    ->orWhereIn('from_account_id', $onlineIds);
            });
        $since = \App\Models\FIN\ConfigModel::get('bank_unassigned_tracking_since', null);
        if ($since) {
            $q->whereDate('transaction_date', '>=', substr((string) $since, 0, 10));
        }
        return $q;
    }

    /**
     * Set (or clear) the date from which to track the No-bank bucket. Taimur
     * only. Pass an empty date to clear (count all history again).
     */
    public function setUnassignedSince(Request $request)
    {
        if ($resp = $this->requireTaimur('set the No-bank tracking date')) {
            return $resp;
        }
        $validated = $request->validate([
            'since' => 'nullable|date',
        ]);
        \App\Models\FIN\ConfigModel::set(
            'bank_unassigned_tracking_since',
            $validated['since'] ?? null,
            'Bank Balances: only count untagged online movements on/after this date.'
        );
        return response()->json(['success' => true, 'message' => 'Saved.']);
    }

    /** Shape ledger rows into statement rows with an in/out/neutral direction. */
    private function mapStatementRows($rows, array $onlineIds)
    {
        // Resolve the counterparty for payments whose description may not name
        // it (e.g. a vendor payment with a custom note): vendor payments and
        // salary rows point at the vendor/employee via to_account_id. One
        // batched lookup — no N+1.
        $counterpartyTypes = [
            LedgerModel::TYPE_VENDOR_PAYMENT,
            LedgerModel::TYPE_SALARY_ADVANCE,
            LedgerModel::TYPE_SALARY_PAYMENT,
        ];
        $counterpartyAccountIds = $rows
            ->filter(fn ($r) => in_array($r->transaction_type, $counterpartyTypes, true))
            ->pluck('to_account_id')->filter()->unique()->values();
        $accountNames = $counterpartyAccountIds->isNotEmpty()
            ? AccountModel::whereIn('id', $counterpartyAccountIds->all())->pluck('account_name', 'id')
            : collect();

        return $rows->map(function ($r) use ($onlineIds, $counterpartyTypes, $accountNames) {
            $toOnline = in_array((int) $r->to_account_id, $onlineIds, true);
            $fromOnline = in_array((int) $r->from_account_id, $onlineIds, true);
            // Both sides online (transfer between online chart accounts) is the
            // same physical money — neutral for this bank, not an inflow.
            $isInflow = $toOnline && !$fromOnline;
            $isOutflow = $fromOnline && !$toOnline;

            // Who was paid — shown as its own chip so even old rows with a
            // free-text description (e.g. "test") identify the vendor.
            $counterparty = null;
            if (in_array($r->transaction_type, $counterpartyTypes, true)) {
                $counterparty = $accountNames[(int) $r->to_account_id] ?? null;
            }

            return [
                'id' => $r->id,
                'date' => optional($r->transaction_date)->toDateString(),
                'type' => $r->transaction_type,
                'description' => $r->description,
                'counterparty' => $counterparty,
                'amount' => (float) $r->amount,
                'direction' => $isInflow ? 'in' : ($isOutflow ? 'out' : 'neutral'),
            ];
        });
    }

    // Balance math lives in App\Services\FIN\BankBalanceService — the single
    // source of truth shared with the Manage Banks payload and expense chips.
}
