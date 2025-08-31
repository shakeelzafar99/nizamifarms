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

    function store(Request $request) //ADD   
    {

        try {

            $sharedSecret = Config::get('woocommerce.webhook_secret');
            // Get the raw POST body
            $rawBody = $request->getContent();
            $this->createLog($request->all(), "json", "request", "t1");
            // Get HMAC header from Shopify
            $hmacHeader = $request->header('X-WC-Webhook-Signature');

            // Calculate HMAC hash
            $calculatedHmac = base64_encode(hash_hmac('sha256', $rawBody, $sharedSecret, true));

            // Verify webhook
            if (! $hmacHeader && !hash_equals($calculatedHmac, $hmacHeader)) {
                // Log::warning('Invalid Shopify Webhook Signature');
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            $payload = json_decode($rawBody, true);
            $mappedOrder = $this->orderModel->mapWooOrder($payload);
            $this->orderModel->store($mappedOrder);
            //$this->shopifyModel->Store($payload);

            return response()->json(['success' => 'completed'], 200); //$this->success($response);
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
