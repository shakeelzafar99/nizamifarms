<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LocationService
 * 
 * Handles GPS location calculations and distance computations
 * for attendance tracking and other location-based features.
 */
class LocationService
{
    /**
     * Calculate distance between two GPS coordinates using Haversine formula
     * 
     * The Haversine formula determines the great-circle distance between two points
     * on a sphere given their longitudes and latitudes.
     * 
     * @param float $lat1 Latitude of first point
     * @param float $lon1 Longitude of first point
     * @param float $lat2 Latitude of second point
     * @param float $lon2 Longitude of second point
     * @return float Distance in meters
     */
    public static function calculateDistance($lat1, $lon1, $lat2, $lon2): float
    {
        $earthRadius = 6371000; // Earth radius in meters
        
        // Convert degrees to radians
        $lat1 = deg2rad($lat1);
        $lat2 = deg2rad($lat2);
        $lon1 = deg2rad($lon1);
        $lon2 = deg2rad($lon2);
        
        // Calculate differences
        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;
        
        // Haversine formula
        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1) * cos($lat2) *
             sin($deltaLon / 2) * sin($deltaLon / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c; // Distance in meters
    }

    /**
     * Get primary base location for attendance
     * 
     * @return object|null Base location {id, location_name, latitude, longitude, radius_meters}
     */
    public static function getPrimaryBaseLocation()
    {
        return DB::table('t_ops_company_locations')
            ->where('is_primary', 1)
            ->where('is_active', 1)
            ->select('id', 'location_name', 'latitude', 'longitude', 'radius_meters')
            ->first();
    }

    /**
     * Get the configured Qurbani Operations Base.
     *
     * Qurbani is seasonal — the slaughter / packing depot is usually a
     * different physical location from the regular office. Storing it
     * as a ConfigModel key/value (rather than a row in
     * t_ops_company_locations) keeps it cleanly toggleable for the
     * season without polluting the attendance pickers.
     *
     * Returns null if any of name/lat/lng is unset, so callers can
     * fall back to the regular office.
     *
     * Shape mirrors getPrimaryBaseLocation() so callers can swap.
     *
     * @return object|null {id:null, location_name, latitude, longitude, radius_meters:null, is_qurbani_base:true}
     */
    public static function getQurbaniBaseLocation()
    {
        $name = \App\Models\FIN\ConfigModel::get('qurbani_base_name');
        $lat  = \App\Models\FIN\ConfigModel::get('qurbani_base_lat');
        $lng  = \App\Models\FIN\ConfigModel::get('qurbani_base_lng');
        if (!$name || !$lat || !$lng) {
            return null;
        }
        return (object) [
            'id'              => null,
            'location_name'   => (string) $name,
            'latitude'        => (float) $lat,
            'longitude'       => (float) $lng,
            'radius_meters'   => null,
            'is_qurbani_base' => true,
        ];
    }

    /**
     * Origin lookup for Qurbani route ETA / dispatch.
     *
     * Priority chain (matches the Qurbani plan agreed May-2026):
     *   1) Rider's recent GPS  (≤30 min old)
     *   2) Qurbani Operations Base (from ConfigModel)
     *   3) Rider's assigned company office
     *   4) Primary company office
     *
     * Returns an associative array with the latitude/longitude PLUS a
     * `source` enum so the API response can tell the user where the
     * origin came from. The shape is intentionally small — callers
     * only need lat/lng/source for the actual Google Directions call.
     *
     * @param int $riderId
     * @param int $gpsFreshnessMinutes Default 30 — how recent the rider GPS reading must be
     * @return array{latitude:float, longitude:float, source:string, source_label:string}|null
     */
    public static function getQurbaniOriginForRider(int $riderId, int $gpsFreshnessMinutes = 30): ?array
    {
        // 1. Rider GPS (preferred — most accurate origin while OFD).
        $gps = DB::table('t_ops_rider_location')
            ->where('user_id', $riderId)
            ->where('captured_at', '>=', now()->subMinutes($gpsFreshnessMinutes))
            ->orderBy('captured_at', 'desc')
            ->first();
        if ($gps && $gps->latitude && $gps->longitude) {
            return [
                'latitude'     => (float) $gps->latitude,
                'longitude'    => (float) $gps->longitude,
                'source'       => 'rider_gps',
                'source_label' => 'Rider GPS',
            ];
        }

        // 2. Qurbani Operations Base — Qurbani-specific override before
        //    we fall back to generic office locations.
        $qBase = self::getQurbaniBaseLocation();
        if ($qBase) {
            return [
                'latitude'     => (float) $qBase->latitude,
                'longitude'    => (float) $qBase->longitude,
                'source'       => 'qurbani_base',
                'source_label' => 'Qurbani Base (' . $qBase->location_name . ')',
            ];
        }

        // 3-4. Existing fallback chain — assigned company location, then
        //      primary company location.
        $assigned = self::getUserAssignedLocation($riderId);
        if ($assigned && $assigned->latitude && $assigned->longitude) {
            return [
                'latitude'     => (float) $assigned->latitude,
                'longitude'    => (float) $assigned->longitude,
                'source'       => 'assigned_office',
                'source_label' => 'Office (' . $assigned->location_name . ')',
            ];
        }
        return null;
    }

