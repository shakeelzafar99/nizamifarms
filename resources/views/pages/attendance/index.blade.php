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
      <button
        type="button"
        onclick="toggleAddForm()"
        id="toggleFormBtn"
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
        style="position: relative; z-index: 20; pointer-events: auto; cursor: pointer;"
      >
        ➕ Mark Attendance
      </button>
      <button
        type="button"
        onclick="openApplyLeave()"
        style="background:#16a34a;color:#ffffff;"
        class="px-4 py-2 rounded-lg transition font-semibold shadow-md text-center hover:opacity-90"
      >
        📝 Apply Leave
      </button>
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
              href="/attendance/reports"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📊 Full Reports (print)
            </a>
            <a
              href="/riders"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              👤 Rider Profiles
            </a>
            <a
              href="/attendance/locations"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📍 Office Locations
            </a>
            <a
              href="/shift-planner"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📅 Shift Planner
            </a>
            <a
              href="/shifts"
              class="block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              🗂️ Shift Types
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
            <button
              type="button"
              onclick="openFuelRateModal(); toggleSettingsMenu();"
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              ⛽ Fuel Rate Groups
            </button>
            <button
              type="button"
              onclick="openAttendanceRules(); toggleSettingsMenu();"
              class="w-full text-left block px-4 py-3 text-gray-800 hover:bg-gray-100 transition font-medium"
            >
              📐 Attendance Rules
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Date breakdown modal (exact dates behind a Month-tab count) -->
  <div id="dateBreakdownModal" style="display:none;position:fixed;inset:0;z-index:10001;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeDateBreakdown()">
    <div style="background:#ffffff;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,0.25);max-width:420px;width:100%;padding:20px 22px;max-height:80vh;display:flex;flex-direction:column;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;">
        <div>
          <h2 id="bdTitle" style="font-size:16px;font-weight:700;color:#111827;margin:0;">Dates</h2>
          <div id="bdSub" style="font-size:12px;color:#6B7280;margin-top:2px;"></div>
        </div>
        <button type="button" onclick="closeDateBreakdown()" style="background:none;border:none;font-size:24px;line-height:1;color:#9ca3af;cursor:pointer;">&times;</button>
      </div>
      <div id="bdBody" style="overflow:auto;margin-top:8px;"></div>
    </div>
  </div>

  <!-- Attendance Rules Modal (year cycle + meter thresholds) -->
  <div id="attRulesModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="if(event.target===this)closeAttendanceRules()">
    <div style="background:#ffffff;border-radius:12px;box-shadow:0 20px 40px rgba(0,0,0,0.25);max-width:520px;width:100%;padding:24px;max-height:90vh;overflow:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <h2 style="font-size:18px;font-weight:700;color:#111827;margin:0;">📐 Attendance Rules</h2>
        <button type="button" onclick="closeAttendanceRules()" style="background:none;border:none;font-size:26px;line-height:1;color:#9ca3af;cursor:pointer;">&times;</button>
      </div>
      <p style="font-size:12.5px;color:#6b7280;margin:0 0 14px;">Open a section to edit it. These settings drive the counters, warnings, leave and overtime — they do not change salary.</p>

      <!-- Year cycle -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCycle')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📅 Yearly cycle</span>
          <span id="secCycleChev" style="color:#9ca3af;font-size:13px;">▾</span>
        </button>
        <div id="secCycle" style="display:block;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">The 12-month window for "leaves this year" and "absent this year". Not fixed to January — set it to your cycle (e.g. June → May).</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Cycle start</label>
              <input type="date" id="ruleCycleStart" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Cycle end</label>
              <input type="date" id="ruleCycleEnd" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
          </div>
          <div id="ruleCycleHint" style="font-size:11.5px;color:#2563eb;margin-top:8px;"></div>
        </div>
      </div>

      <!-- Meter checks -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secMeter')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">🏍 Meter checks</span>
          <span id="secMeterChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secMeter" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">GPS mismatch warn (km)</label>
              <input type="number" min="0" step="1" id="ruleGpsWarn" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Warn when meter distance differs from road/GPS by more than this.</div>
            </div>
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Overnight bike grace (km)</label>
              <input type="number" min="0" step="1" id="ruleOvernight" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Default for company-bike riders. Can be overridden per rider on the Riders page.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Leave policy -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secLeave')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">🏖 Leave policy</span>
          <span id="secLeaveChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secLeave" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">One yearly pool of leaves. A few of them may be applied the same day (emergency) up to a cap, before a cutoff time.</div>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Leaves per year</label>
              <input type="number" min="0" step="1" id="ruleLeaveTotal" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Same-day allowed</label>
              <input type="number" min="0" step="1" id="ruleSamedayCap" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How many can be taken same-day (emergency).</div>
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Same-day cutoff</label>
              <input type="time" id="ruleSamedayCutoff" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Same-day leave must be applied before this time.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Overtime -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:10px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secOvertime')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">⏱ Overtime</span>
          <span id="secOvertimeChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secOvertime" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:150px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Shift length (hours)</label>
              <input type="number" min="0" step="0.5" id="ruleTargetHours" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Checking out after this many hours starts counting overtime.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Check-in rule -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCheckin')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📍 Check-in rule</span>
          <span id="secCheckinChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secCheckin" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">When on, a rider can only mark attendance from the app while their GPS is inside their shift location's radius (set per location on the Office Locations page). Too far — or no GPS — and check-in is blocked. You can still mark someone present yourself from Mark Attendance. Off = check in from anywhere.</div>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
            <input type="checkbox" id="ruleCheckinRequireLocation" style="width:18px;height:18px;">
            <span style="font-size:13px;font-weight:600;color:#374151;">Require riders to be at their shift location to check in</span>
          </label>
        </div>
      </div>

      <!-- Checkout rule -->
      <div style="border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden;">
        <button type="button" onclick="toggleRuleSection('secCheckout')" style="width:100%;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:none;padding:12px 14px;cursor:pointer;">
          <span style="font-weight:700;color:#111827;font-size:14px;">📍 Checkout rule</span>
          <span id="secCheckoutChev" style="color:#9ca3af;font-size:13px;">▸</span>
        </button>
        <div id="secCheckout" style="display:none;padding:14px;border-top:1px solid #f1f5f9;">
          <div style="font-size:12px;color:#6b7280;margin-bottom:12px;">When on, a rider can only check out at the office, or at his most recent delivery within the time window. Off = check out anywhere.</div>
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:12px;">
            <input type="checkbox" id="ruleCheckoutEnabled" style="width:18px;height:18px;">
            <span style="font-size:13px;font-weight:600;color:#374151;">Require checkout at office or last delivery</span>
          </label>
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Delivery window (min)</label>
              <input type="number" min="1" step="1" id="ruleCheckoutWindow" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Minutes after marking delivered that he may check out there.</div>
            </div>
            <div style="flex:1;min-width:130px;">
              <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Delivery radius (m)</label>
              <input type="number" min="10" step="10" id="ruleCheckoutRadius" style="width:100%;border:2px solid #d1d5db;border-radius:8px;padding:9px 10px;font-size:14px;">
              <div style="font-size:11px;color:#9ca3af;margin-top:4px;">How close his checkout must be to that delivery point.</div>
            </div>
          </div>
        </div>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:10px;">
        <button type="button" onclick="closeAttendanceRules()" style="padding:10px 16px;border-radius:8px;border:1px solid #d1d5db;background:#ffffff;color:#374151;font-weight:600;cursor:pointer;">Cancel</button>
        <button type="button" id="ruleSaveBtn" onclick="saveAttendanceRules()" style="padding:10px 18px;border-radius:8px;border:none;background:#16a34a;color:#ffffff;font-weight:700;cursor:pointer;">Save rules</button>
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
        style="background:#16a34a;color:#ffffff;"
        class="px-6 py-4 rounded-lg hover:opacity-90 transition font-semibold text-base shadow-lg"
      >
        💾 Save Attendance
      </button>
    </div>
    </div>
  </div>

  <!-- Tabs: Today / Month / Calendar (segmented control) -->
  <div class="mb-4">
    <div class="inline-flex bg-gray-100 rounded-xl p-1 gap-1 border border-gray-200">
      <button id="tabBtnToday" onclick="switchAttTab('today')" class="px-5 py-2 text-sm font-semibold rounded-lg transition bg-white text-blue-700 shadow-sm">📋 Today</button>
      <button id="tabBtnMonth" onclick="switchAttTab('month')" class="px-5 py-2 text-sm font-semibold rounded-lg transition text-gray-600 hover:text-gray-900">📅 Month</button>
      <button id="tabBtnCalendar" onclick="switchAttTab('calendar')" class="px-5 py-2 text-sm font-semibold rounded-lg transition text-gray-600 hover:text-gray-900">🗓️ Calendar</button>
    </div>
  </div>

  <!-- ===== TODAY TAB (existing daily view — unchanged) ===== -->
  <div id="tabToday">

  <!-- Pending leave requests the rider submitted himself — approve/reject without leaving. -->
  <div id="pendingLeavesCard" style="display:none;border:1px solid #FDE68A;background:#FFFBEB;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <div style="font-size:13px;font-weight:700;color:#92400E;">🏖 Pending leave requests <span id="pendingLeavesCount" style="background:#F59E0B;color:#fff;border-radius:999px;padding:0 8px;font-size:11px;margin-left:4px;">0</span></div>
      <a href="#" onclick="loadPendingLeaves(); return false;" style="font-size:11px;color:#92400E;text-decoration:underline;">refresh</a>
    </div>
    <div id="pendingLeavesBody"></div>
  </div>

  <!-- Company-bike meter issues (info only): overnight grace exceeded and/or no meter reading. -->
  <div id="graceBreachCard" style="display:none;border:1px solid #FCA5A5;background:#FEF2F2;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
      <span style="font-size:13px;font-weight:700;color:#991B1B;">🏍 Company bike — meter issues</span>
      <span id="graceBreachCount" style="background:#DC2626;color:#fff;border-radius:999px;padding:0 8px;font-size:11px;">0</span>
      <span style="font-size:11px;color:#9CA3AF;margin-left:4px;">(for information only)</span>
    </div>
    <div id="graceBreachBody"></div>
  </div>

  <!-- Summary Cards - Elegant Horizontal Row -->
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-3 mb-4">
    <div class="flex justify-between items-center mb-2">
      <h3 class="text-sm font-semibold text-gray-700">Summary <span class="font-normal text-gray-400">· selected day</span></h3>
      <a href="#" onclick="switchAttTab('month'); return false;" class="text-xs font-medium" style="color:#2563EB;">Monthly totals →</a>
    </div>

    <!-- Slim stat strip — one number per status, colour-dot coded. Inline styles are
         intentional: the app purges un-compiled utility classes, so colours must be inline. -->
    <div style="display:flex;align-items:stretch;flex-wrap:wrap;">
      <div style="flex:1;min-width:90px;padding:4px 14px 4px 4px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#22C55E;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#16A34A;line-height:1;" id="cardPresent">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Present</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#3B82F6;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#2563EB;line-height:1;" id="cardOnTime">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">On time</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#F59E0B;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#D97706;line-height:1;" id="cardLate">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Late</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#8B5CF6;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#7C3AED;line-height:1;" id="cardOvertime">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Overtime</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 14px;border-right:1px solid #F1F5F9;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#F97316;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#EA580C;line-height:1;" id="cardOnLeave">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">On leave</div>
      </div>
      <div style="flex:1;min-width:90px;padding:4px 4px 4px 14px;">
        <div style="display:flex;align-items:baseline;gap:6px;">
          <span style="width:8px;height:8px;border-radius:50%;background:#EF4444;display:inline-block;"></span>
          <span style="font-size:22px;font-weight:700;color:#DC2626;line-height:1;" id="cardAbsent">0</span>
        </div>
        <div style="font-size:11px;color:#6B7280;margin-top:3px;">Absent</div>
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

        <!-- Filters (moved here from the header — they only affect the Today view) -->
        <div class="flex items-center gap-2 flex-wrap">
          <select id="activeFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="onActiveFilterChange()" title="Active vs all users">
            <option value="active" selected>Active users</option>
            <option value="all">All users</option>
          </select>
          <select id="userFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="loadAttendanceForDate()" title="Role">
            <option value="all">Everyone</option>
            <option value="riders">Riders only</option>
            <option value="staff">Staff only</option>
          </select>
          <select id="locationFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="filterByLocation()" title="Location">
            <option value="all" selected>All locations</option>
            <option value="onsite">On-site</option>
            <option value="remote">Remote</option>
            <option value="no_location">No location</option>
          </select>
          <select id="statusFilter" class="px-3 py-2 border border-gray-300 rounded-lg text-sm" onchange="filterTableByStatus()" title="Status">
            <option value="all">All status</option>
            <option value="present">Present</option>
            <option value="late">Late</option>
            <option value="overtime">Overtime</option>
            <option value="absent">Absent</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Meter-attention strip (only shows when something needs attention today) -->
    <div id="meterAttention" class="hidden px-4 pb-2 flex flex-wrap gap-2"></div>

    <!-- Table -->
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">In → Out</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Location</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Meter</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Hours</th>
            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody id="attBody" class="bg-white divide-y divide-gray-200">
          <tr>
            <td colspan="6" class="px-4 py-8 text-center text-gray-500 text-sm">Loading attendance records...</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  </div><!-- /#tabToday -->

  <!-- ===== MONTH TAB ===== -->
  <div id="tabMonth" class="hidden">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-2">
        <button onclick="monthStep(-1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Prev</button>
        <input type="month" id="monthPicker" onchange="loadMonthTab()" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm">
        <button onclick="monthStep(1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next →</button>
      </div>
      <div class="flex items-center gap-2">
        <span class="text-xs text-gray-500">Month totals — plus leave and absences for the year cycle <span id="monthCycleLabel" style="font-weight:600;color:#6b7280;"></span>.</span>
        <button onclick="exportMonthCsv()" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">📥 CSV</button>
      </div>
    </div>
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Employee</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Present</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Absent (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Leave (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Late (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-emerald-600 uppercase">OT (mo)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-purple-500 uppercase">Leave (bal)</th>
            <th class="px-4 py-3 text-center text-xs font-semibold text-red-500 uppercase">Absent (yr)</th>
          </tr>
        </thead>
        <tbody id="monthBody" class="bg-white divide-y divide-gray-100">
          <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ===== CALENDAR TAB ===== -->
  <div id="tabCalendar" class="hidden">
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4 mb-4 flex items-center justify-between flex-wrap gap-3">
      <div class="flex items-center gap-2">
        <button onclick="calStep(-1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">←</button>
        <span id="calLabel" class="text-sm font-semibold text-gray-800 min-w-[140px] text-center"></span>
        <button onclick="calStep(1)" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm hover:bg-gray-50">→</button>
      </div>
      <div class="flex items-center gap-3 text-xs" style="color:#6B7280;">
        <span class="inline-flex items-center gap-1"><span style="width:10px;height:10px;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:3px;display:inline-block;"></span> Holiday</span>
        <span id="calHint">Click a day to add a holiday — or click a start day then an end day for a range.</span>
        <button id="calCancelBtn" onclick="calCancelSelect()" style="display:none;background:#EF4444;color:#fff;padding:3px 10px;border-radius:6px;font-weight:600;">Cancel</button>
      </div>
    </div>
    <div class="bg-white border border-gray-200 rounded-lg shadow-sm p-4">
      <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;" class="text-center text-xs font-semibold text-gray-400">
        <div>Mon</div><div>Tue</div><div>Wed</div><div>Thu</div><div>Fri</div><div>Sat</div><div>Sun</div>
      </div>
      <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
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

<!-- Apply Leave Modal (manager applies leave on a rider's behalf → auto-approved) -->
<div id="applyLeaveModal" style="display:none;position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;padding:16px;" onclick="closeApplyLeave()">
  <div class="bg-white rounded-xl shadow-2xl w-full max-w-md" style="max-height:90vh;overflow-y:auto;" onclick="event.stopPropagation();">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
      <h3 class="text-lg font-semibold text-gray-900">📝 Apply leave</h3>
      <button onclick="closeApplyLeave()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
    </div>
    <div class="p-5 space-y-4">
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Rider / staff</label>
        <select id="leaveUser" onchange="loadLeaveBalanceChip()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"></select>
        <div id="leaveBalanceChip" style="margin-top:6px;font-size:12px;color:#6b7280;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"></div>
        <div id="leaveHistoryBox" style="display:none;margin-top:6px;font-size:12px;border-top:1px dashed #e5e7eb;padding-top:6px;"></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">From</label>
          <input type="date" id="leaveFrom" onchange="syncLeaveToMin()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">To</label>
          <input type="date" id="leaveTo" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
        </div>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Leave type</label>
        <select id="leaveType" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
          <option value="planned" selected>📅 Planned (applied in advance)</option>
          <option value="emergency">⚡ Emergency (same-day)</option>
        </select>
        <p class="text-xs text-gray-500" style="margin-top:4px;">No time cutoff applies to you. Emergency counts toward the rider's same-day allowance.</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Note <span class="text-gray-400 font-normal">(optional)</span></label>
        <input type="text" id="leaveNote" maxlength="500" placeholder="e.g. family emergency" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
      </div>
      <p class="text-xs text-gray-500">You're granting this leave — it's approved right away and logged under your name. Days on leave won't be counted as absent.</p>
    </div>
    <div class="px-5 py-4 border-t border-gray-200 flex justify-end gap-2">
      <button onclick="closeApplyLeave()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 text-sm">Cancel</button>
      <button id="leaveSubmitBtn" onclick="submitApplyLeave()" style="background:#16a34a;color:#ffffff;" class="px-4 py-2 rounded-lg hover:opacity-90 text-sm font-semibold">Approve leave</button>
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
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #16a34a; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Meter</th>
            <th style="padding: 10px 16px; text-align: center; font-size: 11px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; white-space: nowrap;">Pics</th>
          </tr>
        </thead>
        <tbody id="employeeDetailsBody" style="background: white;">
          <tr>
            <td colspan="12" style="padding: 20px; text-align: center; color: #6b7280;">Loading...</td>
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

{{-- Shared "Change shift" popup (used by the clickable Expected Shift column) --}}
@include('partials.shift-change-modal')

<!-- Customize User List Modal -->
<div id="customizeUserListModal" style="display: none;" onclick="if(event.target === this) closeCustomizeUserList();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 800px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">People &amp; Rider List</h2>
          <p style="font-size: 14px; color: #6b7280; margin-top: 4px;"><b>Show in Attendance</b> = appears in attendance &amp; salary tracking. <b>Delivery Rider</b> = appears in the rider-assign lists on web &amp; mobile. These are independent — e.g. an office person can be shown in attendance but not be a delivery rider.</p>
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
            <th style="padding: 12px;">Employee</th>
            <th style="padding: 12px;">Role</th>
            <th style="padding: 12px; width: 150px; text-align:center;">Show in Attendance</th>
            <th style="padding: 12px; width: 140px; text-align:center;">Delivery Rider</th>
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

<!-- GPS Audit Modal -->
<div id="gpsAuditModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 99999; background: rgba(0, 0, 0, 0.6); align-items: center; justify-content: center; padding: 1rem;" onclick="if(event.target === this) closeGpsAudit();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 700px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    
    <!-- Header -->
    <div style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
      <div>
        <h3 style="font-size: 18px; font-weight: 600; color: #111827; margin: 0;" id="gpsAuditTitle">GPS Tracking Audit</h3>
        <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 0;" id="gpsAuditSubtitle">Loading...</p>
      </div>
      <button onclick="closeGpsAudit()" style="background: none; border: none; color: #9ca3af; font-size: 28px; cursor: pointer; padding: 0 8px;">&times;</button>
    </div>
    
    <!-- Content -->
    <div id="gpsAuditContent" style="flex: 1; overflow-y: auto; padding: 20px 24px;">
      <div style="text-align: center; padding: 40px; color: #6b7280;">
        <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
        Loading GPS data...
      </div>
    </div>
    
    <!-- Footer -->
    <div style="padding: 16px 24px; border-top: 1px solid #e5e7eb; flex-shrink: 0; display: flex; justify-content: flex-end;">
      <button onclick="closeGpsAudit()" style="padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;">
        Close
      </button>
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
  
  /* GPS Audit Styles */
  .gps-audit-stat {
    text-align: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
  }
  .gps-audit-stat .value {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
  }
  .gps-audit-stat .label {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
  }
  .gps-gap-item {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    border-radius: 8px;
    margin-bottom: 8px;
  }
  .gps-gap-critical {
    background: #fef2f2;
    border-left: 3px solid #ef4444;
  }
  .gps-gap-warning {
    background: #fffbeb;
    border-left: 3px solid #f59e0b;
  }
  .gps-gap-info {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
  }
  .gps-timeline {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 12px;
  }
  .gps-timeline-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
  }
  .gps-timeline-dot.gap {
    background: #ef4444;
    width: 16px;
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
let deliveryRiderChanges = {};

// Initialize on page load
document.addEventListener('DOMContentLoaded', async function() {
  await loadAllUsers();
  loadAttendanceForDate();
  loadPendingLeaves();

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
        // Use the in-place popup (same as clicking the Expected Shift) instead of
        // redirecting away to the shifts page.
        openShiftChange({ userId: userId, userName: userName, onSaved: loadAttendanceForDate });
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
    // Meter-flag thresholds (with sane defaults if the endpoint omits them).
    window.attConfig = attJson.config || {meter_gps_warn_km: 10, overnight_grace_km: 30};
    
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
    // Grace-breach alert reflects ALL riders (not the current table filter).
    renderGraceBreachBanner(allAttendanceData);
  } catch(e) {
    console.error('Error loading attendance', e);
    document.getElementById('attBody').innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-red-500 text-sm">Error loading data</td></tr>';
  }
}

// ===== Pending leave requests (rider-submitted) — approve/reject inline =====
function escLeave(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function leaveDateLabel(s){ try { return new Date(s+'T00:00:00').toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short'}); } catch(e){ return s; } }

async function loadPendingLeaves() {
  const card = document.getElementById('pendingLeavesCard');
  const body = document.getElementById('pendingLeavesBody');
  if (!card || !body) return;
  try {
    const res = await fetch('/attendance/pending-leaves', { headers: { 'Accept':'application/json' } });
    const j = await res.json();
    const reqs = (j.success && j.requests) ? j.requests : [];
    if (!reqs.length) { card.style.display = 'none'; return; }
    document.getElementById('pendingLeavesCount').textContent = reqs.length;
    body.innerHTML = reqs.map(r => {
      const range = r.start === r.end ? leaveDateLabel(r.start) : (leaveDateLabel(r.start) + ' → ' + leaveDateLabel(r.end));
      const stale = r.upcoming ? '' : '<span style="font-size:10px;color:#9CA3AF;">(past)</span>';
      return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-top:1px solid #FDE68A;" data-leaverow="'+r.id+'">' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-size:13px;font-weight:600;color:#111827;">'+escLeave(r.name)+' '+stale+'</div>' +
          '<div style="font-size:12px;color:#6B7280;">'+range+' · '+r.days+' day'+(r.days>1?'s':'')+' · '+escLeave(r.type)+'</div>' +
          (r.reason ? '<div style="font-size:11px;color:#9CA3AF;margin-top:1px;">'+escLeave(r.reason)+'</div>' : '') +
        '</div>' +
        '<button onclick="approveLeave('+r.id+', this)" style="background:#16A34A;color:#fff;border:none;border-radius:7px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;">Approve</button>' +
        '<button onclick="rejectLeave('+r.id+', this)" style="background:#fff;color:#B91C1C;border:1px solid #FCA5A5;border-radius:7px;padding:6px 10px;font-size:12px;font-weight:600;cursor:pointer;">Reject</button>' +
      '</div>';
    }).join('');
    card.style.display = 'block';
  } catch(e) {
    console.error('Error loading pending leaves', e);
    card.style.display = 'none';
  }
}

async function approveLeave(id, btn) {
  btn.disabled = true; btn.textContent = '…';
  try {
    const res = await fetch('/attendance/leave-request/'+id+'/approve', {
      method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    // Remove the row, refresh both the list and the day table (approved rider stops showing Absent).
    const row = document.querySelector('[data-leaverow="'+id+'"]'); if (row) row.remove();
    await loadPendingLeaves();
    if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
  } catch(e) {
    alert('Could not approve: ' + (e.message || e));
    btn.disabled = false; btn.textContent = 'Approve';
  }
}

async function rejectLeave(id, btn) {
  const reason = prompt('Reason for rejecting this leave (optional):', '');
  if (reason === null) return; // cancelled
  btn.disabled = true; btn.textContent = '…';
  try {
    const res = await fetch('/attendance/leave-request/'+id+'/reject', {
      method:'POST', headers:{ 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ reason })
    });
    const j = await res.json();
    if (!j.success) throw new Error(j.message || 'Failed');
    const row = document.querySelector('[data-leaverow="'+id+'"]'); if (row) row.remove();
    await loadPendingLeaves();
  } catch(e) {
    alert('Could not reject: ' + (e.message || e));
    btn.disabled = false; btn.textContent = 'Reject';
  }
}

function renderAttendanceTable(data) {
  const body = document.getElementById('attBody');

  renderMeterAttention(data);

  if (!data || data.length === 0) {
    body.innerHTML = '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500 text-sm">No attendance records found for this date</td></tr>';
    return;
  }

  body.innerHTML = data.map(r => {
    const hours = calculateHours(r.login_time, r.logout_time);
    // Prefer server-computed late/overtime (per-date + frozen-snapshot aware). Fall
    // back to local calc only if the server didn't send them (older cached responses).
    const fmtMins = (n) => { const h = Math.floor(n / 60), m = n % 60; return h > 0 ? `${h}h ${m}m` : `${m}m`; };
    const lateBy = (r.late_minutes != null)
      ? { isLate: r.late_minutes > 0, duration: r.late_minutes > 0 ? fmtMins(r.late_minutes) : '-' }
      : calculateLateBy(r.login_time, r.shift_start);
    const overtime = (r.overtime_minutes != null)
      ? { hasOvertime: r.overtime_minutes > 0, duration: r.overtime_minutes > 0 ? fmtMins(r.overtime_minutes) : '-' }
      : calculateOvertime(r.logout_time, r.shift_end, r.login_time);
    
    // Get location status
    const locationBadge = getLocationBadge(r);
    
    return `
      <tr class="hover:bg-gray-50" style="border-left:3px solid ${attStatusColor(r, lateBy)}" data-status="${getRowStatus(r, lateBy, overtime)}" data-location="${locationBadge.type}">
        <td class="px-4 py-3 text-sm font-medium">
          <button
            onclick="showEmployeeDetails(${r.user_id}, \`${(r.fullname || '').replace(/`/g, '')}\`, '${r.attendance_date}')"
            class="text-blue-600 hover:text-blue-800 hover:underline font-medium cursor-pointer text-left"
            title="View last 30 days attendance with order details"
          >
            ${r.fullname || '#' + r.user_id}
          </button>
          <div class="mt-1" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
            ${attStatusPill(r, lateBy, overtime)}
            <button type="button"
              onclick="openShiftChange({userId:${r.user_id}, userName:\`${(r.fullname||'').replace(/`/g,'')}\`, onSaved: loadAttendanceForDate})"
              title="${r.shift_start || '09:00'}${r.shift_end ? ' – ' + r.shift_end : ' onwards'} — click to change this rider's shift"
              style="background:none;border:none;padding:0;font-size:11px;color:#6B7280;cursor:pointer;white-space:nowrap;">
              · ${r.shift_name || 'Default Shift'} <span style="color:#D1D5DB;">✎</span>
            </button>
          </div>
          ${attInlineContext(r)}
        </td>
        <td class="px-4 py-3 text-sm whitespace-nowrap">
          <span class="${r.login_time ? (lateBy.isLate ? 'text-red-600 font-medium' : 'text-gray-900') : 'text-gray-300'}">${r.login_time || '–'}</span>
          <span class="text-gray-300 mx-1">→</span>
          <span class="${r.logout_time ? 'text-gray-900' : 'text-gray-300'}">${r.logout_time || '–'}</span>
          ${checkoutChip(r.checkout_info)}
        </td>

        <!-- Location Badge Column -->
        <td class="px-4 py-3 text-sm">
          ${locationBadge.html}
        </td>

        <!-- Meter Column: "127 km 📷📷 details" + integrity flags -->
        <td class="px-4 py-3 text-sm" style="white-space:nowrap;">
          <div>${getDistanceBadge(r)}</div>
          ${getMeterFlags(r)}
        </td>

        <td class="px-4 py-3 text-sm ${hours === '-' ? 'text-gray-300' : 'text-gray-600'}">${hours}</td>
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
            ${(!r.login_time || r.day_kind === 'not_needed') ? `
            <button type="button"
              onclick="toggleNotNeeded(${r.user_id}, '${r.attendance_date}', ${r.day_kind === 'not_needed' ? 'true' : 'false'})"
              title="${r.day_kind === 'not_needed' ? 'Marked not needed — click to undo' : 'Mark as not needed (paid, not counted absent)'}"
              style="padding:4px 8px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid ${r.day_kind === 'not_needed' ? '#c7d2fe' : '#e5e7eb'};background:${r.day_kind === 'not_needed' ? '#e0e7ff' : '#f9fafb'};color:${r.day_kind === 'not_needed' ? '#3730a3' : '#6b7280'};">🚫</button>
            ` : ''}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

// Mark / unmark a rider as "not needed" for a day. Paid as present, never absent.
async function toggleNotNeeded(uid, date, isTagged) {
  // Absent rows have no attendance record, so r.attendance_date is missing → fall back to the
  // day the table is showing (the tag is always for the selected day). Fixes the
  // "The date field must be a valid date" error when tagging a no-show.
  if (!date || date === 'undefined' || date === 'null') {
    const td = document.getElementById('tableDate');
    date = td ? td.value : '';
  }
  if (!date) { alert('Please pick a valid date first.'); return; }
  const msg = isTagged
    ? 'Remove the "not needed" mark for this day?'
    : 'Mark this rider as NOT NEEDED for this day?\n\nIt will be treated as a normal paid day — not counted absent, no deduction.';
  if (!confirm(msg)) return;
  try {
    const res = await fetch('/attendance/toggle-day-tag', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: uid, date: date })
    });
    const j = await res.json();
    if (j.success) {
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else {
      alert(j.message || 'Could not update the day.');
    }
  } catch (e) { alert('Could not update the day.'); }
}

function getRowStatus(r, lateBy, overtime) {
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  if (onLeave) return 'leave';
  // No login: only a genuine working day is "absent"; off/holiday/not-joined are
  // their own status so the Absent filter doesn't sweep them in.
  if (!r.login_time) return ((r.day_kind || 'working') === 'working') ? 'absent' : (r.day_kind || 'off');
  if (overtime.hasOvertime) return 'overtime';
  if (lateBy.isLate) return 'late';
  return 'present';
}

// Status chip under the employee name — folds in Late duration + an Overtime badge
// (replaces the old separate Late By / Overtime columns).
function attStatusPill(r, lateBy, overtime) {
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  const kind = r.day_kind || 'working';
  let label, bg, fg;
  if (onLeave)            { label = 'On leave';                bg = '#E6ECFD'; fg = '#1D4ED8'; }
  // A no-login day only counts as Absent on a genuine working day. Holiday / weekly
  // off / before-hire-date show a neutral gray chip, never red.
  else if (!r.login_time && kind === 'holiday')     { label = 'Holiday';    bg = '#EEF1F5'; fg = '#5B6B84'; }
  else if (!r.login_time && kind === 'off')         { label = 'Off day';    bg = '#EEF1F5'; fg = '#5B6B84'; }
  else if (!r.login_time && kind === 'not_joined')  { label = 'Not joined'; bg = '#EEF1F5'; fg = '#94A3B8'; }
  // A manager-tagged "not needed" day with no login — paid as present, never absent.
  // (If the rider logged in anyway, normal On-time/Late below applies.)
  else if (!r.login_time && kind === 'not_needed')  { label = 'Not needed'; bg = '#E6ECFD'; fg = '#3730A3'; }
  else if (!r.login_time) { label = 'Absent';                 bg = '#FDE7E7'; fg = '#B42318'; }
  else if (lateBy.isLate) { label = 'Late ' + lateBy.duration; bg = '#FBEEDC'; fg = '#B45309'; }
  else                    { label = 'On time';                bg = '#E6F3EB'; fg = '#15803D'; }
  let html = `<span style="display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:20px; background:${bg}; color:${fg};">${label}</span>`;
  if (overtime && overtime.hasOvertime) {
    html += ` <span style="display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:20px; background:#E6F3EB; color:#15803D;">+OT ${overtime.duration}</span>`;
  }
  return html;
}

// Small inline context under the status pill:
//   • month-to-date lateness (amber) — always shown when > 0, so a manager sees the
//     accumulating pattern without opening the Month tab.
//   • "Nth absence this year" (red) — only when the rider is absent today, so an
//     unexplained no-show is instantly weighed against their yearly record.
function attInlineContext(r) {
  const bits = [];
  // Leave detail (the old Leave column, folded in): type + approval state.
  if (r.leave_request_id) {
    const st = String(r.leave_status || '').toLowerCase();
    const [bg, fg, bd] = st === 'approved' ? ['#DCFCE7', '#15803D', '#86EFAC']
                       : st === 'pending'  ? ['#FEF9C3', '#854D0E', '#FDE047']
                       :                     ['#FEE2E2', '#B42318', '#FCA5A5'];
    bits.push(`<span title="Leave request ${st}" style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:${fg};background:${bg};border:1px solid ${bd};border-radius:5px;padding:1px 6px;">🏖 ${r.leave_type_from_req || 'Leave'} · ${st}</span>`);
  }
  const mlate = Number(r.month_late_minutes) || 0;
  if (mlate > 0) {
    const h = Math.floor(mlate / 60), m = mlate % 60;
    const txt = h > 0 ? `${h}h ${m}m` : `${m}m`;
    bits.push(`<span title="Total late so far this month" style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:600;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:5px;padding:1px 6px;">⏰ ${txt} this mo</span>`);
  }
  const ya = r.year_absent_days;
  if (ya != null && Number(ya) > 0) {
    const n = Number(ya);
    const ord = (n % 10 === 1 && n % 100 !== 11) ? 'st' : (n % 10 === 2 && n % 100 !== 12) ? 'nd' : (n % 10 === 3 && n % 100 !== 13) ? 'rd' : 'th';
    bits.push(`<span title="Absent working-days so far this year cycle (including today)" style="display:inline-flex;align-items:center;gap:3px;font-size:11px;font-weight:700;color:#B42318;background:#FDE7E7;border:1px solid #FCA5A5;border-radius:5px;padding:1px 6px;">📅 ${n}${ord} absence this yr</span>`);
  }
  if (!bits.length) return '';
  return `<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">${bits.join('')}</div>`;
}

// Meter-photo proof icons for the distance cell. A rider who logged a meter reading
// should also have snapped the odometer: a camera with photo on file is CLICKABLE
// (opens the photo via the shared viewMeterPicturePath viewer); a greyed camera =
// reading present but NO photo (can't be verified).
function getMeterPhotoIcons(r) {
  const icons = [];
  const path = v => (v && v !== '-') ? String(v).replace(/'/g, "\\'") : null;
  const cam = (p, title) => p
    ? `<button type="button" onclick="viewMeterPicturePath('${p}')" title="${title}: photo attached — click to view" style="background:none;border:none;padding:0;cursor:pointer;font-size:12px;line-height:1;">📷</button>`
    : `<span title="${title}: no photo" style="font-size:12px;filter:grayscale(1);opacity:0.4;line-height:1;">📷</span>`;
  if (r.meter_start != null && r.meter_start !== '') icons.push(cam(path(r.picture_start), 'Start meter'));
  if (r.meter_end != null && r.meter_end !== '')   icons.push(cam(path(r.picture_end), 'End meter'));
  if (!icons.length) return '';
  return `<span style="display:inline-flex;gap:3px;margin-left:5px;vertical-align:middle;">${icons.join('')}</span>`;
}

// Left-edge accent colour per row status (scannable at a glance).
function attStatusColor(r, lateBy) {
  const onLeave = r.leave_request_id && ['approved','pending'].includes(String(r.leave_status || '').toLowerCase());
  const kind = r.day_kind || 'working';
  if (onLeave) return '#1D4ED8';
  // Genuine absence (a working day with no login) stands out red; off/holiday/
  // not-joined stay neutral gray.
  if (!r.login_time) return (kind === 'working') ? '#EF4444' : '#CBD5E1';
  if (lateBy.isLate) return '#F59E0B';
  return '#22C55E';
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
    const mapsUrl = `https://www.google.com/maps?q=${record.checkin_latitude},${record.checkin_longitude}`;
    
    // Name of the base the distance was measured against (the SHIFT location for
    // the selected date, recomputed server-side) — "7.9 km from LaCarne" tells the
    // manager which pin the number is relative to; a bare "away" doesn't.
    const escLoc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const baseName = record.assigned_office_location ? escLoc(record.assigned_office_location) : null;

    // Compact dot-pill (inline-styled, purge-safe). A small coloured dot replaces the
    // oversized tick/pin SVGs; the whole pill links to Google Maps.
    const pill = (bg, fg, dot, text) => `
      <a href="${mapsUrl}" target="_blank" title="Click to view on Google Maps"
         style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:${bg};color:${fg};text-decoration:none;white-space:nowrap;">
        <span style="width:7px;height:7px;border-radius:50%;background:${dot};display:inline-block;flex:none;"></span>${text}
      </a>`;

    if (record.is_remote_checkin == 1) {
      // Remote check-in (outside the base location's radius)
      const distanceKm = (record.checkin_distance_from_base / 1000).toFixed(1);
      return {
        type: 'remote',
        html: pill('#FEE2E2', '#B42318', '#EF4444', `${distanceKm} km ${baseName ? 'from ' + baseName : 'away'}`)
      };
    } else {
      // Onsite check-in (within the base location's radius)
      const distanceText = record.checkin_distance_from_base
        ? (record.checkin_distance_from_base < 1000
            ? `${Math.round(record.checkin_distance_from_base)}m`
            : `${(record.checkin_distance_from_base / 1000).toFixed(1)}km`)
        : '';
      return {
        type: 'onsite',
        html: pill('#DCFCE7', '#15803D', '#22C55E', `At ${baseName || 'office'}${distanceText ? ` · ${distanceText}` : ''}`)
      };
    }
  }

  // Check-in without location (GPS failed or denied)
  return {
    type: 'no_location',
    html: `<span title="Location not captured" style="display:inline-flex;align-items:center;gap:5px;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;background:#F3F4F6;color:#6B7280;white-space:nowrap;"><span style="width:7px;height:7px;border-radius:50%;background:#9CA3AF;display:inline-block;flex:none;"></span>No location</span>`
  };
}

/**
 * Meter-integrity flags shown under the distance detail:
 *   ⛽ no meter        — a rider finished the day (checked out) but logged no meter reading
 *   ⚠ N km off         — meter vs tracked (road/GPS) distance differ beyond the threshold
 *                        (accuracy warning only, never blocks anything)
 *   🏍 +N km overnight — company-bike rider whose start meter exceeds yesterday's end meter
 *                        beyond the grace → the bike was used off company hours
 * Thresholds come from window.attConfig (backend t_fin_config, defaults 10 / 25 km).
 */
// ── Tabs: Today / Month / Calendar ───────────────────────────────────────────
let monthData = [];
function switchAttTab(tab) {
  ['today','month','calendar'].forEach(t => {
    const cap = t.charAt(0).toUpperCase() + t.slice(1);
    const panel = document.getElementById('tab' + cap);
    const btn = document.getElementById('tabBtn' + cap);
    if (panel) panel.classList.toggle('hidden', t !== tab);
    if (btn) {
      const active = (t === tab);
      btn.classList.toggle('bg-white', active);
      btn.classList.toggle('text-blue-700', active);
      btn.classList.toggle('shadow-sm', active);
      btn.classList.toggle('text-gray-600', !active);
    }
  });
  if (tab === 'month') loadMonthTab();
  if (tab === 'calendar') loadCalendarTab();
}

// ---- Month tab ----
function currentMonthValue() {
  const el = document.getElementById('monthPicker');
  if (el && el.value) return el.value;
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
}
function monthStep(delta) {
  const parts = currentMonthValue().split('-');
  const d = new Date(Number(parts[0]), Number(parts[1]) - 1 + delta, 1);
  document.getElementById('monthPicker').value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
  loadMonthTab();
}
async function loadMonthTab() {
  const monthEl = document.getElementById('monthPicker');
  if (monthEl && !monthEl.value) monthEl.value = currentMonthValue();
  const body = document.getElementById('monthBody');
  body.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">Loading…</td></tr>';
  try {
    const res = await fetch('/attendance/monthly-report?month=' + currentMonthValue());
    const json = await res.json();
    monthData = json.success ? (json.data || []) : [];
    renderMonthBody(monthData);
  } catch (e) {
    body.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-red-500 text-sm">Failed to load</td></tr>';
  }
}
function fmtMinsShort(n) { n = Number(n) || 0; const h = Math.floor(n / 60), m = n % 60; return h > 0 ? `${h}h ${m}m` : `${m}m`; }
function fmtCycleShort(d) {
  if (!d) return '';
  const p = String(d).split('-'); if (p.length !== 3) return d;
  const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return m[parseInt(p[1],10)-1] + " '" + p[0].slice(2);
}
function renderMonthBody(data) {
  const body = document.getElementById('monthBody');
  const lbl = document.getElementById('monthCycleLabel');
  if (lbl) {
    const c0 = data[0] || {};
    lbl.textContent = (c0.cycle_start && c0.cycle_end) ? '(' + fmtCycleShort(c0.cycle_start) + ' → ' + fmtCycleShort(c0.cycle_end) + ')' : '';
  }
  if (!data.length) { body.innerHTML = '<tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">No data for this month</td></tr>'; return; }
  const sorted = [...data].sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || '')));
  // A clickable count → opens the exact-dates drill-down. Zero shows a muted dash so the
  // eye skips it. stopPropagation keeps the row's own "open 30-day detail" from also firing.
  const numCell = (val, color, uid, nm, type) => {
    const n = Number(val) || 0;
    if (n <= 0) return `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
    return `<td class="px-4 py-3 text-sm" style="text-align:center;">
      <button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', '${type}')"
        title="Click to see the exact dates"
        style="background:none;border:none;cursor:pointer;font-weight:700;color:${color};text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;">${n}</button>
    </td>`;
  };
  body.innerHTML = sorted.map(u => {
    const late = Number(u.total_late_minutes) || 0;
    const lateStyle = late > 300 ? 'background:#FEE2E2;color:#B91C1C;' : (late > 0 ? 'background:#FEF3C7;color:#92400E;' : 'background:#F3F4F6;color:#9CA3AF;');
    const uid = u.user_id;
    const nm = String(u.fullname || '').replace(/'/g, "\\'");
    const ot = Number(u.overtime_target_minutes) || 0;
    const otCell = ot > 0
      ? `<td class="px-4 py-3 text-center"><button type="button" onclick="event.stopPropagation(); showDateBreakdown(${uid}, '${nm}', 'month_overtime')" title="Click to see the days" style="background:none;border:none;cursor:pointer;font-weight:700;color:#047857;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:2px;font-size:13px;">${fmtMinsShort(ot)}</button></td>`
      : `<td class="px-4 py-3 text-sm" style="text-align:center;color:#D1D5DB;">–</td>`;
    return `<tr class="hover:bg-gray-50 cursor-pointer" onclick="openMonthDetail(${uid}, '${nm}')">
      <td class="px-4 py-3 text-sm" style="font-weight:600;color:#1F2937;">${u.fullname || ''}</td>
      <td class="px-4 py-3 text-sm text-center" style="color:#374151;">${u.present_days || 0}</td>
      ${numCell(u.absent_days, '#DC2626', uid, nm, 'month_absent')}
      ${numCell(u.leave_days, '#2563EB', uid, nm, 'month_leave')}
      <td class="px-4 py-3 text-center"><span style="display:inline-block;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:600;${lateStyle}">${fmtMinsShort(late)}</span></td>
      ${otCell}
      ${leaveBalCell(u, uid, nm)}
      ${numCell(u.absent_days_year, '#B91C1C', uid, nm, 'year_absent')}
    </tr>`;
  }).join('');
}
// The "Leave (bal)" cell: remaining leaves up top (the number a manager decides on), with taken
// context + where the extra/missing ones came from. Click → full balance + history summary.
function leaveBalCell(u, uid, nm) {
  if (u.leave_remaining == null || u.leave_remaining === undefined) {
    // Balance unavailable (old cache / error) — fall back to the plain taken-count drill.
    return numCell(u.leaves_taken_year, '#7C3AED', uid, nm, 'year_leave');
  }
  const rem = Number(u.leave_remaining);
  const taken = Number(u.leaves_taken_year) || 0;
  const quota = Number(u.leave_effective_quota) || 0;
  const remColor = rem <= 0 ? '#DC2626' : (rem <= 2 ? '#D97706' : '#15803D');
  const ot = Number(u.leave_earned_overtime) || 0;
  const late = Number(u.leave_late_penalties) || 0;
  const adj = Number(u.leave_manual_adjust) || 0;
  let chips = '';
  if (ot > 0) chips += `<span style="color:#6d28d9;">+${ot} OT</span> `;
  if (late < 0) chips += `<span style="color:#c2410c;">${late} late</span> `;
  if (adj !== 0) chips += `<span style="color:#6b7280;">${adj > 0 ? '+' : ''}${adj} adj</span> `;
  return `<td class="px-4 py-3" style="text-align:center;">
    <button type="button" onclick="event.stopPropagation(); showLeaveSummary(${uid}, '${nm}')" title="Leave balance + history"
      style="background:none;border:none;cursor:pointer;padding:0;">
      <div style="font-weight:800;font-size:14px;color:${remColor};line-height:1.1;">${rem}<span style="font-size:10px;font-weight:600;color:#9CA3AF;"> left</span></div>
      <div style="font-size:10px;color:#9CA3AF;margin-top:1px;">${taken} of ${quota} taken</div>
      ${chips ? `<div style="font-size:10px;margin-top:1px;">${chips.trim()}</div>` : ''}
    </button>
  </td>`;
}

// Full leave picture for one employee — balance formula + adjustment history (who/when/why) +
// the dates actually taken. Reuses the date-breakdown modal shell.
async function showLeaveSummary(uid, name) {
  const u = monthData.find(x => String(x.user_id) === String(uid)) || {};
  const modal = document.getElementById('dateBreakdownModal');
  document.getElementById('bdTitle').textContent = `🏖 Leave — ${name}`;
  document.getElementById('bdSub').textContent = 'balance · adjustments · dates taken (year cycle)';
  const bodyEl = document.getElementById('bdBody');
  bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">Loading…</div>';
  modal.style.display = 'flex';

  const rem = Number(u.leave_remaining) || 0;
  const remColor = rem <= 0 ? '#DC2626' : (rem <= 2 ? '#D97706' : '#15803D');
  const quotaBase = Number(u.leave_quota_total) || 0;
  const ot = Number(u.leave_earned_overtime) || 0;
  const late = Number(u.leave_late_penalties) || 0;
  const adj = Number(u.leave_manual_adjust) || 0;
  const taken = Number(u.leaves_taken_year) || 0;

  const fRow = (label, val, color) => `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;"><span style="color:#6B7280;">${label}</span><span style="font-weight:600;color:${color || '#111827'};">${val}</span></div>`;
  let summary = `<div style="background:#F9FAFB;border:1px solid #F3F4F6;border-radius:8px;padding:10px 12px;margin-bottom:12px;">`;
  summary += `<div style="text-align:center;margin-bottom:6px;"><span style="font-size:26px;font-weight:800;color:${remColor};">${rem}</span><span style="font-size:13px;color:#6B7280;font-weight:600;"> leaves left</span></div>`;
  summary += fRow('Yearly quota', quotaBase);
  if (ot > 0) summary += fRow('+ Overtime earned', '+' + ot, '#6d28d9');
  if (late < 0) summary += fRow('− Late penalty', late, '#c2410c');
  if (adj !== 0) summary += fRow((adj > 0 ? '+ ' : '− ') + 'Manual adjustment', (adj > 0 ? '+' : '') + adj, '#6b7280');
  summary += fRow('− Taken', '−' + taken, '#2563eb');
  summary += `<div style="border-top:1px dashed #E5E7EB;margin-top:4px;padding-top:4px;">` + fRow('= Remaining', rem, remColor) + `</div>`;
  summary += `</div>`;

  const dow = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'], mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const fmtD = (ds) => { if (!ds) return ''; const d = new Date(ds + 'T00:00:00'); return `${dow[d.getDay()]}, ${d.getDate()} ${mon[d.getMonth()]} '${String(d.getFullYear()).slice(2)}`; };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
  try {
    const [adjRes, takenRes] = await Promise.all([
      fetch('/attendance/date-breakdown?type=leave_grants&user_id=' + uid).then(r => r.json()).catch(() => ({})),
      fetch('/attendance/date-breakdown?type=year_leave&user_id=' + uid + '&month=' + currentMonthValue()).then(r => r.json()).catch(() => ({})),
    ]);
    const adjs = (adjRes && adjRes.success) ? (adjRes.dates || []) : [];
    let adjHtml = `<div style="font-size:12px;font-weight:700;color:#374151;margin:4px 0 6px;">Adjustments — overtime / late / manual</div>`;
    adjHtml += adjs.length
      ? adjs.map(a => `<div style="display:flex;justify-content:space-between;gap:10px;padding:6px 4px;border-bottom:1px solid #F3F4F6;"><span style="font-size:12.5px;color:#111827;">${esc(a.label || '')}</span><span style="font-size:11px;color:#9CA3AF;white-space:nowrap;">${fmtD(a.date)}</span></div>`).join('')
      : `<div style="font-size:12px;color:#9CA3AF;padding:4px;">None this cycle.</div>`;
    const takens = (takenRes && takenRes.success) ? (takenRes.dates || []) : [];
    let takenHtml = `<div style="font-size:12px;font-weight:700;color:#374151;margin:12px 0 6px;">Leave dates taken (${takens.length})</div>`;
    takenHtml += takens.length
      ? takens.map(t => `<div style="display:flex;justify-content:space-between;gap:10px;padding:6px 4px;border-bottom:1px solid #F3F4F6;"><span style="font-size:12.5px;color:#111827;">${fmtD(t.date)}</span>${t.label ? `<span style="font-size:11px;color:#7C3AED;background:#7C3AED14;border-radius:5px;padding:1px 7px;font-weight:600;">${esc(t.label)}</span>` : ''}</div>`).join('')
      : `<div style="font-size:12px;color:#9CA3AF;padding:4px;">None this cycle.</div>`;
    bodyEl.innerHTML = summary + adjHtml + takenHtml;
  } catch (e) {
    bodyEl.innerHTML = summary + '<div style="color:#DC2626;font-size:12px;padding:8px;">Could not load history.</div>';
  }
}

function openMonthDetail(userId, name) {
  if (typeof showEmployeeDetails !== 'function') return;
  // Scope the detail to the SELECTED month (1st → last day), not a rolling 30-day window.
  const m = currentMonthValue();                       // 'YYYY-MM'
  const y = parseInt(m.slice(0, 4), 10), mo = parseInt(m.slice(5, 7), 10);
  const start = m + '-01';
  const end = m + '-' + String(new Date(y, mo, 0).getDate()).padStart(2, '0'); // last day of month
  const label = new Date(m + '-01T00:00:00').toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
  showEmployeeDetails(userId, name, end, start, end, label);
}

// ---- Month-tab date drill-down (exact dates behind a clicked count) ----
const BREAKDOWN_META = {
  month_absent: { title: 'Absent days',       sub: 'this month',      color: '#DC2626', icon: '❌' },
  month_leave:  { title: 'Leave days',        sub: 'this month',      color: '#2563EB', icon: '🏖' },
  year_leave:   { title: 'Leave days',        sub: 'this year cycle', color: '#7C3AED', icon: '🏖' },
  year_absent:  { title: 'Absent days',       sub: 'this year cycle', color: '#B91C1C', icon: '❌' },
  month_overtime: { title: 'Overtime days',   sub: 'this month',      color: '#047857', icon: '⏱' },
};
async function showDateBreakdown(userId, name, type) {
  const meta = BREAKDOWN_META[type] || { title: 'Dates', sub: '', color: '#374151', icon: '📅' };
  const modal = document.getElementById('dateBreakdownModal');
  document.getElementById('bdTitle').textContent = `${meta.icon} ${meta.title} — ${name}`;
  document.getElementById('bdSub').textContent = meta.sub;
  const bodyEl = document.getElementById('bdBody');
  bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">Loading…</div>';
  modal.style.display = 'flex';
  try {
    const res = await fetch(`/attendance/date-breakdown?user_id=${userId}&type=${type}&month=${currentMonthValue()}`);
    const json = await res.json();
    const dates = (json && json.success) ? (json.dates || []) : [];
    if (!dates.length) {
      bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">No dates found.</div>';
      return;
    }
    const dow = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const rows = dates.map(item => {
      const d = new Date(item.date + 'T00:00:00');
      const label = `${dow[d.getDay()]}, ${d.getDate()} ${mon[d.getMonth()]} ${d.getFullYear()}`;
      const tag = item.label ? `<span style="font-size:11px;color:${meta.color};background:${meta.color}14;border-radius:5px;padding:1px 7px;font-weight:600;">${String(item.label).replace(/</g,'&lt;')}</span>` : '';
      return `<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 4px;border-bottom:1px solid #F3F4F6;">
        <span style="font-size:13px;color:#111827;">${label}</span>${tag}
      </div>`;
    }).join('');
    bodyEl.innerHTML = `<div style="font-size:12px;color:#6B7280;margin-bottom:8px;">${dates.length} day${dates.length>1?'s':''}</div>${rows}`;
  } catch (e) {
    bodyEl.innerHTML = '<div style="padding:24px;text-align:center;color:#DC2626;font-size:13px;">Could not load the dates.</div>';
  }
}
function closeDateBreakdown() { document.getElementById('dateBreakdownModal').style.display = 'none'; }
function exportMonthCsv() {
  if (!monthData.length) { alert('Nothing to export.'); return; }
  let csv = 'Employee,Present,Absent (month),Leave (month),Late minutes,Leave (year),Absent (year)\n';
  [...monthData].sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || ''))).forEach(u => {
    csv += `"${(u.fullname || '').replace(/"/g, '""')}",${u.present_days || 0},${u.absent_days || 0},${u.leave_days || 0},${u.total_late_minutes || 0},${u.leaves_taken_year || 0},${u.absent_days_year || 0}\n`;
  });
  const blob = new Blob([csv], { type: 'text/csv' });
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = `attendance-${currentMonthValue()}.csv`; a.click();
}

