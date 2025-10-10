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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Balance Payable</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($vendors as $vendor)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $vendor->vendor_name }}</div>
                            <div class="text-xs text-gray-500">{{ $vendor->account ? $vendor->account->account_code : 'N/A' }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">{{ $vendor->vendor_contact }}</div>
                            @if($vendor->vendor_phone)
                                <div class="text-xs text-gray-500">{{ $vendor->vendor_phone }}</div>
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
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                            <a href="{{ route('fin.vendors.show', $vendor->id) }}" class="text-blue-600 hover:text-blue-900 mr-3">View Ledger</a>
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
<div id="createVendorModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">➕ Add New Vendor</h2>
                <button onclick="closeCreateVendorModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            
            <form action="{{ route('fin.vendors.store') }}" method="POST">
                @csrf
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-red-500">*</span></label>
                        <input type="text" name="vendor_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="e.g., ABC Suppliers">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Contact Person</label>
                        <input type="text" name="contact_person"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="e.g., John Doe">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="contact_email"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="e.g., vendor@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="contact_phone"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500"
                               placeholder="e.g., +92 300 1234567">
                    </div>
                    
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-md">
                        <p class="text-xs text-blue-800">
                            ℹ️ <strong>System will automatically:</strong>
                        </p>
                        <ul class="text-xs text-blue-700 mt-1 ml-4 list-disc">
                            <li>Create a payable account for this vendor</li>
                            <li>Set account code (e.g., VEN_ABC_SUPPLIERS)</li>
                            <li>Configure as Liability account</li>
                        </ul>
                    </div>
                    
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeCreateVendorModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md"
                                style="background-color: #059669 !important; color: white !important;">
                            <span style="color: white !important;">✓ Create Vendor</span>
                        </button>
                    </div>
                </div>
            </form>
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
</script>

@endsection

