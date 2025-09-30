{{-- resources/views/pages/roles/index.blade.php --}}

@extends('layouts.app')

@section('title', 'Role Management')

@section('content')

@if(session('success'))
<div class="kt-container-fixed">
    <div class="kt-alert kt-alert-success mb-5" id="alert_1">
        <div class="kt-alert-title"><span>{{ session('success') }}</span></div>
        <div class="kt-alert-toolbar">
            <div class="kt-alert-actions">
                <button class="kt-alert-close" data-kt-dismiss="#alert_1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x" aria-hidden="true">
                        <path d="M18 6 6 18"></path>
                        <path d="m6 6 12 12"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

@if(session('error'))
<div class="kt-container-fixed">
    <div class="kt-alert kt-alert-danger mb-5" id="alert_error">
        <div class="kt-alert-title"><span>{{ session('error') }}</span></div>
        <div class="kt-alert-toolbar">
            <div class="kt-alert-actions">
                <button class="kt-alert-close" data-kt-dismiss="#alert_error">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x" aria-hidden="true">
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
            <!-- Header -->
            <div class="kt-card-header">
                <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center w-full gap-4">
                    <!-- Left: Title -->
                    <div class="flex items-center gap-4">
                        <h3 class="kt-card-title text-lg font-semibold">Role Management</h3>
                    </div>

                    <!-- Right: Add Role Button -->
                    <button onclick="openAddRoleModal()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-plus"></i> Add New Role
                    </button>
                </div>
            </div>

            <div class="kt-card-table">
                <div class="grid datatable-initialized" data-kt-datatable="true" data-kt-datatable-page-size="10" data-kt-datatable-initialized="true">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table text-xs kt-table-border" data-kt-datatable-table="true">
                            <thead class="bg-gray-100 font-bold">
                                <tr>
                                    <th class="w-[60px]">ID</th>
                                    <th class="min-w-[200px]">Role Name</th>
                                    <th class="w-[120px]">Type</th>
                                    <th class="min-w-[200px]">Description</th>
                                    <th class="w-[100px]">Users</th>
                                    <th class="w-[80px]">Status</th>
                                    <th class="w-[100px]">Created</th>
                                    <th class="w-[120px] text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                @foreach($roles as $role)
                                <tr>
                                    <td>{{ $role->id }}</td>
                                    <td class="font-medium">{{ $role->urole_name }}</td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-info">
                                            {{ ucwords(str_replace('_', ' ', $role->type)) }}
                                        </span>
                                    </td>
                                    <td>{{ $role->description ?: '-' }}</td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm kt-badge-primary">
                                            {{ $role->user_roles_count }} {{ $role->user_roles_count == 1 ? 'User' : 'Users' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($role->is_active)
                                            <span class="kt-badge kt-badge-sm kt-badge-success">Active</span>
                                        @else
                                            <span class="kt-badge kt-badge-sm kt-badge-danger">Inactive</span>
                                        @endif
                                    </td>
                                    <td>{{ $role->created_at ? $role->created_at->format('M d, Y') : '-' }}</td>
                                    <td class="text-center">
                                        <div class="flex gap-2 justify-center">
                                            <button onclick="viewRoleDetails({{ $role->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-primary" 
                                                    title="View Details">
                                                <i class="ki-filled ki-eye"></i>
                                            </button>
                                            <button onclick="editRoleDetails({{ $role->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-warning" 
                                                    title="Edit Role">
                                                <i class="ki-filled ki-pencil"></i>
                                            </button>
                                            <a href="{{ route('roles.permissions.manage', $role->id) }}" 
                                               class="kt-btn kt-btn-sm kt-btn-icon kt-btn-info" 
                                               title="Manage Permissions">
                                                <i class="ki-filled ki-setting-2"></i>
                                            </a>
                                            @if($role->user_roles_count == 0)
                                            <form action="{{ route('roles.destroy', $role->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this role?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="kt-btn kt-btn-sm kt-btn-icon kt-btn-danger" 
                                                        title="Delete Role">
                                                    <i class="ki-filled ki-trash"></i>
                                                </button>
                                            </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="kt-card-footer">
                    {{ $roles->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Role Modal -->
<div id="viewRoleModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Role Details</h3>
            <button onclick="closeModal('viewRoleModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewRoleContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Add/Edit Role Modal -->
<div id="roleFormModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="roleFormModalTitle" style="font-size: 18px; font-weight: 600; margin: 0;">Add New Role</h3>
            <button onclick="closeModal('roleFormModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 20px;">
            <form id="roleForm" method="POST">
                @csrf
                <input type="hidden" id="roleFormMethod" name="_method" value="">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Role Name *</label>
                    <input type="text" name="urole_name" id="urole_name" required 
                           style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Type *</label>
                        <select name="type" id="type" required 
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="">Select Type</option>
                            <option value="admin">Admin</option>
                            <option value="user">User</option>
                            <option value="manager">Manager</option>
                            <option value="rider">Rider</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Status</label>
                        <select name="is_active" id="is_active" 
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Description</label>
                    <textarea name="description" id="description" rows="4" 
                              style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('roleFormModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Save Role
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
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Add Role Modal
function openAddRoleModal() {
    const modal = document.getElementById('roleFormModal');
    const form = document.getElementById('roleForm');
    const title = document.getElementById('roleFormModalTitle');
    
    // Reset form
    form.reset();
    form.action = '{{ route("roles.store") }}';
    document.getElementById('roleFormMethod').value = '';
    
    // Update title
    title.textContent = 'Add New Role';
    
    modal.style.display = 'block';
}

// View Role Details
function viewRoleDetails(roleId) {
    console.log('View role details clicked for role:', roleId);
    const modal = document.getElementById('viewRoleModal');
    const content = document.getElementById('viewRoleContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch role details via AJAX
    fetch(`/roles/${roleId}`, {
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
            const role = data.role;
            console.log('Role data:', role);
            
            let usersHtml = '';
            if (role.user_roles && role.user_roles.length > 0) {
                usersHtml = role.user_roles.map(userRole => 
                    `<span style="display: inline-block; background-color: #e5e7eb; padding: 4px 8px; border-radius: 4px; font-size: 12px; margin: 2px;">${userRole.user ? userRole.user.fullname : 'Unknown User'}</span>`
                ).join(' ');
            } else {
                usersHtml = '<span style="color: #6b7280; font-style: italic;">No users assigned to this role</span>';
            }
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Role Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Role Name</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${role.urole_name || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Type</label>
                                <p style="margin: 4px 0 0 0;">${role.type ? role.type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Status</label>
                                <p style="margin: 4px 0 0 0;">
                                    <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; ${role.is_active ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fee2e2; color: #dc2626;'}">
                                        ${role.is_active ? 'Active' : 'Inactive'}
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">System Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Users Assigned</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${role.user_roles ? role.user_roles.length : 0}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Created</label>
                                <p style="margin: 4px 0 0 0;">${formatDate(role.created_at)}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${role.description ? `
                <div style="margin-top: 20px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Description</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <p style="margin: 0; color: #6b7280;">${role.description}</p>
                    </div>
                </div>
                ` : ''}
                
                <div style="margin-top: 20px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Assigned Users</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        ${usersHtml}
                    </div>
                </div>
            `;
            
            content.innerHTML = html;
        } else {
            content.innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div style="color: #ef4444; margin-bottom: 16px;">
                        <svg style="width: 48px; height: 48px; margin: 0 auto;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <h3 style="font-size: 18px; font-weight: 500; color: #111827; margin: 0 0 8px 0;">Error Loading Role</h3>
                    <p style="color: #6b7280; margin: 0;">${data.message || 'Unable to load role details'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching role details:', error);
        content.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <div style="color: #ef4444; margin-bottom: 16px;">
                    <svg style="width: 48px; height: 48px; margin: 0 auto;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 style="font-size: 18px; font-weight: 500; color: #111827; margin: 0 0 8px 0;">Network Error</h3>
                <p style="color: #6b7280; margin: 0;">Unable to connect to server. Please try again.</p>
            </div>
        `;
    });
}

// Edit Role Details
function editRoleDetails(roleId) {
    console.log('Edit role details clicked for role:', roleId);
    const modal = document.getElementById('roleFormModal');
    const form = document.getElementById('roleForm');
    const title = document.getElementById('roleFormModalTitle');
    
    // Update form for editing
    form.action = `/roles/${roleId}`;
    document.getElementById('roleFormMethod').value = 'PUT';
    title.textContent = 'Edit Role';
    
    // Fetch role data and populate form
    fetch(`/roles/${roleId}`, {
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
            const role = data.role;
            
            // Populate form fields
            document.getElementById('urole_name').value = role.urole_name || '';
            document.getElementById('type').value = role.type || '';
            document.getElementById('description').value = role.description || '';
            document.getElementById('is_active').value = role.is_active ? '1' : '0';
            
            modal.style.display = 'block';
        } else {
            alert('Error loading role data: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error fetching role for editing:', error);
        alert('Network error. Please try again.');
    });
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('viewRoleModal');
        closeModal('roleFormModal');
    }
});

// Debug: Log when script loads
console.log('Role management script loaded');
</script>
@endpush
