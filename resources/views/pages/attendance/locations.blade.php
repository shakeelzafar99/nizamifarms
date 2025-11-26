@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6">
  <!-- Header -->
  <div class="flex justify-between items-center mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Office Locations Management</h1>
      <p class="text-sm text-gray-600 mt-1">Manage company office locations for attendance tracking</p>
    </div>
    <div class="flex gap-2">
      <a href="/attendance" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition font-semibold">
        ← Back to Attendance
      </a>
      <button 
        onclick="openAddLocationModal()" 
        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold"
      >
        ➕ Add Location
      </button>
    </div>
  </div>

  <!-- Locations Table -->
  <div class="bg-white rounded-lg shadow-md overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location Name</th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Coordinates</th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Radius</th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned Users</th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
        </tr>
      </thead>
      <tbody id="locationsTableBody" class="bg-white divide-y divide-gray-200">
        <!-- Loaded via JavaScript -->
      </tbody>
    </table>
  </div>

  <!-- Loading State -->
  <div id="loadingState" class="text-center py-8">
    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
    <p class="mt-2 text-gray-600">Loading locations...</p>
  </div>

  <!-- Empty State -->
  <div id="emptyState" class="hidden text-center py-12">
    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
    </svg>
    <h3 class="mt-2 text-sm font-medium text-gray-900">No locations</h3>
    <p class="mt-1 text-sm text-gray-500">Get started by creating a new office location.</p>
  </div>
</div>

<!-- Add/Edit Location Modal -->
<div id="locationModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;" onclick="closeLocationModal()">
  <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 12px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);" onclick="event.stopPropagation()">
    <div class="p-6">
      <div class="flex justify-between items-center mb-4">
        <h2 id="modalTitle" class="text-lg font-semibold text-gray-800">Add Location</h2>
        <button onclick="closeLocationModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
      </div>

    <form id="locationForm" onsubmit="saveLocation(event)">
      <input type="hidden" id="locationId" name="location_id">
      
      <div class="space-y-4">
        <!-- Location Name -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Location Name *</label>
          <input 
            type="text" 
            id="locationName" 
            name="location_name" 
            required
            placeholder="e.g., Main Office, Warehouse, Branch Office"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
        </div>

        <!-- Coordinates -->
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Latitude *</label>
            <input 
              type="number" 
              id="latitude" 
              name="latitude" 
              step="any"
              min="-90"
              max="90"
              required
              placeholder="33.70811597"
              onchange="updateMapPreview()"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Longitude *</label>
            <input 
              type="number" 
              id="longitude" 
              name="longitude" 
              step="any"
              min="-180"
              max="180"
              required
              placeholder="73.08868750"
              onchange="updateMapPreview()"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
            >
          </div>
        </div>

        <!-- Map Picker Button -->
        <div class="flex gap-2">
          <button 
            type="button"
            onclick="openMapPicker()"
            class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium"
          >
            📍 Pick Location from Map
          </button>
          <button 
            type="button"
            onclick="getCurrentLocation()"
            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition font-medium"
          >
            📱 Use My Location
          </button>
        </div>

        <!-- Map Preview -->
        <div id="mapPreview" class="hidden">
          <label class="block text-sm font-medium text-gray-700 mb-2">Location Preview</label>
          <div class="border border-gray-300 rounded-lg overflow-hidden">
            <iframe 
              id="mapFrame"
              width="100%" 
              height="250"
              style="border:0;"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade">
            </iframe>
          </div>
        </div>

        <!-- Google Maps Link Helper -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
          <p class="text-sm text-blue-800">
            <strong>💡 Tip:</strong> You can either:
            <br>• Click "Pick Location from Map" to choose on an interactive map
            <br>• Click "Use My Location" to use your current GPS location
            <br>• Or manually enter coordinates from Google Maps (right-click → "What's here?")
          </p>
        </div>

        <!-- Radius -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Allowed Radius (meters) *</label>
          <input 
            type="number" 
            id="radiusMeters" 
            name="radius_meters" 
            min="100"
            max="10000"
            required
            placeholder="2000"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
          <p class="text-xs text-gray-500 mt-1">Distance within which check-ins are considered on-site (100-10,000 meters)</p>
        </div>

        <!-- Checkboxes -->
        <div class="space-y-2">
          <div class="flex items-center">
            <input 
              type="checkbox" 
              id="isPrimary" 
              name="is_primary"
              class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
            >
            <label for="isPrimary" class="ml-2 block text-sm text-gray-700">
              Set as Primary Location
              <span class="text-xs text-gray-500">(Default location for users without specific assignment)</span>
            </label>
          </div>
          <div class="flex items-center">
            <input 
              type="checkbox" 
              id="isActive" 
              name="is_active"
              checked
              class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
            >
            <label for="isActive" class="ml-2 block text-sm text-gray-700">Active</label>
          </div>
        </div>
      </div>

      <!-- Form Actions -->
      <div class="flex justify-end gap-2 mt-6">
        <button 
          type="button" 
          onclick="closeLocationModal()"
          class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition"
        >
          Cancel
        </button>
        <button 
          type="submit"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
        >
          Save Location
        </button>
      </div>
    </form>
    </div>
  </div>
