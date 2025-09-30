@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">Attendance Management</h1>
    <div class="flex gap-2">
      <button onclick="showShiftManager()" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
        ⏰ Manage Shifts
      </button>
      <button onclick="showSummary()" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
        📊 View Summary
      </button>
    </div>
  </div>

  <!-- Add/Mark Attendance Card -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Mark Attendance</h2>
    
    <!-- Employee and Date Selection -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <!-- User Select Dropdown -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Select Employee *</label>
        <select 
          id="userSelect" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          onchange="selectUserFromDropdown()"
        >
          <option value="">-- Select Employee --</option>
        </select>
      </div>

      <!-- Date -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Date *</label>
        <input 
          id="attDate" 
          type="date" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          value="<?php echo date('Y-m-d'); ?>"
          onchange="loadExistingAttendance()"
        >
      </div>
    </div>

    <!-- Quick Info Display -->
    <div id="userInfo" class="mb-4 p-4 bg-blue-50 border-2 border-blue-200 rounded-lg hidden">
      <div class="flex items-center gap-3">
        <span class="text-3xl">👤</span>
        <div>
          <p class="text-base font-semibold text-gray-800"><span id="infoName"></span></p>
          <p class="text-sm text-gray-600">Expected Shift: <span id="infoShift" class="font-medium"></span></p>
        </div>
      </div>
    </div>

    <!-- Time Inputs -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <!-- Login Time -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
        <input 
          id="loginTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          step="60"
        >
      </div>

      <!-- Logout Time -->
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Logout Time</label>
        <input 
          id="logoutTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          step="60"
        >
      </div>
    </div>

    <!-- Action Buttons - Large and Prominent -->
    <div class="grid grid-cols-2 gap-3">
      <button 
        onclick="clearForm()" 
        class="px-6 py-4 bg-gray-100 border-2 border-gray-300 text-gray-700 rounded-lg hover:bg-gray-200 transition font-semibold text-base"
      >
        🗑️ Clear All
      </button>
      <button 
        onclick="saveAttendance()" 
        class="px-6 py-4 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold text-base shadow-lg"
      >
        💾 Save Attendance
      </button>
    </div>
  </div>

  <!-- Filter & Records -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
    <!-- Filter Bar -->
    <div class="p-4 border-b border-gray-200 bg-gray-50 flex items-center gap-3 flex-wrap">
      <div class="flex-1 min-w-[200px]">
        <select id="filterUser" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
          <option value="">All Employees</option>
        </select>
      </div>
      <input 
        id="filterDate" 
        type="date" 
        class="border border-gray-300 rounded-lg px-3 py-2 text-sm"
      >
      <select id="filterStatus" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <option value="">All Status</option>
        <option value="present">Present</option>
        <option value="absent">Absent</option>
        <option value="late">Late</option>
      </select>
      <button 
        onclick="loadAttendance()" 
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
      >
        🔍 Filter
      </button>
      <button 
        onclick="clearFilters()" 
        class="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition"
      >
        ✕ Clear
      </button>
    </div>

    <!-- Attendance Table -->
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expected</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Login</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logout</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hours</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Late By</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Overtime</th>
          </tr>
        </thead>
        <tbody id="attBody" class="bg-white divide-y divide-gray-200">
          <tr>
            <td colspan="8" class="px-4 py-8 text-center text-gray-500 text-sm">Loading attendance records...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Shift Manager Modal -->
<div id="shiftModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
    <div class="p-6 border-b border-gray-200 flex justify-between items-center sticky top-0 bg-white">
      <h3 class="text-xl font-semibold text-gray-900">Manage Employee Shifts</h3>
      <button onclick="closeShiftManager()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    <div class="p-6">
      <div class="mb-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-sm text-blue-800">💡 <strong>Note:</strong> Set custom shift timings for each employee. Default is 09:00 - 17:00. Riders can have flexible timings.</p>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shift Start</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shift End</th>
              <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
            </tr>
          </thead>
          <tbody id="shiftTableBody" class="bg-white divide-y divide-gray-200">
            <tr>
              <td colspan="5" class="px-4 py-8 text-center text-gray-500 text-sm">Loading employees...</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Summary Modal -->
