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

                    <!-- Right: Import Form -->
                    <form action="{{ route('orders.importOrders') }}" method="POST" class="flex gap-2 items-center">
                        @csrf
                        <select class="kt-select" name="source" required>
                            <option value="">Source</option>
                            <option value="Shopify">Shopify</option>
                            <option value="WooCommerce">WooCommerce</option>
                        </select>
                        <span class="text-xs text-muted">From</span>
                        <div class="kt-input">
                            <input type="date" name="from_date" class="kt-input" placeholder="Date From" />
                        </div>
                        <span class="text-xs text-muted">To</span>
                        <div class="kt-input">
                            <input type="date" name="to_date" class="kt-input" placeholder="Date From" />
                        </div>
                        <button class="kt-btn kt-btn-outline" type="submit">
                            <i class="ki-filled ki-exit-down"></i> Import Order
                        </button>
                    </form>
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
<div id="viewOrderModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-4 mx-auto p-0 border w-11/12 max-w-6xl shadow-lg rounded-lg bg-white max-h-[95vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex justify-between items-center p-6 border-b bg-gray-50 rounded-t-lg">
            <h3 class="text-xl font-semibold text-gray-900">Order Details</h3>
            <button onclick="closeModal('viewOrderModal')" class="text-gray-400 hover:text-gray-600 p-1">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewOrderContent" class="flex-1 overflow-y-auto p-6">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Edit Order Modal -->
<div id="editOrderModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-5xl shadow-lg rounded-md bg-white">
        <!-- Modal Header -->
        <div class="flex justify-between items-center pb-4 border-b">
            <h3 class="text-lg font-semibold text-gray-900">Edit Order</h3>
            <button onclick="closeModal('editOrderModal')" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        
        <!-- Modal Body -->
        <div id="editOrderContent" class="mt-4">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

@endsection

