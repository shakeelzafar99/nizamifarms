<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\AssetModel;
use App\Models\FIN\AssetCategoryModel;
use App\Models\FIN\BusinessUnitModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\VendorModel;
use App\Models\FIN\LedgerModel;
use App\Models\SysAdmin\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    /**
     * Display list of assets
     */
    public function index(Request $request)
    {
        $query = AssetModel::with(['businessUnit', 'category', 'purchasedBy', 'paymentAccount']);
        
        // Filter by business unit
        if ($request->filled('business_unit')) {
            $query->where('business_unit_id', $request->business_unit);
        }
        
        // Filter by category
        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }
        
        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('asset_name', 'LIKE', "%{$search}%")
                  ->orWhere('asset_code', 'LIKE', "%{$search}%")
                  ->orWhere('serial_number', 'LIKE', "%{$search}%")
                  ->orWhere('location', 'LIKE', "%{$search}%");
            });
        }
        
        $assets = $query->orderBy('purchase_date', 'desc')
                       ->orderBy('id', 'desc')
                       ->paginate(25);
        
        // Get summary stats
        $summary = AssetModel::getSummary($request->business_unit);
        
        // Get dropdown data
        $businessUnits = BusinessUnitModel::getForDropdown();
        $categories = AssetCategoryModel::getForDropdown();
        
        return view('fin.asset.index', compact('assets', 'summary', 'businessUnits', 'categories'));
    }

    /**
     * Show create asset form
     */
    public function create()
    {
        $businessUnits = BusinessUnitModel::getForDropdown();
        $categories = AssetCategoryModel::getForDropdown();
        $paymentAccounts = AccountModel::where('is_active', 1)
                                       ->where(function($q) {
                                           $q->whereIn('account_code', ['NF_CASH', 'ONLINE', 'EXP_FUND'])
                                             ->orWhere('account_code', 'LIKE', 'NF_FOOD%');
                                       })
                                       ->get(['id', 'account_name', 'account_code']);
        $vendors = VendorModel::where('is_active', 1)
                              ->orderBy('vendor_name')
                              ->get(['id', 'vendor_name']);
        $users = UserModel::where('is_active', 1)
                          ->orderBy('fullname')
                          ->get(['id', 'fullname']);
        
        return view('fin.asset.create', compact('businessUnits', 'categories', 'paymentAccounts', 'vendors', 'users'));
    }

    /**
     * Store new asset
     */
    public function store(Request $request)
    {
        $request->validate([
            'asset_name' => 'required|string|max:255',
            'business_unit_id' => 'required|exists:t_fin_business_units,id',
            'category_id' => 'required|exists:t_fin_asset_categories,id',
            'purchase_date' => 'required|date',
            'purchase_amount' => 'required|numeric|min:0',
            'payment_account_id' => 'required|exists:t_fin_accounts,id',
            'payment_mode' => 'required|in:cash,online',
            'purchased_by' => 'required|exists:t_sys_user,id',
            'vendor_id' => 'nullable|exists:t_fin_vendors,id',
            'serial_number' => 'nullable|string|max:100',
            'model_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'condition' => 'required|in:new,good,fair,poor',
            'description' => 'nullable|string',
            'notes' => 'nullable|string',
            'bill_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:5120'
        ]);

        try {
            DB::beginTransaction();
            
            // Generate asset code
            $assetCode = AssetModel::generateAssetCode();
            
            // Get vendor name if vendor selected
            $vendorNameSnapshot = null;
            if ($request->vendor_id) {
                $vendor = VendorModel::find($request->vendor_id);
                $vendorNameSnapshot = $vendor ? $vendor->vendor_name : null;
            }
            
            // Handle bill image upload
            $billImagePath = null;
            if ($request->hasFile('bill_image')) {
                $billImagePath = $request->file('bill_image')->store('assets/bills', 'public');
            }
            
            // Get the appropriate fixed assets account based on business unit
            $businessUnit = BusinessUnitModel::find($request->business_unit_id);
            $fixedAssetsAccountCode = $businessUnit->code === 'NF_FOODS' 
                ? 'FIXED_ASSETS_NFFOODS' 
                : 'FIXED_ASSETS_NF';
            
            $fixedAssetsAccount = AccountModel::getByCode($fixedAssetsAccountCode);
            if (!$fixedAssetsAccount) {
                $fixedAssetsAccount = AccountModel::getByCode('FIXED_ASSETS');
            }
            
            if (!$fixedAssetsAccount) {
                throw new \Exception("Fixed Assets account not found. Please run the migration first.");
            }
            
            // Get payment account
            $paymentAccount = AccountModel::find($request->payment_account_id);
            
            // Create ledger entry: Payment Account → Fixed Assets
            $purchasedByUser = UserModel::find($request->purchased_by);
            $description = "Asset Purchase: {$request->asset_name} (by {$purchasedByUser->fullname})";
            
            $ledger = LedgerModel::create([
                'transaction_date' => $request->purchase_date,
                'transaction_type' => 'asset_purchase',
                'description' => $description,
                'from_account_id' => $paymentAccount->id,
                'to_account_id' => $fixedAssetsAccount->id,
                'amount' => $request->purchase_amount,
                'mode' => $request->payment_mode,
                'business_unit_id' => $request->business_unit_id,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approval_date' => now(),
                'created_by' => auth()->id(),
                'comments' => "Asset: {$assetCode}"
            ]);
            
            // Update account balances
            // Decrease payment account
            $paymentAccount->current_balance -= $request->purchase_amount;
            $paymentAccount->save();
            
            // Increase fixed assets account
            $fixedAssetsAccount->current_balance += $request->purchase_amount;
            $fixedAssetsAccount->save();
            
            // Get category for useful life
            $category = AssetCategoryModel::find($request->category_id);
            
            // Create asset record
            $asset = AssetModel::create([
                'asset_code' => $assetCode,
                'asset_name' => $request->asset_name,
                'description' => $request->description,
                'business_unit_id' => $request->business_unit_id,
                'category_id' => $request->category_id,
                'purchase_date' => $request->purchase_date,
                'purchase_amount' => $request->purchase_amount,
                'vendor_id' => $request->vendor_id,
                'vendor_name_snapshot' => $vendorNameSnapshot,
                'payment_account_id' => $request->payment_account_id,
                'payment_mode' => $request->payment_mode,
                'serial_number' => $request->serial_number,
                'model_number' => $request->model_number,
                'location' => $request->location,
                'condition' => $request->condition,
                'useful_life_years' => $category->useful_life_years,
                'salvage_value' => 0,
                'depreciation_method' => 'none',
                'current_book_value' => $request->purchase_amount,
                'purchased_by' => $request->purchased_by,
                'ledger_transaction_id' => $ledger->id,
                'status' => AssetModel::STATUS_ACTIVE,
                'bill_image' => $billImagePath,
                'notes' => $request->notes,
                'created_by' => auth()->id()
            ]);
            
            DB::commit();
            
            return redirect()->route('fin.assets.index')
                           ->with('success', "Asset '{$asset->asset_name}' ({$assetCode}) created successfully!");

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating asset: ' . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error creating asset: ' . $e->getMessage());
        }
    }

    /**
     * Show asset details
     */
    public function show($id)
    {
        $asset = AssetModel::with([
            'businessUnit',
            'category', 
            'vendor',
            'purchasedBy',
            'paymentAccount',
            'ledgerTransaction',
            'createdBy'
        ])->findOrFail($id);
        
        return view('fin.asset.show', compact('asset'));
    }

    /**
     * Show edit form
     */
    public function edit($id)
    {
        $asset = AssetModel::findOrFail($id);
        
        $businessUnits = BusinessUnitModel::getForDropdown();
        $categories = AssetCategoryModel::getForDropdown();
        $users = UserModel::where('is_active', 1)
                          ->orderBy('fullname')
                          ->get(['id', 'fullname']);
        
        return view('fin.asset.edit', compact('asset', 'businessUnits', 'categories', 'users'));
    }

    /**
     * Update asset (limited fields - no financial changes)
     */
    public function update(Request $request, $id)
    {
        $asset = AssetModel::findOrFail($id);
        
        $request->validate([
            'asset_name' => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:100',
            'model_number' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'condition' => 'required|in:new,good,fair,poor,disposed',
            'description' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        try {
            $asset->update([
                'asset_name' => $request->asset_name,
                'serial_number' => $request->serial_number,
                'model_number' => $request->model_number,
                'location' => $request->location,
                'condition' => $request->condition,
                'description' => $request->description,
                'notes' => $request->notes,
                'updated_by' => auth()->id()
            ]);
            
            return redirect()->route('fin.assets.show', $asset->id)
                           ->with('success', 'Asset updated successfully!');

        } catch (\Exception $e) {
            Log::error('Error updating asset: ' . $e->getMessage());
            
            return back()->withInput()
                       ->with('error', 'Error updating asset: ' . $e->getMessage());
        }
    }

    /**
     * API: Get assets list (for mobile)
     */
    public function apiIndex(Request $request)
    {
        try {
            $query = AssetModel::with(['businessUnit:id,name,code', 'category:id,name', 'purchasedBy:id,fullname'])
                              ->active();
            
            // Filter by business unit
            if ($request->filled('business_unit_id')) {
                $query->where('business_unit_id', $request->business_unit_id);
            }
            
            // Search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('asset_name', 'LIKE', "%{$search}%")
                      ->orWhere('asset_code', 'LIKE', "%{$search}%");
                });
            }
            
            $assets = $query->orderBy('purchase_date', 'desc')
                           ->limit(100)
                           ->get();
            
            // Get summary
            $summary = [
                'total_count' => AssetModel::active()->count(),
                'total_value' => AssetModel::active()->sum('purchase_amount'),
                'by_business_unit' => AssetModel::active()
                    ->select('business_unit_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(purchase_amount) as total'))
                    ->groupBy('business_unit_id')
                    ->with('businessUnit:id,name,code')
                    ->get()
            ];
            
            return response()->json([
                'success' => true,
                'assets' => $assets,
                'summary' => $summary
            ]);
            
        } catch (\Exception $e) {
            Log::error('API Error fetching assets: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching assets'
            ], 500);
        }
    }

    /**
     * API: Get asset details (for mobile)
     */
    public function apiShow($id)
    {
        try {
            $asset = AssetModel::with([
                'businessUnit:id,name,code',
                'category:id,name',
                'purchasedBy:id,fullname',
                'paymentAccount:id,account_name,account_code',
                'vendor:id,vendor_name'
            ])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'asset' => $asset
            ]);
            
        } catch (\Exception $e) {
            Log::error('API Error fetching asset: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Asset not found'
            ], 404);
        }
    }

    /**
     * API: Get form data for creating asset (categories, business units, etc.)
     */
    public function apiFormData()
    {
        try {
            return response()->json([
                'success' => true,
                'business_units' => BusinessUnitModel::getForDropdown(),
                'categories' => AssetCategoryModel::getForDropdown(),
                'payment_accounts' => AccountModel::where('is_active', 1)
                    ->where(function($q) {
                        $q->whereIn('account_code', ['NF_CASH', 'ONLINE', 'EXP_FUND'])
                          ->orWhere('account_code', 'LIKE', 'NF_FOOD%');
                    })
                    ->get(['id', 'account_name', 'account_code']),
                'vendors' => VendorModel::where('is_active', 1)
                    ->orderBy('vendor_name')
                    ->get(['id', 'vendor_name']),
                'users' => UserModel::where('is_active', 1)
                    ->orderBy('fullname')
                    ->get(['id', 'fullname'])
            ]);
            
        } catch (\Exception $e) {
            Log::error('API Error fetching form data: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching form data'
            ], 500);
        }
    }

    /**
     * API: Create asset (from mobile)
     */
    public function apiStore(Request $request)
    {
        $request->validate([
            'asset_name' => 'required|string|max:255',
            'business_unit_id' => 'required|exists:t_fin_business_units,id',
            'category_id' => 'required|exists:t_fin_asset_categories,id',
            'purchase_date' => 'required|date',
            'purchase_amount' => 'required|numeric|min:0',
            'payment_account_id' => 'required|exists:t_fin_accounts,id',
            'payment_mode' => 'required|in:cash,online',
            'purchased_by' => 'required|exists:t_sys_user,id',
            'condition' => 'required|in:new,good,fair,poor'
        ]);

        try {
            DB::beginTransaction();
            
            $assetCode = AssetModel::generateAssetCode();
            
            // Get business unit and fixed assets account
            $businessUnit = BusinessUnitModel::find($request->business_unit_id);
            $fixedAssetsAccountCode = $businessUnit->code === 'NF_FOODS' 
                ? 'FIXED_ASSETS_NFFOODS' 
                : 'FIXED_ASSETS_NF';
            
            $fixedAssetsAccount = AccountModel::getByCode($fixedAssetsAccountCode);
            if (!$fixedAssetsAccount) {
                $fixedAssetsAccount = AccountModel::getByCode('FIXED_ASSETS');
            }
            
            $paymentAccount = AccountModel::find($request->payment_account_id);
            $purchasedByUser = UserModel::find($request->purchased_by);
            
            // Create ledger entry
            $description = "Asset Purchase: {$request->asset_name} (by {$purchasedByUser->fullname})";
            
            $ledger = LedgerModel::create([
                'transaction_date' => $request->purchase_date,
                'transaction_type' => 'asset_purchase',
                'description' => $description,
                'from_account_id' => $paymentAccount->id,
                'to_account_id' => $fixedAssetsAccount->id,
                'amount' => $request->purchase_amount,
                'mode' => $request->payment_mode,
                'business_unit_id' => $request->business_unit_id,
                'approval_status' => LedgerModel::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approval_date' => now(),
                'created_by' => auth()->id(),
                'comments' => "Asset: {$assetCode}"
            ]);
            
            // Update balances
            $paymentAccount->current_balance -= $request->purchase_amount;
            $paymentAccount->save();
            
            $fixedAssetsAccount->current_balance += $request->purchase_amount;
            $fixedAssetsAccount->save();
            
            // Get category
            $category = AssetCategoryModel::find($request->category_id);
            
            // Create asset
            $asset = AssetModel::create([
                'asset_code' => $assetCode,
                'asset_name' => $request->asset_name,
                'description' => $request->description,
                'business_unit_id' => $request->business_unit_id,
                'category_id' => $request->category_id,
                'purchase_date' => $request->purchase_date,
                'purchase_amount' => $request->purchase_amount,
                'vendor_id' => $request->vendor_id,
                'payment_account_id' => $request->payment_account_id,
                'payment_mode' => $request->payment_mode,
                'serial_number' => $request->serial_number,
                'model_number' => $request->model_number,
                'location' => $request->location,
                'condition' => $request->condition,
                'useful_life_years' => $category->useful_life_years,
                'current_book_value' => $request->purchase_amount,
                'purchased_by' => $request->purchased_by,
                'ledger_transaction_id' => $ledger->id,
                'status' => AssetModel::STATUS_ACTIVE,
                'notes' => $request->notes,
                'created_by' => auth()->id()
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Asset created successfully",
                'asset' => $asset->load(['businessUnit', 'category', 'purchasedBy'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('API Error creating asset: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating asset: ' . $e->getMessage()
            ], 500);
        }
    }
}
