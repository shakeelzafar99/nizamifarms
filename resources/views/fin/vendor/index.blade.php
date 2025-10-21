@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Vendors</h1>
        <div class="flex gap-3">
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
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">All Statuses</option>
                <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') == 'inactive' ? 'selected' : '' }}>Inactive</option>
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Payment</th>
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
                            @if($vendor->last_payment_date && $vendor->last_payment_amount)
                                <div class="text-sm font-medium text-gray-900">Rs. {{ number_format($vendor->last_payment_amount, 2) }}</div>
                                <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($vendor->last_payment_date)->format('M d, Y') }}</div>
                            @else
                                <div class="text-sm text-gray-400">No payments yet</div>
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
                                <button onclick="event.stopPropagation(); openEditVendorModal({{ $vendor->id }}, '{{ addslashes($vendor->vendor_name) }}', '{{ addslashes($vendor->contact_person ?? '') }}', '{{ addslashes($vendor->contact_phone ?? '') }}', '{{ addslashes($vendor->contact_email ?? '') }}', '{{ $vendor->default_purchase_method ?? 'by_total' }}')" 
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
            {{ $vendors->links() }}
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
function openEditVendorModal(vendorId, vendorName, contactPerson, contactPhone, contactEmail, defaultPurchaseMethod) {
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
</script>

@endsection

