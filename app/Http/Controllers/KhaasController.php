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

        // ─── Product-level Sales Summary ───
        $productSales = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            })
            ->selectRaw('product_id, name, SUM(quantity) as total_qty, SUM(line_total) as total_revenue, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('product_id', 'name')
            ->orderByDesc('total_revenue')
            ->get();

        $grandTotalRevenue = $productSales->sum('total_revenue');
        $grandTotalQty = $productSales->sum('total_qty');
        $grandTotalOrders = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            })
            ->distinct('order_id')
            ->count('order_id');

        // ─── Detailed Transactions (all line items with order info) ───
        $transactions = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $khaasProductIds)
            ->whereHas('order', function($q) use ($monthStart, $monthEnd) {
                $q->whereNotIn('order_status', ['cancelled'])
                  ->whereRaw('DATE(order_date) >= ?', [$monthStart])
                  ->whereRaw('DATE(order_date) <= ?', [$monthEnd]);
            })
            ->with(['order:id,order_number,order_date,order_status,total_price,customer_id,payment_method', 'order.customer:id,first_name,last_name,phone_original'])
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
            'transactions'
        ));
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
}
