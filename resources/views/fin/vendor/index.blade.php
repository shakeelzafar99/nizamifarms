@extends('layouts.app')

@section('title', 'Vendors')

@section('content')
<style>
@media print {
    /* Hide everything except the report content */
    body * {
        visibility: hidden;
    }
    #reportContent, #reportContent * {
        visibility: visible;
    }
    #reportContent {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
    }
    
    /* Hide modal backdrop and other UI elements */
    #reportModal {
        background: white !important;
        backdrop-filter: none !important;
    }
    
    /* Hide the print button when printing */
    button[onclick="printReport()"] {
        display: none !important;
    }
    
    /* Ensure proper page breaks */
    .vendor-section {
        page-break-inside: avoid;
        break-inside: avoid;
    }
    
    /* Better spacing for print */
    .bg-gradient-to-r {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        color-adjust: exact;
    }
    
    /* Preserve table borders */
    table, th, td {
        border: 1px solid #000 !important;
    }
    
    /* Preserve colors for amounts */
    .text-red-600, .text-red-700 {
        color: #dc2626 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-green-600, .text-green-700 {
        color: #16a34a !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .text-purple-600, .text-purple-700 {
        color: #9333ea !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    /* Background colors for print */
    .bg-purple-100, .print\:bg-purple-200 {
        background-color: #f3e8ff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .bg-gray-100, .print\:bg-gray-200 {
        background-color: #f3f4f6 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    
    .bg-green-50, .bg-red-50 {
        background-color: transparent !important;
    }
    
    /* Compact spacing for print */
    .p-6 {
        padding: 1rem !important;
    }
    
    .mb-6 {
        margin-bottom: 1rem !important;
    }
    
    /* Font sizes for print */
    body {
        font-size: 10pt;
    }
    
    h3 {
        font-size: 14pt;
    }
    
    h4 {
        font-size: 12pt;
    }
}
</style>
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <div class="flex items-center gap-4">
            <h1 class="text-2xl font-semibold text-gray-900">Vendors</h1>
            <!-- Total Balance Card -->
            <div class="bg-gradient-to-r from-red-50 to-red-100 border-2 border-red-300 rounded-lg px-4 py-2 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="text-2xl">💰</div>
                    <div>
                        <div class="text-xs font-semibold text-red-700 uppercase">Total Payable</div>
                        <div class="text-lg font-bold {{ $totalBalance > 0 ? 'text-red-600' : 'text-green-600' }}">
                            Rs. {{ number_format($totalBalance, 2) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex gap-3">
            <button onclick="openReportModal()" 
                    class="inline-flex items-center px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #7c3aed !important; color: white !important;">
                <span style="color: white !important;">📊 Report</span>
            </button>
            <button onclick="openCreateVendorModal()" 
                    class="inline-flex items-center px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md"
                    style="background-color: #059669 !important; color: white !important;">
                <span style="color: white !important;">➕ Add New Vendor</span>
            </button>
            <a href="{{ route('admin.operations') }}" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                ← Back to Operations
            </a>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-6">
        <form method="GET" action="{{ route('fin.vendors.index') }}" class="flex gap-4">
            <div class="flex-1">
                <input type="text" name="search" value="{{ request('search') }}" 
                       placeholder="Search vendors..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            @php
                // Default to BU 1 (Nizami Farms) when page loads without explicit filter
                $selectedBuFilter = request('business_unit_id', '1');
            @endphp
            <select name="business_unit_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="all" {{ $selectedBuFilter === 'all' ? 'selected' : '' }}>All Business Units</option>
                @foreach($businessUnits ?? [] as $bu)
                    <option value="{{ $bu->id }}" {{ $selectedBuFilter == $bu->id ? 'selected' : '' }}>
                        {{ $bu->name }} {{ $bu->short_code ? '(' . $bu->short_code . ')' : '' }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">
                Search
            </button>
        </form>
    </div>

    <!-- Success Message -->
    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-sm text-green-800">{{ session('success') }}</p>
        </div>
    @endif

    <!-- Error Message -->
    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-sm text-red-800">{{ session('error') }}</p>
        </div>
    @endif

    <!-- Vendors Table -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendor Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Purchase Method</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Business Unit</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance Payable</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($vendors as $vendor)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('fin.vendors.show', $vendor->id) }}'">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $vendor->vendor_name }}</div>
                            <div class="text-xs text-gray-500">{{ $vendor->account ? $vendor->account->account_code : 'N/A' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($vendor->default_purchase_method === 'by_weight')
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-orange-100 text-orange-800">
                                    ⚖️ By Weight
                                </span>
                            @else
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                    📦 By Total
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($vendor->businessUnit)
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full" style="background-color: {{ ($vendor->businessUnit->color_hex ?? '#6b7280') }}20; color: {{ $vendor->businessUnit->color_hex ?? '#6b7280' }}">
                                    {{ $vendor->businessUnit->name }} {{ $vendor->businessUnit->short_code ? '(' . $vendor->businessUnit->short_code . ')' : '' }}
                                </span>
                            @else
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-600">
                                    Nizami Farms
                                </span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <span class="text-sm font-semibold {{ ($vendor->account && $vendor->account->current_balance > 0) ? 'text-red-600' : 'text-gray-900' }}">
                                Rs. {{ $vendor->account ? number_format($vendor->account->current_balance, 2) : '0.00' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $vendor->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                                {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-center gap-2">
                                <a href="{{ route('fin.vendors.show', $vendor->id) }}" 
                                   class="text-blue-600 hover:text-blue-900"
                                   title="View Ledger">
                                    📊 View
                                </a>
                                <button onclick="event.stopPropagation(); openEditVendorModal({{ $vendor->id }}, '{{ addslashes($vendor->vendor_name) }}', '{{ addslashes($vendor->contact_person ?? '') }}', '{{ addslashes($vendor->contact_phone ?? '') }}', '{{ addslashes($vendor->contact_email ?? '') }}', '{{ $vendor->default_purchase_method ?? 'by_total' }}', '{{ $vendor->default_payment_source_id ?? '' }}', '{{ $vendor->business_unit_id ?? 1 }}')" 
                                        class="text-indigo-600 hover:text-indigo-900"
                                        title="Edit Vendor">
                                    ✏️ Edit
                                </button>
                                <button onclick="event.stopPropagation(); toggleVendorStatus({{ $vendor->id }}, {{ $vendor->is_active ? 'true' : 'false' }})" 
                                        class="{{ $vendor->is_active ? 'text-orange-600 hover:text-orange-900' : 'text-green-600 hover:text-green-900' }}"
                                        title="{{ $vendor->is_active ? 'Mark Inactive' : 'Mark Active' }}">
                                    {{ $vendor->is_active ? '⏸️ Inactive' : '▶️ Active' }}
                                </button>
                                @if($vendor->account && $vendor->account->current_balance == 0)
                                <button onclick="event.stopPropagation(); confirmDeleteVendor({{ $vendor->id }}, '{{ addslashes($vendor->vendor_name) }}')" 
                                        class="text-red-600 hover:text-red-900"
                                        title="Delete Vendor">
                                    🗑️ Delete
                                </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                            No vendors found. Import your legacy data to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($vendors->hasPages())
        <div class="mt-4">
            {{ $vendors->appends(request()->query())->links() }}
        </div>
    @endif
</div>

<!-- Create Vendor Modal -->
<div id="createVendorModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #d1fae5 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #86efac; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ➕
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Add New Vendor</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Create a new vendor account</p>
                </div>
            </div>
            <button type="button" onclick="closeCreateVendorModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
            </div>
            
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form action="{{ route('fin.vendors.store') }}" method="POST" id="createVendorForm">
                @csrf
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Vendor Name <span class="text-red-600">*</span></label>
                        <input type="text" name="vendor_name" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900"
                               placeholder="e.g., ABC Suppliers">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Contact Person</label>
                        <input type="text" name="contact_person"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900"
                               placeholder="e.g., John Doe">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Email</label>
                        <input type="email" name="contact_email"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900"
                               placeholder="e.g., vendor@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Phone</label>
                        <input type="text" name="contact_phone"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900"
                               placeholder="e.g., +92 300 1234567">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Default Purchase Method <span class="text-red-600">*</span></label>
                        <select name="default_purchase_method" required
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900 bg-white">
                            <option value="by_total">By Total (Flat Amount)</option>
                            <option value="by_weight">By Weight (Itemized)</option>
                        </select>
                        <p class="text-xs text-gray-600 mt-1">💡 Choose how you'll typically record purchases from this vendor</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Default Payment Source</label>
                        <select name="default_payment_source_id"
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900 bg-white">
                            <option value="">NF Cash (Default)</option>
                            @foreach($accessibleCompanyAccounts ?? [] as $source)
                                @if($source->account_code !== 'EXP_FUND')
                                <option value="{{ $source->id }}">{{ $source->account_name }}</option>
                                @endif
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-600 mt-1">💳 Pre-selected when recording payments for this vendor</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Business Unit</label>
                        <select name="business_unit_id"
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 text-gray-900 bg-white">
                            @foreach($businessUnits as $unit)
                                <option value="{{ $unit->id }}" {{ $unit->id == ($userDefaultBuId ?? 1) ? 'selected' : '' }}>
                                    {{ $unit->name }} {{ $unit->short_code ? '(' . $unit->short_code . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-600 mt-1">🏢 Tag vendor to a business unit for expense tracking</p>
                    </div>
                    
                    <div class="p-3 bg-blue-50 border-2 border-blue-200 rounded-md">
                        <p class="text-xs text-blue-900 font-semibold">
                            ℹ️ System will automatically:
                        </p>
                        <ul class="text-xs text-blue-800 mt-1 ml-4 list-disc">
                            <li>Create a payable account for this vendor</li>
                            <li>Set account code (e.g., VEN_ABC_SUPPLIERS)</li>
                            <li>Configure as Liability account</li>
                        </ul>
                    </div>
                    
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeCreateVendorModal()" 
                    style="flex: 1; padding: 10px 16px; border: 2px solid #d1d5db; background: white; color: #374151; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
                            Cancel
                        </button>
            <button type="submit" form="createVendorForm"
                    style="flex: 1; padding: 10px 16px; border: none; background: #059669; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                ✓ Create Vendor
                        </button>
                    </div>
    </div>
</div>

<!-- Edit Vendor Modal -->
<div id="editVendorModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #e0e7ff 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #a5b4fc; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    ✏️
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Edit Vendor</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">Update vendor information</p>
                </div>
            </div>
            <button type="button" onclick="closeEditVendorModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <form id="editVendorForm" method="POST">
                @csrf
                @method('PUT')
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Vendor Name <span class="text-red-600">*</span></label>
                        <input type="text" id="edit_vendor_name" name="vendor_name" required
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900"
                               placeholder="e.g., ABC Suppliers">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Contact Person</label>
                        <input type="text" id="edit_contact_person" name="contact_person"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900"
                               placeholder="e.g., John Doe">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Email</label>
                        <input type="email" id="edit_contact_email" name="contact_email"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900"
                               placeholder="e.g., vendor@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Phone</label>
                        <input type="text" id="edit_contact_phone" name="contact_phone"
                               class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900"
                               placeholder="e.g., +92 300 1234567">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Default Purchase Method <span class="text-red-600">*</span></label>
                        <select id="edit_default_purchase_method" name="default_purchase_method" required
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900 bg-white">
                            <option value="by_total">By Total (Flat Amount)</option>
                            <option value="by_weight">By Weight (Itemized)</option>
                        </select>
                        <p class="text-xs text-gray-600 mt-1">💡 This determines which purchase button will be shown for this vendor</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Default Payment Source</label>
                        <select id="edit_default_payment_source_id" name="default_payment_source_id"
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900 bg-white">
                            <option value="">NF Cash (Default)</option>
                            @foreach($accessibleCompanyAccounts ?? [] as $source)
                                @if($source->account_code !== 'EXP_FUND')
                                <option value="{{ $source->id }}">{{ $source->account_name }}</option>
                                @endif
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-600 mt-1">💳 Pre-selected when recording payments for this vendor</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-800 mb-1">Business Unit</label>
                        <select id="edit_business_unit_id" name="business_unit_id"
                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900 bg-white">
                            @foreach($businessUnits as $unit)
                                <option value="{{ $unit->id }}">
                                    {{ $unit->name }} {{ $unit->short_code ? '(' . $unit->short_code . ')' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-600 mt-1">🏢 Tag vendor to a business unit for expense tracking</p>
                    </div>
                    
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer -->
        <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; gap: 12px;">
            <button type="button" onclick="closeEditVendorModal()" 
                    style="flex: 1; padding: 10px 16px; border: 2px solid #d1d5db; background: white; color: #374151; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s;">
                Cancel
            </button>
            <button type="submit" form="editVendorForm"
                    style="flex: 1; padding: 10px 16px; border: none; background: #4f46e5; color: white; font-weight: 600; border-radius: 8px; cursor: pointer; transition: all 0.15s; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                ✓ Update Vendor
            </button>
        </div>
    </div>
</div>

<script>
function openCreateVendorModal() {
    const modal = document.getElementById('createVendorModal');
    
    // Portalize to body if not already there
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    
    document.body.style.overflow = 'hidden';
}

function closeCreateVendorModal() {
    const modal = document.getElementById('createVendorModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modal when clicking outside
document.addEventListener('click', function(event) {
    const modal = document.getElementById('createVendorModal');
    if (event.target === modal) {
        closeCreateVendorModal();
    }
});

// Edit Vendor Modal Functions
function openEditVendorModal(vendorId, vendorName, contactPerson, contactPhone, contactEmail, defaultPurchaseMethod, defaultPaymentSourceId, businessUnitId) {
    const modal = document.getElementById('editVendorModal');
    const form = document.getElementById('editVendorForm');
    
    // Set form action
    form.action = `/finance/vendors/${vendorId}`;
    
    // Populate form fields
    document.getElementById('edit_vendor_name').value = vendorName;
    document.getElementById('edit_contact_person').value = contactPerson || '';
    document.getElementById('edit_contact_phone').value = contactPhone || '';
    document.getElementById('edit_contact_email').value = contactEmail || '';
    document.getElementById('edit_default_purchase_method').value = defaultPurchaseMethod || 'by_total';
    document.getElementById('edit_default_payment_source_id').value = defaultPaymentSourceId || '';
    document.getElementById('edit_business_unit_id').value = businessUnitId || '1';
    
    // Portalize to body if not already there
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }
    
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '99999'
    });
    
    document.body.style.overflow = 'hidden';
}

function closeEditVendorModal() {
    const modal = document.getElementById('editVendorModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Toggle Vendor Status
function toggleVendorStatus(vendorId, isActive) {
    const action = isActive ? 'mark as inactive' : 'mark as active';
    const confirmMsg = `Are you sure you want to ${action} this vendor?`;
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/toggle-status`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to update vendor status'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating vendor status');
    });
}

// Delete Vendor
function confirmDeleteVendor(vendorId, vendorName) {
    const confirmMsg = `⚠️ Are you sure you want to DELETE "${vendorName}"?\n\nThis action cannot be undone!`;
    
    if (!confirm(confirmMsg)) {
        return;
    }
    
    // Double confirmation for safety
    const doubleConfirm = confirm(`⚠️ FINAL CONFIRMATION\n\nYou are about to permanently delete "${vendorName}".\n\nClick OK to proceed or Cancel to abort.`);
    
    if (!doubleConfirm) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Vendor deleted successfully');
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Failed to delete vendor'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting vendor');
    });
}

// Report Modal Functions
function openReportModal() {
    const modal = document.getElementById('reportModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReportModal() {
    const modal = document.getElementById('reportModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    
    // Clear report content
    document.getElementById('reportContent').innerHTML = '';
    document.getElementById('reportContent').style.display = 'none';
    
    // Hide print and Excel buttons
    document.getElementById('printBtn').style.display = 'none';
    document.getElementById('excelBtn').style.display = 'none';
    
    // Clear stored report data
    window.currentReportData = null;
}

function generateReport() {
    const dateFrom = document.getElementById('report_date_from').value;
    const dateTo = document.getElementById('report_date_to').value;
    const vendorId = document.getElementById('report_vendor_id').value;
    const showPayments = document.getElementById('show_payments').checked;
    
    if (!dateFrom || !dateTo) {
        alert('Please select both start and end dates');
        return;
    }
    
    // Show loading
    const reportContent = document.getElementById('reportContent');
    reportContent.style.display = 'block';
    reportContent.innerHTML = `
        <div class="text-center py-12">
            <div class="animate-spin rounded-full h-16 w-16 border-b-2 border-purple-600 mx-auto"></div>
            <p class="mt-4 text-gray-600 font-medium">Generating report...</p>
        </div>
    `;
    
    // Fetch report data
    const params = new URLSearchParams({
        date_from: dateFrom,
        date_to: dateTo,
        show_payments: showPayments ? '1' : '0'
    });
    if (vendorId) {
        params.append('vendor_id', vendorId);
    }
    
    fetch(`/finance/vendors/report?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayReport(data.report, showPayments);
            } else {
                reportContent.innerHTML = `
                    <div class="text-center py-12">
                        <p class="text-red-600 font-medium">Error: ${data.message || 'Failed to generate report'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            reportContent.innerHTML = `
                <div class="text-center py-12">
                    <p class="text-red-600 font-medium">Error generating report</p>
                </div>
            `;
        });
}

function displayReport(report, showPayments = true) {
    const reportContent = document.getElementById('reportContent');
    
    // Show print and Excel buttons
    document.getElementById('printBtn').style.display = 'inline-block';
    document.getElementById('excelBtn').style.display = 'inline-block';
    
    // Store report data globally for Excel export
    window.currentReportData = report;
    window.currentShowPayments = showPayments;
    
    let html = `
        <div id="printableReport" class="bg-white rounded-lg border-2 border-purple-200 overflow-hidden">
            <!-- Report Header -->
            <div class="bg-gradient-to-r from-purple-600 to-purple-700 text-white px-6 py-4 print:bg-purple-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-xl font-bold">📊 Vendor Transaction Report</h3>
                        <p class="text-sm text-purple-100 mt-1">
                            Period: ${report.date_from} to ${report.date_to}
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Report Body -->
            <div class="p-6">
    `;
    
    // Loop through each vendor
    report.vendors.forEach((vendor, index) => {
        html += `
            <div class="vendor-section mb-6 ${index > 0 ? 'border-t-2 border-gray-300 pt-6' : ''} print:break-inside-avoid">
                <!-- Vendor Header -->
                <div class="flex justify-between items-center mb-3 bg-gray-100 px-4 py-3 rounded-lg print:bg-gray-200">
                    <div>
                        <h4 class="text-lg font-bold text-gray-900">${vendor.vendor_name}</h4>
                        <p class="text-xs text-gray-600 mt-0.5">
                            ${vendor.contact_person ? 'Contact: ' + vendor.contact_person : ''} ${vendor.contact_phone ? '• ' + vendor.contact_phone : ''}
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500 uppercase">Current Balance</div>
                        <div class="text-lg font-bold ${vendor.current_balance > 0 ? 'text-red-600' : 'text-green-600'}">
                            Rs. ${Number(vendor.current_balance).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                        </div>
                    </div>
                </div>
                
                <!-- Transactions Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-purple-100 print:bg-purple-200">
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Date</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Type</th>
                                <th class="border border-gray-300 px-3 py-2 text-left font-semibold text-gray-700">Details</th>
                                <th class="border border-gray-300 px-3 py-2 text-right font-semibold text-gray-700">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        // Loop through each day and transaction
        vendor.daily_summary.forEach(day => {
            let dayRowSpan = 0;
            
            // Count total rows for this day (including line items)
            day.transactions.forEach(txn => {
                if (txn.line_items && txn.line_items.length > 0) {
                    dayRowSpan += txn.line_items.length;
                } else {
                    dayRowSpan += 1;
                }
            });
            
            let isFirstRowOfDay = true;
            
            day.transactions.forEach(txn => {
                const isPayment = txn.type === 'payment';
                const amountColor = isPayment ? 'text-green-700' : 'text-red-700';
                const bgColor = isPayment ? 'bg-green-50' : 'bg-red-50';
                
                if (txn.line_items && txn.line_items.length > 0) {
                    // Weighted purchase with line items
                    txn.line_items.forEach((item, itemIndex) => {
                        html += `<tr class="${bgColor} print:bg-white">`;
                        
                        // Date column (only on first row of the day)
                        if (isFirstRowOfDay) {
                            html += `<td class="border border-gray-300 px-3 py-2 align-top font-medium text-gray-900" rowspan="${dayRowSpan}">${day.date}</td>`;
                            isFirstRowOfDay = false;
                        }
                        
                        // Type column (only on first line item)
                        if (itemIndex === 0) {
                            html += `
                                <td class="border border-gray-300 px-3 py-2 align-top" rowspan="${txn.line_items.length}">
                                    <div class="font-medium ${amountColor}">${isPayment ? '💰 Payment' : '📦 Purchase'}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">${txn.transaction_id}</div>
                                    ${txn.description ? `<div class="text-xs text-gray-600 mt-1">${txn.description}</div>` : ''}
                                </td>
                            `;
                        }
                        
                        // Details column (product details)
                        html += `
                            <td class="border border-gray-300 px-3 py-2">
                                <div class="flex justify-between items-center">
                                    <span class="font-medium text-gray-800">${item.product_name}</span>
                                    <span class="text-gray-600 ml-4">${item.quantity} ${item.unit} × Rs. ${Number(item.rate_per_unit).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                                </div>
                            </td>
                        `;
                        
                        // Amount column (only on first line item, show total)
                        if (itemIndex === 0) {
                            html += `
                                <td class="border border-gray-300 px-3 py-2 text-right align-top font-bold ${amountColor}" rowspan="${txn.line_items.length}">
                                    Rs. ${Number(txn.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                                </td>
                            `;
                        }
                        
                        html += `</tr>`;
                    });
                } else {
                    // Simple transaction (payment or flat purchase)
                    html += `<tr class="${bgColor} print:bg-white">`;
                    
                    // Date column (only on first row of the day)
                    if (isFirstRowOfDay) {
                        html += `<td class="border border-gray-300 px-3 py-2 align-top font-medium text-gray-900" rowspan="${dayRowSpan}">${day.date}</td>`;
                        isFirstRowOfDay = false;
                    }
                    
                    // Type column
                    html += `
                        <td class="border border-gray-300 px-3 py-2">
                            <div class="font-medium ${amountColor}">${isPayment ? '💰 Payment' : '📦 Purchase'}</div>
                            <div class="text-xs text-gray-500 mt-0.5">${txn.transaction_id}</div>
                        </td>
                    `;
                    
                    // Details column
                    html += `
                        <td class="border border-gray-300 px-3 py-2">
                            ${txn.description ? `<span class="text-gray-700">${txn.description}</span>` : '<span class="text-gray-400 italic">No description</span>'}
                        </td>
                    `;
                    
                    // Amount column
                    html += `
                        <td class="border border-gray-300 px-3 py-2 text-right font-bold ${amountColor}">
                            Rs. ${Number(txn.amount).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                        </td>
                    `;
                    
                    html += `</tr>`;
                }
            });
        });
        
        // Calculate payment mode totals for this vendor
        let vendorTotalPaymentsOnline = 0;
        let vendorTotalPaymentsCash = 0;
        vendor.daily_summary.forEach(day => {
            vendorTotalPaymentsOnline += day.total_payments_online || 0;
            vendorTotalPaymentsCash += day.total_payments_cash || 0;
        });
        
        // Vendor Summary Row
        html += `
                            <tr class="bg-purple-100 font-bold print:bg-purple-200">
                                <td colspan="3" class="border border-gray-300 px-3 py-2 text-right">
                                    <span class="text-gray-700">Vendor Total:</span>
                                    <span class="text-red-600 ml-4">Purchases: Rs. ${Number(vendor.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                                    ${showPayments ? `
                                        <span class="text-green-600 ml-4">Payments: Rs. ${Number(vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}</span>
                                        ${vendorTotalPaymentsOnline > 0 ? `<span class="text-blue-600 ml-2 text-xs">(Online: Rs. ${Number(vendorTotalPaymentsOnline).toLocaleString('en-PK', {minimumFractionDigits: 2})})</span>` : ''}
                                        ${vendorTotalPaymentsCash > 0 ? `<span class="text-orange-600 ml-2 text-xs">(Cash: Rs. ${Number(vendorTotalPaymentsCash).toLocaleString('en-PK', {minimumFractionDigits: 2})})</span>` : ''}
                                    ` : ''}
                                </td>
                                <td class="border border-gray-300 px-3 py-2 text-right ${(vendor.total_purchases - vendor.total_payments) > 0 ? 'text-red-600' : 'text-green-600'}">
                                    Rs. ${Number(vendor.total_purchases - vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });
    
    // Grand Total
    html += `
                <div class="border-t-4 border-purple-600 pt-4 mt-6 print:break-inside-avoid">
                    <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-4 border-2 border-purple-300 print:bg-purple-100">
                        <h4 class="text-lg font-bold text-gray-900 mb-3">📈 Grand Total (All Vendors)</h4>
                        <div class="grid ${showPayments ? 'grid-cols-3' : 'grid-cols-2'} gap-4">
                            <div class="text-center">
                                <div class="text-xs text-gray-600 uppercase mb-1">Total Purchases</div>
                                <div class="text-2xl font-bold text-red-600">Rs. ${Number(report.grand_total.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                            </div>
                            ${showPayments ? `
                            <div class="text-center">
                                <div class="text-xs text-gray-600 uppercase mb-1">Total Payments</div>
                                <div class="text-2xl font-bold text-green-600">Rs. ${Number(report.grand_total.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                            </div>
                            ` : ''}
                            <div class="text-center">
                                <div class="text-xs text-gray-600 uppercase mb-1">Net Change</div>
                                <div class="text-2xl font-bold ${(report.grand_total.total_purchases - report.grand_total.total_payments) > 0 ? 'text-red-600' : 'text-green-600'}">
                                    Rs. ${Number(report.grand_total.total_purchases - report.grand_total.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    reportContent.innerHTML = html;
}

function printReport() {
    window.print();
}

function exportToExcel() {
    if (!window.currentReportData) {
        alert('Please generate a report first');
        return;
    }
    
    const report = window.currentReportData;
    const showPayments = window.currentShowPayments !== undefined ? window.currentShowPayments : true;
    
    // Create CSV content
    let csvContent = "Vendor Transaction Report\n";
    csvContent += `Period: ${report.date_from} to ${report.date_to}\n\n`;
    
    // Loop through each vendor
    report.vendors.forEach((vendor, vendorIndex) => {
        if (vendorIndex > 0) csvContent += "\n\n";
        
        csvContent += `${vendor.vendor_name}\n`;
        csvContent += `Contact: ${vendor.contact_person || 'N/A'}${vendor.contact_phone ? ' • ' + vendor.contact_phone : ''}\n`;
        csvContent += `Current Balance: Rs. ${Number(vendor.current_balance).toLocaleString('en-PK', {minimumFractionDigits: 2})}\n\n`;
        
        // Table header
        csvContent += "Date,Type,Details,Amount\n";
        
        // Loop through each day
        vendor.daily_summary.forEach(day => {
            day.transactions.forEach(txn => {
                const type = txn.type === 'payment' ? 'Payment' : 'Purchase';
                const amount = Number(txn.amount).toLocaleString('en-PK', {minimumFractionDigits: 2});
                
                if (txn.line_items && txn.line_items.length > 0) {
                    // Weighted purchase with line items
                    txn.line_items.forEach((item, itemIndex) => {
                        const date = itemIndex === 0 ? day.date : '';
                        const typeCol = itemIndex === 0 ? `${type} (${txn.transaction_id})` : '';
                        const details = `${item.product_name} (${item.quantity} ${item.unit} × Rs. ${Number(item.rate_per_unit).toLocaleString('en-PK', {minimumFractionDigits: 2})})`;
                        const amountCol = itemIndex === 0 ? `Rs. ${amount}` : '';
                        
                        csvContent += `"${date}","${typeCol}","${details}","${amountCol}"\n`;
                    });
                } else {
                    // Simple transaction
                    const details = txn.description || 'No description';
                    csvContent += `"${day.date}","${type} (${txn.transaction_id})","${details}","Rs. ${amount}"\n`;
                }
            });
        });
        
        // Vendor summary
        const vendorSummary = showPayments 
            ? `Purchases: Rs. ${Number(vendor.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2})} | Payments: Rs. ${Number(vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}`
            : `Purchases: Rs. ${Number(vendor.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2})}`;
        csvContent += `\n"Vendor Total","","${vendorSummary}","Rs. ${Number(vendor.total_purchases - vendor.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}"\n`;
    });
    
    // Grand total
    csvContent += `\n\nGrand Total (All Vendors)\n`;
    csvContent += `Total Purchases,Rs. ${Number(report.grand_total.total_purchases).toLocaleString('en-PK', {minimumFractionDigits: 2})}\n`;
    if (showPayments) {
        csvContent += `Total Payments,Rs. ${Number(report.grand_total.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}\n`;
    }
    csvContent += `Net Change,Rs. ${Number(report.grand_total.total_purchases - report.grand_total.total_payments).toLocaleString('en-PK', {minimumFractionDigits: 2})}\n`;
    
    // Create download link
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    const filename = `Vendor_Report_${report.date_from.replace(/ /g, '_')}_to_${report.date_to.replace(/ /g, '_')}.csv`;
    
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<!-- Report Modal -->
<div id="reportModal" class="hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 99999; display: none; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;" onclick="if(event.target === this) closeReportModal()">
    <div onclick="event.stopPropagation()" style="background: white; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); width: 100%; max-width: 1400px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
        <!-- Fixed Header -->
        <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: linear-gradient(135deg, #f3e8ff 0%, #ffffff 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 48px; height: 48px; border-radius: 50%; background: #c084fc; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                    📊
                </div>
                <div>
                    <h3 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Vendor Report</h3>
                    <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;">View detailed purchase and payment summary</p>
                </div>
            </div>
            <button type="button" onclick="closeReportModal()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
        </div>
        
        <!-- Scrollable Content -->
        <div style="overflow-y: auto; flex: 1; padding: 20px 24px;">
            <!-- Report Filters - Compact Design -->
            <div class="bg-gradient-to-r from-purple-50 to-purple-100 rounded-lg p-3 border border-purple-300 mb-4 shadow-sm">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">From <span class="text-red-600">*</span></label>
                        <input type="date" id="report_date_from" value="{{ date('Y-m-01') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">To <span class="text-red-600">*</span></label>
                        <input type="date" id="report_date_to" value="{{ date('Y-m-d') }}"
                               class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900">
                    </div>
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Vendor</label>
                        <select id="report_vendor_id"
                                class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-purple-500 focus:border-purple-500 text-gray-900 bg-white">
                            <option value="">All Vendors</option>
                            {{-- ⚠ NOT $vendors — that is the PAGINATED table, so this
                                 picker used to offer only the 20 rows on screen and
                                 changed with the search box. A picker must list what
                                 exists, not what the table happens to be showing. --}}
                            @foreach(($pickerVendors ?? $vendors) as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->vendor_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-end">
                        <label class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 rounded cursor-pointer hover:bg-gray-50 transition-colors">
                            <input type="checkbox" id="show_payments" checked class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500 focus:ring-2">
                            <span class="ml-2 text-sm font-medium text-gray-700">Show Payments</span>
                        </label>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="generateReport()" 
                                class="px-4 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🔍 Generate
                        </button>
                        <button onclick="printReport()" id="printBtn" style="display: none;"
                                class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            🖨️ Print
                        </button>
                        <button onclick="exportToExcel()" id="excelBtn" style="display: none;"
                                class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-semibold rounded shadow-sm transition-colors">
                            📊 Excel
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Report Content -->
            <div id="reportContent" style="display: none;">
                <!-- Report will be loaded here -->
            </div>
        </div>
    </div>
</div>

@endsection

