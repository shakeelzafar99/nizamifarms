<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use App\Models\FIN\OnlineReceivingAccountModel;
use Illuminate\Http\Request;

class OnlineReceivingAccountController extends Controller
{
    /**
     * API: List all accounts (active + inactive) for admin management
     */
    public function index()
    {
        $accounts = OnlineReceivingAccountModel::ordered()->get();
        return response()->json(['success' => true, 'data' => $accounts]);
    }

    /**
     * API: Create a new receiving account
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'short_code' => 'required|string|max:20',
            'color_hex' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer',
        ]);

        $maxOrder = OnlineReceivingAccountModel::max('sort_order') ?? 0;

        $account = OnlineReceivingAccountModel::create([
            'name' => $validated['name'],
            'short_code' => $validated['short_code'],
            'color_hex' => $validated['color_hex'] ?? '#3B82F6',
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
        $account = OnlineReceivingAccountModel::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'short_code' => 'sometimes|required|string|max:20',
            'color_hex' => 'nullable|string|max:7',
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
