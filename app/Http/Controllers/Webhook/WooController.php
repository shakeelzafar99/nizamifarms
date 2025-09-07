<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class WooController extends Controller
{


    protected $orderModel;
    public function __construct(OrderModel  $orderModel)
    {
        $this->orderModel = $orderModel;
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

    function test(Request $request) // TEST endpoint for debugging
    {
        try {
            $rawBody = $request->getContent();
            $payload = json_decode($rawBody, true);
            
            // Log everything for debugging
            $this->createLog([
                'headers' => $request->headers->all(),
                'body' => $payload,
                'raw_body' => $rawBody
            ], "json", "debug", "woo_test");
            
            return response()->json(['success' => 'test completed', 'received' => $payload], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    function store(Request $request) //ADD   
    {

        try {
            // Log the incoming request for debugging
            $this->createLog($request->all(), "json", "request", "woo_webhook");
            
            $sharedSecret = Config::get('woocommerce.webhook_secret');
            // Get the raw POST body
            $rawBody = $request->getContent();
            
            // Get HMAC header from WooCommerce
            $hmacHeader = $request->header('X-WC-Webhook-Signature');

            // Skip signature verification if no secret is configured (for testing)
            if ($sharedSecret) {
                // Calculate HMAC hash
                $calculatedHmac = base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true));

                // Verify webhook
                if (!$hmacHeader || !hash_equals($calculatedHmac, $hmacHeader)) {
                    Log::warning('Invalid WooCommerce Webhook Signature');
                    return response()->json(['error' => 'Unauthorized'], 401);
                }
            }
            
            $payload = json_decode($rawBody, true);
            
            // Check if payload is valid JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON payload: ' . json_last_error_msg());
            }
            
            // Log the payload for debugging
            $this->createLog($payload, "json", "payload", "woo_order");
            
            // Map and store using new structure
            $orderData = \App\Models\CRM\OrderModel::mapWooCommerceOrder($payload);
            \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);

            return response()->json(['success' => 'completed'], 200);
        } catch (\Exception $e) {
            $errorString =
                "Message: " . $e->getMessage() . PHP_EOL .
                "File: " . $e->getFile() . PHP_EOL .
                "Line: " . $e->getLine() . PHP_EOL .
                "Trace: " . $e->getTraceAsString();
            $this->createLog($errorString, "txt", "error", "e1");

            // Log the error
            Log::error('WooCommerce Webhook Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->shopifyModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
