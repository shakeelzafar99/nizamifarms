<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\CRM\WarehouseInventoryModel;
use App\Models\CRM\WarehouseInventoryLogModel;
use App\Models\CRM\WarehouseTransferModel;
use App\Models\CRM\ProductModel;
use App\Models\CRM\ProductVariantModel;
use App\Models\CRM\ProductBatchModel;

class WarehouseController extends Controller
{
    /**
     * Get warehouse inventory for products (used by Khaas Products screen)
     * Returns warehouse qty + pending transfers per product
     */
    public function getInventory(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }
            
            // Get all warehouse inventory for this BU
            // ⭐ FIX: Aggregate by product_id (sum across variants) since the product card
            // displays at product level, not variant level
            $inventoryRaw = WarehouseInventoryModel::where('business_unit_id', $businessUnitId)
                ->where('is_active', true)
                ->get();
            
            // Aggregate by product_id
            $inventoryByProduct = [];
            foreach ($inventoryRaw as $inv) {
                $pid = (string)$inv->product_id;
                if (!isset($inventoryByProduct[$pid])) {
                    $inventoryByProduct[$pid] = [
                        'warehouse_qty' => 0,
                        'unit' => $inv->unit ?? 'pcs',
                        'warehouse_location' => $inv->warehouse_location,
                        'min_stock_level' => $inv->min_stock_level ?? 0,
                    ];
                }
                $inventoryByProduct[$pid]['warehouse_qty'] += $inv->quantity;
            }
            
            // Get pending transfers TO store - aggregate by product_id
            $pendingTransfersRaw = WarehouseTransferModel::where('business_unit_id', $businessUnitId)
                ->where('status', WarehouseTransferModel::STATUS_PENDING)
                ->where('to_location', 'store')
                ->with(['requester:id,fullname'])
                ->get();
            
            $pendingByProduct = [];
            foreach ($pendingTransfersRaw as $t) {
                $pid = (string)$t->product_id;
                if (!isset($pendingByProduct[$pid])) {
                    $pendingByProduct[$pid] = ['qty' => 0, 'transfers' => []];
                }
                $pendingByProduct[$pid]['qty'] += $t->quantity;
                $pendingByProduct[$pid]['transfers'][] = [
                    'id' => $t->id,
                    'quantity' => $t->quantity,
                    'notes' => $t->notes,
                    'requested_by' => $t->requester?->fullname ?? 'Unknown',
                    'created_at' => $t->created_at?->format('Y-m-d H:i'),
                ];
            }
            
            // Build response keyed by product_id
            $result = [];
            
            // Add inventory records
            foreach ($inventoryByProduct as $pid => $inv) {
                $result[$pid] = [
                    'warehouse_qty' => $inv['warehouse_qty'],
                    'unit' => $inv['unit'],
                    'warehouse_location' => $inv['warehouse_location'],
                    'min_stock_level' => $inv['min_stock_level'],
                    'pending_to_store' => $pendingByProduct[$pid]['qty'] ?? 0,
                    'pending_transfers' => $pendingByProduct[$pid]['transfers'] ?? [],
                ];
            }
            
            // Also include products that have pending transfers but no inventory record
            foreach ($pendingByProduct as $pid => $pending) {
                if (!isset($result[$pid])) {
                    $result[$pid] = [
                        'warehouse_qty' => 0,
                        'unit' => 'pcs',
                        'warehouse_location' => null,
                        'min_stock_level' => 0,
                        'pending_to_store' => $pending['qty'],
                        'pending_transfers' => $pending['transfers'],
                    ];
                }
            }
            
            // ⭐ 3-day sales data: Get order quantities per product for today, yesterday, day-before
            // Uses order_date (not delivery_date) since stock is reduced when order is placed
            $salesByProduct = [];
            try {
                $today = now()->startOfDay();
                $threeDaysAgo = $today->copy()->subDays(2); // day-before-yesterday start
                
                // Get all product IDs for this BU
                $buProductIds = ProductModel::where('business_unit_id', $businessUnitId)->pluck('id')->toArray();
                
                if (!empty($buProductIds)) {
                    $salesRows = \App\Models\CRM\OrderLineItemModel::whereIn('product_id', $buProductIds)
                        ->whereHas('order', function($q) use ($threeDaysAgo) {
                            $q->whereNotIn('order_status', ['cancelled'])
                              ->where('order_date', '>=', $threeDaysAgo->toDateString());
                        })
                        ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
                        ->select(
                            't_crm_prod_order_line_item.product_id',
                            DB::raw('DATE(o.order_date) as sale_date'),
                            DB::raw('SUM(t_crm_prod_order_line_item.quantity) as total_qty')
                        )
                        ->groupBy('t_crm_prod_order_line_item.product_id', DB::raw('DATE(o.order_date)'))
                        ->get();
                    
                    $todayStr = now()->toDateString();
                    $yesterdayStr = now()->subDay()->toDateString();
                    $dayBeforeStr = now()->subDays(2)->toDateString();
                    
                    foreach ($salesRows as $row) {
                        $pid = (string)$row->product_id;
                        if (!isset($salesByProduct[$pid])) {
                            $salesByProduct[$pid] = ['today' => 0, 'yesterday' => 0, 'day_before' => 0];
                        }
                        $dateStr = $row->sale_date;
                        if ($dateStr === $todayStr) {
                            $salesByProduct[$pid]['today'] += (int)$row->total_qty;
                        } elseif ($dateStr === $yesterdayStr) {
                            $salesByProduct[$pid]['yesterday'] += (int)$row->total_qty;
                        } elseif ($dateStr === $dayBeforeStr) {
                            $salesByProduct[$pid]['day_before'] += (int)$row->total_qty;
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to fetch 3-day sales data', ['error' => $e->getMessage()]);
                // Non-critical, continue without sales data
            }
            
            return response()->json([
                'success' => true,
                'warehouse_inventory' => $result,
                'product_sales' => $salesByProduct,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get warehouse inventory', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load warehouse inventory'], 500);
        }
    }

    /**
     * Update warehouse stock (stock in / stock out / adjustment)
     */
    public function updateStock(Request $request)
    {
        try {
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
            
            // For stock_out, ensure quantity is negative
            if ($changeType === 'stock_out' && $quantityChange > 0) {
                $quantityChange = -$quantityChange;
            }
            
            // For stock_in, ensure quantity is positive
            if ($changeType === 'stock_in' && $quantityChange < 0) {
                $quantityChange = abs($quantityChange);
            }
            
            DB::beginTransaction();
            
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);
            
            // For count type, set absolute quantity
            if ($changeType === 'count') {
                $before = $inventory->quantity;
                $inventory->quantity = $quantityChange; // Set directly
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
                    'notes' => $notes ?? 'Physical stock count',
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
            } else {
                // Validate stock_out doesn't go below 0
                if ($changeType === 'stock_out' && ($inventory->quantity + $quantityChange) < 0) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient stock. Current: {$inventory->quantity}, Requested: " . abs($quantityChange)
                    ], 400);
                }
                
                $inventory->adjustQuantity($quantityChange, $changeType, $notes);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Warehouse stock updated',
                'inventory' => [
                    'quantity' => $inventory->quantity,
                    'unit' => $inventory->unit,
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update warehouse stock', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update stock: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Initiate a transfer from warehouse to store (requires approval)
     */
    public function initiateTransfer(Request $request)
    {
        try {
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
            
            // ⭐ Always resolve variant_id to the product's first variant if not provided
            if (!$variantId) {
                $firstVariant = ProductVariantModel::where('product_id', $productId)->first();
                if ($firstVariant) {
                    $variantId = $firstVariant->id;
                }
            }
            
            DB::beginTransaction();
            
            // Check warehouse has enough stock
            $inventory = WarehouseInventoryModel::getOrCreate($productId, $variantId, $businessUnitId);
            
            if ($inventory->quantity < $quantity) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient warehouse stock. Available: {$inventory->quantity}, Requested: {$quantity}"
                ], 400);
            }
            
            // Deduct from warehouse immediately
            $inventory->adjustQuantity(-$quantity, 'transfer', 
                "Transfer to store (pending approval): {$quantity} units", 
                'transfer_to_store', null);
            
            // Create pending transfer record
            $transfer = WarehouseTransferModel::create([
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
            
            return response()->json([
                'success' => true,
                'message' => "Transfer of {$quantity} units initiated. Pending admin approval.",
                'transfer' => [
                    'id' => $transfer->id,
                    'quantity' => $transfer->quantity,
                    'status' => $transfer->status,
                    'warehouse_qty_after' => $inventory->quantity,
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to initiate transfer', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to initiate transfer: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Approve a pending transfer (Khaas admin OR any store mode user)
     * Adds quantity to store inventory
     */
    public function approveTransfer(Request $request, $transferId)
    {
        try {
            // Check if user has approve permission:
            // 1. Khaas mode: approve_khaas_transfer permission or admin/taimur role
            // 2. Store mode: any user with access_store_mode can accept transfers into shop
            $user = Auth::user();
            $userRoles = $user->roles->pluck('urole_name')->map(fn($r) => strtolower($r))->toArray();
            $hasPermission = $user->hasMobilePermission('approve_khaas_transfer') 
                || in_array('admin', $userRoles) 
                || in_array('taimur', $userRoles)
                || $user->hasMobilePermission('access_store_mode'); // ⭐ Store users can accept transfers
            
            if (!$hasPermission) {
                return response()->json(['success' => false, 'message' => 'Only authorized users can approve transfers'], 403);
            }
            
            $transfer = WarehouseTransferModel::findOrFail($transferId);
            
            if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
                return response()->json(['success' => false, 'message' => 'Transfer is not pending'], 400);
            }
            
            DB::beginTransaction();
            
            // Update transfer status
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
                // Also update the transfer record so future lookups work correctly
                if ($variant) {
                    $transfer->update(['product_variant_id' => $variant->id]);
                }
            }
            
            // Add to store inventory (variant's inventory_quantity is the Shop stock)
            if ($variant) {
                $variant->increment('inventory_quantity', $transfer->quantity);
            }
            
            // Sync product's total_inventory from variant(s) to stay consistent
            $product = ProductModel::find($transfer->product_id);
            if ($product) {
                $newTotal = ProductVariantModel::where('product_id', $product->id)->sum('inventory_quantity');
                $product->update(['total_inventory' => $newTotal]);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Transfer of {$transfer->quantity} units approved. Store inventory updated.",
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to approve transfer', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to approve: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a pending transfer (Khaas admin OR any store mode user)
     * Returns quantity to warehouse
     */
    public function rejectTransfer(Request $request, $transferId)
    {
        try {
            $user = Auth::user();
            $userRoles = $user->roles->pluck('urole_name')->map(fn($r) => strtolower($r))->toArray();
            $hasPermission = $user->hasMobilePermission('approve_khaas_transfer') 
                || in_array('admin', $userRoles) 
                || in_array('taimur', $userRoles)
                || $user->hasMobilePermission('access_store_mode'); // ⭐ Store users can reject transfers
            
            if (!$hasPermission) {
                return response()->json(['success' => false, 'message' => 'Only authorized users can reject transfers'], 403);
            }
            
            $transfer = WarehouseTransferModel::findOrFail($transferId);
            
            if ($transfer->status !== WarehouseTransferModel::STATUS_PENDING) {
                return response()->json(['success' => false, 'message' => 'Transfer is not pending'], 400);
            }
            
            $reason = $request->input('reason', 'Rejected by admin');
            
            DB::beginTransaction();
            
            // Return stock to warehouse
            $inventory = WarehouseInventoryModel::getOrCreate(
                $transfer->product_id, 
                $transfer->product_variant_id, 
                $transfer->business_unit_id
            );
            $inventory->adjustQuantity($transfer->quantity, 'adjustment', 
                "Transfer rejected - stock returned: {$transfer->quantity} units. Reason: {$reason}",
                'transfer_rejected', $transfer->id);
            
            // Update transfer status
            $transfer->update([
                'status' => WarehouseTransferModel::STATUS_REJECTED,
                'rejected_by' => auth()->id(),
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Transfer rejected. {$transfer->quantity} units returned to warehouse.",
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to reject transfer', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to reject: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get pending transfers (for admin approval screen)
     */
    public function getPendingTransfers(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            
            $query = WarehouseTransferModel::where('status', WarehouseTransferModel::STATUS_PENDING)
                ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname'])
                ->orderBy('created_at', 'asc');
            
            if ($businessUnitId) {
                $query->where('business_unit_id', $businessUnitId);
            }
            
            $transfers = $query->get()->map(fn($t) => [
                'id' => $t->id,
                'product_name' => $t->product?->title ?? 'Unknown',
                'variant_name' => $t->variant?->title ?? null,
                'quantity' => $t->quantity,
                'from_location' => $t->from_location,
                'to_location' => $t->to_location,
                'requested_by' => $t->requester?->fullname ?? 'Unknown',
                'notes' => $t->notes,
                'created_at' => $t->created_at?->format('Y-m-d H:i'),
            ]);
            
            return response()->json([
                'success' => true,
                'transfers' => $transfers,
                'count' => $transfers->count(),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get pending transfers', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load transfers'], 500);
        }
    }

    /**
     * Get transfer history for a business unit (approved/rejected transfers)
     * Used by Store Mode Khaas Inventory screen to show who approved what
     */
    public function getTransferHistory(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            $limit = $request->input('limit', 20);
            
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }
            
            $transfers = WarehouseTransferModel::where('business_unit_id', $businessUnitId)
                ->whereIn('status', [WarehouseTransferModel::STATUS_APPROVED, WarehouseTransferModel::STATUS_REJECTED])
                ->with(['product:id,title', 'variant:id,title,sku', 'requester:id,fullname', 'approver:id,fullname', 'rejecter:id,fullname'])
                ->orderBy('updated_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($t) {
                    $statusEmoji = $t->status === 'approved' ? '✅' : '❌';
                    $actionBy = $t->status === 'approved' 
                        ? ($t->approver?->fullname ?? 'Unknown')
                        : ($t->rejecter?->fullname ?? 'Unknown');
                    $actionAt = $t->status === 'approved' 
                        ? ($t->approved_at?->format('M d, h:i A') ?? '')
                        : ($t->rejected_at?->format('M d, h:i A') ?? '');
                    
                    return [
                        'id' => $t->id,
                        'product_name' => $t->product?->title ?? 'Unknown',
                        'variant_name' => $t->variant?->title ?? null,
                        'quantity' => $t->quantity,
                        'status' => $t->status,
                        'status_emoji' => $statusEmoji,
                        'requested_by' => $t->requester?->fullname ?? 'Unknown',
                        'action_by' => $actionBy,
                        'action_at' => $actionAt,
                        'notes' => $t->notes,
                        'rejection_reason' => $t->rejection_reason,
                        'created_at' => $t->created_at?->format('M d, h:i A'),
                    ];
                });
            
            return response()->json([
                'success' => true,
                'transfers' => $transfers,
                'count' => $transfers->count(),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get transfer history', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load transfer history'], 500);
        }
    }

    /**
     * Get recent history for a product (stock changes + transfers)
     * Returns last N events combining warehouse logs and transfers
     */
    public function getProductHistory(Request $request)
    {
        try {
            $productId = $request->input('product_id');
            $businessUnitId = $request->input('business_unit_id');
            $limit = $request->input('limit', 5);
            
            if (!$productId || !$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'product_id and business_unit_id required'], 400);
            }
            
            // Get recent warehouse inventory logs (stock in, stock out, adjustments)
            $stockLogs = WarehouseInventoryLogModel::where('product_id', $productId)
                ->where('business_unit_id', $businessUnitId)
                ->with('creator:id,fullname')
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($log) {
                    $typeEmoji = match($log->change_type) {
                        'stock_in' => '📥',
                        'stock_out' => '📤',
                        'count' => '📊',
                        'adjustment' => '🔧',
                        default => '📦',
                    };
                    $typeLabel = match($log->change_type) {
                        'stock_in' => 'Stock In',
                        'stock_out' => 'Stock Out',
                        'count' => 'Stock Count',
                        'adjustment' => 'Adjustment',
                        default => ucfirst($log->change_type ?? 'Unknown'),
                    };
                    return [
                        'id' => $log->id,
                        'event_type' => 'warehouse',
                        'type_emoji' => $typeEmoji,
                        'type_label' => $typeLabel,
                        'change_type' => $log->change_type,
                        'qty_change' => $log->quantity_change,
                        'qty_after' => $log->quantity_after,
                        'notes' => $log->notes,
                        'user' => $log->creator?->fullname ?? 'System',
                        'created_at' => $log->created_at?->format('M d, h:i A'),
                        'sort_time' => $log->created_at?->timestamp ?? 0,
                    ];
                });
            
            // Get recent transfers for this product
            $transfers = WarehouseTransferModel::where('product_id', $productId)
                ->where('business_unit_id', $businessUnitId)
                ->with(['requester:id,fullname', 'approver:id,fullname'])
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($t) {
                    $statusEmoji = match($t->status) {
                        'pending' => '⏳',
                        'approved' => '✅',
                        'rejected' => '❌',
                        default => '↔️',
                    };
                    return [
                        'id' => $t->id,
                        'event_type' => 'transfer',
                        'type_emoji' => $statusEmoji,
                        'type_label' => 'Transfer to Shop',
                        'change_type' => 'transfer',
                        'qty_change' => $t->quantity,
                        'status' => $t->status,
                        'notes' => $t->notes,
                        'user' => $t->requester?->fullname ?? 'Unknown',
                        'approved_by' => $t->approver?->fullname ?? null,
                        'created_at' => $t->created_at?->format('M d, h:i A'),
                        'sort_time' => $t->created_at?->timestamp ?? 0,
                    ];
                });
            
            // Merge and sort by time, take top N
            $combined = $stockLogs->concat($transfers)
                ->sortByDesc('sort_time')
                ->take($limit)
                ->values();
            
            return response()->json([
                'success' => true,
                'history' => $combined,
                'count' => $combined->count(),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get product history', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load history'], 500);
        }
    }

    // ================================================================
    // BATCH PRODUCTION TRACKING
    // ================================================================

    /**
     * Get active batches for all products in a business unit
     * Returns a map keyed by product_id for easy lookup on the frontend
     */
    public function getActiveBatches(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            $activeBatches = ProductBatchModel::where('business_unit_id', $businessUnitId)
                ->where('status', ProductBatchModel::STATUS_IN_PROGRESS)
                ->with('startedByUser:id,fullname')
                ->get();

            // Also get last completed batch per product (for "last batch" display)
            $productIds = ProductModel::where('business_unit_id', $businessUnitId)->pluck('id')->toArray();
            
            $lastCompleted = ProductBatchModel::where('business_unit_id', $businessUnitId)
                ->where('status', ProductBatchModel::STATUS_COMPLETED)
                ->whereIn('product_id', $productIds)
                ->orderBy('ended_at', 'desc')
                ->get()
                ->groupBy('product_id')
                ->map(fn($batches) => $batches->first());

            $activeMap = [];
            foreach ($activeBatches as $batch) {
                $pid = (string)$batch->product_id;
                $activeMap[$pid] = [
                    'id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'batch_number' => $batch->batch_number,
                    'started_at' => $batch->started_at->format('Y-m-d H:i'),
                    'elapsed' => $batch->elapsed_time,
                    'started_by' => $batch->startedByUser?->fullname ?? 'Unknown',
                    'notes' => $batch->notes_start,
                ];
            }

            $lastMap = [];
            foreach ($lastCompleted as $pid => $batch) {
                $lastMap[(string)$pid] = [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'quantity_produced' => $batch->quantity_produced,
                    'ended_at' => $batch->ended_at?->format('Y-m-d H:i'),
                    'ended_ago' => $batch->ended_at ? $batch->ended_at->diffForHumans() : null,
                    'duration' => $batch->started_at && $batch->ended_at 
                        ? $batch->started_at->diff($batch->ended_at)->format('%hh %im') 
                        : null,
                ];
            }

            return response()->json([
                'success' => true,
                'active_batches' => $activeMap,
                'last_batches' => $lastMap,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get active batches', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load batches'], 500);
        }
    }

    /**
     * Start a new production batch for a product
     */
    public function startBatch(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|integer|exists:t_crm_prod_product,id',
                'business_unit_id' => 'required|integer',
                'notes' => 'nullable|string|max:500',
            ]);