    /**
     * Get assigned location for a specific user
     * Falls back to primary location if no assignment exists
     * 
     * @param int $userId User ID
     * @return object|null Location {id, location_name, latitude, longitude, radius_meters}
     */
    public static function getUserAssignedLocation(int $userId)
    {
        // First, try to get user's assigned location
        $assignedLocation = DB::table('t_ops_user_location_assignment as ula')
            ->join('t_ops_company_locations as loc', 'loc.id', '=', 'ula.location_id')
            ->where('ula.user_id', $userId)
            ->where('ula.is_active', 1)
            ->where('loc.is_active', 1)
            ->select('loc.id', 'loc.location_name', 'loc.latitude', 'loc.longitude', 'loc.radius_meters')
            ->first();

        // If user has an assigned location, return it
        if ($assignedLocation) {
            return $assignedLocation;
        }

        // Otherwise, fall back to primary location
        return self::getPrimaryBaseLocation();
    }

    /**
     * Calculate distance from primary base location
     * 
     * @param float $latitude User's latitude
     * @param float $longitude User's longitude
     * @param int|null $userId Optional user ID to check assigned location
     * @return array ['distance_meters' => int, 'is_remote' => bool, 'base_location' => object|null, 'error' => string|null]
     */
    public static function calculateDistanceFromBase($latitude, $longitude, $userId = null, ?int $baseLocationId = null): array
    {
        // Explicit base (e.g. today's SHIFT location) wins when valid & active; else fall
        // back to the user's assigned location, else the primary.
        $baseLocation = null;
        if ($baseLocationId) {
            $baseLocation = DB::table('t_ops_company_locations')
                ->where('id', $baseLocationId)->where('is_active', 1)
                ->first(['id', 'location_name', 'latitude', 'longitude', 'radius_meters']);
        }
        if (!$baseLocation) {
            $baseLocation = $userId
                ? self::getUserAssignedLocation($userId)
                : self::getPrimaryBaseLocation();
        }

        if (!$baseLocation) {
            Log::warning('No base location configured for attendance', ['user_id' => $userId]);
            return [
                'distance_meters' => null,
                'is_remote' => false,
                'base_location' => null,
                'error' => 'No base location configured'
            ];
        }

        try {
            $distance = self::calculateDistance(
                $latitude,
                $longitude,
                $baseLocation->latitude,
                $baseLocation->longitude
            );

            return [
                'distance_meters' => (int) round($distance),
                'is_remote' => $distance > $baseLocation->radius_meters,
                'base_location' => $baseLocation,
                'error' => null
            ];
        } catch (\Exception $e) {
            Log::error('Distance calculation error', [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'distance_meters' => null,
                'is_remote' => false,
                'base_location' => $baseLocation,
                'error' => 'Distance calculation failed'
            ];
        }
    }

    /**
     * Format distance for display
     * 
     * @param int $meters Distance in meters
     * @return string Formatted distance (e.g., "3.2 km", "450 m")
     */
    public static function formatDistance(int $meters): string
    {
        if ($meters >= 1000) {
            $km = $meters / 1000;
            return number_format($km, 1) . ' km';
        }
        return $meters . ' m';
    }

    /**
     * Validate GPS coordinates
     * 
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public static function isValidCoordinates($latitude, $longitude): bool
    {
        return is_numeric($latitude) && 
               is_numeric($longitude) &&
               $latitude >= -90 && 
               $latitude <= 90 &&
               $longitude >= -180 && 
               $longitude <= 180;
    }

    /**
     * Get formatted location info for display
     * 
     * @param float|null $latitude
     * @param float|null $longitude
     * @param int|null $distance Distance in meters
     * @param bool $isRemote
     * @return array ['has_location' => bool, 'display_text' => string, 'badge_class' => string]
     */
    public static function getLocationDisplay($latitude, $longitude, $distance, $isRemote): array
    {
        if (is_null($latitude) || is_null($longitude)) {
            return [
                'has_location' => false,
                'display_text' => 'No location',
                'badge_class' => 'no-location',
                'icon' => 'question-circle'
            ];
        }

        if ($isRemote && !is_null($distance)) {
            return [
                'has_location' => true,
                'display_text' => self::formatDistance($distance) . ' from office',
                'badge_class' => 'remote',
                'icon' => 'map-marker-alt'
            ];
        }

        return [
            'has_location' => true,
            'display_text' => 'At office',
            'badge_class' => 'normal',
            'icon' => 'check-circle'
        ];
    }
}

