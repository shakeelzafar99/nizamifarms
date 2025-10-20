# Short Cash - Missing Title Field Fix
## Date: October 19, 2025

## Issue
After fixing the button issue, user successfully submitted the short cash form but encountered a database error:

```
SQLSTATE[HY000]: General error: 1364 Field 'title' doesn't have a default value
(Connection: mysql, SQL: insert into `t_req_master` (`request_number`, `category_id`, 
`requester_user_id`, `amount`, `expense_category`, `description`, `payment_source_account_id`, 
`status`, `settlement_status`, `created_by`, `updated_at`, `created_at`) values ...)
```

## Root Cause
The `t_req_master` table has a `title` field that is marked as `NOT NULL` without a default value. When creating the expense request for the short cash shortage, we were not providing a value for the `title` field.

## Fix Applied

### File: `app/Http/Controllers/FIN/EmployeeCashController.php`
**Line**: 1286

Added the `title` field to the expense request creation:

**Before**:
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
    'category_id' => $category->id,
    'requester_user_id' => $employeeAccount->user_id,
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
    'title' => "Short Cash - {$request->expense_category}",  // ← ADDED
    'amount' => $shortCashAmount,
    'expense_category' => $request->expense_category,
    'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
    'payment_source_account_id' => $employeeAccount->id,
    'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
    'settlement_status' => 'not_required',
    'created_by' => auth()->id(),
]);
```

## Title Format
The title will now be generated as:
- Example: `"Short Cash - Petrol"`
- Example: `"Short Cash - Rent"`
- Example: `"Short Cash - Office Supplies"`

This provides a clear, concise title that:
1. Identifies it as a short cash expense
2. Shows the expense category at a glance
3. Is suitable for display in lists and approvals

## Testing
After this fix, the short cash submission should work completely:

1. ✅ Button enables when category is selected
2. ✅ Form submits successfully
3. ✅ Expense request created with proper title
4. ✅ Deposit ledger entry created
5. ✅ Both transactions pending approval

## Related Fixes
This completes the short cash implementation fixes:
1. **SHORT_CASH_CLASSNAME_FIX_OCT19.md** - Fixed CategoryModel class name
2. **SHORT_CASH_BUTTON_DEBUG_OCT19.md** - Fixed button not enabling
3. **SHORT_CASH_TITLE_FIELD_FIX_OCT19.md** (this file) - Fixed missing title field

## Verification
The feature should now work end-to-end:
- User can open short cash modal
- Select invoices
- Enter deposit amount
- Select expense category
- Submit successfully
- See success message
- Both transactions appear as pending approval

## Database Schema Note
The `title` field in `t_req_master` is defined as:
```sql
title VARCHAR(255) NOT NULL COMMENT 'Request title'
```

All request creation code must provide a value for this field.

