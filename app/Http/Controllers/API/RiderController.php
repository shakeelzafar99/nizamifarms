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
                    // ⭐ Order note - specific to this order
                    'notes' => $order->note,
                    'order_note' => $order->note,
                    'has_order_note' => !empty($order->note),
                    // ⭐ Customer notes - customer-level notes (applies to all orders for this customer)
                    'customer_notes' => $order->customer ? ($order->customer->notes ?? null) : null,
                    'has_customer_notes' => $order->customer && !empty($order->customer->notes),
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
                'limit' => 4500, // Safe limit (5000 free)
                'remaining' => 4500 - ($googleUsage->call_count ?? 0),
                'at_limit' => ($googleUsage->call_count ?? 0) >= 4500,
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
        // Check monthly usage limit (4500 to stay safely under 5000 free tier)
        $monthKey = date('Y-m');
        $usage = \DB::table('t_sys_api_usage')
            ->where('api_name', 'google_directions')
            ->where('month_key', $monthKey)
            ->first();
        
        $currentCount = $usage->call_count ?? 0;
        if ($currentCount >= 4500) {
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
            
            // Determine traffic status
            $traffic = null;
            if (isset($route['duration_in_traffic']) && isset($route['duration'])) {
                $ratio = $route['duration_in_traffic']['value'] / $route['duration']['value'];
                if ($ratio > 1.5) $traffic = 'heavy';
                elseif ($ratio > 1.2) $traffic = 'moderate';
                else $traffic = 'light';
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
            // OpenRouteService supports up to 50 waypoints, we'll use ~25 for safety
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
            
            return response()->json([
                'success' => true,
                'road_distance' => round($roadDistance, 1),
                'straight_distance' => $straightResult['distance'],
                'readings_count' => $readings->count(),
                'sampled_points' => count($sampledReadings),
                'accuracy_ratio' => $straightResult['distance'] > 0 
                    ? round($roadDistance / $straightResult['distance'] * 100) . '%' 
                    : null,
                'source' => 'openrouteservice'
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
     * ⭐ Sample GPS readings to reduce API calls while maintaining route accuracy
     * Uses time-based sampling to ensure we capture the full journey
     */
    private function sampleGpsReadings(array $readings, int $maxPoints = 25): array
    {
        $count = count($readings);
        
        if ($count <= $maxPoints) {
            return $readings;
        }
        
        $sampled = [];
        $step = ($count - 1) / ($maxPoints - 1);
        
        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            if ($index < $count) {
                $sampled[] = $readings[$index];
            }
        }
        
        // Always include first and last point
        if (!in_array($readings[0], $sampled)) {
            array_unshift($sampled, $readings[0]);
        }
        if (!in_array($readings[$count - 1], $sampled)) {
            $sampled[] = $readings[$count - 1];
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

            return response()->json([
                'success' => true,
                'message' => 'Checked out successfully at ' . date('h:i A', strtotime($currentTime)),
                'logout_time' => $currentTime,
                'picture_url' => $picturePath ? $this->getMeterPictureUrl($picturePath) : null,
                'location_captured' => $locationData ? true : false,
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
            
            $locationTrail = $locationQuery
                ->orderBy('captured_at', 'desc')
                ->limit($isHistory ? 50 : 10) // More points for history
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get()
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
            
            $ordersQuery = \DB::table('t_crm_prod_order as o')
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->leftJoin('t_crm_order_status_history as osh', function($join) {
                    $join->on('o.id', '=', 'osh.order_id')
                         ->where('osh.status_code', '=', 'delivered');
                })
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
            
            $locations = $query
                ->orderBy('captured_at', 'asc') // Oldest first for grouping
                ->select('latitude', 'longitude', 'accuracy', 'captured_at', 'source')
                ->get();
            
            if ($locations->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'locations' => [],
                    'total_points' => 0,
                    'grouped_points' => 0,
                ]);
            }
            
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
            
            // Get all delivered orders for this rider in the last 3 months
            $orders = \DB::table('t_crm_prod_order as o')
                ->join('t_crm_order_status_history as osh', function($join) {
                    $join->on('o.id', '=', 'osh.order_id')
                         ->where('osh.status_code', '=', 'delivered');
                })
                ->leftJoin('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('o.assigned_rider_user_id', $riderId)
                ->where('osh.changed_at', '>=', $threeMonthsAgo)
                ->select([
                    'o.id',
                    'o.order_number',
                    'o.customer_id',
                    'o.total_price',
                    'o.payment_method',
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
                'expense_date' => 'nullable|date', // ⭐ For backdated expense entries
                'leave_start_date' => 'nullable|date',
                'leave_end_date' => 'nullable|date|after_or_equal:leave_start_date',
                'leave_type' => 'nullable|string',
            ]);

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
                'expense_date' => $validated['expense_date'] ?? now()->toDateString(), // ⭐ Expense date (defaults to today)
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
            
            return response()->json([
                'success' => true,
                'permissions' => $permissions,
                'has_store_mode' => in_array('access_store_mode', $permissions),
                'expense_backdate_days' => (int)$expenseBackdateDays // ⭐ Include backdate days
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
                    // ⭐ Customer Notes - for important customer-level information
                    'customer_notes' => $order->customer ? ($order->customer->notes ?? null) : null,
                    'has_customer_notes' => $order->customer && !empty($order->customer->notes),
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
                    // ⭐ Include 'notes' for customer notes display, 'phone_original' for contact
                    // ⭐ Include verified_location_saved_by and saved_at for showing who saved location
                    $q->select('id', 'first_name', 'last_name', 'phone_original', 'latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude', 'verified_location_url', 'notes', 'verified_location_saved_by', 'verified_location_saved_at');
                }])
                ->with(['assignedRider' => function($q) {
                    $q->select('id', 'fullname');
                }])
                ->with(['lineItems' => function($q) {
                    // Load essential line item fields for marking prepared
                    $q->select('id', 'order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total', 'preparation_status');
                }])
                ->with(['discounts']) // ⭐ Load discounts for invoice view
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
                
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number ?? 'NF-' . str_pad($order->id, 4, '0', STR_PAD_LEFT),
                    'order_date' => $order->order_date,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                    'delivery_priority' => $order->delivery_priority, // ⭐ Delivery sequence priority
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
                    // ⭐ Customer Notes - for important customer-level information (e.g., delivery instructions)
                    'customer_notes' => $order->customer ? ($order->customer->notes ?? null) : null,
                    'has_customer_notes' => $order->customer && !empty($order->customer->notes),
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
                    // ⭐ Customer location data for route map
                    'customer' => $order->customer ? [
                        'latitude' => $order->customer->latitude,
                        'longitude' => $order->customer->longitude,
                        'geocoded_latitude' => $order->customer->geocoded_latitude,
                        'geocoded_longitude' => $order->customer->geocoded_longitude,
                    ] : null,
                    'external_source' => $order->external_source,
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
                            'name' => $item->name ?? 'N/A',  // ⭐ Use 'name' consistently
                            'product_name' => $item->name ?? 'N/A',
                            'variant_name' => $item->sku ?? '',
                            'quantity' => (float) $item->quantity,
                            'unit_price' => (float) $item->unit_price,
                            'unit_price_formatted' => $item->unit_price ? 'Rs. ' . number_format($item->unit_price, 0) : null,
                            'line_total' => (float) $item->line_total,
                            'preparation_status' => $item->preparation_status,
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
            ];
            
        } catch (\Exception $e) {
            \Log::error('Failed to get rider dashboard summary', [
                'rider_id' => $riderId,
                'error' => $e->getMessage()
            ]);
            return null;
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
                    // ⭐ Customer Notes - for important customer-level information
                    'customer_notes' => $order->customer ? ($order->customer->notes ?? null) : null,
                    'has_customer_notes' => $order->customer && !empty($order->customer->notes),
                    // ⭐ Order Notes - order-specific notes
                    'order_note' => $order->note ?? null,
                    'has_order_note' => !empty($order->note),
                    'has_verified_location' => $hasVerifiedLocation,
                    'verified_location' => $verifiedLocation,
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
                'rider_id' => 'required|exists:t_sys_user,id'
            ]);
            
            $order = OrderModel::findOrFail($validated['order_id']);
            
            // ⚠️ SAFETY CHECK: Don't allow rider change for delivered/completed orders
            if (in_array($order->order_status, ['delivered', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot change rider for delivered or completed orders'
                ], 422);
            }
            
            // Get rider name for response
            $rider = DB::table('t_sys_user')->where('id', $validated['rider_id'])->first();
            
            if (!$rider) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rider not found'
                ], 404);
            }
            
            // ✅ Use the model method to properly create history records
            // This ensures rider history is tracked just like web UI does
            $success = $order->assignRider(
                $validated['rider_id'],
                'Assigned via Store Mode',  // Notes
                $user->id                    // Assigned by user ID
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
                // ✅ EXCLUDE prepared items from quantities (same as web)
                ->where(function($q) {
                    $q->whereNull('li.preparation_status')
                      ->orWhere('li.preparation_status', '!=', 'preparing');
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
            
            \Log::debug('Expense filter params received', [
                'month' => $month,
                'category' => $category,
                'settlement_status' => $settlementStatus,
                'all_params' => $request->all()
            ]);
            
            // Build base query for expenses and salary advances
            $expensesQuery = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->whereNotNull('ledger_transaction_id')
                ->where('status', \App\Models\Request\RequestModel::STATUS_APPROVED)
                ->with(['requester', 'paymentSourceAccount', 'category', 'settledBy', 'settlementDestinationAccount']);
            
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
            
            // Get expense fund balance
            $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            
            // Get pending approvals (real-time, not filtered by month)
            // Include approvals relationship to check L1/L2 status
            $pendingApprovals = \App\Models\Request\RequestModel::whereHas('category', function($q) {
                    $q->whereIn('category_code', ['expense', 'salary_advance']);
                })
                ->where('status', \App\Models\Request\RequestModel::STATUS_PENDING)
                ->with(['requester', 'paymentSourceAccount', 'category', 'approvals.approver'])
                ->orderBy('created_at', 'asc')
                ->get();
            
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
            
            // ⭐ Check user's approval rights
            $hasL1Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 1);
            $hasL2Rights = \App\Models\SysAdmin\RoleApprovalLevelModel::userHasApprovalLevel($user->id, 2);
            
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
                        'top_categories' => $topCategories
                    ],
                    // ⭐ User's approval rights
                    'user_approval_rights' => [
                        'has_l1' => $hasL1Rights,
                        'has_l2' => $hasL2Rights,
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
            
            // Get expense fund account
            $expenseFund = \App\Models\FIN\ConfigModel::getExpenseFundingAccount() 
                ?? \App\Models\FIN\AccountModel::where('account_code', 'EXP_FUND')->first();
            
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
            
            // Build query for active accounts
            $query = \App\Models\FIN\AccountModel::where('is_active', 1);
            
            // Show both company accounts (cash, bank) and employee accounts
            $query->where(function($q) {
                $q->where('account_category', \App\Models\FIN\AccountModel::CATEGORY_EMPLOYEE_CASH)
                  ->orWhereIn('account_category', [\App\Models\FIN\AccountModel::CATEGORY_CASH, \App\Models\FIN\AccountModel::CATEGORY_BANK]);
            });
            
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
                    $hours = $logout->diffInHours($login, true);
                    
                    // Check if late
                    $shiftStart = \Carbon\Carbon::parse($shiftData['shift_start']);
                    if ($login->gt($shiftStart)) {
                        $isLate = true;
                        $lateMinutes = $login->diffInMinutes($shiftStart);
                    }
                    
                    // Check if overtime
                    $shiftEnd = \Carbon\Carbon::parse($shiftData['shift_end']);
                    if ($logout->gt($shiftEnd)) {
                        $isOvertime = true;
                        $overtimeMinutes = $logout->diffInMinutes($shiftEnd);
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
                    // ⭐ GPS distance will be calculated in batch after the loop
                    'gps_distance' => null,
                    'gps_readings_count' => 0,
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
            
            // Add previous meter info to each employee
            foreach ($formattedData as &$employee) {
                $prev = $previousMeterEnds[$employee['user_id']] ?? null;
                $employee['prev_meter_end'] = $prev ? $prev['meter_end'] : null;
                $employee['prev_meter_date'] = $prev ? $prev['date'] : null;
                
                // Calculate meter gap if both values exist
                $employee['meter_gap'] = null;
                if ($prev && $employee['meter_start']) {
                    $gap = (int)$employee['meter_start'] - (int)$prev['meter_end'];
                    $employee['meter_gap'] = $gap; // Can be 0, positive, or negative
                }
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
                        
                        // ⭐ Calculate road distance for most recent day OR if straight-line > 0.5km
                        $record->road_distance = null;
                        $record->road_source = null;
                        $record->gap_info = null;
                        
                        if ($recordDate === $autoCalcRoadDate && $record->gps_distance !== null && $record->gps_distance >= 0.5) {
                            // Auto-calculate road distance for most recent day
                            $sampledReadings = $this->sampleGpsReadings($readings, 25);
                            if (count($sampledReadings) >= 2) {
                                $roadDist = $this->callOpenRouteService($sampledReadings);
                                if ($roadDist !== null) {
                                    $record->road_distance = round($roadDist, 1);
                                    $record->road_source = 'openrouteservice';
                                    $totalRoadDistance += $roadDist;
                                    $daysWithRoadDistance++;
                                }
                            }
                        } elseif ($record->gps_distance !== null && $record->gps_distance < 0.5) {
                            $record->road_source = 'skipped_stationary';
                        }
                        
                        // ⭐ Calculate gap info for this day
                        if ($record->login_time && count($readings) >= 2) {
                            $gapInfo = $this->calculateGapInfo($readings, $record->login_time, $record->logout_time);
                            $record->gap_info = $gapInfo;
                        }
                    } else {
                        $record->gps_distance = null;
                        $record->gps_readings_count = 0;
                        $record->road_distance = null;
                        $record->road_source = null;
                        $record->gap_info = null;
                    }
                }
            } else {
                // No attendance, set all GPS values to null
                foreach ($query as $record) {
                    $record->gps_distance = null;
                    $record->gps_readings_count = 0;
                    $record->road_distance = null;
                    $record->road_source = null;
                    $record->gap_info = null;
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
            
            foreach ($users as $user) {
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
                $lateDays = 0;
                $lateMinutes = 0;
                $overtimeDays = 0;
                $overtimeMinutes = 0;
                
                $shiftStart = \Carbon\Carbon::parse($user->shift_start);
                $shiftEnd = \Carbon\Carbon::parse($user->shift_end);
                
                foreach ($attendance as $record) {
                    if ($record->login_time && $record->logout_time) {
                        $login = \Carbon\Carbon::parse($record->login_time);
                        $logout = \Carbon\Carbon::parse($record->logout_time);
                        $totalHours += $logout->diffInHours($login, true);
                        
                        if ($login->gt($shiftStart)) {
                            $lateDays++;
                            $lateMinutes += $login->diffInMinutes($shiftStart);
                        }
                        
                        if ($logout->gt($shiftEnd)) {
                            $overtimeDays++;
                            $overtimeMinutes += $logout->diffInMinutes($shiftEnd);
                        }
                    }
                }
                
                // Calculate working days using ShiftResolutionService (same as web app)
                $shiftService = new \App\Services\ShiftResolutionService();
                try {
                    $workingDays = $shiftService->calculateWorkingDays($user->user_id, $startDate, $endDate);
                } catch (\Exception $e) {
                    // Fallback to simple weekday calculation
                    $workingDays = \Carbon\Carbon::parse($startDate)->diffInDaysFiltered(function(\Carbon\Carbon $date) use ($endDate) {
                        return $date->isWeekday() && $date->lte(\Carbon\Carbon::parse($endDate));
                    }, \Carbon\Carbon::parse($endDate));
                }
                
                $absentDays = max(0, $workingDays - $presentDays - $leaveDays);
                
                $monthlyData[] = [
                    'user_id' => $user->user_id,
                    'fullname' => $user->fullname,
                    'role_name' => $user->role_name,
                    'shift_name' => $user->shift_start . ' - ' . $user->shift_end,
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
            
            // Check if from account has sufficient balance for asset accounts
            if ($fromAccount->account_type === 'asset') {
                if ($fromAccount->current_balance < $request->amount) {
                    return response()->json([
                        'success' => false,
                        'message' => "Insufficient balance in {$fromAccount->account_name}. Current balance: Rs. " . number_format($fromAccount->current_balance, 2)
                    ], 400);
                }
            }
            
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
            
            // Get filter parameters
            $period = $request->input('period', 'all_time'); // today, this_week, this_month, all_time
            
            // Build ledger query - INCLUDE BOTH APPROVED AND PENDING
            $ledgerQuery = \App\Models\FIN\LedgerModel::where(function($q) use ($accountId) {
                $q->where('from_account_id', $accountId)
                  ->orWhere('to_account_id', $accountId);
            })
            ->whereIn('approval_status', [
                \App\Models\FIN\LedgerModel::STATUS_APPROVED,
                \App\Models\FIN\LedgerModel::STATUS_PENDING
            ]);
            
            // Apply period filter
            if ($period === 'today') {
                $ledgerQuery->whereDate('transaction_date', today());
            } elseif ($period === 'this_week') {
                $ledgerQuery->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()]);
            } elseif ($period === 'this_month') {
                $ledgerQuery->whereBetween('transaction_date', [now()->startOfMonth(), now()->endOfMonth()]);
            }
            
            // Get transactions
            $transactions = $ledgerQuery
                ->with(['fromAccount', 'toAccount'])
                ->orderBy('transaction_date', 'desc')
                ->orderBy('id', 'desc')
                ->limit(100)
                ->get();
            
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
                ],
                'summary' => [
                    'total_in' => $totalIn,
                    'total_out' => $totalOut,
                    'current_balance' => $effectiveBalance,
                ],
                'grouped_transactions' => $groupedTransactions,
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
            
            // Calculate KPIs (same logic as web app)
            $kpis = $this->calculateOverallLedgerKPIs($startDate, $endDate);
            
            // Get recent transactions (limited to 50 for mobile)
            $query = \App\Models\FIN\LedgerModel::with(['fromAccount', 'toAccount', 'order']);
            
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
            $invoicesQuery = LedgerModel::where('transaction_type', LedgerModel::TYPE_INVOICE)
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
            
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'invoices_by_rider' => $invoicesByRider,
                'invoices_by_date' => $invoicesByDate, // ⭐ New: date-grouped data for settled view
                'online_summary' => $onlineData, // ⭐ New: online summary
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
                ->select(['order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total'])
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
                
                // Check for verified location - either from lat/lng columns or parse from URL
                $verifiedLat = $order->verified_lat ? (float)$order->verified_lat : null;
                $verifiedLng = $order->verified_lng ? (float)$order->verified_lng : null;
                
                // If no stored coords but URL exists, try to resolve and parse coords from URL
                if ((!$verifiedLat || !$verifiedLng) && $order->verified_location_url) {
                    // Resolve shortened URLs (maps.app.goo.gl, etc.)
                    $resolvedUrl = $this->resolveGoogleMapsUrl($order->verified_location_url);
                    $parsedCoords = $this->parseCoordinatesFromGoogleMapsUrl($resolvedUrl);
                    if ($parsedCoords) {
                        $verifiedLat = $parsedCoords['latitude'];
                        $verifiedLng = $parsedCoords['longitude'];
                    }
                }
                
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
                    ];
                })->values()->toArray();
                
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
                ->select(['order_id', 'name', 'sku', 'quantity', 'unit_price', 'line_total'])
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
}

