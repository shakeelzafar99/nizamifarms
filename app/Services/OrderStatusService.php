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
    /**
     * @param array $options Per-call answers the caller collected from the user.
     *        'credit_stranded_payment' => true moves money already received on a
     *        cancelled order to the customer's account balance instead of
     *        leaving it unowned. Absent/false keeps the historical behaviour.
     */
    public function changeOrderStatus(int $orderId, string $statusCode, ?string $notes = null, ?int $userId = null, array $options = []): array
    {
        try {
            $order = OrderModel::find($orderId);
            if (!$order) {
                return ['success' => false, 'message' => 'Order not found'];
            }

            // 🚚 An order ON THE VAN is only moved by the van doors (handover
            // scan / no-scan override / take-off-the-van) — owner ruling Aug-4.
            $vanBlock = \App\Services\Riders\VanService::manualChangeBlock($order, $statusCode);
            if ($vanBlock !== null) {
                return ['success' => false, 'message' => $vanBlock];
            }

            $order->creditStrandedPaymentOnCancel = !empty($options['credit_stranded_payment']);

            $success = $order->changeStatus($statusCode, $notes, $userId ?? auth()->id());

            $result = [
                'success' => $success,
                'message' => $success ? 'Status updated successfully' : 'Failed to update status',
                'order' => $success ? $order->fresh() : null
            ];

            // Surface what the cancellation did with the customer's money, so
            // the caller can tell them rather than leaving it to the log.
            if ($success && $order->strandedPaymentCredited) {
                $result['credited_to_balance'] = $order->strandedPaymentCredited;
                // 'active' = auto-approved (Shabib/Taimur), 'pending' = queued.
                $result['credited_status'] = $order->strandedPaymentCreditStatus;
            }
            if ($success && $order->creditReleasedOnCancel) {
                $result['credit_released'] = $order->creditReleasedOnCancel;
            }

            return $result;
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

            $this->reconcileQuantitiesExclusion($status);

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

            $this->reconcileQuantitiesExclusion($status);

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
     * Keep the Open-Order Quantities `excluded_statuses` setting in step with a status's
     * `counts_in_quantities` flag, then bust the rule-service cache so it applies at once.
     *
     * Surgical on purpose: it only adds/removes THIS status's code, so it never clobbers other
     * entries (e.g. ones set from the legacy gear modal). The quantities engine still reads the
     * setting, so this is what makes the Hub's "count in quantities" toggle actually take effect
     * without changing how the endpoints compute exclusion.
     */
    private function reconcileQuantitiesExclusion(OrderStatusMaster $status): void
    {
        try {
            $row = DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'excluded_statuses')
                ->first();

            $excluded = $row ? json_decode($row->setting_value, true) : [];
            if (!is_array($excluded)) {
                $excluded = [];
            }
            $excluded = array_values(array_unique($excluded));

            $code = $status->status_code;
            $shouldExclude = !$status->counts_in_quantities; // counts=false => excluded from quantities

            if ($shouldExclude && !in_array($code, $excluded, true)) {
                $excluded[] = $code;
            } elseif (!$shouldExclude && in_array($code, $excluded, true)) {
                $excluded = array_values(array_filter($excluded, fn ($c) => $c !== $code));
            }

            DB::table('t_crm_open_quantities_settings')->updateOrInsert(
                ['setting_key' => 'excluded_statuses'],
                [
                    'setting_value' => json_encode(array_values($excluded)),
                    'setting_type' => 'status_filter',
                    'updated_by_user_id' => auth()->id(),
                    'updated_at' => now(),
                ]
            );

            app(\App\Services\CRM\OrderStatusRuleService::class)->bustCache();
        } catch (\Throwable $e) {
            // Never fail the status save because of the sync; the 60s cache TTL self-heals.
            Log::warning('reconcileQuantitiesExclusion failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * "Bring back" every prepared line item on an order to the to-prepare queue: clear
     * preparation_status and restore the inventory that was deducted. Mirrors the manual
     * un-mark endpoints (OrderController / RiderController) so behaviour is identical.
     * Non-throwing — a failure here must not undo the status change that already happened.
     */
    public function unprepareItems(int $orderId): array
    {
        try {
            $order = OrderModel::with('lineItems')->find($orderId);
            if (!$order) {
                return ['success' => false, 'unprepared' => 0, 'restored' => 0];
            }

            $hasSource = \Schema::hasColumn('t_crm_prod_order_line_item', 'prepared_source');
            $unprepared = 0;
            $restored = 0;

            foreach ($order->lineItems as $lineItem) {
                if ($lineItem->preparation_status === 'preparing') {
                    $wasDeducted = $lineItem->inventory_deducted;
                    $lineItem->preparation_status = null;
                    if ($hasSource) {
                        $lineItem->prepared_source = null;
                    }
                    $lineItem->updated_by = auth()->id();
                    $lineItem->save();
                    $unprepared++;

                    if ($wasDeducted && $lineItem->restoreInventory()) {
                        $restored++;
                    }
                }
            }

            Log::info('Bring-back: items returned to prepare queue', [
                'order_id' => $orderId,
                'unprepared' => $unprepared,
                'inventory_restored' => $restored,
            ]);

            return ['success' => true, 'unprepared' => $unprepared, 'restored' => $restored];
        } catch (\Exception $e) {
            Log::error('OrderStatusService::unprepareItems failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'unprepared' => 0, 'restored' => 0];
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
