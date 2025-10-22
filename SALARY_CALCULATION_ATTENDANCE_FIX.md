# Salary Calculation Attendance Fix - October 22, 2025

## Problem Statement

When generating salary slips mid-month (e.g., on the 25th), the system was calculating absent days based on the **total working days in the month**, which incorrectly penalized employees for future days that haven't occurred yet.

### Example Issue:
- **Scenario**: Generating salary on October 25th for October 2025
- **Old Behavior**: 
  - Working days in October: 27 days
  - Present days (up to 25th): 12 days
  - Leave days: 0
  - **Absent days calculated**: 27 - 12 - 0 = **15 days** ❌
  - This includes 5-6 future days (26th-31st) that haven't occurred yet!
  
- **New Behavior**:
  - Working days (up to 25th): 19 days
  - Present days: 12 days
  - Leave days: 0
  - **Absent days calculated**: 19 - 12 - 0 = **7 days** ✅
  - Only counts actual absent days, not future days!

---

## Solution

Modified the salary calculation service to use an **effective end date** that respects the current date when generating salaries for the current month.

### Key Changes

1. **Calculate effective end date**:
   - If generating salary for current month → use **today's date**
   - If generating salary for past month → use **last day of that month**

2. **Apply effective end date to all attendance queries**:
   - Present days count
   - Absent days count
   - Late minutes calculation
   - Overtime calculation
   - Working days calculation
   - Leave days calculation

3. **Match attendance report logic**:
   - Uses the same formula as the attendance report
   - `absent_days = working_days - present_days - leave_days`
   - Ensures consistency across the system

---

## Technical Implementation

### File Modified
**`app/Services/HR/SalaryCalculationService.php`**

### Changes Made

#### 1. **Added Effective End Date Calculation** (Lines 130-133)
```php
// CRITICAL: Use today's date as the effective end date if we're in the current month
// This prevents penalizing employees for future days that haven't occurred yet
$today = date('Y-m-d');
$effectiveEndDate = ($endDate > $today) ? $today : $endDate;
```

#### 2. **Updated Attendance Query** (Line 152)
```php
// Changed from: [$userId, $startDate, $endDate]
// Changed to:   [$userId, $startDate, $effectiveEndDate]
$attendance = DB::selectOne($attendanceQuery, [$userId, $startDate, $effectiveEndDate]);
```

#### 3. **Updated Late/Overtime Query** (Line 190)
```php
// Changed from: [$userId, $startDate, $endDate]
// Changed to:   [$userId, $startDate, $effectiveEndDate]
$lateAndOT = DB::selectOne($lateAndOTQuery, [
    $shiftStart, $shiftStart,
    $shiftEnd, $shiftEnd,
    $userId, $startDate, $effectiveEndDate
]);
```

#### 4. **Updated Working Days Calculation** (Line 197)
```php
// Changed from: calculateWorkingDays($userId, $startDate, $endDate)
// Changed to:   calculateWorkingDays($userId, $startDate, $effectiveEndDate)
$workingDays = $shiftService->calculateWorkingDays($userId, $startDate, $effectiveEndDate);
```

#### 5. **Updated Leave Days Query** (Lines 208-214)
```php
// Changed all references from $endDate to $effectiveEndDate
->where(function($q) use ($startDate, $effectiveEndDate) {
    $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
      ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
      ->orWhere(function($q2) use ($startDate, $effectiveEndDate) {
          $q2->where('leave_start_date', '<=', $startDate)
             ->where('leave_end_date', '>=', $effectiveEndDate);
      });
})
```

#### 6. **Updated Leave Days Loop** (Line 227)
```php
// Changed from: if ($dateStr >= $startDate && $dateStr <= $endDate)
// Changed to:   if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate)
if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
    $leaveDays++;
}
```

---

## Benefits

### 1. **Accurate Salary Calculations**
- Employees are only evaluated on days that have actually occurred
- No penalty for future days when generating mid-month salaries

### 2. **Consistency with Attendance Reports**
- Salary slip absent days now match attendance report absent days
- Uses the exact same calculation logic
- Eliminates confusion and discrepancies

### 3. **Fair Compensation**
- Employees get full salary based on actual attendance data
- Manager can generate salary on any day of the month without worrying about future days

