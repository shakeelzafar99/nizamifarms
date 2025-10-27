# Final Fixes: Column Name & Late Minutes
**Date:** October 27, 2025

## ✅ Issues Fixed

### 1. **Payment Method Change - Wrong Column Name** ✅
**Error**: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'assigned_rider_id'`

**Root Cause**: The query was using `assigned_rider_id` but the actual column name in the database is `assigned_rider_user_id`.

**Solution**: Fixed the column name in the query.

**File Modified**: `app/Http/Controllers/API/RiderController.php` (line 466)

```php
// Before (WRONG)
$order = OrderModel::where('id', $id)
    ->where('assigned_rider_id', $user->id) // ❌ Wrong column name
    ->first();

// After (CORRECT)
$order = OrderModel::where('id', $id)
    ->where('assigned_rider_user_id', $user->id) // ✅ Correct column name
    ->first();
```

---

### 2. **Attendance Summary - Add Late Minutes** ✅
**Request**: Show total late minutes in the attendance summary alongside working days, present, absent, and leave days.

**Solution**: 
- Backend: Added late minutes calculation using the same logic as the salary service
- Mobile: Added "Late (mins)" to the summary grid

**Files Modified**:
- `app/Http/Controllers/API/RiderController.php` (lines 1223-1260, 1309)
- `src/screens/AttendanceScreen.js` (lines 258-261)

---

## 📊 Late Minutes Calculation

### Backend Logic (Same as Salary Service)

```php
// Get user's shift start time
$userShift = $shiftService->getUserShift($user->id);
$shiftStart = $userShift['shift_start'] ?? '09:00:00';

// Calculate total late minutes for the month
$lateMinutesQuery = "
    SELECT 
        COALESCE(SUM(CASE 
            WHEN login_time > ? AND login_time IS NOT NULL THEN 
                TIMESTAMPDIFF(MINUTE, 
                    CONCAT(attendance_date, ' ', ?),
                    CONCAT(attendance_date, ' ', login_time)
                )
            ELSE 0 
        END), 0) as total_late_minutes
    FROM t_ops_attendance
    WHERE user_id = ?
    AND attendance_date IS NOT NULL
    AND attendance_date BETWEEN ? AND ?
    AND login_time IS NOT NULL
    AND login_time != ''
";

$lateResult = DB::selectOne($lateMinutesQuery, [
    $shiftStart, $shiftStart, // For late calculation
    $user->id, $startDate, $effectiveEndDate
]);

