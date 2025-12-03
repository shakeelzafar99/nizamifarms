<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeocodingService
{
    /**
     * Geocode an address using OpenStreetMap Nominatim (free)
     * Tries multiple simplified versions of the address for better success rate
     * Returns [latitude, longitude] or null if failed
     */
    public static function geocodeAddress(string $address, string $city = null): ?array
    {
        if (empty($address)) {
            return null;
        }

        // Build address variations to try (most specific to least specific)
        $addressVariations = self::buildAddressVariations($address, $city);
        
        foreach ($addressVariations as $fullAddress) {
            // Check cache first (cache for 30 days)
            $cacheKey = 'geocode_' . md5(strtolower($fullAddress));
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                if ($cached) {
                    return $cached; // Return cached successful result
                }
                continue; // Skip this variation if it failed before
            }

            try {
                // Rate limit: don't geocode more than once per second
                $lastGeocode = Cache::get('geocode_last_request');
                if ($lastGeocode && (microtime(true) - $lastGeocode) < 1.1) {
                    usleep(1100000 - (int)((microtime(true) - $lastGeocode) * 1000000));
                }
                Cache::put('geocode_last_request', microtime(true), 60);

                $response = Http::timeout(10)
                    ->withHeaders([
                        'User-Agent' => 'NizamiFarms/1.0 (delivery-management)',
                        'Accept' => 'application/json',
                    ])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $fullAddress,
                        'format' => 'json',
                        'limit' => 1,
                    ]);

                if ($response->successful()) {
                    $results = $response->json();
                    
                    if (!empty($results) && isset($results[0]['lat'], $results[0]['lon'])) {
                        $coords = [
                            'latitude' => (float) $results[0]['lat'],
                            'longitude' => (float) $results[0]['lon'],
                        ];
                        
                        // Cache successful result for 30 days
                        Cache::put($cacheKey, $coords, now()->addDays(30));
                        
                        Log::info('Geocoding successful', [
                            'original_address' => $address,
                            'matched_query' => $fullAddress,
                            'coords' => $coords,
                        ]);
                        
                        return $coords;
                    }
                }

                // Cache failed result for this variation (1 day)
                Cache::put($cacheKey, false, now()->addDay());

            } catch (\Exception $e) {
                Log::error('Geocoding error', [
                    'address' => $fullAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::warning('Geocoding failed - no results for any variation', [
            'original_address' => $address,
            'city' => $city,
            'variations_tried' => count($addressVariations),
        ]);
        
        return null;
    }
    
    /**
     * Build multiple address variations to try for geocoding
     * Nominatim works better with simpler, well-known location names
     */
    private static function buildAddressVariations(string $address, ?string $city): array
    {
        $variations = [];
        $country = 'Pakistan';
        
        // Clean up address
        $cleanAddress = trim($address);
        
        // Extract sector/phase/block patterns common in Pakistan
        // Examples: F-6/1, DHA Phase 1, E-11/4, Bahria Town Phase 2, Askari Heights
        $sectorPatterns = [
            '/\b([A-Z]-\d+\/\d+)\b/i',           // F-6/1, E-11/4
            '/\b([A-Z]-\d+)\b/i',                 // F-6, E-11
            '/\bDHA Phase \d+\b/i',               // DHA Phase 1
            '/\bDHA \d+\b/i',                     // DHA 5
            '/\bBahria Town[,\s]*Phase \d+\b/i',  // Bahria Town Phase 2
            '/\bSector [A-Z0-9]+\b/i',            // Sector F, Sector 1
            '/\bBlock [A-Z0-9]+\b/i',             // Block H
        ];
        
        // Special patterns for known housing societies
        $housingSocietyPatterns = [
            '/\bAskari Heights?\s*\d*\b/i',       // Askari Heights, Askari Heights 4
            '/\bAskari Tower\s*\d*\b/i',          // Askari Tower 5
            '/\bG-\d+\s*Markaz\b/i',              // G-9 Markaz
            '/\bF-\d+\s*Markaz\b/i',              // F-6 Markaz
        ];
        
        // Try 1: Full address with city
        if ($city) {
            $variations[] = $cleanAddress . ', ' . $city . ', ' . $country;
        }
        $variations[] = $cleanAddress . ', ' . $country;
        
        // Try 2: Extract known housing societies/buildings first (more specific)
        foreach ($housingSocietyPatterns as $pattern) {
            if (preg_match($pattern, $cleanAddress, $matches)) {
                $building = $matches[0];
                // Try with DHA if address mentions DHA
                if (preg_match('/DHA\s*\d*/i', $cleanAddress, $dhaMatch)) {
                    $variations[] = $building . ', ' . $dhaMatch[0] . ', Islamabad, ' . $country;
                    $variations[] = $building . ', ' . $dhaMatch[0] . ', Rawalpindi, ' . $country;
                }
                if ($city) {
                    $variations[] = $building . ', ' . $city . ', ' . $country;
                }
                $variations[] = $building . ', Islamabad, ' . $country;
            }
        }
        
        // Try 3: Extract known sector/area and use that
        foreach ($sectorPatterns as $pattern) {
            if (preg_match($pattern, $cleanAddress, $matches)) {
                $sector = $matches[0];
                if ($city) {
                    $variations[] = $sector . ', ' . $city . ', ' . $country;
                }
                // Try with Islamabad/Rawalpindi if not specified
                if (!$city || !preg_match('/islamabad|rawalpindi/i', $city)) {
                    $variations[] = $sector . ', Islamabad, ' . $country;
                    $variations[] = $sector . ', Rawalpindi, ' . $country;
                }
            }
        }
        
        // Try 4: Just city if we have it
        if ($city) {
            $variations[] = $city . ', ' . $country;
        }
        
        // Remove duplicates while preserving order
        return array_values(array_unique($variations));
    }

    /**
     * Geocode a customer's address and save to database
     * Stores in geocoded_latitude/longitude (separate from verified location)
     */
    public static function geocodeCustomer(int $customerId, bool $forceUpdate = false): bool
    {
        $customer = \DB::table('t_crm_prod_customer')->find($customerId);
        
        if (!$customer) {
            return false;
        }

        // Skip if already has geocoded coordinates (unless force update)
        // Note: We check geocoded_latitude, NOT latitude (which is for verified locations)
        if (!$forceUpdate && $customer->geocoded_latitude && $customer->geocoded_longitude) {
            return true; // Already has geocoded coordinates
        }

        // Get address
        $address = $customer->address1 ?? $customer->address ?? null;
        $city = $customer->city ?? null;

        if (empty($address)) {
            return false;
        }

        $coords = self::geocodeAddress($address, $city);
        
        if ($coords) {
            // Success - save coordinates
            \DB::table('t_crm_prod_customer')
                ->where('id', $customerId)
                ->update([
                    'geocoded_latitude' => $coords['latitude'],
                    'geocoded_longitude' => $coords['longitude'],
                    'geocoded_at' => now(),
                    'updated_at' => now(),
                ]);
            
            return true;
        }

        // Failed - mark as attempted (so we don't retry immediately)
        // geocoded_at is set but lat/long remain null
        \DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->whereNull('geocoded_at') // Only if not already attempted
            ->update([
                'geocoded_at' => now(),
                'updated_at' => now(),
            ]);

        return false;
    }

    /**
     * Batch geocode customers without geocoded coordinates
     * Returns count of successfully geocoded customers
     */
    public static function batchGeocodeCustomers(int $limit = 100): array
    {
        // Find customers without geocoded coordinates (not verified - those are separate)
        $customers = \DB::table('t_crm_prod_customer')
            ->whereNull('geocoded_latitude')
            ->whereNotNull('address1')
            ->where('address1', '!=', '')
            ->limit($limit)
            ->get(['id', 'address1', 'city']);

        $success = 0;
        $failed = 0;
        $processed = [];

        foreach ($customers as $customer) {
            $result = self::geocodeCustomer($customer->id);
            
            if ($result) {
                $success++;
            } else {
                $failed++;
            }
            
            $processed[] = [
                'id' => $customer->id,
                'address' => $customer->address1,
                'success' => $result,
            ];

            // Rate limit
            usleep(1100000); // 1.1 seconds between requests
        }

        return [
            'total' => count($customers),
            'success' => $success,
            'failed' => $failed,
            'processed' => $processed,
        ];
    }
    
    /**
     * Get the best available coordinates for a customer
     * Priority: 1) Verified location, 2) Geocoded location
     */
    public static function getCustomerCoordinates(int $customerId): ?array
    {
        $customer = \DB::table('t_crm_prod_customer')
            ->where('id', $customerId)
            ->select('latitude', 'longitude', 'geocoded_latitude', 'geocoded_longitude')
            ->first();
        
        if (!$customer) {
            return null;
        }
        
        // Priority 1: Verified location (manually set by rider)
        if ($customer->latitude && $customer->longitude) {
            return [
                'latitude' => (float) $customer->latitude,
                'longitude' => (float) $customer->longitude,
                'source' => 'verified_location',
            ];
        }
        
        // Priority 2: Geocoded location (auto-generated from address)
        if ($customer->geocoded_latitude && $customer->geocoded_longitude) {
            return [
                'latitude' => (float) $customer->geocoded_latitude,
                'longitude' => (float) $customer->geocoded_longitude,
                'source' => 'geocoded_address',
            ];
        }
        
        return null;
    }
}

