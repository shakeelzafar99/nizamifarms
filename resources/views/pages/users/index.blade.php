{{-- resources/views/pages/users/index.blade.php --}}

@extends('layouts.app')

@section('title', 'User Management')

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
                        <h3 class="kt-card-title text-lg font-semibold">User Management</h3>
                    </div>

                    <!-- Right: Add User Button -->
                    <button onclick="openAddUserModal()" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-plus"></i> Add New User
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
                                    <th class="min-w-[200px]">Full Name</th>
                                    <th class="min-w-[150px]">Email</th>
                                    <th class="w-[100px]">User Type</th>
                                    <th class="w-[120px]">Role</th>
                                    <th class="w-[80px]">Status</th>
                                    <th class="w-[100px]">Created</th>
                                    <th class="w-[120px] text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                @foreach($users as $user)
                                <tr>
                                    <td>{{ $user->id }}</td>
                                    <td class="font-medium">{{ $user->fullname }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm kt-badge-outline kt-badge-primary">
                                            {{ ucwords(str_replace('_', ' ', $user->user_type)) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($user->userRoles && $user->userRoles->count() > 0)
                                            <span class="kt-badge kt-badge-sm kt-badge-success">
                                                {{ $user->userRoles->first()->role->urole_name ?? 'No Role' }}
                                            </span>
                                        @else
                                            <span class="kt-badge kt-badge-sm kt-badge-gray">No Role</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($user->is_active)
                                            <span class="kt-badge kt-badge-sm kt-badge-success">Active</span>
                                        @else
                                            <span class="kt-badge kt-badge-sm kt-badge-danger">Inactive</span>
                                        @endif
                                    </td>
                                    <td>{{ $user->created_at ? $user->created_at->format('M d, Y') : '-' }}</td>
                                    <td class="text-center">
                                        <div class="flex gap-2 justify-center">
                                            <button onclick="viewUserDetails({{ $user->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-primary" 
                                                    title="View Details">
                                                <i class="ki-filled ki-eye"></i>
                                            </button>
                                            <button onclick="editUserDetails({{ $user->id }})" 
                                                    class="kt-btn kt-btn-sm kt-btn-icon kt-btn-warning" 
                                                    title="Edit User">
                                                <i class="ki-filled ki-pencil"></i>
                                            </button>
                                            @if($user->id != auth()->id())
                                            <form action="{{ route('users.destroy', $user->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="kt-btn kt-btn-sm kt-btn-icon kt-btn-danger" 
                                                        title="Delete User">
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
                    {{ $users->links() }}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View User Modal -->
<div id="viewUserModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">User Details</h3>
            <button onclick="closeModal('viewUserModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div id="viewUserContent" style="padding: 20px;">
            <!-- Content will be loaded here -->
        </div>
    </div>
</div>

<!-- Add/Edit User Modal -->
<div id="userFormModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="userFormModalTitle" style="font-size: 18px; font-weight: 600; margin: 0;">Add New User</h3>
            <button onclick="closeModal('userFormModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 20px;">
            <form id="userForm" method="POST">
                @csrf
                <input type="hidden" id="userFormMethod" name="_method" value="">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Full Name *</label>
                        <input type="text" name="fullname" id="fullname" required 
                               style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Email *</label>
                        <input type="email" name="email" id="email" required 
                               style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Password <span id="passwordRequired">*</span></label>
                        <input type="password" name="password" id="password" 
                               style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                        <small style="color: #6b7280; font-size: 12px;" id="passwordHelp">Leave blank to keep current password (when editing)</small>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">User Type *</label>
                        <select name="user_type" id="user_type" required 
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="">Select User Type</option>
                            <option value="admin">Admin</option>
                            <option value="branch_user">Branch User</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Role <span style="color: #6b7280; font-weight: normal;">(Optional)</span></label>
                        <select name="role_id" id="role_id" 
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="">Select Role</option>
                            @if(isset($roles) && count($roles) > 0)
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->urole_name }}</option>
                                @endforeach
                            @else
                                <option value="" disabled>No roles available ({{ isset($roles) ? count($roles) : 'roles not set' }} roles found)</option>
                            @endif
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
                    <textarea name="description" id="description" rows="3" 
                              style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('userFormModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" 
                            style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        Save User
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

// Add User Modal
function openAddUserModal() {
    const modal = document.getElementById('userFormModal');
    const form = document.getElementById('userForm');
    const title = document.getElementById('userFormModalTitle');
    
    // Reset form
    form.reset();
    form.action = '{{ route("users.store") }}';
    document.getElementById('userFormMethod').value = '';
    
    // Update title and password field
    title.textContent = 'Add New User';
    document.getElementById('password').required = true;
    document.getElementById('passwordRequired').textContent = '*';
    document.getElementById('passwordHelp').style.display = 'none';
    
    modal.style.display = 'block';
}

// View User Details
function viewUserDetails(userId) {
    console.log('View user details clicked for user:', userId);
    const modal = document.getElementById('viewUserModal');
    const content = document.getElementById('viewUserContent');
    
    // Show loading
    content.innerHTML = '<div style="text-align: center; padding: 40px;"><div style="display: inline-block; width: 32px; height: 32px; border: 3px solid #e5e7eb; border-top: 3px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div></div>';
    modal.style.display = 'block';
    
    // Fetch user details via AJAX
    fetch(`/users/${userId}`, {
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
            const user = data.user;
            console.log('User data:', user);
            
            let roleInfo = 'No Role Assigned';
            if (user.user_roles && user.user_roles.length > 0 && user.user_roles[0].role) {
                roleInfo = user.user_roles[0].role.urole_name;
            }
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">Personal Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Full Name</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${user.fullname || 'N/A'}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Email</label>
                                <p style="margin: 4px 0 0 0;">${user.email || 'N/A'}</p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">User Type</label>
                                <p style="margin: 4px 0 0 0;">${user.user_type ? user.user_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) : 'N/A'}</p>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <h4 style="font-weight: 600; color: #374151; margin: 0 0 16px 0;">System Information</h4>
                        <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Role</label>
                                <p style="margin: 4px 0 0 0; font-weight: 500;">${roleInfo}</p>
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Status</label>
                                <p style="margin: 4px 0 0 0;">
                                    <span style="display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 500; ${user.is_active ? 'background-color: #dcfce7; color: #166534;' : 'background-color: #fee2e2; color: #dc2626;'}">
                                        ${user.is_active ? 'Active' : 'Inactive'}
                                    </span>
                                </p>
                            </div>
                            <div>
                                <label style="font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 500;">Created</label>
                                <p style="margin: 4px 0 0 0;">${formatDate(user.created_at)}</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${user.description ? `
                <div style="margin-top: 20px;">
                    <h4 style="font-weight: 600; color: #374151; margin: 0 0 12px 0;">Description</h4>
                    <div style="background-color: #f9fafb; padding: 16px; border-radius: 8px;">
                        <p style="margin: 0; color: #6b7280;">${user.description}</p>
                    </div>
                </div>
                ` : ''}
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
                    <h3 style="font-size: 18px; font-weight: 500; color: #111827; margin: 0 0 8px 0;">Error Loading User</h3>
                    <p style="color: #6b7280; margin: 0;">${data.message || 'Unable to load user details'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error fetching user details:', error);
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

// Edit User Details
function editUserDetails(userId) {
    console.log('Edit user details clicked for user:', userId);
    const modal = document.getElementById('userFormModal');
    const form = document.getElementById('userForm');
    const title = document.getElementById('userFormModalTitle');
    
    // Update form for editing
    form.action = `/users/${userId}`;
    document.getElementById('userFormMethod').value = 'PUT';
    title.textContent = 'Edit User';
    document.getElementById('password').required = false;
    document.getElementById('passwordRequired').textContent = '';
    document.getElementById('passwordHelp').style.display = 'block';
    
    // Fetch user data and populate form
    fetch(`/users/${userId}`, {
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
            const user = data.user;
            
            // Populate form fields
            document.getElementById('fullname').value = user.fullname || '';
            document.getElementById('email').value = user.email || '';
            document.getElementById('password').value = '';
            document.getElementById('user_type').value = user.user_type || '';
            document.getElementById('description').value = user.description || '';
            document.getElementById('is_active').value = user.is_active ? '1' : '0';
            
            // Set role if exists
            if (user.user_roles && user.user_roles.length > 0) {
                document.getElementById('role_id').value = user.user_roles[0].role_id || '';
            }
            
            modal.style.display = 'block';
        } else {
            alert('Error loading user data: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error fetching user for editing:', error);
        alert('Network error. Please try again.');
    });
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal('viewUserModal');
        closeModal('userFormModal');
    }
});

// Debug: Log when script loads
console.log('User management script loaded');
</script>
@endpush
