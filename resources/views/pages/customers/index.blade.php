@extends('layouts.app')

@section('title', 'Customers')

@section('content')
<div class="container-fixed">
    <div class="flex flex-wrap items-center lg:items-end justify-between gap-5 pb-7.5">
        <div class="flex flex-col justify-center gap-2">
            <h1 class="text-xl font-semibold leading-none text-foreground">Customers</h1>
            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                Manage your customer database and relationships
            </div>
        </div>
        
        <div class="flex items-center gap-2.5">
            <div class="flex items-center gap-4 text-sm">
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    <span>{{ $stats['active_customers'] }} Active</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                    <span>{{ $stats['total_customers'] }} Total</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
            <div class="card card-grid">
                <div class="card-body p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Total Customers</p>
                            <p class="text-2xl font-bold text-foreground">{{ number_format($stats['total_customers']) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="ki-filled ki-people text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card card-grid">
                <div class="card-body p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Active Customers</p>
                            <p class="text-2xl font-bold text-green-600">{{ number_format($stats['active_customers']) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="ki-filled ki-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card card-grid">
                <div class="card-body p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Total Orders</p>
                            <p class="text-2xl font-bold text-purple-600">{{ number_format($stats['total_orders']) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center">
                            <i class="ki-filled ki-shopping-cart text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card card-grid">
                <div class="card-body p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-muted-foreground">Total Revenue</p>
                            <p class="text-2xl font-bold text-orange-600">PKR {{ number_format($stats['total_revenue'], 0) }}</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                            <i class="ki-filled ki-dollar text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customers Table -->
        <div class="card card-grid min-w-full">
            <div class="card-header flex-wrap gap-2">
                <h3 class="card-title font-medium text-sm">All Customers</h3>
                
                <div class="flex flex-wrap gap-2 lg:gap-5">
                    <!-- Search -->
                    <form method="GET" class="flex items-center gap-2">
                        <input type="text" name="search" value="{{ request('search') }}" 
                               placeholder="Search customers..." 
                               class="input input-sm w-48">
                        
                        <!-- City Filter -->
                        <select name="city" class="select select-sm">
                            <option value="">All Cities</option>
                            @foreach($cities as $city)
                                <option value="{{ $city }}" {{ request('city') == $city ? 'selected' : '' }}>
                                    {{ $city }}
                                </option>
                            @endforeach
                        </select>
                        
                        <!-- Status Filter -->
                        <select name="status" class="select select-sm">
                            <option value="">All Status</option>
                            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                            <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
                        </select>
                        
                        <button type="submit" class="kt-btn kt-btn-sm kt-btn-light">Filter</button>
                        @if(request()->hasAny(['search', 'city', 'status']))
                            <a href="{{ route('customers.index') }}" class="kt-btn kt-btn-sm kt-btn-light">Clear</a>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card-body">
                <div class="scrollable-x-auto">
                    <table class="table table-auto table-border" data-datatable="true">
                        <thead>
                            <tr>
                                <th class="w-[60px]">#</th>
                                <th class="min-w-[200px]">Customer</th>
                                <th class="w-[150px]">Contact</th>
                                <th class="w-[150px]">Location</th>
                                <th class="w-[100px]">Orders</th>
                                <th class="w-[120px]">Total Spent</th>
                                <th class="w-[100px]">Status</th>
                                <th class="w-[100px]">Last Order</th>
                                <th class="w-[120px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($customers as $customer)
                            <tr class="hover:bg-gray-50">
                                <td class="text-center">
                                    <span class="text-sm text-gray-500">#{{ $customer->id }}</span>
                                </td>
                                <td>
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-900">
                                            {{ $customer->first_name }} {{ $customer->last_name }}
                                        </span>
                                        @if($customer->company)
                                            <span class="text-xs text-gray-500">{{ $customer->company }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-col text-sm">
                                        @if($customer->email)
                                            <span class="text-gray-600">{{ $customer->email }}</span>
                                        @endif
                                        @if($customer->phone)
                                            <span class="text-gray-500">{{ $customer->phone }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    <div class="flex flex-col text-sm">
                                        @if($customer->city)
                                            <span class="text-gray-600">{{ $customer->city }}</span>
                                        @endif
                                        @if($customer->province)
                                            <span class="text-gray-500">{{ $customer->province }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                        {{ $customer->total_orders }}
                                    </span>
                                </td>
                                <td class="text-right">
                                    <span class="font-medium text-gray-900">
                                        PKR {{ number_format($customer->total_spent, 0) }}
                                    </span>
                                </td>
                                <td>
                                    @if($customer->is_active)
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 text-green-800">
                                            Active
                                        </span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-red-100 text-red-800">
                                            Inactive
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    @if($customer->last_order_date)
                                        <span class="text-sm text-gray-600">
                                            {{ $customer->last_order_date->format('M d, Y') }}
                                        </span>
                                    @else
                                        <span class="text-sm text-gray-400">Never</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <button onclick="viewCustomer({{ $customer->id }})" 
                                                class="kt-btn kt-btn-sm kt-btn-light" 
                                                title="View Details">
                                            <i class="ki-filled ki-eye"></i>
                                        </button>
                                        <button onclick="editCustomer({{ $customer->id }})" 
                                                class="kt-btn kt-btn-sm kt-btn-light" 
                                                title="Edit">
                                            <i class="ki-filled ki-pencil"></i>
                                        </button>
                                        @if($customer->total_orders == 0)
                                            <button onclick="deleteCustomer({{ $customer->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-light text-red-600" 
                                                    title="Delete">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div class="mt-6">
                    {{ $customers->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Customer Modal -->
<div id="viewCustomerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Customer Details</h3>
                <button onclick="closeModal('viewCustomerModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="viewCustomerContent" style="padding: 24px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div id="editCustomerModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Customer</h3>
                <button onclick="closeModal('editCustomerModal')" 
                        style="background: none; border: none; font-size: 24px; color: #6b7280; cursor: pointer;">&times;</button>
            </div>
        </div>
        <div id="editCustomerContent" style="padding: 24px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<script>
function viewCustomer(customerId) {
    const modal = document.getElementById('viewCustomerModal');
    const content = document.getElementById('viewCustomerContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch customer details
    fetch(`/customers/${customerId}`, {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const customer = data.customer;
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Personal Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Full Name</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${customer.first_name} ${customer.last_name}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Email</label>
                                <p style="margin: 4px 0 0 0;">${customer.email || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Phone</label>
                                <p style="margin: 4px 0 0 0;">${customer.phone || 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Company</label>
                                <p style="margin: 4px 0 0 0;">${customer.company || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Address Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Address</label>
                                <p style="margin: 4px 0 0 0;">${customer.address1 || 'N/A'}</p>
                                ${customer.address2 ? `<p style="margin: 4px 0 0 0;">${customer.address2}</p>` : ''}
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">City</label>
                                <p style="margin: 4px 0 0 0;">${customer.city || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Province</label>
                                <p style="margin: 4px 0 0 0;">${customer.province || 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Country</label>
                                <p style="margin: 4px 0 0 0;">${customer.country || 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div style="margin-top: 24px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Statistics</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;">
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Total Orders</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #2563eb;">${customer.total_orders}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Total Spent</label>
                                <p style="margin: 4px 0 0 0; font-size: 18px; font-weight: 600; color: #059669;">PKR ${customer.total_spent ? customer.total_spent.toLocaleString() : '0'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">First Order</label>
                                <p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 500;">${customer.first_order_date ? new Date(customer.first_order_date).toLocaleDateString() : 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Last Order</label>
                                <p style="margin: 4px 0 0 0; font-size: 14px; font-weight: 500;">${customer.last_order_date ? new Date(customer.last_order_date).toLocaleDateString() : 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Error loading customer details</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        content.innerHTML = '<div style="text-align: center; padding: 40px; color: #ef4444;">Network error occurred</div>';
    });
}

function editCustomer(customerId) {
    // Implement edit functionality
    alert('Edit customer functionality - to be implemented');
}

function deleteCustomer(customerId) {
    if (confirm('Are you sure you want to delete this customer? This action cannot be undone.')) {
        // Implement delete functionality
        alert('Delete customer functionality - to be implemented');
    }
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}
</script>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
@endsection
