# Attendance Location Display - Complete Implementation

## Overview
Adding location badges and filters to show onsite vs remote attendance across:
1. **Web App** - Attendance Management table
2. **Mobile App** - Rider Mode (AttendanceScreen)
3. **Mobile App** - Store Mode (StoreAttendanceScreen)

---

## PART 1: Web App Updates

### File: `resources/views/pages/attendance/index.blade.php`

### Step 1: Add Location Filter (Add after line 24, after "Show: Active Users" dropdown)

```html
<!-- FIND THIS (around line 21-25): -->
<select id="activeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="onActiveFilterChange()">
  <option value="active" selected>Active Users</option>
  <option value="all">All Users</option>
</select>

<!-- ADD THIS AFTER IT: -->
<div class="flex items-center gap-2 ml-2">
  <label for="locationFilter" class="text-sm text-gray-700">Location:</label>
  <select id="locationFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="filterByLocation()">
    <option value="all" selected>All</option>
    <option value="onsite">Onsite</option>
    <option value="remote">Remote</option>
    <option value="no_location">No Location</option>
  </select>
</div>
```

### Step 2: Update Table Header (Add Location column after "Logout" column)

**FIND** the table headers section (around line 300-320):

```html
<!-- Current headers -->
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Employee</th>
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Shift</th>
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Login</th>
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Logout</th>
<!-- ADD THIS NEW HEADER: -->
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Location</th>
<th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Hours</th>
<!-- ... rest of headers -->
```

### Step 3: Update `renderAttendanceTable` Function (around line 913)

**REPLACE** the function from line 913-997 with this updated version:

```javascript
function renderAttendanceTable(data) {
  const body = document.getElementById('attBody');
  
  if (!data || data.length === 0) {
    body.innerHTML = '<tr><td colspan="12" class="px-4 py-8 text-center text-gray-500 text-sm">No attendance records found for this date</td></tr>';
    return;
  }

  body.innerHTML = data.map(r => {
    const hours = calculateHours(r.login_time, r.logout_time);
    const lateBy = calculateLateBy(r.login_time, r.shift_start);
    const overtime = calculateOvertime(r.logout_time, r.shift_end, r.login_time);
    
    // Get location status
    const locationBadge = getLocationBadge(r);
    
    return `
      <tr class="hover:bg-gray-50" data-status="${getRowStatus(r, lateBy, overtime)}" data-location="${locationBadge.type}">
        <td class="px-4 py-3 text-sm font-medium">
          <button 
            onclick="showEmployeeDetails(${r.user_id}, \`${(r.fullname || '').replace(/`/g, '')}\`, '${r.attendance_date}')"
            class="text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer text-left"
            title="View last 30 days attendance with order details"
          >
            ${r.fullname || '#' + r.user_id}
          </button>
        </td>
        <td class="px-4 py-3 text-sm">
          <div class="text-gray-900 font-medium">${r.shift_name || 'Default Shift'}</div>
          <div class="text-xs text-gray-500">${r.shift_start || '09:00'} - ${r.shift_end || '17:00'}</div>
        </td>
        <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-medium' : 'text-gray-900'}">${r.login_time || '-'}</td>
        <td class="px-4 py-3 text-sm text-gray-900">${r.logout_time || '-'}</td>
        
        <!-- NEW: Location Badge Column -->
        <td class="px-4 py-3 text-sm">
          ${locationBadge.html}
        </td>
        
        <td class="px-4 py-3 text-sm text-gray-600">${hours}</td>
        <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-semibold' : 'text-gray-400'}">${lateBy.duration}</td>
        <td class="px-4 py-3 text-sm ${overtime.hasOvertime ? 'text-green-600 font-semibold' : 'text-gray-400'}">${overtime.duration}</td>
        
        <!-- Leave badge -->
        ${r.leave_request_id ? `
        <td class="px-4 py-3 text-sm">
            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${r.leave_status === 'approved' ? 'bg-green-100 text-green-700' : (r.leave_status === 'pending' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700')}">
              ${r.leave_type_from_req || 'Leave'} · ${r.leave_status}
            </span>
          </td>
        ` : '<td class="px-4 py-3 text-sm text-gray-400">-</td>'}
        <td class="px-4 py-3 text-sm">
          <div class="flex gap-2" style="position: relative; z-index: 5;">
            ${!r.logout_time ? `
              <button 
                type="button"
                class="quick-add-btn px-2 py-1 bg-green-100 text-green-700 rounded hover:bg-green-200 transition text-xs font-medium"
                data-user-id="${r.user_id}"
                data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
                title="${!r.login_time ? 'Add login time' : 'Add logout time'}"
                style="cursor: pointer;"
              >
                ➕
              </button>
            ` : ''}
            <button 
              type="button"
              class="manage-shift-btn px-2 py-1 bg-purple-100 text-purple-700 rounded hover:bg-purple-200 transition text-xs font-medium"
              data-user-id="${r.user_id}"
              data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
              title="Manage shift for ${(r.fullname || '').replace(/"/g, '&quot;')}"
              style="cursor: pointer;"
            >
              📅
            </button>
            <button 
              type="button"
              class="quick-edit-btn px-2 py-1 bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition text-xs font-medium"
              data-user-id="${r.user_id}"
              data-user-name="${(r.fullname || '').replace(/"/g, '&quot;')}"
              data-login-time="${r.login_time || ''}"
              data-logout-time="${r.logout_time || ''}"
              data-attendance-date="${r.attendance_date}"
              title="Edit attendance"
              style="cursor: pointer;"
            >
              ✏️
            </button>
          </div>
        </td>
      </tr>
    `;
  }).join('');
}
```

