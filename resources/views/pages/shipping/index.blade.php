@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="flex flex-col gap-5 lg:gap-7.5">
        <!-- Page Header -->
        <div class="flex flex-wrap items-center justify-between gap-5 pb-7.5">
            <div class="flex flex-col justify-center gap-2">
                <h1 class="text-xl font-semibold leading-none text-gray-900">
                    Shipping Configuration
                </h1>
                <div class="flex items-center gap-2 text-sm font-medium text-gray-600">
                    Set default shipping price for manual orders
                </div>
            </div>
        </div>

        <!-- Shipping Configuration Card -->
        <div class="card">
            <div class="card-body">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Configuration Form -->
                    <div>
                        <form id="shippingForm">
                            @csrf
                            <div class="mb-6">
                                <label for="shipping_price" class="form-label text-gray-900 text-sm font-medium mb-2">
                                    Default Shipping Price
                                </label>
                                <div class="flex items-center gap-2">
                                    <span class="text-gray-700 font-medium">PKR</span>
                                    <input type="number" 
                                           class="form-control flex-1" 
                                           id="shipping_price" 
                                           name="shipping_price" 
                                           value="{{ $shippingPrice }}" 
                                           step="0.01" 
                                           min="0" 
                                           placeholder="0.00"
                                           required>
                                </div>
                                <div class="text-xs text-gray-600 mt-1">
                                    This will be the default shipping cost for new manual orders
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="ki-filled ki-check"></i>
                                Save Changes
                            </button>
                        </form>
                    </div>
                    
                    <!-- Information Panel -->
                    <div class="bg-gray-50 rounded-lg p-6">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">
                            <i class="ki-filled ki-information-2 text-blue-600 mr-2"></i>
                            How it works
                        </h3>
                        <div class="space-y-2 text-xs text-gray-600">
                            <div class="flex items-start gap-2">
                                <i class="ki-filled ki-check-circle text-green-500 mt-0.5 text-xs"></i>
                                <span>Automatically applied to new manual orders</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <i class="ki-filled ki-check-circle text-green-500 mt-0.5 text-xs"></i>
                                <span>Can be modified when creating individual orders</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <i class="ki-filled ki-check-circle text-green-500 mt-0.5 text-xs"></i>
                                <span>API orders use their own shipping values</span>
                            </div>
                            <div class="flex items-start gap-2">
                                <i class="ki-filled ki-check-circle text-green-500 mt-0.5 text-xs"></i>
                                <span>Setting persists across all sessions</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const shippingForm = document.getElementById('shippingForm');
    
    shippingForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(shippingForm);
        const submitBtn = shippingForm.querySelector('button[type="submit"]');
        
        // Show loading state
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="ki-filled ki-loading"></i> Updating...';
        
        fetch('/shipping/update', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                showSuccessMessage('Shipping price updated successfully!');
            } else {
                showErrorMessage(data.message || 'Failed to update shipping price');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showErrorMessage('An error occurred while updating shipping price');
        })
        .finally(() => {
            // Reset button state
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="ki-filled ki-check"></i> Update Shipping Price';
        });
    });
});

function showSuccessMessage(message) {
    // Create and show success toast/alert
    const alert = document.createElement('div');
    alert.className = 'alert alert-success alert-dismissible fade show';
    alert.innerHTML = `
        <i class="ki-filled ki-check-circle"></i> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insert at top of card body
    const cardBody = document.querySelector('.card-body');
    cardBody.insertBefore(alert, cardBody.firstChild);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 3000);
}

function showErrorMessage(message) {
    // Create and show error toast/alert
    const alert = document.createElement('div');
    alert.className = 'alert alert-danger alert-dismissible fade show';
    alert.innerHTML = `
        <i class="ki-filled ki-cross-circle"></i> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insert at top of card body
    const cardBody = document.querySelector('.card-body');
    cardBody.insertBefore(alert, cardBody.firstChild);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (alert.parentNode) {
            alert.remove();
        }
    }, 5000);
}
</script>
@endsection
