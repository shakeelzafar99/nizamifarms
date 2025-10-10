<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\ConfigModel;
use App\Models\FIN\AccountModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpenseCategoryController extends Controller
{
    /**
     * Store a new expense category
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_name' => 'required|string|max:255'
        ]);

        try {
            DB::beginTransaction();

            $categoryName = trim($request->category_name);
            
            // Generate config key
            $configKey = 'EXPENSE_CATEGORY_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $categoryName));
            
            // Check if category already exists
            $existing = ConfigModel::where('config_key', $configKey)->first();
            
            if ($existing) {
                return redirect()->route('admin.operations')
                               ->with('error', "Expense category '{$categoryName}' already exists!");
            }
            
            // Create expense account using the helper method
            $account = AccountModel::createExpenseAccount($categoryName);
            
            // Store category in config
            ConfigModel::create([
                'config_key' => $configKey,
                'config_value' => $categoryName,
                'description' => "Expense category: {$categoryName}. Account: {$account->account_code}"
            ]);

            DB::commit();

            Log::info("Expense category created", [
                'category' => $categoryName,
                'config_key' => $configKey,
                'account_code' => $account->account_code,
                'created_by' => auth()->id()
            ]);

            // Check if this is an AJAX request
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => "✓ Expense category '{$categoryName}' created successfully!",
                    'category' => $categoryName,
                    'account_code' => $account->account_code
                ]);
            }

            return redirect()->route('admin.operations')
                           ->with('success', "✓ Expense category '{$categoryName}' created successfully! Account: {$account->account_code}");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating expense category: " . $e->getMessage());
            
            // Check if this is an AJAX request
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error creating expense category: ' . $e->getMessage()
                ], 500);
            }
            
            return redirect()->route('admin.operations')
                           ->with('error', 'Error creating expense category: ' . $e->getMessage());
        }
    }
}