### 4. **Flexible Salary Generation**
- Can generate salary on the 25th, 28th, or any day
- System automatically adjusts to only count days up to that point
- For past months, still counts all days in the month

---

## Example Scenarios

### Scenario 1: Generate Salary on October 25th
```
Month: October 2025
Generation Date: October 25, 2025

Calculation:
- Start Date: October 1, 2025
- End Date: October 31, 2025
- Effective End Date: October 25, 2025 (today)

Working Days (Oct 1-25): 19 days
Present Days: 12 days
Leave Days: 0 days
Absent Days: 19 - 12 - 0 = 7 days ✅

Result: Employee gets full salary with only 7 absent days deducted
```

### Scenario 2: Generate Salary on November 5th for October
```
Month: October 2025
Generation Date: November 5, 2025

Calculation:
- Start Date: October 1, 2025
- End Date: October 31, 2025
- Effective End Date: October 31, 2025 (past month, use full month)

Working Days (Oct 1-31): 27 days
Present Days: 20 days
Leave Days: 2 days
Absent Days: 27 - 20 - 2 = 5 days ✅

Result: Full month is counted since it's a past month
```

### Scenario 3: Generate Salary on October 31st (Last Day)
```
Month: October 2025
Generation Date: October 31, 2025

Calculation:
- Start Date: October 1, 2025
- End Date: October 31, 2025
- Effective End Date: October 31, 2025 (today is last day)

Working Days (Oct 1-31): 27 days
Present Days: 22 days
Leave Days: 2 days
Absent Days: 27 - 22 - 2 = 3 days ✅

Result: Full month is counted since we're at the end of the month
```

---

## Alignment with Attendance Report

The salary calculation now uses the **exact same logic** as the attendance report:

### Attendance Report Logic (AttendanceController.php, line 739)
```php
$absentDays = $workingDays - $presentDays - $onLeaveDays;
```

### Salary Calculation Logic (SalaryCalculationService.php, line 241)
```php
'absent_days' => max(0, $workingDays - $presentDays - $leaveDays)
```

Both now:
- Count only days up to today (or end of month if past)
- Respect shift schedules and public holidays
- Subtract leave days from absent days
- Use the same working days calculation

---

## Testing Recommendations

### Test Case 1: Mid-Month Salary Generation
1. Generate salary for an employee on the 25th of the current month
2. Verify absent days matches attendance report
3. Verify no penalty for future days (26th-31st)

### Test Case 2: Past Month Salary Generation
1. Generate salary for an employee for last month
2. Verify all days in the month are counted
3. Verify absent days matches attendance report for that month

### Test Case 3: Employee with Leaves
1. Generate salary for an employee with approved leaves
2. Verify leave days are subtracted from absent days
3. Verify matches attendance report

### Test Case 4: Last Day of Month
1. Generate salary on the last day of the month
2. Verify full month is counted
3. Verify matches attendance report

---

## Impact Assessment

### ✅ **Positive Impacts**
- More accurate salary calculations
- Fair treatment of employees
- Consistency with attendance reports
- Flexibility in salary generation timing

### ⚠️ **No Breaking Changes**
- Existing salary slips are not affected
- No database migrations required
- Backward compatible with all existing functionality

### 📊 **Performance**
- No performance impact
- Same number of queries
- Efficient date calculations

---

## User Experience

### Before Fix
- Manager generates salary on October 25th
- System shows 15 absent days (includes future days)
- Employee gets unfairly penalized
- Manager confused why numbers don't match attendance report

### After Fix
- Manager generates salary on October 25th
- System shows 7 absent days (only actual absences)
- Employee gets fair salary based on actual attendance
- Numbers match attendance report exactly

---

## Notes

1. **Date Handling**: Uses PHP's `date()` function for current date, ensuring server timezone is respected
2. **Shift Awareness**: Still respects shift schedules (e.g., Tuesday off for some employees)
3. **Holiday Awareness**: Still respects public holidays via `ShiftResolutionService`
4. **Leave Integration**: Properly integrates with leave request system
5. **Logging**: Existing log statements still capture all relevant data for debugging

---

**Implementation Date**: October 22, 2025  
**Status**: ✅ Complete  
**Tested**: Ready for testing  
**Breaking Changes**: None  
**Backward Compatible**: Yes

