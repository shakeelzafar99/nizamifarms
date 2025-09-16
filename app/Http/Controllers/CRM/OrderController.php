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
        $source = $request->get('source', 'other'); // 'other' shows non-shopify from prod_order
        $tab = $request->get('tab', 'all'); // 'all' or 'approvals'

        // Build query per source
        if ($source === 'shopify') {
            // Read from new Shopify tables
            $query = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems']);
            
            // If specifically viewing approvals tab, filter to unconverted only
            if ($tab === 'approvals') {
                $query->where(function($q){
                    $q->whereNull('converted')->orWhere('converted', 0);
                });
            }
            // Otherwise show ALL Shopify orders
        } else {
            // Non-shopify from prod orders
            $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                });
        }
        
        // Handle per_page parameter
        $perPage = $request->get('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 10; // Validate per_page values
        
        $orders = $query->orderBy('order_date', 'desc')->paginate($perPage);
        
        // Append parameters to pagination links
        $orders->appends(['source' => $source, 'per_page' => $perPage, 'tab' => $tab]);
        
        // Counts for badges
        if ($source === 'shopify') {
            // For Shopify page: count all orders and approvals separately
            $shopifyCount = \App\Models\CRM\ShopifyOrderModel::count(); // All Shopify orders
            $approvalsCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count(); // Only unconverted
            $otherCount = 0; // Not relevant for Shopify page
        } else {
            // For main Invoices page: count as before
            $shopifyCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count();
            $approvalsCount = 0; // Not relevant for main page
            $otherCount = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->count();
        }

        return view('pages.orders.index', compact('orders', 'source', 'tab', 'shopifyCount', 'approvalsCount', 'otherCount'));
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

    public function invoicePdf($id)
    {
        try {
            $order = \App\Models\CRM\OrderModel::with(['customer', 'lineItems'])
                        ->findOrFail($id);
            
            // Generate filename for download
            $filename = 'Invoice-' . ($order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT));
            
            // Check if user wants direct image download
            if (request()->has('download_image')) {
                return $this->generateInvoiceImage($order, $filename);
            }
            
            // Check if user wants auto PDF download
            if (request()->has('auto_pdf')) {
                // Check if user wants direct PDF download (bypass browser display)
                if (request()->has('direct_download')) {
                    return $this->generateServerPDF($order, $filename);
                }
                
                // Otherwise, use JavaScript-enhanced auto-download page
                return $this->createJavaScriptPDFDownload($order, $filename);
            }
            
            // Check if user wants direct server-generated PDF download
            if (request()->has('force_pdf')) {
                return $this->generateServerPDF($order, $filename);
            }
            
            // Return a clean, print-ready view that can be saved as image or PDF
            return view('pages.orders.invoice-print', compact('order', 'filename'));
            
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to generate invoice: ' . $e->getMessage());
        }
    }
    
    private function generateInvoiceImage($order, $filename)
    {
        // Create HTML content for image generation
        $html = view('pages.orders.invoice-image', compact('order'))->render();
        
        // Try to use Puppeteer or wkhtmltoimage if available
        $imagePath = $this->createInvoiceImage($html, $filename);
        
        if ($imagePath && file_exists($imagePath)) {
            return response()->download($imagePath, $filename . '.png')->deleteFileAfterSend(true);
        }
        
        // Fallback: Return HTML view with auto-download instructions
        return view('pages.orders.invoice-print', compact('order', 'filename'))
               ->with('auto_download', true);
    }
    
    private function createInvoiceImage($html, $filename)
    {
        $tempDir = storage_path('app/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $htmlPath = $tempDir . '/' . $filename . '.html';
        $imagePath = $tempDir . '/' . $filename . '.png';
        
        file_put_contents($htmlPath, $html);
        
        // Try different methods to generate image
        $methods = [
            // Method 1: wkhtmltoimage
            "wkhtmltoimage --width 800 --height 1200 --quality 100 --format png \"{$htmlPath}\" \"{$imagePath}\"",
            // Method 2: Chrome headless (if available)
            "google-chrome --headless --disable-gpu --window-size=800,1200 --screenshot=\"{$imagePath}\" \"{$htmlPath}\"",
            // Method 3: Puppeteer (if available)
            "node -e \"const puppeteer = require('puppeteer'); (async () => { const browser = await puppeteer.launch(); const page = await browser.newPage(); await page.setViewport({width: 800, height: 1200}); await page.goto('file://{$htmlPath}'); await page.screenshot({path: '{$imagePath}'}); await browser.close(); })();\"",
        ];
        
        foreach ($methods as $command) {
            exec($command . ' 2>&1', $output, $returnCode);
            if ($returnCode === 0 && file_exists($imagePath)) {
                unlink($htmlPath); // Clean up HTML file
                return $imagePath;
            }
        }
        
        // Clean up HTML file if image generation failed
        if (file_exists($htmlPath)) {
            unlink($htmlPath);
        }
        
        return null;
    }
    
    private function generateServerPDF($order, $filename)
    {
        try {
            // Method 1: Try Laravel's dompdf (most reliable)
            try {
                $pdf = \PDF::loadView('pages.orders.invoice-image', compact('order'))
                    ->setOptions([
                        'isHtml5ParserEnabled' => true,
                        'isRemoteEnabled' => true,
                        'defaultFont' => 'Times-Roman'
                    ])
                    ->setPaper('A4', 'portrait');
                
                // Force download with proper headers
                return response($pdf->output(), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
                    'Content-Length' => strlen($pdf->output()),
                    'Cache-Control' => 'no-cache, no-store, must-revalidate',
                    'Pragma' => 'no-cache',
                    'Expires' => '0'
                ]);
                
            } catch (\Exception $dompdfError) {
                \Log::info('Dompdf failed, trying wkhtmltopdf: ' . $dompdfError->getMessage());
                
                // Method 2: Try wkhtmltopdf as fallback
                return $this->tryWkhtmltopdf($order, $filename);
            }
            
        } catch (\Exception $e) {
            \Log::error('All PDF generation methods failed: ' . $e->getMessage());
            
            // Final fallback: Return a view that auto-downloads via JavaScript
            return $this->createJavaScriptPDFDownload($order, $filename);
        }
    }
    
    private function tryWkhtmltopdf($order, $filename)
    {
        try {
            // Use the clean invoice-image view for PDF generation
            $html = view('pages.orders.invoice-image', compact('order'))->render();
            
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $htmlPath = $tempDir . '/' . $filename . '.html';
            $pdfPath = $tempDir . '/' . $filename . '.pdf';
            
            file_put_contents($htmlPath, $html);
            
            // Try wkhtmltopdf command
            $command = "wkhtmltopdf --page-size A4 --margin-top 0.5in --margin-bottom 0.5in --margin-left 0.5in --margin-right 0.5in --enable-local-file-access \"{$htmlPath}\" \"{$pdfPath}\"";
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($pdfPath)) {
                // Clean up HTML file
                unlink($htmlPath);
                
                // Return PDF download
                return response()->download($pdfPath, $filename . '.pdf')->deleteFileAfterSend(true);
            }
            
            // Clean up files if generation failed
            if (file_exists($htmlPath)) {
                unlink($htmlPath);
            }
            if (file_exists($pdfPath)) {
                unlink($pdfPath);
            }
            
            throw new \Exception('wkhtmltopdf command failed');
            
        } catch (\Exception $e) {
            \Log::info('wkhtmltopdf failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    private function createJavaScriptPDFDownload($order, $filename)
    {
        // Create a special view that uses JavaScript to trigger automatic PDF download
        return view('pages.orders.invoice-auto-download', compact('order', 'filename'));
    }

    // Open edit order in a dedicated tab with full assets loaded
    public function editTab($id)
    {
        // Open the main Orders page with an instruction to auto-open the edit modal for this order.
        return redirect('/orders?edit_order_id=' . urlencode((string) $id));
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
                'total_price' => 'required|numeric',
                'coupon_code' => 'nullable|string',
                'payment_method' => 'nullable|string',
                'note' => 'nullable|string',
                'items' => 'required|array',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:0.001',
                'items.*.unit_price' => 'required|numeric|min:0',
                'items.*.line_total' => 'required|numeric|min:0',
                // Address fields
                'address_first_name' => 'nullable|string',
                'address_last_name' => 'nullable|string',
                'address_email' => 'nullable|email',
                'address_phone' => 'nullable|string',
                'address_line1' => 'nullable|string',
                'address_line2' => 'nullable|string',
                'address_city' => 'nullable|string',
                'address_province' => 'nullable|string',
                'address_postal_code' => 'nullable|string',
                'address_country' => 'nullable|string'
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
                
                // Update line items directly since this is an existing order
                // Delete existing line items
                $order->lineItems()->delete();
                
                // Create new line items
                $lineItemModels = [];
                foreach ($formattedLineItems as $lineItem) {
                    $lineItem['order_id'] = $order->id;
                    $lineItem['created_by'] = auth()->check() ? auth()->id() : null;
                    $lineItemModels[] = new \App\Models\CRM\OrderLineItemModel($lineItem);
                }
                
                $order->lineItems()->saveMany($lineItemModels);
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
                'total_price' => 'required|numeric',
                'coupon_code' => 'nullable|string',
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
            
            // Handle customer selection/population
            $customerId = $validated['customer_id'];
            if (!$customerId && $validated['customer_phone']) {
                // Don't create customer here - let storeOrderFromApi handle it to avoid double counting
                // Just populate address fields for the order
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
                'customer_id' => $customerId, // Will be null for new customers, storeOrderFromApi will handle it
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


    public function convertOrder($id)
    {
        try {
            // Find the original Shopify order
            $originalOrder = \App\Models\CRM\OrderModel::with(['customer', 'lineItems'])
                ->where('external_source', 'shopify')
                ->findOrFail($id);
            
            // Check if already converted or ignored
            if ($originalOrder->converted) {
                $status = $originalOrder->converted == 1 ? 'converted' : 'ignored';
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }
            
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
            $latestOrder = \App\Models\CRM\OrderModel::where('external_source', 'webapp')
                ->orderBy('id', 'desc')
                ->first();
            
            $nextNumber = $latestOrder ? (intval(substr($latestOrder->order_number, 3)) + 1) : 1;
            $orderData['order_number'] = 'NF-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            
            // Set current timestamp for order date
            $orderData['order_date'] = now();
            
            // Prepare line items data
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
            $convertedOrder = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);
            
            // Mark original order as converted
            $originalOrder->update(['converted' => 1]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order converted successfully',
                'original_order_id' => $originalOrder->id,
                'converted_order_id' => $convertedOrder->id,
                'converted_order' => $convertedOrder->load(['customer', 'lineItems'])
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert order: ' . $e->getMessage()
            ], 500);
        }
    }

    public function ignoreOrder($id)
    {
        try {
            // Find the original Shopify order
            $originalOrder = \App\Models\CRM\OrderModel::where('external_source', 'shopify')
                ->findOrFail($id);
            
            // Check if already converted or ignored
            if ($originalOrder->converted) {
                $status = $originalOrder->converted == 1 ? 'converted' : 'ignored';
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }
            
            // Mark order as ignored
            $originalOrder->update(['converted' => 2]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order marked as ignored - no invoice will be created',
                'order_id' => $originalOrder->id
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to ignore order: ' . $e->getMessage()
            ], 500);
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
