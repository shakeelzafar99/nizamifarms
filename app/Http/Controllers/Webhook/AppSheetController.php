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
            
            // Generate new order number for webapp orders
            $latestOrder = OrderModel::where('external_source', 'webapp')
                ->orderBy('id', 'desc')
                ->first();
            
            $nextNumber = $latestOrder ? (intval(substr($latestOrder->order_number, 3)) + 1) : 1;
            $orderData['order_number'] = 'NF-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            
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
}
