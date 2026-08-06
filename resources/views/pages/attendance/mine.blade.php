@extends('layouts.app')

@section('title', 'My Attendance')

@section('content')
<div class="max-w-5xl mx-auto p-6">
  <!-- Header with Mark Attendance Button -->
  <div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-semibold text-gray-900">My Attendance</h1>
    <button 
      onclick="openMarkAttendanceModal()" 
      class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
    >
      ➕ Mark Attendance
    </button>
  </div>

  <!-- Mark Attendance Modal -->
  <div id="markAttendanceModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center" style="z-index: 9999;">
    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-md">
      <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold text-gray-900">Mark My Attendance</h2>
        <button onclick="closeMarkAttendanceModal()" class="text-gray-400 hover:text-gray-600">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <form id="markAttendanceForm" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Date</label>
          <input 
            type="date" 
            id="modalDate" 
            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            required
          >
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Login Time</label>
          <input 
            type="time" 
            id="modalLoginTime" 
            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            step="60"
          >
          <p class="text-xs text-gray-500 mt-1">Leave empty if not logging in</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Logout Time</label>
          <input 
            type="time" 
            id="modalLogoutTime" 
            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            step="60"
          >
          <p class="text-xs text-gray-500 mt-1">Leave empty if not logging out</p>
        </div>

        <div class="flex gap-3 pt-4">
          <button 
            type="button" 
            onclick="closeMarkAttendanceModal()" 
            class="flex-1 px-4 py-2 bg-gray-100 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-200 transition"
          >
            Cancel
          </button>
          <button 
            type="submit" 
            class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold"
          >
            💾 Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Attendance Table -->
  <div class="bg-white border border-gray-200 rounded-lg">
    <table class="min-w-full">
      <thead>
        <tr class="bg-gray-50 text-gray-600 text-sm">
          <th class="px-4 py-2 text-left">Date</th>
          <th class="px-4 py-2 text-left">Login</th>
          <th class="px-4 py-2 text-left">Logout</th>
          <th class="px-4 py-2 text-left">Device</th>
        </tr>
      </thead>
      <tbody id="mineBody"></tbody>
    </table>
  </div>
</div>

<script>
const currentUserId = {{ auth()->id() }};

async function loadMine() {
  const res = await fetch('/attendance/mine/data', { headers: { 'Accept':'application/json' }});
  const json = await res.json();
  const body = document.getElementById('mineBody');
  
  if (!json.data || json.data.length === 0) {
    body.innerHTML = '<tr><td colspan="4" class="px-4 py-4 text-center text-gray-500">No attendance records found</td></tr>';
    return;
  }
  
  body.innerHTML = (json.data||[]).map(r => `
    <tr class="border-t text-sm hover:bg-gray-50">
      <td class="px-4 py-2">${r.attendance_date}</td>
      <td class="px-4 py-2">${r.login_time || '<span class="text-gray-400">-</span>'}</td>
      <td class="px-4 py-2">${r.logout_time || '<span class="text-gray-400">-</span>'}</td>
      <td class="px-4 py-2">${r.device_id || '<span class="text-gray-400">-</span>'}</td>
    </tr>`).join('');
}

function openMarkAttendanceModal() {
  // Set today's date as default
  const today = new Date().toISOString().split('T')[0];
  document.getElementById('modalDate').value = today;
  document.getElementById('modalLoginTime').value = '';
  document.getElementById('modalLogoutTime').value = '';
  
  document.getElementById('markAttendanceModal').classList.remove('hidden');
}

function closeMarkAttendanceModal() {
  document.getElementById('markAttendanceModal').classList.add('hidden');
}

document.getElementById('markAttendanceForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const date = document.getElementById('modalDate').value;
  const loginTime = document.getElementById('modalLoginTime').value;
  const logoutTime = document.getElementById('modalLogoutTime').value;
  
  if (!date) {
    alert('Please select a date');
    return;
  }
  
  if (!loginTime && !logoutTime) {
    alert('Please enter at least login time or logout time');
    return;
  }
  
  const payload = {
    user_id: currentUserId,
    attendance_date: date,
    login_time: loginTime || null,
    logout_time: logoutTime || null
  };
  
  try {
    const res = await fetch('/attendance', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert('✅ Attendance marked successfully!');
      closeMarkAttendanceModal();
      loadMine(); // Reload the table
    } else {
      alert('❌ Error: ' + (json.message || 'Failed to mark attendance'));
    }
  } catch (error) {
    console.error('Error:', error);
    alert('❌ Failed to mark attendance. Please try again.');
  }
});

// Close modal when clicking outside
document.getElementById('markAttendanceModal').addEventListener('click', function(e) {
  if (e.target === this) {
    closeMarkAttendanceModal();
  }
});

document.addEventListener('DOMContentLoaded', loadMine);
</script>
@endsection


