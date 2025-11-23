@extends('layouts.app')

@section('content')
<script>
  // Pass logged-in user info to JavaScript
  window.currentUser = {
    id: {{ auth()->id() }},
    name: "{{ auth()->user()->fullname ?? 'User' }}"
  };
</script>
<div class="max-w-7xl mx-auto p-6">
  <!-- Header with Add Button -->
  <div class="flex justify-between items-center mb-6 relative" style="z-index: 10;">
    <h1 class="text-2xl font-semibold text-gray-900">Attendance Management</h1>
    <div class="flex gap-2 items-center">
      <a href="/attendance/reports" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md border-2 border-blue-700">
        Reports
      </a>
      <div class="flex items-center gap-2 ml-2">
        <label for="activeFilter" class="text-sm text-gray-700">Show:</label>
        <select id="activeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="onActiveFilterChange()">
          <option value="active" selected>Active Users</option>
          <option value="all">All Users</option>
        </select>
      </div>
      <div class="flex items-center gap-2 ml-2">
        <label for="locationFilter" class="text-sm text-gray-700">Location:</label>
        <select id="locationFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="filterByLocation()">
          <option value="all" selected>All</option>
          <option value="onsite">Onsite</option>
          <option value="remote">Remote</option>
          <option value="no_location">No Location</option>
        </select>
      </div>
      <button 
        type="button"
        onclick="toggleAddForm()" 
        id="toggleFormBtn" 
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
        style="position: relative; z-index: 20; pointer-events: auto; cursor: pointer;"
      >
        ➕ Mark Attendance
      </button>
      <a 
        href="/requests/create"
        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold shadow-md inline-block text-center"
        style="text-decoration: none;"
      >
        📝 Leave Request
      </a>
      <!-- Settings Dropdown -->
      <div class="relative inline-block" id="settingsDropdown">
        <button 
          type="button"
          onclick="toggleSettingsMenu(); return false;" 
          class="px-4 py-2 bg-white text-gray-800 rounded-lg hover:bg-gray-100 transition font-semibold shadow-md border-2 border-gray-300 inline-flex items-center gap-2"
        >
          ⚙️ Settings
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>
        <!-- Dropdown Menu -->
        <div id="settingsMenu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 z-50">
          <div class="py-2">
            <a 
              href="/shifts"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📅 Shift Management
            </a>
            <a 
              href="/holidays"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              🎉 Public Holidays
            </a>
            <button 
              type="button"
              onclick="openCustomizeUserList(); toggleSettingsMenu();" 
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              👥 Customize User List
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Mark Attendance Modal -->
  <div id="addAttendanceForm" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-6" onclick="event.stopPropagation()">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold text-gray-800">Mark Attendance</h2>
        <button onclick="toggleAddForm()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
      </div>
    
    <!-- Employee and Date Selection -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
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

    <!-- Employee Info -->
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
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
        <input 
          id="loginTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-4 py-3 text-base focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          step="60"
        >
      </div>

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

    <!-- Action Buttons -->
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
  </div>

  <!-- Summary Cards - Elegant Horizontal Row -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-3 mb-4">
    <div class="flex justify-between items-center mb-2">
      <h3 class="text-sm font-semibold text-gray-700">Summary</h3>
      <div class="flex bg-gray-100 rounded-lg p-0.5">
        <button 
          id="btnDaySummary" 
          onclick="toggleSummaryPeriod('day')" 
          class="px-3 py-1 rounded-md text-xs font-medium bg-blue-600 text-white transition"
        >
          Today
        </button>
        <button 
          id="btnMonthSummary" 
          onclick="toggleSummaryPeriod('month')" 
          class="px-3 py-1 rounded-md text-xs font-medium text-gray-600 hover:text-gray-900 transition"
        >
          This Month
        </button>
      </div>
    </div>
    
    <!-- All 6 cards in one elegant horizontal row -->
    <div class="flex items-stretch justify-between gap-1">
      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
            <span class="text-base">✓</span>
          </div>
        </div>
        <p class="text-xl font-bold text-green-600" id="cardPresent">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">Present</p>
      </div>

      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
            <span class="text-base">⏰</span>
          </div>
        </div>
        <p class="text-xl font-bold text-blue-600" id="cardOnTime">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">On Time</p>
      </div>

      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
            <span class="text-base">⚠</span>
          </div>
        </div>
        <p class="text-xl font-bold text-red-600" id="cardLate">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">Late</p>
      </div>

      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center">
            <span class="text-base">⏱</span>
          </div>
        </div>
        <p class="text-xl font-bold text-purple-600" id="cardOvertime">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">Overtime</p>
      </div>

      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center">
            <span class="text-base">🏖️</span>
          </div>
        </div>
        <p class="text-xl font-bold text-orange-600" id="cardOnLeave">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">On Leave</p>
      </div>

      <div class="flex-1 text-center p-2 rounded-lg hover:bg-gray-50 transition min-w-0">
        <div class="flex justify-center mb-1">
          <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
            <span class="text-base">❌</span>
          </div>
        </div>
        <p class="text-xl font-bold text-gray-700" id="cardAbsent">0</p>
        <p class="text-xs text-gray-500 whitespace-nowrap">Absent</p>
      </div>
    </div>
  </div>

  <!-- Main Attendance Table -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
    <!-- Date Navigation & Filters -->
    <div class="p-4 border-b border-gray-200 bg-gray-50">
      <div class="flex flex-wrap items-center gap-3 justify-between">
        <!-- Date Navigation -->
        <div class="flex items-center gap-2">
          <button onclick="navigateDate(-1)" class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition">
            ← Prev
          </button>
          <input 
            type="date" 
            id="tableDate" 
            value="<?php echo date('Y-m-d'); ?>"
            onchange="loadAttendanceForDate()"
            class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
          <button onclick="navigateDate(1)" class="px-3 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition">
            Next →
          </button>
          <button onclick="goToToday()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium">
            Today
          </button>
        </div>

        <!-- User Filter (Admin Only) -->
        <div id="userFilterSection" class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-700">Show:</label>
          <select 
            id="userFilter" 
            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
            onchange="loadAttendanceForDate()"
          >
            <option value="all">All Users</option>
            <option value="riders">Riders Only</option>
            <option value="staff">Staff Only</option>
          </select>
        </div>

        <!-- Status Filter -->
        <div class="flex items-center gap-2">
          <label class="text-sm font-medium text-gray-700">Filter:</label>
          <select 
            id="statusFilter" 
            class="px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-sm"
            onchange="filterTableByStatus()"
          >
            <option value="all">All Status</option>
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="overtime">Overtime</option>
            <option value="absent">Absent</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expected Shift</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Login</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logout</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hours</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Late By</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Overtime</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Leave</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
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

