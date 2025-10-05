@extends('layouts.app')

@section('title', 'Shift Management')

@section('content')
<style>
  #userAssignmentModal {
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
  
  #shiftModal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(4px);
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }
</style>
<div class="p-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Shift Management</h1>
      <p class="text-sm text-gray-500 mt-1">Manage shift templates and assign them to employees</p>
    </div>
    <div class="flex gap-2">
      <button onclick="openUserAssignmentView()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md">
        👥 Assign to Users
      </button>
      <button onclick="openCreateModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold shadow-md">
        ➕ Create Shift
      </button>
    </div>
  </div>

  <!-- Shift Templates Grid -->
  <div id="shiftsGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4"></div>

  <!-- Empty State -->
  <div id="emptyState" class="hidden text-center py-12">
    <div class="text-gray-400 text-6xl mb-4">📅</div>
    <h3 class="text-lg font-medium text-gray-900 mb-2">No Shift Templates</h3>
    <p class="text-gray-500 mb-4">Create your first shift template to get started</p>
    <button onclick="openCreateModal()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
      Create Shift Template
    </button>
  </div>
</div>

<!-- Create/Edit Shift Modal -->
<div id="shiftModal" class="hidden" onclick="closeShiftModal()">
  <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl" style="position: relative; max-height: calc(100vh - 40px); overflow-y: auto; margin: auto;" onclick="event.stopPropagation();">
    <div class="p-6 border-b border-gray-200">
      <div class="flex justify-between items-center">
        <h2 id="modalTitle" class="text-xl font-semibold text-gray-900">Create Shift Template</h2>
        <button onclick="closeShiftModal()" class="text-gray-400 hover:text-gray-600">
          <span class="text-2xl">×</span>
        </button>
      </div>
    </div>
    
    <form id="shiftForm" class="p-6 space-y-4">
      <input type="hidden" id="shiftId">
      
      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Shift Name <span class="text-red-500">*</span></label>
        <input type="text" id="shiftName" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="e.g., Rider Shift 1" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Shift Code <span class="text-red-500">*</span></label>
        <input type="text" id="shiftCode" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="e.g., rider_shift_1" required>
        <p class="text-xs text-gray-500 mt-1">Lowercase, no spaces (use underscores)</p>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Start Time <span class="text-red-500">*</span></label>
          <input type="time" id="shiftStart" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">End Time <span class="text-red-500">*</span></label>
          <input type="time" id="shiftEnd" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-2">Working Days <span class="text-red-500">*</span></label>
        <div class="grid grid-cols-7 gap-2">
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="1" id="day_mon">
            <span class="text-sm mt-1">Mon</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="2" id="day_tue">
            <span class="text-sm mt-1">Tue</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="3" id="day_wed">
            <span class="text-sm mt-1">Wed</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="4" id="day_thu">
            <span class="text-sm mt-1">Thu</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="5" id="day_fri">
            <span class="text-sm mt-1">Fri</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="6" id="day_sat">
            <span class="text-sm mt-1">Sat</span>
          </label>
          <label class="flex flex-col items-center cursor-pointer">
            <input type="checkbox" class="working-day-checkbox" value="7" id="day_sun">
            <span class="text-sm mt-1">Sun</span>
          </label>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
        <textarea id="shiftDescription" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" rows="2" placeholder="Optional notes about this shift"></textarea>
      </div>

      <div class="flex justify-end gap-2 pt-4">
        <button type="button" onclick="closeShiftModal()" class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
          Cancel
        </button>
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
          Save Shift
        </button>
      </div>
    </form>
  </div>
</div>