// ---- Calendar tab ----
let calYear, calMonth, calHolidays = {};
async function loadCalendarTab() {
  if (calYear === undefined) { const d = new Date(); calYear = d.getFullYear(); calMonth = d.getMonth(); }
  try {
    const res = await fetch('/holidays/list?year=' + calYear);
    const json = await res.json();
    calHolidays = {};
    (json.data || []).forEach(h => { calHolidays[h.holiday_date] = h.holiday_name; });
  } catch (e) { calHolidays = {}; }
  renderCalendar();
}
let calRangeStart = null; // 'Y-m-d' while picking a range end, else null
function calStep(delta) {
  calMonth += delta;
  if (calMonth < 0) { calMonth = 11; calYear--; }
  else if (calMonth > 11) { calMonth = 0; calYear++; }
  loadCalendarTab();
}
function calCancelSelect() { calRangeStart = null; renderCalendar(); }
function updateCalHint() {
  const hint = document.getElementById('calHint');
  const cancel = document.getElementById('calCancelBtn');
  if (calRangeStart) {
    if (hint) hint.textContent = `Start: ${calRangeStart}. Now click the last day of the holiday (or the same day for one day).`;
    if (cancel) cancel.style.display = 'inline-block';
  } else {
    if (hint) hint.textContent = 'Click a day to add a holiday — or click a start day then an end day for a range.';
    if (cancel) cancel.style.display = 'none';
  }
}
function renderCalendar() {
  const label = document.getElementById('calLabel');
  const grid = document.getElementById('calGrid');
  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  label.textContent = monthNames[calMonth] + ' ' + calYear;
  const first = new Date(calYear, calMonth, 1);
  const startDow = (first.getDay() + 6) % 7; // Mon = 0
  const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
  const pad = n => String(n).padStart(2, '0');
  let html = '';
  for (let i = 0; i < startDow; i++) html += '<div></div>';
  for (let day = 1; day <= daysInMonth; day++) {
    const ds = `${calYear}-${pad(calMonth + 1)}-${pad(day)}`;
    const isHol = calHolidays[ds];
    const isStart = (ds === calRangeStart);
    let box = 'background:#ffffff;border:1px solid #e5e7eb;';
    if (isHol) box = 'background:#FEE2E2;border:1px solid #FCA5A5;';
    if (isStart) box = 'background:#DBEAFE;border:2px solid #2563EB;';
    const nameHtml = isHol ? `<div style="font-size:9px;color:#b91c1c;line-height:1.1;margin-top:2px;overflow:hidden;">${String(isHol).replace(/</g,'&lt;')}</div>` : '';
    const t = isHol ? 'Click to remove this holiday' : (calRangeStart ? 'Click to set as the end day' : 'Click to add a holiday');
    html += `<div title="${t}" onclick="calDayClick('${ds}')" onmouseover="this.style.filter='brightness(0.97)'" onmouseout="this.style.filter=''" style="min-height:48px;border-radius:8px;padding:5px 6px;cursor:pointer;${box}">
      <div style="font-size:12px;font-weight:600;color:${isHol ? '#b91c1c' : (isStart ? '#1D4ED8' : '#374151')}">${day}</div>${nameHtml}
    </div>`;
  }
  grid.innerHTML = html;
  updateCalHint();
}
async function calDayClick(ds) {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  // Clicking an existing holiday always means "remove it".
  if (calHolidays[ds]) {
    calRangeStart = null;
    if (!confirm(`Remove holiday "${calHolidays[ds]}" on ${ds}?`)) { renderCalendar(); return; }
    try {
      const res = await fetch('/holidays/list?year=' + calYear);
      const json = await res.json();
      const h = (json.data || []).find(x => x.holiday_date === ds);
      if (!h) { loadCalendarTab(); return; }
      const del = await fetch('/holidays/' + h.id, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf } });
      const dj = await del.json();
      if (dj.success) loadCalendarTab(); else alert(dj.message || 'Could not remove the holiday.');
    } catch (e) { alert('Could not remove the holiday.'); }
    return;
  }
  // First click sets the range start; second click sets the end. No date typing.
  if (!calRangeStart) {
    calRangeStart = ds;
    renderCalendar();
    return;
  }
  const from = calRangeStart <= ds ? calRangeStart : ds;
  const to = calRangeStart <= ds ? ds : calRangeStart;
  calRangeStart = null;
  const label = (from === to) ? from : `${from} → ${to}`;
  const name = prompt(`Holiday name for ${label}:`);
  renderCalendar();
  if (!name) return;
  try {
    const res = await fetch('/holidays', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify({ holiday_date: from, holiday_end_date: (from === to ? null : to), holiday_name: name })
    });
    const json = await res.json();
    if (json.success) loadCalendarTab(); else alert(json.message || 'Could not add the holiday.');
  } catch (e) { alert('Could not add the holiday.'); }
}

