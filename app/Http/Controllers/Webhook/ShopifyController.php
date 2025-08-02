<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Webhook\ShopifyModel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

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
            $bodyArray = json_decode($rawJson, true);

            // Get all headers, flattening the array values to strings
            $headers = collect($request->headers->all())
                ->mapWithKeys(fn($value, $key) => [$key => implode(', ', $value)])
                ->toArray();

            // Combine headers and body into one array
            $dataToStore = [
                'headers' => $headers,
                'body' => $bodyArray,
            ];

            // Encode combined data to pretty JSON
            $jsonToStore = json_encode($dataToStore, JSON_PRETTY_PRINT);

            // Filename with timestamp
            $filename = 'shopify_requests/request_' . now()->format('Y_m_d_His') . '.json';

            // Save to storage/app/shopify_requests/
            Storage::disk('public')->put($filename, $jsonToStore);

            return response()->json(['message' => 'Request and headers saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    function store(Request $request) //ADD   
    {
        try {

            $sharedSecret = Config::get('services.shopify.webhook_secret');
            // Get the raw POST body
            $data = $request->getContent();

            // Get HMAC header from Shopify
            $hmacHeader = $request->header('x-shopify-hmac-sha256');

            // Calculate HMAC hash
            $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $sharedSecret, true));

            // Verify webhook
            if (! $hmacHeader && !hash_equals($calculatedHmac, $hmacHeader)) {
                // Log::warning('Invalid Shopify Webhook Signature');
                return response()->json(['error' => 'Unauthorized'], 401);
            } 
            $response = $this->shopifyModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
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