<!-- User Assignment View Modal -->
<div id="userAssignmentModal" style="display: none;" onclick="if(event.target === this) closeUserAssignmentView();">
  <div style="background: white; border-radius: 16px; width: 95%; max-width: 1200px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);" onclick="event.stopPropagation();">
    <!-- Header -->
    <div style="padding: 20px 24px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
      <div style="display: flex; align-items: center; justify-content: space-between;">
        <div>
          <h2 style="font-size: 20px; font-weight: 600; color: #111827; margin: 0;">Assign Shifts to Users</h2>
          <p style="font-size: 14px; color: #6b7280; margin-top: 4px;">Select users and assign them to a shift template</p>
        </div>
        <button onclick="closeUserAssignmentView()" style="color: #9ca3af; font-size: 28px; line-height: 1; border: none; background: none; cursor: pointer; padding: 0;" onmouseover="this.style.color='#4b5563'" onmouseout="this.style.color='#9ca3af'">
          ×
        </button>
      </div>
    </div>
    
    <!-- Bulk Actions Bar -->
    <div style="padding: 16px 24px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; flex-shrink: 0;">
      <div style="display: flex; align-items: center; gap: 16px;">
        <button onclick="selectAllUsers()" style="font-size: 14px; color: #2563eb; background: none; border: none; cursor: pointer; padding: 0;" onmouseover="this.style.color='#1d4ed8'" onmouseout="this.style.color='#2563eb'">Select All</button>
        <button onclick="deselectAllUsers()" style="font-size: 14px; color: #4b5563; background: none; border: none; cursor: pointer; padding: 0;" onmouseover="this.style.color='#111827'" onmouseout="this.style.color='#4b5563'">Deselect All</button>
        <div style="flex: 1;"></div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <span style="font-size: 14px; color: #4b5563;"><span id="selectedCount">0</span> selected</span>
          <select id="bulkShiftSelect" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px;">
            <option value="">Select Shift...</option>
          </select>
          <button onclick="bulkAssignShift()" style="padding: 8px 16px; background: #2563eb; color: white; border-radius: 8px; border: none; cursor: pointer; font-size: 14px;" onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
            Assign to Selected
          </button>
        </div>
      </div>
    </div>
    
    <!-- Users List (Scrollable) -->
    <div style="overflow-y: auto; flex: 1; padding: 24px;">
      <table style="width: 100%;">
        <thead style="background: #f9fafb; position: sticky; top: 0;">
          <tr style="text-align: left; font-size: 12px; font-weight: 500; color: #6b7280; text-transform: uppercase;">
            <th style="padding: 12px; width: 40px;">
              <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)">
            </th>
            <th style="padding: 12px;">Employee</th>
            <th style="padding: 12px;">Current Shift</th>
            <th style="padding: 12px;">Source</th>
            <th style="padding: 12px;">Actions</th>
          </tr>
        </thead>
        <tbody id="usersTableBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
let allShifts = [];
let allUsers = [];
let selectedUserIds = new Set();
let editingShiftId = null;

document.addEventListener('DOMContentLoaded', function() {
  loadShifts();
  
  // Check if URL has user parameter (coming from attendance page)
  const urlParams = new URLSearchParams(window.location.search);
  const userId = urlParams.get('user');
  const userName = urlParams.get('name');
  
  if (userId) {
    console.log('Auto-opening shift assignment for user:', userId, userName);
    // Wait a bit for shifts to load, then open modal
    setTimeout(() => {
      openUserAssignmentView().then(() => {
        // Auto-scroll to the user and highlight them
        const userRow = document.querySelector(`tr:has(input.user-checkbox[value="${userId}"])`);
        if (userRow) {
          userRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
          userRow.style.backgroundColor = '#fef3c7'; // Yellow highlight
          setTimeout(() => {
            userRow.style.backgroundColor = '';
          }, 3000);
        }
      });
    }, 500);
  }
});

async function loadShifts() {
  try {
    const res = await fetch('/shifts/list');
    const json = await res.json();
    
    if (json.success) {
      allShifts = json.data;
      renderShifts();
    }
  } catch(e) {
    console.error('Error loading shifts:', e);
    alert('Failed to load shifts');
  }
}

