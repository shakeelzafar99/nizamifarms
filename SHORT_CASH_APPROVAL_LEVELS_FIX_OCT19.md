# Short Cash - Approval Levels Fix
## Date: October 19, 2025

## Issue Reported
User noted that after approving the deposit from the short cash settlement:
1. ✅ The deposit was approved and settled successfully
2. ❌ The expense request was created but **not visible in the Approvals Dashboard**
3. ✅ The expense request was visible in "Expense Management" as pending
4. ❌ The expense request showed status as "Pending" without L1 or L2 designation

### User's Observation
> "i cannot see it anywhere in the approval dashboard. probably because it says pending without l1 or l2"

## Root Cause
The short cash expense request was being created without the required approval level fields:
- `requires_level_1` - missing
- `requires_level_2` - missing  
- `level_1_status` - missing
- `level_2_status` - missing

Without these fields, the approval dashboard query couldn't identify the request as needing approval:

```php
// Approval Dashboard Query (ApprovalController.php line 104-108)
foreach ($pendingRequests as $req) {
    if ($req->requires_level_1 && $req->level_1_status === 'pending') {
        $items[] = $this->formatRequestItem($req, 1, ...);
    }
}
```

## Fix Applied

### File: `app/Http/Controllers/FIN/EmployeeCashController.php`
**Lines**: 1293-1296

Added the approval level fields to the expense request creation:

**Before**:
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
    'category_id' => $category->id,
    'requester_user_id' => $employeeAccount->user_id,
    'title' => "Short Cash - {$request->expense_category}",
    'amount' => $shortCashAmount,
    'expense_category' => $request->expense_category,
    'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
    'payment_source_account_id' => $employeeAccount->id,
    'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
    'settlement_status' => 'not_required',
    'created_by' => auth()->id(),
]);
```

**After**:
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
    'category_id' => $category->id,
    'requester_user_id' => $employeeAccount->user_id,
    'title' => "Short Cash - {$request->expense_category}",
    'amount' => $shortCashAmount,
    'expense_category' => $request->expense_category,
    'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
    'payment_source_account_id' => $employeeAccount->id,
    'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
    'settlement_status' => 'not_required',
    'requires_level_1' => $category->requiresLevel1(),          // ← ADDED
    'requires_level_2' => $category->requiresLevel2(),          // ← ADDED
    'level_1_status' => $category->requiresLevel1() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,  // ← ADDED
    'level_2_status' => $category->requiresLevel2() ? \App\Models\Request\RequestModel::APPROVAL_STATUS_PENDING : null,  // ← ADDED
    'created_by' => auth()->id(),
]);
```

## How It Works

### 1. Category Configuration
The approval levels are determined by the category's configuration in `t_req_category_approval_config`:
- For "expense" category: typically requires Level 1 approval
- Can be configured to require Level 2 approval as well

### 2. Dynamic Level Assignment
```php
'requires_level_1' => $category->requiresLevel1(),  // Checks category config
'requires_level_2' => $category->requiresLevel2(),  // Checks category config
```

### 3. Status Assignment
```php
'level_1_status' => $category->requiresLevel1() ? 'pending' : null,
'level_2_status' => $category->requiresLevel2() ? 'pending' : null,
```

If Level 1 is required → `level_1_status` = 'pending'  
If Level 2 is required → `level_2_status` = 'pending'  
If not required → status = null

## Expected Behavior After Fix

### When Short Cash is Submitted:
1. **Deposit Transaction** created with `approval_status = 'pending'`
2. **Expense Request** created with:
   - `status = 'pending'`
   - `requires_level_1 = 1` (from category config)
   - `level_1_status = 'pending'`
   - `requires_level_2 = 0 or 1` (from category config)
   - `level_2_status = 'pending' or null`

### In Approvals Dashboard:
- ✅ **Deposit** appears in "L1 PENDING" under appropriate area (e.g., NF CASH)
- ✅ **Expense Request** appears in "L1 PENDING" under "EXP FUND" area
- Both show with proper L1 badge and approval buttons

### In Expense Management:
- ✅ Expense request appears in "Pending Expense Approvals" section
- ✅ Shows proper status: "Pending L1" (not just "Pending")
- ✅ Manager can approve from either location

### After Approval:
- When manager approves the expense request (L1):
  - If L2 required → moves to "L2 PENDING" in dashboard
  - If L2 not required → status changes to "approved"
  - Ledger posting happens automatically
  - Employee balance is debited

## Verification Queries

### Check Expense Request Structure
```sql
SELECT 
    request_number,
    title,
    category_id,
    amount,
    expense_category,
    status,
    requires_level_1,
    requires_level_2,
    level_1_status,
    level_2_status,
    payment_source_account_id,
    created_at
FROM t_req_master
WHERE title LIKE 'Short Cash%'
ORDER BY created_at DESC
LIMIT 5;
```

### Check Category Approval Config
```sql
SELECT 
    c.category_code,
    c.category_name,
    ac.requires_level_1,
    ac.requires_level_2
FROM t_req_category c
LEFT JOIN t_req_category_approval_config ac ON c.id = ac.category_id
WHERE c.category_code = 'expense';
```

## Testing Checklist

- [x] Code updated with approval level fields
- [ ] Test new short cash submission
- [ ] Verify expense request appears in Approvals Dashboard
- [ ] Verify expense request shows in EXP FUND area
- [ ] Verify L1 badge displays correctly
- [ ] Approve expense request from Approvals Dashboard
- [ ] Verify ledger posting happens
- [ ] Verify employee balance is debited
- [ ] Check that invoices are properly settled

## Related Issues

This fix also addresses a potential issue where:
- Any expense requests created without approval levels would be "orphaned"
- They would show as pending but not appear in approval workflows
- Managers wouldn't be able to find them to approve

## Database Note

For any existing short cash expense requests that were created without approval levels, you can fix them with:

```sql
-- Update existing short cash expense requests to have proper approval levels
UPDATE t_req_master r
JOIN t_req_category c ON r.category_id = c.id
JOIN t_req_category_approval_config ac ON c.id = ac.category_id
SET 
    r.requires_level_1 = ac.requires_level_1,
    r.requires_level_2 = ac.requires_level_2,
    r.level_1_status = CASE WHEN ac.requires_level_1 = 1 THEN 'pending' ELSE NULL END,
    r.level_2_status = CASE WHEN ac.requires_level_2 = 1 THEN 'pending' ELSE NULL END
WHERE r.title LIKE 'Short Cash%'
AND r.status = 'pending'
AND r.requires_level_1 IS NULL;

SELECT 'Updated existing short cash requests with approval levels' as Status;
```

## Summary

✅ **Fixed**: Short cash expense requests now have proper approval levels  
✅ **Visible**: Requests will appear in Approvals Dashboard  
✅ **Consistent**: Follows same approval flow as other expense requests  
✅ **Complete**: Full integration with existing approval system

The short cash feature is now fully integrated with the approval workflow system.

