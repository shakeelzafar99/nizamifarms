@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Attendance Reports</h1>
      <p class="text-sm text-gray-500 mt-1">Comprehensive analytics and insights</p>
    </div>
    <a href="/attendance" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition">
      ← Back to Attendance
    </a>
  </div>

  <!-- Month Selector & Working Days Config -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-6 mb-6">
    <div class="flex items-center gap-4 mb-4">
      <label class="text-sm font-semibold text-gray-700">Select Month:</label>
      <input 
        type="month" 
        id="reportMonth" 
        value="<?php echo date('Y-m'); ?>"
        class="px-4 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        onchange="loadMonthlyReport()"
      >
      <button 
        onclick="toggleWorkingDaysConfig()" 
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
      >
        ⚙️ Configure Working Days
      </button>
      <button 
        onclick="exportToCSV()" 
        class="ml-auto px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium"
      >
        📥 Export CSV
      </button>
    </div>

    <!-- Working Days Configuration (Collapsible) -->
    <div id="workingDaysConfig" class="hidden border-t border-gray-200 pt-4 mt-4" style="display: none;">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Working Days Selection -->
        <div>
          <h4 class="text-sm font-semibold text-gray-700 mb-3">Select Working Days:</h4>
          <div class="space-y-2">
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_monday" value="1" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Monday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_tuesday" value="2" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Tuesday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_wednesday" value="3" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Wednesday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_thursday" value="4" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Thursday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_friday" value="5" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Friday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_saturday" value="6" checked onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Saturday</span>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="day_sunday" value="0" onchange="calculateWorkingDays()" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
              <span class="text-sm text-gray-700">Sunday</span>
            </label>
          </div>
          
          <!-- Working Days Counter + Apply Button RIGHT HERE -->
          <div class="mt-4 p-4 bg-blue-50 border-2 border-blue-400 rounded-lg">
            <div class="flex items-center justify-between gap-3">
              <span class="text-base font-bold text-gray-800">
                Working Days: <span id="workingDaysCount" class="text-blue-600 text-2xl font-black">0</span>
              </span>
              <button 
                onclick="applyWorkingDaysConfig()" 
                class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition font-medium whitespace-nowrap"
              >
                ✓ Apply
              </button>
            </div>
          </div>
        </div>

        <!-- Holidays -->
        <div>
          <h4 class="text-sm font-semibold text-gray-700 mb-3">Exclude Holidays:</h4>
          <div class="space-y-2">
            <div class="flex gap-2">
              <input 
                type="date" 
                id="holidayDate" 
                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
              >
              <button 
                onclick="addHoliday()" 
                class="px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm font-medium"
              >
                + Add
              </button>
            </div>
            <div id="holidaysList" class="mt-3 space-y-1">
              <!-- Holidays will be added here -->
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Summary Statistics - Compact One Row -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-6">
    <div class="grid grid-cols-4 gap-4">
      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">👥</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-blue-600" id="statTotalEmployees">0</p>
        <p class="text-xs text-gray-500 mt-1">Employees</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">✓</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-green-600" id="statAvgAttendance">0%</p>
        <p class="text-xs text-gray-500 mt-1">Avg Attendance</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-red-500 to-red-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">⚠</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-red-600" id="statTotalLate">0</p>
        <p class="text-xs text-gray-500 mt-1">Late Instances</p>
      </div>

      <div class="text-center">
        <div class="flex justify-center mb-1">
          <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-full flex items-center justify-center text-white">
            <span class="text-xl">⏱</span>
          </div>
        </div>
        <p class="text-2xl font-bold text-purple-600" id="statTotalHours">0h</p>
        <p class="text-xs text-gray-500 mt-1">Total Hours</p>
      </div>
    </div>
  </div>

  <!-- Employee Summary Table -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm mb-6">
    <div class="p-4 border-b border-gray-200 bg-gray-50">
      <h3 class="text-lg font-semibold text-gray-900">Employee Summary</h3>
      <p class="text-sm text-gray-500">Click on any employee to view daily details</p>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Present Days</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Attendance %</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Late Days</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Avg Late</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">OT Days</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Total Hours</th>
            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody id="reportBody" class="bg-white divide-y divide-gray-200">
          <tr>
            <td colspan="8" class="px-4 py-8 text-center text-gray-500 text-sm">Loading report...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Daily Details Modal -->
