<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\FIN\BusinessUnitModel;
use App\Models\FIN\VendorModel;
use App\Models\FIN\LedgerModel;
use App\Models\CRM\ProductModel;
use App\Models\CRM\ProductVariantModel;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\CRM\WarehouseInventoryLogModel;
use App\Models\CRM\WarehouseTransferModel;
use App\Models\Request\RequestModel;

class KhaasController extends Controller
{
    /**
     * Get the Khaas business unit (id=2, code=KHAAS)
     */
    private function getKhaasBU()
    {
        return BusinessUnitModel::where('code', 'KHAAS')
            ->where('is_active', 1)
            ->first();
    }

    /**
     * Check if current user has Khaas access
     * Uses mobile permission system: checks for 'access_khaas_mode' permission
     * This is the same permission the mobile app checks via /rider/permissions
     */
    private function hasKhaasAccess()
    {
        $user = auth()->user();
        if (!$user) return false;

        // Load roles with mobile permissions if not already loaded
        if (!$user->relationLoaded('roles')) {
            $user->load(['roles.mobilePermissions']);
        }

        // Check using the mobile permission system (same as mobile app)
        return $user->hasMobilePermission('access_khaas_mode');
    }

    /**
     * Khaas Dashboard - overview hub
     */
    public function dashboard()
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Get summary stats
        $vendorCount = VendorModel::where('business_unit_id', $khaasBU->id)->where('is_active', 1)->count();
        $productCount = ProductModel::where('business_unit_id', $khaasBU->id)->where('is_active', 1)->count();
        