</div>

<!-- Interactive Map Picker Modal -->
<div id="mapPickerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50" style="z-index: 9999;">
  <div class="flex items-center justify-center min-h-screen p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl" onclick="event.stopPropagation()">
      <!-- Header -->
      <div class="flex justify-between items-center p-6 border-b border-gray-200">
        <div>
          <h2 class="text-lg font-semibold text-gray-800">Pick Location on Map</h2>
          <p class="text-sm text-gray-600 mt-1">Click anywhere on the map or drag the marker to select location</p>
        </div>
        <button onclick="closeMapPickerModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
      </div>

      <!-- Map Container -->
      <div class="p-6">
        <div id="interactiveMap" style="height: 500px; width: 100%; border-radius: 8px; overflow: hidden; border: 1px solid #e5e7eb;"></div>

        <!-- Selected Coordinates Display -->
        <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Selected Latitude</label>
              <input 
                type="text" 
                id="selectedLat" 
                readonly
                class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm"
              >
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Selected Longitude</label>
              <input 
                type="text" 
                id="selectedLng" 
                readonly
                class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-white text-sm"
              >
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex justify-end gap-2 mt-4">
          <button 
            type="button" 
            onclick="closeMapPickerModal()"
            class="px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition font-medium"
          >
            Cancel
          </button>
          <button 
            type="button"
            onclick="confirmMapSelection()"
            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium"
          >
            Use This Location
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Manage Users Modal -->
<div id="usersModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
  <div class="bg-white rounded-lg shadow-xl max-w-4xl w-full p-6 max-h-[90vh] overflow-y-auto">
    <div class="flex justify-between items-center mb-4">
      <div>
        <h2 class="text-lg font-semibold text-gray-800">Manage Users</h2>
        <p id="usersModalLocationName" class="text-sm text-gray-600"></p>
      </div>
      <button onclick="closeUsersModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
    </div>

    <input type="hidden" id="currentLocationId">

    <!-- Add Users Section -->
    <div class="mb-6 p-4 bg-gray-50 rounded-lg">
      <h3 class="text-sm font-semibold text-gray-700 mb-3">Assign Users to This Location</h3>
      <div class="flex gap-2">
        <select 
          id="userSelect" 
          multiple
          size="5"
          class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        >
          <!-- Loaded via JavaScript -->
        </select>
        <button 
          onclick="assignSelectedUsers()"
          class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition self-start"
        >
          Assign →
        </button>
      </div>
      <p class="text-xs text-gray-500 mt-2">Hold Ctrl/Cmd to select multiple users</p>
    </div>

    <!-- Assigned Users List -->
    <div>
      <h3 class="text-sm font-semibold text-gray-700 mb-3">Currently Assigned Users</h3>
      <div class="border border-gray-200 rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Assigned Date</th>
              <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
            </tr>
          </thead>
          <tbody id="assignedUsersTableBody" class="bg-white divide-y divide-gray-200">
            <!-- Loaded via JavaScript -->
          </tbody>
        </table>
      </div>
      <div id="noAssignedUsers" class="hidden text-center py-8 text-gray-500">
        No users assigned to this location yet.
      </div>
    </div>
  </div>
