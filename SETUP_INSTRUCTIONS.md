# 🚀 Approval Workflow System - Quick Setup Guide

## ✅ What Has Been Created

### 1. Database Schema
- **File:** `database/migrations/approval_workflow_schema.sql`
- 5 new tables + updates to attendance table
- Initial seed data for categories

### 2. Models (All Complete)
- `app/Models/Request/RequestModel.php`
- `app/Models/Request/RequestCategoryModel.php`
- `app/Models/Request/RequestCategoryApprovalConfigModel.php`
- `app/Models/Request/RequestApprovalModel.php`
- `app/Models/SysAdmin/RoleApprovalLevelModel.php`
- Updated: `app/Models/SysAdmin/RoleModel.php`

### 3. Controllers (All Complete)
- `app/Http/Controllers/Request/RequestController.php`
- `app/Http/Controllers/Request/RequestApprovalController.php`
- `app/Http/Controllers/Request/RequestSettingsController.php`

### 4. Routes
- Updated: `routes/web.php` with all request routes

### 5. Documentation
- `APPROVAL_WORKFLOW_IMPLEMENTATION_GUIDE.md` - Complete system guide
- `BLADE_VIEWS_TEMPLATES.md` - View templates and examples

---

## 📋 YOUR ACTION ITEMS

### Step 1: Run SQL Scripts ⚠️ REQUIRED

**Open MySQL Workbench and run:**

```bash
database/migrations/approval_workflow_schema.sql
```

This will create all necessary tables and seed initial data.

### Step 2: Create Views Directory ⚠️ REQUIRED

[[memory:8836725]][[memory:9480692]]

**You need to manually create this directory:**

```
resources/views/pages/requests/
```

Then create these view files in that directory (templates provided in `BLADE_VIEWS_TEMPLATES.md`):
- `index.blade.php`
- `create.blade.php`
- `show.blade.php`
- `settings.blade.php`

### Step 3: Assign Approval Levels to Roles

**First, check your existing roles:**

```sql
SELECT id, urole_name, type FROM t_sys_role WHERE is_active = 1;
```

**Then assign approval levels:**

```sql
-- Example: Manager role gets Level 1 approval rights
-- Replace role_id with your actual manager role ID
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (2, 1, 1);

-- Example: Admin role gets Level 2 approval rights
-- Replace role_id with your actual admin role ID
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (1, 2, 1);
```

### Step 4: Configure Category Approval Requirements (Optional)

By default, all categories require Level 1 only. To require both levels:

```sql
-- Make salary advance require both Level 1 AND Level 2
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'advance');

-- Make expense reimbursement require both levels
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'expense');
```

### Step 5: Add to Navigation Menu (Optional)

