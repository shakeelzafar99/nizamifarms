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
use App\Models\FIN\AccountModel;
use App\Models\FIN\LedgerModel;
use App\Models\FIN\ConfigModel;
use App\Models\Request\RequestModel;
use App\Services\LocationService;

class RiderController extends Controller
{
    /**
     * Parse coordinates from a Google Maps URL
     * Handles various URL formats:
     * - https://www.google.com/maps?q=33.6844,73.0479
     * - https://maps.google.com/?ll=33.6844,73.0479
     * - https://www.google.com/maps/place/.../@33.6844,73.0479,15z
     * - https://www.google.com/maps/@33.6844,73.0479,15z
     * 
     * @param string|null $url The Google Maps URL
     * @return array|null ['latitude' => float, 'longitude' => float] or null if parsing fails
     */
    private function parseCoordinatesFromGoogleMapsUrl(?string $url): ?array
    {
        if (empty($url)) {
            return null;
        }
        
        // Pattern 1: ?q=lat,lng or ?ll=lat,lng
        if (preg_match('/[?&](q|ll)=(-?\d+\.?\d*),(-?\d+\.?\d*)/', $url, $matches)) {
            return [
                'latitude' => (float) $matches[2],
                'longitude' => (float) $matches[3],
            ];
        }
        
        // Pattern 2: @lat,lng in URL path (e.g., /maps/@33.6844,73.0479,15z or /maps/place/.../@33.6844,73.0479,15z)
        if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*)/', $url, $matches)) {
            return [
                'latitude' => (float) $matches[1],
                'longitude' => (float) $matches[2],
            ];
        }
        
        // Pattern 3: place/lat,lng format
        if (preg_match('/place\/(-?\d+\.?\d*),(-?\d+\.?\d*)/', $url, $matches)) {
            return [
                'latitude' => (float) $matches[1],
                'longitude' => (float) $matches[2],
            ];
        }
        
        return null;
    }

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
                    'total_formatted' => $item->is_free ? 'FREE' : 'Rs. ' . number_format($item->line_total, 0),
                    'preparation_status' => $item->preparation_status,
                    'is_free' => (bool) $item->is_free,
                    'qurbani_day' => $item->qurbani_day,
                    'qurbani_slot' => $item->qurbani_slot,
                    'qurbani_region' => $item->qurbani_region,
                    'qurbani_delivery_type' => $item->qurbani_delivery_type,
                    'instructions' => $item->instructions,
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
                    // ⭐ Order note - specific to this order
                    'notes' => $order->note,
                    'order_note' => $order->note,
                    'has_order_note' => !empty($order->note),
                    // ⭐ Customer notes - use primary customer notes if order linked to merged duplicate
                    'customer_notes' => $order->customer ? (
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ) : null,
                    'has_customer_notes' => $order->customer && !empty(
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ),
                    'expected_packets' => $order->expected_packets, // Number of packets expected (from manager)
                    'actual_packets' => $order->actual_packets,     // Number of packets delivered (from rider)
                    'delivery_location' => $deliveryLocation,       // GPS coordinates of delivery (if delivered)
                    'online_message_sent_at' => $order->online_message_sent_at,  // WhatsApp message sent timestamp
                    'online_message_sent_by' => $order->online_message_sent_by,  // Who sent the message
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
                    'qurbani_day' => $order->qurbani_day,
                    'qurbani_slot' => $order->qurbani_slot,
                    'qurbani_region' => $order->qurbani_region,
                    'qurbani_delivery_type' => $order->qurbani_delivery_type,
                    'is_qurbani' => !empty($order->qurbani_day) || !empty($order->qurbani_slot) || !empty($order->qurbani_region) || !empty($order->qurbani_delivery_type) || $order->hasQurbaniItems() || $order->lineItems->contains(function($li) { return !empty($li->qurbani_day) || !empty($li->qurbani_slot) || !empty($li->qurbani_region) || !empty($li->qurbani_delivery_type); }),
                    'total_paid' => (float)($order->total_paid ?? 0),
                    'payment_status' => $order->payment_status ?? 'unpaid',
                    'balance_remaining' => max(0, (float)$order->total_price - (float)($order->total_paid ?? 0)),
                    // Fully computed PAID stamp — shared source of truth for
                    // the mobile InvoiceTemplate. Uses the exact same rules
                    // as the server-side blade partial (getPaidStampData),
                    // so mobile doesn't need to duplicate the cash/online
                    // bank fallback logic.
                    'paid_stamp' => $order->getPaidStampData(),
                    'qurbani_rider_delivered_enabled' => \App\Models\FIN\ConfigModel::get('qurbani_rider_delivered_enabled', '0') === '1',
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
     * Resolve a shortened Google Maps URL to get the final URL with coordinates
     * Follows redirects for goo.gl, maps.app.goo.gl, etc.
     * 
     * @param string $url The potentially shortened URL
     * @return string The resolved URL (or original if resolution fails)
     */
    private function resolveGoogleMapsUrl(string $url): string
    {
        // Check if this is a shortened URL that needs resolution
        $shortenedDomains = ['goo.gl', 'maps.app.goo.gl', 'g.co', 'maps.google.com/goo.gl'];
        $needsResolution = false;
        
        foreach ($shortenedDomains as $domain) {
            if (stripos($url, $domain) !== false) {
                $needsResolution = true;
                break;
            }
        }
        
        if (!$needsResolution) {
            return $url;
        }
        
        try {
            // Use cURL to follow redirects and get final URL
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 400 && !empty($finalUrl)) {
                \Log::info('Resolved shortened Google Maps URL', [
                    'original' => $url,
                    'resolved' => $finalUrl,
                ]);
                return $finalUrl;
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to resolve shortened URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
        
        return $url;
    }

    /**
     * Set verified location for a customer
     * Accepts either coordinates OR Google Maps URL
     * For shortened URLs (goo.gl, maps.app.goo.gl), automatically resolves and extracts coordinates
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
                // URL provided - resolve if shortened and extract coordinates
                $originalUrl = $validated['url'];
                $resolvedUrl = $this->resolveGoogleMapsUrl($originalUrl);
                
                // Store the original URL (user-provided)
                $updateData['verified_location_url'] = $originalUrl;
                
                // Try to parse coordinates from the resolved URL
                $parsedCoords = $this->parseCoordinatesFromGoogleMapsUrl($resolvedUrl);
                
                if ($parsedCoords) {
                    // Successfully extracted coordinates - save them too!
                    $updateData['latitude'] = $parsedCoords['latitude'];
                    $updateData['longitude'] = $parsedCoords['longitude'];
                    
                    \Log::info('Setting verified location from URL with extracted coordinates', [
                        'customer_id' => $customerId,
                        'original_url' => $originalUrl,
                        'resolved_url' => $resolvedUrl,
                        'latitude' => $parsedCoords['latitude'],
                        'longitude' => $parsedCoords['longitude'],
                        'saved_by' => Auth::user()->fullname,
                    ]);
                } else {
                    \Log::info('Setting verified location URL (coordinates not extracted)', [
                        'customer_id' => $customerId,
                        'url' => $originalUrl,
                        'resolved_url' => $resolvedUrl,
                        'saved_by' => Auth::user()->fullname,
                    ]);
                }
            }
            
            if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                // Coordinates provided directly - store them (overrides URL-extracted coords)
                $updateData['latitude'] = $validated['latitude'];
                $updateData['longitude'] = $validated['longitude'];
                
                // If no URL was provided in this request, clear the old URL
                // This ensures "View Location" shows the new pin location, not the old URL
                if (empty($validated['url'])) {
                    $updateData['verified_location_url'] = null;
                }
                
                \Log::info('Setting verified location coordinates for customer', [
                    'customer_id' => $customerId,
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'cleared_old_url' => empty($validated['url']),
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
     * ⭐ Get ETA and distance from rider to destination
     * Uses Google Maps Directions API with fallback to OpenRouteService
     * Includes smart usage tracking to stay within free tier limits
     * 
     * @param Request $request - origin_lat, origin_lng, dest_lat, dest_lng
     * @return JSON with distance_km, duration_minutes, duration_text, source
     */
    public function getEtaToDestination(Request $request)
    {
        try {
            $validated = $request->validate([
                'origin_lat' => 'required|numeric|between:-90,90',
                'origin_lng' => 'required|numeric|between:-180,180',
                'dest_lat' => 'required|numeric|between:-90,90',
                'dest_lng' => 'required|numeric|between:-180,180',
            ]);
            
            $originLat = $validated['origin_lat'];
            $originLng = $validated['origin_lng'];
            $destLat = $validated['dest_lat'];
            $destLng = $validated['dest_lng'];
            
            // Check cache first (5-minute cache based on rounded coordinates)
            $cacheKey = sprintf(
                'eta_%s_%s_%s_%s',
                round($originLat, 3), round($originLng, 3),
                round($destLat, 3), round($destLng, 3)
            );
            
            $cached = \Cache::get($cacheKey);
            if ($cached) {
                return response()->json([
                    'success' => true,
                    'cached' => true,
                    ...$cached
                ]);
            }
            
            // Try Google Maps first (if within monthly limit)
            $result = $this->getEtaFromGoogleMaps($originLat, $originLng, $destLat, $destLng);
            
            // If Google failed or limit reached, try OpenRouteService
            if (!$result) {
                $result = $this->getEtaFromOpenRouteService($originLat, $originLng, $destLat, $destLng);
            }
            
            // If both failed, calculate straight-line estimate
            if (!$result) {
                $straightLineKm = $this->haversineDistance($originLat, $originLng, $destLat, $destLng) / 1000;
                // Rough estimate: 30 km/h average speed in city
                $estimatedMinutes = round(($straightLineKm / 30) * 60);
                
                $result = [
                    'distance_km' => round($straightLineKm, 1),
                    'distance_text' => round($straightLineKm, 1) . ' km',
                    'duration_minutes' => $estimatedMinutes,
                    'duration_text' => $estimatedMinutes . ' min',
                    'source' => 'estimate',
                    'traffic' => null,
                ];
            }
            
            // Cache result for 5 minutes
            \Cache::put($cacheKey, $result, now()->addMinutes(5));
            
            return response()->json([
                'success' => true,
                'cached' => false,
                ...$result
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coordinates',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ETA calculation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate ETA',
            ], 500);
        }
    }
    
    /**
     * Get current API usage stats for monitoring
     */
    public function getApiUsageStats()
    {
        $monthKey = date('Y-m');
        
        $stats = \DB::table('t_sys_api_usage')
            ->where('month_key', $monthKey)
            ->get()
            ->keyBy('api_name');
        
        $googleUsage = $stats->get('google_directions');
        $openRouteUsage = $stats->get('openroute_directions');
        
        return response()->json([
            'success' => true,
            'month' => $monthKey,
            'google_directions' => [
                'calls' => $googleUsage->call_count ?? 0,
                'limit' => 10000,
                'remaining' => 10000 - ($googleUsage->call_count ?? 0),
                'at_limit' => ($googleUsage->call_count ?? 0) >= 10000,
            ],
            'openroute_directions' => [
                'calls' => $openRouteUsage->call_count ?? 0,
                'daily_limit' => 2000,
            ],
        ]);
    }
    
    /**
     * Get ETA from Google Maps Directions API
     * Tracks usage and returns null if monthly limit reached
     */
    private function getEtaFromGoogleMaps($originLat, $originLng, $destLat, $destLng): ?array
    {
        // Check monthly usage limit
        $monthKey = date('Y-m');
        $usage = \DB::table('t_sys_api_usage')
            ->where('api_name', 'google_directions')
            ->where('month_key', $monthKey)
            ->first();
        
        $currentCount = $usage->call_count ?? 0;
        if ($currentCount >= 10000) {
            \Log::warning('Google Maps API monthly limit reached', [
                'month' => $monthKey,
                'count' => $currentCount,
            ]);
            return null;
        }
        
        $apiKey = env('GOOGLE_MAPS_DIRECTIONS_API_KEY');
        if (empty($apiKey)) {
            \Log::warning('Google Maps API key not configured');
            return null;
        }
        
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            
            $response = $client->get('https://maps.googleapis.com/maps/api/directions/json', [
                'query' => [
                    'origin' => "{$originLat},{$originLng}",
                    'destination' => "{$destLat},{$destLng}",
                    'mode' => 'driving',
                    'departure_time' => 'now', // For traffic data
                    'key' => $apiKey,
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            // Increment usage counter
            $this->incrementApiUsage('google_directions');
            
            if ($data['status'] !== 'OK' || empty($data['routes'])) {
                \Log::warning('Google Maps API error', ['status' => $data['status']]);
                return null;
            }
            
            $route = $data['routes'][0]['legs'][0];
            
            // Use duration_in_traffic if available, else regular duration
            $durationSeconds = $route['duration_in_traffic']['value'] ?? $route['duration']['value'];
            $durationText = $route['duration_in_traffic']['text'] ?? $route['duration']['text'];
            $distanceMeters = $route['distance']['value'];
            
            // Determine traffic status (guard against division by zero when duration is 0)
            $traffic = null;
            if (isset($route['duration_in_traffic']) && isset($route['duration'])) {
                $normalDuration = $route['duration']['value'] ?? 0;
                if ($normalDuration > 0) {
                    $ratio = $route['duration_in_traffic']['value'] / $normalDuration;
                    if ($ratio > 1.5) $traffic = 'heavy';
                    elseif ($ratio > 1.2) $traffic = 'moderate';
                    else $traffic = 'light';
                } else {
                    // Duration is 0 (origin ≈ destination), default to light traffic
                    $traffic = 'light';
                }
            }
            
            return [
                'distance_km' => round($distanceMeters / 1000, 1),
                'distance_text' => $route['distance']['text'],
                'duration_minutes' => round($durationSeconds / 60),
                'duration_text' => $durationText,
                'source' => 'google',
                'traffic' => $traffic,
            ];
            
        } catch (\Exception $e) {
            \Log::error('Google Maps API call failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Get ETA from OpenRouteService (fallback)
     */
    private function getEtaFromOpenRouteService($originLat, $originLng, $destLat, $destLng): ?array
    {
        $apiKey = env('OPENROUTESERVICE_API_KEY', '5b3ce3597851110001cf62487c37b3c0b8d74b9fb9f7d9f3c3d7f8e9');
        
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            
            $response = $client->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'coordinates' => [
                        [$originLng, $originLat], // GeoJSON: [lng, lat]
                        [$destLng, $destLat],
                    ],
                    'instructions' => false,
                    'geometry' => false,
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            // Increment usage counter
            $this->incrementApiUsage('openroute_directions');
            
            if (empty($data['routes'])) {
                return null;
            }
            
            $summary = $data['routes'][0]['summary'];
            $distanceKm = round($summary['distance'] / 1000, 1);
            $durationMinutes = round($summary['duration'] / 60);
            
            return [
                'distance_km' => $distanceKm,
                'distance_text' => $distanceKm . ' km',
                'duration_minutes' => $durationMinutes,
                'duration_text' => $durationMinutes . ' min',
                'source' => 'openroute',
                'traffic' => null, // OpenRouteService doesn't provide traffic data
            ];
            
        } catch (\Exception $e) {
            \Log::error('OpenRouteService API call failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Increment API usage counter
     */
    private function incrementApiUsage(string $apiName): void
    {
        $monthKey = date('Y-m');
        
        \DB::table('t_sys_api_usage')
            ->updateOrInsert(
                ['api_name' => $apiName, 'month_key' => $monthKey],
                [
                    'call_count' => \DB::raw('call_count + 1'),
                    'last_called_at' => now(),
                    'updated_at' => now(),
                ]
            );
    }
    
    /**
     * ⭐ Calculate and save delivery ETAs for a rider's out_for_delivery orders
     * Called when user presses "Get Times" button in pinned rider view
     * 
     * Uses Google Maps Directions API with waypoints (1 API call for all orders)
     * Adds 10 minutes stop time at each destination
     * Saves estimated_delivery_at for each order (fixed, won't change)
     * 
     * @param int $riderId - The rider to calculate ETAs for
     */
    public function calculateDeliveryEtas(Request $request, $riderId)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Get rider info
            $rider = \DB::table('t_sys_user')
                ->where('id', $riderId)
                ->where('is_active', 1)
                ->select('id', 'fullname')
                ->first();
            
            if (!$rider) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rider not found'
                ], 404);
            }
            
            // Get rider's current location (most recent heartbeat)
            $riderLocation = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId)
                ->where('captured_at', '>=', now()->subMinutes(30))
                ->orderBy('captured_at', 'desc')
                ->first();
            
            $usedShopLocation = false;
            if (!$riderLocation) {
                $baseLocation = \App\Services\LocationService::getUserAssignedLocation($riderId)
                    ?? \App\Services\LocationService::getPrimaryBaseLocation();

                if (!$baseLocation || !$baseLocation->latitude || !$baseLocation->longitude) {
                    return response()->json([
                        'success' => false,
                        'message' => "⚠️ Rider GPS not active — please ask {$rider->fullname} to turn on GPS. Shop location also not configured.",
                    ], 400);
                }

                $riderLocation = (object) [
                    'latitude' => $baseLocation->latitude,
                    'longitude' => $baseLocation->longitude,
                ];
                $usedShopLocation = true;
            }
            
            // ⭐ Check if frontend passed explicit order sequence (fixes debounce timing issue)
            $orderSequence = $request->input('order_sequence', []);
            
            // Get all out_for_delivery orders for this rider
            $ordersQuery = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $riderId)
                ->where('o.order_status', 'out_for_delivery')
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.delivery_priority',
                    'o.estimated_delivery_at', // Check if already has ETA
                    'c.latitude',
                    'c.longitude',
                    'c.geocoded_latitude',
                    'c.geocoded_longitude',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                ]);
            
            // ⭐ If frontend passed order sequence, use that order
            if (!empty($orderSequence)) {
                // Create a map of order_id => priority
                $priorityMap = [];
                foreach ($orderSequence as $item) {
                    $priorityMap[$item['order_id']] = $item['priority'];
                }
                
                $orders = $ordersQuery->get();
                
                // Sort by the provided sequence
                $orders = $orders->sortBy(function($order) use ($priorityMap) {
                    return $priorityMap[$order->id] ?? 999;
                })->values();
            } else {
                // Use DB priority order (fallback)
                $orders = $ordersQuery->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')->get();
            }
            
            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No out_for_delivery orders found for this rider'
                ], 400);
            }
            
            // Build waypoints: [rider_location, order1, order2, ...]
            $waypoints = [];
            $ordersWithLocation = [];
            
            // Start with rider's current location
            $waypoints[] = [
                'lat' => (float)$riderLocation->latitude,
                'lng' => (float)$riderLocation->longitude,
            ];
            
            $unverifiedOrders = [];
            $noLocationOrders = [];

            foreach ($orders as $order) {
                $lat = $order->latitude ?: $order->geocoded_latitude;
                $lng = $order->longitude ?: $order->geocoded_longitude;
                
                if ($lat && $lng) {
                    $waypoints[] = [
                        'lat' => (float)$lat,
                        'lng' => (float)$lng,
                    ];
                    $ordersWithLocation[] = $order;
                    if (!$order->latitude || !$order->longitude) {
                        $unverifiedOrders[] = $order->order_number;
                    }
                } else {
                    $noLocationOrders[] = $order->order_number;
                }
            }
            
            if (count($ordersWithLocation) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No orders have GPS coordinates'
                ], 400);
            }
            
            $etaResult = $this->getMultiStopEtaFromGoogle($waypoints);
            
            if (!$etaResult) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to calculate ETA (API error or limit reached)'
                ], 500);
            }
            
            // ⭐ Calculate cumulative ETA for each order
            $stopTimeMinutes = 10; // Time spent at each stop
            $now = now();
            $calculatedAt = $now->copy();
            $cumulativeMinutes = 0;
            $updatedOrders = [];
            
            foreach ($ordersWithLocation as $index => $order) {
                // Get travel time to this order from previous point
                $legDuration = $etaResult['legs'][$index] ?? 0; // Duration in minutes
                $cumulativeMinutes += $legDuration;
                
                // Calculate estimated arrival time
                $estimatedAt = $now->copy()->addMinutes(round($cumulativeMinutes));
                
                // Update order with estimated time only (DO NOT change delivery_priority - that's set by the team)
                \DB::table('t_crm_prod_order')
                    ->where('id', $order->id)
                    ->update([
                        'estimated_delivery_at' => $estimatedAt,
                        'eta_calculated_at' => $calculatedAt,
                    ]);
                
                $updatedOrders[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => trim($order->customer_name),
                    'sequence' => $index + 1,
                    'delivery_priority' => $index + 1, // ⭐ Show the assigned priority
                    'travel_minutes' => round($legDuration),
                    'estimated_at' => $estimatedAt->format('h:i A'),
                    'estimated_at_raw' => $estimatedAt->toIso8601String(),
                ];
                
                // Add stop time for next order calculation
                $cumulativeMinutes += $stopTimeMinutes;
            }
            
            \Log::info('📊 Delivery ETAs calculated', [
                'rider_id' => $riderId,
                'orders_count' => count($updatedOrders),
                'total_duration_minutes' => round($cumulativeMinutes),
            ]);
            
            $response = [
                'success' => true,
                'message' => 'ETAs calculated for ' . count($updatedOrders) . ' orders',
                'rider' => [
                    'id' => $rider->id,
                    'name' => $rider->fullname,
                    'current_location' => [
                        'latitude' => (float)$riderLocation->latitude,
                        'longitude' => (float)$riderLocation->longitude,
                        'captured_at' => $riderLocation->captured_at ?? null,
                    ],
                ],
                'calculated_at' => $calculatedAt->toIso8601String(),
                'stop_time_minutes' => $stopTimeMinutes,
                'orders' => $updatedOrders,
                'unverified_orders' => $unverifiedOrders,
                'no_location_orders' => $noLocationOrders,
            ];

            if ($usedShopLocation) {
                $response['gps_warning'] = "⚠️ Rider GPS not active — ETAs calculated from shop location. Ask {$rider->fullname} to turn on GPS for accurate times.";
            }

            return response()->json($response);
            
        } catch (\Exception $e) {
            \Log::error('Failed to calculate delivery ETAs', [
                'rider_id' => $riderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate ETAs: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get multi-stop ETA from Google Maps Directions API
     * Returns leg durations for waypoints route
     * 
     * @param array $waypoints - Array of ['lat' => x, 'lng' => y]
     * @return array|null - ['total_duration' => minutes, 'legs' => [min, min, ...]]
     */
    private function getMultiStopEtaFromGoogle(array $waypoints): ?array
    {
        if (count($waypoints) < 2) {
            return null;
        }
        
        // Check monthly usage limit
        $monthKey = date('Y-m');
        $usage = \DB::table('t_sys_api_usage')
            ->where('api_name', 'google_directions')
            ->where('month_key', $monthKey)
            ->first();
        
        $currentCount = $usage->call_count ?? 0;
        if ($currentCount >= 10000) {
            \Log::warning('Google Maps API monthly limit reached for multi-stop ETA');
            return null;
        }
        
        $apiKey = env('GOOGLE_MAPS_DIRECTIONS_API_KEY');
        if (empty($apiKey)) {
            \Log::warning('Google Maps API key not configured');
            return null;
        }
        
        try {
            // Build origin (first point)
            $origin = $waypoints[0]['lat'] . ',' . $waypoints[0]['lng'];
            
            // Build destination (last point)
            $lastIdx = count($waypoints) - 1;
            $destination = $waypoints[$lastIdx]['lat'] . ',' . $waypoints[$lastIdx]['lng'];
            
            // Build waypoints (intermediate points) - Google limits to 25 waypoints
            $intermediateWaypoints = [];
            for ($i = 1; $i < $lastIdx && $i <= 25; $i++) {
                $intermediateWaypoints[] = $waypoints[$i]['lat'] . ',' . $waypoints[$i]['lng'];
            }
            
            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            
            $queryParams = [
                'origin' => $origin,
                'destination' => $destination,
                'mode' => 'driving',
                'departure_time' => 'now', // For traffic data
                'key' => $apiKey,
            ];
            
            if (!empty($intermediateWaypoints)) {
                $queryParams['waypoints'] = implode('|', $intermediateWaypoints);
            }
            
            $response = $client->get('https://maps.googleapis.com/maps/api/directions/json', [
                'query' => $queryParams
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            // Increment usage counter
            $this->incrementApiUsage('google_directions');
            
            if ($data['status'] !== 'OK' || empty($data['routes'])) {
                \Log::warning('Google Maps multi-stop API error', ['status' => $data['status']]);
                return null;
            }
            
            $route = $data['routes'][0];
            $legs = [];
            $totalDuration = 0;
            
            foreach ($route['legs'] as $leg) {
                // Use duration_in_traffic if available
                $durationSeconds = $leg['duration_in_traffic']['value'] ?? $leg['duration']['value'];
                $durationMinutes = round($durationSeconds / 60);
                $legs[] = $durationMinutes;
                $totalDuration += $durationMinutes;
            }
            
            \Log::info('📍 Multi-stop ETA from Google', [
                'waypoints' => count($waypoints),
                'legs' => $legs,
                'total_minutes' => $totalDuration,
            ]);
            
            return [
                'total_duration' => $totalDuration,
                'legs' => $legs,
                'source' => 'google_maps',
            ];
            
        } catch (\Exception $e) {
            \Log::error('Google Maps multi-stop API call failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Optimize delivery route using Google Directions API (optimize:true).
     * Returns suggested order sequence + per-leg ETAs without saving to DB (preview only).
     */
    public function optimizeRoute(Request $request, $riderId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json(['success' => false, 'message' => 'Permission denied'], 403);
            }

            $rider = \DB::table('t_sys_user')->where('id', $riderId)->where('is_active', 1)->select('id', 'fullname')->first();
            if (!$rider) {
                return response()->json(['success' => false, 'message' => 'Rider not found'], 404);
            }

            $riderLocation = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId)
                ->where('captured_at', '>=', now()->subMinutes(30))
                ->orderBy('captured_at', 'desc')
                ->first();

            $usedShopLocation = false;
            if (!$riderLocation) {
                // Fall back to shop/office location
                $baseLocation = \App\Services\LocationService::getUserAssignedLocation($riderId)
                    ?? \App\Services\LocationService::getPrimaryBaseLocation();

                if (!$baseLocation || !$baseLocation->latitude || !$baseLocation->longitude) {
                    return response()->json([
                        'success' => false,
                        'message' => "⚠️ Rider GPS not active — please ask {$rider->fullname} to turn on GPS. Shop location also not configured.",
                    ], 400);
                }

                $riderLocation = (object) [
                    'latitude' => $baseLocation->latitude,
                    'longitude' => $baseLocation->longitude,
                ];
                $usedShopLocation = true;
            }

            $orders = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $riderId)
                ->where('o.order_status', 'out_for_delivery')
                ->select([
                    'o.id', 'o.order_number', 'o.delivery_priority',
                    'c.latitude', 'c.longitude', 'c.geocoded_latitude', 'c.geocoded_longitude',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    \DB::raw('CONCAT(COALESCE(c.address1, ""), ", ", COALESCE(c.city, "")) as address_short'),
                ])
                ->orderByRaw('COALESCE(o.delivery_priority, 999) ASC, o.id ASC')
                ->get();

            if ($orders->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No out_for_delivery orders found'], 400);
            }

            $waypoints = [['lat' => (float)$riderLocation->latitude, 'lng' => (float)$riderLocation->longitude]];
            $ordersWithLocation = [];
            $unverifiedOrders = [];
            $noLocationOrders = [];

            foreach ($orders as $order) {
                $lat = $order->latitude ?: $order->geocoded_latitude;
                $lng = $order->longitude ?: $order->geocoded_longitude;
                if ($lat && $lng) {
                    $waypoints[] = ['lat' => (float)$lat, 'lng' => (float)$lng];
                    $ordersWithLocation[] = $order;
                    if (!$order->latitude || !$order->longitude) {
                        $unverifiedOrders[] = $order->order_number;
                    }
                } else {
                    $noLocationOrders[] = $order->order_number;
                }
            }

            if (count($ordersWithLocation) === 0) {
                return response()->json(['success' => false, 'message' => 'No orders have GPS coordinates'], 400);
            }

            $result = $this->getOptimizedRouteFromGoogle($waypoints);

            if (!$result) {
                return response()->json(['success' => false, 'message' => 'Failed to optimize route (API error or limit reached)'], 500);
            }

            $stopTimeMinutes = 10;
            $now = now();
            $cumulativeMinutes = 0;
            $optimizedSequence = [];
            $waypointOrder = $result['waypoint_order'];

            foreach ($waypointOrder as $priority => $originalIndex) {
                $order = $ordersWithLocation[$originalIndex];
                $legDuration = $result['legs'][$priority] ?? 0;
                $cumulativeMinutes += $legDuration;
                $estimatedAt = $now->copy()->addMinutes(round($cumulativeMinutes));

                $optimizedSequence[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => trim($order->customer_name),
                    'address_short' => trim($order->address_short),
                    'priority' => $priority + 1,
                    'travel_minutes' => round($legDuration),
                    'cumulative_minutes' => round($cumulativeMinutes),
                    'estimated_at' => $estimatedAt->format('h:i A'),
                ];

                $cumulativeMinutes += $stopTimeMinutes;
            }

            // Flag orders with unusually long travel times (likely wrong GPS)
            $farOrders = [];
            foreach ($optimizedSequence as $item) {
                if ($item['travel_minutes'] > 60) {
                    $farOrders[] = "{$item['order_number']} ({$item['customer_name']}) — {$item['travel_minutes']} min away";
                }
            }

            $response = [
                'success' => true,
                'optimized_sequence' => $optimizedSequence,
                'total_time_minutes' => round($cumulativeMinutes - $stopTimeMinutes),
                'total_distance_km' => $result['total_distance_km'] ?? null,
                'orders_count' => count($optimizedSequence),
                'unverified_orders' => $unverifiedOrders,
                'no_location_orders' => $noLocationOrders,
            ];

            if ($usedShopLocation) {
                $response['gps_warning'] = "⚠️ Rider GPS not active — route optimized from shop location. Ask {$rider->fullname} to turn on GPS for accurate results.";
            }

            if (!empty($farOrders)) {
                $response['far_orders_warning'] = $farOrders;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            \Log::error('Failed to optimize route', ['rider_id' => $riderId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Failed to optimize route: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Nearest-neighbor route optimization using Google Directions.
     * 
     * Strategy: Starting from rider/shop, always go to the nearest unvisited
     * customer next. Uses Google Directions for real road travel times per leg.
     * Simple, predictable, and optimal for typical city delivery routes.
     * 
     * Falls back to haversine straight-line if Google API fails.
     */
    private function getOptimizedRouteFromGoogle(array $waypoints): ?array
    {
        if (count($waypoints) < 3) {
            $directResult = $this->getMultiStopEtaFromGoogle($waypoints);
            if (!$directResult) return null;
            return [
                'waypoint_order' => [0],
                'legs' => $directResult['legs'],
                'total_duration' => $directResult['total_duration'],
                'total_distance_km' => null,
            ];
        }

        $apiKey = env('GOOGLE_MAPS_DIRECTIONS_API_KEY');
        if (empty($apiKey)) return null;

        $monthKey = date('Y-m');
        $usage = \DB::table('t_sys_api_usage')
            ->where('api_name', 'google_directions')
            ->where('month_key', $monthKey)
            ->first();

        if (($usage->call_count ?? 0) >= 10000) {
            \Log::warning('Google Maps API monthly limit reached for route optimization');
            return null;
        }

        try {
            // Build the nearest-neighbor sequence using haversine first (fast)
            $orderCount = count($waypoints) - 1; // exclude rider at index 0
            $visited = [];
            $sequence = []; // indices into $waypoints (1-based, orders only)
            $currentLat = $waypoints[0]['lat'];
            $currentLng = $waypoints[0]['lng'];

            for ($step = 0; $step < $orderCount; $step++) {
                $nearestIdx = null;
                $nearestDist = PHP_FLOAT_MAX;

                for ($i = 1; $i < count($waypoints); $i++) {
                    if (in_array($i, $visited)) continue;
                    $dist = $this->haversineDistance($currentLat, $currentLng, $waypoints[$i]['lat'], $waypoints[$i]['lng']);
                    if ($dist < $nearestDist) {
                        $nearestDist = $dist;
                        $nearestIdx = $i;
                    }
                }

                if ($nearestIdx === null) break;
                $visited[] = $nearestIdx;
                $sequence[] = $nearestIdx;
                $currentLat = $waypoints[$nearestIdx]['lat'];
                $currentLng = $waypoints[$nearestIdx]['lng'];
            }

            // Build the ordered route: rider → sequence of orders
            $orderedWaypoints = [$waypoints[0]];
            foreach ($sequence as $idx) {
                $orderedWaypoints[] = $waypoints[$idx];
            }

            // Get actual road travel times from Google Directions for this sequence
            $origin = $orderedWaypoints[0]['lat'] . ',' . $orderedWaypoints[0]['lng'];
            $lastIdx = count($orderedWaypoints) - 1;
            $destination = $orderedWaypoints[$lastIdx]['lat'] . ',' . $orderedWaypoints[$lastIdx]['lng'];

            $intermediateCoords = [];
            for ($i = 1; $i < $lastIdx; $i++) {
                $intermediateCoords[] = $orderedWaypoints[$i]['lat'] . ',' . $orderedWaypoints[$i]['lng'];
            }

            $client = new \GuzzleHttp\Client(['timeout' => 15]);
            $queryParams = [
                'origin' => $origin,
                'destination' => $destination,
                'mode' => 'driving',
                'departure_time' => 'now',
                'key' => $apiKey,
            ];

            if (!empty($intermediateCoords)) {
                // No optimize:true — we already determined the order via nearest-neighbor
                $queryParams['waypoints'] = implode('|', $intermediateCoords);
            }

            $response = $client->get('https://maps.googleapis.com/maps/api/directions/json', ['query' => $queryParams]);
            $data = json_decode($response->getBody()->getContents(), true);
            $this->incrementApiUsage('google_directions');

            $legs = [];
            $totalDuration = 0;
            $totalDistanceMeters = 0;

            if ($data['status'] === 'OK' && !empty($data['routes'])) {
                $route = $data['routes'][0];
                foreach ($route['legs'] as $leg) {
                    $durationSeconds = $leg['duration_in_traffic']['value'] ?? $leg['duration']['value'];
                    $durationMinutes = round($durationSeconds / 60);
                    $legs[] = $durationMinutes;
                    $totalDuration += $durationMinutes;
                    $totalDistanceMeters += $leg['distance']['value'] ?? 0;
                }
            } else {
                // Fallback: estimate using haversine at ~30 km/h
                \Log::warning('Google Directions failed for ordered route, using haversine fallback', ['status' => $data['status'] ?? 'unknown']);
                for ($i = 0; $i < count($orderedWaypoints) - 1; $i++) {
                    $distMeters = $this->haversineDistance(
                        $orderedWaypoints[$i]['lat'], $orderedWaypoints[$i]['lng'],
                        $orderedWaypoints[$i+1]['lat'], $orderedWaypoints[$i+1]['lng']
                    );
                    $minutes = round(($distMeters / 1000 / 30) * 60);
                    $legs[] = max(1, $minutes);
                    $totalDuration += $legs[count($legs) - 1];
                    $totalDistanceMeters += $distMeters;
                }
            }

            // Map sequence back to 0-based order indices
            $waypointOrder = array_map(fn($idx) => $idx - 1, $sequence);

            \Log::info('Optimized route (nearest-neighbor)', [
                'waypoint_order' => $waypointOrder,
                'legs' => $legs,
                'total_minutes' => $totalDuration,
                'total_distance_km' => round($totalDistanceMeters / 1000, 1),
            ]);

            return [
                'waypoint_order' => $waypointOrder,
                'legs' => $legs,
                'total_duration' => $totalDuration,
                'total_distance_km' => round($totalDistanceMeters / 1000, 1),
            ];

        } catch (\Exception $e) {
            \Log::error('Route optimization failed', ['error' => $e->getMessage()]);
            return null;
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
            
            // Determine old payment type - treat NULL/empty as 'cash' (default)
            // This handles orders created from mobile without explicit payment method
            $normalizedOldMethod = strtolower(trim($oldPaymentMethod ?? ''));
            $oldPaymentType = (empty($normalizedOldMethod) || in_array($normalizedOldMethod, ['cash', 'cash_on_delivery', 'cod'])) 
                ? 'cash' 
                : 'online';
            
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
     * Mark that the WhatsApp payment reminder message was sent for an online order
     * This only sets a flag - does NOT affect delivery status, ledger, or settlement flow
     */
    public function markOnlineMessageSent(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            $order = \App\Models\CRM\OrderModel::find($id);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            // Order must be delivered
            if (!in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order must be delivered first',
                ], 400);
            }

            // Verify it's an online/bank transfer order
            $paymentMethod = strtolower($order->payment_method ?? 'cash');
            $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
            if ($isCash) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is not an online payment order',
                ], 400);
            }

            // Set the message sent flag (simple flag - no ledger/settlement impact)
            $order->online_message_sent_at = now();
            $order->online_message_sent_by = $user->id;
            $order->save();

            \Log::info('Online payment WhatsApp message marked as sent', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'rider_id' => $user->id,
                'rider_name' => $user->fullname,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message status updated',
                'online_message_sent_at' => $order->online_message_sent_at->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to mark online message sent', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update message status: ' . $e->getMessage(),
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

            // Use CALCULATED balance for employee_cash accounts
            // This excludes salary/personal transactions - only tracks company cash held
            $balance = $account->getCalculatedBalance();

            // Get recent transactions that affect balance (last 30 days)
            // For employee_cash: excludes salary_payment, salary_advance, etc.
            $recentTransactions = $account->getBalanceAffectingTransactions(30)
                ->map(function($txn) use ($account) {
                    return [
                        'id' => $txn->id,
                        'date' => $txn->transaction_date->format('Y-m-d'),
                        'type' => $txn->transaction_type,
                        'description' => $txn->description,
                        'amount' => $txn->amount,
                        'amount_formatted' => 'Rs. ' . number_format($txn->amount, 0),
                        'is_debit' => $txn->from_account_id == $account->id,
                        'is_credit' => $txn->to_account_id == $account->id,
                    ];
                });

            // Get outstanding invoices count and total
            // IMPORTANT: Include both 'open' and 'partial' statuses, exclude REVERSED transactions
            $outstandingInvoices = \App\Models\FIN\LedgerModel::where('to_account_id', $account->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->where('approval_status', '!=', \App\Models\FIN\LedgerModel::STATUS_REVERSED)
                ->get();

            $totalOutstanding = $outstandingInvoices->sum(function($invoice) {
                return $invoice->amount - ($invoice->settled_amount ?? 0);
            });

            // ⭐ Get loan, advance, and petty cash balances for display in rider mode
            $loanBalance = 0;
            $advanceBalance = 0;
            $pettyCash = $account->petty_cash ?? 0;
            
            if ($account->user_id) {
                // Get total outstanding loan balance
                $loanBalance = \App\Models\HR\EmployeeLoanModel::where('user_id', $account->user_id)
                    ->where('loan_status', 'active')
                    ->sum('outstanding_balance');
                
                // Get total pending salary advances (from approved but not settled requests)
                $advanceBalance = \App\Models\Request\RequestModel::where('requester_user_id', $account->user_id)
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'salary_advance');
                    })
                    ->where('status', 'approved')
                    ->where(function($q) {
                        $q->whereNull('settlement_status')
                          ->orWhere('settlement_status', '!=', 'settled');
                    })
                    ->sum('amount');
            }

            return response()->json([
                'success' => true,
                'account_id' => $account->id,
                'balance' => $balance,
                'balance_formatted' => 'Rs. ' . number_format(abs($balance), 2),
                'balance_type' => $balance >= 0 ? 'You are owed' : 'You owe',
                'outstanding_invoices' => [
                    'count' => $outstandingInvoices->count(),
                    'total' => $totalOutstanding,
                    'total_formatted' => 'Rs. ' . number_format($totalOutstanding, 0),
                ],
                'loan_balance' => round($loanBalance, 0),
                'loan_balance_formatted' => 'Rs. ' . number_format($loanBalance, 0),
                'advance_balance' => round($advanceBalance, 0),
                'advance_balance_formatted' => 'Rs. ' . number_format($advanceBalance, 0),
                'petty_cash' => round($pettyCash, 0),
                'petty_cash_formatted' => 'Rs. ' . number_format($pettyCash, 0),
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
            $user = Auth::user();
            
            // Check if user has admin role (super_admin or admin type)
            $isAdmin = $user->roles()
                ->whereIn('type', ['super_admin', 'admin'])
                ->exists();
            
            if ($isAdmin) {
                // Admin users: Get all categories from existing expenses
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
            } else {
                // Non-admin users: Limited categories only
                $categories = ['Petrol', 'Maintenance', 'PENDING'];
            }

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
     * Get expense categories from config table (for expense request form dropdown)
     * Same data source as the web expense form
     * ⭐ Supports business_unit_id filter for Khaas mode
     */
    public function getExpenseCategoriesFromConfig(Request $request)
    {
        try {
            $businessUnitId = $request->input('business_unit_id');
            
            $query = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'EXPENSE_CATEGORY_%');
            
            // ⭐ Filter by business unit if provided
            // NF categories (BU 1) may have business_unit_id = NULL in config
            if ($businessUnitId) {
                if ($businessUnitId == 1) {
                    $query->where(function($q) {
                        $q->where('business_unit_id', 1)
                          ->orWhereNull('business_unit_id');
                    });
                } else {
                    $query->where('business_unit_id', $businessUnitId);
                }
            }
            
            $categories = $query->orderBy('config_value')
                ->pluck('config_value')
                ->toArray();

            return response()->json([
                'success' => true,
                'categories' => $categories,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get expense categories from config', [
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
            
            // CRITICAL FIX: Allow fetching invoices for another user (for NF Ledger settle functionality)
            // If user_id is provided in request, use that (requires permission check)
            // Otherwise, use the logged-in user's ID (rider mode)
            $targetUserId = $request->input('user_id', $user->id);
            
            // Security check: Only allow fetching other users' invoices if user has store mode permission
            if ($targetUserId != $user->id) {
                if (!$user->hasMobilePermission('view_nf_ledger')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to view other users\' invoices',
                    ], 403);
                }
            }
            
            // Get account for the target user
            $account = \App\Models\FIN\AccountModel::where('user_id', $targetUserId)
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
            
            // CRITICAL FIX: Allow settling for another user (for NF Ledger settle functionality)
            // If user_id is provided in request, use that (requires permission check)
            // Otherwise, use the logged-in user's ID (rider mode)
            $targetUserId = $request->input('user_id', $user->id);
            
            // Security check: Only allow settling other users' accounts if user has store mode permission
            if ($targetUserId != $user->id) {
                if (!$user->hasMobilePermission('view_nf_ledger')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to settle other users\' accounts',
                    ], 403);
                }
            }
            
            // Get account for the target user
            $account = \App\Models\FIN\AccountModel::where('user_id', $targetUserId)
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

            // Verify selected invoices (include both open/partial invoices and qurbani order_payment entries)
            $selectedInvoices = \App\Models\FIN\LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $account->id)
                ->whereIn('transaction_type', [\App\Models\FIN\LedgerModel::TYPE_INVOICE, \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT])
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Determine destination based on whether these are qurbani order payments
            $hasQurbaniPayments = $selectedInvoices->contains(function ($inv) {
                if ($inv->transaction_type !== \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT) return false;
                if (!$inv->order_id) return false;
                $order = \App\Models\CRM\OrderModel::find($inv->order_id);
                return $order && (str_starts_with($order->order_number ?? '', 'QUR') || !empty($order->qurbani_day));
            });
            $hasRegularInvoices = $selectedInvoices->contains(fn($inv) => $inv->transaction_type === \App\Models\FIN\LedgerModel::TYPE_INVOICE);

            if ($hasQurbaniPayments && $hasRegularInvoices) {
                throw new \Exception("Cannot settle qurbani payments and regular invoices together. Please settle them separately.");
            }

            $destinationAccount = $hasQurbaniPayments
                ? \App\Models\FIN\ConfigModel::getQurbaniCashAccount()
                : \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
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
            
            // CRITICAL FIX: Allow settling for another user (for NF Ledger settle functionality)
            // If user_id is provided in request, use that (requires permission check)
            // Otherwise, use the logged-in user's ID (rider mode)
            $targetUserId = $request->input('user_id', $user->id);
            
            // Security check: Only allow settling other users' accounts if user has store mode permission
            if ($targetUserId != $user->id) {
                if (!$user->hasMobilePermission('view_nf_ledger')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to settle other users\' accounts',
                    ], 403);
                }
            }
            
            // Get account for the target user
            $account = \App\Models\FIN\AccountModel::where('user_id', $targetUserId)
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

            // Verify selected invoices (include both open/partial invoices and qurbani order_payment entries)
            $selectedInvoices = \App\Models\FIN\LedgerModel::whereIn('id', $request->invoice_ids)
                ->where('to_account_id', $account->id)
                ->whereIn('transaction_type', [\App\Models\FIN\LedgerModel::TYPE_INVOICE, \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT])
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();

            if ($selectedInvoices->count() !== count($request->invoice_ids)) {
                throw new \Exception("Some selected invoices are invalid or already settled");
            }

            // Determine destination based on qurbani vs regular
            $hasQurbaniPayments = $selectedInvoices->contains(function ($inv) {
                if ($inv->transaction_type !== \App\Models\FIN\LedgerModel::TYPE_ORDER_PAYMENT) return false;
                if (!$inv->order_id) return false;
                $order = \App\Models\CRM\OrderModel::find($inv->order_id);
                return $order && (str_starts_with($order->order_number ?? '', 'QUR') || !empty($order->qurbani_day));
            });
            $hasRegularInvoices = $selectedInvoices->contains(fn($inv) => $inv->transaction_type === \App\Models\FIN\LedgerModel::TYPE_INVOICE);

            if ($hasQurbaniPayments && $hasRegularInvoices) {
                throw new \Exception("Cannot settle qurbani payments and regular invoices together. Please settle them separately.");
            }

            $destinationAccount = $hasQurbaniPayments
                ? \App\Models\FIN\ConfigModel::getQurbaniCashAccount()
                : \App\Models\FIN\ConfigModel::getNFCashAccount();
            if (!$destinationAccount) {
                throw new \Exception("Destination account not found");
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
                    'expense_date' => now()->toDateString(), // ⭐ Always set expense_date
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

            // Get user's assigned office location
            $assignedLocation = \DB::table('t_ops_user_location_assignment as ula')
                ->join('t_ops_company_locations as loc', 'loc.id', '=', 'ula.location_id')
                ->where('ula.user_id', $user->id)
                ->where('ula.is_active', 1)
                ->where('loc.is_active', 1)
                ->select('loc.location_name', 'loc.latitude', 'loc.longitude', 'loc.radius_meters')
                ->first();

            // If no assigned location, get primary location
            if (!$assignedLocation) {
                $assignedLocation = \DB::table('t_ops_company_locations')
                    ->where('is_primary', 1)
                    ->where('is_active', 1)
                    ->select('location_name', 'latitude', 'longitude', 'radius_meters')
                    ->first();
            }

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
                    'checkin_latitude' => $attendance->checkin_latitude ?? null,
                    'checkin_longitude' => $attendance->checkin_longitude ?? null,
                    'checkin_distance_from_base' => $attendance->checkin_distance_from_base ?? null,
                    'is_remote_checkin' => $attendance->is_remote_checkin ?? 0,
                    'meter_start' => $attendance->meter_start ? (int) $attendance->meter_start : null,
                    'meter_end' => $attendance->meter_end ? (int) $attendance->meter_end : null,
                ] : null,
                'assigned_location' => $assignedLocation ? [
                    'name' => $assignedLocation->location_name,
                    'latitude' => $assignedLocation->latitude,
                    'longitude' => $assignedLocation->longitude,
                    'radius_meters' => $assignedLocation->radius_meters,
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
     * Optionally accepts meter picture and GPS location
     */
    public function checkIn(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            // Validate optional meter picture and location data
            $request->validate([
                'meter_picture' => 'nullable|image|max:5120', // 5MB max
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'accuracy' => 'nullable|numeric',
            ]);

            // Check if already checked in today
            $existing = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->first();

            if ($existing && $existing->login_time) {
                return response()->json(['success' => false, 'message' => 'Already checked in today'], 400);
            }

            // Process location data for check-in
            $locationData = $this->processCheckinLocation($request, $user->id);

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
                // Add location data
                if ($locationData) {
                    $updateData = array_merge($updateData, $locationData['db_fields']);
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
                
                // Add location data
                if ($locationData) {
                    $insertData = array_merge($insertData, $locationData['db_fields']);
                }
                
                \DB::table('t_ops_attendance')->insert($insertData);
            }

            // Prepare response message with location info
            $message = 'Checked in successfully at ' . date('h:i A', strtotime($currentTime));
            $responseData = [
                'success' => true,
                'message' => $message,
                'login_time' => $currentTime,
                'picture_url' => $picturePath ? $this->getMeterPictureUrl($picturePath) : null,
                'location_captured' => $locationData ? true : false,
            ];

            if ($locationData) {
                $responseData['is_remote'] = $locationData['is_remote'];
                $responseData['distance'] = $locationData['distance'];
                
                if ($locationData['is_remote']) {
                    $distanceFormatted = LocationService::formatDistance($locationData['distance']);
                    $responseData['message'] = $message . " ⚠️ Remote: {$distanceFormatted} from office";
                }
            }

            return response()->json($responseData);
        } catch (\Exception $e) {
            \Log::error('Failed to check in', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['success' => false, 'message' => 'Failed to check in: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Process check-in location data and calculate distance from base
     * 
     * @param Request $request
     * @param int $userId
     * @return array|null Location data or null if not provided
     */
    private function processCheckinLocation(Request $request, int $userId)
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $accuracy = $request->input('accuracy');
        $source = $request->input('source'); // fresh_gps, recent_gps, network_cached
        $method = $request->input('method'); // 1, 2, or 3

        if (is_null($latitude) || is_null($longitude)) {
            \Log::warning('📍 ATTENDANCE CHECK-IN: No location provided', [
                'user_id' => $userId,
                'date' => now()->toDateString()
            ]);
            return null;
        }

        // Validate coordinates
        if (!LocationService::isValidCoordinates($latitude, $longitude)) {
            \Log::error('📍 ATTENDANCE CHECK-IN: Invalid GPS coordinates', [
                'user_id' => $userId,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);
            return null;
        }

        // Calculate distance from base (using user's assigned location)
        $distanceInfo = LocationService::calculateDistanceFromBase($latitude, $longitude, $userId);

        // ⭐ Detailed logging for attendance location tracking
        $methodLabels = [
            1 => 'Fresh GPS (Method 1 - Best)',
            2 => 'Recent GPS Cache (Method 2)',
            3 => 'Network/Fallback (Method 3 - ⚠️ Check GPS)',
        ];
        $methodLabel = $methodLabels[$method] ?? "Unknown (Method {$method})";
        
        \Log::info('📍 ATTENDANCE CHECK-IN: Location captured', [
            'user_id' => $userId,
            'user_name' => \DB::table('t_sys_user')->where('id', $userId)->value('fullname'),
            'date' => now()->toDateString(),
            'time' => now()->format('H:i:s'),
            'location_method' => $methodLabel,
            'source' => $source ?? 'unknown',
            'accuracy_meters' => $accuracy,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance_from_office' => $distanceInfo['distance_meters'] ? round($distanceInfo['distance_meters']) . 'm' : 'N/A',
            'is_remote' => $distanceInfo['is_remote'] ? 'YES' : 'NO',
            'office_location' => $distanceInfo['base_location']->location_name ?? 'N/A',
        ]);

        // Log warning if fallback method was used (method 3)
        if ($method == 3) {
            \Log::warning('📍 ATTENDANCE: Fallback location used - GPS may have been unavailable', [
                'user_id' => $userId,
                'accuracy' => $accuracy,
                'recommendation' => 'User should ensure GPS is enabled and try outdoors'
            ]);
        }

        if ($distanceInfo['error']) {
            \Log::error('📍 ATTENDANCE CHECK-IN: Distance calculation failed', [
                'user_id' => $userId,
                'error' => $distanceInfo['error']
            ]);
        }

        return [
            'db_fields' => [
                'checkin_latitude' => $latitude,
                'checkin_longitude' => $longitude,
                'checkin_accuracy' => $accuracy,
                'checkin_distance_from_base' => $distanceInfo['distance_meters'],
                'checkin_location_captured_at' => now(),
                'is_remote_checkin' => $distanceInfo['is_remote'] ? 1 : 0,
            ],
            'is_remote' => $distanceInfo['is_remote'],
            'distance' => $distanceInfo['distance_meters'],
        ];
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
     * Get petrol rate groups with assigned users
     */
    public function getPetrolRate(Request $request)
    {
        try {
            $groups = DB::table('t_fin_petrol_rate_group')
                ->where('is_active', 1)
                ->orderBy('name')
                ->get();

            // Build groups with user details
            $rateGroups = [];
            foreach ($groups as $group) {
                $users = [];
                if (!empty($group->user_ids)) {
                    $ids = array_map('trim', explode(',', $group->user_ids));
                    $ids = array_filter($ids);
                    $users = DB::table('t_sys_user')
                        ->whereIn('id', $ids)
                        ->select('id', 'fullname')
                        ->get()
                        ->map(fn($u) => ['id' => $u->id, 'name' => $u->fullname])
                        ->values()
                        ->toArray();
                }
                $rateGroups[] = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'rate' => (float) $group->rate,
                    'user_ids' => $group->user_ids ?: '',
                    'users' => $users,
                ];
            }

            // Get all visible active users for the picker
            $allRiders = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->where('u.is_active', 1)
                ->where(function($q) {
                    $q->whereNull('av.is_visible')
                      ->orWhere('av.is_visible', 1);
                })
                ->select('u.id', 'u.fullname')
                ->orderBy('u.fullname')
                ->get()
                ->map(fn($u) => ['id' => $u->id, 'name' => $u->fullname])
                ->values();

            // Legacy: return first group's rate for backward compat
            $primaryRate = count($rateGroups) > 0 ? $rateGroups[0]['rate'] : 10;
            $allUserIds = collect($rateGroups)->pluck('user_ids')->filter()->implode(',');

            return response()->json([
                'success' => true,
                'petrol_rate' => $primaryRate,
                'allowed_user_ids' => $allUserIds,
                'rate_groups' => $rateGroups,
                'all_riders' => $allRiders,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Save petrol rate groups - admin only
     */
    public function setPetrolRate(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json(['success' => false, 'message' => 'Permission denied'], 403);
            }

            $validated = $request->validate([
                'groups' => 'required|array|min:1',
                'groups.*.id' => 'nullable|integer',
                'groups.*.name' => 'required|string|max:100',
                'groups.*.rate' => 'required|numeric|min:0.01|max:999',
                'groups.*.user_ids' => 'nullable|string',
            ]);

            // Validate no user appears in multiple groups
            $allAssignedIds = [];
            foreach ($validated['groups'] as $group) {
                if (!empty($group['user_ids'])) {
                    $ids = array_map('trim', explode(',', $group['user_ids']));
                    $ids = array_filter($ids);
                    foreach ($ids as $id) {
                        if (in_array($id, $allAssignedIds)) {
                            $userName = DB::table('t_sys_user')->where('id', $id)->value('fullname') ?: "User #$id";
                            return response()->json([
                                'success' => false,
                                'message' => "$userName is assigned to multiple rate groups. Each user can only be in one group."
                            ], 422);
                        }
                        $allAssignedIds[] = $id;
                    }
                }
            }

            DB::beginTransaction();

            // Get existing group IDs
            $existingIds = DB::table('t_fin_petrol_rate_group')->where('is_active', 1)->pluck('id')->toArray();
            $incomingIds = array_filter(array_column($validated['groups'], 'id'));

            // Soft-delete groups that are no longer in the list
            $toDelete = array_diff($existingIds, $incomingIds);
            if (!empty($toDelete)) {
                DB::table('t_fin_petrol_rate_group')
                    ->whereIn('id', $toDelete)
                    ->update(['is_active' => 0, 'updated_at' => now()]);
            }

            // Upsert groups
            foreach ($validated['groups'] as $groupData) {
                if (!empty($groupData['id'])) {
                    DB::table('t_fin_petrol_rate_group')
                        ->where('id', $groupData['id'])
                        ->update([
                            'name' => $groupData['name'],
                            'rate' => $groupData['rate'],
                            'user_ids' => $groupData['user_ids'] ?? null,
                            'is_active' => 1,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('t_fin_petrol_rate_group')->insert([
                        'name' => $groupData['name'],
                        'rate' => $groupData['rate'],
                        'user_ids' => $groupData['user_ids'] ?? null,
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Keep legacy config keys in sync with first group for any code that still reads them
            $firstGroup = $validated['groups'][0] ?? null;
            if ($firstGroup) {
                \App\Models\FIN\ConfigModel::set('PETROL_RATE_PER_KM', (string) $firstGroup['rate'], 'Petrol rate (synced from rate groups)');
                \App\Models\FIN\ConfigModel::set('PETROL_AUTO_CALC_USER_IDS', implode(',', $allAssignedIds), 'Petrol user IDs (synced from rate groups)');
            }

            DB::commit();

            \Log::info('Petrol rate groups updated', [
                'groups_count' => count($validated['groups']),
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Petrol rate groups saved successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
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
     * ⭐ Calculate GPS distance from an array of location readings
     * Uses Haversine formula with noise filtering
     * 
     * @param array $readings Array of objects with latitude, longitude, accuracy, captured_at
     * @return array ['distance' => km, 'readings_count' => int]
     */
    private function calculateGpsDistanceFromReadings(array $readings): array
    {
        $totalDistance = 0;
        $validReadingsCount = count($readings);
        
        if ($validReadingsCount < 2) {
            return ['distance' => null, 'readings_count' => $validReadingsCount];
        }
        
        $minMovementMeters = 20; // Filter out GPS drift (< 20m considered stationary)
        $segmentsUsed = 0;
        
        for ($i = 1; $i < $validReadingsCount; $i++) {
            $prev = $readings[$i - 1];
            $curr = $readings[$i];
            
            // Calculate distance between consecutive points using Haversine
            $distanceMeters = $this->haversineDistance(
                (float) $prev->latitude,
                (float) $prev->longitude,
                (float) $curr->latitude,
                (float) $curr->longitude
            );
            
            // Filter out GPS noise - only count if movement > 20m
            // This handles stationary GPS drift
            if ($distanceMeters >= $minMovementMeters) {
                $totalDistance += $distanceMeters;
                $segmentsUsed++;
            }
        }
        
        // Convert to kilometers, round to 1 decimal
        $distanceKm = round($totalDistance / 1000, 1);
        
        return [
            'distance' => $distanceKm > 0 ? $distanceKm : null,
            'readings_count' => $validReadingsCount,
        ];
    }

    /**
     * ⭐ Haversine formula to calculate distance between two lat/lng points
     * 
     * @param float $lat1 Latitude of point 1
     * @param float $lng1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lng2 Longitude of point 2
     * @return float Distance in meters
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000; // Earth's radius in meters
        
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);
        
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1Rad) * cos($lat2Rad) *
             sin($deltaLng / 2) * sin($deltaLng / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadiusMeters * $c;
    }

    /**
     * ⭐ Calculate gap information for GPS readings
     * Identifies periods where GPS data is missing and whether rider was stationary
     * 
     * @param array $readings GPS readings array
     * @param string $loginTime HH:MM:SS
     * @param string|null $logoutTime HH:MM:SS or null if still working
     * @return array Gap summary info
     */
    private function calculateGapInfo(array $readings, string $loginTime, ?string $logoutTime): array
    {
        $minGapThreshold = 10; // Minutes - gaps less than this are normal
        $totalGapMinutes = 0;
        $stationaryGapMinutes = 0;
        $gapsCount = 0;
        $movingGapsCount = 0;
        
        if (count($readings) < 2) {
            return [
                'total_gap_minutes' => 0,
                'stationary_gap_minutes' => 0,
                'effective_gap_minutes' => 0,
                'gaps_count' => 0,
                'moving_gaps_count' => 0,
                'coverage_percent' => 0,
                'effective_coverage_percent' => 0,
            ];
        }
        
        // Calculate expected working minutes
        $loginTimestamp = strtotime($loginTime);
        $logoutTimestamp = $logoutTime ? strtotime($logoutTime) : time();
        $workingMinutes = ($logoutTimestamp - $loginTimestamp) / 60;
        
        // Check gap between login and first reading
        $firstReading = $readings[0];
        $firstReadingTime = strtotime(is_object($firstReading) ? $firstReading->captured_at : $firstReading['captured_at']);
        $gapFromLogin = ($firstReadingTime - $loginTimestamp) / 60;
        if ($gapFromLogin > $minGapThreshold) {
            $totalGapMinutes += $gapFromLogin;
            $gapsCount++;
            $movingGapsCount++; // Unknown if stationary at start
        }
        
        // Check gaps between consecutive readings
        for ($i = 1; $i < count($readings); $i++) {
            $prev = $readings[$i - 1];
            $curr = $readings[$i];
            
            $prevTime = strtotime(is_object($prev) ? $prev->captured_at : $prev['captured_at']);
            $currTime = strtotime(is_object($curr) ? $curr->captured_at : $curr['captured_at']);
            $gapMinutes = ($currTime - $prevTime) / 60;
            
            if ($gapMinutes > $minGapThreshold) {
                $gapsCount++;
                $totalGapMinutes += $gapMinutes;
                
                // Check if stationary during gap (< 50m movement)
                $distanceMeters = $this->haversineDistance(
                    (float) (is_object($prev) ? $prev->latitude : $prev['latitude']),
                    (float) (is_object($prev) ? $prev->longitude : $prev['longitude']),
                    (float) (is_object($curr) ? $curr->latitude : $curr['latitude']),
                    (float) (is_object($curr) ? $curr->longitude : $curr['longitude'])
                );
                
                if ($distanceMeters < 50) {
                    // Stationary gap - rider didn't move, so no distance lost
                    $stationaryGapMinutes += $gapMinutes;
                } else {
                    // Moving gap - rider moved but we don't have data
                    $movingGapsCount++;
                }
            }
        }
        
        // Check gap between last reading and logout
        if ($logoutTime) {
            $lastReading = $readings[count($readings) - 1];
            $lastReadingTime = strtotime(is_object($lastReading) ? $lastReading->captured_at : $lastReading['captured_at']);
            $gapToLogout = ($logoutTimestamp - $lastReadingTime) / 60;
            if ($gapToLogout > $minGapThreshold) {
                $totalGapMinutes += $gapToLogout;
                $gapsCount++;
                $movingGapsCount++; // Unknown if stationary at end
            }
        }
        
        // Effective gap = only gaps where we might have lost distance data
        $effectiveGapMinutes = $totalGapMinutes - $stationaryGapMinutes;
        
        // Coverage percentages
        $coveragePercent = $workingMinutes > 0 
            ? round((($workingMinutes - $totalGapMinutes) / $workingMinutes) * 100) 
            : 0;
        $effectiveCoveragePercent = $workingMinutes > 0 
            ? round((($workingMinutes - $effectiveGapMinutes) / $workingMinutes) * 100) 
            : 0;
        
        return [
            'total_gap_minutes' => round($totalGapMinutes),
            'stationary_gap_minutes' => round($stationaryGapMinutes),
            'effective_gap_minutes' => round($effectiveGapMinutes),
            'gaps_count' => $gapsCount,
            'moving_gaps_count' => $movingGapsCount,
            'coverage_percent' => min(100, max(0, $coveragePercent)),
            'effective_coverage_percent' => min(100, max(0, $effectiveCoveragePercent)),
        ];
    }

    /**
     * ⭐ Calculate ROAD distance using OpenRouteService API
     * This gives actual road distance instead of straight-line
     * Free tier: 2,000 requests/day
     * 
     * @param int $userId
     * @param string $date YYYY-MM-DD
     * @return array ['road_distance' => km, 'straight_distance' => km, 'readings_count' => int, 'source' => 'api'|'calculated']
     */
    public function calculateRoadDistance(Request $request)
    {
        try {
            $userId = $request->input('user_id');
            $date = $request->input('date', now()->toDateString());
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }
            
            // Get all GPS readings for the day
            $readings = \DB::table('t_ops_rider_location')
                ->where('user_id', $userId)
                ->whereDate('captured_at', $date)
                ->where('accuracy', '<=', 100)
                ->orderBy('captured_at')
                ->select('latitude', 'longitude', 'accuracy', 'captured_at')
                ->get();
            
            if ($readings->count() < 2) {
                return response()->json([
                    'success' => true,
                    'road_distance' => null,
                    'straight_distance' => null,
                    'readings_count' => $readings->count(),
                    'message' => 'Not enough GPS readings for distance calculation',
                    'source' => 'insufficient_data'
                ]);
            }
            
            // Calculate straight-line distance first (for comparison)
            $straightResult = $this->calculateGpsDistanceFromReadings($readings->toArray());
            
            // ⭐ IMPORTANT: Skip road distance API if rider barely moved (< 100m)
            // This prevents misleading results where OpenRouteService calculates a driving route
            // between nearby points that are just GPS drift, not actual travel
            // 100m threshold = reasonable to distinguish GPS drift from actual short trips
            if ($straightResult['distance'] === null || $straightResult['distance'] < 0.1) {
                return response()->json([
                    'success' => true,
                    'road_distance' => null,
                    'straight_distance' => $straightResult['distance'],
                    'readings_count' => $readings->count(),
                    'message' => 'Insufficient GPS movement (< 100m) - rider likely stationary',
                    'source' => 'skipped_stationary'
                ]);
            }
            
            // ⭐ Sample readings intelligently for API call
            // Using 25 samples with GPS drift filtering gives best accuracy
            $sampledReadings = $this->sampleGpsReadings($readings->toArray(), 25);
            
            if (count($sampledReadings) < 2) {
                return response()->json([
                    'success' => true,
                    'road_distance' => null,
                    'straight_distance' => $straightResult['distance'],
                    'readings_count' => $readings->count(),
                    'message' => 'Could not sample enough readings',
                    'source' => 'calculated'
                ]);
            }
            
            // ⭐ Call OpenRouteService Directions API
            $roadDistance = $this->callOpenRouteService($sampledReadings);
            
            if ($roadDistance === null) {
                // API failed, return straight-line as fallback
                return response()->json([
                    'success' => true,
                    'road_distance' => null,
                    'straight_distance' => $straightResult['distance'],
                    'readings_count' => $readings->count(),
                    'sampled_points' => count($sampledReadings),
                    'message' => 'Road distance API unavailable, showing straight-line estimate',
                    'source' => 'calculated'
                ]);
            }
            
            // ⭐ Store the calculated road distance in the database for future use
            $roundedRoadDistance = round($roadDistance, 1);
            \DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereDate('attendance_date', $date)
                ->update([
                    'road_distance_km' => $roundedRoadDistance,
                    'road_distance_source' => 'openrouteservice',
                    'gps_straight_distance_km' => $straightResult['distance'],
                    'gps_readings_used' => $readings->count(),
                ]);
            
            \Log::info("Road distance stored for user {$userId} on {$date}: {$roundedRoadDistance} km");
            
            return response()->json([
                'success' => true,
                'road_distance' => $roundedRoadDistance,
                'straight_distance' => $straightResult['distance'],
                'readings_count' => $readings->count(),
                'sampled_points' => count($sampledReadings),
                'accuracy_ratio' => $straightResult['distance'] > 0 
                    ? round($roadDistance / $straightResult['distance'] * 100) . '%' 
                    : null,
                'source' => 'openrouteservice',
                'stored' => true, // ⭐ Indicate the value was stored
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Road distance calculation failed', [
                'error' => $e->getMessage(),
                'user_id' => $request->input('user_id'),
                'date' => $request->input('date')
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate road distance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ Sample GPS readings for road distance API call
     * 
     * Strategy:
     * 1. Filter out GPS DRIFT - consecutive readings within ~30m are noise
     * 2. Then sample evenly from filtered points to get max 50 waypoints
     * 
     * This removes fake distance from GPS drift while preserving actual route
     * 
     * @param array $readings GPS readings array
     * @param int $maxPoints Maximum number of points (OpenRouteService limit is 50)
     * @return array Sampled readings
     */
    private function sampleGpsReadings(array $readings, int $maxPoints = 25): array
    {
        $count = count($readings);
        
        // Handle edge cases
        if ($count <= 2) {
            return $readings;
        }
        
        // ⭐ Step 1: Filter GPS drift - remove consecutive points within ~30m
        // GPS drift = small fake movements when rider is stationary
        // 0.0003 degrees ≈ 30 meters - good threshold for drift vs real movement
        $filtered = [];
        $prevLat = null;
        $prevLng = null;
        $driftThreshold = 0.0003; // ~30m - filters GPS noise but keeps real movements
        
        foreach ($readings as $reading) {
            $lat = (float)(is_object($reading) ? $reading->latitude : ($reading['latitude'] ?? 0));
            $lng = (float)(is_object($reading) ? $reading->longitude : ($reading['longitude'] ?? 0));
            
            // Include point if it's the first OR moved more than drift threshold
            if ($prevLat === null || 
                abs($lat - $prevLat) > $driftThreshold || 
                abs($lng - $prevLng) > $driftThreshold) {
                $filtered[] = $reading;
                $prevLat = $lat;
                $prevLng = $lng;
            }
        }
        
        $filteredCount = count($filtered);
        
        // Safety: if filtering removed too much, use original
        if ($filteredCount < 2) {
            $filtered = $readings;
            $filteredCount = $count;
        }
        
        // ⭐ Step 2: If under limit, return filtered points
        if ($filteredCount <= $maxPoints) {
            return $filtered;
        }
        
        // ⭐ Step 3: Sample evenly from filtered points
        $sampled = [];
        $step = ($filteredCount - 1) / ($maxPoints - 1);
        
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            if ($index < $filteredCount) {
                $sampled[] = $filtered[$index];
            }
        }
        
        // Always ensure first and last points are included
        $first = $filtered[0];
        $last = $filtered[$filteredCount - 1];
        
        // Check first
        $firstLat = (float)(is_object($first) ? $first->latitude : ($first['latitude'] ?? 0));
        $firstLng = (float)(is_object($first) ? $first->longitude : ($first['longitude'] ?? 0));
        $sampledFirst = $sampled[0];
        $sFirstLat = (float)(is_object($sampledFirst) ? $sampledFirst->latitude : ($sampledFirst['latitude'] ?? 0));
        $sFirstLng = (float)(is_object($sampledFirst) ? $sampledFirst->longitude : ($sampledFirst['longitude'] ?? 0));
        
        if (abs($firstLat - $sFirstLat) > 0.00001 || abs($firstLng - $sFirstLng) > 0.00001) {
            array_unshift($sampled, $first);
        }
        
        // Check last
        $lastLat = (float)(is_object($last) ? $last->latitude : ($last['latitude'] ?? 0));
        $lastLng = (float)(is_object($last) ? $last->longitude : ($last['longitude'] ?? 0));
        $sampledLast = end($sampled);
        $sLastLat = (float)(is_object($sampledLast) ? $sampledLast->latitude : ($sampledLast['latitude'] ?? 0));
        $sLastLng = (float)(is_object($sampledLast) ? $sampledLast->longitude : ($sampledLast['longitude'] ?? 0));
        
        if (abs($lastLat - $sLastLat) > 0.00001 || abs($lastLng - $sLastLng) > 0.00001) {
            $sampled[] = $last;
        }
        
        return $sampled;
    }

    /**
     * ⭐ Call OpenRouteService Directions API
     * Free tier: 2,000 requests/day
     * Docs: https://openrouteservice.org/dev/#/api-docs/v2/directions
     */
    private function callOpenRouteService(array $readings): ?float
    {
        // OpenRouteService API key (free tier)
        // Get yours at: https://openrouteservice.org/dev/#/signup
        $apiKey = env('OPENROUTESERVICE_API_KEY', '5b3ce3597851110001cf62487c37b3c0b8d74b9fb9f7d9f3c3d7f8e9');
        
        // Build coordinates array [lng, lat] format (GeoJSON standard)
        $coordinates = array_map(function($reading) {
            return [
                (float) (is_object($reading) ? $reading->longitude : $reading['longitude']),
                (float) (is_object($reading) ? $reading->latitude : $reading['latitude'])
            ];
        }, $readings);
        
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);
            
            $response = $client->post('https://api.openrouteservice.org/v2/directions/driving-car', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'coordinates' => $coordinates,
                    'instructions' => false,        // We don't need turn-by-turn
                    'geometry' => false,            // We don't need the polyline
                    'units' => 'km',
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            // Extract total distance from response
            if (isset($data['routes'][0]['summary']['distance'])) {
                return $data['routes'][0]['summary']['distance']; // Already in km
            }
            
            \Log::warning('OpenRouteService response missing distance', ['data' => $data]);
            return null;
            
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response';
            \Log::warning('OpenRouteService API client error', [
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'body' => $responseBody,
                'coordinates_count' => count($coordinates)
            ]);
            return null;
            
        } catch (\Exception $e) {
            \Log::warning('OpenRouteService API error', [
                'error' => $e->getMessage(),
                'coordinates_count' => count($coordinates)
            ]);
            return null;
        }
    }

    /**
     * Upload meter picture independently (after check-in/out)
     */
    public function uploadMeterPicture(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');

            // Validate request - ⭐ meter_reading is optional (from OCR)
            $request->validate([
                'meter_picture' => 'required|image|max:5120', // 5MB max
                'type' => 'required|in:start,end',
                'meter_reading' => 'nullable|numeric|min:0|max:9999999', // ⭐ OCR-extracted reading
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
            $meterReading = $request->input('meter_reading'); // ⭐ Get OCR reading
            
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
                // ⭐ Save OCR-extracted meter reading to meter_start column
                if ($meterReading !== null && $meterReading !== '') {
                    $updateData['meter_start'] = (int) $meterReading;
                }
            } else {
                $updateData['picture_end'] = $picturePath;
                // ⭐ Save OCR-extracted meter reading to meter_end column
                if ($meterReading !== null && $meterReading !== '') {
                    $updateData['meter_end'] = (int) $meterReading;
                }
            }

            \DB::table('t_ops_attendance')
                ->where('id', $existing->id)
                ->update($updateData);

            // ⭐ Build success message with reading info
            $message = 'Meter picture uploaded successfully';
            if ($meterReading !== null && $meterReading !== '') {
                $message .= ' with reading: ' . number_format((int) $meterReading);
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'picture_url' => $this->getMeterPictureUrl($picturePath),
                'meter_reading' => $meterReading !== null && $meterReading !== '' ? (int) $meterReading : null, // ⭐ Return saved reading
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
     * Optionally accepts meter picture and GPS location
     */
    public function checkOut(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');
            $currentTime = now()->format('H:i:s');

            // Validate optional meter picture and location data
            $request->validate([
                'meter_picture' => 'nullable|image|max:5120', // 5MB max
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'accuracy' => 'nullable|numeric',
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

            // Process location data for check-out (no distance calculation)
            $locationData = $this->processCheckoutLocation($request, $user->id);

            // Store meter picture if provided
            $picturePath = null;
            if ($request->hasFile('meter_picture')) {
                $picturePath = $this->storeMeterPicture($request->file('meter_picture'), $user->id, 'checkout');
            }

            // Update with logout time, picture, and location
            $updateData = [
                'logout_time' => $currentTime,
                'updated_at' => now(),
            ];
            if ($picturePath) {
                $updateData['picture_end'] = $picturePath;
            }
            // Add location data
            if ($locationData) {
                $updateData = array_merge($updateData, $locationData);
            }

            \DB::table('t_ops_attendance')
                ->where('id', $existing->id)
                ->update($updateData);

            // ⭐ ROAD DISTANCE: Calculate and store after successful checkout
            // This runs in background - doesn't block the checkout response
            $roadDistanceResult = null;
            try {
                $roadDistanceResult = $this->calculateAndStoreRoadDistance($user->id, $today, $existing->id);
            } catch (\Exception $rdError) {
                // Non-fatal - log but don't fail checkout
                \Log::warning('Road distance calculation failed (non-fatal)', [
                    'user_id' => $user->id,
                    'date' => $today,
                    'error' => $rdError->getMessage()
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Checked out successfully at ' . date('h:i A', strtotime($currentTime)),
                'logout_time' => $currentTime,
                'picture_url' => $picturePath ? $this->getMeterPictureUrl($picturePath) : null,
                'location_captured' => $locationData ? true : false,
                'road_distance' => $roadDistanceResult, // ⭐ Include calculated road distance
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
     * Process check-out location data (no distance calculation needed)
     * 
     * @param Request $request
     * @param int $userId
     * @return array|null Location data or null if not provided
     */
    private function processCheckoutLocation(Request $request, int $userId)
    {
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');

        if (is_null($latitude) || is_null($longitude)) {
            \Log::info('Check-out without location', [
                'user_id' => $userId,
                'date' => now()->toDateString()
            ]);
            return null;
        }

        // Validate coordinates
        if (!LocationService::isValidCoordinates($latitude, $longitude)) {
            \Log::error('Invalid GPS coordinates on check-out', [
                'user_id' => $userId,
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);
            return null;
        }

        // For check-out: Just capture location, no distance calculation or remote flag
        return [
            'checkout_latitude' => $latitude,
            'checkout_longitude' => $longitude,
            'checkout_accuracy' => $request->input('accuracy'),
            'checkout_location_captured_at' => now(),
        ];
    }

    /**
     * ⭐ Calculate and store road distance for attendance
     * Called automatically on checkout or manually via button
     * 
     * @param int $userId
     * @param string $date YYYY-MM-DD
     * @param int|null $attendanceId Optional - if not provided, will look up
     * @return array|null Result with road_distance_km or null if failed
     */
    private function calculateAndStoreRoadDistance(int $userId, string $date, ?int $attendanceId = null): ?array
    {
        // Get attendance record if not provided
        if (!$attendanceId) {
            $attendance = \DB::table('t_ops_attendance')
                ->where('user_id', $userId)
                ->whereDate('attendance_date', $date)
                ->first();
            if (!$attendance) {
                return null;
            }
            $attendanceId = $attendance->id;
        }
        
        // Get all GPS readings for the day
        $readings = \DB::table('t_ops_rider_location')
            ->where('user_id', $userId)
            ->whereDate('captured_at', $date)
            ->where('accuracy', '<=', 100)
            ->orderBy('captured_at')
            ->select('latitude', 'longitude', 'accuracy', 'captured_at')
            ->get();
        
        // Not enough readings
        if ($readings->count() < 2) {
            \DB::table('t_ops_attendance')
                ->where('id', $attendanceId)
                ->update([
                    'road_distance_source' => 'insufficient_data',
                    'road_distance_calculated_at' => now(),
                    'gps_readings_used' => $readings->count(),
                ]);
            return [
                'road_distance_km' => null,
                'source' => 'insufficient_data',
                'readings_count' => $readings->count(),
            ];
        }
        
        // Calculate straight-line distance first
        $straightResult = $this->calculateGpsDistanceFromReadings($readings->toArray());
        
        // Skip API if rider barely moved (< 100m) - likely stationary
        if ($straightResult['distance'] === null || $straightResult['distance'] < 0.1) {
            \DB::table('t_ops_attendance')
                ->where('id', $attendanceId)
                ->update([
                    'road_distance_source' => 'skipped_stationary',
                    'road_distance_calculated_at' => now(),
                    'gps_straight_distance_km' => $straightResult['distance'],
                    'gps_readings_used' => $readings->count(),
                ]);
            return [
                'road_distance_km' => null,
                'straight_distance_km' => $straightResult['distance'],
                'source' => 'skipped_stationary',
                'readings_count' => $readings->count(),
            ];
        }
        
        // Sample readings for API call (max 25 waypoints)
        $sampledReadings = $this->sampleGpsReadings($readings->toArray(), 25);
        
        if (count($sampledReadings) < 2) {
            \DB::table('t_ops_attendance')
                ->where('id', $attendanceId)
                ->update([
                    'road_distance_source' => 'calculated',
                    'road_distance_calculated_at' => now(),
                    'gps_straight_distance_km' => $straightResult['distance'],
                    'gps_readings_used' => $readings->count(),
                ]);
            return [
                'road_distance_km' => null,
                'straight_distance_km' => $straightResult['distance'],
                'source' => 'calculated',
                'readings_count' => $readings->count(),
            ];
        }
        
        // Call OpenRouteService for road distance
        $roadDistance = $this->callOpenRouteService($sampledReadings);
        
        // Store result
        $updateData = [
            'road_distance_calculated_at' => now(),
            'gps_straight_distance_km' => $straightResult['distance'],
            'gps_readings_used' => $readings->count(),
        ];
        
        if ($roadDistance !== null) {
            $updateData['road_distance_km'] = round($roadDistance, 2);
            $updateData['road_distance_source'] = 'openrouteservice';
        } else {
            // API failed - store straight-line as fallback
            $updateData['road_distance_km'] = $straightResult['distance'];
            $updateData['road_distance_source'] = 'calculated';
        }
        
        \DB::table('t_ops_attendance')
            ->where('id', $attendanceId)
            ->update($updateData);
        
        return [
            'road_distance_km' => $updateData['road_distance_km'],
            'straight_distance_km' => $straightResult['distance'],
            'source' => $updateData['road_distance_source'],
            'readings_count' => $readings->count(),
            'sampled_points' => count($sampledReadings),
        ];
    }

    /**
     * ⭐ LOCATION TRACKING: Record rider's location heartbeat
     * Called every 5 minutes from mobile app when rider is checked in
     * Also performs daily cleanup of old location data (once per day)
     */
    public function locationHeartbeat(Request $request)
    {
        try {
            $user = Auth::user();
            $today = now()->format('Y-m-d');

            // Validate rider is checked in today (has login_time but no logout_time)
            $attendance = \DB::table('t_ops_attendance')
                ->where('user_id', $user->id)
                ->whereDate('attendance_date', $today)
                ->whereNotNull('login_time')
                ->whereNull('logout_time')
                ->first();

            if (!$attendance) {
                // ⭐ Log when we return 400 - helps diagnose why heartbeats stop
                $anyAttendanceToday = \DB::table('t_ops_attendance')
                    ->where('user_id', $user->id)
                    ->whereDate('attendance_date', $today)
                    ->first();
                    
                \Log::warning('📍 Heartbeat rejected - not checked in', [
                    'user_id' => $user->id,
                    'user_name' => $user->fullname,
                    'today' => $today,
                    'has_attendance_record' => $anyAttendanceToday ? 'yes' : 'no',
                    'login_time' => $anyAttendanceToday->login_time ?? null,
                    'logout_time' => $anyAttendanceToday->logout_time ?? null,
                    'source' => $request->input('source'),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Not checked in or already checked out'
                ], 400);
            }

            // Validate coordinates
            $latitude = $request->input('latitude');
            $longitude = $request->input('longitude');

            if (is_null($latitude) || is_null($longitude)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Location coordinates required'
                ], 400);
            }

            if (!LocationService::isValidCoordinates($latitude, $longitude)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid GPS coordinates'
                ], 400);
            }

            // Store location heartbeat
            $accuracy = $request->input('accuracy');
            $source = $request->input('source', 'heartbeat');
            
            // Ensure accuracy is a valid number or null
            if ($accuracy !== null && !is_numeric($accuracy)) {
                $accuracy = null;
            }
            
            // Truncate source to 20 chars (DB column limit)
            $sourceStr = is_string($source) ? substr($source, 0, 20) : 'heartbeat';
            
            \DB::table('t_ops_rider_location')->insert([
                'user_id' => $user->id,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
                'captured_at' => now(),
                'source' => $sourceStr,
                'created_at' => now(),
            ]);

            // ⭐ SMART DAILY CLEANUP: Only run once per day
            // Uses cache to track last cleanup date - first heartbeat of the day triggers cleanup
            // Keeps 20 days of location history
            $lastCleanup = \Cache::get('rider_location_last_cleanup');
            if ($lastCleanup !== $today) {
                $deletedCount = \DB::table('t_ops_rider_location')
                    ->where('captured_at', '<', now()->subDays(20))
                    ->delete();
                
                \Cache::put('rider_location_last_cleanup', $today, now()->addDays(2));
                
                if ($deletedCount > 0) {
                    \Log::info('Location data cleanup completed', [
                        'deleted_count' => $deletedCount,
                        'triggered_by_user' => $user->id
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Location recorded'
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to record location heartbeat', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to record location: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ LOCATION TRACKING: Log location failure
     * Records when the app tried to get location but failed
     * Helps diagnose GPS gaps in the audit
     */
    public function logLocationFailure(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                \Log::warning('📍 Location failure log rejected: unauthorized');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            
            // ⭐ Log incoming request for debugging
            \Log::info('📍 Location failure API called', [
                'user_id' => $user->id,
                'request_data' => $request->all(),
            ]);
            
            // Validate input - be lenient with last_success_at
            $validated = $request->validate([
                'failure_reason' => 'required|string|max:50',
                'failure_source' => 'nullable|string|max:30',
                'error_message' => 'nullable|string|max:255',
                'device_online' => 'nullable',
                'last_known_lat' => 'nullable|numeric',
                'last_known_lng' => 'nullable|numeric',
                'last_success_at' => 'nullable|string', // Changed from date to string
                'app_state' => 'nullable|string|max:20',
                'app_version' => 'nullable|string|max:20',
            ]);
            
            // ⭐ Parse last_success_at if provided (handle various formats)
            $lastSuccessAt = null;
            if (!empty($validated['last_success_at'])) {
                try {
                    $lastSuccessAt = \Carbon\Carbon::parse($validated['last_success_at']);
                } catch (\Exception $e) {
                    \Log::warning('📍 Could not parse last_success_at', [
                        'value' => $validated['last_success_at'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // ⭐ Use raw SQL INSERT for maximum reliability
            $insertData = [
                'user_id' => (int)$user->id,
                'failure_reason' => $validated['failure_reason'],
                'failure_source' => $validated['failure_source'] ?? 'unknown',
                'error_message' => isset($validated['error_message']) ? substr($validated['error_message'], 0, 255) : null,
                'device_online' => isset($validated['device_online']) ? ($validated['device_online'] ? 1 : 0) : null,
                'last_known_lat' => $validated['last_known_lat'] ?? null,
                'last_known_lng' => $validated['last_known_lng'] ?? null,
                'last_success_at' => $lastSuccessAt ? $lastSuccessAt->toDateTimeString() : null,
                'app_state' => $validated['app_state'] ?? 'unknown',
                'app_version' => $validated['app_version'] ?? null,
                'captured_at' => now()->toDateTimeString(),
            ];
            
            \Log::info('📍 Attempting insert with data', $insertData);
            
            // ⭐ Try raw INSERT statement
            $inserted = \DB::statement(
                "INSERT INTO t_ops_location_failures 
                (user_id, failure_reason, failure_source, error_message, device_online, 
                 last_known_lat, last_known_lng, last_success_at, app_state, app_version, captured_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $insertData['user_id'],
                    $insertData['failure_reason'],
                    $insertData['failure_source'],
                    $insertData['error_message'],
                    $insertData['device_online'],
                    $insertData['last_known_lat'],
                    $insertData['last_known_lng'],
                    $insertData['last_success_at'],
                    $insertData['app_state'],
                    $insertData['app_version'],
                    $insertData['captured_at'],
                ]
            );
            
            \Log::info('📍 Location failure logged to DB successfully', [
                'user_id' => $user->id,
                'reason' => $validated['failure_reason'],
                'inserted' => $inserted,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Failure logged',
                'debug' => 'inserted=' . ($inserted ? 'true' : 'false'),
            ]);
            
        } catch (\Exception $e) {
            // Log the actual error for debugging
            \Log::error('❌ Failed to log location failure (EXCEPTION)', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => Auth::id(),
            ]);
            
            // Return success anyway - don't block the app, but include error for debugging
            return response()->json([
                'success' => true,
                'message' => 'Noted',
                'debug_error' => substr($e->getMessage(), 0, 100),
            ]);
        }
    }

    /**
     * ⭐ LOCATION TRACKING: Get all active riders for map view
     * Returns riders who are checked in today OR have recent location data
     * 
     * @param date - Optional date for viewing history (YYYY-MM-DD)
     */
    public function getActiveRidersForMap(Request $request)
    {
        try {
            // Check if viewing history or live
            $requestedDate = $request->get('date');
            $isHistory = $requestedDate && $requestedDate !== now()->format('Y-m-d');
            $targetDate = $requestedDate ?: now()->format('Y-m-d');
            $today = now()->format('Y-m-d');
            
            // Get riders who are:
            // 1. Checked in on target date (with or without checkout), OR
            // 2. Have location data on that date (for history) or in last 24 hours (for live)
            // 3. For history: riders who delivered orders on that date
            $riders = \DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance as a', function($join) use ($targetDate) {
                    $join->on('u.id', '=', 'a.user_id')
                         ->whereDate('a.attendance_date', $targetDate);
                });
            
            if ($isHistory) {
                // For history, get location data from that specific date
                $riders->leftJoin(\DB::raw("(
                    SELECT user_id, 
                           MAX(captured_at) as last_location_at,
                           SUBSTRING_INDEX(GROUP_CONCAT(latitude ORDER BY captured_at DESC), ',', 1) as last_lat,
                           SUBSTRING_INDEX(GROUP_CONCAT(longitude ORDER BY captured_at DESC), ',', 1) as last_lng
                    FROM t_ops_rider_location 
                    WHERE DATE(captured_at) = '{$targetDate}'
                    GROUP BY user_id
                ) as loc"), 'u.id', '=', 'loc.user_id');
                
                // For history, also include riders who delivered orders on that date
                $riders->leftJoin(\DB::raw("(
                    SELECT DISTINCT o.assigned_rider_user_id as user_id
                    FROM t_crm_prod_order o
                    INNER JOIN t_crm_order_status_history osh ON o.id = osh.order_id
                    WHERE osh.status_code = 'delivered'
                    AND DATE(osh.changed_at) = '{$targetDate}'
                ) as delivered"), 'u.id', '=', 'delivered.user_id');
            } else {
                // For live view, get recent location data
                $riders->leftJoin(\DB::raw('(
                    SELECT user_id, 
                           MAX(captured_at) as last_location_at,
                           SUBSTRING_INDEX(GROUP_CONCAT(latitude ORDER BY captured_at DESC), ",", 1) as last_lat,
                           SUBSTRING_INDEX(GROUP_CONCAT(longitude ORDER BY captured_at DESC), ",", 1) as last_lng
                    FROM t_ops_rider_location 
                    WHERE captured_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY user_id
                ) as loc'), 'u.id', '=', 'loc.user_id');
            }
            
            // ⭐ Join to get last sync time from orders (more reliable than location heartbeat)
            $riders->leftJoin(\DB::raw('(
                SELECT assigned_rider_user_id as user_id,
                       MAX(rider_last_sync_at) as last_sync_at
                FROM t_crm_prod_order 
                WHERE rider_last_sync_at IS NOT NULL
                GROUP BY assigned_rider_user_id
            ) as sync'), 'u.id', '=', 'sync.user_id')
            // ⭐ Join to get app login status (check if user HAS any tokens)
            ->leftJoin(\DB::raw('(
                SELECT tokenable_id as user_id,
                       COUNT(*) as token_count,
                       MAX(last_used_at) as token_last_used_at,
                       MAX(created_at) as token_created_at
                FROM personal_access_tokens 
                WHERE tokenable_type = "App\\\\Models\\\\User"
                GROUP BY tokenable_id
            ) as tokens'), 'u.id', '=', 'tokens.user_id')
                ->where('u.is_active', 1);
            
            if ($isHistory) {
                // For history, show riders who checked in OR delivered orders that day
                $riders->where(function($q) {
                    $q->whereNotNull('a.login_time')
                      ->orWhereNotNull('loc.last_location_at')
                      ->orWhereNotNull('delivered.user_id');
                });
            } else {
                // For live view (today), ONLY show riders who have checked in today
                // Recent location alone is not enough - they must have attendance for today
                $riders->whereNotNull('a.login_time');
            }
            
            $riders = $riders->select([
                    'u.id',
                    'u.fullname as name',
                    'u.app_version', // ⭐ Get app_version from user table (set on login)
                    'a.login_time',
                    'a.logout_time',
                    'loc.last_location_at',
                    'loc.last_lat as latitude',
                    'loc.last_lng as longitude',
                    'sync.last_sync_at',
                    'tokens.token_count', // ⭐ Count of active tokens
                    'tokens.token_last_used_at',
                    'tokens.token_created_at',
                ])
                ->orderBy('u.fullname')
                ->get();

            // Get order counts for each rider:
            // - For live: Open orders (any date) + Delivered today
            // - For history: Delivered on that date only
            $riderIds = $riders->pluck('id')->toArray();
            $orderCounts = [];
            
            if (!empty($riderIds)) {
                if ($isHistory) {
                    // For history, only show delivered orders on that date
                    $deliveredCounts = \DB::table('t_crm_prod_order as o')
                        ->join('t_crm_order_status_history as osh', function($join) use ($targetDate) {
                            $join->on('o.id', '=', 'osh.order_id')
                                 ->where('osh.status_code', '=', 'delivered')
                                 ->whereDate('osh.changed_at', $targetDate);
                        })
                        ->whereIn('o.assigned_rider_user_id', $riderIds)
                        ->select([
                            'o.assigned_rider_user_id as rider_id',
                            \DB::raw('COUNT(DISTINCT o.id) as delivered_orders'),
                        ])
                        ->groupBy('o.assigned_rider_user_id')
                        ->get()
                        ->keyBy('rider_id');
                    
                    foreach ($riderIds as $riderId) {
                        $orderCounts[$riderId] = (object)[
                            'open_orders' => 0, // No open orders for history view
                            'delivered_orders' => $deliveredCounts[$riderId]->delivered_orders ?? 0,
                        ];
                    }
                } else {
                    // For live view, get open orders count (any date, excluding completed statuses)
                    $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
                    $openCounts = \DB::table('t_crm_prod_order')
                        ->whereIn('assigned_rider_user_id', $riderIds)
                        ->whereNotIn('order_status', $excludedStatuses)
                        ->select([
                            'assigned_rider_user_id as rider_id',
                            \DB::raw('COUNT(*) as open_orders'),
                        ])
                        ->groupBy('assigned_rider_user_id')
                        ->get()
                        ->keyBy('rider_id');
                
                    // Get delivered today count (based on status history changed_at)
                    $deliveredCounts = \DB::table('t_crm_prod_order as o')
                        ->join('t_crm_order_status_history as osh', function($join) use ($today) {
                            $join->on('o.id', '=', 'osh.order_id')
                                 ->where('osh.status_code', '=', 'delivered')
                                 ->whereDate('osh.changed_at', $today);
                        })
                        ->whereIn('o.assigned_rider_user_id', $riderIds)
                        ->select([
                            'o.assigned_rider_user_id as rider_id',
                            \DB::raw('COUNT(DISTINCT o.id) as delivered_today'),
                        ])
                        ->groupBy('o.assigned_rider_user_id')
                        ->get()
                        ->keyBy('rider_id');
                    
                    // Combine counts
                    foreach ($riderIds as $riderId) {
                        $openCount = $openCounts[$riderId]->open_orders ?? 0;
                        $deliveredCount = $deliveredCounts[$riderId]->delivered_today ?? 0;
                        $orderCounts[$riderId] = (object)[
                            'total_orders' => $openCount + $deliveredCount,
                            'delivered_orders' => $deliveredCount,
                            'open_orders' => $openCount,
                        ];
                    }
                } // End else (live view)
            } // End if (!empty($riderIds))

            // Format response
            $formattedRiders = $riders->map(function($rider) use ($orderCounts) {
                $counts = $orderCounts[$rider->id] ?? (object)['total_orders' => 0, 'delivered_orders' => 0, 'open_orders' => 0];
                $isCheckedIn = !is_null($rider->login_time);
                $isCheckedOut = !is_null($rider->logout_time);
                
                // Calculate location age in human-readable format
                $locationAge = null;
                $locationAgeMinutes = null;
                if ($rider->last_location_at) {
                    $lastLocationTime = \Carbon\Carbon::parse($rider->last_location_at);
                    // Use absolute value to ensure positive minutes
                    $locationAgeMinutes = abs(now()->diffInMinutes($lastLocationTime));
                    $locationAge = $lastLocationTime->diffForHumans(); // e.g., "2 minutes ago"
                }
                
                // ⭐ Calculate sync age (more reliable for online status than location)
                // This is when the mobile app last fetched orders
                $syncAge = null;
                $syncAgeMinutes = null;
                if ($rider->last_sync_at) {
                    $lastSyncTime = \Carbon\Carbon::parse($rider->last_sync_at);
                    // Use absolute value to ensure positive minutes
                    $syncAgeMinutes = abs(now()->diffInMinutes($lastSyncTime));
                    $syncAge = $lastSyncTime->diffForHumans();
                }
                
                // ⭐ Calculate app login status (from API token existence)
                // User is "logged in" if they have ANY active tokens
                $tokenCount = intval($rider->token_count ?? 0);
                $isAppLoggedIn = $tokenCount > 0;
                $appLastActiveAt = null;
                $appLastActiveAge = null;
                if ($rider->token_last_used_at) {
                    $lastTokenUse = \Carbon\Carbon::parse($rider->token_last_used_at);
                    $appLastActiveAt = $rider->token_last_used_at;
                    $appLastActiveAge = $lastTokenUse->diffForHumans();
                }

                return [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'is_checked_in' => $isCheckedIn,
                    'is_checked_out' => $isCheckedOut,
                    'status' => $isCheckedOut ? 'checked_out' : ($isCheckedIn ? 'on_duty' : 'has_location'),
                    'checked_in_at' => $rider->login_time,
                    'checked_out_at' => $rider->logout_time,
                    // Last location from GPS heartbeat
                    'last_location' => $rider->latitude ? [
                        'latitude' => (float)$rider->latitude,
                        'longitude' => (float)$rider->longitude,
                        'captured_at' => $rider->last_location_at,
                        'age' => $locationAge,
                        'age_minutes' => $locationAgeMinutes,
                    ] : null,
                    // ⭐ Last sync from mobile app (more reliable for online status)
                    'last_sync' => $rider->last_sync_at ? [
                        'synced_at' => $rider->last_sync_at,
                        'age' => $syncAge,
                        'age_minutes' => $syncAgeMinutes,
                    ] : null,
                    // ⭐ App login status (from API token)
                    'app_status' => [
                        'is_logged_in' => $isAppLoggedIn,
                        'last_active_at' => $appLastActiveAt,
                        'last_active_age' => $appLastActiveAge,
                        'app_version' => $rider->app_version,
                    ],
                    'orders_today' => [
                        'total' => $counts->total_orders ?? 0,
                        'delivered' => $counts->delivered_orders ?? 0,
                        'pending' => $counts->open_orders ?? 0,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'riders' => $formattedRiders,
                'is_history' => $isHistory,
                'target_date' => $targetDate,
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get active riders for map', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load riders'
            ], 500);
        }
    }

    /**
     * ⭐ LOCATION TRACKING: Get detailed map data for a specific rider
     * Returns rider's location trail and orders with locations
     * 
     * @param date - Optional date for viewing history (YYYY-MM-DD)
     */
    public function getRiderMapData(Request $request, $riderId)
    {
        try {
            // Check if viewing history or live
            $requestedDate = $request->get('date');
            $isHistory = $requestedDate && $requestedDate !== now()->format('Y-m-d');
            $targetDate = $requestedDate ?: now()->format('Y-m-d');
            $today = now()->format('Y-m-d');
            
            // Get rider info
            $rider = \DB::table('t_sys_user')
                ->where('id', $riderId)
                ->where('is_active', 1)
                ->select('id', 'fullname as name')
                ->first();

            if (!$rider) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rider not found'
                ], 404);
            }

            // Get rider's attendance status for target date
            $attendance = \DB::table('t_ops_attendance')
                ->where('user_id', $riderId)
                ->whereDate('attendance_date', $targetDate)
                ->first();

            // Get rider's location fixes for target date (or last 24 hours for live)
            $locationQuery = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId);
            
            if ($isHistory) {
                // For history, get all locations from that specific date
                $locationQuery->whereDate('captured_at', $targetDate);
            } else {
                // For live, get last 24 hours
                $locationQuery->where('captured_at', '>=', now()->subHours(24));
            }
            
            $rawLocationTrail = $locationQuery
                ->orderBy('captured_at', 'desc')
                ->limit($isHistory ? 200 : 50) // ⭐ Fetch more before filtering
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get();
            
            // ⭐ Filter for quality: accuracy <= 50m, deduplicate per second
            $filteredTrail = $rawLocationTrail->filter(function($loc) {
                $accuracy = (float)($loc->accuracy ?? 0);
                return $accuracy > 0 && $accuracy <= 50;
            });
            if ($filteredTrail->isEmpty()) {
                $filteredTrail = $rawLocationTrail->sortBy('accuracy')->take(max(1, intval($rawLocationTrail->count() * 0.3)));
            }
            // Deduplicate same-second readings
            $dedupedTrail = collect();
            $groupedTrail = $filteredTrail->groupBy(function($loc) {
                return substr($loc->captured_at, 0, 19);
            });
            foreach ($groupedTrail as $ts => $grp) {
                $dedupedTrail->push($grp->sortBy(function($loc) { return (float)($loc->accuracy ?? 999); })->first());
            }
            
            $locationTrail = $dedupedTrail->sortByDesc('captured_at')
                ->take($isHistory ? 50 : 10)
                ->values()
                ->map(function($loc) {
                    $capturedAt = \Carbon\Carbon::parse($loc->captured_at);
                    return [
                        'latitude' => (float)$loc->latitude,
                        'longitude' => (float)$loc->longitude,
                        'accuracy' => $loc->accuracy,
                        'captured_at' => $loc->captured_at,
                        'time' => $capturedAt->format('H:i'),
                        'age' => $capturedAt->diffForHumans(),
                        'source' => $loc->source,
                    ];
                });

            // Current location is the most recent
            $currentLocation = $locationTrail->first();

            // Get orders for this rider based on view mode
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            // ⭐ Subquery to get LATEST delivered status per order (handles duplicate delivered statuses)
            $latestDeliveredStatus = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MAX(id) as latest_osh_id'))
                ->where('status_code', 'delivered')
                ->groupBy('order_id');
            
            $ordersQuery = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoinSub($latestDeliveredStatus, 'latest_del', function($join) {
                    $join->on('o.id', '=', 'latest_del.order_id');
                })
                ->leftJoin('t_crm_order_status_history as osh', 'osh.id', '=', 'latest_del.latest_osh_id')
                ->where('o.assigned_rider_user_id', $riderId);
            
            if ($isHistory) {
                // For history, ONLY show orders delivered on that date
                $ordersQuery->whereExists(function($sub) use ($targetDate) {
                    $sub->select(\DB::raw(1))
                        ->from('t_crm_order_status_history as osh2')
                        ->whereColumn('osh2.order_id', 'o.id')
                        ->where('osh2.status_code', 'delivered')
                        ->whereDate('osh2.changed_at', $targetDate);
                });
            } else {
                // For live view:
                // 1. All OPEN orders (not delivered/completed/cancelled/refunded) - any date
                // 2. Orders DELIVERED TODAY (based on status history changed_at)
                $ordersQuery->where(function($q) use ($excludedStatuses, $today) {
                    // Open orders (any date)
                    $q->whereNotIn('o.order_status', $excludedStatuses)
                    // OR delivered today (based on status history)
                      ->orWhere(function($q2) use ($today) {
                          $q2->whereIn('o.order_status', ['delivered', 'completed'])
                             ->whereExists(function($sub) use ($today) {
                                 $sub->select(\DB::raw(1))
                                     ->from('t_crm_order_status_history as osh2')
                                     ->whereColumn('osh2.order_id', 'o.id')
                                     ->where('osh2.status_code', 'delivered')
                                     ->whereDate('osh2.changed_at', $today);
                             });
                      });
                });
            }
            
            $orders = $ordersQuery
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.order_status as status',
                    'o.total_price',
                    'o.payment_method',
                    // Customer info
                    'c.id as customer_id',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'c.address1 as address',
                    'c.city',
                    // Verified location (customer - manually set, high accuracy)
                    'c.latitude as verified_lat',
                    'c.longitude as verified_lng',
                    'c.verified_location_url',
                    // Geocoded location (customer - auto from address, approximate)
                    'c.geocoded_latitude as geocoded_lat',
                    'c.geocoded_longitude as geocoded_lng',
                    // Delivery location (from status history - actual GPS when marked delivered)
                    'osh.delivery_latitude as delivery_lat',
                    'osh.delivery_longitude as delivery_lng',
                    'osh.changed_at as delivered_at',
                    // ⭐ ETA from "Get Times" button
                    'o.estimated_delivery_at',
                ])
                ->orderBy('o.id', 'desc')
                ->get();

            // Format orders with location info
            // Location priority:
            // 1. Delivery GPS (actual location when marked delivered)
            // 2. Customer verified location (manually set by rider)
            // 3. Customer geocoded location (auto from address)
            $formattedOrders = $orders->map(function($order) {
                $isDelivered = in_array($order->status, ['delivered', 'completed']);
                
                // Determine location source and coordinates with priority
                $location = null;
                $locationSource = null;
                
                // Priority 1: Actual delivery GPS (only for delivered orders)
                if ($isDelivered && $order->delivery_lat && $order->delivery_lng) {
                    $location = [
                        'latitude' => (float)$order->delivery_lat,
                        'longitude' => (float)$order->delivery_lng,
                    ];
                    $locationSource = 'delivery_gps';
                }
                // Priority 2: Customer's verified location (manually confirmed)
                elseif ($order->verified_lat && $order->verified_lng) {
                    $location = [
                        'latitude' => (float)$order->verified_lat,
                        'longitude' => (float)$order->verified_lng,
                    ];
                    $locationSource = 'verified_location';
                }
                // Priority 3: Customer's geocoded location (auto from address)
                elseif ($order->geocoded_lat && $order->geocoded_lng) {
                    $location = [
                        'latitude' => (float)$order->geocoded_lat,
                        'longitude' => (float)$order->geocoded_lng,
                    ];
                    $locationSource = 'geocoded_address';
                }

                // Determine payment type from payment_method
                $paymentMethod = strtolower($order->payment_method ?? 'cash');
                $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                
                // ⭐ Build ETA display for delivered orders
                $etaDisplay = null;
                $etaComparison = null;
                if ($order->estimated_delivery_at && $isDelivered && $order->delivered_at) {
                    $estimatedTime = \Carbon\Carbon::parse($order->estimated_delivery_at);
                    $etaDisplay = $estimatedTime->format('h:i A');
                    $actualTime = \Carbon\Carbon::parse($order->delivered_at);
                    $diffMinutes = (int) round($actualTime->diffInMinutes($estimatedTime, false));
                    
                    $etaComparison = [
                        'estimated_at_display' => $etaDisplay,
                        'actual_at_display' => $actualTime->format('h:i A'),
                        'diff_minutes' => $diffMinutes,
                        'status' => $diffMinutes >= 0 ? 'early' : 'late',
                        'status_text' => $diffMinutes >= 0 
                            ? ($diffMinutes == 0 ? 'On time' : "{$diffMinutes} min early")
                            : (abs($diffMinutes) . ' min late'),
                    ];
                } elseif ($order->estimated_delivery_at && !$isDelivered) {
                    $estimatedTime = \Carbon\Carbon::parse($order->estimated_delivery_at);
                    $etaDisplay = $estimatedTime->format('h:i A');
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_id' => $order->customer_id,
                    'status' => $order->status,
                    'status_display' => ucfirst(str_replace('_', ' ', $order->status)),
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'address' => $order->address . ($order->city ? ', ' . $order->city : ''),
                    'total' => 'PKR ' . number_format($order->total_price, 0),
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'location' => $location,
                    'location_source' => $locationSource,
                    'delivered_at' => $order->delivered_at,
                    'estimated_delivery_at_display' => $etaDisplay, // ⭐ ETA time
                    'eta_comparison' => $etaComparison, // ⭐ ETA vs actual
                    'google_maps_url' => $order->verified_location_url,
                ];
            });

            // Separate delivered and pending orders for counts
            $deliveredOrders = $formattedOrders->filter(fn($o) => in_array($o['status'], ['delivered', 'completed']))->values();
            $pendingOrders = $formattedOrders->filter(fn($o) => !in_array($o['status'], ['delivered', 'completed', 'cancelled', 'refunded']))->values();

            return response()->json([
                'success' => true,
                'rider' => [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'status' => $attendance ? 
                        ($attendance->logout_time ? 'checked_out' : ($attendance->login_time ? 'on_duty' : 'not_checked_in')) 
                        : 'not_checked_in',
                    'checked_in_at' => $attendance->login_time ?? null,
                    'checked_out_at' => $attendance->logout_time ?? null,
                    // Include location data inside rider object as frontend expects
                    'current_location' => $currentLocation,
                    'location_trail' => $locationTrail->slice(0, 5)->values(), // Last 5 for trail
                ],
                // Summary for the header
                'summary' => [
                    'total_orders' => $formattedOrders->count(),
                    'delivered' => $deliveredOrders->count(),
                    'pending' => $pendingOrders->count(),
                ],
                // Flat array of all orders (frontend iterates over this)
                'orders' => $formattedOrders->values(),
                'timestamp' => now()->toIso8601String()
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get rider map data', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load rider data'
            ], 500);
        }
    }

    /**
     * ⭐ LOCATION TRACKING: Get rider's location history (last 2 hours)
     * Groups nearby locations and shows duration at each location
     * 
     * @param riderId - Rider user ID
     * @param hours - Hours to look back (default 2)
     */
    public function getRiderLocationHistory(Request $request, $riderId)
    {
        try {
            $hours = $request->get('hours', 2);
            $date = $request->get('date'); // Optional specific date
            
            $query = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId);
            
            if ($date) {
                $query->whereDate('captured_at', $date);
            } else {
                // Last N hours for live view
                $query->where('captured_at', '>=', now()->subHours($hours));
            }
            
            $rawLocations = $query
                ->orderBy('captured_at', 'asc') // Oldest first for grouping
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get();
            
            if ($rawLocations->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'locations' => [],
                    'total_points' => 0,
                    'grouped_points' => 0,
                ]);
            }
            
            // ⭐ Filter out low-quality GPS readings (accuracy > 50m)
            $locations = $rawLocations->filter(function($loc) {
                $accuracy = (float)($loc->accuracy ?? 0);
                return $accuracy > 0 && $accuracy <= 50;
            });
            
            // If all filtered out, use best 50% by accuracy
            if ($locations->isEmpty()) {
                $locations = $rawLocations->sortBy('accuracy')->take(max(1, intval($rawLocations->count() * 0.5)));
            }
            
            // ⭐ Deduplicate same-second readings (keep best accuracy per second)
            $deduped = collect();
            $grouped = $locations->groupBy(function($loc) {
                return substr($loc->captured_at, 0, 19);
            });
            foreach ($grouped as $timestamp => $group) {
                $best = $group->sortBy(function($loc) {
                    return (float)($loc->accuracy ?? 999);
                })->first();
                $deduped->push($best);
            }
            $locations = $deduped->sortBy('captured_at')->values();
            
            // Group nearby locations and calculate duration at each spot
            // ~50m threshold (roughly 0.0005 degrees)
            $threshold = 0.0005;
            $groupedLocations = [];
            $currentGroup = null;
            
            foreach ($locations as $loc) {
                $lat = (float)$loc->latitude;
                $lng = (float)$loc->longitude;
                $capturedAt = \Carbon\Carbon::parse($loc->captured_at);
                
                if ($currentGroup === null) {
                    // Start first group
                    $currentGroup = [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'accuracy' => $loc->accuracy,
                        'first_seen' => $capturedAt,
                        'last_seen' => $capturedAt,
                        'point_count' => 1,
                        'sources' => [$loc->source],
                    ];
                } else {
                    // Check if this point is near the current group
                    $latDiff = abs($lat - $currentGroup['latitude']);
                    $lngDiff = abs($lng - $currentGroup['longitude']);
                    
                    if ($latDiff < $threshold && $lngDiff < $threshold) {
                        // Same location - extend the group
                        $currentGroup['last_seen'] = $capturedAt;
                        $currentGroup['point_count']++;
                        if (!in_array($loc->source, $currentGroup['sources'])) {
                            $currentGroup['sources'][] = $loc->source;
                        }
                        // Update to use best accuracy
                        if ($loc->accuracy && (!$currentGroup['accuracy'] || $loc->accuracy < $currentGroup['accuracy'])) {
                            $currentGroup['accuracy'] = $loc->accuracy;
                        }
                    } else {
                        // New location - save current group and start new one
                        $groupedLocations[] = $this->formatLocationGroup($currentGroup);
                        $currentGroup = [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'accuracy' => $loc->accuracy,
                            'first_seen' => $capturedAt,
                            'last_seen' => $capturedAt,
                            'point_count' => 1,
                            'sources' => [$loc->source],
                        ];
                    }
                }
            }
            
            // Don't forget the last group
            if ($currentGroup !== null) {
                $groupedLocations[] = $this->formatLocationGroup($currentGroup);
            }
            
            // Reverse so newest is first
            $groupedLocations = array_reverse($groupedLocations);
            
            return response()->json([
                'success' => true,
                'locations' => $groupedLocations,
                'total_points' => $locations->count(),
                'raw_points' => $rawLocations->count(), // ⭐ Total before GPS filtering
                'grouped_points' => count($groupedLocations),
                'hours' => $hours,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get rider location history', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load location history'
            ], 500);
        }
    }
    
    /**
     * Helper: Format a location group with duration
     */
    private function formatLocationGroup($group)
    {
        $firstSeen = $group['first_seen'];
        $lastSeen = $group['last_seen'];
        $durationMinutes = $firstSeen->diffInMinutes($lastSeen);
        
        // Format duration
        $duration = null;
        if ($durationMinutes > 0) {
            if ($durationMinutes >= 60) {
                $hours = floor($durationMinutes / 60);
                $mins = $durationMinutes % 60;
                $duration = $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
            } else {
                $duration = $durationMinutes . ' min';
            }
        }
        
        return [
            'latitude' => $group['latitude'],
            'longitude' => $group['longitude'],
            'accuracy' => $group['accuracy'],
            'captured_at' => $lastSeen->toIso8601String(),
            'first_seen' => $firstSeen->format('h:i A'), // ⭐ Use formatted time instead of ISO
            'last_seen' => $lastSeen->format('h:i A'), // ⭐ Use formatted time instead of ISO
            'time' => $lastSeen->format('h:i A'),
            'time_display' => $firstSeen->format('h:i A'), // ⭐ Add time_display for frontend
            'arrival_time' => $firstSeen->format('h:i A'),
            'age' => $lastSeen->diffForHumans(),
            'duration' => $duration,
            'duration_minutes' => round($durationMinutes), // ⭐ Round to whole minutes
            'duration_display' => $duration, // ⭐ Already formatted display string
            'point_count' => $group['point_count'],
            'sources' => implode(', ', $group['sources']),
        ];
    }

    /**
     * ⭐ Get GPS trail segment between two deliveries
     * Used by RiderMapModal to show trail for a selected order
     * 
     * @param riderId - Rider ID
     * @param from_time - Start timestamp (e.g., previous delivery time or check-in time)
     * @param to_time - End timestamp (e.g., this order's delivery time)
     * @param office_lat/office_lng - Office coordinates for batch break detection
     * 
     * @return GPS trail with batch break detection
     */
    public function getTrailSegment(Request $request, $riderId)
    {
        try {
            $fromTime = $request->get('from_time');
            $toTime = $request->get('to_time');
            $officeLat = $request->get('office_lat');
            $officeLng = $request->get('office_lng');
            $proximityThreshold = $request->get('proximity_threshold', 500); // 500m default
            
            if (!$fromTime || !$toTime) {
                return response()->json([
                    'success' => false,
                    'message' => 'from_time and to_time are required'
                ], 400);
            }
            
            // Parse timestamps
            $from = \Carbon\Carbon::parse($fromTime);
            $to = \Carbon\Carbon::parse($toTime);
            
            // Get GPS readings between timestamps
            $rawReadings = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId)
                ->whereBetween('captured_at', [$from, $to])
                ->orderBy('captured_at')
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get();
            
            if ($rawReadings->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'trail' => [],
                    'count' => 0,
                    'raw_count' => 0,
                    'batch_breaks' => [],
                    'message' => 'No GPS readings in this time range'
                ]);
            }
            
            // ⭐ FILTER GPS READINGS for quality (fixes fuzzy/zigzag trails)
            // Step 1: Filter out very low accuracy readings (accuracy > 50m)
            $filtered = $rawReadings->filter(function($loc) {
                $accuracy = (float)($loc->accuracy ?? 0);
                return $accuracy > 0 && $accuracy <= 50;
            });
            
            // If all readings were filtered out (all low quality), fall back to best available
            if ($filtered->isEmpty()) {
                $filtered = $rawReadings->sortBy('accuracy')->take(max(1, intval($rawReadings->count() * 0.5)));
            }
            
            // Step 2: Deduplicate same-second readings (keep best accuracy per second)
            $deduped = collect();
            $grouped = $filtered->groupBy(function($loc) {
                return substr($loc->captured_at, 0, 19); // Group by second (YYYY-MM-DD HH:MM:SS)
            });
            foreach ($grouped as $timestamp => $group) {
                // Keep the reading with best (lowest) accuracy for each second
                $best = $group->sortBy(function($loc) {
                    return (float)($loc->accuracy ?? 999);
                })->first();
                $deduped->push($best);
            }
            $deduped = $deduped->sortBy('captured_at')->values();
            
            // Step 3: Filter out impossible speed jumps (>150 km/h between consecutive points)
            $readings = collect();
            $prev = null;
            foreach ($deduped as $loc) {
                if ($prev === null) {
                    $readings->push($loc);
                    $prev = $loc;
                    continue;
                }
                
                $distance = $this->haversineDistance(
                    (float)$prev->latitude, (float)$prev->longitude,
                    (float)$loc->latitude, (float)$loc->longitude
                );
                $timeDiff = abs(\Carbon\Carbon::parse($loc->captured_at)->diffInSeconds(\Carbon\Carbon::parse($prev->captured_at)));
                
                // Calculate speed: if time diff is 0, allow only if distance < 100m
                if ($timeDiff > 0) {
                    $speedKmh = ($distance / 1000) / ($timeDiff / 3600);
                    if ($speedKmh <= 150) {
                        $readings->push($loc);
                        $prev = $loc;
                    }
                    // else: skip this point (impossible jump)
                } else {
                    // Same timestamp - only allow if very close (<100m)
                    if ($distance < 100) {
                        $readings->push($loc);
                        $prev = $loc;
                    }
                }
            }
            $readings = $readings->values();
            
            if ($readings->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'trail' => [],
                    'count' => 0,
                    'raw_count' => $rawReadings->count(),
                    'batch_breaks' => [],
                    'message' => 'All GPS readings were filtered out (low quality)'
                ]);
            }
            
            // Format trail points
            $trail = $readings->map(function($loc) {
                $capturedAt = \Carbon\Carbon::parse($loc->captured_at);
                return [
                    'latitude' => (float)$loc->latitude,
                    'longitude' => (float)$loc->longitude,
                    'accuracy' => $loc->accuracy,
                    'time' => $capturedAt->format('h:i A'),
                    'captured_at' => $loc->captured_at, // ⭐ Use captured_at (matches frontend expectation)
                    'timestamp' => $loc->captured_at,     // ⭐ Keep for backward compatibility
                    'source' => $loc->source,
                ];
            })->values();
            
            // ⭐ Detect batch breaks (times when rider was near office)
            $batchBreaks = [];
            if ($officeLat && $officeLng) {
                $officeLat = (float)$officeLat;
                $officeLng = (float)$officeLng;
                $currentBatchBreak = null;
                
                foreach ($readings as $loc) {
                    $lat = (float)$loc->latitude;
                    $lng = (float)$loc->longitude;
                    
                    // Calculate distance from office (Haversine formula)
                    $distance = $this->haversineDistance($lat, $lng, $officeLat, $officeLng);
                    
                    if ($distance < $proximityThreshold) {
                        // Near office
                        if (!$currentBatchBreak) {
                            $currentBatchBreak = [
                                'entered_at' => $loc->captured_at,
                                'latitude' => $lat,
                                'longitude' => $lng,
                            ];
                        }
                        $currentBatchBreak['exited_at'] = $loc->captured_at;
                    } else {
                        // Away from office
                        if ($currentBatchBreak) {
                            // Calculate duration at office
                            $entered = \Carbon\Carbon::parse($currentBatchBreak['entered_at']);
                            $exited = \Carbon\Carbon::parse($currentBatchBreak['exited_at']);
                            $durationMinutes = $entered->diffInMinutes($exited);
                            
                            // Only count as batch break if stayed > 5 minutes
                            if ($durationMinutes >= 5) {
                                $batchBreaks[] = [
                                    'entered_at' => $entered->format('h:i A'),
                                    'exited_at' => $exited->format('h:i A'),
                                    'duration_minutes' => $durationMinutes,
                                    'latitude' => $currentBatchBreak['latitude'],
                                    'longitude' => $currentBatchBreak['longitude'],
                                ];
                            }
                            $currentBatchBreak = null;
                        }
                    }
                }
                
                // Handle case where trail ends at office
                if ($currentBatchBreak) {
                    $entered = \Carbon\Carbon::parse($currentBatchBreak['entered_at']);
                    $exited = \Carbon\Carbon::parse($currentBatchBreak['exited_at']);
                    $durationMinutes = $entered->diffInMinutes($exited);
                    
                    if ($durationMinutes >= 5) {
                        $batchBreaks[] = [
                            'entered_at' => $entered->format('h:i A'),
                            'exited_at' => $exited->format('h:i A') . ' (current)',
                            'duration_minutes' => $durationMinutes,
                            'latitude' => $currentBatchBreak['latitude'],
                            'longitude' => $currentBatchBreak['longitude'],
                        ];
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'trail' => $trail,
                'count' => $trail->count(),
                'raw_count' => $rawReadings->count(), // ⭐ Total before filtering
                'from_time' => $from->format('h:i A'),
                'to_time' => $to->format('h:i A'),
                'batch_breaks' => $batchBreaks,
                'has_batch_break' => count($batchBreaks) > 0,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get trail segment', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load trail segment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ DELIVERY JOURNEY: Analyze what happened between ride start/last delivery and this delivery.
     * Returns trail, stops, distance, and timing summary for a delivered order.
     */
    public function getDeliveryJourney(Request $request, $orderId)
    {
        try {
            // Get the delivered order with status history
            $order = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->where('o.id', $orderId)
                ->select([
                    'o.id', 'o.order_number', 'o.assigned_rider_user_id',
                    'o.address_line1', 'o.address_city',
                    'o.estimated_delivery_at',
                    'u.fullname as rider_name',
                ])
                ->first();
            
            if (!$order || !$order->assigned_rider_user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found or no rider assigned'
                ], 404);
            }
            
            $riderId = $order->assigned_rider_user_id;
            
            // Get the delivery timestamp for this order
            $deliveryEvent = \DB::table('t_crm_order_status_history')
                ->where('order_id', $orderId)
                ->where('status_code', 'delivered')
                ->orderBy('id', 'asc')
                ->first();
            
            if (!$deliveryEvent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order has not been delivered yet'
                ], 400);
            }
            
            $deliveredAt = \Carbon\Carbon::parse($deliveryEvent->changed_at);
            
            // Find the previous event: either the previous delivery by this rider, or the ride start
            // Look for the most recent delivery before this one by the same rider on the same date
            $prevDelivery = \DB::table('t_crm_order_status_history as osh')
                ->join('t_crm_prod_order as prev_o', 'prev_o.id', '=', 'osh.order_id')
                ->where('prev_o.assigned_rider_user_id', $riderId)
                ->where('osh.status_code', 'delivered')
                ->where('osh.changed_at', '<', $deliveryEvent->changed_at)
                ->whereDate('osh.changed_at', $deliveredAt->toDateString())
                ->where('osh.order_id', '!=', $orderId)
                ->orderBy('osh.changed_at', 'desc')
                ->select('osh.changed_at', 'osh.order_id', 'prev_o.order_number')
                ->first();
            
            if ($prevDelivery) {
                $journeyStartTime = \Carbon\Carbon::parse($prevDelivery->changed_at);
                $journeyStartLabel = "After delivering #{$prevDelivery->order_number}";
            } else {
                // No previous delivery - use ride start (first GPS point of the day or first 'start' source)
                $firstGps = \DB::table('t_ops_rider_location')
                    ->where('user_id', $riderId)
                    ->whereDate('captured_at', $deliveredAt->toDateString())
                    ->where('captured_at', '<', $deliveryEvent->changed_at)
                    ->orderBy('captured_at', 'asc')
                    ->first();
                
                if ($firstGps) {
                    $journeyStartTime = \Carbon\Carbon::parse($firstGps->captured_at);
                    $journeyStartLabel = "Day start";
                } else {
                    // No GPS data before delivery
                    $journeyStartTime = $deliveredAt->copy()->subMinutes(30);
                    $journeyStartLabel = "~30 min before delivery (no earlier GPS)";
                }
            }
            
            $journeyEndTime = $deliveredAt;
            
            // ⭐ Get GPS trail between journey start and end (reuse trail filtering logic)
            $rawReadings = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId)
                ->whereBetween('captured_at', [$journeyStartTime, $journeyEndTime])
                ->orderBy('captured_at')
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get();
            
            if ($rawReadings->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'order_id' => $orderId,
                    'order_number' => $order->order_number,
                    'rider_name' => $order->rider_name,
                    'journey_start' => $journeyStartTime->format('h:i A'),
                    'journey_end' => $journeyEndTime->format('h:i A'),
                    'journey_start_label' => $journeyStartLabel,
                    'total_duration_minutes' => (int) round($journeyStartTime->diffInMinutes($journeyEndTime)),
                    'trail' => [],
                    'stops' => [],
                    'summary' => [
                        'total_distance_km' => 0,
                        'moving_time_minutes' => 0,
                        'stopped_time_minutes' => 0,
                        'stop_count' => 0,
                        'avg_speed_kmh' => 0,
                    ],
                    'message' => 'No GPS data for this journey segment'
                ]);
            }
            
            // Step 1: Filter out low accuracy readings
            $filtered = $rawReadings->filter(function($loc) {
                $accuracy = (float)($loc->accuracy ?? 0);
                return $accuracy > 0 && $accuracy <= 50;
            });
            
            if ($filtered->isEmpty()) {
                $filtered = $rawReadings->sortBy('accuracy')->take(max(1, intval($rawReadings->count() * 0.5)));
            }
            
            // Step 2: Deduplicate same-second readings (keep best accuracy)
            $deduped = collect();
            $grouped = $filtered->groupBy(function($loc) {
                return substr($loc->captured_at, 0, 19);
            });
            foreach ($grouped as $timestamp => $group) {
                $best = $group->sortBy(function($loc) {
                    return (float)($loc->accuracy ?? 999);
                })->first();
                $deduped->push($best);
            }
            $deduped = $deduped->sortBy('captured_at')->values();
            
            // Step 3: Filter impossible speed jumps (>150 km/h)
            $readings = collect();
            $prev = null;
            foreach ($deduped as $loc) {
                if ($prev === null) {
                    $readings->push($loc);
                    $prev = $loc;
                    continue;
                }
                $distance = $this->haversineDistance(
                    (float)$prev->latitude, (float)$prev->longitude,
                    (float)$loc->latitude, (float)$loc->longitude
                );
                $timeDiff = abs(\Carbon\Carbon::parse($loc->captured_at)->diffInSeconds(\Carbon\Carbon::parse($prev->captured_at)));
                if ($timeDiff > 0) {
                    $speedKmh = ($distance / 1000) / ($timeDiff / 3600);
                    if ($speedKmh <= 150) {
                        $readings->push($loc);
                        $prev = $loc;
                    }
                } else if ($distance < 100) {
                    $readings->push($loc);
                    $prev = $loc;
                }
            }
            $readings = $readings->values();
            
            // ⭐ Detect stops (>3 min within 50m radius)
            $stops = [];
            $currentStop = null;
            $stopThresholdMeters = 50;
            $stopThresholdSeconds = 180; // 3 minutes
            
            for ($i = 0; $i < $readings->count(); $i++) {
                $point = $readings[$i];
                
                if ($currentStop === null) {
                    // Start a potential stop
                    $currentStop = [
                        'start_idx' => $i,
                        'center_lat' => (float)$point->latitude,
                        'center_lng' => (float)$point->longitude,
                        'start_time' => $point->captured_at,
                        'end_time' => $point->captured_at,
                        'point_count' => 1,
                    ];
                } else {
                    $dist = $this->haversineDistance(
                        $currentStop['center_lat'], $currentStop['center_lng'],
                        (float)$point->latitude, (float)$point->longitude
                    );
                    
                    if ($dist <= $stopThresholdMeters) {
                        // Still within stop radius
                        $currentStop['end_time'] = $point->captured_at;
                        $currentStop['point_count']++;
                    } else {
                        // Moved away - check if previous cluster was a stop
                        $durationSecs = \Carbon\Carbon::parse($currentStop['start_time'])
                            ->diffInSeconds(\Carbon\Carbon::parse($currentStop['end_time']));
                        
                        if ($durationSecs >= $stopThresholdSeconds) {
                            $durationMins = (int) round($durationSecs / 60);
                            $stops[] = [
                                'latitude' => $currentStop['center_lat'],
                                'longitude' => $currentStop['center_lng'],
                                'start_time' => \Carbon\Carbon::parse($currentStop['start_time'])->format('h:i A'),
                                'end_time' => \Carbon\Carbon::parse($currentStop['end_time'])->format('h:i A'),
                                'duration_minutes' => $durationMins,
                                'duration_display' => $durationMins >= 60 
                                    ? floor($durationMins / 60) . 'h ' . ($durationMins % 60) . 'min'
                                    : $durationMins . ' min',
                            ];
                        }
                        
                        // Start new potential stop
                        $currentStop = [
                            'start_idx' => $i,
                            'center_lat' => (float)$point->latitude,
                            'center_lng' => (float)$point->longitude,
                            'start_time' => $point->captured_at,
                            'end_time' => $point->captured_at,
                            'point_count' => 1,
                        ];
                    }
                }
            }
            
            // Check last cluster
            if ($currentStop) {
                $durationSecs = \Carbon\Carbon::parse($currentStop['start_time'])
                    ->diffInSeconds(\Carbon\Carbon::parse($currentStop['end_time']));
                if ($durationSecs >= $stopThresholdSeconds) {
                    $durationMins = (int) round($durationSecs / 60);
                    $stops[] = [
                        'latitude' => $currentStop['center_lat'],
                        'longitude' => $currentStop['center_lng'],
                        'start_time' => \Carbon\Carbon::parse($currentStop['start_time'])->format('h:i A'),
                        'end_time' => \Carbon\Carbon::parse($currentStop['end_time'])->format('h:i A'),
                        'duration_minutes' => $durationMins,
                        'duration_display' => $durationMins >= 60 
                            ? floor($durationMins / 60) . 'h ' . ($durationMins % 60) . 'min'
                            : $durationMins . ' min',
                    ];
                }
            }
            
            // ⭐ Calculate total distance
            $totalDistanceMeters = 0;
            for ($i = 1; $i < $readings->count(); $i++) {
                $totalDistanceMeters += $this->haversineDistance(
                    (float)$readings[$i-1]->latitude, (float)$readings[$i-1]->longitude,
                    (float)$readings[$i]->latitude, (float)$readings[$i]->longitude
                );
            }
            $totalDistanceKm = round($totalDistanceMeters / 1000, 2);
            
            // Calculate stopped time
            $totalStoppedMinutes = collect($stops)->sum('duration_minutes');
            $totalDurationMinutes = (int) round($journeyStartTime->diffInMinutes($journeyEndTime));
            $movingTimeMinutes = max(0, $totalDurationMinutes - $totalStoppedMinutes);
            
            // Average moving speed
            $avgSpeedKmh = $movingTimeMinutes > 0 
                ? round(($totalDistanceKm / ($movingTimeMinutes / 60)), 1) 
                : 0;
            
            // Format trail for response
            $trail = $readings->map(function($loc) {
                return [
                    'latitude' => (float)$loc->latitude,
                    'longitude' => (float)$loc->longitude,
                    'time' => \Carbon\Carbon::parse($loc->captured_at)->format('h:i A'),
                    'captured_at' => $loc->captured_at,
                ];
            })->values();
            
            // ETA comparison
            $etaComparison = null;
            if ($order->estimated_delivery_at) {
                $estimatedTime = \Carbon\Carbon::parse($order->estimated_delivery_at);
                $diffMinutes = (int) round($deliveredAt->diffInMinutes($estimatedTime, false));
                $etaComparison = [
                    'estimated_at_display' => $estimatedTime->format('h:i A'),
                    'actual_at_display' => $deliveredAt->format('h:i A'),
                    'diff_minutes' => $diffMinutes,
                    'status' => $diffMinutes >= 0 ? 'early' : 'late',
                    'status_text' => $diffMinutes >= 0 
                        ? ($diffMinutes == 0 ? 'On time' : "{$diffMinutes} min early")
                        : (abs($diffMinutes) . ' min late'),
                ];
            }
            
            return response()->json([
                'success' => true,
                'order_id' => $orderId,
                'order_number' => $order->order_number,
                'rider_name' => $order->rider_name,
                'address' => trim(implode(', ', array_filter([$order->address_line1, $order->address_city]))),
                'journey_start' => $journeyStartTime->format('h:i A'),
                'journey_end' => $journeyEndTime->format('h:i A'),
                'journey_start_label' => $journeyStartLabel,
                'eta_comparison' => $etaComparison,
                'total_duration_minutes' => $totalDurationMinutes,
                'trail' => $trail,
                'trail_count' => $trail->count(),
                'raw_count' => $rawReadings->count(),
                'stops' => $stops,
                'summary' => [
                    'total_distance_km' => $totalDistanceKm,
                    'total_distance_display' => $totalDistanceKm < 1 
                        ? round($totalDistanceMeters) . 'm' 
                        : $totalDistanceKm . ' km',
                    'moving_time_minutes' => $movingTimeMinutes,
                    'moving_time_display' => $movingTimeMinutes >= 60 
                        ? floor($movingTimeMinutes / 60) . 'h ' . ($movingTimeMinutes % 60) . 'min'
                        : $movingTimeMinutes . ' min',
                    'stopped_time_minutes' => $totalStoppedMinutes,
                    'stopped_time_display' => $totalStoppedMinutes >= 60
                        ? floor($totalStoppedMinutes / 60) . 'h ' . ($totalStoppedMinutes % 60) . 'min'
                        : $totalStoppedMinutes . ' min',
                    'stop_count' => count($stops),
                    'avg_speed_kmh' => $avgSpeedKmh,
                    'total_duration_display' => $totalDurationMinutes >= 60
                        ? floor($totalDurationMinutes / 60) . 'h ' . ($totalDurationMinutes % 60) . 'min'
                        : $totalDurationMinutes . ' min',
                ],
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get delivery journey', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load delivery journey: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⭐ LOCATION TRACKING: Get all open orders for map view
     * Supports filtering by status and rider
     * 
     * @param status - Filter by order status (comma-separated, e.g., "processing,out_for_delivery")
     * @param rider_id - Filter by specific rider (or "all" for all riders)
     */
    public function getAllOpenOrdersForMap(Request $request)
    {
        try {
            $statusFilter = $request->get('status'); // comma-separated
            $riderFilter = $request->get('rider_id'); // specific rider ID or null for all
            
            // Build query for open orders
            $excludedStatuses = ['delivered', 'completed', 'cancelled', 'refunded'];
            
            $ordersQuery = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->whereNotIn('o.order_status', $excludedStatuses)
                // Exclude Shopify orders - match Invoices page logic
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                });
            
            // Apply status filter if provided
            if ($statusFilter && $statusFilter !== 'all') {
                $statuses = array_map('trim', explode(',', $statusFilter));
                $ordersQuery->whereIn('o.order_status', $statuses);
            }
            
            // Apply rider filter if provided
            if ($riderFilter && $riderFilter !== 'all') {
                if ($riderFilter === 'unassigned') {
                    $ordersQuery->whereNull('o.assigned_rider_user_id');
                } else {
                    $ordersQuery->where('o.assigned_rider_user_id', $riderFilter);
                }
            }
            
            $orders = $ordersQuery
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.order_status as status',
                    'o.total_price',
                    'o.payment_method',
                    'o.assigned_rider_user_id as rider_id',
                    'u.fullname as rider_name',
                    // Customer info
                    'c.id as customer_id',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'c.address1 as address',
                    'c.city',
                    // Location sources
                    'c.latitude as verified_lat',
                    'c.longitude as verified_lng',
                    'c.geocoded_latitude as geocoded_lat',
                    'c.geocoded_longitude as geocoded_lng',
                ])
                ->orderBy('o.id', 'desc')
                ->get();
            
            // Format orders with location info
            $formattedOrders = $orders->map(function($order) {
                // Determine location with priority
                $location = null;
                $locationSource = null;
                
                if ($order->verified_lat && $order->verified_lng) {
                    $location = [
                        'latitude' => (float)$order->verified_lat,
                        'longitude' => (float)$order->verified_lng,
                    ];
                    $locationSource = 'verified_location';
                } elseif ($order->geocoded_lat && $order->geocoded_lng) {
                    $location = [
                        'latitude' => (float)$order->geocoded_lat,
                        'longitude' => (float)$order->geocoded_lng,
                    ];
                    $locationSource = 'geocoded_address';
                }
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'status_display' => ucfirst(str_replace('_', ' ', $order->status)),
                    'customer_id' => $order->customer_id,
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'address' => $order->address . ($order->city ? ', ' . $order->city : ''),
                    'total' => 'PKR ' . number_format($order->total_price, 0),
                    'rider_id' => $order->rider_id,
                    'rider_name' => $order->rider_name ?: 'Unassigned',
                    'location' => $location,
                    'location_source' => $locationSource,
                ];
            });
            
            // Get unique statuses for filter
            $uniqueStatuses = $orders->pluck('status')->unique()->values();
            
            // Get riders with open orders for filter
            $ridersWithOrders = $orders
                ->filter(fn($o) => $o->rider_id)
                ->unique('rider_id')
                ->map(fn($o) => [
                    'id' => $o->rider_id,
                    'name' => $o->rider_name,
                ])
                ->values();
            
            // Count by status
            $statusCounts = $orders->groupBy('status')->map->count();
            
            // Count by rider (including unassigned)
            $riderCounts = $orders->groupBy('rider_id')->map->count();
            
            // Count unassigned orders
            $unassignedCount = $orders->filter(fn($o) => !$o->rider_id)->count();
            
            return response()->json([
                'success' => true,
                'orders' => $formattedOrders->values(),
                'summary' => [
                    'total' => $formattedOrders->count(),
                    'with_location' => $formattedOrders->filter(fn($o) => $o['location'])->count(),
                    'without_location' => $formattedOrders->filter(fn($o) => !$o['location'])->count(),
                ],
                'filters' => [
                    'statuses' => $uniqueStatuses,
                    'status_counts' => $statusCounts,
                    'riders' => $ridersWithOrders,
                    'rider_counts' => $riderCounts,
                    'unassigned_count' => $unassignedCount,
                ],
                'timestamp' => now()->toIso8601String()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get all open orders for map', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load orders'
            ], 500);
        }
    }

    /**
     * ⭐ LOCATION TRACKING: Get delivery history for a specific date
     * Returns delivered orders grouped by rider with cash/online split
     * 
     * @param date - Date to get history for (YYYY-MM-DD)
     */
    public function getDeliveryHistory(Request $request)
    {
        try {
            $date = $request->get('date', now()->format('Y-m-d'));
            
            // Get all delivered orders for this date
            $orders = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_order_status_history as osh', function($join) use ($date) {
                    $join->on('o.id', '=', 'osh.order_id')
                         ->where('osh.status_code', '=', 'delivered')
                         ->whereDate('osh.changed_at', $date);
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.total_price',
                    'o.payment_method',
                    'o.assigned_rider_user_id as rider_id',
                    'u.fullname as rider_name',
                    'osh.changed_at as delivered_at',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                ])
                ->orderBy('osh.changed_at', 'desc')
                ->get();
            
            // Get attendance for riders on this date
            $attendances = \DB::table('t_ops_attendance')
                ->whereDate('attendance_date', $date)
                ->whereNotNull('login_time')
                ->get()
                ->keyBy('user_id');
            
            // Group by rider
            $riderGroups = [];
            foreach ($orders as $order) {
                $riderId = $order->rider_id ?: 0;
                $riderName = $order->rider_name ?: 'Unassigned';
                
                if (!isset($riderGroups[$riderId])) {
                    $attendance = $attendances->get($riderId);
                    $riderGroups[$riderId] = [
                        'id' => $riderId,
                        'name' => $riderName,
                        'check_in_time' => $attendance ? \Carbon\Carbon::parse($attendance->login_time)->format('h:i A') : null,
                        'check_out_time' => $attendance && $attendance->logout_time ? \Carbon\Carbon::parse($attendance->logout_time)->format('h:i A') : null,
                        'cash_count' => 0,
                        'cash_total' => 0,
                        'online_count' => 0,
                        'online_total' => 0,
                        'delivered_count' => 0,
                        'orders' => [],
                    ];
                }
                
                $paymentMethod = strtolower($order->payment_method ?? 'cash');
                $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                $amount = (float)$order->total_price;
                
                if ($isCash) {
                    $riderGroups[$riderId]['cash_count']++;
                    $riderGroups[$riderId]['cash_total'] += $amount;
                } else {
                    $riderGroups[$riderId]['online_count']++;
                    $riderGroups[$riderId]['online_total'] += $amount;
                }
                
                $riderGroups[$riderId]['delivered_count']++;
                $riderGroups[$riderId]['orders'][] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'amount' => $amount,
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'delivered_at' => \Carbon\Carbon::parse($order->delivered_at)->format('h:i A'),
                ];
            }
            
            // Sort riders by delivered count (highest first)
            $riders = collect($riderGroups)->sortByDesc('delivered_count')->values();
            
            // Calculate totals
            $totalDelivered = $orders->count();
            $cashTotal = $riders->sum('cash_total');
            $onlineTotal = $riders->sum('online_total');
            $cashCount = $riders->sum('cash_count');
            $onlineCount = $riders->sum('online_count');
            
            return response()->json([
                'success' => true,
                'date' => $date,
                'riders' => $riders,
                'summary' => [
                    'total_delivered' => $totalDelivered,
                    'cash_total' => $cashTotal,
                    'cash_count' => $cashCount,
                    'online_total' => $onlineTotal,
                    'online_count' => $onlineCount,
                    'grand_total' => $cashTotal + $onlineTotal,
                ],
                'timestamp' => now()->toIso8601String()
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get delivery history', [
                'date' => $request->get('date'),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load history'
            ], 500);
        }
    }

    /**
     * ⭐ HISTORY: Get list of riders (users with rider role) for history view
     */
    public function getRidersForHistory(Request $request)
    {
        try {
            // Get users with rider role (role_id that has delivery permissions)
            // For now, get all active users who have delivered orders in the last 3 months
            $threeMonthsAgo = now()->subMonths(3)->format('Y-m-d');
            
            $riders = \DB::table('t_sys_user as u')
                ->join(\DB::raw("(
                    SELECT DISTINCT o.assigned_rider_user_id as user_id,
                           COUNT(*) as total_delivered,
                           SUM(CASE WHEN LOWER(COALESCE(o.payment_method, 'cash')) IN ('cash', 'cash_on_delivery', 'cod') THEN o.total_price ELSE 0 END) as total_cash,
                           SUM(CASE WHEN LOWER(COALESCE(o.payment_method, 'cash')) NOT IN ('cash', 'cash_on_delivery', 'cod') THEN o.total_price ELSE 0 END) as total_online
                    FROM t_crm_prod_order o
                    INNER JOIN t_crm_order_status_history osh ON o.id = osh.order_id AND osh.status_code = 'delivered'
                    WHERE osh.changed_at >= '{$threeMonthsAgo}'
                    AND o.assigned_rider_user_id IS NOT NULL
                    GROUP BY o.assigned_rider_user_id
                ) as stats"), 'u.id', '=', 'stats.user_id')
                ->where('u.is_active', 1)
                ->select([
                    'u.id',
                    'u.fullname as name',
                    'stats.total_delivered',
                    'stats.total_cash',
                    'stats.total_online',
                ])
                ->orderBy('stats.total_delivered', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'riders' => $riders,
                'period' => [
                    'start' => $threeMonthsAgo,
                    'end' => now()->format('Y-m-d'),
                    'label' => 'Last 3 months',
                ],
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get riders for history', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load riders'
            ], 500);
        }
    }

    /**
     * ⭐ HISTORY: Get a specific rider's delivery history grouped by date (last 3 months)
     * Similar to rider mode's delivered orders view
     */
    public function getRiderDeliveryHistory(Request $request, $riderId)
    {
        try {
            $threeMonthsAgo = now()->subMonths(3)->format('Y-m-d');
            
            // Get rider info
            $rider = \DB::table('t_sys_user')
                ->where('id', $riderId)
                ->select('id', 'fullname as name')
                ->first();
            
            if (!$rider) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rider not found'
                ], 404);
            }
            
            // ⭐ Get all delivered orders for this rider in the last 3 months
            // Use subquery to get LATEST delivered status per order (handles duplicate delivered statuses)
            $latestDeliveredSubquery = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MAX(id) as latest_osh_id'))
                ->where('status_code', 'delivered')
                ->groupBy('order_id');
            
            $orders = \DB::table('t_crm_prod_order as o')
                ->joinSub($latestDeliveredSubquery, 'latest_osh', function($join) {
                    $join->on('o.id', '=', 'latest_osh.order_id');
                })
                ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'latest_osh.latest_osh_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $riderId)
                ->where('osh.changed_at', '>=', $threeMonthsAgo)
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.customer_id',
                    'o.total_price',
                    'o.payment_method',
                    'o.estimated_delivery_at', // ⭐ ETA from "Get Times"
                    'osh.changed_at as delivered_at',
                    \DB::raw('DATE(osh.changed_at) as delivery_date'),
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'c.address1 as address',
                    'c.city',
                    'c.latitude as verified_lat',
                    'c.longitude as verified_lng',
                    'c.geocoded_latitude as geocoded_lat',
                    'c.geocoded_longitude as geocoded_lng',
                    'osh.delivery_latitude',
                    'osh.delivery_longitude',
                ])
                ->orderBy('osh.changed_at', 'desc')
                ->get();
            
            // Group by date
            $dateGroups = [];
            foreach ($orders as $order) {
                $dateKey = $order->delivery_date;
                
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = [
                        'date' => $dateKey,
                        'date_display' => \Carbon\Carbon::parse($dateKey)->format('D, M j, Y'),
                        'cash_count' => 0,
                        'cash_total' => 0,
                        'online_count' => 0,
                        'online_total' => 0,
                        'total_delivered' => 0,
                        'orders' => [],
                    ];
                }
                
                $paymentMethod = strtolower($order->payment_method ?? 'cash');
                $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                $amount = (float)$order->total_price;
                
                if ($isCash) {
                    $dateGroups[$dateKey]['cash_count']++;
                    $dateGroups[$dateKey]['cash_total'] += $amount;
                } else {
                    $dateGroups[$dateKey]['online_count']++;
                    $dateGroups[$dateKey]['online_total'] += $amount;
                }
                
                $dateGroups[$dateKey]['total_delivered']++;
                
                // Determine location for map
                $location = null;
                if ($order->delivery_latitude && $order->delivery_longitude) {
                    $location = [
                        'latitude' => (float)$order->delivery_latitude,
                        'longitude' => (float)$order->delivery_longitude,
                        'source' => 'delivery_gps',
                    ];
                } elseif ($order->verified_lat && $order->verified_lng) {
                    $location = [
                        'latitude' => (float)$order->verified_lat,
                        'longitude' => (float)$order->verified_lng,
                        'source' => 'verified',
                    ];
                } elseif ($order->geocoded_lat && $order->geocoded_lng) {
                    $location = [
                        'latitude' => (float)$order->geocoded_lat,
                        'longitude' => (float)$order->geocoded_lng,
                        'source' => 'geocoded',
                    ];
                }
                
                // ⭐ Build ETA comparison
                $etaDisplay = null;
                $etaComparison = null;
                if ($order->estimated_delivery_at) {
                    $estimatedTime = \Carbon\Carbon::parse($order->estimated_delivery_at);
                    $etaDisplay = $estimatedTime->format('h:i A');
                    
                    $actualTime = \Carbon\Carbon::parse($order->delivered_at);
                    $diffMinutes = (int) round($actualTime->diffInMinutes($estimatedTime, false));
                    
                    $etaComparison = [
                        'estimated_at_display' => $etaDisplay,
                        'actual_at_display' => $actualTime->format('h:i A'),
                        'diff_minutes' => $diffMinutes,
                        'status' => $diffMinutes >= 0 ? 'early' : 'late',
                        'status_text' => $diffMinutes >= 0 
                            ? ($diffMinutes == 0 ? 'On time' : "{$diffMinutes} min early")
                            : (abs($diffMinutes) . ' min late'),
                        'status_emoji' => $diffMinutes >= 0 ? '✅' : '⚠️',
                    ];
                }
                
                $dateGroups[$dateKey]['orders'][] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer_id' => $order->customer_id,
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'address' => trim($order->address . ($order->city ? ', ' . $order->city : '')),
                    'amount' => $amount,
                    'amount_formatted' => 'Rs. ' . number_format($amount, 0),
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'delivered_at' => \Carbon\Carbon::parse($order->delivered_at)->format('h:i A'),
                    'delivered_at_raw' => $order->delivered_at, // ⭐ Raw timestamp for trail segment calculation
                    'estimated_delivery_at_display' => $etaDisplay, // ⭐ ETA time display
                    'eta_comparison' => $etaComparison, // ⭐ ETA vs actual comparison
                    'location' => $location,
                ];
            }
            
            // Sort by date (newest first) and convert to array
            $dates = collect($dateGroups)->sortByDesc('date')->values();
            
            // Calculate totals
            $totalDelivered = $orders->count();
            $cashTotal = $dates->sum('cash_total');
            $onlineTotal = $dates->sum('online_total');
            $cashCount = $dates->sum('cash_count');
            $onlineCount = $dates->sum('online_count');
            
            return response()->json([
                'success' => true,
                'rider' => $rider,
                'dates' => $dates,
                'summary' => [
                    'total_delivered' => $totalDelivered,
                    'cash_total' => $cashTotal,
                    'cash_count' => $cashCount,
                    'online_total' => $onlineTotal,
                    'online_count' => $onlineCount,
                    'grand_total' => $cashTotal + $onlineTotal,
                    'days_active' => $dates->count(),
                ],
                'period' => [
                    'start' => $threeMonthsAgo,
                    'end' => now()->format('Y-m-d'),
                ],
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get rider delivery history', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load history'
            ], 500);
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
                        
                        $meterStart = $record->meter_start ? (float)$record->meter_start : null;
                        $meterEnd = $record->meter_end ? (float)$record->meter_end : null;
                        $meterDistance = null;
                        $meterWarning = null;
                        if ($meterStart !== null && $meterEnd !== null) {
                            if ($meterEnd < $meterStart) {
                                $meterWarning = 'End reading (' . number_format($meterEnd) . ') is less than start (' . number_format($meterStart) . '). Please check your readings.';
                            } else {
                                $meterDistance = round($meterEnd - $meterStart, 1);
                                if ($meterDistance > 1000) {
                                    $meterWarning = 'Distance ' . number_format($meterDistance) . ' km seems unusually high. Please verify readings.';
                                }
                            }
                        }

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
                            'meter_start' => $meterStart,
                            'meter_end' => $meterEnd,
                            'meter_distance' => $meterDistance,
                            'meter_warning' => $meterWarning,
                            'checkin_latitude' => $record->checkin_latitude ?? null,
                            'checkin_longitude' => $record->checkin_longitude ?? null,
                            'checkin_distance_from_base' => $record->checkin_distance_from_base ?? null,
                            'is_remote_checkin' => $record->is_remote_checkin ?? 0,
                            'checkout_latitude' => $record->checkout_latitude ?? null,
                            'checkout_longitude' => $record->checkout_longitude ?? null,
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

            // Approved leaves taken for the current year
            $currentYear = date('Y');
            $yearLeaveDays = 0;
            $yearLeaveRequests = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
                ->where('status', 'approved')
                ->whereHas('category', fn($q) => $q->where('category_code', 'leave'))
                ->where('leave_start_date', '>=', "{$currentYear}-01-01")
                ->where('leave_end_date', '<=', "{$currentYear}-12-31")
                ->get();

            foreach ($yearLeaveRequests as $lr) {
                if ($lr->leave_start_date && $lr->leave_end_date) {
                    $yearLeaveDays += \Carbon\Carbon::parse($lr->leave_start_date)
                        ->diffInDays(\Carbon\Carbon::parse($lr->leave_end_date)) + 1;
                }
            }
            $summary['leaves_taken_year'] = $yearLeaveDays;
            $summary['leaves_year'] = (int) $currentYear;

            // Fetch petrol rate for this user from their assigned rate group
            $petrolRate = null;
            $userRateGroup = DB::table('t_fin_petrol_rate_group')
                ->where('is_active', 1)
                ->get()
                ->first(function ($group) use ($user) {
                    if (empty($group->user_ids)) return false;
                    $ids = array_map('trim', explode(',', $group->user_ids));
                    return in_array((string) $user->id, $ids);
                });

            if ($userRateGroup) {
                $petrolRate = (float) $userRateGroup->rate;
            }

            // Fetch existing petrol requests for this user in this month
            $petrolRequests = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'expense')
                ->where('r.requester_user_id', $user->id)
                ->where('r.expense_category', 'Petrol')
                ->whereNotNull('r.attendance_id')
                ->whereBetween('r.expense_date', [$startDate, $endDate])
                ->select('r.id', 'r.attendance_id', 'r.expense_date', 'r.amount', 'r.status', 'r.meter_distance', 'r.petrol_rate')
                ->get()
                ->keyBy('attendance_id');

            return response()->json([
                'success' => true,
                'month' => $month,
                'month_formatted' => date('F Y', strtotime($month)),
                'summary' => $summary,
                'history' => $history,
                'petrol_rate' => $petrolRate,
                'petrol_requests' => $petrolRequests,
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
            $businessUnitId = $request->input('business_unit_id');
            
            // ⭐ In Khaas mode (BU filter provided), only show khaas_expense
            // In normal mode, show expense, salary_advance, leave
            if ($businessUnitId && $businessUnitId != 1) {
                // Khaas mode: only show khaas_expense
                $categoryCodes = ['khaas_expense'];
            } else {
                // Normal store/rider mode
                $categoryCodes = ['expense', 'salary_advance', 'leave'];
            }
            
            $categories = \App\Models\Request\RequestCategoryModel::whereIn('category_code', $categoryCodes)
                ->where('is_active', 1)
                ->orderBy('sequence_order')
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

            $query = \App\Models\Request\RequestModel::with(['category', 'approvals.approver'])
                ->where('requester_user_id', $user->id)
                ->whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance', 'leave', 'khaas_expense']);
                })
                ->orderByDesc('created_at');

            if ($status !== 'all') {
                $query->where('status', $status);
            }

            $requests = $query->limit(100)->get()->map(function($req) {
                $approverName = null;
                if ($req->status === 'approved' || $req->status === 'rejected') {
                    $lastApproval = $req->approvals
                        ->sortByDesc('updated_at')
                        ->first(function ($a) use ($req) {
                            return $a->status === $req->status;
                        });
                    if ($lastApproval && $lastApproval->approver) {
                        $approverName = $lastApproval->approver->fullname;
                    }
                }

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
                    'approved_by' => $approverName,
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
                'expense_date' => 'nullable|date',
                'meter_distance' => 'nullable|numeric|min:0',
                'petrol_rate' => 'nullable|numeric|min:0',
                'attendance_id' => 'nullable|integer',
                'leave_start_date' => 'nullable|date',
                'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
                'leave_type' => 'nullable|string',
                'attachment_image' => 'nullable|image|max:5120',
            ]);

            // Duplicate check for petrol requests: one per attendance day
            if ($request->filled('attendance_id') && ($request->input('expense_category') === 'Petrol')) {
                if ($request->filled('expense_date')) {
                    $petrolDate = \Carbon\Carbon::parse($validated['expense_date'])->startOfDay();
                    $fiveDaysAgo = \Carbon\Carbon::today()->subDays(5);
                    if ($petrolDate->lt($fiveDaysAgo)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Petrol requests from meter reading can only be raised for the last 5 days.'
                        ], 422);
                    }
                }

                $existingPetrol = DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', 'expense')
                    ->where('r.requester_user_id', $user->id)
                    ->where('r.expense_category', 'Petrol')
                    ->where('r.attendance_id', $request->input('attendance_id'))
                    ->whereNotIn('r.status', ['cancelled', 'rejected'])
                    ->exists();

                if ($existingPetrol) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A petrol request has already been submitted for this day.'
                    ], 422);
                }
            }

            // ⭐ Validate expense_date is within allowed backdate range
            if ($request->filled('expense_date')) {
                $expenseDate = \Carbon\Carbon::parse($validated['expense_date']);
                $today = \Carbon\Carbon::today();
                
                // Get user's max backdate days from their roles
                $maxBackdateDays = DB::table('t_sys_user_role as ur')
                    ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                    ->where('ur.user_id', $user->id)
                    ->max('r.expense_backdate_days') ?? 0;
                
                // Check if date is in the future
                if ($expenseDate->gt($today)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Expense date cannot be in the future'
                    ], 422);
                }
                
                // Check if date is too far in the past
                $daysDiff = $today->diffInDays($expenseDate);
                if ($daysDiff > $maxBackdateDays) {
                    return response()->json([
                        'success' => false,
                        'message' => "You can only backdate expenses up to {$maxBackdateDays} days. Selected date is {$daysDiff} days ago."
                    ], 422);
                }
            }

            // Prevent duplicate leave requests for overlapping dates
            if ($request->filled('leave_start_date') && $request->filled('leave_end_date')) {
                $overlapping = DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', 'leave')
                    ->where('r.requester_user_id', $user->id)
                    ->whereNotIn('r.status', ['cancelled', 'rejected'])
                    ->where('r.leave_start_date', '<=', $validated['leave_end_date'])
                    ->where('r.leave_end_date', '>=', $validated['leave_start_date'])
                    ->select('r.leave_start_date', 'r.leave_end_date', 'r.request_number')
                    ->first();

                if ($overlapping) {
                    $existingStart = \Carbon\Carbon::parse($overlapping->leave_start_date)->format('M d');
                    $existingEnd = \Carbon\Carbon::parse($overlapping->leave_end_date)->format('M d');
                    return response()->json([
                        'success' => false,
                        'message' => "Leave already raised for {$existingStart} - {$existingEnd} ({$overlapping->request_number}). Cannot create overlapping leave request."
                    ], 422);
                }
            }

            // Verify category is allowed (⭐ includes khaas_expense)
            $category = \App\Models\Request\RequestCategoryModel::with('approvalConfig')
                ->whereIn('category_code', ['expense', 'salary_advance', 'leave', 'khaas_expense'])
                ->findOrFail($validated['category_id']);

            DB::beginTransaction();

            // Calculate leave days if it's a leave request
            $leaveDays = null;
            if (isset($validated['leave_start_date']) && isset($validated['leave_end_date'])) {
                $start = \Carbon\Carbon::parse($validated['leave_start_date']);
                $end = \Carbon\Carbon::parse($validated['leave_end_date']);
                $leaveDays = $end->diffInDays($start) + 1;
            }

            // Server-side payment source validation: non-EXP_FUND requires permission
            $paymentSourceAccountId = $request->input('payment_source_account_id');
            if ($paymentSourceAccountId) {
                $sourceAccount = \App\Models\FIN\AccountModel::find($paymentSourceAccountId);
                if ($sourceAccount && $sourceAccount->account_code !== 'EXP_FUND') {
                    $buId = $request->input('business_unit_id');
                    $canUseAllSources = $user->hasMobilePermission('expense_all_payment_sources')
                        || ($buId && $user->hasMobilePermission('approve_khaas_transfer'));
                    if (!$canUseAllSources) {
                        return response()->json([
                            'success' => false,
                            'message' => 'You do not have permission to create expenses from this payment source. Only Expense Fund is allowed.'
                        ], 403);
                    }
                }
                // Block private accounts for non-Taimur
                if ($sourceAccount && $sourceAccount->is_private) {
                    $isTaimurRole = $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
                    if (!$isTaimurRole) {
                        return response()->json([
                            'success' => false,
                            'message' => 'This payment source is not available.'
                        ], 403);
                    }
                }
            }
            if (!$paymentSourceAccountId && isset($validated['attendance_id']) && ($validated['expense_category'] ?? null) === 'Petrol') {
                $expFundAccount = \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->where('is_active', 1)->first();
                if ($expFundAccount) {
                    $paymentSourceAccountId = $expFundAccount->id;
                }
            }

            // Store attachment image if provided (e.g. fuel bill photo)
            $attachments = null;
            if ($request->hasFile('attachment_image')) {
                $file = $request->file('attachment_image');
                $date = now();
                $filename = "req_{$user->id}_{$date->format('Ymd_His')}.jpg";
                $path = "requests/attachments/{$date->format('Y')}/{$date->format('m')}/{$filename}";
                \Storage::disk('public')->put($path, file_get_contents($file));
                $attachments = [$path];
            }

            $newRequest = \App\Models\Request\RequestModel::create([
                'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
                'category_id' => $category->id,
                'requester_user_id' => $user->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'amount' => $validated['amount'] ?? null,
                'expense_category' => $validated['expense_category'] ?? null,
                'expense_date' => $validated['expense_date'] ?? now()->toDateString(),
                'meter_distance' => $validated['meter_distance'] ?? null,
                'petrol_rate' => $validated['petrol_rate'] ?? null,
                'attendance_id' => $validated['attendance_id'] ?? null,
                'payment_source_account_id' => $paymentSourceAccountId,
                'business_unit_id' => $request->input('business_unit_id', 1),
                'leave_start_date' => $validated['leave_start_date'] ?? null,
                'leave_end_date' => $validated['leave_end_date'] ?? null,
                'leave_type' => $validated['leave_type'] ?? null,
                'leave_days' => $leaveDays,
                'attachments' => $attachments,
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
     * ⭐ Get available accounts for loan/advance disbursement (Store Mode)
     */
    public function getDisbursementAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Get company accounts that can be used for disbursement
            // Include: All cash/asset accounts that can be payment sources
            $accounts = \DB::table('t_fin_accounts')
                ->where(function($q) {
                    $q->whereIn('account_code', ['NF_CASH', 'EXP_FUND', 'PETTY_CASH', 'ONLINE', 'NF_ONLINE', 'BANK', 'NF_FOOD'])
                      ->orWhere('account_category', 'company_cash')
                      ->orWhere('account_category', 'cash') // ⭐ Include 'cash' category
                      ->orWhere('account_category', 'online')
                      ->orWhere('account_category', 'bank')
                      ->orWhere('account_type', 'bank')
                      ->orWhere('account_type', 'online')
                      // Also include accounts with 'online' or 'bank' in name
                      ->orWhere('account_name', 'LIKE', '%online%')
                      ->orWhere('account_name', 'LIKE', '%bank%')
                      ->orWhere('account_name', 'LIKE', '%jazzcash%')
                      ->orWhere('account_name', 'LIKE', '%easypaisa%')
                      // ⭐ Include NF subsidiary accounts
                      ->orWhere('account_code', 'LIKE', 'NF_%');
                })
                ->where('is_active', 1)
                // ⭐ Exclude employee cash and expense accounts (only want source accounts)
                ->where('account_category', '!=', 'employee_cash')
                ->where('account_type', '!=', 'expense')
                ->select('id', 'account_code', 'account_name', 'current_balance', 'account_category', 'account_type')
                ->orderByRaw("CASE 
                    WHEN account_code = 'NF_CASH' THEN 1
                    WHEN account_code = 'EXP_FUND' THEN 2
                    WHEN account_name LIKE '%online%' THEN 3
                    ELSE 4
                END")
                ->get()
                ->map(function($acc) {
                    // Determine icon based on account type
                    $icon = '💵';
                    $isOnline = in_array($acc->account_category, ['online', 'bank']) 
                        || in_array($acc->account_type, ['online', 'bank'])
                        || stripos($acc->account_name, 'online') !== false
                        || stripos($acc->account_name, 'bank') !== false
                        || stripos($acc->account_name, 'jazzcash') !== false
                        || stripos($acc->account_name, 'easypaisa') !== false;
                    
                    if ($isOnline) {
                        $icon = '🏦';
                    } elseif ($acc->account_code === 'EXP_FUND') {
                        $icon = '📋';
                    }
                    
                    return [
                        'id' => $acc->id,
                        'code' => $acc->account_code,
                        'name' => $acc->account_name,
                        'balance' => (float)$acc->current_balance,
                        'icon' => $icon,
                        'category' => $acc->account_category,
                        'is_online' => $isOnline,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'accounts' => $accounts,
                // Add "Outside Cash" option for loans
                'outside_cash_option' => [
                    'id' => null,
                    'code' => 'OUTSIDE_CASH',
                    'name' => 'Outside Cash (Not from company)',
                    'balance' => null,
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load accounts: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Create salary advance for employee (Store Mode)
     * Auto-approves if requested by store manager
     */
    public function createSalaryAdvance(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'amount' => 'required|numeric|min:1',
                'reason' => 'nullable|string|max:500',
                'auto_approve' => 'nullable|boolean',
                'payment_source_account_id' => 'nullable|integer|exists:t_fin_accounts,id', // ⭐ Payment source
            ]);
            
            // Get salary_advance category
            $category = \App\Models\Request\RequestCategoryModel::where('category_code', 'salary_advance')->first();
            
            if (!$category) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salary advance category not configured'
                ], 500);
            }
            
            // Generate request number
            $requestNumber = 'REQ-' . date('Ymd') . '-' . str_pad(
                \App\Models\Request\RequestModel::whereDate('created_at', today())->count() + 1,
                3, '0', STR_PAD_LEFT
            );
            
            $autoApprove = $validated['auto_approve'] ?? false;
            
            // Create the request using only valid columns
            $advanceRequest = \App\Models\Request\RequestModel::create([
                'request_number' => $requestNumber,
                'requester_user_id' => $validated['user_id'],
                'category_id' => $category->id,
                'title' => 'Salary Advance',
                'description' => $validated['reason'] ?? 'Salary advance requested by store manager',
                'amount' => $validated['amount'],
                'status' => 'pending', // Start as pending
                'submitted_at' => now(),
                'created_by' => $user->id,
                'payment_source_account_id' => $validated['payment_source_account_id'] ?? null,
                // No approval workflow required for store manager
                'requires_level_1' => false,
                'requires_level_2' => false,
            ]);
            
            // ⭐ If auto-approved, use the model's approve method
            if ($autoApprove) {
                // Approve the request properly
                $advanceRequest->status = 'approved';
                $advanceRequest->completed_at = now();
                $advanceRequest->updated_by = $user->id;
                $advanceRequest->save();
                
                // Post to ledger
                try {
                    $ledgerService = new \App\Services\FIN\LedgerPostingService();
                    $ledgerService->postSalaryAdvanceFromRequest($advanceRequest);
                } catch (\Exception $e) {
                    \Log::warning('Failed to post salary advance to ledger', [
                        'request_id' => $advanceRequest->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail - advance is created, ledger can be posted later
                }
            }
            
            \Log::info('Salary advance created from store mode', [
                'request_id' => $advanceRequest->id,
                'request_number' => $requestNumber,
                'for_user_id' => $validated['user_id'],
                'amount' => $validated['amount'],
                'auto_approved' => $autoApprove,
                'created_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => $autoApprove ? 'Salary advance created and approved' : 'Salary advance created',
                'request' => [
                    'id' => $advanceRequest->id,
                    'request_number' => $requestNumber,
                    'amount' => $validated['amount'],
                    'status' => $advanceRequest->status,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to create salary advance', [
                'user_id' => $request->input('user_id'),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create advance: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get employee salary details (loans and pending advances) for store mode
     */
    public function getEmployeeSalaryDetails(Request $request, $userId)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Get employee profile with active loans
            $profile = \App\Models\HR\EmployeeProfileModel::with(['activeLoans' => function($q) {
                $q->where('loan_status', 'active');
            }])
            ->where('user_id', $userId)
            ->first();
            
            // Get pending salary advances (not settled)
            $pendingAdvances = \App\Models\Request\RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where('status', 'approved')
                ->where(function($q) {
                    $q->whereNull('settlement_status')
                      ->orWhere('settlement_status', '!=', 'settled');
                })
                ->orderByDesc('created_at')
                ->get()
                ->map(function($adv) {
                    return [
                        'id' => $adv->id,
                        'request_number' => $adv->request_number,
                        'amount' => (float)$adv->amount,
                        'title' => $adv->title,
                        'description' => $adv->description,
                        'created_at' => $adv->created_at->format('Y-m-d'),
                        'settlement_status' => $adv->settlement_status ?? 'pending',
                    ];
                });
            
            // ⭐ Get advance history (all advances including settled ones for history view)
            $advanceHistory = \App\Models\Request\RequestModel::where('requester_user_id', $userId)
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where('status', 'approved')
                ->orderByDesc('created_at')
                ->limit(20) // Last 20 advances
                ->get()
                ->map(function($adv) {
                    return [
                        'id' => $adv->id,
                        'request_number' => $adv->request_number,
                        'amount' => (float)$adv->amount,
                        'title' => $adv->title,
                        'description' => $adv->description,
                        'created_at' => $adv->created_at->format('Y-m-d'),
                        'settlement_status' => $adv->settlement_status ?? 'pending',
                        'settled_at' => $adv->settled_at ? $adv->settled_at->format('Y-m-d') : null,
                        'is_settled' => $adv->settlement_status === 'settled',
                    ];
                });
            
            // Get active loans summary - ensure it's always a Collection
            $activeLoans = collect(); // Start with empty collection
            if ($profile && $profile->activeLoans) {
                $activeLoans = $profile->activeLoans->map(function($loan) {
                    return [
                        'id' => $loan->id,
                        'loan_number' => $loan->loan_number,
                        'principal_amount' => (float)$loan->principal_amount,
                        'monthly_installment' => (float)$loan->monthly_installment,
                        'outstanding_balance' => (float)$loan->outstanding_balance, // ⭐ Fixed: use property
                        'total_paid' => (float)$loan->getAmountPaid(), // ⭐ Fixed: correct method name
                        'description' => $loan->description,
                        'loan_date' => $loan->loan_date ? $loan->loan_date->format('Y-m-d') : null,
                        'loan_status' => $loan->loan_status,
                    ];
                });
            }
            
            return response()->json([
                'success' => true,
                'user_id' => $userId,
                'loans' => $activeLoans->values(), // Convert to array
                'advances' => $pendingAdvances->values(), // Convert to array
                'advance_history' => $advanceHistory->values(), // ⭐ History including settled
                'total_loan_outstanding' => $activeLoans->sum('outstanding_balance'),
                'total_advances_pending' => $pendingAdvances->sum('amount'),
                'has_advance_history' => $advanceHistory->count() > 0,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get employee salary details', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load details: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Settle a salary advance manually (Store Mode)
     * Marks the advance as settled so it no longer shows on employee's pending balance
     */
    public function settleSalaryAdvance(Request $request, $advanceId)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            // Find the advance request
            $advance = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->where('category_code', 'salary_advance');
                })
                ->where('id', $advanceId)
                ->where('status', 'approved')
                ->first();
            
            if (!$advance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Salary advance not found or not approved'
                ], 404);
            }
            
            // Check if already settled
            if ($advance->settlement_status === 'settled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This advance is already settled'
                ], 400);
            }
            
            // Mark as settled
            $advance->settlement_status = 'settled';
            $advance->settled_at = now();
            $advance->settled_by = $user->id;
            $advance->settlement_notes = $request->input('notes', 'Manually settled from store mobile app');
            $advance->save();
            
            \Log::info('Salary advance manually settled', [
                'advance_id' => $advance->id,
                'request_number' => $advance->request_number,
                'amount' => $advance->amount,
                'settled_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Salary advance marked as settled',
                'advance' => [
                    'id' => $advance->id,
                    'request_number' => $advance->request_number,
                    'amount' => $advance->amount,
                    'settlement_status' => $advance->settlement_status,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to settle salary advance', [
                'advance_id' => $advanceId,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to settle advance: ' . $e->getMessage()
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
            
            // ⭐ Get expense backdate days from user's roles
            $expenseBackdateDays = \DB::table('t_sys_user_role as ur')
                ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->where('ur.user_id', $user->id)
                ->max('r.expense_backdate_days') ?? 0;
            
            \Log::info('Mobile permissions fetched', [
                'user_id' => $user->id,
                'user_name' => $user->fullname,
                'roles_count' => $user->roles->count(),
                'permissions' => $permissions,
                'has_store_mode' => in_array('access_store_mode', $permissions),
                'expense_backdate_days' => $expenseBackdateDays
            ]);
            
            // ⭐ Get Khaas business unit info for Khaas mode
            $hasKhaasMode = in_array('access_khaas_mode', $permissions);
            $khaasBusinessUnit = null;
            if ($hasKhaasMode) {
                $khaasBusinessUnit = \App\Models\FIN\BusinessUnitModel::where('code', 'KHAAS')
                    ->where('is_active', 1)
                    ->first(['id', 'code', 'name', 'short_code', 'color_hex']);
            }
            
            $hasQurbaniMode = in_array('access_qurbani_mode', $permissions);
            $qurbaniRiderDeliveredEnabled = \App\Models\FIN\ConfigModel::get('qurbani_rider_delivered_enabled', '0') === '1';

            return response()->json([
                'success' => true,
                'permissions' => $permissions,
                'has_store_mode' => in_array('access_store_mode', $permissions),
                'has_khaas_mode' => $hasKhaasMode,
                'khaas_business_unit' => $khaasBusinessUnit,
                'has_qurbani_mode' => $hasQurbaniMode,
                'qurbani_rider_delivered_enabled' => $qurbaniRiderDeliveredEnabled,
                'expense_backdate_days' => (int)$expenseBackdateDays
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
                'has_store_mode' => false,
                'has_khaas_mode' => false,
                'has_qurbani_mode' => false
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
     * Filters based on mobile visibility settings and user role
     */
    public function getOrderStatuses(Request $request)
    {
        try {
            $user = Auth::user();
            $userRoleIds = $user ? $user->roles()->pluck('t_sys_role.id')->toArray() : [];
            
            $statuses = DB::table('t_crm_order_status_master')
                ->where('is_active', 1)
                ->where('show_in_mobile', 1)  // ⭐ Only show statuses enabled for mobile
                // Exclude legacy status codes (keep only underscore versions)
                ->whereNotIn('status_code', ['on-hold', 'on hold'])
                ->orderBy('sequence_order')
                ->get(['id', 'status_code', 'status_name', 'icon', 'color_class', 'visible_to_roles', 'sequence_order']);
            
            // Filter by role visibility
            $filteredStatuses = $statuses->filter(function($status) use ($userRoleIds) {
                // If no role restriction (null or empty), visible to all
                $visibleRoles = $status->visible_to_roles;
                if (empty($visibleRoles)) {
                    return true;
                }
                
                // Decode JSON if it's a string
                if (is_string($visibleRoles)) {
                    $visibleRoles = json_decode($visibleRoles, true);
                }
                
                // If still empty after decode, visible to all
                if (empty($visibleRoles)) {
                    return true;
                }
                
                // Check if user has any of the required roles
                return !empty(array_intersect($userRoleIds, $visibleRoles));
            })->map(function($status) {
                // Include sequence_order for mobile app sorting
                return [
                    'status_code' => $status->status_code,
                    'status_name' => $status->status_name,
                    'icon' => $status->icon,
                    'color_class' => $status->color_class,
                    'sequence_order' => $status->sequence_order  // ⭐ NEW: For sorting in mobile
                ];
            })->values();
            
            return response()->json([
                'success' => true,
                'statuses' => $filteredStatuses
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to fetch order statuses', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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

            // Exclude qurbani orders from store mode
            $qurbaniExcludeIds = \DB::table('t_crm_prod_order_line_item')
                ->join('t_crm_prod_product', 't_crm_prod_order_line_item.product_id', '=', 't_crm_prod_product.id')
                ->whereRaw("LOWER(t_crm_prod_product.attribute_1) = 'qurbani'")
                ->distinct()
                ->pluck('t_crm_prod_order_line_item.order_id');

            if ($qurbaniExcludeIds->isNotEmpty()) {
                $query->whereNotIn('id', $qurbaniExcludeIds);
            }

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
                
                // Check if customer has verified location and build verified_location object
                $hasVerifiedLocation = false;
                $verifiedLocation = null;
                if ($order->customer) {
                    $hasCoords = !empty($order->customer->latitude) && !empty($order->customer->longitude);
                    $hasUrl = !empty($order->customer->verified_location_url);
                    $hasVerifiedLocation = $hasCoords || $hasUrl;
                    
                    if ($hasVerifiedLocation) {
                        // Get coordinates: prioritize stored coords, else parse from URL
                        $lat = $order->customer->latitude ? (float)$order->customer->latitude : null;
                        $lng = $order->customer->longitude ? (float)$order->customer->longitude : null;
                        
                        // If no stored coords but URL exists, try to parse coords from URL
                        if ((!$lat || !$lng) && $hasUrl) {
                            $parsedCoords = $this->parseCoordinatesFromGoogleMapsUrl($order->customer->verified_location_url);
                            if ($parsedCoords) {
                                $lat = $parsedCoords['latitude'];
                                $lng = $parsedCoords['longitude'];
                            }
                        }
                        
                        // Build google_maps_url: prioritize stored URL, else construct from coordinates
                        $googleMapsUrl = $order->customer->verified_location_url;
                        if (!$googleMapsUrl && $lat && $lng) {
                            $googleMapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                        }
                        
                        $verifiedLocation = [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'url' => $order->customer->verified_location_url ?? null,
                            'google_maps_url' => $googleMapsUrl,
                        ];
                    }
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
                    // ⭐ Customer Notes - use primary customer notes if order linked to merged duplicate
                    'customer_notes' => $order->customer ? (
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ) : null,
                    'has_customer_notes' => $order->customer && !empty(
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ),
                    // ⭐ Order Notes - order-specific notes
                    'order_note' => $order->note ?? null,
                    'has_order_note' => !empty($order->note),
                    'has_verified_location' => $hasVerifiedLocation,
                    'verified_location' => $verifiedLocation,
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
                            'line_total' => $item->line_total,
                            'total' => $item->line_total,
                            'total_formatted' => $item->is_free ? 'FREE' : 'Rs. ' . number_format($item->line_total, 0),
                            'preparation_status' => $item->preparation_status,
                            'is_free' => (bool) $item->is_free,
                            'instructions' => $item->instructions,
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
                        'image_url' => $invoiceImageUrl,
                        'pdf_url' => $invoicePdfUrl,
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
                    $q->select('id', 'first_name', 'last_name', 'phone_original', 'latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude', 'verified_location_url', 'notes', 'verified_location_saved_by', 'verified_location_saved_at', 'merged_into_customer_id', 'delivery_region_id');
                }])
                ->with(['assignedRider' => function($q) {
                    $q->select('id', 'fullname');
                }])
                ->with(['lineItems' => function($q) {
                    $q->select('id', 'order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'preparation_status', 'is_free', 'instructions');
                }])
                ->with(['discounts']) // ⭐ Load discounts for invoice view
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')
                      ->orWhereNull('external_source');
                })
                ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded']);

            // Exclude qurbani orders from store mode (mixed orders are blocked at creation)
            $qurbaniExcludeIds = \DB::table('t_crm_prod_order_line_item')
                ->join('t_crm_prod_product', 't_crm_prod_order_line_item.product_id', '=', 't_crm_prod_product.id')
                ->whereRaw("LOWER(t_crm_prod_product.attribute_1) = 'qurbani'")
                ->distinct()
                ->pluck('t_crm_prod_order_line_item.order_id');

            if ($qurbaniExcludeIds->isNotEmpty()) {
                $query->whereNotIn('id', $qurbaniExcludeIds);
            }

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
            
            // ❄️ Detect orders containing Khaas (non-default BU) products in single query
            $orderIds = $orders->pluck('id');
            $khaasOrderIds = collect();
            if ($orderIds->isNotEmpty()) {
                $khaasOrderIds = \DB::table('t_crm_prod_order_line_item')
                    ->join('t_crm_prod_product', 't_crm_prod_order_line_item.product_id', '=', 't_crm_prod_product.id')
                    ->whereIn('t_crm_prod_order_line_item.order_id', $orderIds)
                    ->where('t_crm_prod_product.business_unit_id', '!=', 1)
                    ->distinct()
                    ->pluck('t_crm_prod_order_line_item.order_id');
            }
            
            $regionMap = [];
            try {
                $regionMap = \DB::table('t_ops_delivery_region')
                    ->where('is_active', 1)
                    ->pluck('name', 'id')
                    ->toArray();
            } catch (\Exception $e) {}

            // Format for mobile (lightweight)
            $formattedOrders = $orders->map(function($order) use ($prepSummaries, $khaasOrderIds, $regionMap) {
                // Build customer name
                $customerName = $order->name ?? 'N/A';
                if (!$order->name && ($order->address_first_name || $order->address_last_name)) {
                    $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
                }
                if ($customerName === 'N/A' && $order->customer) {
                    // Customer table has first_name and last_name, not name
                    $customerName = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? '')) ?: 'Unknown';
                }
                
                // Check verified location and build verified_location object
                $hasVerifiedLocation = false;
                $verifiedLocation = null;
                if ($order->customer) {
                    $hasCoords = !empty($order->customer->latitude) && !empty($order->customer->longitude);
                    $hasUrl = !empty($order->customer->verified_location_url);
                    $hasVerifiedLocation = $hasCoords || $hasUrl;
                    
                    if ($hasVerifiedLocation) {
                        // Get coordinates: prioritize stored coords, else parse from URL
                        $lat = $order->customer->latitude ? (float)$order->customer->latitude : null;
                        $lng = $order->customer->longitude ? (float)$order->customer->longitude : null;
                        
                        // If no stored coords but URL exists, try to parse coords from URL
                        if ((!$lat || !$lng) && $hasUrl) {
                            $parsedCoords = $this->parseCoordinatesFromGoogleMapsUrl($order->customer->verified_location_url);
                            if ($parsedCoords) {
                                $lat = $parsedCoords['latitude'];
                                $lng = $parsedCoords['longitude'];
                            }
                        }
                        
                        // Build google_maps_url: prioritize stored URL, else construct from coordinates
                        $googleMapsUrl = $order->customer->verified_location_url;
                        if (!$googleMapsUrl && $lat && $lng) {
                            $googleMapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                        }
                        
                        // ⭐ Get saved_by user name if available
                        $savedByName = null;
                        if ($order->customer->verified_location_saved_by) {
                            $savedByName = \DB::table('t_sys_user')
                                ->where('id', $order->customer->verified_location_saved_by)
                                ->value('fullname');
                        }
                        
                        $verifiedLocation = [
                            'latitude' => $lat,
                            'longitude' => $lng,
                            'url' => $order->customer->verified_location_url ?? null,
                            'google_maps_url' => $googleMapsUrl,
                            // ⭐ Who saved and when - for display in UI
                            'saved_by' => $savedByName,
                            'saved_at' => $order->customer->verified_location_saved_at,
                        ];
                    }
                }
                
                // Get preparation summary from pre-fetched data
                $prepSummary = $prepSummaries[$order->id] ?? null;
                
                // ⭐ Safety net: if the order's customer is a merged duplicate, use the primary customer's notes
                $effectiveCustomerNotes = null;
                if ($order->customer) {
                    if ($order->customer->merged_into_customer_id) {
                        // This order is linked to a merged duplicate - get primary customer's notes
                        $primaryCustomer = \App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id);
                        $effectiveCustomerNotes = $primaryCustomer ? ($primaryCustomer->notes ?? null) : null;
                    } else {
                        $effectiveCustomerNotes = $order->customer->notes ?? null;
                    }
                }
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'delivery_priority' => $order->delivery_priority, // ⭐ Delivery sequence priority
                    // ⭐ Estimated delivery time (from "Get Times" button)
                    'estimated_delivery_at' => $order->estimated_delivery_at,
                    'estimated_delivery_at_display' => $order->estimated_delivery_at 
                        ? \Carbon\Carbon::parse($order->estimated_delivery_at)->format('h:i A')
                        : null,
                    'expected_packets' => $order->expected_packets, // ⭐ Packet info (from manager)
                    'actual_packets' => $order->actual_packets,     // ⭐ Actual packets (from rider, after delivery)
                    'payment_method' => $order->payment_method,     // ⭐ Payment method (cash/online)
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
                    // ⭐ Customer Notes - use primary customer's notes if order is linked to merged duplicate
                    'customer_notes' => $effectiveCustomerNotes,
                    'has_customer_notes' => !empty($effectiveCustomerNotes),
                    // ⭐ Order Notes - order-specific notes
                    'order_note' => $order->note ?? null,
                    'has_order_note' => !empty($order->note),
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
                    'verified_location' => $verifiedLocation,
                    'has_khaas_item' => $khaasOrderIds->contains($order->id), // ❄️ Khaas product indicator
                    // ⭐ Customer location data for route map
                    'customer' => $order->customer ? [
                        'latitude' => $order->customer->latitude,
                        'longitude' => $order->customer->longitude,
                        'geocoded_latitude' => $order->customer->geocoded_latitude,
                        'geocoded_longitude' => $order->customer->geocoded_longitude,
                    ] : null,
                    'external_source' => $order->external_source,
                    'delivery_region_id' => $order->customer->delivery_region_id ?? null,
                    'delivery_region_name' => ($order->customer->delivery_region_id ?? null) ? ($regionMap[$order->customer->delivery_region_id] ?? null) : null,
                    'updated_at' => $order->updated_at ? $order->updated_at->toIso8601String() : null, // ⭐ For processing time calculation
                    // ⭐ Invoice fields - needed for invoice view
                    'subtotal_price' => $order->subtotal_price ?? 0,
                    'discount_total' => $order->discount_total ?? 0,
                    'shipping_total' => $order->shipping_total ?? 0,
                    'tip_amount' => $order->tip_amount ?? 0,
                    'discounts' => $order->discounts ? $order->discounts->map(function($discount) {
                        return [
                            'discount_amount' => $discount->discount_amount,
                            'discount_type' => $discount->discount_type,
                        ];
                    })->toArray() : [],
                    'line_items' => $order->lineItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name ?? 'N/A',
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => (float) $item->quantity,
                            'unit_price' => (float) $item->unit_price,
                            'unit_price_formatted' => $item->unit_price ? 'Rs. ' . number_format($item->unit_price, 0) : null,
                            'line_total' => (float) $item->line_total,
                            'preparation_status' => $item->preparation_status,
                            'is_free' => (bool) $item->is_free,
                            'instructions' => $item->instructions,
                        ];
                    })->values()->toArray(),
                ];
            });
            
            // ⭐ PINNED RIDER DASHBOARD: Include rider summaries for pinned riders
            // Supports both single rider (pinned_rider_id) and multiple riders (pinned_rider_ids)
            $riderSummary = null;
            $riderSummaries = null;
            
            // Single rider (backward compatible)
            $pinnedRiderId = $request->get('pinned_rider_id');
            if ($pinnedRiderId) {
                $riderSummary = $this->getRiderDashboardSummary($pinnedRiderId);
            }
            
            // Multiple riders (new: prefetch all at once for seamless tab switching)
            $pinnedRiderIds = $request->get('pinned_rider_ids');
            if ($pinnedRiderIds) {
                // Accept comma-separated string or array
                if (is_string($pinnedRiderIds)) {
                    $pinnedRiderIds = array_filter(array_map('trim', explode(',', $pinnedRiderIds)));
                }
                if (!empty($pinnedRiderIds)) {
                    $riderSummaries = [];
                    foreach ($pinnedRiderIds as $id) {
                        $summary = $this->getRiderDashboardSummary($id);
                        if ($summary) {
                            $riderSummaries[$id] = $summary;
                        }
                    }
                }
            }
            
            $response = [
                'success' => true,
                'orders' => $formattedOrders,
                'total_count' => $formattedOrders->count()
            ];
            
            // Single rider summary (backward compatible)
            if ($riderSummary) {
                $response['rider_summary'] = $riderSummary;
            }
            
            // Multiple rider summaries (new: keyed by rider ID)
            if ($riderSummaries && !empty($riderSummaries)) {
                $response['rider_summaries'] = $riderSummaries;
            }
            
            return response()->json($response);
            
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
     * ⭐ PINNED RIDER DASHBOARD: Get rider summary for dashboard header
     * Returns delivered count, last delivered order, location and distance from office
     * 
     * @param int $riderId
     * @return array|null
     */
    private function getRiderDashboardSummary($riderId)
    {
        try {
            $today = now()->format('Y-m-d');
            
            // Get rider info
            $rider = \DB::table('t_sys_user')
                ->where('id', $riderId)
                ->where('is_active', 1)
                ->select('id', 'fullname as name')
                ->first();
            
            if (!$rider) {
                return null;
            }
            
            // ⭐ Get delivered orders count TODAY for this rider
            // Based on status history (when order was marked delivered)
            $deliveredToday = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_order_status_history as osh', function($join) use ($today) {
                    $join->on('o.id', '=', 'osh.order_id')
                         ->where('osh.status_code', '=', 'delivered')
                         ->whereDate('osh.changed_at', $today);
                })
                ->where('o.assigned_rider_user_id', $riderId)
                ->select(\DB::raw('COUNT(DISTINCT o.id) as count'))
                ->first();
            
            $deliveredTodayCount = $deliveredToday->count ?? 0;
            
            // ⭐ Get last delivered order for this rider (today)
            $lastDelivered = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_order_status_history as osh', function($join) use ($today) {
                    $join->on('o.id', '=', 'osh.order_id')
                         ->where('osh.status_code', '=', 'delivered')
                         ->whereDate('osh.changed_at', $today);
                })
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $riderId)
                ->select([
                    'o.id',
                    'o.order_number',
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'osh.changed_at as delivered_at',
                ])
                ->orderBy('osh.changed_at', 'desc')
                ->first();
            
            // ⭐ Get rider's current location (from heartbeat)
            $riderLocation = \DB::table('t_ops_rider_location')
                ->where('user_id', $riderId)
                ->where('captured_at', '>=', now()->subHours(24))
                ->orderBy('captured_at', 'desc')
                ->select('latitude', 'longitude', 'accuracy', 'captured_at')
                ->first();
            
            // ⭐ Calculate distance from office using LocationService
            $distanceInfo = null;
            if ($riderLocation) {
                $distanceInfo = \App\Services\LocationService::calculateDistanceFromBase(
                    $riderLocation->latitude,
                    $riderLocation->longitude,
                    $riderId
                );
            }
            
            // ⭐ Get rider's online status (based on last sync or location)
            $lastSync = \DB::table('t_crm_prod_order')
                ->where('assigned_rider_user_id', $riderId)
                ->whereNotNull('rider_last_sync_at')
                ->max('rider_last_sync_at');
            
            // Determine online status
            $lastSeenTime = null;
            $lastSeenText = 'Unknown';
            $isOnline = false;
            
            // Use the most recent: location heartbeat or order sync
            if ($riderLocation && $riderLocation->captured_at) {
                $locTime = \Carbon\Carbon::parse($riderLocation->captured_at);
                if (!$lastSeenTime || $locTime->gt($lastSeenTime)) {
                    $lastSeenTime = $locTime;
                }
            }
            if ($lastSync) {
                $syncTime = \Carbon\Carbon::parse($lastSync);
                if (!$lastSeenTime || $syncTime->gt($lastSeenTime)) {
                    $lastSeenTime = $syncTime;
                }
            }
            
            if ($lastSeenTime) {
                $ageMinutes = abs(now()->diffInMinutes($lastSeenTime));
                $lastSeenText = $lastSeenTime->diffForHumans();
                $isOnline = $ageMinutes <= 5;  // Online if seen within 5 minutes
            }
            
            return [
                'rider' => [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'is_online' => $isOnline,
                    'last_seen' => $lastSeenText,
                    'last_seen_at' => $lastSeenTime ? $lastSeenTime->toIso8601String() : null,
                ],
                'delivered_today' => $deliveredTodayCount,
                'last_delivered' => $lastDelivered ? [
                    'order_id' => $lastDelivered->id,
                    'order_number' => $lastDelivered->order_number,
                    'customer_name' => trim($lastDelivered->customer_name) ?: 'Unknown',
                    'delivered_at' => \Carbon\Carbon::parse($lastDelivered->delivered_at)->format('h:i A'),
                ] : null,
                'location' => $riderLocation ? [
                    'latitude' => (float)$riderLocation->latitude,
                    'longitude' => (float)$riderLocation->longitude,
                    'accuracy' => $riderLocation->accuracy,
                    'captured_at' => $riderLocation->captured_at,
                    // ⭐ Pre-computed age for consistent display (avoids client-side timezone issues)
                    'age' => \Carbon\Carbon::parse($riderLocation->captured_at)->diffForHumans(),
                ] : null,
                'office' => $distanceInfo && $distanceInfo['base_location'] ? [
                    'name' => $distanceInfo['base_location']->location_name ?? 'Office',
                    'latitude' => (float)$distanceInfo['base_location']->latitude,
                    'longitude' => (float)$distanceInfo['base_location']->longitude,
                    'distance_meters' => $distanceInfo['distance_meters'],
                    'distance_display' => $distanceInfo['distance_meters'] 
                        ? \App\Services\LocationService::formatDistance($distanceInfo['distance_meters'])
                        : null,
                ] : null,
                'route_lock' => $this->getRouteLockInfo($riderId),
            ];
            
        } catch (\Exception $e) {
            \Log::error('Failed to get rider dashboard summary', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getRouteLockInfo($riderId)
    {
        $lock = \DB::table('t_crm_route_lock as rl')
            ->leftJoin('t_sys_user as u', 'u.id', '=', 'rl.locked_by')
            ->where('rl.rider_id', $riderId)
            ->select('rl.locked_by', 'u.fullname as locked_by_name', 'rl.locked_at')
            ->first();

        if (!$lock) return null;

        return [
            'locked_by' => $lock->locked_by,
            'locked_by_name' => $lock->locked_by_name,
            'locked_at' => $lock->locked_at,
        ];
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
            
            // Check verified location and build verified_location object
            $hasVerifiedLocation = false;
            $verifiedLocation = null;
            if ($order->customer) {
                $hasCoords = !empty($order->customer->latitude) && !empty($order->customer->longitude);
                $hasUrl = !empty($order->customer->verified_location_url);
                $hasVerifiedLocation = $hasCoords || $hasUrl;
                
                if ($hasVerifiedLocation) {
                    // Get coordinates: prioritize stored coords, else parse from URL
                    $lat = $order->customer->latitude ? (float)$order->customer->latitude : null;
                    $lng = $order->customer->longitude ? (float)$order->customer->longitude : null;
                    
                    // If no stored coords but URL exists, try to parse coords from URL
                    if ((!$lat || !$lng) && $hasUrl) {
                        $parsedCoords = $this->parseCoordinatesFromGoogleMapsUrl($order->customer->verified_location_url);
                        if ($parsedCoords) {
                            $lat = $parsedCoords['latitude'];
                            $lng = $parsedCoords['longitude'];
                        }
                    }
                    
                    // Build google_maps_url: prioritize stored URL, else construct from coordinates
                    $googleMapsUrl = $order->customer->verified_location_url;
                    if (!$googleMapsUrl && $lat && $lng) {
                        $googleMapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
                    }
                    
                    $verifiedLocation = [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'url' => $order->customer->verified_location_url ?? null,
                        'google_maps_url' => $googleMapsUrl,
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
                    // ⭐ Customer Notes - use primary customer notes if order linked to merged duplicate
                    'customer_notes' => $order->customer ? (
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ) : null,
                    'has_customer_notes' => $order->customer && !empty(
                        $order->customer->merged_into_customer_id
                            ? (\App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id)?->notes ?? null)
                            : ($order->customer->notes ?? null)
                    ),
                    // ⭐ Order Notes - order-specific notes
                    'order_note' => $order->note ?? null,
                    'has_order_note' => !empty($order->note),
                    'has_verified_location' => $hasVerifiedLocation,
                    'verified_location' => $verifiedLocation,
                    'has_khaas_item' => (function() use ($order) { // ❄️ Khaas product indicator
                        $productIds = $order->lineItems->pluck('product_id')->filter()->unique()->values();
                        if ($productIds->isEmpty()) return false;
                        return \DB::table('t_crm_prod_product')
                            ->whereIn('id', $productIds)
                            ->where('business_unit_id', '!=', 1)
                            ->exists();
                    })(),
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
                            'total_formatted' => $item->is_free ? 'FREE' : 'Rs. ' . number_format($item->line_total, 0),
                            'preparation_status' => $item->preparation_status,
                            'is_free' => (bool) $item->is_free,
                            'instructions' => $item->instructions,
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
     * Uses the model's assignRider() method to properly create history records
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
                'rider_id' => 'required|integer|min:0'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            
            // ⚠️ SAFETY CHECK: Don't allow rider change for delivered/completed orders
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change rider for delivered or completed orders'
                ], 422);
            }
            
            // Unassign rider when rider_id is 0
            if ((int)$validated['rider_id'] === 0) {
                DB::table('t_ops_order_rider_history')
                    ->where('order_id', $order->id)
                    ->where('is_current', 1)
                    ->update(['is_current' => 0, 'unassigned_at' => now()]);
                $order->assigned_rider_user_id = null;
                $order->save();
                return response()->json([
                    'success' => true,
                    'message' => 'Rider unassigned successfully',
                ]);
            }
            
            // Get rider name for response
            $rider = DB::table('t_sys_user')->where('id', $validated['rider_id'])->first();
            
            if (!$rider) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rider not found'
                ], 404);
            }
            
            $success = $order->assignRider(
                $validated['rider_id'],
                'Assigned via Store Mode',
                $user->id
            );
            
            if (!$success) {
                throw new \Exception('Failed to assign rider - model method returned false');
            }
            
            \Log::info('Rider assigned to order (Store Mode) - with history', [
                'order_id' => $order->id,
                'rider_id' => $validated['rider_id'],
                'rider_name' => $rider->fullname,
                'assigned_by' => $user->id,
                'assigned_by_name' => $user->fullname
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
            \Log::error('Failed to assign rider (Store Mode)', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
     * STORE MODE: Update order note/instructions
     */
    public function updateOrderNote(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - reuse enter_packet_info permission (general order editing)
            if (!$user->hasMobilePermission('enter_packet_info')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to edit order instructions'
                ], 403);
            }
            
            $validated = $request->validate([
                'order_id' => 'required|exists:t_crm_prod_order,id',
                'note' => 'nullable|string|max:1000'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            
            // Don't allow editing if already delivered
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot edit instructions for delivered orders'
                ], 422);
            }
            
            // Build the new note with attribution
            $newNote = trim($validated['note'] ?? '');
            $existingNote = trim($order->note ?? '');
            
            if (!empty($newNote)) {
                // Add attribution: "Note by [User Name]: [note text]"
                $attribution = "[{$user->fullname} - " . now()->format('d M H:i') . "]";
                
                if (!empty($existingNote)) {
                    // Append to existing note
                    $finalNote = $existingNote . "\n" . $attribution . " " . $newNote;
                } else {
                    // New note
                    $finalNote = $attribution . " " . $newNote;
                }
                
                $order->note = $finalNote;
            }
            
            $order->save();
            
            \Log::info('Order note updated (Store Mode)', [
                'order_id' => $order->id,
                'note_length' => strlen($order->note ?? ''),
                'updated_by' => $user->id,
                'user_name' => $user->fullname
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Order instructions updated successfully',
                'note' => $order->note
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to update order note', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update order instructions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Add note to customer (saves to customer record for future orders)
     */
    public function addCustomerNote(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - reuse enter_packet_info permission (general order editing)
            if (!$user->hasMobilePermission('enter_packet_info')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to add customer notes'
                ], 403);
            }
            
            $validated = $request->validate([
                'customer_id' => 'required|exists:t_crm_prod_customer,id',
                'note' => 'required|string|max:1000'
            ]);
            
            $customer = \App\Models\CRM\CustomerModel::findOrFail($validated['customer_id']);
            
            // Build the new note with attribution
            $newNote = trim($validated['note']);
            $existingNote = trim($customer->notes ?? '');
            $timestamp = now()->format('Y-m-d H:i');
            $attribution = "[{$timestamp} - {$user->fullname}]";
            
            if (!empty($existingNote)) {
                // Append to existing notes
                $finalNote = $existingNote . "\n\n" . $attribution . " " . $newNote;
            } else {
                // New note
                $finalNote = $attribution . " " . $newNote;
            }
            
            $customer->notes = $finalNote;
            $customer->save();
            
            \Log::info('Customer note added (Store Mode)', [
                'customer_id' => $customer->id,
                'customer_name' => $customer->first_name . ' ' . $customer->last_name,
                'added_by' => $user->id,
                'user_name' => $user->fullname
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Note saved to customer profile',
                'notes' => $customer->notes
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to add customer note', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to save customer note: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE MODE: Update payment method for an order
     */
    public function updatePaymentMethod(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - reuse change_order_status permission
            if (!$user->hasMobilePermission('change_order_status')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to change payment method'
                ], 403);
            }
            
            $validated = $request->validate([
                'order_id' => 'required|exists:t_crm_prod_order,id',
                'payment_type' => 'required|in:cash,online'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            
            // Don't allow editing if already delivered
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change payment method for delivered orders'
                ], 422);
            }
            
            $oldPaymentMethod = $order->payment_method;
            
            // Map payment type to method (consistent with Rider Mode)
            // Using 'cash_on_delivery' and 'online_payment' for consistency across the system
            $newPaymentMethod = $validated['payment_type'] === 'cash' ? 'cash_on_delivery' : 'online_payment';
            
            $order->payment_method = $newPaymentMethod;
            $order->save();
            
            \Log::info('Payment method updated (Store Mode)', [
                'order_id' => $order->id,
                'old_payment_method' => $oldPaymentMethod,
                'new_payment_method' => $newPaymentMethod,
                'updated_by' => $user->id
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment method updated successfully',
                'payment_method' => $newPaymentMethod,
                'payment_type' => $validated['payment_type']
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to update payment method', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment method: ' . $e->getMessage()
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

            // FIX: Use exclusive/priority-based JOIN to prevent duplicate rows
            // When SKU exists and matches, don't also match via product_id/variant_id
            // This prevents cross-matches where li.product_id (WooCommerce ID) accidentally 
            // matches pv.shopify_variant_id of a different product
            $query = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
                    // EXCLUSIVE matching: SKU match OR (fallbacks only when no SKU)
                    $join->where(function ($q) {
                        // PRIORITY 1: SKU match (most reliable) - when SKU exists
                        $q->where(function($skuMatch) {
                            $skuMatch->whereNotNull('li.sku')
                                     ->where('li.sku', '!=', '')
                                     ->whereColumn('li.sku', 'pv.sku');
                        })
                        // PRIORITY 2-5: Fallbacks ONLY when no valid SKU exists
                        ->orWhere(function($fallback) {
                            $fallback->where(function($noSku) {
                                $noSku->whereNull('li.sku')
                                      ->orWhere('li.sku', '');
                            })
                            ->where(function($idMatch) {
                                $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.variant_id', 'pv.id')
                                        ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.product_id', 'pv.id');
                            });
                        });
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function ($join) {
                    // Product match: via variant (covers SKU match) or name fallback for legacy
                    $join->where(function ($q) {
                        // Primary: Match via variant's product_id (safe, no cross-match risk)
                        $q->whereColumn('pv.product_id', 'p.id')
                          // PRIORITY 7: Name fallback for legacy orders without SKU/IDs
                          ->orWhere(function($nameFallback) {
                              $nameFallback->whereNull('li.sku')
                                           ->where(function($noIds) {
                                               $noIds->whereNull('li.variant_id')
                                                     ->orWhere('li.variant_id', '');
                                           })
                                           ->where(function($noProdId) {
                                               $noProdId->whereNull('li.product_id')
                                                        ->orWhere('li.product_id', '');
                                           })
                                           ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                          });
                    });
                })
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function ($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where('o.order_date', '>=', Carbon::now()->subDays(20))
                ->where(function($q) {
                    $q->whereNull('li.preparation_status')
                      ->orWhere('li.preparation_status', '!=', 'preparing');
                })
                ->where(function($q) {
                    $q->whereNull('p.attribute_1')
                      ->orWhereRaw("LOWER(p.attribute_1) != 'qurbani'");
                });

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

            $addMetrics = function (&$node, $row) {
                $qty = (float) ($row->line_item_quantity ?? 0);
                $isLean = (int) ($row->is_lean ?? 0) === 1;
                $orderStatus = strtolower((string) ($row->order_status ?? ''));
                $lineStatus = strtolower((string) ($row->line_item_status ?? ''));

                // ✅ Defensive initialization to avoid \"Undefined array key\" issues
                if (!isset($node['quantity'])) {
                    $node['quantity'] = 0.0;
                }
                if (!isset($node['lean_quantity'])) {
                    $node['lean_quantity'] = 0.0;
                }
                if (!isset($node['non_lean_quantity'])) {
                    $node['non_lean_quantity'] = 0.0;
                }
                if (!isset($node['processing_quantity'])) {
                    $node['processing_quantity'] = 0.0;
                }
                if (!isset($node['prepared_quantity'])) {
                    $node['prepared_quantity'] = 0.0;
                }

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
                                '_li_product_ids' => [],
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

                        if ($row->line_item_product_id) {
                            $currentMap[$orderKey]['_product_ids'][(int) $row->line_item_product_id] = true;
                            $currentMap[$orderKey]['_li_product_ids'][(int) $row->line_item_product_id] = true;
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
                $node['order_count'] = count($node['_order_ids']);
                $node['product_count'] = count(array_filter(array_keys($node['_product_ids'])));

                unset($node['_order_ids'], $node['_product_ids'], $node['_children_map']);

                if (isset($node['product_ids']) && is_array($node['product_ids'])) {
                    $productIds = array_keys($node['product_ids']);
                    sort($productIds);
                    $node['product_ids'] = $productIds;
                }

                if (isset($node['_li_product_ids'])) {
                    if (!empty($node['_li_product_ids'])) {
                        $node['product_ids'] = array_values(array_map('intval', array_keys($node['_li_product_ids'])));
                    }
                    unset($node['_li_product_ids']);
                }

                if (!empty($node['children'])) {
                    // Finalize children first (to calculate their quantities)
                    foreach ($node['children'] as &$child) {
                        $finalizeNode($child);
                    }
                    unset($child); // Break the reference to avoid issues
                }
            };

            foreach ($tree as &$rootNode) {
                $finalizeNode($rootNode);
            }
            unset($rootNode);

            usort($tree, function ($a, $b) {
                return $b['quantity'] <=> $a['quantity'];
            });

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

            // ✅ CRITICAL: Break all PHP references before JSON encoding
            // The tree uses references (&) for performance during building,
            // but these create circular references that break json_encode()
            // Deep clone via unserialize(serialize()) breaks all references
            $treeClean = unserialize(serialize($tree));

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
                'tree' => $treeClean,
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
            
            // Handle filters - can be JSON string or already an array (from mobile)
            $filtersInput = $request->get('filters', '{}');
            if (is_array($filtersInput)) {
                $filters = $filtersInput;
            } else {
                $filters = json_decode($filtersInput, true) ?: [];
            }
            
            $statusFilter = $request->get('status_filter'); // Optional status filter
            
            // Allow mobile to override hierarchy (same as tree endpoint)
            $overrideHierarchy = $request->get('hierarchy_override');
            if ($overrideHierarchy) {
                // Handle both JSON string and array
                $hierarchy = is_array($overrideHierarchy) ? $overrideHierarchy : json_decode($overrideHierarchy, true);
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
            
            // Build base query - same as webapp with SKU-primary matching
            $query = DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function($join) {
                    $join->where(function($q) {
                        // PRIORITY 1: SKU match (most reliable)
                        $q->where(function($skuMatch) {
                            $skuMatch->whereNotNull('li.sku')
                                     ->where('li.sku', '!=', '')
                                     ->whereColumn('li.sku', 'pv.sku');
                        })
                        // PRIORITY 2-5: Fallbacks for manual orders without SKU
                          ->orWhereColumn('li.variant_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.variant_id', 'pv.id')
                          ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                          ->orWhereColumn('li.product_id', 'pv.id');
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function($join) {
                    $join->where(function($q) {
                        $q->whereColumn('pv.product_id', 'p.id')
                          ->orWhereColumn('li.product_id', 'p.id')
                          // PRIORITY 7: Name fallback for legacy orders without SKU/IDs
                          ->orWhere(function($nameFallback) {
                              $nameFallback->whereNull('li.sku')
                                           ->where(function($noIds) {
                                               $noIds->whereNull('li.variant_id')
                                                     ->orWhere('li.variant_id', '');
                                           })
                                           ->where(function($noProdId) {
                                               $noProdId->whereNull('li.product_id')
                                                        ->orWhere('li.product_id', '');
                                           })
                                           ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                          });
                    });
                })
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->whereNotIn('o.order_status', $excludedStatuses)
                ->where(function($q) {
                    $q->whereNull('p.attribute_1')
                      ->orWhereRaw("LOWER(p.attribute_1) != 'qurbani'");
                });
            
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
            
            // Update line items + handle inventory deduction/restoration
            $updated = 0;
            $inventoryDeducted = 0;
            $inventoryRestored = 0;
            foreach ($lineItemIds as $lineItemId) {
                $lineItem = $order->lineItems->where('id', $lineItemId)->first();
                if ($lineItem) {
                    $oldStatus = $lineItem->preparation_status;
                    $lineItem->preparation_status = $preparationStatus;
                    $lineItem->updated_by = $user->id;
                    $lineItem->save();
                    $updated++;

                    // ⭐ INVENTORY: Deduct when marking as prepared
                    if ($preparationStatus === 'preparing' && $oldStatus !== 'preparing') {
                        if ($lineItem->deductInventory()) {
                            $inventoryDeducted++;
                        }
                    }
                    // ⭐ INVENTORY: Restore when un-marking as prepared
                    elseif ($preparationStatus === null && $oldStatus === 'preparing') {
                        if ($lineItem->restoreInventory()) {
                            $inventoryRestored++;
                        }
                    }
                }
            }
            
            // Get updated counts (refresh from DB)
            $order->load('lineItems');
            $totalItems = $order->lineItems->count();
            $preparingCount = $order->lineItems->where('preparation_status', 'preparing')->count();
            
            return response()->json([
                'success' => true,
                'message' => "Updated {$updated} line item(s)",
                'updated_count' => $updated,
                'preparing_count' => $preparingCount,
                'total_items' => $totalItems,
                'inventory_deducted' => $inventoryDeducted,
                'inventory_restored' => $inventoryRestored,
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
            $paymentSourceFilter = $request->input('payment_source'); // New filter: account_id or 'all'
            $businessUnitId = $request->input('business_unit_id'); // ⭐ Filter by business unit (for Khaas mode)
            
            // ⭐ Check if user can see ALL payment sources (EXP_FUND, NF_CASH, ONLINE)
            // Without this permission, user only sees EXP_FUND expenses
            // In Khaas mode: also grant this if user has approve_khaas_transfer (admin)
            $hasAllPaymentSourcesPermission = $user->hasMobilePermission('expense_all_payment_sources')
                || ($businessUnitId && $user->hasMobilePermission('approve_khaas_transfer'));
            
            \Log::debug('Expense filter params received', [
                'month' => $month,
                'category' => $category,
                'settlement_status' => $settlementStatus,
                'payment_source' => $paymentSourceFilter,
                'has_all_sources_permission' => $hasAllPaymentSourcesPermission,
                'all_params' => $request->all()
            ]);
            
            // ⭐ Get EXP_FUND account ID for filtering
            $expenseFundAccount = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            $expenseFundAccountId = $expenseFundAccount ? $expenseFundAccount->id : null;
            
            // Build base query for expenses and salary advances
            // ⭐ In Khaas mode (BU filter): show 'khaas_expense' (and 'expense' for backwards compat)
            // Salary advances are employee-level, not business-unit-level
            $expenseCategoryCodes = $businessUnitId ? ['expense', 'khaas_expense'] : ['expense', 'salary_advance'];
            
            $expensesQuery = \App\Models\Request\RequestModel::whereHas('category', function($q) use ($expenseCategoryCodes) {
                    $q->whereIn('category_code', $expenseCategoryCodes);
                })
                ->whereNotNull('ledger_transaction_id')
                ->where('status', \App\Models\Request\RequestModel::STATUS_APPROVED)
                ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy', 'settlementDestinationAccount']);
            
            // ⭐ Apply business unit filter (for Khaas mode)
            if ($businessUnitId) {
                $expensesQuery->where('business_unit_id', $businessUnitId);
            }
            
            // ⭐ Apply payment source filter — all users can VIEW all payment source expenses
            // Permission only controls which sources they can CREATE from (handled in getPaymentSources)
            if ($paymentSourceFilter && $paymentSourceFilter !== 'all') {
                $expensesQuery->where('payment_source_account_id', $paymentSourceFilter);
            }
            
            // Apply date filter only if month is provided AND is valid format
            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $dateFrom = $month . '-01';
                $dateTo = date('Y-m-t', strtotime($dateFrom)); // Last day of month
                
                \Log::debug('Expense date filter applied', [
                    'month' => $month,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo
                ]);
                
                // ⭐ Filter by expense_date (falls back to created_at for old records)
                $expensesQuery->whereRaw('DATE(COALESCE(expense_date, created_at)) >= ?', [$dateFrom])
                              ->whereRaw('DATE(COALESCE(expense_date, created_at)) <= ?', [$dateTo]);
            } else if ($month) {
                \Log::warning('Invalid month format received', ['month' => $month]);
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
            
            // Debug: Log the dates of returned expenses
            \Log::debug('Expenses returned', [
                'count' => $allExpenses->count(),
                'month_filter' => $month,
                'date_range' => $allExpenses->count() > 0 ? [
                    'first' => $allExpenses->first()->created_at->format('Y-m-d'),
                    'last' => $allExpenses->last()->created_at->format('Y-m-d'),
                ] : 'no records',
                'all_dates' => $allExpenses->pluck('created_at')->map(fn($d) => $d->format('Y-m-d'))->toArray()
            ]);
            
            // Get salary slips
            $salarySlipsQuery = SalarySlipModel::with(['employee'])
                ->whereIn('slip_status', ['approved', 'paid'])
                ->whereNotNull('ledger_transaction_id');
            
            // Apply date filter to salary slips if month is provided
            if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
                $dateFrom = $month . '-01';
                $dateTo = date('Y-m-t', strtotime($dateFrom));
                $salarySlipsQuery->whereRaw('DATE(created_at) >= ?', [$dateFrom])
                                 ->whereRaw('DATE(created_at) <= ?', [$dateTo]);
            }
            
            // ⭐ FIX: Only include salary slips if:
            // 1. No category filter OR category is 'salary'
            // 2. AND no payment source filter OR filter is EXP_FUND
            // 3. AND no business unit filter (salary slips don't have BU concept)
            $includeSalarySlips = (!$category || strtolower($category) === 'salary');
            
            // ⭐ If user filters by a specific non-EXP_FUND source, exclude salary slips
            if ($paymentSourceFilter && $paymentSourceFilter !== 'all' && $paymentSourceFilter != $expenseFundAccountId) {
                $includeSalarySlips = false;
            }
            
            // ⭐ If filtering by business unit (Khaas mode), exclude salary slips
            // Salary slips don't belong to a business unit
            if ($businessUnitId) {
                $includeSalarySlips = false;
            }
            
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
                    'date' => ($expense->expense_date ?? $expense->created_at)->format('Y-m-d'),
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
            
            // ⭐ Calculate expenses breakdown by payment source
            $expensesBySource = [];
            
            // Group expenses by source account
            foreach ($allExpenses as $expense) {
                $sourceId = $expense->payment_source_account_id ?? 'exp_fund'; // Default to exp_fund for old records
                $sourceName = $expense->paymentSourceAccount ? $expense->paymentSourceAccount->account_name : 'Expense Fund';
                
                if (!isset($expensesBySource[$sourceId])) {
                    $expensesBySource[$sourceId] = [
                        'id' => $sourceId,
                        'name' => $sourceName,
                        'amount' => 0,
                        'count' => 0
                    ];
                }
                $expensesBySource[$sourceId]['amount'] += $expense->amount;
                $expensesBySource[$sourceId]['count']++;
            }
            
            // Add salary slips (always from Expense Fund / EXP_FUND)
            if ($totalSalaryExpenses > 0) {
                $expFundId = $expenseFundAccount ? $expenseFundAccount->id : 'exp_fund';
                $expFundName = $expenseFundAccount ? $expenseFundAccount->account_name : 'Expense Fund';
                
                if (!isset($expensesBySource[$expFundId])) {
                    $expensesBySource[$expFundId] = [
                        'id' => $expFundId,
                        'name' => $expFundName,
                        'amount' => 0,
                        'count' => 0
                    ];
                }
                $expensesBySource[$expFundId]['amount'] += $totalSalaryExpenses;
                $expensesBySource[$expFundId]['count'] += $salarySlips->count();
            }
            
            // Sort by amount descending and convert to array
            $expensesBySourceArray = array_values($expensesBySource);
            usort($expensesBySourceArray, fn($a, $b) => $b['amount'] <=> $a['amount']);
            
            // Get expense fund balance
            // ⭐ For non-NF BUs (Khaas etc): use the BU's configured default expense account
            // For NF main (BU 1) or no BU: use standard EXP_FUND
            if ($businessUnitId && (int) $businessUnitId !== 1) {
                $expenseFund = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
            } else {
                $expenseFund = $expenseFundAccount;
            }
            
            // Get pending approvals (real-time, not filtered by month)
            // Include approvals relationship to check L1/L2 status
            // ⭐ In Khaas mode: show 'khaas_expense' (and 'expense' for backwards compat)
            $pendingCategoryCodes = $businessUnitId ? ['expense', 'khaas_expense'] : ['expense', 'salary_advance'];
            
            $pendingApprovalsQuery = \App\Models\Request\RequestModel::whereHas('category', function($q) use ($pendingCategoryCodes) {
                    $q->whereIn('category_code', $pendingCategoryCodes);
                })
                ->where('status', \App\Models\Request\RequestModel::STATUS_PENDING)
                ->with(['requester', 'paymentSourceAccount', 'category', 'approvals.approver'])
                ->orderBy('created_at', 'asc');
            
            // ⭐ Filter pending approvals by business unit (for Khaas mode)
            if ($businessUnitId) {
                $pendingApprovalsQuery->where('business_unit_id', $businessUnitId);
            }
            
            $pendingApprovals = $pendingApprovalsQuery->get();
            
            // Transform pending approvals with level information
            $allPendingApprovals = $pendingApprovals->map(function($request) {
                // ⭐ Check approvals relationship for L1 status
                $l1Approval = $request->approvals->where('approval_level', 1)->where('status', 'approved')->first();
                $l2Approval = $request->approvals->where('approval_level', 2)->where('status', 'approved')->first();
                
                // Determine which level is pending
                $pendingLevel = 1; // Default to L1
                if ($l1Approval) {
                    $pendingLevel = 2; // L1 done, L2 pending
                }
                
                return [
                    'id' => $request->id,
                    'request_number' => $request->request_number,
                    'date' => $request->created_at->format('Y-m-d'),
                    'employee' => $request->requester ? $request->requester->fullname : 'Unknown',
                    'category' => $request->expense_category ?? ($request->category ? $request->category->category_name : 'Uncategorized'),
                    'amount' => $request->amount,
                    'payment_source' => $request->paymentSourceAccount ? $request->paymentSourceAccount->account_name : 'Unknown',
                    'status' => $request->status,
                    'pending_level' => $pendingLevel, // ⭐ Which level needs approval
                    'l1_approved' => $l1Approval ? true : false,
                    'l1_approved_by_name' => $l1Approval && $l1Approval->approver ? $l1Approval->approver->fullname : null,
                ];
            });
            
            // ⭐ Separate L1 and L2 pending approvals
            $pendingL1 = $allPendingApprovals->where('pending_level', 1)->values();
            $pendingL2 = $allPendingApprovals->where('pending_level', 2)->values();
            
            // For backwards compatibility, keep the combined list
            $pendingApprovalsForDisplay = $allPendingApprovals;
            
            // Calculate expense categories with user breakdown (like web version)
            $expensesByCategory = [];
            $expensesByCategoryUser = []; // Track user-wise breakdown
            
            foreach ($allExpenses as $expense) {
                $cat = $expense->expense_category;
                if (empty($cat) && $expense->category && $expense->category->category_code === 'salary_advance') {
                    $cat = 'Salary Advance';
                } elseif (empty($cat)) {
                    $cat = 'Uncategorized';
                }
                
                // Category total
                if (!isset($expensesByCategory[$cat])) {
                    $expensesByCategory[$cat] = 0;
                }
                $expensesByCategory[$cat] += $expense->amount;
                
                // User breakdown within category
                $userName = $expense->requester ? $expense->requester->fullname : 'Unknown';
                if (!isset($expensesByCategoryUser[$cat])) {
                    $expensesByCategoryUser[$cat] = [];
                }
                if (!isset($expensesByCategoryUser[$cat][$userName])) {
                    $expensesByCategoryUser[$cat][$userName] = 0;
                }
                $expensesByCategoryUser[$cat][$userName] += $expense->amount;
            }
            
            // Add salary slips to category totals with user breakdown
            if ($totalSalaryExpenses > 0) {
                if (!isset($expensesByCategory['Salary'])) {
                    $expensesByCategory['Salary'] = 0;
                }
                $expensesByCategory['Salary'] += $totalSalaryExpenses;
                
                // Track salary by employee
                if (!isset($expensesByCategoryUser['Salary'])) {
                    $expensesByCategoryUser['Salary'] = [];
                }
                foreach ($salarySlips as $slip) {
                    $empName = $slip->employee ? $slip->employee->fullname : 'Unknown';
                    if (!isset($expensesByCategoryUser['Salary'][$empName])) {
                        $expensesByCategoryUser['Salary'][$empName] = 0;
                    }
                    $expensesByCategoryUser['Salary'][$empName] += $slip->net_salary;
                }
            }
            
            // Sort categories by amount descending
            arsort($expensesByCategory);
            
            // Build top categories with user breakdown (return all like web, up to 15)
            $topCategories = [];
            $othersTotal = 0;
            $othersUsers = [];
            $count = 0;
            
            foreach ($expensesByCategory as $cat => $amount) {
                if ($count < 15 && $cat !== 'Uncategorized') {
                    // Sort users within category by amount descending
                    $usersInCategory = $expensesByCategoryUser[$cat] ?? [];
                    arsort($usersInCategory);
                    
                    $topCategories[$cat] = [
                        'total' => $amount,
                        'users' => $usersInCategory
                    ];
                    $count++;
                } else {
                    $othersTotal += $amount;
                    // Merge users from other categories
                    foreach (($expensesByCategoryUser[$cat] ?? []) as $userName => $userAmount) {
                        if (!isset($othersUsers[$userName])) {
                            $othersUsers[$userName] = 0;
                        }
                        $othersUsers[$userName] += $userAmount;
                    }
                }
            }
            
            if ($othersTotal > 0) {
                arsort($othersUsers);
                $topCategories['Other Expenses'] = [
                    'total' => $othersTotal,
                    'users' => $othersUsers
                ];
            }
            
            // Get all unique categories for filter
            // ⭐ Use same category codes as for the main expenses query (includes khaas_expense for Khaas mode)
            $categoriesFilterQuery = \App\Models\Request\RequestModel::whereHas('category', function($q) use ($expenseCategoryCodes) {
                    $q->whereIn('category_code', $expenseCategoryCodes);
                })
                ->whereNotNull('ledger_transaction_id')
                ->where('status', \App\Models\Request\RequestModel::STATUS_APPROVED)
                ->whereNotNull('expense_category')
                ->where('expense_category', '!=', '');
            
            // ⭐ Filter categories by business unit (for Khaas mode)
            if ($businessUnitId) {
                $categoriesFilterQuery->where('business_unit_id', $businessUnitId);
            }
            
            $categoriesFromExpenses = $categoriesFilterQuery->distinct()->pluck('expense_category');
            
            // ⭐ In Khaas mode: don't add Salary/Salary Advance to categories filter
            if ($businessUnitId) {
                $categories = $categoriesFromExpenses->unique()->sort()->values();
            } else {
                $categories = $categoriesFromExpenses
                    ->merge(['Salary', 'Salary Advance'])
                    ->unique()
                    ->sort()
                    ->values();
            }
            
            // ⭐ Check user's approval rights
            $hasL1Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasL2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            // ⭐ Get available payment sources for filter dropdown — all users can see all sources for filtering
            $availablePaymentSources = [];
            
            if ($businessUnitId) {
                // BU-specific mode: show ALL accounts for this BU (excluding employee/vendor)
                $buAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                    ->where('business_unit_id', $businessUnitId)
                    ->where('is_private', 0)
                    ->whereNotIn('account_category', [
                        \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH,
                        \App\Models\FIN\AccountModel::CATEGORY_VENDOR_PAYABLE,
                    ])
                    ->get();
                foreach ($buAccounts as $account) {
                    $availablePaymentSources[] = [
                        'id' => $account->id,
                        'code' => $account->account_code,
                        'name' => $account->account_name,
                        'balance' => $account->current_balance
                    ];
                }
            } else {
                // NF mode: always show all company payment sources for filtering
                $nfCashAccount = \App\Models\FIN\ConfigModel::getNFCashAccount();
                $onlineAccount = \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                
                // Check Taimur role for private account visibility
                $isTaimurRole = $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
                
                if ($expenseFund) {
                    $availablePaymentSources[] = [
                        'id' => $expenseFund->id,
                        'code' => $expenseFund->account_code,
                        'name' => $expenseFund->account_name,
                        'balance' => $expenseFund->current_balance
                    ];
                }
                if ($nfCashAccount && !$nfCashAccount->is_private) {
                    $availablePaymentSources[] = [
                        'id' => $nfCashAccount->id,
                        'code' => $nfCashAccount->account_code,
                        'name' => $nfCashAccount->account_name,
                        'balance' => $nfCashAccount->current_balance
                    ];
                }
                if ($onlineAccount && !$onlineAccount->is_private) {
                    $availablePaymentSources[] = [
                        'id' => $onlineAccount->id,
                        'code' => $onlineAccount->account_code,
                        'name' => $onlineAccount->account_name,
                        'balance' => $onlineAccount->current_balance
                    ];
                }
                
                // Add NF Food accounts
                $nfFoodAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                    ->where('account_code', 'LIKE', 'NF_FOOD%')
                    ->where('is_private', 0)
                    ->get();
                foreach ($nfFoodAccounts as $nfFoodAccount) {
                    $availablePaymentSources[] = [
                        'id' => $nfFoodAccount->id,
                        'code' => $nfFoodAccount->account_code,
                        'name' => $nfFoodAccount->account_name,
                        'balance' => $nfFoodAccount->current_balance
                    ];
                }
                
                // Include private accounts only for Taimur
                if ($isTaimurRole) {
                    $privateAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                        ->where('is_private', 1)
                        ->whereIn('account_category', [
                            \App\Models\FIN\AccountModel::CATEGORY_CASH,
                            \App\Models\FIN\AccountModel::CATEGORY_BANK,
                        ])
                        ->get();
                    foreach ($privateAccounts as $pa) {
                        $availablePaymentSources[] = [
                            'id' => $pa->id,
                            'code' => $pa->account_code,
                            'name' => $pa->account_name,
                            'balance' => $pa->current_balance
                        ];
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'expenses' => $allExpensesForDisplay,
                    'pending_approvals' => $pendingApprovalsForDisplay,
                    // ⭐ Separated L1/L2 pending approvals for tabbed view
                    'pending_l1' => $pendingL1,
                    'pending_l2' => $pendingL2,
                    'kpis' => [
                        'total_expenses' => $totalExpenses,
                        'needs_settlement' => $needsSettlement,
                        'settled' => $settled,
                        'fund_balance' => $expenseFund ? $expenseFund->current_balance : 0,
                        'pending_approvals' => $pendingApprovals->sum('amount'),
                        'pending_approvals_count' => $pendingApprovals->count(),
                        // ⭐ Separate L1/L2 counts and amounts
                        'pending_l1_count' => $pendingL1->count(),
                        'pending_l1_amount' => $pendingL1->sum('amount'),
                        'pending_l2_count' => $pendingL2->count(),
                        'pending_l2_amount' => $pendingL2->sum('amount'),
                        'top_categories' => $topCategories,
                        // ⭐ Expenses breakdown by source
                        'expenses_by_source' => $expensesBySourceArray
                    ],
                    // ⭐ User's approval rights
                    'user_approval_rights' => [
                        'has_l1' => $hasL1Rights,
                        'has_l2' => $hasL2Rights,
                    ],
                    // ⭐ Payment source permission and available sources
                    'has_all_payment_sources_permission' => $hasAllPaymentSourcesPermission,
                    'available_payment_sources' => $availablePaymentSources,
                    'categories' => $categories,
                    'current_month' => $month ?: now()->format('Y-m'),
                    // ⭐ BU default expense account info (for admin to configure)
                    'default_expense_account_id' => $expenseFund ? $expenseFund->id : null,
                    'default_expense_account_name' => $expenseFund ? $expenseFund->account_name : null,
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
     * Get fund transfers into the expense fund account
     * Shows transfers from NF Cash or Online into EXP_FUND
     */
    public function getFundTransfers(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view fund transfers'
                ], 403);
            }
            
            // Get month parameter (YYYY-MM format)
            $month = $request->input('month', now()->format('Y-m'));
            $businessUnitId = $request->input('business_unit_id');
            
            // ⭐ For non-NF BUs (Khaas etc): use the BU's configured default account
            // For NF main (BU 1) or no BU: use standard EXP_FUND
            if ($businessUnitId && (int) $businessUnitId !== 1) {
                $expenseFund = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
            } else {
                $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                    ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            }
            
            if (!$expenseFund) {
                return response()->json([
                    'success' => false,
                    'message' => 'Expense fund account not found'
                ], 404);
            }
            
            // Calculate date range for the month
            $startDate = $month . '-01';
            $endDate = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();
            
            // Get transfers INTO the expense fund
            $transfers = \App\Models\FIN\LedgerModel::with(['fromAccount', 'toAccount', 'createdBy'])
                ->where('to_account_id', $expenseFund->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_TRANSFER)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get();
            
            // Format transfers for response
            $formattedTransfers = $transfers->map(function($transfer) {
                return [
                    'id' => $transfer->id,
                    'transaction_date' => $transfer->transaction_date->format('Y-m-d'),
                    'amount' => (float) $transfer->amount,
                    'from_account' => $transfer->fromAccount ? $transfer->fromAccount->account_name : 'Unknown',
                    'from_account_code' => $transfer->fromAccount ? $transfer->fromAccount->account_code : null,
                    'description' => $transfer->description,
                    'mode' => $transfer->mode,
                    'created_by' => $transfer->createdBy ? $transfer->createdBy->fullname : 'System',
                    'created_at' => $transfer->created_at->format('Y-m-d H:i'),
                ];
            });
            
            // Calculate totals
            $totalAmount = $transfers->sum('amount');
            
            return response()->json([
                'success' => true,
                'transfers' => $formattedTransfers,
                'summary' => [
                    'total_transfers' => $transfers->count(),
                    'total_amount' => (float) $totalAmount,
                    'fund_balance' => (float) $expenseFund->current_balance,
                ],
                'month' => $month,
                'month_display' => \Carbon\Carbon::parse($startDate)->format('F Y'),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get fund transfers', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load fund transfers: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available payment sources for expense creation
     * Returns sources based on user's Business Unit access and expense_all_payment_sources permission
     * - Without permission: Only EXP_FUND
     * - With permission: All company accounts (cash, bank) filtered by user's BU access
     */
    public function getPaymentSources(Request $request)
    {
        try {
            $user = Auth::user();
            $businessUnitId = $request->input('business_unit_id');
            
            // Check permission to view expenses
            if (!$user->hasMobilePermission('view_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view expenses'
                ], 403);
            }
            
            // Check if user can see ALL payment sources
            // In Khaas mode (non-NF BU): also grant this if user has approve_khaas_transfer (admin)
            $isNonNfBU = $businessUnitId && (int) $businessUnitId !== 1;
            $hasAllPaymentSourcesPermission = $user->hasMobilePermission('expense_all_payment_sources')
                || ($isNonNfBU && $user->hasMobilePermission('approve_khaas_transfer'));
            
            $paymentSources = [];
            
            // Get EXP_FUND account
            $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            
            // Company account categories that can be payment sources
            $companyCategories = [
                \App\Models\FIN\AccountModel::CATEGORY_CASH,
                \App\Models\FIN\AccountModel::CATEGORY_BANK,
            ];
            
            // ⭐ Determine if this is a non-NF business unit (Khaas etc.)
            // BU 1 = Nizami Farms main → should use standard EXP_FUND logic
            // Other BUs (Khaas etc.) → use BU-specific account logic
            $isNonNfBusinessUnit = $businessUnitId && (int) $businessUnitId !== 1;
            
            if ($isNonNfBusinessUnit) {
                // ⭐ BU-specific mode (Khaas etc — NOT NF main)
                // Admin/Taimur (expense_all_payment_sources): show ALL BU accounts
                // Regular user: show only the configured default account (e.g., NF Food)
                
                // Get the configured default account for this BU
                $defaultBuAccount = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
                $defaultAccountId = $defaultBuAccount ? $defaultBuAccount->id : null;
                
                if ($hasAllPaymentSourcesPermission) {
                    // Admin: show all BU accounts (excluding private for non-Taimur)
                    $buAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                        ->where('business_unit_id', $businessUnitId)
                        ->visibleTo($user)
                        ->whereNotIn('account_category', [
                            \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH,
                            \App\Models\FIN\AccountModel::CATEGORY_VENDOR_PAYABLE,
                        ])
                        ->orderBy('account_name')
                        ->get();
                } else {
                    // Regular user: only the configured default account
                    $buAccounts = $defaultBuAccount ? collect([$defaultBuAccount]) : collect();
                }
                
                foreach ($buAccounts as $account) {
                    $paymentSources[] = [
                        'id' => $account->id,
                        'code' => $account->account_code,
                        'name' => $account->account_name,
                        'display_name' => $account->account_name,
                        'balance' => (float) $account->current_balance,
                        'business_unit_id' => $account->business_unit_id,
                        'is_default' => ($account->id === $defaultAccountId)
                    ];
                }
            } else {
                // ⭐ Standard NF mode (BU 1 or no BU specified)
                // Default: EXP_FUND — but for Taimur role, default to ONLINE
                $isTaimurRole = $user->roles()
                    ->whereRaw('LOWER(urole_name) = ?', ['taimur'])
                    ->exists();
                
                // Fetch ONLINE account for Taimur default
                $onlineAccount = null;
                if ($isTaimurRole) {
                    $onlineAccount = \App\Models\FIN\AccountModel::where('account_code', 'ONLINE')
                        ->where('is_active', 1)
                        ->first();
                }
                
                // Determine which account is the default
                $defaultAccountId = ($isTaimurRole && $onlineAccount) ? $onlineAccount->id : ($expenseFund ? $expenseFund->id : null);
                
                if ($expenseFund) {
                    $paymentSources[] = [
                        'id' => $expenseFund->id,
                        'code' => $expenseFund->account_code,
                        'name' => $expenseFund->account_name,
                        'display_name' => 'Exp Fund',
                        'balance' => (float) $expenseFund->current_balance,
                        'business_unit_id' => $expenseFund->business_unit_id,
                        'is_default' => ($expenseFund->id === $defaultAccountId)
                    ];
                }
                
                // Only add other sources if user has permission
                if ($hasAllPaymentSourcesPermission) {
                    $accessibleAccounts = \App\Models\FIN\AccountModel::getAccessibleCompanyAccounts();
                    
                    foreach ($accessibleAccounts as $account) {
                        // Skip EXP_FUND since we already added it above
                        if ($expenseFund && $account->id === $expenseFund->id) {
                            continue;
                        }
                        // Skip private accounts for non-Taimur
                        if ($account->is_private && !$isTaimurRole) {
                            continue;
                        }
                        
                        $paymentSources[] = [
                            'id' => $account->id,
                            'code' => $account->account_code,
                            'name' => $account->account_name,
                            'display_name' => $account->account_name,
                            'balance' => (float) $account->current_balance,
                            'business_unit_id' => $account->business_unit_id,
                            'is_default' => ($account->id === $defaultAccountId)
                        ];
                    }
                }
            }
            
            \Log::info('Payment sources returned', [
                'user_id' => Auth::id(),
                'count' => count($paymentSources),
                'has_all_permission' => $hasAllPaymentSourcesPermission,
                'business_unit_id' => $businessUnitId,
            ]);
            
            return response()->json([
                'success' => true,
                'payment_sources' => $paymentSources,
                'has_all_payment_sources_permission' => $hasAllPaymentSourcesPermission
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get payment sources', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load payment sources: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set the default expense account for a business unit.
     * Only accessible to users with expense_all_payment_sources permission.
     */
    public function setBuDefaultExpenseAccount(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user->hasMobilePermission('expense_all_payment_sources') && 
                !$user->hasMobilePermission('approve_khaas_transfer')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to change this setting'
                ], 403);
            }
            
            $request->validate([
                'business_unit_id' => 'required|integer',
                'account_id' => 'required|integer',
            ]);
            
            $businessUnitId = (int) $request->input('business_unit_id');
            $accountId = (int) $request->input('account_id');
            
            // Verify the account exists, is active, and belongs to this BU
            $account = \App\Models\FIN\AccountModel::where('id', $accountId)
                ->where('business_unit_id', $businessUnitId)
                ->where('is_active', 1)
                ->first();
            
            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found or does not belong to this business unit'
                ], 400);
            }
            
            \App\Models\FIN\ConfigModel::setBuDefaultExpenseAccount($businessUnitId, $accountId);
            
            return response()->json([
                'success' => true,
                'message' => "Default expense account set to: {$account->account_name}",
                'account' => [
                    'id' => $account->id,
                    'name' => $account->account_name,
                    'code' => $account->account_code,
                    'balance' => (float) $account->current_balance,
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to set BU default expense account', [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to save setting: ' . $e->getMessage()
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
            
            $expenseRequest = \App\Models\Request\RequestModel::with('approvals')->findOrFail($id);
            
            // ⭐ Determine which level is NEEDED for this request by checking approvals
            $l1Approval = $expenseRequest->approvals->where('approval_level', 1)->where('status', 'approved')->first();
            $pendingLevel = 1;
            if ($l1Approval) {
                $pendingLevel = 2; // L1 done, needs L2
            }
            
            // Check if user has the required approval level
            if (!\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, $pendingLevel)) {
                return response()->json([
                    'success' => false,
                    'message' => "This request requires Level {$pendingLevel} approval, which you don't have"
                ], 403);
            }
            
            // Use the existing approval controller logic
            $approvalController = new \App\Http\Controllers\Request\RequestApprovalController();
            $approvalRequest = new Request([
                'level' => $pendingLevel, // ⭐ Use the level the request needs
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
     * Reject a pending expense request
     */
    public function rejectExpense(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('approve_expenses')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to reject expenses'
                ], 403);
            }
            
            $expenseRequest = \App\Models\Request\RequestModel::with('approvals')->findOrFail($id);
            
            // Determine which level is pending by checking approvals
            $l1Approval = $expenseRequest->approvals->where('approval_level', 1)->where('status', 'approved')->first();
            $pendingLevel = 1;
            if ($l1Approval) {
                $pendingLevel = 2;
            }
            
            // Check if user has the required approval level
            if (!\App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, $pendingLevel)) {
                return response()->json([
                    'success' => false,
                    'message' => "This request requires Level {$pendingLevel} rights to reject, which you don't have"
                ], 403);
            }
            
            $notes = $request->input('notes', '');
            if (empty($notes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please provide a reason for rejection'
                ], 400);
            }
            
            // Use the existing approval controller logic
            $approvalController = new \App\Http\Controllers\Request\RequestApprovalController();
            $rejectRequest = new Request([
                'level' => $pendingLevel,
                'comments' => $notes,
            ]);
            
            $response = $approvalController->reject($rejectRequest, $id);
            $responseData = $response->getData(true);
            
            return response()->json($responseData, $response->status());
            
        } catch (\Exception $e) {
            \Log::error('Failed to reject expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject expense: ' . $e->getMessage()
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
     * Delete an approved expense (L2 only)
     * Reverses ledger entry and account balances
     */
    public function deleteExpense(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            \Log::info('Delete expense attempt', [
                'expense_id' => $id,
                'user_id' => $user->id,
                'user_name' => $user->fullname
            ]);
            
            // ⭐ Check if user has L2 approval rights (required for delete)
            $hasL2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
            \Log::info('L2 rights check', [
                'user_id' => $user->id,
                'has_l2_rights' => $hasL2Rights
            ]);
            
            if (!$hasL2Rights) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Level 2 approvers can delete expenses'
                ], 403);
            }
            
            $expenseRequest = \App\Models\Request\RequestModel::with(['paymentSourceAccount', 'category'])
                ->findOrFail($id);
            
            // Check if expense is approved
            if ($expenseRequest->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only approved expenses can be deleted'
                ], 400);
            }
            
            $notes = $request->input('notes', '');
            
            \DB::beginTransaction();
            
            // ⭐ If expense has ledger entry, reverse it
            if ($expenseRequest->ledger_transaction_id) {
                $ledger = \App\Models\FIN\LedgerModel::find($expenseRequest->ledger_transaction_id);
                
                if ($ledger && $ledger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    // Reverse account balances
                    $fromAccount = $ledger->fromAccount;
                    $toAccount = $ledger->toAccount;
                    
                    if ($fromAccount) {
                        // Add amount back to from_account (was deducted)
                        $fromAccount->current_balance += $ledger->amount;
                        $fromAccount->save();
                        
                        \Log::info("Reversed from_account balance", [
                            'account_id' => $fromAccount->id,
                            'account_name' => $fromAccount->account_name,
                            'amount_added' => $ledger->amount,
                            'new_balance' => $fromAccount->current_balance
                        ]);
                    }
                    
                    if ($toAccount) {
                        // Subtract amount from to_account (was added)
                        $toAccount->current_balance -= $ledger->amount;
                        $toAccount->save();
                        
                        \Log::info("Reversed to_account balance", [
                            'account_id' => $toAccount->id,
                            'account_name' => $toAccount->account_name,
                            'amount_subtracted' => $ledger->amount,
                            'new_balance' => $toAccount->current_balance
                        ]);
                    }
                    
                    // Mark ledger entry as reversed (using valid status)
                    $ledger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
                    $ledger->comments = ($ledger->comments ? $ledger->comments . "\n" : '') . 
                        "DELETED by {$user->fullname} on " . now()->format('Y-m-d H:i:s') . 
                        ($notes ? " - Reason: {$notes}" : '');
                    $ledger->save();
                }
            }
            
            // ⭐ If expense has settlement transaction, reverse it too
            if ($expenseRequest->settlement_transaction_id) {
                $settlementLedger = \App\Models\FIN\LedgerModel::find($expenseRequest->settlement_transaction_id);
                
                if ($settlementLedger && $settlementLedger->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    // Reverse settlement balances
                    $fromAccount = $settlementLedger->fromAccount;
                    $toAccount = $settlementLedger->toAccount;
                    
                    if ($fromAccount) {
                        $fromAccount->current_balance += $settlementLedger->amount;
                        $fromAccount->save();
                    }
                    
                    if ($toAccount) {
                        $toAccount->current_balance -= $settlementLedger->amount;
                        $toAccount->save();
                    }
                    
                    $settlementLedger->approval_status = \App\Models\FIN\LedgerModel::STATUS_REVERSED;
                    $settlementLedger->comments = ($settlementLedger->comments ? $settlementLedger->comments . "\n" : '') . 
                        "DELETED (settlement reversal) by {$user->fullname} on " . now()->format('Y-m-d H:i:s');
                    $settlementLedger->save();
                    
                    \Log::info("Reversed settlement transaction", [
                        'settlement_ledger_id' => $settlementLedger->id,
                        'amount' => $settlementLedger->amount
                    ]);
                }
            }
            
            // Mark request as deleted/cancelled
            $expenseRequest->status = 'cancelled';
            $expenseRequest->rejection_reason = "DELETED by {$user->fullname} on " . now()->format('Y-m-d H:i:s') . 
                ($notes ? " - Reason: {$notes}" : '');
            $expenseRequest->updated_by = $user->id;
            $expenseRequest->save();
            
            \DB::commit();
            
            \Log::info('Expense deleted successfully', [
                'expense_id' => $id,
                'request_number' => $expenseRequest->request_number,
                'amount' => $expenseRequest->amount,
                'deleted_by' => $user->id,
                'ledger_reversed' => $expenseRequest->ledger_transaction_id ? true : false,
                'settlement_reversed' => $expenseRequest->settlement_transaction_id ? true : false
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Expense #{$expenseRequest->request_number} deleted successfully. Ledger entries reversed."
            ]);
            
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to delete expense', [
                'expense_id' => $id,
                'user_id' => Auth::id(),
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense: ' . $e->getMessage()
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

    /**
     * NF LEDGER - Get all accounts with balances (for mobile)
     * Shows both company accounts and employee accounts
     */
    public function getNFLedgerAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_nf_ledger')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view NF Ledger'
                ], 403);
            }
            
            $businessUnitId = $request->input('business_unit_id');
            $isNonNfBU = $businessUnitId && (int) $businessUnitId !== 1;
            
            // For non-NF BU (Khaas), check if user is admin or limited
            $isKhaasAdmin = false;
            if ($isNonNfBU) {
                $isKhaasAdmin = $user->hasMobilePermission('expense_all_payment_sources')
                    || $user->hasMobilePermission('approve_khaas_transfer');
            }
            
            // Build query for active accounts — filter private accounts for non-Taimur
            $query = \App\Models\FIN\AccountModel::where('is_active', 1)
                ->visibleTo($user);
            
            if ($isNonNfBU) {
                // Khaas mode: only company accounts (cash/bank) for this BU
                $query->where('business_unit_id', (int) $businessUnitId);
                $query->whereIn('account_category', [
                    \App\Models\FIN\AccountModel::CATEGORY_CASH,
                    \App\Models\FIN\AccountModel::CATEGORY_BANK,
                ]);
                
                // Limited users: only the configured default account
                if (!$isKhaasAdmin) {
                    $defaultBuAccount = \App\Models\FIN\ConfigModel::getBuDefaultExpenseAccount((int) $businessUnitId);
                    if ($defaultBuAccount) {
                        $query->where('id', $defaultBuAccount->id);
                    }
                }
            } else {
                // Standard NF mode: show company + employee accounts
                $query->where(function($q) {
                    $q->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                      ->orWhereIn('account_category', [\App\Models\FIN\AccountModel::CATEGORY_CASH, \App\Models\FIN\AccountModel::CATEGORY_BANK]);
                });
            }
            
            // Load user relationship for employee accounts
            $query->with('user');
            
            // Search filter
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where('account_name', 'LIKE', "%{$search}%");
            }
            
            // Order: Company accounts first, then employee accounts (alphabetically)
            $accounts = $query
                ->orderByRaw("CASE 
                    WHEN account_category IN ('cash', 'bank') THEN 1 
                    WHEN account_category = 'employee_cash' THEN 2 
                    ELSE 3 
                END")
                ->orderBy('account_name', 'asc')
                ->get();
            
            // Calculate pending actions for each account
            foreach ($accounts as $account) {
                $pendingActions = 0;
                
                // For employee accounts: count pending expense requests
                if ($account->user_id) {
                    $pendingActions = \App\Models\Request\RequestModel::where('requester_user_id', $account->user_id)
                        ->where('status', 'pending')
                        ->whereHas('category', function($q) {
                            $q->where('category_code', 'expense');
                        })
                        ->sum('amount');
                }
                // For company accounts: count pending requests to be paid from this account
                else {
                    $query = \App\Models\Request\RequestModel::where('status', 'pending')
                        ->whereHas('category', function($q) {
                            $q->where('category_code', 'expense');
                        });
                    
                    // If this is the Expense Fund, include requests with NULL payment source
                    if ($account->account_code === 'EXP_FUND') {
                        $query->where(function($q) use ($account) {
                            $q->where('payment_source_account_id', $account->id)
                              ->orWhereNull('payment_source_account_id');
                        });
                    } else {
                        $query->where('payment_source_account_id', $account->id);
                    }
                    
                    $pendingActions = $query->sum('amount');
                }
                
                $account->pending_actions = $pendingActions ?? 0;
            }
            
            // Separate into company and employee accounts
            $companyAccounts = [];
            $employeeAccounts = [];
            
            foreach ($accounts as $account) {
                // Use effective balance: calculated for employee_cash, stored for others
                $effectiveBalance = $account->getEffectiveBalance();
                
                $accountData = [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_category' => $account->account_category,
                    'current_balance' => $effectiveBalance,
                    'pending_actions' => $account->pending_actions,
                    'user_id' => $account->user_id,
                    'user_name' => $account->user ? ($account->user->fullname ?? $account->user->name) : null,
                ];
                
                if ($account->account_category === \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH) {
                    $employeeAccounts[] = $accountData;
                } else {
                    $companyAccounts[] = $accountData;
                }
            }
            
            return response()->json([
                'success' => true,
                'company_accounts' => $companyAccounts,
                'employee_accounts' => $employeeAccounts,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get NF Ledger accounts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE ATTENDANCE - Get daily attendance for all employees
     * Reuses web app attendance logic
     */
    public function getStoreAttendanceDaily(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view store attendance'
                ], 403);
            }
            
            $selectedDate = $request->input('date', now()->toDateString());
            
            // Build subquery for leave requests
            $leaveSub = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', '=', 'leave')
                ->select(
                    'r.id',
                    'r.requester_user_id',
                    'r.status',
                    'r.leave_type',
                    'r.leave_start_date',
                    'r.leave_end_date'
                );
            
            // Get attendance data for all visible users
            $query = DB::table('t_sys_user as u')
                ->leftJoin('t_ops_attendance as a', function($join) use ($selectedDate) {
                    $join->on('u.id', '=', 'a.user_id')
                         ->whereDate('a.attendance_date', '=', $selectedDate);
                })
                ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->leftJoinSub($leaveSub, 'lr', function($join) use ($selectedDate) {
                    $join->on('lr.requester_user_id', '=', 'u.id')
                        ->whereIn('lr.status', ['approved', 'pending'])
                        ->whereRaw('? BETWEEN lr.leave_start_date AND lr.leave_end_date', [$selectedDate]);
                })
                ->select(
                    'u.id as user_id',
                    'u.fullname',
                    'u.is_active',
                    'r.urole_name as role_name',
                    'a.id as attendance_id',
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    'a.notes',
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
                    'a.checkin_latitude',
                    'a.checkin_longitude',
                    'a.checkin_distance_from_base',
                    'a.is_remote_checkin',
                    'a.checkout_latitude',
                    'a.checkout_longitude',
                    // ⭐ Road distance columns (auto-calculated on checkout)
                    'a.road_distance_km',
                    'a.road_distance_source',
                    'a.gps_straight_distance_km',
                    'a.gps_readings_used',
                    DB::raw('COALESCE(rp.shift_start, "09:00") as legacy_shift_start'),
                    DB::raw('COALESCE(rp.shift_end, "17:00") as legacy_shift_end'),
                    'lr.id as leave_request_id',
                    'lr.status as leave_status',
                    'lr.leave_type as leave_type_from_req',
                    DB::raw('COALESCE(av.is_visible, 1) as is_attendance_visible')
                )
                ->where(function($q) {
                    $q->whereNull('av.is_visible')
                      ->orWhere('av.is_visible', 1);
                })
                ->where('u.is_active', 1)
                ->orderBy('u.fullname')
                ->limit(100)
                ->get();
            
            // Resolve shifts using ShiftResolutionService
            $shiftService = new \App\Services\ShiftResolutionService();
            $formattedData = [];
            
            foreach ($query as $row) {
                $shiftData = $shiftService->getUserShift($row->user_id);
                
                // Calculate hours and late/OT status
                $hours = 0;
                $isLate = false;
                $lateMinutes = 0;
                $isOvertime = false;
                $overtimeMinutes = 0;
                
                if ($row->login_time && $row->logout_time) {
                    $login = \Carbon\Carbon::parse($row->login_time);
                    $logout = \Carbon\Carbon::parse($row->logout_time);
                    // ⭐ Calculate hours worked (round to 2 decimal places)
                    $hours = round(abs($logout->diffInMinutes($login)) / 60, 2);
                    
                    // Check if late
                    $shiftStart = \Carbon\Carbon::parse($shiftData['shift_start']);
                    if ($login->gt($shiftStart)) {
                        $isLate = true;
                        // ⭐ Use abs() to ensure positive, round to whole number
                        $lateMinutes = (int) abs($login->diffInMinutes($shiftStart));
                    }
                    
                    // Check if overtime
                    $shiftEnd = \Carbon\Carbon::parse($shiftData['shift_end']);
                    if ($logout->gt($shiftEnd)) {
                        $isOvertime = true;
                        // ⭐ Use abs() to ensure positive, round to whole number
                        $overtimeMinutes = (int) abs($logout->diffInMinutes($shiftEnd));
                    }
                }
                
                // ⭐ Calculate meter distance (if both readings exist)
                $meterDistance = null;
                if ($row->meter_start && $row->meter_end) {
                    $meterDistance = abs((int) $row->meter_end - (int) $row->meter_start);
                }
                
                $formattedData[] = [
                    'user_id' => $row->user_id,
                    'fullname' => $row->fullname,
                    'role_name' => $row->role_name,
                    'attendance_id' => $row->attendance_id,
                    'attendance_date' => $row->attendance_date,
                    'login_time' => $row->login_time,
                    'logout_time' => $row->logout_time,
                    'hours' => round($hours, 2),
                    'shift_start' => $shiftData['shift_start'],
                    'shift_end' => $shiftData['shift_end'],
                    'shift_name' => $shiftData['shift_name'],
                    'is_late' => $isLate,
                    'late_minutes' => $lateMinutes,
                    'is_overtime' => $isOvertime,
                    'overtime_minutes' => $overtimeMinutes,
                    'leave_request_id' => $row->leave_request_id,
                    'leave_status' => $row->leave_status,
                    'leave_type' => $row->leave_type_from_req,
                    'notes' => $row->notes,
                    // Meter pictures
                    'picture_start' => $row->picture_start ? $this->getMeterPictureUrl($row->picture_start) : null,
                    'picture_end' => $row->picture_end ? $this->getMeterPictureUrl($row->picture_end) : null,
                    // Meter values
                    'meter_start' => $row->meter_start,
                    'meter_end' => $row->meter_end,
                    'meter_distance' => $meterDistance, // ⭐ Pre-calculated meter distance
                    // Location data
                    'checkin_latitude' => $row->checkin_latitude ?? null,
                    'checkin_longitude' => $row->checkin_longitude ?? null,
                    'checkin_distance_from_base' => $row->checkin_distance_from_base ?? null,
                    'is_remote_checkin' => $row->is_remote_checkin ?? 0,
                    'checkout_latitude' => $row->checkout_latitude ?? null,
                    'checkout_longitude' => $row->checkout_longitude ?? null,
                    // ⭐ Road distance (auto-calculated on checkout) - PRIMARY indicator
                    'road_distance_km' => $row->road_distance_km ?? null,
                    'road_distance_source' => $row->road_distance_source ?? null,
                    // ⭐ GPS distance will be calculated in batch after the loop
                    'gps_distance' => null,
                    'gps_readings_count' => 0,
                    'gps_straight_distance_km' => $row->gps_straight_distance_km ?? null,
                ];
            }
            
            // ⭐ Batch calculate GPS distance for all users who have attendance
            $userIdsWithAttendance = array_filter(
                array_column($formattedData, 'user_id'),
                fn($uid) => collect($formattedData)->firstWhere('user_id', $uid)['login_time'] ?? false
            );
            
            if (!empty($userIdsWithAttendance)) {
                // Get all GPS readings for the date in one query
                $allGpsReadings = \DB::table('t_ops_rider_location')
                    ->whereIn('user_id', $userIdsWithAttendance)
                    ->whereDate('captured_at', $selectedDate)
                    ->where('accuracy', '<=', 100) // Skip inaccurate readings
                    ->orderBy('user_id')
                    ->orderBy('captured_at')
                    ->select('user_id', 'latitude', 'longitude', 'accuracy', 'captured_at')
                    ->get()
                    ->groupBy('user_id');
                
                // Calculate GPS distance for each user
                foreach ($formattedData as &$employee) {
                    if (isset($allGpsReadings[$employee['user_id']])) {
                        $readings = $allGpsReadings[$employee['user_id']]->values()->all();
                        $gpsResult = $this->calculateGpsDistanceFromReadings($readings);
                        $employee['gps_distance'] = $gpsResult['distance'];
                        $employee['gps_readings_count'] = $gpsResult['readings_count'];
                    }
                }
                unset($employee); // Clear reference
            }
            
            // ⭐ Batch fetch previous day's meter_end for all employees
            // This helps identify gaps between yesterday's end and today's start
            $allUserIds = array_column($formattedData, 'user_id');
            $previousMeterEnds = [];
            
            if (!empty($allUserIds)) {
                // Get the most recent meter_end for each user before the selected date
                $prevReadings = \DB::select("
                    SELECT a.user_id, a.meter_end, a.attendance_date
                    FROM t_ops_attendance a
                    INNER JOIN (
                        SELECT user_id, MAX(attendance_date) as max_date
                        FROM t_ops_attendance
                        WHERE user_id IN (" . implode(',', array_fill(0, count($allUserIds), '?')) . ")
                          AND attendance_date < ?
                          AND meter_end IS NOT NULL
                        GROUP BY user_id
                    ) latest ON a.user_id = latest.user_id AND a.attendance_date = latest.max_date
                ", array_merge($allUserIds, [$selectedDate]));
                
                foreach ($prevReadings as $prev) {
                    $previousMeterEnds[$prev->user_id] = [
                        'meter_end' => $prev->meter_end,
                        'date' => $prev->attendance_date,
                    ];
                }
            }
            
            // Batch fetch leaves_taken_year for users who are on leave today
            $currentYear = date('Y');
            $leaveUserIds = array_column(
                array_filter($formattedData, fn($e) => $e['leave_request_id']),
                'user_id'
            );
            $yearLeavesByUser = [];
            if (!empty($leaveUserIds)) {
                $yearLeaves = DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', 'leave')
                    ->where('r.status', 'approved')
                    ->whereIn('r.requester_user_id', $leaveUserIds)
                    ->where('r.leave_start_date', '>=', "{$currentYear}-01-01")
                    ->where('r.leave_end_date', '<=', "{$currentYear}-12-31")
                    ->select('r.requester_user_id', 'r.leave_start_date', 'r.leave_end_date')
                    ->get();
                foreach ($yearLeaves as $yl) {
                    $days = \Carbon\Carbon::parse($yl->leave_start_date)
                        ->diffInDays(\Carbon\Carbon::parse($yl->leave_end_date)) + 1;
                    $yearLeavesByUser[$yl->requester_user_id] = 
                        ($yearLeavesByUser[$yl->requester_user_id] ?? 0) + $days;
                }
            }

            // Add previous meter info and leaves_taken_year to each employee
            foreach ($formattedData as &$employee) {
                $prev = $previousMeterEnds[$employee['user_id']] ?? null;
                $employee['prev_meter_end'] = $prev ? $prev['meter_end'] : null;
                $employee['prev_meter_date'] = $prev ? $prev['date'] : null;
                
                $employee['meter_gap'] = null;
                if ($prev && $employee['meter_start']) {
                    $gap = (int)$employee['meter_start'] - (int)$prev['meter_end'];
                    $employee['meter_gap'] = $gap;
                }

                $employee['leaves_taken_year'] = $yearLeavesByUser[$employee['user_id']] ?? 0;
                $employee['leaves_year'] = (int) $currentYear;
            }
            unset($employee);
            
            // Calculate summary stats
            $summary = [
                'total_employees' => count($formattedData),
                'present' => count(array_filter($formattedData, fn($e) => $e['login_time'])),
                'absent' => count(array_filter($formattedData, fn($e) => !$e['login_time'] && !$e['leave_request_id'])),
                'on_leave' => count(array_filter($formattedData, fn($e) => $e['leave_request_id'])),
                'late' => count(array_filter($formattedData, fn($e) => $e['is_late'])),
                'overtime' => count(array_filter($formattedData, fn($e) => $e['is_overtime'])),
            ];
            
            return response()->json([
                'success' => true,
                'date' => $selectedDate,
                'summary' => $summary,
                'attendance' => $formattedData,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get store attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE ATTENDANCE - Get individual employee attendance details
     * Reuses web app logic from AttendanceController@employeeDetails
     */
    public function getStoreAttendanceEmployeeDetails(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view store attendance'
                ], 403);
            }
            
            $userId = $request->input('user_id');
            $month = $request->input('month', now()->format('Y-m'));
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id is required'
                ], 400);
            }
            
            // Calculate date range for the month
            $startDate = $month . '-01';
            $endDate = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();
            
            // Get user info
            $userInfo = DB::table('t_sys_user as u')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->select('u.id', 'u.fullname', 'r.urole_name as role_name')
                ->where('u.id', $userId)
                ->first();
            
            if (!$userInfo) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }
            
            // Get shift info using ShiftResolutionService
            $shiftService = new \App\Services\ShiftResolutionService();
            $shiftInfo = $shiftService->getUserShift($userId, date('Y-m-d'));
            
            // Build per-day delivered orders via subquery
            $deliveredPerDay = DB::table('t_ops_order_rider_history as orh')
                ->join('t_crm_order_status_history as osh', function($join) {
                    $join->on('osh.order_id', '=', 'orh.order_id')
                         ->where('osh.status_code', 'delivered')
                         ->where('osh.is_current', 1);
                })
                ->select(
                    'orh.rider_user_id as rider_id',
                    DB::raw('DATE(osh.changed_at) as delivered_date'),
                    DB::raw('COUNT(DISTINCT osh.order_id) as orders_delivered'),
                    DB::raw('MIN(TIME(osh.changed_at)) as first_delivery_time'),
                    DB::raw('MAX(TIME(osh.changed_at)) as last_delivery_time')
                )
                ->where('orh.rider_user_id', '=', $userId)
                ->where('orh.is_current', '=', 1)
                ->whereBetween(DB::raw('DATE(osh.changed_at)'), [$startDate, $endDate])
                ->groupBy('orh.rider_user_id', DB::raw('DATE(osh.changed_at)'));
            
            // Leave requests subquery
            $leaveSub = DB::table('t_req_master')
                ->select(
                    'requester_user_id',
                    'id as leave_request_id',
                    'status as leave_status',
                    'leave_type',
                    'leave_start_date',
                    'leave_end_date'
                )
                ->where('category_id', function($q) {
                    $q->select('id')
                      ->from('t_req_category')
                      ->where('category_code', 'leave')
                      ->limit(1);
                });
            
            // Attendance rows joined with per-day delivered orders and leave requests
            $query = DB::table('t_ops_attendance as a')
                ->leftJoinSub($deliveredPerDay, 'd', function($join) {
                    $join->on('d.rider_id', '=', 'a.user_id')
                         ->on('d.delivered_date', '=', 'a.attendance_date');
                })
                ->leftJoinSub($leaveSub, 'lr', function($join) {
                    $join->on('lr.requester_user_id', '=', 'a.user_id')
                         ->whereColumn('a.attendance_date', '>=', 'lr.leave_start_date')
                         ->whereColumn('a.attendance_date', '<=', 'lr.leave_end_date');
                })
                ->where('a.user_id', '=', $userId)
                ->whereBetween('a.attendance_date', [$startDate, $endDate])
                ->select(
                    'a.id as attendance_id',
                    'a.attendance_date',
                    'a.login_time',
                    'a.logout_time',
                    'a.picture_start',
                    'a.picture_end',
                    'a.meter_start',
                    'a.meter_end',
                    'a.checkin_latitude',
                    'a.checkin_longitude',
                    'a.checkin_distance_from_base',
                    'a.is_remote_checkin',
                    'a.checkout_latitude',
                    'a.checkout_longitude',
                    // ⭐ Include stored road distance columns
                    'a.road_distance_km',
                    'a.road_distance_source',
                    'a.gps_straight_distance_km',
                    'a.gps_readings_used',
                    'lr.leave_request_id',
                    'lr.leave_status',
                    'lr.leave_type',
                    DB::raw('COALESCE(d.orders_delivered, 0) as total_orders_delivered'),
                    DB::raw("COALESCE(d.first_delivery_time, '-') as first_delivery_time"),
                    DB::raw("COALESCE(d.last_delivery_time, '-') as last_delivery_time")
                )
                ->orderByDesc('a.attendance_date')
                ->get();
            
            // Clean up null values
            foreach ($query as $record) {
                if ($record->first_delivery_time === null) {
                    $record->first_delivery_time = '-';
                }
                if ($record->last_delivery_time === null) {
                    $record->last_delivery_time = '-';
                }
            }
            
            // Calculate working days
            $workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $endDate);
            
            // ⭐ Calculate meter/distance statistics
            $totalDistance = 0;
            $daysWithMeterReadings = 0;
            $daysMissingMeterReadings = 0;
            
            foreach ($query as $record) {
                // Check if has valid meter readings
                if ($record->meter_start !== null && $record->meter_end !== null && 
                    $record->meter_start > 0 && $record->meter_end > 0) {
                    $distance = abs(intval($record->meter_end) - intval($record->meter_start));
                    $totalDistance += $distance;
                    $daysWithMeterReadings++;
                } elseif ($record->login_time) {
                    // Has attendance but no meter readings
                    $daysMissingMeterReadings++;
                }
            }
            
            // ⭐ Get fuel/petrol expenses for this user in this month
            $fuelExpense = DB::table('t_req_master as r')
                ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                ->where('c.category_code', 'expense')
                ->where('r.requester_user_id', $userId)
                ->whereIn('r.status', ['approved', 'pending']) // Include pending for visibility
                ->where(function($q) {
                    $q->where('r.expense_category', 'LIKE', '%petrol%')
                      ->orWhere('r.expense_category', 'LIKE', '%fuel%')
                      ->orWhere('r.expense_category', 'LIKE', '%Petrol%')
                      ->orWhere('r.expense_category', 'LIKE', '%Fuel%');
                })
                ->where(function($q) use ($startDate, $endDate) {
                    // Use expense_date if available, otherwise created_at
                    $q->whereBetween('r.expense_date', [$startDate, $endDate])
                      ->orWhere(function($q2) use ($startDate, $endDate) {
                          $q2->whereNull('r.expense_date')
                             ->whereBetween(DB::raw('DATE(r.created_at)'), [$startDate, $endDate]);
                      });
                })
                ->sum('r.amount');
            
            // Calculate statistics
            $totalDays = $query->count();
            $presentDays = $query->where('login_time', '!=', null)->count();
            
            // Count leave days
            $onLeaveDays = 0;
            foreach ($query as $record) {
                if ($record->leave_request_id && 
                    in_array(strtolower($record->leave_status ?? ''), ['approved', 'pending'])) {
                    $onLeaveDays++;
                }
            }
            
            $absentDays = $workingDays - $presentDays - $onLeaveDays;
            if ($absentDays < 0) $absentDays = 0;
            
            $lateDays = 0;
            $overtimeDays = 0;
            $totalHours = 0;
            $totalOrdersDelivered = 0;
            
            foreach ($query as $record) {
                // Calculate hours worked
                if ($record->login_time && $record->logout_time) {
                    $login = strtotime($record->login_time);
                    $logout = strtotime($record->logout_time);
                    $hours = ($logout - $login) / 3600;
                    $totalHours += $hours;
                    $record->hours_worked = round($hours, 1);
                } else {
                    $record->hours_worked = 0;
                }
                
                // Check if late
                if ($record->login_time && $shiftInfo['shift_start']) {
                    $shiftStart = strtotime($record->attendance_date . ' ' . $shiftInfo['shift_start']);
                    $actualLogin = strtotime($record->attendance_date . ' ' . $record->login_time);
                    if ($actualLogin > $shiftStart) {
                        $lateDays++;
                        $record->late_minutes = round(($actualLogin - $shiftStart) / 60);
                    } else {
                        $record->late_minutes = 0;
                    }
                } else {
                    $record->late_minutes = 0;
                }
                
                // Check if overtime
                if ($record->logout_time && $shiftInfo['shift_end']) {
                    $shiftEnd = strtotime($record->attendance_date . ' ' . $shiftInfo['shift_end']);
                    $actualLogout = strtotime($record->attendance_date . ' ' . $record->logout_time);
                    if ($actualLogout > $shiftEnd) {
                        $overtimeDays++;
                        $record->overtime_minutes = round(($actualLogout - $shiftEnd) / 60);
                    } else {
                        $record->overtime_minutes = 0;
                    }
                } else {
                    $record->overtime_minutes = 0;
                }
                
                // Add order count to total
                $totalOrdersDelivered += $record->total_orders_delivered;
                
                // Format delivery times
                if ($record->first_delivery_time && $record->first_delivery_time !== '-') {
                    try {
                        $record->first_delivery_time = date('H:i', strtotime($record->first_delivery_time));
                    } catch (\Exception $e) {
                        $record->first_delivery_time = '-';
                    }
                } else {
                    $record->first_delivery_time = '-';
                }
                
                if ($record->last_delivery_time && $record->last_delivery_time !== '-') {
                    try {
                        $record->last_delivery_time = date('H:i', strtotime($record->last_delivery_time));
                    } catch (\Exception $e) {
                        $record->last_delivery_time = '-';
                    }
                } else {
                    $record->last_delivery_time = '-';
                }
                
                // Convert picture paths to URLs
                $record->picture_start = $record->picture_start ? $this->getMeterPictureUrl($record->picture_start) : null;
                $record->picture_end = $record->picture_end ? $this->getMeterPictureUrl($record->picture_end) : null;
                
                // ⭐ Calculate meter distance for this day
                $record->meter_distance = null;
                if ($record->meter_start && $record->meter_end) {
                    $record->meter_distance = abs((int) $record->meter_end - (int) $record->meter_start);
                }
            }
            
            // ⭐ Batch calculate GPS distance for all days with attendance
            $datesWithAttendance = $query
                ->filter(fn($r) => $r->login_time !== null)
                ->pluck('attendance_date')
                ->toArray();
            
            $totalGpsDistance = 0;
            $totalRoadDistance = 0;
            $daysWithGpsReadings = 0;
            $daysWithRoadDistance = 0;
            
            // Determine which day to auto-calculate road distance for (most recent with attendance)
            $autoCalcRoadDate = !empty($datesWithAttendance) ? max($datesWithAttendance) : null;
            
            if (!empty($datesWithAttendance)) {
                // Get all GPS readings for this user in the date range
                $allGpsReadings = \DB::table('t_ops_rider_location')
                    ->where('user_id', $userId)
                    ->whereBetween(\DB::raw('DATE(captured_at)'), [$startDate, $endDate])
                    ->where('accuracy', '<=', 100)
                    ->orderBy('captured_at')
                    ->select('latitude', 'longitude', 'accuracy', 'captured_at', \DB::raw('DATE(captured_at) as reading_date'))
                    ->get()
                    ->groupBy('reading_date');
                
                // Get attendance records for gap analysis
                $attendanceByDate = [];
                foreach ($query as $record) {
                    $attendanceByDate[$record->attendance_date] = $record;
                }
                
                // Assign GPS distance to each record
                foreach ($query as $record) {
                    $recordDate = $record->attendance_date;
                    if (isset($allGpsReadings[$recordDate])) {
                        $readings = $allGpsReadings[$recordDate]->values()->all();
                        $gpsResult = $this->calculateGpsDistanceFromReadings($readings);
                        $record->gps_distance = $gpsResult['distance'];
                        $record->gps_readings_count = $gpsResult['readings_count'];
                        
                        if ($gpsResult['distance'] !== null) {
                            $totalGpsDistance += $gpsResult['distance'];
                            $daysWithGpsReadings++;
                        }
                        
                        // ⭐ Check for stored road distance first, otherwise calculate for most recent day
                        $record->gap_info = null;
                        
                        // ⭐ USE STORED VALUE if available (from checkout or manual calculation)
                        if ($record->road_distance_km !== null) {
                            $record->road_distance = (float)$record->road_distance_km;
                            $record->road_source = $record->road_distance_source ?? 'stored';
                            $totalRoadDistance += $record->road_distance;
                            $daysWithRoadDistance++;
                        } elseif ($recordDate === $autoCalcRoadDate && $record->gps_distance !== null && $record->gps_distance >= 0.5) {
                            // Auto-calculate road distance for most recent day only (if not stored)
                            $sampledReadings = $this->sampleGpsReadings($readings, 25); // ⭐ Use 25 samples
                            if (count($sampledReadings) >= 2) {
                                $roadDist = $this->callOpenRouteService($sampledReadings);
                                if ($roadDist !== null) {
                                    $record->road_distance = round($roadDist, 1);
                                    $record->road_source = 'openrouteservice';
                                    $totalRoadDistance += $roadDist;
                                    $daysWithRoadDistance++;
                                } else {
                                    $record->road_distance = null;
                                    $record->road_source = null;
                                }
                            } else {
                                $record->road_distance = null;
                                $record->road_source = null;
                            }
                        } elseif ($record->gps_distance !== null && $record->gps_distance < 0.5) {
                            $record->road_distance = null;
                            $record->road_source = 'skipped_stationary';
                        } else {
                            $record->road_distance = null;
                            $record->road_source = null;
                        }
                        
                        // ⭐ Calculate gap info for this day
                        // IMPORTANT: Combine date + time to get full timestamp for accurate gap calculation
                        if ($record->login_time && count($readings) >= 2) {
                            $fullLoginTime = $recordDate . ' ' . $record->login_time;
                            $fullLogoutTime = $record->logout_time ? ($recordDate . ' ' . $record->logout_time) : null;
                            $gapInfo = $this->calculateGapInfo($readings, $fullLoginTime, $fullLogoutTime);
                            $record->gap_info = $gapInfo;
                        }
                    } else {
                        // No GPS readings for this day, but check for stored road distance
                        $record->gps_distance = null;
                        $record->gps_readings_count = 0;
                        $record->gap_info = null;
                        
                        // ⭐ Still use stored road_distance_km if available
                        if ($record->road_distance_km !== null) {
                            $record->road_distance = (float)$record->road_distance_km;
                            $record->road_source = $record->road_distance_source ?? 'stored';
                            $totalRoadDistance += $record->road_distance;
                            $daysWithRoadDistance++;
                        } else {
                            $record->road_distance = null;
                            $record->road_source = null;
                        }
                    }
                }
            } else {
                // No attendance dates, but still check for stored road distance
                foreach ($query as $record) {
                    $record->gps_distance = null;
                    $record->gps_readings_count = 0;
                    $record->gap_info = null;
                    
                    // ⭐ Still use stored road_distance_km if available
                    if ($record->road_distance_km !== null) {
                        $record->road_distance = (float)$record->road_distance_km;
                        $record->road_source = $record->road_distance_source ?? 'stored';
                        $totalRoadDistance += $record->road_distance;
                        $daysWithRoadDistance++;
                    } else {
                        $record->road_distance = null;
                        $record->road_source = null;
                    }
                }
            }
            
            return response()->json([
                'success' => true,
                'employee' => [
                    'user_id' => $userInfo->id,
                    'fullname' => $userInfo->fullname,
                    'role_name' => $userInfo->role_name,
                    'shift_start' => $shiftInfo['shift_start'],
                    'shift_end' => $shiftInfo['shift_end'],
                    'shift_name' => $shiftInfo['shift_name'],
                    'working_days' => $workingDays,
                    'present_days' => $presentDays,
                    'on_leave_days' => $onLeaveDays,
                    'absent_days' => $absentDays,
                    'late_days' => $lateDays,
                    'overtime_days' => $overtimeDays,
                    'total_hours' => round($totalHours, 1),
                    'total_orders_delivered' => $totalOrdersDelivered,
                    // ⭐ NEW: Meter/Distance statistics
                    'total_distance' => $totalDistance,
                    'days_with_meter_readings' => $daysWithMeterReadings,
                    'days_missing_meter_readings' => $daysMissingMeterReadings,
                    // ⭐ NEW: GPS Distance statistics
                    'total_gps_distance' => round($totalGpsDistance, 1),
                    'days_with_gps_readings' => $daysWithGpsReadings,
                    // ⭐ NEW: Road Distance statistics (most accurate)
                    'total_road_distance' => round($totalRoadDistance, 1),
                    'days_with_road_distance' => $daysWithRoadDistance,
                    // ⭐ NEW: Fuel expense for efficiency calculation
                    'fuel_expense' => round($fuelExpense, 0),
                ],
                'daily_records' => $query,
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get employee attendance details: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to load employee details: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE ATTENDANCE - Update meter values for an attendance record
     */
    public function updateMeterValues(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to update attendance'
                ], 403);
            }
            
            $request->validate([
                'attendance_id' => 'required|integer',
                'meter_start' => 'nullable|integer',
                'meter_end' => 'nullable|integer',
            ]);
            
            $attendanceId = $request->input('attendance_id');
            $meterStart = $request->input('meter_start');
            $meterEnd = $request->input('meter_end');
            
            // Verify attendance record exists
            $attendance = DB::table('t_ops_attendance')->where('id', $attendanceId)->first();
            
            if (!$attendance) {
                return response()->json([
                    'success' => false,
                    'message' => 'Attendance record not found'
                ], 404);
            }
            
            // Update meter values
            DB::table('t_ops_attendance')
                ->where('id', $attendanceId)
                ->update([
                    'meter_start' => $meterStart,
                    'meter_end' => $meterEnd,
                    'updated_at' => now(),
                ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Meter values updated successfully',
                'meter_start' => $meterStart,
                'meter_end' => $meterEnd,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to update meter values: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update meter values: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * STORE ATTENDANCE - Get monthly attendance summary for all employees
     */
    public function getStoreAttendanceMonthly(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view store attendance'
                ], 403);
            }
            
            $month = $request->input('month', now()->format('Y-m'));
            $startDate = $month . '-01';
            $endDate = \Carbon\Carbon::parse($startDate)->endOfMonth()->toDateString();
            
            // Get all active visible users
            $users = DB::table('t_sys_user as u')
                ->leftJoin('t_sys_user_role as ur', 'ur.user_id', '=', 'u.id')
                ->leftJoin('t_sys_role as r', 'r.id', '=', 'ur.role_id')
                ->leftJoin('t_ops_attendance_visibility as av', 'av.user_id', '=', 'u.id')
                ->leftJoin('t_ops_rider_profile as rp', 'rp.user_id', '=', 'u.id')
                ->select(
                    'u.id as user_id',
                    'u.fullname',
                    'r.urole_name as role_name',
                    DB::raw('COALESCE(rp.shift_start, "09:00") as shift_start'),
                    DB::raw('COALESCE(rp.shift_end, "17:00") as shift_end')
                )
                ->where('u.is_active', 1)
                ->where(function($q) {
                    $q->whereNull('av.is_visible')
                      ->orWhere('av.is_visible', 1);
                })
                ->orderBy('u.fullname')
                ->get();
            
            $monthlyData = [];
            $shiftService = new \App\Services\ShiftResolutionService();
            $lookupDate = date('Y-m-d');
            
            foreach ($users as $user) {
                // Resolve shift using ShiftResolutionService (same as web) for accurate shift times
                try {
                    $shiftData = $shiftService->getUserShift($user->user_id, $lookupDate);
                    $resolvedShiftStart = $shiftData['shift_start'];
                    $resolvedShiftEnd = $shiftData['shift_end'];
                    $resolvedShiftName = $shiftData['shift_name'];
                } catch (\Exception $e) {
                    $resolvedShiftStart = $user->shift_start;
                    $resolvedShiftEnd = $user->shift_end;
                    $resolvedShiftName = $user->shift_start . ' - ' . $user->shift_end;
                }

                // Normalize to HH:MM:SS for consistent SQL/PHP comparisons
                $shiftStartFull = strlen($resolvedShiftStart) <= 5 ? $resolvedShiftStart . ':00' : $resolvedShiftStart;
                $shiftEndFull = strlen($resolvedShiftEnd) <= 5 ? $resolvedShiftEnd . ':00' : $resolvedShiftEnd;

                // Get attendance records for this user in the month
                $attendance = DB::table('t_ops_attendance')
                    ->where('user_id', $user->user_id)
                    ->whereBetween('attendance_date', [$startDate, $endDate])
                    ->get();
                
                // Get leave requests and calculate leave days manually
                $leaveRequests = DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', '=', 'leave')
                    ->where('r.requester_user_id', $user->user_id)
                    ->whereIn('r.status', ['approved', 'pending'])
                    ->where(function($q) use ($startDate, $endDate) {
                        $q->whereBetween('r.leave_start_date', [$startDate, $endDate])
                          ->orWhereBetween('r.leave_end_date', [$startDate, $endDate])
                          ->orWhere(function($q2) use ($startDate, $endDate) {
                              $q2->where('r.leave_start_date', '<=', $startDate)
                                 ->where('r.leave_end_date', '>=', $endDate);
                          });
                    })
                    ->select('r.leave_start_date', 'r.leave_end_date')
                    ->get();
                
                // Calculate total leave days within the month range
                $leaveDays = 0;
                foreach ($leaveRequests as $leave) {
                    $leaveStart = max($leave->leave_start_date, $startDate);
                    $leaveEnd = min($leave->leave_end_date, $endDate);
                    $days = \Carbon\Carbon::parse($leaveStart)->diffInDays(\Carbon\Carbon::parse($leaveEnd)) + 1;
                    $leaveDays += $days;
                }
                
                // Calculate stats
                $presentDays = $attendance->filter(fn($a) => $a->login_time)->count();
                $totalHours = 0;
                $overtimeDays = 0;
                $overtimeMinutes = 0;
                
                foreach ($attendance as $record) {
                    if ($record->login_time && $record->logout_time) {
                        $loginTs = strtotime($record->attendance_date . ' ' . $record->login_time);
                        $logoutTs = strtotime($record->attendance_date . ' ' . $record->logout_time);
                        $totalHours += ($logoutTs - $loginTs) / 3600;
                        
                        $shiftEndTs = strtotime($record->attendance_date . ' ' . $shiftEndFull);
                        if ($logoutTs > $shiftEndTs) {
                            $overtimeDays++;
                            $overtimeMinutes += round(($logoutTs - $shiftEndTs) / 60);
                        }
                    }
                }
                
                // Use SQL with TIME() cast for reliable late calculation
                $lateStats = DB::selectOne("
                    SELECT 
                        COALESCE(SUM(CASE WHEN TIME(login_time) > TIME(?) THEN 1 ELSE 0 END), 0) as late_days,
                        COALESCE(SUM(CASE WHEN TIME(login_time) > TIME(?) THEN 
                            TIMESTAMPDIFF(MINUTE, 
                                CONCAT(attendance_date, ' ', ?),
                                CONCAT(attendance_date, ' ', login_time)
                            ) ELSE 0 END), 0) as late_minutes
                    FROM t_ops_attendance
                    WHERE user_id = ?
                    AND attendance_date BETWEEN ? AND ?
                    AND login_time IS NOT NULL
                    AND login_time != ''
                ", [$shiftStartFull, $shiftStartFull, $shiftStartFull, $user->user_id, $startDate, $endDate]);
                
                $lateDays = (int) ($lateStats->late_days ?? 0);
                $lateMinutes = (int) ($lateStats->late_minutes ?? 0);
                
                // Calculate working days using ShiftResolutionService (same as web app)
                try {
                    $workingDays = $shiftService->calculateWorkingDays($user->user_id, $startDate, $endDate);
                } catch (\Exception $e) {
                    $workingDays = \Carbon\Carbon::parse($startDate)->diffInDaysFiltered(function(\Carbon\Carbon $date) use ($endDate) {
                        return $date->isWeekday() && $date->lte(\Carbon\Carbon::parse($endDate));
                    }, \Carbon\Carbon::parse($endDate));
                }
                
                $absentDays = max(0, $workingDays - $presentDays - $leaveDays);
                
                // Approved leaves for current year
                $currentYear = date('Y');
                $yearLeaveDays = 0;
                $yearLeaves = DB::table('t_req_master as r')
                    ->join('t_req_category as c', 'c.id', '=', 'r.category_id')
                    ->where('c.category_code', 'leave')
                    ->where('r.requester_user_id', $user->user_id)
                    ->where('r.status', 'approved')
                    ->where('r.leave_start_date', '>=', "{$currentYear}-01-01")
                    ->where('r.leave_end_date', '<=', "{$currentYear}-12-31")
                    ->select('r.leave_start_date', 'r.leave_end_date')
                    ->get();
                foreach ($yearLeaves as $yl) {
                    $yearLeaveDays += \Carbon\Carbon::parse($yl->leave_start_date)
                        ->diffInDays(\Carbon\Carbon::parse($yl->leave_end_date)) + 1;
                }

                $monthlyData[] = [
                    'user_id' => $user->user_id,
                    'fullname' => $user->fullname,
                    'role_name' => $user->role_name,
                    'shift_name' => $resolvedShiftName,
                    'present_days' => $presentDays,
                    'absent_days' => max(0, $absentDays),
                    'leave_days' => $leaveDays,
                    'late_days' => $lateDays,
                    'late_minutes' => $lateMinutes,
                    'overtime_days' => $overtimeDays,
                    'overtime_minutes' => $overtimeMinutes,
                    'total_hours' => round($totalHours, 1),
                    'working_days' => $workingDays,
                    'attendance_percentage' => $workingDays > 0 ? round(($presentDays / $workingDays) * 100, 1) : 0,
                    'leaves_taken_year' => $yearLeaveDays,
                    'leaves_year' => (int) $currentYear,
                ];
            }
            
            return response()->json([
                'success' => true,
                'month' => $month,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'employees' => $monthlyData,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get monthly attendance: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load monthly attendance: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * NF LEDGER - Get list of accounts for transfer (source or destination)
     */
    public function getTransferAccounts(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_nf_ledger')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view NF Ledger'
                ], 403);
            }
            
            // Get all active accounts (company accounts only - cash and bank)
            $accounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                ->whereIn('account_category', ['cash', 'bank'])
                ->orderBy('account_name', 'asc')
                ->get()
                ->map(function($account) {
                    return [
                        'id' => $account->id,
                        'account_code' => $account->account_code,
                        'account_name' => $account->account_name,
                        'account_category' => $account->account_category,
                        'account_type' => $account->account_type,
                        'current_balance' => $account->current_balance,
                    ];
                });
            
            return response()->json([
                'success' => true,
                'accounts' => $accounts,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get transfer accounts: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load accounts: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * NF LEDGER - Process account transfer
     * Reuses the same logic as web app
     */
    public function processTransfer(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_nf_ledger')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to process transfers'
                ], 403);
            }
            
            // Validate input
            $validator = \Validator::make($request->all(), [
                'from_account_id' => 'required|exists:t_fin_accounts,id',
                'to_account_id' => 'required|exists:t_fin_accounts,id|different:from_account_id',
                'amount' => 'required|numeric|min:0.01',
                'transaction_date' => 'required|date',
                'description' => 'required|string|max:500',
                'mode' => 'required|in:cash,online'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            DB::beginTransaction();
            
            $fromAccount = \App\Models\FIN\AccountModel::findOrFail($request->from_account_id);
            $toAccount = \App\Models\FIN\AccountModel::findOrFail($request->to_account_id);
            
            // Determine approval status
            // Online transfers require approval
            $approvalStatus = $request->mode === 'online' 
                ? \App\Models\FIN\LedgerModel::STATUS_PENDING 
                : \App\Models\FIN\LedgerModel::STATUS_APPROVED;
            
            // Create ledger entry
            $ledger = \App\Models\FIN\LedgerModel::create([
                'transaction_date' => $request->transaction_date,
                'transaction_type' => \App\Models\FIN\LedgerModel::TYPE_TRANSFER,
                'description' => $request->description,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $request->amount,
                'mode' => $request->mode,
                'approval_status' => $approvalStatus,
                'created_by' => $user->id
            ]);
            
            // Update balances (only if approved or cash)
            if ($approvalStatus === \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                // From account: debit or credit based on account type
                if ($fromAccount->account_type === 'asset') {
                    // Money going OUT from asset = Decrease
                    $fromAccount->current_balance -= $request->amount;
                } else {
                    // Money going OUT from liability/income/equity = Increase
                    $fromAccount->current_balance += $request->amount;
                }
                $fromAccount->save();
                
                // To account: opposite
                if ($toAccount->account_type === 'asset') {
                    // Money coming IN to asset = Increase
                    $toAccount->current_balance += $request->amount;
                } else {
                    // Money coming IN to liability/income/equity = Decrease
                    $toAccount->current_balance -= $request->amount;
                }
                $toAccount->save();
            }
            
            DB::commit();
            
            $message = $approvalStatus === \App\Models\FIN\LedgerModel::STATUS_PENDING
                ? 'Transfer created and pending approval!'
                : 'Transfer completed successfully!';
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'transfer' => [
                    'id' => $ledger->id,
                    'from_account' => $fromAccount->account_name,
                    'to_account' => $toAccount->account_name,
                    'amount' => $ledger->amount,
                    'mode' => $ledger->mode,
                    'approval_status' => $ledger->approval_status,
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Mobile API - Failed to process transfer: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to process transfer: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * NF LEDGER - Get ledger details for a specific account
     * Shows transaction history with filtering options
     */
    public function getNFLedgerDetails(Request $request, $accountId)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_nf_ledger')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view NF Ledger'
                ], 403);
            }
            
            // Get the account
            $account = \App\Models\FIN\AccountModel::with('user')->find($accountId);
            
            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found'
                ], 404);
            }
            
            // Block access to private accounts for non-Taimur users
            if ($account->is_private) {
                $isTaimurRole = $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
                if (!$isTaimurRole) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Account not found'
                    ], 404);
                }
            }
            
            // Get filter parameters
            $period = $request->input('period', 'all_time'); // today, this_week, this_month, all_time
            $daysPerPage = 30;
            
            // Build base query - INCLUDE APPROVED AND ALL PENDING LEVELS
            $baseQuery = \App\Models\FIN\LedgerModel::where(function($q) use ($accountId) {
                $q->where('from_account_id', $accountId)
                  ->orWhere('to_account_id', $accountId);
            })
            ->whereIn('approval_status', [
                \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                \App\Models\FIN\LedgerModel::STATUS_PENDING,
                \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
                \App\Models\FIN\LedgerModel::STATUS_PENDING_L2,
            ]);
            
            // Determine date window based on period or date_end
            $useDateWindow = ($period === 'all_time');
            
            if ($useDateWindow) {
                $dateEndStr = $request->input('date_end');
                $dateEnd = $dateEndStr ? \Carbon\Carbon::parse($dateEndStr) : \Carbon\Carbon::today();
                $dateStart = $dateEnd->copy()->subDays($daysPerPage - 1);
                $windowStart = $dateStart->format('Y-m-d');
                $windowEnd = $dateEnd->format('Y-m-d');
                
                $ledgerQuery = (clone $baseQuery)
                    ->whereBetween('transaction_date', [$windowStart, $windowEnd]);
            } else {
                $ledgerQuery = clone $baseQuery;
                if ($period === 'today') {
                    $ledgerQuery->whereDate('transaction_date', today());
                } elseif ($period === 'this_week') {
                    $ledgerQuery->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()]);
                } elseif ($period === 'this_month') {
                    $ledgerQuery->whereBetween('transaction_date', [now()->startOfMonth(), now()->endOfMonth()]);
                }
            }
            
            // Get transactions for the window
            $transactions = $ledgerQuery
                ->with(['fromAccount', 'toAccount'])
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->get();
            
            // Check if older records exist (for "Load Earlier" button)
            $hasMore = false;
            $nextDateEnd = null;
            if ($useDateWindow) {
                $hasMore = (clone $baseQuery)
                    ->where('transaction_date', '<', $windowStart)
                    ->exists();
                if ($hasMore) {
                    $nextDateEnd = \Carbon\Carbon::parse($windowStart)->subDay()->format('Y-m-d');
                }
            }
            
            // Group transactions by date and calculate running balance
            $groupedTransactions = [];
            $currentBalance = $account->current_balance;
            $currentDate = null;
            $dateGroup = null;
            
            foreach ($transactions as $txn) {
                $txnDate = \Carbon\Carbon::parse($txn->transaction_date)->format('Y-m-d');
                $isDebit = $txn->from_account_id == $accountId;
                
                // Create new date group if date changed
                if ($txnDate !== $currentDate) {
                    // Save previous group if exists
                    if ($dateGroup !== null) {
                        $groupedTransactions[] = $dateGroup;
                    }
                    
                    // Start new group
                    $currentDate = $txnDate;
                    $dateGroup = [
                        'date' => $txnDate,
                        'transactions' => [],
                        'total_in' => 0,
                        'total_out' => 0,
                        'net_change' => 0,
                    ];
                }
                
                // Format transaction
                $formattedTxn = [
                    'id' => $txn->id,
                    'date' => $txn->transaction_date,
                    'created_at' => $txn->created_at ? $txn->created_at->toIso8601String() : null,
                    'type' => $txn->transaction_type,
                    'description' => $txn->description,
                    'amount' => $txn->amount,
                    'is_debit' => $isDebit,
                    'approval_status' => $txn->approval_status,
                    'other_account' => $isDebit ? 
                        ($txn->toAccount ? $txn->toAccount->account_name : 'Unknown') :
                        ($txn->fromAccount ? $txn->fromAccount->account_name : 'Unknown'),
                    'reference_type' => $txn->reference_type,
                    'reference_id' => $txn->reference_id,
                ];
                
                // Add to date group
                $dateGroup['transactions'][] = $formattedTxn;
                
                // Calculate totals for this date (only approved transactions affect balance)
                if ($txn->approval_status === \App\Models\FIN\LedgerModel::STATUS_APPROVED) {
                    if ($isDebit) {
                        $dateGroup['total_out'] += $txn->amount;
                    } else {
                        $dateGroup['total_in'] += $txn->amount;
                    }
                }
            }
            
            // Save last group
            if ($dateGroup !== null) {
                $dateGroup['net_change'] = $dateGroup['total_in'] - $dateGroup['total_out'];
                $groupedTransactions[] = $dateGroup;
            }
            
            // Calculate summary (only approved transactions)
            $totalIn = \App\Models\FIN\LedgerModel::where('to_account_id', $accountId)
                ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
                ->sum('amount');
            
            $totalOut = \App\Models\FIN\LedgerModel::where('from_account_id', $accountId)
                ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
                ->sum('amount');
            
            // Use effective balance: calculated for employee_cash, stored for others
            $effectiveBalance = $account->getEffectiveBalance();
            
            // Get loan and advance balances for employee accounts
            $loanBalance = 0;
            $advanceBalance = 0;
            if ($account->account_category === 'employee_cash' && $account->user_id) {
                // Get total outstanding loan balance
                $loanBalance = \App\Models\HR\EmployeeLoanModel::where('user_id', $account->user_id)
                    ->where('loan_status', 'active')
                    ->sum('outstanding_balance');
                
                // Get total pending salary advances (from approved but not settled requests)
                $advanceBalance = \App\Models\Request\RequestModel::where('requester_user_id', $account->user_id)
                    ->whereHas('category', function($q) {
                        $q->where('category_code', 'salary_advance');
                    })
                    ->where('status', 'approved')
                    ->where(function($q) {
                        $q->whereNull('settlement_status')
                          ->orWhere('settlement_status', '!=', 'settled');
                    })
                    ->sum('amount');
            }
            
            // Get company accounts for petty cash source selection
            $companyAccounts = \App\Models\FIN\AccountModel::where('is_active', 1)
                ->where('account_category', '!=', 'employee_cash')
                ->whereIn('account_code', ['NF_CASH', 'ONLINE', 'EXP_FUND'])
                ->get(['id', 'account_code', 'account_name']);
            
            return response()->json([
                'success' => true,
                'account' => [
                    'id' => $account->id,
                    'account_code' => $account->account_code,
                    'account_name' => $account->account_name,
                    'account_category' => $account->account_category,
                    'current_balance' => $effectiveBalance,
                    'user_id' => $account->user_id,
                    'user_name' => $account->user ? ($account->user->fullname ?? $account->user->name) : null,
                    'petty_cash' => $account->petty_cash ?? 0,
                ],
                'summary' => [
                    'total_in' => $totalIn,
                    'total_out' => $totalOut,
                    'current_balance' => $effectiveBalance,
                    'loan_balance' => $loanBalance,
                    'advance_balance' => $advanceBalance,
                ],
                'grouped_transactions' => $groupedTransactions,
                'company_accounts' => $companyAccounts,
                'pagination' => [
                    'has_more' => $hasMore,
                    'next_date_end' => $nextDateEnd,
                    'window_start' => $useDateWindow ? $windowStart : null,
                    'window_end' => $useDateWindow ? $windowEnd : null,
                ],
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Mobile API - Failed to get NF Ledger details: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load ledger details: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update petty cash for an employee account
     * Only Taimur can modify, all can view
     */
    public function updatePettyCash(Request $request, $accountId)
    {
        try {
            $user = Auth::user();
            
            // Only Taimur can modify petty cash
            $isTaimur = strtolower($user->name ?? '') === 'taimur' || 
                        strtolower($user->fullname ?? '') === 'taimur' ||
                        strtolower($user->urole_name ?? '') === 'taimur';
            
            if (!$isTaimur) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only Taimur can modify petty cash'
                ], 403);
            }
            
            $account = \App\Models\FIN\AccountModel::find($accountId);
            
            if (!$account) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account not found'
                ], 404);
            }
            
            $amount = $request->input('amount', 0);
            $sourceType = $request->input('source_type', 'outside_cash');
            $sourceAccountId = $request->input('source_account_id');
            
            // Update petty cash
            $account->petty_cash = $amount;
            $account->save();
            
            // Log the change
            \Log::info('Petty cash updated', [
                'account_id' => $accountId,
                'amount' => $amount,
                'source_type' => $sourceType,
                'source_account_id' => $sourceAccountId,
                'updated_by' => $user->id,
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Petty cash updated successfully',
                'petty_cash' => $amount,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to update petty cash: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update petty cash: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Overall Ledger Summary (for mobile app)
     * Reuses same logic as LedgerController for consistency
     */
    public function getOverallLedger(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission (reuse view_nf_ledger for now)
            if (!$user->hasMobilePermission('view_nf_ledger')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view Overall Ledger'
                ], 403);
            }
            
            // Get date range for filters (default to current month)
            $startDate = $request->input('start_date', now()->startOfMonth()->format('Y-m-d'));
            $endDate = $request->input('end_date', now()->endOfMonth()->format('Y-m-d'));
            
            // Check if user is Taimur (for private account filtering)
            $isTaimurRole = $user->roles()->whereRaw('LOWER(urole_name) = ?', ['taimur'])->exists();
            
            // Calculate KPIs (same logic as web app)
            $kpis = $this->calculateOverallLedgerKPIs($startDate, $endDate);
            
            // Get recent transactions (limited to 50 for mobile)
            $query = \App\Models\FIN\LedgerModel::with(['fromAccount', 'toAccount', 'order']);
            
            // Exclude transactions involving private accounts for non-Taimur
            if (!$isTaimurRole) {
                $privateAccountIds = \App\Models\FIN\AccountModel::where('is_private', 1)->pluck('id')->toArray();
                if (!empty($privateAccountIds)) {
                    $query->whereNotIn('from_account_id', $privateAccountIds)
                          ->whereNotIn('to_account_id', $privateAccountIds);
                }
            }
            
            // Filter by date range
            if ($startDate && $endDate) {
                $query->whereBetween('transaction_date', [$startDate, $endDate]);
            }
            
            // Apply filters if provided
            if ($request->has('type') && $request->input('type')) {
                $query->where('transaction_type', $request->input('type'));
            }
            
            if ($request->has('mode') && $request->input('mode')) {
                $query->where('mode', $request->input('mode'));
            }
            
            if ($request->has('status') && $request->input('status')) {
                $query->where('approval_status', $request->input('status'));
            }
            
            // Special vendor filter
            if ($request->has('vendor_filter') && $request->input('vendor_filter')) {
                $query->whereIn('transaction_type', [
                    \App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE,
                    \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT
                ]);
            }
            
            $transactions = $query->orderBy('transaction_date', 'desc')
                                  ->orderBy('created_at', 'desc')
                                  ->limit(50)
                                  ->get()
                                  ->map(function($txn) {
                                      return [
                                          'id' => $txn->id,
                                          'date' => $txn->transaction_date->format('Y-m-d'),
                                          'type' => $txn->transaction_type,
                                          'type_label' => ucfirst(str_replace('_', ' ', $txn->transaction_type)),
                                          'from_account' => $txn->fromAccount ? $txn->fromAccount->account_name : 'N/A',
                                          'to_account' => $txn->toAccount ? $txn->toAccount->account_name : 'N/A',
                                          'description' => $txn->description,
                                          'amount' => $txn->amount,
                                          'amount_formatted' => 'Rs. ' . number_format($txn->amount, 2),
                                          'mode' => $txn->mode,
                                          'approval_status' => $txn->approval_status,
                                      ];
                                  });
            
            return response()->json([
                'success' => true,
                'kpis' => $kpis,
                'transactions' => $transactions,
                'date_range' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get overall ledger', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load overall ledger: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Calculate Overall Ledger KPIs (reuses LedgerController logic)
     */
    private function calculateOverallLedgerKPIs($startDate, $endDate)
    {
        // Get delivered order IDs for the period (using status history table)
        $invoicesQuery = \DB::table('t_crm_order_status_history')
            ->where('status_code', 'delivered')
            ->where('is_current', 1);
        
        if ($startDate && $endDate) {
            $invoicesQuery->whereBetween('changed_at', [$startDate, $endDate]);
        }
        
        $deliveredOrderIds = $invoicesQuery->pluck('order_id');
        
        // === KPI 1: INVOICES ===
        $totalInvoices = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->sum('total_price') ?? 0;
        
        $invoicesCash = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['cash', 'cash_on_delivery', 'Cash', 'COD'])
            ->sum('total_price') ?? 0;
        
        $nfCashAccount = \App\Models\FIN\AccountModel::where('account_code', 'NF_CASH')->first();
        $cashDeposits = 0;
        $shortCashTotal = 0;
        
        if ($nfCashAccount) {
            $cashDeposits = \App\Models\FIN\LedgerModel::where('to_account_id', $nfCashAccount->id)
                ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->sum('amount') ?? 0;
            
            $shortCashTotal = \App\Models\Request\RequestModel::where('status', 'approved')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                })
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('amount') ?? 0;
        }
        
        $invoicesOnline = \DB::table('t_crm_prod_order')
            ->whereIn('id', $deliveredOrderIds)
            ->whereIn('payment_method', ['online', 'Online', 'bank_transfer', 'card', 'online_payment'])
            ->sum('total_price') ?? 0;
        
        // === ONLINE INVOICES: Enhanced with L1/L2 split (matching web LedgerController) ===
        $onlineAccount = \App\Models\FIN\AccountModel::where('account_code', 'ONLINE')->first();
        $onlineInvoiceLedgersQuery = \App\Models\FIN\LedgerModel::where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
            ->where('approval_status', '!=', \App\Models\FIN\LedgerModel::STATUS_REVERSED)
            ->whereBetween('transaction_date', [$startDate, $endDate]);
        
        if ($onlineAccount) {
            $onlineInvoiceLedgersQuery->where('to_account_id', $onlineAccount->id);
        }
        
        $onlineInvoiceLedgers = $onlineInvoiceLedgersQuery->get();
        
        $onlineApproved = $onlineInvoiceLedgers
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->sum('amount');
        
        // Split pending by approval level (L1 vs L2)
        // Legacy 'pending' status is treated as pending_l1
        $onlinePendingL1 = $onlineInvoiceLedgers
            ->filter(function ($invoice) {
                return in_array($invoice->approval_status, [
                    \App\Models\FIN\LedgerModel::STATUS_PENDING,      // Legacy: treat as L1
                    \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
                ], true);
            })
            ->sum('amount');
        
        $onlinePendingL2 = $onlineInvoiceLedgers
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_PENDING_L2)
            ->sum('amount');
        
        // Total pending (for backward compatibility)
        $onlinePending = $onlinePendingL1 + $onlinePendingL2;
        
        // === KPI 2: EXPENSES ===
        $ledgerExpenses = \App\Models\FIN\LedgerModel::where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_EXPENSE)
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $salaryExpenses = \App\Models\HR\SalarySlipModel::whereIn('slip_status', ['approved', 'paid'])
            ->whereNotNull('ledger_transaction_id')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('net_salary') ?? 0;
        
        $totalExpenses = $ledgerExpenses + $salaryExpenses;
        
        $expensesNeedingSettlement = \App\Models\Request\RequestModel::where('status', 'approved')
            ->where('settlement_status', 'pending')
            ->whereHas('category', function($q) {
                $q->where('category_code', 'expense');
            })
            ->whereBetween('created_at', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        // === KPI 3: VENDOR BALANCE ===
        $vendorPurchases = \App\Models\FIN\LedgerModel::where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_VENDOR_PURCHASE)
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $vendorPayments = \App\Models\FIN\LedgerModel::where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_VENDOR_PAYMENT)
            ->where('approval_status', \App\Models\FIN\LedgerModel::STATUS_APPROVED)
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->sum('amount') ?? 0;
        
        $vendorBalance = $vendorPurchases - $vendorPayments;
        
        // === KPI 4: NF PROFIT ===
        $profit = $totalInvoices - $totalExpenses - $vendorPurchases;
        
        return [
            'total_invoices' => round($totalInvoices, 2),
            'invoices_cash' => round($invoicesCash, 2),
            'cash_deposits' => round($cashDeposits, 2),
            'short_cash_total' => round($shortCashTotal, 2),
            'invoices_online' => round($invoicesOnline, 2),
            'online_approved' => round($onlineApproved, 2),
            'online_pending' => round($onlinePending, 2),
            'online_pending_l1' => round($onlinePendingL1, 2), // NEW: Pending L1 split
            'online_pending_l2' => round($onlinePendingL2, 2), // NEW: Pending L2 split
            'total_expenses' => round($totalExpenses, 2),
            'regular_expenses' => round($ledgerExpenses, 2),
            'salary_expenses' => round($salaryExpenses, 2),
            'expenses_needing_settlement' => round($expensesNeedingSettlement, 2),
            'vendor_balance' => round($vendorBalance, 2),
            'vendor_purchases' => round($vendorPurchases, 2),
            'vendor_payments' => round($vendorPayments, 2),
            'profit' => round($profit, 2),
        ];
    }

    /**
     * Get Daily Closing Summary (Invoice Tracker for mobile app)
     * Replicates web app's EmployeeCashController::allOutstandingInvoices logic
     */
    public function getDailyClosing(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_daily_closing')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view Daily Closing'
                ], 403);
            }
            
            // Get filter parameters (same as web app)
            $statusFilter = $request->get('status', 'all'); // all, open, partial, settled
            $riderFilter = $request->get('rider', 'all');
            $dateFrom = $request->get('date_from');
            $dateTo = $request->get('date_to');
            // ⭐ New parameters: group_by (default 'date') and include_online (default true)
            $groupBy = $request->get('group_by', 'date'); // 'date' (default) or 'rider'
            $includeOnline = !$request->has('include_online') || $request->get('include_online') === '1';
            
            // Base query for ALL invoices (not just open)
            // IMPORTANT: Exclude reversed transactions (e.g., from payment method changes)
            // Includes both regular invoices AND qurbani order_payment entries that go to rider accounts
            $invoicesQuery = LedgerModel::whereIn('transaction_type', [LedgerModel::TYPE_INVOICE, LedgerModel::TYPE_ORDER_PAYMENT])
                ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED)
                ->with(['toAccount', 'order'])
                ->whereHas('toAccount', function($q) {
                    $q->where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH);
                });
            
            // Apply date filters
            if ($dateFrom) {
                $invoicesQuery->where('transaction_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $invoicesQuery->where('transaction_date', '<=', $dateTo);
            }
            
            // Apply rider filter
            if ($riderFilter !== 'all') {
                $invoicesQuery->where('to_account_id', $riderFilter);
            }
            
            // Get all invoices
            $allInvoices = $invoicesQuery->orderBy('transaction_date', 'desc')->get();
            
            // Separate into categories (EXACT web app logic)
            $openInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) == 0;
            });
            
            $partialInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'partial' || 
                       ($invoice->settlement_status === 'open' && ($invoice->settled_amount ?? 0) > 0);
            });
            
            $settledInvoices = $allInvoices->filter(function($invoice) {
                return $invoice->settlement_status === 'settled';
            });
            
            // Get pending settlement deposits (settlements awaiting approval)
            // Include both "Settlement" and "Partial Payment" descriptions
            $pendingSettlements = LedgerModel::where('transaction_type', LedgerModel::TYPE_EMPLOYEE_DEPOSIT)
                ->where('approval_status', LedgerModel::STATUS_PENDING)
                ->where(function($q) {
                    $q->where('description', 'LIKE', '%Settlement%')
                      ->orWhere('description', 'LIKE', '%Partial Payment%');
                })
                ->with(['fromAccount'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Enhance pending settlements with invoice details from metadata
            $pendingSettlements = $pendingSettlements->map(function($settlement) {
                // Try ledger metadata first (NEW), fallback to session (OLD)
                $settlementData = $settlement->settlement_metadata;
                
                if ($settlementData && isset($settlementData['invoice_ids'])) {
                    $settlement->invoice_ids = $settlementData['invoice_ids'];
                    $settlement->total_outstanding = $settlementData['total_outstanding'];
                    
                    // Get the actual invoice records for display
                    $settlement->invoices = LedgerModel::whereIn('id', $settlementData['invoice_ids'])
                        ->with('order')
                        ->orderBy('transaction_date', 'asc')
                        ->get()
                        ->map(function($invoice) {
                            return [
                                'id' => $invoice->id,
                                'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                                'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                                'description' => $invoice->description,
                                'amount' => $invoice->amount,
                                'settled_amount' => $invoice->settled_amount ?? 0,
                                'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0)
                            ];
                        });
                } else {
                    $settlement->invoice_ids = [];
                    $settlement->invoices = collect();
                    $settlement->total_outstanding = 0;
                }
                
                return $settlement;
            });
            
            // Apply status filter for display
            $displayInvoices = collect();
            switch ($statusFilter) {
                case 'open':
                    $displayInvoices = $openInvoices;
                    break;
                case 'partial':
                    $displayInvoices = $partialInvoices;
                    break;
                case 'settled':
                    $displayInvoices = $settledInvoices;
                    break;
                default:
                    $displayInvoices = $openInvoices->concat($partialInvoices);
            }
            
            // Create a map of invoice IDs to their pending settlement IDs
            $invoiceToPendingSettlement = [];
            foreach ($pendingSettlements as $settlement) {
                foreach ($settlement->invoice_ids as $invoiceId) {
                    $invoiceToPendingSettlement[$invoiceId] = $settlement->id;
                }
            }
            
            // Group pending settlements by rider (from_account_id)
            $settlementsByRider = $pendingSettlements->groupBy('from_account_id');
            
            // Group by rider for display
            $invoicesByRider = $displayInvoices->groupBy('to_account_id')->map(function($riderInvoices) use ($invoiceToPendingSettlement, $settlementsByRider) {
                $account = $riderInvoices->first()->toAccount;
                $totalOutstanding = $riderInvoices->sum(function($invoice) {
                    return $invoice->amount - ($invoice->settled_amount ?? 0);
                });
                
                // Get pending settlements for this rider
                $riderSettlements = $settlementsByRider->get($account->id, collect());
                
                return [
                    'account' => [
                        'id' => $account->id,
                        'account_name' => $account->account_name,
                        'account_code' => $account->account_code
                    ],
                    'pending_settlements' => $riderSettlements->map(function($settlement) {
                        return [
                            'id' => $settlement->id,
                            'amount' => $settlement->amount,
                            'created_at' => $settlement->created_at->format('Y-m-d H:i:s'),
                            'description' => $settlement->description,
                            'invoice_ids' => $settlement->invoice_ids,
                            'invoices' => $settlement->invoices,
                            'total_outstanding' => $settlement->total_outstanding
                        ];
                    })->values(),
                    'invoices' => $riderInvoices->map(function($invoice) use ($invoiceToPendingSettlement) {
                        $isPendingApproval = isset($invoiceToPendingSettlement[$invoice->id]);
                        $pendingSettlementId = $isPendingApproval ? $invoiceToPendingSettlement[$invoice->id] : null;
                        
                        return [
                            'id' => $invoice->id,
                            'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                            'customer_name' => $invoice->order ? $invoice->order->customer_name : null,
                            'transaction_date' => $invoice->transaction_date->format('Y-m-d'),
                            'is_pending_approval' => $isPendingApproval,
                            'pending_settlement_id' => $pendingSettlementId,
                            'description' => $invoice->description,
                            'amount' => $invoice->amount,
                            'settled_amount' => $invoice->settled_amount ?? 0,
                            'outstanding_amount' => $invoice->amount - ($invoice->settled_amount ?? 0),
                            'settlement_status' => $invoice->settlement_status,
                            'settled_at' => $invoice->settled_at ? $invoice->settled_at->format('Y-m-d H:i:s') : null
                        ];
                    })->values(),
                    'total_outstanding' => $totalOutstanding,
                    'invoice_count' => $riderInvoices->count()
                ];
            })->values();
            
            // === Calculate Pending Approvals for Expense Requests ===
            // Use same logic as NF Cash: pending expenses that will affect NF Cash
            $nfCashAccount = AccountModel::where('account_code', 'NF_CASH')->first();
            
            $pendingApprovalsQuery = RequestModel::where('status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                });
            
            // NF Cash logic: explicit NF Cash assignments OR paid from any rider balance
            if ($nfCashAccount) {
                $pendingApprovalsQuery->where(function($q) use ($nfCashAccount) {
                    $q->where('payment_source_account_id', $nfCashAccount->id)
                      ->orWhereHas('paymentSourceAccount', function($subQ) {
                          $subQ->where('account_category', 'employee_cash');
                      });
                });
            }
            
            // Apply date filters if provided
            if ($dateFrom) {
                $pendingApprovalsQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $pendingApprovalsQuery->where('created_at', '<=', $dateTo);
            }
            
            $pendingApprovalsAmount = $pendingApprovalsQuery->sum('amount') ?? 0;
            $pendingApprovalsCount = $pendingApprovalsQuery->count();
            
            // === Calculate Short Cash ===
            // Approved expenses paid from rider balance but not yet settled
            $shortCashQuery = RequestModel::where('status', 'approved')
                ->where('settlement_status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->whereHas('paymentSourceAccount', function($q) {
                    $q->where('account_category', 'employee_cash');
                });
            
            // Apply date filters if provided
            if ($dateFrom) {
                $shortCashQuery->where('created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $shortCashQuery->where('created_at', '<=', $dateTo);
            }
            
            $shortCashAmount = $shortCashQuery->sum('amount') ?? 0;
            $shortCashCount = $shortCashQuery->count();
            
            // Calculate summary stats (EXACT web app logic)
            $stats = [
                'open_count' => $openInvoices->count(),
                'open_total' => $openInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                'partial_count' => $partialInvoices->count(),
                'partial_total' => $partialInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                'pending_settlement_count' => $pendingSettlements->count(),
                'pending_settlement_total' => $pendingSettlements->sum('amount'),
                'settled_count' => $settledInvoices->count(),
                'settled_total' => $settledInvoices->sum('amount'),
                'total_outstanding' => $openInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }) + $partialInvoices->sum(function($inv) {
                    return $inv->amount - ($inv->settled_amount ?? 0);
                }),
                'pending_approvals_count' => $pendingApprovalsCount,
                'pending_approvals_amount' => $pendingApprovalsAmount,
                'short_cash_count' => $shortCashCount,
                'short_cash_amount' => $shortCashAmount
            ];
            
            // === Pending Petrol Requests for Daily Closing (auto + manual) ===
            $pendingPetrolQuery = RequestModel::where('status', 'pending')
                ->whereHas('category', function($q) {
                    $q->where('category_code', 'expense');
                })
                ->where('expense_category', 'Petrol')
                ->with(['requester']);

            if ($dateFrom) {
                $pendingPetrolQuery->where('expense_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $pendingPetrolQuery->where('expense_date', '<=', $dateTo);
            }

            $pendingPetrolRequests = $pendingPetrolQuery->orderBy('expense_date', 'desc')->get();

            $base = request()->getSchemeAndHttpHost();
            $petrolRequestsData = $pendingPetrolRequests->map(function($req) use ($base) {
                $attachmentUrl = null;
                if ($req->attachments && is_array($req->attachments) && count($req->attachments) > 0) {
                    $attachmentUrl = rtrim($base, '/') . '/public-storage/' . ltrim($req->attachments[0], '/');
                }
                return [
                    'id' => $req->id,
                    'request_number' => $req->request_number,
                    'rider_name' => $req->requester ? $req->requester->fullname : 'Unknown',
                    'requester_user_id' => $req->requester_user_id,
                    'expense_date' => $req->expense_date ? $req->expense_date->format('Y-m-d') : null,
                    'expense_date_formatted' => $req->expense_date ? $req->expense_date->format('D, M d') : null,
                    'meter_distance' => (float) $req->meter_distance,
                    'petrol_rate' => (float) $req->petrol_rate,
                    'amount' => (float) $req->amount,
                    'status' => $req->status,
                    'description' => $req->description,
                    'source' => $req->attendance_id ? 'meter' : 'manual',
                    'attachment_url' => $attachmentUrl,
                    'created_at' => $req->created_at ? $req->created_at->format('Y-m-d H:i:s') : null,
                ];
            })->values();

            $stats['petrol_requests_count'] = $petrolRequestsData->count();
            $stats['petrol_requests_amount'] = $petrolRequestsData->sum('amount');

            // Payment source accounts for petrol approval dropdown
            $petrolPaymentAccounts = [];
            if ($petrolRequestsData->count() > 0) {
                $petrolPaymentAccounts = AccountModel::whereIn('account_code', ['NF_CASH', 'EXP_FUND', 'ONLINE', 'PETTY_CASH'])
                    ->where('is_active', 1)
                    ->orderByRaw("CASE WHEN account_code = 'NF_CASH' THEN 1 WHEN account_code = 'EXP_FUND' THEN 2 WHEN account_code = 'ONLINE' THEN 3 ELSE 4 END")
                    ->select('id', 'account_code', 'account_name')
                    ->get()
                    ->map(fn($a) => ['id' => $a->id, 'code' => $a->account_code, 'name' => $a->account_name]);
            }

            // Get all riders for filter dropdown
            $allRiders = AccountModel::where('account_category', AccountModel::CATEGORY_EMPLOYEE_CASH)
                ->where('is_active', 1)
                ->orderBy('account_name')
                ->get()
                ->map(function($rider) {
                    return [
                        'id' => $rider->id,
                        'account_name' => $rider->account_name
                    ];
                });
            
            // ⭐ Prepare date-grouped data for settled view
            $invoicesByDate = null;
            $onlineData = null;
            
            if ($statusFilter === 'settled' && $groupBy === 'date') {
                // Group settled invoices by date (cash)
                $invoicesByDate = $settledInvoices
                    ->groupBy(function($invoice) {
                        return $invoice->settled_at ? $invoice->settled_at->format('Y-m-d') : $invoice->transaction_date->format('Y-m-d');
                    })
                    ->map(function($dateInvoices, $date) {
                        // Group by rider within each date
                        $riderGroups = $dateInvoices->groupBy('to_account_id')->map(function($riderInvoices) {
                            $account = $riderInvoices->first()->toAccount;
                            return [
                                'rider_name' => $account ? $account->account_name : 'Unknown',
                                'count' => $riderInvoices->count(),
                                'total_amount' => $riderInvoices->sum('amount'),
                                'invoices' => $riderInvoices->map(function($inv) {
                                    return [
                                        'id' => $inv->id,
                                        'order_number' => $inv->order ? $inv->order->order_number : 'N/A',
                                        'customer_name' => $inv->order ? $inv->order->customer_name : null,
                                        'amount' => $inv->amount,
                                        'settled_at' => $inv->settled_at ? $inv->settled_at->format('Y-m-d H:i:s') : null
                                    ];
                                })->values()
                            ];
                        })->values();
                        
                        return [
                            'date' => $date,
                            'riders' => $riderGroups,
                            'cash_count' => $dateInvoices->count(),
                            'cash_amount' => $dateInvoices->sum('amount'),
                            'online_count' => 0,
                            'online_amount' => 0,
                            'online_approved_count' => 0,
                            'online_approved_amount' => 0,
                            'online_pending_count' => 0,
                            'online_pending_amount' => 0,
                            'total_count' => $dateInvoices->count(),
                            'total_amount' => $dateInvoices->sum('amount')
                        ];
                    })
                    ->sortKeysDesc()
                    ->values()
                    ->toArray();
                
                // ⭐ Fetch online data if include_online is true
                if ($includeOnline) {
                    $onlineAccount = AccountModel::where('account_code', 'ONLINE')->first();
                    
                    if ($onlineAccount) {
                        $onlineQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
                            ->where('to_account_id', $onlineAccount->id)
                            ->where('approval_status', '!=', LedgerModel::STATUS_REVERSED);
                        
                        if ($dateFrom) {
                            $onlineQuery->where('transaction_date', '>=', $dateFrom);
                        }
                        if ($dateTo) {
                            $onlineQuery->where('transaction_date', '<=', $dateTo . ' 23:59:59');
                        }
                        
                        $onlineInvoices = $onlineQuery->with('order')->get();
                        
                        // Separate approved and pending
                        $onlineApproved = $onlineInvoices->where('approval_status', LedgerModel::STATUS_APPROVED);
                        $onlinePending = $onlineInvoices->where('approval_status', LedgerModel::STATUS_PENDING);
                        
                        // Group by transaction_date
                        $onlineByDate = [];
                        foreach ($onlineInvoices as $invoice) {
                            $date = $invoice->transaction_date->format('Y-m-d');
                            if (!isset($onlineByDate[$date])) {
                                $onlineByDate[$date] = [
                                    'approved' => [],
                                    'pending' => [],
                                    'approved_count' => 0,
                                    'approved_amount' => 0,
                                    'pending_count' => 0,
                                    'pending_amount' => 0
                                ];
                            }
                            
                            $invoiceData = [
                                'id' => $invoice->id,
                                'order_number' => $invoice->order ? $invoice->order->order_number : 'N/A',
                                'customer_name' => $invoice->order ? $invoice->order->customer_name : null,
                                'amount' => $invoice->amount,
                                'transaction_date' => $invoice->transaction_date->format('Y-m-d H:i:s')
                            ];
                            
                            if ($invoice->approval_status === LedgerModel::STATUS_APPROVED) {
                                $onlineByDate[$date]['approved'][] = $invoiceData;
                                $onlineByDate[$date]['approved_count']++;
                                $onlineByDate[$date]['approved_amount'] += $invoice->amount;
                            } else {
                                $onlineByDate[$date]['pending'][] = $invoiceData;
                                $onlineByDate[$date]['pending_count']++;
                                $onlineByDate[$date]['pending_amount'] += $invoice->amount;
                            }
                        }
                        
                        // Merge online data into invoicesByDate
                        foreach ($onlineByDate as $date => $onlineDayData) {
                            $found = false;
                            foreach ($invoicesByDate as &$dayData) {
                                if ($dayData['date'] === $date) {
                                    $dayData['online_count'] = $onlineDayData['approved_count'] + $onlineDayData['pending_count'];
                                    $dayData['online_amount'] = $onlineDayData['approved_amount'] + $onlineDayData['pending_amount'];
                                    $dayData['online_approved_count'] = $onlineDayData['approved_count'];
                                    $dayData['online_approved_amount'] = $onlineDayData['approved_amount'];
                                    $dayData['online_pending_count'] = $onlineDayData['pending_count'];
                                    $dayData['online_pending_amount'] = $onlineDayData['pending_amount'];
                                    $dayData['online_approved'] = $onlineDayData['approved'];
                                    $dayData['online_pending'] = $onlineDayData['pending'];
                                    $dayData['total_count'] += $onlineDayData['approved_count'] + $onlineDayData['pending_count'];
                                    $dayData['total_amount'] += $onlineDayData['approved_amount'] + $onlineDayData['pending_amount'];
                                    $found = true;
                                    break;
                                }
                            }
                            unset($dayData);
                            
                            // Add new date entry if not found in cash data
                            if (!$found) {
                                $invoicesByDate[] = [
                                    'date' => $date,
                                    'riders' => [],
                                    'cash_count' => 0,
                                    'cash_amount' => 0,
                                    'online_count' => $onlineDayData['approved_count'] + $onlineDayData['pending_count'],
                                    'online_amount' => $onlineDayData['approved_amount'] + $onlineDayData['pending_amount'],
                                    'online_approved_count' => $onlineDayData['approved_count'],
                                    'online_approved_amount' => $onlineDayData['approved_amount'],
                                    'online_pending_count' => $onlineDayData['pending_count'],
                                    'online_pending_amount' => $onlineDayData['pending_amount'],
                                    'online_approved' => $onlineDayData['approved'],
                                    'online_pending' => $onlineDayData['pending'],
                                    'total_count' => $onlineDayData['approved_count'] + $onlineDayData['pending_count'],
                                    'total_amount' => $onlineDayData['approved_amount'] + $onlineDayData['pending_amount']
                                ];
                            }
                        }
                        
                        // Re-sort by date descending
                        usort($invoicesByDate, function($a, $b) {
                            return strcmp($b['date'], $a['date']);
                        });
                        
                        $onlineData = [
                            'total_count' => $onlineInvoices->count(),
                            'total_amount' => $onlineInvoices->sum('amount'),
                            'approved_count' => $onlineApproved->count(),
                            'approved_amount' => $onlineApproved->sum('amount'),
                            'pending_count' => $onlinePending->count(),
                            'pending_amount' => $onlinePending->sum('amount')
                        ];
                    }
                }
            }
            
            // ============================================================
            // ⭐ Online WhatsApp Message Tracking (separate from ledger/settlement)
            // Shows today's online delivered orders and whether rider sent payment reminder
            // This does NOT affect any settlement, ledger, or invoice logic
            // Wrapped in its own try-catch so it NEVER crashes the daily closing
            // ============================================================
            $onlineMessageTracking = null;
            try {
                $onlinePaymentMethods = ['online', 'Online', 'bank_transfer', 'card', 'online_payment', 'direct_bank_transfer', 'bacs'];
                
                // Use status history to find orders delivered today (delivery_date is computed, not a real column)
                $onlineMessageQuery = \App\Models\CRM\OrderModel::whereIn('order_status', ['delivered', 'completed'])
                    ->where(function($q) use ($onlinePaymentMethods) {
                        $q->whereIn('payment_method', $onlinePaymentMethods);
                    })
                    ->whereExists(function($q) {
                        $q->select(\DB::raw(1))
                          ->from('t_crm_order_status_history as h')
                          ->whereColumn('h.order_id', 't_crm_prod_order.id')
                          ->where('h.status_code', 'delivered')
                          ->whereDate('h.changed_at', today());
                    })
                    ->with(['customer']);
                
                // Apply rider filter if specific rider selected
                if ($riderFilter !== 'all') {
                    // Need to find the user_id from the account_id
                    $riderAccount = AccountModel::find($riderFilter);
                    if ($riderAccount && $riderAccount->user_id) {
                        $onlineMessageQuery->where('assigned_rider_user_id', $riderAccount->user_id);
                    }
                }
                
                // Note: delivery_date is a computed accessor, not a real column - use id for ordering
                $onlineMessageOrders = $onlineMessageQuery->orderBy('id', 'desc')->get();
                
                // Separate into message sent and pending
                $messageSentOrders = $onlineMessageOrders->whereNotNull('online_message_sent_at');
                $messagePendingOrders = $onlineMessageOrders->whereNull('online_message_sent_at');
                
                // Pre-fetch delivery timestamps for pending orders
                $pendingOrderIds = $messagePendingOrders->pluck('id')->all();
                $deliveryHistory = [];
                if (!empty($pendingOrderIds)) {
                    $deliveryHistory = \DB::table('t_crm_order_status_history')
                        ->whereIn('order_id', $pendingOrderIds)
                        ->where('status_code', 'delivered')
                        ->get()
                        ->keyBy('order_id');
                }
                
                // Group by rider
                $onlineMessageByRider = $onlineMessageOrders->groupBy('assigned_rider_user_id')->map(function($riderOrders) use ($deliveryHistory) {
                    $riderUser = $riderOrders->first()->assignedRider ?? null;
                    $riderName = $riderUser ? $riderUser->fullname : 'Unknown';
                    
                    return [
                        'rider_name' => $riderName,
                        'rider_user_id' => $riderOrders->first()->assigned_rider_user_id,
                        'message_sent' => $riderOrders->whereNotNull('online_message_sent_at')->map(function($order) {
                            return [
                                'id' => $order->id,
                                'order_number' => $order->order_number,
                                'customer_name' => trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? '')) ?: ($order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'N/A'),
                                'amount' => round($order->total_price),
                                'message_sent_at' => $order->online_message_sent_at ? $order->online_message_sent_at->format('H:i') : null,
                            ];
                        })->values(),
                        'message_pending' => $riderOrders->whereNull('online_message_sent_at')->map(function($order) use ($deliveryHistory) {
                            $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? '')) ?: ($order->customer ? trim($order->customer->first_name . ' ' . $order->customer->last_name) : 'N/A');
                            $customerPhone = $order->address_phone ?: ($order->customer ? ($order->customer->phone_original ?? $order->customer->phone ?? '') : '');
                            $orderRider = $order->assignedRider;
                            $riderFullName = $orderRider ? $orderRider->fullname : 'your rider';
                            
                            $deliveryRecord = $deliveryHistory->get($order->id);
                            $deliveryDate = $deliveryRecord ? \Carbon\Carbon::parse($deliveryRecord->changed_at)->format('M d, Y') : ($order->delivery_date ?? now()->format('M d, Y'));
                            $deliveryTime = $deliveryRecord ? \Carbon\Carbon::parse($deliveryRecord->changed_at)->format('h:i A') : '';
                            
                            return [
                                'id' => $order->id,
                                'order_number' => $order->order_number,
                                'customer_name' => $customerName,
                                'customer_phone' => $customerPhone,
                                'rider_name' => $riderFullName,
                                'delivery_date' => $deliveryDate,
                                'delivery_time' => $deliveryTime,
                                'amount' => round($order->total_price),
                            ];
                        })->values(),
                        'sent_count' => $riderOrders->whereNotNull('online_message_sent_at')->count(),
                        'pending_count' => $riderOrders->whereNull('online_message_sent_at')->count(),
                        'total_amount' => round($riderOrders->sum('total_price')),
                    ];
                })->values();
                
                $onlineMessageTracking = [
                    'total_online_delivered' => $onlineMessageOrders->count(),
                    'message_sent_count' => $messageSentOrders->count(),
                    'message_pending_count' => $messagePendingOrders->count(),
                    'message_sent_amount' => round($messageSentOrders->sum('total_price')),
                    'message_pending_amount' => round($messagePendingOrders->sum('total_price')),
                    'by_rider' => $onlineMessageByRider,
                ];
            } catch (\Exception $msgEx) {
                \Log::warning('Online message tracking failed in daily closing (non-critical)', [
                    'error' => $msgEx->getMessage()
                ]);
                $onlineMessageTracking = null;
            }

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'invoices_by_rider' => $invoicesByRider,
                'invoices_by_date' => $invoicesByDate, // ⭐ New: date-grouped data for settled view
                'online_summary' => $onlineData, // ⭐ New: online summary
                'online_message_tracking' => $onlineMessageTracking, // ⭐ WhatsApp message tracking (no ledger impact)
                'petrol_requests' => $petrolRequestsData,
                'petrol_payment_accounts' => $petrolPaymentAccounts,
                'pending_settlements' => $pendingSettlements->map(function($settlement) {
                    return [
                        'id' => $settlement->id,
                        'from_account' => $settlement->fromAccount ? $settlement->fromAccount->account_name : 'N/A',
                        'from_account_id' => $settlement->from_account_id,
                        'amount' => $settlement->amount,
                        'created_at' => $settlement->created_at->format('Y-m-d H:i:s'),
                        'description' => $settlement->description,
                        'approval_status' => $settlement->approval_status,
                        'invoice_ids' => $settlement->invoice_ids,
                        'invoices' => $settlement->invoices,
                        'total_outstanding' => $settlement->total_outstanding
                    ];
                })->values(),
                'all_riders' => $allRiders,
                'filters' => [
                    'status' => $statusFilter,
                    'rider' => $riderFilter,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'group_by' => $groupBy, // ⭐ New
                    'include_online' => $includeOnline // ⭐ New
                ]
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Failed to get daily closing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load daily closing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve settlement deposit (Daily Closing)
     * Reuses LedgerController::approveTransaction logic
     */
    public function approveDailyClosingSettlement(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_daily_closing')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to approve settlements'
                ], 403);
            }
            
            // Find the transaction
            $transaction = LedgerModel::findOrFail($id);
            
            // Verify it's a pending settlement
            if ($transaction->transaction_type !== LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is not a settlement deposit transaction'
                ], 400);
            }
            
            if ($transaction->approval_status !== LedgerModel::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'This transaction has already been ' . $transaction->approval_status
                ], 400);
            }
            
            DB::beginTransaction();
            
            // Load accounts
            $transaction->load(['fromAccount', 'toAccount']);
            $fromAccount = $transaction->fromAccount;
            $toAccount = $transaction->toAccount;
            
            // Update transaction status (EXACT web app logic)
            $transaction->approval_status = LedgerModel::STATUS_APPROVED;
            $transaction->approved_by = $user->id;
            $transaction->approval_date = now()->toDateString(); // ← FIXED: Use approval_date (DATE) not approved_at (DATETIME)
            $transaction->save();
            
            // Update account balances (EXACT web app logic)
            // From account: Asset accounts decrease on outflow
            if ($fromAccount->account_type === 'asset') {
                $fromAccount->current_balance -= $transaction->amount;
            } else {
                $fromAccount->current_balance += $transaction->amount;
            }
            $fromAccount->save();
            
            // To account: Asset accounts increase on inflow
            if ($toAccount->account_type === 'asset') {
                $toAccount->current_balance += $transaction->amount;
            } else {
                $toAccount->current_balance -= $transaction->amount;
            }
            $toAccount->save();
            
            // Process settlement metadata if exists (EXACT web app logic)
            if ($transaction->settlement_metadata && isset($transaction->settlement_metadata['invoice_ids'])) {
                \Log::info('Processing invoice settlement via mobile', [
                    'deposit_id' => $transaction->id,
                    'metadata' => $transaction->settlement_metadata
                ]);
                
                $this->processInvoiceSettlementMobile($transaction, $transaction->settlement_metadata);
                
                // If this is a short cash settlement with linked expense request, auto-approve it
                if (isset($transaction->settlement_metadata['is_short_cash_settlement']) && 
                    $transaction->settlement_metadata['is_short_cash_settlement'] && 
                    isset($transaction->settlement_metadata['expense_request_id'])) {
                    
                    $expenseRequestId = $transaction->settlement_metadata['expense_request_id'];
                    
                    \Log::info('Auto-approving linked short cash expense via mobile', [
                        'deposit_id' => $transaction->id,
                        'expense_request_id' => $expenseRequestId
                    ]);
                    
                    // Auto-approve the linked expense request (EXACT web app logic)
                    $expenseRequest = RequestModel::find($expenseRequestId);
                    
                    if ($expenseRequest && $expenseRequest->status === 'pending') {
                        // Process the approval (level, approverId, action, comments)
                        $expenseRequest->processApproval(1, $user->id, 'approved', 'Auto-approved with deposit settlement (mobile)');
                        
                        \Log::info('Short cash expense auto-approved via mobile', [
                            'expense_request_id' => $expenseRequestId,
                            'amount' => $expenseRequest->amount
                        ]);
                    }
                }
            } else {
                \Log::warning('No settlement data found for deposit - invoices will not be auto-settled', [
                    'deposit_id' => $transaction->id,
                    'description' => $transaction->description
                ]);
            }
            
            DB::commit();
            
            \Log::info('Daily closing settlement approved via mobile', [
                'settlement_id' => $id,
                'approved_by' => $user->fullname,
                'amount' => $transaction->amount
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Settlement approved successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Failed to approve daily closing settlement', [
                'settlement_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject settlement deposit (Daily Closing)
     * Reuses LedgerController::rejectTransaction logic
     */
    public function rejectDailyClosingSettlement(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_daily_closing')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to reject settlements'
                ], 403);
            }
            
            // Find the transaction
            $transaction = LedgerModel::findOrFail($id);
            
            // Verify it's a pending settlement
            if ($transaction->transaction_type !== LedgerModel::TYPE_EMPLOYEE_DEPOSIT) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is not a settlement deposit transaction'
                ], 400);
            }
            
            if ($transaction->approval_status !== LedgerModel::STATUS_PENDING) {
                return response()->json([
                    'success' => false,
                    'message' => 'This transaction has already been ' . $transaction->approval_status
                ], 400);
            }
            
            DB::beginTransaction();
            
            // Mark as rejected (EXACT web app logic)
            $transaction->approval_status = LedgerModel::STATUS_REJECTED;
            $transaction->approved_by = $user->id;
            $transaction->approval_date = now()->toDateString(); // ← FIXED: Use approval_date not approved_at
            $transaction->save();
            
            // Note: Account balances are NOT updated when rejecting (per web app logic)
            
            DB::commit();
            
            \Log::info('Daily closing settlement rejected via mobile', [
                'settlement_id' => $id,
                'rejected_by' => $user->fullname,
                'amount' => $transaction->amount
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Settlement rejected successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Failed to reject daily closing settlement', [
                'settlement_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject settlement: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process invoice settlement when deposit is approved (Mobile version)
     * Replicates LedgerController::processInvoiceSettlement logic EXACTLY
     * 
     * @param LedgerModel $depositLedger The approved deposit transaction
     * @param array $settlementData Contains invoice_ids, deposit_amount, total_outstanding
     */
    private function processInvoiceSettlementMobile(LedgerModel $depositLedger, array $settlementData)
    {
        try {
            $invoiceIds = $settlementData['invoice_ids'];
            $depositAmount = $settlementData['deposit_amount'];
            $totalOutstanding = $settlementData['total_outstanding'];
            
            // Check if this is a short cash settlement or partial payment
            $isShortCash = $settlementData['is_short_cash_settlement'] ?? false;
            $isPartialPayment = $settlementData['is_partial_payment'] ?? false;
            $shortCashAmount = $settlementData['short_cash_amount'] ?? 0;
            
            // For short cash, the total amount settling invoices = deposit + expense
            // For partial payment, only the deposit amount is used (remaining stays open)
            if ($isShortCash) {
                $totalSettlementAmount = $depositAmount + $shortCashAmount;
            } else {
                $totalSettlementAmount = $depositAmount;
            }
            
            \Log::info('Processing invoice settlement (mobile)', [
                'deposit_id' => $depositLedger->id,
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment,
                'deposit_amount' => $depositAmount,
                'short_cash_amount' => $shortCashAmount,
                'total_settlement_amount' => $totalSettlementAmount
            ]);
            
            // Get the invoices that need to be settled (in order) - include both open and partial
            $invoices = LedgerModel::whereIn('id', $invoiceIds)
                ->whereIn('settlement_status', ['open', 'partial'])
                ->orderBy('transaction_date', 'asc')
                ->get();
            
            $remainingAmount = $totalSettlementAmount;
            
            foreach ($invoices as $invoice) {
                $outstandingForThisInvoice = $invoice->amount - ($invoice->settled_amount ?? 0);
                
                if ($remainingAmount <= 0) {
                    break; // No more money to allocate
                }
                
                // Calculate how much to settle on this invoice
                $amountToSettle = min($remainingAmount, $outstandingForThisInvoice);
                
                // Update invoice
                $invoice->settled_amount = ($invoice->settled_amount ?? 0) + $amountToSettle;
                
                if ($invoice->settled_amount >= $invoice->amount) {
                    // Fully settled
                    $invoice->settlement_status = 'settled';
                    $invoice->settled_at = now();
                    $invoice->settled_via_ledger_id = $depositLedger->id;
                } else {
                    // Partially settled: keep status 'open' (legacy behavior)
                    // We infer partial by settled_amount > 0
                    // Do NOT write 'partial' to settlement_status - it stays 'open'
                }
                $invoice->save();
                
                // Create audit record (if InvoiceSettlementModel exists)
                if (class_exists('\App\Models\FIN\InvoiceSettlementModel')) {
                    \App\Models\FIN\InvoiceSettlementModel::create([
                        'settlement_deposit_id' => $depositLedger->id,
                        'invoice_ledger_id' => $invoice->id,
                        'settled_amount' => $amountToSettle
                    ]);
                }
                
                $remainingAmount -= $amountToSettle;
            }
            
            \Log::info('Invoice settlement completed (mobile)', [
                'deposit_id' => $depositLedger->id,
                'invoices_count' => $invoices->count(),
                'total_settlement_amount' => $totalSettlementAmount,
                'amount_allocated' => $totalSettlementAmount - $remainingAmount,
                'amount_remaining' => $remainingAmount,
                'is_short_cash' => $isShortCash,
                'is_partial_payment' => $isPartialPayment
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error processing invoice settlement (mobile): ' . $e->getMessage(), [
                'deposit_id' => $depositLedger->id,
                'settlement_data' => $settlementData
            ]);
            throw $e;
        }
    }
    
    /**
     * ⭐ Update delivery priorities for orders (Store Mode only)
     * Used by store managers to set delivery sequence for a rider's out_for_delivery orders
     */
    public function updateDeliveryPriorities(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - must have store mode access
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to update delivery priorities'
                ], 403);
            }
            
            $validated = $request->validate([
                'rider_id' => 'required|integer|exists:t_sys_user,id',
                'priorities' => 'required|array',
                'priorities.*.order_id' => 'required|integer|exists:t_crm_prod_order,id',
                'priorities.*.priority' => 'required|integer|min:1',
            ]);
            
            $riderId = $validated['rider_id'];
            $priorities = $validated['priorities'];

            // Check route lock
            $lock = \DB::table('t_crm_route_lock as rl')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'rl.locked_by')
                ->where('rl.rider_id', $riderId)
                ->select('rl.locked_by', 'u.fullname as locked_by_name', 'rl.locked_at')
                ->first();

            if ($lock && $lock->locked_by != $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => "Route is locked by {$lock->locked_by_name}. Unlock it first before making changes.",
                    'route_locked' => true,
                    'locked_by_name' => $lock->locked_by_name,
                ], 423);
            }
            $updatedCount = 0;
            
            \DB::beginTransaction();
            
            foreach ($priorities as $item) {
                $order = OrderModel::where('id', $item['order_id'])
                    ->where('assigned_rider_user_id', $riderId)
                    ->where('order_status', 'out_for_delivery') // Only out_for_delivery orders
                    ->first();
                
                if ($order) {
                    $order->delivery_priority = $item['priority'];
                    $order->save();
                    $updatedCount++;
                }
            }
            
            \DB::commit();
            
            \Log::info('Delivery priorities updated (Store Mode)', [
                'user_id' => $user->id,
                'rider_id' => $riderId,
                'orders_updated' => $updatedCount,
                'total_priorities' => count($priorities)
            ]);
            
            return response()->json([
                'success' => true,
                'message' => "Updated priorities for {$updatedCount} orders",
                'updated_count' => $updatedCount
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error updating delivery priorities: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update delivery priorities: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Lock route for a rider — prevents other users from changing delivery order
     */
    public function lockRoute(Request $request, $riderId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json(['success' => false, 'message' => 'Permission denied'], 403);
            }

            $existing = \DB::table('t_crm_route_lock')->where('rider_id', $riderId)->first();
            if ($existing) {
                if ($existing->locked_by == $user->id) {
                    return response()->json(['success' => true, 'message' => 'Route already locked by you']);
                }
                $lockerName = \DB::table('t_sys_user')->where('id', $existing->locked_by)->value('fullname');
                return response()->json([
                    'success' => false,
                    'message' => "Route is already locked by {$lockerName}",
                    'locked_by_name' => $lockerName,
                ], 409);
            }

            \DB::table('t_crm_route_lock')->insert([
                'rider_id' => $riderId,
                'locked_by' => $user->id,
                'locked_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Route locked',
                'route_lock' => [
                    'locked_by' => $user->id,
                    'locked_by_name' => $user->fullname,
                    'locked_at' => now()->toIso8601String(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Route lock error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to lock route'], 500);
        }
    }

    /**
     * Unlock route for a rider
     */
    public function unlockRoute(Request $request, $riderId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json(['success' => false, 'message' => 'Permission denied'], 403);
            }

            \DB::table('t_crm_route_lock')->where('rider_id', $riderId)->delete();

            return response()->json(['success' => true, 'message' => 'Route unlocked']);
        } catch (\Exception $e) {
            \Log::error('Route unlock error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to unlock route'], 500);
        }
    }

    /**
     * ⭐ Clear delivery priority when order status changes or rider is reassigned
     * This should be called from OrderModel when status/rider changes
     */
    public static function clearDeliveryPriority($orderId)
    {
        try {
            OrderModel::where('id', $orderId)->update(['delivery_priority' => null]);
        } catch (\Exception $e) {
            \Log::warning('Failed to clear delivery priority for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * ⭐ Get WhatsApp message templates (stored in t_fin_config)
     */
    public function getWhatsappTemplates(Request $request)
    {
        try {
            $templates = \App\Models\FIN\ConfigModel::where('config_key', 'LIKE', 'WHATSAPP_TEMPLATE_%')
                ->get()
                ->mapWithKeys(function ($item) {
                    $key = str_replace('WHATSAPP_TEMPLATE_', '', $item->config_key);
                    return [strtolower($key) => $item->config_value];
                });

            $defaults = [
                'location_request' => 'Dear Customer, Can you please share your google location pin',
            ];

            foreach ($defaults as $key => $defaultValue) {
                if (!$templates->has($key)) {
                    $templates[$key] = $defaultValue;
                }
            }

            return response()->json(['success' => true, 'templates' => $templates]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ⭐ Save/update a WhatsApp message template
     */
    public function saveWhatsappTemplate(Request $request)
    {
        try {
            $request->validate([
                'template_key' => 'required|string|max:100',
                'template_value' => 'required|string|max:2000',
            ]);

            $configKey = 'WHATSAPP_TEMPLATE_' . strtoupper($request->input('template_key'));

            \App\Models\FIN\ConfigModel::updateOrCreate(
                ['config_key' => $configKey],
                [
                    'config_value' => $request->input('template_value'),
                    'description' => 'WhatsApp message template: ' . $request->input('template_key'),
                ]
            );

            \Illuminate\Support\Facades\Cache::forget("fin_config_{$configKey}");

            return response()->json(['success' => true, 'message' => 'Template saved successfully']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // CAMPAIGN MANAGEMENT
    // =========================================================================

    /**
     * List all campaigns (active first, then ended)
     */
    public function getCampaigns(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaigns = DB::table('t_crm_campaigns')
                ->select(
                    't_crm_campaigns.*',
                    DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'pending') as pending_count"),
                    DB::raw("(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = t_crm_campaigns.id AND status = 'skipped') as skipped_count")
                )
                ->orderByRaw("FIELD(status, 'active', 'ended')")
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['success' => true, 'campaigns' => $campaigns]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new campaign and populate its customer list from filters
     */
    public function createCampaign(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $request->validate([
                'name' => 'required|string|max:255',
                'message_template' => 'nullable|string|max:2000',
                'wa_template_name' => 'required|string|max:255',
                'wa_template_language' => 'nullable|string|max:10',
                'filters' => 'required|array',
                'tracking_type' => 'nullable|string|in:general,products',
                'tracked_product_ids' => 'nullable|array',
                'tracking_window_days' => 'nullable|integer|min:1|max:365',
                'dedup_window_days' => 'nullable|integer|min:0|max:365',
            ]);

            $filters = $request->input('filters', []);

            // Delegate all filter logic to the shared service so web + mobile
            // stay in lockstep (multi-group filters, qurbani_year, excludes).
            $filterService = app(\App\Services\CampaignFilterService::class);
            $result = $filterService->buildCustomerIdSet($filters);
            $customerIds = $result['customer_ids'];
            $tagsById = $result['tags_by_id'] ?? [];

            // Template-dedup: customers who already got this template
            // in the last N days are still inserted into the campaign,
            // but as status='skipped' with a distinguishing
            // 'Excluded:' error_message prefix. The detail view has a
            // dedicated Excluded tab for audit; they will NOT be sent
            // to (sendBulk only pulls status='pending').
            $templateName = (string) $request->input('wa_template_name');
            $windowDays   = (int) $request->input('dedup_window_days', 0);
            $alreadySentIds = $filterService->customersRecentlySentTemplate($customerIds, $templateName, $windowDays);
            $alreadySentSet = array_flip($alreadySentIds);
            $excludedByDedup = count($alreadySentIds);
            $excludedReason = ($windowDays > 0 && $templateName !== '')
                ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
                : 'Excluded: recent template send';

            $campaignId = DB::table('t_crm_campaigns')->insertGetId([
                'name' => $request->input('name'),
                'status' => 'active',
                'filters_json' => json_encode($filters),
                'message_template' => $request->input('message_template', ''),
                'wa_template_name' => $templateName,
                'wa_template_language' => $request->input('wa_template_language', 'en'),
                'tracking_type' => $request->input('tracking_type', 'general'),
                'tracked_product_ids' => $request->input('tracked_product_ids') ? json_encode($request->input('tracked_product_ids')) : null,
                'tracking_window_days' => $request->input('tracking_window_days', 30),
                // Persist the operator's dedup window so sendCampaignBulk()
                // and Refresh Dedup can enforce it later. Matches web.
                // 0 = dedup disabled (legacy campaigns pre-migration).
                'dedup_window_days' => $windowDays,
                // Full campaign size (pending + excluded). Matches web.
                'total_customers' => count($customerIds),
                'sent_count' => 0,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (!empty($customerIds)) {
                $hasTagsColumn = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
                $hasErrColumn  = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'error_message');
                // Laravel multi-row insert builds the column list from
                // row 0, so every row in the batch must carry the same
                // keys. Mixing rows that omit 'error_message' with rows
                // that include it triggers MySQL 21S01. Always set it
                // (null when not excluded) if the column exists.
                $rows = array_map(function ($cId) use ($campaignId, $tagsById, $hasTagsColumn, $hasErrColumn, $filterService, $alreadySentSet, $excludedReason) {
                    $isExcluded = isset($alreadySentSet[(int) $cId]);
                    $row = [
                        'campaign_id' => $campaignId,
                        'customer_id' => $cId,
                        'status' => $isExcluded ? 'skipped' : 'pending',
                        'created_at' => now(),
                    ];
                    if ($hasTagsColumn) {
                        $row['match_tags'] = $filterService->matchTagsJson($tagsById, (int) $cId);
                    }
                    if ($hasErrColumn) {
                        $row['error_message'] = $isExcluded ? $excludedReason : null;
                    }
                    return $row;
                }, $customerIds);

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('t_crm_campaign_customers')->insert($chunk);
                }
            }

            return response()->json([
                'success' => true,
                'campaign_id' => $campaignId,
                'total_customers' => count($customerIds),
                'pending_count' => count($customerIds) - $excludedByDedup,
                'excluded_by_dedup' => $excludedByDedup,
                'matched' => count($customerIds),
                'dedup_window_days' => $windowDays,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview: count matching customers for given filters (before creating).
     * When wa_template_name + dedup_window_days are provided, also returns
     * the count of customers that would be excluded because they already
     * got the template in the window + the net queue size.
     */
    public function previewCampaign(Request $request)
    {
        try {
            $filters = $request->input('filters', []);
            $filterService = app(\App\Services\CampaignFilterService::class);
            $result = $filterService->buildCustomerIdSet($filters);

            $template   = trim((string) $request->input('wa_template_name', ''));
            $windowDays = (int) $request->input('dedup_window_days', 0);
            $alreadySentCount = 0;
            if ($template !== '' && $windowDays > 0 && !empty($result['customer_ids'])) {
                $alreadySentCount = count($filterService->customersRecentlySentTemplate(
                    $result['customer_ids'],
                    $template,
                    $windowDays
                ));
            }

            return response()->json([
                'success' => true,
                'count' => count($result['customer_ids']),
                'group_counts' => $result['group_counts'],
                'excluded_count' => $result['excluded_count'],
                'already_sent_count' => $alreadySentCount,
                'net_to_send' => max(0, count($result['customer_ids']) - $alreadySentCount),
                'dedup_window_days' => $windowDays,
                'wa_template_name' => $template ?: null,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get campaign detail with customer list
     */
    public function getCampaignDetail(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
            }

            $statusFilter = $request->get('status', 'pending');

            $customersQuery = DB::table('t_crm_campaign_customers as cc')
                ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
                ->where('cc.campaign_id', $id)
                ->select(
                    'cc.id as campaign_customer_id',
                    'cc.customer_id',
                    'cc.status as campaign_status',
                    'cc.sent_at',
                    'cc.sent_by',
                    'cc.error_message',
                    'c.first_name',
                    'c.last_name',
                    'c.phone',
                    'c.phone_normalized',
                    'c.city',
                    'c.total_orders',
                    'c.total_spent',
                    'c.last_order_date'
                );

            // Derived Shopify-customer flag for the mobile badge. Same
            // access pattern as the qurbani_year EXISTS already used in
            // CampaignFilterService — hits the customer_id index and
            // short-circuits. One boolean per returned row, no extra
            // burden on list loads.
            $filterService = app(\App\Services\CampaignFilterService::class);
            $customersQuery->selectRaw('(' . $filterService->shopifyExistsExpr('c') . ') as is_shopify');

            // New columns are optional so mobile builds against older DBs still work.
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'replied_at')) {
                $customersQuery->addSelect('cc.replied_at');
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'match_tags')) {
                $customersQuery->addSelect('cc.match_tags');
            }

            // "skipped" vs "excluded" both live under status='skipped' at
            // the DB level — we split them via the 'Excluded:'
            // error_message prefix set at insert time. Keeps parity with
            // the web detail() endpoint and avoids an ENUM migration.
            if ($statusFilter === 'excluded') {
                $customersQuery
                    ->where('cc.status', 'skipped')
                    ->where('cc.error_message', 'LIKE', 'Excluded:%');
            } elseif ($statusFilter === 'skipped') {
                $customersQuery
                    ->where('cc.status', 'skipped')
                    ->where(function ($q) {
                        $q->whereNull('cc.error_message')
                          ->orWhere('cc.error_message', 'NOT LIKE', 'Excluded:%');
                    });
            } elseif ($statusFilter !== 'all') {
                $customersQuery->where('cc.status', $statusFilter);
            }

            $customersQuery->orderByRaw("FIELD(cc.status, 'pending', 'failed', 'sent', 'skipped')");

            $sortBy = json_decode($campaign->filters_json, true)['sort_by'] ?? 'last_order_date';
            $sortDir = json_decode($campaign->filters_json, true)['sort_dir'] ?? 'desc';
            if (in_array($sortBy, ['last_order_date', 'total_spent', 'created_at'])) {
                $customersQuery->orderBy("c.{$sortBy}", $sortDir);
            }

            $customers = $customersQuery->get();

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            return response()->json([
                'success' => true,
                'campaign' => $campaign,
                'customers' => $customers,
                'counts' => $counts,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a campaign customer as sent
     */
    public function markCampaignCustomerSent(Request $request, $campaignId, $customerId)
    {
        try {
            $user = Auth::user();

            $updated = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $campaignId)
                ->where('customer_id', $customerId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'sent_by' => $user->id,
                ]);

            if ($updated) {
                DB::table('t_crm_campaigns')
                    ->where('id', $campaignId)
                    ->increment('sent_count');
            }

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $campaignId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            return response()->json(['success' => true, 'counts' => $counts]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a campaign customer as skipped
     */
    public function markCampaignCustomerSkipped(Request $request, $campaignId, $customerId)
    {
        try {
            DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $campaignId)
                ->where('customer_id', $customerId)
                ->where('status', 'pending')
                ->update(['status' => 'skipped']);

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $campaignId)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            return response()->json(['success' => true, 'counts' => $counts]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Send campaign template to one or more customers via WhatsApp Business API
     */
    public function sendCampaignBulk(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->where('status', 'active')->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found or ended'], 404);
            }

            $templateName = $campaign->wa_template_name;
            if (!$templateName) {
                return response()->json(['success' => false, 'message' => 'No WhatsApp template configured for this campaign'], 422);
            }

            $request->validate([
                'customer_ids' => 'required|array|min:1',
                'customer_ids.*' => 'integer',
                'body_params' => 'nullable|array',
                'include_failed' => 'nullable|boolean',
            ]);

            $customerIds = $request->input('customer_ids');
            $language = $campaign->wa_template_language ?: 'en';
            // Retry flow: when include_failed=true we also send to rows currently in `failed`.
            $includeFailed = (bool) $request->input('include_failed', false);
            $eligibleStatuses = $includeFailed ? ['pending', 'failed'] : ['pending'];

            // Expected variable_count of the template — used to size body_params correctly.
            // Without this, a 0-variable template (e.g. pure marketing copy) will be rejected
            // by Meta with error 132000 when the caller sends any placeholder.
            $templateMeta = DB::table('t_wa_templates')->where('name', $templateName)->first();
            $expectedVarCount = $templateMeta ? (int) $templateMeta->variable_count : null;

            $customers = DB::table('t_crm_campaign_customers as cc')
                ->join('t_crm_prod_customer as c', 'cc.customer_id', '=', 'c.id')
                ->where('cc.campaign_id', $id)
                ->whereIn('cc.status', $eligibleStatuses)
                ->whereIn('cc.customer_id', $customerIds)
                ->select('cc.customer_id', 'c.first_name', 'c.last_name', 'c.phone', 'c.phone_normalized')
                ->get();

            if ($customers->isEmpty()) {
                $label = $includeFailed ? 'pending or failed' : 'pending';
                return response()->json(['success' => false, 'message' => "No {$label} customers found for the given IDs"], 422);
            }

            // Send-time dedup guard — mirrors CampaignWebController::sendBulk().
            // Catches pending customers who received the template via a
            // newer campaign since this one was created. One batch-level
            // lookup against the shared CampaignFilterService keeps web
            // + mobile in lockstep on what "already received" means.
            $dedupWindow = (int) ($campaign->dedup_window_days ?? 0);
            $sendTimeExcluded = 0;
            $filterService = app(\App\Services\CampaignFilterService::class);
            if ($dedupWindow > 0 && $templateName !== '') {
                $batchIds = $customers->pluck('customer_id')->map(fn($v) => (int) $v)->all();
                $nowExcludedIds = $filterService->customersRecentlySentTemplate(
                    $batchIds,
                    $templateName,
                    $dedupWindow
                );
                if (!empty($nowExcludedIds)) {
                    $excludedSet = array_flip($nowExcludedIds);
                    $reason = 'Excluded: already received template "' . $templateName . '" in last '
                        . $dedupWindow . ' day' . ($dedupWindow === 1 ? '' : 's');

                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)
                        ->whereIn('customer_id', $nowExcludedIds)
                        ->whereIn('status', $eligibleStatuses)
                        ->update(['status' => 'skipped', 'error_message' => $reason, 'sent_at' => null]);

                    $customers = $customers->reject(fn($c) => isset($excludedSet[(int) $c->customer_id]))->values();
                    $sendTimeExcluded = count($nowExcludedIds);

                    \Illuminate\Support\Facades\Log::info('Campaign bulk send (mobile): dedup guard auto-excluded', [
                        'campaign_id' => $id,
                        'template' => $templateName,
                        'window_days' => $dedupWindow,
                        'excluded' => $sendTimeExcluded,
                    ]);
                }
            }

            if ($customers->isEmpty()) {
                // Whole batch was auto-excluded by the guard — return
                // success with the excluded count so the app can show a
                // "nothing sent, N moved to Excluded" toast.
                $counts = DB::table('t_crm_campaign_customers')
                    ->where('campaign_id', $id)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                        SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                        SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                    ")
                    ->first();
                return response()->json([
                    'success' => true,
                    'results' => ['sent' => 0, 'failed' => 0, 'excluded' => $sendTimeExcluded, 'errors' => []],
                    'counts' => $counts,
                ]);
            }

            $whatsapp = app(\App\Services\WhatsAppService::class);
            $results = ['sent' => 0, 'failed' => 0, 'excluded' => $sendTimeExcluded, 'errors' => []];

            // Pace outbound WhatsApp API calls to stay within Meta's per-second
            // template throughput and reduce the chance of 131056 rate-limit
            // errors on large retries. Mirrors the web sendBulk behaviour.
            $total = $customers->count();
            $paceMicros = $total > 1 ? 200_000 : 0;
            \Illuminate\Support\Facades\Log::info('Campaign bulk send (mobile): starting batch', [
                'campaign_id'    => $id,
                'template'       => $templateName,
                'customer_count' => $total,
                'include_failed' => $includeFailed,
                'user_id'        => $user->id,
            ]);

            foreach ($customers as $index => $customer) {
                if ($paceMicros && $index > 0) { usleep($paceMicros); }

                $phone = $customer->phone_normalized ?: $customer->phone;
                if (!$phone) {
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)
                        ->where('customer_id', $customer->customer_id)
                        ->update(['status' => 'failed', 'error_message' => 'No phone number']);
                    $results['failed']++;
                    continue;
                }

                try {
                    $formattedPhone = $whatsapp->formatPhone($phone);
                    $customerName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

                    $bodyParams = $request->input('body_params', []);
                    $resolvedParams = array_map(function ($p) use ($customerName) {
                        return $p === '{{customer_name}}' ? $customerName : $p;
                    }, $bodyParams);

                    // Resize to match the template's actual variable_count so we never
                    // over-send (error 132000) or under-send placeholders.
                    if ($expectedVarCount !== null) {
                        if (count($resolvedParams) > $expectedVarCount) {
                            $resolvedParams = array_slice($resolvedParams, 0, $expectedVarCount);
                        }
                        while (count($resolvedParams) < $expectedVarCount) {
                            $resolvedParams[] = $customerName;
                        }
                    }

                    $response = $whatsapp->sendTemplateMessage($formattedPhone, $templateName, $language, $resolvedParams);

                    if ($response['success'] ?? false) {
                        DB::table('t_crm_campaign_customers')
                            ->where('campaign_id', $id)
                            ->where('customer_id', $customer->customer_id)
                            ->update([
                                'status' => 'sent',
                                'sent_at' => now(),
                                'sent_by' => $user->id,
                                'error_message' => null,
                            ]);
                        DB::table('t_crm_campaigns')->where('id', $id)->increment('sent_count');
                        $results['sent']++;

                        $conversation = $whatsapp->findOrCreateConversation($formattedPhone);
                        if (!$conversation->customer_id) {
                            $conversation->update(['customer_id' => $customer->customer_id]);
                        }
                        $whatsapp->saveOutboundMessage(
                            $conversation->id,
                            $response,
                            'template',
                            "Campaign: {$campaign->name}",
                            $user->id,
                            $templateName,
                            $resolvedParams
                        );
                    } else {
                        $error = $response['error'] ?? 'API send failed';
                        DB::table('t_crm_campaign_customers')
                            ->where('campaign_id', $id)
                            ->where('customer_id', $customer->customer_id)
                            ->update(['status' => 'failed', 'error_message' => mb_substr($error, 0, 500)]);
                        $results['failed']++;
                        $results['errors'][] = ['customer_id' => $customer->customer_id, 'phone' => $phone, 'error' => $error];
                    }
                } catch (\Exception $e) {
                    $error = $e->getMessage();
                    DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)
                        ->where('customer_id', $customer->customer_id)
                        ->update(['status' => 'failed', 'error_message' => mb_substr($error, 0, 500)]);
                    $results['failed']++;
                    $results['errors'][] = ['customer_id' => $customer->customer_id, 'phone' => $phone, 'error' => $error];
                }
            }

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status = 'skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            \Illuminate\Support\Facades\Log::info('Campaign bulk send (mobile): batch finished', [
                'campaign_id' => $id,
                'sent'        => $results['sent'],
                'failed'      => $results['failed'],
            ]);

            return response()->json([
                'success' => true,
                'results' => $results,
                'counts' => $counts,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Campaign bulk send failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Refresh campaign: re-run filters and add NEW customers not already in the list
     */
    public function refreshCampaign(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->where('status', 'active')->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found or ended'], 404);
            }

            $filters = json_decode($campaign->filters_json, true) ?: [];

            // Use shared service so new multi-group + qurbani_year + excludes
            // filter shapes are honoured. Legacy single-filter campaigns still
            // work thanks to CampaignFilterService::extractGroups.
            $filterService = app(\App\Services\CampaignFilterService::class);
            $result = $filterService->buildCustomerIdSet($filters);
            $allMatchingIds = $result['customer_ids'];
            $tagsById = $result['tags_by_id'] ?? [];

            $existingIds = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->pluck('customer_id')
                ->toArray();

            $newIds = array_values(array_diff($allMatchingIds, $existingIds));

            if (!empty($newIds)) {
                $hasTagsColumn = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
                $rows = array_map(function ($cId) use ($id, $tagsById, $hasTagsColumn, $filterService) {
                    $row = [
                        'campaign_id' => $id,
                        'customer_id' => $cId,
                        'status' => 'pending',
                        'created_at' => now(),
                    ];
                    if ($hasTagsColumn) {
                        $row['match_tags'] = $filterService->matchTagsJson($tagsById, (int) $cId);
                    }
                    return $row;
                }, $newIds);

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('t_crm_campaign_customers')->insert($chunk);
                }
            }

            $newTotal = DB::table('t_crm_campaign_customers')->where('campaign_id', $id)->count();
            DB::table('t_crm_campaigns')->where('id', $id)->update([
                'total_customers' => $newTotal,
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'new_customers_added' => count($newIds),
                'total_customers' => $newTotal,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Add more customers to an existing active campaign from a filter payload.
     * Mirrors CampaignWebController::addCustomers for parity with the web UI.
     */
    public function addCampaignCustomers(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
            }
            if ($campaign->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Cannot add customers to an ended campaign'], 422);
            }

            $request->validate([
                'filters' => 'required|array',
                'dedup_window_days' => 'nullable|integer|min:0|max:365',
            ]);
            $filters = $request->input('filters', []);

            $filterService = app(\App\Services\CampaignFilterService::class);
            $result = $filterService->buildCustomerIdSet($filters);
            $candidateIds = $result['customer_ids'];
            $tagsById = $result['tags_by_id'] ?? [];

            if (empty($candidateIds)) {
                return response()->json([
                    'success' => true,
                    'added' => 0,
                    'already_in_campaign' => 0,
                    'matched' => 0,
                    'excluded_count' => $result['excluded_count'],
                    'group_counts' => $result['group_counts'],
                    'excluded_by_dedup' => 0,
                    'dedup_window_days' => (int) $request->input('dedup_window_days', 0),
                ]);
            }

            $existingIds = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->pluck('customer_id')
                ->map(fn($v) => (int)$v)
                ->all();
            $existingSet = array_flip($existingIds);

            $toInsert = [];
            foreach ($candidateIds as $cId) {
                if (!isset($existingSet[$cId])) $toInsert[] = $cId;
            }

            // Template-dedup — candidates who already received this
            // campaign's template in the window are inserted as
            // status='skipped' with the 'Excluded:' prefix so they
            // land in the Excluded tab, not Pending or Skipped.
            $windowDays = (int) $request->input('dedup_window_days', 0);
            $templateName = (string) ($campaign->wa_template_name ?: '');
            $alreadySentIds = $filterService->customersRecentlySentTemplate($toInsert, $templateName, $windowDays);
            $alreadySentSet = array_flip($alreadySentIds);
            $excludedByDedup = count($alreadySentIds);
            $excludedReason = ($windowDays > 0 && $templateName !== '')
                ? 'Excluded: already received template "' . $templateName . '" in last ' . $windowDays . ' day' . ($windowDays === 1 ? '' : 's')
                : 'Excluded: recent template send';

            if (!empty($toInsert)) {
                $hasTagsColumn = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'match_tags');
                $hasErrColumn  = \Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'error_message');
                // Same row-shape rule as createCampaign() — always
                // include error_message when the column exists, null
                // for non-excluded rows. Otherwise Laravel's batch
                // insert throws 21S01 at the first excluded row.
                $rows = array_map(function ($cId) use ($id, $tagsById, $hasTagsColumn, $hasErrColumn, $filterService, $alreadySentSet, $excludedReason) {
                    $isExcluded = isset($alreadySentSet[(int) $cId]);
                    $row = [
                        'campaign_id' => (int) $id,
                        'customer_id' => $cId,
                        'status' => $isExcluded ? 'skipped' : 'pending',
                        'created_at' => now(),
                    ];
                    if ($hasTagsColumn) {
                        $row['match_tags'] = $filterService->matchTagsJson($tagsById, (int) $cId);
                    }
                    if ($hasErrColumn) {
                        $row['error_message'] = $isExcluded ? $excludedReason : null;
                    }
                    return $row;
                }, $toInsert);

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('t_crm_campaign_customers')->insert($chunk);
                }

                DB::table('t_crm_campaigns')
                    ->where('id', $id)
                    ->increment('total_customers', count($toInsert), ['updated_at' => now()]);
            }

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            return response()->json([
                'success' => true,
                'added' => count($toInsert),
                'added_pending' => count($toInsert) - $excludedByDedup,
                'already_in_campaign' => count($candidateIds) - count($toInsert),
                'matched' => count($candidateIds),
                'excluded_count' => $result['excluded_count'],
                'group_counts' => $result['group_counts'],
                'counts' => $counts,
                'excluded_by_dedup' => $excludedByDedup,
                'dedup_window_days' => $windowDays,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Refresh template-dedup for an existing campaign (mobile parity with
     * CampaignWebController::refreshDedup). Uses the campaign's stored
     * dedup_window_days + wa_template_name — no user inputs beyond the
     * campaign id — so the behaviour is identical whether the operator
     * hits refresh from the web or mobile UI.
     */
    public function refreshCampaignDedup(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
            }

            $templateName = (string) ($campaign->wa_template_name ?? '');
            $windowDays   = (int) ($campaign->dedup_window_days ?? 0);

            if ($windowDays <= 0 || $templateName === '') {
                return response()->json([
                    'success' => true,
                    'moved'   => 0,
                    'message' => 'Dedup is not enabled for this campaign (window or template not set).',
                    'dedup_window_days' => $windowDays,
                    'wa_template_name'  => $templateName ?: null,
                ]);
            }

            $pendingIds = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->where('status', 'pending')
                ->pluck('customer_id')
                ->map(fn($v) => (int) $v)
                ->all();

            $filterService = app(\App\Services\CampaignFilterService::class);
            $moved = 0;
            if (!empty($pendingIds)) {
                $nowExcludedIds = $filterService->customersRecentlySentTemplate(
                    $pendingIds,
                    $templateName,
                    $windowDays
                );

                if (!empty($nowExcludedIds)) {
                    $reason = 'Excluded: already received template "' . $templateName . '" in last '
                        . $windowDays . ' day' . ($windowDays === 1 ? '' : 's');

                    $moved = DB::table('t_crm_campaign_customers')
                        ->where('campaign_id', $id)
                        ->whereIn('customer_id', $nowExcludedIds)
                        ->where('status', 'pending')
                        ->update(['status' => 'skipped', 'error_message' => $reason]);
                }
            }

            $counts = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status='skipped' AND (error_message IS NULL OR error_message NOT LIKE 'Excluded:%') THEN 1 ELSE 0 END) as skipped,
                    SUM(CASE WHEN status='skipped' AND error_message LIKE 'Excluded:%' THEN 1 ELSE 0 END) as excluded
                ")
                ->first();

            \Illuminate\Support\Facades\Log::info('Campaign dedup refresh (mobile)', [
                'campaign_id' => $id,
                'template'    => $templateName,
                'window_days' => $windowDays,
                'pending_scanned' => count($pendingIds),
                'moved_to_excluded' => $moved,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'moved'   => $moved,
                'pending_scanned' => count($pendingIds),
                'dedup_window_days' => $windowDays,
                'wa_template_name'  => $templateName,
                'counts' => $counts,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Campaign dedup refresh failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * End a campaign
     */
    public function endCampaign(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            DB::table('t_crm_campaigns')
                ->where('id', $id)
                ->update([
                    'status' => 'ended',
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => true, 'message' => 'Campaign ended']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get campaign conversion stats
     */
    public function getCampaignStats(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('view_campaigns')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            $campaign = DB::table('t_crm_campaigns')->where('id', $id)->first();
            if (!$campaign) {
                return response()->json(['success' => false, 'message' => 'Campaign not found'], 404);
            }

            $trackingDays = $campaign->tracking_window_days ?: 30;
            $trackingType = $campaign->tracking_type ?: 'general';
            $trackedProducts = $campaign->tracked_product_ids ? json_decode($campaign->tracked_product_ids, true) : [];

            $sentCustomers = DB::table('t_crm_campaign_customers')
                ->where('campaign_id', $id)
                ->where('status', 'sent')
                ->get();

            $totalSent = $sentCustomers->count();
            $customersWhoOrdered = 0;
            $customersWhoReplied = 0;
            $totalOrders = 0;
            $totalRevenue = 0;
            $customerDetails = [];
            $productBreakdown = [];

            foreach ($sentCustomers as $cc) {
                $ordersQuery = DB::table('t_crm_prod_order')
                    ->where('customer_id', $cc->customer_id)
                    ->where('order_date', '>', $cc->sent_at)
                    ->where('order_date', '<=', Carbon::parse($cc->sent_at)->addDays($trackingDays))
                    ->whereIn('order_status', ['delivered', 'completed']);

                if ($trackingType === 'products' && !empty($trackedProducts)) {
                    $ordersQuery->whereExists(function ($q) use ($trackedProducts) {
                        $q->select(DB::raw(1))
                          ->from('t_crm_prod_order_line_item')
                          ->whereColumn('t_crm_prod_order_line_item.order_id', 't_crm_prod_order.id')
                          ->whereIn('t_crm_prod_order_line_item.product_id', $trackedProducts);
                    });
                }

                $orders = $ordersQuery->get();
                $orderCount = $orders->count();
                $revenue = $orders->sum('total_price');

                if ($orderCount > 0) {
                    $customersWhoOrdered++;
                    $totalOrders += $orderCount;
                    $totalRevenue += $revenue;
                }

                $customer = DB::table('t_crm_prod_customer')
                    ->where('id', $cc->customer_id)
                    ->select('first_name', 'last_name', 'phone_normalized')
                    ->first();

                $replied = !empty($cc->replied_at ?? null);
                if ($replied) $customersWhoReplied++;

                $customerDetails[] = [
                    'customer_id' => $cc->customer_id,
                    'name' => $customer ? trim($customer->first_name . ' ' . $customer->last_name) : 'Unknown',
                    'phone' => $customer->phone_normalized ?? '',
                    'sent_at' => $cc->sent_at,
                    'ordered' => $orderCount > 0,
                    'order_count' => $orderCount,
                    'revenue' => $revenue,
                    'replied' => $replied,
                    'replied_at' => $cc->replied_at ?? null,
                ];

                if ($trackingType === 'products' && !empty($trackedProducts) && $orderCount > 0) {
                    $orderIds = $orders->pluck('id')->toArray();
                    if (!empty($orderIds)) {
                        $lineItems = DB::table('t_crm_prod_order_line_item as li')
                            ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                            ->whereIn('li.order_id', $orderIds)
                            ->whereIn('li.product_id', $trackedProducts)
                            ->select('li.product_id', 'p.title as product_name', DB::raw('SUM(li.quantity) as total_qty'), DB::raw('SUM(li.price * li.quantity) as total_value'))
                            ->groupBy('li.product_id', 'p.title')
                            ->get();

                        foreach ($lineItems as $item) {
                            $key = $item->product_id;
                            if (!isset($productBreakdown[$key])) {
                                $productBreakdown[$key] = [
                                    'product_id' => $item->product_id,
                                    'product_name' => $item->product_name,
                                    'total_qty' => 0,
                                    'total_value' => 0,
                                ];
                            }
                            $productBreakdown[$key]['total_qty'] += $item->total_qty;
                            $productBreakdown[$key]['total_value'] += $item->total_value;
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_sent' => $totalSent,
                    'customers_who_ordered' => $customersWhoOrdered,
                    'conversion_rate' => $totalSent > 0 ? round(($customersWhoOrdered / $totalSent) * 100, 1) : 0,
                    'customers_who_replied' => $customersWhoReplied,
                    'reply_rate' => $totalSent > 0 ? round(($customersWhoReplied / $totalSent) * 100, 1) : 0,
                    'total_orders' => $totalOrders,
                    'total_revenue' => round($totalRevenue, 2),
                    'tracking_type' => $trackingType,
                    'tracking_window_days' => $trackingDays,
                    'product_breakdown' => array_values($productBreakdown),
                    'customer_details' => $customerDetails,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get distinct Qurbani years for campaign year filter (mobile).
     * Same definition as CampaignWebController::getQurbaniYears / Qurbani dashboard.
     */
    public function getCampaignQurbaniYears(Request $request)
    {
        try {
            $years = collect();
            $current = DB::table('t_crm_prod_order as o')
                ->where(function ($q) {
                    $q->whereNotNull('o.qurbani_day')
                      ->orWhereExists(function ($sub) {
                          $sub->select(DB::raw(1))
                              ->from('t_crm_prod_order_line_item as li')
                              ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                              ->whereColumn('li.order_id', 'o.id')
                              ->whereRaw("LOWER(p.attribute_1) = 'qurbani'");
                      });
                })
                ->select(DB::raw('DISTINCT YEAR(o.order_date) as year'))
                ->pluck('year');
            $years = $years->merge($current);

            if (\Illuminate\Support\Facades\Schema::hasTable('t_crm_history_order') && \Illuminate\Support\Facades\Schema::hasTable('t_crm_history_order_line_item')) {
                $hist = DB::table('t_crm_history_order as ho')
                    ->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('t_crm_history_order_line_item as hli')
                            ->whereColumn('hli.order_id', 'ho.id')
                            ->where(function ($q) {
                                $q->whereRaw("LOWER(hli.name) LIKE '%qurbani%'")
                                  ->orWhereRaw("LOWER(hli.name) LIKE '%hissa%'")
                                  ->orWhereRaw("LOWER(COALESCE(hli.sku,'')) LIKE 'qur%'");
                            });
                    })
                    ->select(DB::raw('DISTINCT YEAR(ho.order_date) as year'))
                    ->pluck('year');
                $years = $years->merge($hist);
            }

            $years = $years->filter()->unique()->sortDesc()->values();
            return response()->json(['success' => true, 'years' => $years]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get available cities for campaign filter dropdown
     */
    public function getCampaignCities(Request $request)
    {
        try {
            $cities = DB::table('t_crm_prod_customer')
                ->whereNull('merged_into_customer_id')
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->select('city', DB::raw('COUNT(*) as count'))
                ->groupBy('city')
                ->orderBy('count', 'desc')
                ->limit(50)
                ->get();

            return response()->json(['success' => true, 'cities' => $cities]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * ⭐ Get delivered orders for Store Mode - grouped by DELIVERY DATE
     * 
     * Delivery date is determined from t_crm_order_status_history.changed_at
     * where status_code = 'delivered'
     * 
     * Returns orders grouped by delivery date for the last 60 days (2 months)
     * Includes: line items (products), delivery location (rider's GPS when delivered)
     */
    public function getStoreDeliveredOrders(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - same as view_open_orders (store mode access)
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view delivered orders'
                ], 403);
            }
            
            // Get date range (default: last 60 days / 2 months for performance)
            $daysBack = $request->get('days', 60);
            $startDate = now()->subDays($daysBack)->format('Y-m-d');
            
            // ⭐ FIXED: Use subquery to get only the FIRST 'delivered' status entry per order
            // This prevents duplicates when an order is marked delivered multiple times
            $deliveredSubquery = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MIN(id) as first_delivered_id'))
                ->where('status_code', 'delivered')
                ->where('changed_at', '>=', $startDate)
                ->groupBy('order_id');
            
            // Get all delivered orders with delivery date and location from status history
            $orders = \DB::table('t_crm_prod_order as o')
                ->joinSub($deliveredSubquery, 'first_osh', function($join) {
                    $join->on('o.id', '=', 'first_osh.order_id');
                })
                ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'first_osh.first_delivered_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.total_price',
                    'o.payment_method',
                    'o.assigned_rider_user_id as rider_id',
                    'o.customer_id',
                    'o.address_line1',
                    'o.address_line2',
                    'o.address_city',
                    'o.address_phone',
                    'o.order_date',
                    'o.expected_packets',
                    'o.actual_packets',
                    // ⭐ Estimated delivery time (from "Get Times" button)
                    'o.estimated_delivery_at',
                    'u.fullname as rider_name',
                    'osh.changed_at as delivered_at',
                    \DB::raw('DATE(osh.changed_at) as delivery_date'),
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'c.phone_original as customer_phone_from_customer',
                    // ⭐ Delivery location (GPS captured when rider marked delivered)
                    'osh.delivery_latitude',
                    'osh.delivery_longitude',
                    // ⭐ Customer verified location (manually set - high accuracy)
                    'c.latitude as verified_lat',
                    'c.longitude as verified_lng',
                    'c.verified_location_url',
                    // ⭐ Customer geocoded location (from address - approximate)
                    'c.geocoded_latitude as geocoded_lat',
                    'c.geocoded_longitude as geocoded_lng',
                ])
                ->orderBy('osh.changed_at', 'desc')
                ->get();
            
            // Get order IDs for batch fetching line items
            $orderIds = $orders->pluck('id')->toArray();
            
            // ⭐ Batch fetch line items for all orders (avoid N+1 queries)
            $lineItems = \DB::table('t_crm_prod_order_line_item')
                ->whereIn('order_id', $orderIds)
                ->select(['order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'is_free'])
                ->get()
                ->groupBy('order_id');
            
            // Group by delivery date
            $dateGroups = [];
            foreach ($orders as $order) {
                $dateKey = $order->delivery_date;
                
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = [
                        'date' => $dateKey,
                        'date_display' => \Carbon\Carbon::parse($dateKey)->format('D, M j, Y'),
                        'is_today' => $dateKey === now()->format('Y-m-d'),
                        'cash_count' => 0,
                        'cash_total' => 0,
                        'online_count' => 0,
                        'online_total' => 0,
                        'total_delivered' => 0,
                        'total_amount' => 0,
                        'orders' => [],
                    ];
                }
                
                $paymentMethod = strtolower($order->payment_method ?? 'cash');
                $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                $amount = (float)$order->total_price;
                
                if ($isCash) {
                    $dateGroups[$dateKey]['cash_count']++;
                    $dateGroups[$dateKey]['cash_total'] += $amount;
                } else {
                    $dateGroups[$dateKey]['online_count']++;
                    $dateGroups[$dateKey]['online_total'] += $amount;
                }
                
                $dateGroups[$dateKey]['total_delivered']++;
                $dateGroups[$dateKey]['total_amount'] += $amount;
                
                // Build customer address
                $customerAddress = trim(implode(', ', array_filter([
                    $order->address_line1,
                    $order->address_line2,
                    $order->address_city,
                ])));
                
                // ⭐ Build delivery location object (GPS when rider marked delivered)
                $deliveryLocation = null;
                if ($order->delivery_latitude && $order->delivery_longitude) {
                    $deliveryLocation = [
                        'latitude' => (float)$order->delivery_latitude,
                        'longitude' => (float)$order->delivery_longitude,
                        'google_maps_url' => "https://www.google.com/maps?q={$order->delivery_latitude},{$order->delivery_longitude}",
                    ];
                }
                
                // ⭐ Build verified location object (customer's saved location)
                $verifiedLocation = null;
                $hasVerifiedLocation = false;
                
                // Check for verified location - use stored lat/lng columns only
                // ⭐ NOTE: Do NOT resolve URLs here - it makes HTTP requests and causes timeouts!
                // URL resolution should only happen when viewing/editing a single order
                $verifiedLat = $order->verified_lat ? (float)$order->verified_lat : null;
                $verifiedLng = $order->verified_lng ? (float)$order->verified_lng : null;
                
                if ($verifiedLat && $verifiedLng) {
                    $hasVerifiedLocation = true;
                    $verifiedLocation = [
                        'latitude' => $verifiedLat,
                        'longitude' => $verifiedLng,
                        'google_maps_url' => $order->verified_location_url ?: "https://www.google.com/maps?q={$verifiedLat},{$verifiedLng}",
                    ];
                }
                
                // ⭐ Build geocoded location object (from address - approximate)
                $geocodedLocation = null;
                if ($order->geocoded_lat && $order->geocoded_lng) {
                    $geocodedLocation = [
                        'latitude' => (float)$order->geocoded_lat,
                        'longitude' => (float)$order->geocoded_lng,
                        'google_maps_url' => "https://www.google.com/maps?q={$order->geocoded_lat},{$order->geocoded_lng}",
                    ];
                }
                
                // ⭐ Calculate location verification
                $locationVerification = null;
                if ($deliveryLocation) {
                    // Check against verified location first (higher priority)
                    if ($verifiedLocation) {
                        $distance = $this->haversineDistance(
                            $deliveryLocation['latitude'], $deliveryLocation['longitude'],
                            $verifiedLocation['latitude'], $verifiedLocation['longitude']
                        );
                        $distanceKm = round($distance / 1000, 2);
                        $isClose = $distanceKm <= 1.0; // Within 1km
                        
                        $locationVerification = [
                            'type' => 'verified',
                            'is_match' => $isClose,
                            'distance_km' => $distanceKm,
                            'distance_display' => $distanceKm < 1 ? round($distance) . 'm' : $distanceKm . 'km',
                            'expected_location' => $verifiedLocation,
                            'status' => $isClose ? 'verified_match' : 'verified_mismatch',
                            'status_text' => $isClose ? '✓ Delivered at verified location' : '⚠ Delivered ' . ($distanceKm < 1 ? round($distance) . 'm' : $distanceKm . 'km') . ' from verified',
                        ];
                    }
                    // If no verified, check against geocoded
                    elseif ($geocodedLocation) {
                        $distance = $this->haversineDistance(
                            $deliveryLocation['latitude'], $deliveryLocation['longitude'],
                            $geocodedLocation['latitude'], $geocodedLocation['longitude']
                        );
                        $distanceKm = round($distance / 1000, 2);
                        $isClose = $distanceKm <= 1.0;
                        
                        $locationVerification = [
                            'type' => 'geocoded',
                            'is_match' => $isClose,
                            'distance_km' => $distanceKm,
                            'distance_display' => $distanceKm < 1 ? round($distance) . 'm' : $distanceKm . 'km',
                            'expected_location' => $geocodedLocation,
                            'status' => $isClose ? 'geocoded_match' : 'geocoded_mismatch',
                            'status_text' => $isClose ? '📍 Matches address location' : '📍 ' . ($distanceKm < 1 ? round($distance) . 'm' : $distanceKm . 'km') . ' from address',
                            'can_set_verified' => $isClose, // Allow setting as verified if delivery matches geocoded
                        ];
                    }
                    // No expected location available
                    else {
                        $locationVerification = [
                            'type' => 'none',
                            'is_match' => false,
                            'status' => 'no_expected',
                            'status_text' => '📍 No saved location',
                            'can_set_verified' => true, // Can set this delivery location as verified
                        ];
                    }
                } else {
                    // No delivery GPS captured
                    $locationVerification = [
                        'type' => 'no_delivery_gps',
                        'is_match' => false,
                        'status' => 'no_gps',
                        'status_text' => '❌ No delivery GPS',
                        'has_verified' => $hasVerifiedLocation,
                        'has_geocoded' => $geocodedLocation !== null,
                    ];
                }
                
                // ⭐ Get line items (products) for this order
                $orderLineItems = $lineItems->get($order->id, collect())->map(function($item) {
                    return [
                        'name' => $item->name ?? 'Product',
                        'sku' => $item->sku,
                        'quantity' => (int)$item->quantity,
                        'unit_price' => (float)$item->unit_price,
                        'line_total' => (float)$item->line_total,
                        'is_free' => (bool) $item->is_free,
                    ];
                })->values()->toArray();
                
                // ⭐ Calculate ETA comparison if both estimated and actual times exist
                $etaComparison = null;
                if ($order->estimated_delivery_at && $order->delivered_at) {
                    $estimatedTime = \Carbon\Carbon::parse($order->estimated_delivery_at);
                    $actualTime = \Carbon\Carbon::parse($order->delivered_at);
                    $diffMinutes = (int) round($actualTime->diffInMinutes($estimatedTime, false)); // negative if late
                    
                    $etaComparison = [
                        'estimated_at' => $order->estimated_delivery_at,
                        'estimated_at_display' => $estimatedTime->format('h:i A'),
                        'actual_at' => $order->delivered_at,
                        'actual_at_display' => $actualTime->format('h:i A'),
                        'diff_minutes' => $diffMinutes,
                        'status' => $diffMinutes >= 0 ? 'early' : 'late',
                        'status_text' => $diffMinutes >= 0 
                            ? ($diffMinutes == 0 ? 'On time' : "{$diffMinutes} min early")
                            : (abs($diffMinutes) . ' min late'),
                        'status_emoji' => $diffMinutes >= 0 ? '✅' : '⚠️',
                    ];
                }
                
                $dateGroups[$dateKey]['orders'][] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'customer_id' => $order->customer_id,
                    'customer_address' => $customerAddress,
                    'customer_phone' => $order->address_phone ?? $order->customer_phone_from_customer,
                    'rider_id' => $order->rider_id,
                    'rider_name' => $order->rider_name ?: 'Unassigned',
                    'total_price' => $amount,
                    'payment_method' => $order->payment_method,
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'order_date' => $order->order_date,
                    'delivered_at' => $order->delivered_at,
                    'delivered_at_display' => \Carbon\Carbon::parse($order->delivered_at)->format('h:i A'),
                    // ⭐ ETA comparison (estimated vs actual delivery time)
                    'estimated_delivery_at' => $order->estimated_delivery_at,
                    'estimated_delivery_at_display' => $order->estimated_delivery_at 
                        ? \Carbon\Carbon::parse($order->estimated_delivery_at)->format('h:i A') 
                        : null,
                    'eta_comparison' => $etaComparison,
                    'expected_packets' => $order->expected_packets,
                    'actual_packets' => $order->actual_packets,
                    'delivery_location' => $deliveryLocation,     // ⭐ GPS location when delivered
                    'verified_location' => $verifiedLocation,     // ⭐ Customer's saved verified location
                    'geocoded_location' => $geocodedLocation,     // ⭐ Location from address
                    'location_verification' => $locationVerification, // ⭐ Verification status
                    'line_items' => $orderLineItems,
                    'items_count' => count($orderLineItems),
                ];
            }
            
            // Convert to array and sort by date descending (most recent first)
            $dateGroupsArray = collect($dateGroups)->sortByDesc('date')->values()->toArray();
            
            // Calculate summary stats
            $totalOrders = $orders->count();
            $totalCash = $orders->filter(function($o) {
                return in_array(strtolower($o->payment_method ?? 'cash'), ['cash', 'cash_on_delivery', 'cod']);
            })->sum('total_price');
            $totalOnline = $orders->reject(function($o) {
                return in_array(strtolower($o->payment_method ?? 'cash'), ['cash', 'cash_on_delivery', 'cod']);
            })->sum('total_price');
            
            return response()->json([
                'success' => true,
                'summary' => [
                    'total_orders' => $totalOrders,
                    'total_cash' => $totalCash,
                    'total_online' => $totalOnline,
                    'total_amount' => $totalCash + $totalOnline,
                    'days_included' => count($dateGroupsArray),
                ],
                'date_groups' => $dateGroupsArray,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching delivered orders for store: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivered orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get cancelled orders for Store Mode (grouped by cancellation date)
     * 
     * Similar to delivered orders but for cancelled status
     * Shows who cancelled and when
     */
    public function getStoreCancelledOrders(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission - same as view_open_orders (store mode access)
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view cancelled orders'
                ], 403);
            }
            
            // Get date range (default: last 60 days for performance)
            $daysBack = $request->get('days', 60);
            $startDate = now()->subDays($daysBack)->format('Y-m-d');
            
            // ⭐ Use subquery to get only the FIRST 'cancelled' status entry per order
            $cancelledSubquery = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MIN(id) as first_cancelled_id'))
                ->where('status_code', 'cancelled')
                ->where('changed_at', '>=', $startDate)
                ->groupBy('order_id');
            
            // Get all cancelled orders with cancellation date and who cancelled
            $orders = \DB::table('t_crm_prod_order as o')
                ->joinSub($cancelledSubquery, 'first_osh', function($join) {
                    $join->on('o.id', '=', 'first_osh.order_id');
                })
                ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'first_osh.first_cancelled_id')
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_sys_user as cancelled_by_user', 'cancelled_by_user.id', '=', 'osh.changed_by')
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.total_price',
                    'o.payment_method',
                    'o.assigned_rider_user_id as rider_id',
                    'o.customer_id',
                    'o.address_line1',
                    'o.address_line2',
                    'o.address_city',
                    'o.address_phone',
                    'o.order_date',
                    'o.expected_packets',
                    'u.fullname as rider_name',
                    'osh.changed_at as cancelled_at',
                    'osh.notes as cancellation_reason',
                    \DB::raw('DATE(osh.changed_at) as cancellation_date'),
                    \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                    'c.phone_original as customer_phone_from_customer',
                    // Who cancelled
                    'cancelled_by_user.fullname as cancelled_by_name',
                    'osh.changed_by as cancelled_by_id',
                ])
                ->orderBy('osh.changed_at', 'desc')
                ->get();
            
            // Get order IDs for batch fetching line items
            $orderIds = $orders->pluck('id')->toArray();
            
            // ⭐ Batch fetch line items for all orders (avoid N+1 queries)
            $lineItems = \DB::table('t_crm_prod_order_line_item')
                ->whereIn('order_id', $orderIds)
                ->select(['order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'is_free'])
                ->get()
                ->groupBy('order_id');
            
            // Group by cancellation date
            $dateGroups = [];
            foreach ($orders as $order) {
                $dateKey = $order->cancellation_date;
                
                if (!isset($dateGroups[$dateKey])) {
                    $dateGroups[$dateKey] = [
                        'date' => $dateKey,
                        'date_display' => \Carbon\Carbon::parse($dateKey)->format('D, M j, Y'),
                        'is_today' => $dateKey === now()->format('Y-m-d'),
                        'total_cancelled' => 0,
                        'total_amount' => 0,
                        'orders' => [],
                    ];
                }
                
                $amount = (float)$order->total_price;
                
                $dateGroups[$dateKey]['total_cancelled']++;
                $dateGroups[$dateKey]['total_amount'] += $amount;
                
                // Build customer address
                $customerAddress = trim(implode(', ', array_filter([
                    $order->address_line1,
                    $order->address_line2,
                    $order->address_city,
                ])));
                
                // ⭐ Get line items (products) for this order
                $orderLineItems = $lineItems->get($order->id, collect())->map(function($item) {
                    return [
                        'name' => $item->name ?? 'Product',
                        'sku' => $item->sku,
                        'quantity' => (int)$item->quantity,
                        'unit_price' => (float)$item->unit_price,
                        'line_total' => (float)$item->line_total,
                        'is_free' => (bool) $item->is_free,
                    ];
                })->values()->toArray();
                
                // Determine payment type
                $paymentMethod = strtolower($order->payment_method ?? 'cash');
                $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                
                $dateGroups[$dateKey]['orders'][] = [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'customer_name' => trim($order->customer_name) ?: 'Unknown',
                    'customer_address' => $customerAddress,
                    'customer_phone' => $order->address_phone ?? $order->customer_phone_from_customer,
                    'rider_id' => $order->rider_id,
                    'rider_name' => $order->rider_name ?: 'Unassigned',
                    'total_price' => $amount,
                    'payment_method' => $order->payment_method,
                    'payment_type' => $isCash ? 'cash' : 'online',
                    'order_date' => $order->order_date,
                    'cancelled_at' => $order->cancelled_at,
                    'cancelled_at_display' => \Carbon\Carbon::parse($order->cancelled_at)->format('h:i A'),
                    'cancelled_by_name' => $order->cancelled_by_name ?: 'System',
                    'cancellation_reason' => $order->cancellation_reason,
                    'expected_packets' => $order->expected_packets,
                    'line_items' => $orderLineItems,
                    'items_count' => count($orderLineItems),
                ];
            }
            
            // Convert to array and sort by date descending (most recent first)
            $dateGroupsArray = collect($dateGroups)->sortByDesc('date')->values()->toArray();
            
            // Calculate summary stats
            $totalOrders = $orders->count();
            $totalAmount = $orders->sum('total_price');
            
            return response()->json([
                'success' => true,
                'summary' => [
                    'total_orders' => $totalOrders,
                    'total_amount' => (float)$totalAmount,
                    'days_included' => count($dateGroupsArray),
                ],
                'date_groups' => $dateGroupsArray,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching cancelled orders for store: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cancelled orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get FULL delivered quantities tree for Store Mode (pre-loaded for instant access)
     * 
     * Returns a complete nested tree structure for a date window:
     * dates → category1 → category2 → category3 → products
     * 
     * Supports windowed loading:
     * - Window 1: days 0-20 (most recent)
     * - Window 2: days 20-40
     * - Window 3: days 40-60
     * 
     * Orders are NOT included (lazy-loaded separately to keep payload small)
     * Default: 20 days per window, max: 60 days total
     */
    public function getDeliveredQuantitiesFullTree(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view delivered quantities'
                ], 403);
            }
            
            // Window-based loading: offset_days determines which window to load
            // Window 0: days 0-20 (default)
            // Window 1: days 20-40
            // Window 2: days 40-60
            $windowSize = 20;
            $offsetDays = max(0, min((int)$request->get('offset_days', 0), 40)); // 0, 20, or 40
            $maxDays = 60;
            
            // Calculate date range for this window
            $endDate = now()->subDays($offsetDays)->format('Y-m-d');
            $startDate = now()->subDays(min($offsetDays + $windowSize, $maxDays))->format('Y-m-d');
            
            // Subquery to get ONE delivered status entry per order within date range
            $deliveredSubquery = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MIN(id) as first_delivered_id'))
                ->where('status_code', 'delivered')
                ->where('changed_at', '>=', $startDate)
                ->where('changed_at', '<=', $endDate . ' 23:59:59')
                ->groupBy('order_id');
            
            // Get all line items with their categories for delivered orders
            $rawData = \DB::table('t_crm_prod_order as o')
                ->joinSub($deliveredSubquery, 'first_osh', function($join) {
                    $join->on('o.id', '=', 'first_osh.order_id');
                })
                ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'first_osh.first_delivered_id')
                ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
                    $join->where(function ($q) {
                        $q->where(function($skuMatch) {
                            $skuMatch->whereNotNull('li.sku')
                                     ->where('li.sku', '!=', '')
                                     ->whereColumn('li.sku', 'pv.sku');
                        })
                        ->orWhere(function($fallback) {
                            $fallback->where(function($noSku) {
                                $noSku->whereNull('li.sku')
                                      ->orWhere('li.sku', '');
                            })
                            ->where(function($idMatch) {
                                $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.variant_id', 'pv.id')
                                        ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.product_id', 'pv.id');
                            });
                        });
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function ($join) {
                    $join->where(function ($q) {
                        $q->whereColumn('pv.product_id', 'p.id')
                          ->orWhere(function($nameFallback) {
                              $nameFallback->whereNull('li.sku')
                                           ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                          });
                    });
                })
                ->where(function ($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->select([
                    \DB::raw('DATE(osh.changed_at) as delivery_date'),
                    \DB::raw('COALESCE(NULLIF(p.attribute_1, ""), "Uncategorized") as attr1'),
                    \DB::raw('COALESCE(NULLIF(p.attribute_2, ""), "Uncategorized") as attr2'),
                    \DB::raw('COALESCE(NULLIF(p.attribute_3, ""), "Uncategorized") as attr3'),
                    'p.id as product_id',
                    \DB::raw('COALESCE(p.title, li.name) as product_name'),
                    'o.id as order_id',
                    'li.quantity',
                ])
                ->get();
            
            // Build nested tree structure
            $tree = [];
            $ordersByDate = []; // Track unique orders per date
            
            foreach ($rawData as $row) {
                $date = $row->delivery_date;
                $attr1 = $row->attr1;
                $attr2 = $row->attr2;
                $attr3 = $row->attr3;
                $productId = $row->product_id ?? 'unknown';
                $productName = $row->product_name ?? 'Unknown Product';
                $qty = round((float)$row->quantity, 2); // Preserve decimals up to 2 places
                $orderId = $row->order_id;
                
                // Initialize date level
                if (!isset($tree[$date])) {
                    $tree[$date] = [
                        'name' => $date,
                        'display_name' => \Carbon\Carbon::parse($date)->format('D, M j, Y'),
                        'is_today' => $date === now()->format('Y-m-d'),
                        'total_quantity' => 0,
                        'order_count' => 0,
                        'children' => [],
                    ];
                    $ordersByDate[$date] = [];
                }
                
                // Track unique orders for this date
                if (!in_array($orderId, $ordersByDate[$date])) {
                    $ordersByDate[$date][] = $orderId;
                    $tree[$date]['order_count'] = count($ordersByDate[$date]);
                }
                
                $tree[$date]['total_quantity'] += $qty;
                
                // Initialize attr1 level
                if (!isset($tree[$date]['children'][$attr1])) {
                    $tree[$date]['children'][$attr1] = [
                        'name' => $attr1,
                        'total_quantity' => 0,
                        'order_count' => 0,
                        'children' => [],
                        '_orders' => [],
                    ];
                }
                $tree[$date]['children'][$attr1]['total_quantity'] += $qty;
                if (!in_array($orderId, $tree[$date]['children'][$attr1]['_orders'])) {
                    $tree[$date]['children'][$attr1]['_orders'][] = $orderId;
                    $tree[$date]['children'][$attr1]['order_count'] = count($tree[$date]['children'][$attr1]['_orders']);
                }
                
                // Initialize attr2 level
                if (!isset($tree[$date]['children'][$attr1]['children'][$attr2])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2] = [
                        'name' => $attr2,
                        'total_quantity' => 0,
                        'order_count' => 0,
                        'children' => [],
                        '_orders' => [],
                    ];
                }
                $tree[$date]['children'][$attr1]['children'][$attr2]['total_quantity'] += $qty;
                if (!in_array($orderId, $tree[$date]['children'][$attr1]['children'][$attr2]['_orders'])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2]['_orders'][] = $orderId;
                    $tree[$date]['children'][$attr1]['children'][$attr2]['order_count'] = count($tree[$date]['children'][$attr1]['children'][$attr2]['_orders']);
                }
                
                // Initialize attr3 level
                if (!isset($tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3] = [
                        'name' => $attr3,
                        'total_quantity' => 0,
                        'order_count' => 0,
                        'children' => [],
                        '_orders' => [],
                    ];
                }
                $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['total_quantity'] += $qty;
                if (!in_array($orderId, $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['_orders'])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['_orders'][] = $orderId;
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['order_count'] = count($tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['_orders']);
                }
                
                // Initialize product level
                $productKey = $productId . '-' . $productName;
                if (!isset($tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey] = [
                        'name' => $productName,
                        'product_id' => $productId,
                        'total_quantity' => 0,
                        'order_count' => 0,
                        '_orders' => [],
                    ];
                }
                $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey]['total_quantity'] += $qty;
                if (!in_array($orderId, $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey]['_orders'])) {
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey]['_orders'][] = $orderId;
                    $tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey]['order_count'] = count($tree[$date]['children'][$attr1]['children'][$attr2]['children'][$attr3]['children'][$productKey]['_orders']);
                }
            }
            
            // Convert associative arrays to indexed arrays and remove _orders tracking
            $convertToArray = function($node, $level = 0) use (&$convertToArray) {
                unset($node['_orders']);
                
                // Round total_quantity to 2 decimal places
                if (isset($node['total_quantity'])) {
                    $node['total_quantity'] = round($node['total_quantity'], 2);
                }
                
                if (isset($node['children']) && is_array($node['children'])) {
                    $children = [];
                    foreach ($node['children'] as $child) {
                        $children[] = $convertToArray($child, $level + 1);
                    }
                    // Sort by quantity descending
                    usort($children, function($a, $b) {
                        return ($b['total_quantity'] ?? 0) - ($a['total_quantity'] ?? 0);
                    });
                    $node['children'] = $children;
                    $node['has_children'] = count($children) > 0;
                } else {
                    $node['has_children'] = false;
                }
                
                return $node;
            };
            
            $result = [];
            // Sort dates descending
            krsort($tree);
            foreach ($tree as $dateNode) {
                $result[] = $convertToArray($dateNode);
            }
            
            // Calculate totals
            $totalQty = array_sum(array_column($result, 'total_quantity'));
            $totalOrders = array_sum(array_column($result, 'order_count'));
            
            // Determine if there's more data to load
            $nextOffsetDays = $offsetDays + $windowSize;
            $hasMore = $nextOffsetDays < $maxDays;
            
            return response()->json([
                'success' => true,
                'window_size' => $windowSize,
                'offset_days' => $offsetDays,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'has_more' => $hasMore,
                'next_offset_days' => $hasMore ? $nextOffsetDays : null,
                'total_quantity' => round($totalQty, 2),
                'total_orders' => $totalOrders,
                'dates' => $result,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching delivered quantities full tree: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivered quantities: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get delivered quantities tree for Store Mode (lazy-load for older data)
     * 
     * Returns quantities grouped by:
     * - Delivery Date (from status history) 
     * - Category Level 1 (attribute_1)
     * - Category Level 2 (attribute_2)  
     * - Category Level 3 (attribute_3)
     * - Product
     * - Orders
     * 
     * Limited to last 60 days (2 months) for performance
     */
    public function getDeliveredQuantitiesTree(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user->hasMobilePermission('view_open_orders')) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to view delivered quantities'
                ], 403);
            }
            
            // Limit to 60 days for performance
            $daysBack = min($request->get('days', 60), 60);
            $startDate = now()->subDays($daysBack)->format('Y-m-d');
            
            // Get the drill-down level and filters from request
            $drillLevel = $request->get('level', 'dates'); // dates, category1, category2, category3, products, orders
            $dateFilter = $request->get('date'); // YYYY-MM-DD
            $attr1Filter = $request->get('attribute_1');
            $attr2Filter = $request->get('attribute_2');
            $attr3Filter = $request->get('attribute_3');
            $productFilter = $request->get('product_id');
            
            // ⭐ FIXED: Use subquery to get only ONE 'delivered' status entry per order
            // This prevents duplicates when an order is marked delivered multiple times
            $deliveredSubquery = \DB::table('t_crm_order_status_history')
                ->select('order_id', \DB::raw('MIN(id) as first_delivered_id'))
                ->where('status_code', 'delivered')
                ->where('changed_at', '>=', $startDate)
                ->groupBy('order_id');
            
            // ⭐ For DATES level, start from ORDERS table to include ALL delivered orders
            // This ensures order counts match the Orders tab exactly
            if ($drillLevel === 'dates') {
                $ordersQuery = \DB::table('t_crm_prod_order as o')
                    ->joinSub($deliveredSubquery, 'first_osh', function($join) {
                        $join->on('o.id', '=', 'first_osh.order_id');
                    })
                    ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'first_osh.first_delivered_id')
                    ->leftJoin('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                    ->where(function ($q) {
                        $q->where('o.external_source', '!=', 'shopify')
                          ->orWhereNull('o.external_source');
                    });
                
                $items = $ordersQuery
                    ->select([
                        \DB::raw('DATE(osh.changed_at) as name'),
                        \DB::raw('COALESCE(SUM(li.quantity), 0) as total_quantity'),
                        \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    ])
                    ->groupBy(\DB::raw('DATE(osh.changed_at)'))
                    ->orderBy('name', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'name' => $item->name,
                            'display_name' => \Carbon\Carbon::parse($item->name)->format('D, M j, Y'),
                            'is_today' => $item->name === now()->format('Y-m-d'),
                            'total_quantity' => (int)$item->total_quantity,
                            'order_count' => (int)$item->order_count,
                            'has_children' => true,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'dates',
                    'total_quantity' => $items->sum('total_quantity'),
                    'total_orders' => $items->sum('order_count'),
                    'items' => $items,
                ]);
            }
            
            // ⭐ For drill-down levels (category/products), use line items query
            // This makes sense because we're drilling into product categories
            // Use same deduplication subquery to prevent duplicates
            $baseQuery = \DB::table('t_crm_prod_order as o')
                ->joinSub($deliveredSubquery, 'first_osh_drill', function($join) {
                    $join->on('o.id', '=', 'first_osh_drill.order_id');
                })
                ->join('t_crm_order_status_history as osh', 'osh.id', '=', 'first_osh_drill.first_delivered_id')
                ->join('t_crm_prod_order_line_item as li', 'li.order_id', '=', 'o.id')
                ->leftJoin('t_crm_prod_product_variant as pv', function ($join) {
                    $join->where(function ($q) {
                        $q->where(function($skuMatch) {
                            $skuMatch->whereNotNull('li.sku')
                                     ->where('li.sku', '!=', '')
                                     ->whereColumn('li.sku', 'pv.sku');
                        })
                        ->orWhere(function($fallback) {
                            $fallback->where(function($noSku) {
                                $noSku->whereNull('li.sku')
                                      ->orWhere('li.sku', '');
                            })
                            ->where(function($idMatch) {
                                $idMatch->whereColumn('li.variant_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.variant_id', 'pv.id')
                                        ->orWhereColumn('li.product_id', 'pv.shopify_variant_id')
                                        ->orWhereColumn('li.product_id', 'pv.id');
                            });
                        });
                    });
                })
                ->leftJoin('t_crm_prod_product as p', function ($join) {
                    $join->where(function ($q) {
                        $q->whereColumn('pv.product_id', 'p.id')
                          ->orWhere(function($nameFallback) {
                              $nameFallback->whereNull('li.sku')
                                           ->whereRaw('LOWER(TRIM(li.name)) = LOWER(TRIM(p.title))');
                          });
                    });
                })
                ->leftJoin('t_sys_user as u', 'u.id', '=', 'o.assigned_rider_user_id')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where(function ($q) {
                    $q->where('o.external_source', '!=', 'shopify')
                      ->orWhereNull('o.external_source');
                })
                ->where('osh.changed_at', '>=', $startDate);
            
            // Apply filters based on drill level
            if ($dateFilter) {
                $baseQuery->whereDate('osh.changed_at', $dateFilter);
            }
            if ($attr1Filter) {
                if ($attr1Filter === 'Uncategorized') {
                    $baseQuery->where(function($q) {
                        $q->whereNull('p.attribute_1')->orWhere('p.attribute_1', '');
                    });
                } else {
                    $baseQuery->where('p.attribute_1', $attr1Filter);
                }
            }
            if ($attr2Filter) {
                if ($attr2Filter === 'Uncategorized') {
                    $baseQuery->where(function($q) {
                        $q->whereNull('p.attribute_2')->orWhere('p.attribute_2', '');
                    });
                } else {
                    $baseQuery->where('p.attribute_2', $attr2Filter);
                }
            }
            if ($attr3Filter) {
                if ($attr3Filter === 'Uncategorized') {
                    $baseQuery->where(function($q) {
                        $q->whereNull('p.attribute_3')->orWhere('p.attribute_3', '');
                    });
                } else {
                    $baseQuery->where('p.attribute_3', $attr3Filter);
                }
            }
            if ($productFilter) {
                $baseQuery->where('p.id', $productFilter);
            }
            
            if ($drillLevel === 'category1') {
                // Level 2: Group by attribute_1 (Category Level 1)
                $items = (clone $baseQuery)
                    ->select([
                        \DB::raw('COALESCE(NULLIF(p.attribute_1, ""), "Uncategorized") as name'),
                        \DB::raw('SUM(li.quantity) as total_quantity'),
                        \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    ])
                    ->groupBy('name')
                    ->orderBy('total_quantity', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'name' => $item->name,
                            'total_quantity' => (int)$item->total_quantity,
                            'order_count' => (int)$item->order_count,
                            'has_children' => true,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'category1',
                    'date' => $dateFilter,
                    'total_quantity' => $items->sum('total_quantity'),
                    'items' => $items,
                ]);
            }
            
            if ($drillLevel === 'category2') {
                // Level 3: Group by attribute_2 (Category Level 2)
                $items = (clone $baseQuery)
                    ->select([
                        \DB::raw('COALESCE(NULLIF(p.attribute_2, ""), "Uncategorized") as name'),
                        \DB::raw('SUM(li.quantity) as total_quantity'),
                        \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    ])
                    ->groupBy('name')
                    ->orderBy('total_quantity', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'name' => $item->name,
                            'total_quantity' => (int)$item->total_quantity,
                            'order_count' => (int)$item->order_count,
                            'has_children' => true,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'category2',
                    'date' => $dateFilter,
                    'attribute_1' => $attr1Filter,
                    'total_quantity' => $items->sum('total_quantity'),
                    'items' => $items,
                ]);
            }
            
            if ($drillLevel === 'category3') {
                // Level 4: Group by attribute_3 (Category Level 3)
                $items = (clone $baseQuery)
                    ->select([
                        \DB::raw('COALESCE(NULLIF(p.attribute_3, ""), "Uncategorized") as name'),
                        \DB::raw('SUM(li.quantity) as total_quantity'),
                        \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    ])
                    ->groupBy('name')
                    ->orderBy('total_quantity', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'name' => $item->name,
                            'total_quantity' => (int)$item->total_quantity,
                            'order_count' => (int)$item->order_count,
                            'has_children' => true,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'category3',
                    'date' => $dateFilter,
                    'attribute_1' => $attr1Filter,
                    'attribute_2' => $attr2Filter,
                    'total_quantity' => $items->sum('total_quantity'),
                    'items' => $items,
                ]);
            }
            
            if ($drillLevel === 'products') {
                // Level 5: Group by product
                $items = (clone $baseQuery)
                    ->select([
                        'p.id as product_id',
                        \DB::raw('COALESCE(p.title, li.name) as name'),
                        \DB::raw('SUM(li.quantity) as total_quantity'),
                        \DB::raw('COUNT(DISTINCT o.id) as order_count'),
                    ])
                    ->groupBy('p.id', 'name')
                    ->orderBy('total_quantity', 'desc')
                    ->get()
                    ->map(function($item) {
                        return [
                            'name' => $item->name,
                            'product_id' => $item->product_id,
                            'total_quantity' => (int)$item->total_quantity,
                            'order_count' => (int)$item->order_count,
                            'has_children' => true,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'products',
                    'date' => $dateFilter,
                    'attribute_1' => $attr1Filter,
                    'attribute_2' => $attr2Filter,
                    'attribute_3' => $attr3Filter,
                    'total_quantity' => $items->sum('total_quantity'),
                    'items' => $items,
                ]);
            }
            
            if ($drillLevel === 'orders') {
                // Final level: Show individual orders
                $items = (clone $baseQuery)
                    ->select([
                        'o.id',
                        'o.order_number',
                        'o.total_price',
                        'o.payment_method',
                        'li.quantity',
                        'li.name as product_name',
                        'osh.changed_at as delivered_at',
                        \DB::raw('CONCAT(COALESCE(c.first_name, ""), " ", COALESCE(c.last_name, "")) as customer_name'),
                        'u.fullname as rider_name',
                    ])
                    ->orderBy('osh.changed_at', 'desc')
                    ->get()
                    ->map(function($item) {
                        $paymentMethod = strtolower($item->payment_method ?? 'cash');
                        $isCash = in_array($paymentMethod, ['cash', 'cash_on_delivery', 'cod']);
                        
                        return [
                            'id' => $item->id,
                            'order_number' => $item->order_number ?? 'NF-' . str_pad($item->id, 4, '0', STR_PAD_LEFT),
                            'customer_name' => trim($item->customer_name) ?: 'Unknown',
                            'rider_name' => $item->rider_name ?: 'Unassigned',
                            'quantity' => (int)$item->quantity,
                            'product_name' => $item->product_name,
                            'total_price' => (float)$item->total_price,
                            'payment_type' => $isCash ? 'cash' : 'online',
                            'delivered_at' => $item->delivered_at,
                            'delivered_at_display' => \Carbon\Carbon::parse($item->delivered_at)->format('h:i A'),
                            'has_children' => false,
                        ];
                    });
                    
                return response()->json([
                    'success' => true,
                    'level' => 'orders',
                    'date' => $dateFilter,
                    'attribute_1' => $attr1Filter,
                    'attribute_2' => $attr2Filter,
                    'attribute_3' => $attr3Filter,
                    'product_id' => $productFilter,
                    'total_quantity' => $items->sum('quantity'),
                    'items' => $items,
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid drill level'
            ], 400);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching delivered quantities tree: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch delivered quantities: ' . $e->getMessage()
            ], 500);
        }
    }
    
    // ========================================================================
    // ⭐ SALARY SLIP MANAGEMENT (Store Mode)
    // ========================================================================
    
    /**
     * ⭐ Get salary slips - history for employees
     * Can filter by user_id, month, or get all
     */
    public function getSalarySlips(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            $query = \App\Models\HR\SalarySlipModel::with(['employee']);
            
            // Filter by specific user
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
            
            // Filter by month
            if ($request->filled('month')) {
                $startDate = date('Y-m-01', strtotime($request->month));
                $endDate = date('Y-m-t', strtotime($request->month));
                $query->whereBetween('salary_month', [$startDate, $endDate]);
            }
            
            // Filter by status
            if ($request->filled('status')) {
                $query->where('slip_status', $request->status);
            }
            
            $slips = $query->orderByDesc('salary_month')
                          ->orderByDesc('created_at')
                          ->limit(50)
                          ->get()
                          ->map(function($slip) {
                              return [
                                  'id' => $slip->id,
                                  'slip_number' => $slip->slip_number,
                                  'employee_name' => $slip->employee->fullname ?? 'Unknown',
                                  'user_id' => $slip->user_id,
                                  'salary_month' => $slip->salary_month->format('Y-m-d'),
                                  'salary_month_display' => $slip->salary_month->format('M Y'),
                                  'gross_salary' => (float) $slip->gross_salary,
                                  'total_deductions' => (float) $slip->total_deductions,
                                  'net_salary' => (float) $slip->net_salary,
                                  'slip_status' => $slip->slip_status,
                                  'status_badge' => match($slip->slip_status) {
                                      'draft' => '📝',
                                      'approved' => '✅',
                                      'paid' => '💰',
                                      'cancelled' => '❌',
                                      default => '⏳'
                                  },
                                  'paid_at' => $slip->paid_at ? $slip->paid_at->format('M d, Y') : null,
                                  'created_at' => $slip->created_at->format('M d, Y'),
                              ];
                          });
            
            return response()->json([
                'success' => true,
                'slips' => $slips,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error fetching salary slips: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load salary slips: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Calculate salary for an employee and month
     * Uses existing SalaryCalculationService for consistency
     */
    public function calculateSalary(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'month' => 'required|date_format:Y-m', // Format: 2026-02
            ]);
            
            $salaryMonth = $validated['month'] . '-01'; // Convert to full date
            
            // Use existing salary calculation service
            $salaryService = new \App\Services\HR\SalaryCalculationService();
            $calculation = $salaryService->calculateSalary($validated['user_id'], $salaryMonth);
            
            // ⭐ DEBUG: Log what IDs are returned from calculation
            \Log::info('Salary calculation result - IDs', [
                'user_id' => $validated['user_id'],
                'month' => $salaryMonth,
                'loan_ids' => $calculation['loan_ids'] ?? 'NOT SET',
                'advance_request_ids' => $calculation['advance_request_ids'] ?? 'NOT SET',
                'loan_installment' => $calculation['loan_installment'] ?? 0,
                'salary_advance' => $calculation['salary_advance'] ?? 0,
            ]);
            
            if (!$calculation['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $calculation['error'] ?? 'Failed to calculate salary'
                ], 400);
            }
            
            // Check for existing slips in this month (for weekly support)
            $startDate = date('Y-m-01', strtotime($salaryMonth));
            $endDate = date('Y-m-t', strtotime($salaryMonth));
            $existingSlips = \App\Models\HR\SalarySlipModel::where('user_id', $validated['user_id'])
                ->whereBetween('salary_month', [$startDate, $endDate])
                ->whereIn('slip_status', ['draft', 'approved', 'paid'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($slip) {
                    return [
                        'id' => $slip->id,
                        'slip_number' => $slip->slip_number,
                        'net_salary' => (float)$slip->net_salary,
                        'status' => $slip->slip_status,
                        'created_at' => $slip->created_at->format('M d'),
                    ];
                });
            
            return response()->json([
                'success' => true,
                'calculation' => $calculation,
                'existing_slips' => $existingSlips,
                'has_existing_slip' => $existingSlips->count() > 0,
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error calculating salary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to calculate salary: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Create salary slip (Store Mode)
     * Supports weekly salaries by allowing multiple slips per month
     */
    public function createSalarySlip(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            $validated = $request->validate([
                'user_id' => 'required|integer|exists:t_sys_user,id',
                'salary_month' => 'required|date_format:Y-m-d',
                'slip_status' => 'required|in:draft,approved',
                'allow_multiple' => 'nullable|boolean', // ⭐ Allow multiple slips in same month
                'period_label' => 'nullable|string|max:50', // ⭐ e.g., "Week 1", "Week 2"
                
                // Earnings
                'base_salary' => 'required|numeric|min:0',
                'overtime_hours' => 'nullable|numeric|min:0',
                'overtime_amount' => 'nullable|numeric|min:0',
                'bonuses' => 'nullable|numeric|min:0',
                'allowances' => 'nullable|numeric|min:0',
                'other_earnings' => 'nullable|numeric|min:0',
                'other_earnings_desc' => 'nullable|string|max:500',
                
                // Deductions
                'late_minutes' => 'nullable|numeric|min:0',
                'late_deduction' => 'nullable|numeric|min:0',
                'absent_days' => 'nullable|integer|min:0',
                'absent_deduction' => 'nullable|numeric|min:0',
                'salary_advance' => 'nullable|numeric|min:0',
                'loan_installment' => 'nullable|numeric|min:0',
                'tax_deduction' => 'nullable|numeric|min:0',
                'other_deductions' => 'nullable|numeric|min:0',
                'other_deductions_desc' => 'nullable|string|max:500',
                
                // Attendance
                'working_days' => 'nullable|integer|min:0',
                'present_days' => 'nullable|integer|min:0',
                'leave_days' => 'nullable|integer|min:0',
                'half_days' => 'nullable|integer|min:0',
                
                // Overrides
                'late_deduction_overridden' => 'nullable|boolean',
                'overtime_overridden' => 'nullable|boolean',
                'absent_deduction_overridden' => 'nullable|boolean',
                'loan_installment_skipped' => 'nullable|boolean',
                'salary_advance_overridden' => 'nullable|boolean',
                'has_manual_adjustments' => 'nullable|boolean',
                'override_notes' => 'nullable|string|max:1000',
                
                // Totals
                'gross_salary' => 'required|numeric|min:0',
                'total_deductions' => 'required|numeric|min:0',
                'net_salary' => 'required|numeric',
                
                // IDs for settlement tracking
                'advance_request_ids' => 'nullable|string',
                'loan_ids' => 'nullable|string'
            ]);
            
            // ⭐ DEBUG: Log what we received for loan/advance IDs
            \Log::info('Salary slip creation - IDs received', [
                'user_id' => $validated['user_id'],
                'slip_status' => $validated['slip_status'],
                'loan_ids' => $validated['loan_ids'] ?? 'NOT SET',
                'advance_request_ids' => $validated['advance_request_ids'] ?? 'NOT SET',
                'loan_installment' => $validated['loan_installment'] ?? 0,
                'salary_advance' => $validated['salary_advance'] ?? 0,
            ]);
            
            \DB::beginTransaction();
            
            // Check for duplicate unless explicitly allowed
            if (!($validated['allow_multiple'] ?? false)) {
                $startDate = date('Y-m-01', strtotime($validated['salary_month']));
                $endDate = date('Y-m-t', strtotime($validated['salary_month']));
                $existingSlip = \App\Models\HR\SalarySlipModel::where('user_id', $validated['user_id'])
                    ->whereBetween('salary_month', [$startDate, $endDate])
                    ->whereIn('slip_status', ['draft', 'approved', 'paid'])
                    ->first();
                
                if ($existingSlip) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A salary slip already exists for this month (Slip #' . $existingSlip->slip_number . '). Enable "Allow multiple" for weekly salaries.',
                        'existing_slip' => [
                            'id' => $existingSlip->id,
                            'slip_number' => $existingSlip->slip_number,
                        ]
                    ], 400);
                }
            }
            
            // Generate slip number with period label if provided
            $slipCount = \App\Models\HR\SalarySlipModel::whereDate('created_at', today())->count() + 1;
            $slipNumber = 'SAL-' . date('Ymd') . '-' . str_pad($slipCount, 3, '0', STR_PAD_LEFT);
            
            // Create salary slip
            $slip = \App\Models\HR\SalarySlipModel::create([
                'user_id' => $validated['user_id'],
                'salary_month' => $validated['salary_month'],
                'slip_number' => $slipNumber,
                'slip_status' => 'draft', // Always start as draft
                
                // Earnings
                'base_salary' => $validated['base_salary'],
                'overtime_hours' => $validated['overtime_hours'] ?? 0,
                'overtime_amount' => $validated['overtime_amount'] ?? 0,
                'bonuses' => $validated['bonuses'] ?? 0,
                'allowances' => $validated['allowances'] ?? 0,
                'other_earnings' => $validated['other_earnings'] ?? 0,
                'other_earnings_description' => $validated['other_earnings_desc'] ?? null,
                
                // Deductions
                'late_minutes' => $validated['late_minutes'] ?? 0,
                'late_deduction' => $validated['late_deduction'] ?? 0,
                'absent_days' => $validated['absent_days'] ?? 0,
                'absent_deduction' => $validated['absent_deduction'] ?? 0,
                'salary_advance' => $validated['salary_advance'] ?? 0,
                'loan_installment' => $validated['loan_installment'] ?? 0,
                'tax_deduction' => $validated['tax_deduction'] ?? 0,
                'other_deductions' => $validated['other_deductions'] ?? 0,
                'other_deductions_description' => $validated['other_deductions_desc'] ?? null,
                
                // Attendance
                'working_days' => $validated['working_days'] ?? 0,
                'present_days' => $validated['present_days'] ?? 0,
                'leave_days' => $validated['leave_days'] ?? 0,
                'half_days' => $validated['half_days'] ?? 0,
                
                // Overrides
                'late_deduction_overridden' => $validated['late_deduction_overridden'] ?? false,
                'overtime_overridden' => $validated['overtime_overridden'] ?? false,
                'absent_deduction_overridden' => $validated['absent_deduction_overridden'] ?? false,
                'loan_installment_skipped' => $validated['loan_installment_skipped'] ?? false,
                'salary_advance_overridden' => $validated['salary_advance_overridden'] ?? false,
                'has_manual_adjustments' => $validated['has_manual_adjustments'] ?? false,
                'override_notes' => $validated['override_notes'] ?? null,
                
                // Totals
                'gross_salary' => $validated['gross_salary'],
                'total_deductions' => $validated['total_deductions'],
                'net_salary' => $validated['net_salary'],
                
                // IDs
                'advance_request_ids' => $validated['advance_request_ids'] ?? null,
                'loan_ids' => $validated['loan_ids'] ?? null,
                
                // Meta
                'created_by' => $user->id
            ]);
            
            // If user wants it approved immediately, do full approval flow
            if ($validated['slip_status'] === 'approved') {
                // Approve the slip
                $slip->approve($user->id);
                
                // Create ledger entry for salary payment using the SalarySlipController logic
                try {
                    // Get employee cash account
                    $employeeCashAccount = \App\Models\FIN\AccountModel::where('user_id', $slip->user_id)
                        ->where('account_category', 'employee_cash')
                        ->first();

                    if (!$employeeCashAccount) {
                        // Auto-create employee cash account if doesn't exist
                        $employee = \App\Models\SysAdmin\UserModel::find($slip->user_id);
                        $employeeCashAccount = \App\Models\FIN\AccountModel::create([
                            'account_code' => 'EMP_' . $slip->user_id . '_CASH',
                            'account_name' => 'Employee Cash - ' . ($employee->fullname ?? 'Employee'),
                            'account_type' => 'liability',
                            'account_category' => 'employee_cash',
                            'user_id' => $slip->user_id,
                            'is_active' => true,
                            'current_balance' => 0,
                        ]);
                    }

                    // Get payment source account
                    $paymentSource = \App\Models\FIN\AccountModel::whereIn('account_code', ['EXP_FUND', 'NF_CASH'])
                        ->where('is_active', true)
                        ->first();

                    if ($paymentSource && $employeeCashAccount) {
                        // Create ledger transaction
                        $ledger = \App\Models\FIN\LedgerModel::create([
                            'transaction_date' => now(),
                            'transaction_type' => 'salary_payment',
                            'description' => 'Salary payment - ' . ($slip->employee->fullname ?? 'Employee') . ' - ' . date('M Y', strtotime($slip->salary_month)),
                            'from_account_id' => $paymentSource->id,
                            'to_account_id' => $employeeCashAccount->id,
                            'amount' => $slip->net_salary,
                            'reference_type' => 'salary_slip',
                            'reference_id' => $slip->id,
                            'created_by' => $user->id,
                            'status' => 'completed',
                        ]);

                        // Update account balances
                        $paymentSource->decrement('current_balance', $slip->net_salary);
                        $employeeCashAccount->increment('current_balance', $slip->net_salary);

                        // Mark slip as paid
                        $slip->markAsPaid($ledger->id, 'cash');
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to post salary to ledger', [
                        'slip_id' => $slip->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue - slip is created and approved, ledger can be fixed manually
                }
                
                // ⭐ CRITICAL: Process loan payments and settle advances OUTSIDE the ledger block
                // This ensures loans/advances are settled even if ledger posting fails
                try {
                    // Process loan payments if any
                    if ($slip->loan_ids && !$slip->loan_installment_skipped) {
                        \Log::info('Processing loan payments for slip', [
                            'slip_id' => $slip->id,
                            'loan_ids' => $slip->loan_ids,
                        ]);
                        $this->processSlipLoanPayments($slip);
                    }

                    // Settle salary advances if any
                    if ($slip->advance_request_ids) {
                        \Log::info('Settling salary advances for slip', [
                            'slip_id' => $slip->id,
                            'advance_request_ids' => $slip->advance_request_ids,
                        ]);
                        $this->settleSlipAdvances($slip);
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to process loan/advance settlements', [
                        'slip_id' => $slip->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            \DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => $validated['slip_status'] === 'approved' 
                    ? 'Salary slip created and approved successfully'
                    : 'Salary slip saved as draft',
                'slip' => [
                    'id' => $slip->id,
                    'slip_number' => $slipNumber,
                    'net_salary' => (float)$slip->net_salary,
                    'status' => $slip->slip_status,
                ]
            ]);
            
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Error creating salary slip: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create salary slip: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ⭐ Get salary slip details (Store Mode)
     */
    public function getStoreSalarySlipDetails(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            // Check permission
            if (!$user->hasMobilePermission('view_store_attendance')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Permission denied'
                ], 403);
            }
            
            $slip = \App\Models\HR\SalarySlipModel::with(['employee', 'approver'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'slip' => [
                    'id' => $slip->id,
                    'slip_number' => $slip->slip_number,
                    'employee_name' => $slip->employee->fullname ?? 'Unknown',
                    'user_id' => $slip->user_id,
                    'salary_month' => $slip->salary_month->format('Y-m-d'),
                    'salary_month_display' => $slip->salary_month->format('M Y'),
                    
                    // Earnings
                    'base_salary' => (float)$slip->base_salary,
                    'overtime_hours' => (float)$slip->overtime_hours,
                    'overtime_amount' => (float)$slip->overtime_amount,
                    'bonuses' => (float)$slip->bonuses,
                    'allowances' => (float)$slip->allowances,
                    'other_earnings' => (float)$slip->other_earnings,
                    'gross_salary' => (float)$slip->gross_salary,
                    
                    // Deductions
                    'late_minutes' => (float)$slip->late_minutes,
                    'late_deduction' => (float)$slip->late_deduction,
                    'absent_days' => (int)$slip->absent_days,
                    'absent_deduction' => (float)$slip->absent_deduction,
                    'salary_advance' => (float)$slip->salary_advance,
                    'loan_installment' => (float)$slip->loan_installment,
                    'other_deductions' => (float)$slip->other_deductions,
                    'total_deductions' => (float)$slip->total_deductions,
                    
                    // Net
                    'net_salary' => (float)$slip->net_salary,
                    
                    // Attendance
                    'working_days' => (int)$slip->working_days,
                    'present_days' => (int)$slip->present_days,
                    'leave_days' => (int)$slip->leave_days,
                    
                    // Status
                    'slip_status' => $slip->slip_status,
                    'approved_by' => $slip->approver->fullname ?? null,
                    'approved_at' => $slip->approved_at ? $slip->approved_at->format('M d, Y h:i A') : null,
                    'paid_at' => $slip->paid_at ? $slip->paid_at->format('M d, Y h:i A') : null,
                    
                    // Overrides
                    'has_manual_adjustments' => (bool)$slip->has_manual_adjustments,
                    'override_notes' => $slip->override_notes,
                    
                    'created_at' => $slip->created_at->format('M d, Y'),
                ],
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load salary slip: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Helper: Process loan payments for a salary slip
     */
    private function processSlipLoanPayments($slip)
    {
        if (!$slip->loan_ids || $slip->loan_installment_skipped) {
            \Log::info('No loan_ids to process or loan installment skipped');
            return;
        }
        
        $loanIds = array_filter(explode(',', $slip->loan_ids));
        
        \Log::info('Processing loan payments', [
            'loan_ids' => $loanIds,
            'slip_id' => $slip->id,
        ]);
        
        foreach ($loanIds as $loanId) {
            $loan = \App\Models\HR\EmployeeLoanModel::find($loanId);
            
            if (!$loan) {
                \Log::warning('Loan not found', ['loan_id' => $loanId]);
                continue;
            }
            
            if ($loan->loan_status !== 'active') {
                \Log::warning('Loan not active', ['loan_id' => $loanId, 'status' => $loan->loan_status]);
                continue;
            }
            
            // Calculate payment amount (use full installment or remaining balance, whichever is less)
            $paymentAmount = min($loan->monthly_installment, $loan->outstanding_balance);
            
            \Log::info('Processing loan payment', [
                'loan_id' => $loanId,
                'payment_amount' => $paymentAmount,
                'outstanding_before' => $loan->outstanding_balance,
            ]);
            
            // Create loan payment record
            // ⭐ FIX: Use correct field names matching LoanPaymentModel fillable
            $payment = \App\Models\HR\LoanPaymentModel::create([
                'loan_id' => $loan->id,
                'salary_slip_id' => $slip->id,
                'payment_date' => now(),
                'payment_amount' => $paymentAmount,
                'balance_before' => $loan->outstanding_balance,
                'balance_after' => $loan->outstanding_balance - $paymentAmount,
                'payment_type' => 'salary_deduction',
                'payment_notes' => 'Salary deduction via slip: ' . $slip->slip_number,
                'created_by' => auth()->id(),
            ]);
            
            // Update loan balance
            // ⭐ FIX: Only update fields that exist in the model
            $loan->outstanding_balance -= $paymentAmount;
            
            // Check if loan is fully paid
            if ($loan->outstanding_balance <= 0) {
                $loan->outstanding_balance = 0; // Ensure it's not negative
                $loan->loan_status = 'completed';
                $loan->completed_at = now();
            }
            
            $loan->save();
            
            \Log::info('Loan payment processed', [
                'loan_id' => $loanId,
                'payment_id' => $payment->id,
                'outstanding_after' => $loan->outstanding_balance,
                'loan_status' => $loan->loan_status,
            ]);
        }
    }
    
    /**
     * Helper: Settle salary advances for a salary slip
     */
    private function settleSlipAdvances($slip)
    {
        if (!$slip->advance_request_ids) {
            \Log::info('No advance_request_ids to settle');
            return;
        }
        
        $advanceIds = array_filter(explode(',', $slip->advance_request_ids));
        
        \Log::info('Settling advances', [
            'advance_ids' => $advanceIds,
            'slip_id' => $slip->id,
        ]);
        
        // Update settlement status for each advance
        // Note: settled_via_slip_id column may not exist, so we only update fields that exist
        $updated = \App\Models\Request\RequestModel::whereIn('id', $advanceIds)
            ->update([
                'settlement_status' => 'settled',
                'settled_at' => now(),
            ]);
        
        \Log::info('Advances settled', [
            'count' => $updated,
            'advance_ids' => $advanceIds,
        ]);
    }

    // =========================================================================
    // QURBANI MODE ENDPOINTS
    // =========================================================================

    /**
     * Qurbani open orders - orders containing products with attribute_1 = 'qurbani'
     * Supports filters: qurbani_day, qurbani_slot, qurbani_region, qurbani_delivery_type, status
     */
    public function getQurbaniOpenOrders(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user->hasMobilePermission('access_qurbani_mode')) {
                return response()->json(['success' => false, 'message' => 'No permission'], 403);
            }

            // Find open qurbani orders efficiently:
            // First check orders with qurbani fields set (fast indexed check),
            // then fall back to line-item join only for orders in last 60 days without fields set
            $qurbaniOrderIds = \DB::table('t_crm_prod_order')
                ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                ->where(function($q) {
                    $q->where('external_source', '!=', 'shopify')->orWhereNull('external_source');
                })
                ->where(function($q) {
                    // Orders with qurbani fields explicitly set (fast)
                    $q->whereNotNull('qurbani_day')
                      ->orWhereNotNull('qurbani_slot')
                      ->orWhereNotNull('qurbani_region')
                      ->orWhereNotNull('qurbani_delivery_type');
                })
                ->pluck('id');

            // Also find orders identified by line item products (for orders created before fields were added)
            $lineItemQurbaniIds = \DB::table('t_crm_prod_order_line_item as li')
                ->join('t_crm_prod_product as p', 'li.product_id', '=', 'p.id')
                ->join('t_crm_prod_order as o', 'li.order_id', '=', 'o.id')
                ->whereRaw("LOWER(p.attribute_1) = 'qurbani'")
                ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                ->where(function($q) {
                    $q->where('o.external_source', '!=', 'shopify')->orWhereNull('o.external_source');
                })
                ->where('o.order_date', '>=', \Carbon\Carbon::now()->subDays(60))
                ->distinct()
                ->pluck('li.order_id');

            $allQurbaniIds = $qurbaniOrderIds->merge($lineItemQurbaniIds)->unique();

            if ($allQurbaniIds->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'orders' => [],
                    'summary' => ['total' => 0, 'unpaid' => 0, 'partial' => 0, 'paid' => 0],
                ]);
            }

            $query = OrderModel::with(['customer' => function($q) {
                    $q->select('id', 'first_name', 'last_name', 'phone_original', 'latitude', 'longitude',
                               'geocoded_latitude', 'geocoded_longitude', 'verified_location_url', 'notes',
                               'verified_location_saved_by', 'verified_location_saved_at', 'delivery_region_id');
                }])
                ->with(['assignedRider' => function($q) {
                    $q->select('id', 'fullname');
                }])
                ->with(['lineItems' => function($q) {
                    $q->select('id', 'order_id', 'product_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'preparation_status', 'is_free', 'qurbani_day', 'qurbani_slot', 'qurbani_region', 'qurbani_sub_region', 'qurbani_delivery_type', 'instructions');
                }])
                ->with(['lineItems.product' => function($q) {
                    $q->select('id', 'attribute_2');
                }])
                ->with(['discounts'])
                ->whereIn('id', $allQurbaniIds);

            // Apply status filter
            if ($request->get('status')) {
                $query->where('order_status', $request->get('status'));
            }

            // Apply qurbani field filters (check both order-level and line-item-level)
            foreach (['qurbani_day', 'qurbani_slot', 'qurbani_region', 'qurbani_sub_region', 'qurbani_delivery_type'] as $field) {
                if ($request->get($field)) {
                    $filterVal = $request->get($field);
                    $query->where(function($q) use ($field, $filterVal) {
                        $q->where($field, $filterVal)
                          ->orWhereHas('lineItems', function($sub) use ($field, $filterVal) {
                              $sub->where($field, $filterVal);
                          });
                    });
                }
            }

            $orders = $query->orderBy('order_date', 'desc')->get();

            // Preparation summaries
            $prepSummaries = \DB::table('t_crm_prod_order_line_item')
                ->whereIn('order_id', $orders->pluck('id'))
                ->groupBy('order_id')
                ->selectRaw('order_id, COUNT(*) as total, SUM(CASE WHEN preparation_status = "preparing" THEN 1 ELSE 0 END) as preparing')
                ->get()
                ->keyBy('order_id');

            $regionMap = [];
            try {
                $regionMap = \DB::table('t_ops_delivery_region')
                    ->where('is_active', 1)
                    ->pluck('name', 'id')
                    ->toArray();
            } catch (\Exception $e) {}

            $formattedOrders = $orders->map(function($order) use ($prepSummaries, $regionMap) {
                $customerName = $order->name ?? 'N/A';
                if (!$order->name && ($order->address_first_name || $order->address_last_name)) {
                    $customerName = trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? ''));
                }
                if ($customerName === 'N/A' && $order->customer) {
                    $customerName = trim(($order->customer->first_name ?? '') . ' ' . ($order->customer->last_name ?? '')) ?: 'Unknown';
                }

                $prepSummary = $prepSummaries[$order->id] ?? null;

                $effectiveCustomerNotes = null;
                if ($order->customer) {
                    if ($order->customer->merged_into_customer_id ?? false) {
                        $primaryCustomer = \App\Models\CRM\CustomerModel::find($order->customer->merged_into_customer_id);
                        $effectiveCustomerNotes = $primaryCustomer ? ($primaryCustomer->notes ?? null) : null;
                    } else {
                        $effectiveCustomerNotes = $order->customer->notes ?? null;
                    }
                }

                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status ?? 'unpaid',
                    'total_paid' => (float) ($order->total_paid ?? 0),
                    'balance_remaining' => max(0, (float)$order->total_price - (float)($order->total_paid ?? 0)),
                    // Mirrors server-side PAID stamp (bank / date / ref).
                    // The mobile InvoiceTemplate reads this as-is; without it
                    // older clients fall back to hardcoded "CASH" which is
                    // wrong for online-paid orders.
                    'paid_stamp' => $order->getPaidStampData(),
                    'qurbani_day' => $order->qurbani_day,
                    'qurbani_slot' => $order->qurbani_slot,
                    'qurbani_region' => $order->qurbani_region,
                    'qurbani_delivery_type' => $order->qurbani_delivery_type,
                    'delivery_priority' => $order->delivery_priority,
                    'expected_packets' => $order->expected_packets,
                    'actual_packets' => $order->actual_packets,
                    'customer_id' => $order->customer_id,
                    'customer_name' => $customerName,
                    'customer_address' => trim(implode(', ', array_filter([
                        $order->address_line1, $order->address_line2, $order->address_city, $order->address_province
                    ]))),
                    'customer_phone' => $order->address_phone ?? ($order->customer->phone_original ?? null),
                    'customer_notes' => $effectiveCustomerNotes,
                    'has_customer_notes' => !empty($effectiveCustomerNotes),
                    'order_note' => $order->note ?? null,
                    'has_order_note' => !empty($order->note),
                    'assigned_rider_id' => $order->assigned_rider_user_id,
                    'assigned_rider' => $order->assignedRider ? [
                        'id' => $order->assignedRider->id,
                        'name' => $order->assignedRider->fullname,
                    ] : null,
                    'preparation_summary' => [
                        'preparing_count' => $prepSummary->preparing ?? 0,
                        'total_items' => $prepSummary->total ?? 0,
                    ],
                    'delivery_region_id' => $order->customer->delivery_region_id ?? null,
                    'delivery_region_name' => ($order->customer->delivery_region_id ?? null) ? ($regionMap[$order->customer->delivery_region_id] ?? null) : null,
                    'updated_at' => $order->updated_at ? $order->updated_at->toIso8601String() : null,
                    'subtotal_price' => $order->subtotal_price ?? 0,
                    'discount_total' => $order->discount_total ?? 0,
                    'shipping_total' => $order->shipping_total ?? 0,
                    'tip_amount' => $order->tip_amount ?? 0,
                    'discounts' => $order->discounts ? $order->discounts->map(function($d) {
                        return ['discount_amount' => $d->discount_amount, 'discount_type' => $d->discount_type];
                    })->toArray() : [],
                    'line_items' => $order->lineItems->map(function($item) {
                        return [
                            'id' => $item->id,
                            'name' => $item->name ?? 'N/A',
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => (float) $item->quantity,
                            'unit_price' => (float) $item->unit_price,
                            'unit_price_formatted' => $item->unit_price ? 'Rs. ' . number_format($item->unit_price, 0) : null,
                            'line_total' => (float) $item->line_total,
                            'preparation_status' => $item->preparation_status,
                            'is_free' => (bool) $item->is_free,
                            'qurbani_day' => $item->qurbani_day,
                            'qurbani_slot' => $item->qurbani_slot,
                            'qurbani_region' => $item->qurbani_region,
                            'qurbani_sub_region' => $item->qurbani_sub_region,
                            'qurbani_delivery_type' => $item->qurbani_delivery_type,
                            'category_level_2' => $item->product->attribute_2 ?? null,
                            'instructions' => $item->instructions,
                        ];
                    })->values()->toArray(),
                    'external_source' => $order->external_source,
                ];
            });

            // Payment status summary
            $summary = [
                'total' => $formattedOrders->count(),
                'unpaid' => $formattedOrders->where('payment_status', 'unpaid')->count(),
                'partial' => $formattedOrders->where('payment_status', 'partial')->count(),
                'paid' => $formattedOrders->where('payment_status', 'paid')->count(),
            ];

            return response()->json([
                'success' => true,
                'orders' => $formattedOrders->values(),
                'summary' => $summary,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get qurbani open orders', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Failed to load qurbani orders: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update qurbani details (day, slot, region, delivery_type) on an existing order
     */
    public function updateQurbaniDetails(Request $request, $orderId)
    {
        try {
            $validated = $request->validate([
                'qurbani_day' => 'nullable|string|max:50',
                'qurbani_slot' => 'nullable|string|max:50',
                'qurbani_region' => 'nullable|string|max:100',
                'qurbani_sub_region' => 'nullable|string|max:100',
                'qurbani_delivery_type' => 'nullable|string|max:50',
                'instructions' => 'nullable|string|max:500',
                'line_item_id' => 'nullable|integer|exists:t_crm_prod_order_line_item,id',
            ]);

            $order = OrderModel::findOrFail($orderId);
            $fields = [
                'qurbani_day' => $validated['qurbani_day'] ?? null,
                'qurbani_slot' => $validated['qurbani_slot'] ?? null,
                'qurbani_region' => $validated['qurbani_region'] ?? null,
                'qurbani_sub_region' => $validated['qurbani_sub_region'] ?? null,
                'qurbani_delivery_type' => $validated['qurbani_delivery_type'] ?? null,
            ];
            if (array_key_exists('instructions', $validated)) {
                $fields['instructions'] = $validated['instructions'];
            }

            if (!empty($validated['line_item_id'])) {
                $order->lineItems()->where('id', $validated['line_item_id'])->update($fields);
            } else {
                $order->lineItems()->update($fields);
            }

            // Sync first line item's values to order level for filtering
            $firstItem = $order->lineItems()->first();
            if ($firstItem) {
                $order->update([
                    'qurbani_day' => $firstItem->qurbani_day,
                    'qurbani_slot' => $firstItem->qurbani_slot,
                    'qurbani_region' => $firstItem->qurbani_region,
                    'qurbani_sub_region' => $firstItem->qurbani_sub_region,
                    'qurbani_delivery_type' => $firstItem->qurbani_delivery_type,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Qurbani details updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function updateLineItemInstructions(Request $request, $orderId, $lineItemId)
    {
        try {
            $validated = $request->validate([
                'instructions' => 'nullable|string|max:500',
            ]);

            $lineItem = \App\Models\CRM\OrderLineItemModel::where('id', $lineItemId)
                ->where('order_id', $orderId)
                ->firstOrFail();

            $lineItem->instructions = $validated['instructions'] ?? null;
            $lineItem->save();

            return response()->json(['success' => true, 'message' => 'Instructions updated']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Qurbani field options - dropdown values for qurbani order fields
     */
    public function getQurbaniFieldOptions(Request $request)
    {
        try {
            $options = \DB::table('t_crm_qurbani_field_options')
                ->where('is_active', 1)
                ->orderBy('field_name')
                ->orderBy('display_order')
                ->get()
                ->groupBy('field_name')
                ->map(function ($group) {
                    return $group->map(function ($item) {
                        $opt = ['id' => $item->id, 'value' => $item->option_value];
                        if ($item->parent_id) $opt['parent_id'] = $item->parent_id;
                        if (isset($item->delivery_type_parent_id) && $item->delivery_type_parent_id) $opt['delivery_type_parent_id'] = $item->delivery_type_parent_id;
                        if ($item->is_default) $opt['is_default'] = true;
                        return $opt;
                    })->values();
                });

            $categories = \DB::table('t_crm_prod_product')
                ->whereRaw("LOWER(attribute_1) = 'qurbani'")
                ->whereNotNull('attribute_2')
                ->where('attribute_2', '!=', '')
                ->distinct()
                ->orderBy('attribute_2')
                ->pluck('attribute_2')
                ->values();

            $shippingPrice = \App\Models\FIN\ConfigModel::get('qurbani_shipping_price', '1000');

            $rawInvoiceFields = \DB::table('t_crm_qurbani_field_options')
                ->where('show_in_invoice', 1)
                ->groupBy('field_name')
                ->pluck('field_name')
                ->toArray();
            $desiredOrder = ['qurbani_day', 'qurbani_delivery_type', 'qurbani_slot', 'qurbani_region', 'qurbani_sub_region'];
            $invoiceFields = [];
            foreach ($desiredOrder as $f) { if (in_array($f, $rawInvoiceFields)) $invoiceFields[] = $f; }
            foreach ($rawInvoiceFields as $f) { if (!in_array($f, $invoiceFields)) $invoiceFields[] = $f; }

            return response()->json([
                'success' => true,
                'options' => $options,
                'categories' => $categories,
                'qurbani_shipping_price' => $shippingPrice,
                'invoice_fields' => $invoiceFields,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * List payments for an order
     */
    public function getOrderPayments(Request $request, $orderId)
    {
        try {
            $user = Auth::user();

            $order = OrderModel::findOrFail($orderId);

            $payments = \DB::table('t_crm_order_payments as p')
                ->leftJoin('t_sys_user as u', 'p.created_by', '=', 'u.id')
                ->leftJoin('t_fin_ledger as l', 'p.ledger_transaction_id', '=', 'l.id')
                ->leftJoin('t_fin_online_receiving_accounts as b', 'p.receiving_account_id', '=', 'b.id')
                ->where('p.order_id', $orderId)
                ->where('p.status', 'active')
                ->orderBy('p.payment_date', 'desc')
                ->orderBy('p.created_at', 'desc')
                ->select([
                    'p.id', 'p.amount', 'p.payment_method', 'p.payment_date',
                    'p.reference', 'p.notes', 'p.status', 'p.ledger_transaction_id',
                    'p.created_by', 'p.created_at',
                    'p.receiving_account_id',
                    'u.fullname as created_by_name',
                    'b.name as receiving_account_name',
                    'b.short_code as receiving_account_code',
                    'b.color_hex as receiving_account_color',
                    'l.approval_status as ledger_approval_status',
                    'l.settlement_status as ledger_settlement_status',
                ])
                ->get();

            return response()->json([
                'success' => true,
                'order_id' => (int) $orderId,
                'order_number' => $order->order_number,
                'total_price' => (float) $order->total_price,
                'total_paid' => (float) ($order->total_paid ?? 0),
                'payment_status' => $order->payment_status ?? 'unpaid',
                'balance_remaining' => max(0, (float)$order->total_price - (float)($order->total_paid ?? 0)),
                'payments' => $payments,
                // Ship PAID-stamp overrides + list of receiving banks so the
                // mobile Add Payment sheet has everything it needs in one
                // round-trip (no need to call /online-receiving-accounts
                // separately).
                'paid_stamp' => [
                    'sending_bank' => $order->paid_stamp_sending_bank,
                    'date'         => $order->paid_stamp_date
                        ? \Carbon\Carbon::parse($order->paid_stamp_date)->format('Y-m-d')
                        : null,
                    'ref_mode'     => $order->paid_stamp_ref_mode ?: 'reference',
                ],
                'receiving_accounts' => \App\Models\FIN\OnlineReceivingAccountModel::active()
                    ->ordered()
                    ->get(['id', 'name', 'short_code', 'color_hex']),
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to get order payments', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Add a payment to an order.
     * Ledger routing:
     *   Rider adds cash → rider's employee_cash (shows in daily closing, needs settlement)
     *   Manager/Taimur adds cash → NF_CASH (auto-settled)
     *   Online (any) → online bank account
     */
    public function addOrderPayment(Request $request, $orderId)
    {
        try {
            $user = Auth::user();
            $order = OrderModel::findOrFail($orderId);

            $validated = $request->validate([
                'amount' => 'required|numeric|min:0.01',
                'payment_method' => 'required|string|in:cash,cash_on_delivery,online,bank_transfer',
                // Allow backdating but never future-date — matches web modal.
                'payment_date' => 'nullable|date|before_or_equal:today',
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                // Which of our banks received the online payment. Old APKs
                // that don't know about this field will leave it null which
                // is exactly the legacy behaviour.
                'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
                // Invoice PAID-stamp display overrides — all optional.
                'sending_bank'    => 'nullable|string|max:100',
                'stamp_date'      => 'nullable|date',
                'stamp_ref_mode'  => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $amount = (float) $validated['amount'];
            $currentPaid = (float) ($order->total_paid ?? 0);
            $orderTotal = (float) $order->total_price;
            $remaining = $orderTotal - $currentPaid;

            if ($amount > $remaining + 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment amount (Rs. ' . number_format($amount) . ') exceeds remaining balance (Rs. ' . number_format($remaining) . ')'
                ], 422);
            }

            $paymentMethod = $validated['payment_method'];
            $paymentDate = $validated['payment_date'] ?? now()->toDateString();

            // Normalize cash_on_delivery to cash for ledger
            $normalizedMethod = in_array($paymentMethod, ['cash', 'cash_on_delivery']) ? 'cash' : $paymentMethod;
            $isOnline = in_array($normalizedMethod, ['online', 'bank_transfer', 'card']);

            // Determine if user is a manager (Taimur/Management) for auto-approval
            $user->load('roles');
            $roleNames = $user->roles->pluck('urole_name')->map(fn($n) => strtolower($n))->toArray();
            $isManager = in_array('taimur', $roleNames)
                || collect($roleNames)->contains(fn($n) => str_contains($n, 'management'))
                || $user->user_type === 'admin';

            \DB::beginTransaction();

            // Receiving bank only applies to online/bank_transfer — cash has
            // no bank. If the caller sent a receiving_account_id with a cash
            // payment, quietly ignore it rather than erroring out (old +
            // new APKs can both send the same payload shape).
            $receivingAccountId = $isOnline ? ($validated['receiving_account_id'] ?? null) : null;

            // Create payment record
            $payment = \App\Models\CRM\OrderPaymentModel::create([
                'order_id' => $order->id,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'receiving_account_id' => $receivingAccountId,
                'payment_date' => $paymentDate,
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            // Merge PAID-stamp metadata onto the order. Skip any key the
            // client didn't send so old APKs don't wipe stamp overrides a
            // user set previously on web.
            $stampUpdates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $stampUpdates['paid_stamp_sending_bank'] = $validated['sending_bank'];
            }
            if (array_key_exists('stamp_date', $validated)) {
                $stampUpdates['paid_stamp_date'] = $validated['stamp_date'];
            } else {
                // Auto-advance stamp date with the newest payment when no
                // explicit override was supplied.
                $stampUpdates['paid_stamp_date'] = $paymentDate;
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $stampUpdates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'];
            }
            if (!empty($stampUpdates)) {
                $order->fill($stampUpdates)->save();
            }

            // --- Ledger entry ---
            $salesAccount = \App\Models\FIN\ConfigModel::getSalesRevenueAccount();
            if (!$salesAccount) {
                throw new \Exception("Sales revenue account not configured");
            }

            $customerName = $order->name ?? trim(($order->address_first_name ?? '') . ' ' . ($order->address_last_name ?? '')) ?: 'Customer';
            $isQurbaniOrder = str_starts_with($order->order_number ?? '', 'QUR') || !empty($order->qurbani_day);
            $description = ($isQurbaniOrder ? "Qurbani" : "Order") . " Payment #{$order->order_number} - Rs. " . number_format($amount) . " ({$customerName})";

            if ($isOnline) {
                $toAccount = $isQurbaniOrder
                    ? \App\Models\FIN\ConfigModel::getQurbaniOnlineAccount()
                    : \App\Models\FIN\ConfigModel::getOnlineBankAccount();
                if (!$toAccount) throw new \Exception("Online account not configured");
                $mode = LedgerModel::MODE_ONLINE;
                $invoiceCategory = \App\Models\Request\RequestCategoryModel::getByCode('invoice_approval');
                $approvalStatus = LedgerModel::STATUS_APPROVED;
                if ($invoiceCategory) {
                    $config = $invoiceCategory->approvalConfig;
                    if ($config && $config->canAutoApprove($amount)) {
                        $approvalStatus = LedgerModel::STATUS_APPROVED;
                    } elseif ($invoiceCategory->requiresLevel1()) {
                        $approvalStatus = LedgerModel::STATUS_PENDING_L1;
                    }
                }
                $settlementStatus = $approvalStatus === LedgerModel::STATUS_APPROVED ? 'settled' : 'open';
            } elseif ($isManager) {
                $toAccount = $isQurbaniOrder
                    ? \App\Models\FIN\ConfigModel::getQurbaniCashAccount()
                    : \App\Models\FIN\ConfigModel::getNFCashAccount();
                if (!$toAccount) throw new \Exception("Cash account not configured");
                $mode = LedgerModel::MODE_CASH;
                $approvalStatus = LedgerModel::STATUS_APPROVED;
                $settlementStatus = 'settled';
            } else {
                // Rider cash → rider's employee cash (needs settlement via daily closing)
                $toAccount = AccountModel::createEmployeeCashAccount($user->id, $user->fullname ?? $user->name);
                $mode = LedgerModel::MODE_CASH;
                $approvalStatus = LedgerModel::STATUS_APPROVED;
                $settlementStatus = 'open';
            }

            $applyBalanceNow = in_array($approvalStatus, [
                LedgerModel::STATUS_APPROVED,
                LedgerModel::STATUS_PENDING_L2,
            ]);

            $ledger = LedgerModel::create([
                'transaction_date' => $paymentDate,
                'transaction_type' => LedgerModel::TYPE_ORDER_PAYMENT,
                'description' => $description,
                'from_account_id' => $salesAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $amount,
                'mode' => $mode,
                'approval_status' => $approvalStatus,
                'balance_updated' => $applyBalanceNow ? 1 : 0,
                'settlement_status' => $settlementStatus,
                'settled_amount' => $settlementStatus === 'settled' ? $amount : 0,
                'settled_at' => $settlementStatus === 'settled' ? now() : null,
                'approval_date' => $approvalStatus === LedgerModel::STATUS_APPROVED ? now() : null,
                'approved_by' => $approvalStatus === LedgerModel::STATUS_APPROVED ? $user->id : null,
                'order_id' => $order->id,
                'created_by' => $user->id,
            ]);

            if ($applyBalanceNow) {
                $salesAccount->current_balance -= $amount;
                $salesAccount->save();
                $toAccount->current_balance += $amount;
                $toAccount->save();
            }

            // Link ledger to payment
            $payment->ledger_transaction_id = $ledger->id;
            $payment->save();

            // Recalculate order payment status
            $order->recalculatePaymentStatus();

            \DB::commit();

            \Log::info('Qurbani payment added', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'ledger_id' => $ledger->id,
                'amount' => $amount,
                'method' => $paymentMethod,
                'by_role' => $isManager ? 'manager' : 'rider',
                'approval' => $approvalStatus,
                'settlement' => $settlementStatus,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment of Rs. ' . number_format($amount) . ' recorded successfully',
                'payment' => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'payment_date' => $payment->payment_date,
                    'ledger_transaction_id' => $ledger->id,
                ],
                'order_payment_status' => $order->payment_status,
                'order_total_paid' => (float) $order->total_paid,
                'order_balance_remaining' => max(0, (float)$order->total_price - (float)$order->total_paid),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            \DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('Failed to add order payment', ['order_id' => $orderId, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['success' => false, 'message' => 'Failed to record payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Mobile counterpart of OrderController@updateQurbaniPayment — lets the
     * app patch non-financial metadata (receiving bank, reference, notes,
     * stamp display fields) on an existing active payment. The ledger is
     * NEVER touched here; amount / method / date remain immutable (use the
     * void-and-readd flow for those).
     */
    public function updateOrderPayment(Request $request, $orderId, $paymentId)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = OrderModel::findOrFail($orderId);
            $payment = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
                ->where('id', $paymentId)
                ->first();

            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
            }
            if ($payment->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Voided payments cannot be edited'], 422);
            }

            $validated = $request->validate([
                'reference' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:1000',
                'receiving_account_id' => 'nullable|integer|exists:t_fin_online_receiving_accounts,id',
                'sending_bank'    => 'nullable|string|max:100',
                'stamp_date'      => 'nullable|date',
                'stamp_ref_mode'  => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $isOnline = in_array($payment->payment_method, ['online', 'bank_transfer', 'card']);

            $updates = [];
            if (array_key_exists('reference', $validated)) {
                $updates['reference'] = $validated['reference'];
            }
            if (array_key_exists('notes', $validated)) {
                $updates['notes'] = $validated['notes'];
            }
            if ($isOnline && array_key_exists('receiving_account_id', $validated)) {
                $updates['receiving_account_id'] = $validated['receiving_account_id'];
            }

            if (!empty($updates)) {
                $payment->fill($updates)->save();
            }

            $stampUpdates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $stampUpdates['paid_stamp_sending_bank'] = $validated['sending_bank'];
            }
            if (array_key_exists('stamp_date', $validated)) {
                $stampUpdates['paid_stamp_date'] = $validated['stamp_date'];
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $stampUpdates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'];
            }
            if (!empty($stampUpdates)) {
                $order->fill($stampUpdates)->save();
            }

            \Log::info('Order payment metadata updated', [
                'order_id' => $order->id,
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'updated_keys' => array_keys($updates),
                'stamp_keys' => array_keys($stampUpdates),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment updated',
                'payment' => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'notes' => $payment->notes,
                    'receiving_account_id' => $payment->receiving_account_id,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update order payment', [
                'order_id' => $orderId, 'payment_id' => $paymentId, 'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => 'Failed to update payment: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Mobile counterpart of OrderController@deleteQurbaniPayment — voids a
     * single payment on an order and reverses its ledger + account-balance
     * impact. Keeps the row (status='voided') for audit but hides it from
     * every "active" query the rest of the app uses.
     */
    public function deleteOrderPayment(Request $request, $orderId, $paymentId)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $order = OrderModel::findOrFail($orderId);
            $payment = \App\Models\CRM\OrderPaymentModel::where('order_id', $order->id)
                ->where('id', $paymentId)
                ->first();

            if (!$payment) {
                return response()->json(['success' => false, 'message' => 'Payment not found'], 404);
            }
            if ($payment->status !== 'active') {
                return response()->json(['success' => false, 'message' => 'Payment is not active'], 422);
            }

            \DB::beginTransaction();
            try {
                $amount = (float) $payment->amount;

                $ledger = $payment->ledger_transaction_id
                    ? \App\Models\FIN\LedgerModel::find($payment->ledger_transaction_id)
                    : null;

                if ($ledger) {
                    $toAccount = \App\Models\FIN\AccountModel::find($ledger->to_account_id);
                    if ($toAccount) {
                        $toAccount->current_balance = (float) $toAccount->current_balance - $amount;
                        $toAccount->save();
                    }
                    $fromAccount = \App\Models\FIN\AccountModel::find($ledger->from_account_id);
                    if ($fromAccount) {
                        $fromAccount->current_balance = (float) $fromAccount->current_balance + $amount;
                        $fromAccount->save();
                    }
                    $ledger->delete();
                }

                $payment->status = 'voided';
                $payment->updated_by = $user->id;
                $payment->save();

                $order->recalculatePaymentStatus();

                \DB::commit();
            } catch (\Exception $inner) {
                \DB::rollBack();
                throw $inner;
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment voided',
                'order'   => [
                    'total_paid'        => (float) $order->total_paid,
                    'payment_status'    => $order->payment_status,
                    'balance_remaining' => max(0, (float) $order->total_price - (float) $order->total_paid),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to void order payment (mobile)', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Display-only edit of the invoice PAID stamp. Mirrors
     * OrderController@updatePaidStamp so mobile can let the team tweak
     * the sending bank / stamp date / third-line reference mode AFTER
     * a payment has already been recorded — without creating, editing
     * or voiding any payment row. Financial state is untouched.
     */
    public function updatePaidStamp(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $order = OrderModel::findOrFail($id);

            $validated = $request->validate([
                'sending_bank'   => 'nullable|string|max:100',
                'stamp_date'     => 'nullable|date',
                'stamp_ref_mode' => 'nullable|string|in:reference,customer_name,blank',
            ]);

            $updates = [];
            if (array_key_exists('sending_bank', $validated)) {
                $updates['paid_stamp_sending_bank'] = $validated['sending_bank'] !== '' ? $validated['sending_bank'] : null;
            }
            if (array_key_exists('stamp_date', $validated)) {
                $updates['paid_stamp_date'] = $validated['stamp_date'] !== '' ? $validated['stamp_date'] : null;
            }
            if (array_key_exists('stamp_ref_mode', $validated)) {
                $updates['paid_stamp_ref_mode'] = $validated['stamp_ref_mode'] ?: 'reference';
            }

            if (!empty($updates)) {
                $updates['updated_by'] = $user->id;
                $order->fill($updates)->save();
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Invoice stamp updated',
                'paid_stamp' => [
                    'sending_bank' => $order->paid_stamp_sending_bank,
                    'date'         => $order->paid_stamp_date
                        ? \Carbon\Carbon::parse($order->paid_stamp_date)->format('Y-m-d')
                        : null,
                    'ref_mode'     => $order->paid_stamp_ref_mode ?: 'reference',
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to update paid stamp (mobile)', ['order_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}

