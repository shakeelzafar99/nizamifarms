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
        
        // Handle per_page parameter
        $perPage = $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10; // Validate per_page values
        
        $orders = $query->orderBy('order_date', 'desc')->paginate($perPage);
        
        // Append parameters to pagination links
        $orders->appends(['source' => $source, 'per_page' => $perPage]);
        
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
                'order' => $order,
                'lineItems' => $order->lineItems // Explicitly include line items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
    }

    public function invoice($id)
    {
        try {
            $order = \App\Models\CRM\OrderModel::with(['customer', 'lineItems'])
                        ->findOrFail($id);
            
            return view('pages.orders.invoice', compact('order'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Order not found');
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $order = \App\Models\CRM\OrderModel::findOrFail($id);
            
            // Validate request
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:t_crm_prod_customer,id',
                'order_status' => 'required|string',
                'order_date' => 'required|date',
                'contact_email' => 'nullable|email',
                'subtotal_price' => 'required|numeric',
                'discount_total' => 'nullable|numeric',
                'shipping_total' => 'nullable|numeric',
                'total_tax' => 'nullable|numeric',
                'total_price' => 'required|numeric',
                'payment_method' => 'nullable|string',
                'note' => 'nullable|string',
                'items' => 'required|array',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.line_total' => 'required|numeric|min:0'
            ]);
            
            // Update order
            $order->update($validated);
            
            // Update line items using existing API method
            if (isset($validated['items'])) {
                // Format line items for the existing API method
                $formattedLineItems = [];
                foreach ($validated['items'] as $itemData) {
                    $quantity = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];
                    
                    $formattedLineItems[] = [
                        'name' => $itemData['name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $quantity * $unitPrice,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'line_total' => $quantity * $unitPrice,
                    ];
                }
                
                // Use existing storeOrderFromApi method to handle line items
                $orderData = array_merge($validated, [
                    'line_items' => $formattedLineItems
                ]);
                
                $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData, $order->id);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'order' => $order->load(['customer', 'lineItems'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function store(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:t_crm_prod_customer,id',
                'order_status' => 'required|string',
                'order_date' => 'required|date',
                'contact_email' => 'nullable|email',
                'subtotal_price' => 'required|numeric',
                'discount_total' => 'nullable|numeric',
                'shipping_total' => 'nullable|numeric',
                'total_tax' => 'nullable|numeric',
                'total_price' => 'required|numeric',
                'payment_method' => 'nullable|string',
                'note' => 'nullable|string',
                'items' => 'required|array',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.line_total' => 'required|numeric|min:0',
                // Customer creation fields
                'customer_phone' => 'nullable|string',
                'customer_first_name' => 'nullable|string',
                'customer_last_name' => 'nullable|string',
                'customer_company' => 'nullable|string',
                'customer_address1' => 'nullable|string',
                'customer_address2' => 'nullable|string',
                'customer_city' => 'nullable|string',
                'customer_province' => 'nullable|string',
                'customer_postal_code' => 'nullable|string',
                'customer_country' => 'nullable|string'
            ]);
            
            // Generate order number for webapp orders
            $latestOrder = \App\Models\CRM\OrderModel::where('external_source', 'webapp')
                ->orderBy('id', 'desc')
                ->first();
            
            $nextNumber = $latestOrder ? (intval(substr($latestOrder->order_number, 3)) + 1) : 1;
            $orderNumber = 'NF-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            
            // Handle customer creation/selection
            $customerId = $validated['customer_id'];
            if (!$customerId && $validated['customer_phone']) {
                // Create new customer from provided data
                $customerData = [
                    'external_source' => 'webapp',
                    'address_first_name' => $validated['customer_first_name'],
                    'address_last_name' => $validated['customer_last_name'],
                    'address_company' => $validated['customer_company'],
                    'address_email' => $validated['contact_email'],
                    'address_line1' => $validated['customer_address1'],
                    'address_line2' => $validated['customer_address2'],
                    'address_city' => $validated['customer_city'],
                    'address_province' => $validated['customer_province'],
                    'address_postal_code' => $validated['customer_postal_code'],
                    'address_country' => $validated['customer_country'] ?: 'Pakistan'
                ];
                
                // Use the customer model's method to create/update customer with KPIs
                $customer = \App\Models\CRM\CustomerModel::findOrCreateByPhone(
                    $validated['customer_phone'],
                    $customerData,
                    $validated['order_date'],
                    $validated['total_price']
                );
                
                $customerId = $customer->id;
                
                // Set address fields from customer data
                $validated['address_first_name'] = $validated['customer_first_name'];
                $validated['address_last_name'] = $validated['customer_last_name'];
                $validated['address_company'] = $validated['customer_company'];
                $validated['address_email'] = $validated['contact_email'];
                $validated['address_phone'] = $validated['customer_phone'];
                $validated['address_line1'] = $validated['customer_address1'];
                $validated['address_line2'] = $validated['customer_address2'];
                $validated['address_city'] = $validated['customer_city'];
                $validated['address_province'] = $validated['customer_province'];
                $validated['address_postal_code'] = $validated['customer_postal_code'];
                $validated['address_country'] = $validated['customer_country'] ?: 'Pakistan';
            } elseif ($customerId) {
                // Load existing customer and populate address fields
                $customer = \App\Models\CRM\CustomerModel::find($customerId);
                if ($customer) {
                    $validated['address_first_name'] = $customer->first_name;
                    $validated['address_last_name'] = $customer->last_name;
                    $validated['address_company'] = $customer->company;
                    $validated['address_email'] = $customer->email;
                    $validated['address_phone'] = $customer->phone_original;
                    $validated['address_line1'] = $customer->address1;
                    $validated['address_line2'] = $customer->address2;
                    $validated['address_city'] = $customer->city;
                    $validated['address_province'] = $customer->province;
                    $validated['address_postal_code'] = $customer->postal_code;
                    $validated['address_country'] = $customer->country;
                    
                    // Update customer KPIs for webapp orders
                    $customer->recalculateStatistics();
                }
            }
            
            // Create order
            $orderData = array_merge($validated, [
                'customer_id' => $customerId,
                'external_source' => 'webapp',
                'order_number' => $orderNumber,
                'currency' => 'PKR',
                'name' => trim(($validated['address_first_name'] ?? '') . ' ' . ($validated['address_last_name'] ?? '')), // Populate name from address
                'created_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);
            
            // Remove customer creation fields from order data
            $customerFields = ['customer_phone', 'customer_first_name', 'customer_last_name', 'customer_company', 'customer_address1', 'customer_address2', 'customer_city', 'customer_province', 'customer_postal_code', 'customer_country'];
            foreach ($customerFields as $field) {
                unset($orderData[$field]);
            }
            
            // Format line items for the existing API method
            $formattedLineItems = [];
            if (isset($validated['items'])) {
                foreach ($validated['items'] as $itemData) {
                    $quantity = $itemData['quantity'];
                    $unitPrice = $itemData['unit_price'];
                    
                    $formattedLineItems[] = [
                        'name' => $itemData['name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_subtotal' => $quantity * $unitPrice,
                        'discount_amount' => 0,
                        'tax_amount' => 0,
                        'line_total' => $quantity * $unitPrice,
                    ];
                }
            }
            
            // Add line items to order data and use existing storeOrderFromApi method
            $orderData['line_items'] = $formattedLineItems;
            
            // Use existing storeOrderFromApi method to handle both order and line items
            $order = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
            
            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'order' => $order->load(['customer', 'lineItems'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
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


    public function filter(Request $request)
    {
        try {
            $source = $request->get('source', 'other');
            $search = $request->get('search', '');
            $status = $request->get('status', '');
            $date = $request->get('date', '');
            
            // Start with base query
            $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems']);
            
            // Filter by source
            if ($source === 'shopify') {
                $query->where('external_source', 'shopify');
            } else {
                $query->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                });
            }
            
            // Apply search filter
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('order_number', 'like', '%' . $search . '%')
                      ->orWhere('name', 'like', '%' . $search . '%')
                      ->orWhereHas('customer', function($customerQuery) use ($search) {
                          $customerQuery->where('name', 'like', '%' . $search . '%')
                                       ->orWhere('phone', 'like', '%' . $search . '%')
                                       ->orWhere('email', 'like', '%' . $search . '%');
                      });
                });
            }
            
            // Apply status filter
            if (!empty($status)) {
                $query->where('order_status', $status);
            }
            
            // Apply date filter
            if (!empty($date)) {
                $query->whereDate('order_date', $date);
            }
            
            // Get results (limit to 100 for performance)
            $orders = $query->orderBy('order_date', 'desc')->limit(100)->get();
            
            return response()->json([
                'success' => true,
                'orders' => $orders->toArray(),
                'total' => $orders->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error('Order filter error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Filter failed: ' . $e->getMessage(),
                'orders' => []
            ], 500);
        }
    }
}
