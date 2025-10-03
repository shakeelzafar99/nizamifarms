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
        $tab = $request->get('tab', 'all'); // 'all', 'approvals', or 'open'
        $status = $request->get('status', ''); // Status filter
        $date = $request->get('date', ''); // Date filter

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
            $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems', 'assignedRider'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                });
            
            // Role-based filtering: riders see only their assigned orders
            $userRole = $this->getUserRole($request);
            if ($userRole === 'rider') {
                $query->where('assigned_rider_user_id', auth()->id());
            }
            
            // If viewing open orders or riders tab, filter to exclude completed statuses
            if ($tab === 'open' || $tab === 'riders') {
                $query->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
            }
        }
        
        // Apply status filter if provided
        if (!empty($status)) {
            $query->where('order_status', $status);
        }
        
        // Apply date filter if provided
        if (!empty($date)) {
            $query->whereDate('order_date', $date);
        }
        
        // Handle per_page parameter
        $perPage = $request->get('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25; // Validate per_page values
        
        $orders = $query->orderBy('order_date', 'desc')->paginate($perPage);
        
        // Append all parameters to pagination links so they're preserved
        $appendParams = ['source' => $source, 'per_page' => $perPage, 'tab' => $tab];
        if (!empty($status)) $appendParams['status'] = $status;
        if (!empty($date)) $appendParams['date'] = $date;
        $orders->appends($appendParams);
        
        // Counts for badges
        if ($source === 'shopify') {
            // For Shopify page: count all orders and approvals separately
            $shopifyCount = \App\Models\CRM\ShopifyOrderModel::count(); // All Shopify orders
            $approvalsCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count(); // Only unconverted
            $otherCount = 0; // Not relevant for Shopify page
            $openCount = 0; // Not relevant for Shopify page
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
            $openCount = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])->count();
        }

        return view('pages.orders.index', compact('orders', 'source', 'tab', 'shopifyCount', 'approvalsCount', 'otherCount', 'openCount'));
    }

    public function show($id)
    {
        try {
            $order = $this->findOrder($id);
            
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
            $order = $this->findOrder($id);
            
            return view('pages.orders.invoice', compact('order'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Order not found');
        }
    }

    public function invoicePdf($id)
    {
        try {
            $order = $this->findOrder($id);
            
            // Generate filename for download (allow custom filename from request)
            $filename = request('filename', 'Invoice-' . ($order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT)));
            
            // Check if user wants direct image download
            if (request()->has('download_image')) {
                return $this->generateInvoiceImage($order, $filename);
            }
            
            // Check if user wants auto PDF download
            if (request()->has('auto_pdf')) {
                // Always generate actual server PDF for auto_pdf requests
                return $this->generateServerPDF($order, $filename);
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
        // Increase execution time for image generation
        set_time_limit(120);
        
        // Create HTML content for image generation using the exact web invoice (PDF-friendly tweaks)
        $html = view('pages.orders.invoice', ['order' => $order, 'isPdf' => true])->render();
        
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
        $wkhtmltoimage = env('WKHTMLTOIMAGE_BIN', 'wkhtmltoimage');
        $chromeBin = env('CHROME_BIN', 'google-chrome');
        $wkhtmltoimage = escapeshellarg($wkhtmltoimage);
        $chromeBin = escapeshellarg($chromeBin);

        $methods = [
            // Method 1: wkhtmltoimage
            "$wkhtmltoimage --width 1024 --quality 95 --format png --disable-smart-width --enable-local-file-access \"{$htmlPath}\" \"{$imagePath}\"",
            // Method 2: Chrome headless (if available)
            "$chromeBin --headless --disable-gpu --window-size=800,1200 --screenshot=\"{$imagePath}\" \"{$htmlPath}\"",
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
            // Increase execution time for PDF generation
            set_time_limit(120);
            
            \Log::info('Starting PDF generation for order: ' . $order->id . ' with filename: ' . $filename);

            // Prefer wkhtmltopdf first for pixel-perfect rendering
            try {
                return $this->tryWkhtmltopdf($order, $filename);
            } catch (\Exception $wkhtmlError) {
                \Log::info('wkhtmltopdf failed, falling back to dompdf: ' . $wkhtmlError->getMessage());

                // Fallback: use dompdf with print-optimized template
                try {
                    $pdf = \PDF::loadView('pages.orders.invoice', ['order' => $order, 'filename' => $filename, 'isPdf' => true])
                        ->setOptions([
                            'isHtml5ParserEnabled' => true,
                            'isRemoteEnabled' => true,
                            'defaultFont' => 'DejaVu Sans',
                            'isUnicode' => true,
                            'isFontSubsettingEnabled' => true
                        ])
                        ->setPaper('A4', 'portrait');

                    $pdfOutput = $pdf->output();
                    \Log::info('Dompdf PDF generated successfully, size: ' . strlen($pdfOutput) . ' bytes');
                    
                    return response($pdfOutput, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'attachment; filename="' . $filename . '.pdf"',
                        'Content-Length' => strlen($pdfOutput),
                        'Cache-Control' => 'no-cache, no-store, must-revalidate',
                        'Pragma' => 'no-cache',
                        'Expires' => '0'
                    ]);
                } catch (\Exception $dompdfError) {
                    \Log::info('Dompdf failed as well: ' . $dompdfError->getMessage());
                    // Final fallback handled below
                }
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
            // Use the exact same web invoice view for pixel-perfect output
            $html = view('pages.orders.invoice', ['order' => $order, 'isPdf' => true])->render();
            
            $tempDir = storage_path('app/temp');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            $htmlPath = $tempDir . '/' . $filename . '.html';
            $pdfPath = $tempDir . '/' . $filename . '.pdf';
            
            file_put_contents($htmlPath, $html, LOCK_EX);
            
            // Try wkhtmltopdf command (binary can be overridden via .env)
            $wkhtmltopdf = env('WKHTMLTOPDF_BIN', 'wkhtmltopdf');
            $wkhtmltopdf = escapeshellarg($wkhtmltopdf);
            $command = "$wkhtmltopdf --page-size A4 --margin-top 0.5in --margin-bottom 0.5in --margin-left 0.5in --margin-right 0.5in --dpi 300 --zoom 1.0 --disable-smart-shrinking --enable-local-file-access --print-media-type --background --encoding UTF-8 \"{$htmlPath}\" \"{$pdfPath}\"";
            exec($command . ' 2>&1', $output, $returnCode);
            \Log::info('wkhtmltopdf command executed with return code: ' . $returnCode . ', output: ' . implode("\n", $output));
            
            if ($returnCode === 0 && file_exists($pdfPath)) {
                $fileSize = filesize($pdfPath);
                \Log::info('wkhtmltopdf PDF generated successfully, size: ' . $fileSize . ' bytes');
                
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
            $order = $this->findOrder($id, []);
            
            // Validate request
            $validated = $request->validate([
                'customer_id' => 'nullable|exists:t_crm_prod_customer,id',
                // Ensure we only accept valid status codes from master table
                'order_status' => 'required|string|exists:t_crm_order_status_master,status_code',
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
                // Allow null to auto-default to 'new' server-side
                'order_status' => 'nullable|string|exists:t_crm_order_status_master,status_code',
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
            
            // Default order status to 'new' if not provided
            if (empty($validated['order_status'])) {
                $validated['order_status'] = 'new';
            }

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
            // Find the original Shopify order in the new Shopify table
            $originalOrder = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems'])
                ->findOrFail($id);
            
            // Check if already converted or ignored
            if ($originalOrder->converted) {
                $status = $originalOrder->converted == 1 ? 'converted' : 'ignored';
                return response()->json([
                    'success' => false,
                    'message' => "Order has already been {$status}"
                ], 400);
            }
            
            // Validate SKUs and recalculate prices
            $validationResult = $this->validateAndRecalculateOrder($originalOrder);
            if (!$validationResult['success']) {
                return response()->json($validationResult, 400);
            }
            
            // Prepare order data for conversion
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
            
            // Use recalculated line items and totals
            $orderData['line_items'] = $validationResult['recalculated_line_items'];
            $orderData['subtotal_price'] = $validationResult['new_subtotal'];
            $orderData['total_price'] = $validationResult['new_total'];

            // Ensure new converted webapp invoices start in 'new' status (non-Shopify orders only)
            // This preserves existing functionality while initializing the status system correctly.
            $orderData['order_status'] = $orderData['order_status'] ?? 'new';
            
            // Use existing storeOrderFromApi method to create the converted order
            $convertedOrder = \App\Models\CRM\OrderModel::storeOrderFromApi($orderData);

            // Force final status to 'new' AFTER creation so any mapped legacy status from Shopify
            // cannot overwrite it inside store logic. This preserves the request to always start
            // converted invoices in 'new' while keeping all other conversion behavior intact.
            try {
                if (method_exists($convertedOrder, 'changeStatus')) {
                    $convertedOrder->changeStatus('new', 'Converted from Shopify approval');
                } else {
                    $convertedOrder->order_status = 'new';
                    $convertedOrder->save();
                }
            } catch (\Throwable $e) {
                \Log::warning('Unable to set converted order status to new', [
                    'order_id' => $convertedOrder->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Mark original order as converted
            $originalOrder->update(['converted' => 1]);
            
            // Prepare response message with any warnings
            $message = 'Order converted successfully with recalculated prices based on your product rates';
            if (!empty($validationResult['warnings'])) {
                $message .= '. Warnings: ' . implode(', ', $validationResult['warnings']);
            }
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'original_order_id' => $originalOrder->id,
                'converted_order_id' => $convertedOrder->id,
                'converted_order' => $convertedOrder->load(['customer', 'lineItems']),
                'price_changes' => $validationResult['price_changes'],
                'warnings' => $validationResult['warnings'] ?? []
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to convert order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Validate SKUs and recalculate order totals based on local product prices
     */
    private function validateAndRecalculateOrder($shopifyOrder)
    {
        $validationErrors = [];
        $warnings = [];
        $priceChanges = [];
        $recalculatedLineItems = [];
        $newSubtotal = 0;
        
        // Validate coupon if present
        if ($shopifyOrder->coupon_code) {
            $coupon = \App\Models\CRM\CouponModel::where('code', $shopifyOrder->coupon_code)
                ->where('is_active', true)
                ->first();
            
            if (!$coupon) {
                $warnings[] = "Coupon '{$shopifyOrder->coupon_code}' not found in your system - please add it manually";
            }
        }
        
        // Process each line item
        foreach ($shopifyOrder->lineItems as $lineItem) {
            if (!$lineItem->sku) {
                $validationErrors[] = "Line item '{$lineItem->name}' has no SKU";
                continue;
            }
            
            // Find product variant by SKU
            $productVariants = \App\Models\CRM\ProductVariantModel::where('sku', $lineItem->sku)->get();
            
            if ($productVariants->isEmpty()) {
                $validationErrors[] = "SKU '{$lineItem->sku}' not found in your products";
                continue;
            }
            
            if ($productVariants->count() > 1) {
                $validationErrors[] = "SKU '{$lineItem->sku}' found in multiple products - please ensure unique SKUs";
                continue;
            }
            
            $productVariant = $productVariants->first();
            $originalPrice = (float) $lineItem->unit_price;
            $newPrice = (float) $productVariant->price;
            $quantity = (int) $lineItem->quantity;
            
            // Calculate new line total
            $newLineTotal = $quantity * $newPrice;
            $originalLineTotal = $quantity * $originalPrice;
            
            // Track price changes
            if ($originalPrice != $newPrice) {
                $priceChanges[] = [
                    'sku' => $lineItem->sku,
                    'name' => $lineItem->name,
                    'original_price' => $originalPrice,
                    'new_price' => $newPrice,
                    'quantity' => $quantity,
                    'original_total' => $originalLineTotal,
                    'new_total' => $newLineTotal
                ];
            }
            
            // Prepare recalculated line item
            $lineItemData = $lineItem->toArray();
            unset($lineItemData['id']);
            unset($lineItemData['order_id']);
            unset($lineItemData['created_at']);
            unset($lineItemData['updated_at']);
            
            // Update with new prices
            $lineItemData['unit_price'] = $newPrice;
            $lineItemData['line_total'] = $newLineTotal;
            $lineItemData['line_subtotal'] = $newLineTotal; // Assuming no line-level discounts
            
            $recalculatedLineItems[] = $lineItemData;
            $newSubtotal += $newLineTotal;
        }
        
        // If there are validation errors, stop conversion
        if (!empty($validationErrors)) {
            return [
                'success' => false,
                'message' => 'Cannot convert order due to the following issues: ' . implode(', ', $validationErrors),
                'errors' => $validationErrors
            ];
        }
        
        // Calculate new total (preserve shipping, tax, and discount structure)
        $shippingTotal = (float) $shopifyOrder->shipping_total;
        $taxTotal = (float) $shopifyOrder->total_tax;
        $discountTotal = (float) $shopifyOrder->discount_total;
        
        $newTotal = $newSubtotal + $shippingTotal + $taxTotal - $discountTotal;
        
        return [
            'success' => true,
            'recalculated_line_items' => $recalculatedLineItems,
            'new_subtotal' => $newSubtotal,
            'new_total' => $newTotal,
            'price_changes' => $priceChanges,
            'warnings' => $warnings
        ];
    }

    public function ignoreOrder($id)
    {
        try {
            // Find the original Shopify order in the new Shopify table
            $originalOrder = \App\Models\CRM\ShopifyOrderModel::findOrFail($id);
            
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

    /**
     * Helper method to find order from either Shopify or main orders table
     */
    private function findOrder($id, $withRelations = ['customer', 'lineItems', 'assignedRider'])
    {
        // First try to find in Shopify orders table
        $order = \App\Models\CRM\ShopifyOrderModel::with($withRelations)->find($id);
        
        // If not found in Shopify table, try the main orders table
        if (!$order) {
            $order = \App\Models\CRM\OrderModel::with($withRelations)->findOrFail($id);
        }
        
        return $order;
    }

    public function filter(Request $request)
    {
        try {
            $source = $request->get('source', 'other');
            $tab = $request->get('tab', 'all');
            $search = $request->get('search', '');
            $status = $request->get('status', '');
            $date = $request->get('date', '');
            
            // Start with base query based on source
            if ($source === 'shopify') {
                // Use Shopify orders table
                $query = \App\Models\CRM\ShopifyOrderModel::with(['customer', 'lineItems']);
                
                // Apply tab filter for Shopify orders
                if ($tab === 'approvals') {
                    $query->where(function($q){
                        $q->whereNull('converted')->orWhere('converted', 0);
                    });
                }
            } else {
                // Use main orders table (non-Shopify)
                $query = \App\Models\CRM\OrderModel::with(['customer', 'lineItems', 'assignedRider'])
                    ->where(function($q) {
                        $q->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                    });
                
                // Apply tab filter for open orders and riders
                if ($tab === 'open' || $tab === 'riders') {
                    $query->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
                }
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

            // Provide counts for Shopify tabs so the frontend can render badges correctly
            $shopifyAllCount = null;
            $shopifyApprovalsCount = null;
            $otherCountAll = null;
            // Always provide counts for badges across tabs
            $shopifyAllCount = \App\Models\CRM\ShopifyOrderModel::count();
            $shopifyApprovalsCount = \App\Models\CRM\ShopifyOrderModel::where(function($q){
                $q->whereNull('converted')->orWhere('converted', 0);
            })->count();
            $otherCountAll = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->count();
            $openCountAll = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])->count();
            
            return response()->json([
                'success' => true,
                'orders' => $orders->toArray(),
                'total' => $orders->count(),
                'shopify_all_count' => $shopifyAllCount,
                'shopify_approvals_count' => $shopifyApprovalsCount,
                'other_count' => $otherCountAll,
                'open_count' => $openCountAll,
                'tab' => $tab,
                'source' => $source,
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

    /**
     * Get open orders status counts for status cards
     */
    public function getOpenOrdersStatusCounts(Request $request)
    {
        try {
            // Get all active statuses excluding completed ones
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // Build counts by normalizing order_status to canonical codes first
            $statusCounts = \DB::table('t_crm_prod_order as o')
                ->select([
                    \DB::raw("CASE 
                        WHEN o.order_status IN ('on-hold','on hold') THEN 'on_hold'
                        WHEN o.order_status = 'completed' THEN 'delivered'
                        WHEN o.order_status IN ('out-for-delivery','out for delivery') THEN 'out_for_delivery'
                        WHEN o.order_status IN ('pending','pending payment','pending-payment') THEN 'pending'
                        ELSE o.order_status END AS normalized_code"),
                    \DB::raw('COUNT(o.id) as count')
                ])
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->groupBy('normalized_code');

            // Join to master to fetch display data and filter out excluded statuses via canonical codes
            $statusCounts = \DB::query()
                ->fromSub($statusCounts, 'c')
                ->join('t_crm_order_status_master as sm', 'sm.status_code', '=', 'c.normalized_code')
                ->where('sm.is_active', 1)
                ->whereNotIn('sm.status_code', $excludedStatuses)
                ->orderBy('sm.sequence_order')
                ->get([
                    'sm.status_code',
                    'sm.status_name',
                    'sm.icon',
                    'sm.color_class',
                    'c.count'
                ]);

            // Calculate total open orders count
            $totalOpenCount = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', $excludedStatuses)->count();

            // Delivered today (from history), non-shopify orders only
            $deliveredTodayCount = \DB::table('t_crm_order_status_history as h')
                ->join('t_crm_prod_order as o', 'o.id', '=', 'h.order_id')
                ->whereDate('h.changed_at', now()->toDateString())
                ->where('h.status_code', 'delivered')
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->count();

            return response()->json([
                'success' => true,
                'status_counts' => $statusCounts,
                'total_open_count' => $totalOpenCount,
                'delivered_today' => $deliveredTodayCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Open orders status counts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch status counts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rider-wise breakdown of open orders with status counts
     */
    public function getRiderOrdersCounts(Request $request)
    {
        try {
            // Excluded statuses (completed orders)
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // Get open orders grouped by rider with status breakdown
            $riderCounts = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->select([
                    'o.assigned_rider_user_id as rider_id',
                    'u.fullname as rider_name',
                    \DB::raw("CASE 
                        WHEN o.order_status IN ('on-hold','on hold') THEN 'on_hold'
                        WHEN o.order_status = 'completed' THEN 'delivered'
                        WHEN o.order_status IN ('out-for-delivery','out for delivery') THEN 'out_for_delivery'
                        WHEN o.order_status IN ('pending','pending payment','pending-payment') THEN 'pending'
                        ELSE o.order_status END AS normalized_status"),
                    \DB::raw('COUNT(o.id) as count')
                ])
                ->where(function($q){
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->groupBy('o.assigned_rider_user_id', 'u.fullname', 'normalized_status')
                ->orderBy('u.fullname')
                ->get();

            // Organize data by rider
            $ridersData = [];
            $unassignedCount = 0;
            $unassignedBreakdown = [];

            foreach ($riderCounts as $record) {
                if ($record->rider_id) {
                    // Assigned rider
                    if (!isset($ridersData[$record->rider_id])) {
                        $ridersData[$record->rider_id] = [
                            'rider_id' => $record->rider_id,
                            'rider_name' => $record->rider_name,
                            'total_count' => 0,
                            'status_breakdown' => []
                        ];
                    }
                    $ridersData[$record->rider_id]['total_count'] += $record->count;
                    $ridersData[$record->rider_id]['status_breakdown'][$record->normalized_status] = $record->count;
                } else {
                    // Unassigned orders
                    $unassignedCount += $record->count;
                    $unassignedBreakdown[$record->normalized_status] = $record->count;
                }
            }

            // Convert to array for JSON response
            $ridersArray = array_values($ridersData);

            // Total open orders count
            $totalOpenCount = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })->whereNotIn('order_status', $excludedStatuses)->count();

            // Assigned orders count
            $assignedCount = \App\Models\CRM\OrderModel::where(function($q) {
                $q->where('external_source', '!=', 'shopify')
                  ->orWhereNull('external_source');
            })
            ->whereNotNull('assigned_rider_user_id')
            ->whereNotIn('order_status', $excludedStatuses)
            ->count();

            return response()->json([
                'success' => true,
                'riders' => $ridersArray,
                'unassigned_count' => $unassignedCount,
                'unassigned_breakdown' => $unassignedBreakdown,
                'total_open_count' => $totalOpenCount,
                'assigned_count' => $assignedCount,
                'riders_count' => count($ridersArray)
            ]);
        } catch (\Exception $e) {
            Log::error('Rider orders counts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rider counts: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getUserRole(Request $request)
    {
        $user = $request->user();
        if (!$user) return null;

        return \DB::table('t_sys_user_role as ur')
            ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
            ->where('ur.user_id', $user->id)
            ->value('r.type');
    }

    /**
     * Open Order Quantities - Main Page
     * Shows hierarchical breakdown of quantities in open orders
     */
    public function openQuantities(Request $request)
    {
        // Get attribute labels from JSON file
        $labels = $this->getAttributeLabels();
        
        // Get available categories for filters
        $categories = \DB::table('t_crm_prod_product')
            ->select('product_type')
            ->whereNotNull('product_type')
            ->where('product_type', '!=', '')
            ->distinct()
            ->orderBy('product_type')
            ->pluck('product_type');

        return view('pages.orders.open-quantities', compact('labels', 'categories'));
    }

    /**
     * Open Order Quantities - Data API
     * Returns hierarchical quantity data based on drill-down level
     */
    public function openQuantitiesData(Request $request)
    {
        try {
            // Decode JSON parameters
            $hierarchy = json_decode($request->get('hierarchy', '["product_type", "product_name"]'), true);
            if (!is_array($hierarchy)) {
                $hierarchy = ['product_type', 'product_name'];
            }
            
            $level = (int) $request->get('level', 0); // Current drill-down level
            
            $filters = json_decode($request->get('filters', '{}'), true);
            if (!is_array($filters)) {
                $filters = [];
            }
            
            $dateRange = $request->get('date_range', 0); // Days to look back (0 = all time)

            // Excluded order statuses (from user preferences or default to closed statuses)
            $excludedStatuses = json_decode($request->get('excluded_statuses', '["delivered", "completed", "cancelled", "refunded"]'), true);
            if (!is_array($excludedStatuses)) {
                $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            }
            
            Log::debug('Open Quantities Excluded Statuses:', ['excluded' => $excludedStatuses]);
            Log::debug('Open Quantities Join Strategy:', [
                'note' => 'Trying multiple join paths for line_item -> product',
                'paths' => [
                    '1' => 'li.variant_id -> pv.shopify_variant_id -> pv.product_id -> p.id',
                    '2' => 'li.product_id -> pv.shopify_variant_id -> pv.product_id -> p.id',
                    '3' => 'li.product_id -> p.id (direct)',
                    '4' => 'li.name -> p.title (name match fallback)'
                ]
            ]);

            // Build base query for open orders with line items
            // Multiple join paths to match products:
            // Path 1: li.variant_id -> pv.shopify_variant_id -> p.id
            // Path 2: li.product_id -> pv.shopify_variant_id -> p.id  
            // Path 3: li.product_id -> p.id (direct)
            // Path 4: li.name -> p.title (name match)
            $query = \DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function($join) {
                    // Try multiple variant matching strategies
                    $join->where(function($q) {
                        $q->whereColumn('li.variant_id', 'pv.shopify_variant_id')  // Path 1: variant_id field
                          ->orWhereColumn('li.variant_id', 'pv.id')
                          ->orWhereColumn('li.product_id', 'pv.shopify_variant_id') // Path 2: product_id as variant
                          ->orWhereColumn('li.product_id', 'pv.id');
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function($join) {
                    $join->where(function($q) {
                        $q->whereColumn('pv.product_id', 'p.id')  // Via variant table
                          ->orWhereColumn('li.product_id', 'p.id'); // Direct match
                    })->orWhereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))'); // Name fallback
                })
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses);

            // Apply date filter if specified
            if ($dateRange > 0) {
                $query->where('o.order_date', '>=', Carbon::now()->subDays($dateRange));
            }

            // Apply parent filters from breadcrumb navigation
            foreach ($filters as $field => $value) {
                if ($field === 'product_name') {
                    $query->where('li.name', $value);
                } elseif ($field === 'product_type') {
                    $query->where(function($q) use ($value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.product_type')
                              ->orWhere('p.product_type', '');
                        } else {
                            $q->where('p.product_type', $value);
                        }
                    });
                } elseif (in_array($field, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                    $query->where(function($q) use ($field, $value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.' . $field)
                              ->orWhere('p.' . $field, '');
                        } else {
                            $q->where('p.' . $field, $value);
                        }
                    });
                } else {
                    $query->where('p.' . $field, $value);
                }
            }

            // Determine grouping field based on current level in hierarchy
            $currentField = $hierarchy[$level] ?? 'product_name';
            
            // Build select and group by based on current field
            if ($currentField === 'orders') {
                // Final level: show individual orders
                $query->select([
                    'o.order_number as group_name',
                    'o.id as order_id',
                    'o.order_status',
                    'o.order_date',
                    \DB::raw('SUM(li.quantity) as total_quantity'),
                    \DB::raw('COUNT(DISTINCT li.id) as line_item_count')
                ])
                ->groupBy('o.id', 'o.order_number', 'o.order_status', 'o.order_date')
                ->orderBy('o.order_date', 'desc');
            } elseif ($currentField === 'product_name') {
                $query->select([
                    'li.name as group_name',
                    'li.product_id',
                    \DB::raw('SUM(li.quantity) as total_quantity'),
                    \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    \DB::raw('COUNT(DISTINCT li.id) as line_item_count')
                ])
                ->groupBy('li.name', 'li.product_id');
            } else {
                // Use COALESCE to handle null fields by showing as 'Uncategorized'
                $query->select([
                    \DB::raw("COALESCE(p.{$currentField}, 'Uncategorized') as group_name"),
                    \DB::raw('SUM(li.quantity) as total_quantity'),
                    \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    \DB::raw('COUNT(DISTINCT CASE WHEN li.product_id IS NOT NULL THEN li.product_id END) as product_count'),
                    \DB::raw('COUNT(DISTINCT li.id) as line_item_count')
                ])
                ->groupBy(\DB::raw("COALESCE(p.{$currentField}, 'Uncategorized')"));
            }

            // Execute query with debug logging
            $sql = $query->toSql();
            Log::debug('Open Quantities SQL:', [
                'sql' => $sql, 
                'bindings' => $query->getBindings(),
                'current_level' => $level,
                'current_field' => $currentField
            ]);
            
            $results = $query
                ->orderByDesc('total_quantity')
                ->get();
            
            // Get sample line item data to understand the join
            $sampleLineItems = \DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->select('li.product_id', 'li.variant_id', 'li.name', 'o.order_number')
                ->whereIn('o.order_number', ['15890', '15888', '15872'])
                ->limit(5)
                ->get();
            
            Log::debug('Open Quantities Results:', [
                'count' => $results->count(),
                'sample_results' => $results->take(5)->toArray(),
                'sample_line_items' => $sampleLineItems->toArray(),
                'all_group_names' => $results->pluck('group_name')->unique()->toArray(),
                'note' => 'Multiple join paths attempted'
            ]);

            // Calculate totals for summary
            $totalQuantity = $results->sum('total_quantity');
            $totalOrders = \DB::table('t_crm_prod_order')
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->whereNotIn('order_status', $excludedStatuses)
                ->when($dateRange > 0, function($q) use ($dateRange) {
                    $q->where('order_date', '>=', Carbon::now()->subDays($dateRange));
                })
                ->count();

            // Add percentage to each result
            $results = $results->map(function($item) use ($totalQuantity) {
                $item->percentage = $totalQuantity > 0 ? round(($item->total_quantity / $totalQuantity) * 100, 1) : 0;
                return $item;
            });

            // Check if we can drill down further
            $hasNextLevel = isset($hierarchy[$level + 1]);

            return response()->json([
                'success' => true,
                'data' => $results,
                'summary' => [
                    'total_quantity' => $totalQuantity,
                    'total_orders' => $totalOrders,
                    'category_count' => $results->count(),
                    'current_level' => $level,
                    'current_field' => $currentField,
                    'has_next_level' => $hasNextLevel
                ],
                'hierarchy' => $hierarchy
            ]);

        } catch (\Exception $e) {
            Log::error('Open quantities data error: ' . $e->getMessage(), [
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'request_params' => [
                    'hierarchy' => $request->get('hierarchy'),
                    'level' => $request->get('level'),
                    'filters' => $request->get('filters'),
                    'date_range' => $request->get('date_range')
                ]
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch quantity data: ' . $e->getMessage(),
                'error_details' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }

    /**
     * Helper: Read attribute labels from JSON file
     */
    private function getAttributeLabels(): array
    {
        $path = storage_path('app/private/attribute_labels.json');
        $defaults = [
            '1' => 'Category Level 1',
            '2' => 'Category Level 2',
            '3' => 'Category Level 3'
        ];
        
        if (!file_exists($path)) {
            return $defaults;
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true) ?: [];
        
        // Normalize to ensure string keys
        $normalized = [];
        foreach ([1, 2, 3] as $key) {
            $stringKey = (string)$key;
            $normalized[$stringKey] = $data[$stringKey] ?? $data[$key] ?? $defaults[$stringKey];
        }
        
        return $normalized;
    }
}