<div id="summaryModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
  <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
    <div class="p-6 border-b border-gray-200 flex justify-between items-center">
      <h3 class="text-xl font-semibold text-gray-900">Attendance Summary</h3>
      <button onclick="closeSummary()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    <div class="p-6">
      <div class="flex gap-4 mb-6">
        <input id="summaryStartDate" type="date" class="border rounded-lg px-3 py-2 text-sm">
        <input id="summaryEndDate" type="date" class="border rounded-lg px-3 py-2 text-sm" value="<?php echo date('Y-m-d'); ?>">
        <button onclick="loadSummary()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Generate</button>
      </div>
      <div id="summaryContent">
        <p class="text-gray-500 text-center py-8">Select date range and click Generate</p>
      </div>
    </div>
  </div>
</div>

<script>
let allUsers = [];
let selectedUserData = null;

// Load all users on page load
async function loadAllUsers() {
  try {
    const res = await fetch('/users/all', { headers: { 'Accept': 'application/json' }});
    const json = await res.json();
    if (json.success) {
      allUsers = json.data || [];
      populateUserDropdowns();
    }
  } catch(e) {
    console.error('Failed to load users', e);
    alert('Failed to load users. Please refresh the page.');
  }
}

function populateUserDropdowns() {
  // Populate main select dropdown
  const select = document.getElementById('userSelect');
  select.innerHTML = '<option value="">-- Select Employee --</option>' + 
    allUsers.map(u => `<option value="${u.id}" data-shift-start="${u.shift_start}" data-shift-end="${u.shift_end}" data-fullname="${u.fullname}" data-role="${u.role_name || 'Staff'}">${u.fullname || 'User #' + u.id} (${u.role_name || 'Staff'})</option>`).join('');

  // Populate filter dropdown
  const filterSelect = document.getElementById('filterUser');
  filterSelect.innerHTML = '<option value="">All Employees</option>' + 
    allUsers.map(u => `<option value="${u.id}">${u.fullname || 'User #' + u.id}</option>`).join('');
}

function selectUserFromDropdown() {
  const select = document.getElementById('userSelect');
  const selectedOption = select.options[select.selectedIndex];
  
  if (!selectedOption.value) {
    document.getElementById('userInfo').classList.add('hidden');
    selectedUserData = null;
    clearForm();
    return;
  }

  const userId = selectedOption.value;
  const userName = selectedOption.getAttribute('data-fullname');
  const shiftStart = selectedOption.getAttribute('data-shift-start');
  const shiftEnd = selectedOption.getAttribute('data-shift-end');
  const role = selectedOption.getAttribute('data-role');

  selectedUserData = { id: userId, name: userName, shiftStart, shiftEnd, role };

  // Show user info
  const info = document.getElementById('userInfo');
  document.getElementById('infoName').textContent = userName + ' (' + role + ')';
  document.getElementById('infoShift').textContent = `${shiftStart} - ${shiftEnd}`;
  info.classList.remove('hidden');
  
  // Load existing attendance for selected date
  loadExistingAttendance();
}

async function loadExistingAttendance() {
  if (!selectedUserData) return;
  
  const date = document.getElementById('attDate').value;
  if (!date) return;
  
  try {
    const res = await fetch(`/attendance/data?user_id=${selectedUserData.id}&date=${date}`, { 
      headers: { 'Accept': 'application/json' }
    });
    const json = await res.json();
    
    if (json.success && json.data && json.data.length > 0) {
      const record = json.data[0];
      document.getElementById('loginTime').value = record.login_time || '';
      document.getElementById('logoutTime').value = record.logout_time || '';
    } else {
      // No existing record, clear fields
      document.getElementById('loginTime').value = '';
      document.getElementById('logoutTime').value = '';
    }
  } catch(e) {
    console.error('Failed to load existing attendance', e);
  }
}

function clearForm() {
  document.getElementById('loginTime').value = '';
  document.getElementById('logoutTime').value = '';
}

function clearLoginTime() {
  document.getElementById('loginTime').value = '';
}

function clearLogoutTime() {
  document.getElementById('logoutTime').value = '';
}

async function saveAttendance() {
  if (!selectedUserData) {
    alert('Please select an employee');
    return;
  }

  const date = document.getElementById('attDate').value;
  const loginTime = document.getElementById('loginTime').value;
  const logoutTime = document.getElementById('logoutTime').value;

  if (!date) {
    alert('Please select a date');
    return;
  }

  if (!loginTime && !logoutTime) {
    alert('Please enter at least login time or logout time');
    return;
  }

  const payload = {
    user_id: parseInt(selectedUserData.id),
    attendance_date: date,
    login_time: loginTime || null,
    logout_time: logoutTime || null
  };

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (json && json.success) {
      alert('✓ Attendance saved successfully!');
      loadAttendance();
    } else {
      alert(json.message || 'Failed to save attendance');
    }
  } catch(e) {
    alert('Error: ' + e.message);
  }
}

