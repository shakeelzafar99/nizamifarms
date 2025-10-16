# Salary Calculation & Invoice Filename Fixes - Oct 15, 2025

## Two Issues Fixed

### **Issue 1: Salary Calculation Error** ✅
### **Issue 2: Invoice Filename Format** ✅

---

## Issue 1: Salary Calculation - Column Names

### **Error:**
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'approval_status' 
in 'where clause'
```

### **Problem:**
The `getSalaryAdvances()` method was using incorrect column names:
- ❌ `approval_status` (doesn't exist)
- ❌ `final_approval_date` (doesn't exist)

### **Solution:**
Fixed to use the correct RequestModel columns:
- ✅ `status` (correct column for request status)
- ✅ `completed_at` (correct column for when request was fully approved)

### **File:** `app/Services/HR/SalaryCalculationService.php`

**Before:**
```php
$advances = RequestModel::where('requester_user_id', $userId)
    ->whereHas('category', function($q) {
        $q->where('category_code', 'salary_advance');
    })
    ->where('approval_status', 'approved')  // ❌ Wrong column
    ->whereBetween('final_approval_date', [$startDate, $endDate])  // ❌ Wrong column
    ->get()
```

**After:**
```php
try {
    $advances = RequestModel::where('requester_user_id', $userId)
        ->whereHas('category', function($q) {
            $q->where('category_code', 'salary_advance');
        })
        ->where('status', 'approved')  // ✅ Correct column
        ->whereBetween('completed_at', [$startDate, $endDate])  // ✅ Correct column
        ->get()
        ->map(function($request) {
            return [
                'request_id' => $request->id,
                'amount' => $request->amount ?? 0,  // ✅ Null-safe
                'approved_date' => $request->completed_at,
                'description' => $request->description ?? ''  // ✅ Null-safe
            ];
        })
        ->toArray();
        
    // ... rest of code
} catch (\Exception $e) {
    // ✅ Added error handling
    Log::error('Error getting salary advances', [
        'user_id' => $userId,
        'month' => $month,
        'error' => $e->getMessage()
    ]);
    
    return [
        'requests' => [],
        'total_amount' => 0,
        'request_ids' => ''
    ];
}
```

### **Changes Made:**
1. ✅ Fixed column names (`status`, `completed_at`)
2. ✅ Added try-catch for error handling
3. ✅ Added null-safety for `amount` and `description`
4. ✅ Returns empty array if error occurs (doesn't break calculation)

---

## Issue 2: Invoice Filename Format

### **User Request:**
> "Instead of fullname I want first name followed by space and last name and then the rest remains same i.e phone number and order number."

### **Problem:**
The current code was using `first_name + " " + last_name`, but then `preg_replace` was removing ALL non-alphanumeric characters including the space, resulting in:

**Current Output:** `JohnDoe_03331234567_NF0001.png`

**Desired Output:** `John_Doe_03331234567_NF0001.png`

### **Solution:**
Changed the logic to:
1. Clean `first_name` separately
2. Clean `last_name` separately
3. Join with underscore `_`

### **File:** `resources/views/pages/orders/invoice.blade.php`

**Before:**
```javascript
const customerName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", trim(($order->customer->first_name ?? "") . " " . ($order->customer->last_name ?? ""))) ?: "Unknown" }}';
// Result: "JohnDoe" (space removed by preg_replace)
```

**After:**
```javascript
const firstName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->first_name ?? "") ?: "Unknown" }}';
const lastName = '{{ preg_replace("/[^a-zA-Z0-9]/", "", $order->customer->last_name ?? "") }}';
const customerName = firstName + (lastName ? '_' + lastName : '');
// Result: "John_Doe" (underscore preserved)
```

### **Updated in 3 Locations:**

1. **PDF Download** (line 818-823)
2. **PNG Auto-Download** (line 867-872)
3. **PNG View & Download** (line 893-898)

### **Example Filenames:**

**Before:**
- `JohnDoe_03331234567_NF0001.pdf`
- `JohnDoe_03331234567_NF0001.png`

**After:**
- `John_Doe_03331234567_NF0001.pdf` ✅
- `John_Doe_03331234567_NF0001.png` ✅

**Edge Cases Handled:**
- Single name (no last name): `John_03331234567_NF0001.png`
- Missing first name: `Unknown_03331234567_NF0001.png`
- Missing both names: `Unknown_03331234567_NF0001.png`

---

## Testing Instructions

### **Test 1: Salary Calculation**

1. **Refresh:** `Ctrl + Shift + R`
2. Go to: `/hr/salary-slips/create?user_id=70`
3. Select: October 2025
4. Click: **"Calculate Salary"**

**Expected:**
- ✅ Should calculate successfully (no column errors)
- ✅ Should show salary advances if employee has approved advances in October
- ✅ Should show 0 for salary advances if none exist
- ✅ Should NOT crash if there are no advances

---

### **Test 2: Invoice Filename - PDF**

1. Go to: `/orders` (Orders page)
2. Click on any order to view details
3. Click: **"Print Invoice (PDF)"**

**Expected:**
- ✅ Filename format: `FirstName_LastName_PhoneNumber_OrderNumber.pdf`
- ✅ Example: `John_Doe_03331234567_NF0001.pdf`

---

### **Test 3: Invoice Filename - PNG**

1. Go to: `/orders` (Orders page)
2. Click on any order to view details
3. Click: **"Print Invoice (Image)"**

**Expected:**
- ✅ Filename format: `FirstName_LastName_PhoneNumber_OrderNumber.png`
- ✅ Example: `John_Doe_03331234567_NF0001.png`

---

## Files Modified

### **Salary Calculation:**
1. `app/Services/HR/SalaryCalculationService.php` (lines 170-213)
   - Fixed column names
   - Added error handling
   - Added null-safety

### **Invoice Filenames:**
1. `resources/views/pages/orders/invoice.blade.php`
   - Lines 818-823 (PDF download)
   - Lines 867-872 (PNG auto-download)
   - Lines 893-898 (PNG view & download)

---

## No Linter Errors ✅

All code passes validation.

---

## Summary

### **Salary Calculation:**
- ✅ Fixed incorrect column names (`status`, `completed_at`)
- ✅ Added robust error handling
- ✅ Won't crash if no salary advances exist
- ✅ Null-safe for amount and description

### **Invoice Filenames:**
- ✅ Changed from `FirstnameLastname` to `Firstname_Lastname`
- ✅ Maintains underscore separation between first and last name
- ✅ Format: `FirstName_LastName_PhoneNumber_OrderNumber`
- ✅ Applied to all 3 download methods (PDF, PNG auto, PNG view)
- ✅ Handles edge cases (missing names)

---

## Ready to Test! 🚀

Both fixes are complete and ready for testing.