</div>

<script>
let locations = [];
let availableUsers = [];

// Load locations on page load
document.addEventListener('DOMContentLoaded', function() {
  loadLocations();
});

// Load all locations
async function loadLocations() {
  try {
    document.getElementById('loadingState').classList.remove('hidden');
    document.getElementById('emptyState').classList.add('hidden');
    
    const response = await fetch('/attendance/locations/data');
    const data = await response.json();
    
    if (data.success) {
      locations = data.locations;
      renderLocations();
    } else {
      showError('Failed to load locations');
    }
  } catch (error) {
    console.error('Error loading locations:', error);
    showError('Failed to load locations');
  } finally {
    document.getElementById('loadingState').classList.add('hidden');
  }
}

// Render locations table
function renderLocations() {
  const tbody = document.getElementById('locationsTableBody');
  
  if (locations.length === 0) {
    tbody.innerHTML = '';
    document.getElementById('emptyState').classList.remove('hidden');
    return;
  }
  
  document.getElementById('emptyState').classList.add('hidden');
  
  tbody.innerHTML = locations.map(location => `
    <tr class="hover:bg-gray-50">
      <td class="px-6 py-4 whitespace-nowrap">
        <div class="flex items-center">
          <div class="text-sm font-medium text-gray-900">${escapeHtml(location.location_name)}</div>
          ${location.is_primary ? '<span class="ml-2 px-2 py-1 text-xs font-semibold text-blue-800 bg-blue-100 rounded">PRIMARY</span>' : ''}
        </div>
      </td>
      <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
        <div>${location.latitude}, ${location.longitude}</div>
        <a href="https://www.google.com/maps?q=${location.latitude},${location.longitude}" target="_blank" class="text-blue-600 hover:text-blue-800 text-xs">
          View on Map →
        </a>
      </td>
      <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
        ${formatRadius(location.radius_meters)}
      </td>
      <td class="px-6 py-4 whitespace-nowrap">
        <button 
          onclick="openUsersModal(${location.id}, '${escapeHtml(location.location_name)}')"
          class="text-sm text-blue-600 hover:text-blue-800 font-medium"
        >
          ${location.assigned_users_count} user(s) →
        </button>
      </td>
      <td class="px-6 py-4 whitespace-nowrap">
        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${location.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
          ${location.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
        <button onclick="editLocation(${location.id})" class="text-blue-600 hover:text-blue-900">Edit</button>
        <button onclick="deleteLocation(${location.id}, '${escapeHtml(location.location_name)}')" class="text-red-600 hover:text-red-900">Delete</button>
      </td>
    </tr>
  `).join('');
}

// Format radius for display
function formatRadius(meters) {
  if (meters >= 1000) {
    return (meters / 1000).toFixed(1) + ' km';
  }
  return meters + ' m';
}

// Open add location modal
function openAddLocationModal() {
  document.getElementById('modalTitle').textContent = 'Add Location';
  document.getElementById('locationForm').reset();
  document.getElementById('locationId').value = '';
  document.getElementById('isActive').checked = true;
  document.getElementById('locationModal').style.display = 'block';
  document.body.style.overflow = 'hidden'; // Prevent body scroll
}

// Edit location
function editLocation(locationId) {
  const location = locations.find(l => l.id === locationId);
  if (!location) return;
  
  document.getElementById('modalTitle').textContent = 'Edit Location';
  document.getElementById('locationId').value = location.id;
  document.getElementById('locationName').value = location.location_name;
  document.getElementById('latitude').value = location.latitude;
  document.getElementById('longitude').value = location.longitude;
  document.getElementById('radiusMeters').value = location.radius_meters;
  document.getElementById('isPrimary').checked = location.is_primary == 1;
  document.getElementById('isActive').checked = location.is_active == 1;
  document.getElementById('locationModal').style.display = 'block';
  document.body.style.overflow = 'hidden'; // Prevent body scroll
  
  // Trigger map preview update
  updateMapPreview();
}