// ── Apply-leave modal (manager on-behalf, auto-approved) ─────────────────────
function openApplyLeave() {
  const sel = document.getElementById('leaveUser');
  const seen = {};
  const opts = (allAttendanceData || [])
    .filter(u => u.user_id && !seen[u.user_id] && (seen[u.user_id] = true))
    .sort((a, b) => String(a.fullname || '').localeCompare(String(b.fullname || '')))
    .map(u => `<option value="${u.user_id}">${(u.fullname || ('#' + u.user_id))}</option>`).join('');
  sel.innerHTML = opts || '<option value="">No users loaded — load a date first</option>';
  const today = (document.getElementById('tableDate') || {}).value || new Date().toISOString().split('T')[0];
  document.getElementById('leaveFrom').value = today;
  document.getElementById('leaveTo').value = today;
  document.getElementById('leaveTo').min = today;
  document.getElementById('leaveNote').value = '';
  document.getElementById('leaveType').value = 'planned'; // default every open
  document.getElementById('applyLeaveModal').style.display = 'flex';
  loadLeaveBalanceChip();
}
function closeApplyLeave() { document.getElementById('applyLeaveModal').style.display = 'none'; }

// Show the chosen rider's current leave balance in the modal + a "give extra" link.
async function loadLeaveBalanceChip() {
  const chip = document.getElementById('leaveBalanceChip');
  const uid = document.getElementById('leaveUser').value;
  if (!chip) return;
  // Reset the history drawer whenever the selected rider changes.
  const hbox = document.getElementById('leaveHistoryBox');
  if (hbox) { hbox.style.display = 'none'; hbox.innerHTML = ''; hbox.dataset.uid = ''; }
  if (!uid) { chip.innerHTML = ''; return; }
  chip.innerHTML = '<span style="color:#9ca3af;">Loading balance…</span>';
  try {
    const res = await fetch('/attendance/leave-balance?user_id=' + uid);
    const j = await res.json();
    if (!j.success) { chip.innerHTML = ''; return; }
    const b = j.balance;
    const remColor = b.remaining <= 0 ? '#dc2626' : (b.remaining <= 2 ? '#d97706' : '#15803d');
    // Where the extra/missing leaves came from — segregated so a grown quota isn't a mystery.
    let breakdown = '';
    if (b.earned_overtime > 0) breakdown += `<span style="color:#6d28d9;">+${b.earned_overtime} overtime</span>`;
    if (b.late_penalties < 0) breakdown += `<span style="color:#c2410c;">${b.late_penalties} late</span>`;
    if (b.manual_adjust && b.manual_adjust != 0) breakdown += `<span style="color:#6b7280;">${b.manual_adjust > 0 ? '+' : ''}${b.manual_adjust} adj</span>`;
    chip.innerHTML =
      `<span style="font-weight:600;color:${remColor};">${b.remaining} of ${b.effective_quota} leaves left</span>` +
      `<span style="color:#9ca3af;">· same-day used ${b.sameday_used}/${b.sameday_cap}</span>` +
      breakdown +
      `<button type="button" onclick="toggleLeaveHistory(${uid})" style="background:none;border:none;color:#2563eb;cursor:pointer;text-decoration:underline;font-size:12px;padding:0;">history ›</button>` +
      `<button type="button" onclick="grantExtraLeave(${uid})" style="background:none;border:none;color:#2563eb;cursor:pointer;text-decoration:underline;font-size:12px;padding:0;">＋ give extra days</button>`;
  } catch (e) { chip.innerHTML = ''; }
}

