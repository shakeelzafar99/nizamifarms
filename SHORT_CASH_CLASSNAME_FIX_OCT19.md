# Short Cash Class Name Fix - October 19, 2025

## Issue
When submitting a short cash settlement, the system threw an error:
```
Class "App\Models\Request\CategoryModel" not found
```

## Root Cause
The `recordShortCashSettlement()` method in `EmployeeCashController.php` was referencing a non-existent class:
```php
'category_id' => \App\Models\Request\CategoryModel::where('category_code', 'expense')->first()->id,
```

The correct class name is `RequestCategoryModel`, not `CategoryModel`.

## Fix Applied

### File: `app/Http/Controllers/FIN/EmployeeCashController.php`

**Before (Line 1278):**
```php
$expenseRequest = \App\Models\Request\RequestModel::create([
    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
    'category_id' => \App\Models\Request\CategoryModel::where('category_code', 'expense')->first()->id,
    'requester_user_id' => $employeeAccount->user_id,
    // ...
]);
```

**After (Lines 1276-1293):**
```php
// Create EXPENSE REQUEST for the shortage (from rider balance)
$category = \App\Models\Request\RequestCategoryModel::where('category_code', 'expense')->first();

if (!$category) {
    throw new \Exception("Expense category not found in system. Please contact administrator.");
}

$expenseRequest = \App\Models\Request\RequestModel::create([
    'request_number' => \App\Models\Request\RequestModel::generateRequestNumber(),
    'category_id' => $category->id,
    'requester_user_id' => $employeeAccount->user_id,
    'amount' => $shortCashAmount,
    'expense_category' => $request->expense_category,
    'description' => "Short cash from invoice settlement - " . $request->expense_category . ($request->description ? " - {$request->description}" : ""),
    'payment_source_account_id' => $employeeAccount->id, // From rider's balance
    'status' => \App\Models\Request\RequestModel::STATUS_PENDING,
    'settlement_status' => 'not_required', // Paid from rider balance
    'created_by' => auth()->id(),
]);
```

## Changes Made
1. **Fixed class name**: Changed `CategoryModel` to `RequestCategoryModel`
2. **Added error handling**: Now checks if the expense category exists in the database before proceeding
3. **Improved code structure**: Separated the category lookup from the create statement for better readability and error handling

## Verification
- ✅ Correct model class: `App\Models\Request\RequestCategoryModel`
- ✅ Table name: `t_req_category`
- ✅ Category code lookup: `'expense'` (from database migrations)
- ✅ All required columns exist in `t_req_master` table
- ✅ All columns are in the `RequestModel::$fillable` array
- ✅ Route is correctly defined in `routes/web.php`
- ✅ Form action points to correct route in `show.blade.php`

## Testing Checklist
- [ ] Submit a short cash settlement with valid data
- [ ] Verify expense request is created with correct category
- [ ] Verify deposit transaction is created with settlement metadata
- [ ] Check that both transactions show as pending approval
- [ ] Verify error message if expense category is missing from database

## Related Files
- `app/Http/Controllers/FIN/EmployeeCashController.php` (line 1276)
- `app/Models/Request/RequestCategoryModel.php`
- `app/Models/Request/RequestModel.php`
- `resources/views/fin/employee/show.blade.php`
- `routes/web.php` (line 350)

## Database Dependencies
The fix assumes the following data exists in the database:
```sql
-- Check if expense category exists
SELECT * FROM t_req_category WHERE category_code = 'expense';
```

If this record doesn't exist, the system will now throw a clear error message instead of a class not found error.

### Verification Script
Run `verify_expense_category.sql` to:
1. Check if the expense category exists
2. Automatically create it if missing
3. Display the category details

This script is safe to run multiple times and will only insert the category if it doesn't already exist.

