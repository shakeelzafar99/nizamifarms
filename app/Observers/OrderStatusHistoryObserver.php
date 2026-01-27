<?php

namespace App\Observers;

use App\Models\CRM\OrderStatusHistory;
use App\Models\CRM\OrderModel;
use App\Models\CRM\CustomerModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Observer for OrderStatusHistory model
 * Updates customer delivery dates when orders are marked as delivered
 * This replaces a database trigger for shared hosting compatibility
 */
class OrderStatusHistoryObserver
{
    /**
     * Handle the OrderStatusHistory "created" event.
     * When a status history entry is created, check if it's a delivery
     * and update the customer's delivery date tracking.
     */
    public function created(OrderStatusHistory $statusHistory): void
    {
        // Only process 'delivered' status
        if ($statusHistory->status_code !== 'delivered') {
            return;
        }

        try {
            // Get the order to find customer_id
            $order = OrderModel::find($statusHistory->order_id);
            
            if (!$order || !$order->customer_id) {
                return;
            }

            // Don't update for reversed or cancelled orders
            if (in_array($order->order_status, ['reversed', 'cancelled'])) {
                return;
            }

            $customerId = $order->customer_id;
            $deliveryDate = $statusHistory->changed_at;

            // Update customer delivery dates efficiently with a single query
            DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    // Set first_delivery_date only if NULL (first ever delivery)
                    'first_delivery_date' => DB::raw(
                        "COALESCE(first_delivery_date, '{$deliveryDate}')"
                    ),
                    // Update last_delivery_date if this is more recent
                    'last_delivery_date' => DB::raw(
                        "CASE WHEN last_delivery_date IS NULL OR '{$deliveryDate}' > last_delivery_date 
                         THEN '{$deliveryDate}' 
                         ELSE last_delivery_date END"
                    ),
                    // Update last_delivered_order_id if this is the most recent
                    'last_delivered_order_id' => DB::raw(
                        "CASE WHEN last_delivery_date IS NULL OR '{$deliveryDate}' > last_delivery_date 
                         THEN {$statusHistory->order_id} 
                         ELSE last_delivered_order_id END"
                    ),
                ]);

            Log::debug("Customer {$customerId} delivery dates updated for order {$order->id}");

        } catch (\Exception $e) {
            // Log error but don't fail the delivery - this is non-critical
            Log::error("Failed to update customer delivery dates: " . $e->getMessage(), [
                'order_id' => $statusHistory->order_id,
                'status_code' => $statusHistory->status_code,
                'error' => $e->getMessage()
            ]);
        }
    }
}
