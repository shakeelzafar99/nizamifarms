<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\OrderStatusService;
use App\Models\CRM\OrderStatusMaster;
use App\Models\CRM\OrderModel;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class OrderStatusController extends Controller
{
    protected OrderStatusService $statusService;

    public function __construct(OrderStatusService $statusService)
    {
        $this->statusService = $statusService;
    }

    /**
     * Display order status management page
     */
    public function index(): View
    {
        $statuses = $this->statusService->getAllStatuses();
        $statistics = $this->statusService->getStatusStatistics();

        return view('pages.order-status.index', compact('statuses', 'statistics'));
    }

    /**
     * Get all statuses (API)
     *
     * By default excludes "legacy" statuses so they don't appear in order-status pickers
     * (the orders page dropdown, etc.). The management Hub passes ?all=1 to see everything.
     */
    public function getAllStatuses(Request $request): JsonResponse
    {
        try {
            $statuses = $this->statusService->getAllStatuses();

            if (!$request->boolean('all')) {
                $statuses = $statuses
                    ->reject(fn ($s) => ($s->lane ?? 'journey') === 'legacy')
                    ->values();
            }

            return response()->json([
                'success' => true,
                'data' => $statuses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statuses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get status statistics (API)
     */
    public function getStatistics(): JsonResponse
    {
        try {
            $statistics = $this->statusService->getStatusStatistics();
            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create new status
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status_code' => 'required|string|max:50|regex:/^[a-z_]+$/',
                'status_name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'sequence_order' => 'nullable|integer|min:1',
                'is_final' => 'nullable|boolean',
                'color_class' => 'nullable|string|max:50',
                'icon' => 'nullable|string|max:20',  // Emojis are typically 1-4 chars
                'show_in_mobile' => 'nullable|boolean',
                'visible_to_roles' => 'nullable|array',
                'visible_to_roles.*' => 'integer',
                // Order Status Hub behaviour columns
                'counts_in_quantities' => 'nullable|boolean',
                'auto_prepares' => 'nullable|boolean',
                'lane' => 'nullable|in:journey,offtrack,legacy',
                'send_to_customer_app' => 'nullable|boolean',
                'customer_app_alias' => 'nullable|string|max:50',
                'customer_app_hold_until_dispatch' => 'nullable|boolean'
            ]);

            // Convert simple color name to CSS class if needed
            if (isset($validated['color_class']) && !str_starts_with($validated['color_class'], 'bg-')) {
                $validated['color_class'] = 'bg-' . $validated['color_class'] . '-100';
            }

            // Ensure is_final is boolean
            $validated['is_final'] = $validated['is_final'] ?? false;

            // Default show_in_mobile to true if not specified
            $validated['show_in_mobile'] = $validated['show_in_mobile'] ?? true;

            // Hub defaults for a NEW status: counts in quantities, not out-the-door,
            // normal journey lane, and customer updates OFF until deliberately enabled.
            $validated['counts_in_quantities'] = $validated['counts_in_quantities'] ?? true;
            $validated['auto_prepares'] = $validated['auto_prepares'] ?? false;
            $validated['lane'] = $validated['lane'] ?? 'journey';
            $validated['send_to_customer_app'] = $validated['send_to_customer_app'] ?? false;
            $validated['customer_app_hold_until_dispatch'] = $validated['customer_app_hold_until_dispatch'] ?? false;

            // Convert empty array to null for visible_to_roles (null = all roles)
            if (isset($validated['visible_to_roles']) && empty($validated['visible_to_roles'])) {
                $validated['visible_to_roles'] = null;
            }

            $validated = $this->dropColumnsNotYetMigrated($validated);

            $result = $this->statusService->createStatus($validated);

            return response()->json($result, $result['success'] ? 201 : 400);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status_code' => 'sometimes|required|string|max:50|regex:/^[a-z_]+$/',
                'status_name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'sequence_order' => 'nullable|integer|min:1',
                'is_active' => 'nullable|boolean',
                'is_final' => 'nullable|boolean',
                'color_class' => 'nullable|string|max:50',
                'icon' => 'nullable|string|max:20',  // Emojis are typically 1-4 chars
                'show_in_mobile' => 'nullable|boolean',
                'visible_to_roles' => 'nullable|array',
                'visible_to_roles.*' => 'integer',
                // Order Status Hub behaviour columns
                'counts_in_quantities' => 'nullable|boolean',
                'auto_prepares' => 'nullable|boolean',
                'lane' => 'nullable|in:journey,offtrack,legacy',
                'send_to_customer_app' => 'nullable|boolean',
                'customer_app_alias' => 'nullable|string|max:50',
                'customer_app_hold_until_dispatch' => 'nullable|boolean'
            ]);

            // Convert simple color name to CSS class if needed
            if (isset($validated['color_class']) && !str_starts_with($validated['color_class'], 'bg-')) {
                $validated['color_class'] = 'bg-' . $validated['color_class'] . '-100';
            }

            // Convert empty array to null for visible_to_roles (null = all roles)
            if (isset($validated['visible_to_roles']) && empty($validated['visible_to_roles'])) {
                $validated['visible_to_roles'] = null;
            }

            $validated = $this->dropColumnsNotYetMigrated($validated);

            $result = $this->statusService->updateStatus($id, $validated);

            return response()->json($result, $result['success'] ? 200 : 400);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete status (deactivate)
     */
    public function destroy(int $id): JsonResponse
    {
        $result = $this->statusService->deleteStatus($id);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Change order status
     */
    public function changeOrderStatus(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|integer|exists:t_crm_prod_order,id',
            'status_code' => 'required|string|exists:t_crm_order_status_master,status_code',
            'notes' => 'nullable|string|max:1000',
            'confirmed' => 'nullable|boolean', // For ledger reversal confirmation
            'bring_back' => 'nullable|boolean' // Operator chose to return prepared items to the queue
        ]);

        // Capture the status the order is in BEFORE the change, for the bring-back check below.
        $bringBackFromStatus = OrderModel::where('id', $request->order_id)->value('order_status');

        // ================================================================
        // LEDGER REVERSAL DETECTION FOR CANCELLATION
        // ================================================================
        // Check if changing to 'cancelled' and order has ledger entry
        if ($request->status_code === 'cancelled') {
            $order = OrderModel::find($request->order_id);
            
            if ($order && $order->ledger_transaction_id) {
                $ledger = \App\Models\FIN\LedgerModel::find($order->ledger_transaction_id);
                
                if ($ledger && $ledger->approval_status !== \App\Models\FIN\LedgerModel::STATUS_REVERSED) {
                    // Check if ledger is settled
                    if ($ledger->settlement_status === 'settled') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot cancel order: Invoice has already been settled. Please reverse the settlement first.',
                            'error_type' => 'already_settled'
                        ], 422);
                    }
                    
                    // Check for partial settlement
                    if ($ledger->settlement_status === 'partial' || ($ledger->settled_amount > 0)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Cannot cancel order: Invoice has partial settlement. Please reverse the settlement first.',
                            'error_type' => 'partial_settlement'
                        ], 422);
                    }
                    
                    // Require confirmation if not already confirmed
                    if (!$request->has('confirmed') || !$request->confirmed) {
                        // Get rider name for display
                        $riderName = 'Unknown';
                        if ($ledger->mode === \App\Models\FIN\LedgerModel::MODE_CASH && $order->assigned_rider_user_id) {
                            $rider = \App\Models\User::find($order->assigned_rider_user_id);
                            $riderName = $rider ? ($rider->fullname ?? $rider->name) : 'Unknown';
                        } elseif ($ledger->mode === \App\Models\FIN\LedgerModel::MODE_ONLINE) {
                            $riderName = 'Online Bank Account';
                        }
                        
                        return response()->json([
                            'success' => false,
                            'requires_confirmation' => true,
                            'confirmation_data' => [
                                'message' => 'Order cancellation will reverse ledger entry',
                                'order_number' => $order->order_number,
                                'amount' => $order->total_price,
                                'ledger_mode' => $ledger->mode,
                                'account_name' => $riderName,
                                'ledger_id' => $ledger->id
                            ]
                        ], 200);
                    }
                }
            }
        }

        // Proceed with status change (ledger reversal will happen in OrderModel::changeStatus)
        $result = $this->statusService->changeOrderStatus(
            $request->order_id,
            $request->status_code,
            $request->notes
        );

        // BRING-BACK: the operator moved an order back across the "out the door" line and chose to
        // return its prepared items to the "to prepare" queue. Guarded server-side so a stray flag
        // can only ever act on a genuine backward move (was out-the-door, now isn't).
        if ($result['success'] && $request->boolean('bring_back') && $bringBackFromStatus) {
            $rules = app(\App\Services\CRM\OrderStatusRuleService::class);
            $wasOutTheDoor = in_array($bringBackFromStatus, $rules->outTheDoor(), true);
            $nowOutTheDoor = in_array($request->status_code, $rules->outTheDoor(), true);
            if ($wasOutTheDoor && !$nowOutTheDoor) {
                $bb = $this->statusService->unprepareItems($request->order_id);
                $result['bring_back'] = $bb;
            }
        }

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Bulk change order statuses
     */
    public function bulkChangeStatus(Request $request): JsonResponse
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:t_crm_prod_order,id',
            'status_code' => 'required|string|exists:t_crm_order_status_master,status_code',
            'notes' => 'nullable|string|max:1000'
        ]);

        $result = $this->statusService->bulkChangeStatus(
            $request->order_ids,
            $request->status_code,
            $request->notes
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get order status history
     */
    public function getOrderHistory(int $orderId): JsonResponse
    {
        try {
            $history = $this->statusService->getOrderStatusHistory($orderId);
            return response()->json([
                'success' => true,
                'data' => $history
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status history timestamp (Admin only)
     * After updating, reconciles is_current flags and main order table
     */
    public function updateHistoryTimestamp(Request $request, int $historyId): JsonResponse
    {
        try {
            // Validate request
            $request->validate([
                'changed_at' => 'required|date'
            ]);

            // Find the history record
            $history = \DB::table('t_crm_order_status_history')->where('id', $historyId)->first();
            
            if (!$history) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status history record not found'
                ], 404);
            }

            $orderId = $history->order_id;
            $oldTimestamp = $history->changed_at;
            $newTimestamp = $request->changed_at;

            // Update the timestamp using direct DB query (table doesn't have updated_at column)
            \DB::table('t_crm_order_status_history')
                ->where('id', $historyId)
                ->update(['changed_at' => $newTimestamp]);

            \Log::info('Status history timestamp updated', [
                'history_id' => $historyId,
                'order_id' => $orderId,
                'old_timestamp' => $oldTimestamp,
                'new_timestamp' => $newTimestamp,
                'updated_by' => auth()->id()
            ]);

            // Reconcile current status - this will:
            // 1. Find the latest status by changed_at DESC, id DESC
            // 2. Set is_current = 1 for latest, 0 for all others
            // 3. Update main order table with the latest status
            OrderModel::reconcileCurrentStatus($orderId);

            \Log::info('Status reconciliation completed after timestamp edit', [
                'order_id' => $orderId,
                'history_id' => $historyId
            ]);

            // Get updated history to return
            $updatedHistory = $this->statusService->getOrderStatusHistory($orderId);

            return response()->json([
                'success' => true,
                'message' => 'Status timestamp updated successfully. Current status has been reconciled.',
                'data' => $updatedHistory
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update status history timestamp', [
                'history_id' => $historyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update timestamp: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available transitions for a status
     */
    public function getAvailableTransitions(int $statusId): JsonResponse
    {
        try {
            $transitions = $this->statusService->getAvailableTransitions($statusId);
            return response()->json([
                'success' => true,
                'data' => $transitions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transitions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update status transitions
     */
    public function updateTransitions(Request $request, int $statusId): JsonResponse
    {
        $request->validate([
            'allowed_transitions' => 'required|array',
            'allowed_transitions.*' => 'integer|exists:t_crm_order_status_master,id'
        ]);

        $result = $this->statusService->updateTransitions(
            $statusId,
            $request->allowed_transitions
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Reorder statuses
     */
    public function reorderStatuses(Request $request): JsonResponse
    {
        $request->validate([
            'status_order' => 'required|array|min:1',
            'status_order.*' => 'integer|exists:t_crm_order_status_master,id'
        ]);

        $result = $this->statusService->reorderStatuses($request->status_order);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Get orders by status for management
     */
    public function getOrdersByStatus(Request $request): JsonResponse
    {
        try {
            $statusCode = $request->get('status_code');
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 20);

            $query = OrderModel::with(['customer', 'currentStatusHistory.status']);

            if ($statusCode) {
                $query->where('order_status', $statusCode);
            }

            $orders = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => $orders
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display status history index page
     */
    public function historyIndex(): View
    {
        return view('pages.order-status.history');
    }

    /**
     * Display specific order status history page
     */
    public function orderHistory(int $orderId): View
    {
        $order = OrderModel::with(['customer', 'statusHistory.status', 'statusHistory.changedBy'])->find($orderId);
        
        if (!$order) {
            abort(404, 'Order not found');
        }

        return view('pages.order-status.order-history', compact('order'));
    }

    /**
     * Get all available roles for status visibility settings
     */
    public function getAvailableRoles(): JsonResponse
    {
        try {
            $roles = \App\Models\SysAdmin\RoleModel::where('is_active', 1)
                ->orderBy('urole_name')
                ->get(['id', 'urole_name']);
            
            return response()->json([
                'success' => true,
                'roles' => $roles
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch roles: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get order status timeline (last 5 changes) - API endpoint
     */
    public function getOrderTimeline($orderId): JsonResponse
    {
        try {
            // Keep this connection-agnostic (no hardcoded DB names) and avoid joining unknown user tables
            $timeline = \DB::table('t_crm_order_status_history as h')
                ->leftJoin('t_crm_order_status_master as sm', 'sm.status_code', '=', 'h.status_code')
                ->where('h.order_id', $orderId)
                ->orderBy('h.changed_at', 'desc')
                ->limit(5)
                ->get([
                    'h.status_code',
                    \DB::raw("COALESCE(sm.status_name, REPLACE(REPLACE(h.status_code, '_', ' '), '-', ' ')) AS status_name"),
                    \DB::raw("COALESCE(sm.color_class, 'gray') AS color_class"),
                    \DB::raw("COALESCE(sm.icon, '⏳') AS icon"),
                    'h.changed_at',
                    'h.notes',
                    'h.is_current',
                    // If a user table naming differs, avoid joining; still return something meaningful
                    \DB::raw("CASE WHEN h.changed_by IS NULL THEN 'System' ELSE CONCAT('User #', h.changed_by) END AS changed_by_name"),
                ]);

            return response()->json([
                'success' => true,
                'data' => $timeline,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch timeline: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Drop any Hub column whose migration has not been run on this environment yet.
     *
     * Production is deployed by hand, so a file upload can land before its SQL does.
     * Without this, saving ANY status on such a box would fail with "unknown column"
     * — the save form would simply stop working until the SQL was remembered.
     * Stripping the key makes the upload order irrelevant: the toggle is quietly
     * inert until the column exists, then starts saving.
     */
    private function dropColumnsNotYetMigrated(array $data): array
    {
        foreach (['customer_app_hold_until_dispatch'] as $column) {
            if (array_key_exists($column, $data)
                && !\Schema::hasColumn('t_crm_order_status_master', $column)) {
                unset($data[$column]);
            }
        }

        return $data;
    }
}