<!-- Quick Add Time Modal - Modern Design -->
<div id="quickTimeModal" class="hidden fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center p-4 backdrop-blur-sm" style="z-index: 9999;">
  <div id="quickTimeCard" class="bg-gradient-to-br from-white to-gray-50 rounded-2xl shadow-2xl max-w-lg w-full p-8 transform transition-all" onclick="event.stopPropagation()">
    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
          <span class="text-2xl">⏰</span>
        </div>
        <div>
          <h3 class="text-xl font-bold text-gray-900" id="quickTimeModalTitle">Mark Time</h3>
          <p class="text-sm text-gray-500">Quick attendance marking</p>
        </div>
      </div>
      <button onclick="closeQuickTime()" class="w-10 h-10 rounded-full hover:bg-gray-200 transition flex items-center justify-center text-gray-400 hover:text-gray-600">
        <span class="text-2xl leading-none">&times;</span>
      </button>
    </div>
    
    <!-- Employee Info Card -->
    <div class="bg-white rounded-xl p-4 mb-6 border border-gray-100 shadow-sm">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-cyan-500 rounded-full flex items-center justify-center text-white font-bold">
          <span id="quickTimeUserInitial">?</span>
        </div>
        <div class="flex-1">
          <p class="font-semibold text-gray-900" id="quickTimeUser"></p>
          <p class="text-xs text-gray-500" id="quickTimeDate"></p>
        </div>
      </div>
    </div>

    <!-- Time Inputs - Conditional Display -->
    <div class="space-y-4 mb-6">
      <div id="loginTimeSection" class="hidden">
        <label class="block text-sm font-semibold text-gray-700 mb-2">🌅 Login Time</label>
        <div class="relative">
          <input 
            id="quickLoginTimeInput" 
            type="time" 
            class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
          >
        </div>
      </div>

      <div id="logoutTimeSection" class="hidden">
        <label class="block text-sm font-semibold text-gray-700 mb-2">🌆 Logout Time</label>
        <div class="relative">
          <input 
            id="quickLogoutTimeInput" 
            type="time" 
            class="w-full border-2 border-gray-200 rounded-xl px-4 py-3 text-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition"
          >
        </div>
      </div>
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3">
      <button 
        onclick="closeQuickTime()" 
        class="flex-1 px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition font-semibold"
      >
        Cancel
      </button>
      <button 
        onclick="saveQuickTime()" 
        class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition font-semibold shadow-lg"
      >
        💾 Save Time
      </button>
    </div>
  </div>
</div>

<!-- Quick Edit Modal -->
<div id="quickEditModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
  <div id="quickEditCard" class="bg-white rounded-lg shadow-xl max-w-md w-full p-6" onclick="event.stopPropagation()">
    <div class="flex justify-between items-center mb-4">
      <h3 class="text-lg font-semibold text-gray-900">Edit Attendance</h3>
      <button onclick="closeQuickEdit()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    
    <div class="mb-4">
      <p class="text-sm text-gray-600 mb-1">Employee:</p>
      <p class="text-base font-semibold text-gray-800" id="quickEditUser"></p>
      <p class="text-xs text-gray-500" id="quickEditDate"></p>
    </div>

    <div class="grid grid-cols-2 gap-4 mb-6">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
        <input 
          id="quickLoginTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
        >
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Logout Time</label>
        <input 
          id="quickLogoutTime" 
          type="time" 
          class="w-full border-2 border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-500"
        >
      </div>
    </div>

    <div class="flex gap-2">
      <button 
        onclick="closeQuickEdit()" 
        class="flex-1 px-4 py-3 bg-gray-100 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-200 transition font-medium"
      >
        Cancel
      </button>
      <button 
        onclick="saveQuickEdit()" 
        class="flex-1 px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
      >
        💾 Save
      </button>
    </div>
  </div>
</div>

<!-- Shift Manager Modal -->
<div id="shiftModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
  <div id="shiftModalCard" class="bg-white rounded-xl shadow-2xl w-full overflow-hidden" onclick="event.stopPropagation();" style="width: min(92vw, 900px); max-height: 85vh;">
    <!-- Sticky Header -->
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
      <h3 class="text-xl font-semibold text-gray-900">Manage Employee Shifts</h3>
      <button type="button" onclick="closeShiftManager()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>
    <!-- Scrollable Body -->
    <div class="px-6 py-4" style="max-height: calc(85vh - 64px); overflow-y: auto;">
      <div id="shiftList" class="space-y-1 divide-y divide-gray-100">
        <!-- Populated by JS -->
      </div>
    </div>
  </div>
</div>

<!-- Employee Details Modal (Last 30 Days with Order Stats) -->
<div id="employeeDetailsModal" style="display: none;" onclick="if(event.target === this) closeEmployeeDetails();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div style="width: 40px; height: 40px; border-radius: 50%; background: #dbeafe; display: flex; align-items: center; justify-content: center; color: #2563eb; font-size: 18px; font-weight: bold;">
            👤
          </div>
          <div>
            <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="detailsEmployeeName">Employee Name</h3>
            <p style="font-size: 12px; color: #6b7280; margin: 2px 0 0 0;" id="detailsDateRange">Date Range</p>
          </div>
        </div>
        <button type="button" onclick="closeEmployeeDetails()" style="background: none; border: none; color: #9ca3af; font-size: 28px; line-height: 1; cursor: pointer; padding: 4px 8px;">&times;</button>
      </div>
    </div>

    <!-- Stats Bar -->
    <div style="padding: 12px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
      <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px;">
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Present</p>
          <p style="font-size: 16px; font-weight: bold; color: #111827; margin: 0;" id="detailsStatPresent">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Late</p>
          <p style="font-size: 16px; font-weight: bold; color: #dc2626; margin: 0;" id="detailsStatLate">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Overtime</p>
          <p style="font-size: 16px; font-weight: bold; color: #16a34a; margin: 0;" id="detailsStatOT">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">On Leave</p>
          <p style="font-size: 16px; font-weight: bold; color: #ea580c; margin: 0;" id="detailsStatOnLeave">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Absent</p>
          <p style="font-size: 16px; font-weight: bold; color: #6b7280; margin: 0;" id="detailsStatAbsent">0</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Total Hours</p>
          <p style="font-size: 16px; font-weight: bold; color: #111827; margin: 0;" id="detailsStatHours">0h</p>
        </div>
        <div style="text-align: center;">
          <p style="font-size: 10px; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; margin: 0 0 4px 0;">Orders</p>
          <p style="font-size: 16px; font-weight: bold; color: #2563eb; margin: 0;" id="detailsStatOrders">0</p>
        </div>
      </div>
    </div>

    <!-- Scrollable Table Container -->
    <div style="flex: 1 1 auto; overflow-y: auto; overflow-x: auto; min-height: 0; background: white;">
      <table style="width: 100%; border-collapse: collapse; min-width: 900px;">
        <thead style="position: sticky; top: 0; background: #f3f4f6; z-index: 10;">
          <tr>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Date</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Status</th>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Login</th>
            <th style="padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Logout</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Hours</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Late By</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Overtime</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Orders</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">1st Delivery</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #2563eb; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Last Delivery</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Meter Pics</th>
          </tr>
        </thead>
        <tbody id="employeeDetailsBody" style="background: white;">
          <tr>
            <td colspan="11" style="padding: 20px; text-align: center; color: #6b7280;">Loading...</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: white; flex-shrink: 0; display: flex; justify-content: flex-end;">
      <button 
        type="button"
        onclick="closeEmployeeDetails()" 
        style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);"
      >
        Close
      </button>
    </div>

  </div>
