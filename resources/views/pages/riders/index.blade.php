@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Rider Management</h1>
        <div class="flex gap-3">
            <button 
                type="button"
                onclick="openBulkShiftModal()" 
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-md border-2 border-blue-700"
                style="min-width: 150px;"
            >
                Set Bulk Shifts
            </button>
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
                        <div class="flex items-center gap-2">
                            <input 
                                type="time" 
                                id="shift_start_{{ $rider->user_id }}" 
                                value="{{ $rider->shift_start ?? '09:00' }}" 
                                class="px-2 py-1 border border-gray-300 rounded text-xs w-24"
                            >
                            <span class="text-xs text-gray-500">to</span>
                            <input 
                                type="time" 
                                id="shift_end_{{ $rider->user_id }}" 
                                value="{{ $rider->shift_end ?? '17:00' }}" 
                                class="px-2 py-1 border border-gray-300 rounded text-xs w-24"
                            >
                            <button 
                                onclick="saveRiderShift({{ $rider->user_id }})"
                                class="px-2 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 text-xs"
                                title="Save shift times"
                            >
                                💾
                            </button>
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

<!-- Bulk Shift Update Modal -->
<div id="bulkShiftModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4" style="z-index: 9999;">
    <div class="bg-white rounded-xl shadow-2xl overflow-hidden" style="width: min(92vw, 600px); max-height: 85vh;">
        <!-- Sticky Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center bg-white sticky top-0 z-10">
            <div>
                <h3 class="text-xl font-semibold text-gray-900">Set Bulk Shift Times</h3>
                <p class="text-sm text-gray-500 mt-1">Set shift times for multiple riders at once</p>
            </div>
            <button type="button" onclick="closeBulkShiftModal()" class="text-gray-400 hover:text-gray-600 text-2xl ml-4">&times;</button>
        </div>
        
        <!-- Scrollable Body -->
        <div class="p-6 space-y-4" style="max-height: calc(85vh - 140px); overflow-y: auto;">
            <!-- Shift Time Inputs -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <label class="block text-sm font-medium text-gray-700 mb-3">Default Shift Times</label>
                <div class="flex items-center gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Start Time</label>
                        <input 
                            type="time" 
                            id="bulkShiftStart" 
                            value="09:00" 
                            class="px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        >
                    </div>
                    <span class="text-gray-500 mt-5">to</span>
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">End Time</label>
                        <input 
                            type="time" 
                            id="bulkShiftEnd" 
                            value="17:00" 
                            class="px-3 py-2 border border-gray-300 rounded-lg text-sm"
                        >
                    </div>
                </div>
            </div>

            <!-- Override Options -->
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <label class="block text-sm font-medium text-gray-700 mb-3">Update Strategy</label>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input 
                            type="radio" 
                            name="bulkShiftStrategy" 
                            value="all" 
                            checked
                            class="w-4 h-4 text-blue-600"
                        >
                        <span class="text-sm text-gray-700">
                            <strong>Override All</strong> - Update all riders regardless of current shift
                        </span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input 
                            type="radio" 
                            name="bulkShiftStrategy" 
                            value="default_only"
                            class="w-4 h-4 text-blue-600"
                        >
                        <span class="text-sm text-gray-700">
                            <strong>Default Only</strong> - Only update riders with default shift (09:00-17:00 or null)
                        </span>
                    </label>
                </div>
            </div>

            <!-- Preview -->
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                <p class="text-xs text-yellow-800">
                    💡 <strong>Tip:</strong> Individual shifts set on this page will persist unless you choose "Override All"
                </p>
            </div>
        </div>

        <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
            <button 
                onclick="closeBulkShiftModal()" 
                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition"
            >
                Cancel
            </button>
            <button 
                type="button"
                onclick="executeBulkShiftUpdate()" 
                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold shadow-md"
            >
                Apply to Riders
            </button>
        </div>
    </div>
</div>

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

