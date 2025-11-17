<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\HR\SalarySlipModel;
use App\Models\HR\EmployeeLoanModel;
use App\Models\HR\EmployeeProfileModel;

class RiderController extends Controller
{
    /**
     * Get dashboard summary for logged-in rider
     * Returns: order counts, ledger balance, pending requests, attendance status
     */
    public function dashboard(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get order counts for this rider
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            $orderCounts = DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $user->id)
                ->whereNotIn('order_status', $excludedStatuses)
                ->select([
                    DB::raw('COUNT(*) as total_orders'),
                    DB::raw('SUM(CASE WHEN order_status = "ready_for_delivery" THEN 1 ELSE 0 END) as ready_for_delivery'),
                    DB::raw('SUM(CASE WHEN order_status = "pending" THEN 1 ELSE 0 END) as pending'),
                    DB::raw('SUM(CASE WHEN order_status = "processing" THEN 1 ELSE 0 END) as processing')
                ])
                ->first();

            // Get ledger balance
            $ledgerBalance = DB::table('t_fin_employee_cash_txn')
                ->where('rider_user_id', $user->id)
                ->select([
                    DB::raw('SUM(CASE WHEN txn_type = "debit" THEN amount ELSE 0 END) as total_debit'),
                    DB::raw('SUM(CASE WHEN txn_type = "credit" THEN amount ELSE 0 END) as total_credit')
                ])
                ->first();

            $balance = ($ledgerBalance->total_debit ?? 0) - ($ledgerBalance->total_credit ?? 0);

            // Get pending requests count
            $pendingRequests = DB::table('t_fin_request')
                ->where('requested_by', $user->id)
                ->where('status', 'pending')
                ->count();

            // Get today's attendance
            $today = now()->format('Y-m-d');
            $attendance = DB::table('t_hr_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->select('check_in_time', 'check_out_time', 'status')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'fullname' => $user->fullname,
                        'email' => $user->email,
                    ],
                    'orders' => [
                        'total' => $orderCounts->total_orders ?? 0,
                        'ready_for_delivery' => $orderCounts->ready_for_delivery ?? 0,
                        'pending' => $orderCounts->pending ?? 0,
                        'processing' => $orderCounts->processing ?? 0,
                    ],
                    'ledger' => [
                        'balance' => $balance,
                        'balance_formatted' => 'Rs. ' . number_format(abs($balance), 2),
                        'balance_type' => $balance >= 0 ? 'debit' : 'credit',
                    ],
                    'requests' => [
                        'pending_count' => $pendingRequests,
                    ],
                    'attendance' => [
                        'today' => $today,
                        'checked_in' => $attendance ? ($attendance->check_in_time !== null) : false,
                        'checked_out' => $attendance ? ($attendance->check_out_time !== null) : false,
                        'check_in_time' => $attendance->check_in_time ?? null,
                        'check_out_time' => $attendance->check_out_time ?? null,
                        'status' => $attendance->status ?? 'absent',
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed information for a specific order
     * Riders can only view their own assigned orders
     */
    public function getOrderDetails(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Fetch order with relationships
            $order = \App\Models\CRM\OrderModel::with([
                'customer',
                'lineItems',
                'discounts',
                'statusHistory' => function($q) {
                    $q->orderBy('changed_at', 'desc');
                }
            ])->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Check if rider is assigned to this order
            if ($order->assigned_rider_user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to view this order',
                ], 403);
            }

            // Format line items
            $lineItems = $order->lineItems->map(function($item) {
                return [
                    'id' => $item->id,
                    'product_name' => $item->name ?? 'N/A',
                    'variant_name' => $item->sku ?? '',
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'unit_price_formatted' => 'Rs. ' . number_format($item->unit_price, 0),
                    'total' => $item->line_total,
                    'total_formatted' => 'Rs. ' . number_format($item->line_total, 0),
                    'preparation_status' => $item->preparation_status,
                ];
            });
            
            // Calculate preparation summary
            $totalItems = $lineItems->count();
            $preparingCount = $lineItems->where('preparation_status', 'preparing')->count();

            // Format status history
            $statusHistory = $order->statusHistory->map(function($history) {
                return [
                    'status_code' => $history->status_code,
                    'status_display' => ucwords(str_replace(['_', '-'], ' ', $history->status_code)),
                    'changed_at' => $history->changed_at,
                    'changed_by' => $history->changed_by,
                    'notes' => $history->notes,
                ];
            });