function renderShifts() {
  const grid = document.getElementById('shiftsGrid');
  const emptyState = document.getElementById('emptyState');
  
  if (allShifts.length === 0) {
    grid.classList.add('hidden');
    emptyState.classList.remove('hidden');
    return;
  }
  
  grid.classList.remove('hidden');
  emptyState.classList.add('hidden');
  
  grid.innerHTML = allShifts.map(shift => `
    <div class="bg-white border border-gray-200 rounded-lg p-5 hover:shadow-lg transition ${shift.is_default ? 'ring-2 ring-blue-500' : ''}">
      <div class="flex justify-between items-start mb-3">
        <div>
          <h3 class="font-semibold text-lg text-gray-900">${shift.shift_name}</h3>
          <p class="text-xs text-gray-500 mt-1">${shift.shift_code}</p>
        </div>
        ${shift.is_default ? '<span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full font-medium">DEFAULT</span>' : ''}
      </div>
      
      <div class="space-y-2 mb-4">
        <div class="flex items-center text-sm">
          <span class="text-gray-500 w-20">Hours:</span>
          <span class="font-medium">${shift.shift_start} - ${shift.shift_end}</span>
        </div>
        <div class="flex items-center text-sm">
          <span class="text-gray-500 w-20">Working:</span>
          <span class="font-medium">${shift.working_days_count} days/week</span>
        </div>
        <div class="flex items-center text-sm">
          <span class="text-gray-500 w-20">Off Days:</span>
          <span class="font-medium text-red-600">${shift.off_days}</span>
        </div>
        <div class="flex items-center text-sm">
          <span class="text-gray-500 w-20">Users:</span>
          <span class="font-medium text-green-600">${shift.assigned_users_count} assigned</span>
        </div>
      </div>
      
      ${shift.description ? `<p class="text-xs text-gray-600 mb-3 italic">${shift.description}</p>` : ''}
      
      <div class="flex gap-2">
        ${!shift.is_default ? `<button onclick="setAsDefault(${shift.id})" class="flex-1 px-3 py-2 border border-blue-600 text-blue-600 rounded-lg hover:bg-blue-50 text-sm">Set Default</button>` : ''}
        <button onclick="openEditModal(${shift.id})" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm">Edit</button>
        ${!shift.is_default && shift.assigned_users_count === 0 ? `<button onclick="deleteShift(${shift.id})" class="px-3 py-2 border border-red-300 text-red-600 rounded-lg hover:bg-red-50 text-sm">Delete</button>` : ''}
      </div>
    </div>
  `).join('');
}

function openCreateModal() {
  editingShiftId = null;
  document.getElementById('modalTitle').textContent = 'Create Shift Template';
  document.getElementById('shiftForm').reset();
  const modal = document.getElementById('shiftModal');
  modal.classList.remove('hidden');
  modal.style.display = 'flex';
}

function openEditModal(shiftId) {
  const shift = allShifts.find(s => s.id === shiftId);
  if (!shift) return;
  
  editingShiftId = shiftId;
  document.getElementById('modalTitle').textContent = 'Edit Shift Template';
  document.getElementById('shiftId').value = shift.id;
  document.getElementById('shiftName').value = shift.shift_name;
  document.getElementById('shiftCode').value = shift.shift_code;
  document.getElementById('shiftStart').value = shift.shift_start.substring(0, 5);
  document.getElementById('shiftEnd').value = shift.shift_end.substring(0, 5);
  document.getElementById('shiftDescription').value = shift.description || '';
  
  // Set working days checkboxes
  document.querySelectorAll('.working-day-checkbox').forEach(cb => cb.checked = false);
  shift.working_days.forEach(day => {
    const checkbox = document.querySelector(`.working-day-checkbox[value="${day}"]`);
    if (checkbox) checkbox.checked = true;
  });
  
  const modal = document.getElementById('shiftModal');
  modal.classList.remove('hidden');
  modal.style.display = 'flex';
}