async function loadAttendance() {
  const params = new URLSearchParams();
  const userId = document.getElementById('filterUser').value;
  const date = document.getElementById('filterDate').value;
  const status = document.getElementById('filterStatus').value;
  
  if (userId) params.set('user_id', userId);
  if (date) params.set('date', date);
  if (status) params.set('status', status);

  try {
    const res = await fetch('/attendance/data?' + params.toString(), { headers: { 'Accept': 'application/json' }});
    const json = await res.json();
    const body = document.getElementById('attBody');
    
    if (!json.data || json.data.length === 0) {
      body.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 text-sm">No attendance records found</td></tr>';
      return;
    }

    body.innerHTML = json.data.map(r => {
      const hours = calculateHours(r.login_time, r.logout_time);
      const lateBy = calculateLateBy(r.login_time, r.shift_start);
      const overtime = calculateOvertime(r.logout_time, r.shift_end, r.login_time);
      
      return `
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 text-sm font-medium text-gray-900">${r.fullname || '#' + r.user_id}</td>
          <td class="px-4 py-3 text-sm text-gray-600">${r.attendance_date}</td>
          <td class="px-4 py-3 text-sm text-gray-600">${r.shift_start || '09:00'} - ${r.shift_end || '17:00'}</td>
          <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-medium' : 'text-gray-900'}">${r.login_time || '-'}</td>
          <td class="px-4 py-3 text-sm text-gray-900">${r.logout_time || '-'}</td>
          <td class="px-4 py-3 text-sm text-gray-600">${hours}</td>
          <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-semibold' : 'text-gray-400'}">${lateBy.duration}</td>
          <td class="px-4 py-3 text-sm ${overtime.hasOvertime ? 'text-green-600 font-semibold' : 'text-gray-400'}">${overtime.duration}</td>
        </tr>
      `;
    }).join('');
  } catch(e) {
    console.error('Error loading attendance', e);
    document.getElementById('attBody').innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-red-500 text-sm">Error loading data</td></tr>';
  }
}

function calculateLateBy(loginTime, shiftStart) {
  if (!loginTime) {
    return { isLate: false, duration: '-' };
  }
  
  const shift = shiftStart || '09:00';
  if (loginTime <= shift) {
    return { isLate: false, duration: '-' };
  }
  
  // Calculate difference
  const [lh, lm] = loginTime.split(':').map(Number);
  const [sh, sm] = shift.split(':').map(Number);
  const loginMinutes = lh * 60 + lm;
  const shiftMinutes = sh * 60 + sm;
  const diff = loginMinutes - shiftMinutes;
  
  const hours = Math.floor(diff / 60);
  const mins = diff % 60;
  
  if (hours > 0) {
    return { isLate: true, duration: `${hours}h ${mins}m` };
  }
  return { isLate: true, duration: `${mins}m` };
}

function calculateOvertime(logoutTime, shiftEnd, loginTime) {
  if (!logoutTime || !loginTime) {
    return { hasOvertime: false, duration: '-' };
  }
  
  const end = shiftEnd || '17:00';
  if (logoutTime <= end) {
    return { hasOvertime: false, duration: '-' };
  }
  
  // Calculate difference
  const [lh, lm] = logoutTime.split(':').map(Number);
  const [eh, em] = end.split(':').map(Number);
  const logoutMinutes = lh * 60 + lm;
  const endMinutes = eh * 60 + em;
  const diff = logoutMinutes - endMinutes;
  
  if (diff <= 0) {
    return { hasOvertime: false, duration: '-' };
  }
  
  const hours = Math.floor(diff / 60);
  const mins = diff % 60;
  
  if (hours > 0) {
    return { hasOvertime: true, duration: `${hours}h ${mins}m` };
  }
  return { hasOvertime: true, duration: `${mins}m` };
}

function calculateHours(login, logout) {
  if (!login || !logout) return '-';
  const [lh, lm] = login.split(':').map(Number);
  const [oh, om] = logout.split(':').map(Number);
  const minutes = (oh * 60 + om) - (lh * 60 + lm);
  if (minutes < 0) return '-';
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;
  return `${hours}h ${mins}m`;
}

function clearFilters() {
  document.getElementById('filterUser').value = '';
  document.getElementById('filterDate').value = '';
  document.getElementById('filterStatus').value = '';
  loadAttendance();
}