// Dated, attributed leave adjustments (overtime bonus / late penalty / manual) — who/when/why.
async function toggleLeaveHistory(uid) {
  const box = document.getElementById('leaveHistoryBox');
  if (!box) return;
  if (box.style.display !== 'none' && box.dataset.uid === String(uid)) { box.style.display = 'none'; return; }
  const escLh = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  box.dataset.uid = String(uid);
  box.style.display = 'block';
  box.innerHTML = '<span style="color:#9ca3af;">Loading…</span>';
  try {
    const res = await fetch('/attendance/date-breakdown?type=leave_grants&user_id=' + uid);
    const j = await res.json();
    if (!j.success || !j.dates || !j.dates.length) {
      box.innerHTML = '<span style="color:#9ca3af;">No overtime / late / manual leave adjustments this cycle.</span>';
      return;
    }
    box.innerHTML = j.dates.map(d => {
      const dt = d.date ? new Date(d.date + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
      return `<div style="display:flex;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px solid #f3f4f6;"><span>${escLh(d.label || '')}</span><span style="color:#9ca3af;white-space:nowrap;">${dt}</span></div>`;
    }).join('');
  } catch (e) {
    box.innerHTML = '<span style="color:#dc2626;">Could not load history.</span>';
  }
}

async function grantExtraLeave(uid) {
  const raw = prompt('How many extra leave days to give? (use a negative number to deduct)');
  if (raw === null) return;
  const days = parseFloat(raw);
  if (!days || isNaN(days)) { alert('Enter a number of days.'); return; }
  const reason = prompt('Reason (optional):') || '';
  try {
    const res = await fetch('/attendance/grant-leave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify({ user_id: uid, days: days, reason: reason })
    });
    const j = await res.json();
    alert(j.message || (j.success ? 'Done.' : 'Could not save.'));
    if (j.success) loadLeaveBalanceChip();
  } catch (e) { alert('Could not save the grant.'); }
}
function syncLeaveToMin() {
  const f = document.getElementById('leaveFrom').value;
  const to = document.getElementById('leaveTo');
  to.min = f || '';
  if (to.value && f && to.value < f) to.value = f;
}
async function submitApplyLeave(overrideQuota) {
  const btn = document.getElementById('leaveSubmitBtn');
  const payload = {
    user_id: document.getElementById('leaveUser').value,
    leave_start_date: document.getElementById('leaveFrom').value,
    leave_end_date: document.getElementById('leaveTo').value,
    note: document.getElementById('leaveNote').value,
    leave_type: (document.getElementById('leaveType') || {}).value || 'planned',
    override_quota: overrideQuota ? 1 : 0
  };
  if (!payload.user_id || !payload.leave_start_date || !payload.leave_end_date) { alert('Pick a person and both dates.'); return; }
  btn.disabled = true; btn.textContent = 'Approving…';
  try {
    const res = await fetch('/attendance/apply-leave', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (json.success) {
      closeApplyLeave();
      alert(json.message || 'Leave approved.');
      if (typeof loadAttendanceForDate === 'function') loadAttendanceForDate();
    } else if (json.needs_confirm) {
      // Over quota — manager may still grant. Re-submit with override on confirm.
      btn.disabled = false; btn.textContent = 'Approve leave';
      if (confirm(json.message)) { submitApplyLeave(true); }
    } else {
      alert(json.message || 'Could not apply the leave.');
    }
  } catch (e) {
    alert('Could not apply the leave. Please try again.');
  } finally {
    if (!btn.disabled) { btn.textContent = 'Approve leave'; }
    else { btn.disabled = false; btn.textContent = 'Approve leave'; }
  }
}

// Summary strip above the table: counts of riders needing a meter reading and
// company-bike riders flagged for overnight use today. Hidden when all clear.
// Effective overnight grace for a row: per-rider override (sent on the row) → global default.
function rowGrace(r) {
  const cfg = window.attConfig || {};
  if (r && r.overnight_grace_km != null && r.overnight_grace_km !== '') return Number(r.overnight_grace_km);
  return Number(cfg.overnight_grace_km != null ? cfg.overnight_grace_km : 30);
}
function renderMeterAttention(data) {
  const el = document.getElementById('meterAttention');
  if (!el) return;
  let missing = 0, overnight = 0;
  (data || []).forEach(r => {
    if (!r.login_time) return;
    const isRider = r.role_name && String(r.role_name).toLowerCase().includes('rider');
    const hasPair = r.meter_start != null && r.meter_end != null;
    if (isRider && r.logout_time && !hasPair) missing++;
    if (Number(r.company_bike) === 1 && r.prev_meter_end != null && r.meter_start != null
        && (Number(r.meter_start) - Number(r.prev_meter_end)) > rowGrace(r)) overnight++;
  });
  const chips = [];
  if (missing > 0) chips.push(`<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;">⛽ ${missing} rider${missing>1?'s':''} without a meter reading</span>`);
  if (overnight > 0) chips.push(`<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-size:12px;font-weight:700;background:#FEE2E2;color:#B91C1C;border:1px solid #FCA5A5;">🏍 ${overnight} overnight bike flag${overnight>1?'s':''}</span>`);
  el.innerHTML = chips.join('');
  el.classList.toggle('hidden', chips.length === 0);
}

// Top alert banner for company-bike meter issues on the selected day (info only, no actions):
//   • overnight grace exceeded (start meter − yesterday's end > grace), and/or
//   • no meter reading recorded (checked in with no start, or out with no end).
// Fully guarded — needs company_bike; non-bike / non-came riders never appear and never error.
function renderGraceBreachBanner(data) {
  const card = document.getElementById('graceBreachCard');
  const body = document.getElementById('graceBreachBody');
  if (!card || !body) return;
  const has = v => v != null && v !== '';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const rows = [];
  (data || []).forEach(r => {
    if (Number(r.company_bike) !== 1) return;
    const checkedIn = has(r.login_time), checkedOut = has(r.logout_time);
    if (!checkedIn && !checkedOut) return; // didn't work today
    const chips = [];
    // overnight grace exceeded
    if (has(r.meter_start) && has(r.prev_meter_end) && (Number(r.meter_start) - Number(r.prev_meter_end)) > rowGrace(r)) {
      const over = Math.round(Number(r.meter_start) - Number(r.prev_meter_end));
      chips.push('<span style="font-size:12px;font-weight:700;color:#B91C1C;background:#FEE2E2;border:1px solid #FCA5A5;border-radius:6px;padding:1px 7px;">🏍 ' + over + ' km overnight</span>' +
        '<span style="font-size:11.5px;color:#6B7280;margin-left:6px;">start ' + esc(r.meter_start) + ' − prev end ' + esc(r.prev_meter_end) + (r.prev_meter_date ? ' (' + esc(r.prev_meter_date) + ')' : '') + ' · grace ' + Math.round(rowGrace(r)) + ' km</span>');
    }
    // no meter reading recorded
    const missStart = checkedIn && !has(r.meter_start);
    const missEnd = checkedOut && !has(r.meter_end);
    if (missStart || missEnd) {
      const which = (missStart && missEnd) ? 'start & end' : (missStart ? 'start' : 'end');
      chips.push('<span style="font-size:12px;font-weight:700;color:#92400E;background:#FEF3C7;border:1px solid #FDE68A;border-radius:6px;padding:1px 7px;">⛽ no meter reading (' + which + ' missing)</span>');
    }
    if (chips.length) rows.push({name: r.fullname || r.name || 'Rider', chips});
  });

  if (!rows.length) { card.style.display = 'none'; return; }
  document.getElementById('graceBreachCount').textContent = rows.length;
  body.innerHTML = rows.map(r =>
    '<div style="display:flex;align-items:baseline;gap:8px;padding:6px 0;border-top:1px solid #FECACA;flex-wrap:wrap;">' +
      '<span style="font-size:13px;font-weight:600;color:#111827;min-width:130px;">' + esc(r.name) + '</span>' +
      '<span style="display:flex;flex-direction:column;gap:3px;">' + r.chips.map(c => '<span>' + c + '</span>').join('') + '</span>' +
    '</div>'
  ).join('');
  card.style.display = 'block';
}

// Where the rider checked out (manager view). Gray = office, green = at a customer,
// amber ⚠ = that delivery point was away from the address's saved pin, amber = elsewhere.
function checkoutChip(info) {
  if (!info || !info.status) return '';
  let bg, fg, bd, text, title = '';
  if (info.status === 'office') {
    bg = '#F3F4F6'; fg = '#4B5563'; bd = '#E5E7EB'; text = '⇢ ' + info.label;
  } else if (info.status === 'delivery') {
    if (info.pin_away) {
      bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A';
      text = '⚠ ' + info.label + (info.pin_distance_m != null ? ' · ' + info.pin_distance_m + 'm off pin' : '');
      title = 'Checked out at the delivery point, ' + (info.pin_distance_m || '?') + ' m from the saved address pin';
    } else {
      bg = '#DCFCE7'; fg = '#15803D'; bd = '#86EFAC'; text = '⇢ ' + info.label;
    }
  } else {
    bg = '#FEF3C7'; fg = '#92400E'; bd = '#FDE68A'; text = '⇢ ' + info.label;
  }
  const esc = s => String(s).replace(/</g, '&lt;').replace(/"/g, '&quot;');
  return `<div style="margin-top:3px;"><span title="${esc(title)}" style="display:inline-flex;align-items:center;font-size:11px;font-weight:600;color:${fg};background:${bg};border:1px solid ${bd};border-radius:5px;padding:1px 6px;white-space:normal;">${esc(text)}</span></div>`;
}

function getMeterFlags(record) {
  if (!record.login_time) return '';
  const cfg = window.attConfig || {meter_gps_warn_km: 10, overnight_grace_km: 30};
  const chips = [];
  const isRider = record.role_name && String(record.role_name).toLowerCase().includes('rider');
  const hasMeterPair = record.meter_start != null && record.meter_end != null;

  const chip = (bg, fg, bd, txt, title) => `<span style="display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:5px;font-size:11px;font-weight:600;background:${bg};color:${fg};border:1px solid ${bd};" title="${title}">${txt}</span>`;

  if (isRider && record.logout_time && !hasMeterPair) {
    chips.push(chip('#FEF3C7', '#92400E', '#FDE68A', '⛽ no meter', 'Checked out without a meter reading'));
  }

  const meter = record.meter_distance;
  const compare = (record.road_distance_km != null) ? record.road_distance_km
                : (record.gps_distance != null ? record.gps_distance : null);
  if (meter != null && compare != null) {
    const diff = Math.abs(Number(meter) - Number(compare));
    if (diff > Number(cfg.meter_gps_warn_km)) {
      chips.push(chip('#FEF9C3', '#854D0E', '#FDE047', `⚠ ${Math.round(diff)} km off`, `Meter ${meter} km vs tracked ${Math.round(compare)} km — differ by ${Math.round(diff)} km; reading may be inaccurate`));
    }
  }

  if (Number(record.company_bike) === 1 && record.prev_meter_end != null && record.meter_start != null) {
    const overnight = Number(record.meter_start) - Number(record.prev_meter_end);
    const grace = rowGrace(record);
    if (overnight > grace) {
      chips.push(chip('#FEE2E2', '#B91C1C', '#FCA5A5', `🏍 +${Math.round(overnight)} km overnight`, `Start meter ${record.meter_start} − yesterday's end ${record.prev_meter_end} = ${Math.round(overnight)} km overnight (grace ${grace} km)`));
    }
  }

  if (!chips.length) return '';
  return `<div class="flex flex-wrap gap-1 mt-1">${chips.join('')}</div>`;
}

/**
 * ⭐ Get distance badge HTML showing meter, road distance (primary), and GPS distance
 */
// One-line meter cell (the approved mockup): "127 km 📷📷 details".
// The meter reading is what the manager scans daily; road distance, GPS trail,
// coverage %, No-GPS state etc. all live behind the "details" link (the GPS-audit
// modal already renders the full meter/road/GPS comparison + trail stats).
function getDistanceBadge(record) {
  if (!record.login_time) {
    return '<span style="color:#D1D5DB;font-size:12px;">–</span>';
  }
  const hasMeter = record.meter_distance !== null && record.meter_distance !== undefined;
  const hasRoad = record.road_distance_km !== null && record.road_distance_km !== undefined;
  const hasGps = record.gps_distance !== null && record.gps_distance !== undefined;
  const gpsReadings = record.gps_readings_count || 0;

  const details = `<button type="button"
    onclick="showGpsAudit(${record.user_id}, '${(record.fullname || '').replace(/'/g, "\\'")}', '${record.attendance_date}')"
    style="background:none;border:none;padding:0;margin-left:6px;font-size:11px;font-weight:600;color:#2563EB;cursor:pointer;text-decoration:underline;vertical-align:middle;"
    title="Road + GPS distance and the tracking audit">details</button>`;

  if (hasMeter) {
    return `<span style="font-size:13px;font-weight:600;color:#111827;vertical-align:middle;">${record.meter_distance} km</span>${getMeterPhotoIcons(record)}${details}`;
  }
  if (hasRoad) {
    // No meter reading — fall back to the tracked road distance, clearly marked.
    return `<span title="Tracked road distance (no meter reading)" style="font-size:12px;color:#6B7280;vertical-align:middle;">🛰 ${record.road_distance_km} km</span>${details}`;
  }
  if (hasGps) {
    return `<span title="GPS straight-line distance (no meter reading)" style="font-size:12px;color:#6B7280;vertical-align:middle;">🛰 ${record.gps_distance} km</span>${details}`;
  }
  if (!record.logout_time && gpsReadings > 0) {
    // Day still running — distance settles at checkout.
    return `<span style="font-size:11px;color:#9CA3AF;vertical-align:middle;">tracking…</span>${details}`;
  }
  if (record.logout_time) {
    // Day ended with nothing recorded — audit link only.
    return `<span style="font-size:12px;color:#D1D5DB;vertical-align:middle;">–</span>${details}`;
  }
  return '<span style="color:#D1D5DB;font-size:12px;">–</span>';
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
      
      // Start-only shifts (no end) never count as overtime — don't invent 17:00.
      if (r.logout_time && r.shift_end && r.logout_time > r.shift_end) {
        overtime++;
      }
    }
    // Absent ONLY on a genuine working day. A no-login day that's a weekly off, a
    // public holiday, or before the rider's hire date is not an absence.
    else if ((r.day_kind || 'working') === 'working') {
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
async function showEmployeeDetails(userId, fullname, fromDate, startDate, endDate, rangeLabel) {
  console.log('showEmployeeDetails called:', { userId, fullname, fromDate, startDate, endDate });
  
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
    // Fetch employee details with order stats. An explicit start/end (Month tab) scopes the
    // whole detail to the selected month; without it the endpoint uses the rolling 30 days.
    let detailUrl = `/attendance/employee-details?user_id=${userId}&from_date=${fromDate}`;
    if (startDate && endDate) detailUrl += `&start_date=${startDate}&end_date=${endDate}`;
    const res = await fetch(detailUrl, {
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
    document.getElementById('detailsDateRange').textContent = dateRange + ' (' + (rangeLabel || 'Last 30 Days') + ')';
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
      body.innerHTML = '<tr><td colspan="12" style="padding: 20px; text-align: center; color: #6b7280;">No records found for this period</td></tr>';
      return;
    }
    
    body.innerHTML = records.map((day, index) => {
      const date = new Date(day.attendance_date + 'T00:00:00');
      const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
      const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
      const rowBg = index % 2 === 0 ? '#f9fafb' : 'white';
      
      // Prefer the backend-provided status (covers the filled-in absent/leave rows);
      // fall back to the login/leave heuristic for older responses.
      const isOnLeave = day.leave_request_id && (day.leave_status === 'approved' || day.leave_status === 'pending');
      const isPresent = day.login_time && day.login_time !== '-';
      const isLate = isPresent && day.late_minutes > 0;
      const st = day.status || (isOnLeave ? 'on_leave' : (isLate ? 'late' : (isPresent ? 'present' : 'absent')));
      const statusMap = {
        present:  ['Present',  '#dcfce7', '#166534'],
        late:     ['Late',     '#fee2e2', '#991b1b'],
        absent:   ['Absent',   '#fef2f2', '#991b1b'],
        on_leave: ['On Leave', '#dbeafe', '#1e40af'],
        off:      ['Off',      '#f3f4f6', '#6b7280'],
        holiday:  ['Holiday',  '#f3f4f6', '#6b7280'],
        not_needed: ['Not needed', '#e0e7ff', '#3730a3'],
      };
      const [status, statusBg, statusColor] = statusMap[st] || statusMap.absent;
      
      const loginTime = day.login_time || '-';
      const logoutTime = day.logout_time || '-';
      const hours = day.hours_worked ? day.hours_worked.toFixed(1) + 'h' : '-';
      const lateBy = day.late_minutes > 0 ? day.late_minutes + ' min' : '-';
      const overtime = day.overtime_minutes > 0 ? day.overtime_minutes + ' min' : '-';
      
      const ordersDelivered = day.total_orders_delivered || 0;
      const firstDelivery = day.first_delivery_time || '-';
      const lastDelivery = day.last_delivery_time || '-';
      
      // Meter values
      const meterStart = day.meter_start || null;
      const meterEnd = day.meter_end || null;
      const meterDistance = (meterStart && meterEnd) ? Math.abs(parseInt(meterEnd) - parseInt(meterStart)) : null;
      
      // Find previous day's meter_end to calculate gap
      // Records are sorted by date DESC, so "previous day" is the NEXT item in array
      let prevMeterEnd = null;
      let meterGap = null;
      if (index < records.length - 1) {
        const nextRecord = records[index + 1];
        if (nextRecord && nextRecord.meter_end) {
          prevMeterEnd = nextRecord.meter_end;
          if (meterStart) {
            meterGap = parseInt(meterStart) - parseInt(prevMeterEnd);
          }
        }
      }
      
      // Build meter display with values and gap indicator
      let meterValuesHtml = '-';
      if (meterStart || meterEnd) {
        meterValuesHtml = `<div style="font-size: 11px; text-align: center;">`;
        if (meterStart && meterEnd) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} → ${meterEnd}</div>`;
          meterValuesHtml += `<div style="color: #16a34a; font-weight: 600;">${meterDistance} km</div>`;
        } else if (meterStart) {
          meterValuesHtml += `<div style="color: #374151;">${meterStart} →</div>`;
          meterValuesHtml += `<div style="color: #9ca3af;">No end</div>`;
        } else {
          meterValuesHtml += `<div style="color: #9ca3af;">→ ${meterEnd}</div>`;
        }
        // Show gap indicator if previous day's meter_end exists
        if (prevMeterEnd && meterStart) {
          if (meterGap === 0) {
            meterValuesHtml += `<div style="font-size: 9px; color: #16a34a; margin-top: 2px;">✓ matches prev</div>`;
          } else if (Math.abs(meterGap) <= 25) {
            // Normal gap up to 25km (personal use, minor discrepancies)
            meterValuesHtml += `<div style="font-size: 9px; color: #6b7280; margin-top: 2px;">${meterGap > 0 ? '+' : ''}${meterGap} km</div>`;
          } else {
            // Warning for gaps > 25km
            meterValuesHtml += `<div style="font-size: 9px; color: #dc2626; margin-top: 2px; font-weight: 600;">⚠️ ${meterGap > 0 ? '+' : ''}${meterGap} km gap</div>`;
          }
        }
        meterValuesHtml += `</div>`;
      }
      
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
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureStartPath}')" style="background: #dbeafe; color: #1e40af; border: none; padding: 2px 6px; border-radius: 4px; cursor: pointer; font-size: 10px;">📷</button>`;
        }
        if (hasPictureEnd) {
          meterPicsHtml += `<button onclick="viewMeterPicturePath('${pictureEndPath}')" style="background: #fef3c7; color: #92400e; border: none; padding: 2px 6px; border-radius: 4px; cursor: pointer; font-size: 10px;">📷</button>`;
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
          <td style="padding: 8px 12px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">${meterValuesHtml}</td>
          <td style="padding: 8px 12px; font-size: 13px; text-align: center; border-bottom: 1px solid #e5e7eb;">${meterPicsHtml}</td>
        </tr>
      `;
    }).join('');
    
    console.log('Employee details loaded successfully');
    
  } catch(e) {
    console.error('Error loading employee details:', e);
    body.innerHTML = `<tr><td colspan="12" style="padding: 20px; text-align: center; color: #dc2626;">Error loading data: ${e.message}</td></tr>`;
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
      deliveryRiderChanges = {};
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
  deliveryRiderChanges = {};
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
    const curVis = visibilityChanges.hasOwnProperty(user.id) ? visibilityChanges[user.id] : !!user.is_visible;
    const curRider = deliveryRiderChanges.hasOwnProperty(user.id) ? deliveryRiderChanges[user.id] : !!Number(user.is_delivery_rider);

    return `
      <tr style="background: ${rowBg};" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='${rowBg}'">
        <td style="padding: 12px; font-weight: 500; color: #111827;">${user.fullname}</td>
        <td style="padding: 12px; color: #6b7280; font-size: 13px;">${user.role_name || 'N/A'}</td>
        <td style="padding: 12px; text-align:center;">
          <input type="checkbox" class="user-visibility-checkbox" data-user-id="${user.id}"
            ${curVis ? 'checked' : ''} onchange="toggleUserVisibility(${user.id}, this.checked)">
        </td>
        <td style="padding: 12px; text-align:center;">
          <input type="checkbox" data-user-id="${user.id}"
            ${curRider ? 'checked' : ''} onchange="toggleDeliveryRider(${user.id}, this.checked)">
        </td>
      </tr>
    `;
  }).join('');
}

function toggleDeliveryRider(userId, isRider) {
  deliveryRiderChanges[userId] = isRider;
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

  if (!selectAllCheckbox) return; // select-all removed (two-column layout)
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
  const visIds = Object.keys(visibilityChanges);
  const riderIds = Object.keys(deliveryRiderChanges);
  if (visIds.length === 0 && riderIds.length === 0) {
    alert('No changes to save');
    closeCustomizeUserList();
    return;
  }

  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  try {
    const promises = [];
    // "Show in Attendance" changes
    visIds.forEach(userId => {
      promises.push(fetch('/attendance/update-visibility', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({
          user_id: parseInt(userId),
          is_visible: visibilityChanges[userId] ? 1 : 0,
          notes: visibilityChanges[userId] ? null : 'Hidden from attendance tracking'
        })
      }));
    });
    // "Delivery Rider" changes (rider-assign list)
    riderIds.forEach(userId => {
      promises.push(fetch('/attendance/update-delivery-rider', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ user_id: parseInt(userId), is_rider: deliveryRiderChanges[userId] ? 1 : 0 })
      }));
    });

    await Promise.all(promises);

    alert('✓ Saved. Attendance visibility and the delivery-rider list are updated.');
    closeCustomizeUserList();
    loadAttendanceForDate();
  } catch(e) {
    console.error('Error saving user list changes:', e);
    alert('Failed to save changes. Please try again.');
  }
}
window.toggleDeliveryRider = toggleDeliveryRider;

// Make customize user list functions globally accessible
window.openCustomizeUserList = openCustomizeUserList;
window.closeCustomizeUserList = closeCustomizeUserList;
window.toggleUserVisibility = toggleUserVisibility;
window.toggleSelectAllUsersVis = toggleSelectAllUsersVis;
window.saveUserVisibilityChanges = saveUserVisibilityChanges;

// ==================== GPS Audit Functions ====================

/**
 * ⭐ Show GPS Audit Modal for a user on a specific date
 */
async function showGpsAudit(userId, userName, date) {
  const modal = document.getElementById('gpsAuditModal');
  const content = document.getElementById('gpsAuditContent');
  const title = document.getElementById('gpsAuditTitle');
  const subtitle = document.getElementById('gpsAuditSubtitle');
  
  // Show modal with loading state
  modal.style.display = 'flex';
  title.textContent = `GPS Audit: ${userName}`;
  subtitle.textContent = `Loading data for ${date}...`;
  content.innerHTML = `
    <div style="text-align: center; padding: 40px; color: #6b7280;">
      <div style="font-size: 24px; margin-bottom: 8px;">⏳</div>
      Loading GPS data...
    </div>
  `;
  
  try {
    const res = await fetch(`/attendance/gps-audit?user_id=${userId}&date=${date}`);
    const json = await res.json();
    
    if (!json.success) {
      content.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #dc2626;">
          <div style="font-size: 24px; margin-bottom: 8px;">❌</div>
          ${json.message || 'Failed to load GPS data'}
        </div>
      `;
      return;
    }
    
    // Update subtitle
    subtitle.textContent = `${date}`;
    
    // Render audit results
    if (!json.has_attendance) {
      content.innerHTML = `
        <div style="text-align: center; padding: 40px; color: #6b7280;">
          <div style="font-size: 48px; margin-bottom: 12px;">📅</div>
          <p style="font-size: 16px; margin: 0;">No attendance record for this date</p>
        </div>
      `;
      return;
    }
    
    const gps = json.gps_analysis;
    const dist = json.distance;
    const audit = json.audit;
    const att = json.attendance;
    
    // Determine status color
    let statusColor = '#22c55e'; // green
    let statusBg = '#dcfce7';
    let statusText = '✓ Good';
    
    if (audit.status === 'warning') {
      statusColor = '#f59e0b';
      statusBg = '#fef3c7';
      statusText = '⚠️ Warning';
    } else if (audit.status === 'critical') {
      statusColor = '#ef4444';
      statusBg = '#fee2e2';
      statusText = '❌ Critical';
    }
    
    content.innerHTML = `
      <!-- Audit Status Banner -->
      <div style="padding: 12px 16px; border-radius: 10px; background: ${statusBg}; color: ${statusColor}; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between;">
        <span>${statusText}</span>
        ${audit.notes.length > 0 ? `<span style="font-weight: 400; font-size: 13px;">${audit.notes.join(' • ')}</span>` : ''}
      </div>
      
      <!-- Stats Grid -->
      <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;">
        <div class="gps-audit-stat">
          <div class="value" style="color: ${gps.coverage_percent >= 80 ? '#22c55e' : gps.coverage_percent >= 50 ? '#f59e0b' : '#ef4444'}">
            ${gps.coverage_percent}%
          </div>
          <div class="label">GPS Coverage</div>
        </div>
        <div class="gps-audit-stat">
          <div class="value">${gps.actual_readings}</div>
          <div class="label">Readings</div>
        </div>
        <div class="gps-audit-stat">
          <div class="value">${gps.expected_readings}</div>
          <div class="label">Expected</div>
        </div>
        <div class="gps-audit-stat">
          <div class="value">${gps.gaps_count}</div>
          <div class="label">Gaps Found</div>
        </div>
      </div>
      
      <!-- Distance Comparison - 3 columns -->
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px;">
        <div style="padding: 12px 16px; background: #eff6ff; border-radius: 8px;">
          <div style="font-size: 11px; color: #1e40af; text-transform: uppercase; font-weight: 600;">🛣️ Meter Reading</div>
          <div style="font-size: 24px; font-weight: 700; color: #1e40af; margin-top: 4px;">
            ${dist.meter_km !== null ? dist.meter_km + ' km' : 'No data'}
          </div>
          ${dist.meter_start && dist.meter_end ? `
            <div style="font-size: 10px; color: #1e40af; margin-top: 4px; background: #dbeafe; padding: 4px 8px; border-radius: 4px;">
              ${dist.meter_start} → ${dist.meter_end}
            </div>
          ` : ''}
          ${dist.prev_meter_end ? `
            <div style="font-size: 10px; color: #6b7280; margin-top: 6px; padding-top: 6px; border-top: 1px dashed #cbd5e1;">
              ↩️ Prev End: <strong>${dist.prev_meter_end}</strong>
              ${dist.meter_gap !== null && dist.meter_gap !== 0 ? `
                <span style="margin-left: 6px; padding: 2px 6px; border-radius: 4px; font-weight: 600; ${Math.abs(dist.meter_gap) > 25 ? 'background: #fee2e2; color: #dc2626;' : 'background: #f3f4f6; color: #6b7280;'}">
                  ${dist.meter_gap > 0 ? '+' : ''}${dist.meter_gap} km ${Math.abs(dist.meter_gap) > 25 ? '⚠️' : ''}
                </span>
              ` : dist.meter_gap === 0 ? '<span style="margin-left: 6px; color: #16a34a;">✓ matches</span>' : ''}
            </div>
          ` : ''}
        </div>
        <div style="padding: 12px 16px; background: #fef3c7; border-radius: 8px;">
          <div style="font-size: 11px; color: #92400e; text-transform: uppercase; font-weight: 600;">📍 GPS Straight-line</div>
          <div style="font-size: 24px; font-weight: 700; color: #92400e; margin-top: 4px;">
            ${dist.gps_straight_km !== null ? dist.gps_straight_km + ' km' : (gps.actual_readings < 2 ? 'Not enough data' : '0 km')}
          </div>
          <div style="font-size: 10px; color: #b45309; margin-top: 2px;">Point-to-point (filtered)</div>
        </div>
        <div style="padding: 12px 16px; background: ${dist.gps_road_km ? '#dcfce7' : '#f3f4f6'}; border-radius: 8px; ${dist.gps_road_km ? 'border: 2px solid #22c55e;' : ''}">
          <div style="font-size: 11px; color: ${dist.gps_road_km ? '#166534' : '#6b7280'}; text-transform: uppercase; font-weight: 600;">🚗 GPS Road Distance</div>
          <div style="font-size: 24px; font-weight: 700; color: ${dist.gps_road_km ? '#166534' : '#6b7280'}; margin-top: 4px;">
            ${dist.gps_road_km !== null ? dist.gps_road_km + ' km' : 'N/A'}
          </div>
          <div style="font-size: 10px; color: ${dist.gps_road_km ? '#16a34a' : '#9ca3af'}; margin-top: 2px;">
            ${dist.road_source === 'openrouteservice' ? '✓ Via actual roads' : 
              dist.road_source === 'skipped_stationary' ? '⚡ Skipped (rider stationary)' :
              dist.road_source === 'unavailable' ? '⚠️ API unavailable' : 
              'Insufficient GPS movement'}
          </div>
        </div>
      </div>
      
      <!-- Distance Analysis Note -->
      ${(() => {
        // Check for suspicious patterns
        const hasMeter = dist.meter_km !== null && dist.meter_km > 0;
        const hasGps = dist.gps_straight_km !== null && dist.gps_straight_km > 0;
        const hasRoad = dist.gps_road_km !== null && dist.gps_road_km > 0;
        
        // Case 1: Meter shows distance but GPS shows no movement (suspicious)
        if (hasMeter && !hasGps) {
          return '<div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #fef2f2; color: #991b1b;">⚠️ <strong>Suspicious:</strong> Meter shows ' + dist.meter_km + ' km but GPS shows no movement. Possible causes: GPS not working properly, meter reading error, or discrepancy needs review.</div>';
        }
        
        // Case 2: Both meter and road distance available - compare them
        if (hasMeter && hasRoad) {
          const diff = Math.abs(dist.meter_km - dist.gps_road_km);
          const diffPercent = diff / Math.max(dist.meter_km, dist.gps_road_km) * 100;
          
          if (diffPercent <= 20) {
            return '<div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #dcfce7; color: #166534;">✓ Meter and GPS road distance match within 20%</div>';
          } else {
            return '<div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #fef2f2; color: #991b1b;">⚠️ Difference: ' + Math.round(diff) + ' km (' + Math.round(diffPercent) + '%) - Please verify</div>';
          }
        }
        
        // Case 3: GPS shows movement but no meter reading
        if (hasGps && !hasMeter) {
          return '<div style="padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; background: #fef3c7; color: #92400e;">ℹ️ No meter reading available. GPS shows approximately ' + (hasRoad ? dist.gps_road_km : dist.gps_straight_km) + ' km traveled.</div>';
        }
        
        return '';
      })()}
      
      <!-- Attendance Time -->
      <div style="display: flex; gap: 8px; margin-bottom: 20px; font-size: 13px; color: #6b7280;">
        <span>🕐 Login: <strong>${att.login_time}</strong></span>
        <span>•</span>
        <span>🕔 Logout: <strong>${att.logout_time || 'Ongoing'}</strong></span>
        <span>•</span>
        <span>⏱️ Working: <strong>${att.working_minutes} min</strong></span>
      </div>
      
      <!-- Gaps List with Smart Analysis -->
      <div style="margin-bottom: 12px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
          <h4 style="font-size: 14px; font-weight: 600; color: #111827; margin: 0;">
            ${gps.gaps_count > 0 ? `⚠️ ${gps.gaps_count} Gap(s) Detected` : '✓ No Significant Gaps'}
          </h4>
          ${gps.stationary_gaps_count > 0 ? `
            <span style="font-size: 11px; background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 6px;">
              ✓ ${gps.stationary_gaps_count} harmless (rider stationary)
            </span>
          ` : ''}
        </div>
        
        ${gps.effective_coverage_percent !== gps.coverage_percent ? `
          <div style="padding: 8px 12px; background: #f0fdf4; border-radius: 6px; margin-bottom: 12px; font-size: 12px; color: #166534;">
            📊 <strong>Effective Coverage: ${gps.effective_coverage_percent}%</strong> 
            (${gps.coverage_percent}% raw + ${gps.stationary_gap_minutes} min of harmless stationary gaps)
          </div>
        ` : ''}
        
        ${gps.gaps.length > 0 ? gps.gaps.map(gap => {
          // Stationary gaps are shown in green/blue (harmless)
          let gapClass = gap.is_stationary ? 'gps-gap-stationary' : 'gps-gap-info';
          if (!gap.is_stationary) {
            if (gap.duration_minutes >= 30) gapClass = 'gps-gap-critical';
            else if (gap.duration_minutes >= 15) gapClass = 'gps-gap-warning';
          }
          
          const gapStyle = gap.is_stationary 
            ? 'background: #dbeafe; border-left: 3px solid #3b82f6;'
            : '';
          
          return `
            <div class="gps-gap-item ${gapClass}" style="${gapStyle}">
              <div style="flex: 1;">
                <div style="font-weight: 600; font-size: 13px; color: #111827;">
                  ${gap.from} → ${gap.to}
                  ${gap.is_stationary ? '<span style="font-size: 10px; background: #22c55e; color: white; padding: 2px 6px; border-radius: 4px; margin-left: 8px;">✓ STATIONARY</span>' : ''}
                </div>
                <div style="font-size: 12px; color: ${gap.is_stationary ? '#3b82f6' : '#6b7280'};">
                  ${gap.description}
                </div>
              </div>
              <div style="font-weight: 700; font-size: 14px; color: ${gap.is_stationary ? '#3b82f6' : (gap.duration_minutes >= 30 ? '#ef4444' : gap.duration_minutes >= 15 ? '#f59e0b' : '#3b82f6')};">
                ${gap.duration_minutes} min
              </div>
            </div>
          `;
        }).join('') : `
          <div style="padding: 16px; background: #f0fdf4; border-radius: 8px; color: #166534; text-align: center;">
            ✓ GPS tracking was consistent throughout the day
          </div>
        `}
      </div>
      
      <!-- Reading Timeline Preview -->
      ${json.readings_preview && json.readings_preview.length > 0 ? `
        <div style="margin-top: 16px;">
          <h4 style="font-size: 14px; font-weight: 600; color: #111827; margin: 0 0 8px 0;">
            📊 Reading Times (first ${json.readings_preview.length})
          </h4>
          <div style="display: flex; flex-wrap: wrap; gap: 4px;">
            ${json.readings_preview.map(r => `
              <span style="font-size: 10px; background: #f3f4f6; color: #374151; padding: 2px 6px; border-radius: 4px;" title="Accuracy: ${r.accuracy}m, Battery: ${r.battery || 'N/A'}%">
                ${r.time}
              </span>
            `).join('')}
          </div>
        </div>
      ` : ''}
    `;
    
  } catch(e) {
    console.error('Error loading GPS audit:', e);
    content.innerHTML = `
      <div style="text-align: center; padding: 40px; color: #dc2626;">
        <div style="font-size: 24px; margin-bottom: 8px;">❌</div>
        Error loading GPS data: ${e.message}
      </div>
    `;
  }
}

function closeGpsAudit() {
  const modal = document.getElementById('gpsAuditModal');
  if (modal) {
    modal.style.display = 'none';
  }
}

// Make GPS audit functions globally accessible
window.showGpsAudit = showGpsAudit;
window.closeGpsAudit = closeGpsAudit;

// ========================================
// ⛽ Fuel Rate Groups Management
// ========================================
var fuelRateGroups = [];
var fuelAllRiders = [];

// ---- Attendance Rules (year cycle + meter thresholds) ----
async function openAttendanceRules() {
  var modal = document.getElementById('attRulesModal');
  modal.style.display = 'flex';
  updateRuleCycleHint();
  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/settings', { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken } });
    var data = await resp.json();
    if (data && data.success) {
      document.getElementById('ruleCycleStart').value = data.cycle_start || '';
      document.getElementById('ruleCycleEnd').value = data.cycle_end || '';
      document.getElementById('ruleGpsWarn').value = data.meter_gps_warn_km != null ? data.meter_gps_warn_km : 10;
      document.getElementById('ruleOvernight').value = data.overnight_grace_km != null ? data.overnight_grace_km : 30;
      document.getElementById('ruleLeaveTotal').value = data.leave_quota_total != null ? data.leave_quota_total : 10;
      document.getElementById('ruleSamedayCap').value = data.leave_sameday_cap != null ? data.leave_sameday_cap : 4;
      document.getElementById('ruleSamedayCutoff').value = data.leave_sameday_cutoff || '10:00';
      document.getElementById('ruleTargetHours').value = data.shift_target_hours != null ? data.shift_target_hours : 9;
      document.getElementById('ruleCheckoutEnabled').checked = (Number(data.checkout_rule_enabled) === 1);
      document.getElementById('ruleCheckoutWindow').value = data.checkout_window_mins != null ? data.checkout_window_mins : 15;
      document.getElementById('ruleCheckoutRadius').value = data.checkout_radius_m != null ? data.checkout_radius_m : 150;
      document.getElementById('ruleCheckinRequireLocation').checked = (Number(data.require_location) === 1);
      updateRuleCycleHint();
    }
  } catch(e) {
    console.error('Failed to load attendance settings:', e);
  }
  // live hint as the user edits the dates
  document.getElementById('ruleCycleStart').oninput = updateRuleCycleHint;
  document.getElementById('ruleCycleEnd').oninput = updateRuleCycleHint;
}

// Accordion — one rule section open at a time.
function toggleRuleSection(id) {
  var ids = ['secCycle', 'secMeter', 'secLeave', 'secOvertime', 'secCheckin', 'secCheckout'];
  ids.forEach(function (b) {
    var body = document.getElementById(b);
    var chev = document.getElementById(b + 'Chev');
    if (!body) return;
    var open = (b === id) && body.style.display === 'none';
    body.style.display = open ? 'block' : 'none';
    if (chev) chev.textContent = open ? '▾' : '▸';
  });
}

function closeAttendanceRules() {
  document.getElementById('attRulesModal').style.display = 'none';
}

function updateRuleCycleHint() {
  var s = document.getElementById('ruleCycleStart').value;
  var e = document.getElementById('ruleCycleEnd').value;
  var hint = document.getElementById('ruleCycleHint');
  if (!s || !e) { hint.textContent = ''; return; }
  var fmt = function(d) {
    var parts = d.split('-');
    if (parts.length !== 3) return d;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[parseInt(parts[1],10)-1] + ' ' + parseInt(parts[2],10) + ', ' + parts[0];
  };
  if (s > e) { hint.style.color = '#dc2626'; hint.textContent = '⚠ Cycle end must be on or after the start.'; return; }
  hint.style.color = '#2563eb';
  hint.textContent = 'Cycle: ' + fmt(s) + ' → ' + fmt(e);
}

async function saveAttendanceRules() {
  var s = document.getElementById('ruleCycleStart').value;
  var e = document.getElementById('ruleCycleEnd').value;
  if (s && e && s > e) { alert('Cycle end must be on or after the cycle start.'); return; }
  var btn = document.getElementById('ruleSaveBtn');
  var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({
        cycle_start: s,
        cycle_end: e,
        meter_gps_warn_km: document.getElementById('ruleGpsWarn').value,
        overnight_grace_km: document.getElementById('ruleOvernight').value,
        leave_quota_total: document.getElementById('ruleLeaveTotal').value,
        leave_sameday_cap: document.getElementById('ruleSamedayCap').value,
        leave_sameday_cutoff: document.getElementById('ruleSamedayCutoff').value,
        shift_target_hours: document.getElementById('ruleTargetHours').value,
        checkout_rule_enabled: document.getElementById('ruleCheckoutEnabled').checked ? 1 : 0,
        checkout_window_mins: document.getElementById('ruleCheckoutWindow').value,
        checkout_radius_m: document.getElementById('ruleCheckoutRadius').value,
        require_location: document.getElementById('ruleCheckinRequireLocation').checked ? 1 : 0
      })
    });
    var data = await resp.json();
    if (data && data.success) {
      closeAttendanceRules();
      if (typeof loadMonthTab === 'function') { try { loadMonthTab(); } catch(_){} }
      alert('Attendance rules saved.');
    } else {
      alert('Could not save: ' + (data.message || 'Unknown error'));
    }
  } catch(err) {
    console.error('saveAttendanceRules failed:', err);
    alert('Could not save the attendance rules.');
  }
  btn.disabled = false; btn.textContent = orig;
}

