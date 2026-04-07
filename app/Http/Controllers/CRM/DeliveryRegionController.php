<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\RegionDetectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryRegionController extends Controller
{
    public function index()
    {
        return view('pages.regions.index');
    }

    public function getRegions()
    {
        $regions = DB::table('t_ops_delivery_region')
            ->orderBy('sort_order')
            ->get();

        foreach ($regions as $region) {
            $region->area_count = DB::table('t_ops_delivery_area')
                ->where('region_id', $region->id)
                ->where('is_active', 1)
                ->count();
            $region->rider_count = DB::table('t_ops_rider_region')
                ->where('region_id', $region->id)
                ->where('is_active', 1)
                ->count();
            $region->customer_count = DB::table('t_crm_prod_customer')
                ->where('delivery_region_id', $region->id)
                ->where('is_active', 1)
                ->count();
            $region->open_order_count = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('c.delivery_region_id', $region->id)
                ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                ->count();
        }

        return response()->json(['success' => true, 'regions' => $regions]);
    }

    public function saveRegion(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:30',
            'color' => 'required|string|max:7',
            'polygon_coordinates' => 'nullable',
            'center_lat' => 'nullable|numeric|between:-90,90',
            'center_lng' => 'nullable|numeric|between:-180,180',
            'sort_order' => 'nullable|integer',
            'is_active' => 'required|boolean',
        ]);

        $data = [
            'name' => $validated['name'],
            'code' => $validated['code'],
            'color' => $validated['color'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'],
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ];

        if ($request->has('polygon_coordinates')) {
            $data['polygon_coordinates'] = $validated['polygon_coordinates'];
            $data['center_lat'] = $validated['center_lat'] ?? null;
            $data['center_lng'] = $validated['center_lng'] ?? null;
        }

        if (!empty($validated['id'])) {
            DB::table('t_ops_delivery_region')
                ->where('id', $validated['id'])
                ->update($data);
            $id = $validated['id'];
        } else {
            if (!isset($data['polygon_coordinates'])) {
                $data['polygon_coordinates'] = null;
            }
            $data['created_by'] = auth()->id();
            $data['created_at'] = now();
            $id = DB::table('t_ops_delivery_region')->insertGetId($data);
        }

        RegionDetectionService::clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Region saved successfully',
            'region_id' => $id,
        ]);
    }

    /**
     * Save only polygon coordinates for a region (used from mobile search-based polygon setter).
     */
    public function saveRegionPolygon(Request $request)
    {
        $validated = $request->validate([
            'region_id' => 'required|integer|exists:t_ops_delivery_region,id',
            'polygon_coordinates' => 'required|string',
            'center_lat' => 'nullable|numeric|between:-90,90',
            'center_lng' => 'nullable|numeric|between:-180,180',
        ]);

        DB::table('t_ops_delivery_region')
            ->where('id', $validated['region_id'])
            ->update([
                'polygon_coordinates' => $validated['polygon_coordinates'],
                'center_lat' => $validated['center_lat'] ?? null,
                'center_lng' => $validated['center_lng'] ?? null,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);

        RegionDetectionService::clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Polygon saved successfully',
        ]);
    }

    public function getAreas(Request $request)
    {
        $query = DB::table('t_ops_delivery_area as a')
            ->join('t_ops_delivery_region as r', 'r.id', '=', 'a.region_id')
            ->select('a.*', 'r.name as region_name', 'r.color as region_color');

        if ($request->has('region_id')) {
            $query->where('a.region_id', $request->region_id);
        }

        $areas = $query->orderBy('r.sort_order')->orderBy('a.area_name')->get();

        return response()->json(['success' => true, 'areas' => $areas]);
    }

    public function saveArea(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'region_id' => 'required|integer|exists:t_ops_delivery_region,id',
            'area_name' => 'required|string|max:200',
            'keywords' => 'nullable|json',
            'center_lat' => 'nullable|numeric|between:-90,90',
            'center_lng' => 'nullable|numeric|between:-180,180',
            'is_active' => 'required|boolean',
        ]);

        $data = [
            'region_id' => $validated['region_id'],
            'area_name' => $validated['area_name'],
            'keywords' => $validated['keywords'] ?? null,
            'center_lat' => $validated['center_lat'] ?? null,
            'center_lng' => $validated['center_lng'] ?? null,
            'is_active' => $validated['is_active'],
            'updated_at' => now(),
        ];

        if (!empty($validated['id'])) {
            DB::table('t_ops_delivery_area')
                ->where('id', $validated['id'])
                ->update($data);
            $id = $validated['id'];
        } else {
            $data['created_at'] = now();
            $id = DB::table('t_ops_delivery_area')->insertGetId($data);
        }

        RegionDetectionService::clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Area saved successfully',
            'area_id' => $id,
        ]);
    }

    public function deleteArea($id)
    {
        DB::table('t_ops_delivery_area')->where('id', $id)->delete();
        RegionDetectionService::clearCache();

        return response()->json(['success' => true, 'message' => 'Area deleted']);
    }

    public function getRiderAssignments()
    {
        $assignments = DB::table('t_ops_rider_region as rr')
            ->join('t_ops_delivery_region as r', 'r.id', '=', 'rr.region_id')
            ->join('t_sys_user as u', 'u.id', '=', 'rr.rider_user_id')
            ->select('rr.*', 'r.name as region_name', 'r.color as region_color', 'u.fullname as rider_name')
            ->where('rr.is_active', 1)
            ->orderBy('r.sort_order')
            ->orderBy('u.fullname')
            ->get();

        return response()->json(['success' => true, 'assignments' => $assignments]);
    }

    public function getActiveRiders()
    {
        $riders = DB::table('t_sys_user as u')
            ->leftJoin('t_ops_rider_profile as p', 'p.user_id', '=', 'u.id')
            ->where('u.is_active', 1)
            ->where(function ($q) {
                $q->whereNull('p.user_id')->orWhere('p.active', 1);
            })
            ->orderBy('u.fullname')
            ->get(['u.id', 'u.fullname']);

        return response()->json(['success' => true, 'riders' => $riders]);
    }

    public function saveRiderAssignment(Request $request)
    {
        $validated = $request->validate([
            'rider_user_id' => 'required|integer',
            'region_id' => 'required|integer|exists:t_ops_delivery_region,id',
            'is_primary' => 'nullable|boolean',
        ]);

        DB::table('t_ops_rider_region')->updateOrInsert(
            [
                'rider_user_id' => $validated['rider_user_id'],
                'region_id' => $validated['region_id'],
            ],
            [
                'is_primary' => $validated['is_primary'] ?? false,
                'is_active' => 1,
                'assigned_by' => auth()->id(),
                'updated_at' => now(),
            ]
        );

        return response()->json(['success' => true, 'message' => 'Rider assigned to region']);
    }

    public function removeRiderAssignment($id)
    {
        DB::table('t_ops_rider_region')->where('id', $id)->update([
            'is_active' => 0,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Rider removed from region']);
    }

    public function batchDetect(Request $request)
    {
        $type = $request->input('type', 'customers');
        $limit = min((int) $request->input('limit', 15000), 15000);

        if ($type === 'orders') {
            $result = RegionDetectionService::batchDetectOrderRegions($limit);
        } else {
            $result = RegionDetectionService::batchDetectCustomerRegions($limit);
        }

        return response()->json([
            'success' => true,
            'message' => "Processed {$result['total']}: {$result['detected']} detected, {$result['failed']} unresolved",
            'result' => $result,
        ]);
    }

    public function getStats()
    {
        $totalOpen = DB::table('t_crm_prod_order')
            ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
            ->count();

        $openWithRegion = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
            ->whereNotNull('c.delivery_region_id')
            ->count();

        $stats = [
            'total_customers' => DB::table('t_crm_prod_customer')->where('is_active', 1)->count(),
            'customers_with_region' => DB::table('t_crm_prod_customer')->where('is_active', 1)->whereNotNull('delivery_region_id')->count(),
            'total_open_orders' => $totalOpen,
            'orders_with_region' => $openWithRegion,
        ];

        $stats['customers_without_region'] = $stats['total_customers'] - $stats['customers_with_region'];
        $stats['orders_without_region'] = $stats['total_open_orders'] - $stats['orders_with_region'];

        return response()->json(['success' => true, 'stats' => $stats]);
    }

    /**
     * Get top customer cities/addresses to help verify area coverage.
     */
    public function getCustomerCities()
    {
        $cities = DB::table('t_crm_prod_customer')
            ->whereNull('merged_into_customer_id')
            ->where('is_active', 1)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->select('city', DB::raw('COUNT(*) as customer_count'))
            ->groupBy('city')
            ->orderBy('customer_count', 'desc')
            ->limit(100)
            ->get();

        $addressPatterns = DB::table('t_crm_prod_customer')
            ->whereNull('merged_into_customer_id')
            ->where('is_active', 1)
            ->whereNotNull('address1')
            ->where('address1', '!=', '')
            ->select('address1', 'city', 'delivery_region_id')
            ->orderByDesc('last_order_date')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'cities' => $cities,
            'recent_addresses' => $addressPatterns,
        ]);
    }

    public function getRegionsList()
    {
        $regions = DB::table('t_ops_delivery_region')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code', 'color']);

        return response()->json(['success' => true, 'regions' => $regions]);
    }

    public function getRegionsWithStats()
    {
        $regions = DB::table('t_ops_delivery_region')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code', 'color', 'sort_order', 'polygon_coordinates']);

        foreach ($regions as $region) {
            $region->customer_count = DB::table('t_crm_prod_customer')
                ->where('delivery_region_id', $region->id)
                ->where('is_active', 1)
                ->count();

            $region->open_order_count = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->where('c.delivery_region_id', $region->id)
                ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded'])
                ->count();

            $region->polygon_coordinates = !empty($region->polygon_coordinates) ? true : false;
        }

        return response()->json(['success' => true, 'regions' => $regions]);
    }

    public function setOrderRegion(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'delivery_region_id' => 'nullable|integer',
        ]);

        $order = DB::table('t_crm_prod_order')->where('id', $validated['order_id'])->first();
        if (!$order || !$order->customer_id) {
            return response()->json(['success' => false, 'message' => 'Order has no customer'], 400);
        }

        DB::table('t_crm_prod_customer')
            ->where('id', $order->customer_id)
            ->update([
                'delivery_region_id' => $validated['delivery_region_id'],
                'delivery_region_source' => 'manual',
            ]);

        if ($validated['delivery_region_id']) {
            $this->learnFromManualAssignment($order->customer_id, $validated['delivery_region_id']);
        }

        $regionName = $validated['delivery_region_id']
            ? DB::table('t_ops_delivery_region')->where('id', $validated['delivery_region_id'])->value('name')
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Customer region updated (via order)',
            'region_name' => $regionName,
        ]);
    }

    public function setCustomerRegion(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'delivery_region_id' => 'nullable|integer',
        ]);

        DB::table('t_crm_prod_customer')
            ->where('id', $validated['customer_id'])
            ->update([
                'delivery_region_id' => $validated['delivery_region_id'],
                'delivery_region_source' => 'manual',
            ]);

        if ($validated['delivery_region_id']) {
            $this->learnFromManualAssignment($validated['customer_id'], $validated['delivery_region_id']);
        }

        $regionName = $validated['delivery_region_id']
            ? DB::table('t_ops_delivery_region')->where('id', $validated['delivery_region_id'])->value('name')
            : null;

        return response()->json([
            'success' => true,
            'message' => 'Customer region updated',
            'region_name' => $regionName,
        ]);
    }

    /**
     * When a customer is manually assigned to a region, extract area identifiers
     * from their address and add as keywords so future customers from the same
     * area are auto-detected.
     */
    private function learnFromManualAssignment(int $customerId, int $regionId): void
    {
        try {
            $customer = DB::table('t_crm_prod_customer')->find($customerId);
            if (!$customer) return;

            $address = trim(($customer->address1 ?? '') . ' ' . ($customer->address2 ?? ''));
            if (!$address) return;

            $keywords = $this->extractAreaKeywords($address);
            if (empty($keywords)) return;

            $existingAreas = DB::table('t_ops_delivery_area')
                ->where('region_id', $regionId)
                ->where('is_active', 1)
                ->get(['id', 'area_name', 'keywords']);

            $allExistingKeywords = [];
            foreach ($existingAreas as $area) {
                $areaKeywords = json_decode($area->keywords ?? '[]', true) ?: [];
                $areaKeywords[] = mb_strtolower($area->area_name);
                foreach ($areaKeywords as $kw) {
                    $allExistingKeywords[mb_strtolower(trim($kw))] = true;
                }
            }

            $newKeywords = [];
            foreach ($keywords as $kw) {
                if (!isset($allExistingKeywords[mb_strtolower($kw)])) {
                    $newKeywords[] = $kw;
                }
            }

            if (empty($newKeywords)) return;

            $areaName = $newKeywords[0];
            DB::table('t_ops_delivery_area')->insert([
                'region_id' => $regionId,
                'area_name' => ucfirst($areaName),
                'keywords' => json_encode(array_map('mb_strtolower', $newKeywords)),
                'is_active' => 1,
                'created_by' => auth()->id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            RegionDetectionService::clearCache();
        } catch (\Exception $e) {
            \Log::warning('learnFromManualAssignment failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract area identifiers from an address string.
     * Matches patterns like "I-8/4", "F-7", "DHA Phase 2", "Bahria Town", sector names, etc.
     */
    private function extractAreaKeywords(string $address): array
    {
        $keywords = [];
        $addressLower = mb_strtolower(trim($address));

        // Islamabad sectors: I-8, F-7, G-11, E-11, etc. (with optional sub-sector like /1, /2, /4)
        if (preg_match_all('/\b([a-z]-\d{1,2})(?:\/\d+)?\b/i', $address, $matches)) {
            foreach ($matches[1] as $sector) {
                $keywords[] = mb_strtolower($sector);
            }
        }

        // DHA patterns: "dha phase 1", "dha phase 2", "defence", "dha"
        if (preg_match('/\b(dha\s*(?:phase\s*\d+)?|defence\s*(?:phase\s*\d+)?)\b/i', $address, $m)) {
            $keywords[] = mb_strtolower(trim($m[1]));
        }

        // Bahria patterns: "bahria town", "bahria phase 8", "bahria enclave"
        if (preg_match('/\b(bahria\s*(?:town|phase\s*\d+|enclave)?)\b/i', $address, $m)) {
            $keywords[] = mb_strtolower(trim($m[1]));
        }

        // PWD, CBR, Media Town, Gulberg, etc.
        $knownAreas = ['pwd', 'cbr', 'media town', 'gulberg', 'naval anchorage',
            'askari', 'fazaia', 'airport housing', 'judicial colony', 'wapda town',
            'satellite town', 'saddar', 'chaklala', 'westridge', 'committee chowk',
            'korang town', 'chak shahzad', 'bani gala', 'soan garden', 'park road',
            'tramri', 'tarlai', 'alipur', 'lohi bher', 'kuri road', 'lehtrar road',
            'margalla', 'rawal town', 'swan', 'khanna', 'adiala'];
        foreach ($knownAreas as $area) {
            if (mb_strpos($addressLower, $area) !== false) {
                $keywords[] = $area;
            }
        }

        return array_values(array_unique($keywords));
    }

    /**
     * Auto-assign riders to open orders based on region → rider mapping.
     * mode=unassigned: only orders without a rider
     * mode=reassign: all orders except out_for_delivery
     */
    public function autoAssignRiders(Request $request)
    {
        $mode = $request->input('mode', 'unassigned');

        $riderMap = DB::table('t_ops_rider_region')
            ->where('is_active', 1)
            ->orderByDesc('is_primary')
            ->get(['region_id', 'rider_user_id', 'is_primary']);

        $regionToRider = [];
        foreach ($riderMap as $rm) {
            if (!isset($regionToRider[$rm->region_id])) {
                $regionToRider[$rm->region_id] = $rm->rider_user_id;
            }
        }

        if (empty($regionToRider)) {
            return response()->json([
                'success' => false,
                'message' => 'No rider-region assignments found. Assign riders to regions first.',
            ]);
        }

        $totalOpen = DB::table('t_crm_prod_order')
            ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded', 'out_for_delivery'])
            ->count();

        $alreadyAssigned = DB::table('t_crm_prod_order')
            ->whereNotIn('order_status', ['delivered', 'completed', 'cancelled', 'refunded', 'out_for_delivery'])
            ->whereNotNull('assigned_rider_user_id')
            ->count();

        $noRegion = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded', 'out_for_delivery'])
            ->where(function ($q) use ($regionToRider) {
                $q->whereNull('c.delivery_region_id')
                  ->orWhereNotIn('c.delivery_region_id', array_keys($regionToRider));
            })
            ->count();

        $query = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNotIn('o.order_status', ['delivered', 'completed', 'cancelled', 'refunded', 'out_for_delivery'])
            ->whereNotNull('c.delivery_region_id')
            ->whereIn('c.delivery_region_id', array_keys($regionToRider));

        if ($mode === 'unassigned') {
            $query->whereNull('o.assigned_rider_user_id');
        }

        $orders = $query->get(['o.id', 'o.assigned_rider_user_id', 'c.delivery_region_id']);

        $assigned = 0;
        $skipped = 0;
        $changed = 0;

        foreach ($orders as $order) {
            $newRiderId = $regionToRider[$order->delivery_region_id] ?? null;
            if (!$newRiderId) {
                $skipped++;
                continue;
            }

            if ($order->assigned_rider_user_id == $newRiderId) {
                $skipped++;
                continue;
            }

            $wasAssigned = !empty($order->assigned_rider_user_id);

            DB::table('t_crm_prod_order')
                ->where('id', $order->id)
                ->update([
                    'assigned_rider_user_id' => $newRiderId,
                    'rider_sync_required' => true,
                    'updated_at' => now(),
                ]);

            if ($wasAssigned) {
                $changed++;
            } else {
                $assigned++;
            }
        }

        $riderNames = DB::table('t_sys_user')
            ->whereIn('id', array_values($regionToRider))
            ->pluck('fullname', 'id');

        $regionNames = DB::table('t_ops_delivery_region')
            ->whereIn('id', array_keys($regionToRider))
            ->pluck('name', 'id');

        $mappingDesc = [];
        foreach ($regionToRider as $regionId => $riderId) {
            $mappingDesc[] = ($regionNames[$regionId] ?? "Region $regionId") . ' → ' . ($riderNames[$riderId] ?? "Rider $riderId");
        }

        $parts = [];
        if ($assigned > 0) $parts[] = "$assigned newly assigned";
        if ($changed > 0) $parts[] = "$changed re-assigned";
        if ($skipped > 0) $parts[] = "$skipped already correct";
        $actionSummary = implode(', ', $parts) ?: 'No changes made';

        $contextParts = [];
        $contextParts[] = "$totalOpen open orders total";
        if ($alreadyAssigned > 0 && $mode === 'unassigned') {
            $contextParts[] = "$alreadyAssigned already had riders";
        }
        if ($noRegion > 0) {
            $contextParts[] = "$noRegion without region/rider mapping";
        }

        $message = $actionSummary . "\n(" . implode(', ', $contextParts) . ")";

        return response()->json([
            'success' => true,
            'message' => $message,
            'assigned' => $assigned,
            'changed' => $changed,
            'skipped' => $skipped,
            'total_open' => $totalOpen,
            'already_assigned' => $alreadyAssigned,
            'no_region' => $noRegion,
            'mapping' => $mappingDesc,
        ]);
    }

    /**
     * Re-detect regions for ALL customers (force update).
     * reset_manual=true will also overwrite manually set regions.
     */
    public function redetectAll(Request $request)
    {
        $type = $request->input('type', 'customers');
        $resetManual = $request->boolean('reset_manual', false);

        RegionDetectionService::clearCache();

        if ($type === 'orders') {
            $query = DB::table('t_crm_prod_order as o')
                ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
                ->whereNotIn('o.order_status', ['cancelled', 'refunded']);

            if (!$resetManual) {
                $query->where(function ($q) {
                    $q->whereNull('c.delivery_region_source')
                      ->orWhere('c.delivery_region_source', '!=', 'manual');
                });
            }

            $customerIds = $query->distinct()->pluck('o.customer_id');

            $updated = 0;
            $cleared = 0;
            foreach ($customerIds as $cid) {
                if ($resetManual) {
                    DB::table('t_crm_prod_customer')
                        ->where('id', $cid)
                        ->update(['delivery_region_id' => null, 'delivery_region_source' => null]);
                }
                $regionId = RegionDetectionService::detectAndSaveForCustomer($cid, true);
                if ($regionId) {
                    $updated++;
                } else {
                    $cleared++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Re-detected " . count($customerIds) . " order customers: {$updated} matched, {$cleared} cleared",
            ]);
        } else {
            $query = DB::table('t_crm_prod_customer')
                ->where('is_active', 1);

            if (!$resetManual) {
                $query->where(function ($q) {
                    $q->whereNull('delivery_region_source')
                      ->orWhere('delivery_region_source', '!=', 'manual');
                });
            }

            $customers = $query->get(['id']);
            $updated = 0;
            $cleared = 0;
            foreach ($customers as $customer) {
                if ($resetManual) {
                    DB::table('t_crm_prod_customer')
                        ->where('id', $customer->id)
                        ->update(['delivery_region_id' => null, 'delivery_region_source' => null]);
                }
                $regionId = RegionDetectionService::detectAndSaveForCustomer($customer->id, true);
                if ($regionId) {
                    $updated++;
                } else {
                    $cleared++;
                }
            }

            $skippedManual = $resetManual ? 0 : DB::table('t_crm_prod_customer')
                ->where('is_active', 1)
                ->where('delivery_region_source', 'manual')
                ->count();

            $msg = "Re-detected " . count($customers) . " customers: {$updated} matched, {$cleared} cleared";
            if ($skippedManual > 0) {
                $msg .= " ({$skippedManual} manual assignments preserved)";
            }

            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        }
    }
}
