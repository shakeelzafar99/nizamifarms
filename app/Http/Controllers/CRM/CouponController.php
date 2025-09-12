<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\CRM\CouponModel;
use App\Services\ShopifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    protected $shopify;

    public function __construct(ShopifyService $shopify)
    {
        $this->shopify = $shopify;
    }

    /**
     * Display a listing of coupons
     */
    public function index(Request $request)
    {
        try {
            $query = CouponModel::query();

            // Apply search filter
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                      ->orWhere('code', 'LIKE', "%{$search}%");
                });
            }

            // Apply status filter
            if ($request->filled('status')) {
                $query->where('status', $request->get('status'));
            }

            // Apply active filter
            if ($request->filled('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Order by created_at desc
            $query->orderBy('created_at', 'desc');

            $coupons = $query->paginate(15);

            return view('pages.coupons.index', compact('coupons'));
        } catch (\Exception $e) {
            Log::error('Error fetching coupons: ' . $e->getMessage());
            return back()->with('error', 'Failed to load coupons. Please try again.');
        }
    }

    /**
     * Show the form for creating a new coupon
     */
    public function create()
    {
        return view('pages.coupons.create');
    }

    /**
     * Store a newly created coupon
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'code' => 'nullable|string|max:100|unique:t_crm_shopify_coupon,code',
                'discount_type' => 'required|in:percentage,fixed_amount,shipping',
                'value_type' => 'required|in:percentage,fixed_amount',
                'value' => 'required|numeric|min:0',
                'minimum_amount' => 'nullable|numeric|min:0',
                'usage_limit' => 'nullable|integer|min:1',
                'once_per_customer' => 'boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after:starts_at',
                'is_active' => 'boolean'
            ]);

            // Format data to match API structure for consistency
            $couponData = $this->formatManualCouponData($validated);
            
            // Use the same function as API to maintain consistency
            $coupon = CouponModel::storeCouponFromApi($couponData, $validated['code'] ?? null);

            return redirect()->route('coupons.index')
                ->with('success', 'Coupon created successfully.');
        } catch (\Exception $e) {
            Log::error('Error creating coupon: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to create coupon. Please try again.');
        }
    }

    /**
     * Display the specified coupon
     */
    public function show(Request $request, $id)
    {
        try {
            $coupon = CouponModel::findOrFail($id);
            
            // Return JSON for AJAX requests
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'data' => $coupon
                ]);
            }
            
            // Return view for regular requests
            return view('pages.coupons.show', compact('coupon'));
        } catch (\Exception $e) {
            Log::error('Error fetching coupon: ' . $e->getMessage());
            
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coupon not found.'
                ], 404);
            }
            
            return redirect()->route('coupons.index')
                ->with('error', 'Coupon not found.');
        }
    }

    /**
     * Show the form for editing the specified coupon
     */
    public function edit($id)
    {
        try {
            $coupon = CouponModel::findOrFail($id);
            return view('pages.coupons.edit', compact('coupon'));
        } catch (\Exception $e) {
            Log::error('Error fetching coupon for edit: ' . $e->getMessage());
            return redirect()->route('coupons.index')
                ->with('error', 'Coupon not found.');
        }
    }

    /**
     * Update the specified coupon
     */
    public function update(Request $request, $id)
    {
        try {
            $coupon = CouponModel::findOrFail($id);

            // Don't allow editing Shopify coupons
            if ($coupon->shopify_discount_id) {
                return back()->with('error', 'Shopify coupons cannot be edited manually. Please sync from Shopify instead.');
            }

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'code' => 'nullable|string|max:100|unique:t_crm_shopify_coupon,code,' . $id,
                'discount_type' => 'required|in:percentage,fixed_amount,shipping',
                'value_type' => 'required|in:percentage,fixed_amount',
                'value' => 'required|numeric|min:0',
                'minimum_amount' => 'nullable|numeric|min:0',
                'usage_limit' => 'nullable|integer|min:1',
                'once_per_customer' => 'boolean',
                'starts_at' => 'nullable|date',
                'ends_at' => 'nullable|date|after:starts_at',
                'is_active' => 'boolean'
            ]);

            // Format data to match API structure for consistency
            $couponData = $this->formatManualCouponData($validated);
            
            // Use the same function as API to maintain consistency
            $updatedCoupon = CouponModel::storeCouponFromApi($couponData, $validated['code'] ?? $coupon->code);

            return redirect()->route('coupons.index')
                ->with('success', 'Coupon updated successfully.');
        } catch (\Exception $e) {
            Log::error('Error updating coupon: ' . $e->getMessage());
            return back()->withInput()
                ->with('error', 'Failed to update coupon. Please try again.');
        }
    }

    /**
     * Remove the specified coupon
     */
    public function destroy($id)
    {
        try {
            $coupon = CouponModel::findOrFail($id);
            
            // Soft delete by setting is_active to false
            $coupon->update([
                'is_active' => false,
                'status' => 'disabled',
                'updated_by' => auth()->id()
            ]);

            return redirect()->route('coupons.index')
                ->with('success', 'Coupon disabled successfully.');
        } catch (\Exception $e) {
            Log::error('Error disabling coupon: ' . $e->getMessage());
            return back()->with('error', 'Failed to disable coupon. Please try again.');
        }
    }

    /**
     * Import coupons from Shopify (limited)
     */
    public function importCoupons(Request $request)
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:250'
            ]);

            $limit = $validated['limit'] ?? 50;

            // Fetch price rules from Shopify
            $priceRules = $this->shopify->fetchPriceRules($limit);

            if (empty($priceRules)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No price rules found in Shopify store. This could mean: 1) Your store has no discounts, 2) API credentials are incorrect, or 3) Discounts are disabled.'
                ]);
            }

            // Store coupons in database
            $importedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($priceRules as $priceRule) {
                try {
                    // Check if coupon already exists
                    $existingCoupon = CouponModel::findByShopifyId($priceRule['id']);
                    $isUpdate = $existingCoupon !== null;
                    
                    // Fetch discount codes for this price rule
                    $discountCodes = $this->shopify->fetchDiscountCodes($priceRule['id']);
                    $primaryCode = !empty($discountCodes) ? $discountCodes[0]['code'] : null;
                    
                    // Create or update coupon
                    CouponModel::createOrUpdateFromShopify($priceRule, $primaryCode);
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Price Rule ID {$priceRule['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify price rule: ' . $e->getMessage(), [
                        'shopify_price_rule_id' => $priceRule['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $message = "Import completed! Imported: {$importedCount}, Updated: {$updatedCount}";
            if ($errorCount > 0) {
                $message .= ", Errors: {$errorCount}";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'imported' => $importedCount,
                    'updated' => $updatedCount,
                    'errors' => $errorCount,
                    'error_details' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Shopify coupon import failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Import failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import all coupons from Shopify (bulk operation)
     */
    public function importAllCoupons()
    {
        try {
            // Fetch all price rules with their discount codes
            $priceRules = $this->shopify->fetchAllPriceRulesWithCodes();

            if (empty($priceRules)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No price rules found in Shopify store.'
                ]);
            }

            $importedCount = 0;
            $updatedCount = 0;
            $errorCount = 0;
            $errors = [];

            DB::beginTransaction();

            foreach ($priceRules as $priceRule) {
                try {
                    // Check if coupon already exists
                    $existingCoupon = CouponModel::findByShopifyId($priceRule['id']);
                    $isUpdate = $existingCoupon !== null;
                    
                    // Get the primary discount code (first one)
                    $primaryCode = null;
                    if (!empty($priceRule['discount_codes'])) {
                        $primaryCode = $priceRule['discount_codes'][0]['code'];
                    }
                    
                    // Store coupon from API data
                    CouponModel::storeCouponFromApi($priceRule, $primaryCode);
                    
                    if ($isUpdate) {
                        $updatedCount++;
                    } else {
                        $importedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = "Price Rule ID {$priceRule['id']}: " . $e->getMessage();
                    
                    Log::error('Failed to import Shopify price rule (bulk): ' . $e->getMessage(), [
                        'shopify_price_rule_id' => $priceRule['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            DB::commit();

            $message = "Bulk import completed! Imported: {$importedCount}, Updated: {$updatedCount}";
            if ($errorCount > 0) {
                $message .= ", Errors: {$errorCount}";
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'total_processed' => count($priceRules),
                    'imported' => $importedCount,
                    'updated' => $updatedCount,
                    'errors' => $errorCount,
                    'error_details' => array_slice($errors, 0, 10) // Limit error details
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Shopify bulk coupon import failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Bulk import failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get coupon by code (for validation)
     */
    public function validateCoupon(Request $request)
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string'
            ]);

            $coupon = CouponModel::findByCode($validated['code']);

            if (!$coupon) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coupon code not found.'
                ], 404);
            }

            if (!$coupon->isValid()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Coupon code is not valid or has expired.'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $coupon->id,
                    'title' => $coupon->title,
                    'code' => $coupon->code,
                    'discount_type' => $coupon->discount_type,
                    'value_type' => $coupon->value_type,
                    'value' => $coupon->value,
                    'minimum_amount' => $coupon->minimum_amount,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error validating coupon: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate coupon.'
            ], 500);
        }
    }

    /**
     * Get active coupons list
     */
    public function getActiveCoupons()
    {
        try {
            $coupons = CouponModel::getActiveCoupons();

            return response()->json([
                'success' => true,
                'data' => $coupons->map(function ($coupon) {
                    return [
                        'id' => $coupon->id,
                        'title' => $coupon->title,
                        'code' => $coupon->code,
                        'discount_type' => $coupon->discount_type,
                        'value_type' => $coupon->value_type,
                        'value' => $coupon->value,
                        'minimum_amount' => $coupon->minimum_amount,
                    ];
                })
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching active coupons: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active coupons.'
            ], 500);
        }
    }

    /**
     * Search coupons
     */
    public function search(Request $request)
    {
        try {
            $validated = $request->validate([
                'q' => 'required|string|min:1'
            ]);

            $searchTerm = $validated['q'];

            $coupons = CouponModel::where('is_active', true)
                ->where(function ($query) use ($searchTerm) {
                    $query->where('title', 'LIKE', "%{$searchTerm}%")
                          ->orWhere('code', 'LIKE', "%{$searchTerm}%");
                })
                ->limit(10)
                ->get(['id', 'title', 'code', 'discount_type', 'value_type', 'value']);

            return response()->json([
                'success' => true,
                'data' => $coupons
            ]);

        } catch (\Exception $e) {
            Log::error('Error searching coupons: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to search coupons.'
            ], 500);
        }
    }

    /**
     * Format manual coupon data to match API structure
     */
    private function formatManualCouponData(array $validated): array
    {
        // Determine status based on dates
        $status = 'active';
        $now = now();
        
        if (isset($validated['starts_at']) && $validated['starts_at'] && $now < new \DateTime($validated['starts_at'])) {
            $status = 'scheduled';
        } elseif (isset($validated['ends_at']) && $validated['ends_at'] && $now > new \DateTime($validated['ends_at'])) {
            $status = 'expired';
        }

        // Format data to match Shopify price rule structure expected by mapShopifyPriceRule
        return [
            // No Shopify ID for manual coupons
            'id' => null,
            'title' => $validated['title'],
            'value_type' => $validated['value_type'],
            'value' => $validated['discount_type'] === 'shipping' ? '0.0' : (string)$validated['value'],
            'target_type' => $validated['discount_type'] === 'shipping' ? 'shipping_line' : 'line_item',
            'target_selection' => 'all',
            'allocation_method' => 'across',
            'allocation_limit' => null,
            'once_per_customer' => $validated['once_per_customer'] ?? false,
            'usage_limit' => $validated['usage_limit'] ?? null,
            'customer_selection' => 'all',
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            
            // Prerequisites (format to match Shopify structure)
            'prerequisite_subtotal_range' => isset($validated['minimum_amount']) ? [
                'greater_than_or_equal_to' => (string)$validated['minimum_amount']
            ] : null,
            'prerequisite_product_ids' => [],
            'prerequisite_variant_ids' => [],
            'prerequisite_collection_ids' => [],
            'prerequisite_customer_ids' => [],
            'entitled_product_ids' => [],
            'entitled_variant_ids' => [],
            'entitled_collection_ids' => [],
        ];
    }
}