</div>

<!-- Meter Picture Viewer Modal -->
<div id="meterPictureModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10001; background-color: rgba(0, 0, 0, 0.9); align-items: center; justify-content: center; padding: 1rem;" onclick="if(event.target === this) closeMeterPictureModal();">
  <div style="position: relative; max-width: 90%; max-height: 90%; display: flex; flex-direction: column; align-items: center;" onclick="event.stopPropagation();">
    <button onclick="closeMeterPictureModal()" style="position: absolute; top: -40px; right: 0; background: rgba(255, 255, 255, 0.2); color: white; border: none; padding: 8px 16px; border-radius: 20px; cursor: pointer; font-size: 16px; font-weight: 600;">✕ Close</button>
    <img id="meterPictureImage" src="" style="max-width: 100%; max-height: 90vh; border-radius: 8px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);" />
  </div>
</div>

<!-- Customize User List Modal -->
<div id="customizeUserListModal" style="display: none;" onclick="if(event.target === this) closeCustomizeUserList();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Customize Attendance User List</h2>
          <p style="font-size: 14px; color: #6b7280; margin-top: 4px;">Hide system users or test accounts from attendance tracking. By default, all users are visible.</p>
        </div>
        <button onclick="closeCustomizeUserList()" style="color: #9ca3af; font-size: 28px; line-height: 1; border: none; background: none; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
          ×
        </button>
      </div>
    </div>
    
    <!-- Users List (Scrollable) -->
    <div style="overflow-y: auto; flex: 1; padding: 24px;">
      <table style="width: 100%;">
        <thead style="background: #f9fafb; position: sticky; top: 0;">
          <tr style="text-align: left; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">
            <th style="padding: 12px; width: 50px;">
              <input type="checkbox" id="selectAllUsersVis" onchange="toggleSelectAllUsersVis(this)" checked>
            </th>
            <th style="padding: 12px;">Employee</th>
            <th style="padding: 12px;">Role</th>
            <th style="padding: 12px; width: 120px;">Show in Attendance</th>
          </tr>
        </thead>
        <tbody id="usersVisibilityTableBody">
          <tr><td colspan="4" style="padding: 20px; text-align: center; color: #6b7280;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
    
    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; background: #f9fafb; flex-shrink: 0; display: flex; justify-content: space-between; align-items: center;">
      <div style="font-size: 13px; color: #6b7280;">
        <span id="visibleUsersCount">0</span> users will appear in attendance tracking
      </div>
      <div style="display: flex; gap: 12px;">
        <button onclick="closeCustomizeUserList()" style="padding: 10px 20px; background: white; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; cursor: pointer; font-weight: 500;">
          Cancel
        </button>
        <button onclick="saveUserVisibilityChanges()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
          Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<style>
  #customizeUserListModal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow-y: auto;
  }
</style>

<script>
let allUsers = [];
let showOnlyActive = true; // default
let selectedUserData = null;
let currentEditUserId = null;
let currentEditDate = null;
let employeeDetailsData = null; // Store employee details modal data
let allAttendanceData = [];
let currentSummaryPeriod = 'day'; // 'day' or 'month'
let currentTimeModalMode = null; // 'login' or 'logout'
let allUsersVisibility = [];
let visibilityChanges = {};

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
  await loadAllUsers();
  loadAttendanceForDate();
  
  // Event delegation for action buttons
  const tbody = document.getElementById('attBody');
  if (tbody) {
    tbody.addEventListener('click', function(e) {
      const target = e.target.closest('button');
      if (!target) return;
      
      // Handle quick add button (+ button)
      if (target.classList.contains('quick-add-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        quickAddLogout(userId, userName);
      }
      
      // Handle manage shift button (📅 button)
      if (target.classList.contains('manage-shift-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        openShiftManagerForUser(userId, userName);
      }
      
      // Handle quick edit button (✏️ button)
      if (target.classList.contains('quick-edit-btn')) {
        e.preventDefault();
        e.stopPropagation();
        const userId = parseInt(target.dataset.userId);
        const userName = target.dataset.userName;
        const loginTime = target.dataset.loginTime;
        const logoutTime = target.dataset.logoutTime;
        const attendanceDate = target.dataset.attendanceDate;
        openQuickEdit(userId, userName, loginTime, logoutTime, attendanceDate);
      }
    });
  }
});

// Toggle add form modal
function toggleAddForm() {
  const modal = document.getElementById('addAttendanceForm');
  if (modal.classList.contains('hidden')) {
    modal.classList.remove('hidden');
    modal.onclick = function(e) {
      if (e.target === modal) {
        toggleAddForm();
      }
    };
  } else {
    modal.classList.add('hidden');
    clearForm();
  }
}

// Date navigation
function navigateDate(days) {
  const dateInput = document.getElementById('tableDate');
  const currentDate = new Date(dateInput.value);
  currentDate.setDate(currentDate.getDate() + days);
  dateInput.value = currentDate.toISOString().split('T')[0];
  loadAttendanceForDate();
}

function goToToday() {
  document.getElementById('tableDate').value = new Date().toISOString().split('T')[0];
  loadAttendanceForDate();
}

// Load all users for dropdown
async function loadAllUsers() {
  try {
    const endpoint = showOnlyActive ? '/users/all' : '/users/all?include_inactive=1';
    const res = await fetch(endpoint);
    const json = await res.json();
    allUsers = json.data || [];
    populateUserDropdowns();
  } catch(e) {
    console.error('Failed to load users', e);
  }
}

