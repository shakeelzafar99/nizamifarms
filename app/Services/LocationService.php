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
     * Calculate distance from primary base location
     * 
     * @param float $latitude User's latitude
     * @param float $longitude User's longitude
     * @return array ['distance_meters' => int, 'is_remote' => bool, 'base_location' => object|null, 'error' => string|null]
     */
    public static function calculateDistanceFromBase($latitude, $longitude): array
    {
        $baseLocation = self::getPrimaryBaseLocation();
        
        if (!$baseLocation) {
            Log::warning('No base location configured for attendance');
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

