# 👔 Manager Create Request for Others - Feature Guide

## ✨ New Feature Added!

Admins and Managers can now create leave requests (and other requests) on behalf of their team members through the UI.

---

## 🎯 **Who Can Use This Feature?**

Users with these role types can create requests for others:
- ✅ **Admin**
- ✅ **Manager**
- ✅ **Supervisor**

Regular employees/riders can only create requests for themselves.

---

## 🚀 **How to Use**

### **Step 1: Go to Create Request Page**

As an Admin or Manager:
1. Navigate to `/requests`
2. Click **"New Request"** button

### **Step 2: You'll See a Special Section**

At the top of the form, you'll see a blue box labeled **"Admin/Manager Mode"**:

```
📘 Admin/Manager Mode
Create Request For: [Dropdown with all active users]
💡 Select an employee to create a request on their behalf.
```

### **Step 3: Select Employee**

1. Click the dropdown: **"Create Request For"**
2. You'll see all active employees listed as: `Name (email)`
3. Select the employee you want to create the request for
4. **Or leave it blank** to create a request for yourself

### **Step 4: Fill in Request Details**

Fill in the form normally:
- **Category:** Leave Request
- **Title:** "Annual Leave"
- **Start Date:** 2025-10-10
- **End Date:** 2025-10-12
- **Leave Type:** Annual Leave
- **Description:** "Family vacation"

### **Step 5: Submit**

Click **"Submit Request"**

You'll see: ✅ **"Request created successfully for [Employee Name]"**

---

## 🔍 **How It Appears in the System**

### **In the Requests List:**

```
Request #       Category        Title           Requester
REQ-202510-001  Leave Request   Annual Leave    John Doe
                                                via Manager Name
```

The **"via Manager Name"** indicator shows it was created by someone else.

### **In Request Details:**

```
Requester (Employee): John Doe
ℹ️ Created by Manager Name on behalf of employee

Description: Family vacation

[Created by Manager Name on behalf of employee]
```

### **In Attendance Records:**

When approved, attendance records are created for **John Doe** (the employee), not the manager who created it.

---

## ✅ **The Approval Flow Stays the Same**

The request follows the normal approval workflow:

1. **Request Created** → Status: Pending
2. **Manager Approves** (if Level 1 required)
3. **Admin Approves** (if Level 2 required)
4. **Status:** Approved ✅
5. **Attendance Records Created** for the employee

**Important:** The manager who creates the request can still approve it if they have approval rights. The system doesn't prevent this (though you might want to add this as a business rule later).

---

## 🔐 **Security & Permissions**

### **Who Is Tracked:**

- **`requester_user_id`** = The employee the request is for (John Doe)
- **`created_by`** = Who actually created it (Manager)
- **Attendance records** = Created for the employee (John Doe)

### **Permission Validation:**

The backend validates:
1. ✅ Is the logged-in user an admin/manager/supervisor?
2. ✅ Does the selected employee exist and is active?
3. ❌ If not authorized → Error: "You do not have permission to create requests for other users"

---

## 📊 **Use Cases**

### **Use Case 1: Manager Enters Leave for Employee**

**Scenario:** Employee calls in sick but can't access the system

**Solution:**
1. Manager logs in
2. Creates leave request for employee
3. Selects employee from dropdown
4. Enters sick leave details
5. Submits → Request goes through approval flow
6. Approved → Attendance marked automatically

### **Use Case 2: HR Bulk Entry**

**Scenario:** HR needs to enter leave for multiple employees after a meeting

**Solution:**
1. HR (Admin role) logs in
2. Creates multiple leave requests
3. Each time selects different employee
4. All requests follow approval workflow
5. Clean audit trail of who created what

### **Use Case 3: Manager Pre-Approves Team Leave**

**Scenario:** Manager wants to pre-approve planned team leave

**Solution:**
1. Manager creates leave requests for team members
2. Since manager has Level 1 approval rights
3. Manager can then approve them
4. System tracks: created by manager, approved by manager
5. Full transparency maintained

---

## 🎨 **UI/UX Features**

### **Smart Visibility:**
- ✅ Section **only shows** for admins/managers/supervisors
- ✅ Regular employees don't see it at all
- ✅ Dropdown **excludes** the logged-in user (you can still create for yourself by leaving blank)