            $paymentMethod = strtolower($order->payment_method ?? 'cash');
            $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);

            // Get delivery location if order is delivered
            $deliveryLocation = null;
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                $deliveryHistory = \DB::table('t_crm_order_status_history')
                    ->where('order_id', $order->id)
                    ->where('status_code', 'delivered')
                    ->where('is_current', 1)
                    ->select('delivery_latitude', 'delivery_longitude', 'changed_at')
                    ->first();
                
                if ($deliveryHistory && $deliveryHistory->delivery_latitude && $deliveryHistory->delivery_longitude) {
                    $deliveryLocation = [
                        'latitude' => (float)$deliveryHistory->delivery_latitude,
                        'longitude' => (float)$deliveryHistory->delivery_longitude,
                        'delivered_at' => $deliveryHistory->changed_at,
                        'google_maps_url' => "https://www.google.com/maps?q={$deliveryHistory->delivery_latitude},{$deliveryHistory->delivery_longitude}"
                    ];
                }
            }

            // Format discounts (for invoice calculation)
            $discounts = $order->discounts ? $order->discounts->map(function($discount) {
                return [
                    'discount_amount' => $discount->discount_amount,
                    'discount_type' => $discount->discount_type,
                ];
            })->toArray() : [];

            // Generate invoice URLs
            $invoiceImageUrl = route('orders.invoice.pdf', ['id' => $order->id, 'download_image' => 1]);
            $invoicePdfUrl = route('orders.invoice.pdf', ['id' => $order->id, 'force_pdf' => 1]);

            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->order_status,
                    'status_display' => ucwords(str_replace(['_', '-'], ' ', $order->order_status)),
                    'order_date' => $order->order_date,
                    'delivery_date' => $order->delivery_date,
                    'payment_method' => $order->payment_method,
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'payment_label' => $isCash ? 'Cash' : 'Online',
                    'customer' => [
                        'id' => $order->customer->id ?? null,
                        'name' => $order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'N/A',
                        'phone' => $order->customer->phone_original ?? $order->customer->phone ?? '',
                        'email' => $order->customer->email ?? '',
                        'address' => $order->customer->address1 ?? '',
                        'address1' => $order->customer->address1 ?? '',
                        'address2' => $order->customer->address2 ?? '',
                        'city' => $order->customer->city ?? '',
                        'province' => $order->customer->province ?? '',
                        'postal_code' => $order->customer->postal_code ?? '',
                        'verified_location' => ($order->customer && ($order->customer->verified_location_url || ($order->customer->latitude && $order->customer->longitude))) ? [
                            'latitude' => $order->customer->latitude ? (float)$order->customer->latitude : null,
                            'longitude' => $order->customer->longitude ? (float)$order->customer->longitude : null,
                            'url' => $order->customer->verified_location_url ?? null,
                            'google_maps_url' => $order->customer->verified_location_url ?: ($order->customer->latitude && $order->customer->longitude ? "https://www.google.com/maps?q={$order->customer->latitude},{$order->customer->longitude}" : null),
                            'saved_by' => $order->customer->verified_location_saved_by ? \DB::table('t_sys_user')->where('id', $order->customer->verified_location_saved_by)->value('fullname') : null,
                            'saved_at' => $order->customer->verified_location_saved_at,
                        ] : null,
                    ],
                    'amounts' => [
                        'subtotal' => $order->subtotal_price,
                        'discount' => $order->discount_total,
                        'shipping' => $order->shipping_total,
                        'total' => $order->total_price,
                        'subtotal_formatted' => 'Rs. ' . number_format($order->subtotal_price, 0),
                        'discount_formatted' => 'Rs. ' . number_format($order->discount_total, 0),
                        'shipping_formatted' => 'Rs. ' . number_format($order->shipping_total, 0),
                        'total_formatted' => 'Rs. ' . number_format($order->total_price, 0),
                    ],
                    'shipping_total' => $order->shipping_total ?? 0,
                    'tip_amount' => $order->tip_amount ?? 0,
                    'total_price' => $order->total_price,
                    'discounts' => $discounts,
                    'notes' => $order->note,
                    'expected_packets' => $order->expected_packets, // Number of packets expected (from manager)
                    'actual_packets' => $order->actual_packets,     // Number of packets delivered (from rider)
                    'delivery_location' => $deliveryLocation,       // GPS coordinates of delivery (if delivered)
                    'invoice' => [
                        'image_url' => $invoiceImageUrl,  // URL to download invoice as PNG image
                        'pdf_url' => $invoicePdfUrl,      // URL to download invoice as PDF
                    ],
                    'line_items' => $lineItems,
                    'preparation_summary' => [
                        'preparing_count' => $preparingCount,
                        'total_items' => $totalItems,
                    ],
                    'status_history' => $statusHistory,
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get order details', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load order details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set verified location for a customer
     * Accepts either coordinates OR Google Maps URL
     */
    public function setCustomerVerifiedLocation(Request $request, $customerId)
    {
        try {
            $validated = $request->validate([
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'url' => 'nullable|string|max:500',
            ]);

            // Must provide either coordinates OR URL
            if (empty($validated['latitude']) && empty($validated['longitude']) && empty($validated['url'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide either coordinates or a Google Maps URL'
                ], 400);
            }

            $customer = \App\Models\CRM\CustomerModel::find($customerId);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found',
                ], 404);
            }

            // Prepare update data
            $updateData = [
                'updated_by' => Auth::id(),
                'verified_location_saved_by' => Auth::id(),
                'verified_location_saved_at' => now(),
            ];
            
            if (!empty($validated['url'])) {
                // URL provided - store it
                $updateData['verified_location_url'] = $validated['url'];
                \Log::info('Setting verified location URL for customer', [
                    'customer_id' => $customerId,
                    'url' => $validated['url'],
                    'saved_by' => Auth::user()->fullname,
                ]);
            }
            
            if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                // Coordinates provided - store them
                $updateData['latitude'] = $validated['latitude'];
                $updateData['longitude'] = $validated['longitude'];
                \Log::info('Setting verified location coordinates for customer', [
                    'customer_id' => $customerId,
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'saved_by' => Auth::user()->fullname,
                ]);
            }

            // Update customer
            $customer->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Verified location saved successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid location data',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to set customer verified location', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save location: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick verify location from address (for Store Mode)
     * Geocodes the address and saves it as verified location
     */
    public function setVerifiedLocationFromAddress(Request $request, $orderId)
    {
        try {
            $validated = $request->validate([
                'customer_id' => 'required|integer',
                'address' => 'required|string',
            ]);

            $customer = \App\Models\CRM\CustomerModel::find($validated['customer_id']);

            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found',
                ], 404);
            }

            // Create Google Maps search URL from address
            $googleMapsUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode($validated['address']);

            // Prepare update data
            $updateData = [
                'updated_by' => Auth::id(),
                'verified_location_saved_by' => Auth::id(),
                'verified_location_saved_at' => now(),
                'verified_location_url' => $googleMapsUrl,
            ];

            // Update customer
            $customer->update($updateData);

            \Log::info('Quick verified location from address', [
                'order_id' => $orderId,
                'customer_id' => $validated['customer_id'],
                'address' => $validated['address'],
                'url' => $googleMapsUrl,
                'saved_by' => Auth::user()->fullname,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Verified location saved successfully',
                'verified_location_url' => $googleMapsUrl,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid data',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to quick verify location from address', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save location: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark an order as delivered
     * Riders can only mark their own assigned orders as delivered
     */
    public function markOrderDelivered(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Fetch order WITH customer relationship eagerly loaded for ledger posting
            $order = \App\Models\CRM\OrderModel::with('customer')->find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Check if rider is assigned to this order
            if ($order->assigned_rider_user_id != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to update this order',
                ], 403);
            }

            // Check if order is already delivered
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This order is already marked as delivered',
                ], 400);
            }

            // Get notes, GPS coordinates, and packet count from request (optional)
            $notes = $request->input('notes', 'Marked as delivered by rider via mobile app');
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');
            $actualPackets = $request->input('actual_packets'); // Optional packet count from rider

            // Log received GPS data for debugging
            \Log::info('Received GPS data from mobile app', [
                'order_id' => $order->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'latitude_type' => gettype($latitude),
                'longitude_type' => gettype($longitude),
                'latitude_is_null' => is_null($latitude),
                'longitude_is_null' => is_null($longitude),
                'full_request' => $request->all()
            ]);

            // Add GPS coordinates to notes if provided
            if ($latitude && $longitude) {
                $notes .= " (GPS: {$latitude}, {$longitude})";
            }

            // Update actual_packets if provided by rider
            if ($actualPackets !== null && is_numeric($actualPackets)) {
                $order->actual_packets = (int)$actualPackets;
                $order->save();
                
                \Log::info('Rider entered packet count', [
                    'order_id' => $order->id,
                    'expected_packets' => $order->expected_packets,
                    'actual_packets' => $actualPackets,
                    'match' => $order->expected_packets == $actualPackets
                ]);
            }

            // Use the existing changeStatus method
            $result = $order->changeStatus('delivered', $notes, $user->id);

            // Update the status history record with GPS coordinates if provided
            if ($result && $latitude && $longitude) {
                \DB::table('t_crm_order_status_history')
                    ->where('order_id', $order->id)
                    ->where('status_code', 'delivered')
                    ->where('is_current', 1)
                    ->update([
                        'delivery_latitude' => $latitude,
                        'delivery_longitude' => $longitude
                    ]);
                
                \Log::info('GPS location stored', [
                    'order_id' => $order->id,
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);
            }

            if ($result) {
                \Log::info('Order marked as delivered by rider', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'rider_id' => $user->id,
                    'rider_name' => $user->fullname,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Order marked as delivered successfully',
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_status' => $order->order_status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update order status',
                ], 500);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to mark order as delivered', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark order as delivered: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change payment method for an order (rider can switch between cash/online)
     * For non-delivered orders only
     */
    public function changePaymentMethod(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $request->validate([
                'payment_type' => 'required|in:cash,online',
            ]);
            
            $order = OrderModel::where('id', $id)
                ->where('assigned_rider_user_id', $user->id) // Fixed: correct column name
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or not assigned to you',
                ], 404);
            }
            
            // Check if order is already delivered - riders can only change before delivery
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change payment method for delivered orders',
                ], 400);
            }
            
            $newPaymentType = $request->payment_type;
            $oldPaymentMethod = $order->payment_method;
            $oldPaymentType = in_array($oldPaymentMethod, ['cash', 'cash_on_delivery', 'cod']) ? 'cash' : 'online';
            
            if ($oldPaymentType === $newPaymentType) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method is already set to ' . $newPaymentType,
                ], 400);
            }
            
            // Map the payment type to actual payment method value
            $newPaymentMethod = $newPaymentType === 'cash' ? 'cash_on_delivery' : 'online_payment';
            
            // Update payment method
            $order->payment_method = $newPaymentMethod;
            $order->save();
            
            \Log::info('Payment method changed by rider (before delivery)', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'rider_id' => $user->id,
                'rider_name' => $user->fullname,
                'old_method' => $oldPaymentMethod,
                'new_method' => $newPaymentMethod,
                'order_status' => $order->order_status
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment method changed to ' . ($newPaymentType === 'cash' ? 'Cash' : 'Online/Bank'),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to change payment method', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to change payment method: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get ledger balance and recent transactions for logged-in rider
     */
    public function getLedger(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get rider's account (employee_cash category)
            $account = \App\Models\FIN\AccountModel::where('user_id', $user->id)
                ->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->first();

            if (!$account) {
                // Return empty ledger if no account exists yet
                return response()->json([
                    'success' => true,
                    'account_id' => null,
                    'balance' => 0,
                    'balance_formatted' => 'Rs. 0',
                    'balance_type' => 'No account yet',
                    'outstanding_invoices' => [
                        'count' => 0,
                        'total' => 0,
                        'total_formatted' => 'Rs. 0',
                    ],
                    'recent_transactions' => [],
                ]);
            }

            // Calculate balance from APPROVED transactions only (match webapp logic)
            $balance = \App\Models\FIN\LedgerModel::where('from_account_id', $account->id)
                ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
                ->sum('amount') - \App\Models\FIN\LedgerModel::where('to_account_id', $account->id)
                ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
                ->sum('amount');

            // Get recent transactions (last 30 days)
            $recentTransactions = \App\Models\FIN\LedgerModel::where(function($q) use ($account) {
                $q->where('from_account_id', $account->id)
                  ->orWhere('to_account_id', $account->id);
            })
            ->where('transaction_date', '>=', now()->subDays(30))
            ->orderBy('transaction_date', 'desc')
            ->limit(20)
            ->get()
            ->map(function($txn) use ($account) {
                return [
                    'id' => $txn->id,
                    'date' => $txn->transaction_date->format('Y-m-d'),
                    'type' => $txn->transaction_type,
                    'description' => $txn->description,
                    'amount' => $txn->amount,
                    'amount_formatted' => 'Rs. ' . number_format($txn->amount, 2),
                    'is_debit' => $txn->from_account_id == $account->id,
                    'is_credit' => $txn->to_account_id == $account->id,
                ];
            });

            // Get outstanding invoices count and total
            $outstandingInvoices = \App\Models\FIN\LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
                ->where('settlement_status', 'open')
                ->get();

            $totalOutstanding = $outstandingInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            return response()->json([
                'success' => true,
                'account_id' => $account->id,
                'balance' => $balance,
                'balance_formatted' => 'Rs. ' . number_format(abs($balance), 2),
                'balance_type' => $balance >= 0 ? 'You are owed' : 'You owe',
                'outstanding_invoices' => [
                    'count' => $outstandingInvoices->count(),
                    'total' => $totalOutstanding,
                    'total_formatted' => 'Rs. ' . number_format($totalOutstanding, 0), // Format to 0 decimals
                ],
                'recent_transactions' => $recentTransactions,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get rider ledger', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load ledger: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get expense categories (for short cash settlements)
     */
    public function getExpenseCategories(Request $request)
    {
        try {
            // Get categories from existing expenses (like webapp does)
            $categories = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->whereNotNull('expense_category')
                ->where('expense_category', '!=', '')
                ->distinct()
                ->pluck('expense_category')
                ->sort()
                ->values()
                ->toArray();

            // Add PENDING as a special option at the top for partial payments
            array_unshift($categories, 'PENDING');

            return response()->json([
                'success' => true,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get expense categories', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load categories: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get outstanding invoices for settlement (reuses existing controller logic)
     */
    public function getOutstandingInvoices(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get rider's account (employee_cash category)
            $account = \App\Models\FIN\AccountModel::where('user_id', $user->id)
                ->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found for this user',
                ], 404);
            }

            // Reuse existing method from EmployeeCashController
            $controller = new \App\Http\Controllers\FIN\EmployeeCashController();
            return $controller->getOutstandingInvoices($account->id);
        } catch (\Exception $e) {
            \Log::error('Failed to get outstanding invoices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load invoices: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Settle invoices (full or partial payment)
     */
    public function settleInvoices(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get rider's account (employee_cash category)
            $account = \App\Models\FIN\AccountModel::where('user_id', $user->id)
                ->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found for this user',
                ], 404);
            }

            // Validate request
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'transaction_date' => 'required|date',
                'invoice_ids' => 'required|array|min:1',
                'invoice_ids.*' => 'exists:t_fin_ledger,id'
            ]);

            \DB::beginTransaction();

            // Get destination account
            $destinationAccount = \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
            }

            // Verify selected invoices (include both open and partial invoices)
            $selectedInvoices = \App\Models\FIN\LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $account->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            $totalOutstanding = $selectedInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            // Build description
            $invoiceNumbers = $selectedInvoices->map(function($invoice) {
                return $invoice->order ? $invoice->order->order_number : "Invoice #" . $invoice->id;
            })->take(3)->join(', ');
            
            if ($selectedInvoices->count() > 3) {
                $invoiceNumbers .= " + " . ($selectedInvoices->count() - 3) . " more";
            }

            $description = $request->description 
                ? "Settlement: {$invoiceNumbers} - {$request->description}"
                : "Settlement for invoices: {$invoiceNumbers}";

            // Create deposit transaction (pending approval)
            $depositLedger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $description,
                'from_account_id' => $account->id,
                'to_account_id' => $destinationAccount->id,
                'amount' => $request->amount,
                'mode' => \App\Models\FIN\LedgerModel::MODE_CASH,
                'approval_status' => \App\Models\FIN\LedgerModel::STATUS_PENDING,
                'approval_date' => null,
                'approved_by' => null,
                'created_by' => $user->id,
                'comments' => "Settlement deposit for {$selectedInvoices->count()} invoice(s). Total outstanding: Rs. " . number_format($totalOutstanding, 2),
                'settlement_metadata' => [
                    'invoice_ids' => $request->invoice_ids,
                    'deposit_amount' => $request->amount,
                    'total_outstanding' => $totalOutstanding
                ]
            ]);

            \DB::commit();

            $message = 'Settlement deposit recorded and pending approval!';
            if ($request->amount < $totalOutstanding) {
                $shortfall = $totalOutstanding - $request->amount;
                $message .= " Note: Short by Rs. " . number_format($shortfall, 2);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'deposit_id' => $depositLedger->id,
            ]);

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to settle invoices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to settle invoices: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Settle with short cash (partial payment + expense request)
     */
    public function settleShortCash(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get rider's account (employee_cash category)
            $account = \App\Models\FIN\AccountModel::where('user_id', $user->id)
                ->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->first();

            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found for this user',
                ], 404);
            }

            // Validate request
            $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'transaction_date' => 'required|date',
                'invoice_ids' => 'required|array|min:1',
                'invoice_ids.*' => 'exists:t_fin_ledger,id',
                'expense_category' => 'required|string|max:100'
            ]);

            \DB::beginTransaction();

            // Get destination account
            $destinationAccount = \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
            }

            // Verify selected invoices (include both open and partial invoices)
            $selectedInvoices = \App\Models\FIN\LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $account->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Calculate expected amount and shortage (remaining balance for partial invoices)
            $totalOutstanding = $selectedInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            $depositAmount = $request->amount;
            $shortCashAmount = $totalOutstanding - $depositAmount;

            // Use tolerance for floating-point comparison (0.01 Rs tolerance)
            // This handles edge cases from old transactions or rounding issues
            $TOLERANCE = 0.01;
            
            if ($shortCashAmount < -$TOLERANCE) {
                throw new \Exception("Deposit amount cannot exceed total outstanding");
            }
            
            // If shortage is within tolerance (e.g., 0.001 Rs), treat as exact match
            if (abs($shortCashAmount) < $TOLERANCE) {
                $shortCashAmount = 0;
            }

            // Check if this is a PENDING (partial payment) or actual expense
            $expenseRequest = null;
            $isPartialPayment = false;
            
            if (strtoupper($request->expense_category) === 'PENDING') {
                // Partial payment - no expense request, just settle what was deposited
                $isPartialPayment = true;
                \Log::info("Mobile: Partial payment settlement - keeping invoices open", [
                    'user_id' => $user->id,
                    'deposit_amount' => $depositAmount,
                    'remaining_amount' => $shortCashAmount,
                    'total_outstanding' => $totalOutstanding
                ]);
            } else {
                // Create expense request for shortage
                $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')->first();
                if (!$category) {
                    throw new \Exception("Expense category not found in system");
                }

                $expenseRequest = \App\Models\Request\RequestModel::create([
                    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
                    'category_id' => $category->id,
                    'requester_user_id' => $account->user_id,
                    'title' => "Short Cash - {$request->expense_category}",
                    'amount' => $shortCashAmount,
                    'expense_category' => $request->expense_category,
                    'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
                    'payment_source_account_id' => $account->id,
                    'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
                    'settlement_status' => 'pending',
                    'requires_level_1' => $category->requiresLevel1(),
                    'requires_level_2' => $category->requiresLevel2(),
                    'level_1_status' => $category->requiresLevel1() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                    'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                    'submitted_at' => now(),
                    'created_by' => $user->id,
                ]);
            }

            // Build description
            $invoiceNumbers = $selectedInvoices->map(function($invoice) {
                return $invoice->order ? $invoice->order->order_number : "Invoice #" . $invoice->id;
            })->take(3)->join(', ');
            
            if ($selectedInvoices->count() > 3) {
                $invoiceNumbers .= " + " . ($selectedInvoices->count() - 3) . " more";
            }

            // Build description and metadata based on settlement type
            if ($isPartialPayment) {
                $description = "Partial Payment: {$invoiceNumbers} - Paid Rs. " . number_format($depositAmount, 2) . ", Remaining Rs. " . number_format($shortCashAmount, 2);
                $comments = "Partial payment. Paid: Rs. " . number_format($depositAmount, 2) . ", Remaining: Rs. " . number_format($shortCashAmount, 2) . " (will remain open)";
                $settlementMetadata = [
                    'invoice_ids' => $request->invoice_ids,
                    'deposit_amount' => $depositAmount,
                    'total_outstanding' => $totalOutstanding,
                    'is_partial_payment' => true,
                    'is_short_cash_settlement' => false,
                    'short_cash_amount' => $shortCashAmount,
                    'expense_category' => 'PENDING'
                ];
            } else {
                $description = "Short Cash Settlement: {$invoiceNumbers} (Shortage: {$request->expense_category})";
                $comments = "Short cash settlement. Deposit: Rs. " . number_format($depositAmount, 2) . ", Shortage (Expense #{$expenseRequest->request_number}): Rs. " . number_format($shortCashAmount, 2);
                $settlementMetadata = [
                    'invoice_ids' => $request->invoice_ids,
                    'deposit_amount' => $depositAmount,
                    'total_outstanding' => $totalOutstanding,
                    'is_short_cash_settlement' => true,
                    'is_partial_payment' => false,
                    'short_cash_amount' => $shortCashAmount,
                    'expense_request_id' => $expenseRequest->id,
                    'expense_category' => $request->expense_category
                ];
            }

            // Create deposit transaction
            $depositLedger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT,
                'description' => $description,
                'from_account_id' => $account->id,
                'to_account_id' => $destinationAccount->id,
                'amount' => $depositAmount,
                'mode' => \App\Models\FIN\LedgerModel::MODE_CASH,
                'approval_status' => \App\Models\FIN\LedgerModel::STATUS_PENDING,
                'created_by' => $user->id,
                'comments' => $comments,
                'settlement_metadata' => $settlementMetadata
            ]);

            \DB::commit();

            // Build response message
            if ($isPartialPayment) {
                $message = "Partial payment recorded! Paid: Rs. " . number_format($depositAmount, 2) . ". Remaining Rs. " . number_format($shortCashAmount, 2) . " will stay open for future settlement.";
                $responseData = [
                    'success' => true,
                    'message' => $message,
                    'deposit_id' => $depositLedger->id,
                    'is_partial_payment' => true
                ];
            } else {
                $message = "Short cash settlement recorded! Deposit: Rs. " . number_format($depositAmount, 2) . ". Expense request #{$expenseRequest->request_number} created for shortage of Rs. " . number_format($shortCashAmount, 2);
                $responseData = [
                    'success' => true,
                    'message' => $message,
                    'deposit_id' => $depositLedger->id,
                    'expense_request_id' => $expenseRequest->id
                ];
            }

            return response()->json($responseData);

        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to settle short cash', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to settle with short cash: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get today's attendance for logged-in rider
     */
    public function getTodayAttendance(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');

            $attendance = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->first();

            return response()->json([
                'success' => true,
                'attendance' => $attendance ? [
                    'id' => $attendance->id,
                    'attendance_date' => $attendance->attendance_date,
                    'login_time' => $attendance->login_time,
                    'logout_time' => $attendance->logout_time,
                    'notes' => $attendance->notes,
                    'is_checked_in' => $attendance->login_time !== null,
                    'is_checked_out' => $attendance->logout_time !== null,
                ] : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get today attendance', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to load attendance: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Check in for today
     * Optionally accepts meter picture
     */
    public function checkIn(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            // Validate optional meter picture
            $request->validate([
                'meter_picture' => 'nullable|image|max:5120', // 5MB max
            ]);

            // Check if already checked in today
            $existing = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->first();

            if ($existing && $existing->login_time) {
                return response()->json(['success' => false, 'message' => 'Already checked in today'], 400);
            }

            // Store meter picture if provided
            $picturePath = null;
            if ($request->hasFile('meter_picture')) {
                $picturePath = $this->storeMeterPicture($request->file('meter_picture'), $user->id, 'checkin');
            }

            if ($existing) {
                // Update existing record
                $updateData = [
                    'login_time' => $currentTime,
                    'updated_at' => now(),
                ];
                if ($picturePath) {
                    $updateData['picture_start'] = $picturePath;
                }
                
                \DB::table('t_ops_attendance')
                    ->where('id', $existing->id)
                    ->update($updateData);
            } else {
                // Create new record
                $insertData = [
                    'user_id' => $user->id,
                    'attendance_date' => $today,
                    'login_time' => $currentTime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if ($picturePath) {
                    $insertData['picture_start'] = $picturePath;
                }
                
                \DB::table('t_ops_attendance')->insert($insertData);
            }

            return response()->json([
                'success' => true,
                'message' => 'Checked in successfully at ' . date('h:i A', strtotime($currentTime)),
                'login_time' => $currentTime,
                'picture_url' => $picturePath ? $this->getMeterPictureUrl($picturePath) : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to check in', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to check in: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Store meter picture to storage
     */
    private function storeMeterPicture($file, $userId, $type): string
    {
        $date = now();
        $year = $date->format('Y');
        $month = $date->format('m');
        $filename = "user_{$userId}_{$date->format('Ymd_His')}_{$type}.jpg";
        
        $path = "attendance/meters/{$year}/{$month}/{$filename}";
        
        \Storage::disk('public')->put($path, file_get_contents($file));
        
        return $path;
    }

    /**
     * Get meter picture URL (works for both local dev and production)
     */
    private function getMeterPictureUrl($picturePath): string
    {
        // Always route through our storage proxy (works in prod + local)
        // Build absolute URL using the current request host to support mobile devices
        $base = request()->getSchemeAndHttpHost();
        $url = rtrim($base, '/') . '/public-storage/' . ltrim($picturePath, '/');
        
        \Log::info('getMeterPictureUrl', [
            'input' => $picturePath,
            'base' => $base,
            'output' => $url,
        ]);
        
        return $url;
    }

    /**
     * Upload meter picture independently (after check-in/out)
     */
    public function uploadMeterPicture(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');

            // Validate request
            $request->validate([
                'meter_picture' => 'required|image|max:5120', // 5MB max
                'type' => 'required|in:start,end',
            ]);

            // Check if attendance record exists for today
            $existing = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->first();

            if (!$existing) {
                return response()->json([
                    'success' => false,
                    'message' => 'No attendance record found for today. Please check in first.'
                ], 400);
            }

            $type = $request->input('type');
            
            // Validate based on type - only check login for start picture
            // End picture can be uploaded anytime after check-in (no need to check out first)
            if ($type === 'start' && !$existing->login_time) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please check in first before uploading start meter picture.'
                ], 400);
            }

            // Store meter picture
            $picturePath = $this->storeMeterPicture($request->file('meter_picture'), $user->id, $type === 'start' ? 'checkin' : 'checkout');

            // Update attendance record
            $updateData = [
                'updated_at' => now(),
            ];
            if ($type === 'start') {
                $updateData['picture_start'] = $picturePath;
            } else {
                $updateData['picture_end'] = $picturePath;
            }

            \DB::table('t_ops_attendance')
                ->where('id', $existing->id)
                ->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Meter picture uploaded successfully',
                'picture_url' => $this->getMeterPictureUrl($picturePath),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to upload meter picture', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload meter picture: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check out for today
     * Optionally accepts meter picture
     */
    public function checkOut(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            // Validate optional meter picture
            $request->validate([
                'meter_picture' => 'nullable|image|max:5120', // 5MB max
            ]);

            // Check if checked in today
            $existing = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->first();

            if (!$existing || !$existing->login_time) {
                return response()->json(['success' => false, 'message' => 'Please check in first'], 400);
            }

            if ($existing->logout_time) {
                return response()->json(['success' => false, 'message' => 'Already checked out today'], 400);
            }

            // Store meter picture if provided
            $picturePath = null;
            if ($request->hasFile('meter_picture')) {
                $picturePath = $this->storeMeterPicture($request->file('meter_picture'), $user->id, 'checkout');
            }

            // Update with logout time and picture
            $updateData = [
                'logout_time' => $currentTime,
                'updated_at' => now(),
            ];
            if ($picturePath) {
                $updateData['picture_end'] = $picturePath;
            }

            \DB::table('t_ops_attendance')
                ->where('id', $existing->id)
                ->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Checked out successfully at ' . date('h:i A', strtotime($currentTime)),
                'logout_time' => $currentTime,
                'picture_url' => $picturePath ? $this->getMeterPictureUrl($picturePath) : null,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to check out', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to check out: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get monthly attendance (reuses salary service logic for consistency)
     */
    public function getMonthlyAttendance(Request $request)
    {
        try {
            $user = Auth::user();

            // Get month parameter (default to current month)
            $month = $request->input('month', now()->format('Y-m-01'));

            // Establish date range (limit to today for current month)
            $startDate = date('Y-m-01', strtotime($month));
            $endDate = date('Y-m-t', strtotime($month));
            $today = date('Y-m-d');
            $effectiveEndDate = ($endDate > $today) ? $today : $endDate;

            // Attempt to reuse salary service for summary (matches salary section)
            $salaryService = new \App\Services\HR\SalaryCalculationService();
            $salaryData = $salaryService->calculateSalary($user->id, $month, []);

            // Prepare attendance records (used by both primary and fallback flows)
            $attendanceRecords = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereBetween('attendance_date', [$startDate, $effectiveEndDate])
                ->get()
                ->keyBy('attendance_date');

            // Get approved/pending leave requests for the month
            $leaveRequests = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
                ->whereIn('status', ['approved', 'pending'])
                ->whereHas('category', function ($q) {
                    $q->where('category_code', 'leave');
                })
                ->where(function ($q) use ($startDate, $effectiveEndDate) {
                    $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
                      ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
                      ->orWhere(function ($q2) use ($startDate, $effectiveEndDate) {
                          $q2->where('leave_start_date', '<=', $startDate)
                             ->where('leave_end_date', '>=', $effectiveEndDate);
                      });
                })
                ->get();

            // Build a set of dates that are on leave
            $leaveDates = [];
            foreach ($leaveRequests as $req) {
                if ($req->leave_start_date && $req->leave_end_date) {
                    $d = new \DateTime($req->leave_start_date);
                    $dEnd = new \DateTime($req->leave_end_date);
                    while ($d <= $dEnd) {
                        $dateStr = $d->format('Y-m-d');
                        if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
                            $leaveDates[$dateStr] = true;
                        }
                        $d->modify('+1 day');
                    }
                }
            }

            // Get user's shift utilities
            $shiftService = new \App\Services\ShiftResolutionService();
            
            // Get user's shift times
            $userShift = $shiftService->getUserShift($user->id);
            $shiftStart = $userShift['shift_start'] ?? '09:00:00';
            $shiftEnd = $userShift['shift_end'] ?? '17:00:00';

            // Build calendar history of working days with attendance/absent
            $history = [];
            $currentDate = new \DateTime($startDate);
            $endDateTime = new \DateTime($effectiveEndDate);

            while ($currentDate <= $endDateTime) {
                $dateStr = $currentDate->format('Y-m-d');
                $isWorkingDay = $shiftService->isWorkingDay($user->id, $dateStr);

                if ($isWorkingDay) {
                    if (isset($attendanceRecords[$dateStr])) {
                        $record = $attendanceRecords[$dateStr];
                        
                        // Determine detailed status: Check leave FIRST, then attendance
                        $status = 'absent';
                        $lateMinutes = 0;
                        
                        // ✅ FIRST: Check if this date is on leave (even if they have an attendance record)
                        if (isset($leaveDates[$dateStr])) {
                            $status = 'on_leave';
                        } elseif ($record->login_time) {
                            // Check if late
                            $shiftStartTime = strtotime($dateStr . ' ' . $shiftStart);
                            $loginTime = strtotime($dateStr . ' ' . $record->login_time);
                            
                            if ($loginTime > $shiftStartTime) {
                                $lateMinutes = round(($loginTime - $shiftStartTime) / 60);
                                $status = 'late';
                            } else {
                                $status = $record->logout_time ? 'completed' : 'in_progress';
                            }
                        }
                        // else: status remains 'absent' (no login and not on leave)
                        
                        $history[] = [
                            'id' => $record->id,
                            'date' => $record->attendance_date,
                            'date_formatted' => $currentDate->format('D, M d, Y'),
                            'login_time' => $record->login_time,
                            'login_time_formatted' => $record->login_time ? date('h:i A', strtotime($record->login_time)) : null,
                            'logout_time' => $record->logout_time,
                            'logout_time_formatted' => $record->logout_time ? date('h:i A', strtotime($record->logout_time)) : null,
                            'status' => $status,
                            'late_minutes' => $lateMinutes,
                            'notes' => $record->notes,
                            'picture_start' => $record->picture_start ? $this->getMeterPictureUrl($record->picture_start) : null,
                            'picture_end' => $record->picture_end ? $this->getMeterPictureUrl($record->picture_end) : null,
                        ];
                    } else {
                        // No attendance record - check if on leave
                        $status = isset($leaveDates[$dateStr]) ? 'on_leave' : 'absent';
                        
                        $history[] = [
                            'id' => null,
                            'date' => $dateStr,
                            'date_formatted' => $currentDate->format('D, M d, Y'),
                            'login_time' => null,
                            'login_time_formatted' => null,
                            'logout_time' => null,
                            'logout_time_formatted' => null,
                            'status' => $status,
                            'late_minutes' => 0,
                            'notes' => null,
                        ];
                    }
                }

                $currentDate->modify('+1 day');
            }

            // Sort by date descending
            usort($history, function ($a, $b) {
                return strcmp($b['date'], $a['date']);
            });

            // Calculate total late minutes for the month
            $userShift = $shiftService->getUserShift($user->id);
            $shiftStart = $userShift['shift_start'] ?? '09:00:00';
            
            $lateMinutesQuery = "
                SELECT 
                    COALESCE(SUM(CASE 
                        WHEN login_time > ? AND login_time IS NOT NULL THEN 
                            TIMESTAMPDIFF(MINUTE, 
                                CONCAT(attendance_date, ' ', ?),
                                CONCAT(attendance_date, ' ', login_time)
                            )
                        ELSE 0 
                    END), 0) as total_late_minutes
                FROM t_ops_attendance
                WHERE user_id = ?
                AND attendance_date IS NOT NULL
                AND attendance_date BETWEEN ? AND ?
                AND login_time IS NOT NULL
                AND login_time != ''
            ";
            
            $lateResult = DB::selectOne($lateMinutesQuery, [
                $shiftStart, $shiftStart, // For late calculation
                $user->id, $startDate, $effectiveEndDate
            ]);
            
            $totalLateMinutes = $lateResult->total_late_minutes ?? 0;

            // Build summary: prefer salary service; otherwise compute a safe fallback
            if ($salaryData['success']) {
                $summary = [
                    'working_days' => $salaryData['working_days'],
                    'present_days' => $salaryData['present_days'],
                    'absent_days' => $salaryData['absent_days'],
                    'leave_days' => $salaryData['leave_days'],
                    'late_minutes' => $totalLateMinutes,
                ];
            } else {
                // Fallback path: no salary profile/config in production etc.
                // Compute working days using shift service and attendance + leave requests.
                $workingDays = $shiftService->calculateWorkingDays($user->id, $startDate, $effectiveEndDate);

                $presentDays = 0;
                foreach ($attendanceRecords as $rec) {
                    if (!empty($rec->login_time)) {
                        $presentDays++;
                    }
                }

                // Leave days from approved/pending leave requests overlapping the range
                $leaveDays = 0;
                $leaveRequests = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
                    ->whereIn('status', ['approved', 'pending'])
                    ->whereHas('category', function ($q) {
                        $q->where('category_code', 'leave');
                    })
                    ->where(function ($q) use ($startDate, $effectiveEndDate) {
                        $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
                          ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
                          ->orWhere(function ($q2) use ($startDate, $effectiveEndDate) {
                              $q2->where('leave_start_date', '<=', $startDate)
                                 ->where('leave_end_date', '>=', $effectiveEndDate);
                          });
                    })
                    ->get();

                foreach ($leaveRequests as $req) {
                    if ($req->leave_start_date && $req->leave_end_date) {
                        $d = new \DateTime($req->leave_start_date);
                        $dEnd = new \DateTime($req->leave_end_date);
                        while ($d <= $dEnd) {
                            $dateStr = $d->format('Y-m-d');
                            if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
                                $leaveDays++;
                            }
                            $d->modify('+1 day');
                        }
                    }
                }

                $summary = [
                    'working_days' => $workingDays,
                    'present_days' => $presentDays,
                    'absent_days' => max(0, $workingDays - $presentDays - $leaveDays),
                    'leave_days' => $leaveDays,
                    'late_minutes' => $totalLateMinutes,
                ];

                \Log::warning('Monthly attendance fallback used', [
                    'user_id' => $user->id,
                    'month' => $month,
                    'reason' => $salaryData['error'] ?? 'unknown'
                ]);
            }

            return response()->json([
                'success' => true,
                'month' => $month,
                'month_formatted' => date('F Y', strtotime($month)),
                'summary' => $summary,
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get monthly attendance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'message' => 'Failed to load attendance: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get request categories (filtered for riders: expense, salary_advance, leave)
     */
    public function getRequestCategories(Request $request)
    {
        try {
            // Get only categories relevant for riders
            $categories = \App\Models\Request\RequestCategoryModel::whereIn('category_code', ['expense', 'salary_advance', 'leave'])
                ->where('is_active', 1)
                ->orderBy('category_name')
                ->get()
                ->map(function($cat) {
                    return [
                        'id' => $cat->id,
                        'category_code' => $cat->category_code,
                        'category_name' => $cat->category_name,
                        'description' => $cat->description,
                        'icon' => $cat->icon,
                    ];
                });

            return response()->json([
                'success' => true,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get request categories', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to load categories: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get rider's requests
     */
    public function getRequests(Request $request)
    {
        try {
            $user = Auth::user();
            $status = $request->input('status', 'all'); // all, pending, approved, rejected

            $query = \App\Models\Request\RequestModel::with(['category'])
                ->where('requester_user_id', $user->id)
                ->whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance', 'leave']);
                })
                ->orderByDesc('created_at');

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $requests = $query->limit(100)->get()->map(function($req) {
                return [
                    'id' => $req->id,
                    'request_number' => $req->request_number,
                    'category' => [
                        'id' => $req->category->id,
                        'code' => $req->category->category_code,
                        'name' => $req->category->category_name,
                        'icon' => $req->category->icon,
                    ],
                    'title' => $req->title,
                    'description' => $req->description,
                    'amount' => $req->amount,
                    'amount_formatted' => $req->amount ? 'Rs. ' . number_format($req->amount, 0) : null,
                    'expense_category' => $req->expense_category,
                    'leave_start_date' => $req->leave_start_date,
                    'leave_end_date' => $req->leave_end_date,
                    'leave_type' => $req->leave_type,
                    'status' => $req->status,
                    'status_label' => ucfirst($req->status),
                    'priority' => $req->priority,
                    'created_at' => $req->created_at->format('Y-m-d H:i:s'),
                    'created_at_formatted' => $req->created_at->format('M d, Y h:i A'),
                ];
            });

            // ⭐ SMART SYNC: Clear sync flags for rider's fetched requests
            \DB::table('t_req_master')
                ->where('requester_user_id', $user->id)
                ->where('requester_sync_required', true)
                ->update([
                    'requester_sync_required' => false,
                    'requester_last_sync_at' => now()
                ]);

            return response()->json([
                'success' => true,
                'requests' => $requests,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get requests', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to load requests: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Create new request
     */
    public function createRequest(Request $request)
    {
        try {
            $user = Auth::user();

            $validated = $request->validate([
                'category_id' => 'required|exists:t_req_category,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'amount' => 'nullable|numeric|min:0',
                'expense_category' => 'nullable|string|max:255',
                'leave_start_date' => 'nullable|date',
                'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
                'leave_type' => 'nullable|string',
            ]);

            // Verify category is allowed for riders
            $category = \App\Models\Request\RequestCategoryModel::with('approvalConfig')
                ->whereIn('category_code', ['expense', 'salary_advance', 'leave'])
                ->findOrFail($validated['category_id']);

            DB::beginTransaction();

            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if (isset($validated['leave_start_date']) && isset($validated['leave_end_date'])) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            // Create request - SAME AS WEBAPP
            $newRequest = \App\Models\Request\RequestModel::create([
                'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(), // Use model method like webapp
                'category_id' => $category->id,
                'requester_user_id' => $user->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'expense_category' => $validated['expense_category'] ?? null,
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $validated['leave_type'] ?? null,
                'leave_days' => $leaveDays,
                'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
                'priority' => 'normal',
                'requires_level_1' => $category->requiresLevel1(),
                'requires_level_2' => $category->requiresLevel2(),
                'level_1_status' => $category->requiresLevel1() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,
                'submitted_at' => now(),
                'created_by' => $user->id,
            ]);

            DB::commit();

            \Log::info('Request created via mobile app', [
                'request_id' => $newRequest->id,
                'request_number' => $newRequest->request_number,
                'category' => $category->category_name,
                'user_id' => $user->id,
                'user_name' => $user->fullname,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request created successfully',
                'request' => [
                    'id' => $newRequest->id,
                    'request_number' => $newRequest->request_number,
                    'status' => $newRequest->status,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Failed to create request', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to create request: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get salary information for logged-in user
     * Returns: basic salary, loan balance, pending advances, salary slips
     */
    public function getSalaryInfo(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get employee profile
            $profile = EmployeeProfileModel::where('user_id', $user->id)->first();
            
            if (!$profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee profile not found',
                ], 404);
            }

            // Get basic salary information
            $basicSalary = [
                'base_salary' => (float) ($profile->base_salary ?? 0),
                'ot_rate_per_hour' => (float) ($profile->ot_rate_per_hour ?? 0),
                'employee_code' => $profile->employee_code,
                'designation' => $profile->designation,
                'department' => $profile->department,
                'joining_date' => $profile->joining_date,
            ];

            // Calculate total outstanding loans (reusing webapp logic)
            $activeLoans = EmployeeLoanModel::where('user_id', $user->id)
                ->where('loan_status', 'active')
                ->get();
            
            $totalLoanOutstanding = $activeLoans->sum('outstanding_balance');
            
            $loansData = $activeLoans->map(function($loan) {
                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'loan_type' => $loan->loan_type,
                    'principal_amount' => (float) $loan->principal_amount,
                    'monthly_installment' => (float) $loan->monthly_installment,
                    'outstanding_balance' => (float) $loan->outstanding_balance,
                    'loan_date' => $loan->loan_date,
                    'description' => $loan->description,
                ];
            });

            // Calculate unadjusted salary advances (reusing webapp logic)
            $pendingAdvances = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
                ->where('status', 'approved')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where(function($q) {
                    // Only include advances not yet settled
                    $q->whereNull('settlement_status')
                      ->orWhere('settlement_status', '!=', 'settled');
                })
                ->with('category')
                ->get();
            
            $totalPendingAdvances = $pendingAdvances->sum('amount');
            
            $advancesData = $pendingAdvances->map(function($advance) {
                return [
                    'id' => $advance->id,
                    'request_number' => $advance->request_number,
                    'amount' => (float) $advance->amount,
                    'title' => $advance->title,
                    'description' => $advance->description,
                    'submitted_at' => $advance->submitted_at,
                    'settlement_status' => $advance->settlement_status ?? 'pending',
                ];
            });

            // Get salary slips (reusing webapp logic)
            $salarySlips = SalarySlipModel::where('user_id', $user->id)
                ->orderBy('salary_month', 'desc')
                ->limit(12) // Last 12 months
                ->get();
            
            $slipsData = $salarySlips->map(function($slip) {
                return [
                    'id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'salary_month' => $slip->salary_month,
                    'salary_month_formatted' => date('F Y', strtotime($slip->salary_month . '-01')),
                    'gross_salary' => (float) $slip->gross_salary,
                    'total_deductions' => (float) $slip->total_deductions,
                    'net_salary' => (float) $slip->net_salary,
                    'slip_status' => $slip->slip_status,
                    'status_display' => ucfirst($slip->slip_status),
                    'status_color' => $this->getSlipStatusColor($slip->slip_status),
                    'has_manual_adjustments' => (bool) $slip->has_manual_adjustments,
                    'created_at' => $slip->created_at->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'basic_salary' => $basicSalary,
                    'loans' => [
                        'total_outstanding' => (float) $totalLoanOutstanding,
                        'active_loans_count' => $activeLoans->count(),
                        'loans' => $loansData,
                    ],
                    'advances' => [
                        'total_pending' => (float) $totalPendingAdvances,
                        'pending_count' => $pendingAdvances->count(),
                        'advances' => $advancesData,
                    ],
                    'salary_slips' => [
                        'total_count' => $salarySlips->count(),
                        'slips' => $slipsData,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get salary info', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load salary information: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed salary slip information
     * Reuses webapp's getDetailedBreakdown() method
     */
    public function getSalarySlipDetails(Request $request, $slipId)
    {
        try {
            $user = Auth::user();
            
            // Find slip and verify it belongs to the user
            $slip = SalarySlipModel::where('id', $slipId)
                ->where('user_id', $user->id)
                ->with(['employee', 'approver', 'creator'])
                ->first();
            
            if (!$slip) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salary slip not found or you do not have permission to view it',
                ], 404);
            }

            // ✅ Reuse webapp's getDetailedBreakdown() method
            $detailedBreakdown = $slip->getDetailedBreakdown();

            return response()->json([
                'success' => true,
                'slip' => $detailedBreakdown,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get salary slip details', [
                'slip_id' => $slipId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load salary slip details: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Helper: Get status color for salary slip
     */
    private function getSlipStatusColor($status)
    {
        switch ($status) {
            case 'draft':
                return '#9CA3AF'; // Gray
            case 'approved':
                return '#10B981'; // Green
            case 'paid':
                return '#3B82F6'; // Blue
            case 'cancelled':
                return '#EF4444'; // Red
            default:
                return '#6B7280'; // Default gray
        }
    }

    /**
     * Get mobile app permissions for the authenticated user
     * Returns array of permission codes the user has access to
     */
    public function getMobilePermissions(Request $request)
    {
        try {
            // Load user with roles and their mobile permissions
            $user = Auth::user()->load(['roles.mobilePermissions']);
            
            // Get all mobile permissions for this user
            $permissions = $user->getMobilePermissions();
            
            \Log::info('Mobile permissions fetched', [
                'user_id' => $user->id,
                'user_name' => $user->fullname,
                'roles_count' => $user->roles->count(),
                'permissions' => $permissions,
                'has_store_mode' => in_array('access_store_mode', $permissions)
            ]);
            
            return response()->json([
                'success' => true,
                'permissions' => $permissions,
                'has_store_mode' => in_array('access_store_mode', $permissions)
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch mobile permissions', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch permissions',
                'permissions' => [],
                'has_store_mode' => false
            ], 500);
        }
    }
    
    /**
     * Get active users for creating requests on behalf of others
     * Only accessible to users with store mode permission
     * Matches web implementation - excludes current user
     */
    public function getActiveUsers(Request $request)
    {
        try {
            $users = \DB::table('t_sys_user')
                ->where('is_active', 1)
                ->whereNotIn('id', [Auth::id()]) // Exclude current user
                ->orderBy('fullname')
                ->select('id', 'fullname', 'email')
                ->get();
            
            return response()->json([
                'success' => true,
                'users' => $users
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch active users', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'users' => []
            ], 500);
        }
    }

    /**
     * STORE MODE: Get available order statuses
     */
    public function getOrderStatuses(Request $request)
    {
        try {
            $statuses = DB::table('t_crm_order_status_master')
                ->where('is_active', 1)
                ->orderBy('sequence_order')
                ->get(['status_code', 'status_name', 'icon', 'color_class']);
            
            return response()->json([
                'success' => true,
                'statuses' => $statuses
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order statuses', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statuses',
                'statuses' => []
            ], 500);
        }
    }

    /**
     * STORE MODE: Get open orders
     * Reuses webapp logic from OrderController::index
     */
    public function getStoreOpenOrders(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view open orders'
                ], 403);
            }
            
            // Get status filter if provided
            $statusFilter = $request->get('status', null);
            
            // Build query - same logic as webapp OrderController::index with tab='open'
            $query = OrderModel::with(['customer', 'lineItems', 'assignedRider', 'discounts'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
            
            // Apply status filter if provided
            if ($statusFilter) {
                $query->where('order_status', $statusFilter);
            }
            
            // Order by date
            $orders = $query->orderBy('order_date', 'desc')->get();
            
            // Format for mobile
            $formattedOrders = $orders->map(function($order) {
            // Build customer name (same logic as webapp)
            $customerName = $order->name ?? 'N/A';
            if (!$order->name && ($order->address_first_name || $order->address_last_name)) {
                $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
            }
            if ($customerName === 'N/A' && $order->customer) {
                // Customer table has first_name and last_name, not name
                $customerName = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? '')) ?: 'Unknown';
            }
                
                // Check if customer has verified location
                $hasVerifiedLocation = false;
                if ($order->customer) {
                    $hasVerifiedLocation = !empty($order->customer->latitude) && !empty($order->customer->longitude) 
                                        || !empty($order->customer->verified_location_url);
                }
                
                // Generate invoice URLs
                $invoiceImageUrl = route('orders.invoice.pdf', ['id' => $order->id, 'download_image' => 1]);
                $invoicePdfUrl = route('orders.invoice.pdf', ['id' => $order->id, 'force_pdf' => 1]);
                
                // Calculate preparation summary
                $totalItems = $order->lineItems->count();
                $preparingCount = $order->lineItems->where('preparation_status', 'preparing')->count();
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'payment_method' => $order->payment_method,
                    'expected_packets' => $order->expected_packets,
                    'customer_name' => $customerName,
                    'customer_phone' => $order->customer->phone_original ?? $order->customer->phone ?? $order->address_phone ?? '',
                    'customer_address' => $order->customer->address1 ?? $order->address_address ?? '',
                    'customer_address1' => $order->customer->address1 ?? '',
                    'customer_address2' => $order->customer->address2 ?? '',
                    'customer_city' => $order->customer->city ?? '',
                    'customer_province' => $order->customer->province ?? '',
                    'customer_postal_code' => $order->customer->postal_code ?? '',
                    'customer_id' => $order->customer_id,
                    'has_verified_location' => $hasVerifiedLocation,
                    'assigned_rider' => $order->assignedRider ? [
                        'id' => $order->assignedRider->id,
                        'name' => $order->assignedRider->fullname,
                    ] : null,
                    'items_count' => $totalItems,
                    'items_summary' => $order->lineItems->map(function($item) {
                        return $item->name . ' (x' . $item->quantity . ')';
                    })->join(', '),
                    'line_items' => $order->lineItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'unit_price_formatted' => 'Rs. ' . number_format($item->unit_price, 0),
                            'line_total' => $item->line_total, // Add line_total for invoice calculations
                            'total' => $item->line_total,
                            'total_formatted' => 'Rs. ' . number_format($item->line_total, 0),
                            'preparation_status' => $item->preparation_status,
                        ];
                    }),
                    'shipping_total' => $order->shipping_total ?? 0,
                    'tip_amount' => $order->tip_amount ?? 0,
                    'discounts' => $order->discounts ? $order->discounts->map(function($discount) {
                        return [
                            'discount_amount' => $discount->discount_amount,
                            'discount_type' => $discount->discount_type,
                        ];
                    })->toArray() : [],
                    'preparation_summary' => [
                        'preparing_count' => $preparingCount,
                        'total_items' => $totalItems,
                    ],
                    'invoice' => [
                        'image_url' => $invoiceImageUrl,  // URL to download invoice as PNG image
                        'pdf_url' => $invoicePdfUrl,      // URL to download invoice as PDF
                    ],
                ];
            });
            
            return response()->json([
                'success' => true,
                'orders' => $formattedOrders,
                'total_count' => $formattedOrders->count()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch store open orders', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch open orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Get open orders (lightweight for list view)
     * Returns minimal data for fast loading, use getStoreOpenOrderDetails for full data
     */
    public function getStoreOpenOrdersLight(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view open orders'
                ], 403);
            }
            
            // Get status filter if provided
            $statusFilter = $request->get('status', null);
            
            // Build optimized query - load line items for immediate "mark prepared" functionality
            // Note: Not using select() to avoid column name issues - optimization is in relationships
            $query = OrderModel::with(['customer' => function($q) {
                    $q->select('id', 'first_name', 'last_name', 'latitude', 'longitude', 'verified_location_url');
                }])
                ->with(['assignedRider' => function($q) {
                    $q->select('id', 'fullname');
                }])
                ->with(['lineItems' => function($q) {
                    // Load essential line item fields for marking prepared
                    $q->select('id', 'order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'preparation_status');
                }])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);
            
            // Apply status filter if provided
            if ($statusFilter) {
                $query->where('order_status', $statusFilter);
            }
            
            // Order by date
            $orders = $query->orderBy('order_date', 'desc')->get();
            
            // Get preparation summaries in single query (avoid N+1)
            $prepSummaries = \DB::table('t_crm_prod_order_line_item')
                ->whereIn('order_id', $orders->pluck('id'))
                ->groupBy('order_id')
                ->selectRaw('order_id, COUNT(*) as total, SUM(CASE WHEN preparation_status = "preparing" THEN 1 ELSE 0 END) as preparing')
                ->get()
                ->keyBy('order_id');
            
            // Format for mobile (lightweight)
            $formattedOrders = $orders->map(function($order) use ($prepSummaries) {
                // Build customer name
                $customerName = $order->name ?? 'N/A';
                if (!$order->name && ($order->address_first_name || $order->address_last_name)) {
                    $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
                }
                if ($customerName === 'N/A' && $order->customer) {
                    // Customer table has first_name and last_name, not name
                    $customerName = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? '')) ?: 'Unknown';
                }
                
                // Check verified location
                $hasVerifiedLocation = false;
                if ($order->customer) {
                    $hasVerifiedLocation = !empty($order->customer->latitude) && !empty($order->customer->longitude) 
                                        || !empty($order->customer->verified_location_url);
                }
                
                // Get preparation summary from pre-fetched data
                $prepSummary = $prepSummaries[$order->id] ?? null;
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'customer_id' => $order->customer_id, // Added for verified location functionality
                    'customer_name' => $customerName,
                    // Eager address/phone for immediate display on list
                    'customer_address' => trim(implode(', ', array_filter([
                        $order->address_line1,
                        $order->address_line2,
                        $order->address_city,
                        $order->address_province
                    ]))),
                    'customer_phone' => $order->address_phone ?? ($order->customer->phone_original ?? null),
                    'assigned_rider_id' => $order->assigned_rider_user_id,
                    'assigned_rider' => $order->assignedRider ? [
                        'id' => $order->assignedRider->id,
                        'name' => $order->assignedRider->fullname,
                    ] : null,
                    'preparation_summary' => [
                        'preparing_count' => $prepSummary->preparing ?? 0,
                        'total_items' => $prepSummary->total ?? 0,
                    ],
                    'has_verified_location' => $hasVerifiedLocation,
                    'external_source' => $order->external_source,
                    'line_items' => $order->lineItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'line_total' => $item->line_total,
                            'preparation_status' => $item->preparation_status,
                        ];
                    }),
                ];
            });
            
            return response()->json([
                'success' => true,
                'orders' => $formattedOrders,
                'total_count' => $formattedOrders->count()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch store open orders (light)', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch open orders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Get full order details (for expanded view)
     */
    public function getStoreOpenOrderDetails($orderId)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view open orders'
                ], 403);
            }
            
            // Get full order with all relationships
            $order = OrderModel::with(['customer', 'lineItems', 'assignedRider', 'discounts'])
                ->findOrFail($orderId);
            
            // Build customer name
            $customerName = $order->name ?? 'N/A';
            if (!$order->name && ($order->address_first_name || $order->address_last_name)) {
                $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
            }
            if ($customerName === 'N/A' && $order->customer) {
                $customerName = $order->customer->name ?? 'Unknown';
            }
            
            // Check verified location
            $hasVerifiedLocation = false;
            if ($order->customer) {
                $hasVerifiedLocation = !empty($order->customer->latitude) && !empty($order->customer->longitude) 
                                    || !empty($order->customer->verified_location_url);
            }
            
            // Generate invoice URLs
            $invoiceImageUrl = route('orders.invoice.pdf', ['id' => $order->id, 'download_image' => 1]);
            $invoicePdfUrl = route('orders.invoice.pdf', ['id' => $order->id, 'force_pdf' => 1]);
            
            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'payment_method' => $order->payment_method,
                    'expected_packets' => $order->expected_packets,
                    'customer_name' => $customerName,
                    'customer_phone' => $order->customer->phone_original ?? $order->customer->phone ?? $order->address_phone ?? '',
                    'customer_address' => $order->customer->address1 ?? $order->address_address ?? '',
                    'customer_address1' => $order->customer->address1 ?? '',
                    'customer_address2' => $order->customer->address2 ?? '',
                    'customer_city' => $order->customer->city ?? '',
                    'customer_province' => $order->customer->province ?? '',
                    'customer_postal_code' => $order->customer->postal_code ?? '',
                    'customer_id' => $order->customer_id,
                    'has_verified_location' => $hasVerifiedLocation,
                    'items_count' => $order->lineItems->count(),
                    'items_summary' => $order->lineItems->map(function($item) {
                        return $item->name . ' (x' . $item->quantity . ')';
                    })->join(', '),
                    'line_items' => $order->lineItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'unit_price_formatted' => 'Rs. ' . number_format($item->unit_price, 0),
                            'line_total' => $item->line_total,
                            'total' => $item->line_total,
                            'total_formatted' => 'Rs. ' . number_format($item->line_total, 0),
                            'preparation_status' => $item->preparation_status,
                        ];
                    }),
                    'shipping_total' => $order->shipping_total ?? 0,
                    'tip_amount' => $order->tip_amount ?? 0,
                    'discounts' => $order->discounts ? $order->discounts->map(function($discount) {
                        return [
                            'discount_amount' => $discount->discount_amount,
                            'discount_type' => $discount->discount_type,
                        ];
                    })->toArray() : [],
                    'invoice' => [
                        'image_url' => $invoiceImageUrl,
                        'pdf_url' => $invoicePdfUrl,
                    ],
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order details', [
                'user_id' => Auth::id(),
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Get active riders for assignment
     * Reuses webapp logic from RiderController::active
     */
    public function getActiveRiders(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('assign_riders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to assign riders'
                ], 403);
            }
            
            // Same query as webapp CRM\RiderController::active
            $riders = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
                ->where(function ($q) {
                    $q->whereNull('p.user_id')->orWhere('p.active', 1);
                })
                ->where('u.is_active', 1)
                ->orderBy('u.fullname')
                ->get([
                    'u.id',
                    'u.fullname',
                ]);
            
            return response()->json([
                'success' => true,
                'riders' => $riders
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch active riders', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch riders: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Assign rider to order
     */
    public function assignRiderToOrder(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('assign_riders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to assign riders'
                ], 403);
            }
            
            $validated = $request->validate([
                'order_id' => 'required|exists:t_crm_prod_order,id',
                'rider_id' => 'required|exists:t_sys_user,id'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            $order->assigned_rider_user_id = $validated['rider_id'];
            $order->save();
            
            // Get rider name for response
            $rider = DB::table('t_sys_user')->where('id', $validated['rider_id'])->first();
            
            \Log::info('Rider assigned to order (Store Mode)', [
                'order_id' => $order->id,
                'rider_id' => $validated['rider_id'],
                'assigned_by' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Rider assigned successfully',
                'assigned_rider' => [
                    'id' => $rider->id,
                    'name' => $rider->fullname
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to assign rider', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign rider: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Update order status
     */
    public function updateOrderStatus(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('change_order_status')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to change order status'
                ], 403);
            }
            
            $validated = $request->validate([
                'order_id' => 'required|exists:t_crm_prod_order,id',
                'status' => 'required|string|exists:t_crm_order_status_master,status_code'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            $oldStatus = $order->order_status;
            
            // Use the same method as webapp (OrderModel::changeStatus)
            $success = $order->changeStatus(
                $validated['status'],
                'Status changed via Store Mode',
                $user->id
            );
            
            if (!$success) {
                throw new \Exception('Failed to change order status');
            }
            
            \Log::info('Order status updated (Store Mode)', [
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'updated_by' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order status updated successfully',
                'new_status' => $validated['status']
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to update order status', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Update packet information
     */
    public function updatePacketInfo(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('enter_packet_info')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to enter packet information'
                ], 403);
            }
            
            $validated = $request->validate([
                'order_id' => 'required|exists:t_crm_prod_order,id',
                'expected_packets' => 'required|integer|min:0'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            
            // Don't allow editing if already delivered
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot edit packet information for delivered orders'
                ], 422);
            }
            
            $order->expected_packets = $validated['expected_packets'];
            $order->save();
            
            \Log::info('Packet info updated (Store Mode)', [
                'order_id' => $order->id,
                'expected_packets' => $validated['expected_packets'],
                'updated_by' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Packet information updated successfully',
                'expected_packets' => $validated['expected_packets']
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to update packet info', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update packet information: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Get open order quantities with drill-down
     * Reuses webapp logic from OrderController::openQuantitiesData
     */
    public function getOpenOrderQuantitiesTree(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->hasMobilePermission('view_open_quantities')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view open order quantities'
                ], 403);
            }

            $statusFilter = $request->get('status_filter');
            if ($statusFilter === 'all') {
                $statusFilter = null;
            }

            // Allow explicit hierarchy override (used by mobile fixed-endpoint),
            // otherwise fall back to the dynamic settings used by the web app.
            $overrideHierarchy = $request->get('hierarchy_override');
            if (is_array($overrideHierarchy) && !empty($overrideHierarchy)) {
                $hierarchy = $overrideHierarchy;
            } else {
                $hierarchySetting = DB::table('t_crm_open_quantities_settings')
                    ->where('setting_key', 'hierarchy_levels')
                    ->first();
                $hierarchy = $hierarchySetting ? json_decode($hierarchySetting->setting_value, true) : ['product_type', 'product_name', 'orders'];
                if (!is_array($hierarchy) || empty($hierarchy)) {
                    $hierarchy = ['product_type', 'product_name', 'orders'];
                }
            }
            if (!in_array('orders', $hierarchy, true)) {
                $hierarchy[] = 'orders';
            }

            $statusSetting = DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'excluded_statuses')
                ->first();
            $excludedStatuses = $statusSetting ? json_decode($statusSetting->setting_value, true) : ['delivered', 'completed', 'cancelled', 'refunded'];
            if (!is_array($excludedStatuses) || empty($excludedStatuses)) {
                $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            }

            $query = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
                    $join->where(function ($q) {
                        $q->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.variant_id', 'pv.id')
                          ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.product_id', 'pv.id');
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function ($join) {
                    $join->where(function ($q) {
                        $q->whereColumn('pv.product_id', 'p.id')
                          ->orWhereColumn('li.product_id', 'p.id');
                    })->orWhereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                })
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function ($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where('o.order_date', '>=', Carbon::now()->subDays(20));

            if ($statusFilter) {
                $query->where('o.order_status', $statusFilter);
            }

            $rows = $query->select([
                    'o.id as order_id',
                    'o.order_number',
                    'o.order_status',
                    'o.order_date',
                    DB::raw("COALESCE(
                        NULLIF(TRIM(o.name), ''),
                        NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''),
                        TRIM(CONCAT(COALESCE(o.address_first_name, ''), ' ', COALESCE(o.address_last_name, '')))
                    ) as customer_name"),
                    'li.id as line_item_id',
                    'li.quantity as line_item_quantity',
                    'li.preparation_status as line_item_status',
                    'li.product_id as line_item_product_id',
                    'li.variant_id as line_item_variant_id',
                    'li.name as line_item_name',
                    'p.id as product_id',
                    'p.title as product_title',
                    'p.product_type',
                    'p.attribute_1',
                    'p.attribute_2',
                    'p.attribute_3',
                    DB::raw('COALESCE(p.is_lean, 0) as is_lean')
                ])
                ->orderBy('o.order_date', 'desc')
                ->get();

            $totalQuantity = 0.0;
            $totalLineItems = 0;
            $uniqueOrderIds = [];
            $orderStatusesByOrder = [];

            $tree = [];
            $rootMap = [];
            
            // Debug: Log hierarchy being used
            Log::debug('Open Quantities Tree - Starting build', [
                'hierarchy' => $hierarchy,
                'total_rows' => count($rows),
                'first_row_sample' => $rows->isNotEmpty() ? [
                    'attribute_1' => $rows[0]->attribute_1 ?? 'NULL',
                    'attribute_2' => $rows[0]->attribute_2 ?? 'NULL',
                    'attribute_3' => $rows[0]->attribute_3 ?? 'NULL',
                    'product_title' => $rows[0]->product_title ?? 'NULL',
                ] : 'NO ROWS',
            ]);

            $addMetrics = function (&$node, $row) {
                $qty = (float) ($row->line_item_quantity ?? 0);
                $isLean = (int) ($row->is_lean ?? 0) === 1;
                $orderStatus = strtolower((string) ($row->order_status ?? ''));
                $lineStatus = strtolower((string) ($row->line_item_status ?? ''));

                $node['quantity'] += $qty;
                if ($isLean) {
                    $node['lean_quantity'] += $qty;
                } else {
                    $node['non_lean_quantity'] += $qty;
                }
                if ($orderStatus === 'processing') {
                    $node['processing_quantity'] += $qty;
                }
                if ($lineStatus === 'preparing') {
                    $node['prepared_quantity'] += $qty;
                }
            };

            foreach ($rows as $row) {
                $qty = (float) ($row->line_item_quantity ?? 0);
                $totalQuantity += $qty;
                $totalLineItems++;
                $uniqueOrderIds[$row->order_id] = true;

                if (!isset($orderStatusesByOrder[$row->order_id])) {
                    $orderStatusesByOrder[$row->order_id] = $row->order_status ?? 'new';
                }

                $currentList =& $tree;
                $currentMap =& $rootMap;
                $currentFilters = [];

                foreach ($hierarchy as $levelIndex => $field) {
                    // ALWAYS log to debug the loop
                    static $loopCount = 0;
                    if ($loopCount < 20) {
                        Log::debug("🔄 Tree loop", [
                            'loop_count' => $loopCount,
                            'row_id' => $row->line_item_id,
                            'order_id' => $row->order_id,
                            'levelIndex' => $levelIndex,
                            'field' => $field,
                            'field_value' => $row->{$field} ?? 'NULL',
                        ]);
                        $loopCount++;
                    }
                    
                    if ($field === 'orders') {
                        // CRITICAL FIX: Order nodes must be unique per product
                        // Use a composite key: product_name + order_id
                        $productContext = $currentFilters['product_name'] ?? 'unknown';
                        $orderKey = $productContext . '_' . $row->order_id;
                        
                        if (!isset($currentMap[$orderKey])) {
                            $orderLabel = $row->order_number ? 'Order #' . $row->order_number : 'Order ' . $row->order_id;
                            if (!empty($row->customer_name)) {
                                $orderLabel .= ' - ' . $row->customer_name;
                            }
                            
                            // DEBUG: Log what filters we're under when creating order node
                            static $orderDebugCount = 0;
                            if ($orderDebugCount < 2) {
                                Log::debug("Creating order node", [
                                    'order_id' => $row->order_id,
                                    'order_number' => $row->order_number,
                                    'current_filters' => $currentFilters,
                                    'product_name_in_row' => $row->line_item_name ?? $row->product_title,
                                ]);
                                $orderDebugCount++;
                            }

                            $orderNode = [
                                'name' => $orderLabel,
                                'field' => 'orders',
                                'level' => $levelIndex,
                                'quantity' => 0,
                                'lean_quantity' => 0,
                                'non_lean_quantity' => 0,
                                'processing_quantity' => 0,
                                'prepared_quantity' => 0,
                                'order_count' => 0,
                                'product_count' => 0,
                                'order_id' => $row->order_id,
                                'order_number' => $row->order_number,
                                'status' => $row->order_status,
                                'order_date' => $row->order_date ? Carbon::parse($row->order_date)->toIso8601String() : null,
                                'customer_name' => $row->customer_name,
                                'filters' => array_merge($currentFilters, ['order_id' => $row->order_id]),
                                'children' => [],
                                '_children_map' => [],
                                '_order_ids' => [$row->order_id => true],
                                '_product_ids' => [],
                            ];

                            $currentList[] = $orderNode;
                            $currentMap[$orderKey] = &$currentList[count($currentList) - 1];
                        }

                        // CRITICAL FIX: Don't use reference here, update the array directly
                        // Get current quantity
                        $qtyBefore = $currentMap[$orderKey]['quantity'];
                        
                        // Add metrics directly to the map entry
                        $qty = (float) ($row->line_item_quantity ?? 0);
                        $isLean = (int) ($row->is_lean ?? 0) === 1;
                        $orderStatus = strtolower((string) ($row->order_status ?? ''));
                        $lineStatus = strtolower((string) ($row->line_item_status ?? ''));
                        
                        $currentMap[$orderKey]['quantity'] += $qty;
                        if ($isLean) {
                            $currentMap[$orderKey]['lean_quantity'] += $qty;
                        } else {
                            $currentMap[$orderKey]['non_lean_quantity'] += $qty;
                        }
                        if ($orderStatus === 'processing') {
                            $currentMap[$orderKey]['processing_quantity'] += $qty;
                        }
                        if ($lineStatus === 'preparing') {
                            $currentMap[$orderKey]['prepared_quantity'] += $qty;
                        }
                        
                        // Now update the actual array in $currentList
                        foreach ($currentList as $idx => &$listItem) {
                            if (isset($listItem['order_id']) && $listItem['order_id'] == $row->order_id) {
                                $listItem['quantity'] = $currentMap[$orderKey]['quantity'];
                                $listItem['lean_quantity'] = $currentMap[$orderKey]['lean_quantity'];
                                $listItem['non_lean_quantity'] = $currentMap[$orderKey]['non_lean_quantity'];
                                $listItem['processing_quantity'] = $currentMap[$orderKey]['processing_quantity'];
                                $listItem['prepared_quantity'] = $currentMap[$orderKey]['prepared_quantity'];
                                break;
                            }
                        }
                        unset($listItem);
                        
                        // Log successful update (only first few for debugging)
                        static $updateLogCount = 0;
                        if ($updateLogCount < 3) {
                            Log::debug("✅ Order quantity updated", [
                                'order_id' => $row->order_id,
                                'product' => $productContext,
                                'qty_before' => $qtyBefore,
                                'qty_added' => $qty,
                                'qty_after' => $currentMap[$orderKey]['quantity'],
                            ]);
                            $updateLogCount++;
                        }

                        if ($row->line_item_product_id) {
                            $currentMap[$orderKey]['_product_ids'][(int) $row->line_item_product_id] = true;
                        }
                        if ($row->product_id) {
                            $currentMap[$orderKey]['_product_ids'][(int) $row->product_id] = true;
                        }

                        break;
                    }

                    if ($field === 'orders') {
                        continue;
                    }

                    if ($field === 'product_name') {
                        $label = $row->line_item_name ?: ($row->product_title ?: 'Uncategorized');
                    } else {
                        $value = $row->{$field} ?? null;
                        // CRITICAL: Always treat NULL or empty string as "Uncategorized" to ensure tree continues building
                        $label = ($value !== null && trim((string)$value) !== '') ? $value : 'Uncategorized';
                    }

                    $nodeKey = $label;

                    if (!isset($currentMap[$nodeKey])) {
                        $nodeFilters = array_merge($currentFilters, [$field => $label]);

                        $newNode = [
                            'name' => $label,
                            'field' => $field,
                            'level' => $levelIndex,
                            'quantity' => 0,
                            'lean_quantity' => 0,
                            'non_lean_quantity' => 0,
                            'processing_quantity' => 0,
                            'prepared_quantity' => 0,
                            'order_count' => 0,
                            'product_count' => 0,
                            'filters' => $nodeFilters,
                            'children' => [],
                            '_children_map' => [],
                            '_order_ids' => [],
                            '_product_ids' => [],
                        ];

                        if ($field === 'product_name') {
                            $newNode['product_ids'] = [];
                        }

                        // Add to current list
                        $currentList[] = $newNode;
                        // Store a REFERENCE to the node in the map
                        $currentMap[$nodeKey] =& $currentList[count($currentList) - 1];
                    }

                    // Get reference to the node (BEFORE we change $currentList)
                    $node =& $currentMap[$nodeKey];
                    $addMetrics($node, $row);

                    $node['_order_ids'][$row->order_id] = true;
                    if ($row->line_item_product_id) {
                        $node['_product_ids'][(int) $row->line_item_product_id] = true;
                    }
                    if ($row->product_id) {
                        $node['_product_ids'][(int) $row->product_id] = true;
                    }

                    if ($field === 'product_name') {
                        if (!isset($node['product_ids'])) {
                            $node['product_ids'] = [];
                        }
                        if ($row->line_item_product_id) {
                            $node['product_ids'][(int) $row->line_item_product_id] = true;
                        }
                        if ($row->product_id) {
                            $node['product_ids'][(int) $row->product_id] = true;
                        }

                        $node['filters']['product_name'] = $label;
                        if (!empty($node['product_ids'])) {
                            $node['filters']['product_ids'] = implode(',', array_keys($node['product_ids']));
                        }
                    }

                    // CRITICAL FIX: Navigate to next level
                    // We MUST get references to the node's children BEFORE reassigning $currentList
                    $nextChildren =& $node['children'];
                    $nextMap =& $node['_children_map'];
                    $currentFilters = $node['filters'];
                    
                    // Now it's safe to reassign these variables
                    $currentList =& $nextChildren;
                    $currentMap =& $nextMap;
                }
            }

            $finalizeNode = function (&$node) use (&$finalizeNode) {
                // DEBUG: Track if this is an order node and log quantity before/after
                $isOrderNode = ($node['field'] ?? '') === 'orders';
                $qtyBefore = $node['quantity'] ?? 'N/A';
                
                // DEBUG: Log node details BEFORE any modifications
                static $debugFinalizeCount = 0;
                if ($debugFinalizeCount < 5 && $isOrderNode) {
                    Log::debug("🔍 finalizeNode START", [
                        'order_id' => $node['order_id'] ?? 'N/A',
                        'quantity_at_start' => $node['quantity'],
                        'has_children' => !empty($node['children']),
                        'children_count' => count($node['children'] ?? []),
                    ]);
                    $debugFinalizeCount++;
                }
                
                $node['order_count'] = count($node['_order_ids']);
                $node['product_count'] = count(array_filter(array_keys($node['_product_ids'])));

                unset($node['_order_ids'], $node['_product_ids'], $node['_children_map']);

                if (isset($node['product_ids']) && is_array($node['product_ids'])) {
                    $productIds = array_keys($node['product_ids']);
                    sort($productIds);
                    $node['product_ids'] = $productIds;
                }

                if (!empty($node['children'])) {
                    // Finalize children first (to calculate their quantities)
                    foreach ($node['children'] as &$child) {
                        $finalizeNode($child);
                    }
                    unset($child); // Break the reference to avoid issues
                    
                    // REMOVED: usort breaks PHP references!
                    // When we store references like: $currentMap[$key] =& $currentList[...]
                    // usort() re-indexes the array and breaks those references
                    // This was causing order node quantities to reset to 0
                    // 
                    // usort($node['children'], function ($a, $b) {
                    //     return $b['quantity'] <=> $a['quantity'];
                    // });
                }
                
                // DEBUG: Log if order node quantity changed
                if ($isOrderNode) {
                    Log::debug('🔧 finalizeNode on order node', [
                        'order_id' => $node['order_id'] ?? 'N/A',
                        'order_name' => $node['name'] ?? 'N/A',
                        'quantity_before' => $qtyBefore,
                        'quantity_after' => $node['quantity'] ?? 'N/A',
                        'has_children' => !empty($node['children']),
                    ]);
                }
            };

            // DEBUG: Log order nodes BEFORE finalization - check multiple products
            $orderCheckCount = 0;
            foreach ($tree as $rootNode) {
                if (!empty($rootNode['children']) && $orderCheckCount < 5) {
                    foreach ($rootNode['children'] as $child1) {
                        if (!empty($child1['children']) && $orderCheckCount < 5) {
                            foreach ($child1['children'] as $child2) {
                                if (!empty($child2['children']) && $orderCheckCount < 5) {
                                    foreach ($child2['children'] as $child3) {
                                        if (($child3['field'] ?? '') === 'product_name' && !empty($child3['children']) && $orderCheckCount < 5) {
                                            // Check ALL orders in this product, not just first
                                            foreach ($child3['children'] as $orderNode) {
                                                if (($orderNode['field'] ?? '') === 'orders' && $orderCheckCount < 5) {
                                                    Log::debug("🔎 Order BEFORE finalize", [
                                                        'product' => $child3['name'],
                                                        'order_id' => $orderNode['order_id'] ?? 'N/A',
                                                        'order_name' => $orderNode['name'] ?? 'N/A',
                                                        'quantity' => $orderNode['quantity'] ?? 'N/A',
                                                        'check_num' => $orderCheckCount,
                                                    ]);
                                                    $orderCheckCount++;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            foreach ($tree as &$rootNode) {
                $finalizeNode($rootNode);
            }
            unset($rootNode);

            usort($tree, function ($a, $b) {
                return $b['quantity'] <=> $a['quantity'];
            });
            
            // Debug: Log tree structure after primary builder
            $treeStats = [
                'root_count' => count($tree),
                'root_nodes' => [],
            ];
            foreach ($tree as $idx => $node) {
                $treeStats['root_nodes'][] = [
                    'name' => $node['name'],
                    'field' => $node['field'],
                    'level' => $node['level'],
                    'quantity' => $node['quantity'],
                    'children_count' => count($node['children'] ?? []),
                ];
                if ($idx >= 2) break; // Only log first 3 nodes
            }
            Log::debug('Open Quantities Tree - After primary builder', $treeStats);

            // DISABLED: Fallback logic - the primary tree builder handles all hierarchies correctly
            // including NULL values as "Uncategorized" and builds the full depth.
            $needsFallback = false;

            if ($needsFallback) {
                try {
                    $field0 = $hierarchy[0] ?? 'attribute_1';
                    $field1 = $hierarchy[1] ?? null;
                    $labelFor = function ($row, $field) {
                        if ($field === 'product_name') {
                            return $row->line_item_name ?: ($row->product_title ?: 'Uncategorized');
                        }
                        $value = $row->{$field} ?? null;
                        return ($value !== null && $value !== '') ? $value : 'Uncategorized';
                    };
                    $addMetrics = function (&$node, $row) {
                        $qty = (float) ($row->line_item_quantity ?? 0);
                        $isLean = (int) ($row->is_lean ?? 0) === 1;
                        $orderStatus = strtolower((string) ($row->order_status ?? ''));
                        $lineStatus = strtolower((string) ($row->line_item_status ?? ''));
                        $node['quantity'] += $qty;
                        if ($isLean) { $node['lean_quantity'] += $qty; } else { $node['non_lean_quantity'] += $qty; }
                        if ($orderStatus === 'processing') { $node['processing_quantity'] += $qty; }
                        if ($lineStatus === 'preparing') { $node['prepared_quantity'] += $qty; }
                    };

                    $groups0 = [];
                    foreach ($rows as $row) {
                        $key0 = $labelFor($row, $field0);
                        if (!isset($groups0[$key0])) { $groups0[$key0] = []; }
                        $groups0[$key0][] = $row;
                    }

                    $rebuilt = [];
                    foreach ($groups0 as $label0 => $rows0) {
                        $node0 = [
                            'name' => $label0,
                            'field' => $field0,
                            'level' => 0,
                            'quantity' => 0,
                            'lean_quantity' => 0,
                            'non_lean_quantity' => 0,
                            'processing_quantity' => 0,
                            'prepared_quantity' => 0,
                            'order_count' => 0,
                            'product_count' => 0,
                            'filters' => [$field0 => $label0],
                            'children' => [],
                        ];
                        $orderIds = [];
                        $productIds = [];
                        foreach ($rows0 as $r0) {
                            $addMetrics($node0, $r0);
                            $orderIds[$r0->order_id] = true;
                            if ($r0->line_item_product_id) { $productIds[(int)$r0->line_item_product_id] = true; }
                            if ($r0->product_id) { $productIds[(int)$r0->product_id] = true; }
                        }
                        $node0['order_count'] = count($orderIds);
                        $node0['product_count'] = count(array_filter(array_keys($productIds)));

                        // Optional level 1 (attribute_2)
                        if ($field1) {
                            $groups1 = [];
                            foreach ($rows0 as $row1) {
                                $key1 = $labelFor($row1, $field1);
                                if (!isset($groups1[$key1])) { $groups1[$key1] = []; }
                                $groups1[$key1][] = $row1;
                            }
                            foreach ($groups1 as $label1 => $rows1) {
                                $child = [
                                    'name' => $label1,
                                    'field' => $field1,
                                    'level' => 1,
                                    'quantity' => 0,
                                    'lean_quantity' => 0,
                                    'non_lean_quantity' => 0,
                                    'processing_quantity' => 0,
                                    'prepared_quantity' => 0,
                                    'order_count' => 0,
                                    'product_count' => 0,
                                    'filters' => [$field0 => $label0, $field1 => $label1],
                                    'children' => [],
                                ];
                                $oIds = []; $pIds = [];
                                foreach ($rows1 as $r1) {
                                    $addMetrics($child, $r1);
                                    $oIds[$r1->order_id] = true;
                                    if ($r1->line_item_product_id) { $pIds[(int)$r1->line_item_product_id] = true; }
                                    if ($r1->product_id) { $pIds[(int)$r1->product_id] = true; }
                                }
                                $child['order_count'] = count($oIds);
                                $child['product_count'] = count(array_filter(array_keys($pIds)));

                                // Optional level 2 (could be attribute_3, product_name, or other)
                                $field2 = $hierarchy[2] ?? null;
                                if ($field2 && $field2 !== 'orders') {
                                    $groups2 = [];
                                    foreach ($rows1 as $row2) {
                                        $key2 = $labelFor($row2, $field2);
                                        if (!isset($groups2[$key2])) { $groups2[$key2] = []; }
                                        $groups2[$key2][] = $row2;
                                    }
                                    foreach ($groups2 as $label2 => $rows2) {
                                        $node2 = [
                                            'name' => $label2,
                                            'field' => $field2,
                                            'level' => 2,
                                            'quantity' => 0,
                                            'lean_quantity' => 0,
                                            'non_lean_quantity' => 0,
                                            'processing_quantity' => 0,
                                            'prepared_quantity' => 0,
                                            'order_count' => 0,
                                            'product_count' => 0,
                                            'filters' => [$field0 => $label0, $field1 => $label1, $field2 => $label2],
                                            'children' => [],
                                        ];
                                        
                                        if ($field2 === 'product_name') {
                                            $node2['product_ids'] = [];
                                        }
                                        $o2 = []; $p2 = [];
                                        foreach ($rows2 as $r2) {
                                            $addMetrics($prodNode, $r2);
                                            $o2[$r2->order_id] = true;
                                            if ($r2->line_item_product_id) { $p2[(int)$r2->line_item_product_id] = true; }
                                            if ($r2->product_id) { $p2[(int)$r2->product_id] = true; }
                                        }
                                        $prodNode['order_count'] = count($o2);
                                        $prodNode['product_count'] = count(array_filter(array_keys($p2)));
                                        if (!empty($p2)) {
                                            $ids = array_keys($p2);
                                            sort($ids);
                                            $prodNode['product_ids'] = $ids;
                                            $prodNode['filters']['product_ids'] = implode(',', $ids);
                                        }

                                        // Optional level 3 (orders)
                                        $field3 = $hierarchy[3] ?? null;
                                        if ($field3 === 'orders') {
                                            $orders = [];
                                            foreach ($rows2 as $r3) {
                                                $orderKey = (string) $r3->order_id;
                                                if (!isset($orders[$orderKey])) {
                                                    $orderLabel = $r3->order_number ? ('Order #' . $r3->order_number) : ('Order ' . $r3->order_id);
                                                    if (!empty($r3->customer_name)) {
                                                        $orderLabel .= ' - ' . $r3->customer_name;
                                                    }
                                                    $orders[$orderKey] = [
                                                        'name' => $orderLabel,
                                                        'field' => 'orders',
                                                        'level' => 3,
                                                        'quantity' => 0,
                                                        'lean_quantity' => 0,
                                                        'non_lean_quantity' => 0,
                                                        'processing_quantity' => 0,
                                                        'prepared_quantity' => 0,
                                                        'order_count' => 0,
                                                        'product_count' => 0,
                                                        'order_id' => $r3->order_id,
                                                        'order_number' => $r3->order_number,
                                                        'status' => $r3->order_status,
                                                        'order_date' => $r3->order_date ? \Carbon\Carbon::parse($r3->order_date)->toIso8601String() : null,
                                                        'customer_name' => $r3->customer_name,
                                                        'filters' => array_merge($prodNode['filters'], ['order_id' => $r3->order_id]),
                                                        'children' => [],
                                                    ];
                                                }
                                                $addMetrics($orders[$orderKey], $r3);
                                            }
                                            // finalize counts and append
                                            foreach ($orders as &$ordNode) {
                                                $ordNode['order_count'] = 1;
                                            }
                                            uasort($orders, function ($a, $b) { return $b['quantity'] <=> $a['quantity']; });
                                            $prodNode['children'] = array_values($orders);
                                        }

                                        $child['children'][] = $prodNode;
                                    }
                                    usort($child['children'], function ($a, $b) { return $b['quantity'] <=> $a['quantity']; });
                                }
                                $node0['children'][] = $child;
                            }
                            usort($node0['children'], function ($a, $b) { return $b['quantity'] <=> $a['quantity']; });
                        }

                        $rebuilt[] = $node0;
                    }
                    usort($rebuilt, function ($a, $b) { return $b['quantity'] <=> $a['quantity']; });
                    $tree = $rebuilt;
                    Log::info('Applied fallback tree rebuild', ['root_count' => count($tree)]);
                } catch (\Throwable $e) {
                    Log::warning('Fallback tree rebuild failed', ['error' => $e->getMessage()]);
                }
            }

            $orderStatusCounts = [];
            foreach ($orderStatusesByOrder as $status) {
                $orderStatusCounts[$status] = ($orderStatusCounts[$status] ?? 0) + 1;
            }

            // Log detailed tree structure for debugging
            $treeStats = [
                'root_nodes' => count($tree),
                'hierarchy' => $hierarchy,
                'total_orders' => count($uniqueOrderIds),
                'total_line_items' => $totalLineItems,
            ];
            
            // Count nodes at each level
            foreach ($tree as $rootNode) {
                $treeStats['level_0_count'] = ($treeStats['level_0_count'] ?? 0) + 1;
                if (!empty($rootNode['children'])) {
                    foreach ($rootNode['children'] as $l1Node) {
                        $treeStats['level_1_count'] = ($treeStats['level_1_count'] ?? 0) + 1;
                        if (!empty($l1Node['children'])) {
                            foreach ($l1Node['children'] as $l2Node) {
                                $treeStats['level_2_count'] = ($treeStats['level_2_count'] ?? 0) + 1;
                                if (!empty($l2Node['children'])) {
                                    $treeStats['level_3_count'] = ($treeStats['level_3_count'] ?? 0) + 1;
                                }
                            }
                        }
                    }
                }
            }
            
            Log::info('Quantities tree built successfully', $treeStats);
            
            // DEBUG: Check a sample order node before sending
            if (!empty($tree)) {
                foreach ($tree as $l0) {
                    if (!empty($l0['children'])) {
                        foreach ($l0['children'] as $l1) {
                            if (!empty($l1['children'])) {
                                foreach ($l1['children'] as $l2) {
                                    if (!empty($l2['children'])) {
                                        foreach ($l2['children'] as $l3Product) {
                                            if (!empty($l3Product['children'])) {
                                                $firstOrder = $l3Product['children'][0];
                                                $secondOrder = $l3Product['children'][1] ?? null;
                                                Log::debug('📦 Sample product with orders in final tree', [
                                                    'product_name' => $l3Product['name'],
                                                    'product_quantity' => $l3Product['quantity'],
                                                    'order_count' => count($l3Product['children']),
                                                    'first_order_name' => $firstOrder['name'] ?? 'N/A',
                                                    'first_order_quantity' => $firstOrder['quantity'] ?? 'MISSING',
                                                    'first_order_id' => $firstOrder['order_id'] ?? 'N/A',
                                                    'second_order_name' => $secondOrder['name'] ?? 'N/A',
                                                    'second_order_quantity' => $secondOrder['quantity'] ?? 'N/A',
                                                    'second_order_id' => $secondOrder['order_id'] ?? 'N/A',
                                                ]);
                                                break 4; // Exit all loops after first sample
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'generated_at' => Carbon::now()->toIso8601String(),
                'status_filter' => $statusFilter ?? 'all',
                'hierarchy' => $hierarchy,
                'summary' => [
                    'total_orders' => count($uniqueOrderIds),
                    'total_line_items' => $totalLineItems,
                    'total_quantity' => round($totalQuantity, 2),
                ],
                'order_status_counts' => $orderStatusCounts,
                'tree' => $tree,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to build open quantities tree', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load quantities tree: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getOpenOrderQuantities(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_open_quantities')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view open order quantities'
                ], 403);
            }
            
            // Get parameters
            $level = (int) $request->get('level', 0);
            $filters = json_decode($request->get('filters', '{}'), true) ?: [];
            $statusFilter = $request->get('status_filter'); // Optional status filter
            
            // Allow mobile to override hierarchy (same as tree endpoint)
            $overrideHierarchy = $request->get('hierarchy_override');
            if ($overrideHierarchy) {
                $hierarchy = json_decode($overrideHierarchy, true);
            }
            
            // If no override, get global settings from database (same as web app)
            if (empty($hierarchy) || !is_array($hierarchy)) {
                $hierarchySetting = DB::table('t_crm_open_quantities_settings')
                    ->where('setting_key', 'hierarchy_levels')
                    ->first();
                $hierarchy = $hierarchySetting ? json_decode($hierarchySetting->setting_value, true) : ['product_type', 'product_name'];
            }
            
            $statusSetting = DB::table('t_crm_open_quantities_settings')
                ->where('setting_key', 'excluded_statuses')
                ->first();
            $excludedStatuses = $statusSetting ? json_decode($statusSetting->setting_value, true) : ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // Build base query - same as webapp
            $query = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function($join) {
                    $join->where(function($q) {
                        $q->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.variant_id', 'pv.id')
                          ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.product_id', 'pv.id');
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function($join) {
                    $join->where(function($q) {
                        $q->whereColumn('pv.product_id', 'p.id')
                          ->orWhereColumn('li.product_id', 'p.id');
                    })->orWhereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                })
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses);
            
            // Apply status filter if provided
            if ($statusFilter) {
                $query->where('o.order_status', $statusFilter);
            }
            
            // Default: Only show orders from last 20 days for performance
            $query->where('o.order_date', '>=', \Carbon\Carbon::now()->subDays(20));
            
            // Apply parent filters - but only filter on fields where we have product data
            \Log::debug('Open Quantities Mobile - Filters:', [
                'level' => $level,
                'current_field' => $hierarchy[$level] ?? 'unknown',
                'filters' => $filters
            ]);
            
            foreach ($filters as $field => $value) {
                if ($field === 'product_name') {
                    $query->where('li.name', $value);
                } elseif ($field === 'product_ids') {
                    // CSV or array
                    if (is_string($value)) {
                        $ids = array_filter(array_map('intval', explode(',', $value)));
                    } else {
                        $ids = array_map('intval', (array)$value);
                    }
                    if (!empty($ids)) {
                        $query->where(function($q) use ($ids) {
                            $q->whereIn('li.product_id', $ids)
                              ->orWhereIn('p.id', $ids);
                        });
                    }
                } elseif ($field === 'product_id') {
                    // Keep consistency between product level and orders level filtering
                    $query->where(function($q) use ($value) {
                        $q->where('li.product_id', $value)
                          ->orWhere('p.id', $value);
                    });
                } elseif ($field === 'product_type') {
                    $query->where(function($q) use ($value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.product_type')->orWhere('p.product_type', '');
                        } else {
                            $q->where('p.product_type', $value);
                        }
                    });
                } elseif (in_array($field, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                    $query->where(function($q) use ($field, $value) {
                        if ($value === 'Uncategorized') {
                            $q->whereNull('p.' . $field)->orWhere('p.' . $field, '');
                        } else {
                            $q->where('p.' . $field, $value);
                        }
                    });
                } else {
                    // Fallback for any other field (same as web app)
                    $query->where('p.' . $field, $value);
                }
            }
            
            \Log::debug('Open Quantities Mobile - Query SQL:', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings()
            ]);
            
            // Determine grouping based on level (use hierarchy from settings)
            $currentField = $hierarchy[$level] ?? 'product_name';
            
            if ($currentField === 'orders') {
                // Orders level - show individual orders
                $results = $query->select([
                    'o.id as order_id',
                    'o.order_number',
                    DB::raw("CONCAT('Order #', o.order_number, ' - ', COALESCE(o.name, CONCAT(o.address_first_name, ' ', o.address_last_name))) as name"),
                    'o.order_status as status',
                    DB::raw('SUM(li.quantity) as quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                    DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                    DB::raw('SUM(CASE WHEN li.preparation_status = "preparing" THEN li.quantity ELSE 0 END) as prepared_quantity')
                ])
                ->groupBy('o.id', 'o.order_number', 'o.name', 'o.address_first_name', 'o.address_last_name', 'o.order_status')
                ->orderBy('o.order_number', 'desc')
                ->get();
            } elseif ($currentField === 'product_name') {
                // Product level
                $results = $query->select([
                    'li.name as name',
                    DB::raw('GROUP_CONCAT(DISTINCT COALESCE(li.product_id, p.id)) as product_ids'),
                    DB::raw('SUM(li.quantity) as quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                    DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                    DB::raw('SUM(CASE WHEN li.preparation_status = "preparing" THEN li.quantity ELSE 0 END) as prepared_quantity'),
                    DB::raw('COUNT(DISTINCT o.id) as order_count')
                ])
                ->groupBy('li.name')
                ->orderBy('quantity', 'desc')
                ->get();
            } else {
                // Category level
                $results = $query->select([
                    DB::raw("COALESCE(p.{$currentField}, 'Uncategorized') as name"),
                    DB::raw('SUM(li.quantity) as quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 1 THEN li.quantity ELSE 0 END) as lean_quantity'),
                    DB::raw('SUM(CASE WHEN p.is_lean = 0 THEN li.quantity ELSE 0 END) as non_lean_quantity'),
                    DB::raw('SUM(CASE WHEN o.order_status = "processing" THEN li.quantity ELSE 0 END) as processing_quantity'),
                    DB::raw('SUM(CASE WHEN li.preparation_status = "preparing" THEN li.quantity ELSE 0 END) as prepared_quantity'),
                    DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    DB::raw('COUNT(DISTINCT li.product_id) as product_count')
                ])
                ->groupBy(DB::raw("COALESCE(p.{$currentField}, 'Uncategorized')"))
                ->orderBy('quantity', 'desc')
                ->get();
                
                // Apply priority-based sorting for attribute levels
                if (in_array($currentField, ['attribute_1', 'attribute_2', 'attribute_3'])) {
                    $attributeKey = (int)str_replace('attribute_', '', $currentField);
                    $priorityMap = $this->getAttributePriorityMap($attributeKey);
                    
                    if (!empty($priorityMap)) {
                        $results = $results->sort(function($a, $b) use ($priorityMap) {
                            $nameA = $a->name ?? '';
                            $nameB = $b->name ?? '';
                            
                            // Get priorities (higher number = higher priority = show first)
                            $priorityA = $priorityMap[$nameA] ?? 0;
                            $priorityB = $priorityMap[$nameB] ?? 0;
                            
                            // Sort descending by priority (higher priority first)
                            if ($priorityA != $priorityB) {
                                return $priorityB - $priorityA;
                            }
                            
                            // If same priority, sort by quantity
                            return ($b->quantity ?? 0) - ($a->quantity ?? 0);
                        })->values(); // Reset keys
                    }
                }
            }
            
            \Log::debug('Open Quantities Mobile - Results:', [
                'count' => $results->count(),
                'first_item' => $results->first()
            ]);
            
            // Format for mobile - keep it simple and light
            $formattedResults = $results->map(function($item) use ($currentField) {
                $result = [
                    'name' => $item->name ?? 'Uncategorized',
                    'quantity' => (float) ($item->quantity ?? 0),
                    'lean_quantity' => (float) ($item->lean_quantity ?? 0),
                    'non_lean_quantity' => (float) ($item->non_lean_quantity ?? 0),
                    'processing_quantity' => (float) ($item->processing_quantity ?? 0),
                    'prepared_quantity' => (float) ($item->prepared_quantity ?? 0),
                ];
                
                // Add order-specific fields
                if ($currentField === 'orders') {
                    $result['order_id'] = $item->order_id ?? null;
                    $result['order_number'] = $item->order_number ?? null;
                    $result['status'] = $item->status ?? 'new';
                } else {
                    $result['order_count'] = (int) ($item->order_count ?? 0);
                    $result['product_count'] = isset($item->product_count) ? (int) $item->product_count : null;
                    if ($currentField === 'product_name') {
                        $result['product_id'] = $item->product_id ?? null;
                    }
                }
                
                return $result;
            });
            
            return response()->json([
                'success' => true,
                'level' => $level,
                'field' => $currentField,
                'items' => $formattedResults,
                'total_count' => $formattedResults->count(),
                'settings' => [
                    'hierarchy' => $hierarchy,
                    'excluded_statuses' => $excludedStatuses
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch open order quantities', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch quantities: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk update line item preparation status
     * POST /api/rider/orders/{orderId}/line-items/bulk-update-status
     */
    public function bulkUpdateLineItemStatus(Request $request, $orderId)
    {
        try {
            $user = Auth::user();
            
            // Validate request
            $request->validate([
                'line_item_ids' => 'required|array',
                'line_item_ids.*' => 'required|integer',
                'preparation_status' => 'nullable|in:preparing',
            ]);
            
            $lineItemIds = $request->input('line_item_ids');
            $preparationStatus = $request->input('preparation_status');
            
            // If preparation_status is empty string or null, set to null
            if (empty($preparationStatus)) {
                $preparationStatus = null;
            }
            
            // Check if user has permission to update orders
            if (!$user->hasMobilePermission('view_open_orders') && !$user->hasMobilePermission('mark_order_delivered')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to update line items'
                ], 403);
            }
            
            // Only allow updates for regular orders (not Shopify)
            $order = \App\Models\CRM\OrderModel::with('lineItems')->find($orderId);
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or not eligible for preparation status updates'
                ], 404);
            }
            
            // Check if order is open (not delivered/completed/cancelled)
            $closedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            if (in_array($order->order_status, $closedStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot update preparation status for closed orders'
                ], 400);
            }
            
            // Update line items
            $updated = 0;
            foreach ($lineItemIds as $lineItemId) {
                $lineItem = $order->lineItems->where('id', $lineItemId)->first();
                if ($lineItem) {
                    $lineItem->preparation_status = $preparationStatus;
                    $lineItem->updated_by = $user->id;
                    $lineItem->save();
                    $updated++;
                }
            }
            
            // Get updated counts
            $totalItems = $order->lineItems->count();
            $preparingCount = $order->lineItems->where('preparation_status', 'preparing')->count();
            
            return response()->json([
                'success' => true,
                'message' => "Updated {$updated} line item(s)",
                'updated_count' => $updated,
                'preparing_count' => $preparingCount,
                'total_items' => $totalItems,
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to bulk update line item status', [
                'order_id' => $orderId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update line items: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get expense management data for mobile
     * Includes: expenses, pending approvals, KPIs, top categories
     */
    public function getExpenses(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view expenses'
                ], 403);
            }
            
            // Get filter parameters
            $month = $request->input('month'); // Format: YYYY-MM
            $category = $request->input('category');
            $settlementStatus = $request->input('settlement_status');
            
            // Build base query for expenses and salary advances
            $expensesQuery = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->whereNotNull('ledger_transaction_id')
                ->where('status', \App\Models\Request\RequestModel::STATUS_APPROVED)
                ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy', 'settlementDestinationAccount']);
            
            // Apply date filter only if month is provided
            if ($month) {
                $dateFrom = $month . '-01';
                $dateTo = date('Y-m-t', strtotime($dateFrom)); // Last day of month
                $expensesQuery->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }
            
            // Apply category filter
            if ($category) {
                if (strtolower($category) === 'salary') {
                    $expensesQuery->whereRaw('1 = 0'); // Exclude all (salary comes from slips)
                } else {
                    $expensesQuery->where(function($q) use ($category) {
                        $q->whereRaw('LOWER(expense_category) = ?', [strtolower($category)])
                          ->orWhere(function($q2) use ($category) {
                              if (strtolower($category) === 'salary advance') {
                                  $q2->whereNull('expense_category')
                                     ->orWhere('expense_category', '')
                                     ->whereHas('category', function($q3) {
                                         $q3->where('category_code', 'salary_advance');
                                     });
                              }
                          });
                    });
                }
            }
            
            // Apply settlement status filter
            if ($settlementStatus) {
                $expensesQuery->where('settlement_status', $settlementStatus);
            }
            
            $allExpenses = $expensesQuery->orderBy('created_at', 'desc')->get();
            
            // Get salary slips
            $salarySlipsQuery = SalarySlipModel::with(['employee'])
                ->whereIn('slip_status', ['approved', 'paid'])
                ->whereNotNull('ledger_transaction_id');
            
            // Apply date filter to salary slips if month is provided
            if ($month) {
                $dateFrom = $month . '-01';
                $dateTo = date('Y-m-t', strtotime($dateFrom));
                $salarySlipsQuery->whereBetween('created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
            }
            
            $includeSalarySlips = !$category || strtolower($category) === 'salary';
            $salarySlips = $includeSalarySlips ? $salarySlipsQuery->orderBy('created_at', 'desc')->get() : collect([]);
            $totalSalaryExpenses = $salarySlips->sum('net_salary');
            
            // Transform salary slips for display
            $salarySlipsForDisplay = $salarySlips->map(function($slip) {
                return [
                    'id' => 'SALARY-' . $slip->id,
                    'type' => 'salary',
                    'request_number' => $slip->slip_number ?? ('SLIP-' . $slip->id),
                    'date' => $slip->created_at->format('Y-m-d'),
                    'employee' => $slip->employee ? $slip->employee->fullname : 'Unknown',
                    'category' => 'Salary',
                    'amount' => $slip->net_salary,
                    'payment_source' => 'Expense Fund',
                    'settlement_status' => 'not_applicable',
                    'status' => $slip->slip_status
                ];
            });
            
            // Transform expenses for display
            $expensesForDisplay = $allExpenses->map(function($expense) {
                return [
                    'id' => $expense->id,
                    'type' => 'expense',
                    'request_number' => $expense->request_number,
                    'date' => $expense->created_at->format('Y-m-d'),
                    'employee' => $expense->requester ? $expense->requester->fullname : 'Unknown',
                    'category' => $expense->expense_category ?? ($expense->category ? $expense->category->category_name : 'Uncategorized'),
                    'amount' => $expense->amount,
                    'payment_source' => $expense->paymentSourceAccount ? $expense->paymentSourceAccount->account_name : 'Unknown',
                    'settlement_status' => $expense->settlement_status,
                    'status' => $expense->status,
                    'settled_at' => $expense->settled_at ? $expense->settled_at->format('Y-m-d H:i') : null,
                    'settled_by' => $expense->settledBy ? $expense->settledBy->fullname : null
                ];
            });
            
            // Merge and sort
            $allExpensesForDisplay = $expensesForDisplay->concat($salarySlipsForDisplay)->sortByDesc('date')->values();
            
            // Calculate KPIs
            $totalExpenses = $allExpenses->sum('amount') + $totalSalaryExpenses;
            $needsSettlement = $allExpenses->filter(fn($exp) => $exp->settlement_status === 'pending')->sum('amount');
            $settled = $allExpenses->filter(fn($exp) => $exp->settlement_status === 'settled')->sum('amount');
            
            // Get expense fund balance
            $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            
            // Get pending approvals (real-time, not filtered by month)
            $pendingApprovals = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->where('status', \App\Models\Request\RequestModel::STATUS_PENDING)
                ->with(['requester', 'paymentSourceAccount', 'category'])
                ->orderBy('created_at', 'asc')
                ->get();
            
            // Transform pending approvals
            $pendingApprovalsForDisplay = $pendingApprovals->map(function($request) {
                return [
                    'id' => $request->id,
                    'request_number' => $request->request_number,
                    'date' => $request->created_at->format('Y-m-d'),
                    'employee' => $request->requester ? $request->requester->fullname : 'Unknown',
                    'category' => $request->expense_category ?? ($request->category ? $request->category->category_name : 'Uncategorized'),
                    'amount' => $request->amount,
                    'payment_source' => $request->paymentSourceAccount ? $request->paymentSourceAccount->account_name : 'Unknown',
                    'status' => $request->status
                ];
            });
            
            // Calculate top 5 expense categories
            $expensesByCategory = [];
            foreach ($allExpenses as $expense) {
                $cat = $expense->expense_category;
                if (empty($cat) && $expense->category && $expense->category->category_code === 'salary_advance') {
                    $cat = 'Salary Advance';
                } elseif (empty($cat)) {
                    $cat = 'Uncategorized';
                }
                if (!isset($expensesByCategory[$cat])) {
                    $expensesByCategory[$cat] = 0;
                }
                $expensesByCategory[$cat] += $expense->amount;
            }
            
            if ($totalSalaryExpenses > 0) {
                if (!isset($expensesByCategory['Salary'])) {
                    $expensesByCategory['Salary'] = 0;
                }
                $expensesByCategory['Salary'] += $totalSalaryExpenses;
            }
            
            arsort($expensesByCategory);
            $topCategories = array_slice($expensesByCategory, 0, 5, true);
            
            // Get all unique categories for filter
            $categoriesFromExpenses = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->whereNotNull('ledger_transaction_id')
                ->where('status', \App\Models\Request\RequestModel::STATUS_APPROVED)
                ->whereNotNull('expense_category')
                ->where('expense_category', '!=', '')
                ->distinct()
                ->pluck('expense_category');
            
            $categories = $categoriesFromExpenses
                ->merge(['Salary', 'Salary Advance'])
                ->unique()
                ->sort()
                ->values();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'expenses' => $allExpensesForDisplay,
                    'pending_approvals' => $pendingApprovalsForDisplay,
                    'kpis' => [
                        'total_expenses' => $totalExpenses,
                        'needs_settlement' => $needsSettlement,
                        'settled' => $settled,
                        'fund_balance' => $expenseFund ? $expenseFund->current_balance : 0,
                        'pending_approvals' => $pendingApprovals->sum('amount'),
                        'pending_approvals_count' => $pendingApprovals->count(),
                        'top_categories' => $topCategories
                    ],
                    'categories' => $categories,
                    'current_month' => $month ?: now()->format('Y-m')
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get expenses', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load expenses: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a pending expense request
     */
    public function approveExpense(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('approve_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to approve expenses'
                ], 403);
            }
            
            $expenseRequest = \App\Models\Request\RequestModel::findOrFail($id);
            
            // Determine which level to approve at (same logic as web app)
            // Check if user has Level 1 or Level 2 approval rights
            $approvalLevel = null;
            if (\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1)) {
                $approvalLevel = 1;
            } elseif (\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2)) {
                $approvalLevel = 2;
            }
            
            if (!$approvalLevel) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have approval rights for this request'
                ], 403);
            }
            
            // Use the existing approval controller logic
            $approvalController = new \App\Http\Controllers\Request\RequestApprovalController();
            $approvalRequest = new Request([
                'level' => $approvalLevel,
                'comments' => $request->input('notes', ''),
                'payment_source_account_id' => $request->input('payment_source_account_id', null)
            ]);
            
            $response = $approvalController->approve($approvalRequest, $id);
            $responseData = $response->getData(true);
            
            return response()->json($responseData, $response->status());
            
        } catch (\Exception $e) {
            \Log::error('Failed to approve expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve expense: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Settle an expense
     */
    public function settleExpense(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('settle_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to settle expenses'
                ], 403);
            }
            
            // Use the existing expense management controller logic
            $expenseController = new \App\Http\Controllers\FIN\ExpenseManagementController(
                app(\App\Services\FIN\ExpenseSettlementService::class)
            );
            
            $response = $expenseController->settle($request, $id);
            $responseData = $response->getData(true);
            
            return response()->json($responseData, $response->status());
            
        } catch (\Exception $e) {
            \Log::error('Failed to settle expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to settle expense: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get priority map for attribute categories
     * Reads rules from attribute_auto_rules.json and creates a map of category name => priority
     * If multiple rules have the same category, uses the HIGHEST priority
     */
    private function getAttributePriorityMap(int $attributeKey): array
    {
        try {
            $filePath = storage_path('app/private/attribute_auto_rules.json');
            
            if (!file_exists($filePath)) {
                return [];
            }
            
            $json = file_get_contents($filePath);
            $allRules = json_decode($json, true) ?: [];
            $rules = $allRules[(string)$attributeKey] ?? [];
            
            $priorityMap = [];
            foreach ($rules as $rule) {
                $group = trim((string)($rule['group'] ?? ''));
                $priority = (int)($rule['priority'] ?? 0);
                
                if ($group !== '') {
                    // Keep the HIGHEST priority for each category
                    if (!isset($priorityMap[$group]) || $priority > $priorityMap[$group]) {
                        $priorityMap[$group] = $priority;
                    }
                }
            }
            
            return $priorityMap;
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to read attribute priority map: ' . $e->getMessage());
            return [];
        }
    }
}