Add links to your navigation menu (wherever it's defined):

```html
<a href="{{ route('requests.index') }}">Requests</a>
<a href="{{ route('requests.settings.index') }}">Request Settings</a>
```

---

## 🧪 Testing Your Setup

### 1. Verify Database Tables

```sql
-- Should return 5 tables
SHOW TABLES LIKE 't_req%';

-- Should return approval level table
SHOW TABLES LIKE '%approval%';

-- Check seed data
SELECT * FROM t_req_category;
```

### 2. Test Request Creation

1. Go to `/requests/create`
2. Select "Leave Request" category
3. Fill in leave dates
4. Submit
5. Check that request appears in `/requests` with "Pending" status

### 3. Test Approval Flow

1. Login as a user with Level 1 approval rights
2. Go to `/requests` → "Pending My Approval" tab
3. Click on a pending request
4. Click "Approve" button
5. Verify request status changes

### 4. Verify Attendance Integration

After approving a leave request:

```sql
-- Check if attendance records were created
SELECT * FROM t_ops_attendance 
WHERE leave_request_id IS NOT NULL
ORDER BY attendance_date DESC;
```

---

## 📊 System Architecture

### Approval Flow

```
Employee Submits Leave Request
         ↓
   Status: PENDING
   Level 1: PENDING
         ↓
Level 1 Approver Reviews
         ↓
    Level 1 APPROVED
         ↓
    [If Level 2 Required]
         ↓
Level 2 Approver Reviews
         ↓
    Level 2 APPROVED
         ↓
Request Status: APPROVED ✅
         ↓
Attendance Records Created
```

### Database Relationships

```
t_sys_role
    ↓ (1:many)
t_sys_role_approval_level (Level 1, Level 2)
    ↓
t_sys_user (via t_sys_user_role)

t_req_category
    ↓ (1:1)
t_req_category_approval_config
    ↓ (1:many)
t_req_master (requests)
    ↓ (1:many)
t_req_approval (approval actions)

t_req_master
    ↓ (1:many)
t_ops_attendance (for leave requests)
```

---

## 🔧 Configuration Examples

### Example 1: Simple Setup (Level 1 Only)

All requests need only one approval:

```sql
-- All categories require Level 1 only
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 0;

-- Assign Manager role to Level 1
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
SELECT id, 1, 1 FROM t_sys_role WHERE urole_name = 'Manager';
```

### Example 2: Two-Level Setup

Small requests need Level 1, large requests need both:

```sql
-- Leave requests: Level 1 only
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 0
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'leave');

-- Salary advance: Both levels
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'advance');

-- Assign roles
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES 
    (2, 1, 1),  -- Manager = Level 1
    (1, 2, 1);  -- Admin = Level 2
```

### Example 3: Multiple Roles per Level

Multiple roles can approve at same level:

```sql
-- Multiple roles for Level 1
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
SELECT id, 1, 1 FROM t_sys_role WHERE urole_name IN ('Manager', 'Supervisor');

-- Multiple roles for Level 2
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
SELECT id, 2, 1 FROM t_sys_role WHERE urole_name IN ('Admin', 'Director');
```

---

## 🔍 Useful SQL Queries

### Check Who Can Approve What

```sql
-- See all Level 1 approvers
SELECT 
    u.id,
    u.fullname,
    u.email,
    r.urole_name as role_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 1 
  AND ral.is_active = 1
  AND u.is_active = 1
ORDER BY u.fullname;

-- See all Level 2 approvers
SELECT 
    u.id,
    u.fullname,
    u.email,
    r.urole_name as role_name
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE ral.approval_level = 2
  AND ral.is_active = 1
  AND u.is_active = 1
ORDER BY u.fullname;
```

### View All Pending Requests

```sql
SELECT 
    r.request_number,
    r.title,
    rc.category_name,
    u.fullname as requester,
    r.status,
    CASE 
        WHEN r.requires_level_1 = 1 AND r.level_1_status = 'pending' THEN 'Needs Level 1'
        WHEN r.requires_level_2 = 1 AND r.level_2_status = 'pending' THEN 'Needs Level 2'
        ELSE 'Unknown'
    END as pending_level,
    r.submitted_at
FROM t_req_master r
JOIN t_req_category rc ON r.category_id = rc.id
JOIN t_sys_user u ON r.requester_user_id = u.id
WHERE r.status = 'pending'
ORDER BY r.submitted_at DESC;
```

### View Leave Request Impact on Attendance

```sql
SELECT 
    r.request_number,
    r.title,
    u.fullname as requester,
    r.leave_start_date,
    r.leave_end_date,
    r.leave_days,
    r.leave_type,
    r.status,
    COUNT(a.id) as attendance_records_created,
    GROUP_CONCAT(DATE_FORMAT(a.attendance_date, '%Y-%m-%d') ORDER BY a.attendance_date) as dates
FROM t_req_master r
JOIN t_sys_user u ON r.requester_user_id = u.id
JOIN t_req_category rc ON r.category_id = rc.id
LEFT JOIN t_ops_attendance a ON a.leave_request_id = r.id
WHERE rc.category_code = 'leave'
GROUP BY r.id
ORDER BY r.submitted_at DESC
LIMIT 20;
```

### Check Category Configurations

```sql
SELECT 
    c.category_code,
    c.category_name,
    c.is_active,
    cfg.requires_level_1,
    cfg.requires_level_2,
    cfg.auto_approve_threshold
FROM t_req_category c
LEFT JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
ORDER BY c.sequence_order;
```

---

## ⚠️ Common Issues & Solutions

### Issue: "Table doesn't exist" errors
**Solution:** Run the SQL migration file: `database/migrations/approval_workflow_schema.sql`

### Issue: "No approvers found" 
**Solution:** Assign roles to approval levels:
```sql
INSERT INTO t_sys_role_approval_level (role_id, approval_level, created_by)
VALUES (YOUR_ROLE_ID, 1, 1);
```

### Issue: Request stays pending even after approval
**Solution:** Check approval configuration:
```sql
-- See what levels are required
SELECT * FROM t_req_category_approval_config WHERE category_id = YOUR_CATEGORY_ID;
```

### Issue: Attendance records not created
**Solution:** Verify request is fully approved:
```sql
SELECT status, level_1_status, level_2_status 
FROM t_req_master 
WHERE id = YOUR_REQUEST_ID;
-- Both should be 'approved' and status should be 'approved'
```

### Issue: Views directory doesn't exist
**Solution:** Create it manually:
```bash
# Windows
md "resources\views\pages\requests"

# Or in File Explorer:
# Navigate to resources/views/pages/
# Create new folder named "requests"
```

---

## 📚 Additional Resources

- **Full Documentation:** `APPROVAL_WORKFLOW_IMPLEMENTATION_GUIDE.md`
- **View Templates:** `BLADE_VIEWS_TEMPLATES.md`
- **SQL Schema:** `database/migrations/approval_workflow_schema.sql`

---

## ✨ Features Summary

### For Employees/Riders:
- ✅ Submit leave requests
- ✅ View own request history
- ✅ Track approval status in real-time
- ✅ Cancel pending requests

### For Managers (Level 1):
- ✅ View all pending Level 1 requests
- ✅ Approve or reject with comments
- ✅ View approval history

### For Admins (Level 2):
- ✅ Final approval authority
- ✅ View all system requests
- ✅ Configure approval workflows

### Attendance Integration:
- ✅ Approved leaves automatically create attendance records
- ✅ Leave days properly counted and typed
- ✅ Attendance reports distinguish leave from absence
- ✅ Historical link to leave request

---

## 🎯 Next Steps

1. ✅ Run SQL migration
2. ✅ Create views directory and files
3. ✅ Assign approval levels to roles
4. ✅ Test request creation
5. ✅ Test approval flow
6. ✅ Verify attendance integration
7. ✅ Add to navigation menu
8. ✅ Train users

---

## 💡 Tips

- Start with Level 1 only to keep it simple
- Add Level 2 later as needed
- Use the settings page to configure dynamically
- Test with real scenarios before going live
- Check Laravel logs if issues occur: `storage/logs/laravel.log`

---

## Need Help?

Refer to the detailed documentation files:
- `APPROVAL_WORKFLOW_IMPLEMENTATION_GUIDE.md` - System architecture & concepts
- `BLADE_VIEWS_TEMPLATES.md` - Frontend templates & examples

All code is commented and follows your existing patterns. The system reuses your existing functions and models where possible to avoid duplication.

