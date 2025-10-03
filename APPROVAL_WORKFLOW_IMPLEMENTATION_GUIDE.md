# Approval Workflow System - Implementation Guide

## Overview
This document provides a complete guide to implementing the approval workflow system with leave requests integration in your Nizami Farms application.

## Database Schema

### Tables Created
1. **`t_sys_role_approval_level`** - Maps roles to approval levels (Level 1, Level 2)
2. **`t_req_category`** - Request categories (leave, advance, expense, etc.)
3. **`t_req_category_approval_config`** - Approval level requirements per category
4. **`t_req_master`** - Main requests table
5. **`t_req_approval`** - Individual approval actions
6. **`t_ops_attendance`** - Updated with leave request links

### SQL Scripts Location
**File:** `database/migrations/approval_workflow_schema.sql`

**YOU NEED TO RUN THIS FILE MANUALLY IN YOUR MySQL WORKBENCH**

## Step-by-Step Setup Instructions

### Step 1: Run Database Migrations
```sql
-- Open MySQL Workbench
-- Connect to your database
-- Open and run: database/migrations/approval_workflow_schema.sql
```

### Step 2: Assign Approval Levels to Roles

First, check your existing roles:
```sql
SELECT id, urole_name FROM t_sys_role WHERE is_active = 1;
```

Then assign approval levels to appropriate roles:
```sql
-- Example: Manager role (id=2) gets Level 1 approval rights
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (2, 1, 1);

-- Example: Admin role (id=1) gets Level 2 approval rights  
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (1, 2, 1);
```

### Step 3: Configure Request Categories

The system comes with these default categories:
- **Leave Request** - Employee leave/absence requests
- **Salary Advance** - Salary advance requests
- **Expense Reimbursement** - Expense reimbursement requests
- **Equipment Request** - Request for equipment or supplies
- **Other Request** - General requests

Configure which categories require Level 1 only vs Level 1 + Level 2:
```sql
-- Leave requests require Level 1 only
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 0
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'leave');

-- Salary advance requires both Level 1 and Level 2
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'advance');
```

### Step 4: Create Necessary Directories

[[memory:8836725]][[memory:9480692]]

**IMPORTANT:** You need to create these directories manually:

```
resources/views/pages/requests/
```

## Models Created

### Location: `app/Models/Request/`
- `RequestCategoryModel.php`
- `RequestCategoryApprovalConfigModel.php`
- `RequestModel.php`
- `RequestApprovalModel.php`

### Location: `app/Models/SysAdmin/`
- `RoleApprovalLevelModel.php` (NEW)
- `RoleModel.php` (UPDATED - added approvalLevels relationship)

## Controllers Created

### Location: `app/Http/Controllers/Request/`
- `RequestController.php` - Main request management
- `RequestApprovalController.php` - Approval actions
- `RequestSettingsController.php` - Admin settings

## Routes

All routes are added to `routes/web.php` under the auth middleware group.

### Main Routes:
- `GET /requests` - View all requests
- `GET /requests/create` - Create new request form
- `POST /requests` - Submit new request
- `GET /requests/{id}` - View request details
- `POST /requests/{id}/approve` - Approve request
- `POST /requests/{id}/reject` - Reject request
- `GET /requests/settings` - Admin settings page

## How the Approval Flow Works

### 1. Request Submission
```
Employee submits leave request
    ↓
System checks category approval config
    ↓
Creates request with status = "pending"
Level 1 status = "pending" (if required)
Level 2 status = "pending" (if required)
```

### 2. Level 1 Approval
```
Manager (Level 1 approver) reviews request
    ↓
Manager APPROVES
    ↓
Level 1 status = "approved"
    ↓
If Level 2 NOT required → Request = "approved" ✅
If Level 2 required → Wait for Level 2
```

### 3. Level 2 Approval (if required)
```
Admin (Level 2 approver) reviews request
    ↓
(Can only approve if Level 1 already approved)
    ↓
Admin APPROVES
    ↓
Level 2 status = "approved"
Request status = "approved" ✅
```

### 4. Leave Request → Attendance Integration
```
Leave request approved
    ↓
System automatically creates attendance records
    ↓
For each day in leave period:
    - attendance_date = date
    - status = "leave"
    - leave_request_id = request ID
    - leave_type = sick/annual/casual/etc.
```

## Features

### For Employees/Riders:
- Submit leave requests
- View own request history
- Track approval status
- Cancel pending requests

### For Level 1 Approvers (Managers):
- View pending Level 1 requests
- Approve or reject with comments
- View approval history

### For Level 2 Approvers (Admins):
- View pending Level 2 requests (after Level 1 approval)
- Final approval authority
- View all approvals

### For System Admins:
- Configure request categories
- Assign roles to approval levels
- Configure approval requirements per category
- View system-wide statistics

## Attendance System Integration

### Updated Attendance Table Fields:
- `leave_request_id` - Links to approved leave request
- `leave_type` - Type of leave (sick, annual, casual, emergency)
- `status` - Now includes "leave" status

### How It Works:
1. Employee submits leave request (e.g., 3 days off)
2. Request goes through approval workflow
3. Once fully approved:
   - System automatically creates 3 attendance records
   - Each marked as `status = 'leave'`
   - Linked to the leave request
   - Leave type recorded

