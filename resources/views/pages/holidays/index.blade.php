@extends('layouts.app')

@section('title', 'Public Holidays')

@section('content')
<div class="p-6 max-w-5xl mx-auto">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Public Holidays</h1>
      <p class="text-sm text-gray-500 mt-1">Manage public holidays that affect working days calculations</p>
    </div>
    <button onclick="openAddHolidayModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold shadow-md">
      ➕ Add Holiday
    </button>
  </div>

  <!-- Year Filter -->
  <div class="mb-4 flex items-center gap-4">
    <label class="text-sm font-medium text-gray-700">Year:</label>
    <select id="yearFilter" onchange="loadHolidays()" class="px-3 py-2 border border-gray-300 rounded-lg">
      <option value="2024">2024</option>
      <option value="2025" selected>2025</option>
      <option value="2026">2026</option>
    </select>
    <span id="holidayCount" class="text-sm text-gray-600"></span>
  </div>

  <!-- Holidays List -->
  <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
    <table class="min-w-full">
      <thead class="bg-gray-50">
        <tr class="text-left text-xs font-medium text-gray-500 uppercase">
          <th class="p-4">Date</th>
          <th class="p-4">Holiday Name</th>
          <th class="p-4">Day</th>
          <th class="p-4">Description</th>
          <th class="p-4 text-right">Actions</th>
        </tr>
      </thead>
      <tbody id="holidaysTableBody"></tbody>
    </table>
    
    <!-- Empty State -->
    <div id="emptyState" class="hidden text-center py-12">
      <div class="text-gray-400 text-6xl mb-4">🎉</div>
      <h3 class="text-lg font-medium text-gray-900 mb-2">No Holidays Yet</h3>
      <p class="text-gray-500 mb-4">Add your first public holiday for this year</p>
      <button onclick="openAddHolidayModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
        Add Holiday
      </button>
    </div>
  </div>
</div>

<!-- Add Holiday Modal -->
<div id="holidayModal" class="fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center z-50">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
    <div class="p-6 border-b border-gray-200">
      <div class="flex justify-between items-center">
        <h2 class="text-xl font-semibold text-gray-900">Add Public Holiday</h2>
        <button onclick="closeHolidayModal()" class="text-gray-400 hover:text-gray-600">
          <span class="text-2xl">×</span>
        </button>
      </div>
    </div>
    
    <form id="holidayForm" class="p-6 space-y-4">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Holiday Date <span class="text-red-500">*</span></label>
          <input type="date" id="holidayDate" onchange="syncHolidayEndMin()" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">End Date <span class="text-gray-400 font-normal">(optional)</span></label>
          <input type="date" id="holidayEndDate" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
          <p class="text-xs text-gray-500 mt-1">Leave blank for a single day. Set it for a break (e.g. Eid).</p>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Holiday Name <span class="text-red-500">*</span></label>
        <input type="text" id="holidayName" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="e.g., Independence Day" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea id="holidayDescription" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" rows="2" placeholder="Optional notes"></textarea>
      </div>

      <div class="flex justify-end gap-2 pt-4">
        <button type="button" onclick="closeHolidayModal()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
          Add Holiday
        </button>
      </div>
    </form>
  </div>
</div>

<script>
let allHolidays = [];

document.addEventListener('DOMContentLoaded', function() {
  // Set min date to today
  document.getElementById('holidayDate').min = new Date().toISOString().split('T')[0];
  loadHolidays();
});

// Keep the end date >= the start date (a range can't run backwards).
function syncHolidayEndMin() {
  const start = document.getElementById('holidayDate').value;
  const endEl = document.getElementById('holidayEndDate');
  endEl.min = start || new Date().toISOString().split('T')[0];
  if (endEl.value && start && endEl.value < start) endEl.value = '';
}

async function loadHolidays() {
  const year = document.getElementById('yearFilter').value;
  
  try {
    const res = await fetch(`/holidays/list?year=${year}`);
    const json = await res.json();
    
    if (json.success) {
      allHolidays = json.data;
      renderHolidays();
    }
  } catch(e) {
    console.error('Error loading holidays:', e);
    alert('Failed to load holidays');
  }
}

function renderHolidays() {
  const tbody = document.getElementById('holidaysTableBody');
  const emptyState = document.getElementById('emptyState');
  const holidayCount = document.getElementById('holidayCount');
  
  if (allHolidays.length === 0) {
    tbody.innerHTML = '';
    emptyState.classList.remove('hidden');
    holidayCount.textContent = 'No holidays';
    return;
  }
  
  emptyState.classList.add('hidden');
  holidayCount.textContent = `${allHolidays.length} holiday${allHolidays.length !== 1 ? 's' : ''}`;
  
  tbody.innerHTML = allHolidays.map(holiday => `
    <tr class="border-b border-gray-100 hover:bg-gray-50">
      <td class="p-4 font-medium">${holiday.holiday_date_formatted}</td>
      <td class="p-4">${holiday.holiday_name}</td>
      <td class="p-4 text-sm text-gray-600">${holiday.day_of_week}</td>
      <td class="p-4 text-sm text-gray-600">${holiday.description || '-'}</td>
      <td class="p-4 text-right">
        <button onclick="deleteHoliday(${holiday.id}, '${holiday.holiday_name}')" class="px-3 py-1 text-sm text-red-600 hover:bg-red-50 rounded">
          Delete
        </button>
      </td>
    </tr>
  `).join('');
}

function openAddHolidayModal() {
  document.getElementById('holidayForm').reset();
  document.getElementById('holidayModal').classList.remove('hidden');
}

function closeHolidayModal() {
  document.getElementById('holidayModal').classList.add('hidden');
}

document.getElementById('holidayForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const payload = {
    holiday_date: document.getElementById('holidayDate').value,
    holiday_end_date: document.getElementById('holidayEndDate').value || null,
    holiday_name: document.getElementById('holidayName').value,
    description: document.getElementById('holidayDescription').value
  };
  
  try {
    const res = await fetch('/holidays', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      closeHolidayModal();
      loadHolidays();
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error adding holiday:', e);
    alert('Failed to add holiday');
  }
});

async function deleteHoliday(holidayId, holidayName) {
  if (!confirm(`Delete holiday "${holidayName}"?`)) return;
  
  try {
    const res = await fetch(`/holidays/${holidayId}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      loadHolidays();
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error deleting holiday:', e);
    alert('Failed to delete holiday');
  }
}
</script>
@endsection



