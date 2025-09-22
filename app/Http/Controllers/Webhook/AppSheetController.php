<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
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