<div id="dailyDetailsModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 backdrop-blur-sm" style="z-index: 9999;">
  <div class="bg-white rounded-2xl shadow-2xl max-w-6xl w-full max-h-[90vh] overflow-hidden">
    <!-- Modal Header -->
    <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6 text-white">
      <div class="flex items-center justify-between">
        <div>
          <h3 class="text-2xl font-bold" id="modalEmployeeName">Employee Name</h3>
          <p class="text-sm opacity-90 mt-1" id="modalMonthYear">Month Year</p>
        </div>
        <button onclick="closeDailyDetails()" class="w-10 h-10 rounded-full bg-white bg-opacity-20 hover:bg-opacity-30 transition flex items-center justify-center">
          <span class="text-2xl leading-none">&times;</span>
        </button>
      </div>

      <!-- Quick Stats -->
      <div class="grid grid-cols-4 gap-4 mt-6">
        <div class="text-center">
          <p class="text-sm opacity-75">Present</p>
          <p class="text-2xl font-bold" id="modalStatPresent">0</p>
        </div>
        <div class="text-center">
          <p class="text-sm opacity-75">Late</p>
          <p class="text-2xl font-bold" id="modalStatLate">0</p>
        </div>
        <div class="text-center">
          <p class="text-sm opacity-75">Overtime</p>
          <p class="text-2xl font-bold" id="modalStatOT">0</p>
        </div>
        <div class="text-center">
          <p class="text-sm opacity-75">Total Hours</p>
          <p class="text-2xl font-bold" id="modalStatHours">0h</p>
        </div>
      </div>
    </div>

    <!-- Daily Details Table -->
    <div class="overflow-y-auto max-h-[calc(90vh-280px)] p-6">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50 sticky top-0">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Login</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logout</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hours</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Late By</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Overtime</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
          </tr>
        </thead>
        <tbody id="dailyDetailsBody" class="bg-white divide-y divide-gray-200">
          <!-- Populated by JS -->
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
let reportData = [];
let currentMonth = '';
let workingDays = [1, 2, 3, 4, 5, 6]; // Mon-Sat by default (0=Sun, 1=Mon, ... 6=Sat)
let holidays = []; // Array of date strings 'YYYY-MM-DD'
let calculatedWorkingDays = 0;

document.addEventListener('DOMContentLoaded', function() {
  loadSavedConfiguration();
  loadMonthlyReport();
  calculateWorkingDays();
});

// Load saved working days configuration from localStorage
function loadSavedConfiguration() {
  const saved = localStorage.getItem('attendanceWorkingDaysConfig');
  if (saved) {
    try {
      const config = JSON.parse(saved);
      workingDays = config.workingDays || [1, 2, 3, 4, 5, 6];
      holidays = config.holidays || [];
      
      // Update checkboxes to match saved config
      ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
        const checkbox = document.getElementById(`day_${day}`);
        if (checkbox) {
          const jsDay = index === 6 ? 0 : index + 1;
          checkbox.checked = workingDays.includes(jsDay);
        }
      });
      
      // Render saved holidays
      renderHolidays();
      
      console.log('Loaded saved configuration:', config);
    } catch (e) {
      console.error('Error loading saved configuration:', e);
    }
  }
}

async function loadMonthlyReport() {
  const month = document.getElementById('reportMonth').value;
  currentMonth = month;

  try {
    const res = await fetch(`/attendance/monthly-report?month=${month}`);
    const json = await res.json();

    if (json.success) {
      reportData = json.data;
      renderReportTable(reportData);
      updateStatistics(reportData, month);
    }
  } catch(e) {
    console.error('Error loading monthly report', e);
    document.getElementById('reportBody').innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-red-500 text-sm">Error loading report</td></tr>';
  }
}

