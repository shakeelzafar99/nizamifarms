{{-- resources/views/pages/requests/settings.blade.php --}}

@extends('layouts.app')

@section('title', 'Request Settings')

@section('content')

<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        
        <!-- Page Header -->
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold">Request Workflow Settings</h1>
                <p class="text-gray-600 mt-1">Configure approval levels and category requirements</p>
            </div>
            <a href="{{ route('requests.index') }}" class="kt-btn kt-btn-light">
                <i class="ki-filled ki-left"></i> Back to Requests
            </a>
        </div>

        <!-- Approval Level Assignments -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Approval Level Assignments</h3>
            </div>
            
            <div class="kt-card-body">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Level 1 Approvers -->
                    <div>
                        <h4 class="text-lg font-semibold mb-4 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-blue-500 text-white flex items-center justify-center">1</span>
                            Level 1 Approvers
                        </h4>
                        
                        <div class="mb-4">
                            <select id="level1-role-select" class="kt-select mb-2">
                                <option value="">Select role to add...</option>
                                @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->urole_name }}</option>
                                @endforeach
                            </select>
                            <button onclick="assignRoleToLevel(1)" class="kt-btn kt-btn-sm kt-btn-primary w-full">
                                <i class="ki-filled ki-plus"></i> Add to Level 1
                            </button>
                        </div>

                        <div class="space-y-2">
                            @forelse($level1Roles as $roleLevel)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <span class="font-medium">{{ $roleLevel->role->urole_name }}</span>
                                <button onclick="removeRoleFromLevel({{ $roleLevel->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-danger">
                                    <i class="ki-filled ki-trash"></i>
                                </button>
                            </div>
                            @empty
                            <div class="p-4 bg-blue-50 border border-blue-200 rounded">
                                <p class="text-sm text-blue-800 font-medium mb-2">👆 No Level 1 approvers assigned yet</p>
                                <p class="text-xs text-blue-600">Select a role above and click "Add to Level 1" to start. Level 1 approvers can give first-stage approval to requests.</p>
                            </div>
                            @endforelse
                        </div>
                    </div>

                    <!-- Level 2 Approvers -->
                    <div>
                        <h4 class="text-lg font-semibold mb-4 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-full bg-purple-500 text-white flex items-center justify-center">2</span>
                            Level 2 Approvers
                        </h4>
                        
                        <div class="mb-4">
                            <select id="level2-role-select" class="kt-select mb-2">
                                <option value="">Select role to add...</option>
                                @foreach($roles as $role)
                                <option value="{{ $role->id }}">{{ $role->urole_name }}</option>
                                @endforeach
                            </select>
                            <button onclick="assignRoleToLevel(2)" class="kt-btn kt-btn-sm kt-btn-primary w-full">
                                <i class="ki-filled ki-plus"></i> Add to Level 2
                            </button>
                        </div>

                        <div class="space-y-2">
                            @forelse($level2Roles as $roleLevel)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <span class="font-medium">{{ $roleLevel->role->urole_name }}</span>
                                <button onclick="removeRoleFromLevel({{ $roleLevel->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-danger">
                                    <i class="ki-filled ki-trash"></i>
                                </button>
                            </div>
                            @empty
                            <div class="p-4 bg-purple-50 border border-purple-200 rounded">
                                <p class="text-sm text-purple-800 font-medium mb-2">👆 No Level 2 approvers assigned yet</p>
                                <p class="text-xs text-purple-600">Level 2 is optional. Assign roles here for requests that need final approval after Level 1. If no roles assigned, only Level 1 approval is required.</p>
                            </div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="mt-6 p-4 bg-green-50 border border-green-200 rounded">
                    <p class="text-sm text-gray-700 mb-2">
                        <strong>✅ Quick Start Guide:</strong>
                    </p>
                    <ol class="text-xs text-gray-600 space-y-1 ml-4 list-decimal">
                        <li><strong>Assign Level 1 approvers</strong> - These roles can approve requests (e.g., Managers, Supervisors)</li>
                        <li><strong>Optionally assign Level 2</strong> - For requests needing final approval (e.g., Admins, Directors)</li>
                        <li><strong>Configure categories below</strong> - Choose which categories need one level vs two levels</li>
                        <li><strong>Done!</strong> Users can now submit and approve requests</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Category Configuration with Integrated Routing -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Category Approval Configuration & Routing</h3>
            </div>
            
            <div class="kt-card-body">
                <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm text-gray-700 mb-2">
                        <strong>📍 How Approval Routing Works:</strong>
                    </p>
                    <p class="text-xs text-gray-600">
                        Configure which users should approve each category. You can set specific assignees for L1 and L2, or leave empty to use role-based approval (any user with L1/L2 role can approve).
                    </p>
                </div>

                <div class="space-y-4">
                    @foreach($categories as $category)
                    <div class="border rounded-lg p-4 bg-white hover:bg-gray-50">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="font-semibold text-lg">{{ $category->category_name }}</h4>
                                <p class="text-sm text-gray-600">{{ $category->description }}</p>
                            </div>
                            <button onclick="toggleCategoryDetails({{ $category->id }})" class="kt-btn kt-btn-sm kt-btn-light">
                                <i class="ki-filled ki-down" id="icon-{{ $category->id }}"></i>
                            </button>
                        </div>
                        
                        <!-- Quick View -->
                        <div class="flex gap-4 text-sm">
                            <span class="flex items-center gap-1">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-category-id="{{ $category->id }}"
                                       data-field="requires_level_1"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_1 ? 'checked' : '' }}>
                                <label>Requires L1</label>
                            </span>
                            <span class="flex items-center gap-1">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-category-id="{{ $category->id }}"
                                       data-field="requires_level_2"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_2 ? 'checked' : '' }}>
                                <label>Requires L2</label>
                            </span>
                        </div>
                        
                        <!-- Detailed Configuration (Hidden by default) -->
                        <div id="details-{{ $category->id }}" class="hidden mt-4 pt-4 border-t">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <!-- Level 1 Routing -->
                                <div class="border rounded p-3 bg-blue-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs">L1</span>
                                        Level 1 Approvers
                                    </h5>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                            <select class="kt-select kt-select-sm w-full" 
                                                    data-category-id="{{ $category->id }}"
                                                    data-level="1">
                                                <option value="">Any L1 user (role-based)</option>
                                                @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                <option value="{{ $user->id }}">{{ $user->fullname ?? $user->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-600">Payment Source Filter (Optional)</label>
                                            <select class="kt-select kt-select-sm w-full"
                                                    data-category-id="{{ $category->id }}"
                                                    data-level="1"
                                                    data-filter="payment_source">
                                                <option value="">Any payment source</option>
                                                @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                <option value="{{ $account->id }}">{{ $account->account_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Level 2 Routing -->
                                <div class="border rounded p-3 bg-purple-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-purple-500 text-white flex items-center justify-center text-xs">L2</span>
                                        Level 2 Approvers
                                    </h5>
                                    <div class="space-y-2">
                                        <div>
                                            <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                            <select class="kt-select kt-select-sm w-full"
                                                    data-category-id="{{ $category->id }}"
                                                    data-level="2">
                                                <option value="">Any L2 user (role-based)</option>
                                                @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                <option value="{{ $user->id }}">{{ $user->fullname ?? $user->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-600">Payment Source Filter (Optional)</label>
                                            <select class="kt-select kt-select-sm w-full"
                                                    data-category-id="{{ $category->id }}"
                                                    data-level="2"
                                                    data-filter="payment_source">
                                                <option value="">Any payment source</option>
                                                @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                <option value="{{ $account->id }}">{{ $account->account_name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Settings -->
                            <div class="mt-3 pt-3 border-t">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs text-gray-600">Auto-Approve Threshold (Optional)</label>
                                        <input type="number" 
                                               class="kt-input kt-input-sm w-full" 
                                               data-category-id="{{ $category->id }}"
                                               data-field="auto_approve_threshold"
                                               value="{{ $category->approvalConfig ? $category->approvalConfig->auto_approve_threshold : '' }}"
                                               placeholder="e.g., 5000"
                                               step="0.01"
                                               min="0">
                                        <p class="text-xs text-gray-500 mt-1">Requests below this amount auto-approve</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 flex justify-end">
                                <button onclick="saveCategoryConfigAndRouting({{ $category->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-primary">
                                    <i class="ki-filled ki-check"></i> Save Configuration
                                </button>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>

                <div class="mt-6 p-4 bg-yellow-50 rounded">
                    <p class="text-sm text-gray-700">
                        <strong>💡 Configuration Tips:</strong><br>
                        • <strong>Role-based (default):</strong> Leave user assignments empty - any L1/L2 user can approve<br>
                        • <strong>User-specific:</strong> Assign specific users for dedicated approval paths<br>
                        • <strong>Payment source filters:</strong> Route based on which account the payment comes from<br>
                        • <strong>Auto-approve:</strong> Set threshold for automatic approval of small amounts
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle category details
function toggleCategoryDetails(categoryId) {
    const details = document.getElementById(`details-${categoryId}`);
    const icon = document.getElementById(`icon-${categoryId}`);
    
    if (details.classList.contains('hidden')) {
        details.classList.remove('hidden');
        icon.classList.remove('ki-down');
        icon.classList.add('ki-up');
    } else {
        details.classList.add('hidden');
        icon.classList.remove('ki-up');
        icon.classList.add('ki-down');
    }
}

// Save category configuration and routing
function saveCategoryConfigAndRouting(categoryId) {
    const detailsDiv = document.getElementById(`details-${categoryId}`);
    
    // Get basic config
    const requiresL1 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_1"]`).checked;
    const requiresL2 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_2"]`).checked;
    const threshold = document.querySelector(`input[data-category-id="${categoryId}"][data-field="auto_approve_threshold"]`).value;
    
    // Get routing config
    const l1User = detailsDiv.querySelector(`select[data-category-id="${categoryId}"][data-level="1"]:not([data-filter])`).value;
    const l1PaymentSource = detailsDiv.querySelector(`select[data-category-id="${categoryId}"][data-level="1"][data-filter="payment_source"]`).value;
    const l2User = detailsDiv.querySelector(`select[data-category-id="${categoryId}"][data-level="2"]:not([data-filter])`).value;
    const l2PaymentSource = detailsDiv.querySelector(`select[data-category-id="${categoryId}"][data-level="2"][data-filter="payment_source"]`).value;
    
    // First, save basic config
    fetch(`/requests/settings/categories/${categoryId}/config`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            requires_level_1: requiresL1,
            requires_level_2: requiresL2,
            auto_approve_threshold: threshold || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Now save routing rules if any users are assigned
            const promises = [];
            
            if (l1User) {
                promises.push(saveRoutingRule(categoryId, 1, l1User, l1PaymentSource));
            }
            
            if (l2User) {
                promises.push(saveRoutingRule(categoryId, 2, l2User, l2PaymentSource));
            }
            
            if (promises.length > 0) {
                Promise.all(promises)
                    .then(() => {
                        alert('Configuration and routing saved successfully!');
                    })
                    .catch(error => {
                        console.error('Error saving routing:', error);
                        alert('Configuration saved, but routing had errors. Check console.');
                    });
            } else {
                alert('Configuration saved successfully!');
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Helper function to save a routing rule
function saveRoutingRule(categoryId, level, userId, paymentSourceId) {
    // Get category code from the page
    const categoryName = document.querySelector(`#details-${categoryId}`).closest('[class*="border rounded-lg"]').querySelector('h4').textContent.trim();
    
    return fetch('/requests/settings/routing-rules', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            rule_name: `${categoryName} - L${level} Auto-Rule`,
            area_type: 'request_category',
            area_identifier: getCategoryCodeById(categoryId),
            approval_level: level,
            payment_source_account_id: paymentSourceId || null,
            payment_mode: null,
            min_amount: null,
            max_amount: null,
            priority: 100,
            assignees: [{
                user_id: parseInt(userId),
                is_primary: 1,
                sequence_order: 0
            }]
        })
    });
}

// Helper to get category code by ID
function getCategoryCodeById(categoryId) {
    // This is a simple mapping - you might want to pass this from PHP
    const categoryMap = @json($categories->pluck('category_code', 'id'));
    return categoryMap[categoryId];
}

function assignRoleToLevel(level) {
    const selectId = level === 1 ? 'level1-role-select' : 'level2-role-select';
    const roleId = document.getElementById(selectId).value;
    
    if (!roleId) {
        alert('Please select a role');
        return;
    }
    
    fetch('{{ route("requests.settings.roles.assign") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            role_id: roleId,
            approval_level: level
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Role assigned successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

function removeRoleFromLevel(id) {
    if (!confirm('Remove this role from the approval level?')) {
        return;
    }
    
    fetch(`/requests/settings/roles/level/${id}`, {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Role removed successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}

// Old function removed - now using saveCategoryConfigAndRouting
</script>

@endsection

