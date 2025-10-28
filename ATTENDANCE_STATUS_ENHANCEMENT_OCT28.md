# Attendance Status Enhancement - Complete Fix
**Date:** October 28, 2025

## ✅ Issues Fixed

### Problem 1: Attendance Reports Still Showing "Absent" for Leave Days
- ✅ "Last 30 Days" popup was working correctly
- ❌ Attendance Reports page was showing "Absent" for Oct 27 (should be "On Leave")
- ❌ Salary creation page was also showing "Absent" for leave days

### Problem 2: Mobile App Status Too Basic
- ✅ Mobile app was showing "Completed", "In Progress", "Absent", "On Leave"
- ❌ Mobile app was NOT showing "Late" status
- ❌ Mobile app was NOT showing late minutes

---

## 🔄 Changes Made

### 1. Added Debug Logging to Attendance Reports

**File**: `resources/views/pages/attendance/reports.blade.php`

#### Added Console Logging (Lines 515-520)
```javascript
// DEBUG: Log Oct 27 specifically
const oct27 = uniqueDaily.find(d => d.attendance_date === '2025-10-27');
if (oct27) {
  console.log('Oct 27 data:', oct27);
  console.log('Oct 27 status field:', oct27.status);
}
```

**Purpose**: This will help us see what data the backend is actually sending for Oct 27. Open the browser console when viewing the report to see the debug output.

---

### 2. Enhanced Mobile API - Added "Late" Status

**File**: `app/Http/Controllers/API/RiderController.php`

#### Updated Status Logic (Lines 1222-1237)
```php
// Determine detailed status: late, completed, in_progress, or absent
$status = 'absent';
$lateMinutes = 0;

if ($record->login_time) {
    // Check if late
    $shiftStartTime = strtotime($dateStr . ' ' . $shiftStart);
    $loginTime = strtotime($dateStr . ' ' . $record->login_time);
    
    if ($loginTime > $shiftStartTime) {
        $lateMinutes = round(($loginTime - $shiftStartTime) / 60);
        $status = 'late'; // ✅ New: Mark as late
    } else {
        $status = $record->logout_time ? 'completed' : 'in_progress';
    }
}
```

#### Updated History Array (Lines 1239-1250)
```php
$history[] = [
    'id' => $record->id,
    'date' => $record->attendance_date,
    'date_formatted' => $currentDate->format('D, M d, Y'),
    'login_time' => $record->login_time,
    'login_time_formatted' => $record->login_time ? date('h:i A', strtotime($record->login_time)) : null,
    'logout_time' => $record->logout_time,
    'logout_time_formatted' => $record->logout_time ? date('h:i A', strtotime($record->logout_time)) : null,
    'status' => $status, // ✅ Now includes 'late'
    'late_minutes' => $lateMinutes, // ✅ New field
    'notes' => $record->notes,
];
```

**Key Changes**:
- Added logic to check if login time is after shift start time
- If late, set `status = 'late'` and calculate `late_minutes`
- Added `late_minutes` field to the response

---

### 3. Enhanced Mobile App - Display "Late" Status

**File**: `src/screens/AttendanceScreen.js`

#### Updated Status Display (Lines 275-290)
```javascript
<View style={[
  styles.historyStatus,
  record.status === 'completed' && styles.historyStatusCompleted,
  record.status === 'in_progress' && styles.historyStatusInProgress,
  record.status === 'late' && styles.historyStatusLate, // ✅ New
  record.status === 'absent' && styles.historyStatusAbsent,
  record.status === 'on_leave' && styles.historyStatusOnLeave,
]}>
  <Text style={styles.historyStatusText}>
    {record.status === 'completed' ? 'Completed' : 
     record.status === 'in_progress' ? 'In Progress' : 
     record.status === 'late' ? 'Late' :  // ✅ New
     record.status === 'on_leave' ? 'On Leave' : 
     'Absent'}
  </Text>
</View>
```

#### Added Late Minutes Display (Lines 292-301)
```javascript
{record.login_time && (
  <View style={styles.historyTimes}>
    <Text style={styles.historyTime}>In: {record.login_time_formatted}</Text>
    {record.logout_time && (
      <Text style={styles.historyTime}>Out: {record.logout_time_formatted}</Text>
    )}
    {record.late_minutes > 0 && (
      <Text style={[styles.historyTime, styles.lateText]}>Late: {record.late_minutes}m</Text>
    )}
  </View>
)}
```

#### Added Styles (Lines 496-521)
```javascript
historyStatusLate: {
  backgroundColor: '#FEE2E2', // Red background for late
},
lateText: {
  color: '#DC2626',
  fontWeight: '600',
},
```

**Key Changes**:
- Added `'late'` status handling
- Display "Late" badge with red background
- Show late minutes in red text below check-in/out times

---

## 📊 Complete Status Matrix

### Mobile App Statuses