function renderReportTable(data) {
  const body = document.getElementById('reportBody');

  if (!data || data.length === 0) {
    body.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-gray-500 text-sm">No data for selected month</td></tr>';
    return;
  }

  body.innerHTML = data.map(emp => {
    // Use calculated working days instead of total calendar days
    const attendancePerc = calculatedWorkingDays > 0 ? ((emp.present_days / calculatedWorkingDays) * 100).toFixed(1) : 0;
    const avgLate = emp.late_days > 0 ? (emp.total_late_minutes / emp.late_days).toFixed(0) : 0;
    
    return `
      <tr class="hover:bg-gray-50">
        <td class="px-4 py-3 text-sm font-medium text-gray-900">${emp.fullname || 'Unknown'}</td>
        <td class="px-4 py-3 text-sm text-center">${emp.present_days} / ${calculatedWorkingDays}</td>
        <td class="px-4 py-3 text-sm text-center">
          <span class="px-2 py-1 rounded-full text-xs font-medium ${attendancePerc >= 90 ? 'bg-green-100 text-green-700' : attendancePerc >= 75 ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700'}">
            ${attendancePerc}%
          </span>
        </td>
        <td class="px-4 py-3 text-sm text-center ${emp.late_days > 0 ? 'text-red-600 font-semibold' : 'text-gray-400'}">${emp.late_days}</td>
        <td class="px-4 py-3 text-sm text-center ${emp.late_days > 0 ? 'text-red-600' : 'text-gray-400'}">${avgLate > 0 ? avgLate + 'm' : '-'}</td>
        <td class="px-4 py-3 text-sm text-center ${emp.overtime_days > 0 ? 'text-green-600 font-semibold' : 'text-gray-400'}">${emp.overtime_days}</td>
        <td class="px-4 py-3 text-sm text-center font-semibold">${(emp.total_hours || 0).toFixed(1)}h</td>
        <td class="px-4 py-3 text-sm text-center">
          <button 
            onclick="showDailyDetails(${emp.user_id})"
            class="px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition text-xs font-medium"
          >
            View Details
          </button>
        </td>
      </tr>
    `;
  }).join('');
}

function updateStatistics(data, month) {
  const totalEmployees = data.length;
  const totalPresent = data.reduce((sum, emp) => sum + (emp.present_days || 0), 0);
  const avgAttendance = totalEmployees > 0 && calculatedWorkingDays > 0 ? ((totalPresent / (totalEmployees * calculatedWorkingDays)) * 100).toFixed(1) : 0;
  const totalLate = data.reduce((sum, emp) => sum + (emp.late_days || 0), 0);
  const totalHours = data.reduce((sum, emp) => sum + (emp.total_hours || 0), 0);

  document.getElementById('statTotalEmployees').textContent = totalEmployees;
  document.getElementById('statAvgAttendance').textContent = avgAttendance + '%';
  document.getElementById('statTotalLate').textContent = totalLate;
  document.getElementById('statTotalHours').textContent = totalHours.toFixed(0) + 'h';
}

function showDailyDetails(userId) {
  console.log('showDailyDetails called with userId:', userId);
  console.log('reportData:', reportData);
  
  const employee = reportData.find(emp => emp.user_id == userId);
  if (!employee) {
    console.error('Employee not found:', userId);
    console.error('Available user_ids:', reportData.map(e => e.user_id));
    alert('Employee data not found. Check console for details.');
    return;
  }

  console.log('Found employee:', employee);
  console.log('Employee daily data:', employee.daily);

  document.getElementById('modalEmployeeName').textContent = employee.fullname;
  document.getElementById('modalMonthYear').textContent = new Date(currentMonth + '-01').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  document.getElementById('modalStatPresent').textContent = employee.present_days;
  document.getElementById('modalStatLate').textContent = employee.late_days;
  document.getElementById('modalStatOT').textContent = employee.overtime_days;
  document.getElementById('modalStatHours').textContent = employee.total_hours.toFixed(1) + 'h';

  const body = document.getElementById('dailyDetailsBody');
  
  if (!employee.daily || employee.daily.length === 0) {
    body.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No daily records found</td></tr>';
  } else {
    body.innerHTML = employee.daily.map(day => {
      const loginTime = day.login_time || '-';
      const logoutTime = day.logout_time || '-';
      const hours = calculateHours(day.login_time, day.logout_time);
      const lateBy = calculateLateBy(day.login_time, day.shift_start);
      const overtime = calculateOvertime(day.logout_time, day.shift_end);
      const status = getStatus(day.login_time, day.shift_start);

      return `
        <tr class="hover:bg-gray-50">
          <td class="px-4 py-3 text-sm">${day.attendance_date}</td>
          <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-medium' : ''}">${loginTime}</td>
          <td class="px-4 py-3 text-sm">${logoutTime}</td>
          <td class="px-4 py-3 text-sm font-medium">${hours}</td>
          <td class="px-4 py-3 text-sm ${lateBy.isLate ? 'text-red-600 font-semibold' : 'text-gray-400'}">${lateBy.duration}</td>
          <td class="px-4 py-3 text-sm ${overtime.hasOvertime ? 'text-green-600 font-semibold' : 'text-gray-400'}">${overtime.duration}</td>
          <td class="px-4 py-3 text-sm">${status}</td>
        </tr>
      `;
    }).join('');
  }

  const modal = document.getElementById('dailyDetailsModal');
  modal.classList.remove('hidden');
  
  // Close on background click
  modal.onclick = function(e) {
    if (e.target === modal) {
      closeDailyDetails();
    }
  };
}

