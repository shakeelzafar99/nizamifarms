# Blade View Templates for Approval Workflow

## Directory Structure

**YOU NEED TO CREATE THIS DIRECTORY MANUALLY:**
```
resources/views/pages/requests/
```

Then create these files in that directory:
1. `index.blade.php` - Main requests listing page
2. `create.blade.php` - Create new request form  
3. `show.blade.php` - View request details
4. `settings.blade.php` - Admin settings page

## 1. index.blade.php - Main Requests Page

This page shows:
- Three tabs: "My Requests", "Pending My Approval", "All Requests"
- Filters by status, category, date range
- Table with request list
- Status badges
- Action buttons

### Key Features:
- Tab-based navigation
- Real-time data loading via AJAX
- Filter dropdowns
- Responsive table
- Status color coding

### Data Loading:
```javascript
// Loads data from: GET /requests/data?view={my|pending_approval|all}
// Filters: status, category_id, date_from, date_to
```

## 2. create.blade.php - Create Request Form

This page shows:
- Category selection
- Dynamic form fields based on category
- For leave requests: date picker, leave type
- For advance/expense: amount field
- Priority selection
- Description text area

### Leave Request Fields:
- Leave Start Date
- Leave End Date  
- Leave Type (sick, annual, casual, emergency)
- Reason/Description

### Form Submission:
```javascript
// POST /requests
// Redirects to: /requests/{id} on success
```

## 3. show.blade.php - View Request Details

This page shows:
- Request information card
- Requester details
- Category and status badges
- Leave dates (if applicable)
- Amount (if applicable)
- Timeline of approvals
- Approval/Reject buttons (if user can approve)

### Approval Actions:
```javascript
// Approve: POST /requests/{id}/approve with level and comments
// Reject: POST /requests/{id}/reject with level and comments
```

### Status Display:
- **Pending** - Orange/Yellow badge
- **Approved** - Green badge
- **Rejected** - Red badge
- **Cancelled** - Gray badge

## 4. settings.blade.php - Admin Settings

This page shows:
- Two main sections:
  1. Approval Level Assignments
  2. Category Configuration

### Section 1: Approval Level Assignments
- List of roles assigned to Level 1
- List of roles assigned to Level 2
- Add/Remove role buttons
- View users with each level

### Section 2: Category Configuration
- Table of all categories
- Toggle Level 1 required
- Toggle Level 2 required
- Auto-approve threshold (optional)
- Edit category details

### Actions:
```javascript
// Assign role to level: POST /requests/settings/roles/assign-level
// Remove role from level: DELETE /requests/settings/roles/level/{id}
// Update category config: PUT /requests/settings/categories/{id}/config
```

## Status Badge Classes

Use these classes for status display:

```php
@php
$statusClasses = [
    'pending' => 'kt-badge kt-badge-warning',
    'approved' => 'kt-badge kt-badge-success',
    'rejected' => 'kt-badge kt-badge-danger',
    'cancelled' => 'kt-badge kt-badge-secondary',
];
@endphp

<span class="{{ $statusClasses[$request->status] }}">
    {{ ucfirst($request->status) }}
</span>
```

## Priority Badge Classes

```php
@php
$priorityClasses = [
    'low' => 'kt-badge kt-badge-light',
    'normal' => 'kt-badge kt-badge-primary',
    'high' => 'kt-badge kt-badge-warning',
    'urgent' => 'kt-badge kt-badge-danger',
];
@endphp

<span class="{{ $priorityClasses[$request->priority] }}">
    {{ ucfirst($request->priority) }}
</span>
```

## Sample Requests Index Structure

