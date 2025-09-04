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


    protected $shopifyModel;
    public function __construct(ShopifyModel  $shopifyModel)
    {
        $this->shopifyModel = $shopifyModel;
    }
    function list(Request $request)
    {
        try {
            $response = $this->shopifyModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function getdetail($id)
    {

        try {
            $response = $this->shopifyModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function get($id)
    {
        try {
            $response = $this->shopifyModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
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

    function store(Request $request) //ADD   
    {

        try {

            $sharedSecret = Config::get('shopify.webhook_secret'); 
            // Get the raw POST body
            $rawBody = $request->getContent();
            $this->createLog($request->all(), "json", "request", "t1");
            // Get HMAC header from Shopify
            $hmacHeader = $request->header('x-shopify-hmac-sha256');

            // Calculate HMAC hash
            $calculatedHmac = base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true));

            // Verify webhook
            if (! $hmacHeader && !hash_equals($calculatedHmac, $hmacHeader)) {
                // Log::warning('Invalid Shopify Webhook Signature');
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            $payload = json_decode($rawBody, true);
            
            // Map and store using new structure
            $orderData = \App\Models\CRM\OrderModel::mapShopifyOrder($payload);
            \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);

            return response()->json(['success' => 'completed'], 200);
        } catch (\Exception $e) { 
            $errorString =
                "Message: " . $e->getMessage() . PHP_EOL .
                "File: " . $e->getFile() . PHP_EOL .
                "Line: " . $e->getLine() . PHP_EOL .
                "Trace: " . $e->getTraceAsString();
            $this->createLog($errorString, "txt", "error", "e1");
           
            return $this->error($e->getMessage(), $e->getCode());
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
