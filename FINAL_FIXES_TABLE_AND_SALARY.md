# Final Fixes - Table Missing & Employee Screen Update

## Issues Fixed

### **1. SQL Error - Missing Table `t_ops_user_shifts`** ✅

**Error:**
```
SQLSTATE[42S02]: Base table or view not found: 1146 Table 'nizamifarms_db.t_ops_user_shifts' doesn't exist
```

**Problem:** The salary calculation query was trying to LEFT JOIN with a table `t_ops_user_shifts` that doesn't exist in your database.

**Solution:** Removed the join with `t_ops_user_shifts` and only use `t_ops_rider_profile` for shift information.

**Before:**
```sql
SELECT 
    a.attendance_date,
    a.login_time,
    a.logout_time,
    COALESCE(us.shift_start, rp.shift_start, '09:00:00') as shift_start,
    COALESCE(us.shift_end, rp.shift_end, '17:00:00') as shift_end
FROM t_ops_attendance a
LEFT JOIN t_ops_user_shifts us ON us.user_id = a.user_id  -- ❌ This table doesn't exist
LEFT JOIN t_ops_rider_profile rp ON rp.user_id = a.user_id
```

**After:**
```sql
SELECT 
    a.attendance_date,
    a.login_time,
    a.logout_time,
    COALESCE(rp.shift_start, '09:00:00') as shift_start,  -- ✅ Only use rider profile
    COALESCE(rp.shift_end, '17:00:00') as shift_end       -- ✅ Defaults to 9-5
FROM t_ops_attendance a
LEFT JOIN t_ops_rider_profile rp ON rp.user_id = a.user_id
```

**Files Changed:**
- `app/Services/HR/SalaryCalculationService.php` (lines 126-139)

---

### **2. Employee Screen - Replace Dept/Division with Loan & Advances** ✅

**User Request:** 
> "Instead of dept and division can I see total loan unpaid amount and total salary adv amount that's not already adjusted in his salary"

**Changes Made:**

#### **A. Backend - Calculate New Data**

Added two new calculated fields in `EmployeeProfileController::getData()`:

1. **`total_loan_outstanding`:**
   - Sum of `outstanding_balance` from all active loans

2. **`unadjusted_salary_advances`:**
   - Sum of approved salary advance requests
   - That have NOT been included in a paid/approved salary slip
   - Uses `FIND_IN_SET` to check if request ID is in `advance_request_ids` column

```php
// Calculate total outstanding loans
$totalLoanOutstanding = 0;
if ($user->hrProfile && $user->hrProfile->activeLoans) {
    $totalLoanOutstanding = $user->hrProfile->activeLoans->sum('outstanding_balance');
}

// Calculate unadjusted salary advances
$unadjustedAdvances = \DB::table('t_adm_request as r')
    ->join('t_adm_request_category as c', 'r.category_id', '=', 'c.id')
    ->where('r.requester_user_id', $user->id)
    ->where('c.category_code', 'salary_advance')
    ->where('r.approval_status', 'approved')
    ->whereNotExists(function($query) use ($user) {
        $query->select(\DB::raw(1))
            ->from('t_hr_salary_slip')
            ->whereRaw('FIND_IN_SET(r.id, advance_request_ids)')
            ->where('user_id', $user->id)
            ->whereIn('slip_status', ['approved', 'paid']);
    })
    ->sum('r.amount');
```

**Files Changed:**
- `app/Http/Controllers/HR/EmployeeProfileController.php` (lines 71-111)

---

#### **B. Frontend - Update Table Columns**

**Header Changes:**
```html
<!-- BEFORE -->
<th>Designation</th>
<th>Department</th>

<!-- AFTER -->
<th class="text-right">Loan Outstanding</th>
<th class="text-right">Salary Adv. Pending</th>
```