async function openFuelRateModal() {
  var modal = document.getElementById('fuelRateModal');
  modal.style.display = 'flex';
  document.getElementById('fuelRateLoading').style.display = 'block';
  document.getElementById('fuelRateContent').style.display = 'none';

  try {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/fuel-rate-groups', {
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
    });
    var data = await resp.json();
    if (data.success) {
      fuelRateGroups = data.rate_groups || [];
      fuelAllRiders = data.all_riders || [];
      renderFuelRateGroups();
    } else {
      alert('Failed to load fuel rate data: ' + (data.message || 'Unknown error'));
    }
  } catch(e) {
    console.error('Failed to load fuel rates:', e);
    alert('Failed to load fuel rate data');
  }

  document.getElementById('fuelRateLoading').style.display = 'none';
  document.getElementById('fuelRateContent').style.display = 'block';
}

function closeFuelRateModal() {
  document.getElementById('fuelRateModal').style.display = 'none';
}

function renderFuelRateGroups() {
  var container = document.getElementById('fuelRateGroupsList');
  if (fuelRateGroups.length === 0) {
    fuelRateGroups = [{id: null, name: 'Default', rate: 10, user_ids: '', users: [], _expanded: true}];
  }

  var html = '';
  fuelRateGroups.forEach(function(group, idx) {
    var userIds = group.user_ids ? group.user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    var isExpanded = group._expanded;

    html += '<div style="border: 1px solid ' + (isExpanded ? '#F97316' : '#E5E7EB') + '; border-radius: 10px; margin-bottom: 12px; overflow: hidden; background: #fff;">';
    html += '<div onclick="toggleFuelGroup(' + idx + ')" style="cursor: pointer; display: flex; align-items: center; padding: 12px 16px; background: ' + (isExpanded ? '#FFF7ED' : '#F9FAFB') + ';">';
    html += '<div style="flex:1;"><strong style="font-size: 15px; color: #1F2937;">' + (group.name || 'Group ' + (idx+1)) + '</strong>';
    html += '<div style="font-size: 12px; color: #6B7280; margin-top: 2px;">Rs. ' + group.rate + '/km · ' + userIds.length + ' rider' + (userIds.length !== 1 ? 's' : '') + '</div></div>';
    html += '<span style="font-size: 18px; color: #9CA3AF;">' + (isExpanded ? '▲' : '▼') + '</span></div>';

    if (isExpanded) {
      html += '<div style="padding: 16px;">';
      html += '<div style="margin-bottom: 12px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Group Name</label>';
      html += '<input type="text" id="fuelGroupName_' + idx + '" value="' + (group.name || '').replace(/"/g, '&quot;') + '" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 12px; font-size: 14px;" placeholder="e.g. Bike Riders"></div>';
      html += '<div style="margin-bottom: 12px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Rate (Rs/km)</label>';
      html += '<input type="number" step="0.01" min="0.01" id="fuelGroupRate_' + idx + '" value="' + group.rate + '" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 8px 12px; font-size: 14px;" placeholder="e.g. 12.5"></div>';

      html += '<div style="margin-bottom: 8px;"><label style="font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px;">Assign Riders (' + userIds.length + ' selected)</label>';
      html += '<input type="text" id="fuelRiderSearch_' + idx + '" oninput="filterFuelRiders(' + idx + ')" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 8px; padding: 6px 12px; font-size: 13px; margin-bottom: 8px;" placeholder="Search riders...">';
      html += '<div id="fuelRiderList_' + idx + '" style="max-height: 200px; overflow-y: auto; border: 1px solid #E5E7EB; border-radius: 8px; padding: 4px;">';

      fuelAllRiders.forEach(function(rider) {
        var riderId = String(rider.id);
        var isChecked = userIds.includes(riderId);
        var otherGroup = getFuelGroupForRider(riderId, idx);

        html += '<label style="display: flex; align-items: center; padding: 6px 8px; cursor: pointer; border-bottom: 1px solid #F3F4F6; gap: 8px;">';
        html += '<input type="checkbox" data-group="' + idx + '" data-rider="' + riderId + '" ' + (isChecked ? 'checked' : '') + ' onchange="handleFuelRiderToggle(' + idx + ',' + riderId + ', this.checked)" style="accent-color: #F97316; width: 16px; height: 16px;">';
        html += '<span style="flex: 1; font-size: 13px; color: ' + (otherGroup !== null ? '#9CA3AF' : '#1F2937') + ';" class="fuel-rider-name">' + rider.name + '</span>';
        if (otherGroup !== null) {
          html += '<span style="font-size: 10px; color: #F97316; font-weight: 600;">in ' + (fuelRateGroups[otherGroup]?.name || 'Group ' + (otherGroup + 1)) + '</span>';
        }
        html += '</label>';
      });

      html += '</div></div>';

      if (fuelRateGroups.length > 1) {
        html += '<button onclick="removeFuelGroup(' + idx + ')" style="margin-top: 8px; padding: 6px 14px; border-radius: 6px; background: #FEE2E2; color: #DC2626; border: none; font-size: 12px; font-weight: 600; cursor: pointer;">Remove Group</button>';
      }
      html += '</div>';
    }
    html += '</div>';
  });

  container.innerHTML = html;
}

function toggleFuelGroup(idx) {
  syncFuelGroupInputs();
  fuelRateGroups[idx]._expanded = !fuelRateGroups[idx]._expanded;
  renderFuelRateGroups();
}

function syncFuelGroupInputs() {
  fuelRateGroups.forEach(function(group, idx) {
    var nameEl = document.getElementById('fuelGroupName_' + idx);
    var rateEl = document.getElementById('fuelGroupRate_' + idx);
    if (nameEl) group.name = nameEl.value;
    if (rateEl) group.rate = parseFloat(rateEl.value) || group.rate;
  });
}

function addFuelGroup() {
  syncFuelGroupInputs();
  fuelRateGroups.forEach(function(g) { g._expanded = false; });
  fuelRateGroups.push({id: null, name: '', rate: 0, user_ids: '', users: [], _expanded: true});
  renderFuelRateGroups();
}

function removeFuelGroup(idx) {
  if (fuelRateGroups.length <= 1) { alert('At least one rate group is required.'); return; }
  if (!confirm('Remove "' + (fuelRateGroups[idx].name || 'Unnamed') + '" group?')) return;
  syncFuelGroupInputs();
  fuelRateGroups.splice(idx, 1);
  renderFuelRateGroups();
}

function getFuelGroupForRider(riderId, excludeIdx) {
  for (var i = 0; i < fuelRateGroups.length; i++) {
    if (i === excludeIdx) continue;
    var ids = fuelRateGroups[i].user_ids ? fuelRateGroups[i].user_ids.split(',').map(function(id){ return id.trim(); }) : [];
    if (ids.includes(String(riderId))) return i;
  }
  return null;
}

function handleFuelRiderToggle(groupIdx, riderId, isChecked) {
  syncFuelGroupInputs();
  var rid = String(riderId);

  if (isChecked) {
    // Remove from any other group
    fuelRateGroups.forEach(function(g, i) {
      if (i !== groupIdx && g.user_ids) {
        var ids = g.user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean);
        ids = ids.filter(function(id) { return id !== rid; });
        g.user_ids = ids.join(',');
      }
    });
    // Add to this group
    var currentIds = fuelRateGroups[groupIdx].user_ids ? fuelRateGroups[groupIdx].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    if (!currentIds.includes(rid)) currentIds.push(rid);
    fuelRateGroups[groupIdx].user_ids = currentIds.join(',');
  } else {
    // Remove from this group
    var ids = fuelRateGroups[groupIdx].user_ids ? fuelRateGroups[groupIdx].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    ids = ids.filter(function(id) { return id !== rid; });
    fuelRateGroups[groupIdx].user_ids = ids.join(',');
  }

  renderFuelRateGroups();
}

