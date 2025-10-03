# 🧪 Testing Guide - Approval Workflow System

## ✅ What We've Done So Far

1. ✅ SQL tables created successfully
2. ✅ Menu items added to sidebar
3. ✅ All backend code in place
4. ✅ All view templates created

---

## 🎯 Step-by-Step Testing Guide

### **Step 1: Assign Roles to Approval Levels**

#### Via SQL (Quick Start):

```sql
USE nizamifarms_db;

-- 1. First, check what roles you have
SELECT id, urole_name, type, is_active FROM t_sys_role WHERE is_active = 1;
```

**Example output:**
```
id | urole_name      | type    | is_active
---+-----------------+---------+-----------
1  | Admin           | admin   | 1
2  | Manager         | manager | 1
3  | Employee        | employee| 1
4  | Rider           | rider   | 1
```

**Now assign approval levels:**

```sql
-- Assign Manager role (id=2) to Level 1
INSERT INTO t_sys_role_approval_level (role_id, approval_level, is_active, created_by)
VALUES (2, 1, 1, 1);

-- Assign Admin role (id=1) to Level 2
INSERT INTO t_sys_role_approval_level (role_id, approval_level, is_active, created_by)
VALUES (1, 2, 1, 1);

-- Verify it worked
SELECT 
    ral.id,
    r.urole_name as role_name,
    ral.approval_level,
    ral.is_active,
    CASE 
        WHEN ral.approval_level = 1 THEN '✅ Level 1 Approver (First Level)'
        WHEN ral.approval_level = 2 THEN '✅ Level 2 Approver (Final Level)'
    END as description
FROM t_sys_role_approval_level ral
JOIN t_sys_role r ON ral.role_id = r.id
WHERE ral.is_active = 1
ORDER BY ral.approval_level, r.urole_name;
```

**Expected output:**
```
id | role_name | approval_level | is_active | description
---+-----------+----------------+-----------+---------------------------
1  | Manager   | 1              | 1         | ✅ Level 1 Approver (First Level)
2  | Admin     | 2              | 1         | ✅ Level 2 Approver (Final Level)
```

---

### **Step 2: Access the System in Your Browser**

#### **A. Go to Requests Page**

