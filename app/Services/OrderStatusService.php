<?php

namespace App\Services;

use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderStatusMaster;
use App\Models\CRM\OrderStatusHistory;
use App\Models\CRM\OrderStatusTransition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderStatusService
{
    /**
     * Get all active order statuses
     */
    public function getAllStatuses(): Collection
    {
        return OrderStatusMaster::getActiveStatuses();
    }

    /**
     * Get status statistics
     */
    public function getStatusStatistics(): Collection
    {
        // Use the existing database view that you created
        return DB::table('v_order_status_stats')->get();
    }

    /**
     * Change order status with validation
     */
    public function changeOrderStatus(int $orderId, string $statusCode, ?string $notes = null, ?int $userId = null): array
    {
        try {
            $order = OrderModel::find($orderId);
            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }

            $success = $order->changeStatus($statusCode, $notes, $userId ?? auth()->id());
            
            return [
                'success' => $success,
                'message' => $success ? 'Status updated successfully' : 'Failed to update status',
                'order' => $success ? $order->fresh() : null
            ];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::changeOrderStatus failed', [
                'order_id' => $orderId,
                'status_code' => $statusCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Bulk change order statuses
     */
    public function bulkChangeStatus(array $orderIds, string $statusCode, ?string $notes = null, ?int $userId = null): array
    {
        try {
            $results = OrderModel::bulkChangeStatus($orderIds, $statusCode, $notes, $userId ?? auth()->id());
            
            $successCount = count($results['success']);
            $failedCount = count($results['failed']);
            
            return [
                'success' => true,
                'message' => "Updated {$successCount} orders successfully" . ($failedCount > 0 ? ", {$failedCount} failed" : ""),
                'results' => $results,
                'summary' => [
                    'total' => count($orderIds),
                    'success' => $successCount,
                    'failed' => $failedCount
                ]
            ];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::bulkChangeStatus failed', [
                'order_ids' => $orderIds,
                'status_code' => $statusCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Bulk update failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get order status history
     */
    public function getOrderStatusHistory(int $orderId): Collection
    {
        return OrderStatusHistory::getHistoryForOrder($orderId);
    }

    /**
     * Create new status
     */
    public function createStatus(array $data): array
    {
        try {
            // Validate unique status code
            if (OrderStatusMaster::where('status_code', $data['status_code'])->exists()) {
                return ['success' => false, 'message' => 'Status code already exists'];
            }

            // Set sequence order if not provided
            if (!isset($data['sequence_order'])) {
                $data['sequence_order'] = OrderStatusMaster::getNextSequenceOrder();
            }

            $data['created_by'] = auth()->id();
            $status = OrderStatusMaster::create($data);

            return [
                'success' => true,
                'message' => 'Status created successfully',
                'status' => $status
            ];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::createStatus failed', [
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update status
     */
    public function updateStatus(int $statusId, array $data): array
    {
        try {
            $status = OrderStatusMaster::find($statusId);
            if (!$status) {
                return ['success' => false, 'message' => 'Status not found'];
            }

            // Validate unique status code if changed
            if (isset($data['status_code']) && $data['status_code'] !== $status->status_code) {
                if (OrderStatusMaster::where('status_code', $data['status_code'])->where('id', '!=', $statusId)->exists()) {
                    return ['success' => false, 'message' => 'Status code already exists'];
                }
            }

            $data['updated_by'] = auth()->id();
            $status->update($data);

            return [
                'success' => true,
                'message' => 'Status updated successfully',
                'status' => $status->fresh()
            ];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::updateStatus failed', [
                'status_id' => $statusId,
                'data' => $data,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Delete status (deactivate)
     */
    public function deleteStatus(int $statusId): array
    {
        try {
            $status = OrderStatusMaster::find($statusId);
            if (!$status) {
                return ['success' => false, 'message' => 'Status not found'];
            }

            // Check if status is being used by any orders
            $orderCount = OrderModel::where('order_status', $status->status_code)->count();
            if ($orderCount > 0) {
                return [
                    'success' => false, 
                    'message' => "Cannot delete status. It is currently used by {$orderCount} orders."
                ];
            }

            // Deactivate instead of delete to preserve history
            $status->update([
                'is_active' => false,
                'updated_by' => auth()->id()
            ]);

            return [
                'success' => true,
                'message' => 'Status deactivated successfully'
            ];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::deleteStatus failed', [
                'status_id' => $statusId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to delete status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Update status transitions
     */
    public function updateTransitions(int $fromStatusId, array $allowedToStatusIds): array
    {
        try {
            DB::beginTransaction();

            // Remove all existing transitions from this status
            OrderStatusTransition::where('from_status_id', $fromStatusId)->delete();

            // Add new transitions
            foreach ($allowedToStatusIds as $toStatusId) {
                OrderStatusTransition::create([
                    'from_status_id' => $fromStatusId,
                    'to_status_id' => $toStatusId,
                    'is_allowed' => true,
                    'created_by' => auth()->id()
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Transitions updated successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('OrderStatusService::updateTransitions failed', [
                'from_status_id' => $fromStatusId,
                'to_status_ids' => $allowedToStatusIds,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to update transitions: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get available transitions for a status
     */
    public function getAvailableTransitions(int $statusId): Collection
    {
        return OrderStatusTransition::getAllowedTransitions($statusId);
    }

    /**
     * Reorder statuses
     */
    public function reorderStatuses(array $statusOrder): array
    {
        try {
            DB::beginTransaction();

            foreach ($statusOrder as $index => $statusId) {
                OrderStatusMaster::where('id', $statusId)->update([
                    'sequence_order' => $index + 1,
                    'updated_by' => auth()->id()
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Status order updated successfully'
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('OrderStatusService::reorderStatuses failed', [
                'status_order' => $statusOrder,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Failed to reorder statuses: ' . $e->getMessage()
            ];
        }
    }
}
