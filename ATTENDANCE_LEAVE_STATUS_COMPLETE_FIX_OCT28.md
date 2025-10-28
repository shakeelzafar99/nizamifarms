# Attendance Leave Status - Complete Fix (Webapp + Mobile)
**Date:** October 28, 2025

## ✅ Issue Fixed

### Problem
When a leave request is approved:
- ✅ Attendance summary correctly shows "On Leave: 1"
- ❌ Daily records show "Absent" instead of "On Leave"
- ❌ Webapp attendance reports show "Absent" for leave dates
- ❌ Webapp "Last 30 Days" popup doesn't show status at all
- ❌ Mobile app shows "Absent" for leave dates

### Root Cause
1. **Monthly Report (Attendance Reports)**: Backend was tracking leave dates but marking them as `'status' => 'absent'` instead of `'status' => 'on_leave'`
2. **Last 30 Days Popup**: Frontend didn't have a status column, even though backend returns `leave_request_id` and `leave_status`
3. **Mobile App API**: Not checking for leave requests when building daily history

---

## 🔄 Changes Made

### 1. Backend - Monthly Report API

**File**: `app/Http/Controllers/CRM/AttendanceController.php`

#### Updated Leave Day Logic (Lines 442-482)
```php
// Add absent/leave day records for dates within the reporting period that have no attendance
// IMPORTANT: Only add for WORKING DAYS (respects shift off days and public holidays)
$currentDate = new \DateTime($startDate);
$endDateObj = new \DateTime($effectiveEndDate);

while ($currentDate <= $endDateObj) {
    $dateStr = $currentDate->format('Y-m-d');
    
    // Skip if attendance record exists
    if (!isset($attendanceDates[$dateStr])) {
        // CRITICAL: Only add if this is a WORKING DAY for this user
        // This respects shift schedule (e.g., Tuesday off) AND public holidays
        if ($shiftService->isWorkingDay($userId, $dateStr)) {
            // Check if on leave
            if (isset($userData['leave_dates'][$dateStr])) {
                // This is a working day on leave = ON LEAVE
                $userData['daily'][] = [
                    'attendance_date' => $dateStr,
                    'login_time' => null,
                    'logout_time' => null,
                    'shift_start' => $userData['shift_start'],
                    'shift_end' => $userData['shift_end'],
                    'status' => 'on_leave' // ✅ Mark as on leave
                ];
            } else {
                // This is a working day with no attendance = ABSENT
                $userData['daily'][] = [
                    'attendance_date' => $dateStr,
                    'login_time' => null,
                    'logout_time' => null,
                    'shift_start' => $userData['shift_start'],
                    'shift_end' => $userData['shift_end'],
                    'status' => 'absent' // Mark as absent for frontend rendering
                ];
            }
        }
        // else: it's a day off or holiday, don't show in the report
    }
    
    $currentDate->modify('+1 day');
}
```

**Key Change**: Now checks `if (isset($userData['leave_dates'][$dateStr]))` and sets `'status' => 'on_leave'` instead of always marking as absent.

---

### 2. Frontend - Attendance Reports View

**File**: `resources/views/pages/attendance/reports.blade.php`

#### Updated Status Display Logic (Lines 515-534)
```javascript
body.innerHTML = uniqueDaily.map((day, index) => {
  // Check if this is an absent or on leave day (marked by backend)
  const isAbsent = day.status === 'absent' || (!day.login_time && !day.logout_time && day.status !== 'on_leave');
  const isOnLeave = day.status === 'on_leave';
  
  const loginTime = day.login_time || '-';
  const logoutTime = day.logout_time || '-';
  const hours = (isAbsent || isOnLeave) ? '-' : calculateHours(day.login_time, day.logout_time);
  const lateBy = (isAbsent || isOnLeave) ? { duration: '-', isLate: false } : calculateLateBy(day.login_time, day.shift_start);
  const overtime = (isAbsent || isOnLeave) ? { duration: '-', hasOvertime: false } : calculateOvertime(day.logout_time, day.shift_end);
  const status = isOnLeave ? 'On Leave' : (isAbsent ? 'Absent' : getStatus(day.login_time, day.shift_start));
  
  // Format date nicely
  const date = new Date(day.attendance_date + 'T00:00:00');
  const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
  const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

  const rowBg = index % 2 === 0 ? '#f9fafb' : 'white';
  const statusBg = status === 'On Time' ? '#dcfce7' : status === 'Late' ? '#fee2e2' : status === 'Absent' ? '#fef2f2' : status === 'On Leave' ? '#dbeafe' : '#f3f4f6';
  const statusColor = status === 'On Time' ? '#166534' : status === 'Late' ? '#991b1b' : status === 'Absent' ? '#991b1b' : status === 'On Leave' ? '#1e40af' : '#6b7280';
```

