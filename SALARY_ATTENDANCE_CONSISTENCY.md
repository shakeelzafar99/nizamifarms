# Salary & Attendance Consistency - Oct 15, 2025

## Problem Identified

The salary calculation was using a **simple approximation** for working days (26 days per month), while the attendance reports use a **sophisticated shift-based calculation** that considers:

1. User's shift schedule (which days they work)
2. Public holidays from the database
3. Accurate day-by-day counting

This would cause **mismatches** between attendance reports and salary calculations.

---

## Solution Applied

### **Now Using EXACT Same Logic** ✅

Both attendance reports and salary calculations now use the **same service**: `ShiftResolutionService`

---

## How It Works

### **ShiftResolutionService::calculateWorkingDays()**

```php
public function calculateWorkingDays(int $userId, string $startDate, string $endDate): int
{
    // 1. Get user's shift (e.g., Mon-Fri, Sat-Sun, etc.)
    $shift = $this->getUserShift($userId, $lookupDate);
    $workingDaysOfWeek = $shift['working_days']; // e.g., [1,2,3,4,5] = Mon-Fri
    
    // 2. Get public holidays from database
    $holidays = PublicHolidayModel::getHolidaysInRange($startDate, $endDate);
    
    // 3. Iterate through each day in the date range
    $workingDays = 0;
    foreach ($dateRange as $date) {
        $dayOfWeek = (int)$date->format('N'); // 1=Mon, 7=Sun
        
        // Count if it's BOTH a working day AND not a holiday
        if (in_array($dayOfWeek, $workingDaysOfWeek) && !in_array($date, $holidays)) {
            $workingDays++;
        }
    }
    
    return $workingDays;
}
```

---

## Example Calculation

### **October 2025 - Employee with Mon-Fri Shift**

**Month Details:**
- Total days: 31
- Weekdays (Mon-Fri): 23 days
- Weekends (Sat-Sun): 8 days

**Public Holidays (example):**
- October 10, 2025 (Friday) - Holiday

**Working Days Calculation:**
```
Weekdays:          23 days
Minus holidays:    -1 day
----------------------------
Working days:      22 days  ✅
```

**Same calculation will be used in:**
- ✅ Attendance Report
- ✅ Salary Slip Generation
- ✅ Absent Days Calculation

---

## Database Tables Used

### **1. Shift Information**
**Source:** `t_ops_rider_profile` table
- Contains `working_days_json` column
- Format: `[1,2,3,4,5]` (1=Mon, 2=Tue, etc.)
- Example: `[1,2,3,4,5,6]` = Mon-Sat

### **2. Public Holidays**
**Table:** `t_ops_public_holidays` (NOT `t_ops_holidays`)
- Columns: `id`, `holiday_date`, `holiday_name`, `is_active`
- Example data:
  ```sql
  INSERT INTO t_ops_public_holidays (holiday_date, holiday_name) VALUES
  ('2025-01-01', 'New Year'),
  ('2025-03-23', 'Pakistan Day'),
  ('2025-08-14', 'Independence Day');
  ```

---

## Changes Made

### **File:** `app/Services/HR/SalaryCalculationService.php`

**Before (Wrong):**
```php
// Simple approximation
$daysInMonth = date('t', strtotime($month));
$workingDays = floor($daysInMonth * 26 / 30); // ❌ Inaccurate
```

**After (Correct):**
```php
// Use SAME logic as attendance reports
$shiftService = new ShiftResolutionService();
$workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $endDate); // ✅ Accurate
```

**Lines Changed:** 145-154

---

## Benefits

### **✅ Consistency**
- Attendance reports and salary calculations now match EXACTLY
- No more confusion or discrepancies

### **✅ Accuracy**
- Respects each employee's actual shift schedule
- Accounts for public holidays
- Handles different working patterns (5-day week, 6-day week, etc.)

### **✅ Flexibility**
- Different employees can have different shifts
- Public holidays are centrally managed
- Easy to add/remove holidays for all calculations

---

## Testing Instructions

### **Test 1: Verify Working Days Match**

1. **Attendance Report:**
   - Go to: `/attendance/reports`
   - Select: Arsalan, October 2025
   - Note the "Working Days" count

2. **Salary Calculation:**
   - Go to: `/hr/salary-slips/create?user_id=70`
   - Select: Arsalan, October 2025
   - Click: "Calculate Salary"
   - Check: "Working Days" in the attendance section

3. **Expected:**
   - ✅ Both should show THE SAME number
   - ✅ Should match the user's shift schedule
   - ✅ Should exclude public holidays

---

### **Test 2: Verify Different Shifts**

1. Create two employees:
   - Employee A: Mon-Fri shift (5 days/week)
   - Employee B: Mon-Sat shift (6 days/week)

2. Calculate salary for October 2025 for both

3. **Expected:**
   - Employee A: ~22 working days (23 weekdays - 1 holiday)
   - Employee B: ~26 working days (27 Mon-Sat days - 1 holiday)

---

### **Test 3: Verify Holiday Exclusion**

1. Add a public holiday (e.g., October 15, 2025)
2. Calculate salary for October 2025
3. **Expected:**
   - Working days should be 1 less than without the holiday
   - Should match attendance report exactly

---

## Important Notes

### **Shift Data Location**
- Stored in: `t_ops_rider_profile.working_days_json`
- Format: JSON array of day numbers
- Example: `[1,2,3,4,5]` = Mon-Fri

### **Default Shift**
If user has no shift assigned:
- Defaults to Mon-Fri (5-day week)
- Working days: `[1,2,3,4,5]`

### **Public Holidays Management**
You can manage holidays via:
- Direct database insert
- Future admin panel (if needed)
- Attendance reports configuration

---

## Ready to Test! 🚀

The salary calculation now uses the **exact same logic** as your attendance reports.

**Please test:**
1. Calculate a salary slip
2. Compare working days with attendance report
3. They should match perfectly now!