@push('demo1_js')
<script>
// Modal functions
function closeModal(modalId) {
    document.getElementById(modalId).classList.add('hidden');
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
    content.innerHTML = '<div class="flex justify-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>';
    modal.classList.remove('hidden');
    
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
                <!-- Main Layout: Side-by-side design -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 h-full">
                    
                    <!-- Left Column: Order Info & Customer -->
                    <div class="lg:col-span-1 space-y-4">
                        <!-- Order Summary Card -->
                        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-semibold text-gray-800">Order #${order.order_number || order.id}</h4>
                                <span class="px-2 py-1 text-xs font-medium rounded-full ${order.source === 'shopify' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-800'}">
                                    ${(order.source || 'manual').toUpperCase()}
                                </span>
                            </div>
                            <div class="space-y-2 text-sm">
                                <div class="flex justify-between"><span class="text-gray-600">Date:</span><span class="font-medium">${formatDate(order.created_at)}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600">Currency:</span><span class="font-medium">${order.currency || 'PKR'}</span></div>
                                <hr class="my-2 border-blue-200">
                                <div class="flex justify-between"><span class="text-gray-600">Subtotal:</span><span class="font-medium">${formatCurrency(order.subtotal_price, order.currency)}</span></div>
                                <div class="flex justify-between text-base"><span class="font-semibold text-gray-800">Total:</span><span class="font-bold text-blue-600">${formatCurrency(order.total_price, order.currency)}</span></div>
                            </div>
                        </div>

                        <!-- Customer Info Card -->
                        ${order.customer ? `
                        <div class="bg-gray-50 border rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-3 flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                Customer
                            </h4>
                            <div class="space-y-2 text-sm">
                                <div><span class="text-gray-600">Name:</span> <span class="font-medium">${order.customer.first_name || ''} ${order.customer.last_name || ''}</span></div>
                                <div><span class="text-gray-600">Email:</span> <span class="font-medium">${order.customer.email || 'N/A'}</span></div>
                                ${order.customer.phone ? `<div><span class="text-gray-600">Phone:</span> <span class="font-medium">${order.customer.phone}</span></div>` : ''}
                            </div>
                        </div>
                        ` : '<div class="bg-gray-50 border rounded-lg p-4"><p class="text-gray-500">No customer information</p></div>'}

                        <!-- Shipping Address Card -->
                        ${order.order_address && order.order_address.length > 0 ? `
                        <div class="bg-gray-50 border rounded-lg p-4">
                            <h4 class="font-semibold text-gray-800 mb-3 flex items-center">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                Shipping Address
                            </h4>
                            <div class="text-sm space-y-1 text-gray-700">
                                <div class="font-medium">${order.order_address[0].first_name || ''} ${order.order_address[0].last_name || ''}</div>
                                <div>${order.order_address[0].address1 || ''}</div>
                                ${order.order_address[0].address2 ? `<div>${order.order_address[0].address2}</div>` : ''}
                                <div>${order.order_address[0].city || ''}, ${order.order_address[0].zip || ''}</div>
                                <div class="font-medium">${order.order_address[0].country || ''}</div>
                                ${order.order_address[0].phone ? `<div class="pt-1"><span class="text-gray-600">Phone:</span> ${order.order_address[0].phone}</div>` : ''}
                            </div>
                        </div>
                        ` : ''}
                    </div>

                    <!-- Right Column: Line Items -->
                    <div class="lg:col-span-2">
                        ${order.order_details && order.order_details.length > 0 ? `
                        <div class="bg-white border rounded-lg overflow-hidden h-full flex flex-col">
                            <div class="bg-gray-50 px-4 py-3 border-b">
                                <h4 class="font-semibold text-gray-800 flex items-center">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                    </svg>
                                    Line Items (${order.order_details.length} items)
                                </h4>
                            </div>
                            <div class="flex-1 overflow-y-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-100 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-medium text-gray-700">Product</th>
                                            <th class="px-4 py-3 text-center font-medium text-gray-700">Qty</th>
                                            <th class="px-4 py-3 text-right font-medium text-gray-700">Unit Price</th>
                                            <th class="px-4 py-3 text-right font-medium text-gray-700">Total</th>
                                            <th class="px-4 py-3 text-left font-medium text-gray-700">Vendor</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${order.order_details.map((item, index) => `
                                        <tr class="border-t hover:bg-gray-50">
                                            <td class="px-4 py-3">
                                                <div>
                                                    <div class="font-medium text-gray-900">${item.name || 'N/A'}</div>
                                                    ${item.sku ? `<div class="text-xs text-gray-500 font-mono mt-1">SKU: ${item.sku}</div>` : ''}
                                                </div>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    ${item.quantity || 0}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-right font-medium">${formatCurrency(item.price || 0, order.currency)}</td>
                                            <td class="px-4 py-3 text-right font-semibold text-gray-900">${formatCurrency((item.price || 0) * (item.quantity || 0), order.currency)}</td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-700">
                                                    ${item.vendor || 'N/A'}
                                                </span>
                                            </td>
                                        </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        ` : '<div class="flex items-center justify-center h-64 bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg"><p class="text-gray-500">No line items found for this order</p></div>'}
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
    content.innerHTML = '<div class="flex justify-center py-8"><div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div></div>';
    modal.classList.remove('hidden');
    
    // For now, show a placeholder form - you can implement full editing later
    setTimeout(() => {
        content.innerHTML = `
            <div class="text-center py-8">
                <div class="mb-4">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Edit Order #${orderId}</h3>
                <p class="text-gray-500 mb-6">Order editing functionality will be implemented here.</p>
                <div class="flex justify-center gap-4">
                    <button onclick="closeModal('editOrderModal')" class="kt-btn kt-btn-light">Cancel</button>
                    <button class="kt-btn kt-btn-primary">Save Changes</button>
                </div>
            </div>
        `;
    }, 500);
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('viewOrderModal');
        closeModal('editOrderModal');
    }
});

// Close modals when clicking outside
window.addEventListener('click', function(e) {
    if (e.target.classList.contains('fixed')) {
        closeModal('viewOrderModal');
        closeModal('editOrderModal');
    }
});

// Debug: Log when script loads
console.log('Order management script loaded');
</script>
@endpush