### **Visual Indicators:**
- 📘 Blue box with info icon
- 💡 Helpful tooltip text
- ✅ Success message shows employee name
- 📝 Audit notes added to description
- 🔍 "via Manager" badge in list view

### **User-Friendly:**
- Dropdown shows: `Name (email)` for easy identification
- Sorted alphabetically by name
- Only shows active users
- Clear placeholder: "-- Myself --"

---

## 🧪 **Testing**

### **Test 1: Manager Creates for Employee**

1. Login as Manager
2. Go to `/requests/create`
3. See blue "Admin/Manager Mode" box ✅
4. Select "John Doe" from dropdown
5. Fill in leave request
6. Submit
7. **Verify:**
   - Request created with John Doe as requester ✅
   - List shows "via Manager Name" ✅
   - Details show "Created by Manager Name" ✅

### **Test 2: Employee Tries to Create for Others**

1. Login as regular Employee (rider role)
2. Go to `/requests/create`
3. **Verify:** No "Admin/Manager Mode" section visible ✅
4. Can only create for themselves ✅

### **Test 3: Approval Flow**

1. Manager creates leave for Employee A
2. Manager approves it (if Level 1)
3. Admin approves it (if Level 2)
4. **Verify:**
   - Request approved ✅
   - Attendance created for Employee A (not manager) ✅
   - Audit trail shows both creator and approvers ✅

---

## 🔧 **Technical Details**

### **Backend Validation:**

```php
// In RequestController::store()
if ($request->filled('requester_user_id')) {
    // Check logged-in user's role
    $userRole = DB::table('t_sys_user_role as ur')
        ->join('t_sys_role as r', 'r.id', '=', 'ur.role_id')
        ->where('ur.user_id', $loggedInUser->id)
        ->value('r.type');
    
    // Validate permission
    if (!in_array($userRole, ['admin', 'manager', 'supervisor'])) {
        return 403 Forbidden;
    }
}
```

### **Database Records:**

```sql
-- Example record when manager creates for employee
INSERT INTO t_req_master (
    requester_user_id,  -- 5 (Employee John Doe)
    created_by,         -- 2 (Manager Jane Smith)
    ...
);

-- Attendance created for the employee
INSERT INTO t_ops_attendance (
    user_id,           -- 5 (Employee John Doe)
    leave_request_id,  -- Links to request
    ...
);
```

### **What Gets Tracked:**

| Field | Value | Purpose |
|-------|-------|---------|
| `requester_user_id` | Employee ID | Who the request is for |
| `created_by` | Manager ID | Who created it |
| `created_at` | Timestamp | When created |
| `description` | Text + Note | Includes "[Created by X on behalf of employee]" |

---

## 💡 **Best Practices**

### **For Managers:**

1. ✅ **Always add a note** in description explaining why you're creating it
2. ✅ **Verify employee details** before submitting
3. ✅ **Communicate with employee** after creating request
4. ✅ **Use for legitimate reasons** only (sick calls, planned leave)

### **For Admins:**

1. ✅ **Monitor who creates what** using audit trails
2. ✅ **Review approval patterns** for compliance
3. ✅ **Train managers** on proper usage
4. ✅ **Consider adding business rules** (e.g., manager can't approve own-created requests)

---

## 🚨 **Important Notes**

### **Current Behavior:**

- ✅ Manager can create request for employee
- ✅ Same manager can approve that request (if they have approval rights)
- ✅ System tracks everything for audit

### **Potential Enhancement (Future):**

You might want to add a rule:
```
"Manager who created a request cannot approve it"
```

This would require:
1. Check if current approver = created_by
2. If yes, show error or skip to next level
3. Ensures separation of duties

---

## 📚 **Summary**

**What You Get:**
- ✅ Managers can create requests for employees via UI
- ✅ No SQL or backend access needed
- ✅ Full audit trail maintained
- ✅ Works with existing approval workflow
- ✅ Attendance integration preserved
- ✅ Visual indicators for transparency

**Use When:**
- Employee can't access system
- Bulk leave entry needed
- Emergency situations
- Pre-planned team leave

**Security:**
- Only admins/managers/supervisors have access
- Backend validation enforces permissions
- All actions tracked and auditable
- Employee identity preserved in all records

---

**This feature makes leave management more flexible while maintaining complete transparency and auditability!** 🎉

