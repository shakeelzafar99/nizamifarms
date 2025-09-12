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
        $stats = [
            'total_customers' => CustomerModel::count(),
            'active_customers' => CustomerModel::where('is_active', 1)->count(),
            'total_orders' => CustomerModel::sum('total_orders'),
            'total_revenue' => CustomerModel::sum('total_spent')
        ];
        
        return view('pages.customers.index', compact('customers', 'cities', 'stats'));
    }
    
    public function show($id)
    {
        try {
            $customer = CustomerModel::with(['orders' => function($query) {
                $query->orderBy('order_date', 'desc')->limit(10);
            }])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'customer' => $customer
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
            
            // Get customer orders with line items count
            $orders = OrderModel::where('customer_id', $id)
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
}