        $totalVendorBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)
            ->with('account')
            ->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        // Warehouse inventory stats
        $warehouseItems = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->count();
        $totalWarehouseQty = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->sum('quantity');
        $lowStockItems = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->whereColumn('quantity', '<=', 'min_stock_level')
            ->where('min_stock_level', '>', 0)
            ->count();

        // Pending transfers
        $pendingTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->count();

        // Expenses this month
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $monthlyExpenses = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$monthStart])
            ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$monthEnd])
            ->sum('amount');

        // Recent warehouse activity
        $recentActivity = WarehouseInventoryLogModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Recent transfers
        $recentTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'requester:id,fullname'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // ⭐ SALES REPORT: Get Khaas product sales from order line items
        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');
        
        // This month's sales
        $monthlySalesData = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            })
            ->selectRaw('SUM(line_total) as total_revenue, SUM(quantity) as total_units, COUNT(DISTINCT order_id) as total_orders')
            ->first();
        
        $monthlySalesRevenue = $monthlySalesData->total_revenue ?? 0;
        $monthlySalesUnits = $monthlySalesData->total_units ?? 0;
        $monthlySalesOrders = $monthlySalesData->total_orders ?? 0;

        // Recent Khaas sales (last 10 orders with Khaas products)
        $recentSales = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) {
                $q->whereNotIn('order_status', ['cancelled']);
            })
            ->with(['order:id,order_number,order_date,order_status,total_price,customer_id', 'order.customer:id,first_name,last_name,phone_original'])
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get()
            ->groupBy('order_id')
            ->take(8);

        return view('khaas.dashboard', compact(
            'khaasBU', 'vendorCount', 'productCount', 'totalVendorBalance',
            'warehouseItems', 'totalWarehouseQty', 'lowStockItems',
            'pendingTransfers', 'monthlyExpenses', 'recentActivity', 'recentTransfers',
            'monthlySalesRevenue', 'monthlySalesUnits', 'monthlySalesOrders', 'recentSales'
        ));
    }

    /**
     * Khaas Products - with store vs warehouse inventory comparison
     */
    public function products(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Build product query filtered by Khaas BU
        $query = ProductModel::where('business_unit_id', $khaasBU->id)
            ->with(['variants']);

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('handle', 'LIKE', "%{$search}%")
                  ->orWhereHas('variants', function($vq) use ($search) {
                      $vq->where('sku', 'LIKE', "%{$search}%");
                  });
            });
        }

        // Status filter
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Category filter (attribute_1)
        if ($request->has('category') && $request->category) {
            $query->where('attribute_1', $request->category);
        }

        $products = $query->orderBy('title')->paginate(25);

        // Get warehouse inventory for all Khaas products
        $warehouseInventory = WarehouseInventoryModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', true)
            ->get()
            ->groupBy('product_id')
            ->map(function($items) {
                return [
                    'warehouse_qty' => $items->sum('quantity'),
                    'unit' => $items->first()->unit ?? 'pcs',
                    'warehouse_location' => $items->first()->warehouse_location,
                    'min_stock_level' => $items->first()->min_stock_level ?? 0,
                ];
            });

        // Get pending transfers (qty grouped by product for display on cards)
        $pendingTransfers = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->where('to_location', 'store')
            ->get()
            ->groupBy('product_id')
            ->map(fn($items) => $items->sum('quantity'));

        // Get pending transfer records (for approval queue on same page)
        $pendingTransferRecords = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', WarehouseTransferModel::STATUS_PENDING)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Get categories for filter dropdown
        $categories = ProductModel::where('business_unit_id', $khaasBU->id)
            ->distinct()
            ->pluck('attribute_1')
            ->filter()
            ->sort()
            ->values();

        return view('khaas.products', compact(
            'khaasBU', 'products', 'warehouseInventory', 'pendingTransfers', 'pendingTransferRecords', 'categories'
        ));
    }

    /**
     * Khaas Operations - combined page for Vendors, Expenses, Transfers (tabs)
     */
    public function operations(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'vendors');

        // === VENDORS DATA ===
        $vendorQuery = VendorModel::where('business_unit_id', $khaasBU->id)->with(['account']);
        $vendorStatus = $request->input('vendor_status', 'active');
        if ($vendorStatus !== 'all') {
            $vendorQuery->where('is_active', $vendorStatus === 'active' ? 1 : 0);
        }
        if ($request->has('vendor_search') && $request->vendor_search) {
            $search = $request->vendor_search;
            $vendorQuery->where(function($q) use ($search) {
                $q->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%");
            });
        }
        $vendors = $vendorQuery->orderBy('vendor_name', 'asc')->paginate(25, ['*'], 'vendors_page');

        $totalVendorBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)->with('account')->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        // === EXPENSES DATA ===
        $hasDateFilter = $request->has('date_from') || $request->has('date_to');
        if (!$hasDateFilter) {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        } else {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
        }
        $expCategory = $request->input('exp_category');
        $settlementStatus = $request->input('settlement_status');

        // ⭐ Approved expenses (includes khaas_expense category)
        $expensesQuery = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category']);

        if ($dateFrom && $dateTo) {
            $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                          ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
        }
        if ($expCategory) {
            $expensesQuery->whereRaw('LOWER(expense_category) = ?', [strtolower($expCategory)]);
        }

        $expenses = $expensesQuery->orderBy('created_at', 'desc')->paginate(25, ['*'], 'expenses_page');

        $allExpensesForKPI = (clone $expensesQuery)->get();
        $totalExpenses = $allExpensesForKPI->sum('amount');

        // ⭐ Pending approval expenses for this BU
        $pendingExpenses = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->where('status', RequestModel::STATUS_PENDING)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category', 'approvals.approver'])
            ->orderBy('created_at', 'asc')
            ->get();
        $pendingExpenseCount = $pendingExpenses->count();
        $pendingExpenseAmount = $pendingExpenses->sum('amount');

        // ⭐ BU-specific payment source accounts for display
        $buPaymentAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
            ->where('business_unit_id', $khaasBU->id)
            ->whereNotIn('account_category', [
                \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH,
                \App\Models\FIN\AccountModel::CATEGORY_VENDOR_PAYABLE,
            ])
            ->orderBy('account_name')
            ->get();

        $expenseCategories = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'khaas_expense', 'salary_advance']);
            })
            ->where('business_unit_id', $khaasBU->id)
            ->distinct()->pluck('expense_category')->filter()->sort()->values();

        // === TRANSFERS DATA ===
        $transferStatus = $request->input('transfer_status', 'pending');
        $transferQuery = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname', 'approver:id,fullname']);
        if ($transferStatus !== 'all') {
            $transferQuery->where('status', $transferStatus);
        }
        $transfers = $transferQuery->orderBy('created_at', 'desc')->paginate(25, ['*'], 'transfers_page');

        $pendingTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'pending')->count();
        $approvedTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'approved')->count();
        $rejectedTransferCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)->where('status', 'rejected')->count();

        return view('khaas.operations', compact(
            'khaasBU', 'activeTab',
            // Vendors
            'vendors', 'totalVendorBalance', 'vendorStatus',
            // Expenses
            'expenses', 'totalExpenses', 'pendingExpenses', 'pendingExpenseCount', 'pendingExpenseAmount',
            'buPaymentAccounts',
            'dateFrom', 'dateTo', 'expCategory', 'expenseCategories',
            // Transfers
            'transfers', 'transferStatus', 'pendingTransferCount', 'approvedTransferCount', 'rejectedTransferCount'
        ));
    }

    /**
     * Khaas Vendors - filtered by Khaas BU
     */
    public function vendors(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $query = VendorModel::where('business_unit_id', $khaasBU->id)
            ->with(['account']);

        // Status filter
        $status = $request->input('status', 'active');
        if ($status !== 'all') {
            $query->where('is_active', $status === 'active' ? 1 : 0);
        }

        // Search filter
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('vendor_name', 'LIKE', "%{$search}%")
                  ->orWhere('contact_person', 'LIKE', "%{$search}%")
                  ->orWhere('contact_phone', 'LIKE', "%{$search}%");
            });
        }

        $vendors = $query->orderBy('vendor_name', 'asc')->paginate(25);

        // Total balance
        $totalBalance = VendorModel::where('business_unit_id', $khaasBU->id)
            ->where('is_active', 1)
            ->with('account')
            ->get()
            ->sum(fn($v) => $v->account ? $v->account->current_balance : 0);

        return view('khaas.vendors', compact('khaasBU', 'vendors', 'totalBalance', 'status'));
    }

    /**
     * Khaas Expenses - filtered by Khaas BU
     */
    public function expenses(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Get filter parameters
        $hasDateFilter = $request->has('date_from') || $request->has('date_to');
        if (!$hasDateFilter) {
            $dateFrom = date('Y-m-01');
            $dateTo = date('Y-m-t');
        } else {
            $dateFrom = $request->input('date_from');
            $dateTo = $request->input('date_to');
        }
        $category = $request->input('category');
        $settlementStatus = $request->input('settlement_status');

        // Build query filtered by Khaas BU
        $expensesQuery = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->whereNotNull('ledger_transaction_id')
            ->where('status', RequestModel::STATUS_APPROVED)
            ->where('business_unit_id', $khaasBU->id)
            ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy']);

        if ($dateFrom && $dateTo) {
            $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                          ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
        }
        if ($category) {
            $expensesQuery->whereRaw('LOWER(expense_category) = ?', [strtolower($category)]);
        }
        if ($settlementStatus) {
            $expensesQuery->where('settlement_status', $settlementStatus);
        }

        $expenses = $expensesQuery->orderBy('created_at', 'desc')->paginate(25);

        // KPIs
        $allExpensesForKPI = (clone $expensesQuery)->get();
        $totalExpenses = $allExpensesForKPI->sum('amount');
        $settledExpenses = $allExpensesForKPI->where('settlement_status', 'settled')->sum('amount');
        $pendingSettlement = $allExpensesForKPI->where('settlement_status', 'pending')->sum('amount');

        // Categories for filter
        $expenseCategories = RequestModel::whereHas('category', function($q) {
                $q->whereIn('category_code', ['expense', 'salary_advance']);
            })
            ->where('business_unit_id', $khaasBU->id)
            ->distinct()
            ->pluck('expense_category')
            ->filter()
            ->sort()
            ->values();

        return view('khaas.expenses', compact(
            'khaasBU', 'expenses', 'totalExpenses', 'settledExpenses', 'pendingSettlement',
            'dateFrom', 'dateTo', 'category', 'settlementStatus', 'expenseCategories'
        ));
    }

    /**
     * Warehouse Transfers - approval queue
     */
    public function transfers(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $status = $request->input('status', 'pending');

        $query = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname', 'approver:id,fullname']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $transfers = $query->orderBy('created_at', 'desc')->paginate(25);

        // Stats
        $pendingCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'pending')->count();
        $approvedCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'approved')->count();
        $rejectedCount = WarehouseTransferModel::where('business_unit_id', $khaasBU->id)
            ->where('status', 'rejected')->count();

        return view('khaas.transfers', compact(
            'khaasBU', 'transfers', 'status', 'pendingCount', 'approvedCount', 'rejectedCount'
        ));
    }

    /**
     * Approve/reject transfer from web (reuses existing API logic)
     */
    public function approveTransfer(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $transfer = WarehouseTransferModel::findOrFail($id);
        
        if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
            return back()->with('error', 'Transfer is not pending.');
        }

        DB::beginTransaction();
        try {
            $transfer->update([
                'status' => WarehouseTransferModel::STATUS_APPROVED,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            // ⭐ Resolve variant: use transfer's variant_id, or fallback to product's first variant
            $variantId = $transfer->product_variant_id;
            $variant = null;
            if ($variantId) {
                $variant = ProductVariantModel::find($variantId);
            }
            if (!$variant && $transfer->product_id) {
                $variant = ProductVariantModel::where('product_id', $transfer->product_id)->first();
                if ($variant) {
                    $transfer->update(['product_variant_id' => $variant->id]);
                }
            }
            
            // Add to store inventory (variant's inventory_quantity is Shop stock)
            if ($variant) {
                $variant->increment('inventory_quantity', $transfer->quantity);
            }
            
            // Sync product's total_inventory from variant(s)
            $product = ProductModel::find($transfer->product_id);
            if ($product) {
                $newTotal = ProductVariantModel::where('product_id', $product->id)->sum('inventory_quantity');
                $product->update(['total_inventory' => $newTotal]);
            }

            DB::commit();
            return back()->with('success', "Transfer of {$transfer->quantity} units approved. Store inventory updated.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to approve transfer: ' . $e->getMessage());
        }
    }

    public function rejectTransfer(Request $request, $id)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $transfer = WarehouseTransferModel::findOrFail($id);
        
        if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
            return back()->with('error', 'Transfer is not pending.');
        }

        $reason = $request->input('reason', 'Rejected from web');

        DB::beginTransaction();
        try {
            // Return stock to warehouse
            $inventory = WarehouseInventoryModel::getOrCreate(
                $transfer->product_id,
                $transfer->product_variant_id,
                $transfer->business_unit_id
            );
            $inventory->adjustQuantity($transfer->quantity, 'adjustment',
                "Transfer rejected - stock returned: {$transfer->quantity} units. Reason: {$reason}",
                'transfer_rejected', $transfer->id);

            $transfer->update([
                'status' => WarehouseTransferModel::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            DB::commit();
            return back()->with('success', "Transfer rejected. {$transfer->quantity} units returned to warehouse.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to reject transfer: ' . $e->getMessage());
        }
    }

    /**
     * Update warehouse stock from web
     */
    public function updateStock(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity_change' => 'required|integer',
            'change_type' => 'required|in:stock_in,stock_out,adjustment,count',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantityChange = $request->input('quantity_change');
        $changeType = $request->input('change_type');
        $notes = $request->input('notes');

        if ($changeType === 'stock_out' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }
        if ($changeType === 'stock_in' && $quantityChange < 0) {
            $quantityChange = abs($quantityChange);
        }

        DB::beginTransaction();
        try {
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);

            if ($changeType === 'count') {
                $before = $inventory->quantity;
                $inventory->quantity = $quantityChange;
                $inventory->last_counted_at = now();
                $inventory->last_counted_by = auth()->id();
                $inventory->updated_by = auth()->id();
                $inventory->save();

                WarehouseInventoryLogModel::create([
                    'warehouse_inventory_id' => $inventory->id,
                    'product_id' => $productId,
                    'business_unit_id' => $businessUnitId,
                    'change_type' => 'count',
                    'quantity_before' => $before,
                    'quantity_change' => $quantityChange - $before,
                    'quantity_after' => $quantityChange,
                    'notes' => $notes ?? 'Physical stock count (web)',
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
            } else {
                if ($changeType === 'stock_out' && ($inventory->quantity + $quantityChange) < 0) {
                    DB::rollBack();
                    return back()->with('error', "Insufficient stock. Current: {$inventory->quantity}, Requested: " . abs($quantityChange));
                }
                $inventory->adjustQuantity($quantityChange, $changeType, $notes);
            }

            DB::commit();
            return back()->with('success', 'Warehouse stock updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to update stock: ' . $e->getMessage());
        }
    }

    /**
     * Adjust store inventory manually (stock in / stock out / count / adjustment)
     * This modifies the product variant's inventory_quantity (store-side inventory)
     */
    public function updateStoreStock(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity_change' => 'required|integer',
            'change_type' => 'required|in:store_stock_in,store_stock_out,store_count,store_adjustment',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantityChange = $request->input('quantity_change');
        $changeType = $request->input('change_type');
        $notes = $request->input('notes');

        // Normalize quantity sign based on action
        if ($changeType === 'store_stock_out' && $quantityChange > 0) {
            $quantityChange = -$quantityChange;
        }
        if ($changeType === 'store_stock_in' && $quantityChange < 0) {
            $quantityChange = abs($quantityChange);
        }

        DB::beginTransaction();
        try {
            $product = ProductModel::with('variants')->findOrFail($productId);

            // Resolve variant (use first variant if not specified)
            $variant = null;
            if ($variantId) {
                $variant = $product->variants->where('id', $variantId)->first();
            }
            if (!$variant) {
                $variant = $product->variants->first();
            }
            if (!$variant) {
                DB::rollBack();
                return back()->with('error', 'No product variant found for this product.');
            }

            $before = (int) $variant->inventory_quantity;

            if ($changeType === 'store_count') {
                // Set to exact quantity
                $variant->inventory_quantity = $quantityChange;
                $actualChange = $quantityChange - $before;
            } else {
                // Stock in / stock out / adjustment
                if ($changeType === 'store_stock_out' && ($before + $quantityChange) < 0) {
                    DB::rollBack();
                    return back()->with('error', "Insufficient store stock. Current: {$before}, Requested: " . abs($quantityChange));
                }
                $variant->inventory_quantity = $before + $quantityChange;
                $actualChange = $quantityChange;
            }

            $variant->save();

            // Sync product.total_inventory from sum of all variants
            $product->total_inventory = $product->variants()->sum('inventory_quantity');
            $product->save();

            // Log the adjustment
            \App\Models\CRM\StoreInventoryAdjustmentModel::create([
                'product_id' => $productId,
                'product_variant_id' => $variant->id,
                'business_unit_id' => $businessUnitId,
                'change_type' => $changeType,
                'quantity_before' => $before,
                'quantity_change' => $actualChange,
                'quantity_after' => (int) $variant->inventory_quantity,
                'notes' => $notes ?? ('Manual store ' . str_replace('store_', '', $changeType)),
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            DB::commit();
            return back()->with('success', 'Store inventory updated successfully. (' . $before . ' → ' . $variant->inventory_quantity . ')');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update store stock: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Failed to update store stock: ' . $e->getMessage());
        }
    }

    /**
     * Khaas Sales Report - detailed sales breakdown by product with month filter
     */
    public function salesReport(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        // Month filter (default: current month)
        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $monthLabel = date('F Y', strtotime($monthStart));

        // Get all Khaas product IDs
        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');

        // ─── Product-level Sales Summary with delivered/open/free breakdown ───
        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $salesQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);

        if ($excludeFree) {
            $salesQuery->where(function($q) {
                $q->whereNull('t_crm_prod_order_line_item.is_free')
                  ->orWhere('t_crm_prod_order_line_item.is_free', 0);
            });
        }

        $productSales = $salesQuery->selectRaw('t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as total_revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 0 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as paid_qty,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.line_total ELSE 0 END) as delivered_revenue')
            ->groupBy('t_crm_prod_order_line_item.product_id', 't_crm_prod_order_line_item.name')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotalRevenue = $productSales->sum('total_revenue');
        $grandTotalQty = $productSales->sum('total_qty');
        $grandTotalDeliveredQty = $productSales->sum('delivered_qty');
        $grandTotalOpenQty = $productSales->sum('open_qty');
        $grandTotalFreeQty = $productSales->sum('free_qty');
        $ordersQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            });
        if ($excludeFree) {
            $ordersQuery->where(function($q) {
                $q->whereNull('is_free')->orWhere('is_free', 0);
            });
        }
        $grandTotalOrders = $ordersQuery->distinct('order_id')->count('order_id');

        // ─── Detailed Transactions (all line items with order info) ───
        $txnQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            });
        if ($excludeFree) {
            $txnQuery->where(function($q) {
                $q->whereNull('is_free')->orWhere('is_free', 0);
            });
        }
        $transactions = $txnQuery->with(['order:id,order_number,order_date,order_status,total_price,customer_id,payment_method', 'order.customer:id,first_name,last_name,phone_original'])
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        // ─── Available months dropdown (last 12 months) ───
        $availableMonths = [];
        for ($i = 0; $i < 12; $i++) {
            $m = date('Y-m', strtotime("-{$i} months"));
            $availableMonths[$m] = date('F Y', strtotime($m . '-01'));
        }

        return view('khaas.sales-report', compact(
            'khaasBU', 'selectedMonth', 'monthLabel', 'availableMonths',
            'productSales', 'grandTotalRevenue', 'grandTotalQty', 'grandTotalOrders',
            'grandTotalDeliveredQty', 'grandTotalOpenQty', 'grandTotalFreeQty',
            'transactions'
        ));
    }

    /**
     * API version of sales report for mobile
     */
    public function salesReportApi(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');

        // Get selling price per product (variant price or price_min)
        $priceByProduct = [];
        $variantPrices = \DB::table('t_crm_prod_product_variant')
            ->whereIn('product_id', $khaasProductIds)
            ->where('price', '>', 0)
            ->select('product_id', \DB::raw('MIN(price) as variant_price'))
            ->groupBy('product_id')
            ->get();
        foreach ($variantPrices as $vp) {
            if ($vp->variant_price > 0) {
                $priceByProduct[(int) $vp->product_id] = round((float) $vp->variant_price, 2);
            }
        }
        $productPrices = ProductModel::whereIn('id', $khaasProductIds)->pluck('price_min', 'id');
        foreach ($productPrices as $pid => $priceMin) {
            if (!isset($priceByProduct[(int) $pid]) && $priceMin > 0) {
                $priceByProduct[(int) $pid] = round((float) $priceMin, 2);
            }
        }

        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $salesQuery = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);

        if ($excludeFree) {
            $salesQuery->where(function($q) {
                $q->whereNull('t_crm_prod_order_line_item.is_free')
                  ->orWhere('t_crm_prod_order_line_item.is_free', 0);
            });
        }

        $productSales = $salesQuery->selectRaw('t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as total_revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupBy('t_crm_prod_order_line_item.product_id', 't_crm_prod_order_line_item.name')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotalRevenue = $productSales->sum('total_revenue');
        $totalFreeValue = 0;

        $mappedProducts = $productSales->map(function($ps) use ($grandTotalRevenue, $priceByProduct, &$totalFreeValue) {
            $pid = (int) $ps->product_id;
            $freeQty = (int) $ps->free_qty;
            $sellingPrice = $priceByProduct[$pid] ?? 0;
            $freeValue = round($freeQty * $sellingPrice, 2);
            $totalFreeValue += $freeValue;

            return [
                'product_id' => $pid,
                'name' => $ps->name,
                'total_qty' => (int) $ps->total_qty,
                'total_revenue' => (float) $ps->total_revenue,
                'order_count' => (int) $ps->order_count,
                'delivered_qty' => (int) $ps->delivered_qty,
                'open_qty' => (int) $ps->open_qty,
                'free_qty' => $freeQty,
                'free_value' => $freeValue,
                'selling_price' => $sellingPrice,
                'pct_of_total' => $grandTotalRevenue > 0 ? round(($ps->total_revenue / $grandTotalRevenue) * 100, 1) : 0,
            ];
        });

        return response()->json([
            'success' => true,
            'month' => $selectedMonth,
            'products' => $mappedProducts,
            'totals' => [
                'revenue' => (float) $productSales->sum('total_revenue'),
                'qty' => (int) $productSales->sum('total_qty'),
                'delivered_qty' => (int) $productSales->sum('delivered_qty'),
                'open_qty' => (int) $productSales->sum('open_qty'),
                'free_qty' => (int) $productSales->sum('free_qty'),
                'free_value' => round($totalFreeValue, 2),
            ],
        ]);
    }

    /**
     * Daily sales report: day-by-day totals with per-product breakdown
     * GET /warehouse/sales-report/daily?business_unit_id=X&month=YYYY-MM
     */
    public function salesReportDaily(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $khaasProductIds = ProductModel::where('business_unit_id', $khaasBU->id)->pluck('id');
        $excludeFree = filter_var($request->input('exclude_free', false), FILTER_VALIDATE_BOOLEAN);

        $freeFilter = function($q) {
            $q->whereNull('t_crm_prod_order_line_item.is_free')
              ->orWhere('t_crm_prod_order_line_item.is_free', 0);
        };

        // Day-level totals
        $dayQuery = \App\Models\CRM\OrderLineItemModel::whereIn('t_crm_prod_order_line_item.product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);
        if ($excludeFree) $dayQuery->where($freeFilter);

        $dayTotals = $dayQuery->selectRaw('DATE(o.order_date) as sale_date,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupByRaw('DATE(o.order_date)')
            ->orderByDesc('sale_date')
            ->get();

        // Per-product per-day breakdown
        $prodQuery = \App\Models\CRM\OrderLineItemModel::whereIn('t_crm_prod_order_line_item.product_id', $khaasProductIds)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd]);
        if ($excludeFree) $prodQuery->where($freeFilter);

        $productByDay = $prodQuery->selectRaw('DATE(o.order_date) as sale_date,
                t_crm_prod_order_line_item.product_id,
                t_crm_prod_order_line_item.name as product_name,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty')
            ->groupByRaw('DATE(o.order_date), t_crm_prod_order_line_item.product_id, t_crm_prod_order_line_item.name')
            ->orderBy('product_name')
            ->get()
            ->groupBy('sale_date');

        $days = $dayTotals->map(function ($day) use ($productByDay) {
            $date = $day->sale_date;
            $products = ($productByDay[$date] ?? collect())->map(function ($p) {
                return [
                    'product_id' => (int) $p->product_id,
                    'product_name' => $p->product_name,
                    'qty' => (int) $p->total_qty,
                    'revenue' => round((float) $p->revenue, 2),
                    'delivered_qty' => (int) $p->delivered_qty,
                    'free_qty' => (int) $p->free_qty,
                ];
            })->values();

            return [
                'date' => $date,
                'total_qty' => (int) $day->total_qty,
                'revenue' => round((float) $day->revenue, 2),
                'order_count' => (int) $day->order_count,
                'delivered_qty' => (int) $day->delivered_qty,
                'open_qty' => (int) $day->open_qty,
                'free_qty' => (int) $day->free_qty,
                'products' => $products,
            ];
        });

        return response()->json([
            'success' => true,
            'month' => $selectedMonth,
            'days' => $days,
        ]);
    }

    /**
     * Web-facing daily sales AJAX endpoint (session auth)
     */
    public function salesReportDailyWeb(Request $request)
    {
        $request->merge(['business_unit_id' => optional($this->getKhaasBU())->id]);
        return $this->salesReportDaily($request);
    }

    /**
     * Daily breakdown for a product in the sales report (AJAX)
     */
    public function productDailyBreakdown(Request $request, $productId)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $selectedMonth = $request->input('month', date('Y-m'));
        $monthStart = $selectedMonth . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $dailyData = \App\Models\CRM\OrderLineItemModel::where('product_id', $productId)
            ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
            ->whereNotIn('o.order_status', ['cancelled'])
            ->whereRaw('DATE(o.order_date) >= ?', [$monthStart])
            ->whereRaw('DATE(o.order_date) <= ?', [$monthEnd])
            ->selectRaw('DATE(o.order_date) as sale_date,
                SUM(t_crm_prod_order_line_item.quantity) as total_qty,
                SUM(t_crm_prod_order_line_item.line_total) as revenue,
                SUM(CASE WHEN o.order_status = "delivered" THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as delivered_qty,
                SUM(CASE WHEN o.order_status NOT IN ("delivered","cancelled") THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as open_qty,
                SUM(CASE WHEN COALESCE(t_crm_prod_order_line_item.is_free, 0) = 1 THEN t_crm_prod_order_line_item.quantity ELSE 0 END) as free_qty,
                COUNT(DISTINCT t_crm_prod_order_line_item.order_id) as order_count')
            ->groupByRaw('DATE(o.order_date)')
            ->orderByDesc('sale_date')
            ->get();

        return response()->json([
            'success' => true,
            'daily' => $dailyData,
        ]);
    }

    /**
     * Initiate transfer from web
     */
    public function initiateTransfer(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $request->validate([
            'product_id' => 'required|integer|exists:t_crm_prod_product,id',
            'product_variant_id' => 'nullable|integer',
            'business_unit_id' => 'required|integer',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        $productId = $request->input('product_id');
        $variantId = $request->input('product_variant_id');
        $businessUnitId = $request->input('business_unit_id');
        $quantity = $request->input('quantity');
        $notes = $request->input('notes');

        // ⭐ Always resolve variant_id to product's first variant if not provided
        if (!$variantId) {
            $firstVariant = ProductVariantModel::where('product_id', $productId)->first();
            if ($firstVariant) {
                $variantId = $firstVariant->id;
            }
        }

        DB::beginTransaction();
        try {
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);

            if ($inventory->quantity < $quantity) {
                DB::rollBack();
                return back()->with('error', "Insufficient warehouse stock. Available: {$inventory->quantity}, Requested: {$quantity}");
            }

            // Deduct from warehouse
            $inventory->adjustQuantity(-$quantity, 'transfer',
                "Transfer to store (pending approval): {$quantity} units",
                'transfer_to_store', null);

            // Create pending transfer
            WarehouseTransferModel::create([
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'business_unit_id' => $businessUnitId,
                'from_location' => 'warehouse',
                'to_location' => 'store',
                'quantity' => $quantity,
                'status' => WarehouseTransferModel::STATUS_PENDING,
                'requested_by' => auth()->id(),
                'notes' => $notes,
            ]);

            DB::commit();
            return back()->with('success', "Transfer of {$quantity} units initiated. Pending approval.");
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Failed to initiate transfer: ' . $e->getMessage());
        }
    }

    /**
     * GET /khaas/products/{productId}/store-log
     * Returns the last N transactions that changed the store (shop) inventory for a product.
     * Combines: approved transfers IN + order line item deductions OUT + cancellation restores IN.
     * Computes a running balance so the user can trace how the current count was reached.
     */
    public function getStoreInventoryLog(Request $request, $productId)
    {
        $user = auth()->user();
        $canAccess = $this->hasKhaasAccess()
            || ($user && $user->hasMobilePermission('view_khaas_store_inventory'))
            || ($user && $user->hasMobilePermission('access_store_mode'));
        if (!$canAccess) {
            return response()->json(['success' => false, 'message' => 'No access'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
        }

        $product = ProductModel::with('variants')->find($productId);
        if (!$product || $product->business_unit_id !== $khaasBU->id) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        $limit = (int) ($request->get('limit', 30));
        $variantIds = $product->variants->pluck('id')->toArray();

        // Current store qty
        $currentStoreQty = $product->variants->sum('inventory_quantity') ?: ($product->total_inventory ?? 0);

        $events = collect();

        // ─── 1) Approved transfers TO store (INFLOW) ───
        $transfers = WarehouseTransferModel::where('product_id', $productId)
            ->where('status', 'approved')
            ->where('to_location', 'store')
            ->with('approver:id,fullname')
            ->with('requester:id,fullname')
            ->orderBy('approved_at', 'desc')
            ->limit($limit * 2) // fetch more to have enough after merge
            ->get();

        foreach ($transfers as $t) {
            $events->push([
                'date' => $t->approved_at ?? $t->updated_at ?? $t->created_at,
                'type' => 'transfer_in',
                'label' => 'Transfer to Store',
                'change' => +$t->quantity,
                'quantity' => $t->quantity,
                'detail' => 'Approved by ' . ($t->approver->fullname ?? 'System'),
                'sub_detail' => 'Requested by ' . ($t->requester->fullname ?? 'N/A'),
                'reference' => 'Transfer #' . $t->id,
                'icon' => '📥',
                'color' => 'green',
            ]);
        }

        // ─── 2) Order line item deductions (OUTFLOW) — inventory_deducted = 1 ───
        // These are items where inventory was deducted from the store (mark prepared / out for delivery)
        if (!empty($variantIds)) {
            $deductedItems = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->whereIn('li.variant_id', $variantIds)
                ->where('li.inventory_deducted', 1)
                ->where('o.order_status', '!=', 'cancelled') // active orders only
                ->select([
                    'li.id as line_item_id',
                    'li.order_id',
                    'li.quantity',
                    'li.name',
                    'li.updated_at',
                    'o.order_number',
                    'o.order_status',
                    'o.name as customer_name',
                ])
                ->orderBy('li.updated_at', 'desc')
                ->limit($limit * 2)
                ->get();

            foreach ($deductedItems as $item) {
                $events->push([
                    'date' => $item->updated_at,
                    'type' => 'order_deduction',
                    'label' => 'Order Deduction',
                    'change' => -$item->quantity,
                    'quantity' => $item->quantity,
                    'detail' => 'Order #' . ($item->order_number ?? $item->order_id),
                    'sub_detail' => $item->customer_name ? ('Customer: ' . $item->customer_name) : ('Status: ' . $item->order_status),
                    'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                    'icon' => '📦',
                    'color' => 'red',
                ]);
            }
        }

        // ─── 3) Cancelled orders that HAD inventory deducted (shows BOTH deduction + restoration) ───
        // When an order is cancelled after being prepared/out_for_delivery:
        //   - restoreInventory() sets inventory_deducted = 0
        //   - But the deduction DID happen earlier, so we need to show BOTH events
        // We use order status history to get accurate timestamps for each event.
        if (!empty($variantIds)) {
            $cancelledItems = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->whereIn('li.variant_id', $variantIds)
                ->where('li.inventory_deducted', 0)
                ->where('o.order_status', 'cancelled')
                // Only items that were prepared (inventory was once deducted then restored)
                ->where('li.preparation_status', 'preparing')
                ->select([
                    'li.id as line_item_id',
                    'li.order_id',
                    'li.quantity',
                    'li.name',
                    'o.updated_at as order_updated_at',
                    'o.order_number',
                    'o.name as customer_name',
                ])
                ->orderBy('o.updated_at', 'desc')
                ->limit($limit)
                ->get();

            if ($cancelledItems->isNotEmpty()) {
                // Batch-fetch status history for all cancelled orders (for accurate timestamps)
                $cancelledOrderIds = $cancelledItems->pluck('order_id')->unique()->toArray();
                $statusHistory = DB::table('t_crm_order_status_history')
                    ->whereIn('order_id', $cancelledOrderIds)
                    ->whereIn('status_code', ['out_for_delivery', 'cancelled'])
                    ->get()
                    ->groupBy('order_id');

                foreach ($cancelledItems as $item) {
                    $history = $statusHistory->get($item->order_id, collect());
                    $ofdRecord = $history->where('status_code', 'out_for_delivery')->sortByDesc('changed_at')->first();
                    $cancelRecord = $history->where('status_code', 'cancelled')->sortByDesc('changed_at')->first();

                    $deductionDate = $ofdRecord->changed_at ?? $item->order_updated_at;
                    $cancellationDate = $cancelRecord->changed_at ?? $item->order_updated_at;

                    // Event A: The deduction that happened when the order was prepared/sent out
                    $events->push([
                        'date' => $deductionDate,
                        'type' => 'order_deduction',
                        'label' => 'Order Deduction',
                        'change' => -$item->quantity,
                        'quantity' => $item->quantity,
                        'detail' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'sub_detail' => ($item->customer_name ? ('Customer: ' . $item->customer_name) : '') . ' · Later cancelled',
                        'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'icon' => '📦',
                        'color' => 'red',
                    ]);

                    // Event B: The restoration when the order was cancelled
                    $events->push([
                        'date' => $cancellationDate,
                        'type' => 'cancellation_restore',
                        'label' => 'Cancelled → Restored',
                        'change' => +$item->quantity,
                        'quantity' => $item->quantity,
                        'detail' => 'Order #' . ($item->order_number ?? $item->order_id) . ' cancelled',
                        'sub_detail' => $item->customer_name ? ('Customer: ' . $item->customer_name) : '',
                        'reference' => 'Order #' . ($item->order_number ?? $item->order_id),
                        'icon' => '🔄',
                        'color' => 'blue',
                    ]);
                }
            }
        }

        // ─── 4) Manual store inventory adjustments ───
        $storeAdjustments = \App\Models\CRM\StoreInventoryAdjustmentModel::where('product_id', $productId)
            ->where('business_unit_id', $khaasBU->id)
            ->with('creator:id,fullname')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        foreach ($storeAdjustments as $adj) {
            $typeLabel = match($adj->change_type) {
                'store_stock_in' => 'Store Stock In',
                'store_stock_out' => 'Store Stock Out',
                'store_count' => 'Store Count Set',
                'store_adjustment' => 'Store Adjustment',
                default => 'Store Adjustment',
            };
            $isPositive = $adj->quantity_change > 0;

            $events->push([
                'date' => $adj->created_at,
                'type' => 'store_adjustment',
                'label' => $typeLabel,
                'change' => $adj->quantity_change,
                'quantity' => abs($adj->quantity_change),
                'detail' => 'By ' . ($adj->creator->fullname ?? 'User #' . $adj->created_by),
                'sub_detail' => $adj->notes ? ('📝 ' . $adj->notes) : ($adj->quantity_before . ' → ' . $adj->quantity_after),
                'reference' => 'Adjustment #' . $adj->id,
                'icon' => $isPositive ? '🏪' : '🏪',
                'color' => $isPositive ? 'purple' : 'orange',
            ]);
        }

        // ─── Sort by date (newest first) and take the limit ───
        $events = $events->sortByDesc('date')->take($limit)->values();

        // ─── Compute running balance (walk backward from current qty) ───
        $runningBalance = $currentStoreQty;
        $eventsWithBalance = [];

        foreach ($events as $event) {
            $event['balance_after'] = $runningBalance;
            $runningBalance = $runningBalance - $event['change'];
            $event['balance_before'] = $runningBalance;
            $parsedDate = $event['date'] ? \Carbon\Carbon::parse($event['date']) : null;
            $event['date_formatted'] = $parsedDate ? $parsedDate->format('M d, h:i A') : 'Unknown';
            $event['date_ago'] = $parsedDate ? $parsedDate->diffForHumans() : '';
            $event['date_day'] = $parsedDate ? $parsedDate->toDateString() : 'unknown';
            $event['date_day_label'] = $parsedDate ? ($parsedDate->isToday() ? 'Today' : ($parsedDate->isYesterday() ? 'Yesterday' : $parsedDate->format('D, M d'))) : 'Unknown';
            $eventsWithBalance[] = $event;
        }

        // Group events by day for structured display
        $days = [];
        foreach ($eventsWithBalance as $ev) {
            $dayKey = $ev['date_day'];
            if (!isset($days[$dayKey])) {
                $days[$dayKey] = [
                    'date' => $dayKey,
                    'label' => $ev['date_day_label'],
                    'events' => [],
                ];
            }
            $days[$dayKey]['events'][] = $ev;
        }
        // Add opening/closing balance per day
        foreach ($days as &$day) {
            $dayEvents = $day['events'];
            $day['closing_balance'] = $dayEvents[0]['balance_after'];
            $day['opening_balance'] = end($dayEvents)['balance_before'];
            $day['net_change'] = $day['closing_balance'] - $day['opening_balance'];
        }
        unset($day);

        return response()->json([
            'success' => true,
            'product_name' => $product->title,
            'current_store_qty' => $currentStoreQty,
            'events' => $eventsWithBalance,
            'days' => array_values($days),
            'events_count' => count($eventsWithBalance),
        ]);
    }

    /**
     * Khaas Meat Order — Track Orders & Place New Order
     */
    public function meatOrder(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'orders');

        // ⭐ Orders pending approval (still in the approval queue)
        $pendingApproval = \App\Models\CRM\ShopifyOrderModel::with('lineItems')
            ->where('external_source', 'khaas_storage')
            ->where(function($q) {
                $q->whereNull('converted')->orWhere('converted', 0);
            })
            ->where('khaas_business_unit_id', $khaasBU->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(function($o) {
                return (object)[
                    'id' => $o->id,
                    'order_id' => null,
                    'order_number' => $o->order_number,
                    'nf_order_status' => 'pending_approval',
                    'total_amount' => $o->total_price,
                    'status' => 'pending_approval',
                    'notes' => $o->note,
                    'order_created_at' => $o->created_at,
                    'created_by' => $o->created_by,
                    'created_by_name' => $o->created_by
                        ? DB::table('t_sys_user')->where('id', $o->created_by)->value('fullname')
                        : null,
                    'items' => $o->lineItems->map(function($li) {
                        $productName = DB::table('t_crm_prod_product')->where('id', $li->product_id)->value('title');
                        return (object)[
                            'product_name' => $productName ?? $li->name,
                            'quantity' => $li->quantity,
                            'unit_price' => $li->unit_price,
                            'line_total' => $li->line_total,
                        ];
                    }),
                ];
            });

        // Storage orders with NF order status (already approved/converted)
        $orders = DB::table('t_crm_khaas_storage_order as so')
            ->join('t_crm_prod_order as o', 'o.id', '=', 'so.order_id')
            ->where('so.khaas_business_unit_id', $khaasBU->id)
            ->select(
                'so.*',
                'o.order_number',
                'o.order_status as nf_order_status',
                'o.total_price as total_amount',
                'o.created_at as order_created_at'
            )
            ->orderByDesc('so.created_at')
            ->limit(50)
            ->get();

        // Get line items for each order
        foreach ($orders as $order) {
            $order->items = DB::table('t_crm_prod_order_line_item as oi')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'oi.product_id')
                ->where('oi.order_id', $order->order_id)
                ->select('oi.*', 'p.title as product_name')
                ->get();
            $order->created_by_name = $order->created_by
                ? DB::table('t_sys_user')->where('id', $order->created_by)->value('fullname')
                : null;
        }

        // Active orders = not yet received, not cancelled (show at top)
        // Completed/cancelled orders go below
        $pendingReceive = $orders->filter(function ($o) {
            return !in_array($o->status, ['received', 'cancelled'])
                && $o->nf_order_status !== 'cancelled';
        });
        $otherOrders = $orders->filter(function ($o) {
            return in_array($o->status, ['received', 'cancelled'])
                || $o->nf_order_status === 'cancelled';
        });

        // Configured storage products for new order
        $storageProducts = DB::table('t_crm_khaas_storage_config as sc')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
            ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'sc.source_variant_id')
            ->where('sc.khaas_business_unit_id', $khaasBU->id)
            ->where('sc.is_active', 1)
            ->select('sc.*', 'p.title as product_title', 'v.title as variant_title', 'sc.default_unit')
            ->orderBy('sc.sort_order')
            ->orderBy('p.title')
            ->get();

        // Settings (from config table, same as the mobile API's getStorageInventory)
        $vendorId = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
            ->where('business_unit_id', $khaasBU->id)->value('config_value');
        $vendorName = null;
        if ($vendorId) {
            $vendorName = \App\Models\FIN\VendorModel::where('id', $vendorId)->value('vendor_name');
        }
        $settings = (object)[
            'vendor_name' => $vendorName ?: 'Nizami Farms',
        ];

        return view('khaas.meat-order', compact(
            'khaasBU', 'activeTab', 'orders', 'pendingReceive', 'otherOrders',
            'pendingApproval', 'storageProducts', 'settings'
        ));
    }

    /**
     * Khaas Meat Order — receive a delivered order into storage
     */
    public function receiveStorageOrder($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $storageOrder = DB::table('t_crm_khaas_storage_order')->where('id', $id)
            ->where('khaas_business_unit_id', $khaasBU->id)->first();
        if (!$storageOrder || $storageOrder->status === 'received') {
            return back()->with('error', 'Order not found or already received.');
        }

        // Call the existing API method via the WarehouseController
        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $fakeRequest = new Request();
        $response = $controller->receiveStorageOrder($fakeRequest, $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Order received into storage.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to receive order.');
    }

    /**
     * Khaas Meat Order — place a new order (web form submission)
     */
    public function placeStorageOrder(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        // Filter out items with no quantity
        $items = collect($request->input('items', []))->filter(function ($item) {
            return isset($item['quantity']) && (float)$item['quantity'] > 0;
        })->values()->toArray();

        if (empty($items)) {
            return back()->with('error', 'Please enter quantity for at least one item.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'items' => $items,
            'notes' => $request->input('notes'),
        ]);
        $response = $controller->placeStorageOrder($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.meat-order', ['tab' => 'orders'])
                ->with('success', $data['message'] ?? 'Order placed successfully.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to place order.');
    }

    /**
     * Khaas Inventory — Current Stock & Production Plan
     */
    public function inventory(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403, 'You do not have access to Khaas mode.');
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return redirect('/dashboard')->with('error', 'Khaas business unit not found.');
        }

        $activeTab = $request->input('tab', 'stock');

        // === CURRENT STOCK ===
        $configuredProducts = DB::table('t_crm_khaas_storage_config as sc')
            ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
            ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'sc.source_variant_id')
            ->where('sc.khaas_business_unit_id', $khaasBU->id)
            ->where('sc.is_active', 1)
            ->select('sc.*', 'p.title as product_title', 'v.title as variant_title')
            ->orderBy('sc.sort_order')
            ->orderBy('p.title')
            ->get();

        $inventoryRows = DB::table('t_crm_khaas_storage_inventory')
            ->where('khaas_business_unit_id', $khaasBU->id)
            ->get()
            ->keyBy(function ($row) {
                return $row->source_product_id . '_' . ($row->source_variant_id ?: '0');
            });

        // Compute processing quantities — supports both old (single storage_product_id) and new (multi-recipe) demand items
        $inProgressItems = DB::table('t_crm_khaas_production_demand_item as di')
            ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('di.status', ['in_progress', 'accepted'])
            ->where('di.storage_deducted', 1)
            ->select('di.khaas_product_id', 'di.quantity_kg', 'di.storage_product_id', 'di.storage_deducted_qty')
            ->get();

        $processingQtys = [];
        foreach ($inProgressItems as $item) {
            if ($item->storage_product_id) {
                $pid = $item->storage_product_id;
                $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + (float)$item->storage_deducted_qty;
            } else {
                $recipes = DB::table('t_crm_khaas_product_recipe')
                    ->where('khaas_product_id', $item->khaas_product_id)
                    ->where('is_active', 1)
                    ->get();
                foreach ($recipes as $recipe) {
                    $pid = $recipe->storage_product_id;
                    $deductQty = round((float)$item->quantity_kg * (float)$recipe->ratio_kg, 3);
                    $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + $deductQty;
                }
            }
        }

        $stockItems = $configuredProducts->map(function ($p) use ($inventoryRows, $processingQtys) {
            $key = $p->source_product_id . '_' . ($p->source_variant_id ?: '0');
            $inv = $inventoryRows[$key] ?? null;
            return (object)[
                'config_id' => $p->id,
                'product_id' => $p->source_product_id,
                'variant_id' => $p->source_variant_id,
                'name' => $p->display_name ?: $p->product_title,
                'variant_title' => $p->variant_title,
                'unit' => $p->default_unit ?: 'kg',
                'quantity' => $inv ? round((float)$inv->quantity_on_hand, 3) : 0,
                'processing_qty' => round((float)($processingQtys[$p->source_product_id] ?? 0), 3),
                'last_received' => $inv ? $inv->last_received_at : null,
            ];
        });

        // === PRODUCTION DEMANDS ===
        $demands = DB::table('t_crm_khaas_production_demand as d')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('d.status', ['submitted', 'accepted', 'in_progress'])
            ->orderByDesc('d.created_at')
            ->limit(20)
            ->get();

        foreach ($demands as $demand) {
            $demand->items = DB::table('t_crm_khaas_production_demand_item as di')
                ->leftJoin('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->where('di.demand_id', $demand->id)
                ->select('di.*', 'kp.title as product_name')
                ->get();
            $demand->created_by_name = $demand->created_by
                ? DB::table('t_sys_user')->where('id', $demand->created_by)->value('fullname')
                : null;
        }

        // Demand history (completed/cancelled)
        $demandHistory = DB::table('t_crm_khaas_production_demand as d')
            ->where('d.business_unit_id', $khaasBU->id)
            ->whereIn('d.status', ['completed', 'cancelled'])
            ->orderByDesc('d.created_at')
            ->limit(20)
            ->get();

        foreach ($demandHistory as $demand) {
            $demand->items = DB::table('t_crm_khaas_production_demand_item as di')
                ->leftJoin('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->where('di.demand_id', $demand->id)
                ->select('di.*', 'kp.title as product_name')
                ->get();
            $demand->created_by_name = $demand->created_by
                ? DB::table('t_sys_user')->where('id', $demand->created_by)->value('fullname')
                : null;
        }

        $pendingDemandCount = DB::table('t_crm_khaas_production_demand')
            ->where('business_unit_id', $khaasBU->id)
            ->where('status', 'submitted')
            ->count();

        // === DEMAND PRODUCTS (for create plan form) ===
        $demandProducts = collect();
        if ($activeTab === 'production') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $apiReq = new Request(['business_unit_id' => $khaasBU->id]);
            $resp = $controller->getDemandProducts($apiReq);
            $data = json_decode($resp->getContent(), true);
            $demandProducts = collect($data['products'] ?? []);
        }

        // === RECIPES (for recipes tab) ===
        $recipes = collect();
        $khaasProducts = collect();
        $storageProductsForRecipe = collect();
        $customMaterials = collect();
        if ($activeTab === 'recipes') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $apiReq = new Request(['business_unit_id' => $khaasBU->id]);
            $resp = $controller->getProductRecipes($apiReq);
            $data = json_decode($resp->getContent(), true);
            $recipes = collect($data['recipes'] ?? []);

            $khaasProducts = ProductModel::where('business_unit_id', $khaasBU->id)
                ->where('status', 'active')
                ->select('id', 'title')
                ->orderBy('title')
                ->get();

            $storageProductsForRecipe = DB::table('t_crm_khaas_storage_config as sc')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
                ->where('sc.khaas_business_unit_id', $khaasBU->id)
                ->where('sc.is_active', 1)
                ->select('sc.source_product_id as product_id', 'sc.source_variant_id as variant_id', 'sc.display_name', 'p.title as product_title')
                ->orderBy('p.title')
                ->get()
                ->map(function ($p) {
                    $p->name = $p->display_name ?: $p->product_title;
                    return $p;
                });

            try {
                $customMaterials = DB::table('t_crm_khaas_custom_material')
                    ->where('business_unit_id', $khaasBU->id)
                    ->where('is_active', 1)
                    ->orderBy('name')
                    ->get(['id', 'name', 'unit']);
            } catch (\Exception $e) {
                $customMaterials = collect();
            }
        }

        // === CONFIGURE STORAGE PRODUCTS (for config tab) ===
        $availableProducts = collect();
        $configuredProductIds = [];
        if ($activeTab === 'config') {
            $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
            $resp = $controller->getAvailableStorageProducts(new Request());
            $data = json_decode($resp->getContent(), true);
            $availableProducts = collect($data['products'] ?? []);

            $configuredProductIds = DB::table('t_crm_khaas_storage_config')
                ->where('khaas_business_unit_id', $khaasBU->id)
                ->where('is_active', 1)
                ->pluck('source_product_id')
                ->toArray();
        }

        return view('khaas.inventory', compact(
            'khaasBU', 'activeTab', 'stockItems', 'demands', 'demandHistory', 'pendingDemandCount',
            'demandProducts', 'recipes', 'khaasProducts', 'storageProductsForRecipe',
            'customMaterials', 'availableProducts', 'configuredProductIds'
        ));
    }

    /**
     * Khaas Inventory — create a new production demand
     */
    public function createDemand(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $items = collect($request->input('items', []))->filter(function ($item) {
            return isset($item['quantity_kg']) && (float)$item['quantity_kg'] > 0;
        })->values()->toArray();

        if (empty($items)) {
            return back()->with('error', 'Enter weight for at least one product.');
        }

        $tomorrow = now()->addDay()->toDateString();

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'demand_date' => $request->input('demand_date', $tomorrow),
            'items' => array_map(function ($i) {
                return [
                    'khaas_product_id' => $i['khaas_product_id'],
                    'quantity_kg' => (float) $i['quantity_kg'],
                ];
            }, $items),
            'notes' => $request->input('notes'),
        ]);

        $response = $controller->createDemand($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'production'])
                ->with('success', $data['message'] ?? 'Production demand created.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to create demand.');
    }

    /**
     * Khaas Inventory — accept a production demand
     */
    public function acceptDemand($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $response = $controller->acceptDemand(new Request(), $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Demand accepted.');
        }

        $msg = $data['message'] ?? 'Failed to accept demand.';
        if (!empty($data['shortages'])) {
            $msg .= ' — ' . implode(', ', $data['shortages']);
        }
        return back()->with('error', $msg);
    }

    /**
     * Khaas Inventory — cancel a production demand
     */
    public function cancelDemand($id)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $response = $controller->cancelDemand(new Request(), $id);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return back()->with('success', $data['message'] ?? 'Demand cancelled.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to cancel demand.');
    }

    /**
     * Khaas Inventory — save recipe mapping(s). Accepts single or multiple storage_product_ids.
     */
    public function saveRecipe(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Khaas BU not found'], 404);
            }
            return back()->with('error', 'Khaas business unit not found.');
        }

        $khaasProductId = $request->input('khaas_product_id');
        $storageProductId = $request->input('storage_product_id');
        $customMaterialIds = $request->input('custom_material_ids', []);
        if (!is_array($customMaterialIds)) $customMaterialIds = array_filter([$customMaterialIds]);

        // Support array of storage_product_ids for multi-select
        $storageProductIds = is_array($storageProductId) ? $storageProductId : [$storageProductId];
        $storageProductIds = array_filter($storageProductIds);

        if (empty($khaasProductId) || (empty($storageProductIds) && empty($customMaterialIds))) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Select a product and at least one raw material'], 400);
            }
            return back()->with('error', 'Select a product and at least one raw material.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $savedCount = 0;
        $lastError = null;

        foreach ($storageProductIds as $spId) {
            $apiRequest = new Request([
                'business_unit_id' => $khaasBU->id,
                'khaas_product_id' => $khaasProductId,
                'storage_product_id' => $spId,
                'storage_variant_id' => $request->input('storage_variant_id'),
            ]);

            $response = $controller->saveProductRecipe($apiRequest);
            $data = json_decode($response->getContent(), true);

            if ($data['success'] ?? false) {
                $savedCount++;
            } else {
                $lastError = $data['message'] ?? 'Failed to save';
            }
        }

        foreach ($customMaterialIds as $cmId) {
            $apiRequest = new Request([
                'business_unit_id' => $khaasBU->id,
                'khaas_product_id' => $khaasProductId,
                'custom_material_id' => $cmId,
            ]);

            $response = $controller->saveProductRecipe($apiRequest);
            $data = json_decode($response->getContent(), true);

            if ($data['success'] ?? false) {
                $savedCount++;
            } else {
                $lastError = $data['message'] ?? 'Failed to save';
            }
        }

        $message = $savedCount > 0
            ? "Saved {$savedCount} recipe mapping" . ($savedCount > 1 ? 's' : '') . '.'
            : ($lastError ?? 'Failed to save recipe.');

        if ($request->expectsJson()) {
            return response()->json([
                'success' => $savedCount > 0,
                'message' => $message,
                'saved_count' => $savedCount,
            ]);
        }

        if ($savedCount > 0) {
            return redirect()->route('khaas.inventory', ['tab' => 'recipes'])
                ->with('success', $message);
        }
        return back()->with('error', $message);
    }

    /**
     * Web-facing: create a custom material (session auth)
     */
    public function saveCustomMaterialWeb(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            return response()->json(['success' => false, 'message' => 'Access denied'], 403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return response()->json(['success' => false, 'message' => 'BU not found'], 404);
        }

        $request->merge(['business_unit_id' => $khaasBU->id]);
        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        return $controller->saveCustomMaterial($request);
    }

    /**
     * Khaas Inventory — delete a recipe mapping
     */
    public function deleteRecipe(Request $request, $recipeId)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request(['recipe_id' => $recipeId]);
        $response = $controller->deleteProductRecipe($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'recipes'])
                ->with('success', $data['message'] ?? 'Recipe mapping removed.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to remove recipe.');
    }

    /**
     * Khaas Inventory — add/remove product from storage configuration
     */
    public function updateStorageConfig(Request $request)
    {
        if (!$this->hasKhaasAccess()) {
            abort(403);
        }

        $khaasBU = $this->getKhaasBU();
        if (!$khaasBU) {
            return back()->with('error', 'Khaas business unit not found.');
        }

        $controller = app(\App\Http\Controllers\CRM\WarehouseController::class);
        $apiRequest = new Request([
            'business_unit_id' => $khaasBU->id,
            'product_id' => $request->input('product_id'),
            'action' => $request->input('action'),
            'display_name' => $request->input('display_name'),
            'default_unit' => $request->input('default_unit', 'kg'),
        ]);

        $response = $controller->updateStorageConfig($apiRequest);
        $data = json_decode($response->getContent(), true);

        if ($data['success'] ?? false) {
            return redirect()->route('khaas.inventory', ['tab' => 'config'])
                ->with('success', $data['message'] ?? 'Configuration updated.');
        }
        return back()->with('error', $data['message'] ?? 'Failed to update configuration.');
    }
}
