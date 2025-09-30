<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderStatusMaster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AppSheetController extends Controller
{
    /**
     * Handle AppSheet webhook for order conversion
     * This endpoint receives notifications when AppSheet sets converted = 3
     * and triggers the same conversion process as the webapp convert button
     */
    public function handleOrderConversion(Request $request)
    {
        try {
            // Log the incoming webhook for debugging
            $this->createLog($request->all(), 'json', 'request', 'appsheet_webhook');
            
            // Get the raw request data
            $payload = $request->all();
            
            // Validate required fields - AppSheet sends "id" not "order_id"
            if (!isset($payload['id']) || !isset($payload['converted'])) {
                Log::warning('AppSheet webhook missing required fields', [
                    'payload' => $payload
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: id and converted'
                ], 400);
            }
            
            $orderId = $payload['id'];
            $convertedValue = $payload['converted'];
            
            // Log the webhook details
            Log::info('AppSheet webhook received', [
                'order_id' => $orderId,
                'converted_value' => $convertedValue,
                'payload' => $payload
            ]);
            
            // Check if this is a conversion trigger (converted = 3)
            if ($convertedValue != 3) {
                Log::info('AppSheet webhook ignored - not a conversion trigger', [
                    'order_id' => $orderId,
                    'converted_value' => $convertedValue
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook received but not a conversion trigger'
                ]);
            }
            
            // Find the order with line items (same as webapp convert button)
            $order = OrderModel::with(['customer', 'lineItems'])->find($orderId);
            
            if (!$order) {
                Log::error('AppSheet webhook - order not found', [
                    'order_id' => $orderId
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }
            
            // Validate this is a Shopify order that can be converted
            if ($order->external_source !== 'shopify') {
                Log::warning('AppSheet webhook - order is not from Shopify', [
                    'order_id' => $orderId,
                    'external_source' => $order->external_source
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Order is not from Shopify and cannot be converted'
                ], 400);
            }
            
            // Check if already converted or ignored
            if ($order->converted && $order->converted != 3) {
                $status = $order->converted == 1 ? 'converted' : 'ignored';
                
                Log::info('AppSheet webhook - order already processed', [
                    'order_id' => $orderId,
                    'current_converted_value' => $order->converted,
                    'status' => $status
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }
            
            // Use the existing convert order logic
            $conversionResult = $this->performOrderConversion($order);
            
            if ($conversionResult['success']) {
                Log::info('AppSheet webhook conversion successful', [
                    'order_id' => $orderId,
                    'converted_order_id' => $conversionResult['converted_order_id'],
                    'converted_order_number' => $conversionResult['converted_order']->order_number ?? 'N/A'
                ]);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Order converted successfully via AppSheet webhook',
                    'converted_order_id' => $conversionResult['converted_order_id'],
                    'converted_order_number' => $conversionResult['converted_order']->order_number ?? null
                ]);
            } else {
                Log::error('AppSheet webhook conversion failed', [
                    'order_id' => $orderId,
                    'error' => $conversionResult['message']
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Conversion failed: ' . $conversionResult['message']
                ], 500);
            }
            
        } catch (\Exception $e) {
            // Log the error
            Log::error('AppSheet webhook error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);
            
            // Create error log file
            $this->createLog([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ], 'json', 'error', 'appsheet_error');
            
            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing AppSheet webhook'
            ], 500);
        }
    }
    
    /**
     * Perform the actual order conversion using existing logic
     * This replicates the exact same logic as the webapp convert button
     */
    private function performOrderConversion($originalOrder)
    {
        try {
            // Prepare order data for conversion (exact replica but with webapp source)
            $orderData = $originalOrder->toArray();
            
            // Remove fields that should not be duplicated
            unset($orderData['id']);
            unset($orderData['created_at']);
            unset($orderData['updated_at']);
            
            // Change source to webapp and clear external IDs
            $orderData['external_source'] = 'webapp';
            $orderData['external_id'] = null;
            $orderData['external_customer_id'] = null;
            
            // Use same order number as Shopify order with SH- prefix for easy identification
            $orderData['order_number'] = 'SH-' . $originalOrder->order_number;
            
            // Set current timestamp for order date
            $orderData['order_date'] = now();
            
            // Prepare line items data (CRITICAL: same as webapp convert button)
            $lineItems = [];
            foreach ($originalOrder->lineItems as $item) {
                $lineItemData = $item->toArray();
                unset($lineItemData['id']);
                unset($lineItemData['order_id']);
                unset($lineItemData['created_at']);
                unset($lineItemData['updated_at']);
                $lineItems[] = $lineItemData;
            }
            $orderData['line_items'] = $lineItems;
            
            // Use existing storeOrderFromApi method to create the converted order
            $convertedOrder = OrderModel::storeOrderFromApi($orderData);
            
            // Mark original order as converted (this sets converted = 1)
            $originalOrder->update(['converted' => 1]);
            
            return [
                'success' => true,
                'message' => 'Order converted successfully',
                'original_order_id' => $originalOrder->id,
                'converted_order_id' => $convertedOrder->id,
                'converted_order' => $convertedOrder->load(['customer', 'lineItems'])
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create log files for debugging (same pattern as other webhook controllers)
     */
    private function createLog($data, $fileType, $directory, $filename)
    {
        if ($fileType === 'json') {
            $data = json_encode($data, JSON_PRETTY_PRINT);
        }
        
        // Filename with timestamp
        $filename = 'appsheet/' . $directory . '/' . $filename . '-' . now()->format('Y_m_d_His') . '.' . $fileType;
        
        // Save to storage/app/public/appsheet/
        Storage::disk('public')->put($filename, $data);
    }
    
    /**
     * Test endpoint for debugging AppSheet webhook
     */
    public function test(Request $request)
    {
        try {
            $payload = $request->all();
            
            // Log everything for debugging
            $this->createLog([
                'headers' => $request->headers->all(),
                'body' => $payload,
                'method' => $request->method(),
                'url' => $request->fullUrl()
            ], 'json', 'debug', 'appsheet_test');
            
            Log::info('AppSheet webhook test endpoint called', [
                'payload' => $payload,
                'method' => $request->method()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'AppSheet webhook test completed',
                'received_data' => $payload,
                'timestamp' => now()->toISOString()
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook: Update order status from AppSheet
     * Expected JSON body:
     * {
     *   "order_id": 2590              // or "order_number": "NF-14553"
     *   "status" or "status_code": "processing",  // must exist in master (aliases mapped)
     *   "date": "2025-09-27 11:20:46",           // optional; sets history changed_at
     *   "notes": "optional reason",
     *   "changed_by": 1               // optional user id
     * }
     */
    public function statusUpdate(Request $request)
    {
        try {
            $payload = $request->all();

            // Log the incoming request for debugging
            Log::info('AppSheet status-update webhook received', [
                'payload' => $payload,
                'headers' => $request->headers->all()
            ]);

            // Basic validation
            $rawStatus = $payload['status'] ?? $payload['Status'] ?? $payload['status_code'] ?? '';
            $statusCode = strtolower(trim((string) $rawStatus));
            if ($statusCode === '') {
                Log::error('AppSheet status-update: missing status', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'status_code is required'
                ], 422);
            }

            // Accept either order_id or order_number
            $orderId = $payload['order_id'] ?? null;
            $orderNumber = $payload['order_number']
                ?? ($payload['Order Number'] ?? null)
                ?? ($payload['order number'] ?? null)
                ?? ($payload['Order No'] ?? null)
                ?? ($payload['order no'] ?? null)
                ?? ($payload['order_no'] ?? null)
                ?? ($payload['orderNo'] ?? null);

            Log::info('AppSheet status-update: extracted identifiers', [
                'order_id' => $orderId,
                'raw_order_number' => $orderNumber,
                'raw_status' => $rawStatus
            ]);

            // Normalize order number: trim, remove commas, remove NF- prefix if present
            if ($orderNumber !== null) {
                $orderNumber = trim((string) $orderNumber);
                // Remove commas (thousands separators)
                $orderNumber = str_replace(',', '', $orderNumber);
                if (stripos($orderNumber, 'NF-') === 0) {
                    $orderNumber = substr($orderNumber, 3);
                }
            }
            if (!$orderId && !$orderNumber) {
                Log::error('AppSheet status-update: no order identifier', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'Provide order_id or order_number'
                ], 422);
            }

            Log::info('AppSheet status-update: normalized identifiers', [
                'order_id' => $orderId,
                'normalized_order_number' => $orderNumber
            ]);

            // Normalize common legacy codes
            $aliases = [
                'pending' => 'new',
                'on-hold' => 'on_hold',
                'on hold' => 'on_hold',
                'completed' => 'delivered',
                'out for delivery' => 'out_for_delivery',
                'delivered' => 'delivered',
                'processing' => 'processing',
            ];
            $normalizedCode = $aliases[$statusCode] ?? str_replace([' ', '-'], ['_', '_'], $statusCode);

            Log::info('AppSheet status-update: status normalization', [
                'raw_status' => $statusCode,
                'normalized_status' => $normalizedCode
            ]);

            // Ensure status exists in master
            $statusMaster = OrderStatusMaster::getByCode($normalizedCode);
            if (!$statusMaster) {
                // Log all available statuses for debugging
                $availableStatuses = OrderStatusMaster::where('is_active', 1)->pluck('status_code')->toArray();
                Log::error('AppSheet status-update: invalid status code', [
                    'raw_status' => $statusCode,
                    'normalized_status' => $normalizedCode,
                    'available_statuses' => $availableStatuses
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Invalid status_code '{$statusCode}' (normalized: '{$normalizedCode}'). Available statuses: " . implode(', ', $availableStatuses)
                ], 422);
            }

            // Find order
            $order = null;
            if ($orderId) {
                $order = OrderModel::find($orderId);
                Log::info('AppSheet status-update: order lookup by ID', [
                    'order_id' => $orderId,
                    'found' => $order ? true : false
                ]);
            } else if ($orderNumber) {
                $order = OrderModel::where('order_number', $orderNumber)->first();
                Log::info('AppSheet status-update: order lookup by number', [
                    'order_number' => $orderNumber,
                    'found' => $order ? true : false
                ]);
                
                // If not found, try with different variations
                if (!$order) {
                    Log::info('AppSheet status-update: trying alternative order number lookups');
                    // Try with NF- prefix
                    $order = OrderModel::where('order_number', 'NF-' . $orderNumber)->first();
                    if ($order) {
                        Log::info('AppSheet status-update: found with NF- prefix');
                    } else {
                        // Try as integer comparison
                        $order = OrderModel::whereRaw('CAST(order_number AS UNSIGNED) = ?', [(int)$orderNumber])->first();
                        if ($order) {
                            Log::info('AppSheet status-update: found with integer cast');
                        }
                    }
                }
            }

            if (!$order) {
                Log::error('AppSheet status-update: order not found', [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            Log::info('AppSheet status-update: order found', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'external_source' => $order->external_source,
                'current_status' => $order->order_status
            ]);

            // Only non-Shopify orders should be updated here
            if (strtolower((string)$order->external_source) === 'shopify') {
                Log::warning('AppSheet status-update: attempted to update Shopify order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint updates non-Shopify orders only.'
                ], 400);
            }

            $notes = $payload['notes'] ?? null;
            $changedBy = $payload['changed_by'] ?? null;

            Log::info('AppSheet status-update: attempting status change', [
                'order_id' => $order->id,
                'from_status' => $order->order_status,
                'to_status' => $normalizedCode,
                'notes' => $notes,
                'changed_by' => $changedBy
            ]);

            $ok = $order->changeStatus($normalizedCode, $notes, $changedBy);
            if (!$ok) {
                Log::error('AppSheet status-update: changeStatus failed', [
                    'order_id' => $order->id,
                    'status_code' => $normalizedCode,
                    'current_order_status' => $order->fresh()->order_status
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to change status'
                ], 500);
            }

            // Verify the status was actually changed
            $updatedOrder = $order->fresh();
            Log::info('AppSheet status-update: status change result', [
                'order_id' => $order->id,
                'old_status' => $order->order_status,
                'new_status' => $updatedOrder->order_status,
                'change_successful' => $updatedOrder->order_status === $normalizedCode
            ]);

            // Optional explicit date for history (changed_at)
            $providedDate = $payload['date'] ?? $payload['Date'] ?? $payload['changed_at'] ?? null;
            if ($providedDate) {
                try {
                    $dt = new \DateTime($providedDate);
                    \DB::table('t_crm_order_status_history')
                        ->where('order_id', $order->id)
                        ->where('is_current', 1)
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update([
                            'changed_at' => $dt->format('Y-m-d H:i:s'),
                            'created_at' => $dt->format('Y-m-d H:i:s'),
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('AppSheet status-update: invalid or unparsable date, default kept', [
                        'order_id' => $order->id,
                        'date' => $providedDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Final reconciliation safeguard: ensure the 'current' flag and main order table
            // reflect the most recent history by changed_at (handles any out-of-order timestamps)
            try {
                \App\Models\CRM\OrderModel::reconcileCurrentStatus($order->id);
            } catch (\Throwable $e) {
                Log::warning('AppSheet status-update: reconcile step failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('AppSheet status-update applied', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $normalizedCode,
                'notes' => $notes,
                'changed_by' => $changedBy,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status updated',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status_code' => $normalizedCode,
            ]);

        } catch (\Exception $e) {
            Log::error('AppSheet status-update error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing status-update'
            ], 500);
        }
    }

    /**
     * Handle generic flag update coming from AppSheet for Shopify orders.
     * Expects: { "order_id": <number>, "flag": <number> }
     * Behavior: Adds 1000 to order_id to form the Shopify order_number, then
     * - if flag == 3 -> set converted = 1 (approved/converted)
     * - otherwise   -> set converted = 2 (ignored)
     * This does NOT create a webapp order; it only updates the Shopify record.
     */
    public function handleFlagUpdate(Request $request)
    {
        try {
            // Log raw payload
            $this->createLog($request->all(), 'json', 'request', 'appsheet_flag_update');

            $payload = $request->all();

            // Accept multiple input shapes from AppSheet
            // - Preferred: Customer Email holds the short order number directly
            // - Fallbacks: order_id or "Order ID" then +1000 as per prior rule
            $flag = isset($payload['flag']) ? (int) $payload['flag'] : null;
            $customerEmailAsOrderNumber = $payload['Customer Email'] ?? null;
            $orderIdRaw = $payload['order_id'] ?? ($payload['Order ID'] ?? null);

            if ($flag === null || ($customerEmailAsOrderNumber === null && $orderIdRaw === null)) {
                \Log::warning('AppSheet flag update missing required fields', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: provide either Customer Email (order number) OR order_id / Order ID, and flag'
                ], 400);
            }

            // Determine the Shopify order_number to lookup
            if ($customerEmailAsOrderNumber !== null && trim((string)$customerEmailAsOrderNumber) !== '') {
                // If Customer Email is numeric, add 1000 as per requirement; otherwise use as-is
                $raw = trim((string)$customerEmailAsOrderNumber);
                if (ctype_digit($raw)) {
                    $shopifyOrderNumber = (string) (((int) $raw) + 1000);
                } else {
                    $shopifyOrderNumber = $raw;
                }
            } else {
                // Use numeric id + 1000
                $baseOrderId = (int) $orderIdRaw;
                $shopifyOrderNumber = (string) ($baseOrderId + 1000);
            }

            // Find the Shopify order by order_number
            $shopifyOrder = \App\Models\CRM\ShopifyOrderModel::where('order_number', $shopifyOrderNumber)->first();
            if (!$shopifyOrder) {
                \Log::error('AppSheet flag update - Shopify order not found', [
                    'computed_order_number' => $shopifyOrderNumber,
                    'payload' => $payload
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Shopify order not found for order_number ' . $shopifyOrderNumber
                ], 404);
            }

            // Determine converted value
            // 1 = converted/approved, 2 = ignored (kept consistent with existing ignore behavior)
            $newConverted = ($flag === 3) ? 1 : 2;

            $shopifyOrder->update(['converted' => $newConverted]);

            \Log::info('AppSheet flag update applied', [
                'shopify_order_id' => $shopifyOrder->id,
                'order_number' => $shopifyOrder->order_number,
                'new_converted' => $newConverted,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Flag update applied to Shopify order',
                'order_id' => $shopifyOrder->id,
                'order_number' => $shopifyOrder->order_number,
                'converted' => $newConverted,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('AppSheet flag update error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            $this->createLog([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ], 'json', 'error', 'appsheet_flag_update_error');

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing AppSheet flag update'
            ], 500);
        }
    }
}