```blade
@extends('layouts.app')

@section('content')
<div class="kt-container-fixed">
    <div class="grid gap-5 lg:gap-7.5">
        <div class="kt-card kt-card-grid min-w-full">
            
            <!-- Header -->
            <div class="kt-card-header">
                <h3 class="kt-card-title">Requests Management</h3>
                <div class="kt-card-toolbar">
                    <a href="{{ route('requests.create') }}" class="kt-btn kt-btn-primary">
                        <i class="ki-filled ki-plus"></i> New Request
                    </a>
                </div>
            </div>

            <!-- Tabs -->
            <div class="kt-tabs">
                <div class="kt-tabs-list">
                    <button class="kt-tab active" data-view="my">My Requests</button>
                    <button class="kt-tab" data-view="pending_approval">Pending My Approval</button>
                    <button class="kt-tab" data-view="all">All Requests</button>
                </div>
            </div>

            <!-- Filters -->
            <div class="kt-card-body">
                <div class="flex gap-4 mb-4">
                    <select id="filter-status" class="kt-select">
                        <option value="">All Status</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                    </select>

                    <select id="filter-category" class="kt-select">
                        <option value="">All Categories</option>
                        @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->category_name }}</option>
                        @endforeach
                    </select>

                    <button onclick="loadRequests()" class="kt-btn kt-btn-primary">Filter</button>
                </div>

                <!-- Table -->
                <table class="kt-table" id="requests-table">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Category</th>
                            <th>Title</th>
                            <th>Requester</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="requests-tbody">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let currentView = 'my';

function loadRequests() {
    const status = $('#filter-status').val();
    const category = $('#filter-category').val();
    
    $.ajax({
        url: '{{ route("requests.data") }}',
        data: {
            view: currentView,
            status: status,
            category_id: category
        },
        success: function(response) {
            renderRequestsTable(response.data);
        }
    });
}

function renderRequestsTable(requests) {
    let html = '';
    requests.forEach(request => {
        html += `
            <tr>
                <td>${request.request_number}</td>
                <td>${request.category.category_name}</td>
                <td>${request.title}</td>
                <td>${request.requester.fullname}</td>
                <td><span class="kt-badge kt-badge-${getStatusClass(request.status)}">${request.status}</span></td>
                <td><span class="kt-badge kt-badge-${getPriorityClass(request.priority)}">${request.priority}</span></td>
                <td>${formatDate(request.created_at)}</td>
                <td>
                    <a href="/requests/${request.id}" class="kt-btn kt-btn-sm kt-btn-primary">View</a>
                </td>
            </tr>
        `;
    });
    $('#requests-tbody').html(html);
}

// Tab switching
$('.kt-tab').click(function() {
    $('.kt-tab').removeClass('active');
    $(this).addClass('active');
    currentView = $(this).data('view');
    loadRequests();
});

// Initial load
$(document).ready(function() {
    loadRequests();
});
</script>
@endsection
```

## Sample Request Show/Detail Page