function filterFuelRiders(groupIdx) {
  var searchEl = document.getElementById('fuelRiderSearch_' + groupIdx);
  if (!searchEl) return;
  var query = searchEl.value.toLowerCase();
  var listEl = document.getElementById('fuelRiderList_' + groupIdx);
  if (!listEl) return;
  var labels = listEl.querySelectorAll('label');
  labels.forEach(function(label) {
    var name = label.querySelector('.fuel-rider-name');
    if (name) {
      label.style.display = name.textContent.toLowerCase().includes(query) ? 'flex' : 'none';
    }
  });
}

async function saveFuelRateGroups() {
  syncFuelGroupInputs();

  // Validate
  for (var i = 0; i < fuelRateGroups.length; i++) {
    var g = fuelRateGroups[i];
    if (!g.name || !g.name.trim()) { alert('Group ' + (i+1) + ' needs a name.'); return; }
    if (!g.rate || g.rate <= 0) { alert('Group "' + g.name + '" needs a valid rate greater than 0.'); return; }
  }

  // Check duplicates
  var seen = {};
  for (var i = 0; i < fuelRateGroups.length; i++) {
    var ids = fuelRateGroups[i].user_ids ? fuelRateGroups[i].user_ids.split(',').map(function(id){ return id.trim(); }).filter(Boolean) : [];
    for (var j = 0; j < ids.length; j++) {
      if (seen[ids[j]]) {
        var rider = fuelAllRiders.find(function(r){ return String(r.id) === ids[j]; });
        alert((rider ? rider.name : 'A user') + ' is in multiple groups. Each user can only be in one group.');
        return;
      }
      seen[ids[j]] = true;
    }
  }

  var saveBtn = document.getElementById('fuelRateSaveBtn');
  saveBtn.disabled = true;
  saveBtn.textContent = 'Saving...';

  try {
    var groups = fuelRateGroups.map(function(g) {
      return { id: g.id || null, name: g.name.trim(), rate: parseFloat(g.rate), user_ids: g.user_ids || '' };
    });

    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var resp = await fetch('/attendance/fuel-rate-groups', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
      body: JSON.stringify({ groups: groups })
    });

    var data = await resp.json();
    if (data.success) {
      alert('Fuel rate groups saved successfully!');
      closeFuelRateModal();
    } else {
      alert('Error: ' + (data.message || 'Failed to save'));
    }
  } catch(e) {
    console.error('Save error:', e);
    alert('Failed to save fuel rate groups');
  }

  saveBtn.disabled = false;
  saveBtn.textContent = 'Save All Groups';
}

