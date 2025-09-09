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


                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        <button onclick="openColumnSettings()" class="kt-btn kt-btn-light">
                            <i class="ki-filled ki-setting-2"></i> Columns
                        </button>
                        <button onclick="openImportModal()" class="kt-btn kt-btn-outline">
                            <i class="ki-filled ki-exit-down"></i> Import Orders
                        </button>
                    </div>
                </div>
                
                <!-- Search and Filters Section -->
                <div class="mt-6 p-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                        <!-- Search Input -->
                        <div class="relative flex-1 max-w-md">
                            <input type="text" 
                                   id="orderSearch" 
                                   placeholder="Search by customer name, order number..." 
                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white shadow-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="ki-filled ki-magnifier text-gray-400"></i>
                            </div>
                        </div>
                        
                        <!-- Filters and Results -->
                        <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                            <!-- Filters -->
                            <div class="flex gap-2">
                                <select id="statusFilter" class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white shadow-sm">
                                    <option value="">All Status</option>
                                    <option value="on-hold">On Hold</option>
                                    <option value="completed">Completed</option>
                                    <option value="processing">Processing</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                                
                                <input type="date" 
                                       id="dateFilter" 
                                       class="px-3 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white shadow-sm">
                                
                                <button onclick="clearFilters()" class="px-3 py-2.5 text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg text-sm border border-gray-300 shadow-sm transition-colors">
                                    <i class="ki-filled ki-cross"></i> Clear
                                </button>
                            </div>
                            
                            <!-- Results Count -->
                            <div class="text-sm text-gray-600 whitespace-nowrap">
                                <span id="results-count">Showing {{ $orders->total() }} orders</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-card-table">
                <div class="grid datatable-initialized" data-kt-datatable="true" data-kt-datatable-page-size="10" data-kt-datatable-initialized="true">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table text-sm kt-table-border" data-kt-datatable-table="true">
                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100 font-semibold">
                                <tr id="table-header" class="border-b border-gray-200">
                                    <!-- Dynamic headers will be generated by JavaScript -->
                                </tr>
                            </thead>
                            <tbody id="table-body" class="divide-y divide-gray-100">
                                <!-- Dynamic rows will be generated by JavaScript -->
                            </tbody>
                            <tbody id="loading-state" class="hidden">
                                <tr>
                                    <td colspan="100%" class="text-center py-8">
                                        <div class="flex items-center justify-center">
                                            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-600 mr-3"></div>
                                            <span class="text-gray-600">Filtering orders...</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <tbody id="no-results-state" class="hidden">
                                <tr>
                                    <td colspan="100%" class="text-center py-12">
                                        <div class="flex flex-col items-center">
                                            <div class="text-6xl text-gray-300 mb-4">🔍</div>
                                            <h3 class="text-lg font-medium text-gray-900 mb-2">No orders found</h3>
                                            <p class="text-gray-500 mb-4">Try adjusting your search criteria or filters</p>
                                            <button onclick="clearFilters()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                                Clear Filters
                                            </button>
                                        </div>
                                    </td>
                                </tr>
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

