@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-6">
  <h1 class="text-2xl font-semibold text-gray-900 mb-6">Attendance</h1>

  <div class="bg-white border border-green-200 rounded-lg p-4 mb-4 flex items-center gap-3">
    <input id="newAttUserId" type="number" placeholder="User ID" class="border rounded px-3 py-2 text-sm">
    <input id="newAttDate" type="date" class="border rounded px-3 py-2 text-sm" value="<?php echo date('Y-m-d'); ?>">
    <select id="newAttStatus" class="border rounded px-3 py-2 text-sm">
      <option value="present">Present</option>
      <option value="absent">Absent</option>
      <option value="leave">Leave</option>
    </select>
    <button onclick="addAttendance()" class="px-4 py-2 rounded bg-green-600 text-white text-sm">Add</button>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4 flex items-center gap-3">
    <input id="attUserId" type="number" placeholder="User ID" class="border rounded px-3 py-2 text-sm">
    <input id="attDate" type="date" class="border rounded px-3 py-2 text-sm">
    <button onclick="loadAttendance()" class="px-4 py-2 rounded bg-blue-600 text-white text-sm">Filter</button>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg">
    <table class="min-w-full">
      <thead>
        <tr class="bg-gray-50 text-gray-600 text-sm">
          <th class="px-4 py-2 text-left">User</th>
          <th class="px-4 py-2 text-left">Date</th>
          <th class="px-4 py-2 text-left">Login</th>
          <th class="px-4 py-2 text-left">Logout</th>
          <th class="px-4 py-2 text-left">Device</th>
          <th class="px-4 py-2 text-left">Meter</th>
        </tr>
      </thead>
      <tbody id="attBody"></tbody>
    </table>
  </div>
</div>

<script>
async function addAttendance(){
  const user_id = document.getElementById('newAttUserId').value;
  const date = document.getElementById('newAttDate').value;
  const status = document.getElementById('newAttStatus').value;
  if (!user_id || !date) { alert('User and date required'); return; }
  const res = await fetch('/attendance', { method:'POST', headers:{ 'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').getAttribute('content') }, body: JSON.stringify({ user_id, attendance_date: date, status }) });
  const json = await res.json();
  if (json && json.success) { loadAttendance(); } else { alert(json.message || 'Failed to add'); }
}

async function loadAttendance() {
  const params = new URLSearchParams();
  const uid = document.getElementById('attUserId').value;
  const date = document.getElementById('attDate').value;
  if (uid) params.set('user_id', uid);
  if (date) params.set('date', date);
  const res = await fetch('/attendance/data?' + params.toString(), { headers: { 'Accept':'application/json' }});
  const json = await res.json();
  const body = document.getElementById('attBody');
  body.innerHTML = (json.data||[]).map(r => `
    <tr class="border-t text-sm">
      <td class="px-4 py-2">${r.fullname || ('#'+r.user_id)}</td>
      <td class="px-4 py-2">${r.attendance_date}</td>
      <td class="px-4 py-2">${r.login_time || ''}</td>
      <td class="px-4 py-2">${r.logout_time || ''}</td>
      <td class="px-4 py-2">${r.device_id || ''}</td>
      <td class="px-4 py-2">${(r.meter_start||'') + (r.meter_end?(' → '+r.meter_end):'')}</td>
    </tr>`).join('');
}
document.addEventListener('DOMContentLoaded', loadAttendance);
</script>
@endsection