// Close location modal
function closeLocationModal() {
  document.getElementById('locationModal').style.display = 'none';
  document.body.style.overflow = ''; // Restore body scroll
}

// Save location (create or update)
async function saveLocation(event) {
  event.preventDefault();
  
  const locationId = document.getElementById('locationId').value;
  const formData = {
    location_name: document.getElementById('locationName').value,
    latitude: parseFloat(document.getElementById('latitude').value),
    longitude: parseFloat(document.getElementById('longitude').value),
    radius_meters: parseInt(document.getElementById('radiusMeters').value),
    is_primary: document.getElementById('isPrimary').checked ? 1 : 0,
    is_active: document.getElementById('isActive').checked ? 1 : 0
  };
  
  try {
    const url = locationId 
      ? `/attendance/locations/${locationId}` 
      : '/attendance/locations';
    const method = locationId ? 'PUT' : 'POST';
    
    const response = await fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify(formData)
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess(data.message);
      closeLocationModal();
      loadLocations();
    } else {
      showError(data.message || 'Failed to save location');
    }
  } catch (error) {
    console.error('Error saving location:', error);
    showError('Failed to save location');
  }
}

// Delete location
async function deleteLocation(locationId, locationName) {
  if (!confirm(`Are you sure you want to delete "${locationName}"? All user assignments will also be removed.`)) {
    return;
  }
  
  try {
    const response = await fetch(`/attendance/locations/${locationId}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess(data.message);
      loadLocations();
    } else {
      showError(data.message || 'Failed to delete location');
    }
  } catch (error) {
    console.error('Error deleting location:', error);
    showError('Failed to delete location');
  }
}

// Open users management modal
async function openUsersModal(locationId, locationName) {
  document.getElementById('currentLocationId').value = locationId;
  document.getElementById('usersModalLocationName').textContent = locationName;
  document.getElementById('usersModal').classList.remove('hidden');
  
  // Load available users and assigned users
  await Promise.all([
    loadAvailableUsers(),
    loadAssignedUsers(locationId)
  ]);
}

// Close users modal
function closeUsersModal() {
  document.getElementById('usersModal').classList.add('hidden');
}

// Load available users for assignment
async function loadAvailableUsers() {
  try {
    const response = await fetch('/attendance/locations/available-users');
    const data = await response.json();
    
    if (data.success) {
      availableUsers = data.users;
      renderAvailableUsers();
    }
  } catch (error) {
    console.error('Error loading available users:', error);
  }
}

// Render available users in select dropdown
function renderAvailableUsers() {
  const select = document.getElementById('userSelect');
  select.innerHTML = availableUsers.map(user => `
    <option value="${user.id}">
      ${escapeHtml(user.fullname)}${user.role_name ? ' (' + escapeHtml(user.role_name) + ')' : ''}
    </option>
  `).join('');
}

// Load assigned users for a location
async function loadAssignedUsers(locationId) {
  try {
    const response = await fetch(`/attendance/locations/${locationId}/users`);
    const data = await response.json();
    
    if (data.success) {
      renderAssignedUsers(data.users);
    }
  } catch (error) {
    console.error('Error loading assigned users:', error);
  }
}

// Render assigned users table
function renderAssignedUsers(users) {
  const tbody = document.getElementById('assignedUsersTableBody');
  const noUsersDiv = document.getElementById('noAssignedUsers');
  
  if (users.length === 0) {
    tbody.innerHTML = '';
    noUsersDiv.classList.remove('hidden');
    return;
  }
  
  noUsersDiv.classList.add('hidden');
  tbody.innerHTML = users.map(user => `
    <tr class="hover:bg-gray-50">
      <td class="px-4 py-2 text-sm text-gray-900">${escapeHtml(user.fullname)}</td>
      <td class="px-4 py-2 text-sm text-gray-500">${user.role_name ? escapeHtml(user.role_name) : '-'}</td>
      <td class="px-4 py-2 text-sm text-gray-500">${formatDate(user.assigned_at)}</td>
      <td class="px-4 py-2 text-sm">
        <button 
          onclick="removeUserAssignment(${user.assignment_id}, '${escapeHtml(user.fullname)}')"
          class="text-red-600 hover:text-red-900"
        >
          Remove
        </button>
      </td>
    </tr>
  `).join('');
}

// Assign selected users to location
async function assignSelectedUsers() {
  const locationId = document.getElementById('currentLocationId').value;
  const select = document.getElementById('userSelect');
  const selectedOptions = Array.from(select.selectedOptions);
  
  if (selectedOptions.length === 0) {
    showError('Please select at least one user');
    return;
  }
  
  const userIds = selectedOptions.map(option => parseInt(option.value));
  
  try {
    const response = await fetch(`/attendance/locations/${locationId}/assign-users`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      },
      body: JSON.stringify({ user_ids: userIds })
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess(data.message);
      loadAssignedUsers(locationId);
      loadLocations(); // Refresh counts
      select.selectedIndex = -1; // Clear selection
    } else {
      showError(data.message || 'Failed to assign users');
    }
  } catch (error) {
    console.error('Error assigning users:', error);
    showError('Failed to assign users');
  }
}

// Remove user assignment
async function removeUserAssignment(assignmentId, userName) {
  if (!confirm(`Remove ${userName} from this location?`)) {
    return;
  }
  
  try {
    const response = await fetch(`/attendance/locations/assignments/${assignmentId}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
      }
    });
    
    const data = await response.json();
    
    if (data.success) {
      showSuccess(data.message);
      const locationId = document.getElementById('currentLocationId').value;
      loadAssignedUsers(locationId);
      loadLocations(); // Refresh counts
    } else {
      showError(data.message || 'Failed to remove assignment');
    }
  } catch (error) {
    console.error('Error removing assignment:', error);
    showError('Failed to remove assignment');
  }
}

