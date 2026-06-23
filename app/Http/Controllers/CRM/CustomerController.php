<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerModel::query();
        
        // Exclude merged customers (they are hidden after merge)
        $query->whereNull('merged_into_customer_id');
        
        // Search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('first_name', 'like', "%{$searchTerm}%")
                  ->orWhere('last_name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                  ->orWhere('phone_original', 'like', "%{$searchTerm}%")
                  ->orWhere('phone_normalized', 'like', "%{$searchTerm}%")
                  ->orWhere('company', 'like', "%{$searchTerm}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"]);
            });
        }
        
        // Filter by city
        if ($request->has('city') && $request->city) {
            $query->where('city', $request->city);
        }

        // Filter by delivery region
        if ($request->has('region') && $request->region) {
            if ($request->region === 'none') {
                $query->whereNull('delivery_region_id');
            } else {
                $query->where('delivery_region_id', $request->region);
            }
        }
        
        // Filter by activity period (30-day or 90-day active)
        $activity = $request->get('activity', '');
        if ($activity === '30day') {
            $query->where('last_order_date', '>=', now()->subDays(30));
        } elseif ($activity === '90day') {
            $query->where('last_order_date', '>=', now()->subDays(90));
        }

        // Filter by customer type (regular / shop). Default (empty) = all.
        $customerType = $request->get('customer_type', '');
        if (in_array($customerType, [CustomerModel::TYPE_REGULAR, CustomerModel::TYPE_SHOP], true)) {
            $query->where('customer_type', $customerType);
        }
        
        // Sorting: support sort_by (last_order_date, total_spent) and sort_dir (asc, desc)
        $sortBy = $request->get('sort_by', 'last_order_date');
        $sortDir = $request->get('sort_dir', 'desc');
        
        // Validate sort parameters
        $allowedSortFields = ['last_order_date', 'total_spent', 'created_at'];
        $allowedSortDirs = ['asc', 'desc'];
        if (!in_array($sortBy, $allowedSortFields)) $sortBy = 'last_order_date';
        if (!in_array($sortDir, $allowedSortDirs)) $sortDir = 'desc';
        
        // For total_spent sorting, use a subquery to sort by COMBINED total (production + history)
        // This ensures the sort order matches the displayed combined values
        if ($sortBy === 'total_spent') {
            $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
            
            $prodSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_prod_order WHERE t_crm_prod_order.customer_id = t_crm_prod_customer.id AND (external_source != 'shopify' OR external_source IS NULL) AND order_status IN ('delivered', 'completed'))";
            
            if ($hasHistoryTable) {
                $histSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_history_order WHERE t_crm_history_order.customer_id = t_crm_prod_customer.id AND order_status = 'delivered')";
                $query->orderByRaw("($prodSubquery + $histSubquery) $sortDir");
            } else {
                $query->orderByRaw("$prodSubquery $sortDir");
            }
        } else {
            $query->orderBy($sortBy, $sortDir);
        }
        
        $customers = $query->orderBy('created_at', 'desc')
                          ->paginate(15);
        
        // =========================================
        // HYBRID APPROACH: Add combined stats to paginated results
        // =========================================
        $customerIds = $customers->pluck('id')->toArray();
        if (!empty($customerIds)) {
            $combinedStats = $this->getCombinedCustomerStats($customerIds);
            
            // Transform paginator items to include combined stats
            $customers->getCollection()->transform(function($customer) use ($combinedStats) {
                $stats = $combinedStats[$customer->id] ?? null;
                if ($stats) {
                    $customer->total_orders = $stats->combined_order_count;
                    $customer->total_spent = $stats->combined_total_spent;
                }
                return $customer;
            });
        }
        
        // Get unique cities for filter dropdown
        $cities = CustomerModel::whereNotNull('city')
                              ->where('city', '!=', '')
                              ->distinct()
                              ->pluck('city')
                              ->sort()
                              ->values();

        // Get delivery regions for filter dropdown
        $regions = DB::table('t_ops_delivery_region')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code']);

        // Build region name map for customer list
        $regionMap = $regions->pluck('name', 'id')->toArray();
        $customers->getCollection()->transform(function($customer) use ($regionMap) {
            $customer->delivery_region_name = $customer->delivery_region_id
                ? ($regionMap[$customer->delivery_region_id] ?? null) : null;
            return $customer;
        });
        
        // Get statistics
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $ninetyDaysAgo = $now->copy()->subDays(90);
        
        $stats = [
            'total_customers' => CustomerModel::count(),
            'active_30_days' => CustomerModel::where('last_order_date', '>=', $thirtyDaysAgo)->count(),
            'active_90_days' => CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)->count()
        ];
        
        return view('pages.customers.index', compact('customers', 'cities', 'regions', 'stats'));
    }
    
    public function show(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // =========================================
            // Get COMBINED orders (Production + History)
            // =========================================
            
            // 1. Get production orders (non-Shopify)
            $prodOrders = DB::table('t_crm_prod_order')
                ->where('customer_id', $id)
                ->where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->select(
                    'id', 'order_number', 'order_date', 'order_status', 
                    'external_source', 'total_price',
                    DB::raw("'production' as source_type")
                )
                ->get();
            
            // 2. Get history orders (if table exists)
            $historyOrders = collect();
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyOrders = DB::table('t_crm_history_order')
                    ->where('customer_id', $id)
                    ->select(
                        'id', 'order_number', 'order_date', 'order_status',
                        'external_source', 'total_price',
                        DB::raw("'history' as source_type")
                    )
                    ->get();
            }
            
            // 3. Combine and sort by date (newest first), take last 10
            $allOrders = $prodOrders->concat($historyOrders)
                ->sortByDesc('order_date')
                ->take(10)
                ->values();
            
            // =========================================
            // Calculate COMBINED stats (Prod + History)
            // =========================================
            
            // Production stats (non-Shopify, delivered/completed)
            $prodStats = DB::table('t_crm_prod_order')
                ->where('customer_id', $id)
                ->where(function($query) {
                    $query->where('external_source', '!=', 'shopify')
                          ->orWhereNull('external_source');
                })
                ->whereIn('order_status', ['delivered', 'completed'])
                ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_price), 0) as total_spent')
                ->first();
            
            // History stats (delivered only)
            $historyStats = (object)['order_count' => 0, 'total_spent' => 0];
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyStats = DB::table('t_crm_history_order')
                    ->where('customer_id', $id)
                    ->where('order_status', 'delivered')
                    ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_price), 0) as total_spent')
                    ->first();
            }
            
            // Combined totals
            $combinedOrderCount = ($prodStats->order_count ?? 0) + ($historyStats->order_count ?? 0);
            $combinedTotalSpent = ($prodStats->total_spent ?? 0) + ($historyStats->total_spent ?? 0);
            
            // Override customer stats with combined values for display
            $customer->total_orders = $combinedOrderCount;
            $customer->total_spent = $combinedTotalSpent;
            $customer->orders = $allOrders; // Set combined orders for the modal
            
            // Add verified location metadata
            $verifiedLocation = null;
            if ($customer->verified_location_url || ($customer->latitude && $customer->longitude)) {
                $verifiedLocation = [
                    'latitude' => $customer->latitude,
                    'longitude' => $customer->longitude,
                    'url' => $customer->verified_location_url,
                    'google_maps_url' => $customer->verified_location_url ?: 
                        ($customer->latitude && $customer->longitude ? 
                            "https://www.google.com/maps?q={$customer->latitude},{$customer->longitude}" : null),
                    'saved_by' => \App\Models\CRM\CustomerModel::verifierLabel($customer->verified_location_saved_by),
                    'saved_at' => $customer->verified_location_saved_at,
                ];
            }
            
            // =========================================
            // Get customers that were MERGED INTO this one
            // =========================================
            $mergedCustomers = DB::table('t_crm_prod_customer')
                ->where('merged_into_customer_id', $id)
                ->select('id', 'first_name', 'last_name', 'phone', 'phone_normalized', 'merged_at', 'merged_by')
                ->get()
                ->map(function($merged) {
                    // Get merger's name
                    $mergerName = DB::table('t_sys_user')->where('id', $merged->merged_by)->value('fullname');
                    return [
                        'id' => $merged->id,
                        'name' => trim(($merged->first_name ?? '') . ' ' . ($merged->last_name ?? '')),
                        'phone' => $merged->phone,
                        'phone_normalized' => $merged->phone_normalized,
                        'merged_at' => $merged->merged_at,
                        'merged_by_name' => $mergerName,
                    ];
                });
            
            $deliveryRegionName = null;
            if (!empty($customer->delivery_region_id)) {
                $deliveryRegionName = \DB::table('t_ops_delivery_region')
                    ->where('id', $customer->delivery_region_id)
                    ->value('name');
            }

            return response()->json([
                'success' => true,
                'customer' => $customer,
                'verified_location' => $verifiedLocation,
                'merged_customers' => $mergedCustomers,
                'delivery_region_name' => $deliveryRegionName,
                'stats_breakdown' => [
                    'production' => [
                        'order_count' => $prodStats->order_count ?? 0,
                        'total_spent' => $prodStats->total_spent ?? 0
                    ],
                    'history' => [
                        'order_count' => $historyStats->order_count ?? 0,
                        'total_spent' => $historyStats->total_spent ?? 0
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found: ' . $e->getMessage()
            ], 404);
        }
    }
    
    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            $customers = \App\Models\CRM\CustomerModel::query()
                ->whereNull('merged_into_customer_id') // Exclude merged customers
                ->where(function($q) use ($query) {
                    $q->where('first_name', 'LIKE', "%{$query}%")
                      ->orWhere('last_name', 'LIKE', "%{$query}%")
                      ->orWhere('email', 'LIKE', "%{$query}%")
                      ->orWhere('phone', 'LIKE', "%{$query}%")
                      ->orWhere('phone_original', 'LIKE', "%{$query}%")
                      ->orWhere('phone_normalized', 'LIKE', "%{$query}%")
                      ->orWhere('company', 'LIKE', "%{$query}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$query}%"]);
                })
                ->limit($limit)
                ->get();
            
            $results = $customers->map(function($customer) {
                return [
                    'id' => $customer->id,
                    'name' => trim($customer->first_name . ' ' . $customer->last_name),
                    'email' => $customer->email,
                    'phone' => $customer->phone_original,
                    'notes' => $customer->notes,
                    'customer_type' => $customer->customer_type, // regular | shop (drives default online payment)
                    'address' => [
                        'first_name' => $customer->first_name,
                        'last_name' => $customer->last_name,
                        'company' => $customer->company,
                        'email' => $customer->email,
                        'phone' => $customer->phone_original,
                        'address1' => $customer->address1,
                        'address2' => $customer->address2,
                        'city' => $customer->city,
                        'province' => $customer->province,
                        'postal_code' => $customer->postal_code,
                        'country' => $customer->country
                    ]
                ];
            });
            
            return response()->json([
                'success' => true,
                'customers' => $results
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search customers: ' . $e->getMessage()
            ], 500);
        }
    }

    public function filter(Request $request)
    {
        try {
            $search = $request->get('search', '');
            $city = $request->get('city', '');
            $activity = $request->get('activity', '');
            
            // Start with base query
            $query = CustomerModel::query();
            
            // Exclude merged customers (they are hidden after merge)
            $query->whereNull('merged_into_customer_id');
            
            // Apply search filter
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'LIKE', "%{$search}%")
                      ->orWhere('last_name', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%")
                      ->orWhere('phone_original', 'LIKE', "%{$search}%")
                      ->orWhere('phone_normalized', 'LIKE', "%{$search}%")
                      ->orWhere('company', 'LIKE', "%{$search}%")
                      ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                });
            }
            
            // Apply city filter
            if (!empty($city)) {
                $query->where('city', $city);
            }

            // Apply region filter
            $region = $request->get('region', '');
            if (!empty($region)) {
                if ($region === 'none') {
                    $query->whereNull('delivery_region_id');
                } else {
                    $query->where('delivery_region_id', $region);
                }
            }
            
            // Apply activity filter
            if ($activity === '30day') {
                $query->where('last_order_date', '>=', now()->subDays(30));
            } elseif ($activity === '90day') {
                $query->where('last_order_date', '>=', now()->subDays(90));
            }

            // Apply customer type filter (regular / shop). Default (empty) = all.
            $customerType = $request->get('customer_type', '');
            if (in_array($customerType, [CustomerModel::TYPE_REGULAR, CustomerModel::TYPE_SHOP], true)) {
                $query->where('customer_type', $customerType);
            }
            
            // Sorting
            $sortBy = $request->get('sort_by', 'last_order_date');
            $sortDir = $request->get('sort_dir', 'desc');
            $allowedSortFields = ['last_order_date', 'total_spent', 'created_at'];
            $allowedSortDirs = ['asc', 'desc'];
            if (!in_array($sortBy, $allowedSortFields)) $sortBy = 'last_order_date';
            if (!in_array($sortDir, $allowedSortDirs)) $sortDir = 'desc';
            
            // For total_spent sorting, use a subquery to sort by COMBINED total (production + history)
            if ($sortBy === 'total_spent') {
                $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
                
                $prodSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_prod_order WHERE t_crm_prod_order.customer_id = t_crm_prod_customer.id AND (external_source != 'shopify' OR external_source IS NULL) AND order_status IN ('delivered', 'completed'))";
                
                if ($hasHistoryTable) {
                    $histSubquery = "(SELECT COALESCE(SUM(total_price), 0) FROM t_crm_history_order WHERE t_crm_history_order.customer_id = t_crm_prod_customer.id AND order_status = 'delivered')";
                    $query->orderByRaw("($prodSubquery + $histSubquery) $sortDir");
                } else {
                    $query->orderByRaw("$prodSubquery $sortDir");
                }
            } else {
                $query->orderBy($sortBy, $sortDir);
            }
            
            // Get results (limit to 100 for performance)
            $customers = $query->orderBy('created_at', 'desc')
                             ->limit(100)
                             ->get();
            
            // =========================================
            // HYBRID APPROACH: Fetch combined stats efficiently
            // Physical table data + calculated order stats
            // =========================================
            $customerIds = $customers->pluck('id')->toArray();
            
            if (!empty($customerIds)) {
                // Get combined stats from both production and history in ONE query
                $combinedStats = $this->getCombinedCustomerStats($customerIds);
                
                // Merge combined stats into customer objects
                $regionMap = DB::table('t_ops_delivery_region')
                    ->where('is_active', 1)
                    ->pluck('name', 'id')
                    ->toArray();

                $customers = $customers->map(function($customer) use ($combinedStats, $regionMap) {
                    $stats = $combinedStats[$customer->id] ?? null;
                    if ($stats) {
                        $customer->total_orders = $stats->combined_order_count;
                        $customer->total_spent = $stats->combined_total_spent;
                    }
                    $customer->delivery_region_name = $customer->delivery_region_id
                        ? ($regionMap[$customer->delivery_region_id] ?? null) : null;
                    return $customer;
                });
            }
            
            return response()->json([
                'success' => true,
                'customers' => $customers
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to filter customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get combined order stats (production + history) for multiple customers
     * Uses efficient single query to avoid N+1
     */
    private function getCombinedCustomerStats(array $customerIds): array
    {
        // Check if history table exists
        $hasHistoryTable = DB::getSchemaBuilder()->hasTable('t_crm_history_order');
        
        if ($hasHistoryTable) {
            // Combined query with UNION for both tables
            $stats = DB::select("
                SELECT 
                    customer_id,
                    SUM(order_count) as combined_order_count,
                    SUM(total_spent) as combined_total_spent
                FROM (
                    -- Production orders (non-Shopify, delivered/completed)
                    SELECT 
                        customer_id,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_price), 0) as total_spent
                    FROM t_crm_prod_order
                    WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                      AND (external_source != 'shopify' OR external_source IS NULL)
                      AND order_status IN ('delivered', 'completed')
                    GROUP BY customer_id
                    
                    UNION ALL
                    
                    -- History orders (delivered only)
                    SELECT 
                        customer_id,
                        COUNT(*) as order_count,
                        COALESCE(SUM(total_price), 0) as total_spent
                    FROM t_crm_history_order
                    WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                      AND order_status = 'delivered'
                    GROUP BY customer_id
                ) combined
                GROUP BY customer_id
            ", array_merge($customerIds, $customerIds));
        } else {
            // Only production orders if no history table
            $stats = DB::select("
                SELECT 
                    customer_id,
                    COUNT(*) as combined_order_count,
                    COALESCE(SUM(total_price), 0) as combined_total_spent
                FROM t_crm_prod_order
                WHERE customer_id IN (" . implode(',', array_fill(0, count($customerIds), '?')) . ")
                  AND (external_source != 'shopify' OR external_source IS NULL)
                  AND order_status IN ('delivered', 'completed')
                GROUP BY customer_id
            ", $customerIds);
        }
        
        // Key by customer_id for easy lookup
        $result = [];
        foreach ($stats as $stat) {
            $result[$stat->customer_id] = $stat;
        }
        
        return $result;
    }

    public function orders($id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // =========================================
            // Get COMBINED orders (Production + History)
            // =========================================
            
            // 1. Get production orders (non-Shopify) with line item count
            $prodOrders = DB::table('t_crm_prod_order as o')
                ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as line_items_count FROM t_crm_prod_order_line_item GROUP BY order_id) as li'), 'o.id', '=', 'li.order_id')
                ->where('o.customer_id', $id)
                ->where(function($query) {
                    $query->where('o.external_source', '!=', 'shopify')
                          ->orWhereNull('o.external_source');
                })
                ->select(
                    'o.id', 'o.order_number', 'o.order_date', 'o.order_status',
                    'o.total_price', 'o.external_source', 'o.payment_method', 'o.note',
                    DB::raw('COALESCE(li.line_items_count, 0) as line_items_count'),
                    DB::raw("'production' as source_type")
                )
                ->get();
            
            // 2. Get history orders with line item count (if table exists)
            $historyOrders = collect();
            if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                $historyOrders = DB::table('t_crm_history_order as o')
                    ->leftJoin(DB::raw('(SELECT order_id, COUNT(*) as line_items_count FROM t_crm_history_order_line_item GROUP BY order_id) as li'), 'o.id', '=', 'li.order_id')
                    ->where('o.customer_id', $id)
                    ->select(
                        'o.id', 'o.order_number', 'o.order_date', 'o.order_status',
                        'o.total_price', 'o.external_source', 'o.payment_method', 'o.note',
                        DB::raw('COALESCE(li.line_items_count, 0) as line_items_count'),
                        DB::raw("'history' as source_type")
                    )
                    ->get();
            }
            
            // 3. Combine and sort by date (newest first)
            $allOrders = $prodOrders->concat($historyOrders)
                ->sortByDesc('order_date')
                ->values();
            
            return response()->json([
                'success' => true, 
                'orders' => $allOrders->map(function($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_date' => $order->order_date,
                        'order_status' => $order->order_status,
                        'total_price' => $order->total_price,
                        'line_items_count' => $order->line_items_count,
                        'external_source' => $order->external_source ?? 'csv_import',
                        'payment_method' => $order->payment_method,
                        'notes' => $order->note ?? null,
                        'source_type' => $order->source_type // 'production' or 'history'
                    ];
                }),
                'summary' => [
                    'production_orders' => $prodOrders->count(),
                    'history_orders' => $historyOrders->count(),
                    'total_orders' => $allOrders->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Error fetching customer orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check if phone number already exists (for duplicate detection)
     * Used by mobile app before creating new customer
     */
    public function checkPhone(Request $request)
    {
        try {
            $phone = $request->get('phone', '');
            
            if (empty($phone)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Phone number is required'
                ], 400);
            }
            
            // Normalize the phone number (same logic as CustomerModel::normalizePhone)
            $digits = preg_replace('/\D/', '', $phone);
            $normalizedPhone = substr($digits, -10);
            
            // Ensure we have exactly 10 digits
            if (strlen($normalizedPhone) < 10) {
                $normalizedPhone = str_pad($normalizedPhone, 10, '0', STR_PAD_LEFT);
            }
            
            // Check if customer exists with this normalized phone
            $existingCustomer = CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id') // Exclude merged customers
                ->first();
            
            if ($existingCustomer) {
                return response()->json([
                    'success' => true,
                    'exists' => true,
                    'customer' => [
                        'id' => $existingCustomer->id,
                        'name' => trim(($existingCustomer->first_name ?? '') . ' ' . ($existingCustomer->last_name ?? '')),
                        'phone' => $existingCustomer->phone_original ?? $existingCustomer->phone,
                        'city' => $existingCustomer->city,
                    ],
                    'normalized_phone' => $normalizedPhone,
                ]);
            }
            
            return response()->json([
                'success' => true,
                'exists' => false,
                'normalized_phone' => $normalizedPhone,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error checking phone: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a new customer (for mobile app)
     * Uses same normalization logic as findOrCreateByPhone
     */
    public function store(Request $request)
    {
        try {
            // Validate required fields
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'phone' => 'required|string|max:50',
                'email' => 'nullable|email|max:255',
                'company' => 'nullable|string|max:255',
                'customer_type' => 'nullable|string|in:regular,shop',
                'address1' => 'nullable|string|max:500',
                'address2' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:100',
                'province' => 'nullable|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'notes' => 'nullable|string',
            ]);
            
            // Normalize phone number (same logic as CustomerModel::normalizePhone)
            $phoneData = CustomerModel::normalizePhone($validated['phone']);
            $normalizedPhone = $phoneData['normalized'];
            $originalPhone = $phoneData['original'];
            
            // Check for duplicate (normalized phone must be unique)
            $existingCustomer = CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id')
                ->first();
            
            if ($existingCustomer) {
                $existingName = trim(($existingCustomer->first_name ?? '') . ' ' . ($existingCustomer->last_name ?? ''));
                return response()->json([
                    'success' => false,
                    'message' => "A customer with this phone number already exists: {$existingName}",
                    'existing_customer' => [
                        'id' => $existingCustomer->id,
                        'name' => $existingName,
                        'phone' => $existingCustomer->phone_original ?? $existingCustomer->phone,
                    ]
                ], 409); // 409 Conflict
            }
            
            // Create the customer (uses auto-increment ID from database)
            $customer = CustomerModel::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $normalizedPhone, // Legacy field - store normalized
                'phone_normalized' => $normalizedPhone,
                'phone_original' => $originalPhone,
                'email' => $validated['email'] ?? null,
                'company' => $validated['company'] ?? null,
                'customer_type' => $validated['customer_type'] ?? CustomerModel::TYPE_REGULAR,
                'address1' => $validated['address1'] ?? null,
                'address2' => $validated['address2'] ?? null,
                'city' => $validated['city'] ?? null,
                'province' => $validated['province'] ?? null,
                'postal_code' => $validated['postal_code'] ?? null,
                'country' => $validated['country'] ?? 'Pakistan',
                'notes' => $validated['notes'] ?? null,
                'is_active' => 1,
                'total_orders' => 0,
                'total_spent' => 0,
                'created_by' => auth()->check() ? auth()->id() : null,
            ]);
            
            \Log::info('Customer created via mobile app', [
                'customer_id' => $customer->id,
                'phone_normalized' => $normalizedPhone,
                'created_by' => auth()->id()
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Customer created successfully',
                'customer' => [
                    'id' => $customer->id,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'phone' => $customer->phone_original,
                    'phone_normalized' => $customer->phone_normalized,
                    'email' => $customer->email,
                    'company' => $customer->company,
                    'customer_type' => $customer->customer_type,
                    'address1' => $customer->address1,
                    'address2' => $customer->address2,
                    'city' => $customer->city,
                    'province' => $customer->province,
                    'country' => $customer->country,
                    'notes' => $customer->notes,
                    'total_orders' => 0,
                    'total_spent' => 0,
                ]
            ], 201);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to create customer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create customer: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get history order details with line items
     * For viewing legacy/history orders from customer modal
     */
    public function historyOrderDetails($historyOrderId)
    {
        try {
            // Check if history table exists
            if (!DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                return response()->json([
                    'success' => false,
                    'message' => 'History tables not available'
                ], 404);
            }
            
            // Get order details
            $order = DB::table('t_crm_history_order')
                ->where('id', $historyOrderId)
                ->first();
            
            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'History order not found'
                ], 404);
            }
            
            // Get line items
            $lineItems = DB::table('t_crm_history_order_line_item')
                ->where('order_id', $historyOrderId)
                ->get();
            
            // Get customer info if available
            $customer = null;
            if ($order->customer_id) {
                $customer = DB::table('t_crm_prod_customer')
                    ->where('id', $order->customer_id)
                    ->select('id', 'first_name', 'last_name', 'phone', 'email', 'city')
                    ->first();
            }
            
            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'order_status' => $order->order_status,
                    'order_date' => $order->order_date,
                    'delivered_at' => $order->delivered_at,
                    'name' => $order->name,
                    'contact_email' => $order->contact_email,
                    'currency' => $order->currency ?? 'PKR',
                    'subtotal_price' => $order->subtotal_price,
                    'discount_total' => $order->discount_total,
                    'shipping_total' => $order->shipping_total,
                    'total_price' => $order->total_price,
                    'address_first_name' => $order->address_first_name,
                    'address_last_name' => $order->address_last_name,
                    'address_email' => $order->address_email,
                    'address_phone' => $order->address_phone,
                    'address_line1' => $order->address_line1,
                    'address_city' => $order->address_city,
                    'coupon_code' => $order->coupon_code,
                    'payment_method' => $order->payment_method,
                    'note' => $order->note,
                    'external_source' => 'history',
                    'import_batch_id' => $order->import_batch_id,
                    'created_at' => $order->created_at
                ],
                'line_items' => $lineItems->map(function($item) {
                    return [
                        'id' => $item->id,
                        'sku' => $item->sku,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'line_subtotal' => $item->line_subtotal,
                        'discount_amount' => $item->discount_amount,
                        'line_total' => $item->line_total ?? $item->line_subtotal
                    ];
                }),
                'customer' => $customer
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching history order: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function update(Request $request, $id)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'required|string|max:20',
            'company' => 'nullable|string|max:255',
            'customer_type' => 'nullable|string|in:regular,shop',
            'address1' => 'nullable|string|max:255',
            'address2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_active' => 'boolean'
        ]);
        
        try {
            $customer = CustomerModel::findOrFail($id);
            $previousType = $customer->customer_type;
            $payload = $request->all();

            // Guard: don't allow flipping a shop back to regular while it still
            // has in-progress (partially paid) shop orders — that would leave
            // orders mid-cycle on the wrong billing basis.
            if ($request->filled('customer_type')
                && $previousType === CustomerModel::TYPE_SHOP
                && $request->input('customer_type') === CustomerModel::TYPE_REGULAR
                && $this->shopHasInProgressPayments($customer->id)) {
                $msg = 'This shop has orders with partial payments in progress. Settle or void them before switching back to regular.';
                if ($request->expectsJson()) {
                    return response()->json(['success' => false, 'message' => $msg], 422);
                }
                return redirect()->back()->with('error', $msg);
            }

            // Phone is stored across THREE columns: phone (legacy, holds the
            // normalized 10-digit form), phone_normalized (used for dedup
            // lookups), and phone_original (the display value used by mobile
            // and search). The edit form only sends the user-facing 'phone'
            // input, so if we don't fan it out here the mobile app and most
            // search paths keep showing the old number even though the form
            // appeared to save successfully.
            if ($request->filled('phone')) {
                $phoneData = CustomerModel::normalizePhone($request->input('phone'));
                $newNormalized = $phoneData['normalized'];
                $newOriginal = $phoneData['original'];

                if ($newNormalized !== $customer->phone_normalized) {
                    // Dedup guard: prevent edit from colliding with another
                    // customer's normalized phone (which is the dedup key).
                    // Excludes self and any merged-away duplicates.
                    $duplicate = CustomerModel::where('phone_normalized', $newNormalized)
                        ->where('id', '!=', $customer->id)
                        ->whereNull('merged_into_customer_id')
                        ->first();
                    if ($duplicate) {
                        $dupName = trim(($duplicate->first_name ?? '') . ' ' . ($duplicate->last_name ?? ''));
                        $msg = "Phone number already belongs to another customer: {$dupName}";
                        if ($request->expectsJson()) {
                            return response()->json([
                                'success' => false,
                                'message' => $msg,
                                'existing_customer' => [
                                    'id' => $duplicate->id,
                                    'name' => $dupName,
                                    'phone' => $duplicate->phone_original ?? $duplicate->phone,
                                ],
                            ], 409);
                        }
                        return redirect()->back()->with('error', $msg);
                    }
                }

                // Override the request payload with all three columns so the
                // mass-assign update() below writes them in one go.
                $payload['phone'] = $newNormalized;
                $payload['phone_normalized'] = $newNormalized;
                $payload['phone_original'] = $newOriginal;
            }

            $customer->update($payload);

            // If the customer was just flipped regular -> shop, convert their
            // existing PENDING online invoices into the shop payment flow so
            // they leave the regular approval queue and appear in the Shop tab.
            $convertedCount = 0;
            if ($request->filled('customer_type')
                && $previousType !== CustomerModel::TYPE_SHOP
                && $customer->customer_type === CustomerModel::TYPE_SHOP) {
                $convertedCount = $this->convertPendingOnlineInvoicesToShopFlow($customer);
            }

            // Handle both AJAX and regular form submissions
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Customer updated successfully!',
                    'converted_invoices' => $convertedCount,
                ]);
            }
            
            return redirect()->route('customers.index')->with('success', 'Customer updated successfully!');
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error updating customer: ' . $e->getMessage()
                ], 500);
            }
            
            return redirect()->back()->with('error', 'Error updating customer: ' . $e->getMessage());
        }
    }

    /**
     * True if this (shop) customer has any order with active payments recorded
     * but not yet fully paid — i.e. a billing cycle in progress that must not
     * be abandoned by flipping back to regular.
     */
    private function shopHasInProgressPayments($customerId): bool
    {
        return \App\Models\CRM\OrderModel::where('customer_id', $customerId)
            ->where('payment_status', 'partial')
            ->exists();
    }

    /**
     * Convert a newly-flagged shop customer's PENDING online invoices out of
     * the regular approval queue and into the shop payment flow.
     *
     * For each of the customer's online orders that still has a *pending*
     * invoice ledger row (mode=online, transaction_type=invoice, not approved/
     * rejected): reverse any balances already applied at L1, delete the invoice
     * ledger row, and clear order.ledger_transaction_id. The order (delivered +
     * now outstanding) then surfaces in the Shop tab to be settled by payments.
     *
     * Approved invoices are left untouched — that revenue is already booked.
     * Returns the number of invoices converted.
     */
    private function convertPendingOnlineInvoicesToShopFlow(CustomerModel $customer): int
    {
        $converted = 0;

        // Only truly PRE-L1 invoices get converted to the shop flow. Once an
        // invoice clears L1 (status pending_l2) the amount has already been
        // posted to the ledger, so we treat it as booked/approved and LEAVE IT
        // ALONE — deleting it here would both reverse a legitimate balance and
        // make the order resurface in the Shop tab as "unpaid". The shop-tab
        // query already excludes pending_l2 / approved invoices.
        $pendingStatuses = [
            \App\Models\FIN\LedgerModel::STATUS_PENDING,
            \App\Models\FIN\LedgerModel::STATUS_PENDING_L1,
        ];

        // Pending online invoice ledger rows for this customer's orders.
        $invoices = \App\Models\FIN\LedgerModel::query()
            ->where('mode', \App\Models\FIN\LedgerModel::MODE_ONLINE)
            ->where('transaction_type', \App\Models\FIN\LedgerModel::TYPE_INVOICE)
            ->whereIn('approval_status', $pendingStatuses)
            ->whereNull('request_id')
            ->whereIn('order_id', function ($q) use ($customer) {
                $q->select('id')->from('t_crm_prod_order')->where('customer_id', $customer->id);
            })
            ->get();

        foreach ($invoices as $invoice) {
            \DB::beginTransaction();
            try {
                // If L1 already posted balances (balance_updated=1), reverse them
                // so we don't double count once payments start posting.
                if ((int) $invoice->balance_updated === 1) {
                    $fromAccount = \App\Models\FIN\AccountModel::find($invoice->from_account_id);
                    $toAccount   = \App\Models\FIN\AccountModel::find($invoice->to_account_id);
                    $amount = (float) $invoice->amount;
                    if ($fromAccount) {
                        // Mirror-reverse of the approve() posting logic.
                        if ($fromAccount->account_type === 'asset') {
                            $fromAccount->current_balance += $amount;
                        } else {
                            $fromAccount->current_balance -= $amount;
                        }
                        $fromAccount->save();
                    }
                    if ($toAccount) {
                        if ($toAccount->account_type === 'asset') {
                            $toAccount->current_balance -= $amount;
                        } else {
                            $toAccount->current_balance += $amount;
                        }
                        $toAccount->save();
                    }
                }

                $orderId = $invoice->order_id;
                $invoice->delete();

                // Clear the order's invoice link so it isn't treated as posted
                // and can be settled via the shop payment flow instead.
                if ($orderId) {
                    \DB::table('t_crm_prod_order')
                        ->where('id', $orderId)
                        ->update(['ledger_transaction_id' => null]);
                }

                \DB::commit();
                $converted++;
            } catch (\Exception $e) {
                \DB::rollBack();
                \Log::error('Failed to convert pending invoice to shop flow', [
                    'customer_id' => $customer->id,
                    'ledger_id' => $invoice->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($converted > 0) {
            \Log::info('Converted pending online invoices to shop flow', [
                'customer_id' => $customer->id,
                'converted' => $converted,
            ]);
        }

        return $converted;
    }

    public function addNote(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string'
        ]);
        
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // Append new note to existing notes
            $existingNotes = $customer->notes ?? '';
            $newNote = $request->notes;
            $timestamp = now()->format('Y-m-d H:i:s');
            
            if ($existingNotes) {
                $updatedNotes = $existingNotes . "\n\n[{$timestamp}] " . $newNote;
            } else {
                $updatedNotes = "[{$timestamp}] " . $newNote;
            }
            
            $customer->update(['notes' => $updatedNotes]);
            
            return response()->json([
                'success' => true,
                'message' => 'Note added successfully!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error adding note: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Merge duplicate customers into a primary customer
     * - Updates all orders (prod + history) to point to primary customer
     * - Marks duplicate customers as merged (hidden from normal queries)
     * - Preserves full traceability
     */
    public function mergeCustomers(Request $request)
    {
        $validated = $request->validate([
            'primary_customer_id' => 'required|integer|exists:t_crm_prod_customer,id',
            'duplicate_customer_ids' => 'required|array|min:1',
            'duplicate_customer_ids.*' => 'integer|exists:t_crm_prod_customer,id',
        ]);
        
        try {
            $primaryId = $validated['primary_customer_id'];
            $duplicateIds = $validated['duplicate_customer_ids'];
            
            // Cannot merge primary into itself
            if (in_array($primaryId, $duplicateIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary customer cannot be in the duplicate list'
                ], 400);
            }
            
            // Get primary customer
            $primary = CustomerModel::findOrFail($primaryId);
            
            // Cannot merge already-merged customers
            if ($primary->merged_into_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Primary customer is already merged into another customer'
                ], 400);
            }
            
            DB::beginTransaction();
            
            $stats = [
                'prod_orders_updated' => 0,
                'history_orders_updated' => 0,
                'customers_merged' => 0,
            ];
            
            foreach ($duplicateIds as $duplicateId) {
                $duplicate = CustomerModel::find($duplicateId);
                if (!$duplicate) continue;
                
                // Cannot merge already-merged customers
                if ($duplicate->merged_into_customer_id) {
                    continue;
                }
                
                // Update production orders
                $prodUpdated = DB::table('t_crm_prod_order')
                    ->where('customer_id', $duplicateId)
                    ->update(['customer_id' => $primaryId, 'updated_at' => now()]);
                $stats['prod_orders_updated'] += $prodUpdated;
                
                // Update history orders (if table exists)
                if (DB::getSchemaBuilder()->hasTable('t_crm_history_order')) {
                    $historyUpdated = DB::table('t_crm_history_order')
                        ->where('customer_id', $duplicateId)
                        ->update(['customer_id' => $primaryId, 'updated_at' => now()]);
                    $stats['history_orders_updated'] += $historyUpdated;
                }
                
                // Mark duplicate as merged
                $duplicate->update([
                    'merged_into_customer_id' => $primaryId,
                    'merged_at' => now(),
                    'merged_by' => auth()->id(),
                    'notes' => ($duplicate->notes ? $duplicate->notes . "\n\n" : '') . 
                               "[" . now()->format('Y-m-d H:i:s') . "] Merged into customer #$primaryId by " . auth()->user()->fullname
                ]);
                
                $stats['customers_merged']++;
            }
            
            // Recalculate primary customer statistics
            $primary->recalculateStatistics();
            
            // Add merge note to primary customer
            $primary->update([
                'notes' => ($primary->notes ? $primary->notes . "\n\n" : '') . 
                           "[" . now()->format('Y-m-d H:i:s') . "] Merged " . $stats['customers_merged'] . 
                           " duplicate customer(s) into this record by " . auth()->user()->fullname
            ]);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => "Successfully merged {$stats['customers_merged']} customer(s). " .
                             "{$stats['prod_orders_updated']} production orders and " .
                             "{$stats['history_orders_updated']} history orders were updated.",
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Customer merge failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error merging customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Find potential duplicate customers (same name, different phone)
     */
    public function findDuplicates(Request $request)
    {
        try {
            // Find customers with same name but different normalized phones
            // Include combined order counts and last order date from both production and history tables
            $duplicates = DB::select("
                SELECT 
                    c1.id as customer1_id,
                    c1.first_name,
                    c1.last_name,
                    c1.phone_normalized as phone1,
                    c1.city as city1,
                    COALESCE(stats1.total_orders, 0) as orders1,
                    stats1.last_order_date as last_order1,
                    c2.id as customer2_id,
                    c2.phone_normalized as phone2,
                    c2.city as city2,
                    COALESCE(stats2.total_orders, 0) as orders2,
                    stats2.last_order_date as last_order2
                FROM t_crm_prod_customer c1
                JOIN t_crm_prod_customer c2 ON 
                    LOWER(TRIM(c1.first_name)) = LOWER(TRIM(c2.first_name))
                    AND LOWER(TRIM(c1.last_name)) = LOWER(TRIM(c2.last_name))
                    AND c1.id < c2.id
                    AND c1.phone_normalized != c2.phone_normalized
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders, MAX(last_date) as last_order_date FROM (
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats1 ON stats1.customer_id = c1.id
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders, MAX(last_date) as last_order_date FROM (
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count, MAX(order_date) as last_date
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats2 ON stats2.customer_id = c2.id
                WHERE c1.merged_into_customer_id IS NULL
                AND c2.merged_into_customer_id IS NULL
                AND c1.first_name IS NOT NULL
                AND c1.first_name != ''
                ORDER BY c1.last_name, c1.first_name
            ");
            
            return response()->json([
                'success' => true,
                'duplicates' => $duplicates,
                'count' => count($duplicates)
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error finding duplicates: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Search customers for merge (from individual customer view)
     * Returns customers that can be merged into the current one
     */
    public function searchForMerge(Request $request)
    {
        try {
            $currentCustomerId = $request->get('current_customer_id');
            $search = $request->get('q', '');
            $limit = $request->get('limit', 20);
            
            if (strlen($search) < 2) {
                return response()->json([
                    'success' => true,
                    'customers' => []
                ]);
            }
            
            // Use raw query to include combined order counts from prod + history
            $customers = DB::select("
                SELECT 
                    c.id,
                    c.first_name,
                    c.last_name,
                    c.phone_normalized,
                    c.phone_original,
                    c.city,
                    COALESCE(stats.total_orders, 0) as total_orders
                FROM t_crm_prod_customer c
                LEFT JOIN (
                    SELECT customer_id, SUM(order_count) as total_orders FROM (
                        SELECT customer_id, COUNT(*) as order_count 
                        FROM t_crm_prod_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                        UNION ALL
                        SELECT customer_id, COUNT(*) as order_count 
                        FROM t_crm_history_order 
                        WHERE order_status IN ('delivered', 'completed')
                        GROUP BY customer_id
                    ) combined GROUP BY customer_id
                ) stats ON stats.customer_id = c.id
                WHERE c.merged_into_customer_id IS NULL
                AND c.id != ?
                AND (
                    c.first_name LIKE ?
                    OR c.last_name LIKE ?
                    OR c.phone LIKE ?
                    OR c.phone_original LIKE ?
                    OR c.phone_normalized LIKE ?
                    OR c.email LIKE ?
                    OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
                )
                ORDER BY c.last_order_date DESC
                LIMIT ?
            ", [
                $currentCustomerId,
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                "%{$search}%",
                $limit
            ]);
            
            return response()->json([
                'success' => true,
                'customers' => collect($customers)->map(function($c) {
                    return [
                        'id' => $c->id,
                        'name' => trim($c->first_name . ' ' . $c->last_name),
                        'phone' => $c->phone_original ?: $c->phone_normalized,
                        'city' => $c->city,
                        'orders' => $c->total_orders ?: 0
                    ];
                })
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error searching customers: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function setVerifiedLocation(Request $request, $id)
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

            $customer = CustomerModel::findOrFail($id);

            // Prepare update data
            $updateData = [
                'updated_by' => auth()->id(),
                'verified_location_saved_by' => auth()->id(),
                'verified_location_saved_at' => now(),
            ];
            
            if (!empty($validated['url'])) {
                // URL provided — store the user-supplied URL verbatim
                // AND best-effort resolve/parse it to lat/lng so the
                // customer truly counts as "verified" (the Qurbani
                // Orders page treats a customer as verified only when
                // latitude AND longitude are non-empty). Without this
                // step, customers who share a `maps.app.goo.gl/...`
                // link in chat stay flagged as "needs pin" forever.
                // Mirrors the rider endpoint's behaviour
                // (RiderController::setCustomerVerifiedLocation) so
                // both surfaces converge on the same data shape.
                $originalUrl = $validated['url'];
                $updateData['verified_location_url'] = $originalUrl;

                $resolvedUrl = $this->resolveGoogleMapsShortLink($originalUrl);
                $parsedCoords = $this->parseCoordsFromMapsUrl($resolvedUrl);
                if ($parsedCoords) {
                    $updateData['latitude'] = $parsedCoords['latitude'];
                    $updateData['longitude'] = $parsedCoords['longitude'];
                    \Log::info('Setting verified location from URL with extracted coords (webapp)', [
                        'customer_id' => $id,
                        'url'         => $originalUrl,
                        'resolved'    => $resolvedUrl,
                        'lat'         => $parsedCoords['latitude'],
                        'lng'         => $parsedCoords['longitude'],
                        'saved_by'    => auth()->user()->fullname,
                    ]);
                } else {
                    \Log::info('Setting verified location URL only (no coords extractable) (webapp)', [
                        'customer_id' => $id,
                        'url'         => $originalUrl,
                        'resolved'    => $resolvedUrl,
                        'saved_by'    => auth()->user()->fullname,
                    ]);
                }
            }

            if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                // Explicit coordinates always win over URL-extracted
                // ones — they're the most accurate signal staff has.
                $updateData['latitude'] = $validated['latitude'];
                $updateData['longitude'] = $validated['longitude'];
                \Log::info('Setting verified location coordinates for customer (webapp)', [
                    'customer_id' => $id,
                    'latitude' => $validated['latitude'],
                    'longitude' => $validated['longitude'],
                    'saved_by' => auth()->user()->fullname,
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
            \Log::error('Failed to set customer verified location (webapp)', [
                'customer_id' => $id,
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
     * Follow a shortened Google Maps URL to its final destination so
     * we can extract coordinates. Returns the original URL unchanged
     * if it isn't shortened or if the redirect lookup fails. Used by
     * setVerifiedLocation() — duplicated (deliberately) from the
     * rider controller's helper of the same shape rather than shared
     * via a trait, because both controllers are independently
     * deployable and we don't want a shared dependency churn here.
     */
    private function resolveGoogleMapsShortLink(string $url): string
    {
        $shortDomains = ['goo.gl', 'maps.app.goo.gl', 'g.co'];
        $needsResolve = false;
        foreach ($shortDomains as $d) {
            if (stripos($url, $d) !== false) { $needsResolve = true; break; }
        }
        if (!$needsResolve || !function_exists('curl_init')) {
            return $url;
        }
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; NizamiFarms-LocResolver/1.0)');
            curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode >= 200 && $httpCode < 400 && !empty($finalUrl)) {
                return (string) $finalUrl;
            }
        } catch (\Throwable $e) {
            \Log::debug('CustomerController: resolveGoogleMapsShortLink failed (non-fatal)', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
        }
        return $url;
    }

    /**
     * Extract lat/lng from a resolved Google Maps URL. Recognises:
     *   - ?q=LAT,LNG / ?ll=LAT,LNG / ?destination=LAT,LNG
     *   - /@LAT,LNG,...  (the canonical share format)
     *   - /place/LAT,LNG/...
     * Returns ['latitude' => float, 'longitude' => float] or null if
     * the URL contains no coordinates (e.g. it's a named-place share
     * that only resolves to a Place ID without coords in the URL).
     */
    private function parseCoordsFromMapsUrl(?string $url): ?array
    {
        if (empty($url)) { return null; }
        if (preg_match('/[?&](?:q|ll|destination)=(-?\d+\.\d+),(-?\d+\.\d+)/i', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        if (preg_match('~/place/(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) {
            return ['latitude' => (float) $m[1], 'longitude' => (float) $m[2]];
        }
        return null;
    }

    public function destroy($id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // Check if customer has orders
            if ($customer->total_orders > 0) {
                return redirect()->back()->with('error', 'Cannot delete customer with existing orders. Please deactivate instead.');
            }
            
            $customer->delete();
            
            return redirect()->route('customers.index')->with('success', 'Customer deleted successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error deleting customer: ' . $e->getMessage());
        }
    }
    
    /**
     * Geocode a single customer's address
     */
    public function geocode(Request $request, $id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            if (!$customer->address1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer has no address to geocode'
                ], 400);
            }
            
            // Force update even if coordinates exist
            $forceUpdate = $request->boolean('force', false);
            
            if (!$forceUpdate && $customer->latitude && $customer->longitude) {
                return response()->json([
                    'success' => true,
                    'message' => 'Customer already has coordinates',
                    'coordinates' => [
                        'latitude' => $customer->latitude,
                        'longitude' => $customer->longitude,
                    ]
                ]);
            }
            
            $result = \App\Services\GeocodingService::geocodeCustomer($id, $forceUpdate);
            
            if ($result) {
                $customer->refresh();
                return response()->json([
                    'success' => true,
                    'message' => 'Address geocoded successfully',
                    'coordinates' => [
                        'latitude' => $customer->latitude,
                        'longitude' => $customer->longitude,
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not geocode address. The address may be too vague or not found.'
                ], 400);
            }
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Geocoding failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Batch geocode customers without coordinates
     */
    public function batchGeocode(Request $request)
    {
        try {
            $limit = $request->input('limit', 50);
            $limit = min($limit, 100); // Cap at 100 to avoid timeout
            
            $result = \App\Services\GeocodingService::batchGeocodeCustomers($limit);
            
            return response()->json([
                'success' => true,
                'message' => "Geocoded {$result['success']} of {$result['total']} customers",
                'result' => $result
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Batch geocoding failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Geocode a single customer by ID
     * Used for on-demand geocoding from the map views
     */
    public function geocodeSingle(Request $request, $customerId)
    {
        try {
            $forceUpdate = $request->get('force', false);
            
            $customer = \DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->first(['id', 'first_name', 'last_name', 'address1', 'city', 'geocoded_latitude', 'geocoded_longitude']);
            
            if (!$customer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer not found'
                ], 404);
            }
            
            // Skip if already has geocoded coordinates (unless force update)
            if (!$forceUpdate && $customer->geocoded_latitude && $customer->geocoded_longitude) {
                return response()->json([
                    'success' => true,
                    'already_geocoded' => true,
                    'location' => [
                        'latitude' => (float) $customer->geocoded_latitude,
                        'longitude' => (float) $customer->geocoded_longitude,
                    ],
                    'message' => 'Already geocoded'
                ]);
            }
            
            // Perform geocoding
            $result = \App\Services\GeocodingService::geocodeCustomer($customerId, $forceUpdate);
            
            if ($result) {
                // Fetch updated coordinates
                $updated = \DB::table('t_crm_prod_customer')
                    ->where('id', $customerId)
                    ->first(['geocoded_latitude', 'geocoded_longitude']);
                
                return response()->json([
                    'success' => true,
                    'geocoded' => true,
                    'location' => [
                        'latitude' => (float) $updated->geocoded_latitude,
                        'longitude' => (float) $updated->geocoded_longitude,
                    ],
                    'customer_name' => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'message' => 'Successfully geocoded'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'geocoded' => false,
                    'message' => 'Could not geocode address: ' . ($customer->address1 ?? 'No address')
                ]);
            }
            
        } catch (\Exception $e) {
            \Log::error('Failed to geocode customer', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to geocode: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get geocoding statistics
     */
    public function geocodeStats()
    {
        try {
            $total = CustomerModel::count();
            
            // Verified locations (manually set by rider - high accuracy)
            $withVerified = CustomerModel::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->count();
            
            // Geocoded locations (auto-generated from address - approximate)
            $withGeocoded = CustomerModel::whereNotNull('geocoded_latitude')
                ->whereNotNull('geocoded_longitude')
                ->count();
            
            // Either verified or geocoded
            $withAnyCoords = CustomerModel::where(function($q) {
                $q->where(function($q2) {
                    $q2->whereNotNull('latitude')->whereNotNull('longitude');
                })->orWhere(function($q2) {
                    $q2->whereNotNull('geocoded_latitude')->whereNotNull('geocoded_longitude');
                });
            })->count();
            
            $withAddress = CustomerModel::whereNotNull('address1')
                ->where('address1', '!=', '')
                ->count();
            
            // Needs geocoding: has address but no geocoded coordinates
            $needsGeocode = CustomerModel::whereNull('geocoded_latitude')
                ->whereNotNull('address1')
                ->where('address1', '!=', '')
                ->count();
            
            return response()->json([
                'success' => true,
                'stats' => [
                    'total_customers' => $total,
                    'with_verified_location' => $withVerified,
                    'with_geocoded_location' => $withGeocoded,
                    'with_any_coordinates' => $withAnyCoords,
                    'with_address' => $withAddress,
                    'needs_geocoding' => $needsGeocode,
                    'coverage_percent' => $withAddress > 0 ? round(($withAnyCoords / $withAddress) * 100, 1) : 0,
                ]
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get stats: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Upload a promo image for WhatsApp messaging
     * Stores image in public storage and returns the URL
     */
    public function uploadPromoImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
            ]);
            
            $file = $request->file('image');
            $filename = 'whatsapp_promo_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('whatsapp_promo', $filename, 'public');
            
            $url = asset('storage/' . $path);
            $url = str_replace('://app.nizamifarms.com', '://www.nizamifarms.com', $url);
            
            return response()->json([
                'success' => true,
                'url' => $url,
                'path' => $path
            ]);
            
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid image: ' . implode(', ', $e->validator->errors()->all())
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Delete a promo image from storage
     */
    public function deletePromoImage(Request $request)
    {
        try {
            $path = $request->input('path');
            
            // Security: Only allow deleting from whatsapp_promo directory
            if (!$path || !str_starts_with($path, 'whatsapp_promo/')) {
                return response()->json(['success' => false, 'message' => 'Invalid path'], 400);
            }
            
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
            
            return response()->json(['success' => true]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete image: ' . $e->getMessage()
            ], 500);
        }
    }
}