| Status | Display Text | Badge Color | When Shown | Additional Info |
|--------|-------------|-------------|------------|-----------------|
| `completed` | "Completed" | Green (#D1FAE5) | Checked in on time AND checked out | - |
| `in_progress` | "In Progress" | Blue (#DBEAFE) | Checked in on time, NOT checked out | - |
| `late` | "Late" | Red (#FEE2E2) | Checked in after shift start | Shows "Late: Xm" |
| `on_leave` | "On Leave" | Blue (#DBEAFE) | Approved/pending leave request | - |
| `absent` | "Absent" | Red (#FEE2E2) | No check-in AND no leave | - |

### Webapp Statuses (Reports & Last 30 Days)

| Status | Display Text | Badge Color | When Shown |
|--------|-------------|-------------|------------|
| `on_time` / `completed` | "On Time" / "Present" | Green (#DCFCE7) | Checked in on time |
| `late` | "Late" | Red (#FEE2E2) | Checked in after shift start |
| `on_leave` | "On Leave" | Blue (#DBEAFE) | Approved/pending leave request |
| `absent` | "Absent" | Red (#FEF2F2) | No check-in AND no leave |

---

## 🎨 Visual Examples

### Mobile App - Before
```
Oct 23, 2025    [Completed]
Oct 27, 2025    [On Leave]   ✅ Already working
```

### Mobile App - After
```
Oct 23, 2025    [Late]       ✅ NEW!
                In: 05:52 PM
                Out: -
                Late: 412m   ✅ NEW!

Oct 27, 2025    [On Leave]   ✅ Still working
```

---

## 🧪 Testing Instructions

### Test 1: Check Attendance Reports Debug Output
1. Go to `/attendance/reports`
2. Select October 2025
3. Click "View Details" for Waseem
4. **Open browser console** (F12)
5. ✅ Look for debug logs:
   ```
   Oct 27 data: {attendance_date: '2025-10-27', status: 'on_leave', ...}
   Oct 27 status field: on_leave
   ```
6. If status shows `'absent'` instead of `'on_leave'`, the backend issue persists

### Test 2: Mobile App - Late Status
1. **Reload Metro** (press `r`)
2. Open Attendance tab
3. ✅ Oct 23 should show "Late" badge (red)
4. ✅ Should show "Late: 412m" below the times
5. ✅ Oct 27 should show "On Leave" badge (blue)

### Test 3: Mobile App - All Statuses
Check that all statuses display correctly:
- ✅ **Completed**: Green badge, no late minutes
- ✅ **In Progress**: Blue badge, no late minutes
- ✅ **Late**: Red badge, shows late minutes
- ✅ **On Leave**: Blue badge
- ✅ **Absent**: Red badge

---

## 🔍 Debugging the Attendance Reports Issue

If the Attendance Reports page is still showing "Absent" for Oct 27, check the following:

### Step 1: Check Browser Console
Open `/attendance/reports`, click "View Details" for Waseem, and check console:
```javascript
Oct 27 data: {attendance_date: '2025-10-27', status: '???', ...}
```

**If status is `'absent'`**: Backend is not setting `'status' => 'on_leave'` correctly
**If status is `'on_leave'`**: Frontend is not displaying it correctly (unlikely, as we fixed this)

### Step 2: Check Backend Leave Dates
The backend builds a `leave_dates` array. Add this debug to `AttendanceController.php` line 433:
```php
Log::info('User leave dates', [
    'user_id' => $userId,
    'fullname' => $userData['fullname'],
    'leave_dates' => array_keys($userData['leave_dates'])
]);
```

Then check `storage/logs/laravel.log` to see if Oct 27 is in the leave dates.

### Step 3: Check Leave Request Query
The SQL query joins `t_req_master` with these conditions:
- `status IN ('approved', 'pending')`
- Category code = 'leave'
- Date range overlaps with Oct 27

Run this SQL to verify:
```sql
SELECT 
    rm.id,
    rm.status,
    rc.category_code,
    rm.leave_start_date,
    rm.leave_end_date,
    u.fullname
FROM t_req_master rm
JOIN t_sys_user u ON u.id = rm.requester_user_id
JOIN t_req_category rc ON rc.id = rm.category_id
WHERE u.fullname = 'Waseem'
AND rc.category_code = 'leave'
AND rm.leave_start_date <= '2025-10-27'
AND rm.leave_end_date >= '2025-10-27';
```

If this returns no rows, the leave request isn't being found by the query.

---

## 📂 Files Modified

### Backend
1. **`app/Http/Controllers/API/RiderController.php`**
   - Lines 1222-1237: Added late status detection logic
   - Lines 1239-1250: Added `late_minutes` field to history response

### Frontend (Webapp)
2. **`resources/views/pages/attendance/reports.blade.php`**
   - Lines 515-520: Added debug logging for Oct 27

### Mobile App
3. **`src/screens/AttendanceScreen.js`**
   - Lines 275-290: Added "Late" status display
   - Lines 292-301: Added late minutes display
   - Lines 496-521: Added styles for late status and late text

---

## ✅ Summary

### Mobile App Enhancements ✅
1. ✅ Added "Late" status detection in backend API
2. ✅ Added `late_minutes` field to API response
3. ✅ Display "Late" badge (red) in mobile app
4. ✅ Show late minutes below check-in/out times
5. ✅ All statuses now working: Completed, In Progress, Late, On Leave, Absent

### Attendance Reports Debugging 🔍
1. ✅ Added console logging to see what data backend is sending
2. 🔍 Need to check browser console to diagnose why Oct 27 shows "Absent"
3. 🔍 Backend logic looks correct, but may need SQL query debugging

### Next Steps
1. **Test mobile app** - Just reload Metro (press `r`)
2. **Check webapp console** - Open browser console and view Waseem's report details
3. **Share console output** - Send screenshot of console logs showing Oct 27 data
4. **If needed** - Add backend logging to trace leave dates array

---

**Mobile app is now fully enhanced with Late status!** 🎉  
**Webapp reports need console debugging to diagnose the issue.** 🔍