function closeShiftModal() {
  const modal = document.getElementById('shiftModal');
  modal.classList.add('hidden');
  modal.style.display = 'none';
  editingShiftId = null;
}

document.getElementById('shiftForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  
  const workingDays = Array.from(document.querySelectorAll('.working-day-checkbox:checked')).map(cb => parseInt(cb.value));
  
  if (workingDays.length === 0) {
    alert('Please select at least one working day');
    return;
  }
  
  const payload = {
    shift_name: document.getElementById('shiftName').value,
    shift_code: document.getElementById('shiftCode').value,
    shift_start: document.getElementById('shiftStart').value,
    shift_end: document.getElementById('shiftEnd').value,
    working_days: workingDays,
    description: document.getElementById('shiftDescription').value
  };
  
  try {
    const url = editingShiftId ? `/shifts/${editingShiftId}` : '/shifts';
    const method = editingShiftId ? 'PUT' : 'POST';
    
    const res = await fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(payload)
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      closeShiftModal();
      loadShifts();
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error saving shift:', e);
    alert('Failed to save shift');
  }
});

async function setAsDefault(shiftId) {
  if (!confirm('Set this shift as the default for all users without an assigned shift?')) return;
  
  try {
    const res = await fetch(`/shifts/${shiftId}/set-default`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      loadShifts();
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error setting default:', e);
    alert('Failed to set default shift');
  }
}

async function deleteShift(shiftId) {
  if (!confirm('Are you sure you want to delete this shift template?')) return;
  
  try {
    const res = await fetch(`/shifts/${shiftId}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      loadShifts();
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error deleting shift:', e);
    alert('Failed to delete shift');
  }
}

async function openUserAssignmentView() {
  return new Promise(async (resolve, reject) => {
    try {
      console.log('Opening user assignment view...');
      
      // Load shifts for dropdown
      const shiftsRes = await fetch('/shifts/list');
      const shiftsJson = await shiftsRes.json();
      console.log('Shifts loaded:', shiftsJson);
      
      if (shiftsJson.success) {
        allShifts = shiftsJson.data;
        
        // Populate shift dropdown
        const dropdown = document.getElementById('bulkShiftSelect');
        dropdown.innerHTML = '<option value="">Select Shift...</option>' + 
          allShifts.map(s => `<option value="${s.id}">${s.shift_name}</option>`).join('');
        console.log('Shift dropdown populated with', allShifts.length, 'shifts');
      }
      
      // Load users
      console.log('Fetching users from /shifts/users-with-shifts...');
      const usersRes = await fetch('/shifts/users-with-shifts');
      const usersJson = await usersRes.json();
      console.log('Users response:', usersJson);
      
      if (usersJson.success) {
        allUsers = usersJson.data;
        console.log('Users loaded:', allUsers.length, 'users');
        renderUsers();
        const modal = document.getElementById('userAssignmentModal');
        modal.style.display = 'flex';
        console.log('Modal opened');
        resolve(); // Resolve promise when done
      } else {
        console.error('Users API returned success=false:', usersJson.message);
        alert('Error: ' + usersJson.message);
        reject(new Error(usersJson.message));
      }
    } catch(e) {
      console.error('Error loading data:', e);
      alert('Failed to load user data: ' + e.message);
      reject(e);
    }
  });
}

function renderUsers() {
  console.log('renderUsers called with', allUsers.length, 'users');
  const tbody = document.getElementById('usersTableBody');
  
  if (!tbody) {
    console.error('usersTableBody element not found!');
    return;
  }
  
  console.log('Rendering users to table...');
  tbody.innerHTML = allUsers.map((user, index) => {
    const rowBg = index % 2 === 0 ? '#ffffff' : '#f9fafb';
    const sourceColor = 
      user.current_shift_source === 'user_assignment' ? '#dcfce7' :
      user.current_shift_source === 'legacy_rider_profile' ? '#fef3c7' :
      '#f3f4f6';
    const sourceTextColor = 
      user.current_shift_source === 'user_assignment' ? '#166534' :
      user.current_shift_source === 'legacy_rider_profile' ? '#854d0e' :
      '#374151';
    
    return `
      <tr style="border-bottom: 1px solid #e5e7eb; background: ${rowBg};" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='${rowBg}'">
        <td style="padding: 12px;">
          <input type="checkbox" class="user-checkbox" value="${user.user_id}" onchange="updateSelectedCount()">
        </td>
        <td style="padding: 12px; font-weight: 500; color: #111827;">${user.fullname}</td>
        <td style="padding: 12px;">
          <div style="font-size: 14px; color: ${user.assigned_shift_name ? '#111827' : '#6b7280'};">${user.current_shift_name}</div>
          <div style="font-size: 12px; color: #9ca3af; margin-top: 2px;">${user.shift_start} - ${user.shift_end}</div>
        </td>
        <td style="padding: 12px;">
          <span style="font-size: 12px; padding: 4px 8px; border-radius: 9999px; background: ${sourceColor}; color: ${sourceTextColor};">
            ${user.current_shift_source.replace(/_/g, ' ')}
          </span>
        </td>
        <td style="padding: 12px;">
          <select style="padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 14px;" data-user-id="${user.user_id}" onchange="assignSingleUser(${user.user_id}, this.value)">
            <option value="">Select Shift...</option>
            ${allShifts.map(s => `<option value="${s.id}" ${user.assigned_shift_id == s.id ? 'selected' : ''}>${s.shift_name}</option>`).join('')}
          </select>
        </td>
      </tr>
    `;
  }).join('');
}

function closeUserAssignmentView() {
  const modal = document.getElementById('userAssignmentModal');
  modal.style.display = 'none';
  selectedUserIds.clear();
  loadShifts(); // Refresh counts
}

function toggleSelectAll(checkbox) {
  document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = checkbox.checked);
  updateSelectedCount();
}

function selectAllUsers() {
  document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = true);
  document.getElementById('selectAllCheckbox').checked = true;
  updateSelectedCount();
}

function deselectAllUsers() {
  document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
  document.getElementById('selectAllCheckbox').checked = false;
  updateSelectedCount();
}

function updateSelectedCount() {
  const count = document.querySelectorAll('.user-checkbox:checked').length;
  document.getElementById('selectedCount').textContent = count;
}

async function bulkAssignShift() {
  const shiftId = document.getElementById('bulkShiftSelect').value;
  if (!shiftId) {
    alert('Please select a shift to assign');
    return;
  }
  
  const checkedBoxes = document.querySelectorAll('.user-checkbox:checked');
  if (checkedBoxes.length === 0) {
    alert('Please select at least one user');
    return;
  }
  
  const userIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
  
  if (!confirm(`Assign selected shift to ${userIds.length} user(s)?`)) return;
  
  try {
    const res = await fetch('/shifts/bulk-assign', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        user_ids: userIds,
        shift_template_id: parseInt(shiftId)
      })
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      openUserAssignmentView(); // Reload
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error bulk assigning:', e);
    alert('Failed to assign shifts');
  }
}

async function assignSingleUser(userId, shiftId) {
  if (!shiftId) return;
  
  try {
    const res = await fetch('/shifts/assign', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({
        user_id: userId,
        shift_template_id: parseInt(shiftId)
      })
    });
    
    const json = await res.json();
    
    if (json.success) {
      alert(json.message);
      openUserAssignmentView(); // Reload
    } else {
      alert(json.message);
    }
  } catch(e) {
    console.error('Error assigning shift:', e);
    alert('Failed to assign shift');
  }
}
</script>

<style>
.working-day-checkbox {
  width: 20px;
  height: 20px;
  cursor: pointer;
}

.working-day-checkbox:checked {
  accent-color: #3b82f6;
}
</style>
@endsection