function onActiveFilterChange() {
  const val = document.getElementById('activeFilter').value;
  showOnlyActive = (val === 'active');
  // Reload attendance table with the new filter
  // Also need to reload allUsers for the dropdowns
  loadAllUsers().then(() => {
    loadAttendanceForDate();
  });
}

function populateUserDropdowns() {
  const select = document.getElementById('userSelect');
  select.innerHTML = '<option value="">-- Select Employee --</option>' + 
    allUsers.map(u => `<option value="${u.id}" data-shift-start="${u.shift_start}" data-shift-end="${u.shift_end}" data-fullname="${u.fullname}" data-role="${u.role_name || 'Staff'}">${u.fullname || 'User #' + u.id} (${u.role_name || 'Staff'})</option>`).join('');
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
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (json.success) {
      alert('✅ Attendance recorded successfully!');
      clearForm();
      toggleAddForm();
      loadAttendanceForDate();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to save attendance'));
    }
  } catch(e) {
    console.error('Error saving attendance', e);
    alert('❌ Error saving attendance');
  }
}

// Load attendance for the current date - Show ALL users
async function loadAttendanceForDate() {
  const date = document.getElementById('tableDate').value;
  const userFilter = document.getElementById('userFilter').value;
  const activeFilter = document.getElementById('activeFilter')?.value || 'active';
  
  try {
    // Fetch attendance data for the date (includes shift data from ShiftResolutionService)
    const attRes = await fetch(`/attendance/data?date=${date}&active_filter=${activeFilter}`);
    const attJson = await attRes.json();
    const attendanceData = attJson.success ? attJson.data : [];
    
    // Just use the attendance API data directly - it already has correct shifts!
    // The backend now returns ALL users (not just those with attendance/leave)
    allAttendanceData = attendanceData;
    
    // Apply user filter (using role_name from attendance API data)
    let filteredData = allAttendanceData;
    if (userFilter === 'riders') {
      filteredData = allAttendanceData.filter(u => {
        return u.role_name && u.role_name.toLowerCase().includes('rider');
      });
    } else if (userFilter === 'staff') {
      filteredData = allAttendanceData.filter(u => {
        return !u.role_name || !u.role_name.toLowerCase().includes('rider');
      });
    }
    
    renderAttendanceTable(filteredData);
    updateSummaryCards(filteredData);
  } catch(e) {
    console.error('Error loading attendance', e);
    document.getElementById('attBody').innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-red-500 text-sm">Error loading data</td></tr>';
  }
}

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
        
        <!-- Location Badge Column -->
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

function getRowStatus(r, lateBy, overtime) {
  if (!r.login_time) return 'absent';
  if (overtime.hasOvertime) return 'overtime';
  if (lateBy.isLate) return 'late';
  return 'present';
}

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

function filterTableByStatus() {
  const filter = document.getElementById('statusFilter').value;
  const rows = document.querySelectorAll('#attBody tr[data-status]');
  
  rows.forEach(row => {
    if (filter === 'all') {
      row.style.display = '';
    } else {
      const status = row.getAttribute('data-status');
      row.style.display = status === filter ? '' : 'none';
    }
  });
}

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

  console.log(`Showing ${visibleCount} records with filter: ${filter}`);
}

function updateSummaryCards(data) {
  let present = 0, onTime = 0, late = 0, overtime = 0, onLeave = 0, absent = 0;
  
  data.forEach(r => {
    // Check if on leave (approved or pending)
    if (r.leave_request_id && (r.leave_status === 'approved' || r.leave_status === 'pending')) {
      onLeave++;
    }
    // If has attendance
    else if (r.login_time) {
      present++;
      const shift = r.shift_start || '09:00';
      if (r.login_time <= shift) {
        onTime++;
      } else {
        late++;
      }
      
      if (r.logout_time && r.logout_time > (r.shift_end || '17:00')) {
        overtime++;
      }
    }
    // Absent (no attendance and no leave)
    else {
      absent++;
    }
  });
  
  document.getElementById('cardPresent').textContent = present;
  document.getElementById('cardOnTime').textContent = onTime;
  document.getElementById('cardLate').textContent = late;
  document.getElementById('cardOvertime').textContent = overtime;
  document.getElementById('cardOnLeave').textContent = onLeave;
  document.getElementById('cardAbsent').textContent = absent;
}

// Smart quick add - handles both login and logout
function quickAddLogout(userId, userName) {
  try {
  const user = allAttendanceData.find(u => u.user_id == userId);
  currentEditUserId = userId;
  currentEditDate = document.getElementById('tableDate').value;
  
  // Set current time as default
  const now = new Date();
  const hours = String(now.getHours()).padStart(2, '0');
  const minutes = String(now.getMinutes()).padStart(2, '0');
  const currentTime = `${hours}:${minutes}`;
  
  // Determine what to show based on current attendance
  const hasLogin = user && user.login_time;
  const hasLogout = user && user.logout_time;
  
  // Set user info
    const quickTimeUser = document.getElementById('quickTimeUser');
    const quickTimeDate = document.getElementById('quickTimeDate');
    const quickTimeUserInitial = document.getElementById('quickTimeUserInitial');
    
    if (!quickTimeUser || !quickTimeDate || !quickTimeUserInitial) {
      console.error('Missing modal user info elements!');
      alert('Error: Modal elements not found. Please refresh the page.');
      return;
    }
    
    quickTimeUser.textContent = userName;
    quickTimeDate.textContent = currentEditDate;
    quickTimeUserInitial.textContent = userName.charAt(0).toUpperCase();
  
  // Show appropriate sections
  const loginSection = document.getElementById('loginTimeSection');
  const logoutSection = document.getElementById('logoutTimeSection');
  const modalTitle = document.getElementById('quickTimeModalTitle');
    
    if (!loginSection || !logoutSection || !modalTitle) {
      console.error('Missing section elements!');
      alert('Error: Modal section elements not found. Please refresh the page.');
      return;
    }
  
  if (!hasLogin) {
    // No login yet - ask for login time
    currentTimeModalMode = 'login';
    loginSection.classList.remove('hidden');
    logoutSection.classList.add('hidden');
    document.getElementById('quickLoginTimeInput').value = currentTime;
    modalTitle.textContent = 'Mark Login Time';
  } else if (!hasLogout) {
    // Has login, no logout - ask for logout time
    currentTimeModalMode = 'logout';
    loginSection.classList.add('hidden');
    logoutSection.classList.remove('hidden');
    document.getElementById('quickLogoutTimeInput').value = currentTime;
    modalTitle.textContent = 'Mark Logout Time';
  } else {
    // Both exist - should not happen (use edit button)
    alert('Attendance already complete. Use edit button to modify.');
    return;
  }
  
  const modal = document.getElementById('quickTimeModal');
    
    if (!modal) {
      console.error('quickTimeModal not found!');
      alert('Error: Modal not found. Please refresh the page.');
      return;
    }
    
    // Open modal with elegant styling - ensure it's visible
  modal.classList.remove('hidden');
    // Apply minimal overlay styling only
    Object.assign(modal.style, {
      display: 'flex',
      position: 'fixed',
      top: '0',
      left: '0',
      right: '0',
      bottom: '0',
      zIndex: '9999',
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: 'rgba(0,0,0,0.5)',
      padding: '1rem'
    });
    // Ensure card stays compact and readable
    const card = document.getElementById('quickTimeCard');
    if (card) {
      Object.assign(card.style, {
        maxWidth: '520px',
        width: '100%',
        background: 'white',
        color: '#111827'
      });
    }
    
  modal.onclick = function(e) {
    if (e.target === modal) closeQuickTime();
  };
    
    console.log('Modal opened for user:', userId, userName);
    console.log('Variables set:', {currentEditUserId, currentEditDate, currentTimeModalMode});
  } catch (error) {
    console.error('Error in quickAddLogout:', error);
    alert('Error: ' + error.message);
  }
}