#### Updated Row Rendering (Lines 536-553)
```javascript
return `
  <tr style="background: ${rowBg}; transition: background 0.2s;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='${rowBg}'">
    <td style="padding: 12px 16px; font-size: 14px; border-bottom: 1px solid #f3f4f6;">
      <div style="font-weight: 500; color: ${isOnLeave ? '#1e40af' : (isAbsent ? '#dc2626' : '#111827')};">${formattedDate}</div>
      <div style="font-size: 12px; color: #6b7280;">${dayName}</div>
    </td>
    <td style="padding: 12px 16px; font-size: 14px; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : lateBy.isLate ? '#dc2626' : '#374151'}; font-weight: ${lateBy.isLate ? '600' : '400'}; border-bottom: 1px solid #f3f4f6;">${loginTime}</td>
    <td style="padding: 12px 16px; font-size: 14px; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : '#374151'}; border-bottom: 1px solid #f3f4f6;">${logoutTime}</td>
    <td style="padding: 12px 16px; font-size: 14px; font-weight: 600; text-align: center; color: ${(isAbsent || isOnLeave) ? '#9ca3af' : '#111827'}; border-bottom: 1px solid #f3f4f6;">${hours}</td>
    <td style="padding: 12px 16px; font-size: 14px; text-align: center; color: ${lateBy.isLate ? '#dc2626' : '#9ca3af'}; font-weight: ${lateBy.isLate ? 'bold' : '400'}; border-bottom: 1px solid #f3f4f6;">${lateBy.duration}</td>
    <td style="padding: 12px 16px; font-size: 14px; text-align: center; color: ${overtime.hasOvertime ? '#16a34a' : '#9ca3af'}; font-weight: ${overtime.hasOvertime ? 'bold' : '400'}; border-bottom: 1px solid #f3f4f6;">${overtime.duration}</td>
    <td style="padding: 12px 16px; font-size: 14px; text-align: center; border-bottom: 1px solid #f3f4f6;">
      <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; background: ${statusBg}; color: ${statusColor};">
        ${status}
      </span>
    </td>
  </tr>
`;
```

