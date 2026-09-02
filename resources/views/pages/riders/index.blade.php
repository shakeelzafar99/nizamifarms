@extends('layouts.app')

@section('title', 'Rider Management')

@section('content')
<div class="max-w-6xl mx-auto p-6">
    <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Rider Management</h1>
            <p class="text-sm text-gray-500 mt-1">People, contact, hire dates, and vehicles. Shifts are set in the Shift Planner; attendance uses the hire date and company-bike flag from here.</p>
        </div>
        <div class="flex gap-2 items-center">
            <a href="/attendance" class="px-3 py-2 bg-white text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition border border-gray-300 inline-flex items-center gap-1" style="text-decoration:none;">🕐 Attendance</a>
            <a href="/shift-planner" class="px-3 py-2 bg-white text-gray-700 rounded-lg text-sm font-medium hover:bg-gray-50 transition border border-gray-300 inline-flex items-center gap-1" style="text-decoration:none;">📅 Shift Planner</a>
            <button
                type="button"
                onclick="openAddRiderModal()"
                class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors shadow-sm inline-flex items-center gap-1"
            >
                + Add Rider
            </button>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-md">
            <p class="text-green-700">{{ session('success') }}</p>
            {{-- Show the pin that was actually stored, with a map link. A home pin is invisible
                 once the modal closes; on 31 Aug an OFFICE link was pasted as a rider's home and
                 nothing on screen would have revealed it. --}}
            @if(session('pin_moved'))
                @php $pm = session('pin_moved'); @endphp
                <p class="text-green-700" style="margin-top:6px;font-size:13px;">
                    <a href="https://www.google.com/maps/search/?api=1&query={{ $pm['lat'] }},{{ $pm['lng'] }}"
                       target="_blank" rel="noopener"
                       style="color:#15803d;font-weight:700;text-decoration:underline;">
                        Check it on the map ↗
                    </a>
                    — make sure this is the rider's home, not the office.
                </p>
            @endif
        </div>
    @endif

    {{-- A link we could not read. The rest of the profile WAS saved; only the pin was left
         alone. Amber, not red, because nothing was lost. --}}
    @if(session('warning'))
        <div class="mb-4 p-4 bg-amber-50 border border-amber-300 rounded-md">
            <p class="text-amber-800">⚠ {{ session('warning') }}</p>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
            <p class="text-red-700">{{ session('error') }}</p>
        </div>
    @endif

    @php
        $ridersArr = is_object($riders) && method_exists($riders, 'all') ? $riders->all() : (array) $riders;
        $missingHire = collect($ridersArr)->filter(fn($r) => empty($r->hire_date))->count();
        $avatarBg = ['#EEF2FF' => '#4338CA', '#ECFDF5' => '#047857', '#FEF2F2' => '#B91C1C', '#FEF3C7' => '#B45309', '#F0F9FF' => '#0369A1', '#FDF2F8' => '#BE185D'];
        $avatarKeys = array_keys($avatarBg);
    @endphp

    @if($missingHire > 0)
        <div class="mb-4 p-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center gap-2 text-sm text-amber-800">
            <span>📅</span>
            <span><strong>{{ $missingHire }}</strong> rider{{ $missingHire > 1 ? 's have' : ' has' }} no hire date. Attendance won't count them absent before joining once it's set — add it via <strong>Edit</strong>.</span>
        </div>
    @endif

    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        <table class="min-w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rider</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Contact</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Vehicle</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Current shift</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Hire date</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($riders as $i => $rider)
                @php
                    $parts = preg_split('/\s+/', trim($rider->fullname ?? ''));
                    $initials = strtoupper(substr($parts[0] ?? '?', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
                    $bgKey = $avatarKeys[$i % count($avatarKeys)];
                    $isBike = ($rider->company_bike ?? 0) == 1;
                    $hasHome = ($rider->home_latitude ?? null) !== null && ($rider->home_latitude ?? null) !== '';
                    $anyOffice = ($rider->checkin_any_office ?? 0) == 1;
                    $meterExempt = isset($rider->meter_required) && (int) $rider->meter_required === 0;
                    $active = $rider->profile_active ?? true;
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3.5 whitespace-nowrap">
                        <div class="flex items-center gap-3">
                            <div style="width:38px;height:38px;border-radius:9999px;background:{{ $bgKey }};color:{{ $avatarBg[$bgKey] }};display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;flex-shrink:0;">{{ $initials }}</div>
                            <div>
                                <div class="text-sm font-semibold text-gray-900">{{ $rider->fullname }}</div>
                                <div class="text-xs text-gray-400">{{ $rider->email }}</div>
                                @if($isBike || $anyOffice || $meterExempt)
                                <div class="flex flex-wrap items-center gap-1 mt-1">
                                    @if($isBike)
                                        <span title="Company bike — goes home with the rider" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-indigo-50 text-indigo-700 border border-indigo-100">🏍 bike</span>
                                        @if($hasHome)
                                            <span title="Home location is set — going-home check active" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-100">🏠 home set</span>
                                        @else
                                            <span title="No home location yet — the going-home check stays off. Edit to add it." class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-100">🏠 no home</span>
                                        @endif
                                    @endif
                                    @if($anyOffice)
                                        <span title="May check in at any office location" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-sky-50 text-sky-700 border border-sky-100">🏢 any office</span>
                                    @endif
                                    @if($meterExempt)
                                        <span title="Meter reading NOT required for this user (management exemption)" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-gray-100 text-gray-500 border border-gray-200">⛽ meter exempt</span>
                                    @endif
                                </div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap text-sm">
                        @if($rider->phone)
                            <span class="text-gray-700">{{ $rider->phone }}</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap">
                        @if($rider->vehicle_type || $rider->vehicle_plate || $isBike)
                            <div class="flex items-center gap-2">
                                <div>
                                    <div class="text-sm text-gray-700 capitalize">{{ $rider->vehicle_type ?: 'Vehicle' }}</div>
                                    @if($rider->vehicle_plate)<div class="text-xs text-gray-400">{{ $rider->vehicle_plate }}</div>@endif
                                </div>
                                @if($isBike)
                                    <span title="Company bike — goes home with the rider (overnight meter check on)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100">🏍 Company</span>
                                @endif
                            </div>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap">
                        @if($rider->cur_shift_name)
                            <div class="text-sm font-medium text-gray-800">{{ $rider->cur_shift_name }}</div>
                            <div class="text-xs text-gray-500">
                                {{ $rider->cur_shift_start ? \Illuminate\Support\Str::of($rider->cur_shift_start)->substr(0,5) : '' }}{{ $rider->cur_shift_end ? ' – '.\Illuminate\Support\Str::of($rider->cur_shift_end)->substr(0,5) : ' onwards' }}@if($rider->cur_location) · 📍{{ $rider->cur_location }}@endif
                            </div>
                        @else
                            <span class="text-gray-300 text-sm">—</span>
                        @endif
                        <a href="/shift-planner" class="text-xs text-blue-600 hover:underline">Shift planner →</a>
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap text-sm">
                        @if($rider->hire_date)
                            <span class="text-gray-700">{{ \Carbon\Carbon::parse($rider->hire_date)->format('M d, Y') }}</span>
                        @else
                            <button onclick="editRiderProfile({{ $rider->id }})" class="inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5 hover:bg-amber-100">+ Set date</button>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap">
                        @if($active)
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-100"><span style="width:6px;height:6px;border-radius:9999px;background:#16a34a;display:inline-block;"></span>Active</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 border border-gray-200">Inactive</span>
                        @endif
                    </td>
                    <td class="px-5 py-3.5 whitespace-nowrap text-right">
                        <button onclick="editRiderProfile({{ $rider->id }})" class="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline">Edit</button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @if(count($ridersArr) === 0)
            <div class="text-center py-12 text-gray-400 text-sm">No active riders. Use <strong>Add Rider Profile</strong> to create one.</div>
        @endif
    </div>
</div>

<!-- Rider Profile Modal -->
<div id="riderProfileModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 9999;">
    <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 90%; max-width: 600px; max-height: 92vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);">
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

            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; cursor: pointer;">
                    <input type="checkbox" name="company_bike" id="rider_company_bike" value="1" style="width: 16px; height: 16px;" onchange="toggleGraceRow()">
                    Company bike (goes home with rider)
                </label>
                <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 24px;">Enables the overnight-usage meter check for this rider.</p>

                <div id="rider_grace_row" style="display: none; margin: 10px 0 0 24px;">
                    <label style="display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px;">Overnight grace (km)</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" name="overnight_grace_km" id="rider_overnight_grace" min="0" step="1" placeholder="default 30" style="width: 130px; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                        <span style="font-size: 12px; color: #9ca3af;">km/day allowed off company hours</span>
                    </div>
                    <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 0;">Flag when the morning start meter jumps more than this over yesterday's end. Leave blank to use the global default.</p>

                    <!-- U4 — HOME pin for the going-home journey (ETA + home meter). -->
                    <div style="margin-top: 14px; padding: 10px 12px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #065F46; margin-bottom: 4px;">🏠 Home location (going-home check)</label>
                        <p style="font-size: 12px; color: #047857; margin: 0 0 8px 0;">Paste a Google Maps share link of the rider's home — or type coordinates. After checkout the app times his ride home and asks for the meter at home.</p>
                        <input type="text" name="home_maps_url" id="rider_home_maps_url" placeholder="Paste Google Maps link (https://maps.app.goo.gl/…)" style="width: 100%; padding: 8px 12px; border: 1px solid #6EE7B7; border-radius: 6px; margin-bottom: 8px;">
                        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                            <input type="number" step="any" name="home_latitude" id="rider_home_lat" placeholder="Latitude" style="width: 130px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <input type="number" step="any" name="home_longitude" id="rider_home_lng" placeholder="Longitude" style="width: 130px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                            <input type="number" name="home_radius_m" id="rider_home_radius" min="30" step="10" placeholder="radius 150 m" style="width: 110px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                        </div>
                        <p id="rider_home_status" style="font-size: 12px; color: #047857; margin: 6px 0 0 0;"></p>
                        <p style="font-size: 11px; color: #6b7280; margin: 4px 0 0 0;">A pasted link overrides the typed coordinates. Clear all fields to remove the home pin. A Plus Code (like <code>P35Q+5FF</code>) works too — paste it in the link box.</p>
                        <p style="font-size: 11px; color: #92400e; margin: 4px 0 0 0;">If the link can't be read, the pin is left unchanged and the rest of the profile still saves — you'll see an amber note at the top of the page.</p>
                    </div>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; cursor: pointer;">
                    <input type="checkbox" name="checkin_any_office" id="rider_any_office" value="1" style="width: 16px; height: 16px;">
                    May check in at any office location
                </label>
                <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 24px;">For floating staff — lets this rider check in at ANY office (not just their assigned one) when the "must be at your location" rule is on. He must still be AT an office.</p>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; cursor: pointer;">
                    <input type="checkbox" name="meter_required" id="rider_meter_required" value="1" checked style="width: 16px; height: 16px;">
                    Meter reading is compulsory
                </label>
                <p style="font-size: 12px; color: #6b7280; margin: 4px 0 0 24px;">On (default): the app requires the meter reading — company-bike riders record it at HOME, everyone else at checkout. Untick for management users who don't record meters.</p>
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

function toggleGraceRow() {
    var on = document.getElementById('rider_company_bike').checked;
    document.getElementById('rider_grace_row').style.display = on ? 'block' : 'none';
}

function openAddRiderModal() {
    document.getElementById('riderModalTitle').textContent = 'Add Rider Profile';
    document.getElementById('riderProfileForm').reset();
    document.getElementById('rider_user_id').value = '';
    toggleGraceRow();
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
            document.getElementById('rider_company_bike').checked = (Number(p.company_bike) === 1);
            document.getElementById('rider_overnight_grace').value = (p.overnight_grace_km != null ? p.overnight_grace_km : '');
            // Home pin (U4) — prefill saved coords; the link box stays empty (paste replaces).
            document.getElementById('rider_home_maps_url').value = '';
            document.getElementById('rider_home_lat').value = (p.home_latitude != null ? p.home_latitude : '');
            document.getElementById('rider_home_lng').value = (p.home_longitude != null ? p.home_longitude : '');
            document.getElementById('rider_home_radius').value = (p.home_radius_m != null ? p.home_radius_m : '');
            // Status line: the date is the date the pin last MOVED (the server only stamps
            // home_set_at on a real change), and the map link lets the manager confirm WHERE
            // it is before he changes it — the check that was missing on 31 Aug.
            var homeStatus = document.getElementById('rider_home_status');
            if (p.home_latitude != null && p.home_longitude != null) {
                var q = encodeURIComponent(p.home_latitude + ',' + p.home_longitude);
                homeStatus.innerHTML =
                    '✓ Home pin set' + (p.home_set_at ? ' ' + String(p.home_set_at).slice(0, 10) : '') +
                    ' · <a href="https://www.google.com/maps/search/?api=1&query=' + q + '"' +
                    ' target="_blank" rel="noopener" style="color:#047857;font-weight:700;text-decoration:underline;">' +
                    'view on map ↗</a>';
            } else {
                homeStatus.textContent = (Number(p.company_bike) === 1)
                    ? '⚠ No home pin yet — the going-home check stays off for this rider.'
                    : '';
            }
            document.getElementById('rider_any_office').checked = (Number(p.checkin_any_office) === 1);
            // Meter compulsory — default ON when the profile predates the column (null).
            document.getElementById('rider_meter_required').checked = (p.meter_required == null) ? true : (Number(p.meter_required) === 1);
            document.getElementById('rider_active').value = p.active ? '1' : '0';
            document.getElementById('rider_notes').value = p.notes || '';
            toggleGraceRow();
        }
    } catch (e) {
        console.warn('Failed to load rider profile', e);
    }

    document.getElementById('riderProfileModal').style.display = 'block';
}
</script>
@endsection
