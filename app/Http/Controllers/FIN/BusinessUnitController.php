<?php

namespace App\Http\Controllers\FIN;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\FIN\BusinessUnitModel;
use App\Models\FIN\VendorModel;
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\CRM\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusinessUnitController extends Controller
{
    /**
     * Display list of business units
     */
    public function index()
    {
        $businessUnits = BusinessUnitModel::orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Get counts for each business unit
        foreach ($businessUnits as $bu) {
            $bu->vendor_count = VendorModel::where('business_unit_id', $bu->id)->count();
            $bu->product_count = ProductModel::where('business_unit_id', $bu->id)->count();
            $bu->account_count = AccountModel::where('business_unit_id', $bu->id)->count();
            $bu->ledger_count = LedgerModel::where('business_unit_id', $bu->id)->count();
        }

        return view('fin.business-unit.index', compact('businessUnits'));
    }

    /**
     * Show create form
     */
    public function create()
    {
        return view('fin.business-unit.create');
    }

    /**
     * Store new business unit
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:20|unique:t_fin_business_units,code',
            'name' => 'required|string|max:100',
            'short_code' => 'nullable|string|max:10',
            'description' => 'nullable|string|max:500',
            'color_hex' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        try {
            $businessUnit = BusinessUnitModel::create([
                'code' => strtoupper($request->code),
                'name' => $request->name,
                'short_code' => $request->short_code ?? strtoupper(substr($request->code, 0, 3)),
                'description' => $request->description,
                'color_hex' => $request->color_hex ?? '#3B82F6',
                'sort_order' => $request->sort_order ?? 0,
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('business-units.index')
                ->with('success', 'Business unit created successfully.');
        } catch (\Exception $e) {
            Log::error('Error creating business unit: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to create business unit: ' . $e->getMessage());
        }
    }

    /**
     * Show edit form
     */
    public function edit($id)
    {
        $businessUnit = BusinessUnitModel::findOrFail($id);
        return view('fin.business-unit.edit', compact('businessUnit'));
    }

    /**
     * Update business unit
     */
    public function update(Request $request, $id)
    {
        $businessUnit = BusinessUnitModel::findOrFail($id);

        $request->validate([
            'code' => 'required|string|max:20|unique:t_fin_business_units,code,' . $id,
            'name' => 'required|string|max:100',
            'short_code' => 'nullable|string|max:10',
            'description' => 'nullable|string|max:500',
            'color_hex' => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        try {
            $businessUnit->update([
                'code' => strtoupper($request->code),
                'name' => $request->name,
                'short_code' => $request->short_code ?? $businessUnit->short_code,
                'description' => $request->description,
                'color_hex' => $request->color_hex ?? $businessUnit->color_hex,
                'sort_order' => $request->sort_order ?? $businessUnit->sort_order,
                'is_active' => $request->has('is_active'),
                'updated_by' => auth()->id(),
            ]);

            return redirect()->route('business-units.index')
                ->with('success', 'Business unit updated successfully.');
        } catch (\Exception $e) {
            Log::error('Error updating business unit: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Failed to update business unit: ' . $e->getMessage());
        }
    }

    /**
     * Delete business unit
     */
    public function destroy($id)
    {
        $businessUnit = BusinessUnitModel::findOrFail($id);

        // Check for associated records
        $vendorCount = VendorModel::where('business_unit_id', $id)->count();
        $productCount = ProductModel::where('business_unit_id', $id)->count();
        $accountCount = AccountModel::where('business_unit_id', $id)->count();
        $ledgerCount = LedgerModel::where('business_unit_id', $id)->count();

        if ($vendorCount > 0 || $productCount > 0 || $accountCount > 0 || $ledgerCount > 0) {
            return back()->with('error', 
                "Cannot delete business unit. It has associated records: " .
                "{$vendorCount} vendors, {$productCount} products, {$accountCount} accounts, {$ledgerCount} ledger entries."
            );
        }

        try {
            $businessUnit->delete();
            return redirect()->route('business-units.index')
                ->with('success', 'Business unit deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Error deleting business unit: ' . $e->getMessage());
            return back()->with('error', 'Failed to delete business unit: ' . $e->getMessage());
        }
    }

    /**
     * API: Get business units accessible to the current user
     * Pass ?all=1 to get all active BUs (used by store-mode screens like Khaas Inventory)
     */
    public function apiList(Request $request)
    {
        if ($request->input('all')) {
            $businessUnits = BusinessUnitModel::where('is_active', 1)->get();
        } else {
            $businessUnits = AccountModel::getUserAccessibleBusinessUnits();
        }

        return response()->json([
            'success' => true,
            'data' => $businessUnits->map(fn($bu) => [
                'id' => $bu->id,
                'code' => $bu->code,
                'short_code' => $bu->short_code,
                'name' => $bu->name,
                'color_hex' => $bu->color_hex,
            ])->values()
        ]);
    }
}