function closeDailyDetails() {
  document.getElementById('dailyDetailsModal').classList.add('hidden');
}

function calculateHours(login, logout) {
  if (!login || !logout) return '-';
  const [lh, lm] = login.split(':').map(Number);
  const [oh, om] = logout.split(':').map(Number);
  const loginM = lh * 60 + lm;
  const logoutM = oh * 60 + om;
  const diff = logoutM - loginM;
  if (diff < 0) return '-';
  const h = Math.floor(diff / 60);
  const m = diff % 60;
  return `${h}h ${m}m`;
}

function calculateLateBy(loginTime, shiftStart) {
  if (!loginTime) return { isLate: false, duration: '-' };
  try {
    const shift = shiftStart || '09:00';
    if (loginTime <= shift) return { isLate: false, duration: '-' };
    
    const [lh, lm] = loginTime.split(':').map(Number);
    const [sh, sm] = shift.split(':').map(Number);
    if (isNaN(lh) || isNaN(lm) || isNaN(sh) || isNaN(sm)) return { isLate: false, duration: '-' };
    
    const diff = (lh * 60 + lm) - (sh * 60 + sm);
    const h = Math.floor(diff / 60);
    const m = diff % 60;
    return { isLate: true, duration: h > 0 ? `${h}h ${m}m` : `${m}m` };
  } catch (e) {
    console.warn('Error calculating late by:', e);
    return { isLate: false, duration: '-' };
  }
}

function calculateOvertime(logoutTime, shiftEnd) {
  if (!logoutTime) return { hasOvertime: false, duration: '-' };
  try {
    const end = shiftEnd || '17:00';
    if (logoutTime <= end) return { hasOvertime: false, duration: '-' };
    
    const [lh, lm] = logoutTime.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    if (isNaN(lh) || isNaN(lm) || isNaN(eh) || isNaN(em)) return { hasOvertime: false, duration: '-' };
    
    const diff = (lh * 60 + lm) - (eh * 60 + em);
    if (diff <= 0) return { hasOvertime: false, duration: '-' };
    
    const h = Math.floor(diff / 60);
    const m = diff % 60;
    return { hasOvertime: true, duration: h > 0 ? `${h}h ${m}m` : `${m}m` };
  } catch (e) {
    console.warn('Error calculating overtime:', e);
    return { hasOvertime: false, duration: '-' };
  }
}

function getStatus(loginTime, shiftStart) {
  if (!loginTime) return '<span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-medium">Absent</span>';
  try {
    const shift = shiftStart || '09:00';
    if (loginTime > shift) return '<span class="px-2 py-1 bg-red-100 text-red-600 rounded-full text-xs font-medium">Late</span>';
    return '<span class="px-2 py-1 bg-green-100 text-green-600 rounded-full text-xs font-medium">On Time</span>';
  } catch (e) {
    console.warn('Error getting status:', e);
    return '<span class="px-2 py-1 bg-blue-100 text-blue-600 rounded-full text-xs font-medium">Present</span>';
  }
}

