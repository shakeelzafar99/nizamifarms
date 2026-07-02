<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\OnlineReceivingAccountModel;
use Illuminate\Http\Request;

class OnlineReceivingAccountController extends Controller
{
    /**
     * Only the Taimur role may create/edit/delete bank accounts (they carry the
     * account last-4 and opening balances used for per-bank balance tracking).
     * Returns a 403 JsonResponse when not allowed, else null.
     */
    private function ensureCanManage(): ?\Illuminate\Http\JsonResponse
    {
        $user = auth()->user();
        $isTaimur = $user && $user->roles()
            ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
            ->exists();
        if (!$isTaimur) {
            return response()->json([
                'success' => false,
                'message' => 'Only the Taimur role can manage bank accounts.',
            ], 403);
        }
        return null;
    }

    /**
     * API: List all accounts (active + inactive) for admin management.
     * Each row also carries its computed current `balance` (opening balance +
     * net tagged movement) so pickers can show how much sits in each bank.
     */
    public function index(\App\Services\FIN\BankBalanceService $balances)
    {
        $accounts = OnlineReceivingAccountModel::ordered()->get();
        $byBank = $balances->balancesByBank();
        $data = $accounts->map(function ($acc) use ($byBank) {
            $row = $acc->toArray();
            $row['balance'] = $byBank[(int) $acc->id]['balance'] ?? (float) $acc->opening_balance;
            return $row;
        });
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * API: Create a new receiving account
     */
    public function store(Request $request)
    {
        if ($resp = $this->ensureCanManage()) return $resp;

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'short_code' => 'required|string|max:20',
            'account_last4' => 'nullable|string|max:4',
            'bank_name' => 'nullable|string|max:100',
            'color_hex' => 'nullable|string|max:7',
            'opening_balance' => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'sort_order' => 'nullable|integer',
        ]);

        $maxOrder = OnlineReceivingAccountModel::max('sort_order') ?? 0;

        $account = OnlineReceivingAccountModel::create([
            'name' => $validated['name'],
            'short_code' => $validated['short_code'],
            'account_last4' => $validated['account_last4'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'color_hex' => $validated['color_hex'] ?? '#3B82F6',
            'opening_balance' => $validated['opening_balance'] ?? 0,
            'opening_balance_date' => $validated['opening_balance_date'] ?? null,
            'is_active' => true,
            'sort_order' => $validated['sort_order'] ?? ($maxOrder + 1),
        ]);

        return response()->json(['success' => true, 'data' => $account], 201);
    }

    /**
     * API: Update an existing receiving account
     */
    public function update(Request $request, $id)
    {
        if ($resp = $this->ensureCanManage()) return $resp;

        $account = OnlineReceivingAccountModel::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'short_code' => 'sometimes|required|string|max:20',
            'account_last4' => 'nullable|string|max:4',
            'bank_name' => 'nullable|string|max:100',
            'color_hex' => 'nullable|string|max:7',
            'opening_balance' => 'nullable|numeric',
            'opening_balance_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $account->update($validated);

        return response()->json(['success' => true, 'data' => $account->fresh()]);
    }

    /**
     * API: Toggle active status
     */
    public function toggleActive($id)
    {
        if ($resp = $this->ensureCanManage()) return $resp;

        $account = OnlineReceivingAccountModel::findOrFail($id);
        $account->is_active = !$account->is_active;
        $account->save();

        return response()->json([
            'success' => true,
            'data' => $account,
            'message' => $account->is_active ? 'Account activated' : 'Account deactivated',
        ]);
    }

    /**
     * API: Delete a receiving account (only if not used)
     */
    public function destroy($id)
    {
        if ($resp = $this->ensureCanManage()) return $resp;

        $account = OnlineReceivingAccountModel::findOrFail($id);

        // Check if any ledger entries reference this account
        $usageCount = \App\Models\FIN\LedgerModel::where('receiving_account_id', $id)->count();
        if ($usageCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: {$usageCount} ledger entries use this account. Deactivate it instead.",
            ], 422);
        }

        $account->delete();

        return response()->json(['success' => true, 'message' => 'Account deleted']);
    }
}
