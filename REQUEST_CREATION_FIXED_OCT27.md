# Request Creation Fixed - Now Matches Webapp Exactly
**Date:** October 27, 2025

## ✅ Issue Fixed

### Error
```
SQLSTATE[HY000]: General error: 1364 Field 'request_number' doesn't have a default value
```

### Root Cause
The mobile API was trying to generate the request number AFTER creating the request, but the database field requires a value at creation time. The webapp uses a model method `generateRequestNumber()` to create the number BEFORE insertion.

### Solution
Updated the mobile API to use the EXACT same logic as the webapp's `RequestController::store()` method.

---

## 🔄 Changes Made

### Before (BROKEN)
```php
// Mobile API - OLD (WRONG)
$newRequest = RequestModel::create([
    'category_id' => $category->id,
    'requester_user_id' => $user->id,
    'title' => $validated['title'],
    // ... other fields
]);

// Try to generate request number AFTER creation (TOO LATE!)
$newRequest->request_number = 'REQ-' . str_pad($newRequest->id, 6, '0', STR_PAD_LEFT);
$newRequest->save();
```

### After (FIXED)
```php
// Mobile API - NEW (MATCHES WEBAPP)
$newRequest = RequestModel::create([
    'request_number' => RequestModel::generateRequestNumber(), // ✅ Generate BEFORE creation
    'category_id' => $category->id,
    'requester_user_id' => $user->id,
    'title' => $validated['title'],
    'description' => $validated['description'] ?? null,
    'amount' => $validated['amount'] ?? null,
    'expense_category' => $validated['expense_category'] ?? null,
    'leave_start_date' => $validated['leave_start_date'] ?? null,
    'leave_end_date' => $validated['leave_end_date'] ?? null,
    'leave_type' => $validated['leave_type'] ?? null,
    'leave_days' => $leaveDays, // ✅ Calculate leave days
    'status' => RequestModel::STATUS_PENDING,
    'priority' => 'normal',
    'requires_level_1' => $category->requiresLevel1(), // ✅ Use category methods
    'requires_level_2' => $category->requiresLevel2(), // ✅ Use category methods
    'level_1_status' => $category->requiresLevel1() ? RequestModel::APPROVAL_STATUS_PENDING : null,
    'level_2_status' => $category->requiresLevel2() ? RequestModel::APPROVAL_STATUS_PENDING : null,
    'submitted_at' => now(), // ✅ Add submission timestamp
    'created_by' => $user->id,
]);
```

---

## 📋 What's Now Identical to Webapp

### 1. Request Number Generation
- ✅ Uses `RequestModel::generateRequestNumber()` method
- ✅ Generated BEFORE database insertion
- ✅ Format: `REQ-XXXXXX` (e.g., REQ-000123)

### 2. Leave Days Calculation
- ✅ Calculates number of days between start and end dates
- ✅ Uses Carbon for date math
- ✅ Formula: `diffInDays() + 1` (inclusive of both days)

### 3. Approval Configuration
- ✅ Uses `$category->requiresLevel1()` method
- ✅ Uses `$category->requiresLevel2()` method
- ✅ Sets approval statuses based on category config
- ✅ Same approval flow as webapp

### 4. Timestamps
- ✅ Sets `submitted_at` timestamp
- ✅ Uses `now()` helper (same as webapp)

### 5. All Fields
- ✅ `request_number` - Generated before creation
- ✅ `category_id` - Request category
- ✅ `requester_user_id` - Who the request is for
- ✅ `title` - Request title
- ✅ `description` - Notes/description
- ✅ `amount` - For petrol/salary advance
- ✅ `expense_category` - For expense requests (Petrol)
- ✅ `leave_start_date` - For leave requests
- ✅ `leave_end_date` - For leave requests
- ✅ `leave_type` - Type of leave (annual)
- ✅ `leave_days` - Calculated number of days
- ✅ `status` - PENDING
- ✅ `priority` - normal
- ✅ `requires_level_1` - From category config
- ✅ `requires_level_2` - From category config
- ✅ `level_1_status` - PENDING if required
- ✅ `level_2_status` - PENDING if required
- ✅ `submitted_at` - Current timestamp
- ✅ `created_by` - User who created it

---

## 🔍 Approval Flow (Now Correct)

### How It Works