<!-- Column Settings Modal -->
<div id="columnSettingsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999; overflow-y: auto;">
    <div style="display: flex; min-height: 100%; align-items: center; justify-content: center; padding: 20px;">
        <div style="background: white; border-radius: 8px; width: 100%; max-width: 600px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
            <!-- Modal Header (Fixed) -->
            <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <h3 style="font-size: 18px; font-weight: 600; margin: 0; color: #111827;">Customize Table Columns</h3>
                <button onclick="closeModal('columnSettingsModal')" style="padding: 4px; border: none; background: none; cursor: pointer; color: #6b7280;">
                    <svg style="width: 20px; height: 20px;" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
            </div>
            
            <!-- Modal Content (Scrollable) -->
            <div style="padding: 20px; overflow-y: auto; flex: 1;">
                <p style="color: #6b7280; margin-bottom: 20px; font-size: 14px;">
                    Drag and drop to reorder columns. Toggle visibility using checkboxes.
                </p>
                
                <div id="columnList" style="background: #f9fafb; border-radius: 8px; padding: 16px; max-height: 400px; overflow-y: auto;">
                    <!-- Column items will be generated here -->
                </div>
            </div>
            
            <!-- Modal Footer (Fixed) -->
            <div style="padding: 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-shrink: 0;">
                <button onclick="resetColumns()" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                    Reset to Default
                </button>
                <div style="display: flex; gap: 12px;">
                    <button onclick="closeModal('columnSettingsModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button onclick="applyColumnSettings()" style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Apply Changes
                    </button>
                </div>
            </div>
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
    try {
        // Handle ISO format: "2025-09-09T17:41:03.000000Z"
        let cleanDate = dateString;
        
        if (dateString.includes('T')) {
            // Extract date and time parts
            const [datePart, timePart] = dateString.split('T');
            const [year, month, day] = datePart.split('-');
            const timeOnly = timePart.split('.')[0]; // Remove microseconds
            const [hour, minute] = timeOnly.split(':');
            
            // Format as: "09 Sep 2025, 17:41"
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthName = monthNames[parseInt(month) - 1];
            
            cleanDate = `${day} ${monthName} ${year}, ${hour}:${minute}`;
        } else if (dateString.includes(' ')) {
            // Handle format: "2025-09-09 17:41:03"
            const [datePart, timePart] = dateString.split(' ');
            const [year, month, day] = datePart.split('-');
            const [hour, minute] = timePart.split(':');
            
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                              'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const monthName = monthNames[parseInt(month) - 1];
            
            cleanDate = `${day} ${monthName} ${year}, ${hour}:${minute}`;
        }
        
        return cleanDate;
    } catch (error) {
        console.error('Date parsing error:', error, 'for date:', dateString);
        return dateString;
    }
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
                            <span style="display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 500; ${order.external_source === 'shopify' ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fed7aa; color: #9a3412;'}">
                                ${(order.external_source || 'manual').toUpperCase()}
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
                            <h3 style="font-weight: 600; color: #111827; margin: 0 0 12px 0;">Address:</h3>
                            ${order.address_first_name || order.address_line1 ? `
                            <div style="font-size: 14px;">
                                <p style="font-weight: 500; margin: 0 0 4px 0;">${order.address_first_name || ''} ${order.address_last_name || ''}</p>
                                <p style="margin: 0 0 4px 0;">${order.address_line1 || ''}</p>
                                ${order.address_line2 ? `<p style="margin: 0 0 4px 0;">${order.address_line2}</p>` : ''}
                                <p style="margin: 0 0 4px 0;">${order.address_city || ''}, ${order.address_postal_code || ''}</p>
                                <p style="margin: 0 0 4px 0;">${order.address_country || ''}</p>
                                ${order.address_phone ? `<p style="color: #6b7280; margin: 0;">${order.address_phone}</p>` : ''}
                            </div>
                            ` : '<p style="color: #9ca3af; font-size: 14px; margin: 0;">No address information</p>'}
                        </div>
                    </div>

                    <!-- Line Items -->
                    <div>
                        <h3 style="font-weight: 600; color: #111827; margin: 0 0 12px 0;">Items (${order.line_items ? order.line_items.length : 0})</h3>
                        ${order.line_items && order.line_items.length > 0 ? `
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
                                    ${order.line_items.map((item, index) => `
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
                                <input type="text" name="address_first_name" value="${order.address_first_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div>
                                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Last Name</label>
                                <input type="text" name="address_last_name" value="${order.address_last_name || ''}" 
                                       style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Email</label>
                            <input type="email" name="address_email" value="${order.address_email || ''}" 
                                   style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        </div>
                        <div>
                            <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 4px;">Phone</label>
                            <input type="text" name="address_phone" value="${order.address_phone || ''}" 
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
                    ${order.line_items && order.line_items.length > 0 ? 
                        order.line_items.map((item, index) => `
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

// ==================== COLUMN CUSTOMIZATION SYSTEM ====================

// Available columns with their properties (Based on new 3-table structure)
const availableColumns = {
    id: { label: 'ID', width: 'w-[60px]', key: 'id' },
    order_number: { label: 'Order #', width: 'min-w-[100px]', key: 'order_number' },
    order_date: { label: 'Order Date', width: 'min-w-[130px]', key: 'order_date' },
    order_status: { label: 'Status', width: 'w-[100px]', key: 'order_status' },
    external_source: { label: 'Source', width: 'w-[100px]', key: 'external_source' },
    external_id: { label: 'External ID', width: 'w-[100px]', key: 'external_id' },
    
    // Customer Info
    customer_name: { label: 'Customer Name', width: 'w-[150px]', key: 'customer_name' },
    contact_email: { label: 'Contact Email', width: 'w-[180px]', key: 'contact_email' },
    customer_phone: { label: 'Customer Phone', width: 'w-[130px]', key: 'customer_phone' },
    
    // Address Info
    address_first_name: { label: 'Address Name', width: 'w-[150px]', key: 'address_first_name' },
    address_email: { label: 'Address Email', width: 'w-[180px]', key: 'address_email' },
    address_phone: { label: 'Address Phone', width: 'w-[130px]', key: 'address_phone' },
    address_city: { label: 'City', width: 'w-[120px]', key: 'address_city' },
    address_province: { label: 'Province', width: 'w-[120px]', key: 'address_province' },
    address_country: { label: 'Country', width: 'w-[100px]', key: 'address_country' },
    
    // Financial Info
    currency: { label: 'Currency', width: 'w-[80px]', key: 'currency' },
    subtotal_price: { label: 'Subtotal', width: 'min-w-[100px]', key: 'subtotal_price' },
    discount_total: { label: 'Discount', width: 'w-[100px]', key: 'discount_total' },
    shipping_total: { label: 'Shipping', width: 'w-[100px]', key: 'shipping_total' },
    total_tax: { label: 'Tax', width: 'w-[100px]', key: 'total_tax' },
    total_price: { label: 'Total', width: 'w-[120px]', key: 'total_price' },
    total_weight: { label: 'Weight', width: 'w-[100px]', key: 'total_weight' },
    
    // Payment & Other Info
    payment_method: { label: 'Payment Method', width: 'w-[130px]', key: 'payment_method' },
    coupon_code: { label: 'Coupon', width: 'w-[100px]', key: 'coupon_code' },
    note: { label: 'Note', width: 'w-[150px]', key: 'note' },
    
    // Line Items
    line_items_count: { label: 'Items', width: 'w-[80px]', key: 'line_items_count' },
    
    // Timestamps
    created_at: { label: 'Created At', width: 'min-w-[130px]', key: 'created_at' },
    updated_at: { label: 'Updated At', width: 'min-w-[130px]', key: 'updated_at' },
    
    // Actions (always visible and last)
    actions: { label: 'Actions', width: 'w-[120px]', key: 'actions', fixed: true }
};

// Default column order and visibility (practical selection)
const defaultColumns = [
    { id: 'id', visible: true },
    { id: 'order_number', visible: true },
    { id: 'order_date', visible: true },
    { id: 'order_status', visible: true },
    { id: 'customer_name', visible: true },
    { id: 'contact_email', visible: true },
    { id: 'line_items_count', visible: true },
    { id: 'total_price', visible: true },
    { id: 'payment_method', visible: true },
    { id: 'external_source', visible: true },
    { id: 'actions', visible: true },
    
    // Hidden by default but available
    { id: 'external_id', visible: false },
    { id: 'customer_phone', visible: false },
    { id: 'address_first_name', visible: false },
    { id: 'address_email', visible: false },
    { id: 'address_phone', visible: false },
    { id: 'address_city', visible: false },
    { id: 'address_province', visible: false },
    { id: 'address_country', visible: false },
    { id: 'currency', visible: false },
    { id: 'subtotal_price', visible: false },
    { id: 'discount_total', visible: false },
    { id: 'shipping_total', visible: false },
    { id: 'total_tax', visible: false },
    { id: 'total_weight', visible: false },
    { id: 'coupon_code', visible: false },
    { id: 'note', visible: false },
    { id: 'created_at', visible: false },
    { id: 'updated_at', visible: false }
];

// Current column settings
let currentColumns = JSON.parse(localStorage.getItem('orderTableColumns')) || defaultColumns;

// Orders data passed from Laravel
window.ordersData = @json($orders->items());

// Initialize table on page load
document.addEventListener('DOMContentLoaded', function() {
    renderOrdersTable();
});

function openColumnSettings() {
    renderColumnSettings();
    document.getElementById('columnSettingsModal').style.display = 'block';
}

function renderColumnSettings() {
    const columnList = document.getElementById('columnList');
    columnList.innerHTML = '';
    
    currentColumns.forEach((column, index) => {
        const columnConfig = availableColumns[column.id];
        if (!columnConfig) return;
        
        const item = document.createElement('div');
        item.className = 'column-item';
        item.draggable = true;
        item.dataset.columnId = column.id;
        item.style.cssText = `
            display: flex; 
            align-items: center; 
            padding: 12px; 
            margin-bottom: 8px; 
            background: white; 
            border: 1px solid #e5e7eb; 
            border-radius: 6px; 
            cursor: ${columnConfig.fixed ? 'default' : 'grab'};
            user-select: none;
        `;
        
        item.innerHTML = `
            <div style="display: flex; align-items: center; width: 100%;">
                ${!columnConfig.fixed ? '<div style="margin-right: 12px; color: #9ca3af;"><svg style="width: 16px; height: 16px;" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path></svg></div>' : ''}
                <input type="checkbox" ${column.visible ? 'checked' : ''} ${columnConfig.fixed ? 'disabled' : ''} 
                       onchange="toggleColumnVisibility('${column.id}')" 
                       style="margin-right: 12px;">
                <label style="flex: 1; font-weight: 500; color: ${columnConfig.fixed ? '#9ca3af' : '#374151'};">
                    ${columnConfig.label} ${columnConfig.fixed ? '(Fixed)' : ''}
                </label>
            </div>
        `;
        
        if (!columnConfig.fixed) {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragend', handleDragEnd);
        }
        
        columnList.appendChild(item);
    });
}

function toggleColumnVisibility(columnId) {
    const column = currentColumns.find(col => col.id === columnId);
    if (column && !availableColumns[columnId].fixed) {
        column.visible = !column.visible;
    }
}

let draggedElement = null;

function handleDragStart(e) {
    draggedElement = e.target;
    e.target.style.opacity = '0.5';
}

function handleDragOver(e) {
    e.preventDefault();
}

function handleDrop(e) {
    e.preventDefault();
    if (draggedElement !== e.target && !availableColumns[e.target.dataset.columnId]?.fixed) {
        const draggedId = draggedElement.dataset.columnId;
        const targetId = e.target.dataset.columnId;
        
        const draggedIndex = currentColumns.findIndex(col => col.id === draggedId);
        const targetIndex = currentColumns.findIndex(col => col.id === targetId);
        
        if (draggedIndex !== -1 && targetIndex !== -1) {
            const draggedColumn = currentColumns.splice(draggedIndex, 1)[0];
            currentColumns.splice(targetIndex, 0, draggedColumn);
            renderColumnSettings();
        }
    }
}

function handleDragEnd(e) {
    e.target.style.opacity = '1';
    draggedElement = null;
}

function resetColumns() {
    currentColumns = JSON.parse(JSON.stringify(defaultColumns));
    renderColumnSettings();
}

function applyColumnSettings() {
    localStorage.setItem('orderTableColumns', JSON.stringify(currentColumns));
    renderOrdersTable();
    closeModal('columnSettingsModal');
}

function renderOrdersTable() {
    renderTableHeader();
    renderTableBody();
}

function renderTableHeader() {
    const header = document.getElementById('table-header');
    header.innerHTML = '';
    
    
    currentColumns.forEach(column => {
        if (column.visible) {
            const columnConfig = availableColumns[column.id];
            if (columnConfig) {
                const th = document.createElement('th');
                th.className = `px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider ${columnConfig.width}`;
                th.textContent = columnConfig.label;
                header.appendChild(th);
            }
        }
    });
}

function renderTableBody() {
    const tbody = document.getElementById('table-body');
    tbody.innerHTML = '';
    
    
    if (!window.ordersData || window.ordersData.length === 0) {
        // Show a message in the table
        const row = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 10;
        td.className = 'text-center py-8 text-gray-500';
        td.innerHTML = 'No orders found';
        row.appendChild(td);
        tbody.appendChild(row);
        return;
    }
    
    window.ordersData.forEach((order, index) => {
        try {
            const row = document.createElement('tr');
            row.className = `hover:bg-blue-50 transition-colors duration-200 ${index % 2 === 0 ? 'bg-white' : 'bg-gray-25'}`;
            
            currentColumns.forEach(column => {
                if (column.visible) {
                    try {
                        const td = document.createElement('td');
                        td.className = 'px-4 py-3 align-top';
                        const cellContent = getCellContent(order, column.id);
                        td.innerHTML = cellContent;
                        row.appendChild(td);
                    } catch (cellError) {
                        console.error(`Error rendering cell ${column.id}:`, cellError);
                        const td = document.createElement('td');
                        td.className = 'px-4 py-3 align-top';
                        td.innerHTML = '<span class="text-red-500">Error</span>';
                        row.appendChild(td);
                    }
                }
            });
            
            tbody.appendChild(row);
        } catch (rowError) {
            console.error(`Error rendering row ${index}:`, rowError, order);
        }
    });
}

function getCellContent(order, columnId) {
    
    const formatDate = (dateStr) => {
        if (!dateStr) return '<span class="text-gray-400">-</span>';
        try {
            // Handle ISO format: "2025-09-09T17:41:03.000000Z"
            let cleanDate = dateStr;
            
            if (dateStr.includes('T')) {
                // Extract date and time parts
                const [datePart, timePart] = dateStr.split('T');
                const [year, month, day] = datePart.split('-');
                const timeOnly = timePart.split('.')[0]; // Remove microseconds
                const [hour, minute] = timeOnly.split(':');
                
                // Format as: "09 Sep 2025, 17:41"
                const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const monthName = monthNames[parseInt(month) - 1];
                
                cleanDate = `${day} ${monthName} ${year}, ${hour}:${minute}`;
            } else if (dateStr.includes(' ')) {
                // Handle format: "2025-09-09 17:41:03"
                const [datePart, timePart] = dateStr.split(' ');
                const [year, month, day] = datePart.split('-');
                const [hour, minute] = timePart.split(':');
                
                const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                                  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                const monthName = monthNames[parseInt(month) - 1];
                
                cleanDate = `${day} ${monthName} ${year}, ${hour}:${minute}`;
            }
            
            return `<span class="text-sm text-gray-900">${cleanDate}</span>`;
        } catch (error) {
            console.error('Date parsing error:', error, 'for date:', dateStr);
            return `<span class="text-sm text-gray-600">${dateStr}</span>`;
        }
    };
    
    const formatCurrency = (amount, currency = 'PKR') => {
        if (!amount) return '0.00';
        return parseFloat(amount).toFixed(2);
    };
    
    try {
        switch (columnId) {
        // Basic Info
        case 'id':
            return order.id;
        case 'order_number':
            return order.order_number || '';
        case 'order_date':
            return formatDate(order.order_date);
        case 'order_status':
            const status = order.order_status || 'pending';
            const statusConfig = {
                'pending': { bg: 'bg-yellow-100', text: 'text-yellow-800', icon: '⏳' },
                'processing': { bg: 'bg-blue-100', text: 'text-blue-800', icon: '⚡' },
                'completed': { bg: 'bg-green-100', text: 'text-green-800', icon: '✓' },
                'cancelled': { bg: 'bg-red-100', text: 'text-red-800', icon: '✕' },
                'refunded': { bg: 'bg-purple-100', text: 'text-purple-800', icon: '↩' },
                'on-hold': { bg: 'bg-orange-100', text: 'text-orange-800', icon: '⏸' }
            };
            const config = statusConfig[status] || { bg: 'bg-gray-100', text: 'text-gray-800', icon: '?' };
            return `<span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium ${config.bg} ${config.text}">
                        <span class="mr-1">${config.icon}</span>
                        ${status.charAt(0).toUpperCase() + status.slice(1)}
                    </span>`;
        case 'external_source':
            const source = order.external_source || 'manual';
            const sourceColors = {
                'shopify': 'bg-green-100 text-green-800',
                'woocommerce': 'bg-purple-100 text-purple-800',
                'manual': 'bg-orange-100 text-orange-800'
            };
            const sourceColor = sourceColors[source] || 'bg-gray-100 text-gray-800';
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs ${sourceColor}">${source.charAt(0).toUpperCase() + source.slice(1)}</span>`;
        case 'external_id':
            return order.external_id || '';
            
        // Customer Info
        case 'customer_name':
            // Priority: order.name (from address) -> customer.full_name -> address fields
            if (order.name && order.name.trim()) {
                return order.name.trim();
            }
            if (order.customer && order.customer.full_name && order.customer.full_name.trim()) {
                return order.customer.full_name.trim();
            }
            // Fallback to address fields
            const firstName = order.address_first_name || '';
            const lastName = order.address_last_name || '';
            const fullName = (firstName + ' ' + lastName).trim();
            return fullName || 'N/A';
        case 'contact_email':
            return order.contact_email || '';
        case 'customer_phone':
            return order.customer_phone || order.address_phone || '';
            
        // Address Info
        case 'address_first_name':
            // Show full name from address fields
            const addrFirstName = order.address_first_name || '';
            const addrLastName = order.address_last_name || '';
            const addrFullName = (addrFirstName + ' ' + addrLastName).trim();
            return addrFullName || '';
        case 'address_email':
            return order.address_email || '';
        case 'address_phone':
            return order.address_phone || '';
        case 'address_city':
            return order.address_city || '';
        case 'address_province':
            return order.address_province || '';
        case 'address_country':
            return order.address_country || '';
            
        // Financial Info
        case 'currency':
            return order.currency || 'PKR';
        case 'subtotal_price':
            return formatCurrency(order.subtotal_price);
        case 'discount_total':
            return formatCurrency(order.discount_total);
        case 'shipping_total':
            return formatCurrency(order.shipping_total);
        case 'total_tax':
            return formatCurrency(order.total_tax);
        case 'total_price':
            return formatCurrency(order.total_price);
        case 'total_weight':
            return order.total_weight || '0';
            
        // Payment & Other Info
        case 'payment_method':
            return order.payment_method || '';
        case 'coupon_code':
            return order.coupon_code || '';
        case 'note':
            const note = order.note || '';
            return note.length > 30 ? note.substring(0, 30) + '...' : note;
            
        // Line Items
        case 'line_items_count':
            const itemCount = order.line_items ? order.line_items.length : 0;
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">${itemCount}</span>`;
            
        // Timestamps
        case 'created_at':
            return formatDate(order.created_at);
        case 'updated_at':
            return formatDate(order.updated_at);
            
        // Actions
        case 'actions':
            return `
                <div class="flex gap-2">
                    <button onclick="viewOrderDetails(${order.id})" class="kt-btn kt-btn-sm kt-btn-light" title="View Details">
                        <i class="ki-filled ki-eye text-sm"></i>
                    </button>
                    <button onclick="editOrderDetails(${order.id})" class="kt-btn kt-btn-sm kt-btn-primary" title="Edit Order">
                        <i class="ki-filled ki-notepad-edit text-sm"></i>
                    </button>
                </div>
            `;
        default:
            return '';
        }
    } catch (error) {
        console.error(`Error in getCellContent for column ${columnId}:`, error, order);
        return `<span class="text-red-500">Error</span>`;
    }
}

