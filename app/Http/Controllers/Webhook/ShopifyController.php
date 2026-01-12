<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Webhook\ShopifyModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ShopifyController extends Controller
{
    /**
     * ⚠️ SHOPIFY PRODUCT SYNC DISABLED
     * 
     * Product sync operations remain disabled to protect SKU/category data.
     * 
     * ✅ ORDER WEBHOOKS RE-ENABLED (December 2024)
     * New orders from Shopify go to approval queue (t_crm_shopify_order)
     * They must be manually approved/converted before going to main orders table.
     * This preserves all business rules for SKU validation during conversion.
     */
    private const PRODUCT_DISABLED_MESSAGE = 'Shopify product sync is disabled. Products are managed via web UI and mobile app only.';


    protected $shopifyModel;
    public function __construct(ShopifyModel  $shopifyModel)
    {
        $this->shopifyModel = $shopifyModel;
    }
    
    function list(Request $request)
    {
        Log::warning('Shopify list endpoint called but is DISABLED');
        return response()->json(['error' => self::PRODUCT_DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }

    function getdetail($id)
    {
        Log::warning('Shopify getdetail endpoint called but is DISABLED', ['id' => $id]);
        return response()->json(['error' => self::PRODUCT_DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }


    function get($id)
    {
        Log::warning('Shopify get endpoint called but is DISABLED', ['id' => $id]);
        return response()->json(['error' => self::PRODUCT_DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }



    public function store_bk(Request $request)
    {
        try {
            // Get raw request body as JSON string
            $rawJson = $request->getContent();

            // Decode JSON to array            

            // Encode combined data to pretty JSON
            $jsonToStore = json_encode($rawJson, JSON_PRETTY_PRINT);

            // Filename with timestamp
            $filename = 'shopify_requests/request_' . now()->format('Y_m_d_His') . '.json';

            // Save to storage/app/shopify_requests/
            Storage::disk('public')->put($filename, $jsonToStore);

            return response()->json(['message' => 'Request and headers saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    function createLog($data, $fileType, $directory, $filename)
    {
        if ($fileType === "json") { // Encode combined data to pretty JSON
            $data = json_encode($data, JSON_PRETTY_PRINT);
        }
        // Filename with timestamp
        $filename = 'shopify/' . $directory . '/' . $filename . '-' . now()->format('Y_m_d_His') . '.' . $fileType;
        // Save to storage/app/shopify_requests/
        Storage::disk('public')->put($filename, $data);
    }

    /**
     * ✅ SHOPIFY ORDER WEBHOOK - RE-ENABLED
     * 
     * Receives new orders from Shopify and stores them in the approval queue.
     * Orders go to t_crm_shopify_order table with converted=0 (pending approval).
     * 
     * During conversion (approval), the system:
     * - Validates all SKUs exist in local products
     * - Recalculates prices using local product prices
     * - Creates the order in main table (t_crm_prod_order)
     * 
     * ⚠️ This does NOT sync products - products are managed manually via web/mobile.
     */
    function store(Request $request)
    {
        try {
            // Get raw payload
            $rawContent = $request->getContent();
            $shopifyOrder = json_decode($rawContent, true);
            
            if (!$shopifyOrder || !isset($shopifyOrder['id'])) {
                Log::warning('Shopify webhook: Invalid payload received', [
                    'ip' => $request->ip(),
                    'content_length' => strlen($rawContent)
                ]);
                return response()->json(['error' => 'Invalid payload'], 400);
            }
            
            // Log the incoming order
            Log::info('Shopify order webhook received', [
                'shopify_order_id' => $shopifyOrder['id'],
                'order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? 'unknown',
                'total' => $shopifyOrder['total_price'] ?? 0,
                'ip' => $request->ip()
            ]);
            
            // Save raw payload to file for debugging if needed
            $this->createLog($shopifyOrder, "json", "orders", "order_" . ($shopifyOrder['order_number'] ?? $shopifyOrder['id']));
            
            // Map Shopify order to our format
            $orderData = \App\Models\CRM\OrderModel::mapShopifyOrder($shopifyOrder);
            
            // Store in approval queue (ShopifyOrderModel → t_crm_shopify_order)
            // storeOrderFromApi routes shopify orders to ShopifyOrderModel automatically
            $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
            
            Log::info('Shopify order stored in approval queue', [
                'local_id' => $order->id,
                'shopify_order_id' => $shopifyOrder['id'],
                'order_number' => $order->order_number,
                'table' => 't_crm_shopify_order',
                'converted' => 0
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order received and queued for approval',
                'local_id' => $order->id,
                'status' => 'pending_approval'
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Shopify webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);
            
            // Still return 200 to prevent Shopify from retrying
            // Log the error for manual investigation
            return response()->json([
                'success' => false,
                'message' => 'Order logged but processing failed - will be investigated',
                'error' => $e->getMessage()
            ], 200);
        }
    }

    function remove(Request $request) //DELETE
    {
        Log::warning('Shopify remove endpoint called but is DISABLED', ['id' => $request->id]);
        return response()->json(['error' => self::PRODUCT_DISABLED_MESSAGE, 'status' => 'disabled'], 403);
    }
}
