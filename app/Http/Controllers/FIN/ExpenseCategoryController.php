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
     * ⭐ Supports business_unit_id for Khaas-specific categories
     * ⭐ For Khaas BU (id != 1), only 'taimur' profile can create categories
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_name' => 'required|string|max:255',
            'business_unit_id' => 'nullable|integer',
            'request_category_code' => 'nullable|string|max:50',
        ]);

        // Salaries go through the Payroll screen — don't allow re-creating a
        // "Staff Salaries" expense category (it was removed on purpose).
        if (in_array(strtolower(trim((string) $request->input('category_name', ''))), ['staff salaries', 'staff salary'], true)) {
            $msg = 'Staff salaries are handled by the Payroll screen and cannot be added as an expense category.';
            if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return redirect()->back()->with('error', $msg);
        }

        try {
            $businessUnitId = $request->input('business_unit_id', 1); // Default to NF (BU 1)
            
            // ⭐ For non-default BU (Khaas), restrict to taimur profile only
            if ($businessUnitId && $businessUnitId != 1) {
                $user = auth()->user();
                $isTaimur = $user && $user->roles()
                    ->where(function($q) {
                        $q->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                          ->orWhereRaw('LOWER(urole_name) = ?', ['admin']);
                    })
                    ->exists();
                
                if (!$isTaimur) {
                    $errorMsg = 'Only Taimur/Admin can create new expense categories for this business unit.';
                    if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
                        return response()->json(['success' => false, 'message' => $errorMsg], 403);
                    }
                    return redirect()->back()->with('error', $errorMsg);
                }
            }
            
            DB::beginTransaction();

            $categoryName = trim($request->category_name);
            
            // Generate config key
            $configKey = 'EXPENSE_CATEGORY_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $categoryName));
            
            // ⭐ For Khaas categories, prefix to avoid collisions with main BU categories
            if ($businessUnitId && $businessUnitId != 1) {
                $configKey = 'EXPENSE_CATEGORY_KHAAS_' . strtoupper(str_replace([' ', '-', '.', '(', ')'], '_', $categoryName));
            }
            
            // Check if category already exists
            $existing = ConfigModel::where('config_key', $configKey)->first();
            
            if ($existing) {
                // Check if this is an AJAX request
                if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => "Expense category '{$categoryName}' already exists!"
                    ], 400);
                }
                
                return redirect()->route('admin.operations')
                               ->with('error', "Expense category '{$categoryName}' already exists!");
            }
            
            // Create expense account using the helper method
            $account = AccountModel::createExpenseAccount($categoryName);
            
            $configData = [
                'config_key' => $configKey,
                'config_value' => $categoryName,
                'description' => "Expense category: {$categoryName}. Account: {$account->account_code}",
                'business_unit_id' => $businessUnitId,
            ];

            $requestCategoryCode = $request->input('request_category_code');
            if ($requestCategoryCode) {
                $configData['request_category_code'] = $requestCategoryCode;
            }
            
            $config = ConfigModel::create($configData);

            DB::commit();

            Log::info("Expense category created", [
                'category' => $categoryName,
                'config_key' => $configKey,
                'account_code' => $account->account_code,
                'business_unit_id' => $businessUnitId,
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