### Attendance Reports:
When calculating attendance statistics, the system can now differentiate:
- **Present** - Employee came to work
- **Absent** - Employee didn't come, no leave request
- **Leave** - Employee on approved leave (doesn't count as absence)

## Settings Page Features

### 1. Role-Level Assignments
- Assign multiple roles to Level 1
- Assign multiple roles to Level 2
- View users with each approval level

### 2. Category Configuration
- Enable/disable categories
- Set approval requirements (Level 1 only vs Level 1 + Level 2)
- Set auto-approve thresholds (for amount-based requests)

### 3. Category Management
- Create new request categories
- Edit category details
- Set display order

## API Endpoints

### Request Management
```
GET  /requests/data?view={my|pending_approval|all}
POST /requests
GET  /requests/{id}
PUT  /requests/{id}
POST /requests/{id}/cancel
```

### Approval Actions
```
POST /requests/{id}/approve
POST /requests/{id}/reject
GET  /requests/approval/statistics
```

### Settings (Admin)
```
GET  /requests/settings
PUT  /requests/settings/categories/{id}/config
POST /requests/settings/roles/assign-level
DELETE /requests/settings/roles/level/{id}
```

## Security & Permissions

### Request Submission:
- Any authenticated user can submit requests

### Approval Rights:
- Level 1: Users with roles assigned to Level 1
- Level 2: Users with roles assigned to Level 2

### Request Viewing:
- Employees: See only their own requests
- Approvers: See requests they can approve
- Admins: See all requests

## Testing Checklist

### Database:
- [ ] All tables created successfully
- [ ] Foreign keys working
- [ ] Initial data seeded

### Approval Levels:
- [ ] Roles assigned to Level 1
- [ ] Roles assigned to Level 2
- [ ] Users can be identified by level

### Request Creation:
- [ ] Leave request form works
- [ ] Validation works
- [ ] Request number generates correctly

### Approval Flow:
- [ ] Level 1 approval works
- [ ] Level 2 approval works (if required)
- [ ] Rejection works at any level
- [ ] Comments are saved

### Attendance Integration:
- [ ] Approved leave creates attendance records
- [ ] Leave days calculated correctly
- [ ] Attendance reports show leave correctly

### Settings:
- [ ] Can assign roles to levels
- [ ] Can configure category approval requirements
- [ ] Changes take effect immediately

## Common SQL Queries

### Check pending approvals for a user:
```sql
SELECT r.*, rc.category_name, ru.fullname as requester_name
FROM t_req_master r
JOIN t_req_category rc ON r.category_id = rc.id
JOIN t_sys_user ru ON r.requester_user_id = ru.id
WHERE r.status = 'pending'
  AND (
    (r.requires_level_1 = 1 AND r.level_1_status = 'pending')
    OR 
    (r.requires_level_2 = 1 AND r.level_2_status = 'pending' AND r.level_1_status = 'approved')
  );
```

### View all leave requests and their attendance impact:
```sql
SELECT 
    r.request_number,
    r.leave_start_date,
    r.leave_end_date,
    r.leave_days,
    r.status,
    COUNT(a.id) as attendance_records_created
FROM t_req_master r
LEFT JOIN t_ops_attendance a ON a.leave_request_id = r.id
WHERE r.category_id = (SELECT id FROM t_req_category WHERE category_code = 'leave')
GROUP BY r.id;
```

### See who can approve at each level:
```sql
-- Level 1 Approvers
SELECT u.id, u.fullname, r.urole_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 1 
  AND ral.is_active = 1
  AND u.is_active = 1;

-- Level 2 Approvers  
SELECT u.id, u.fullname, r.urole_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 2
  AND ral.is_active = 1
  AND u.is_active = 1;
```

## Next Steps

1. **Run SQL scripts** (approval_workflow_schema.sql)
2. **Create views directory**: `resources/views/pages/requests/`
3. **Assign approval levels** to your roles
4. **Test the workflow** with sample requests
5. **Customize UI** as needed
6. **Add to navigation menu** (if not already there)

## Support & Troubleshooting

### Request not appearing for approval?
- Check if user's role has the correct approval level assigned
- Verify the request status and level statuses
- Check approval requirements for that category

### Attendance records not creating?
- Verify leave request is fully approved
- Check leave dates are valid
- Look for errors in Laravel logs

### Users can't submit requests?
- Verify routes are working
- Check authentication middleware
- Look for validation errors

## File Locations Summary

```
database/migrations/
  └── approval_workflow_schema.sql

app/Models/Request/
  ├── RequestCategoryModel.php
  ├── RequestCategoryApprovalConfigModel.php
  ├── RequestModel.php
  └── RequestApprovalModel.php

app/Models/SysAdmin/
  ├── RoleApprovalLevelModel.php (NEW)
  └── RoleModel.php (UPDATED)

app/Http/Controllers/Request/
  ├── RequestController.php
  ├── RequestApprovalController.php
  └── RequestSettingsController.php

routes/
  └── web.php (UPDATED)

resources/views/pages/requests/
  ├── index.blade.php (to be created)
  ├── create.blade.php (to be created)
  ├── show.blade.php (to be created)
  └── settings.blade.php (to be created)
```