### Step 4: Add Helper Function for Location Badge (Add after `getRowStatus` function, around line 1004)

```javascript
/**
 * Get location badge HTML based on attendance location data
 */
function getLocationBadge(record) {
  // No check-in = no location data
  if (!record.login_time) {
    return {
      type: 'no_location',
      html: '<span class="text-gray-400 text-xs">-</span>'
    };
  }

  // Has location data
  if (record.checkin_latitude && record.checkin_longitude) {
    if (record.is_remote_checkin == 1) {
      // Remote check-in (>2km from office)
      const distanceKm = (record.checkin_distance_from_base / 1000).toFixed(1);
      return {
        type: 'remote',
        html: `
          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700" title="Checked in remotely">
            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z" clip-rule="evenodd"/>
            </svg>
            ${distanceKm} km away
          </span>
        `
      };
    } else {
      // Onsite check-in (≤2km from office)
      return {
        type: 'onsite',
        html: `
          <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700" title="Checked in at office">
            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            At office
          </span>
        `
      };
    }
  }

  // Check-in without location (GPS failed or denied)
  return {
    type: 'no_location',
    html: `
      <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-600" title="Location not captured">
        <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/>
        </svg>
        No location
      </span>
    `
  };
}
```

### Step 5: Add Location Filter Function (Add after `filterTableByStatus`, around line 1020)

```javascript
/**
 * Filter table by location type
 */
function filterByLocation() {
  const filter = document.getElementById('locationFilter').value;
  const rows = document.querySelectorAll('#attBody tr[data-location]');
  
  let visibleCount = 0;
  
  rows.forEach(row => {
    const location = row.getAttribute('data-location');
    
    if (filter === 'all' || filter === location) {
      row.style.display = '';
      visibleCount++;
    } else {
      row.style.display = 'none';
    }
  });

  // Update visible count indicator (optional)
  console.log(`Showing ${visibleCount} records with filter: ${filter}`);
}
```

---

## PART 2: Mobile App - Rider Mode (AttendanceScreen)

### File: `src/screens/AttendanceScreen.js`

**We already updated check-in/check-out. Now add location display to history.**

### Find the history rendering section (around line 428-480) and ADD this code AFTER the historyTimes section:

```javascript
{/* Location Info - ADD THIS AFTER historyTimes */}
{(record.checkin_latitude || record.checkout_latitude) && (
  <View style={styles.locationInfoSection}>
    {record.checkin_latitude && (
      <View style={styles.locationRow}>
        <Text style={styles.locationLabel}>Check-in: </Text>
        {record.is_remote_checkin ? (
          <View style={styles.locationBadgeRemote}>
            <Text style={styles.locationIconRemote}>⚠️</Text>
            <Text style={styles.locationTextRemote}>
              {(record.checkin_distance_from_base / 1000).toFixed(1)} km from office
            </Text>
          </View>
        ) : (
          <View style={styles.locationBadgeNormal}>
            <Text style={styles.locationIconNormal}>✓</Text>
            <Text style={styles.locationTextNormal}>At office</Text>
          </View>
        )}
      </View>
    )}
    {record.checkout_latitude && (
      <View style={styles.locationRow}>
        <Text style={styles.locationLabel}>Check-out: </Text>
        <View style={styles.locationBadgeNormal}>
          <Text style={styles.locationIconNormal}>📍</Text>
          <Text style={styles.locationTextNormal}>Location captured</Text>
        </View>
      </View>
    )}
  </View>
)}
```

### Add these styles to the StyleSheet (at the end, around line 800):