</script>

<!-- ⛽ Fuel Rate Groups Modal -->
<div id="fuelRateModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; padding: 16px;">
  <div style="background: #fff; border-radius: 16px; max-width: 600px; width: 100%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(0,0,0,0.25);" onclick="event.stopPropagation()">
    <div style="padding: 20px 24px 12px; border-bottom: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
      <div>
        <h2 style="font-size: 18px; font-weight: 700; color: #1F2937; margin: 0;">⛽ Fuel Rate Groups</h2>
        <p style="font-size: 13px; color: #6B7280; margin: 4px 0 0;">Each group has its own rate. Assign riders — each rider can only be in one group.</p>
      </div>
      <button onclick="closeFuelRateModal()" style="background: none; border: none; font-size: 24px; color: #9CA3AF; cursor: pointer; padding: 4px;">&times;</button>
    </div>
    <div id="fuelRateLoading" style="padding: 40px; text-align: center; color: #6B7280;">Loading...</div>
    <div id="fuelRateContent" style="display: none; flex: 1; overflow-y: auto; padding: 16px 24px;">
      <div id="fuelRateGroupsList"></div>
    </div>
    <div style="padding: 12px 24px 20px; border-top: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center;">
      <button onclick="addFuelGroup()" style="padding: 8px 16px; border-radius: 8px; background: #FFF7ED; color: #F97316; border: 1px solid #FDBA74; font-weight: 600; font-size: 13px; cursor: pointer;">+ Add Group</button>
      <div style="display: flex; gap: 8px;">
        <button onclick="closeFuelRateModal()" style="padding: 8px 20px; border-radius: 8px; background: #fff; color: #6B7280; border: 1px solid #D1D5DB; font-weight: 600; font-size: 14px; cursor: pointer;">Cancel</button>
        <button id="fuelRateSaveBtn" onclick="saveFuelRateGroups()" style="padding: 8px 24px; border-radius: 8px; background: #F97316; color: #fff; border: none; font-weight: 700; font-size: 14px; cursor: pointer;">Save All Groups</button>
      </div>
    </div>
  </div>
</div>

@endsection
