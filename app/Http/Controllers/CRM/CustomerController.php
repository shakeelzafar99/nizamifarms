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
            'is_active' => 'boolean'
        ]);
        
        try {
            $customer = CustomerModel::findOrFail($id);
            $customer->update($request->all());
            
            return redirect()->route('customers.index')->with('success', 'Customer updated successfully!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Error updating customer: ' . $e->getMessage());
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