```javascript
locationInfoSection: {
  marginTop: 8,
  paddingTop: 8,
  borderTopWidth: 1,
  borderTopColor: '#F3F4F6',
},
locationRow: {
  flexDirection: 'row',
  alignItems: 'center',
  marginBottom: 4,
},
locationLabel: {
  fontSize: 12,
  color: '#6B7280',
  fontWeight: '500',
},
locationBadgeNormal: {
  flexDirection: 'row',
  alignItems: 'center',
  backgroundColor: '#D1FAE5',
  paddingHorizontal: 8,
  paddingVertical: 3,
  borderRadius: 12,
},
locationBadgeRemote: {
  flexDirection: 'row',
  alignItems: 'center',
  backgroundColor: '#FEE2E2',
  paddingHorizontal: 8,
  paddingVertical: 3,
  borderRadius: 12,
},
locationIconNormal: {
  fontSize: 11,
  marginRight: 4,
},
locationIconRemote: {
  fontSize: 11,
  marginRight: 4,
},
locationTextNormal: {
  fontSize: 11,
  color: '#065F46',
  fontWeight: '600',
},
locationTextRemote: {
  fontSize: 11,
  color: '#991B1B',
  fontWeight: '600',
},
```

---

## PART 3: Mobile App - Store Mode (StoreAttendanceScreen)

### File: `src/screens/StoreAttendanceScreen.js`

### Step 1: Update `renderEmployeeCard` function (around line 148-202)

**FIND** the section that shows time details (around line 172-192) and ADD location info after it:

```javascript
{/* Row 2: Time Details (only if present) */}
{hasAttendance && (
  <View style={styles.cardRow2}>
    <View style={styles.timeInfo}>
      <Text style={styles.timeLabel}>In:</Text>
      <Text style={styles.timeValue}>{formatTime(employee.login_time)}</Text>
    </View>
    <View style={styles.timeInfo}>
      <Text style={styles.timeLabel}>Out:</Text>
      <Text style={styles.timeValue}>{formatTime(employee.logout_time)}</Text>
    </View>
    <View style={styles.timeInfo}>
      <Text style={styles.timeLabel}>Hours:</Text>
      <Text style={styles.timeValue}>{employee.hours}h</Text>
    </View>
    {employee.is_overtime && (
      <View style={styles.otBadge}>
        <Text style={styles.otText}>OT {employee.overtime_minutes}m</Text>
      </View>
    )}
  </View>
)}

{/* ADD THIS NEW SECTION: Location Badge Row */}
{hasAttendance && employee.checkin_latitude && (
  <View style={styles.locationBadgeRow}>
    {employee.is_remote_checkin ? (
      <View style={styles.locationBadgeRemote}>
        <Text style={styles.locationIcon}>⚠️</Text>
        <Text style={styles.locationTextRemote}>
          Remote: {(employee.checkin_distance_from_base / 1000).toFixed(1)} km from office
        </Text>
      </View>
    ) : (
      <View style={styles.locationBadgeOnsite}>
        <Text style={styles.locationIcon}>✓</Text>
        <Text style={styles.locationTextOnsite}>At office</Text>
      </View>
    )}
  </View>
)}

{/* Shift Info */}
<View style={styles.cardRow3}>
  <Text style={styles.shiftInfo}>
    Shift: {employee.shift_name || `${employee.shift_start} - ${employee.shift_end}`}
  </Text>
</View>
```

### Step 2: Add Location Filter (Add after status filter buttons, around line 270-310)

**FIND** the filter buttons section and ADD a location filter:

```javascript
{/* Status Filter Buttons */}
<View style={styles.filterContainer}>
  <TouchableOpacity
    style={[styles.filterButton, statusFilter === 'all' && styles.filterButtonActive]}
    onPress={() => setStatusFilter('all')}>
    <Text style={[styles.filterButtonText, statusFilter === 'all' && styles.filterButtonTextActive]}>
      All ({attendance.length})
    </Text>
  </TouchableOpacity>
  <TouchableOpacity
    style={[styles.filterButton, statusFilter === 'present' && styles.filterButtonActive]}
    onPress={() => setStatusFilter('present')}>
    <Text style={[styles.filterButtonText, statusFilter === 'present' && styles.filterButtonTextActive]}>
      Present ({summary?.present || 0})
    </Text>
  </TouchableOpacity>
  <TouchableOpacity
    style={[styles.filterButton, statusFilter === 'absent' && styles.filterButtonActive]}
    onPress={() => setStatusFilter('absent')}>
    <Text style={[styles.filterButtonText, statusFilter === 'absent' && styles.filterButtonTextActive]}>
      Absent ({summary?.absent || 0})
    </Text>
  </TouchableOpacity>
  <TouchableOpacity
    style={[styles.filterButton, statusFilter === 'late' && styles.filterButtonActive]}
    onPress={() => setStatusFilter('late')}>
    <Text style={[styles.filterButtonText, statusFilter === 'late' && styles.filterButtonTextActive]}>
      Late ({summary?.late || 0})
    </Text>
  </TouchableOpacity>
  
  {/* ADD THIS: Remote Filter Button */}
  <TouchableOpacity
    style={[styles.filterButton, statusFilter === 'remote' && styles.filterButtonActive]}
    onPress={() => setStatusFilter('remote')}>
    <Text style={[styles.filterButtonText, statusFilter === 'remote' && styles.filterButtonTextActive]}>
      📍 Remote
    </Text>
  </TouchableOpacity>
</View>
```