// ==================== END COLUMN CUSTOMIZATION ====================

// ==================== SEARCH AND FILTER FUNCTIONALITY ====================

window.allOrders = []; // Store all orders for filtering
window.filteredOrders = []; // Store filtered results

// Initialize search and filters
document.addEventListener('DOMContentLoaded', function() {
    // Store original data from current page only
    window.allOrders = [...window.ordersData];
    window.filteredOrders = [...window.ordersData];
    
    // Set up search with debouncing
    const searchInput = document.getElementById('orderSearch');
    const statusFilter = document.getElementById('statusFilter');
    const dateFilter = document.getElementById('dateFilter');
    
    let searchTimeout;
    
    // Search functionality - make API call for full dataset
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const searchTerm = searchInput.value.trim();
            if (searchTerm.length > 2) {
                fetchFilteredOrders();
            } else {
                // Reset to current page data if search is too short
                window.filteredOrders = [...window.ordersData];
                renderOrdersWithFilters(window.filteredOrders);
                updateResultsCount();
            }
        }, 500);
    });
    
    // Filter functionality - make API call for full dataset
    statusFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
    
    dateFilter.addEventListener('change', function() {
        fetchFilteredOrders();
    });
});

// Fetch filtered orders from backend
function fetchFilteredOrders() {
    const searchTerm = document.getElementById('orderSearch').value.trim();
    const statusFilter = document.getElementById('statusFilter').value;
    const dateFilter = document.getElementById('dateFilter').value;
    
    // Show loading state
    showLoadingState();
    
    // Build query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (statusFilter) params.append('status', statusFilter);
    if (dateFilter) params.append('date', dateFilter);
    
    // Get current source (shopify/other)
    const currentUrl = new URL(window.location);
    const source = currentUrl.searchParams.get('source') || 'other';
    params.append('source', source);
    
    // Make API call
    fetch(`/orders/filter?${params.toString()}`, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.filteredOrders = data.orders;
            renderOrdersWithFilters(window.filteredOrders);
            updateResultsCount();
        } else {
            console.error('Filter error:', data.message);
            // Fallback to current page data
            window.filteredOrders = [...window.ordersData];
            renderOrdersWithFilters(window.filteredOrders);
            updateResultsCount();
        }
    })
    .catch(error => {
        console.error('Filter request failed:', error);
        // Fallback to current page data
        window.filteredOrders = [...window.ordersData];
        renderOrdersWithFilters(window.filteredOrders);
        updateResultsCount();
    })
    .finally(() => {
        hideLoadingState();
    });
}

