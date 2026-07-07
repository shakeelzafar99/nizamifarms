@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Rider Management</h1>
        <div class="flex gap-3">
            <a
                href="/shift-planner"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-md border-2 border-blue-700 inline-flex items-center gap-2"
                style="min-width: 150px; text-decoration: none;"
            >
                📅 Shift Planner
            </a>
            <button 
                type="button"
                onclick="openAddRiderModal()" 
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors shadow-md border-2 border-blue-700"
            >
                ➕ Add Rider Profile
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-green-700">{{ session('success') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rider</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Shift Times</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hire Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($riders as $rider)
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div>
                            <div class="text-sm font-medium text-gray-900">{{ $rider->fullname }}</div>
                            <div class="text-sm text-gray-500">{{ $rider->email }}</div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {{ $rider->phone ?: 'Not set' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($rider->vehicle_type || $rider->vehicle_plate)
                            <div class="text-sm text-gray-900">{{ $rider->vehicle_type ?: 'Unknown' }}</div>
                            <div class="text-sm text-gray-500">{{ $rider->vehicle_plate ?: 'No plate' }}</div>
                        @else
                            <span class="text-sm text-gray-400">Not set</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="text-sm">
                            <span class="font-medium text-gray-900">{{ $rider->shift_start ?? '11:00' }} - {{ $rider->shift_end ?? '19:00' }}</span>
                            <br>
                            <a href="/shift-planner" class="text-xs text-blue-600 hover:text-blue-800">Shift planner →</a>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        {{ $rider->hire_date ? \Carbon\Carbon::parse($rider->hire_date)->format('M d, Y') : 'Not set' }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        @if($rider->profile_active ?? true)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">Inactive</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="editRiderProfile({{ $rider->id }})" class="text-blue-600 hover:text-blue-900">Edit</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Rider Profile Modal -->
<div id="riderProfileModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 600px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
            <h3 id="riderModalTitle" style="font-size: 18px; font-weight: 600; margin: 0;">Add Rider Profile</h3>
        </div>
        
        <form id="riderProfileForm" action="{{ route('riders.store') }}" method="POST" style="padding: 20px;">
            @csrf
            <input type="hidden" id="rider_user_id" name="user_id">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Phone</label>
                    <input type="text" name="phone" id="rider_phone" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Emergency Contact</label>
                    <input type="text" name="emergency_contact" id="rider_emergency" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Vehicle Type</label>
                    <select name="vehicle_type" id="rider_vehicle_type" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="">Select Vehicle</option>
                        <option value="bike">Bike</option>
                        <option value="car">Car</option>
                        <option value="van">Van</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Vehicle Plate</label>
                    <input type="text" name="vehicle_plate" id="rider_vehicle_plate" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Hire Date</label>
                    <input type="date" name="hire_date" id="rider_hire_date" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                </div>
                <div>
                    <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Status</label>
                    <select name="active" id="rider_active" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px;">Notes</label>
                <textarea name="notes" id="rider_notes" rows="3" style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;"></textarea>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" onclick="closeModal('riderProfileModal')" style="padding: 8px 16px; border: 1px solid #d1d5db; background: white; color: #374151; border-radius: 6px;">Cancel</button>
                <button type="submit" style="padding: 8px 16px; background: #2563eb; color: white; border: none; border-radius: 6px;">Save Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- Legacy bulk shift modal removed - use /shifts page for shift management -->

<script>
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function openAddRiderModal() {
    document.getElementById('riderModalTitle').textContent = 'Add Rider Profile';
    document.getElementById('riderProfileForm').reset();
    document.getElementById('rider_user_id').value = '';
    document.getElementById('riderProfileModal').style.display = 'block';
}

// Note: Shift management has been moved to /shifts page
// Use the "Manage Shifts" button to assign shifts to riders

async function editRiderProfile(userId) {
    document.getElementById('riderModalTitle').textContent = 'Edit Rider Profile';
    document.getElementById('rider_user_id').value = userId;
    
    try {
        const res = await fetch(`/riders/${userId}`, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        
        if (data.success && data.profile) {
            const p = data.profile;
            document.getElementById('rider_phone').value = p.phone || '';
            document.getElementById('rider_emergency').value = p.emergency_contact || '';
            document.getElementById('rider_vehicle_type').value = p.vehicle_type || '';
            document.getElementById('rider_vehicle_plate').value = p.vehicle_plate || '';
            document.getElementById('rider_hire_date').value = p.hire_date || '';
            document.getElementById('rider_active').value = p.active ? '1' : '0';
            document.getElementById('rider_notes').value = p.notes || '';
        }
    } catch (e) {
        console.warn('Failed to load rider profile', e);
    }
    
    document.getElementById('riderProfileModal').style.display = 'block';
}
</script>
@endsection
