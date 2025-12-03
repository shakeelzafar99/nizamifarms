<?php

namespace App\Http\Controllers\CRM;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CRM\CustomerModel;
use App\Models\CRM\OrderModel;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = CustomerModel::query();
        
        // Search functionality
        if ($request->has('search') && $request->search) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('first_name', 'like', "%{$searchTerm}%")
                  ->orWhere('last_name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                  ->orWhere('company', 'like', "%{$searchTerm}%");
            });
        }
        
        // Filter by city
        if ($request->has('city') && $request->city) {
            $query->where('city', $request->city);
        }
        
        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('is_active', $request->status === 'active' ? 1 : 0);
        }
        
        // Order by last order date (most recent first)
        $customers = $query->orderBy('last_order_date', 'desc')
                          ->orderBy('created_at', 'desc')
                          ->paginate(15);
        
        // Get unique cities for filter dropdown
        $cities = CustomerModel::whereNotNull('city')
                              ->where('city', '!=', '')
                              ->distinct()
                              ->pluck('city')
                              ->sort()
                              ->values();
        
        // Get statistics
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);
        $ninetyDaysAgo = $now->copy()->subDays(90);
        
        $stats = [
            'total_customers' => CustomerModel::count(),
            'active_30_days' => CustomerModel::where('last_order_date', '>=', $thirtyDaysAgo)->count(),
            'active_90_days' => CustomerModel::where('last_order_date', '>=', $ninetyDaysAgo)->count()
        ];
        
        return view('pages.customers.index', compact('customers', 'cities', 'stats'));
    }
    
    public function show(Request $request, $id)
    {
        try {
            $customer = CustomerModel::with(['orders' => function($query) {
                $query->orderBy('order_date', 'desc')->limit(10);
            }])->findOrFail($id);
            
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
                    'saved_by' => $customer->verified_location_saved_by ? 
                        \DB::table('t_sys_user')->where('id', $customer->verified_location_saved_by)->value('fullname') : null,
                    'saved_at' => $customer->verified_location_saved_at,
                ];
            }
            
            // Always return JSON for now to maintain existing functionality
            // The existing viewCustomer function expects JSON response
            return response()->json([
                'success' => true,
                'customer' => $customer,
                'verified_location' => $verifiedLocation
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found'
            ], 404);
        }
    }
    
    public function search(Request $request)
    {
        try {
            $query = $request->get('q', '');
            $limit = $request->get('limit', 10);
            
            $customers = \App\Models\CRM\CustomerModel::query()
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
            $status = $request->get('status', '');
            
            // Start with base query
            $query = CustomerModel::query();
            
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
            
            // Apply status filter
            if (!empty($status)) {
                $query->where('is_active', $status === 'active' ? 1 : 0);
            }
            
            // Get results (limit to 100 for performance)
            $customers = $query->orderBy('last_order_date', 'desc')
                             ->orderBy('created_at', 'desc')
                             ->limit(100)
                             ->get();
            
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

    public function orders($id)
    {
        try {
            $customer = CustomerModel::findOrFail($id);
            
            // Get customer orders with line items count (only non-Shopify orders to match customer statistics)
            $orders = OrderModel::where('customer_id', $id)
                              ->where(function($query) {
                                  $query->where('external_source', '!=', 'shopify')
                                        ->orWhereNull('external_source');
                              })
                              ->withCount('lineItems')
                              ->orderBy('order_date', 'desc')
                              ->get();
            
            return response()->json([
                'success' => true, 
                'orders' => $orders->map(function($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'order_date' => $order->order_date,
                        'order_status' => $order->order_status,
                        'total_price' => $order->total_price,
                        'line_items_count' => $order->line_items_count,
                        'external_source' => $order->external_source,
                        'payment_method' => $order->payment_method,
                        'notes' => $order->notes
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => 'Error fetching customer orders: ' . $e->getMessage()
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
            $customer->update($request->all());
            
            // Handle both AJAX and regular form submissions
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Customer updated successfully!'
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
                // URL provided - store it
                $updateData['verified_location_url'] = $validated['url'];
                \Log::info('Setting verified location URL for customer (webapp)', [
                    'customer_id' => $id,
                    'url' => $validated['url'],
                    'saved_by' => auth()->user()->fullname,
                ]);
            }
            
            if (!empty($validated['latitude']) && !empty($validated['longitude'])) {
                // Coordinates provided - store them
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
}