// Apply all filters and search (legacy function - keeping for compatibility)
function applyFilters() {
    // Show loading state
    showLoadingState();
    
    // Small delay to show loading animation
    setTimeout(() => {
        const searchTerm = document.getElementById('orderSearch').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const dateFilter = document.getElementById('dateFilter').value;
        
        window.filteredOrders = window.allOrders.filter(order => {
        // Search filter
        const matchesSearch = !searchTerm || 
            (order.customer && order.customer.name && order.customer.name.toLowerCase().includes(searchTerm)) ||
            (order.order_number && order.order_number.toLowerCase().includes(searchTerm)) ||
            (order.customer && order.customer.phone && order.customer.phone.includes(searchTerm)) ||
            (order.customer && order.customer.email && order.customer.email.toLowerCase().includes(searchTerm));
        
        // Status filter
        const matchesStatus = !statusFilter || 
            (order.order_status && order.order_status.toLowerCase() === statusFilter);
        
        // Date filter
        const matchesDate = !dateFilter || 
            (order.order_date && order.order_date.startsWith(dateFilter));
        
        return matchesSearch && matchesStatus && matchesDate;
    });
    
        // Update the display
        renderOrdersWithFilters(window.filteredOrders);
        updateResultsCount();
        hideLoadingState();
    }, 100);
}