// Shift Manager
function showShiftManager() {
  loadShiftData();
  document.getElementById('shiftModal').classList.remove('hidden');
}

function closeShiftManager() {
  document.getElementById('shiftModal').classList.add('hidden');
}

async function loadShiftData() {
  const tbody = document.getElementById('shiftTableBody');
  tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-gray-500 text-sm">Loading...</td></tr>';

  try {
    const res = await fetch('/users/all', { headers: { 'Accept': 'application/json' }});
    const json = await res.json();
    
    if (json.success && json.data) {
      tbody.innerHTML = json.data.map(u => `
        <tr>
          <td class="px-4 py-3 text-sm font-medium text-gray-900">${u.fullname || 'User #' + u.id}</td>
          <td class="px-4 py-3 text-sm text-gray-600">${u.role_name || 'Staff'}</td>
          <td class="px-4 py-3 text-sm">
            <input type="time" id="shift_start_${u.id}" value="${u.shift_start || '09:00'}" class="border border-gray-300 rounded px-2 py-1 text-sm">
          </td>
          <td class="px-4 py-3 text-sm">
            <input type="time" id="shift_end_${u.id}" value="${u.shift_end || '17:00'}" class="border border-gray-300 rounded px-2 py-1 text-sm">
          </td>
          <td class="px-4 py-3 text-sm">
            <button onclick="saveShift(${u.id})" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs">Save</button>
          </td>
        </tr>
      `).join('');
    }
  } catch(e) {
    tbody.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-red-500 text-sm">Error loading data</td></tr>';
  }
}

async function saveShift(userId) {
  const shiftStart = document.getElementById(`shift_start_${userId}`).value;
  const shiftEnd = document.getElementById(`shift_end_${userId}`).value;

  if (!shiftStart || !shiftEnd) {
    alert('Please enter both start and end times');
    return;
  }

  try {
    const res = await fetch('/riders/shift', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
      },
      body: JSON.stringify({ user_id: userId, shift_start: shiftStart, shift_end: shiftEnd })
    });

    const json = await res.json();
    if (json.success) {
      alert('✓ Shift times saved successfully');
      loadAllUsers(); // Refresh the main dropdowns
    } else {
      alert(json.message || 'Failed to save');
    }
  } catch(e) {
    alert('Error: ' + e.message);
  }
}

// Summary Modal
function showSummary() {
  const endDate = new Date();
  const startDate = new Date();
  startDate.setDate(startDate.getDate() - 30);
  
  document.getElementById('summaryStartDate').value = startDate.toISOString().split('T')[0];
  document.getElementById('summaryEndDate').value = endDate.toISOString().split('T')[0];
  document.getElementById('summaryModal').classList.remove('hidden');
}

function closeSummary() {
  document.getElementById('summaryModal').classList.add('hidden');
}

async function loadSummary() {
  const start = document.getElementById('summaryStartDate').value;
  const end = document.getElementById('summaryEndDate').value;
  
  if (!start || !end) {
    alert('Please select date range');
    return;
  }

  try {
    const res = await fetch(`/attendance/summary?start=${start}&end=${end}`, { headers: { 'Accept': 'application/json' }});
    const json = await res.json();
    
    if (json.success && json.data) {
      const content = document.getElementById('summaryContent');
      content.innerHTML = `
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
          <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="text-green-700 text-sm font-medium">On Time</div>
            <div class="text-2xl font-bold text-green-900">${json.data.on_time || 0}</div>
          </div>
          <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="text-red-700 text-sm font-medium">Late Arrivals</div>
            <div class="text-2xl font-bold text-red-900">${json.data.late || 0}</div>
          </div>
          <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <div class="text-gray-700 text-sm font-medium">Absent</div>
            <div class="text-2xl font-bold text-gray-900">${json.data.absent || 0}</div>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Employee</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Present</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Late</th>
                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Absent</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              ${(json.data.by_user || []).map(u => `
                <tr>
                  <td class="px-4 py-2 text-sm font-medium">${u.name}</td>
                  <td class="px-4 py-2 text-sm">${u.present}</td>
                  <td class="px-4 py-2 text-sm text-red-600">${u.late}</td>
                  <td class="px-4 py-2 text-sm text-gray-600">${u.absent}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }
  } catch(e) {
    alert('Failed to load summary');
  }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
  loadAllUsers();
  loadAttendance();
});
</script>
@endsection