// Save individual rider shift times
async function saveRiderShift(userId) {
    const startInput = document.getElementById(`shift_start_${userId}`);
    const endInput = document.getElementById(`shift_end_${userId}`);
    
    if (!startInput || !endInput) {
        alert('❌ Could not find shift inputs');
        return;
    }
    
    const start = startInput.value;
    const end = endInput.value;
    
    if (!start || !end) {
        alert('⚠️ Please set both start and end times');
        return;
    }
    
    try {
        const res = await fetch('/riders/shift', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ 
                user_id: userId, 
                shift_start: start, 
                shift_end: end 
            })
        });
        
        const json = await res.json();
        if (json.success) {
            alert('✅ Shift times updated successfully!');
        } else {
            alert('❌ Error: ' + (json.message || 'Failed to update shift'));
        }
    } catch(e) {
        console.error('Error saving shift:', e);
        alert('❌ Error saving shift times');
    }
}

// Bulk shift modal functions
function openBulkShiftModal() {
    console.log('openBulkShiftModal called');
    const modal = document.getElementById('bulkShiftModal');
    console.log('Modal element:', modal);
    
    if (!modal) {
        alert('❌ Error: Bulk shift modal not found in page!');
        console.error('bulkShiftModal element does not exist');
        return;
    }
    // Portalize to body to avoid clipping and enforce overlay styles
    try {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    } catch (e) {
        console.warn('Could not portalize bulkShiftModal:', e);
    }
    // Lock background scroll
    window.__prevBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    
    modal.classList.remove('hidden');
    Object.assign(modal.style, {
        display: 'flex',
        position: 'fixed',
        top: '0', left: '0', right: '0', bottom: '0',
        zIndex: '99999',
        backgroundColor: 'rgba(0,0,0,0.5)',
        overscrollBehavior: 'contain'
    });
    console.log('Modal should be visible now');
}

function closeBulkShiftModal() {
    const modal = document.getElementById('bulkShiftModal');
    if (modal) {
        modal.classList.add('hidden');
        modal.style.display = 'none';
        // Restore background scroll
        document.body.style.overflow = window.__prevBodyOverflow || '';
    }
}

async function executeBulkShiftUpdate() {
    const start = document.getElementById('bulkShiftStart').value;
    const end = document.getElementById('bulkShiftEnd').value;
    const strategy = document.querySelector('input[name="bulkShiftStrategy"]:checked').value;
    
    if (!start || !end) {
        alert('⚠️ Please set both start and end times');
        return;
    }
    
    if (!confirm(`Are you sure you want to update shift times to ${start} - ${end}?\n\nStrategy: ${strategy === 'all' ? 'Override All Riders' : 'Default Shifts Only'}`)) {
        return;
    }
    
    // Get all rider user IDs and their current shifts from the page
    const allRiders = [];
    document.querySelectorAll('[id^="shift_start_"]').forEach(input => {
        const userId = input.id.replace('shift_start_', '');
        const currentStart = input.value;
        const currentEnd = document.getElementById(`shift_end_${userId}`).value;
        allRiders.push({ userId, currentStart, currentEnd });
    });
    
    // Filter based on strategy
    let ridersToUpdate = allRiders;
    if (strategy === 'default_only') {
        ridersToUpdate = allRiders.filter(r => {
            const isDefault = (r.currentStart === '09:00' && r.currentEnd === '17:00') || 
                            !r.currentStart || !r.currentEnd;
            return isDefault;
        });
    }
    
    if (ridersToUpdate.length === 0) {
        alert('ℹ️ No riders to update based on selected strategy');
        return;
    }
    
    // Show progress
    const originalText = event.target.textContent;
    event.target.textContent = `Updating ${ridersToUpdate.length} riders...`;
    event.target.disabled = true;
    
    let successCount = 0;
    let errorCount = 0;
    
    // Update each rider
    for (const rider of ridersToUpdate) {
        try {
            const res = await fetch('/riders/shift', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ 
                    user_id: rider.userId, 
                    shift_start: start, 
                    shift_end: end 
                })
            });
            
            const json = await res.json();
            if (json.success) {
                successCount++;
                // Update UI
                document.getElementById(`shift_start_${rider.userId}`).value = start;
                document.getElementById(`shift_end_${rider.userId}`).value = end;
            } else {
                errorCount++;
            }
        } catch(e) {
            console.error('Error updating rider:', rider.userId, e);
            errorCount++;
        }
    }
    
    // Reset button
    event.target.textContent = originalText;
    event.target.disabled = false;
    
    // Show result
    if (errorCount === 0) {
        alert(`✅ Successfully updated ${successCount} riders!`);
        closeBulkShiftModal();
    } else {
        alert(`⚠️ Updated ${successCount} riders\n❌ Failed: ${errorCount} riders`);
    }
}

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