function exportToCSV() {
  let csv = 'Employee,Present Days,Attendance %,Late Days,Avg Late (min),OT Days,Total Hours\n';
  
  reportData.forEach(emp => {
    const attendancePerc = calculatedWorkingDays > 0 ? ((emp.present_days / calculatedWorkingDays) * 100).toFixed(1) : 0;
    const avgLate = emp.late_days > 0 ? (emp.total_late_minutes / emp.late_days).toFixed(0) : 0;
    csv += `${emp.fullname},${emp.present_days}/${calculatedWorkingDays},${attendancePerc}%,${emp.late_days},${avgLate},${emp.overtime_days},${emp.total_hours.toFixed(1)}\n`;
  });

  const blob = new Blob([csv], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `attendance_report_${currentMonth}.csv`;
  a.click();
}

// Working Days Configuration Functions
function toggleWorkingDaysConfig() {
  const config = document.getElementById('workingDaysConfig');
  if (config.style.display === 'none' || config.classList.contains('hidden')) {
    config.style.display = 'block';
    config.classList.remove('hidden');
  } else {
    config.style.display = 'none';
    config.classList.add('hidden');
  }
  calculateWorkingDays();
}

function calculateWorkingDays() {
  if (!currentMonth) {
    console.log('No currentMonth set yet');
    return;
  }
  
  // Read current checkbox state
  const selectedDays = [];
  ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
    const checkbox = document.getElementById(`day_${day}`);
    if (checkbox && checkbox.checked) {
      // Convert to JS day (0=Sun, 1=Mon, etc.)
      const jsDay = index === 6 ? 0 : index + 1;
      selectedDays.push(jsDay);
    }
  });
  
  console.log('Selected days:', selectedDays);
  
  const [year, month] = currentMonth.split('-');
  const daysInMonth = new Date(year, month, 0).getDate();
  let count = 0;
  
  for (let day = 1; day <= daysInMonth; day++) {
    const date = new Date(year, month - 1, day);
    const dayOfWeek = date.getDay();
    const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    
    // Check if it's a working day and not a holiday
    if (selectedDays.includes(dayOfWeek) && !holidays.includes(dateStr)) {
      count++;
    }
  }
  
  console.log('Calculated working days:', count);
  calculatedWorkingDays = count;
  const countEl = document.getElementById('workingDaysCount');
  if (countEl) {
    countEl.textContent = count;
  }
}

function applyWorkingDaysConfig() {
  // Get selected working days
  workingDays = [];
  ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].forEach((day, index) => {
    const checkbox = document.getElementById(`day_${day}`);
    if (checkbox && checkbox.checked) {
      // Convert to JS day (0=Sun, 1=Mon, etc.)
      const jsDay = index === 6 ? 0 : index + 1;
      workingDays.push(jsDay);
    }
  });
  
  // Save configuration to localStorage
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  console.log('Saved configuration:', config);
  
  calculateWorkingDays();
  loadMonthlyReport(); // Reload with new calculation
  
  // Show success message
  alert('✓ Working days configuration saved! This will be used for all future calculations.');
}

function addHoliday() {
  const dateInput = document.getElementById('holidayDate');
  const dateStr = dateInput.value;
  
  if (!dateStr) {
    alert('Please select a date');
    return;
  }
  
  if (holidays.includes(dateStr)) {
    alert('This date is already added');
    return;
  }
  
  holidays.push(dateStr);
  
  // Save to localStorage immediately
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  
  renderHolidays();
  calculateWorkingDays();
  dateInput.value = '';
}

function removeHoliday(dateStr) {
  holidays = holidays.filter(h => h !== dateStr);
  
  // Save to localStorage immediately
  const config = {
    workingDays: workingDays,
    holidays: holidays
  };
  localStorage.setItem('attendanceWorkingDaysConfig', JSON.stringify(config));
  
  renderHolidays();
  calculateWorkingDays();
}

function renderHolidays() {
  const list = document.getElementById('holidaysList');
  if (!list) return;
  
  if (holidays.length === 0) {
    list.innerHTML = '<p class="text-sm text-gray-500 italic">No holidays added</p>';
    return;
  }
  
  list.innerHTML = holidays.map(date => {
    const formatted = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    return `
      <div class="flex items-center justify-between p-2 bg-red-50 border border-red-200 rounded text-sm">
        <span class="text-red-700">${formatted}</span>
        <button onclick="removeHoliday('${date}')" class="text-red-600 hover:text-red-800 font-bold">&times;</button>
      </div>
    `;
  }).join('');
}
</script>
@endsection