// Update results count display
function updateResultsCount() {
    const totalCount = window.allOrders.length;
    const filteredCount = window.filteredOrders.length;
    
    // Update our custom results count element
    const resultsElement = document.getElementById('results-count');
    if (resultsElement) {
        if (filteredCount === totalCount) {
            resultsElement.textContent = `Showing ${filteredCount} orders`;
        } else {
            resultsElement.textContent = `Showing ${filteredCount} of ${totalCount} orders`;
        }
    }
    
    // Also update pagination info if it exists
    const infoElement = document.querySelector('[data-kt-datatable-info="true"]');
    if (infoElement) {
        if (filteredCount === totalCount) {
            infoElement.textContent = `${filteredCount} orders`;
        } else {
            infoElement.textContent = `${filteredCount} of ${totalCount} orders`;
        }
    }
}

// Render table with filtered data
function renderOrdersWithFilters(data) {
    // Update the global ordersData
    window.ordersData = data;
    
    if (data.length === 0) {
        // Show no results state
        document.getElementById('table-body').style.display = 'none';
        document.getElementById('no-results-state').classList.remove('hidden');
    } else {
        // Show normal table
        document.getElementById('no-results-state').classList.add('hidden');
        document.getElementById('table-body').style.display = '';
        renderOrdersTable(); // Re-render the table
    }
}

// Clear all filters
function clearFilters() {
    document.getElementById('orderSearch').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('dateFilter').value = '';
    
    // Reset to current page data
    window.filteredOrders = [...window.ordersData];
    renderOrdersWithFilters(window.filteredOrders);
    updateResultsCount();
}

// Loading state functions
function showLoadingState() {
    document.getElementById('table-body').style.display = 'none';
    document.getElementById('loading-state').classList.remove('hidden');
}

function hideLoadingState() {
    document.getElementById('loading-state').classList.add('hidden');
    document.getElementById('table-body').style.display = '';
}

// ==================== END SEARCH AND FILTER ====================

</script>
@endpush