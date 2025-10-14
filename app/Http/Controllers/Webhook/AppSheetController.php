<?php

namespace App\Http\Controllers\Webhook;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderStatusMaster;
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
            
            // Use same order number as Shopify order with SH- prefix for easy identification
            $orderData['order_number'] = 'SH-' . $originalOrder->order_number;
            
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

    /**
     * Webhook: Update order status from AppSheet
     * Expected JSON body:
     * {
     *   "order_id": 2590              // or "order_number": "NF-14553"
     *   "status" or "status_code": "processing",  // must exist in master (aliases mapped)
     *   "date": "2025-09-27 11:20:46",           // optional; sets history changed_at
     *   "notes": "optional reason",
     *   "changed_by": 1               // optional user id
     * }
     */
    public function statusUpdate(Request $request)
    {
        try {
            $payload = $request->all();

            // Log the incoming request for debugging
            Log::info('AppSheet status-update webhook received', [
                'payload' => $payload,
                'headers' => $request->headers->all()
            ]);

            // Basic validation
            $rawStatus = $payload['status'] ?? $payload['Status'] ?? $payload['status_code'] ?? '';
            $statusCode = strtolower(trim((string) $rawStatus));
            if ($statusCode === '') {
                Log::error('AppSheet status-update: missing status', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'status_code is required'
                ], 422);
            }

            // Accept either order_id or order_number
            $orderId = $payload['order_id'] ?? null;
            $orderNumber = $payload['order_number']
                ?? ($payload['Order Number'] ?? null)
                ?? ($payload['order number'] ?? null)
                ?? ($payload['Order No'] ?? null)
                ?? ($payload['order no'] ?? null)
                ?? ($payload['order_no'] ?? null)
                ?? ($payload['orderNo'] ?? null);

            Log::info('AppSheet status-update: extracted identifiers', [
                'order_id' => $orderId,
                'raw_order_number' => $orderNumber,
                'raw_status' => $rawStatus
            ]);

            // Normalize order number: trim, remove commas, remove NF- prefix if present
            if ($orderNumber !== null) {
                $orderNumber = trim((string) $orderNumber);
                // Remove commas (thousands separators)
                $orderNumber = str_replace(',', '', $orderNumber);
                if (stripos($orderNumber, 'NF-') === 0) {
                    $orderNumber = substr($orderNumber, 3);
                }
            }
            if (!$orderId && !$orderNumber) {
                Log::error('AppSheet status-update: no order identifier', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'Provide order_id or order_number'
                ], 422);
            }

            Log::info('AppSheet status-update: normalized identifiers', [
                'order_id' => $orderId,
                'normalized_order_number' => $orderNumber
            ]);

            // Normalize common legacy codes
            $aliases = [
                // Do not map pending to new; keep as pending
                'pending' => 'pending',
                'pending payment' => 'pending',
                'pending-payment' => 'pending',
                'on-hold' => 'on_hold',
                'on hold' => 'on_hold',
                'completed' => 'delivered',
                'out for delivery' => 'out_for_delivery',
                'delivered' => 'delivered',
                'processing' => 'processing',
            ];
            $normalizedCode = $aliases[$statusCode] ?? str_replace([' ', '-'], ['_', '_'], $statusCode);

            Log::info('AppSheet status-update: status normalization', [
                'raw_status' => $statusCode,
                'normalized_status' => $normalizedCode
            ]);

            // Ensure status exists in master
            $statusMaster = OrderStatusMaster::getByCode($normalizedCode);
            if (!$statusMaster) {
                // Log all available statuses for debugging
                $availableStatuses = OrderStatusMaster::where('is_active', 1)->pluck('status_code')->toArray();
                Log::error('AppSheet status-update: invalid status code', [
                    'raw_status' => $statusCode,
                    'normalized_status' => $normalizedCode,
                    'available_statuses' => $availableStatuses
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Invalid status_code '{$statusCode}' (normalized: '{$normalizedCode}'). Available statuses: " . implode(', ', $availableStatuses)
                ], 422);
            }

            // Find order
            $order = null;
            if ($orderId) {
                $order = OrderModel::find($orderId);
                Log::info('AppSheet status-update: order lookup by ID', [
                    'order_id' => $orderId,
                    'found' => $order ? true : false
                ]);
            } else if ($orderNumber) {
                $order = OrderModel::where('order_number', $orderNumber)->first();
                Log::info('AppSheet status-update: order lookup by number', [
                    'order_number' => $orderNumber,
                    'found' => $order ? true : false
                ]);
                
                // If not found, try with different variations
                if (!$order) {
                    Log::info('AppSheet status-update: trying alternative order number lookups');
                    // Try with NF- prefix
                    $order = OrderModel::where('order_number', 'NF-' . $orderNumber)->first();
                    if ($order) {
                        Log::info('AppSheet status-update: found with NF- prefix');
                    } else {
                        // Try as integer comparison
                        $order = OrderModel::whereRaw('CAST(order_number AS UNSIGNED) = ?', [(int)$orderNumber])->first();
                        if ($order) {
                            Log::info('AppSheet status-update: found with integer cast');
                        }
                    }
                }
            }

            if (!$order) {
                Log::error('AppSheet status-update: order not found', [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            Log::info('AppSheet status-update: order found', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'external_source' => $order->external_source,
                'current_status' => $order->order_status
            ]);

            // Only non-Shopify orders should be updated here
            if (strtolower((string)$order->external_source) === 'shopify') {
                Log::warning('AppSheet status-update: attempted to update Shopify order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint updates non-Shopify orders only.'
                ], 400);
            }

            $notes = $payload['notes'] ?? null;
            $changedBy = $payload['changed_by'] ?? null;

            Log::info('AppSheet status-update: attempting status change', [
                'order_id' => $order->id,
                'from_status' => $order->order_status,
                'to_status' => $normalizedCode,
                'notes' => $notes,
                'changed_by' => $changedBy
            ]);

            $ok = $order->changeStatus($normalizedCode, $notes, $changedBy);
            if (!$ok) {
                Log::error('AppSheet status-update: changeStatus failed', [
                    'order_id' => $order->id,
                    'status_code' => $normalizedCode,
                    'current_order_status' => $order->fresh()->order_status
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to change status'
                ], 500);
            }

            // Verify the status was actually changed
            $updatedOrder = $order->fresh();
            Log::info('AppSheet status-update: status change result', [
                'order_id' => $order->id,
                'old_status' => $order->order_status,
                'new_status' => $updatedOrder->order_status,
                'change_successful' => $updatedOrder->order_status === $normalizedCode
            ]);

            // Optional explicit date for history (changed_at)
            $providedDate = $payload['date'] ?? $payload['Date'] ?? $payload['changed_at'] ?? null;
            if ($providedDate) {
                try {
                    $dt = new \DateTime($providedDate);
                    \DB::table('t_crm_order_status_history')
                        ->where('order_id', $order->id)
                        ->where('is_current', 1)
                        ->orderByDesc('id')
                        ->limit(1)
                        ->update([
                            'changed_at' => $dt->format('Y-m-d H:i:s'),
                            'created_at' => $dt->format('Y-m-d H:i:s'),
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('AppSheet status-update: invalid or unparsable date, default kept', [
                        'order_id' => $order->id,
                        'date' => $providedDate,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Final reconciliation safeguard: ensure the 'current' flag and main order table
            // reflect the most recent history by changed_at (handles any out-of-order timestamps)
            try {
                \App\Models\CRM\OrderModel::reconcileCurrentStatus($order->id);
            } catch (\Throwable $e) {
                Log::warning('AppSheet status-update: reconcile step failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('AppSheet status-update applied', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $normalizedCode,
                'notes' => $notes,
                'changed_by' => $changedBy,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status updated',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'status_code' => $normalizedCode,
            ]);

        } catch (\Exception $e) {
            Log::error('AppSheet status-update error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing status-update'
            ], 500);
        }
    }

    /**
     * Handle generic flag update coming from AppSheet for Shopify orders.
     * Expects: { "order_id": <number>, "flag": <number> }
     * Behavior: Adds 1000 to order_id to form the Shopify order_number, then
     * - if flag == 3 -> set converted = 1 (approved/converted)
     * - otherwise   -> set converted = 2 (ignored)
     * This does NOT create a webapp order; it only updates the Shopify record.
     */
    public function handleFlagUpdate(Request $request)
    {
        try {
            // Log raw payload
            $this->createLog($request->all(), 'json', 'request', 'appsheet_flag_update');

            $payload = $request->all();

            // Accept multiple input shapes from AppSheet
            // - Preferred: Customer Email holds the short order number directly
            // - Fallbacks: order_id or "Order ID" then +1000 as per prior rule
            $flag = isset($payload['flag']) ? (int) $payload['flag'] : null;
            $customerEmailAsOrderNumber = $payload['Customer Email'] ?? null;
            $orderIdRaw = $payload['order_id'] ?? ($payload['Order ID'] ?? null);

            if ($flag === null || ($customerEmailAsOrderNumber === null && $orderIdRaw === null)) {
                \Log::warning('AppSheet flag update missing required fields', ['payload' => $payload]);
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: provide either Customer Email (order number) OR order_id / Order ID, and flag'
                ], 400);
            }

            // Determine the Shopify order_number to lookup
            if ($customerEmailAsOrderNumber !== null && trim((string)$customerEmailAsOrderNumber) !== '') {
                // If Customer Email is numeric, add 1000 as per requirement; otherwise use as-is
                $raw = trim((string)$customerEmailAsOrderNumber);
                if (ctype_digit($raw)) {
                    $shopifyOrderNumber = (string) (((int) $raw) + 1000);
                } else {
                    $shopifyOrderNumber = $raw;
                }
            } else {
                // Use numeric id + 1000
                $baseOrderId = (int) $orderIdRaw;
                $shopifyOrderNumber = (string) ($baseOrderId + 1000);
            }

            // Find the Shopify order by order_number
            $shopifyOrder = \App\Models\CRM\ShopifyOrderModel::where('order_number', $shopifyOrderNumber)->first();
            if (!$shopifyOrder) {
                \Log::error('AppSheet flag update - Shopify order not found', [
                    'computed_order_number' => $shopifyOrderNumber,
                    'payload' => $payload
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Shopify order not found for order_number ' . $shopifyOrderNumber
                ], 404);
            }

            // Determine converted value
            // 1 = converted/approved, 2 = ignored (kept consistent with existing ignore behavior)
            $newConverted = ($flag === 3) ? 1 : 2;

            $shopifyOrder->update(['converted' => $newConverted]);

            \Log::info('AppSheet flag update applied', [
                'shopify_order_id' => $shopifyOrder->id,
                'order_number' => $shopifyOrder->order_number,
                'new_converted' => $newConverted,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Flag update applied to Shopify order',
                'order_id' => $shopifyOrder->id,
                'order_number' => $shopifyOrder->order_number,
                'converted' => $newConverted,
            ], 200);

        } catch (\Exception $e) {
            \Log::error('AppSheet flag update error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            $this->createLog([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ], 'json', 'error', 'appsheet_flag_update_error');

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing AppSheet flag update'
            ], 500);
        }
    }

    /**
     * Webhook: Record attendance from AppSheet
     * Expected JSON body:
     * {
     *   "date": "2025-09-27",              // attendance_date
     *   "employee": "John Doe",             // fullname to match in t_sys_user
     *   "login_time": "09:15:00",          // optional HH:MM:SS or HH:MM
     *   "logout_time": "17:30:00",         // optional HH:MM:SS or HH:MM
     *   "login_location": "33.7, 73.0",    // optional lat,lng
     *   "logout_location": "33.7, 73.0",   // optional lat,lng
     *   "device_id": "ABC123",             // optional
     *   "meter_start": 1234,               // optional
     *   "meter_end": 5678,                 // optional
     *   "picture_start": "url",            // optional
     *   "picture_end": "url",              // optional
     *   "notes": "optional notes"          // optional
     * }
     * 
     * This endpoint mirrors the CSV import logic from OperationsController
     */
    public function attendanceUpdate(Request $request)
    {
        try {
            $payload = $request->all();

            // Log the incoming request for debugging
            Log::info('AppSheet attendance-update webhook received', [
                'payload' => $payload,
                'headers' => $request->headers->all()
            ]);

            // Extract fields with multiple fallback keys (case-insensitive)
            $date = $payload['date'] 
                ?? $payload['Date'] 
                ?? $payload['attendance_date'] 
                ?? $payload['Attendance Date'] 
                ?? null;
            
            $employee = $payload['employee'] 
                ?? $payload['Employee'] 
                ?? $payload['employee_name'] 
                ?? $payload['Employee Name'] 
                ?? null;
            
            $loginTime = $payload['login_time'] 
                ?? $payload['Login Time'] 
                ?? $payload['login time'] 
                ?? null;
            
            $logoutTime = $payload['logout_time'] 
                ?? $payload['Logout Time'] 
                ?? $payload['logout time'] 
                ?? $payload['log out time']  // AppSheet sends this format
                ?? $payload['log_out_time'] 
                ?? $payload['Log Out Time'] 
                ?? null;
            
            $loginLoc = $payload['login_location'] 
                ?? $payload['Login Location'] 
                ?? $payload['login location']  // AppSheet sends this format
                ?? $payload['login_lat_lng'] 
                ?? null;
            
            $logoutLoc = $payload['logout_location'] 
                ?? $payload['Logout Location'] 
                ?? $payload['logout location']  // AppSheet sends this format
                ?? $payload['logout_lat_lng'] 
                ?? null;
            
            $device = $payload['device_id'] 
                ?? $payload['Device ID'] 
                ?? $payload['Device Id'] 
                ?? $payload['device id']  // AppSheet sends this format
                ?? null;
            
            $meterStart = $payload['meter_start'] 
                ?? $payload['Meter Start'] 
                ?? $payload['meter start']  // AppSheet sends this format
                ?? null;
            
            $meterEnd = $payload['meter_end'] 
                ?? $payload['Meter End'] 
                ?? $payload['meter end']  // AppSheet sends this format
                ?? null;
            
            $picStart = $payload['picture_start'] 
                ?? $payload['Picture Start'] 
                ?? $payload['picture start']  // AppSheet sends this format
                ?? null;
            
            $picEnd = $payload['picture_end'] 
                ?? $payload['Picture End'] 
                ?? $payload['picture end']  // AppSheet sends this format
                ?? null;
            
            $notes = $payload['notes'] 
                ?? $payload['Notes'] 
                ?? null;

            // Validate required fields
            if (!$date || !$employee) {
                Log::error('AppSheet attendance-update: missing required fields', [
                    'date' => $date,
                    'employee' => $employee,
                    'payload' => $payload
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: date and employee'
                ], 422);
            }

            // Clean employee name (remove suffixes like "- indrive", extra spaces, etc.)
            $cleanName = $this->cleanEmployeeName($employee);

            Log::info('AppSheet attendance-update: cleaned employee name', [
                'original' => $employee,
                'cleaned' => $cleanName
            ]);

            // Try multiple matching strategies to find user
            $user = $this->findUserByName($cleanName);
            
            if (!$user) {
                Log::warning('AppSheet attendance-update: employee not found', [
                    'original_name' => $employee,
                    'cleaned_name' => $cleanName
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Employee not found in system: {$employee} (cleaned: {$cleanName})"
                ], 404);
            }

            Log::info('AppSheet attendance-update: user found', [
                'user_id' => $user->id,
                'fullname' => $user->fullname
            ]);

            // Parse lat,lng from a single cell like "33.7, 73.0"
            [$loginLat, $loginLng] = $this->splitLatLng($loginLoc);
            [$logoutLat, $logoutLng] = $this->splitLatLng($logoutLoc);

            // Parse date properly
            try {
                $attendanceDate = date('Y-m-d', strtotime($date));
            } catch (\Exception $e) {
                Log::error('AppSheet attendance-update: invalid date format', [
                    'date' => $date,
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Invalid date format: {$date}"
                ], 422);
            }

            // Normalize time formats (handle HH:MM or HH:MM:SS)
            $loginTime = $this->normalizeTime($loginTime);
            $logoutTime = $this->normalizeTime($logoutTime);

            // Check if record already exists
            $existingRecord = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->where('attendance_date', $attendanceDate)
                ->first();

            Log::info('AppSheet attendance-update: preparing to save', [
                'user_id' => $user->id,
                'attendance_date' => $attendanceDate,
                'login_time' => $loginTime,
                'logout_time' => $logoutTime,
                'existing' => $existingRecord ? true : false,
                'existing_has_login' => $existingRecord ? ($existingRecord->login_time ? true : false) : false,
                'existing_has_logout' => $existingRecord ? ($existingRecord->logout_time ? true : false) : false
            ]);

            // Build update data - only update fields that are provided (not null)
            // This allows partial updates: login first, then logout later
            $updateData = [];

            // Handle login-related fields (only update if provided)
            if ($loginTime !== null) {
                $updateData['login_time'] = $loginTime;
            }
            if ($loginLat !== null || $loginLng !== null) {
                $updateData['login_lat'] = $loginLat;
                $updateData['login_lng'] = $loginLng;
            }
            if ($picStart !== null) {
                $updateData['picture_start'] = $picStart;
            }
            if (is_numeric($meterStart)) {
                $updateData['meter_start'] = (int)$meterStart;
            }

            // Handle logout-related fields (only update if provided)
            if ($logoutTime !== null) {
                $updateData['logout_time'] = $logoutTime;
            }
            if ($logoutLat !== null || $logoutLng !== null) {
                $updateData['logout_lat'] = $logoutLat;
                $updateData['logout_lng'] = $logoutLng;
            }
            if ($picEnd !== null) {
                $updateData['picture_end'] = $picEnd;
            }
            if (is_numeric($meterEnd)) {
                $updateData['meter_end'] = (int)$meterEnd;
            }

            // Device ID can come with either login or logout
            if ($device !== null) {
                $updateData['device_id'] = $device;
            }

            // Notes: append to existing if present, or set default
            if ($existingRecord && $existingRecord->notes && $notes) {
                // Append new notes if different
                if (strpos($existingRecord->notes, $notes) === false) {
                    $updateData['notes'] = $existingRecord->notes . ' | ' . $notes;
                }
            } elseif ($notes) {
                $updateData['notes'] = $notes;
            } elseif (!$existingRecord) {
                $updateData['notes'] = 'AppSheet webhook';
            }

            // Audit fields: use employee name as "created_by" and "updated_by" in notes for tracking
            // But use system admin (1) for the actual user_id fields
            if (!$existingRecord) {
                $updateData['created_at'] = now();
                $updateData['created_by'] = 1; // System admin
                $auditNote = " (Created by: {$user->fullname} via AppSheet)";
            } else {
                $auditNote = " (Updated by: {$user->fullname} via AppSheet)";
            }
            
            $updateData['updated_by'] = 1; // System admin
            $updateData['updated_at'] = now();

            // Append audit note to notes field
            if (isset($updateData['notes'])) {
                $updateData['notes'] .= $auditNote;
            } else {
                $updateData['notes'] = 'AppSheet webhook' . $auditNote;
            }

            // Use updateOrInsert to avoid duplicates
            \DB::table('t_ops_attendance')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'attendance_date' => $attendanceDate
                ],
                $updateData
            );

            Log::info('AppSheet attendance-update: record saved', [
                'user_id' => $user->id,
                'fullname' => $user->fullname,
                'attendance_date' => $attendanceDate,
                'action' => $existingRecord ? 'updated' : 'created'
            ]);

            return response()->json([
                'success' => true,
                'message' => $existingRecord ? 'Attendance record updated' : 'Attendance record created',
                'user_id' => $user->id,
                'fullname' => $user->fullname,
                'attendance_date' => $attendanceDate,
                'login_time' => $loginTime,
                'logout_time' => $logoutTime
            ]);

        } catch (\Exception $e) {
            Log::error('AppSheet attendance-update error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing attendance-update: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to clean employee names - reused from OperationsController
     * Removes common suffixes and extra spaces
     */
    private function cleanEmployeeName($name)
    {
        // Remove common suffixes
        $name = preg_replace('/\s*-\s*(indrive|indriver|indri)/i', '', $name);
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Trim
        $name = trim($name);
        
        return $name;
    }

    /**
     * Helper to find user by name with multiple strategies - reused from OperationsController
     * Searches ALL users (active and inactive) - historical data may reference inactive users
     */
    private function findUserByName($name)
    {
        // Strategy 1: Exact match
        $user = \DB::table('t_sys_user')->where('fullname', $name)->first();
        if ($user) return $user;

        // Strategy 2: Case-insensitive exact match
        $user = \DB::table('t_sys_user')->whereRaw('LOWER(fullname) = ?', [strtolower($name)])->first();
        if ($user) return $user;

        // Strategy 3: LIKE match (starts with)
        $user = \DB::table('t_sys_user')->where('fullname', 'like', $name.'%')->first();
        if ($user) return $user;

        // Strategy 4: Contains match
        $user = \DB::table('t_sys_user')->where('fullname', 'like', '%'.$name.'%')->first();
        if ($user) return $user;

        return null;
    }

    /**
     * Helper to split lat,lng from a single cell like "33.7, 73.0" - reused from OperationsController
     */
    private function splitLatLng($cell)
    {
        if (!$cell) return [null, null];
        if (strpos($cell, ',') !== false) {
            $parts = array_map('trim', explode(',', $cell));
            return [
                is_numeric($parts[0] ?? null) ? (float)$parts[0] : null,
                is_numeric($parts[1] ?? null) ? (float)$parts[1] : null
            ];
        }
        return [null, null];
    }

    /**
     * Webhook: Assign rider to order from AppSheet
     * Expected JSON body:
     * {
     *   "order_number": "9145",          // required
     *   "delivery_rider": "Arsalan",     // required - rider name
     *   "payment_method": "Cash",        // optional - will normalize and update if different
     *   "date": "3/3/2025"              // optional - assignment date
     * }
     * 
     * This endpoint mirrors the CSV import logic from OperationsController
     */
    public function riderAssignment(Request $request)
    {
        try {
            $payload = $request->all();

            // Log the incoming request for debugging
            Log::info('AppSheet rider-assignment webhook received', [
                'payload' => $payload,
                'headers' => $request->headers->all()
            ]);

            // Extract fields with multiple fallback keys (case-insensitive)
            $orderNumber = $payload['order_number'] 
                ?? $payload['Order Number'] 
                ?? $payload['Order_Number']
                ?? $payload['order number'] 
                ?? null;
            
            $riderName = $payload['delivery_rider'] 
                ?? $payload['Delivery_Rider'] 
                ?? $payload['rider_name'] 
                ?? $payload['Rider Name']
                ?? $payload['rider name'] 
                ?? null;
            
            $paymentMethod = $payload['payment_method'] 
                ?? $payload['Payment_method'] 
                ?? $payload['Payment Method'] 
                ?? $payload['payment method'] 
                ?? null;
            
            $assignedAt = $payload['date'] 
                ?? $payload['Date'] 
                ?? $payload['assigned_at'] 
                ?? $payload['Assigned At'] 
                ?? null;

            // Validate required fields
            if (!$orderNumber || !$riderName) {
                Log::error('AppSheet rider-assignment: missing required fields', [
                    'order_number' => $orderNumber,
                    'rider_name' => $riderName,
                    'payload' => $payload
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Missing required fields: order_number and delivery_rider'
                ], 422);
            }

            // Trim whitespace
            $orderNumber = trim($orderNumber);
            $riderName = trim($riderName);

            Log::info('AppSheet rider-assignment: extracted fields', [
                'order_number' => $orderNumber,
                'rider_name' => $riderName,
                'payment_method' => $paymentMethod,
                'assigned_at' => $assignedAt
            ]);

            // Resolve order (non-shopify only)
            $order = \DB::table('t_crm_prod_order')
                ->where(function($q) { 
                    $q->whereNull('external_source')
                      ->orWhere('external_source', '!=', 'shopify'); 
                })
                ->where('order_number', $orderNumber)
                ->first();
            
            if (!$order) {
                Log::warning('AppSheet rider-assignment: order not found', [
                    'order_number' => $orderNumber
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Order not found or is a Shopify order: {$orderNumber}"
                ], 404);
            }

            Log::info('AppSheet rider-assignment: order found', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'current_rider' => $order->assigned_rider_user_id,
                'current_payment_method' => $order->payment_method
            ]);

            // Clean rider name (remove suffixes like "- indrive", "- Indri", etc.)
            $cleanRiderName = $this->cleanEmployeeName($riderName);

            Log::info('AppSheet rider-assignment: cleaned rider name', [
                'original' => $riderName,
                'cleaned' => $cleanRiderName
            ]);

            // Resolve rider user id using smart matching (same as CSV import)
            $rider = $this->findUserByName($cleanRiderName);
            
            if (!$rider) {
                Log::warning('AppSheet rider-assignment: rider not found', [
                    'original_name' => $riderName,
                    'cleaned_name' => $cleanRiderName
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Rider not found in system: {$riderName} (cleaned: {$cleanRiderName})"
                ], 404);
            }

            Log::info('AppSheet rider-assignment: rider found', [
                'rider_id' => $rider->id,
                'fullname' => $rider->fullname
            ]);

            // Handle payment method update if provided
            $paymentMethodUpdated = false;
            if ($paymentMethod) {
                // Normalize payment method using OrderModel's method
                // Access static method via reflection or use FQCN
                $normalizedPaymentMethod = $this->normalizePaymentMethod($paymentMethod);
                
                // Only update if different from current
                if ($order->payment_method !== $normalizedPaymentMethod) {
                    \DB::table('t_crm_prod_order')
                        ->where('id', $order->id)
                        ->update([
                            'payment_method' => $normalizedPaymentMethod,
                            'updated_at' => now()
                        ]);
                    
                    Log::info('AppSheet rider-assignment: payment method updated', [
                        'order_id' => $order->id,
                        'original_payment_method' => $paymentMethod,
                        'old_payment_method' => $order->payment_method,
                        'new_payment_method' => $normalizedPaymentMethod
                    ]);
                    
                    $paymentMethodUpdated = true;
                } else {
                    Log::info('AppSheet rider-assignment: payment method unchanged', [
                        'order_id' => $order->id,
                        'payment_method' => $normalizedPaymentMethod
                    ]);
                }
            }

            // Use model method for rider assignment
            $model = \App\Models\CRM\OrderModel::find($order->id);
            $assignedAtDateTime = $assignedAt ? new \DateTime($assignedAt) : null;
            $success = $model && $model->assignRider(
                (int)$rider->id, 
                'AppSheet webhook', 
                null, // assigned_by (will default to system)
                $assignedAtDateTime
            );

            if (!$success) {
                Log::error('AppSheet rider-assignment: assignment failed', [
                    'order_id' => $order->id,
                    'rider_id' => $rider->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => "Failed to assign rider to order {$orderNumber}"
                ], 500);
            }

            Log::info('AppSheet rider-assignment: assignment successful', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'rider_id' => $rider->id,
                'rider_name' => $rider->fullname,
                'payment_method_updated' => $paymentMethodUpdated,
                'assigned_at' => $assignedAtDateTime ? $assignedAtDateTime->format('Y-m-d H:i:s') : 'now'
            ]);

            $response = [
                'success' => true,
                'message' => 'Rider assigned successfully',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'rider_id' => $rider->id,
                'rider_name' => $rider->fullname,
                'assigned_at' => $assignedAtDateTime ? $assignedAtDateTime->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s')
            ];

            if ($paymentMethodUpdated) {
                $response['payment_method_updated'] = true;
                $response['payment_method'] = $normalizedPaymentMethod ?? null;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('AppSheet rider-assignment error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error processing rider-assignment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper to normalize payment method (copied from OrderModel for webhook use)
     */
    private function normalizePaymentMethod(?string $paymentMethod): string
    {
        if (!$paymentMethod) {
            return 'cash'; // Default to cash
        }

        $method = strtolower(trim($paymentMethod));

        // Mapping from external payment methods to our standard values
        $methodMap = [
            // Cash variants
            'cash' => 'cash',
            'cash_on_delivery' => 'cash_on_delivery',
            'cod' => 'cash_on_delivery',
            
            // Bank transfer variants
            'bank_transfer' => 'bank_transfer',
            'direct_bank_transfer' => 'bank_transfer',
            'bacs' => 'bank_transfer',
            'wire_transfer' => 'bank_transfer',
            'manual' => 'bank_transfer',
            
            // Card variants
            'card' => 'card',
            'credit_card' => 'card',
            'debit_card' => 'card',
            'visa' => 'card',
            'mastercard' => 'card',
            'amex' => 'card',
            
            // Online payment variants
            'online' => 'online',
            'online_payment' => 'online',
            'paypal' => 'online',
            'stripe' => 'online',
            'razorpay' => 'online',
            'square' => 'online',
            'authorize.net' => 'online',
            'shopify_payments' => 'online',
            'bogus' => 'online', // Shopify test gateway
        ];

        // Check for partial matches if exact match not found
        if (!isset($methodMap[$method])) {
            if (strpos($method, 'bank') !== false || strpos($method, 'transfer') !== false) {
                $normalized = 'bank_transfer';
            } elseif (strpos($method, 'cash') !== false || strpos($method, 'cod') !== false) {
                $normalized = 'cash';
            } elseif (strpos($method, 'card') !== false || strpos($method, 'visa') !== false || strpos($method, 'master') !== false) {
                $normalized = 'card';
            } elseif (strpos($method, 'online') !== false || strpos($method, 'paypal') !== false || strpos($method, 'stripe') !== false) {
                $normalized = 'online';
            } else {
                $normalized = 'cash'; // Default fallback
            }
        } else {
            $normalized = $methodMap[$method];
        }

        Log::info('Payment method normalized in webhook', [
            'original' => $paymentMethod,
            'normalized' => $normalized
        ]);

        return $normalized;
    }

    /**
     * Helper to normalize time format (handle HH:MM or HH:MM:SS)
     */
    private function normalizeTime($time)
    {
        if (!$time) return null;
        
        $time = trim($time);
        
        // If already HH:MM:SS format, return as-is
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        
        // If HH:MM format, append :00
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time . ':00';
        }
        
        // Handle 12-hour format with AM/PM (e.g., "4:26:35 PM")
        // Remove extra spaces around AM/PM
        $time = preg_replace('/\s+(AM|PM|am|pm)/', ' $1', $time);
        
        // Try to parse and format using strtotime
        try {
            $timestamp = strtotime($time);
            if ($timestamp === false) {
                // If strtotime fails, try creating DateTime object
                $dateTime = \DateTime::createFromFormat('g:i:s A', $time);
                if (!$dateTime) {
                    $dateTime = \DateTime::createFromFormat('h:i:s A', $time);
                }
                if (!$dateTime) {
                    $dateTime = \DateTime::createFromFormat('g:i A', $time);
                }
                if (!$dateTime) {
                    $dateTime = \DateTime::createFromFormat('h:i A', $time);
                }
                
                if ($dateTime) {
                    return $dateTime->format('H:i:s');
                }
                
                return null;
            }
            
            $parsed = date('H:i:s', $timestamp);
            return $parsed;
        } catch (\Exception $e) {
            \Log::error('Time normalization failed', [
                'original_time' => $time,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