```blade
@extends('layouts.app')

@section('content')
<div class="kt-container-fixed">
    <div class="grid gap-5">
        <!-- Request Details Card -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Request Details</h3>
                <div class="kt-card-toolbar">
                    <span class="kt-badge kt-badge-{{ $statusClass }}">{{ ucfirst($request->status) }}</span>
                </div>
            </div>
            
            <div class="kt-card-body">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="font-semibold">Request Number:</label>
                        <p>{{ $request->request_number }}</p>
                    </div>
                    
                    <div>
                        <label class="font-semibold">Category:</label>
                        <p>{{ $request->category->category_name }}</p>
                    </div>
                    
                    <div>
                        <label class="font-semibold">Requester:</label>
                        <p>{{ $request->requester->fullname }}</p>
                    </div>
                    
                    <div>
                        <label class="font-semibold">Submitted:</label>
                        <p>{{ $request->submitted_at->format('Y-m-d H:i') }}</p>
                    </div>

                    @if($request->leave_start_date)
                    <div>
                        <label class="font-semibold">Leave Period:</label>
                        <p>{{ $request->leave_start_date->format('Y-m-d') }} to {{ $request->leave_end_date->format('Y-m-d') }}</p>
                        <p class="text-sm text-gray-600">{{ $request->leave_days }} days</p>
                    </div>
                    
                    <div>
                        <label class="font-semibold">Leave Type:</label>
                        <p>{{ ucfirst($request->leave_type) }}</p>
                    </div>
                    @endif

                    @if($request->amount)
                    <div>
                        <label class="font-semibold">Amount:</label>
                        <p>Rs. {{ number_format($request->amount, 2) }}</p>
                    </div>
                    @endif
                    
                    <div class="col-span-2">
                        <label class="font-semibold">Description:</label>
                        <p>{{ $request->description }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval Timeline -->
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Approval Timeline</h3>
            </div>
            
            <div class="kt-card-body">
                @if($request->requires_level_1)
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-8 h-8 rounded-full {{ $request->level_1_status === 'approved' ? 'bg-green-500' : ($request->level_1_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }} flex items-center justify-center text-white">
                        1
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold">Level 1 Approval</p>
                        <p class="text-sm">Status: {{ ucfirst($request->level_1_status ?? 'pending') }}</p>
                        @if($request->getLevel1Approver())
                        <p class="text-sm">By: {{ $request->getLevel1Approver()->approver->fullname }}</p>
                        @endif
                    </div>
                </div>
                @endif

                @if($request->requires_level_2)
                <div class="flex items-center gap-4">
                    <div class="w-8 h-8 rounded-full {{ $request->level_2_status === 'approved' ? 'bg-green-500' : ($request->level_2_status === 'rejected' ? 'bg-red-500' : 'bg-gray-300') }} flex items-center justify-center text-white">
                        2
                    </div>
                    <div class="flex-1">
                        <p class="font-semibold">Level 2 Approval</p>
                        <p class="text-sm">Status: {{ ucfirst($request->level_2_status ?? 'pending') }}</p>
                        @if($request->getLevel2Approver())
                        <p class="text-sm">By: {{ $request->getLevel2Approver()->approver->fullname }}</p>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>

        <!-- Approval Actions -->
        @if($canApproveLevel1 || $canApproveLevel2)
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Approval Action</h3>
            </div>
            
            <div class="kt-card-body">
                <form id="approval-form">
                    @csrf
                    <input type="hidden" name="level" value="{{ $canApproveLevel1 ? 1 : 2 }}">
                    
                    <div class="mb-4">
                        <label class="kt-label">Comments</label>
                        <textarea name="comments" class="kt-input" rows="3" placeholder="Enter your comments..."></textarea>
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="button" onclick="approveRequest()" class="kt-btn kt-btn-success">
                            <i class="ki-filled ki-check"></i> Approve
                        </button>
                        <button type="button" onclick="rejectRequest()" class="kt-btn kt-btn-danger">
                            <i class="ki-filled ki-cross"></i> Reject
                        </button>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>

<script>
function approveRequest() {
    const formData = $('#approval-form').serialize();
    
    $.ajax({
        url: '{{ route("requests.approve", $request->id) }}',
        method: 'POST',
        data: formData,
        success: function(response) {
            alert('Request approved successfully');
            location.reload();
        },
        error: function(xhr) {
            alert('Error: ' + xhr.responseJSON.message);
        }
    });
}

function rejectRequest() {
    if (!$('textarea[name="comments"]').val()) {
        alert('Please enter comments for rejection');
        return;
    }
    
    const formData = $('#approval-form').serialize();
    
    $.ajax({
        url: '{{ route("requests.reject", $request->id) }}',
        method: 'POST',
        data: formData,
        success: function(response) {
            alert('Request rejected');
            location.reload();
        },
        error: function(xhr) {
            alert('Error: ' + xhr.responseJSON.message);
        }
    });
}
</script>
@endsection
```

## JavaScript Helper Functions

```javascript
// Status badge class mapping
function getStatusClass(status) {
    const classes = {
        'pending': 'warning',
        'approved': 'success',
        'rejected': 'danger',
        'cancelled': 'secondary'
    };
    return classes[status] || 'secondary';
}

// Priority badge class mapping
function getPriorityClass(priority) {
    const classes = {
        'low': 'light',
        'normal': 'primary',
        'high': 'warning',
        'urgent': 'danger'
    };
    return classes[priority] || 'primary';
}

// Format date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}
```

## Styling Notes

The system uses Keenthemes Metronic styling classes:
- `kt-card` - Card container
- `kt-btn` - Button
- `kt-btn-primary` - Primary button
- `kt-badge` - Badge
- `kt-badge-success` - Green badge
- `kt-badge-danger` - Red badge
- `kt-badge-warning` - Orange/Yellow badge
- `kt-table` - Table
- `kt-input` - Input field
- `kt-select` - Select dropdown

Refer to your existing pages (users, roles, etc.) for exact styling patterns.