**Row Data Changes:**
```javascript
// BEFORE
<td>${profile?.designation || '-'}</td>
<td>${profile?.department || '-'}</td>

// AFTER (with color coding!)
<td class="text-right ${emp.total_loan_outstanding > 0 ? 'text-red-600 font-semibold' : 'text-gray-500'}">
    ${emp.total_loan_outstanding > 0 ? formatCurrency(emp.total_loan_outstanding) : '-'}
</td>
<td class="text-right ${emp.unadjusted_salary_advances > 0 ? 'text-orange-600 font-semibold' : 'text-gray-500'}">
    ${emp.unadjusted_salary_advances > 0 ? formatCurrency(emp.unadjusted_salary_advances) : '-'}
</td>
```

**Color Coding:**
- 🔴 **Red (bold):** Loan outstanding > 0
- 🟠 **Orange (bold):** Salary advances pending > 0
- ⚪ **Gray:** No amount (shows "-")

**Files Changed:**
- `resources/views/pages/hr/employees/index.blade.php` (lines 79-315)

---

## How It Works

### **Loan Outstanding**
Shows the total unpaid amount from all active employee loans:
```
Example:
Loan 1: PKR 50,000 (outstanding: PKR 30,000)
Loan 2: PKR 20,000 (outstanding: PKR 15,000)
-------------------------------------------------
DISPLAY: PKR 45,000 (in red)
```

### **Salary Advance Pending (Unadjusted)**
Shows salary advances that haven't been adjusted in a salary slip yet:

```
Timeline:
1. Employee takes advance: PKR 10,000 (Sept 15)
   → Shows: PKR 10,000 (orange)

2. Salary slip generated for September
   → Advance included in slip
   → Now shows: PKR 0 (or "-")

3. New advance taken: PKR 5,000 (Oct 10)
   → Shows: PKR 5,000 (orange)
```

**Logic:**
- ✅ Approved salary advance requests
- ❌ NOT included in any `approved` or `paid` salary slip
- ✅ Only counts advances that still need to be deducted

---

## Testing Instructions

### **Test 1: Salary Calculation (Fixed Table Error)**
1. Go to: `/hr/salary-slips/create?user_id=70`
2. Select: October 2025
3. Click: **"Calculate Salary"**
4. **Expected:** 
   - ✅ Should calculate successfully (no table error)
   - ✅ Should show salary breakdown
   - ✅ Shifts default to 9:00-17:00 if no rider profile

### **Test 2: Employee Screen - New Columns**
1. Go to: `/hr/employees`
2. **Expected Table Columns:**
   - Code
   - Employee Name
   - **Loan Outstanding** (NEW - instead of Designation)
   - **Salary Adv. Pending** (NEW - instead of Department)
   - Base Salary
   - OT Rate/hr
   - Late Deduction/hr
   - Status
   - Actions

3. **Expected Behavior:**
   - Employees with loans → Red amount shown
   - Employees with pending advances → Orange amount shown
   - No loans/advances → Shows "-" in gray

### **Test 3: Verify Unadjusted Advances Logic**
1. Create a salary advance request for an employee
2. Approve it
3. Check `/hr/employees` → Should show in orange
4. Generate and approve salary slip for that month
5. Check `/hr/employees` again → Should now show "-" (adjusted)

---

## Files Modified

1. **`app/Services/HR/SalaryCalculationService.php`**
   - Removed `t_ops_user_shifts` join
   - Now only uses `t_ops_rider_profile` for shift info

2. **`app/Http/Controllers/HR/EmployeeProfileController.php`**
   - Added `total_loan_outstanding` calculation
   - Added `unadjusted_salary_advances` calculation
   - Returns these new fields in API response

3. **`resources/views/pages/hr/employees/index.blade.php`**
   - Updated table headers
   - Updated row rendering with new columns
   - Added color coding (red for loans, orange for advances)

---

## No Linter Errors ✅

All files pass linting checks.

---

## Summary

### **Fixed:**
1. ✅ SQL error (missing `t_ops_user_shifts` table)
2. ✅ Employee screen now shows loan outstanding
3. ✅ Employee screen now shows unadjusted salary advances
4. ✅ Color-coded for easy identification (red/orange/gray)

### **Logic:**
- Loan Outstanding = Sum of all active loan balances
- Unadjusted Advances = Approved advances NOT in any paid/approved salary slip

### **Ready to Test!** 🚀

Refresh the page and try creating a salary slip for Arsalan (October 2025).

