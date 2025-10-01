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

                    <!-- Right: Buttons -->
                    <div class="flex gap-2">
                        <button onclick="openBulkImportModal()" class="kt-btn kt-btn-light">
                            <i class="ki-filled ki-file-up"></i> Bulk Import
                        </button>
                        <button onclick="openAddUserModal()" class="kt-btn kt-btn-primary">
                            <i class="ki-filled ki-plus"></i> Add New User
                        </button>
                    </div>
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
                
                <!-- Display validation errors -->
                @if ($errors->any())
                    <div style="background-color: #fef2f2; border: 1px solid #fecaca; color: #dc2626; padding: 12px; border-radius: 6px; margin-bottom: 20px;">
                        <strong>Please fix the following errors:</strong>
                        <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                
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
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Password <span id="passwordRequired">*</span> <span style="color: #6b7280; font-weight: normal;">(min 6 characters)</span></label>
                        <input type="password" name="password" id="password" minlength="6"
                               style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;"
                               oninput="validatePassword(this)">
                        <div id="passwordError" style="color: #dc2626; font-size: 12px; margin-top: 4px; display: none;"></div>
                        <small style="color: #6b7280; font-size: 12px;" id="passwordHelp">Leave blank to keep current password (when editing)</small>
                    </div>
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Role *</label>
                        <select name="role_id" id="role_id" required
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="">Select Role</option>
                            @if(isset($roles) && count($roles) > 0)
                                @foreach($roles as $role)
                                    <option value="{{ $role->id }}">{{ $role->urole_name }} ({{ ucfirst($role->type) }})</option>
                                @endforeach
                            @else
                                <option value="" disabled>No roles available</option>
                            @endif
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">Status</label>
                        <select name="is_active" id="is_active" 
                                style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div>
                        <!-- Empty space for layout balance -->
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