**Key Changes**:
- Added `isOnLeave` check
- Set status to "On Leave" when `day.status === 'on_leave'`
- Blue background (#dbeafe) and blue text (#1e40af) for "On Leave" status
- Date text color changes to blue for leave days

---

### 3. Frontend - Main Attendance Page (Last 30 Days Popup)

**File**: `resources/views/pages/attendance/index.blade.php`

#### Added Status Column to Table Header (Lines 537-538)
```html
<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Date</th>
<th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Status</th>
<th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Login</th>
```

#### Updated Row Rendering with Status (Lines 1694-1738)
```javascript
// Determine status: On Leave, Present, Late, or Absent
const isOnLeave = day.leave_request_id && (day.leave_status === 'approved' || day.leave_status === 'pending');
const isPresent = day.login_time && day.login_time !== '-';
const isLate = isPresent && day.late_minutes > 0;

let status, statusBg, statusColor;
if (isOnLeave) {
  status = 'On Leave';
  statusBg = '#dbeafe';
  statusColor = '#1e40af';
} else if (isLate) {
  status = 'Late';
  statusBg = '#fee2e2';
  statusColor = '#991b1b';
} else if (isPresent) {
  status = 'Present';
  statusBg = '#dcfce7';
  statusColor = '#166534';
} else {
  status = 'Absent';
  statusBg = '#fef2f2';
  statusColor = '#991b1b';
}

return `
  <tr style="background: ${rowBg};">
    <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">
      <div style="font-weight: 600;">${dayName}</div>
      <div style="font-size: 11px; color: #6b7280;">${formattedDate}</div>
    </td>
    <td style="padding: 12px 16px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">
      <span style="display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: ${statusBg}; color: ${statusColor};">
        ${status}
      </span>
    </td>
    <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">${loginTime}</td>
    ...
  </tr>
`;
```

**Key Changes**:
- Added new "Status" column (2nd column)
- Checks `day.leave_request_id` and `day.leave_status` to determine if on leave
- Displays status badge: "On Leave", "Late", "Present", or "Absent"
- Updated colspan from 9 to 10 for error/loading messages

---

### 4. Backend - Mobile API

**File**: `app/Http/Controllers/API/RiderController.php`

#### Added Leave Request Fetching (Lines 1174-1204)
```php
// Get approved/pending leave requests for the month
$leaveRequests = \App\Models\Request\RequestModel::where('requester_user_id', $user->id)
    ->whereIn('status', ['approved', 'pending'])
    ->whereHas('category', function ($q) {
        $q->where('category_code', 'leave');
    })
    ->where(function ($q) use ($startDate, $effectiveEndDate) {
        $q->whereBetween('leave_start_date', [$startDate, $effectiveEndDate])
          ->orWhereBetween('leave_end_date', [$startDate, $effectiveEndDate])
          ->orWhere(function ($q2) use ($startDate, $effectiveEndDate) {
              $q2->where('leave_start_date', '<=', $startDate)
                 ->where('leave_end_date', '>=', $effectiveEndDate);
          });
    })
    ->get();

// Build a set of dates that are on leave
$leaveDates = [];
foreach ($leaveRequests as $req) {
    if ($req->leave_start_date && $req->leave_end_date) {
        $d = new \DateTime($req->leave_start_date);
        $dEnd = new \DateTime($req->leave_end_date);
        while ($d <= $dEnd) {
            $dateStr = $d->format('Y-m-d');
            if ($dateStr >= $startDate && $dateStr <= $effectiveEndDate) {
                $leaveDates[$dateStr] = true;
            }
            $d->modify('+1 day');
        }
    }
}
```

#### Updated Daily History Status (Line 1234)
```php
// Check if this date is on leave
$status = isset($leaveDates[$dateStr]) ? 'on_leave' : 'absent';

$history[] = [
    'id' => null,
    'date' => $dateStr,
    'date_formatted' => $currentDate->format('D, M d, Y'),
    'login_time' => null,
    'login_time_formatted' => null,
    'logout_time' => null,
    'logout_time_formatted' => null,
    'status' => $status, // ✅ Now returns 'on_leave' if applicable
    'notes' => null,
];
```

---

### 5. Mobile App

**File**: `src/screens/AttendanceScreen.js`

#### Updated Status Display Logic (Lines 275-288)
```javascript
<View style={[
  styles.historyStatus,
  record.status === 'completed' && styles.historyStatusCompleted,
  record.status === 'in_progress' && styles.historyStatusInProgress,
  record.status === 'absent' && styles.historyStatusAbsent,
  record.status === 'on_leave' && styles.historyStatusOnLeave, // ✅ New
]}>
  <Text style={styles.historyStatusText}>
    {record.status === 'completed' ? 'Completed' : 
     record.status === 'in_progress' ? 'In Progress' : 
     record.status === 'on_leave' ? 'On Leave' :  // ✅ New
     'Absent'}
  </Text>
</View>
```

#### Added Style (Lines 494-496)
```javascript
historyStatusOnLeave: {
  backgroundColor: '#DBEAFE', // Blue background for leave
},
```

---

## 📊 Status Display Matrix

| Status | Display Text | Background Color | Text Color | When Shown |
|--------|-------------|------------------|------------|------------|
| `completed` | "Completed" | Green (#D1FAE5) | Dark (#1F2937) | Checked in AND checked out |
| `in_progress` | "In Progress" | Blue (#DBEAFE) | Dark (#1F2937) | Checked in, NOT checked out |
| `on_leave` | "On Leave" | Blue (#DBEAFE) | Blue (#1e40af) | Approved/pending leave request | ✅ NEW
| `absent` | "Absent" | Red (#FEE2E2) | Red (#991b1b) | No attendance AND no leave |
| `late` | "Late" | Red (#FEE2E2) | Red (#991b1b) | Checked in after shift start |
| `present` | "Present" | Green (#DCFCE7) | Green (#166534) | Checked in on time |

---

## 🎨 Visual Changes

### Before (All Screens)
```
Oct 27, 2025    [Absent]  ❌ Wrong!
```

### After (All Screens)
```
Oct 27, 2025    [On Leave]  ✅ Correct!
```

---

## 🧪 Testing Instructions

### Test 1: Webapp - Attendance Reports
1. Go to `/attendance/reports`
2. Select October 2025
3. Click "View Details" for Waseem
4. ✅ Oct 27 should show "On Leave" badge (blue)
5. ✅ Summary should show "On Leave: 1"

### Test 2: Webapp - Main Attendance Page
1. Go to `/attendance`
2. Click on Waseem's row (view icon)
3. ✅ "Last 30 Days" popup should have "Status" column
4. ✅ Oct 27 should show "On Leave" badge (blue)
5. ✅ Summary at top should show "On Leave: 1"

### Test 3: Mobile App
1. Open Attendance tab
2. ✅ Summary shows "On Leave: 1"
3. ✅ Daily records show "On Leave" for Oct 27 (blue badge)
4. **Just reload Metro** (press `r`) - no rebuild needed!

### Test 4: Multi-Day Leave
1. Create leave request for Oct 28-30 (3 days)
2. Approve the leave
3. ✅ All 3 screens should show "On Leave" for all 3 days
4. ✅ Summary should show "On Leave: 3"

---

## 📂 Files Modified

### Backend
1. **`app/Http/Controllers/CRM/AttendanceController.php`**
   - Lines 442-482: Updated leave day logic in `monthlyReport()` method

2. **`app/Http/Controllers/API/RiderController.php`**
   - Lines 1174-1204: Added leave request fetching and date set building
   - Line 1234: Changed status logic to check for leave dates

### Frontend (Webapp)
3. **`resources/views/pages/attendance/reports.blade.php`**
   - Lines 515-534: Updated status display logic
   - Lines 536-553: Updated row rendering with "On Leave" styling

4. **`resources/views/pages/attendance/index.blade.php`**
   - Lines 537-538: Added "Status" column to table header
   - Lines 1694-1738: Added status determination and badge rendering
   - Updated colspan from 9 to 10

### Mobile App
5. **`src/screens/AttendanceScreen.js`**
   - Lines 275-288: Updated status display logic
   - Lines 494-496: Added style for "On Leave" status

---

## ✅ Summary

The attendance leave status is now correctly displayed across **ALL** platforms:

1. ✅ **Mobile App**: Shows "On Leave" badge (blue) in daily records
2. ✅ **Webapp - Attendance Reports**: Shows "On Leave" status in monthly report details
3. ✅ **Webapp - Main Attendance**: Shows "On Leave" status in "Last 30 Days" popup
4. ✅ **Consistent Styling**: All use blue badge (#dbeafe background, #1e40af text)
5. ✅ **Correct Logic**: Checks approved/pending leave requests for each date
6. ✅ **Summary Consistency**: Summary counts match daily record statuses

**No rebuild needed for mobile app** - just reload Metro (press `r`)! 🎉

All attendance screens now accurately reflect when employees are on approved leave.

