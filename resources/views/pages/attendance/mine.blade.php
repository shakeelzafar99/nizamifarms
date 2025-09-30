@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-6">
  <h1 class="text-2xl font-semibold text-gray-900 mb-6">My Attendance</h1>

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
async function loadMine() {
  const res = await fetch('/attendance/mine/data', { headers: { 'Accept':'application/json' }});
  const json = await res.json();
  const body = document.getElementById('mineBody');
  body.innerHTML = (json.data||[]).map(r => `
    <tr class="border-t text-sm">
      <td class="px-4 py-2">${r.attendance_date}</td>
      <td class="px-4 py-2">${r.login_time || ''}</td>
      <td class="px-4 py-2">${r.logout_time || ''}</td>
      <td class="px-4 py-2">${r.device_id || ''}</td>
    </tr>`).join('');
}
document.addEventListener('DOMContentLoaded', loadMine);
</script>
@endsection


