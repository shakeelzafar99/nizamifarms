@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">{{ $vendor->vendor_name }} - Products</h1>
            <p class="text-sm text-gray-600 mt-1">Manage vendor-specific products for weighted purchases</p>
        </div>
        <a href="{{ route('fin.vendors.show', $vendor->id) }}" 
           class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
            ← Back to Vendor
        </a>
    </div>

    <!-- Success/Error Messages -->
    <div id="messageContainer"></div>

    <!-- Add Product Form -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
        <h2 class="text-lg font-medium text-gray-900 mb-4">➕ Add New Product</h2>
        <form id="addProductForm" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                <input type="text" id="product_name" required placeholder="e.g., Chicken Breast"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                <select id="unit" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">-- Select Unit --</option>
                    <option value="kg">Kilogram (kg)</option>
                    <option value="liter">Liter</option>
                    <option value="piece">Piece</option>
                    <option value="dozen">Dozen</option>
                    <option value="pack">Pack</option>
                    <option value="box">Box</option>
                    <option value="ton">Ton</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                <input type="number" id="rate_per_unit" step="0.01" min="0.01" required placeholder="0.00"
                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <button type="submit" 
                        class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md"
                        style="background-color: #2563eb !important; color: white !important;">
                    <span style="color: white !important;">✓ Add Product</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Products List -->
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-medium text-gray-900">Product Catalog ({{ count($products) }} items)</h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200" id="productsTable">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Product Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Rate per Unit</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="productsTableBody">
                    @forelse($products as $product)
                        <tr class="hover:bg-gray-50" data-product-id="{{ $product->id }}">
                            <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $product->product_name }}</td>
                            <td class="px-6 py-4 text-sm text-gray-600">{{ $product->unit }}</td>
                            <td class="px-6 py-4 text-sm text-gray-900 text-right font-semibold">Rs. {{ number_format($product->rate_per_unit, 2) }}</td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-xs font-semibold rounded-full status-badge-{{ $product->id }}
                                    {{ $product->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex justify-center gap-2">
                                    <button onclick='editProduct({{ $product->id }}, {{ json_encode($product->product_name) }}, "{{ $product->unit }}", {{ $product->rate_per_unit }})'
                                            class="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded-md hover:bg-blue-200">
                                        ✏️ Edit
                                    </button>
                                    <button onclick="toggleStatus({{ $product->id }}, {{ $product->is_active ? 'true' : 'false' }})"
                                            class="px-3 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-md hover:bg-yellow-200">
                                        {{ $product->is_active ? '🔒 Disable' : '✅ Enable' }}
                                    </button>
                                    <button onclick="deleteProduct({{ $product->id }})"
                                            class="px-3 py-1 text-xs bg-red-100 text-red-700 rounded-md hover:bg-red-200">
                                        🗑️ Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                                No products added yet. Add your first product above.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div id="editModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">✏️ Edit Product</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form id="editProductForm">
                <input type="hidden" id="edit_product_id">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Product Name <span class="text-red-500">*</span></label>
                        <input type="text" id="edit_product_name" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Unit <span class="text-red-500">*</span></label>
                        <select id="edit_unit" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="kg">Kilogram (kg)</option>
                            <option value="liter">Liter</option>
                            <option value="piece">Piece</option>
                            <option value="dozen">Dozen</option>
                            <option value="pack">Pack</option>
                            <option value="box">Box</option>
                            <option value="ton">Ton</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Rate per Unit (Rs.) <span class="text-red-500">*</span></label>
                        <input type="number" id="edit_rate_per_unit" step="0.01" min="0.01" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="flex gap-3 mt-6">
                        <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-2 border border-gray-300 text-gray-700 font-medium rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md"
                                style="background-color: #2563eb !important; color: white !important;">
                            <span style="color: white !important;">✓ Update Product</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const vendorId = {{ $vendor->id }};

// Add Product
document.getElementById('addProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = {
        product_name: document.getElementById('product_name').value,
        unit: document.getElementById('unit').value,
        rate_per_unit: document.getElementById('rate_per_unit').value
    };
    
    fetch(`/finance/vendors/${vendorId}/products`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            document.getElementById('addProductForm').reset();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error adding product');
        console.error('Error:', error);
    });
});

// Edit Product
function editProduct(id, name, unit, rate) {
    document.getElementById('edit_product_id').value = id;
    document.getElementById('edit_product_name').value = name;
    document.getElementById('edit_unit').value = unit;
    document.getElementById('edit_rate_per_unit').value = rate;
    
    const modal = document.getElementById('editModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
}

function closeEditModal() {
    const modal = document.getElementById('editModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
}

document.getElementById('editProductForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const productId = document.getElementById('edit_product_id').value;
    const formData = {
        product_name: document.getElementById('edit_product_name').value,
        unit: document.getElementById('edit_unit').value,
        rate_per_unit: document.getElementById('edit_rate_per_unit').value
    };
    
    fetch(`/finance/vendors/${vendorId}/products/${productId}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            closeEditModal();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating product');
        console.error('Error:', error);
    });
});

// Toggle Status
function toggleStatus(id, currentStatus) {
    if (!confirm(`Are you sure you want to ${currentStatus ? 'disable' : 'enable'} this product?`)) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}/toggle`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error updating status');
        console.error('Error:', error);
    });
}

// Delete Product
function deleteProduct(id) {
    if (!confirm('Are you sure you want to delete this product? If it has purchase history, it will be deactivated instead.')) {
        return;
    }
    
    fetch(`/finance/vendors/${vendorId}/products/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showMessage('success', data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showMessage('error', data.message);
        }
    })
    .catch(error => {
        showMessage('error', 'Error deleting product');
        console.error('Error:', error);
    });
}

// Show Message
function showMessage(type, message) {
    const container = document.getElementById('messageContainer');
    const bgColor = type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800';
    
    container.innerHTML = `
        <div class="mb-4 p-4 ${bgColor} border rounded-md">
            <p class="text-sm">${message}</p>
        </div>
    `;
    
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>

@endsection

