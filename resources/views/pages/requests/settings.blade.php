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
                    @php
                        $categoryRouting = $routingRulesByCategory[$category->id] ?? [];
                        $l1Routing = $categoryRouting[1] ?? [];
                        $l2Routing = $categoryRouting[2] ?? [];

                        // Detect whether this category has request-based routing,
                        // ledger-based routing, or both (for display badges).
                        // 1) Look at actual rules (area_type).
                        $hasRequestRouting = false;
                        $hasLedgerRouting = false;

                        foreach ([$l1Routing, $l2Routing] as $levelRows) {
                            foreach ($levelRows as $row) {
                                if (isset($row['area_type'])) {
                                    if ($row['area_type'] === 'request_category') {
                                        $hasRequestRouting = true;
                                    } elseif ($row['area_type'] === 'ledger_transaction') {
                                        $hasLedgerRouting = true;
                                    }
                                }
                            }
                        }

                        // 2) Apply sensible defaults based on category code so the flow
                        //    badge is still meaningful even when there are no explicit rules.
                        $code = $category->category_code;

                        // Pure request-driven categories
                        if (in_array($code, ['leave', 'expense', 'salary_advance'])) {
                            $hasRequestRouting = true;
                        }

                        // Pure ledger-driven categories
                        if (in_array($code, ['employee_deposit', 'vendor_payment', 'account_transfer', 'invoice_approval', 'invoice_adjustment'])) {
                            $hasLedgerRouting = true;
                        }
                    @endphp
                    <div class="border rounded-lg p-4 bg-white hover:bg-gray-50">
                        <div class="flex items-start justify-between mb-3">
                            <div>
                                <h4 class="font-semibold text-lg">{{ $category->category_name }}</h4>
                                <p class="text-sm text-gray-600">{{ $category->description }}</p>

                                @if($hasRequestRouting || $hasLedgerRouting)
                                    <div class="mt-1 flex flex-wrap gap-1 text-xs">
                                        @if($hasRequestRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-green-100 text-green-800 border border-green-200" title="Approvals start as a request (e.g., Leave, Expense, Salary Advance)">
                                            Request flow
                                        </span>
                                        @endif
                                        @if($hasLedgerRouting)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-800 border border-indigo-200" title="Approvals happen directly on ledger entries (e.g., Employee Deposit, Vendor Payment, Account Transfer, Online Invoice)">
                                            Ledger flow
                                        </span>
                                        @endif
                                    </div>
                                @endif
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
                                    <div id="l1-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l1Rows = count($l1Routing) ? $l1Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l1Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="1">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L1 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 1)">
                                        <i class="ki-filled ki-plus"></i> Add L1 Rule
                                    </button>
                                </div>
                                
                                <!-- Level 2 Routing -->
                                <div class="border rounded p-3 bg-purple-50">
                                    <h5 class="font-medium mb-2 flex items-center gap-2">
                                        <span class="w-6 h-6 rounded-full bg-purple-500 text-white flex items-center justify-center text-xs">L2</span>
                                        Level 2 Approvers
                                    </h5>
                                    <div id="l2-rules-{{ $category->id }}" class="space-y-2">
                                        @php
                                            $l2Rows = count($l2Routing) ? $l2Routing : [['user_id' => null, 'payment_source_account_id' => null]];
                                        @endphp
                                        @foreach($l2Rows as $row)
                                        <div class="flex items-center gap-2 routing-row" data-level="2">
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Assign Specific User (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="user">
                                                    <option value="">Any L2 user (role-based)</option>
                                                    @foreach(\App\Models\SysAdmin\UserModel::where('is_active', 1)->orderBy('fullname')->get() as $user)
                                                    <option value="{{ $user->id }}"
                                                        {{ isset($row['user_id']) && (int)$row['user_id'] === (int)$user->id ? 'selected' : '' }}>
                                                        {{ $user->fullname ?? $user->name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="flex-1">
                                                <label class="text-xs text-gray-600">Payment Source (Optional)</label>
                                                <select class="kt-select kt-select-sm w-full" data-role="payment_source">
                                                    <option value="">Any payment source</option>
                                                    @foreach(\App\Models\FIN\AccountModel::where('is_active', 1)->whereIn('account_category', ['cash', 'bank'])->orderBy('account_name')->get() as $account)
                                                    <option value="{{ $account->id }}"
                                                        {{ isset($row['payment_source_account_id']) && (int)$row['payment_source_account_id'] === (int)$account->id ? 'selected' : '' }}>
                                                        {{ $account->account_name }}
                                                    </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button"
                                                    class="kt-btn kt-btn-sm kt-btn-danger mt-6"
                                                    onclick="removeRoutingRow(this)">
                                                <i class="ki-filled ki-trash"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                    <button type="button"
                                            class="kt-btn kt-btn-xs kt-btn-light mt-2"
                                            onclick="addRoutingRow({{ $category->id }}, 2)">
                                        <i class="ki-filled ki-plus"></i> Add L2 Rule
                                    </button>
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

// Add a new routing row for a given category and level (1 or 2)
function addRoutingRow(categoryId, level) {
    const container = document.getElementById(`l${level}-rules-${categoryId}`);
    if (!container) return;

    const firstRow = container.querySelector('.routing-row');
    let newRow;

    if (firstRow) {
        newRow = firstRow.cloneNode(true);
        // Reset selects in cloned row
        newRow.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        // Fallback: no existing row (should not normally happen)
        newRow = document.createElement('div');
        newRow.className = 'flex items-center gap-2 routing-row';
        newRow.dataset.level = String(level);
        newRow.innerHTML = '<span class="text-xs text-red-600">No template row found</span>';
    }

    container.appendChild(newRow);
}

// Remove a routing row (but keep at least one row for UX)
function removeRoutingRow(button) {
    const row = button.closest('.routing-row');
    if (!row) return;

    const container = row.parentElement;
    const rows = container.querySelectorAll('.routing-row');

    if (rows.length <= 1) {
        // Just clear selections instead of removing the last row
        row.querySelectorAll('select').forEach(sel => {
            sel.value = '';
        });
    } else {
        row.remove();
    }
}

// Save category configuration and routing
function saveCategoryConfigAndRouting(categoryId) {
    // Get basic config
    const requiresL1 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_1"]`).checked;
    const requiresL2 = document.querySelector(`input[data-category-id="${categoryId}"][data-field="requires_level_2"]`).checked;
    const threshold = document.querySelector(`input[data-category-id="${categoryId}"][data-field="auto_approve_threshold"]`).value;

    // Build routing rules array from UI
    const rules = [];
    [1, 2].forEach(level => {
        const container = document.getElementById(`l${level}-rules-${categoryId}`);
        if (!container) return;

        container.querySelectorAll('.routing-row').forEach(row => {
            const userSelect = row.querySelector('select[data-role="user"]');
            const accountSelect = row.querySelector('select[data-role="payment_source"]');

            if (!userSelect || !userSelect.value) {
                return; // Skip rows without user selected
            }

            const rule = {
                level: level,
                user_id: parseInt(userSelect.value, 10),
                payment_source_account_id: accountSelect && accountSelect.value
                    ? parseInt(accountSelect.value, 10)
                    : null
            };

            rules.push(rule);
        });
    });

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
        if (!data.success) {
            alert('Error: ' + data.message);
            return;
        }

        // Now save routing rules (this will also clear any existing rules for this category)
        fetch(`/requests/settings/categories/${categoryId}/routing`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ rules })
        })
        .then(response => response.json())
        .then(routingData => {
            if (routingData.success) {
                alert('Configuration and routing saved successfully!');
            } else {
                alert('Configuration saved, but routing failed: ' + routingData.message);
            }
        })
        .catch(error => {
            console.error('Error saving routing rules:', error);
            alert('Configuration saved, but routing had errors. Check console.');
        });
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
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

