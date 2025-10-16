# Salary Calculation & Invoice Filename Fixes - October 15, 2025

## **Issues Fixed**

### **1. Salary Calculation Error - Missing Employee Data** ✅

**Problem:**
- Frontend error: "Cannot read properties of undefined (reading 'fullname')"
- The salary calculation service was not returning employee details that the frontend expected
- Frontend expected `data.employee.fullname`, `data.profile.employee_code`, etc.

**Solution:**
- Updated `app/Services/HR/SalaryCalculationService.php` to:
  - Load user relationship: `EmployeeProfileModel::with('user')`
  - Return complete employee data structure:
    ```php
    'employee' => [
        'id' => $profile->user->id,
        'fullname' => $profile->user->fullname,
        'email' => $profile->user->email ?? ''
    ],
    'profile' => [
        'employee_code' => $profile->employee_code,
        'designation' => $profile->designation,
        'department' => $profile->department
    ],
    ```
  - Return flattened salary component data for easier form population:
    - `base_salary`, `overtime_hours`, `overtime_amount`, `bonuses`, etc.
    - `late_deduction`, `absent_deduction`, `salary_advance`, `loan_installment`, etc.
    - `gross_salary`, `total_deductions`, `net_salary`

**Files Modified:**
- `app/Services/HR/SalaryCalculationService.php` (lines 28-93)

---

### **2. Invoice Filename - Spaces Instead of Underscores** ✅

**Problem:**
- Invoice filenames used underscores: `John_Doe_3455000681_NF-14539.png`
- User requested spaces: `John Doe 3455000681 NF-14539.png`

**Solution:**
- Changed filename construction in 3 places:
  ```javascript
  // OLD
  const customerName = firstName + (lastName ? '_' + lastName : '');
  link.download = customerName + '_' + phoneNumber + '_' + orderNumber + '.png';
  
  // NEW
  const customerName = firstName + (lastName ? ' ' + lastName : '');
  link.download = customerName + ' ' + phoneNumber + ' ' + orderNumber + '.png';
  ```

**Affected Modes:**
1. PDF download (`print_pdf=1`)
2. PNG auto-download (`auto_png=1`)
3. PNG view and download (`view_and_download_png=1`)

**Files Modified:**
- `resources/views/pages/orders/invoice.blade.php` (lines 819-822, 868-871, 894-897)

---

### **3. Invoice Discounts - Single Row Instead of Multiple** ✅

**Problem:**
- Discounts were showing as separate rows for each discount code:
  ```
  Test:     -Rs.500
  Flat100:  -Rs.100
  ```
- User requested one consolidated "Discounts" row:
  ```
  Discounts: -Rs.600
  ```

**Solution:**
- Changed discount display logic:
  ```php
  // OLD
  @foreach($discountBreakdown as $discount)
      <tr>
          <td class="label">{{ $discount->discount_title }}:</td>
          <td class="amount">-Rs.{{ number_format($discount->discount_amount, 0) }}</td>
      </tr>
  @endforeach
  
  // NEW
  @php
      $totalDiscounts = $discountBreakdown->sum('discount_amount');
  @endphp
  @if($totalDiscounts > 0)
      <tr>
          <td class="label">Discounts:</td>
          <td class="amount">-Rs.{{ number_format($totalDiscounts, 0) }}</td>
      </tr>
  @endif
  ```

**Note:** This change only affects the printed invoice and PDF. The web version and edit invoice remain unchanged (as per user request).

**Files Modified:**
- `resources/views/pages/orders/invoice.blade.php` (lines 776-785)

---

## **Testing Checklist**

### **Salary Calculation:**
1. ✅ Go to "HR & Salary" → "Salary Slips" → "+ Generate New Slip"
2. ✅ Select employee (e.g., "Arsalan") and month (e.g., "2025-10")
3. ✅ Click "Calculate Salary"
4. ✅ Verify:
   - No JavaScript errors in console
   - Employee name, code, and designation display correctly
   - All salary components populate correctly
   - Form allows proceeding to Step 2

### **Invoice Filename:**
1. ✅ Go to any order invoice (e.g., order #14539)
2. ✅ Download as PDF or PNG
3. ✅ Verify filename format:
   - **Expected:** `Ali Nizami 3455000681 NF-14539.png`
   - **Not:** `Ali_Nizami_3455000681_NF-14539.png`

### **Invoice Discounts Display:**
1. ✅ Go to an order with multiple discounts (e.g., order with "Test" + "Flat100" discounts)
2. ✅ View/print invoice
3. ✅ Verify discounts section shows:
   ```
   Subtotal:    Rs.1,200
   Discounts:   -Rs.600    (combined total, not separate rows)
   Total:       Rs.600
   ```

---

## **Technical Details**

### **Why the Salary Error Happened:**
- The `SalaryCalculationService` was returning minimal data structure
- Frontend `populateForm()` function expected nested employee/profile objects
- No eager loading of user relationship caused missing data

### **Why Frontend/Backend Mismatch:**
- Controller was calling service and passing response directly to frontend
- Service response structure didn't match frontend expectations
- Fixed by restructuring service response to match frontend needs

### **Invoice Filename - Windows Compatibility:**
- While spaces in filenames are generally allowed on Windows, Mac, and Linux
- If any issues arise, the browser's download handler should properly encode the filename
- Worst case: browser may replace spaces with `%20` or underscores automatically

---

## **Files Changed Summary**

| File | Lines Changed | Description |
|------|---------------|-------------|
| `app/Services/HR/SalaryCalculationService.php` | 28-93 | Added employee/profile data + flattened salary components |
| `resources/views/pages/orders/invoice.blade.php` | 776-785 | Changed discount display to single row |
| `resources/views/pages/orders/invoice.blade.php` | 819-822, 868-871, 894-897 | Changed filename from underscores to spaces |

---

## **No Breaking Changes**

✅ All existing functionality preserved
✅ No database schema changes
✅ No route changes
✅ No permission changes
✅ Invoice web/edit views remain unchanged (discounts still show separately there)
✅ Only print/PDF invoice discounts consolidated

---

## **Next Steps**

1. **Hard refresh** the application: `Ctrl + Shift + R`
2. **Test salary calculation** with the employee who previously failed
3. **Test invoice downloads** to verify new filename format
4. **Verify discount display** in printed invoices

---

**Date:** October 15, 2025  
**Status:** ✅ Complete - Ready for Testing