<!-- Bulk Import Modal -->
<div id="bulkImportModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <!-- Modal Header -->
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 18px; font-weight: 600; margin: 0;">Bulk Import Users</h3>
            <button onclick="closeModal('bulkImportModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280; padding: 5px;">&times;</button>
        </div>
        
        <!-- Modal Body -->
        <div style="padding: 20px;">
            <!-- Instructions -->
            <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 16px; margin-bottom: 20px;">
                <h4 style="font-size: 14px; font-weight: 600; color: #1e40af; margin: 0 0 8px 0;">📝 Instructions:</h4>
                <ul style="margin: 0; padding-left: 20px; color: #1e40af; font-size: 13px; line-height: 1.6;">
                    <li>Enter one name per line</li>
                    <li>Email will be auto-generated: <code style="background: white; padding: 2px 4px; border-radius: 3px;">firstname.lastname@nizamifarms.com</code></li>
                    <li>Default password will be: <code style="background: white; padding: 2px 4px; border-radius: 3px;">nf123456</code></li>
                    <li>Users will be prompted to change password on first login</li>
                    <li>Duplicate emails will be automatically skipped</li>
                </ul>
            </div>

            <form id="bulkImportForm" onsubmit="handleBulkImport(event)">
                @csrf
                
                <!-- Role Selection -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        Select Role <span style="color: #dc2626;">*</span>
                    </label>
                    <select name="role_id" id="bulkRoleId" required
                            style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background-color: white;">
                        <option value="">-- Select Role --</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}" {{ $role->urole_name == 'rider' ? 'selected' : '' }}>
                                {{ $role->urole_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <!-- Names Textarea -->
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 8px;">
                        User Names <span style="color: #dc2626;">*</span>
                        <span style="font-size: 12px; color: #6b7280; font-weight: 400;">(One name per line)</span>
                    </label>
                    <textarea name="names" id="bulkNames" rows="10" required placeholder="Arsalan&#10;Asim Tahir&#10;Haider&#10;Jazib&#10;Waseem"
                              style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: monospace; resize: vertical;"></textarea>
                    <p style="margin: 4px 0 0 0; font-size: 12px; color: #6b7280;">
                        Count: <span id="nameCount" style="font-weight: 600;">0</span> names
                    </p>
                </div>

                <!-- Results Section (Hidden initially) -->
                <div id="bulkResults" style="display: none; margin-bottom: 20px;"></div>

                <!-- Buttons -->
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="button" onclick="closeModal('bulkImportModal')" 
                            style="padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; color: #374151; background-color: white; cursor: pointer; font-size: 14px;">
                        Cancel
                    </button>
                    <button type="submit" id="bulkImportBtn"
                            style="padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">
                        <i class="ki-filled ki-file-up"></i> Import Users
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

// Password validation function
function validatePassword(input) {
    const errorDiv = document.getElementById('passwordError');
    const password = input.value;
    
    if (password.length > 0 && password.length < 6) {
        errorDiv.textContent = 'Password must be at least 6 characters long';
        errorDiv.style.display = 'block';
        input.style.borderColor = '#dc2626';
    } else {
        errorDiv.style.display = 'none';
        input.style.borderColor = '#d1d5db';
    }
}

// Add User Modal Functions
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

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Bulk Import Functions
function openBulkImportModal() {
    const modal = document.getElementById('bulkImportModal');
    const form = document.getElementById('bulkImportForm');
    const results = document.getElementById('bulkResults');
    
    // Reset form and results
    form.reset();
    results.style.display = 'none';
    results.innerHTML = '';
    updateNameCount();
    
    modal.style.display = 'block';
}

// Update name count as user types
document.addEventListener('DOMContentLoaded', function() {
    const bulkNames = document.getElementById('bulkNames');
    if (bulkNames) {
        bulkNames.addEventListener('input', updateNameCount);
    }
});

function updateNameCount() {
    const textarea = document.getElementById('bulkNames');
    const countSpan = document.getElementById('nameCount');
    if (!textarea || !countSpan) return;
    
    const lines = textarea.value.split('\n').filter(line => line.trim() !== '');
    countSpan.textContent = lines.length;
}

async function handleBulkImport(event) {
    event.preventDefault();
    
    const form = document.getElementById('bulkImportForm');
    const btn = document.getElementById('bulkImportBtn');
    const results = document.getElementById('bulkResults');
    
    // Disable button and show loading
    btn.disabled = true;
    btn.innerHTML = '<span style="display: inline-block; width: 16px; height: 16px; border: 2px solid white; border-top: 2px solid transparent; border-radius: 50%; animation: spin 0.6s linear infinite;"></span> Processing...';
    
    try {
        const formData = new FormData(form);
        
        const response = await fetch('/users/bulk', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            displayBulkResults(data);
            
            // If all successful, reload page after 2 seconds
            if (data.summary.errors === 0 && data.summary.skipped === 0) {
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            }
        } else {
            alert('Error: ' + (data.message || 'Failed to import users'));
        }
        
    } catch (error) {
        console.error('Error:', error);
        alert('Error processing bulk import');
    } finally {
        // Re-enable button
        btn.disabled = false;
        btn.innerHTML = '<i class="ki-filled ki-file-up"></i> Import Users';
    }
}

function displayBulkResults(data) {
    const results = document.getElementById('bulkResults');
    const summary = data.summary;
    
    let html = '<div style="border-radius: 6px; overflow: hidden;">';
    
    // Summary
    html += `<div style="background-color: #f9fafb; padding: 16px; border-bottom: 2px solid #e5e7eb;">
        <h4 style="font-size: 16px; font-weight: 600; margin: 0 0 12px 0;">Import Summary</h4>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
            <div style="text-align: center; padding: 8px; background: white; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: 700; color: #6b7280;">${summary.total}</div>
                <div style="font-size: 12px; color: #6b7280;">Total</div>
            </div>
            <div style="text-align: center; padding: 8px; background: #dcfce7; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: 700; color: #166534;">${summary.created}</div>
                <div style="font-size: 12px; color: #166534;">Created</div>
            </div>
            <div style="text-align: center; padding: 8px; background: #fef3c7; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: 700; color: #92400e;">${summary.skipped}</div>
                <div style="font-size: 12px; color: #92400e;">Skipped</div>
            </div>
            <div style="text-align: center; padding: 8px; background: #fee2e2; border-radius: 6px;">
                <div style="font-size: 24px; font-weight: 700; color: #dc2626;">${summary.errors}</div>
                <div style="font-size: 12px; color: #dc2626;">Errors</div>
            </div>
        </div>
    </div>`;
    
    // Created users
    if (data.created && data.created.length > 0) {
        html += `<div style="padding: 16px; background: white; border-bottom: 1px solid #e5e7eb;">
            <h5 style="font-size: 14px; font-weight: 600; color: #166534; margin: 0 0 8px 0;">✓ Successfully Created (${data.created.length})</h5>
            <div style="max-height: 150px; overflow-y: auto;">`;
        data.created.forEach(user => {
            html += `<div style="padding: 6px 8px; margin-bottom: 4px; background: #f0fdf4; border-radius: 4px; font-size: 13px;">
                <strong>${user.name}</strong> → ${user.email}
            </div>`;
        });
        html += `</div></div>`;
    }
    
    // Skipped users
    if (data.skipped && data.skipped.length > 0) {
        html += `<div style="padding: 16px; background: #fffbeb; border-bottom: 1px solid #e5e7eb;">
            <h5 style="font-size: 14px; font-weight: 600; color: #92400e; margin: 0 0 8px 0;">⚠ Skipped (${data.skipped.length})</h5>
            <div style="max-height: 150px; overflow-y: auto;">`;
        data.skipped.forEach(item => {
            html += `<div style="padding: 6px 8px; margin-bottom: 4px; background: white; border-radius: 4px; font-size: 13px;">
                <strong>${item.name}</strong><br>
                <span style="color: #92400e; font-size: 12px;">${item.reason}</span>
            </div>`;
        });
        html += `</div></div>`;
    }
    
    // Errors
    if (data.errors && data.errors.length > 0) {
        html += `<div style="padding: 16px; background: #fef2f2;">
            <h5 style="font-size: 14px; font-weight: 600; color: #dc2626; margin: 0 0 8px 0;">✕ Errors (${data.errors.length})</h5>
            <div style="max-height: 150px; overflow-y: auto;">`;
        data.errors.forEach(item => {
            html += `<div style="padding: 6px 8px; margin-bottom: 4px; background: white; border-radius: 4px; font-size: 13px;">
                <strong>${item.name}</strong><br>
                <span style="color: #dc2626; font-size: 12px;">${item.error}</span>
            </div>`;
        });
        html += `</div></div>`;
    }
    
    html += '</div>';
    
    results.innerHTML = html;
    results.style.display = 'block';
}

</script>
@endpush