1. Open your browser
2. Navigate to: `http://your-app-url/requests`
3. You should see the **Requests Management** page with 3 tabs:
   - **My Requests** - Shows your own requests
   - **Pending My Approval** - Shows requests waiting for your approval (only if you're an approver)
   - **All Requests** - Shows all requests in the system

#### **B. Check the Sidebar Menu**

Look at the left sidebar - you should see a new section:

```
📋 REQUESTS & APPROVALS
   📄 Requests
   ⚙️ Request Settings  (only visible to non-riders)
```

---

### **Step 3: Configure Approval Requirements via Settings Page**

1. **Go to:** `/requests/settings`
2. You'll see two main sections:

#### **Section 1: Approval Level Assignments**

- **Left side:** Level 1 Approvers
- **Right side:** Level 2 Approvers

You can:
- Add more roles to each level using the dropdown
- Remove roles by clicking the trash icon
- View which roles are assigned

#### **Section 2: Category Approval Configuration**

This shows a table with all request categories:

| Category | Requires Level 1 | Requires Level 2 | Auto-Approve Threshold |
|----------|------------------|------------------|------------------------|
| Leave Request | ✅ | ☐ | - |
| Salary Advance | ✅ | ☐ | - |
| Expense Reimbursement | ✅ | ☐ | - |
| Equipment Request | ✅ | ☐ | - |
| Other Request | ✅ | ☐ | - |

**Try this:**
- Check the "Requires Level 2" box for "Salary Advance"
- Click "Save"
- Now salary advance requests will need BOTH Level 1 AND Level 2 approval!

---

### **Step 4: Test Creating a Leave Request**

#### **As an Employee/Rider:**

1. **Go to:** `/requests/create`
2. **Fill in the form:**
   - **Category:** Select "Leave Request"
   - **Title:** "Sick Leave - Flu"
   - **Leave Start Date:** Tomorrow's date
   - **Leave End Date:** Day after tomorrow
   - **Leave Type:** Select "Sick Leave"
   - **Description:** "Need 2 days off due to flu"
   - **Priority:** "Normal"
3. **Click:** "Submit Request"
4. You should see: "Request submitted successfully!"
5. You'll be redirected to the request details page

---

### **Step 5: Test Level 1 Approval**

#### **As a Manager (Level 1 Approver):**

1. **Login as a user with Manager role**
2. **Go to:** `/requests`
3. **Click:** "Pending My Approval" tab
4. You should see the leave request you just created
5. **Click:** "View" button on the request
6. **Scroll down** to the "Take Action" section
7. **Enter comments** (optional): "Approved - get well soon"
8. **Click:** "Approve" button
9. You should see: "Request approved at Level 1"
10. Page reloads - status should now be "Approved" ✅

---

### **Step 6: Verify Attendance Records Created**

After the leave request is approved, check if attendance records were created:

```sql
USE nizamifarms_db;

-- Check attendance records linked to leave requests
SELECT 
    a.id,
    a.user_id,
    u.fullname,
    a.attendance_date,
    a.status,
    a.leave_type,
    a.leave_request_id,
    r.request_number,
    r.title
FROM t_ops_attendance a
JOIN t_sys_user u ON a.user_id = u.id
LEFT JOIN t_req_master r ON a.leave_request_id = r.id
WHERE a.leave_request_id IS NOT NULL
ORDER BY a.attendance_date DESC;
```

**Expected output:**
```
id | user_id | fullname | attendance_date | status | leave_type | leave_request_id | request_number | title
---+---------+----------+-----------------+--------+------------+------------------+----------------+-------------------
1  | 5       | John Doe | 2025-10-04      | leave  | sick       | 1                | REQ-202510-0001| Sick Leave - Flu
2  | 5       | John Doe | 2025-10-05      | leave  | sick       | 1                | REQ-202510-0001| Sick Leave - Flu
```

✅ **Success!** Two attendance records created automatically (one for each day of leave)

---

### **Step 7: Test Two-Level Approval**

#### **Setup:**
```sql
-- Make salary advance require both levels
UPDATE t_req_category_approval_config 
SET requires_level_1 = 1, requires_level_2 = 1
WHERE category_id = (SELECT id FROM t_req_category WHERE category_code = 'advance');
```

#### **Test:**

1. **As Employee:** Create a new request
   - Category: "Salary Advance"
   - Title: "Emergency advance needed"
   - Amount: 50000
   - Description: "Need advance for medical emergency"
   - Submit

2. **As Manager (Level 1):** 
   - Go to "Pending My Approval"
   - View the advance request
   - Approve it
   - ✅ Status changes to "Pending Level 2 Approval"

3. **As Admin (Level 2):**
   - Go to "Pending My Approval"
   - View the same request
   - You should see:
     - Level 1: ✅ Approved by Manager Name
     - Level 2: ⏳ Pending (waiting for your approval)
   - Approve it
   - ✅ Status changes to "Approved"

---

### **Step 8: Test Rejection**

1. **Create a new leave request** as an employee
2. **As Manager:**
   - View the request
   - Enter comments: "Please reschedule - we're understaffed this week"
   - Click "Reject"
3. **Verify:**
   - Status changes to "Rejected" ❌
   - Rejection reason is displayed
   - No attendance records created

---

## 🔍 How to Check Everything is Working

### **1. Check User Approval Rights**

```sql
-- See who can approve what
SELECT 
    u.id,
    u.fullname,
    u.email,
    r.urole_name,
    ral.approval_level,
    CASE 
        WHEN ral.approval_level = 1 THEN 'Can approve first level'
        WHEN ral.approval_level = 2 THEN 'Can give final approval'
    END as rights
FROM t_sys_user u
JOIN t_sys_user_role ur ON u.id = ur.user_id
JOIN t_sys_role r ON ur.role_id = r.id
JOIN t_sys_role_approval_level ral ON r.id = ral.role_id
WHERE u.is_active = 1
  AND ral.is_active = 1
ORDER BY ral.approval_level, u.fullname;
```

### **2. Check Category Configurations**

```sql
-- See approval requirements for each category
SELECT 
    c.category_name,
    cfg.requires_level_1,
    cfg.requires_level_2,
    CASE 
        WHEN cfg.requires_level_1 = 1 AND cfg.requires_level_2 = 1 THEN 'Both Levels Required ⚠️'
        WHEN cfg.requires_level_1 = 1 THEN 'Level 1 Only ✅'
        ELSE 'No Approval ⚡'
    END as approval_flow,
    cfg.auto_approve_threshold as threshold
FROM t_req_category c
JOIN t_req_category_approval_config cfg ON c.id = cfg.category_id
WHERE c.is_active = 1
ORDER BY c.sequence_order;
```

### **3. View All Requests**

```sql
-- See all requests and their status
SELECT 
    r.request_number,
    r.title,
    c.category_name,
    u.fullname as requester,
    r.status,
    r.level_1_status,
    r.level_2_status,
    r.submitted_at,
    r.completed_at
FROM t_req_master r
JOIN t_req_category c ON r.category_id = c.id
JOIN t_sys_user u ON r.requester_user_id = u.id
ORDER BY r.submitted_at DESC
LIMIT 20;
```

### **4. View Approval History**

```sql
-- See who approved what and when
SELECT 
    r.request_number,
    r.title,
    a.approval_level,
    u.fullname as approver,
    a.status,
    a.comments,
    a.action_date
FROM t_req_approval a
JOIN t_req_master r ON a.request_id = r.id
JOIN t_sys_user u ON a.approver_user_id = u.id
ORDER BY a.action_date DESC;
```

---

## 🎨 UI Screens Explained

### **1. Main Requests Page** (`/requests`)

Shows three tabs:

**My Requests:**
- See all your submitted requests
- Filter by status, category, date
- Create new requests

**Pending My Approval:**
- Only visible if you're an approver
- Shows requests waiting for YOUR approval
- Split by level (Level 1 or Level 2)

**All Requests:**
- See all requests in the system
- For admins/managers to monitor

**Statistics Cards:**
- Shows counts of pending approvals
- Your pending requests
- Your approved requests

---

### **2. Create Request Page** (`/requests/create`)

**Dynamic Form:**
- Changes based on category selected
- **Leave Request:** Shows date pickers, leave type, auto-calculates days
- **Salary Advance/Expense:** Shows amount field
- **Other:** Basic form

**Approval Info:**
- Shows which levels are required
- Example: "This request will require: Level 1 AND Level 2 approval"

---

### **3. Request Details Page** (`/requests/{id}`)

**Request Information:**
- All details of the request
- Requester info
- Dates, amounts, descriptions

**Approval Timeline:**
- Visual timeline showing:
  - Level 1: Status, who approved, when, comments
  - Level 2: Status, who approved, when, comments
- Color-coded: Green = approved, Red = rejected, Gray = pending

**Action Buttons:**
- **For Approvers:** Approve/Reject buttons with comments field
- **For Requester:** Cancel button (only if pending)

---

### **4. Settings Page** (`/requests/settings`)

**Role-Level Assignments:**
- Manage which roles can approve at each level
- Add/remove roles from Level 1 and Level 2

**Category Configuration:**
- Configure approval requirements per category
- Set which categories need one level vs two levels
- Optional auto-approve thresholds

---

## ✅ Success Criteria Checklist

- [ ] Menu items visible in sidebar
- [ ] Can access `/requests` page
- [ ] Can access `/requests/settings` page
- [ ] Roles assigned to approval levels (check SQL)
- [ ] Can create a leave request
- [ ] Level 1 approver can see pending requests
- [ ] Level 1 approval changes status
- [ ] Attendance records created after approval
- [ ] Two-level approval works (if configured)
- [ ] Rejection works and stops workflow
- [ ] Statistics cards show correct counts

---

## 🆘 Troubleshooting

### Issue: "Page not found" when accessing `/requests`
**Check:**
```bash
# Make sure routes are cached
php artisan route:clear
php artisan route:cache
```

### Issue: No requests showing in "Pending My Approval"
**Check:**
1. Are you logged in as a user with an approver role?
2. Run SQL to verify role assignment:
   ```sql
   SELECT * FROM t_sys_role_approval_level WHERE role_id IN (
       SELECT role_id FROM t_sys_user_role WHERE user_id = YOUR_USER_ID
   );
   ```

### Issue: Attendance records not created
**Check:**
1. Request status should be "approved"
2. Both level statuses should be "approved"
3. Check Laravel logs: `storage/logs/laravel.log`

---

## 🎉 You're Done!

Once you complete all tests successfully, your approval workflow system is fully operational!

**What you have now:**
- ✅ Complete request submission system
- ✅ Multi-level approval workflow
- ✅ Automatic attendance integration for leaves
- ✅ Admin configuration interface
- ✅ Full audit trail of approvals

**Next steps:**
- Train your users
- Configure approval requirements per your needs
- Monitor and adjust as needed

