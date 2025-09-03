{{-- resources/views/auth/login.blade.php --}}

@extends('layouts.app')

@section('title', 'Orders')

@section('content')

@if(session('success'))

<div class="kt-container-fixed">
    <div class="kt-alert kt-alert-success mb-5" id="alert_1">

        <div class="kt-alert-title"><span>{{ session('success') }}</span></div>
        <div class="kt-alert-toolbar">
            <div class="kt-alert-actions">
                <button class="kt-alert-close" data-kt-dismiss="#alert_1">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="24"
                        height="24"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="lucide lucide-x"
                        aria-hidden="true">
                        <path d="M18 6 6 18"></path>
                        <path d="m6 6 12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>




@endif
<!-- Container -->
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card kt-card-grid min-w-full">
            <!-- Header with Tabs and Import Form -->
            <div class="kt-card-header">
                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center w-full gap-4">
                    <!-- Left: Tabs -->
                    <div class="flex items-center gap-4">
                        <h3 class="kt-card-title text-lg font-semibold mr-4">Orders</h3>
                        <div class="flex bg-gray-100 rounded-lg p-1">
                            <a href="{{ url('/orders') }}?source=other" 
                               class="px-4 py-2 rounded-md text-sm font-medium transition-colors {{ $source === 'other' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800' }}">
                                Invoices
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs {{ $source === 'other' ? 'bg-blue-100 text-blue-600' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $otherCount }}
                                </span>
                            </a>
                            <a href="{{ url('/orders') }}?source=shopify" 
                               class="px-4 py-2 rounded-md text-sm font-medium transition-colors {{ $source === 'shopify' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-800' }}">
                                Shopify
                                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs {{ $source === 'shopify' ? 'bg-blue-100 text-blue-600' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $shopifyCount }}
                                </span>
                            </a>
                        </div>
                    </div>

                    <!-- Right: Import Button -->
                    <button onclick="openImportModal()" class="kt-btn kt-btn-outline">
                        <i class="ki-filled ki-exit-down"></i> Import Orders
                    </button>
                </div>
            </div>

            <div class="kt-card-table">
                <div class="grid datatable-initialized" data-kt-datatable="true" data-kt-datatable-page-size="10" data-kt-datatable-initialized="true">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table text-xs kt-table-border" data-kt-datatable-table="true">
                            <thead class="bg-gray-100 font-bold">
                                <tr>
                                    <th class="w-[60px]">ID</th>
                                    <th class="w-[200px]">Contact Email</th>
                                    <th class="min-w-[130px]">Created At</th>
                                    <th class="w-[100px]">Currency</th>
                                    <th class="w-[150px]">Name</th>
                                    <th class="min-w-[90px]">Order #</th>
                                    <th class="w-[80px]">Items</th>
                                    <th class="min-w-[100px]">Subtotal</th>
                                    <th class="w-[140px]">Total</th>
                                    <th class="w-[120px]">Weight</th>
                                    <th class="w-[100px]">Source</th>
                                    <th class="w-[120px]">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                <tr class="hover:bg-gray-50">
                                    <td>{{ $order->id }}</td>
                                    <td>{{ $order->contact_email }}</td>
                                    <td>{{ $order->created_at ? $order->created_at->format('d-M-Y H:i') : '' }}</td>
                                    <td>{{ $order->currency }}</td>
                                    <td>{{ $order->name }}</td>
                                    <td>{{ $order->order_number }}</td>
                                    <td class="text-center">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                            {{ $order->OrderDetails ? count($order->OrderDetails) : 0 }}
                                        </span>
                                    </td>
                                    <td>{{ $order->subtotal_price }}</td>
                                    <td>{{ $order->total_price }}</td>
                                    <td>{{ $order->total_weight }}</td>
                                    <td>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs 
                                            {{ $order->source === 'shopify' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800' }}">
                                            {{ ucfirst($order->source ?: 'manual') }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="flex gap-2">
                                            <button onclick="viewOrderDetails({{ $order->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                                                <i class="ki-filled ki-eye text-sm"></i>
                                            </button>
                                            <button onclick="editOrderDetails({{ $order->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-primary" title="Edit Order">
                                                <i class="ki-filled ki-notepad-edit text-sm"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>

                    </div>
                    <div class="kt-card-footer justify-center md:justify-between flex-col md:flex-row gap-5 text-secondary-foreground text-sm font-medium">
                        <div class="flex items-center gap-4 order-1 md:order-2">
                            {{ $orders->links() }}
                        </div>

                        <!-- <div class="flex items-center gap-2 order-2 md:order-1">
                            Show
                            <select class="hidden" data-kt-datatable-size="true" data-kt-select="" name="perpage" data-kt-select-initialized="true">
                                <option value="5" data-kt-select-option-initialized="true">5</option>
                                <option value="10" data-kt-select-option-initialized="true">10</option>
                                <option value="20" data-kt-select-option-initialized="true">20</option>
                                <option value="30" data-kt-select-option-initialized="true">30</option>
                                <option value="50" data-kt-select-option-initialized="true">50</option>
                            </select>
                            <div data-kt-select-wrapper="" class="kt-select-wrapper w-16">
                                <div data-kt-select-display="" class="kt-select-display kt-select" tabindex="0" role="button" data-selected="0" aria-haspopup="listbox" aria-expanded="false" aria-label="Select an option">10</div>
                                <div data-kt-select-dropdown="" class="kt-select-dropdown hidden " style="z-index: 105;">
                                    <ul role="listbox" aria-label="Select an option" class="kt-select-options " data-kt-select-options="true">
                                        <li data-kt-select-option="" data-value="5" data-text="5" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">5</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="10" data-text="10" class="kt-select-option selected" role="option" aria-selected="true">
                                            <div class="kt-select-option-text" data-kt-text-container="true">10</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="20" data-text="20" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">20</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="30" data-text="30" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">30</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                        <li data-kt-select-option="" data-value="50" data-text="50" class="kt-select-option" role="option" aria-selected="false">
                                            <div class="kt-select-option-text" data-kt-text-container="true">50</div><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-3.5 ms-auto hidden text-primary kt-select-option-selected:block">
                                                <path d="M20 6 9 17l-5-5"></path>
                                            </svg>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            per page
                        </div>
                        <div class="flex items-center gap-4 order-1 md:order-2">
                            <span data-kt-datatable-info="true">1-10 of 30</span>
                            <div class="kt-datatable-pagination" data-kt-datatable-pagination="true"><button class="kt-datatable-pagination-button kt-datatable-pagination-prev disabled" disabled="">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M8.86501 16.7882V12.8481H21.1459C21.3724 12.8481 21.5897 12.7581 21.7498 12.5979C21.91 12.4378 22 12.2205 22 11.994C22 11.7675 21.91 11.5503 21.7498 11.3901C21.5897 11.2299 21.3724 11.1399 21.1459 11.1399H8.86501V7.2112C8.86628 7.10375 8.83517 6.9984 8.77573 6.90887C8.7163 6.81934 8.63129 6.74978 8.53177 6.70923C8.43225 6.66869 8.32283 6.65904 8.21775 6.68155C8.11267 6.70405 8.0168 6.75766 7.94262 6.83541L2.15981 11.6182C2.1092 11.668 2.06901 11.7274 2.04157 11.7929C2.01413 11.8584 2 11.9287 2 11.9997C2 12.0707 2.01413 12.141 2.04157 12.2065C2.06901 12.272 2.1092 12.3314 2.15981 12.3812L7.94262 17.164C8.0168 17.2417 8.11267 17.2953 8.21775 17.3178C8.32283 17.3403 8.43225 17.3307 8.53177 17.2902C8.63129 17.2496 8.7163 17.18 8.77573 17.0905C8.83517 17.001 8.86628 16.8956 8.86501 16.7882Z" fill="currentColor"></path>
                                    </svg>
                                </button><button class="kt-datatable-pagination-button active disabled" disabled="">1</button><button class="kt-datatable-pagination-button">2</button><button class="kt-datatable-pagination-button">3</button><button class="kt-datatable-pagination-button kt-datatable-pagination-next">
                                    <svg class="rtl:transform rtl:rotate-180 size-3.5 shrink-0" width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M15.135 7.21144V11.1516H2.85407C2.62756 11.1516 2.41032 11.2415 2.25015 11.4017C2.08998 11.5619 2 11.7791 2 12.0056C2 12.2321 2.08998 12.4494 2.25015 12.6096C2.41032 12.7697 2.62756 12.8597 2.85407 12.8597H15.135V16.7884C15.1337 16.8959 15.1648 17.0012 15.2243 17.0908C15.2837 17.1803 15.3687 17.2499 15.4682 17.2904C15.5677 17.3309 15.6772 17.3406 15.7822 17.3181C15.8873 17.2956 15.9832 17.242 16.0574 17.1642L21.8402 12.3814C21.8908 12.3316 21.931 12.2722 21.9584 12.2067C21.9859 12.1412 22 12.0709 22 11.9999C22 11.9289 21.9859 11.8586 21.9584 11.7931C21.931 11.7276 21.8908 11.6683 21.8402 11.6185L16.0574 6.83565C15.9832 6.75791 15.8873 6.70429 15.7822 6.68179C15.6772 6.65929 15.5677 6.66893 15.4682 6.70948C15.3687 6.75002 15.2837 6.81959 15.2243 6.90911C15.1648 6.99864 15.1337 7.10399 15.135 7.21144Z" fill="currentColor"></path>
                                    </svg>
                                </button></div>
                        </div>
                    </div> -->


                    </div>
                </div>
            </div>
        </div>
    </div>

</div>




</div>

<!-- View Order Modal -->
<div id="viewOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Invoice Details</h3>
            <button onclick="closeModal('viewOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div id="editOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Edit Invoice</h3>
            <button onclick="closeModal('editOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="editOrderContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Import Orders Modal -->
<div id="importOrderModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Import Historical Orders</h3>
            <button onclick="closeModal('importOrderModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 20px;">
            <form id="importOrderForm" action="{{ route('orders.importOrders') }}" method="POST">
                @csrf
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Select Source</label>
                    <select name="source" required style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                        <option value="">Choose a source...</option>
                        <option value="Shopify">Shopify</option>
                        <option value="WooCommerce">WooCommerce</option>
                    </select>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">From Date</label>
                        <input type="date" name="from_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">To Date</label>
                        <input type="date" name="to_date" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" required>
                    </div>
                </div>

                <div style="background-color: #f0f9ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 12px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: start;">
                        <div style="color: #3b82f6; margin-right: 8px;">
                            <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                            </svg>
                        </div>
                        <div>
                            <h4 style="font-size: 14px; font-weight: 500; color: #1e40af; margin: 0 0 4px 0;">Import Information</h4>
                            <p style="font-size: 12px; color: #1e40af; margin: 0;">This will fetch and import orders from the selected platform within the specified date range. Existing orders will be updated if found.</p>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('importOrderModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background-color: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                        </svg>
                        Import Orders
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>
<script>
// Modal functions
function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Format date helper
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Format currency helper
function formatCurrency(amount, currency = 'PKR') {
    return `${currency} ${parseFloat(amount).toFixed(2)}`;
}

// View Order Details
function viewOrderDetails(orderId) {
    console.log('View order details clicked for order:', orderId);
    const modal = document.getElementById('viewOrderModal');
    const content = document.getElementById('viewOrderContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch order details via AJAX
    fetch(`/orders/${orderId}`, {
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
            const order = data.order;
            console.log('Order data:', order);
            
            let html = `
                <div>
                    <!-- Invoice Header -->
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 16px; border-bottom: 1px solid #e5e7eb; margin-bottom: 20px;">
                        <div>
                            <h2 style="font-size: 24px; font-weight: bold; color: #111827; margin: 0;">Invoice #${order.order_number || order.id}</h2>
                            <p style="font-size: 14px; color: #6b7280; margin: 8px 0 0 0;">Date: ${formatDate(order.created_at)}</p>
                        </div>
                        <div style="text-align: right;">
                            <span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; ${order.source === 'shopify' ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fed7aa; color: #9a3412;'}">
                                ${(order.source || 'manual').toUpperCase()}
                            </span>
                            <p style="font-size: 24px; font-weight: bold; color: #2563eb; margin: 8px 0 0 0;">${formatCurrency(order.total_price, order.currency)}</p>
                        </div>
                    </div>

                    <!-- Customer & Address Info -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <h3 style="font-weight: 600; color: #111827; margin: 0 0 12px 0;">Bill To:</h3>
                            ${order.customer ? `
                            <div style="font-size: 14px;">
                                <p style="font-weight: 500; margin: 0 0 4px 0;">${order.customer.first_name || ''} ${order.customer.last_name || ''}</p>
                                <p style="color: #6b7280; margin: 0 0 4px 0;">${order.customer.email || ''}</p>
                                ${order.customer.phone ? `<p style="color: #6b7280; margin: 0;">${order.customer.phone}</p>` : ''}
                            </div>
                            ` : '<p style="color: #9ca3af; font-size: 14px; margin: 0;">No customer information</p>'}
                        </div>
                        
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <h3 style="font-weight: 600; color: #111827; margin: 0 0 12px 0;">Ship To:</h3>
                            ${order.order_address && order.order_address.length > 0 ? `
                            <div style="font-size: 14px;">
                                <p style="font-weight: 500; margin: 0 0 4px 0;">${order.order_address[0].first_name || ''} ${order.order_address[0].last_name || ''}</p>
                                <p style="margin: 0 0 4px 0;">${order.order_address[0].address1 || ''}</p>
                                ${order.order_address[0].address2 ? `<p style="margin: 0 0 4px 0;">${order.order_address[0].address2}</p>` : ''}
                                <p style="margin: 0 0 4px 0;">${order.order_address[0].city || ''}, ${order.order_address[0].zip || ''}</p>
                                <p style="margin: 0 0 4px 0;">${order.order_address[0].country || ''}</p>
                                ${order.order_address[0].phone ? `<p style="color: #6b7280; margin: 0;">${order.order_address[0].phone}</p>` : ''}
                            </div>
                            ` : '<p style="color: #9ca3af; font-size: 14px; margin: 0;">No shipping address</p>'}
                        </div>
                    </div>

                    <!-- Line Items -->
                    <div>
                        <h3 style="font-weight: 600; color: #111827; margin: 0 0 12px 0;">Items (${order.order_details ? order.order_details.length : 0})</h3>
                        ${order.order_details && order.order_details.length > 0 ? `
                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead style="background-color: #f9fafb;">
                                    <tr>
                                        <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">Item</th>
                                        <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">Qty</th>
                                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">Unit Price</th>
                                        <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${order.order_details.map((item, index) => `
                                    <tr style="border-top: 1px solid #e5e7eb;">
                                        <td style="padding: 16px;">
                                            <div style="font-weight: 500; color: #111827;">${item.name || 'N/A'}</div>
                                            ${item.sku ? `<div style="font-size: 12px; color: #6b7280; margin-top: 4px;">SKU: ${item.sku}</div>` : ''}
                                            ${item.vendor ? `<div style="font-size: 12px; color: #6b7280;">Vendor: ${item.vendor}</div>` : ''}
                                        </td>
                                        <td style="padding: 16px; text-align: center;">
                                            <span style="font-size: 14px; font-weight: 500;">${item.quantity || 0}</span>
                                        </td>
                                        <td style="padding: 16px; text-align: right; font-size: 14px;">${formatCurrency(item.price || 0, order.currency)}</td>
                                        <td style="padding: 16px; text-align: right; font-weight: 500;">${formatCurrency((item.price || 0) * (item.quantity || 0), order.currency)}</td>
                                    </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Totals -->
                        <div style="margin-top: 16px; background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div>
                                <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;">
                                    <span>Subtotal:</span>
                                    <span>${formatCurrency(order.subtotal_price || 0, order.currency)}</span>
                                </div>
                                ${order.total_tax > 0 ? `
                                <div style="display: flex; justify-content: space-between; font-size: 14px; margin-bottom: 8px;">
                                    <span>Tax:</span>
                                    <span>${formatCurrency(order.total_tax || 0, order.currency)}</span>
                                </div>
                                ` : ''}
                                <div style="display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; border-top: 1px solid #d1d5db; padding-top: 8px;">
                                    <span>Total:</span>
                                    <span style="color: #2563eb;">${formatCurrency(order.total_price, order.currency)}</span>
                                </div>
                            </div>
                        </div>
                        ` : '<div style="text-align: center; padding: 32px; color: #6b7280;">No items found</div>'}
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = `
                <div class="text-center py-8">
                    <div class="text-red-600 mb-4">
                        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Error Loading Order</h3>
                    <p class="text-gray-500">${data.message || 'Unable to load order details'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching order details:', error);
        content.innerHTML = `
            <div class="text-center py-8">
                <div class="text-red-600 mb-4">
                    <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Network Error</h3>
                <p class="text-gray-500">Unable to connect to server. Please try again.</p>
            </div>
        `;
    });
}

// Edit Order Details
function editOrderDetails(orderId) {
    console.log('Edit order details clicked for order:', orderId);
    const modal = document.getElementById('editOrderModal');
    const content = document.getElementById('editOrderContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch order details for editing
    fetch(`/orders/${orderId}`, {
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
            const order = data.order;
            loadEditForm(order);
        } else {
            showEditError('Error loading order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error fetching order for editing:', error);
        showEditError('Network error. Please try again.');
    });
}

function loadEditForm(order) {
    const content = document.getElementById('editOrderContent');
    content.innerHTML = `
        <form id="editOrderForm" style="padding: 0;">
            <input type="hidden" name="order_id" value="${order.id}">
            
            <!-- Order Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Information</h4>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Order Number</label>
                            <input type="text" name="order_number" value="${order.order_number || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Contact Email</label>
                            <input type="email" name="contact_email" value="${order.contact_email || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Customer Name</label>
                            <input type="text" name="customer_name" value="${order.name || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                    </div>
                </div>

                <!-- Customer Information -->
                <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Customer Details</h4>
                    <div style="display: flex; flex-direction: column; gap: 16px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">First Name</label>
                                <input type="text" name="customer_first_name" value="${order.customer ? order.customer.first_name || '' : ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Last Name</label>
                                <input type="text" name="customer_last_name" value="${order.customer ? order.customer.last_name || '' : ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Email</label>
                            <input type="email" name="customer_email" value="${order.customer ? order.customer.email || '' : ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Phone</label>
                            <input type="text" name="customer_phone" value="${order.customer ? order.customer.phone || '' : ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Line Items Section -->
            <div style="background-color: #fefefe; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 20px;">
                <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0;">Line Items</h4>
                    <button type="button" onclick="addLineItem()" style="background-color: #10b981; color: white; padding: 8px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer;">
                        + Add Item
                    </button>
                </div>
                <div id="lineItemsContainer" style="padding: 16px;">
                    ${order.order_details && order.order_details.length > 0 ? 
                        order.order_details.map((item, index) => `
                        <div class="line-item" data-index="${index}" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
                                <input type="text" name="items[${index}][name]" value="${item.name || ''}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
                                <input type="hidden" name="items[${index}][id]" value="${item.id || ''}">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
                                <input type="number" name="items[${index}][quantity]" value="${item.quantity || 1}" min="1"
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
                                <input type="number" step="0.01" name="items[${index}][price]" value="${item.price || 0}" 
                                       style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${index})">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
                                <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">${formatCurrency((item.price || 0) * (item.quantity || 0), order.currency)}</span>
                            </div>
                            <div>
                                <button type="button" onclick="removeLineItem(${index})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    ×
                                </button>
                            </div>
                        </div>
                        `).join('') : 
                        '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>'
                    }
                </div>
            </div>

            <!-- Order Totals -->
            <div style="background-color: #eff6ff; padding: 16px; border-radius: 8px; margin-bottom: 20px;">
                <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Order Totals</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Subtotal</label>
                        <input type="number" step="0.01" name="subtotal_price" value="${order.subtotal_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" readonly>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Total Tax</label>
                        <input type="number" step="0.01" name="total_tax" value="${order.total_tax || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="updateOrderTotal()">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Total Price</label>
                        <input type="number" step="0.01" name="total_price" value="${order.total_price || 0}" 
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-weight: 600;" readonly>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                <button type="button" onclick="closeModal('editOrderModal')" 
                        style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                    Cancel
                </button>
                <button type="submit" 
                        style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Save Changes
                </button>
            </div>
        </form>
    `;
    
    // Add form submission handler
    document.getElementById('editOrderForm').addEventListener('submit', function(e) {
        e.preventDefault();
        saveOrderChanges(order.id);
    });
}

function showEditError(message) {
    const content = document.getElementById('editOrderContent');
    content.innerHTML = `
        <div class="text-center py-8">
            <div class="text-red-600 mb-4">
                <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Error</h3>
            <p class="text-gray-500">${message}</p>
        </div>
    `;
}

// Line item management functions
let lineItemIndex = 1000; // Start high to avoid conflicts with existing items

function addLineItem() {
    const container = document.getElementById('lineItemsContainer');
    const emptyMessage = container.querySelector('div[style*="text-align: center"]');
    if (emptyMessage) {
        emptyMessage.remove();
    }
    
    const newItem = document.createElement('div');
    newItem.className = 'line-item';
    newItem.setAttribute('data-index', lineItemIndex);
    newItem.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 12px; align-items: end; padding: 12px; margin-bottom: 12px; border: 1px solid #e5e7eb; border-radius: 6px; background-color: #f9fafb;';
    
    newItem.innerHTML = `
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Item Name</label>
            <input type="text" name="items[${lineItemIndex}][name]" value="" 
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;">
            <input type="hidden" name="items[${lineItemIndex}][id]" value="">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Quantity</label>
            <input type="number" name="items[${lineItemIndex}][quantity]" value="1" min="1"
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${lineItemIndex})">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Unit Price</label>
            <input type="number" step="0.01" name="items[${lineItemIndex}][price]" value="0" 
                   style="width: 100%; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" onchange="updateLineTotal(${lineItemIndex})">
        </div>
        <div>
            <label style="display: block; font-size: 12px; font-weight: 500; color: #6b7280; margin-bottom: 4px;">Total</label>
            <span class="line-total" style="display: block; padding: 6px 8px; background-color: #e5e7eb; border-radius: 4px; font-size: 14px; font-weight: 500;">PKR 0.00</span>
        </div>
        <div>
            <button type="button" onclick="removeLineItem(${lineItemIndex})" style="background-color: #ef4444; color: white; padding: 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                ×
            </button>
        </div>
    `;
    
    container.appendChild(newItem);
    lineItemIndex++;
    updateSubtotal();
}

function removeLineItem(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (item) {
        item.remove();
        updateSubtotal();
        
        // Check if no items left
        const container = document.getElementById('lineItemsContainer');
        const items = container.querySelectorAll('.line-item');
        if (items.length === 0) {
            container.innerHTML = '<div style="text-align: center; color: #6b7280; padding: 20px;">No line items. Click "Add Item" to add items.</div>';
        }
    }
}

function updateLineTotal(index) {
    const item = document.querySelector(`.line-item[data-index="${index}"]`);
    if (item) {
        const quantity = parseFloat(item.querySelector(`input[name="items[${index}][quantity]"]`).value) || 0;
        const price = parseFloat(item.querySelector(`input[name="items[${index}][price]"]`).value) || 0;
        const total = quantity * price;
        
        const totalSpan = item.querySelector('.line-total');
        totalSpan.textContent = formatCurrency(total, 'PKR');
        
        updateSubtotal();
    }
}

function updateSubtotal() {
    let subtotal = 0;
    const items = document.querySelectorAll('.line-item');
    
    items.forEach(item => {
        const index = item.getAttribute('data-index');
        const quantity = parseFloat(item.querySelector(`input[name*="[quantity]"]`).value) || 0;
        const price = parseFloat(item.querySelector(`input[name*="[price]"]`).value) || 0;
        subtotal += quantity * price;
    });
    
    const subtotalInput = document.querySelector('input[name="subtotal_price"]');
    if (subtotalInput) {
        subtotalInput.value = subtotal.toFixed(2);
        updateOrderTotal();
    }
}

function updateOrderTotal() {
    const subtotal = parseFloat(document.querySelector('input[name="subtotal_price"]').value) || 0;
    const tax = parseFloat(document.querySelector('input[name="total_tax"]').value) || 0;
    const total = subtotal + tax;
    
    const totalInput = document.querySelector('input[name="total_price"]');
    if (totalInput) {
        totalInput.value = total.toFixed(2);
    }
}

function saveOrderChanges(orderId) {
    const form = document.getElementById('editOrderForm');
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;
    
    const data = {};
    formData.forEach((value, key) => data[key] = value);
    
    // For now, just show success message - you can implement actual save later
    setTimeout(() => {
        alert('Order updated successfully! (Demo - actual save not implemented yet)');
        closeModal('editOrderModal');
        submitBtn.textContent = 'Save Changes';
        submitBtn.disabled = false;
    }, 1000);
}

// Import modal functions
function openImportModal() {
    const modal = document.getElementById('importOrderModal');
    modal.style.display = 'block';
    
    // Set default dates (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);
    
    document.querySelector('input[name="to_date"]').value = today.toISOString().split('T')[0];
    document.querySelector('input[name="from_date"]').value = thirtyDaysAgo.toISOString().split('T')[0];
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('viewOrderModal');
        closeModal('editOrderModal');
        closeModal('importOrderModal');
    }
});

// Close modals when clicking outside - this is now handled by onclick in the backdrop divs

// Debug: Log when script loads
console.log('Order management script loaded');
</script>
@endpush