function closeQuickTime() {
  const modal = document.getElementById('quickTimeModal');
  if (modal) {
    modal.classList.add('hidden');
    // Reset inline overlay styles to avoid accumulation
    modal.removeAttribute('style');
  }
  currentEditUserId = null;
  currentEditDate = null;
  currentTimeModalMode = null;
}

async function saveQuickTime() {
  // Validate required data
  if (!currentEditUserId) {
    console.error('Missing currentEditUserId');
    alert('Error: User ID is missing. Please try again.');
    return;
  }
  
  if (!currentEditDate) {
    console.error('Missing currentEditDate');
    alert('Error: Date is missing. Please try again.');
    return;
  }
  
  let payload = {
    user_id: currentEditUserId,
    attendance_date: currentEditDate
  };
  
  if (currentTimeModalMode === 'login') {
    const loginTime = document.getElementById('quickLoginTimeInput').value;
    if (!loginTime) {
      alert('Please enter a login time');
      return;
    }
    payload.login_time = loginTime;
  } else if (currentTimeModalMode === 'logout') {
    const logoutTime = document.getElementById('quickLogoutTimeInput').value;
    if (!logoutTime) {
      alert('Please enter a logout time');
      return;
    }
    payload.logout_time = logoutTime;
  }

  console.log('Saving attendance with payload:', payload);

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    console.log('Server response:', json);
    
    if (json.success) {
      console.log(`${currentTimeModalMode === 'login' ? 'Login' : 'Logout'} recorded by user ID: ${window.currentUser.id} (${window.currentUser.name})`);
      closeQuickTime();
      loadAttendanceForDate();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to save time'));
    }
  } catch(e) {
    console.error('Error saving time', e);
    alert('❌ Error saving time');
  }
}

// Toggle summary period
async function toggleSummaryPeriod(period) {
  currentSummaryPeriod = period;
  
  // Update button styles
  const dayBtn = document.getElementById('btnDaySummary');
  const monthBtn = document.getElementById('btnMonthSummary');
  
  if (period === 'day') {
    dayBtn.classList.add('bg-blue-600', 'text-white');
    dayBtn.classList.remove('text-gray-600');
    monthBtn.classList.remove('bg-blue-600', 'text-white');
    monthBtn.classList.add('text-gray-600');
    
    // Update with current date data
    updateSummaryCards(allAttendanceData);
  } else {
    monthBtn.classList.add('bg-blue-600', 'text-white');
    monthBtn.classList.remove('text-gray-600');
    dayBtn.classList.remove('bg-blue-600', 'text-white');
    dayBtn.classList.add('text-gray-600');
    
    // Fetch month data
    await loadMonthSummary();
  }
}

async function loadMonthSummary() {
  const date = new Date(document.getElementById('tableDate').value);
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const startDate = `${year}-${month}-01`;
  const endDate = new Date(year, date.getMonth() + 1, 0).toISOString().split('T')[0];
  
  try {
    const res = await fetch(`/attendance/summary?start=${startDate}&end=${endDate}`);
    const json = await res.json();
    
    if (json.success) {
      document.getElementById('cardPresent').textContent = json.data.on_time + json.data.late;
      document.getElementById('cardOnTime').textContent = json.data.on_time;
      document.getElementById('cardLate').textContent = json.data.late;
      document.getElementById('cardOvertime').textContent = json.data.absent; // Reuse for now
    }
  } catch(e) {
    console.error('Error loading month summary', e);
  }
}

// Quick edit modal
function openQuickEdit(userId, userName, loginTime, logoutTime, attendanceDate) {
  currentEditUserId = userId;
  currentEditDate = attendanceDate || document.getElementById('tableDate').value;
  
  document.getElementById('quickEditUser').textContent = userName;
  document.getElementById('quickEditDate').textContent = currentEditDate;
  document.getElementById('quickLoginTime').value = loginTime;
  document.getElementById('quickLogoutTime').value = logoutTime;
  
  const modal = document.getElementById('quickEditModal');
  modal.classList.remove('hidden');
  // Minimal overlay styling
  Object.assign(modal.style, {
    display: 'flex',
    position: 'fixed',
    top: '0',
    left: '0',
    right: '0',
    bottom: '0',
    zIndex: '9999',
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(0,0,0,0.5)',
    padding: '1rem'
  });
  const editCard = document.getElementById('quickEditCard');
  if (editCard) {
    Object.assign(editCard.style, {
      maxWidth: '480px',
      width: '100%',
      background: 'white',
      color: '#111827'
    });
  }
  
  modal.onclick = function(e) {
    if (e.target === modal) closeQuickEdit();
  };
}

function closeQuickEdit() {
  const modal = document.getElementById('quickEditModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.removeAttribute('style');
  }
  currentEditUserId = null;
  currentEditDate = null;
}

async function saveQuickEdit() {
  const loginTime = document.getElementById('quickLoginTime').value;
  const logoutTime = document.getElementById('quickLogoutTime').value;

  if (!loginTime && !logoutTime) {
    alert('Please enter at least one time');
    return;
  }

  const payload = {
    user_id: currentEditUserId,
    attendance_date: currentEditDate,
    login_time: loginTime || null,
    logout_time: logoutTime || null
  };

  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    if (json.success) {
      alert('✅ Attendance updated successfully!');
      closeQuickEdit();
      loadAttendanceForDate();
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to update attendance'));
    }
  } catch(e) {
    console.error('Error updating attendance', e);
    alert('❌ Error updating attendance');
  }
}

