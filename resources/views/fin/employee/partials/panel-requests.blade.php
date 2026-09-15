{{-- ══════════ Daily Closing · LEFT PANE BODY · Requests ══════════════════
     Petrol + maintenance requests waiting for approval.

     Sep-2026: lifted OUT of outstanding-invoices.blade.php unchanged, so the
     page and `EmployeeCashController::dailyClosingPanels()` (the in-place
     background refresh) render from ONE source and can never drift. The move
     was byte-verified: the page rendered identically before and after.

     Needs: $pendingPetrolRequests, $pendingMaintenanceRequests,
            $petrolPaymentAccounts, $petrolPayBanks
     ⚠ Every id and inline handler in here is load-bearing — approvePetrolRequest(),
     rejectPetrolRequest() and petrolSourceChanged() find their rows and their
     <select>s by id. Do not rename them.

     ⚠⚠ The per-rider group ids key off `rider_user_id`, NOT the loop index they
     used before. The background refresh remembers which groups the operator had
     collapsed and restores them after a swap — and an index-keyed id silently
     means a DIFFERENT rider once the pending set changes, so it would have
     re-collapsed the wrong person's requests. --}}
    <!-- ⛽ Petrol Requests (Meter-based + Manual) -->
    @if(isset($pendingPetrolRequests) && $pendingPetrolRequests)
    <div class="dc-panel">
        <div style="background: linear-gradient(to right, #fff7ed, #ffedd5); border: 2px solid #fdba74;" class="rounded-lg shadow-sm overflow-hidden">
            <!-- Header -->
            <div style="background: linear-gradient(to right, #ea580c, #c2410c);" class="px-4 py-3 flex items-center justify-between cursor-pointer" onclick="document.getElementById('petrol-requests-body').classList.toggle('hidden')">
                <div class="flex items-center gap-3">
                    <span class="text-lg">⛽</span>
                    <h3 class="text-sm font-bold text-white">Petrol Requests</h3>
                </div>
                <div class="flex items-center gap-3">
                    <span class="animate-pulse text-xs bg-white text-orange-700 px-2 py-0.5 rounded-full font-bold">{{ $pendingPetrolRequests['total_count'] }} Pending</span>
                    <span class="text-xs bg-orange-900 bg-opacity-30 text-white px-2 py-0.5 rounded-full font-bold">Rs. {{ number_format($pendingPetrolRequests['total_amount']) }}</span>
                    <svg class="w-4 h-4 text-white transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
            </div>
            <!-- Body (auto-expanded since these are pending) -->
            <div id="petrol-requests-body">
                <!-- Summary Row -->
                <div class="px-4 py-2 flex gap-4 border-b" style="border-color: #fdba74;">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Total Requests:</span>
                        <span class="text-xs font-bold text-gray-900">{{ $pendingPetrolRequests['total_count'] }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Total Amount:</span>
                        <span class="text-xs font-bold text-orange-700">Rs. {{ number_format($pendingPetrolRequests['total_amount'], 2) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Riders:</span>
                        <span class="text-xs font-bold text-gray-900">{{ count($pendingPetrolRequests['by_rider']) }}</span>
                    </div>
                </div>
                
                <!-- Rider Groups -->
                @foreach($pendingPetrolRequests['by_rider'] as $riderIdx => $riderData)
                <div class="border-b last:border-b-0" style="border-color: #fdba74;">
                    <!-- Rider Header -->
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-orange-50 transition-colors" 
                         onclick="document.getElementById('petrol-rider-{{ $riderData['rider_user_id'] ?? $riderIdx }}').classList.toggle('hidden')">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold text-gray-800">{{ $riderData['rider_name'] }}</span>
                            <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-medium">{{ $riderData['count'] }} request(s)</span>
                            {{-- Month view: the rider's full fuel month (meter km, every claim,
                                 duplicate flags) so the approver can judge THIS request in
                                 context instead of in isolation. stopPropagation keeps the
                                 row's expand/collapse from also firing. --}}
                            @if(!empty($riderData['rider_user_id']))
                            <button onclick="event.stopPropagation(); fmOpen({{ $riderData['rider_user_id'] }}, '{{ addslashes($riderData['rider_name']) }}')"
                                    style="background:#fff; border:1px solid #fdba74; color:#c2410c; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:600; cursor:pointer;">
                                📊 Month view
                            </button>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-orange-700">Rs. {{ number_format($riderData['total_amount'], 2) }}</span>
                            <svg class="w-3 h-3 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>
                    
                    <!-- Rider Request Details (expanded by default) -->
                    <div id="petrol-rider-{{ $riderData['rider_user_id'] ?? $riderIdx }}">
                        @foreach($riderData['requests'] as $petrolReq)
                        <div id="petrol-req-{{ $petrolReq['id'] }}" class="mx-4 mb-2 rounded-lg overflow-hidden" style="background-color: #fff7ed; border: 1px solid #fed7aa;">
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center flex-wrap gap-3">
                                        <span class="text-xs font-mono font-bold text-orange-800">{{ $petrolReq['request_number'] }}</span>
                                        <span class="text-xs text-gray-500">{{ $petrolReq['expense_date'] }}</span>
                                        @if(($petrolReq['source'] ?? 'meter') === 'manual')
                                        <span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Manual</span>
                                        @else
                                        <span class="text-xs bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded font-semibold">Meter</span>
                                        @endif
                                        @include('fin.employee.partials.machine-chip', ['req' => $petrolReq, 'tone' => '#fdba74'])
                                    </div>
                                    <span class="text-sm font-bold text-orange-800">Rs. {{ number_format($petrolReq['amount'], 2) }}</span>
                                </div>
                                @if(($petrolReq['source'] ?? 'meter') === 'meter')
                                <div class="flex items-center gap-4 mb-2">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500">Distance:</span>
                                        <span class="text-xs font-bold text-gray-800">{{ $petrolReq['meter_distance'] }} km</span>
                                    </div>
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500">Rate:</span>
                                        <span class="text-xs font-bold text-gray-800">Rs. {{ $petrolReq['petrol_rate'] }}/km</span>
                                    </div>
                                </div>
                                @endif
                                @if($petrolReq['notes'])
                                <div class="text-xs text-gray-500 mb-2 italic">{{ $petrolReq['notes'] }}</div>
                                @endif
                                @if(!empty($petrolReq['attachment_url']))
                                <div class="mb-2">
                                    <a href="{{ $petrolReq['attachment_url'] }}" target="_blank" class="inline-block">
                                        <img src="{{ $petrolReq['attachment_url'] }}" alt="Receipt" class="h-20 w-auto rounded border border-orange-200 hover:opacity-80 transition-opacity cursor-pointer" />
                                    </a>
                                </div>
                                @endif
                                <div class="flex items-center gap-2 mt-2">
                                    @include('fin.employee.partials.pay-source-row', [
                                        'req' => $petrolReq,
                                        'accounts' => $petrolPaymentAccounts,
                                        'banks' => $petrolPayBanks,
                                        'ringClass' => 'focus:ring-orange-400',
                                    ])
                                    <button type="button" 
                                        onclick="approvePetrolRequest({{ $petrolReq['id'] }}, {{ $petrolReq['requires_level_1'] ? '1' : '2' }})"
                                        style="background-color: #16a34a;"
                                        class="text-xs text-white px-4 py-1.5 rounded-md font-bold hover:opacity-90 transition-all cursor-pointer flex items-center gap-1">
                                        ✅ Approve
                                    </button>
                                    <button type="button" 
                                        onclick="rejectPetrolRequest({{ $petrolReq['id'] }}, {{ $petrolReq['requires_level_1'] ? '1' : '2' }})"
                                        style="background-color: #dc2626;"
                                        class="text-xs text-white px-4 py-1.5 rounded-md font-bold hover:opacity-90 transition-all cursor-pointer flex items-center gap-1">
                                        ❌ Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
    <!-- 🔧 Maintenance Requests -->
    @if(isset($pendingMaintenanceRequests) && $pendingMaintenanceRequests)
    <div class="dc-panel">
        <div style="background: linear-gradient(to right, #f0fdfa, #ccfbf1); border: 2px solid #5eead4;" class="rounded-lg shadow-sm overflow-hidden">
            <!-- Header (collapsed by default — keeps the closing screen tidy) -->
            <div style="background: linear-gradient(to right, #0d9488, #0f766e);" class="px-4 py-3 flex items-center justify-between cursor-pointer" onclick="document.getElementById('maint-requests-body').classList.toggle('hidden')">
                <div class="flex items-center gap-3">
                    <span class="text-lg">🔧</span>
                    <h3 class="text-sm font-bold text-white">Maintenance Requests</h3>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-xs bg-white text-teal-700 px-2 py-0.5 rounded-full font-bold">{{ $pendingMaintenanceRequests['total_count'] }} Pending</span>
                    <span class="text-xs bg-teal-900 bg-opacity-30 text-white px-2 py-0.5 rounded-full font-bold">Rs. {{ number_format($pendingMaintenanceRequests['total_amount']) }}</span>
                    <svg class="w-4 h-4 text-white transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
            </div>
            {{-- Aug-2026: was class="hidden". A queue holding money that needs
                 approval must not load collapsed — the pane scrolls on its own
                 now, so an open group costs nothing. --}}
            <div id="maint-requests-body">
                <!-- Summary Row -->
                <div class="px-4 py-2 flex gap-4 border-b" style="border-color: #5eead4;">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Total Requests:</span>
                        <span class="text-xs font-bold text-gray-900">{{ $pendingMaintenanceRequests['total_count'] }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Total Amount:</span>
                        <span class="text-xs font-bold text-teal-700">Rs. {{ number_format($pendingMaintenanceRequests['total_amount'], 2) }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">People:</span>
                        <span class="text-xs font-bold text-gray-900">{{ count($pendingMaintenanceRequests['by_rider']) }}</span>
                    </div>
                </div>

                @foreach($pendingMaintenanceRequests['by_rider'] as $mIdx => $riderData)
                <div class="border-b last:border-b-0" style="border-color: #5eead4;">
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-teal-50 transition-colors"
                         onclick="document.getElementById('maint-rider-{{ $riderData['rider_user_id'] ?? $mIdx }}').classList.toggle('hidden')">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold text-gray-800">{{ $riderData['rider_name'] }}</span>
                            <span class="text-xs bg-teal-100 text-teal-700 px-2 py-0.5 rounded-full font-medium">{{ $riderData['count'] }} request(s)</span>
                            @if(!empty($riderData['rider_user_id']))
                            <button onclick="event.stopPropagation(); fmOpen({{ $riderData['rider_user_id'] }}, '{{ addslashes($riderData['rider_name']) }}')"
                                    style="background:#fff; border:1px solid #5eead4; color:#0f766e; border-radius:999px; padding:2px 10px; font-size:11px; font-weight:600; cursor:pointer;">
                                📊 Month view
                            </button>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-teal-700">Rs. {{ number_format($riderData['total_amount'], 2) }}</span>
                            <svg class="w-3 h-3 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>

                    <div id="maint-rider-{{ $riderData['rider_user_id'] ?? $mIdx }}">
                        @foreach($riderData['requests'] as $mReq)
                        <div id="petrol-req-{{ $mReq['id'] }}" class="mx-4 mb-2 rounded-lg overflow-hidden" style="background-color: #f0fdfa; border: 1px solid #99f6e4;">
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center flex-wrap gap-3">
                                        <span class="text-xs font-mono font-bold text-teal-800">{{ $mReq['request_number'] }}</span>
                                        <span class="text-xs text-gray-500">{{ $mReq['expense_date'] }}</span>
                                        <span class="text-xs bg-teal-100 text-teal-700 px-1.5 py-0.5 rounded font-semibold">🔧 Maintenance</span>
                                        @include('fin.employee.partials.machine-chip', ['req' => $mReq, 'tone' => '#5eead4'])
                                    </div>
                                    <span class="text-sm font-bold text-teal-800">Rs. {{ number_format($mReq['amount'], 2) }}</span>
                                </div>
                                @if($mReq['notes'])
                                <div class="text-xs text-gray-500 mb-2 italic">{{ $mReq['notes'] }}</div>
                                @endif
                                @if(!empty($mReq['attachment_url']))
                                <div class="mb-2">
                                    <a href="{{ $mReq['attachment_url'] }}" target="_blank" class="inline-block">
                                        <img src="{{ $mReq['attachment_url'] }}" alt="Receipt" class="h-20 w-auto rounded border border-teal-200 hover:opacity-80 transition-opacity cursor-pointer" />
                                    </a>
                                </div>
                                @endif
                                <div class="flex items-center gap-2 mt-2">
                                    @include('fin.employee.partials.pay-source-row', [
                                        'req' => $mReq,
                                        'accounts' => $petrolPaymentAccounts,
                                        'banks' => $petrolPayBanks,
                                        'ringClass' => 'focus:ring-teal-400',
                                    ])
                                    <button type="button"
                                        onclick="approvePetrolRequest({{ $mReq['id'] }}, {{ $mReq['requires_level_1'] ? '1' : '2' }})"
                                        style="background-color: #16a34a;"
                                        class="text-xs text-white px-4 py-1.5 rounded-md font-bold hover:opacity-90 transition-all cursor-pointer flex items-center gap-1">
                                        ✅ Approve
                                    </button>
                                    <button type="button"
                                        onclick="rejectPetrolRequest({{ $mReq['id'] }}, {{ $mReq['requires_level_1'] ? '1' : '2' }})"
                                        style="background-color: #dc2626;"
                                        class="text-xs text-white px-4 py-1.5 rounded-md font-bold hover:opacity-90 transition-all cursor-pointer flex items-center gap-1">
                                        ❌ Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
                @if(empty($pendingPetrolRequests) && empty($pendingMaintenanceRequests))
                <div class="dc-pane-empty">✅ No petrol or maintenance requests waiting for approval.</div>
                @endif
