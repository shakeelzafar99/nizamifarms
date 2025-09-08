<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\Validator;
use App\Services\ShopifyService;
use App\Services\WooCommerceService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // ✅ Correct namespace
class OrderController extends Controller
{


    protected $orderModel;
    protected ShopifyService $shopify;
    protected WooCommerceService $wooCommerce;
    public function __construct(OrderModel  $orderModel, ShopifyService $shopify, WooCommerceService $wooCommerce)
    {
        $this->orderModel = $orderModel;
        $this->shopify = $shopify;
        $this->wooCommerce = $wooCommerce;
    }

    public function index(Request $request)
    {
        $source = $request->get('source', 'other'); // default to 'other' (non-shopify)
        
        // Filter orders based on source using new table structure
        $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems']);
        
        if ($source === 'shopify') {
            $query->where('external_source', 'shopify');
        } else {
            // Show all non-shopify sources (woocommerce, manual, etc.)
            $query->where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            });
        }
        
        $orders = $query->orderBy('order_date', 'desc')->paginate(10);
        
        // Append source parameter to pagination links
        $orders->appends(['source' => $source]);
        
        // Get counts for tab badges using new structure
        $shopifyCount = \App\Models\CRM\OrderModel::where('external_source', 'shopify')->count();
        $otherCount = \App\Models\CRM\OrderModel::where(function($q) {
            $q->where('external_source', '!=', 'shopify')
              ->orWhereNull('external_source');
        })->count();

        return view('pages.orders.index', compact('orders', 'source', 'shopifyCount', 'otherCount'));
    }

    public function show($id)
    {
        try {
            $order = \App\Models\CRM\OrderModel::with(['customer', 'lineItems'])
                        ->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'order' => $order
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }




    public function importOrders(Request $request)
    {

        $validated = $request->validate([
            'source' => 'required',
            'from_date' => 'required|date',
            'to_date'   => 'required|date|after_or_equal:from_date',
        ]);
        $orderCount =  0;
        if ($validated['source']  === "Shopify") {
            $orderCount = $this->importShopify($validated);
        } else if ($validated['source']  === "WooCommerce") {
            $orderCount = $this->importWooOrders($validated);
        }
        return redirect()->back()->with('success',  $orderCount . ' ' . $validated['source'] . ' orders imported successfully.');
    }


    private function importWooOrders($validated)
    {

        $allOrders = $this->wooCommerce->fetchOrders($validated['from_date'], $validated['to_date']);
        $orderModel = new OrderModel();
        $importedCount = 0;
        foreach ($allOrders as $wooOrder) {
            try {
                // Map WooCommerce order to our format
                $orderData = \App\Models\CRM\OrderModel::mapWooCommerceOrder($wooOrder);
                
                // Store order with line items and customer management
                \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
                $importedCount++;
            } catch (\Exception $innerEx) {
                \Log::error("Failed to process WooCommerce order ID {$wooOrder['id']}: " . $innerEx->getMessage());
                // Continue to next order
                continue;
            }
        }
        return $importedCount;
    }

    private function importShopify($validated)
    {
        try {


            // ✅ Step 2: Fetch orders from Shopify Service
            $orders = $this->shopify->fetchOrders($validated['from_date'], $validated['to_date']);

            if (empty($orders)) {
                return redirect()->back()->with('warning', 'No orders found for the selected date range.');
            }

            // Store orders in new DB structure
            $importedCount = 0;
            foreach ($orders as $shopifyOrder) {
                try {
                    // Map Shopify order to our format
                    $orderData = \App\Models\CRM\OrderModel::mapShopifyOrder($shopifyOrder);
                    
                    // Store order with line items and customer management
                    \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
                    $importedCount++;
                } catch (\Exception $e) {
                    \Log::error('Failed to import Shopify order: ' . $e->getMessage(), [
                        'shopify_order_id' => $shopifyOrder['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    // Continue with next order instead of failing completely
                }
            }

            // Return success
            return $importedCount;
        } catch (\Illuminate\Validation\ValidationException $e) {
            dd(" Validation errors" . $e->errors());
            // Validation errors (handled automatically but you can customize)
            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            // Shopify API request failure
            dd("Shopify API request failure" . $e->getMessage());
            Log::error('Shopify API Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to connect to Shopify API. Please try again.');
        } catch (\Exception $e) {
            dd("Catch-all for unexpected errors" . $e->getMessage());
            // Catch-all for unexpected errors
            Log::error('Shopify Import Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return redirect()->back()->with('error', 'Something went wrong while importing orders.');
        }
    }



    function list(Request $request)
    {
        try {
            $response = $this->orderModel->List($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function getdetail($id)
    {

        try {
            $response = $this->orderModel->GetDetail($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }


    function get($id)
    {
        try {
            $response = $this->orderModel->Get($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function store(Request $request) //ADD   
    {
        try {
            $response = $this->orderModel->Store($request->all());
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

    function remove(Request $request) //DELETE
    {
        try {
            $id = $request->id;
            $response = $this->orderModel->Remove($id);
            return $this->success($response);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }
}