// Utility functions
function calculateLateBy(loginTime, shiftStart) {
  if (!loginTime) {
    return { isLate: false, duration: '-' };
  }
  
  const shift = shiftStart || '09:00';
  if (loginTime <= shift) {
    return { isLate: false, duration: '-' };
  }
  
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
  const loginM = lh * 60 + lm;
  const logoutM = oh * 60 + om;
  const diff = logoutM - loginM;
  if (diff < 0) return '-';
  const h = Math.floor(diff / 60);
  const m = diff % 60;
  return `${h}h ${m}m`;
}

// Shift manager
let shiftModalOpen = false;

function showShiftManager() {
  console.log('showShiftManager called, current state:', shiftModalOpen);
  
  if (shiftModalOpen) {
    console.log('Modal already open, ignoring duplicate call');
    return;
  }
  
  const modal = document.getElementById('shiftModal');
  console.log('Shift modal element:', modal);
  
  if (!modal) {
    alert('❌ Error: Shift modal not found!');
    console.error('shiftModal element does not exist');
    return;
  }
  
  shiftModalOpen = true;
  loadShiftData();

  // Portalize modal to body to avoid clipping/stacking issues
  try {
    if (modal.parentElement !== document.body) {
      document.body.appendChild(modal);
    }
  } catch (e) {
    console.warn('Could not portalize shiftModal:', e);
  }

  // Enforce centered modal overlay (not full screen)
  modal.classList.remove('hidden');
  Object.assign(modal.style, {
    display: 'flex',
    position: 'fixed',
    top: '0',
    left: '0',
    right: '0',
    bottom: '0',
    zIndex: '99999',
    backgroundColor: 'rgba(0,0,0,0.5)',
    overscrollBehavior: 'contain'
  });
  // Lock background scroll while modal open
  window.__prevBodyOverflow = document.body.style.overflow;
  document.body.style.overflow = 'hidden';
  console.log('Shift modal opened successfully');
  
  // Close on background click
  modal.onclick = function(e) {
    if (e.target === modal) {
      closeShiftManager();
    }
  };
  // Ensure card keeps reasonable width even if utility classes conflict
  const card = document.getElementById('shiftModalCard');
  if (card) {
    card.style.width = 'min(92vw, 900px)';
    card.style.maxHeight = '85vh';
  }
}

// Open shift manager for specific user (from attendance row action button)
function openShiftManagerForUser(userId, userName) {
  console.log('Opening shift manager for user:', userId, userName);
  // Redirect to shifts page with user filter in URL
  window.location.href = `/shifts?user=${userId}&name=${encodeURIComponent(userName)}`;
}

function closeShiftManager() {
  const modal = document.getElementById('shiftModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.style.display = 'none';
    shiftModalOpen = false;
    console.log('Shift modal closed');
    // Restore background scroll
    document.body.style.overflow = '';
  }
}

async function loadShiftData() {
  try {
    const res = await fetch('/users/all');
    const json = await res.json();
    const users = json.data || [];
    
    const shiftList = document.getElementById('shiftList');
    shiftList.innerHTML = `
      <div class="grid gap-2 text-[11px] text-gray-500 uppercase tracking-wide pb-1 border-b border-gray-200" style="grid-template-columns: 1fr 110px 110px 70px;">
        <div>Employee</div>
        <div class="text-center">Start</div>
        <div class="text-center">End</div>
        <div class="text-right">Action</div>
      </div>
    ` + users.map(u => `
      <div class="py-1.5 border-b border-gray-100">
        <div class="grid gap-2 items-center" style="grid-template-columns: 1fr 110px 110px 70px;">
          <div class="flex items-center min-w-0">
            <div class="truncate">
              <span class="font-medium text-gray-800">${u.fullname || 'User #' + u.id}</span>
              <span class="ml-1 text-[10px] text-gray-500">${u.role_name || ''}</span>
            </div>
          </div>
          <div class="flex justify-center">
            <input type="time" value="${u.shift_start || '09:00'}" class="px-1 py-1 border border-gray-300 rounded text-xs w-full" id="shift_start_${u.id}">
          </div>
          <div class="flex justify-center">
            <input type="time" value="${u.shift_end || '17:00'}" class="px-1 py-1 border border-gray-300 rounded text-xs w-full" id="shift_end_${u.id}">
          </div>
          <div class="flex justify-end">
            <button onclick="saveShift(${u.id})" class="px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs whitespace-nowrap">Save</button>
          </div>
        </div>
      </div>
    `).join('');
  } catch(e) {
    console.error('Failed to load shift data', e);
  }
}

async function saveShift(userId) {
  const start = document.getElementById(`shift_start_${userId}`).value;
  const end = document.getElementById(`shift_end_${userId}`).value;

  try {
    const res = await fetch('/riders/shift', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({ user_id: userId, shift_start: start, shift_end: end })
    });

    const json = await res.json();
    if (json.success) {
      alert('✅ Shift updated!');
      loadAllUsers();
    } else {
      alert('❌ Error updating shift');
    }
  } catch(e) {
    console.error('Error saving shift', e);
    alert('❌ Error saving shift');
  }
}