            $productId = $request->input('product_id');
            $businessUnitId = $request->input('business_unit_id');

            // Check if there's already an active batch for this product
            $existing = ProductBatchModel::getActiveBatch($productId, $businessUnitId);
            if ($existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'A batch is already in progress for this product (started ' . $existing->elapsed_time . ' ago)',
                ], 400);
            }

            $batchNumber = ProductBatchModel::generateBatchNumber($productId, $businessUnitId);

            $batch = ProductBatchModel::create([
                'product_id' => $productId,
                'product_variant_id' => ProductModel::find($productId)?->variants?->first()?->id,
                'business_unit_id' => $businessUnitId,
                'batch_number' => $batchNumber,
                'status' => ProductBatchModel::STATUS_IN_PROGRESS,
                'started_at' => now(),
                'notes_start' => $request->input('notes'),
                'started_by' => auth()->id(),
            ]);

            // Update product's current_batch_number for quick reference
            try {
                ProductModel::where('id', $productId)->update([
                    'current_batch_number' => $batchNumber,
                ]);
            } catch (\Exception $e) {
                // Non-critical — column may not exist yet
                \Log::info('Could not update current_batch_number on product', ['error' => $e->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => "Batch {$batchNumber} started",
                'batch' => [
                    'id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'started_at' => $batch->started_at->format('Y-m-d H:i'),
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to start batch', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to start batch: ' . $e->getMessage()], 500);
        }
    }

    /**
     * End a batch — records quantity produced and auto-triggers warehouse stock_in
     */
    public function endBatch(Request $request, $batchId)
    {
        try {
            $request->validate([
                'quantity_produced' => 'nullable|integer|min:0',
                'notes' => 'nullable|string|max:500',
            ]);

            $batch = ProductBatchModel::findOrFail($batchId);

            if ($batch->status !== ProductBatchModel::STATUS_IN_PROGRESS) {
                return response()->json(['success' => false, 'message' => 'Batch is not in progress'], 400);
            }

            $rawQty = $request->input('quantity_produced');
            $quantityProduced = ($rawQty !== null && $rawQty !== '') ? (int) $rawQty : 0;

            DB::beginTransaction();

            // 1. End the batch
            $batch->update([
                'status' => ProductBatchModel::STATUS_COMPLETED,
                'ended_at' => now(),
                'quantity_produced' => $quantityProduced,
                'notes_end' => $request->input('notes'),
                'ended_by' => auth()->id(),
            ]);

            // 2. Auto stock-in to warehouse (only if quantity > 0)
            $stockLog = null;
            if ($quantityProduced > 0) {
                $inventory = WarehouseInventoryModel::getOrCreate(
                    $batch->product_id,
                    $batch->product_variant_id,
                    $batch->business_unit_id
                );

                $inventory->adjustQuantity(
                    $quantityProduced,
                    'stock_in',
                    "Auto stock-in from batch {$batch->batch_number} ({$quantityProduced} units produced)",
                    'batch',
                    $batch->id
                );

                // 3. Link the stock log to the batch
                $stockLog = WarehouseInventoryLogModel::where('reference_type', 'batch')
                    ->where('reference_id', $batch->id)
                    ->first();
                
                if ($stockLog) {
                    $batch->update(['warehouse_stock_log_id' => $stockLog->id]);
                }
            }

            // 4. Update product's current_batch_number
            try {
                ProductModel::where('id', $batch->product_id)->update([
                    'current_batch_number' => $batch->batch_number,
                ]);
            } catch (\Exception $e) {
                \Log::info('Could not update current_batch_number on product', ['error' => $e->getMessage()]);
            }

            // 5. Update production demand items linked to this batch
            try {
                DB::table('t_crm_khaas_production_demand_item')
                    ->where('batch_id', $batch->id)
                    ->whereIn('status', ['in_progress', 'accepted', 'pending'])
                    ->update(['status' => 'completed', 'updated_at' => now()]);

                // Check if all items in parent demands are completed → mark demand completed
                $demandIds = DB::table('t_crm_khaas_production_demand_item')
                    ->where('batch_id', $batch->id)
                    ->pluck('demand_id')
                    ->unique();

                foreach ($demandIds as $dId) {
                    $pendingCount = DB::table('t_crm_khaas_production_demand_item')
                        ->where('demand_id', $dId)
                        ->whereNotIn('status', ['completed', 'cancelled'])
                        ->count();
                    if ($pendingCount === 0) {
                        DB::table('t_crm_khaas_production_demand')
                            ->where('id', $dId)
                            ->whereNotIn('status', ['completed', 'cancelled'])
                            ->update(['status' => 'completed', 'completed_at' => now(), 'updated_at' => now()]);
                    }
                }
            } catch (\Exception $e) {
                \Log::info('Non-critical: demand item status update after batch end', ['error' => $e->getMessage()]);
            }

            DB::commit();

            $duration = $batch->started_at->diff(now());
            $durationStr = $duration->h > 0 
                ? $duration->h . 'h ' . $duration->i . 'm' 
                : $duration->i . 'm';

            $message = $quantityProduced > 0 
                ? "Batch {$batch->batch_number} completed. {$quantityProduced} units added to warehouse."
                : "Batch {$batch->batch_number} completed (0 units produced — no stock change).";

            $responseData = [
                'id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'quantity_produced' => $quantityProduced,
                'duration' => $durationStr,
            ];

            // Include warehouse qty if stock was added
            if ($quantityProduced > 0 && isset($inventory)) {
                $responseData['warehouse_qty'] = $inventory->quantity;
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'batch' => $responseData,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to end batch', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to end batch: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancel an active batch (no stock changes)
     */
    public function cancelBatch(Request $request, $batchId)
    {
        try {
            $batch = ProductBatchModel::findOrFail($batchId);

            if ($batch->status !== ProductBatchModel::STATUS_IN_PROGRESS) {
                return response()->json(['success' => false, 'message' => 'Batch is not in progress'], 400);
            }

            $batch->update([
                'status' => ProductBatchModel::STATUS_CANCELLED,
                'ended_at' => now(),
                'ended_by' => auth()->id(),
                'notes_end' => $request->input('notes', 'Cancelled'),
            ]);

            return response()->json([
                'success' => true,
                'message' => "Batch {$batch->batch_number} cancelled. No stock changes.",
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to cancel batch', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to cancel batch'], 500);
        }
    }

    /**
     * Get batch history for a specific product
     */
    public function getBatchHistory(Request $request, $productId = null)
    {
        try {
            // Accept product_id from route path or query param
            $productId = $productId ?: $request->input('product_id');
            $businessUnitId = $request->input('business_unit_id');
            $limit = $request->input('limit', 10);

            if (!$productId || !$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'product_id and business_unit_id required'], 400);
            }

            $batches = ProductBatchModel::where('product_id', $productId)
                ->where('business_unit_id', $businessUnitId)
                ->with(['startedByUser:id,fullname', 'endedByUser:id,fullname'])
                ->orderBy('started_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function($b) {
                    $duration = null;
                    if ($b->started_at && $b->ended_at) {
                        $diff = $b->started_at->diff($b->ended_at);
                        $duration = $diff->h > 0 ? $diff->h . 'h ' . $diff->i . 'm' : $diff->i . 'm';
                    }
                    return [
                        'id' => $b->id,
                        'batch_number' => $b->batch_number,
                        'status' => $b->status,
                        'started_at' => $b->started_at->format('M d, h:i A'),
                        'ended_at' => $b->ended_at?->format('M d, h:i A'),
                        'duration' => $duration,
                        'elapsed' => $b->status === ProductBatchModel::STATUS_IN_PROGRESS ? $b->elapsed_time : null,
                        'quantity_produced' => $b->quantity_produced,
                        'started_by' => $b->startedByUser?->fullname ?? 'Unknown',
                        'ended_by' => $b->endedByUser?->fullname ?? null,
                        'notes_start' => $b->notes_start,
                        'notes_end' => $b->notes_end,
                    ];
                });

            return response()->json([
                'success' => true,
                'batches' => $batches,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get batch history', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load batch history'], 500);
        }
    }

    // ======================================================
    // ⭐ STORAGE FEATURE — NF meat → Khaas warehouse
    // ======================================================

    /**
     * Lightweight badge counts for Khaas tab indicators.
     */
    public function getKhaasBadges(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false], 400);
            }

            // Meat tab: orders delivered but not yet received into storage
            $pendingReceive = DB::table('t_crm_khaas_storage_order as so')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'so.order_id')
                ->where('so.khaas_business_unit_id', $businessUnitId)
                ->whereNotIn('so.status', ['received', 'cancelled'])
                ->whereIn('o.order_status', ['delivered', 'completed'])
                ->count();

            // Inventory tab: demand plans with status 'submitted' (not yet accepted)
            $pendingDemand = DB::table('t_crm_khaas_production_demand')
                ->where('business_unit_id', $businessUnitId)
                ->where('status', 'submitted')
                ->count();

            return response()->json([
                'success' => true,
                'pending_receive' => $pendingReceive,
                'pending_demand' => $pendingDemand,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => true, 'pending_receive' => 0, 'pending_demand' => 0]);
        }
    }

    /**
     * Get storage config (which NF products are configured for storage)
     * + current storage inventory levels
     */
    public function getStorageInventory(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            // Get configured storage products
            // LEFT JOIN on specified variant; also join default variant (first active) as fallback for price
            $configuredProducts = DB::table('t_crm_khaas_storage_config as sc')
                ->join('t_crm_prod_product as p', 'p.id', '=', 'sc.source_product_id')
                ->leftJoin('t_crm_prod_product_variant as v', 'v.id', '=', 'sc.source_variant_id')
                ->where('sc.khaas_business_unit_id', $businessUnitId)
                ->where('sc.is_active', 1)
                ->select(
                    'sc.id as config_id',
                    'sc.source_product_id',
                    'sc.source_variant_id',
                    'sc.display_name',
                    'sc.default_unit',
                    'sc.sort_order',
                    'p.title as product_title',
                    'p.featured_image',
                    'p.price_min as product_price', // ⭐ Fallback price from product
                    'v.price as variant_price',
                    'v.sku',
                    'v.title as variant_title'
                )
                ->orderBy('sc.sort_order')
                ->orderBy('p.title')
                ->get();

            // For products without a specific variant, look up their default variant price
            $productIdsNeedingVariant = $configuredProducts
                ->filter(fn($p) => !$p->source_variant_id && !$p->variant_price)
                ->pluck('source_product_id')
                ->unique()
                ->values()
                ->toArray();

            $defaultVariantPrices = [];
            if (!empty($productIdsNeedingVariant)) {
                $defaultVariants = DB::table('t_crm_prod_product_variant')
                    ->whereIn('product_id', $productIdsNeedingVariant)
                    ->orderBy('product_id')
                    ->orderBy('id') // first variant
                    ->get()
                    ->groupBy('product_id');

                foreach ($defaultVariants as $productId => $variants) {
                    $first = $variants->first();
                    $defaultVariantPrices[$productId] = [
                        'price' => $first->price,
                        'sku' => $first->sku,
                        'variant_id' => $first->id,
                        'variant_title' => $first->title,
                    ];
                }
            }

            // Get storage inventory levels
            $inventoryRows = DB::table('t_crm_khaas_storage_inventory')
                ->where('khaas_business_unit_id', $businessUnitId)
                ->get();

            // ⭐ Build dual-key lookup: exact key (product+variant) AND product-only fallback
            $inventoryByKey = [];
            $inventoryByProduct = [];
            foreach ($inventoryRows as $row) {
                $exactKey = $row->source_product_id . '_' . ($row->source_variant_id ?: '0');
                $inventoryByKey[$exactKey] = $row;
                // Also index by product only (for fallback when variant mismatch exists)
                if (!isset($inventoryByProduct[$row->source_product_id])) {
                    $inventoryByProduct[$row->source_product_id] = $row;
                } else {
                    // If multiple, use the one with most stock
                    $existing = $inventoryByProduct[$row->source_product_id];
                    if ((float)$row->quantity_on_hand > (float)$existing->quantity_on_hand) {
                        $inventoryByProduct[$row->source_product_id] = $row;
                    }
                }
            }

            // Get processing quantities — supports old (single storage_product_id) and new (multi-recipe) demand items
            $inProgressItems = DB::table('t_crm_khaas_production_demand_item as di')
                ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
                ->where('d.business_unit_id', $businessUnitId)
                ->whereIn('di.status', ['in_progress', 'accepted'])
                ->where('di.storage_deducted', 1)
                ->select('di.khaas_product_id', 'di.quantity_kg', 'di.storage_product_id', 'di.storage_deducted_qty')
                ->get();

            $processingQtys = [];
            foreach ($inProgressItems as $pItem) {
                if ($pItem->storage_product_id) {
                    $pid = $pItem->storage_product_id;
                    $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + (float)$pItem->storage_deducted_qty;
                } else {
                    $recipes = DB::table('t_crm_khaas_product_recipe')
                        ->where('khaas_product_id', $pItem->khaas_product_id)
                        ->where('is_active', 1)
                        ->get();
                    foreach ($recipes as $recipe) {
                        $pid = $recipe->storage_product_id;
                        $deductQty = round((float)$pItem->quantity_kg * (float)$recipe->ratio_kg, 3);
                        $processingQtys[$pid] = ($processingQtys[$pid] ?? 0) + $deductQty;
                    }
                }
            }

            // Build result
            $products = $configuredProducts->map(function($p) use ($inventoryByKey, $inventoryByProduct, $defaultVariantPrices, $processingQtys) {
                $key = $p->source_product_id . '_' . ($p->source_variant_id ?: '0');
                // ⭐ Exact match first, then fallback to product-only match
                $inv = $inventoryByKey[$key] ?? ($inventoryByProduct[$p->source_product_id] ?? null);

                // ⭐ Resolve price: specific variant → default variant → product price_min → 0
                $price = $p->variant_price;
                $sku = $p->sku;
                $variantTitle = $p->variant_title;
                $resolvedVariantId = $p->source_variant_id;

                if (!$price && isset($defaultVariantPrices[$p->source_product_id])) {
                    $dv = $defaultVariantPrices[$p->source_product_id];
                    $price = $dv['price'];
                    $sku = $sku ?: $dv['sku'];
                    $variantTitle = $variantTitle ?: $dv['variant_title'];
                    $resolvedVariantId = $resolvedVariantId ?: $dv['variant_id'];
                }
                if (!$price) {
                    $price = $p->product_price; // price_min from product table
                }

                return [
                    'config_id' => $p->config_id,
                    'product_id' => $p->source_product_id,
                    'variant_id' => $resolvedVariantId,
                    'name' => $p->display_name ?: $p->product_title,
                    'product_title' => $p->product_title,
                    'variant_title' => $variantTitle,
                    'price' => (float)($price ?: 0),
                    'sku' => $sku,
                    'unit' => $p->default_unit,
                    'image' => $p->featured_image,
                    'quantity_on_hand' => $inv ? (float)$inv->quantity_on_hand : 0,
                    'total_received' => $inv ? (float)$inv->total_received : 0,
                    'total_used' => $inv ? (float)$inv->total_used : 0,
                    'processing_qty' => (float)($processingQtys[$p->source_product_id] ?? 0),
                    'last_received_at' => $inv ? $inv->last_received_at : null,
                    'last_used_at' => $inv ? $inv->last_used_at : null,
                ];
            });

            // ⭐ Orders pending approval (still in the approval queue)
            $pendingApprovalOrders = \App\Models\CRM\ShopifyOrderModel::with('lineItems')
                ->where('external_source', 'khaas_storage')
                ->where(function($q) {
                    $q->whereNull('converted')->orWhere('converted', 0);
                })
                ->where('khaas_business_unit_id', $businessUnitId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function($o) {
                    return [
                        'id' => $o->id,
                        'order_id' => null,
                        'order_number' => $o->order_number,
                        'nf_order_status' => 'pending_approval',
                        'status' => 'pending_approval',
                        'total_price' => $o->total_price,
                        'notes' => $o->note,
                        'created_at' => $o->created_at?->toDateTimeString(),
                        'line_items' => $o->lineItems->map(function($li) {
                            return [
                                'name' => $li->name,
                                'quantity' => $li->quantity,
                                'unit_price' => $li->unit_price,
                                'line_total' => $li->line_total,
                            ];
                        })->toArray(),
                    ];
                });

            // Get recent storage orders with line items and main order status
            $recentOrders = DB::table('t_crm_khaas_storage_order as so')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'so.order_id')
                ->where('so.khaas_business_unit_id', $businessUnitId)
                ->select(
                    'so.*',
                    'o.order_number',
                    'o.total_price',
                    'o.order_status as nf_order_status',
                    'o.order_date',
                    'o.name as customer_name'
                )
                ->orderBy('so.created_at', 'desc')
                ->limit(20)
                ->get();

            // Attach line items to each order for detail display
            $orderIds = $recentOrders->pluck('order_id')->toArray();
            $allLineItems = [];
            if (!empty($orderIds)) {
                $allLineItems = \App\Models\CRM\OrderLineItemModel::whereIn('order_id', $orderIds)
                    ->get(['order_id', 'name', 'quantity', 'unit_price', 'line_total'])
                    ->groupBy('order_id');
            }

            $recentOrders = $recentOrders->map(function($order) use ($allLineItems) {
                $order->line_items = isset($allLineItems[$order->order_id])
                    ? $allLineItems[$order->order_id]->toArray()
                    : [];
                return $order;
            });

            // Get storage settings
            $customerPhone = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_CUSTOMER_PHONE')
                ->where('business_unit_id', $businessUnitId)->value('config_value') ?: '';
            $customerId = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_CUSTOMER_ID')
                ->where('business_unit_id', $businessUnitId)->value('config_value') ?: '';

            // Vendor settings for purchase recording
            $vendorId = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
                ->where('business_unit_id', $businessUnitId)->value('config_value') ?: null;
            $vendorName = null;
            if ($vendorId) {
                $vendorName = \App\Models\FIN\VendorModel::where('id', $vendorId)->value('vendor_name');
            }
            // Default vendor name if not configured
            if (!$vendorName) {
                $vendorName = 'Nizami Farms';
            }

            // Get list of active vendors for the BU (for admin to choose from)
            $availableVendors = \App\Models\FIN\VendorModel::where('is_active', 1)
                ->orderBy('vendor_name')
                ->get(['id', 'vendor_name'])
                ->map(fn($v) => ['id' => $v->id, 'name' => $v->vendor_name]);

            return response()->json([
                'success' => true,
                'products' => $products,
                'recent_orders' => $recentOrders,
                'pending_approval_orders' => $pendingApprovalOrders,
                'settings' => [
                    'customer_phone' => $customerPhone,
                    'customer_id' => $customerId,
                    'vendor_id' => $vendorId ? (int) $vendorId : null,
                    'vendor_name' => $vendorName,
                ],
                'available_vendors' => $availableVendors,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get storage inventory', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Failed to load storage data: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get available NF products that can be configured for storage
     * (Admin only — for the config screen)
     */
    public function getAvailableStorageProducts(Request $request)
    {
        try {
            // Get NF (BU 1) active products
            $products = ProductModel::where('business_unit_id', 1)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->with('variants:id,product_id,title,price,sku')
                ->orderBy('title')
                ->get(['id', 'title', 'featured_image', 'product_type', 'vendor']);

            return response()->json([
                'success' => true,
                'products' => $products,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load products'], 500);
        }
    }

    /**
     * Add/remove products from storage config (Admin only)
     */
    public function updateStorageConfig(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'product_id' => 'required|integer|exists:t_crm_prod_product,id',
                'action' => 'required|in:add,remove',
                'variant_id' => 'nullable|integer',
                'display_name' => 'nullable|string|max:255',
                'default_unit' => 'nullable|string|max:20',
            ]);

            $businessUnitId = $request->input('business_unit_id');
            $productId = $request->input('product_id');
            $action = $request->input('action');

            if ($action === 'add') {
                DB::table('t_crm_khaas_storage_config')->updateOrInsert(
                    [
                        'source_product_id' => $productId,
                        'khaas_business_unit_id' => $businessUnitId,
                    ],
                    [
                        'source_variant_id' => $request->input('variant_id'),
                        'display_name' => $request->input('display_name'),
                        'default_unit' => $request->input('default_unit', 'kg'),
                        'is_active' => 1,
                        'created_by' => auth()->id(),
                        'updated_at' => now(),
                    ]
                );
                return response()->json(['success' => true, 'message' => 'Product added to storage config']);
            } else {
                DB::table('t_crm_khaas_storage_config')
                    ->where('source_product_id', $productId)
                    ->where('khaas_business_unit_id', $businessUnitId)
                    ->update(['is_active' => 0, 'updated_at' => now()]);
                return response()->json(['success' => true, 'message' => 'Product removed from storage config']);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to update storage config', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Place a storage order (creates a real order in the NF system)
     * Uses storeOrderFromApi() — the same proven path as OrderController::store —
     * for proper order number generation (with lockForUpdate), customer handling,
     * status history creation, and line item storage.
     */
    public function placeStorageOrder(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.product_id' => 'required|integer',
                'items.*.variant_id' => 'nullable|integer',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.name' => 'required|string',
                'notes' => 'nullable|string|max:1000',
            ]);

            $businessUnitId = $request->input('business_unit_id');

            // Get or create the Khaas Warehouse customer
            $customerId = $this->getOrCreateStorageCustomer($businessUnitId);
            $customer = \App\Models\CRM\CustomerModel::find($customerId);

            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Failed to create/find warehouse customer'], 500);
            }

            // Build line items — look up prices server-side from product/variant
            $lineItems = [];
            $subtotal = 0;
            foreach ($request->input('items') as $item) {
                $productId = $item['product_id'];
                $variantId = $item['variant_id'] ?? null;
                $quantity = (float) $item['quantity'];

                // ⭐ Server-side price lookup: variant price → first variant → product price_min → 0
                $unitPrice = 0;
                $sku = null;

                if ($variantId) {
                    $variant = DB::table('t_crm_prod_product_variant')
                        ->where('id', $variantId)
                        ->first(['price', 'sku']);
                    if ($variant) {
                        $unitPrice = (float)($variant->price ?: 0);
                        $sku = $variant->sku;
                    }
                }

                // Fallback: find default variant for this product
                if (!$unitPrice) {
                    $defaultVariant = DB::table('t_crm_prod_product_variant')
                        ->where('product_id', $productId)
                        ->orderBy('id')
                        ->first(['id', 'price', 'sku']);
                    if ($defaultVariant) {
                        $unitPrice = (float)($defaultVariant->price ?: 0);
                        $sku = $sku ?: $defaultVariant->sku;
                        if (!$variantId) {
                            $variantId = $defaultVariant->id; // Use default variant
                        }
                    }
                }

                // Fallback: product price_min
                if (!$unitPrice) {
                    $product = DB::table('t_crm_prod_product')
                        ->where('id', $productId)
                        ->first(['price_min']);
                    $unitPrice = $product ? (float)($product->price_min ?: 0) : 0;
                }

                $lineTotal = $quantity * $unitPrice;
                $subtotal += $lineTotal;

                $lineItems[] = [
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'name' => $item['name'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_subtotal' => $lineTotal,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $lineTotal,
                    'sku' => $sku,
                ];
            }

            // ⭐ Generate order number (KS = Khaas Storage) for the approval queue
            $orderNumber = DB::transaction(function() {
                $maxNum = DB::table('t_crm_shopify_order')
                    ->where('order_number', 'LIKE', 'KS-%')
                    ->lockForUpdate()
                    ->max(DB::raw("CAST(SUBSTRING(order_number, 4) AS UNSIGNED)"));
                $maxProd = DB::table('t_crm_prod_order')
                    ->where('order_number', 'LIKE', 'KS-%')
                    ->lockForUpdate()
                    ->max(DB::raw("CAST(SUBSTRING(order_number, 4) AS UNSIGNED)"));
                return 'KS-' . (max($maxNum ?? 0, $maxProd ?? 0) + 1);
            });

            // ⭐ Route to approval queue (t_crm_shopify_order) so frozen meat orders
            // don't affect next-day quantity planning until approved
            $orderData = [
                'customer_id' => $customerId,
                'external_source' => 'khaas_storage',
                'order_number' => $orderNumber,
                'order_status' => 'pending',
                'order_date' => now()->toDateString(),
                'currency' => 'PKR',
                'name' => trim($customer->first_name . ' ' . ($customer->last_name ?? '')),
                'contact_email' => $customer->email ?? null,
                'subtotal_price' => $subtotal,
                'discount_total' => 0,
                'shipping_total' => 0,
                'total_price' => $subtotal,
                'address_first_name' => $customer->first_name ?? 'Khaas',
                'address_last_name' => $customer->last_name ?? 'Warehouse',
                'address_phone' => $customer->phone_original ?? null,
                'address_line1' => $customer->address1 ?? null,
                'address_city' => $customer->city ?? 'Islamabad',
                'address_country' => $customer->country ?? 'Pakistan',
                'note' => $request->input('notes')
                    ? '[Khaas Storage Order] ' . $request->input('notes')
                    : '[Khaas Storage Order]',
                'converted' => 0,
                'khaas_business_unit_id' => $businessUnitId,
                'line_items' => $lineItems,
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ];

            \Log::info('Placing storage order into approval queue', [
                'customer_id' => $customerId,
                'order_number' => $orderNumber,
                'items_count' => count($lineItems),
                'subtotal' => $subtotal,
                'user_id' => auth()->id(),
            ]);

            $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);

            return response()->json([
                'success' => true,
                'message' => "Storage order {$order->order_number} placed into approval queue — {$this->formatItemsSummary($lineItems)}. Approve it from the Orders > Shopify Approvals tab.",
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total' => $subtotal,
                    'items_count' => count($lineItems),
                    'pending_approval' => true,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to place storage order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->except(['_token']),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to place order: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper: Format items summary for order confirmation message
     */
    private function formatItemsSummary(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = "{$item['name']} x{$item['quantity']}";
        }
        return implode(', ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? ' +more' : '');
    }

    /**
     * Receive delivered items into storage inventory
     */
    public function receiveStorageOrder(Request $request, $storageOrderId)
    {
        try {
            $storageOrder = DB::table('t_crm_khaas_storage_order')->where('id', $storageOrderId)->first();
            if (!$storageOrder) {
                return response()->json(['success' => false, 'message' => 'Storage order not found'], 404);
            }

            if ($storageOrder->status === 'received') {
                return response()->json(['success' => false, 'message' => 'Already received'], 400);
            }

            // Get order line items
            $lineItems = \App\Models\CRM\OrderLineItemModel::where('order_id', $storageOrder->order_id)->get();

            DB::beginTransaction();

            foreach ($lineItems as $lineItem) {
                // ⭐ Resolve the storage config's variant_id for this product
                // The line item may have a resolved variant_id (e.g., default variant 456),
                // but the config has source_variant_id = NULL. We must use the CONFIG's variant
                // so inventory records match what getStorageInventory expects.
                $config = DB::table('t_crm_khaas_storage_config')
                    ->where('source_product_id', $lineItem->product_id)
                    ->where('khaas_business_unit_id', $storageOrder->khaas_business_unit_id)
                    ->where('is_active', 1)
                    ->first();

                $storageVariantId = $config ? $config->source_variant_id : $lineItem->variant_id;

                // Get or create storage inventory record
                // ⭐ Handle NULL variant_id properly (NULL = NULL is FALSE in SQL)
                $inventoryQuery = DB::table('t_crm_khaas_storage_inventory')
                    ->where('source_product_id', $lineItem->product_id)
                    ->where('khaas_business_unit_id', $storageOrder->khaas_business_unit_id);

                if ($storageVariantId === null) {
                    $inventoryQuery->whereNull('source_variant_id');
                } else {
                    $inventoryQuery->where('source_variant_id', $storageVariantId);
                }

                $inventory = $inventoryQuery->first();

                $qtyBefore = $inventory ? (float)$inventory->quantity_on_hand : 0;
                $qtyChange = (float)$lineItem->quantity;
                $qtyAfter = $qtyBefore + $qtyChange;

                if ($inventory) {
                    DB::table('t_crm_khaas_storage_inventory')
                        ->where('id', $inventory->id)
                        ->update([
                            'quantity_on_hand' => $qtyAfter,
                            'total_received' => DB::raw("total_received + {$qtyChange}"),
                            'last_received_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $inventoryId = $inventory->id;
                } else {
                    $inventoryId = DB::table('t_crm_khaas_storage_inventory')->insertGetId([
                        'source_product_id' => $lineItem->product_id,
                        'source_variant_id' => $storageVariantId, // ⭐ Use config's variant, not line item's
                        'khaas_business_unit_id' => $storageOrder->khaas_business_unit_id,
                        'quantity_on_hand' => $qtyChange,
                        'unit' => $config->default_unit ?? 'kg',
                        'total_received' => $qtyChange,
                        'total_used' => 0,
                        'last_received_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Create log entry
                DB::table('t_crm_khaas_storage_log')->insert([
                    'storage_inventory_id' => $inventoryId,
                    'source_product_id' => $lineItem->product_id,
                    'khaas_business_unit_id' => $storageOrder->khaas_business_unit_id,
                    'change_type' => 'received',
                    'quantity_before' => $qtyBefore,
                    'quantity_change' => $qtyChange,
                    'quantity_after' => $qtyAfter,
                    'reference_order_id' => $storageOrder->order_id,
                    'notes' => "Received from order #{$storageOrder->order_id}",
                    'created_by' => auth()->id(),
                    'created_at' => now(),
                ]);
            }

            // Update storage order status
            DB::table('t_crm_khaas_storage_order')
                ->where('id', $storageOrderId)
                ->update([
                    'status' => 'received',
                    'received_at' => now(),
                    'received_by' => auth()->id(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            // ⭐ Auto-create vendor purchase entry for the received storage order (outside main transaction)
            try {
                $this->createStorageVendorPurchase($storageOrder, $lineItems);
            } catch (\Exception $purchaseError) {
                // Don't fail the receipt — just log the error
                \Log::error('Failed to auto-create storage vendor purchase', [
                    'storage_order_id' => $storageOrderId,
                    'error' => $purchaseError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Items received into storage successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to receive storage order', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to receive: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Auto-create a vendor purchase ledger entry when a storage order is received.
     * Records the purchase under the configured vendor (default: "Nizami Farms")
     * using the same flow as VendorController::recordPurchase.
     *
     * The vendor is configurable via KHAAS_STORAGE_VENDOR_ID in t_fin_config.
     */
    private function createStorageVendorPurchase($storageOrder, $lineItems)
    {
        // Get the NF order for total price and order number
        $order = \App\Models\CRM\OrderModel::find($storageOrder->order_id);
        if (!$order) {
            \Log::warning('Cannot create storage vendor purchase: order not found', ['order_id' => $storageOrder->order_id]);
            return;
        }

        $totalAmount = (float) $order->total_price;
        if ($totalAmount <= 0) {
            \Log::info('Skipping storage vendor purchase: order total is 0', ['order_id' => $order->id]);
            return;
        }

        $businessUnitId = (int) $storageOrder->khaas_business_unit_id;

        // Get configured vendor for this BU (or default to "Nizami Farms")
        $vendorId = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
            ->where('business_unit_id', $businessUnitId)
            ->value('config_value');

        $vendor = null;
        if ($vendorId) {
            $vendor = \App\Models\FIN\VendorModel::with('account')->find($vendorId);
        }

        // Fallback: find or create "Nizami Farms" vendor for this BU
        if (!$vendor) {
            $vendor = \App\Models\FIN\VendorModel::with('account')
                ->where('vendor_name', 'Nizami Farms')
                ->first();

            if (!$vendor) {
                // Create the vendor with account
                $vendor = \App\Models\FIN\VendorModel::getOrCreateVendor('Nizami Farms', auth()->id(), $businessUnitId);
                $vendor->load('account');
            }
        }

        if (!$vendor || !$vendor->account) {
            \Log::warning('Cannot create storage vendor purchase: vendor or vendor account not found');
            return;
        }

        // Get purchase expense account
        $purchaseAccount = \App\Models\FIN\AccountModel::getByCode('EXP_PURCHASES');
        if (!$purchaseAccount) {
            \Log::warning('Cannot create storage vendor purchase: EXP_PURCHASES account not found');
            return;
        }

        // Build description with delivered items and quantities
        $itemDescriptions = [];
        foreach ($lineItems as $item) {
            $product = \App\Models\CRM\ProductModel::find($item->product_id);
            $name = $product ? $product->title : ('Product #' . $item->product_id);
            $qty = (float) $item->quantity;
            // Get unit from storage config
            $config = DB::table('t_crm_khaas_storage_config')
                ->where('source_product_id', $item->product_id)
                ->where('khaas_business_unit_id', $businessUnitId)
                ->first();
            $unit = $config->default_unit ?? 'kg';
            $itemDescriptions[] = "{$name}: {$qty} {$unit}";
        }
        $description = "Meat supply from order {$order->order_number} — " . implode(', ', $itemDescriptions);

        DB::beginTransaction();
        try {
            // Create ledger entry (same pattern as VendorController::recordPurchase)
            \App\Models\FIN\LedgerModel::create([
                'transaction_date' => now()->toDateString(),
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE,
                'description' => $description,
                'from_account_id' => $purchaseAccount->id,
                'to_account_id' => $vendor->account->id,
                'amount' => $totalAmount,
                'mode' => \App\Models\FIN\LedgerModel::MODE_CASH,
                'approval_status' => \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                'business_unit_id' => $businessUnitId,
                'created_by' => auth()->id(),
                'comments' => "Auto-recorded from storage order receipt",
            ]);

            // Update account balances (same as VendorController::recordPurchase)
            $purchaseAccount->current_balance += $totalAmount;
            $purchaseAccount->save();

            $vendor->account->current_balance += $totalAmount;
            $vendor->account->save();

            DB::commit();

            \Log::info('Auto-created storage vendor purchase', [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->vendor_name,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'amount' => $totalAmount,
                'business_unit_id' => $businessUnitId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; // Re-throw so the outer catch logs it
        }
    }

    /**
     * Use (consume) items from storage inventory
     */
    public function useStorageItem(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'product_id' => 'required|integer',
                'variant_id' => 'nullable|integer',
                'quantity' => 'required|numeric|min:0.001',
                'notes' => 'nullable|string|max:500',
            ]);

            $businessUnitId = $request->input('business_unit_id');
            $productId = $request->input('product_id');
            $variantId = $request->input('variant_id');
            $quantity = (float) $request->input('quantity');

            // ⭐ Resolve the CONFIG's variant_id for this product.
            // The API may return a "resolved" variant_id (e.g. default variant 456),
            // but the inventory was stored using the CONFIG's source_variant_id (which may be NULL).
            // We must use the CONFIG's variant for the inventory lookup to match correctly.
            $config = DB::table('t_crm_khaas_storage_config')
                ->where('source_product_id', $productId)
                ->where('khaas_business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->first();

            $storageVariantId = $config ? $config->source_variant_id : $variantId;

            // ⭐ Handle NULL variant_id properly (NULL = NULL is FALSE in SQL)
            $inventoryQuery = DB::table('t_crm_khaas_storage_inventory')
                ->where('source_product_id', $productId)
                ->where('khaas_business_unit_id', $businessUnitId);

            if ($storageVariantId === null) {
                $inventoryQuery->whereNull('source_variant_id');
            } else {
                $inventoryQuery->where('source_variant_id', $storageVariantId);
            }

            $inventory = $inventoryQuery->first();

            // ⭐ Fallback: if no exact match, try product-only match (handles legacy/mismatched records)
            if (!$inventory) {
                $inventory = DB::table('t_crm_khaas_storage_inventory')
                    ->where('source_product_id', $productId)
                    ->where('khaas_business_unit_id', $businessUnitId)
                    ->orderByDesc('quantity_on_hand')
                    ->first();
            }

            if (!$inventory || (float)$inventory->quantity_on_hand < $quantity) {
                $available = $inventory ? $inventory->quantity_on_hand : 0;
                return response()->json([
                    'success' => false,
                    'message' => "Insufficient stock. Available: {$available}",
                ], 400);
            }

            DB::beginTransaction();

            $qtyBefore = (float)$inventory->quantity_on_hand;
            $qtyAfter = $qtyBefore - $quantity;

            DB::table('t_crm_khaas_storage_inventory')
                ->where('id', $inventory->id)
                ->update([
                    'quantity_on_hand' => $qtyAfter,
                    'total_used' => DB::raw("total_used + {$quantity}"),
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('t_crm_khaas_storage_log')->insert([
                'storage_inventory_id' => $inventory->id,
                'source_product_id' => $productId,
                'khaas_business_unit_id' => $businessUnitId,
                'change_type' => 'used',
                'quantity_before' => $qtyBefore,
                'quantity_change' => -$quantity,
                'quantity_after' => $qtyAfter,
                'notes' => $request->input('notes') ?: 'Used/consumed',
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Used {$quantity} — remaining: {$qtyAfter}",
                'quantity_remaining' => $qtyAfter,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to use storage item', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Adjust storage item: add or remove stock manually
     */
    public function adjustStorageItem(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'product_id' => 'required|integer',
                'type' => 'required|in:add,remove',
                'quantity' => 'required|numeric|min:0.001',
            ]);

            $businessUnitId = $request->input('business_unit_id');
            $productId = $request->input('product_id');
            $variantId = $request->input('variant_id');
            $type = $request->input('type');
            $quantity = round((float) $request->input('quantity'), 3);
            $notes = $request->input('notes') ?: ($type === 'add' ? 'Manual stock addition' : 'Manual stock removal');

            $configRow = DB::table('t_crm_khaas_storage_config')
                ->where('source_product_id', $productId)
                ->where('khaas_business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->first();
            // Use config's variant_id for inventory lookup (that's where stock is tracked)
            // Don't fall back to request variant_id — it may be a pricing-resolved variant
            $configVariantId = $configRow?->source_variant_id;

            DB::beginTransaction();

            // Lookup by config's variant_id first (matches how inventory was created)
            $inventoryQuery = DB::table('t_crm_khaas_storage_inventory')
                ->where('source_product_id', $productId)
                ->where('khaas_business_unit_id', $businessUnitId);

            if ($configVariantId) {
                $inventoryQuery->where('source_variant_id', $configVariantId);
            } else {
                $inventoryQuery->whereNull('source_variant_id');
            }

            $inventory = $inventoryQuery->first();

            // Fallback: try request variant_id
            if (!$inventory && $variantId) {
                $inventory = DB::table('t_crm_khaas_storage_inventory')
                    ->where('source_product_id', $productId)
                    ->where('khaas_business_unit_id', $businessUnitId)
                    ->where('source_variant_id', $variantId)
                    ->first();
            }

            // Fallback: pick the row with the most stock
            if (!$inventory) {
                $inventory = DB::table('t_crm_khaas_storage_inventory')
                    ->where('source_product_id', $productId)
                    ->where('khaas_business_unit_id', $businessUnitId)
                    ->orderByDesc('quantity_on_hand')
                    ->first();
            }

            // Create if nothing exists
            if (!$inventory) {
                $newId = DB::table('t_crm_khaas_storage_inventory')->insertGetId([
                    'source_product_id' => $productId,
                    'source_variant_id' => $configVariantId ?: $variantId,
                    'khaas_business_unit_id' => $businessUnitId,
                    'quantity_on_hand' => 0,
                    'total_received' => 0,
                    'total_used' => 0,
                    'created_at' => now(),
                ]);
                $inventory = DB::table('t_crm_khaas_storage_inventory')->where('id', $newId)->first();
            }

            $qtyBefore = (float) $inventory->quantity_on_hand;
            $change = $type === 'add' ? $quantity : -$quantity;

            if ($type === 'remove' && $qtyBefore < $quantity) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => "Only {$qtyBefore} available"], 400);
            }

            $qtyAfter = round($qtyBefore + $change, 3);

            $updates = ['quantity_on_hand' => max(0, $qtyAfter)];
            if ($type === 'add') {
                $updates['total_received'] = DB::raw("total_received + {$quantity}");
            } else {
                $updates['total_used'] = DB::raw("total_used + {$quantity}");
            }

            DB::table('t_crm_khaas_storage_inventory')
                ->where('id', $inventory->id)
                ->update($updates);

            DB::table('t_crm_khaas_storage_log')->insert([
                'storage_inventory_id' => $inventory->id,
                'source_product_id' => $productId,
                'khaas_business_unit_id' => $businessUnitId,
                'change_type' => 'adjustment',
                'quantity_before' => $qtyBefore,
                'quantity_change' => $change,
                'quantity_after' => max(0, $qtyAfter),
                'notes' => $notes,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            DB::commit();

            $label = $type === 'add' ? "Added {$quantity}" : "Removed {$quantity}";
            return response()->json([
                'success' => true,
                'message' => "{$label} — now: {$qtyAfter}",
                'quantity_remaining' => $qtyAfter,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to adjust storage item', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get monthly breakdown of storage movements for a product
     */
    public function getStorageMonthlyBreakdown(Request $request, $productId)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            $logs = DB::table('t_crm_khaas_storage_log')
                ->where('source_product_id', $productId)
                ->where('khaas_business_unit_id', $businessUnitId)
                ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
                ->orderBy('created_at', 'desc')
                ->select('id', 'change_type', 'quantity_before', 'quantity_change', 'quantity_after', 'notes', 'created_at')
                ->get();

            $months = [];
            foreach ($logs as $log) {
                $monthKey = date('Y-m', strtotime($log->created_at));
                $monthLabel = date('M Y', strtotime($log->created_at));
                if (!isset($months[$monthKey])) {
                    $months[$monthKey] = [
                        'month_key' => $monthKey,
                        'month_label' => $monthLabel,
                        'received' => 0,
                        'used' => 0,
                        'adjustments' => 0,
                        'events' => [],
                    ];
                }
                $qty = abs((float) $log->quantity_change);
                if ($log->change_type === 'received') {
                    $months[$monthKey]['received'] += $qty;
                } elseif ($log->change_type === 'used') {
                    $months[$monthKey]['used'] += $qty;
                } elseif ($log->change_type === 'adjustment') {
                    $months[$monthKey]['adjustments'] += (float) $log->quantity_change;
                }
                $months[$monthKey]['events'][] = [
                    'type' => $log->change_type,
                    'change' => round((float) $log->quantity_change, 3),
                    'after' => round((float) $log->quantity_after, 3),
                    'notes' => $log->notes,
                    'date' => date('d M, H:i', strtotime($log->created_at)),
                ];
            }

            return response()->json([
                'success' => true,
                'months' => array_values($months),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update storage customer phone (Admin only)
     */
    public function updateStorageSettings(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'customer_phone' => 'nullable|string|min:6|max:20',
                'vendor_id' => 'nullable|integer|exists:t_fin_vendors,id',
            ]);

            $businessUnitId = $request->input('business_unit_id');

            // Save phone if provided
            if ($request->filled('customer_phone')) {
                \App\Models\FIN\ConfigModel::updateOrCreate(
                    ['config_key' => 'KHAAS_STORAGE_CUSTOMER_PHONE', 'business_unit_id' => $businessUnitId],
                    ['config_value' => $request->input('customer_phone'), 'description' => 'Phone number for Khaas Warehouse customer']
                );
            }

            // Save vendor if provided
            if ($request->has('vendor_id')) {
                $vendorId = $request->input('vendor_id');
                if ($vendorId) {
                    \App\Models\FIN\ConfigModel::updateOrCreate(
                        ['config_key' => 'KHAAS_STORAGE_VENDOR_ID', 'business_unit_id' => $businessUnitId],
                        ['config_value' => (string) $vendorId, 'description' => 'Vendor for auto-recording storage order purchases']
                    );
                } else {
                    // Clear vendor config (revert to default "Nizami Farms")
                    \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_VENDOR_ID')
                        ->where('business_unit_id', $businessUnitId)
                        ->delete();
                }
            }

            return response()->json(['success' => true, 'message' => 'Storage settings updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get or create the "Khaas Warehouse" customer
     */
    private function getOrCreateStorageCustomer(int $businessUnitId): int
    {
        $configKey = 'KHAAS_STORAGE_CUSTOMER_ID';
        $existingId = \App\Models\FIN\ConfigModel::where('config_key', $configKey)
            ->where('business_unit_id', $businessUnitId)
            ->value('config_value');

        if ($existingId) {
            $customer = \App\Models\CRM\CustomerModel::find($existingId);
            if ($customer) return $customer->id;
        }

        // Get phone from config
        $phone = \App\Models\FIN\ConfigModel::where('config_key', 'KHAAS_STORAGE_CUSTOMER_PHONE')
            ->where('business_unit_id', $businessUnitId)
            ->value('config_value') ?: '03001234567';

        // ⭐ Use CustomerModel::normalizePhone() for consistent phone normalization
        // The column phone_normalized is VARCHAR(10), storing last 10 digits only
        $phoneData = \App\Models\CRM\CustomerModel::normalizePhone($phone);

        // Create customer
        $customer = \App\Models\CRM\CustomerModel::create([
            'first_name' => 'Khaas',
            'last_name' => 'Warehouse',
            'phone' => $phoneData['normalized'],
            'phone_original' => $phoneData['original'],
            'phone_normalized' => $phoneData['normalized'],
            'company' => 'Khaas Warehouse',
            'city' => 'Islamabad',
            'country' => 'Pakistan',
        ]);

        // Save customer ID in config
        \App\Models\FIN\ConfigModel::updateOrCreate(
            ['config_key' => $configKey, 'business_unit_id' => $businessUnitId],
            ['config_value' => (string) $customer->id, 'description' => 'Customer ID for Khaas Warehouse storage orders']
        );

        return $customer->id;
    }

    // ======================================================
    // ⭐ INVENTORY & SALES REPORT (Khaas Mode)
    // ======================================================

    /**
     * Monthly inventory & sales report for a Khaas business unit.
     * For each product shows: produced, transferred to shop, sold,
     * plus current warehouse/shop quantities.
     *
     * GET /warehouse/inventory-report?business_unit_id=X&month=YYYY-MM
     */
    public function inventoryReport(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            $monthStr = $request->input('month', now()->format('Y-m'));

            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            // Parse month safely
            try {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Invalid month format. Use YYYY-MM'], 400);
            }
            $endDate = $startDate->copy()->endOfMonth();

            // ─── 1. Get all active products for this BU (including price) ───
            $products = ProductModel::where('business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->orderBy('title')
                ->get(['id', 'title', 'featured_image', 'total_inventory', 'product_type', 'price_min', 'price_max']);

            if ($products->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'month' => $monthStr,
                    'products' => [],
                    'totals' => [
                        'produced' => 0,
                        'warehouse_stock' => 0,
                        'shop_stock' => 0,
                        'inventory_value' => 0,
                    ],
                ]);
            }

            $productIds = $products->pluck('id')->toArray();

            // ─── 2. Selling price per product (prefer first variant price, fallback to price_min) ───
            $priceByProduct = [];
            try {
                $variantPrices = DB::table('t_crm_prod_product_variant')
                    ->whereIn('product_id', $productIds)
                    ->where('price', '>', 0)
                    ->select('product_id', DB::raw('MIN(price) as variant_price'))
                    ->groupBy('product_id')
                    ->get();
                foreach ($variantPrices as $vp) {
                    if ($vp->variant_price > 0) {
                        $priceByProduct[(int) $vp->product_id] = round((float) $vp->variant_price, 2);
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch variant prices', ['error' => $e->getMessage()]);
            }
            foreach ($products as $p) {
                $pid = (int) $p->id;
                if (!isset($priceByProduct[$pid]) && $p->price_min > 0) {
                    $priceByProduct[$pid] = round((float) $p->price_min, 2);
                }
            }

            // ─── 3. Production: Completed batches in the month ───
            $producedByProduct = [];
            try {
                $batchRows = ProductBatchModel::where('business_unit_id', $businessUnitId)
                    ->where('status', ProductBatchModel::STATUS_COMPLETED)
                    ->whereNotNull('ended_at')
                    ->whereBetween('ended_at', [$startDate, $endDate])
                    ->whereIn('product_id', $productIds)
                    ->select('product_id', DB::raw('SUM(quantity_produced) as total_produced'))
                    ->groupBy('product_id')
                    ->get();

                foreach ($batchRows as $row) {
                    $producedByProduct[(int) $row->product_id] = (int) ($row->total_produced ?? 0);
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch batch production', ['error' => $e->getMessage()]);
            }

            // ─── 4. Warehouse stock-in / out / adjustments ───
            $stockInByProduct = [];
            $stockOutByProduct = [];
            $adjustmentsByProduct = [];
            try {
                $logRows = WarehouseInventoryLogModel::where('business_unit_id', $businessUnitId)
                    ->whereIn('product_id', $productIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select(
                        'product_id',
                        'change_type',
                        DB::raw('SUM(quantity_change) as total_change')
                    )
                    ->groupBy('product_id', 'change_type')
                    ->get();

                foreach ($logRows as $row) {
                    $pid = (int) $row->product_id;
                    $val = (int) ($row->total_change ?? 0);

                    switch ($row->change_type) {
                        case 'stock_in':
                            $stockInByProduct[$pid] = ($stockInByProduct[$pid] ?? 0) + $val;
                            break;
                        case 'stock_out':
                            $stockOutByProduct[$pid] = ($stockOutByProduct[$pid] ?? 0) + abs($val);
                            break;
                        case 'adjustment':
                        case 'count':
                            $adjustmentsByProduct[$pid] = ($adjustmentsByProduct[$pid] ?? 0) + $val;
                            break;
                        case 'transfer':
                            break;
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch warehouse logs', ['error' => $e->getMessage()]);
            }

            // ─── 5. Approved transfers to shop in the month ───
            $transferredByProduct = [];
            try {
                $transferRows = WarehouseTransferModel::where('business_unit_id', $businessUnitId)
                    ->where('status', WarehouseTransferModel::STATUS_APPROVED)
                    ->where('to_location', 'store')
                    ->whereIn('product_id', $productIds)
                    ->whereNotNull('approved_at')
                    ->whereBetween('approved_at', [$startDate, $endDate])
                    ->select('product_id', DB::raw('SUM(quantity) as total_transferred'))
                    ->groupBy('product_id')
                    ->get();

                foreach ($transferRows as $row) {
                    $transferredByProduct[(int) $row->product_id] = (int) ($row->total_transferred ?? 0);
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch transfers', ['error' => $e->getMessage()]);
            }

            // ─── 6. Sales ───
            $soldByProduct = [];
            $salesAmountByProduct = [];
            try {
                $salesRows = \App\Models\CRM\OrderLineItemModel::whereIn('t_crm_prod_order_line_item.product_id', $productIds)
                    ->join('t_crm_prod_order as o', 'o.id', '=', 't_crm_prod_order_line_item.order_id')
                    ->whereNotIn('o.order_status', ['cancelled'])
                    ->where(function ($q) {
                        $q->whereNull('o.external_source')
                          ->orWhere('o.external_source', '!=', 'khaas_storage');
                    })
                    ->whereBetween('o.order_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->select(
                        't_crm_prod_order_line_item.product_id',
                        DB::raw('SUM(t_crm_prod_order_line_item.quantity) as total_qty'),
                        DB::raw('SUM(t_crm_prod_order_line_item.line_total) as total_amount')
                    )
                    ->groupBy('t_crm_prod_order_line_item.product_id')
                    ->get();

                foreach ($salesRows as $row) {
                    $pid = (int) $row->product_id;
                    $soldByProduct[$pid] = (float) ($row->total_qty ?? 0);
                    $salesAmountByProduct[$pid] = round((float) ($row->total_amount ?? 0), 2);
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch sales', ['error' => $e->getMessage()]);
            }

            // ─── 7. Current warehouse quantities ───
            $warehouseQtyByProduct = [];
            try {
                $whRows = WarehouseInventoryModel::where('business_unit_id', $businessUnitId)
                    ->where('is_active', true)
                    ->whereIn('product_id', $productIds)
                    ->select('product_id', DB::raw('SUM(quantity) as total_qty'))
                    ->groupBy('product_id')
                    ->get();

                foreach ($whRows as $row) {
                    $warehouseQtyByProduct[(int) $row->product_id] = (int) ($row->total_qty ?? 0);
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch warehouse qtys', ['error' => $e->getMessage()]);
            }

            // ─── 8. Daily breakdown: production demand + warehouse-in per product per day ───
            $dailyByProduct = []; // [product_id => [date => {plan, warehouse_in}]]
            try {
                // Production demand items grouped by product + demand_date
                $demandRows = DB::table('t_crm_khaas_production_demand_item as di')
                    ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
                    ->where('d.business_unit_id', $businessUnitId)
                    ->whereIn('di.khaas_product_id', $productIds)
                    ->whereBetween('d.demand_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->whereNotIn('d.status', ['cancelled'])
                    ->select(
                        'di.khaas_product_id as product_id',
                        'd.demand_date',
                        DB::raw('SUM(di.quantity_kg) as plan_qty')
                    )
                    ->groupBy('di.khaas_product_id', 'd.demand_date')
                    ->get();

                foreach ($demandRows as $row) {
                    $pid = (int) $row->product_id;
                    $date = $row->demand_date;
                    if (!isset($dailyByProduct[$pid])) $dailyByProduct[$pid] = [];
                    if (!isset($dailyByProduct[$pid][$date])) $dailyByProduct[$pid][$date] = ['plan' => 0, 'warehouse_in' => 0];
                    $dailyByProduct[$pid][$date]['plan'] += round((float) $row->plan_qty, 3);
                }

                // Warehouse stock-in grouped by product + date
                $stockInDaily = WarehouseInventoryLogModel::where('business_unit_id', $businessUnitId)
                    ->whereIn('product_id', $productIds)
                    ->where('change_type', 'stock_in')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select(
                        'product_id',
                        DB::raw('DATE(created_at) as log_date'),
                        DB::raw('SUM(quantity_change) as total_in')
                    )
                    ->groupBy('product_id', DB::raw('DATE(created_at)'))
                    ->get();

                foreach ($stockInDaily as $row) {
                    $pid = (int) $row->product_id;
                    $date = $row->log_date;
                    if (!isset($dailyByProduct[$pid])) $dailyByProduct[$pid] = [];
                    if (!isset($dailyByProduct[$pid][$date])) $dailyByProduct[$pid][$date] = ['plan' => 0, 'warehouse_in' => 0];
                    $dailyByProduct[$pid][$date]['warehouse_in'] += (int) $row->total_in;
                }
            } catch (\Exception $e) {
                \Log::warning('Inventory report: failed to fetch daily breakdown', ['error' => $e->getMessage()]);
            }

            // ─── 9. Compute total planned weight from demand data ───
            $totalPlannedWeight = 0;
            foreach ($dailyByProduct as $pid => $dates) {
                foreach ($dates as $date => $vals) {
                    $totalPlannedWeight += $vals['plan'];
                }
            }

            // ─── 10. Build per-product response ───
            $totalProduced = 0;
            $totalTransferred = 0;
            $totalSold = 0;
            $totalSalesAmount = 0;
            $totalInventoryValue = 0;
            $totalWarehouseStock = 0;
            $totalShopStock = 0;
            $totalWarehouseValue = 0;
            $totalShopValue = 0;

            $productData = $products->map(function ($product) use (
                $producedByProduct, $stockInByProduct, $stockOutByProduct, $adjustmentsByProduct,
                $transferredByProduct, $soldByProduct, $salesAmountByProduct, $warehouseQtyByProduct,
                $priceByProduct, $dailyByProduct,
                &$totalProduced, &$totalTransferred, &$totalSold, &$totalSalesAmount,
                &$totalInventoryValue, &$totalWarehouseStock, &$totalShopStock,
                &$totalWarehouseValue, &$totalShopValue
            ) {
                $pid = (int) $product->id;

                $produced = $producedByProduct[$pid] ?? 0;
                $stockIn = $stockInByProduct[$pid] ?? 0;
                $stockOut = $stockOutByProduct[$pid] ?? 0;
                $adjustments = $adjustmentsByProduct[$pid] ?? 0;
                $transferred = $transferredByProduct[$pid] ?? 0;
                $sold = $soldByProduct[$pid] ?? 0;
                $salesAmount = $salesAmountByProduct[$pid] ?? 0;
                $warehouseQty = $warehouseQtyByProduct[$pid] ?? 0;
                $shopQty = (int) ($product->total_inventory ?? 0);
                $sellingPrice = $priceByProduct[$pid] ?? 0;
                $totalQty = $warehouseQty + $shopQty;
                $inventoryValue = round($totalQty * $sellingPrice, 2);
                $whValue = round($warehouseQty * $sellingPrice, 2);
                $shValue = round($shopQty * $sellingPrice, 2);

                $totalProduced += $produced;
                $totalTransferred += $transferred;
                $totalSold += $sold;
                $totalSalesAmount += $salesAmount;
                $totalInventoryValue += $inventoryValue;
                $totalWarehouseStock += $warehouseQty;
                $totalShopStock += $shopQty;
                $totalWarehouseValue += $whValue;
                $totalShopValue += $shValue;

                // Build sorted daily breakdown for this product
                $daily = [];
                if (isset($dailyByProduct[$pid])) {
                    foreach ($dailyByProduct[$pid] as $date => $vals) {
                        $daily[] = [
                            'date' => $date,
                            'plan' => $vals['plan'],
                            'warehouse_in' => $vals['warehouse_in'],
                        ];
                    }
                    usort($daily, fn($a, $b) => strcmp($a['date'], $b['date']));
                }

                return [
                    'product_id' => $pid,
                    'product_name' => $product->title ?? 'Unknown',
                    'product_type' => $product->product_type,
                    'image' => $product->featured_image,
                    'selling_price' => $sellingPrice,
                    'produced' => $produced,
                    'stock_in' => $stockIn,
                    'stock_out' => $stockOut,
                    'adjustments' => $adjustments,
                    'transferred_to_shop' => $transferred,
                    'sold' => $sold,
                    'sales_amount' => $salesAmount,
                    'current_warehouse_qty' => $warehouseQty,
                    'current_shop_qty' => $shopQty,
                    'inventory_value' => $inventoryValue,
                    'daily_breakdown' => $daily,
                ];
            })->values()->toArray();

            return response()->json([
                'success' => true,
                'month' => $monthStr,
                'business_unit_id' => (int) $businessUnitId,
                'products' => $productData,
                'totals' => [
                    'produced' => $totalProduced,
                    'planned_weight' => round($totalPlannedWeight, 2),
                    'transferred' => $totalTransferred,
                    'sold' => $totalSold,
                    'sales_amount' => round($totalSalesAmount, 2),
                    'warehouse_stock' => $totalWarehouseStock,
                    'shop_stock' => $totalShopStock,
                    'warehouse_value' => round($totalWarehouseValue, 2),
                    'shop_value' => round($totalShopValue, 2),
                    'inventory_value' => round($totalInventoryValue, 2),
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate inventory report', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Daily inventory report: for a given month, returns day-by-day totals
     * with per-product breakdown available when expanding a day.
     * GET /warehouse/inventory-report/daily?business_unit_id=X&month=YYYY-MM
     */
    public function dailyInventoryReport(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            $monthStr = $request->input('month', now()->format('Y-m'));

            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            try {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m', $monthStr)->startOfMonth();
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Invalid month format. Use YYYY-MM'], 400);
            }
            $endDate = $startDate->copy()->endOfMonth();

            $productIds = ProductModel::where('business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->pluck('id')
                ->toArray();

            if (empty($productIds)) {
                return response()->json(['success' => true, 'month' => $monthStr, 'days' => []]);
            }

            $productNames = ProductModel::whereIn('id', $productIds)->pluck('title', 'id')->toArray();

            // Production demand by date and product
            $demandByDateProduct = [];
            $demandStatusByDate = [];
            try {
                $demandRows = DB::table('t_crm_khaas_production_demand_item as di')
                    ->join('t_crm_khaas_production_demand as d', 'd.id', '=', 'di.demand_id')
                    ->where('d.business_unit_id', $businessUnitId)
                    ->whereIn('di.khaas_product_id', $productIds)
                    ->whereBetween('d.demand_date', [$startDate->toDateString(), $endDate->toDateString()])
                    ->whereNotIn('d.status', ['cancelled'])
                    ->select(
                        'di.khaas_product_id as product_id',
                        'd.demand_date',
                        'd.status as demand_status',
                        DB::raw('SUM(di.quantity_kg) as plan_qty')
                    )
                    ->groupBy('di.khaas_product_id', 'd.demand_date', 'd.status')
                    ->get();

                foreach ($demandRows as $row) {
                    $date = $row->demand_date;
                    $pid = (int) $row->product_id;
                    if (!isset($demandByDateProduct[$date])) $demandByDateProduct[$date] = [];
                    $demandByDateProduct[$date][$pid] = ($demandByDateProduct[$date][$pid] ?? 0) + round((float) $row->plan_qty, 3);
                    $demandStatusByDate[$date] = $row->demand_status;
                }
            } catch (\Exception $e) {
                \Log::warning('Daily report: failed to fetch demand', ['error' => $e->getMessage()]);
            }

            // Warehouse stock-in by date and product
            $warehouseInByDateProduct = [];
            try {
                $stockInRows = WarehouseInventoryLogModel::where('business_unit_id', $businessUnitId)
                    ->whereIn('product_id', $productIds)
                    ->where('change_type', 'stock_in')
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->select(
                        'product_id',
                        DB::raw('DATE(created_at) as log_date'),
                        DB::raw('SUM(quantity_change) as total_in')
                    )
                    ->groupBy('product_id', DB::raw('DATE(created_at)'))
                    ->get();

                foreach ($stockInRows as $row) {
                    $date = $row->log_date;
                    $pid = (int) $row->product_id;
                    if (!isset($warehouseInByDateProduct[$date])) $warehouseInByDateProduct[$date] = [];
                    $warehouseInByDateProduct[$date][$pid] = ($warehouseInByDateProduct[$date][$pid] ?? 0) + (int) $row->total_in;
                }
            } catch (\Exception $e) {
                \Log::warning('Daily report: failed to fetch warehouse-in', ['error' => $e->getMessage()]);
            }

            // Merge all dates and build response
            $allDates = array_unique(array_merge(
                array_keys($demandByDateProduct),
                array_keys($warehouseInByDateProduct)
            ));
            sort($allDates);

            $days = [];
            foreach ($allDates as $date) {
                $demandProducts = $demandByDateProduct[$date] ?? [];
                $warehouseProducts = $warehouseInByDateProduct[$date] ?? [];
                $allPids = array_unique(array_merge(array_keys($demandProducts), array_keys($warehouseProducts)));
                sort($allPids);

                $totalPlan = 0;
                $totalWarehouseIn = 0;
                $products = [];

                foreach ($allPids as $pid) {
                    $plan = $demandProducts[$pid] ?? 0;
                    $whIn = $warehouseProducts[$pid] ?? 0;
                    $totalPlan += $plan;
                    $totalWarehouseIn += $whIn;

                    $products[] = [
                        'product_id' => $pid,
                        'product_name' => $productNames[$pid] ?? 'Unknown',
                        'plan_qty' => $plan,
                        'warehouse_in' => $whIn,
                        'diff' => round($whIn - $plan, 3),
                    ];
                }

                $days[] = [
                    'date' => $date,
                    'demand_status' => $demandStatusByDate[$date] ?? null,
                    'total_plan' => round($totalPlan, 3),
                    'total_warehouse_in' => $totalWarehouseIn,
                    'total_diff' => round($totalWarehouseIn - $totalPlan, 3),
                    'products' => $products,
                ];
            }

            // Reverse so newest date first
            $days = array_reverse($days);

            return response()->json([
                'success' => true,
                'month' => $monthStr,
                'business_unit_id' => (int) $businessUnitId,
                'days' => $days,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to generate daily inventory report', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: ' . $e->getMessage()
            ], 500);
        }
    }

    // ================================================================
    // PRODUCTION DEMAND & RECIPE MAPPING
    // ================================================================

    /**
     * Get product recipe mappings (raw material → finished product)
     */
    public function getProductRecipes(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            // Inventory-linked recipes
            $invRecipes = DB::table('t_crm_khaas_product_recipe as r')
                ->join('t_crm_prod_product as kp', 'kp.id', '=', 'r.khaas_product_id')
                ->join('t_crm_prod_product as sp', 'sp.id', '=', 'r.storage_product_id')
                ->where('kp.business_unit_id', $businessUnitId)
                ->where('r.is_active', 1)
                ->where('kp.is_active', 1)
                ->whereNotNull('r.storage_product_id')
                ->select(
                    'r.id as recipe_id',
                    'r.khaas_product_id',
                    'kp.title as khaas_product_name',
                    'r.storage_product_id',
                    'r.storage_variant_id',
                    'r.custom_material_id',
                    'sp.title as storage_product_name',
                    'r.ratio_kg',
                    DB::raw('0 as is_custom')
                )
                ->orderBy('kp.title')
                ->orderBy('sp.title')
                ->get();

            // Custom material recipes (gracefully handle if migration not run yet)
            try {
                $customRecipes = DB::table('t_crm_khaas_product_recipe as r')
                    ->join('t_crm_prod_product as kp', 'kp.id', '=', 'r.khaas_product_id')
                    ->join('t_crm_khaas_custom_material as cm', 'cm.id', '=', 'r.custom_material_id')
                    ->where('kp.business_unit_id', $businessUnitId)
                    ->where('r.is_active', 1)
                    ->where('kp.is_active', 1)
                    ->whereNotNull('r.custom_material_id')
                    ->select(
                        'r.id as recipe_id',
                        'r.khaas_product_id',
                        'kp.title as khaas_product_name',
                        DB::raw('NULL as storage_product_id'),
                        DB::raw('NULL as storage_variant_id'),
                        'r.custom_material_id',
                        DB::raw('CONCAT(cm.name, " (", cm.unit, ")") as storage_product_name'),
                        'r.ratio_kg',
                        DB::raw('1 as is_custom')
                    )
                    ->orderBy('kp.title')
                    ->orderBy('cm.name')
                    ->get();
            } catch (\Exception $e) {
                $customRecipes = collect();
            }

            $recipes = $invRecipes->concat($customRecipes);

            $grouped = $recipes->groupBy('khaas_product_id')->map(function($items) {
                $first = $items->first();
                return [
                    'khaas_product_id' => $first->khaas_product_id,
                    'khaas_product_name' => $first->khaas_product_name,
                    'materials' => $items->map(function($r) {
                        return [
                            'recipe_id' => $r->recipe_id,
                            'storage_product_id' => $r->storage_product_id,
                            'storage_product_name' => $r->storage_product_name,
                            'storage_variant_id' => $r->storage_variant_id,
                            'custom_material_id' => $r->custom_material_id,
                            'is_custom' => (bool) $r->is_custom,
                            'ratio_kg' => (float) $r->ratio_kg,
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json(['success' => true, 'recipes' => $grouped]);
        } catch (\Exception $e) {
            \Log::error('Failed to get recipes', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load recipes'], 500);
        }
    }

    /**
     * Save a recipe mapping (khaas product → storage raw material OR custom material)
     */
    public function saveProductRecipe(Request $request)
    {
        try {
            $khaasProductId = $request->input('khaas_product_id');
            $storageProductId = $request->input('storage_product_id');
            $customMaterialId = $request->input('custom_material_id');

            if (!$khaasProductId) {
                return response()->json(['success' => false, 'message' => 'khaas_product_id is required'], 400);
            }
            if (!$storageProductId && !$customMaterialId) {
                return response()->json(['success' => false, 'message' => 'storage_product_id or custom_material_id is required'], 400);
            }

            if ($customMaterialId) {
                DB::table('t_crm_khaas_product_recipe')->updateOrInsert(
                    [
                        'khaas_product_id' => $khaasProductId,
                        'custom_material_id' => $customMaterialId,
                    ],
                    [
                        'storage_product_id' => null,
                        'storage_variant_id' => null,
                        'ratio_kg' => 1.000,
                        'is_active' => 1,
                        'updated_at' => now(),
                        'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            } else {
                DB::table('t_crm_khaas_product_recipe')->updateOrInsert(
                    [
                        'khaas_product_id' => $khaasProductId,
                        'storage_product_id' => $storageProductId,
                    ],
                    [
                        'custom_material_id' => null,
                        'storage_variant_id' => $request->input('storage_variant_id'),
                        'ratio_kg' => 1.000,
                        'is_active' => 1,
                        'updated_at' => now(),
                        'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                    ]
                );
            }

            return response()->json(['success' => true, 'message' => 'Recipe mapping saved']);
        } catch (\Exception $e) {
            \Log::error('Failed to save recipe', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to save: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create or get a custom material (not tracked in inventory)
     */
    public function saveCustomMaterial(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'name' => 'required|string|max:255',
                'unit' => 'nullable|string|max:50',
            ]);

            $name = trim($request->input('name'));
            $unit = trim($request->input('unit', 'kg'));
            $buId = $request->input('business_unit_id');

            $existing = DB::table('t_crm_khaas_custom_material')
                ->where('name', $name)
                ->where('business_unit_id', $buId)
                ->first();

            if ($existing) {
                if (!$existing->is_active) {
                    DB::table('t_crm_khaas_custom_material')
                        ->where('id', $existing->id)
                        ->update(['is_active' => 1, 'updated_at' => now()]);
                }
                return response()->json([
                    'success' => true,
                    'material' => ['id' => $existing->id, 'name' => $existing->name, 'unit' => $existing->unit],
                ]);
            }

            $id = DB::table('t_crm_khaas_custom_material')->insertGetId([
                'name' => $name,
                'unit' => $unit,
                'business_unit_id' => $buId,
                'is_active' => 1,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'material' => ['id' => $id, 'name' => $name, 'unit' => $unit],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to save custom material', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * List custom materials for a business unit
     */
    public function getCustomMaterials(Request $request)
    {
        try {
            $buId = $request->input('business_unit_id');
            $materials = DB::table('t_crm_khaas_custom_material')
                ->where('business_unit_id', $buId)
                ->where('is_active', 1)
                ->orderBy('name')
                ->get(['id', 'name', 'unit']);

            return response()->json(['success' => true, 'materials' => $materials]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a recipe mapping
     */
    public function deleteProductRecipe(Request $request)
    {
        try {
            $recipeId = $request->input('recipe_id');
            DB::table('t_crm_khaas_product_recipe')->where('id', $recipeId)->update(['is_active' => 0]);
            return response()->json(['success' => true, 'message' => 'Mapping removed']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to remove'], 500);
        }
    }

    /**
     * Get ALL active Khaas products for demand creation, with recipe info where available
     */
    public function getDemandProducts(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            // All active Khaas products
            $allProducts = DB::table('t_crm_prod_product')
                ->where('business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->select('id', 'title')
                ->orderBy('title')
                ->get();

            // Inventory-linked recipes
            $buId = (int) $businessUnitId;
            $invRecipes = DB::table('t_crm_khaas_product_recipe as r')
                ->join('t_crm_prod_product as sp', 'sp.id', '=', 'r.storage_product_id')
                ->where('r.is_active', 1)
                ->whereNotNull('r.storage_product_id')
                ->select(
                    'r.khaas_product_id',
                    'r.storage_product_id',
                    'sp.title as raw_material_name',
                    'r.storage_variant_id',
                    'r.ratio_kg',
                    DB::raw("(SELECT COALESCE(MAX(si.quantity_on_hand), 0) FROM t_crm_khaas_storage_inventory si WHERE si.source_product_id = r.storage_product_id AND si.khaas_business_unit_id = {$buId}) as raw_material_available"),
                    DB::raw('0 as is_custom')
                )
                ->get()
                ->groupBy('khaas_product_id');

            // Custom material recipes (gracefully handle if migration not run yet)
            try {
                $customRecipes = DB::table('t_crm_khaas_product_recipe as r')
                    ->join('t_crm_khaas_custom_material as cm', 'cm.id', '=', 'r.custom_material_id')
                    ->where('r.is_active', 1)
                    ->whereNotNull('r.custom_material_id')
                    ->select(
                        'r.khaas_product_id',
                        DB::raw('NULL as storage_product_id'),
                        DB::raw('CONCAT(cm.name, " (", cm.unit, ")") as raw_material_name'),
                        DB::raw('NULL as storage_variant_id'),
                        'r.ratio_kg',
                        DB::raw('0 as raw_material_available'),
                        DB::raw('1 as is_custom')
                    )
                    ->get()
                    ->groupBy('khaas_product_id');
            } catch (\Exception $e) {
                $customRecipes = collect();
            }

            $products = $allProducts->map(function($p) use ($invRecipes, $customRecipes) {
                $pid = $p->id;
                $materials = [];

                foreach (($invRecipes[$pid] ?? collect()) as $r) {
                    $materials[] = [
                        'storage_product_id' => $r->storage_product_id,
                        'raw_material_name' => $r->raw_material_name,
                        'storage_variant_id' => $r->storage_variant_id,
                        'ratio_kg' => (float) $r->ratio_kg,
                        'raw_material_available' => round((float) $r->raw_material_available, 3),
                        'is_custom' => false,
                    ];
                }
                foreach (($customRecipes[$pid] ?? collect()) as $r) {
                    $materials[] = [
                        'storage_product_id' => null,
                        'raw_material_name' => $r->raw_material_name,
                        'storage_variant_id' => null,
                        'ratio_kg' => (float) $r->ratio_kg,
                        'raw_material_available' => 0,
                        'is_custom' => true,
                    ];
                }

                $invMaterials = array_filter($materials, fn($m) => !$m['is_custom']);
                return [
                    'khaas_product_id' => $pid,
                    'product_name' => $p->title,
                    'raw_materials' => array_values($materials),
                    'raw_material_name' => empty($materials) ? 'Unlinked' : implode(' + ', array_column($materials, 'raw_material_name')),
                    'raw_material_available' => empty($invMaterials) ? 0 : round(min(array_column($invMaterials, 'raw_material_available')), 3),
                    'has_recipe' => !empty($materials),
                ];
            })->values();

            return response()->json(['success' => true, 'products' => $products]);
        } catch (\Exception $e) {
            \Log::error('Failed to get demand products', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load products'], 500);
        }
    }

    /**
     * Get current/recent demands
     */
    public function getDemands(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            $demands = DB::table('t_crm_khaas_production_demand as d')
                ->leftJoin('t_sys_user as cu', 'cu.id', '=', 'd.created_by')
                ->leftJoin('t_sys_user as au', 'au.id', '=', 'd.accepted_by')
                ->where('d.business_unit_id', $businessUnitId)
                ->whereNotIn('d.status', ['completed', 'cancelled'])
                ->select(
                    'd.*',
                    'cu.fullname as created_by_name',
                    'au.fullname as accepted_by_name'
                )
                ->orderByDesc('d.demand_date')
                ->orderByDesc('d.created_at')
                ->limit(20)
                ->get();

            // Load items for each demand
            $demandIds = $demands->pluck('id')->toArray();
            $items = DB::table('t_crm_khaas_production_demand_item as di')
                ->join('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->leftJoin('t_crm_prod_product as sp', 'sp.id', '=', 'di.storage_product_id')
                ->whereIn('di.demand_id', $demandIds)
                ->select(
                    'di.*',
                    'kp.title as product_name',
                    'sp.title as raw_material_name'
                )
                ->orderBy('kp.title')
                ->get()
                ->groupBy('demand_id');

            // Pre-load recipe mappings with ratio and availability info
            $allKhaasIds = $items->flatten(1)->pluck('khaas_product_id')->unique()->toArray();
            $recipeMap = [];
            $recipeDetails = [];
            if (!empty($allKhaasIds)) {
                $buId = (int) $businessUnitId;
                $recipeRows = DB::table('t_crm_khaas_product_recipe as r')
                    ->join('t_crm_prod_product as sp', 'sp.id', '=', 'r.storage_product_id')
                    ->whereIn('r.khaas_product_id', $allKhaasIds)
                    ->where('r.is_active', 1)
                    ->select(
                        'r.khaas_product_id',
                        'r.storage_product_id',
                        'sp.title as storage_product_name',
                        'r.ratio_kg',
                        DB::raw("(SELECT COALESCE(MAX(si.quantity_on_hand), 0) FROM t_crm_khaas_storage_inventory si WHERE si.source_product_id = r.storage_product_id AND si.khaas_business_unit_id = {$buId}) as available_kg")
                    )
                    ->get();
                foreach ($recipeRows as $rr) {
                    $recipeMap[$rr->khaas_product_id][] = $rr->storage_product_name;
                    $recipeDetails[$rr->khaas_product_id][] = $rr;
                }
            }

            $result = $demands->map(function($d) use ($items, $recipeMap, $recipeDetails) {
                $demandItemsList = $items->get($d->id, collect());

                // Aggregate total raw material needs across ALL items in this demand
                $totalNeeds = [];
                foreach ($demandItemsList as $di) {
                    if ($di->status !== 'pending') continue;
                    $recipes = $recipeDetails[$di->khaas_product_id] ?? [];
                    foreach ($recipes as $recipe) {
                        $key = $recipe->storage_product_id;
                        if (!isset($totalNeeds[$key])) {
                            $totalNeeds[$key] = [
                                'material' => $recipe->storage_product_name,
                                'total_needed' => 0,
                                'available' => (float) $recipe->available_kg,
                            ];
                        }
                        $totalNeeds[$key]['total_needed'] += (float) $di->quantity_kg * (float) $recipe->ratio_kg;
                    }
                }

                $demandItems = $demandItemsList->map(function($i) use ($recipeMap, $recipeDetails, $totalNeeds) {
                    $materialNames = $recipeMap[$i->khaas_product_id] ?? [];
                    $rawMaterialName = $i->raw_material_name ?: implode(' + ', $materialNames);

                    // Per-item shortages using aggregated demand totals
                    $shortages = [];
                    $recipes = $recipeDetails[$i->khaas_product_id] ?? [];
                    if ($i->status === 'pending' && !empty($recipes)) {
                        foreach ($recipes as $recipe) {
                            $key = $recipe->storage_product_id;
                            $agg = $totalNeeds[$key] ?? null;
                            if ($agg && $agg['total_needed'] > $agg['available']) {
                                $shortages[] = [
                                    'material' => $recipe->storage_product_name,
                                    'needed' => round($agg['total_needed'], 2),
                                    'available' => round($agg['available'], 2),
                                ];
                            }
                        }
                    }

                    return [
                        'id' => $i->id,
                        'khaas_product_id' => $i->khaas_product_id,
                        'product_name' => $i->product_name,
                        'quantity_kg' => (float) $i->quantity_kg,
                        'raw_material_name' => $rawMaterialName,
                        'raw_materials' => $materialNames,
                        'storage_deducted' => (bool) $i->storage_deducted,
                        'storage_deducted_qty' => $i->storage_deducted_qty ? (float) $i->storage_deducted_qty : null,
                        'batch_id' => $i->batch_id,
                        'status' => $i->status,
                        'shortages' => $shortages,
                    ];
                });

                return [
                    'id' => $d->id,
                    'demand_date' => $d->demand_date,
                    'status' => $d->status,
                    'notes' => $d->notes,
                    'created_by_name' => $d->created_by_name,
                    'accepted_by_name' => $d->accepted_by_name,
                    'accepted_at' => $d->accepted_at,
                    'completed_at' => $d->completed_at,
                    'created_at' => $d->created_at,
                    'items' => $demandItems->values(),
                    'total_kg' => $demandItems->sum('quantity_kg'),
                ];
            });

            return response()->json(['success' => true, 'demands' => $result]);
        } catch (\Exception $e) {
            \Log::error('Failed to get demands', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to load demands'], 500);
        }
    }

    /**
     * Get completed/cancelled demand history
     */
    public function getDemandHistory(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            if (!$businessUnitId) {
                return response()->json(['success' => false, 'message' => 'business_unit_id required'], 400);
            }

            $demands = DB::table('t_crm_khaas_production_demand as d')
                ->leftJoin('t_sys_user as cu', 'cu.id', '=', 'd.created_by')
                ->leftJoin('t_sys_user as au', 'au.id', '=', 'd.accepted_by')
                ->where('d.business_unit_id', $businessUnitId)
                ->whereIn('d.status', ['completed', 'cancelled'])
                ->select('d.*', 'cu.fullname as created_by_name', 'au.fullname as accepted_by_name')
                ->orderByDesc('d.completed_at')
                ->orderByDesc('d.demand_date')
                ->limit(30)
                ->get();

            $demandIds = $demands->pluck('id')->toArray();
            $items = DB::table('t_crm_khaas_production_demand_item as di')
                ->join('t_crm_prod_product as kp', 'kp.id', '=', 'di.khaas_product_id')
                ->leftJoin('t_crm_prod_product as sp', 'sp.id', '=', 'di.storage_product_id')
                ->whereIn('di.demand_id', $demandIds)
                ->select('di.*', 'kp.title as product_name', 'sp.title as raw_material_name')
                ->orderBy('kp.title')
                ->get()
                ->groupBy('demand_id');

            $allKhaasIds = $items->flatten(1)->pluck('khaas_product_id')->unique()->toArray();
            $recipeMap = [];
            if (!empty($allKhaasIds)) {
                $recipeRows = DB::table('t_crm_khaas_product_recipe as r')
                    ->join('t_crm_prod_product as sp', 'sp.id', '=', 'r.storage_product_id')
                    ->whereIn('r.khaas_product_id', $allKhaasIds)
                    ->where('r.is_active', 1)
                    ->select('r.khaas_product_id', 'sp.title as storage_product_name')
                    ->get();
                foreach ($recipeRows as $rr) {
                    $recipeMap[$rr->khaas_product_id][] = $rr->storage_product_name;
                }
            }

            $result = $demands->map(function($d) use ($items, $recipeMap) {
                $demandItems = $items->get($d->id, collect())->map(function($i) use ($recipeMap) {
                    $materialNames = $recipeMap[$i->khaas_product_id] ?? [];
                    $rawMaterialName = $i->raw_material_name ?: implode(' + ', $materialNames);
                    return [
                        'id' => $i->id,
                        'product_name' => $i->product_name,
                        'quantity_kg' => (float) $i->quantity_kg,
                        'raw_material_name' => $rawMaterialName,
                        'raw_materials' => $materialNames,
                        'storage_deducted_qty' => $i->storage_deducted_qty ? (float) $i->storage_deducted_qty : null,
                        'status' => $i->status,
                    ];
                });
                return [
                    'id' => $d->id,
                    'demand_date' => $d->demand_date,
                    'status' => $d->status,
                    'created_by_name' => $d->created_by_name,
                    'accepted_by_name' => $d->accepted_by_name,
                    'completed_at' => $d->completed_at,
                    'items' => $demandItems->values(),
                    'total_kg' => $demandItems->sum('quantity_kg'),
                ];
            });

            return response()->json(['success' => true, 'demands' => $result]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load history'], 500);
        }
    }

    /**
     * Create a new production demand (Taimur/admin only)
     */
    public function createDemand(Request $request)
    {
        try {
            $request->validate([
                'business_unit_id' => 'required|integer',
                'demand_date' => 'required|date',
                'items' => 'required|array|min:1',
                'items.*.khaas_product_id' => 'required|integer',
                'items.*.quantity_kg' => 'required|numeric|min:0.001',
                'notes' => 'nullable|string|max:500',
            ]);

            $businessUnitId = $request->input('business_unit_id');
            $demandDate = $request->input('demand_date');

            DB::beginTransaction();

            $demandId = DB::table('t_crm_khaas_production_demand')->insertGetId([
                'business_unit_id' => $businessUnitId,
                'demand_date' => $demandDate,
                'status' => 'submitted',
                'notes' => $request->input('notes'),
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($request->input('items') as $item) {
                DB::table('t_crm_khaas_production_demand_item')->insert([
                    'demand_id' => $demandId,
                    'khaas_product_id' => $item['khaas_product_id'],
                    'quantity_kg' => $item['quantity_kg'],
                    'storage_product_id' => null,
                    'storage_variant_id' => null,
                    'status' => 'pending',
                    'notes' => $item['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            // Send push notification to all Khaas mode users
            try {
                $creatorName = auth()->user()->name ?? auth()->user()->fullname ?? 'Someone';
                $totalKg = collect($request->input('items'))->sum('quantity_kg');
                app(\App\Services\FirebaseService::class)->notifyNewPlan($creatorName, $demandDate, $demandId, round($totalKg, 1));
            } catch (\Exception $pushErr) {
                \Log::debug('Demand push notification failed (non-fatal)', ['error' => $pushErr->getMessage()]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Production demand created for ' . date('M d', strtotime($demandDate)),
                'demand_id' => $demandId,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create demand', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to create demand: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing production demand (admin only, before accepted)
     */
    public function updateDemand(Request $request, $demandId)
    {
        try {
            $request->validate([
                'items' => 'required|array|min:1',
                'items.*.khaas_product_id' => 'required|integer',
                'items.*.quantity_kg' => 'required|numeric|min:0.001',
                'notes' => 'nullable|string|max:500',
            ]);

            $demand = DB::table('t_crm_khaas_production_demand')->where('id', $demandId)->first();
            if (!$demand) {
                return response()->json(['success' => false, 'message' => 'Demand not found'], 404);
            }
            if (!in_array($demand->status, ['draft', 'submitted'])) {
                return response()->json(['success' => false, 'message' => 'Can only edit plans in draft or submitted status'], 400);
            }

            DB::beginTransaction();

            DB::table('t_crm_khaas_production_demand_item')->where('demand_id', $demandId)->delete();

            foreach ($request->input('items') as $item) {
                DB::table('t_crm_khaas_production_demand_item')->insert([
                    'demand_id' => $demandId,
                    'khaas_product_id' => $item['khaas_product_id'],
                    'quantity_kg' => $item['quantity_kg'],
                    'storage_product_id' => null,
                    'storage_variant_id' => null,
                    'status' => 'pending',
                    'notes' => $item['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('t_crm_khaas_production_demand')
                ->where('id', $demandId)
                ->update([
                    'notes' => $request->input('notes'),
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Production plan updated successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update demand', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to update demand: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Accept a demand: deducts storage inventory + auto-starts batches
     */
    public function acceptDemand(Request $request, $demandId)
    {
        try {
            DB::beginTransaction();

            // Lock the demand row to prevent concurrent accepts
            $demand = DB::table('t_crm_khaas_production_demand')
                ->where('id', $demandId)
                ->lockForUpdate()
                ->first();

            if (!$demand) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Demand not found'], 404);
            }
            if (!in_array($demand->status, ['submitted', 'accepted'])) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Demand cannot be accepted in current status (' . $demand->status . ')'], 400);
            }

            $businessUnitId = $demand->business_unit_id;
            $items = DB::table('t_crm_khaas_production_demand_item')
                ->where('demand_id', $demandId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'No pending items to process'], 400);
            }

            // Check storage availability inside transaction with locked rows
            $shortages = [];
            $storageNeeds = [];
            foreach ($items as $item) {
                $recipes = DB::table('t_crm_khaas_product_recipe')
                    ->where('khaas_product_id', $item->khaas_product_id)
                    ->where('is_active', 1)
                    ->whereNotNull('storage_product_id')
                    ->get();
                if ($recipes->isEmpty()) continue;

                foreach ($recipes as $recipe) {
                    $key = $recipe->storage_product_id . '-' . ($recipe->storage_variant_id ?: '0');
                    if (!isset($storageNeeds[$key])) {
                        $storageNeeds[$key] = [
                            'product_id' => $recipe->storage_product_id,
                            'variant_id' => $recipe->storage_variant_id,
                            'needed' => 0,
                        ];
                    }
                    $storageNeeds[$key]['needed'] += $item->quantity_kg * (float) $recipe->ratio_kg;
                }
            }

            foreach ($storageNeeds as $need) {
                $config = DB::table('t_crm_khaas_storage_config')
                    ->where('source_product_id', $need['product_id'])
                    ->where('khaas_business_unit_id', $businessUnitId)
                    ->where('is_active', 1)
                    ->first();
                $configVariantId = $config?->source_variant_id;

                $invQuery = DB::table('t_crm_khaas_storage_inventory')
                    ->where('source_product_id', $need['product_id'])
                    ->where('khaas_business_unit_id', $businessUnitId);

                if ($configVariantId) {
                    $invQuery->where('source_variant_id', $configVariantId);
                } else {
                    $invQuery->whereNull('source_variant_id');
                }

                $inv = $invQuery->lockForUpdate()->first();

                if (!$inv) {
                    $inv = DB::table('t_crm_khaas_storage_inventory')
                        ->where('source_product_id', $need['product_id'])
                        ->where('khaas_business_unit_id', $businessUnitId)
                        ->orderByDesc('quantity_on_hand')
                        ->lockForUpdate()
                        ->first();
                }

                $available = $inv ? (float) $inv->quantity_on_hand : 0;
                if ($available < $need['needed']) {
                    $productName = DB::table('t_crm_prod_product')->where('id', $need['product_id'])->value('title');
                    $shortages[] = "{$productName}: need " . round($need['needed'], 2) . "kg, have " . round($available, 2) . "kg";
                }
            }

            if (!empty($shortages)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient raw materials',
                    'shortages' => $shortages,
                ], 400);
            }

            // Process each item: deduct inventory-linked raw materials (skip custom materials)
            foreach ($items as $item) {
                $recipes = DB::table('t_crm_khaas_product_recipe')
                    ->where('khaas_product_id', $item->khaas_product_id)
                    ->where('is_active', 1)
                    ->whereNotNull('storage_product_id')
                    ->get();

                $totalDeducted = 0;

                foreach ($recipes as $recipe) {
                    $deductQty = round($item->quantity_kg * (float) $recipe->ratio_kg, 3);

                    $recipeConfig = DB::table('t_crm_khaas_storage_config')
                        ->where('source_product_id', $recipe->storage_product_id)
                        ->where('khaas_business_unit_id', $businessUnitId)
                        ->where('is_active', 1)
                        ->first();
                    $rcVariantId = $recipeConfig?->source_variant_id;

                    $invQuery = DB::table('t_crm_khaas_storage_inventory')
                        ->where('source_product_id', $recipe->storage_product_id)
                        ->where('khaas_business_unit_id', $businessUnitId);

                    if ($rcVariantId) {
                        $invQuery->where('source_variant_id', $rcVariantId);
                    } else {
                        $invQuery->whereNull('source_variant_id');
                    }

                    $inv = $invQuery->first();

                    if (!$inv) {
                        $inv = DB::table('t_crm_khaas_storage_inventory')
                            ->where('source_product_id', $recipe->storage_product_id)
                            ->where('khaas_business_unit_id', $businessUnitId)
                            ->orderByDesc('quantity_on_hand')
                            ->first();
                    }

                    if ($inv) {
                        $qtyBefore = (float) $inv->quantity_on_hand;
                        $qtyAfter = $qtyBefore - $deductQty;

                        DB::table('t_crm_khaas_storage_inventory')
                            ->where('id', $inv->id)
                            ->update([
                                'quantity_on_hand' => max(0, $qtyAfter),
                                'total_used' => DB::raw("total_used + {$deductQty}"),
                                'last_used_at' => now(),
                            ]);

                        DB::table('t_crm_khaas_storage_log')->insert([
                            'storage_inventory_id' => $inv->id,
                            'source_product_id' => $recipe->storage_product_id,
                            'khaas_business_unit_id' => $businessUnitId,
                            'change_type' => 'used',
                            'quantity_before' => $qtyBefore,
                            'quantity_change' => -$deductQty,
                            'quantity_after' => max(0, $qtyAfter),
                            'notes' => 'Production demand #' . $demandId . ' for ' . date('M d', strtotime($demand->demand_date)),
                            'created_by' => auth()->id(),
                            'created_at' => now(),
                        ]);

                        $totalDeducted += $deductQty;
                    }
                }

                // Auto-start batch for this product
                $existingBatch = \App\Models\CRM\ProductBatchModel::getActiveBatch($item->khaas_product_id, $businessUnitId);
                $batchId = null;
                if (!$existingBatch) {
                    $batchNumber = \App\Models\CRM\ProductBatchModel::generateBatchNumber($item->khaas_product_id, $businessUnitId);
                    $batch = \App\Models\CRM\ProductBatchModel::create([
                        'product_id' => $item->khaas_product_id,
                        'product_variant_id' => \App\Models\CRM\ProductModel::find($item->khaas_product_id)?->variants?->first()?->id,
                        'business_unit_id' => $businessUnitId,
                        'batch_number' => $batchNumber,
                        'status' => \App\Models\CRM\ProductBatchModel::STATUS_IN_PROGRESS,
                        'started_at' => now(),
                        'notes_start' => 'Auto-started from demand #' . $demandId . ' (' . round($item->quantity_kg, 2) . 'kg)',
                        'started_by' => auth()->id(),
                    ]);
                    $batchId = $batch->id;
                } else {
                    $batchId = $existingBatch->id;
                }

                // Update demand item
                DB::table('t_crm_khaas_production_demand_item')
                    ->where('id', $item->id)
                    ->update([
                        'status' => 'in_progress',
                        'storage_deducted' => 1,
                        'storage_deducted_qty' => $totalDeducted,
                        'batch_id' => $batchId,
                        'updated_at' => now(),
                    ]);
            }

            // Update demand status
            DB::table('t_crm_khaas_production_demand')
                ->where('id', $demandId)
                ->update([
                    'status' => 'in_progress',
                    'accepted_by' => auth()->id(),
                    'accepted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Demand accepted. Raw materials deducted and batches started.',
            ]);
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            \Log::error('Failed to accept demand', [
                'demand_id' => $demandId,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to accept demand: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a demand
     */
    public function cancelDemand(Request $request, $demandId)
    {
        try {
            $demand = DB::table('t_crm_khaas_production_demand')->where('id', $demandId)->first();
            if (!$demand) {
                return response()->json(['success' => false, 'message' => 'Demand not found'], 404);
            }

            if (in_array($demand->status, ['completed', 'cancelled'])) {
                return response()->json(['success' => false, 'message' => 'Cannot cancel a ' . $demand->status . ' demand'], 400);
            }

            DB::table('t_crm_khaas_production_demand')
                ->where('id', $demandId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            DB::table('t_crm_khaas_production_demand_item')
                ->where('demand_id', $demandId)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return response()->json(['success' => true, 'message' => 'Demand cancelled']);
        } catch (\Exception $e) {
            \Log::error('Failed to cancel demand', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to cancel demand'], 500);
        }
    }
}