$totalLateMinutes = $lateResult->total_late_minutes ?? 0;
```

### How It Works

1. **Get Shift Start Time**: Retrieves the user's shift start time (e.g., 09:00:00)
2. **Compare Login Time**: For each attendance record, if `login_time > shift_start`, calculate the difference in minutes
3. **Sum All Late Minutes**: Adds up all late minutes for the month
4. **Return in Summary**: Includes `late_minutes` in the API response

### Example

- **Shift Start**: 09:00 AM
- **Day 1**: Login at 09:15 AM → 15 minutes late
- **Day 2**: Login at 08:55 AM → 0 minutes late (early)
- **Day 3**: Login at 09:30 AM → 30 minutes late
- **Total Late Minutes**: 45 minutes

---

## 📱 Mobile App Display

### Before
```
┌─────────────────────────────────┐
│ Attendance Summary              │
├─────────────────────────────────┤
│ [20]        [18]        [2]     │
│ Working     Present     Absent  │
│ Days                            │
│                                 │
│ [0]                             │
│ On Leave                        │
└─────────────────────────────────┘
```

### After
```
┌─────────────────────────────────┐
│ Attendance Summary              │
├─────────────────────────────────┤
│ [20]        [18]        [2]     │
│ Working     Present     Absent  │
│ Days                            │
│                                 │
│ [0]         [45]                │
│ On Leave    Late (mins)         │
└─────────────────────────────────┘
```

### Color Coding
- **Working Days**: Gray (#6B7280)
- **Present**: Green (#10B981)
- **Absent**: Red (#EF4444)
- **On Leave**: Blue (#3B82F6)
- **Late (mins)**: Orange (#F59E0B) ← NEW

---

## 🔍 Database Column Names Reference

### t_crm_prod_order Table

| Column Name | Type | Description |
|-------------|------|-------------|
| `id` | INT | Primary key |
| `customer_id` | INT | Foreign key to customer |
| `assigned_rider_user_id` | INT | ✅ **Correct** - Foreign key to user (rider) |
| `order_number` | VARCHAR | Order reference number |
| `payment_method` | VARCHAR | cash_on_delivery, online_payment, etc. |
| `order_status` | VARCHAR | pending, processing, delivered, etc. |
| `ledger_transaction_id` | INT | Foreign key to ledger |

**IMPORTANT**: The column is `assigned_rider_user_id`, NOT `assigned_rider_id`!

### t_ops_attendance Table

| Column Name | Type | Description |
|-------------|------|-------------|
| `id` | INT | Primary key |
| `user_id` | INT | Foreign key to user |
| `attendance_date` | DATE | Date of attendance |
| `login_time` | TIME | Check-in time |
| `logout_time` | TIME | Check-out time |
| `notes` | TEXT | Optional notes |

---

## 🧪 Testing Instructions

### Test 1: Payment Method Change
1. Open mobile app
2. Navigate to a **non-delivered** order
3. Tap the payment badge
4. Confirm change
5. ✅ Should work without SQL column error
6. ✅ Payment method should update

### Test 2: Late Minutes Display
1. Open "Attendance" tab
2. View current month summary
3. ✅ Should see 5 items in summary grid:
   - Working Days
   - Present
   - Absent
   - On Leave
   - **Late (mins)** ← NEW
4. ✅ Late minutes should show total for the month
5. ✅ Color should be orange (#F59E0B)

### Test 3: Verify Late Calculation
```sql
-- Check a specific user's late minutes for current month
SELECT 
    user_id,
    attendance_date,
    login_time,
    CASE 
        WHEN login_time > '09:00:00' THEN 
            TIMESTAMPDIFF(MINUTE, 
                CONCAT(attendance_date, ' ', '09:00:00'),
                CONCAT(attendance_date, ' ', login_time)
            )
        ELSE 0 
    END as late_minutes
FROM t_ops_attendance
WHERE user_id = [RIDER_USER_ID]
AND attendance_date BETWEEN '2025-10-01' AND '2025-10-31'
AND login_time IS NOT NULL
ORDER BY attendance_date DESC;
```

---

## 📂 Files Modified

### Backend
1. **`app/Http/Controllers/API/RiderController.php`**
   - Line 466: Fixed column name `assigned_rider_user_id`
   - Lines 1223-1260: Added late minutes calculation
   - Line 1259: Added `late_minutes` to summary (salary service path)
   - Line 1309: Added `late_minutes` to summary (fallback path)

### Mobile
1. **`src/screens/AttendanceScreen.js`**
   - Lines 258-261: Added "Late (mins)" display to summary grid

---

## 🔄 Consistency with Webapp

### Late Minutes Calculation
- ✅ Uses **same query** as `SalaryCalculationService`
- ✅ Uses **same shift resolution** logic
- ✅ Ensures **consistency** between salary and attendance

### Why This Matters
- Riders see the same late minutes in mobile app as in their salary slip
- No discrepancies between attendance and payroll
- Single source of truth for late calculations

---

## ✅ Summary

Both issues are now fixed:

1. **Payment Method Change**:
   - ✅ Fixed SQL column name error
   - ✅ Uses correct column: `assigned_rider_user_id`
   - ✅ Matches database schema

2. **Late Minutes**:
   - ✅ Added to attendance summary
   - ✅ Uses same calculation as salary service
   - ✅ Displays in mobile app with orange color
   - ✅ Shows total minutes late for the month

**Just reload Metro** (press `r`) to test! 🎉

No rebuild needed - JavaScript changes only for mobile.
Backend changes are live immediately.

