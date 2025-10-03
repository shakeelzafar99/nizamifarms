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

        <!-- Category Configuration -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Category Approval Configuration</h3>
            </div>
            
            <div class="kt-card-body">
                <table class="kt-table kt-table-border">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="w-[200px]">Category</th>
                            <th class="w-[120px] text-center">Requires Level 1</th>
                            <th class="w-[120px] text-center">Requires Level 2</th>
                            <th class="w-[150px]">Auto-Approve Threshold</th>
                            <th class="w-[100px] text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($categories as $category)
                        <tr data-category-id="{{ $category->id }}">
                            <td class="font-medium">{{ $category->category_name }}</td>
                            <td class="text-center">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-field="requires_level_1"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_1 ? 'checked' : '' }}>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" 
                                       class="kt-checkbox" 
                                       data-field="requires_level_2"
                                       {{ $category->approvalConfig && $category->approvalConfig->requires_level_2 ? 'checked' : '' }}>
                            </td>
                            <td>
                                <input type="number" 
                                       class="kt-input kt-input-sm" 
                                       data-field="auto_approve_threshold"
                                       value="{{ $category->approvalConfig ? $category->approvalConfig->auto_approve_threshold : '' }}"
                                       placeholder="Optional"
                                       step="0.01"
                                       min="0">
                            </td>
                            <td class="text-center">
                                <button onclick="saveCategoryConfig({{ $category->id }})" 
                                        class="kt-btn kt-btn-sm kt-btn-primary">
                                    <i class="ki-filled ki-check"></i> Save
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="mt-6 p-4 bg-yellow-50 rounded">
                    <p class="text-sm text-gray-700">
                        <strong>Configuration Tips:</strong><br>
                        • Check "Requires Level 1" for requests that need manager approval<br>
                        • Check both levels for high-value or critical requests<br>
                        • Set auto-approve threshold for amount-based requests (e.g., expenses under Rs. 5000)
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
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

function saveCategoryConfig(categoryId) {
    const row = document.querySelector(`[data-category-id="${categoryId}"]`);
    const requiresLevel1 = row.querySelector('[data-field="requires_level_1"]').checked;
    const requiresLevel2 = row.querySelector('[data-field="requires_level_2"]').checked;
    const threshold = row.querySelector('[data-field="auto_approve_threshold"]').value;
    
    fetch(`/requests/settings/categories/${categoryId}/config`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({
            requires_level_1: requiresLevel1,
            requires_level_2: requiresLevel2,
            auto_approve_threshold: threshold || null
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Configuration saved successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
}
</script>

@endsection