// Employee Details Modal Functions
async function showEmployeeDetails(userId, fullname, fromDate) {
  console.log('showEmployeeDetails called:', { userId, fullname, fromDate });
  
  // If fromDate is null/undefined, use current selected date from date picker or today
  if (!fromDate || fromDate === 'null' || fromDate === 'undefined') {
    const dateInput = document.getElementById('attendanceDate');
    fromDate = dateInput ? dateInput.value : new Date().toISOString().split('T')[0];
  }
  
  console.log('Using fromDate:', fromDate);
  
  const modal = document.getElementById('employeeDetailsModal');
  const body = document.getElementById('employeeDetailsBody');
  
  if (!modal || !body) {
    console.error('Modal elements not found');
    return;
  }

  // Show modal - Force display with !important
  modal.classList.remove('hidden');
  modal.style.cssText = 'display: flex !important; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000; background-color: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center; padding: 1rem;';
  
  // Debug: Check modal dimensions and visibility
  const rect = modal.getBoundingClientRect();
  const computedStyle = window.getComputedStyle(modal);
  console.log('Modal should be visible now', { 
    display: modal.style.display, 
    computedDisplay: computedStyle.display,
    visibility: computedStyle.visibility,
    opacity: computedStyle.opacity,
    zIndex: computedStyle.zIndex,
    dimensions: { width: rect.width, height: rect.height, top: rect.top, left: rect.left },
    classList: modal.classList.toString() 
  });
  
  // Set loading state
  body.innerHTML = '<tr><td colspan="9" style="padding: 20px; text-align: center; color: #6b7280;">Loading employee details...</td></tr>';
  
  // Set employee name
  document.getElementById('detailsEmployeeName').textContent = fullname || 'Employee';
  
  try {
    // Fetch employee details with order stats
    const res = await fetch(`/attendance/employee-details?user_id=${userId}&from_date=${fromDate}`, {
      headers: { 'Accept': 'application/json' }
    });
    
    const json = await res.json();
    
    if (!json.success) {
      body.innerHTML = `<tr><td colspan="9" style="padding: 20px; text-align: center; color: #dc2626;">Error: ${json.message || 'Failed to load data'}</td></tr>`;
      return;
    }
    
    employeeDetailsData = json;
    const emp = json.employee;
    const records = json.daily_records;
    
    // Update header with error handling
    try {
      const startDate = emp.date_range && emp.date_range.start ? new Date(emp.date_range.start + 'T00:00:00') : new Date();
      const endDate = emp.date_range && emp.date_range.end ? new Date(emp.date_range.end + 'T00:00:00') : new Date();
      
      const dateRange = startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) + 
                       ' - ' + 
                       endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    document.getElementById('detailsDateRange').textContent = dateRange + ' (Last 30 Days)';
    } catch(e) {
      console.error('Error formatting date range:', e);
      document.getElementById('detailsDateRange').textContent = 'Last 30 Days';
    }
    
    // Use backend-calculated statistics
    console.log('Employee stats from backend:', {
      working_days: emp.working_days,
      present_days: emp.present_days,
      on_leave_days: emp.on_leave_days,
      absent_days: emp.absent_days,
      calculation: `${emp.working_days} working days - ${emp.present_days} present - ${emp.on_leave_days} on leave = ${emp.absent_days} absent`
    });
    
    // Update stats with backend-calculated values
    document.getElementById('detailsStatPresent').textContent = emp.present_days || 0;
    document.getElementById('detailsStatLate').textContent = emp.late_days || 0;
    document.getElementById('detailsStatOT').textContent = emp.overtime_days || 0;
    document.getElementById('detailsStatOnLeave').textContent = emp.on_leave_days || 0;
    document.getElementById('detailsStatAbsent').textContent = emp.absent_days || 0;
    document.getElementById('detailsStatHours').textContent = (emp.total_hours || 0) + 'h';
    document.getElementById('detailsStatOrders').textContent = emp.total_orders_delivered || 0;
    
    // Render table
    if (!records || records.length === 0) {
      body.innerHTML = '<tr><td colspan="11" style="padding: 20px; text-align: center; color: #6b7280;">No records found for this period</td></tr>';
      return;
    }
    
    body.innerHTML = records.map((day, index) => {
      const date = new Date(day.attendance_date + 'T00:00:00');
      const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
      const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      const rowBg = index % 2 === 0 ? '#f9fafb' : 'white';
      
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
      
      const loginTime = day.login_time || '-';
      const logoutTime = day.logout_time || '-';
      const hours = day.hours_worked ? day.hours_worked.toFixed(1) + 'h' : '-';
      const lateBy = day.late_minutes > 0 ? day.late_minutes + ' min' : '-';
      const overtime = day.overtime_minutes > 0 ? day.overtime_minutes + ' min' : '-';
      
      const ordersDelivered = day.total_orders_delivered || 0;
      const firstDelivery = day.first_delivery_time || '-';
      const lastDelivery = day.last_delivery_time || '-';
      
      // Meter pictures
      const hasPictureStart = day.picture_start && day.picture_start !== '-';
      const hasPictureEnd = day.picture_end && day.picture_end !== '-';
      // Build with /storage/ by default and fallback to proxy in viewer
      const pictureStartPath = hasPictureStart ? day.picture_start : null;
      const pictureEndPath = hasPictureEnd ? day.picture_end : null;
      
      let meterPicsHtml = '-';
      if (hasPictureStart || hasPictureEnd) {
        meterPicsHtml = '<div style="display: flex; gap: 4px; justify-content: center;">';
        if (hasPictureStart) {
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureStartPath}')" style="background: #dbeafe; color: #1e40af; border: none; padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: 600;">📷 Start</button>`;
        }
        if (hasPictureEnd) {
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureEndPath}')" style="background: #fef3c7; color: #92400e; border: none; padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 11px; font-weight: 600;">📷 End</button>`;
        }
        meterPicsHtml += '</div>';
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
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; border-bottom: 1px solid #e5e7eb;">${logoutTime}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: #111827; text-align: center; border-bottom: 1px solid #e5e7eb;">${hours}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${day.late_minutes > 0 ? '#dc2626' : '#9ca3af'}; font-weight: ${day.late_minutes > 0 ? '600' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${lateBy}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${day.overtime_minutes > 0 ? '#16a34a' : '#9ca3af'}; font-weight: ${day.overtime_minutes > 0 ? '600' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${overtime}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${ordersDelivered > 0 ? '#2563eb' : '#9ca3af'}; font-weight: ${ordersDelivered > 0 ? '700' : '400'}; text-align: center; border-bottom: 1px solid #e5e7eb;">
            ${ordersDelivered > 0 ? '📦 ' + ordersDelivered : '-'}
          </td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${firstDelivery !== '-' ? '#2563eb' : '#9ca3af'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${firstDelivery}</td>
          <td style="padding: 12px 16px; font-size: 13px; color: ${lastDelivery !== '-' ? '#2563eb' : '#9ca3af'}; text-align: center; border-bottom: 1px solid #e5e7eb;">${lastDelivery}</td>
          <td style="padding: 12px 16px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">${meterPicsHtml}</td>
        </tr>
      `;
    }).join('');
    
    console.log('Employee details loaded successfully');
    
  } catch(e) {
    console.error('Error loading employee details:', e);
    body.innerHTML = `<tr><td colspan="11" style="padding: 20px; text-align: center; color: #dc2626;">Error loading data: ${e.message}</td></tr>`;
  }
}

function viewMeterPicture(imageUrl) {
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.src = imageUrl;
    modal.style.display = 'flex';
  }
}

// Prefer /storage/ and fall back to /public-storage/
function viewMeterPicturePath(relativePath) {
  const primary = `/storage/${relativePath}`;
  const fallback = `/public-storage/${relativePath}`;
  const modal = document.getElementById('meterPictureModal');
  const img = document.getElementById('meterPictureImage');
  if (modal && img) {
    img.onerror = function() {
      // Switch to fallback once if primary fails
      if (img.src !== window.location.origin + fallback) {
        img.src = fallback;
      }
    };
    img.src = primary;
    modal.style.display = 'flex';
  }
}

function closeMeterPictureModal() {
  const modal = document.getElementById('meterPictureModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

function closeEmployeeDetails() {
  const modal = document.getElementById('employeeDetailsModal');
  if (modal) {
    modal.classList.add('hidden');
    modal.style.cssText = 'display: none !important;';
  }
}

// Make functions globally accessible
window.showEmployeeDetails = showEmployeeDetails;
window.closeEmployeeDetails = closeEmployeeDetails;

// ==================== Customize User List Functions ====================

async function openCustomizeUserList() {
  try {
    console.log('Opening customize user list modal...');
    const modal = document.getElementById('customizeUserListModal');
    modal.style.display = 'flex';
    
    // Load users with visibility status
    const res = await fetch('/attendance/users-visibility');
    const json = await res.json();
    
    if (json.success) {
      allUsersVisibility = json.data;
      visibilityChanges = {}; // Reset changes
      renderUsersVisibility();
      updateVisibleUsersCount();
    } else {
      alert('Error loading users: ' + (json.message || 'Unknown error'));
    }
  } catch(e) {
    console.error('Error opening customize user list:', e);
    alert('Failed to load user list');
  }
}

function closeCustomizeUserList() {
  const modal = document.getElementById('customizeUserListModal');
  modal.style.display = 'none';
  visibilityChanges = {}; // Reset unsaved changes
}

// Settings dropdown toggle
function toggleSettingsMenu() {
  const menu = document.getElementById('settingsMenu');
  menu.classList.toggle('hidden');
}

// Close settings menu when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('settingsDropdown');
  const menu = document.getElementById('settingsMenu');
  if (dropdown && menu && !dropdown.contains(event.target)) {
    menu.classList.add('hidden');
  }
});

function renderUsersVisibility() {
  const tbody = document.getElementById('usersVisibilityTableBody');
  
  if (!allUsersVisibility || allUsersVisibility.length === 0) {
    tbody.innerHTML = '<tr><td colspan="4" style="padding: 20px; text-align: center; color: #6b7280;">No users found</td></tr>';
    return;
  }
  
  tbody.innerHTML = allUsersVisibility.map((user, index) => {
    const rowBg = index % 2 === 0 ? '#ffffff' : '#f9fafb';
    // Check if this user has pending changes
    const currentVisibility = visibilityChanges.hasOwnProperty(user.id) 
      ? visibilityChanges[user.id] 
      : user.is_visible;
    
    return `
      <tr style="background: ${rowBg};" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='${rowBg}'">
        <td style="padding: 12px;">
          <input 
            type="checkbox" 
            class="user-visibility-checkbox" 
            data-user-id="${user.id}"
            ${currentVisibility ? 'checked' : ''}
            onchange="toggleUserVisibility(${user.id}, this.checked)"
          >
        </td>
        <td style="padding: 12px; font-weight: 500; color: #111827;">${user.fullname}</td>
        <td style="padding: 12px; color: #6b7280; font-size: 13px;">${user.role_name || 'N/A'}</td>
        <td style="padding: 12px;">
          <span style="font-size: 12px; padding: 4px 8px; border-radius: 6px; background: ${currentVisibility ? '#dcfce7' : '#fee2e2'}; color: ${currentVisibility ? '#166534' : '#991b1b'};">
            ${currentVisibility ? '✓ Visible' : '✗ Hidden'}
          </span>
        </td>
      </tr>
    `;
  }).join('');
}

function toggleUserVisibility(userId, isVisible) {
  // Track the change
  visibilityChanges[userId] = isVisible;
  
  // Re-render to update the status badge
  renderUsersVisibility();
  updateVisibleUsersCount();
  updateSelectAllCheckbox();
}

function toggleSelectAllUsersVis(checkbox) {
  const isChecked = checkbox.checked;
  
  // Update all users' visibility
  allUsersVisibility.forEach(user => {
    visibilityChanges[user.id] = isChecked;
  });
  
  // Re-render
  renderUsersVisibility();
  updateVisibleUsersCount();
}

function updateSelectAllCheckbox() {
  const checkboxes = document.querySelectorAll('.user-visibility-checkbox');
  const selectAllCheckbox = document.getElementById('selectAllUsersVis');
  
  if (checkboxes.length === 0) return;
  
  const allChecked = Array.from(checkboxes).every(cb => cb.checked);
  const noneChecked = Array.from(checkboxes).every(cb => !cb.checked);
  
  selectAllCheckbox.checked = allChecked;
  selectAllCheckbox.indeterminate = !allChecked && !noneChecked;
}

function updateVisibleUsersCount() {
  const visibleCount = allUsersVisibility.filter(user => {
    const currentVisibility = visibilityChanges.hasOwnProperty(user.id) 
      ? visibilityChanges[user.id] 
      : user.is_visible;
    return currentVisibility;
  }).length;
  
  document.getElementById('visibleUsersCount').textContent = visibleCount;
}

async function saveUserVisibilityChanges() {
  if (Object.keys(visibilityChanges).length === 0) {
    alert('No changes to save');
    closeCustomizeUserList();
    return;
  }
  
  try {
    console.log('Saving visibility changes:', visibilityChanges);
    
    // Save each changed user
    const promises = Object.keys(visibilityChanges).map(userId => {
      return fetch('/attendance/update-visibility', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
          user_id: parseInt(userId),
          is_visible: visibilityChanges[userId] ? 1 : 0,
          notes: visibilityChanges[userId] ? null : 'Hidden from attendance tracking'
        })
      });
    });
    
    await Promise.all(promises);
    
    alert('✓ User visibility preferences saved successfully!');
    closeCustomizeUserList();
    
    // Reload attendance table to reflect changes
    loadAttendanceForDate();
    
  } catch(e) {
    console.error('Error saving visibility changes:', e);
    alert('Failed to save changes. Please try again.');
  }
}

// Make customize user list functions globally accessible
window.openCustomizeUserList = openCustomizeUserList;
window.closeCustomizeUserList = closeCustomizeUserList;
window.toggleUserVisibility = toggleUserVisibility;
window.toggleSelectAllUsersVis = toggleSelectAllUsersVis;
window.saveUserVisibilityChanges = saveUserVisibilityChanges;

</script>
@endsection
