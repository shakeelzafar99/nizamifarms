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
     */
    public function getAllStatuses(): JsonResponse
    {
        try {
            $statuses = $this->statusService->getAllStatuses();
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
        $request->validate([
            'status_code' => 'required|string|max:50|regex:/^[a-z_]+$/',
            'status_name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'sequence_order' => 'nullable|integer|min:1',
            'is_final' => 'boolean',
            'color_class' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50'
        ]);

        $result = $this->statusService->createStatus($request->all());

        return response()->json($result, $result['success'] ? 201 : 400);
    }

    /**
     * Update status
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status_code' => 'sometimes|required|string|max:50|regex:/^[a-z_]+$/',
            'status_name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string',
            'sequence_order' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'is_final' => 'boolean',
            'color_class' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50'
        ]);

        $result = $this->statusService->updateStatus($id, $request->all());

        return response()->json($result, $result['success'] ? 200 : 400);
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
            'notes' => 'nullable|string|max:1000'
        ]);

        $result = $this->statusService->changeOrderStatus(
            $request->order_id,
            $request->status_code,
            $request->notes
        );

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
}