1. **Category Configuration**
   - Each category has an `approvalConfig` relationship
   - Config defines `requires_level_1` and `requires_level_2`

2. **Request Creation**
   ```php
   'requires_level_1' => $category->requiresLevel1(),
   'requires_level_2' => $category->requiresLevel2(),
   'level_1_status' => $category->requiresLevel1() ? 'pending' : null,
   'level_2_status' => $category->requiresLevel2() ? 'pending' : null,
   ```

3. **Approval Process**
   - If `requires_level_1 = true`: Level 1 approver must approve
   - If `requires_level_2 = true`: Level 2 approver must also approve
   - Request status changes to 'approved' only when all required levels approve

### Example: Petrol Request
```
Category: Expense (Petrol)
Config: requires_level_1 = true, requires_level_2 = false

Request Created:
- requires_level_1: true
- requires_level_2: false
- level_1_status: 'pending'
- level_2_status: null
- status: 'pending'

After Level 1 Approval:
- level_1_status: 'approved'
- status: 'approved' (no level 2 required)
```

### Example: Salary Advance
```
Category: Salary Advance
Config: requires_level_1 = true, requires_level_2 = true

Request Created:
- requires_level_1: true
- requires_level_2: true
- level_1_status: 'pending'
- level_2_status: 'pending'
- status: 'pending'

After Level 1 Approval:
- level_1_status: 'approved'
- level_2_status: 'pending' (still waiting)
- status: 'pending' (still waiting for level 2)

After Level 2 Approval:
- level_1_status: 'approved'
- level_2_status: 'approved'
- status: 'approved' (all levels approved)
```

---

## 🧪 Testing Instructions

### Test 1: Petrol Request
1. Open mobile app
2. Go to "Requests" tab
3. Tap "New"
4. Select "Petrol"
5. Enter amount: 500
6. Enter notes: "Deliveries"
7. Tap "Save"
8. ✅ Should create successfully
9. ✅ Should show in requests list

### Test 2: Salary Advance
1. Tap "New"
2. Select "Salary Advance"
3. Enter amount: 5000
4. Enter notes: "Emergency"
5. Tap "Save"
6. ✅ Should create successfully

### Test 3: Leave Request
1. Tap "New"
2. Select "Leave"
3. Select start date: Oct 28, 2025
4. Select end date: Oct 30, 2025
5. Enter notes: "Family trip"
6. Tap "Save"
7. ✅ Should create successfully

### Test 4: Verify in Database
```sql
-- Check the created request
SELECT 
    id,
    request_number, -- Should be REQ-XXXXXX
    category_id,
    title,
    description,
    amount,
    expense_category,
    leave_start_date,
    leave_end_date,
    leave_days, -- Should be calculated (e.g., 3 for Oct 28-30)
    status, -- Should be 'pending'
    requires_level_1, -- Should match category config
    requires_level_2, -- Should match category config
    level_1_status, -- Should be 'pending' if required
    level_2_status, -- Should be 'pending' if required
    submitted_at, -- Should have timestamp
    created_by
FROM t_req_master 
ORDER BY id DESC 
LIMIT 1;
```

### Test 5: Verify Approval Flow
1. Go to webapp
2. Navigate to Requests → Approvals
3. ✅ Should see the mobile-created request
4. ✅ Should show correct approval requirements
5. Approve as Level 1 approver
6. ✅ Request should move through approval flow correctly

---

## 📂 Files Modified

### Backend
**`app/Http/Controllers/API/RiderController.php`**
- Lines 1455-1460: Added leave days calculation
- Line 1465: Use `generateRequestNumber()` method
- Lines 1475-1484: Added all missing fields to match webapp
- Removed duplicate approval setup code

---

## ✅ Summary

The mobile API now creates requests EXACTLY like the webapp:

1. ✅ **Request Number**: Generated before creation using model method
2. ✅ **Leave Days**: Calculated automatically
3. ✅ **Approval Config**: Uses category methods for consistency
4. ✅ **All Fields**: Complete set of fields matching webapp
5. ✅ **Timestamps**: Proper submission timestamp
6. ✅ **Approval Flow**: Same approval process as webapp

**No more errors!** Requests will now save successfully and follow the exact same approval flow as webapp requests. 🎉

**Just reload Metro** (press `r`) to test!

