<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CRM\OrderModel;
use App\Models\CRM\OrderStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                        'phone' => $order->customer->phone ?? '',
                        'email' => $order->customer->email ?? '',
                        'address' => $order->customer->address1 ?? '',
                        'city' => $order->customer->city ?? '',
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
     * Mark an order as delivered
     * Riders can only mark their own assigned orders as delivered
     */
    public function markOrderDelivered(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Fetch order
            $order = \App\Models\CRM\OrderModel::find($id);

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
            $query = OrderModel::with(['customer', 'lineItems', 'assignedRider'])
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
                    $customerName = $order->customer->name ?? 'Unknown';
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
                    'customer_phone' => $order->customer->phone ?? $order->address_phone ?? '',
                    'customer_address' => $order->customer->address ?? $order->address_address ?? '',
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
                            'total' => $item->line_total,
                            'total_formatted' => 'Rs. ' . number_format($item->line_total, 0),
                            'preparation_status' => $item->preparation_status,
                        ];
                    }),
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
            $level = (int) $request->get('level', 0); // 0 = Category Level 1, 1 = Level 2, 2 = Level 3, 3 = Products
            $filters = json_decode($request->get('filters', '{}'), true) ?: [];
            
            // Excluded statuses (same as webapp default)
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
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
            
            // Apply parent filters
            foreach ($filters as $field => $value) {
                if ($field === 'product_name') {
                    $query->where('li.name', $value);
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
                }
            }
            
            // Determine grouping based on level
            $hierarchy = ['product_type', 'attribute_1', 'attribute_2', 'product_name'];
            $currentField = $hierarchy[$level] ?? 'product_name';
            
            if ($currentField === 'product_name') {
                // Product level
                $results = $query->select([
                    'li.name as name',
                    'li.product_id',
                    DB::raw('SUM(li.quantity) as quantity'),
                    DB::raw('COUNT(DISTINCT o.id) as order_count')
                ])
                ->groupBy('li.name', 'li.product_id')
                ->orderBy('quantity', 'desc')
                ->get();
            } else {
                // Category level
                $results = $query->select([
                    DB::raw("COALESCE(p.{$currentField}, 'Uncategorized') as name"),
                    DB::raw('SUM(li.quantity) as quantity'),
                    DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    DB::raw('COUNT(DISTINCT li.product_id) as product_count')
                ])
                ->groupBy(DB::raw("COALESCE(p.{$currentField}, 'Uncategorized')"))
                ->orderBy('quantity', 'desc')
                ->get();
            }
            
            // Format for mobile
            $formattedResults = $results->map(function($item) use ($currentField) {
                return [
                    'name' => $item->name ?? 'Uncategorized',
                    'quantity' => (float) $item->quantity,
                    'order_count' => (int) $item->order_count,
                    'product_count' => isset($item->product_count) ? (int) $item->product_count : null,
                    'is_product' => $currentField === 'product_name'
                ];
            });
            
            return response()->json([
                'success' => true,
                'level' => $level,
                'field' => $currentField,
                'items' => $formattedResults,
                'total_count' => $formattedResults->count()
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
}