### Step 3: Update filter logic (around line 100-135)

**FIND** the `useEffect` that applies filters and UPDATE it:

```javascript
// Apply filters
useEffect(() => {
  let filtered = [...attendance];

  // Search filter
  if (searchQuery.trim()) {
    filtered = filtered.filter(emp =>
      emp.fullname.toLowerCase().includes(searchQuery.toLowerCase())
    );
  }

  // Status filter
  if (statusFilter !== 'all') {
    filtered = filtered.filter(emp => {
      if (statusFilter === 'present') return emp.login_time;
      if (statusFilter === 'absent') return !emp.login_time && !emp.leave_request_id;
      if (statusFilter === 'late') return emp.is_late;
      if (statusFilter === 'leave') return emp.leave_request_id;
      // ADD THIS: Remote filter
      if (statusFilter === 'remote') return emp.is_remote_checkin == 1;
      return true;
    });
  }

  setFilteredAttendance(filtered);
}, [attendance, searchQuery, statusFilter]);
```

### Step 4: Add styles to StyleSheet (at the end, around line 500):

```javascript
locationBadgeRow: {
  marginTop: 8,
  flexDirection: 'row',
  alignItems: 'center',
},
locationBadgeOnsite: {
  flexDirection: 'row',
  alignItems: 'center',
  backgroundColor: '#D1FAE5',
  paddingHorizontal: 10,
  paddingVertical: 5,
  borderRadius: 12,
},
locationBadgeRemote: {
  flexDirection: 'row',
  alignItems: 'center',
  backgroundColor: '#FEE2E2',
  paddingHorizontal: 10,
  paddingVertical: 5,
  borderRadius: 12,
},
locationIcon: {
  fontSize: 12,
  marginRight: 4,
},
locationTextOnsite: {
  fontSize: 11,
  color: '#065F46',
  fontWeight: '600',
},
locationTextRemote: {
  fontSize: 11,
  color: '#991B1B',
  fontWeight: '600',
},
```

---

## PART 4: Ensure Backend Returns Location Data

### File: `app/Http/Controllers/CRM/AttendanceController.php`

**Ensure the `data()` method includes location fields (around line 120)**:

```php
'a.checkin_latitude',
'a.checkin_longitude',
'a.checkin_distance_from_base',
'a.is_remote_checkin',
'a.checkout_latitude',
'a.checkout_longitude',
```

### File: `app/Http/Controllers/API/RiderController.php`

**Ensure store attendance endpoint returns location data. Find the `getStoreAttendanceDaily()` method and ensure it selects:**

```php
'a.checkin_latitude',
'a.checkin_longitude',
'a.checkin_distance_from_base',
'a.is_remote_checkin',
```

---

## Summary of Changes

### Web App:
✅ Added location filter dropdown (All, Onsite, Remote, No Location)
✅ Added Location column to attendance table
✅ Location badges with icons:
  - 🟢 Green "At office" for onsite (≤2km)
  - 🔴 Red "X.X km away" for remote (>2km)
  - ⚪ Gray "No location" for missing GPS

### Mobile - Rider Mode:
✅ Location display in attendance history
✅ Shows check-in location badge
✅ Shows check-out location captured

### Mobile - Store Mode:
✅ Location badge on each employee card
✅ Remote filter button to show only remote check-ins
✅ Compact badge design that doesn't clutter UI

---

## Visual Design

**Location Badges:**
- **Onsite**: Green badge with checkmark ✓
- **Remote**: Red badge with warning ⚠️ + distance
- **No Location**: Gray badge with question mark

**Icons:**
- Map pin icon for location
- Checkmark for onsite
- Warning for remote

**Placement:**
- Web: New column between Logout and Hours
- Mobile Rider: Below time info in history
- Mobile Store: Below time row on card

---

## Testing Checklist

- [ ] Web app shows location badges correctly
- [ ] Web app location filter works (all, onsite, remote, no location)
- [ ] Mobile rider history shows location for past records
- [ ] Mobile store shows location on employee cards
- [ ] Mobile store remote filter works
- [ ] Remote attendance (>2km) shows correct distance
- [ ] Onsite attendance (≤2km) shows green badge
- [ ] No location captured shows gray badge

**Ready to implement!** 🚀