// Utility functions
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function formatDate(dateString) {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function showSuccess(message) {
  alert('✓ ' + message);
}

function showError(message) {
  alert('✗ ' + message);
}

// Map Picker Functions
let map;
let marker;
let selectedLatLng = null;

function openMapPicker() {
  const lat = parseFloat(document.getElementById('latitude').value) || 33.70811597;
  const lng = parseFloat(document.getElementById('longitude').value) || 73.08868750;
  
  document.getElementById('mapPickerModal').classList.remove('hidden');
  
  // Load Google Maps API if not already loaded
  if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
    loadGoogleMapsAPI(() => {
      setTimeout(() => {
        initializeMap(lat, lng);
      }, 100);
    });
  } else {
    // Initialize map after modal is visible
    setTimeout(() => {
      initializeMap(lat, lng);
    }, 100);
  }
}

function loadGoogleMapsAPI(callback) {
  const script = document.createElement('script');
  // Note: Replace with your valid Google Maps API key
  const apiKey = 'AIzaSyBCPL4stand6rGPdoWOXhcwLwHzYxqxwA';
  script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&libraries=places`;
  script.async = true;
  script.defer = true;
  script.onload = callback;
  script.onerror = () => {
    console.error('Failed to load Google Maps API');
    alert('Failed to load Google Maps. This may be due to:\n\n1. Invalid or restricted API key\n2. Network connection issues\n3. API key billing not enabled\n\nPlease contact your administrator to configure a valid Google Maps API key.');
    closeMapPickerModal();
  };
  
  // Listen for Google Maps API errors
  window.gm_authFailure = function() {
    console.error('Google Maps authentication failed');
    alert('Google Maps API Key Error:\n\nThe API key is invalid, expired, or not properly configured.\n\nPlease contact your administrator to update the Google Maps API key in the application settings.');
    closeMapPickerModal();
  };
  
  document.head.appendChild(script);
}

function initializeMap(lat, lng) {
  const mapContainer = document.getElementById('interactiveMap');
  
  try {
    // Create map
    map = new google.maps.Map(mapContainer, {
      center: {lat: lat, lng: lng},
      zoom: 15,
      mapTypeControl: true,
      streetViewControl: true,
      fullscreenControl: true,
      zoomControl: true,
    });
    
    // Create marker
    marker = new google.maps.Marker({
      position: {lat: lat, lng: lng},
      map: map,
      draggable: true,
      title: 'Drag me to select location',
      animation: google.maps.Animation.DROP,
    });
    
    // Set initial selected coordinates
    selectedLatLng = {lat: lat, lng: lng};
    updateSelectedCoordinates(lat, lng);
    
    // Add click listener to map
    map.addListener('click', (event) => {
      const clickedLat = event.latLng.lat();
      const clickedLng = event.latLng.lng();
      
      marker.setPosition(event.latLng);
      selectedLatLng = {lat: clickedLat, lng: clickedLng};
      updateSelectedCoordinates(clickedLat, clickedLng);
    });
    
    // Add drag listener to marker
    marker.addListener('dragend', (event) => {
      const draggedLat = event.latLng.lat();
      const draggedLng = event.latLng.lng();
      
      selectedLatLng = {lat: draggedLat, lng: draggedLng};
      updateSelectedCoordinates(draggedLat, draggedLng);
    });
  } catch (error) {
    console.error('Error initializing map:', error);
    alert('Error loading map. Please try again.');
    closeMapPickerModal();
  }
}

function updateSelectedCoordinates(lat, lng) {
  document.getElementById('selectedLat').value = lat.toFixed(8);
  document.getElementById('selectedLng').value = lng.toFixed(8);
}

function confirmMapSelection() {
  if (selectedLatLng) {
    document.getElementById('latitude').value = selectedLatLng.lat.toFixed(8);
    document.getElementById('longitude').value = selectedLatLng.lng.toFixed(8);
    updateMapPreview();
    closeMapPickerModal();
    alert('✓ Location selected successfully!');
  }
}

function closeMapPickerModal() {
  document.getElementById('mapPickerModal').classList.add('hidden');
  // Clean up map
  if (map) {
    map = null;
  }
  if (marker) {
    marker = null;
  }
}

function getCurrentLocation() {
  if (!navigator.geolocation) {
    alert('Geolocation is not supported by your browser');
    return;
  }
  
  const btn = event.target;
  btn.disabled = true;
  btn.textContent = '📍 Getting location...';
  
  navigator.geolocation.getCurrentPosition(
    (position) => {
      document.getElementById('latitude').value = position.coords.latitude.toFixed(8);
      document.getElementById('longitude').value = position.coords.longitude.toFixed(8);
      updateMapPreview();
      btn.disabled = false;
      btn.textContent = '📱 Use My Location';
      alert('✓ Location captured successfully!');
    },
    (error) => {
      btn.disabled = false;
      btn.textContent = '📱 Use My Location';
      alert('Error getting location: ' + error.message);
    },
    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 0
    }
  );
}

function updateMapPreview() {
  const lat = document.getElementById('latitude').value;
  const lng = document.getElementById('longitude').value;
  
  if (lat && lng) {
    const mapPreview = document.getElementById('mapPreview');
    const mapFrame = document.getElementById('mapFrame');
    
    // Update iframe src with embedded map
    mapFrame.src = `https://maps.google.com/maps?q=${lat},${lng}&z=15&output=embed`;
    mapPreview.classList.remove('hidden');
  }
}
</script>

<style>
/* Custom scrollbar for modal */
#usersModal > div {
  scrollbar-width: thin;
  scrollbar-color: #cbd5e0 #f7fafc;
}

#usersModal > div::-webkit-scrollbar {
  width: 8px;
}

#usersModal > div::-webkit-scrollbar-track {
  background: #f7fafc;
}

#usersModal > div::-webkit-scrollbar-thumb {
  background-color: #cbd5e0;
  border-radius: 4px;
}
</style>
@endsection

