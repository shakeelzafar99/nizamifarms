<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class RegionDetectionService
{
    /**
     * Detect delivery region for a customer and persist it.
     * Safe to call multiple times — skips if already set unless $force = true.
     */
    public static function detectAndSaveForCustomer(int $customerId, bool $force = false): ?int
    {
        $customer = DB::table('t_crm_prod_customer')->find($customerId);
        if (!$customer) {
            return null;
        }

        if (!$force && $customer->delivery_region_id) {
            return (int) $customer->delivery_region_id;
        }

        $result = self::detectRegion(
            $customer->latitude,
            $customer->longitude,
            $customer->geocoded_latitude,
            $customer->geocoded_longitude,
            $customer->address1,
            $customer->address2 ?? null,
            $customer->city
        );

        if ($result) {
            DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    'delivery_region_id' => $result['region_id'],
                    'delivery_region_source' => $result['source'],
                    'updated_at' => now(),
                ]);
            return $result['region_id'];
        }

        if ($force && $customer->delivery_region_source !== 'manual') {
            DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    'delivery_region_id' => null,
                    'delivery_region_source' => null,
                    'updated_at' => now(),
                ]);
        }

        return null;
    }

    /**
     * Detect region for an order's customer and ensure customer is assigned.
     * Region is always read from customer (single source of truth).
     */
    public static function detectAndSaveForOrder(int $orderId, ?int $customerId = null): ?int
    {
        if (!$customerId) {
            $order = DB::table('t_crm_prod_order')->find($orderId);
            $customerId = $order->customer_id ?? null;
        }

        if (!$customerId) {
            return null;
        }

        return self::detectAndSaveForCustomer($customerId);
    }

    /**
     * Core detection: tries GPS polygon first, then keyword matching, then city fallback.
     * Returns ['region_id' => int, 'source' => string] or null.
     */
    public static function detectRegion(
        ?float $verifiedLat, ?float $verifiedLng,
        ?float $geocodedLat, ?float $geocodedLng,
        ?string $addressLine1, ?string $addressLine2,
        ?string $city
    ): ?array {
        $regions = self::getActiveRegions();
        if (empty($regions)) {
            return null;
        }

        // Priority 1: Verified GPS → polygon check
        if ($verifiedLat && $verifiedLng) {
            $regionId = self::findRegionByCoordinates($verifiedLat, $verifiedLng, $regions);
            if ($regionId) {
                return ['region_id' => $regionId, 'source' => 'polygon_verified'];
            }
        }

        // Priority 2: Geocoded GPS → polygon check
        if ($geocodedLat && $geocodedLng) {
            $regionId = self::findRegionByCoordinates($geocodedLat, $geocodedLng, $regions);
            if ($regionId) {
                return ['region_id' => $regionId, 'source' => 'polygon_geocoded'];
            }
        }

        // Priority 3: Address keyword matching
        $fullAddress = trim(implode(' ', array_filter([$addressLine1, $addressLine2, $city])));
        if ($fullAddress) {
            $regionId = self::findRegionByKeywords($fullAddress);
            if ($regionId) {
                return ['region_id' => $regionId, 'source' => 'keyword'];
            }
        }

        return null;
    }

    /**
     * Point-in-polygon check against all regions with defined polygons.
     * Supports both single polygon and multi-polygon formats.
     */
    private static function findRegionByCoordinates(float $lat, float $lng, array $regions): ?int
    {
        foreach ($regions as $region) {
            if (empty($region->polygon_coordinates)) {
                continue;
            }

            $polygons = self::parsePolygons($region->polygon_coordinates);

            foreach ($polygons as $polygon) {
                if (count($polygon) < 3) continue;
                if (self::pointInPolygon($lat, $lng, $polygon)) {
                    return (int) $region->id;
                }
            }
        }
        return null;
    }

    /**
     * Parse polygon_coordinates JSON into an array of polygons.
     * Handles both old format (single polygon: [[lat,lng],...])
     * and new format (multi-polygon: [[[lat,lng],...], [[lat,lng],...]]).
     */
    public static function parsePolygons(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data)) {
            return [];
        }

        // Detect format: if first element is a [number, number] pair, it's a single polygon
        if (isset($data[0]) && is_array($data[0]) && count($data[0]) === 2
            && is_numeric($data[0][0]) && is_numeric($data[0][1])) {
            return [$data];
        }

        // Multi-polygon format: array of polygon arrays
        return $data;
    }

    /**
     * Ray-casting algorithm for point-in-polygon test.
     * Polygon is array of [lat, lng] pairs.
     */
    private static function pointInPolygon(float $lat, float $lng, array $polygon): bool
    {
        $n = count($polygon);
        $inside = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $yi = (float) $polygon[$i][0];
            $xi = (float) $polygon[$i][1];
            $yj = (float) $polygon[$j][0];
            $xj = (float) $polygon[$j][1];

            if (($yi > $lat) !== ($yj > $lat)
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)
            ) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Match address text against known area keywords.
     * Returns first matching region_id or null.
     */
    private static function findRegionByKeywords(string $address): ?int
    {
        $areas = self::getActiveAreas();
        $addressLower = mb_strtolower($address);

        $bestMatch = null;
        $bestLength = 0;

        foreach ($areas as $area) {
            $keywords = json_decode($area->keywords ?? '[]', true);
            if (!is_array($keywords)) {
                $keywords = [];
            }

            $keywords[] = mb_strtolower($area->area_name);

            foreach ($keywords as $keyword) {
                $keyword = mb_strtolower(trim($keyword));
                if ($keyword && mb_strpos($addressLower, $keyword) !== false) {
                    if (mb_strlen($keyword) > $bestLength) {
                        $bestLength = mb_strlen($keyword);
                        $bestMatch = (int) $area->region_id;
                    }
                }
            }
        }

        return $bestMatch;
    }

    /**
     * City-level fallback: "Rawalpindi" → rwp, "Islamabad" → isb.
     * Only matches regions that have a polygon defined (i.e. explicitly configured).
     */
    private static function findRegionByCity(?string $city): ?int
    {
        if (!$city) {
            return null;
        }
        $cityLower = mb_strtolower(trim($city));

        $cityMap = Cache::remember('region_city_map', 3600, function () {
            $map = [];
            $regions = DB::table('t_ops_delivery_region')
                ->where('is_active', 1)
                ->whereNotNull('polygon_coordinates')
                ->get(['id', 'code', 'polygon_coordinates']);

            foreach ($regions as $r) {
                $poly = json_decode($r->polygon_coordinates ?? '', true);
                if (!is_array($poly) || count($poly) < 3) {
                    continue;
                }
                if ($r->code === 'rwp') {
                    $map['rawalpindi'] = (int) $r->id;
                    $map['pindi'] = (int) $r->id;
                    $map['rwp'] = (int) $r->id;
                } elseif ($r->code === 'isb') {
                    $map['islamabad'] = (int) $r->id;
                    $map['isb'] = (int) $r->id;
                }
            }
            return $map;
        });

        foreach ($cityMap as $keyword => $regionId) {
            if (mb_strpos($cityLower, $keyword) !== false) {
                return $regionId;
            }
        }
        return null;
    }

    /**
     * Find nearest region by haversine distance to region center.
     */
    private static function findNearestRegion(float $lat, float $lng, array $regions): ?int
    {
        $nearest = null;
        $minDist = PHP_FLOAT_MAX;

        foreach ($regions as $region) {
            if (!$region->center_lat || !$region->center_lng) {
                continue;
            }
            $dist = self::haversineDistance($lat, $lng, (float) $region->center_lat, (float) $region->center_lng);
            if ($dist < $minDist) {
                $minDist = $dist;
                $nearest = (int) $region->id;
            }
        }

        // Only match if within 50 km (reasonable for Islamabad/Rawalpindi metro)
        return ($minDist <= 50) ? $nearest : null;
    }

    private static function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $R * $c;
    }

    /**
     * Cached fetch of active regions.
     */
    private static function getActiveRegions(): array
    {
        return Cache::remember('delivery_regions_active', 300, function () {
            return DB::table('t_ops_delivery_region')
                ->where('is_active', 1)
                ->orderBy('sort_order')
                ->get()
                ->all();
        });
    }

    /**
     * Cached fetch of active areas.
     */
    private static function getActiveAreas(): array
    {
        return Cache::remember('delivery_areas_active', 300, function () {
            return DB::table('t_ops_delivery_area')
                ->where('is_active', 1)
                ->get()
                ->all();
        });
    }

    /**
     * Flush all region-related caches (call after admin edits regions/areas).
     */
    public static function clearCache(): void
    {
        Cache::forget('delivery_regions_active');
        Cache::forget('delivery_areas_active');
        Cache::forget('region_city_map');
    }

    /**
     * Batch detect regions for existing customers without a region.
     */
    public static function batchDetectCustomerRegions(int $limit = 15000): array
    {
        $customers = DB::table('t_crm_prod_customer')
            ->whereNull('delivery_region_id')
            ->where('is_active', 1)
            ->limit($limit)
            ->get(['id']);

        $detected = 0;
        $failed = 0;

        foreach ($customers as $customer) {
            $regionId = self::detectAndSaveForCustomer($customer->id);
            if ($regionId) {
                $detected++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($customers),
            'detected' => $detected,
            'failed' => $failed,
        ];
    }

    /**
     * Batch detect regions for customers of open orders that lack a region.
     * Region lives on customer only; orders inherit via join.
     */
    public static function batchDetectOrderRegions(int $limit = 15000): array
    {
        $customerIds = DB::table('t_crm_prod_order as o')
            ->join('t_crm_prod_customer as c', 'c.id', '=', 'o.customer_id')
            ->whereNull('c.delivery_region_id')
            ->whereNotIn('o.order_status', ['cancelled', 'refunded'])
            ->distinct()
            ->limit($limit)
            ->pluck('o.customer_id');

        $detected = 0;
        $failed = 0;

        foreach ($customerIds as $customerId) {
            $regionId = self::detectAndSaveForCustomer($customerId);
            if ($regionId) {
                $detected++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($customerIds),
            'detected' => $detected,
            'failed' => $failed,
        ];
    }
}
