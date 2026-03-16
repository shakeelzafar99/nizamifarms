@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Compact Header & Filters in One Row -->
    <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-lg shadow-lg p-4 mb-4">
        <form id="filter-form" method="GET" action="{{ route('fin.employee.all-outstanding-invoices') }}" class="flex flex-wrap items-center gap-3">
            <div class="flex-shrink-0">
                <h1 class="text-lg font-bold text-white">📊 Invoice Tracker</h1>
            </div>
            <select name="rider" style="color: #1f2937 !important; background-color: white !important;" class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
                <option value="all">All Riders</option>
                @foreach($allRiders as $rider)
                <option value="{{ $rider->id }}" {{ $filters['rider'] == $rider->id ? 'selected' : '' }}>{{ $rider->account_name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" 
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}"
                   style="color: #1f2937 !important; background-color: white !important;" 
                   class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
            <!-- ⭐ Group By (only for settled view) -->
            @if($filters['status'] == 'settled')
            <select name="group_by" style="color: #1f2937 !important; background-color: white !important;" class="px-3 py-1.5 text-xs rounded-md focus:outline-none border-0">
                <option value="date" {{ ($filters['group_by'] ?? 'date') == 'date' ? 'selected' : '' }}>Group by Date</option>
                <option value="rider" {{ ($filters['group_by'] ?? 'date') == 'rider' ? 'selected' : '' }}>Group by Rider</option>
            </select>
            <!-- ⭐ Include Online toggle (default ON) -->
            @php $includeOnlineChecked = $filters['include_online'] ?? true; @endphp
            <label class="flex items-center gap-2 px-3 py-1.5 rounded-md cursor-pointer transition-all" 
                   style="{{ $includeOnlineChecked ? 'background: #10b981 !important; color: white;' : 'background: white !important; color: #1f2937;' }}">
                <input type="checkbox" name="include_online" value="1" {{ $includeOnlineChecked ? 'checked' : '' }} 
                       style="accent-color: {{ $includeOnlineChecked ? 'white' : '#10b981' }}; width: 16px; height: 16px;">
                <span class="text-xs font-semibold">🏦 Include Online</span>
            </label>
            @endif
            <button type="submit" style="background-color: white !important; color: #7c3aed !important;" class="px-3 py-1.5 font-medium text-xs rounded-md hover:opacity-90 transition-opacity">
                Apply
            </button>
            <input type="hidden" name="status" id="status-filter" value="{{ $filters['status'] }}">
            <div class="ml-auto">
                <a href="{{ route('fin.employee.index') }}" style="background-color: rgba(255, 255, 255, 0.2) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md hover:opacity-90 transition-opacity">
                    ← Back
                </a>
            </div>
        </form>
    </div>

    {{-- Flash messages for approve/reject actions --}}
    @if(session('success'))
    <div class="mb-3 rounded-md border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @elseif(session('error'))
    <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        {{ session('error') }}
    </div>
    @endif

    <!-- Compact Statistics Cards -->
    <div class="grid grid-cols-3 gap-2 mb-4">
        <!-- Row 1: Invoice Cards -->
        <div class="grid grid-cols-4 gap-2 col-span-3">
            <!-- Open Invoices Card -->
            <button type="button" onclick="filterByStatus('open')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'open' ? 'border-red-500 bg-red-50' : 'border-gray-200 bg-white hover:border-red-300' }}">
                <div class="text-xs font-bold text-red-700 mb-1">🔴 OPEN</div>
                <div class="text-xl font-bold text-red-900">{{ $stats['open_count'] }}</div>
                <div class="text-xs font-semibold text-red-600">Rs. {{ number_format($stats['open_total'], 2) }}</div>
            </button>

            <!-- Partial Invoices Card -->
            <button type="button" onclick="filterByStatus('partial')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'partial' ? 'border-yellow-500 bg-yellow-50' : 'border-gray-200 bg-white hover:border-yellow-300' }}">
                <div class="text-xs font-bold text-yellow-700 mb-1">🟡 PARTIAL</div>
                <div class="text-xl font-bold text-yellow-900">{{ $stats['partial_count'] }}</div>
                <div class="text-xs font-semibold text-yellow-600">Rs. {{ number_format($stats['partial_total'], 2) }}</div>
            </button>

            <!-- Pending Settlements Card -->
            <button type="button" onclick="togglePendingSettlements()" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all border-gray-200 bg-white hover:border-blue-300">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-blue-700">⏳ PENDING</div>
                    @if($stats['pending_settlement_count'] > 0)
                    <span class="animate-pulse text-xs bg-blue-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['pending_settlement_count'] }}</span>
                    @endif
                </div>
                <div class="text-xl font-bold text-blue-900">{{ $stats['pending_settlement_count'] }}</div>
                <div class="text-xs font-semibold text-blue-600">Rs. {{ number_format($stats['pending_settlement_total'], 2) }}</div>
            </button>

            <!-- Total Outstanding Card -->
            <button type="button" onclick="filterByStatus('all')" 
                    class="stat-card text-left p-3 rounded-md shadow border-2 transition-all {{ $filters['status'] == 'all' ? 'border-purple-500 bg-purple-50' : 'border-gray-200 bg-white hover:border-purple-300' }}">
                <div class="text-xs font-bold text-purple-700 mb-1">📊 TOTAL</div>
                <div class="text-xl font-bold text-purple-900">{{ $stats['open_count'] + $stats['partial_count'] }}</div>
                <div class="text-xs font-semibold text-purple-600">Rs. {{ number_format($stats['total_outstanding'], 2) }}</div>
            </button>
        </div>
        
        <!-- Row 2: NEW Expense Management Cards -->
        <div class="grid grid-cols-2 gap-2 col-span-3">
            <!-- Pending Approvals Card (Awaiting Approval) -->
            <div class="stat-card text-left p-3 rounded-md shadow border-2 border-gray-200 bg-yellow-50 transition-all">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-yellow-700">⏳ PENDING</div>
                    @if($stats['pending_approvals_count'] > 0)
                    <span class="text-xs bg-yellow-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['pending_approvals_count'] }}</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 mb-1">Awaiting approval</div>
                <div class="text-xl font-bold text-yellow-900">Rs. {{ number_format($stats['pending_approvals_amount'], 2) }}</div>
            </div>

            <!-- Short Cash Card (Unsettled) -->
            <div class="stat-card text-left p-3 rounded-md shadow border-2 border-gray-200 bg-green-50 transition-all">
                <div class="flex items-center justify-between mb-1">
                    <div class="text-xs font-bold text-green-700">💸 SHORT CASH</div>
                    @if($stats['short_cash_count'] > 0)
                    <span class="text-xs bg-green-600 text-white px-1.5 py-0.5 rounded-full">{{ $stats['short_cash_count'] }}</span>
                    @endif
                </div>
                <div class="text-sm text-gray-600 mb-1">Unsettled</div>
                <div class="text-xl font-bold text-green-900">Rs. {{ number_format($stats['short_cash_amount'], 2) }}</div>
            </div>
        </div>
    </div>

    <!-- ⭐ View Settled Invoices Button - AT THE TOP -->
    @if($filters['status'] != 'settled' && $stats['settled_count'] > 0)
    <div class="mb-4">
        <div style="background: linear-gradient(to right, #ecfdf5, #d1fae5); border: 2px solid #86efac;" class="rounded-lg p-4 flex items-center justify-between">
            <div>
                <h3 style="color: #166534 !important;" class="text-sm font-bold mb-1">✅ Settled Invoices Available</h3>
                <p style="color: #15803d !important;" class="text-xs">{{ $stats['settled_count'] }} invoice(s) totaling Rs. {{ number_format($stats['settled_total'], 2) }} have been settled.</p>
            </div>
            <button onclick="filterByStatus('settled')" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-5 py-2.5 text-sm font-semibold rounded-lg shadow-md hover:opacity-90 transition-opacity">
                View Settled Invoices →
            </button>
        </div>
    </div>
    @endif

    <!-- No separate pending settlements section - they'll be shown inline with invoices -->

    <!-- ⭐ Online WhatsApp Message Tracking Section (Today's deliveries) -->
    @if(isset($onlineMessageTracking) && $onlineMessageTracking)
    <div class="mb-4">
        <div style="background: linear-gradient(to right, #f0fdf4, #dcfce7); border: 2px solid #86efac;" class="rounded-lg shadow-sm overflow-hidden">
            <!-- Header -->
            <div style="background: linear-gradient(to right, #059669, #047857);" class="px-4 py-3 flex items-center justify-between cursor-pointer" onclick="document.getElementById('online-msg-tracking-body').classList.toggle('hidden')">
                <div class="flex items-center gap-3">
                    <span class="text-lg">📱</span>
                    <h3 class="text-sm font-bold text-white">Online Payment - WhatsApp Messages (Today)</h3>
                </div>
                <div class="flex items-center gap-3">
                    @if($onlineMessageTracking['pending_count'] > 0)
                    <span class="animate-pulse text-xs bg-red-500 text-white px-2 py-0.5 rounded-full font-bold">{{ $onlineMessageTracking['pending_count'] }} Pending</span>
                    @endif
                    @if($onlineMessageTracking['sent_count'] > 0)
                    <span class="text-xs bg-white text-green-700 px-2 py-0.5 rounded-full font-bold">{{ $onlineMessageTracking['sent_count'] }} Sent</span>
                    @endif
                    <svg class="w-4 h-4 text-white transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
            </div>
            <!-- Body -->
            <div id="online-msg-tracking-body" class="{{ $onlineMessageTracking['pending_count'] > 0 ? '' : 'hidden' }}">
                <!-- Summary Row -->
                <div class="px-4 py-2 flex gap-4 border-b" style="border-color: #bbf7d0;">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-600">Total Online Delivered:</span>
                        <span class="text-xs font-bold text-gray-900">{{ $onlineMessageTracking['total_count'] }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                        <span class="text-xs font-semibold text-green-700">Sent: {{ $onlineMessageTracking['sent_count'] }} (Rs. {{ number_format($onlineMessageTracking['sent_amount']) }})</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-2 h-2 rounded-full bg-red-500"></span>
                        <span class="text-xs font-semibold text-red-700">Pending: {{ $onlineMessageTracking['pending_count'] }} (Rs. {{ number_format($onlineMessageTracking['pending_amount']) }})</span>
                    </div>
                </div>
                
                <!-- Rider Cards -->
                @foreach($onlineMessageTracking['by_rider'] as $riderIdx => $riderData)
                <div class="border-b last:border-b-0" style="border-color: #bbf7d0;">
                    <!-- Rider Header -->
                    <div class="px-4 py-2 flex items-center justify-between cursor-pointer hover:bg-green-50 transition-colors" 
                         onclick="document.getElementById('msg-rider-{{ $riderIdx }}').classList.toggle('hidden')">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold text-gray-800">{{ $riderData['rider_name'] }}</span>
                            <span class="text-xs text-gray-500">Rs. {{ number_format($riderData['total_amount']) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($riderData['sent_count'] > 0)
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">✅ {{ $riderData['sent_count'] }} sent</span>
                            @endif
                            @if($riderData['pending_count'] > 0)
                            <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full font-medium">⏳ {{ $riderData['pending_count'] }} pending</span>
                            @endif
                            <svg class="w-3 h-3 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>
                    
                    <!-- Rider Order Details (collapsed by default, open if has pending) -->
                    <div id="msg-rider-{{ $riderIdx }}" class="{{ $riderData['pending_count'] > 0 ? '' : 'hidden' }}">
                        <!-- Pending Messages -->
                        @if($riderData['pending_count'] > 0)
                        <div class="px-4 py-1">
                            <div class="text-xs font-bold text-red-600 mb-1">⏳ Message Pending</div>
                            @foreach($riderData['message_pending'] as $order)
                            <div id="msg-order-{{ $order['id'] }}" class="flex items-center justify-between py-2 px-3 mb-1.5 rounded-lg" style="background-color: #fef2f2;">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    <span class="text-xs font-mono font-bold text-gray-700">{{ $order['order_number'] }}</span>
                                    <span class="text-xs text-gray-600 truncate">{{ $order['customer_name'] }}</span>
                                    @if($order['delivery_time'])<span class="text-xs text-gray-400">{{ $order['delivery_time'] }}</span>@endif
                                    <span class="text-xs font-semibold text-gray-800">Rs. {{ number_format($order['amount']) }}</span>
                                </div>
                                <button type="button" 
                                    onclick="sendOnlineWhatsApp({{ $order['id'] }}, '{{ addslashes($order['customer_name']) }}', '{{ $order['customer_phone'] }}', '{{ $order['order_number'] }}', '{{ addslashes($order['rider_name']) }}', '{{ $order['delivery_date'] }}', '{{ $order['delivery_time'] }}')"
                                    style="background-color: #25D366; min-width: 140px;"
                                    class="text-sm hover:opacity-90 text-white px-4 py-1.5 rounded-lg font-bold transition-all cursor-pointer whitespace-nowrap flex items-center justify-center gap-1.5 ml-3 shadow-sm">
                                    📱 Send WhatsApp
                                </button>
                            </div>
                            @endforeach
                        </div>
                        @endif
                        
                        <!-- Sent Messages -->
                        @if($riderData['sent_count'] > 0)
                        <div class="px-4 py-1 pb-2">
                            <div class="text-xs font-bold text-green-600 mb-1">✅ Message Sent</div>
                            @foreach($riderData['message_sent'] as $order)
                            <div class="flex items-center justify-between py-1 px-3 mb-1 rounded" style="background-color: #f0fdf4;">
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-mono font-bold text-gray-700">{{ $order['order_number'] }}</span>
                                    <span class="text-xs text-gray-600">{{ $order['customer_name'] }}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-gray-800">Rs. {{ number_format($order['amount']) }}</span>
                                    <span class="text-xs bg-green-500 text-white px-1.5 py-0.5 rounded font-medium">Sent {{ $order['sent_at'] }}</span>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    <!-- ⛽ Petrol Requests (Meter-based + Manual) -->
    @if(isset($pendingPetrolRequests) && $pendingPetrolRequests)
    <div class="mb-4">
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
                         onclick="document.getElementById('petrol-rider-{{ $riderIdx }}').classList.toggle('hidden')">
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-bold text-gray-800">{{ $riderData['rider_name'] }}</span>
                            <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-medium">{{ $riderData['count'] }} request(s)</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-orange-700">Rs. {{ number_format($riderData['total_amount'], 2) }}</span>
                            <svg class="w-3 h-3 text-gray-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                        </div>
                    </div>
                    
                    <!-- Rider Request Details (expanded by default) -->
                    <div id="petrol-rider-{{ $riderIdx }}">
                        @foreach($riderData['requests'] as $petrolReq)
                        <div id="petrol-req-{{ $petrolReq['id'] }}" class="mx-4 mb-2 rounded-lg overflow-hidden" style="background-color: #fff7ed; border: 1px solid #fed7aa;">
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center gap-3">
                                        <span class="text-xs font-mono font-bold text-orange-800">{{ $petrolReq['request_number'] }}</span>
                                        <span class="text-xs text-gray-500">{{ $petrolReq['expense_date'] }}</span>
                                        @if(($petrolReq['source'] ?? 'meter') === 'manual')
                                        <span class="text-xs bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-semibold">Manual</span>
                                        @else
                                        <span class="text-xs bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded font-semibold">Meter</span>
                                        @endif
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
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-gray-500">Pay from:</span>
                                        <select id="petrol-pay-src-{{ $petrolReq['id'] }}" 
                                            style="color: #1f2937; background-color: white; border: 1px solid #d1d5db;"
                                            class="text-xs px-2 py-1 rounded-md focus:outline-none focus:ring-1 focus:ring-orange-400">
                                            @foreach($petrolPaymentAccounts as $acc)
                                            <option value="{{ $acc->id }}" {{ $acc->account_code === 'NF_CASH' ? 'selected' : '' }}>{{ $acc->account_name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
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

    <!-- Invoices Section -->
    @if($invoicesByRider->isEmpty())
    <!-- No Invoices State -->
    <div class="bg-white rounded-lg shadow-sm p-16 text-center">
        <div class="text-6xl mb-4">
            @if($filters['status'] == 'settled')
                📦
            @else
                ✅
            @endif
        </div>
        <h2 class="text-2xl font-bold text-gray-800 mb-2">
            @if($filters['status'] == 'settled')
                No Settled Invoices
            @else
                No Outstanding Invoices
            @endif
        </h2>
        <p class="text-gray-600 mb-4">
            @if($filters['status'] == 'settled')
                No invoices have been settled in the selected period.
            @else
                All invoices are settled! Great job keeping up with payments.
            @endif
        </p>
        <button onclick="filterByStatus('all')" class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md">
            View All Invoices
        </button>
    </div>
    @else
    
    @if($filters['status'] == 'settled' && ($filters['group_by'] ?? 'rider') == 'date' && isset($invoicesByDate) && $invoicesByDate->count() > 0)
    <!-- ⭐ Summary Header (Like Delivery History) -->
    @php
        $totalCashCount = 0;
        $totalCashAmount = 0;
        $totalOnlineCount = 0;
        $totalOnlineAmount = 0;
        $totalOnlineApprovedCount = 0;
        $totalOnlineApprovedAmount = 0;
        $totalOnlinePendingCount = 0;
        $totalOnlinePendingAmount = 0;
        foreach($invoicesByDate as $dayData) {
            if(isset($dayData['riders'])) {
                // Handle both Collection and array
                $riders = is_array($dayData['riders']) ? collect($dayData['riders']) : $dayData['riders'];
                $totalCashCount += $riders->sum('count');
                $totalCashAmount += $riders->sum('total_amount');
            }
            if(isset($dayData['online'])) {
                $totalOnlineCount += $dayData['online']['count'] ?? 0;
                $totalOnlineAmount += $dayData['online']['total_amount'] ?? 0;
                $totalOnlineApprovedCount += $dayData['online']['approved_count'] ?? 0;
                $totalOnlineApprovedAmount += $dayData['online']['approved_amount'] ?? 0;
                $totalOnlinePendingCount += $dayData['online']['pending_count'] ?? 0;
                $totalOnlinePendingAmount += $dayData['online']['pending_amount'] ?? 0;
            }
        }
        $grandTotal = $totalCashAmount + $totalOnlineAmount;
        $grandCount = $totalCashCount + $totalOnlineCount;
    @endphp
    <div class="bg-gradient-to-r from-green-600 to-emerald-600 rounded-lg shadow-lg p-4 mb-4">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div class="flex items-center gap-3">
                <span class="text-3xl">✅</span>
                <div>
                    <h2 class="text-white font-bold text-lg">Daily Settlement Summary</h2>
                    <p class="text-green-100 text-sm">{{ $grandCount }} invoices • Rs. {{ number_format($grandTotal, 0) }} total</p>
                </div>
            </div>
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Cash Total Badge -->
                @if($totalCashCount > 0)
                <div class="bg-white bg-opacity-20 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">💵 Cash Settled</div>
                    <div class="text-white font-bold">{{ $totalCashCount }} • Rs. {{ number_format($totalCashAmount, 0) }}</div>
                </div>
                @endif
                
                <!-- Online Approved Badge -->
                @if($totalOnlineApprovedCount > 0)
                <div class="bg-white bg-opacity-20 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">🏦 Online ✓</div>
                    <div class="text-white font-bold">{{ $totalOnlineApprovedCount }} • Rs. {{ number_format($totalOnlineApprovedAmount, 0) }}</div>
                </div>
                @endif
                
                <!-- Online Pending Badge -->
                @if($totalOnlinePendingCount > 0)
                <div class="bg-yellow-400 bg-opacity-30 rounded-lg px-4 py-2 text-center">
                    <div class="text-white text-xs font-medium">⏳ Online Pending</div>
                    <div class="text-white font-bold">{{ $totalOnlinePendingCount }} • Rs. {{ number_format($totalOnlinePendingAmount, 0) }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>
    
    <!-- ⭐ Invoices by Date (Date-Level Grouping) - Delivery History Style -->
    <div class="space-y-2">
        @php
            $dateIndex = 0;
        @endphp
        @foreach($invoicesByDate as $date => $dayData)
        @php
            // Handle both Collection and array for riders
            $ridersData = isset($dayData['riders']) ? (is_array($dayData['riders']) ? collect($dayData['riders']) : $dayData['riders']) : collect();
            $cashCount = $ridersData->sum('count');
            $cashAmount = $ridersData->sum('total_amount');
            $onlineCount = isset($dayData['online']) ? ($dayData['online']['count'] ?? 0) : 0;
            $onlineAmount = isset($dayData['online']) ? ($dayData['online']['total_amount'] ?? 0) : 0;
            $isFirstDate = $dateIndex === 0;
            $dateIndex++;
        @endphp
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
            <!-- Date Header Row (Like Delivery History) -->
            <div class="flex items-center justify-between px-4 py-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100" onclick="toggleDateGroup('{{ str_replace(['-', ' '], '_', $date) }}')">
                <div class="flex items-center gap-3">
                    <!-- Expand/Collapse Arrow -->
                    <span id="toggle-icon-{{ str_replace(['-', ' '], '_', $date) }}" class="text-gray-400 text-sm transition-transform {{ $isFirstDate ? '' : 'rotate-[-90deg]' }}">▼</span>
                    
                    <!-- Date Info -->
                    <div class="flex items-center gap-2">
                        <span class="text-lg">📅</span>
                        <div>
                            <h3 class="font-bold text-gray-800">{{ \Carbon\Carbon::parse($date)->format('D, M d, Y') }}</h3>
                            <p class="text-xs text-gray-500">
                                {{ $dayData['total_count'] }} invoice(s) • Rs. {{ number_format($dayData['total_amount'], 0) }} total
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Badges (Like Delivery History) -->
                <div class="flex items-center gap-2">
                    <!-- Cash Badge (Green) -->
                    @if($cashCount > 0)
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background: #dcfce7; color: #166534;">
                        💵 {{ $cashCount }} • Rs. {{ number_format($cashAmount, 0) }}
                    </span>
                    @endif
                    
                    <!-- Online Badge (Blue) - Show TOTAL online (approved + pending combined) -->
                    @if($onlineCount > 0)
                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold" style="background: #dbeafe; color: #1e40af;">
                        🏦 {{ $onlineCount }} • Rs. {{ number_format($onlineAmount, 0) }}
                    </span>
                    @endif
                </div>
            </div>
            
            <!-- Date Content (Expandable) -->
            <div id="date-content-{{ str_replace(['-', ' '], '_', $date) }}" class="{{ $isFirstDate ? '' : 'hidden' }}">
                <!-- Cash Settlements by Rider -->
                @if(isset($dayData['riders']) && count($dayData['riders']) > 0)
                <div class="px-4 py-3 border-b border-gray-100">
                    <h4 class="text-sm font-bold text-green-700 mb-3 flex items-center gap-2">
                        💵 Cash Settlements
                        <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">
                            {{ $cashCount }} invoices • Rs. {{ number_format($cashAmount, 0) }}
                        </span>
                    </h4>
                    <div class="space-y-3">
                        @foreach($dayData['riders'] as $riderGroup)
                        <div class="bg-gray-50 rounded-lg p-3">
                            <div class="flex items-center justify-between mb-2 pb-2 border-b border-gray-200">
                                <span class="font-semibold text-purple-700 flex items-center gap-2">
                                    👤 {{ $riderGroup['rider_name'] }}
                                </span>
                                <span class="text-sm font-bold text-gray-700">
                                    {{ $riderGroup['count'] }} inv • Rs. {{ number_format($riderGroup['total_amount'], 0) }}
                                </span>
                            </div>
                            <div class="text-sm text-gray-600 space-y-1">
                                @foreach($riderGroup['invoices'] as $invoice)
                                <div class="flex justify-between items-center py-1.5 border-b border-gray-100 last:border-0">
                                    <div>
                                        <span class="font-medium text-blue-600">#{{ $invoice['order_number'] }}</span>
                                        @if($invoice['customer_name'])
                                            <span class="text-gray-600"> - {{ $invoice['customer_name'] }}</span>
                                        @endif
                                    </div>
                                    <span class="font-semibold text-gray-800">Rs. {{ number_format($invoice['amount'], 0) }}</span>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endif
                
                <!-- Online Payments -->
                @if(isset($dayData['online']) && $dayData['online']['count'] > 0)
                @php
                    $onlineApprovedCount = isset($dayData['online']['approved_count']) ? $dayData['online']['approved_count'] : 0;
                    $onlineApprovedAmount = isset($dayData['online']['approved_amount']) ? $dayData['online']['approved_amount'] : 0;
                    $onlinePendingCount = isset($dayData['online']['pending_count']) ? $dayData['online']['pending_count'] : 0;
                    $onlinePendingAmount = isset($dayData['online']['pending_amount']) ? $dayData['online']['pending_amount'] : 0;
                @endphp
                <div class="px-4 py-3">
                    <h4 class="text-sm font-bold text-blue-700 mb-3 flex items-center gap-2">
                        🏦 Online Payments
                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">
                            {{ $onlineCount }} orders • Rs. {{ number_format($onlineAmount, 0) }}
                        </span>
                    </h4>
                    
                    <!-- Approved Online Payments (Blue) -->
                    @if($onlineApprovedCount > 0)
                    <div class="bg-blue-50 rounded-lg p-3 mb-2">
                        <div class="flex items-center justify-between mb-2 pb-2 border-b border-blue-200">
                            <span class="font-semibold text-blue-700">✓ Approved</span>
                            <span class="text-sm font-bold text-gray-700">{{ $onlineApprovedCount }} • Rs. {{ number_format($onlineApprovedAmount, 0) }}</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            @foreach($dayData['online']['approved_transactions'] ?? [] as $txn)
                            <div class="flex justify-between items-center py-1.5 border-b border-blue-100 last:border-0">
                                <div>
                                    <span class="font-medium text-blue-600">#{{ $txn['order_number'] }}</span>
                                    <span class="text-gray-600"> - {{ $txn['customer_name'] }}</span>
                                </div>
                                <span class="font-semibold text-gray-800">Rs. {{ number_format($txn['amount'], 0) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                    
                    <!-- Pending Online Payments (Yellow) -->
                    @if($onlinePendingCount > 0)
                    <div class="bg-yellow-50 rounded-lg p-3">
                        <div class="flex items-center justify-between mb-2 pb-2 border-b border-yellow-200">
                            <span class="font-semibold text-yellow-700">⏳ Pending Approval</span>
                            <span class="text-sm font-bold text-gray-700">{{ $onlinePendingCount }} • Rs. {{ number_format($onlinePendingAmount, 0) }}</span>
                        </div>
                        <div class="text-sm text-gray-600 space-y-1">
                            @foreach($dayData['online']['pending_transactions'] ?? [] as $txn)
                            <div class="flex justify-between items-center py-1.5 border-b border-yellow-100 last:border-0">
                                <div>
                                    <span class="font-medium text-yellow-600">#{{ $txn['order_number'] }}</span>
                                    <span class="text-gray-600"> - {{ $txn['customer_name'] }}</span>
                                </div>
                                <span class="font-semibold text-gray-800">Rs. {{ number_format($txn['amount'], 0) }}</span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
                @endif
                
                <!-- Empty state if no data -->
                @if((!isset($dayData['riders']) || count($dayData['riders']) == 0) && (!isset($dayData['online']) || ($dayData['online']['count'] ?? 0) == 0))
                <div class="px-4 py-6 text-center text-gray-500 text-sm">
                    No settlement details available for this date.
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    
    <!-- Online Pending Approval Section (if include_online is enabled) -->
    @if(isset($onlineData) && $onlineData['pending_approval']['count'] > 0)
    <div class="mt-4">
        <div class="bg-yellow-50 border-2 border-yellow-300 rounded-lg p-4">
            <h3 class="text-sm font-bold text-yellow-800 mb-3 flex items-center gap-2">
                ⏳ Online Payments - Pending Approval
                <span class="bg-yellow-500 text-white px-2 py-0.5 rounded-full text-xs">
                    {{ $onlineData['pending_approval']['count'] }} pending
                </span>
            </h3>
            <div class="text-xs text-gray-600">
                <table class="w-full">
                    <thead class="bg-yellow-100">
                        <tr>
                            <th class="text-left px-2 py-1">Order #</th>
                            <th class="text-left px-2 py-1">Customer</th>
                            <th class="text-left px-2 py-1">Date</th>
                            <th class="text-right px-2 py-1">Amount</th>
                            <th class="text-center px-2 py-1">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($onlineData['pending_approval']['transactions'] as $txn)
                        <tr class="border-b border-yellow-100">
                            <td class="px-2 py-1 font-medium text-blue-600">#{{ $txn['order_number'] }}</td>
                            <td class="px-2 py-1">{{ $txn['customer_name'] }}</td>
                            <td class="px-2 py-1">{{ \Carbon\Carbon::parse($txn['transaction_date'])->format('M d') }}</td>
                            <td class="px-2 py-1 text-right font-semibold">Rs. {{ number_format($txn['amount'], 2) }}</td>
                            <td class="px-2 py-1 text-center">
                                <span class="bg-yellow-200 text-yellow-800 px-2 py-0.5 rounded text-xs">
                                    {{ ucfirst(str_replace('_', ' ', $txn['approval_status'])) }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-yellow-100">
                        <tr>
                            <td colspan="3" class="px-2 py-1 font-bold text-right">Total Pending:</td>
                            <td class="px-2 py-1 text-right font-bold text-yellow-800">Rs. {{ number_format($onlineData['pending_approval']['amount'], 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    @endif
    
    @else
    <!-- Invoices by Rider (Default View) -->
    <div class="space-y-3">
        @foreach($invoicesByRider as $riderData)
        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden hover:shadow-lg transition-shadow">
            <!-- Rider Header -->
            <div class="bg-gradient-to-r from-purple-600 to-indigo-600 px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white bg-opacity-20 rounded-full flex items-center justify-center border-2 border-white">
                        <span class="text-white font-bold text-lg">
                            {{ substr($riderData['account']->account_name, 0, 1) }}
                        </span>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white">{{ $riderData['account']->account_name }}</h3>
                        <p class="text-xs text-purple-100">{{ $riderData['account']->account_code }} • {{ $riderData['invoice_count'] }} invoice(s)</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-xs text-purple-100">Total Outstanding</p>
                    <p class="text-2xl font-bold text-white">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</p>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="overflow-x-auto">
                @if($filters['status'] == 'settled' && $riderData['invoices_by_date'])
                    <!-- Day-Grouped View for Settled Invoices -->
                    @foreach($riderData['invoices_by_date'] as $date => $dayData)
                    <div class="mb-2 last:mb-0">
                        <!-- Day Header with Total -->
                        <div style="background: linear-gradient(to right, #dcfce7, #bbf7d0) !important;" class="px-4 py-2 border-b-2 border-green-300 flex justify-between items-center">
                            <div>
                                <span class="text-sm font-bold text-green-900">📅 {{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}</span>
                                <span class="text-xs text-green-700 ml-2">({{ $dayData['count'] }} invoice{{ $dayData['count'] > 1 ? 's' : '' }})</span>
                            </div>
                            <div class="text-sm font-bold text-green-900">
                                Day Total: Rs. {{ number_format($dayData['day_total'], 2) }}
                            </div>
                        </div>
                        
                        <!-- Invoices for this day -->
                        <table class="min-w-full divide-y divide-gray-100">
                            <thead style="background-color: #f9fafb !important;">
                                <tr>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Order #</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Invoice Date</th>
                                    <th class="px-3 py-1.5 text-left text-xs font-semibold text-gray-600 uppercase">Description</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Amount</th>
                                    <th class="px-3 py-1.5 text-right text-xs font-semibold text-gray-600 uppercase">Settled</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-50">
                                @foreach($dayData['invoices'] as $invoice)
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-3 py-2 whitespace-nowrap">
                                        <span class="text-xs font-bold text-purple-700">{{ $invoice->order ? $invoice->order->order_number : 'N/A' }}</span>
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                        {{ $invoice->transaction_date->format('M j') }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                        {{ $invoice->description }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                        Rs. {{ number_format($invoice->amount, 2) }}
                                    </td>
                                    <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                        <span class="text-green-700 font-medium">Rs. {{ number_format($invoice->settled_amount, 2) }}</span>
                                        @if(isset($invoice->settlement_breakdown) && $invoice->settlement_breakdown)
                                            <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                                💸 Rs. {{ number_format($invoice->settlement_breakdown['deposit_amount'], 0) }} + 
                                                Rs. {{ number_format($invoice->settlement_breakdown['expense_amount'], 0) }} ({{ $invoice->settlement_breakdown['expense_category'] }})
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endforeach
                @else
                    <!-- Standard View for Open/Partial Invoices -->
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Order #</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Description</th>
                                <th class="px-3 py-2 text-center text-xs font-semibold text-gray-700 uppercase">Status</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Amount</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Settled</th>
                                <th class="px-3 py-2 text-right text-xs font-semibold text-gray-700 uppercase">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            @foreach($riderData['invoices'] as $invoice)
                            <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-2">
                                <div class="text-xs font-bold text-purple-700">{{ $invoice['order_number'] }}</div>
                                @if(isset($invoice['customer_name']) && $invoice['customer_name'])
                                <div class="text-xs text-gray-600 mt-0.5">{{ $invoice['customer_name'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-600">
                                {{ $invoice['transaction_date']->format('M j, Y') }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600 max-w-xs truncate">
                                {{ $invoice['description'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-center">
                                @if($invoice['is_pending_approval'])
                                    <span class="px-2 py-0.5 text-xs font-bold bg-amber-100 text-amber-800 rounded-full animate-pulse">
                                        💰 Deposit Pending
                                    </span>
                                @elseif($invoice['settlement_status'] === 'settled')
                                    <span class="px-2 py-0.5 text-xs font-bold bg-green-100 text-green-800 rounded-full">
                                        ✅ Settled
                                    </span>
                                @elseif($invoice['settled_amount'] > 0)
                                    <span class="px-2 py-0.5 text-xs font-bold bg-yellow-100 text-yellow-800 rounded-full">
                                        🟡 Partial
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-xs font-bold bg-red-100 text-red-800 rounded-full">
                                        🔴 Open
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right font-semibold text-gray-900">
                                Rs. {{ number_format($invoice['amount'], 2) }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['settled_amount'] > 0)
                                    <span class="text-green-700 font-medium">Rs. {{ number_format($invoice['settled_amount'], 2) }}</span>
                                    @if($invoice['settled_at'])
                                        <div class="text-xs text-gray-500">{{ \Carbon\Carbon::parse($invoice['settled_at'])->format('M j') }}</div>
                                    @endif
                                    @if(isset($invoice['settlement_breakdown']) && $invoice['settlement_breakdown'])
                                        <div class="text-xs text-blue-600 mt-1" style="white-space: nowrap;">
                                            💸 Rs. {{ number_format($invoice['settlement_breakdown']['deposit_amount'], 0) }} + 
                                            Rs. {{ number_format($invoice['settlement_breakdown']['expense_amount'], 0) }} ({{ $invoice['settlement_breakdown']['expense_category'] }})
                                        </div>
                                    @endif
                                @else
                                    <span class="text-gray-400">-</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-right">
                                @if($invoice['outstanding_amount'] > 0)
                                    <span class="font-bold text-red-700">Rs. {{ number_format($invoice['outstanding_amount'], 2) }}</span>
                                @else
                                    <span class="text-green-600 font-medium">✓ Paid</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    
                    <!-- Pending Settlement Deposit Rows (Inline) -->
                    @if($riderData['pending_settlements']->count() > 0)
                        @foreach($riderData['pending_settlements'] as $settlement)
                        <tbody style="background: linear-gradient(to right, #fef3c7, #fde68a) !important;" class="border-t-2 border-amber-400">
                            <tr>
                                <td colspan="2" class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="text-lg">💰</span>
                                        <div>
                                            <p class="text-xs font-bold text-amber-900">Settlement Deposit</p>
                                            <p class="text-xs text-amber-700">{{ $settlement->created_at->format('M j, Y g:i A') }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td colspan="2" class="px-3 py-3">
                                    <p class="text-xs text-amber-800">{{ $settlement->description }}</p>
                                    @if($settlement->comments)
                                    <p class="text-xs text-amber-600 mt-1">{{ $settlement->comments }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-center">
                                    <span class="px-2 py-1 text-xs font-bold bg-amber-200 text-amber-900 rounded-full">
                                        ⏳ PENDING APPROVAL
                                    </span>
                                </td>
                                <td colspan="2" class="px-3 py-3 text-right">
                                    <p class="text-lg font-bold text-amber-900">Rs. {{ number_format($settlement->amount, 2) }}</p>
                                    <p class="text-xs text-amber-700">
                                        {{ $settlement->invoices->count() }} invoice(s) • 
                                        @if($settlement->amount >= $settlement->total_outstanding)
                                            <span class="text-green-700">Full Payment</span>
                                        @else
                                            <span class="text-red-700">Short Rs. {{ number_format($settlement->total_outstanding - $settlement->amount, 2) }}</span>
                                        @endif
                                    </p>
                                </td>
                            </tr>
                            <tr style="background-color: #fffbeb !important;">
                                <td colspan="7" class="px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <a href="{{ route('fin.ledger.show', $settlement->id) }}" class="text-xs text-amber-700 hover:text-amber-900 font-medium">
                                            View in Approvals →
                                        </a>
                                        <div class="flex gap-2">
                                            <form method="POST" action="{{ route('fin.ledger.approve', $settlement->id) }}" class="inline" onsubmit="return confirm('Approve this settlement deposit of Rs. {{ number_format($settlement->amount, 2) }}?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #16a34a, #15803d) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✓ Approve
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('fin.ledger.reject', $settlement->id) }}" class="inline" onsubmit="return confirm('Reject this settlement?');">
                                                @csrf
                                                <input type="hidden" name="_origin" value="outstanding-invoices">
                                                <button type="submit" style="background: linear-gradient(to right, #dc2626, #b91c1c) !important; color: white !important;" class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-md shadow-sm hover:opacity-90">
                                                    ✗ Reject
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        @endforeach
                    @endif
                    
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="6" class="px-3 py-2 text-right text-xs font-bold text-gray-700">
                                Subtotal:
                            </td>
                            <td class="px-3 py-2 text-right">
                                <span class="text-sm font-bold text-purple-700">Rs. {{ number_format($riderData['total_outstanding'], 2) }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                @endif
            </div>

            <!-- Quick Actions -->
            <div class="bg-gray-50 px-4 py-2 border-t border-gray-200 flex justify-between items-center">
                <span class="text-xs text-gray-500">{{ $riderData['invoice_count'] }} invoice(s)</span>
                <a href="{{ route('fin.employee.show', $riderData['account']->id) }}" 
                   class="inline-flex items-center px-3 py-1.5 bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium rounded-md transition-colors">
                    View Ledger →
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif
    @endif

</div>

<!-- JavaScript for Interactive Filters -->
<script>
function filterByStatus(status) {
    document.getElementById('status-filter').value = status;
    document.getElementById('filter-form').submit();
}

function togglePendingSettlements() {
    const section = document.getElementById('pending-settlements-section');
    if (section) {
        section.classList.toggle('hidden');
    }
}

// ⭐ Toggle date group visibility
function toggleDateGroup(date) {
    const content = document.getElementById('date-content-' + date);
    const icon = document.getElementById('toggle-icon-' + date);
    if (content) {
        content.classList.toggle('hidden');
        if (icon) {
            // Rotate arrow: pointing right when collapsed, down when expanded
            if (content.classList.contains('hidden')) {
                icon.style.transform = 'rotate(-90deg)';
            } else {
                icon.style.transform = 'rotate(0deg)';
            }
        }
    }
}

// Auto-show pending settlements if there are any
@if($stats['pending_settlement_count'] > 0 && $filters['status'] == 'all')
    // Optionally auto-show on page load
    // togglePendingSettlements();
@endif

function formatPhoneForWhatsApp(phone) {
    if (!phone) return null;
    let cleaned = phone.replace(/[^0-9+]/g, '');
    if (cleaned.startsWith('+92')) return cleaned;
    if (cleaned.startsWith('92') && cleaned.length >= 12) return '+' + cleaned;
    if (cleaned.startsWith('0')) return '+92' + cleaned.substring(1);
    if (cleaned.length === 10) return '+92' + cleaned;
    return cleaned.startsWith('+') ? cleaned : '+' + cleaned;
}

function sendOnlineWhatsApp(orderId, customerName, customerPhone, orderNumber, riderName, deliveryDate, deliveryTime) {
    var formatted = formatPhoneForWhatsApp(customerPhone);
    if (!formatted) {
        alert('No valid phone number available for this customer.');
        return;
    }

    var timeStr = deliveryTime ? ' at ' + deliveryTime : '';
    var msg = 'Dear ' + customerName + ',\n\n'
        + 'We are happy to confirm that your order #' + orderNumber + ' has been successfully delivered on ' + deliveryDate + timeStr + ' by ' + riderName + '.\n\n'
        + 'Your payment method is Bank Transfer.\n\n'
        + 'Thank you for choosing Nizami Farms!';

    var waUrl = 'https://wa.me/' + formatted.replace('+', '') + '?text=' + encodeURIComponent(msg);
    window.open(waUrl, '_blank');

    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    fetch('{{ route("fin.employee.mark-online-message-sent", ["orderId" => "__ID__"]) }}'.replace('__ID__', orderId), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken ? csrfToken.getAttribute('content') : '',
            'Accept': 'application/json'
        },
        body: JSON.stringify({})
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var row = document.getElementById('msg-order-' + orderId);
            if (row) {
                row.style.backgroundColor = '#f0fdf4';
                row.querySelector('button').outerHTML = '<span class="text-xs bg-green-500 text-white px-1.5 py-0.5 rounded font-medium">Sent ' + (data.sent_at || 'now') + '</span>';
            }
        }
    })
    .catch(function(err) {
        console.error('Failed to mark message sent:', err);
    });
}

function approvePetrolRequest(requestId, level) {
    if (!confirm('Approve this petrol request?')) return;
    
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Approving...';
    
    // Read the selected payment source for this request
    var paymentSourceSelect = document.getElementById('petrol-pay-src-' + requestId);
    var paymentSourceAccountId = paymentSourceSelect ? paymentSourceSelect.value : null;
    
    var payload = { level: level, comments: 'Approved from daily closing' };
    if (paymentSourceAccountId) {
        payload.payment_source_account_id = parseInt(paymentSourceAccountId);
    }
    
    fetch('/requests/' + requestId + '/approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var row = document.getElementById('petrol-req-' + requestId);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.backgroundColor = '#f0fdf4';
                row.style.borderColor = '#86efac';
                row.innerHTML = '<div class="px-4 py-3 flex items-center justify-between"><span class="text-xs font-bold text-green-700">✅ Approved</span><span class="text-xs text-gray-400">' + (data.request_status || '') + '</span></div>';
            }
        } else {
            alert(data.message || 'Failed to approve');
            btn.disabled = false;
            btn.textContent = '✅ Approve';
        }
    })
    .catch(function(err) {
        console.error('Petrol approve error:', err);
        alert('Error approving petrol request');
        btn.disabled = false;
        btn.textContent = '✅ Approve';
    });
}

function rejectPetrolRequest(requestId, level) {
    var reason = prompt('Reason for rejecting this petrol request:');
    if (!reason) return;
    
    var btn = event.target;
    btn.disabled = true;
    btn.textContent = 'Rejecting...';
    
    fetch('/requests/' + requestId + '/reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Accept': 'application/json'
        },
        body: JSON.stringify({ level: level, comments: reason })
    })
    .then(function(resp) { return resp.json(); })
    .then(function(data) {
        if (data.success) {
            var row = document.getElementById('petrol-req-' + requestId);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.backgroundColor = '#fef2f2';
                row.style.borderColor = '#fca5a5';
                row.innerHTML = '<div class="px-4 py-3 flex items-center justify-between"><span class="text-xs font-bold text-red-700">❌ Rejected</span><span class="text-xs text-gray-400">' + reason + '</span></div>';
            }
        } else {
            alert(data.message || 'Failed to reject');
            btn.disabled = false;
            btn.textContent = '❌ Reject';
        }
    })
    .catch(function(err) {
        console.error('Petrol reject error:', err);
        alert('Error rejecting petrol request');
        btn.disabled = false;
        btn.textContent = '❌ Reject';
    });
}
</script>

<!-- CSS for animations -->
<style>
.stat-card {
    cursor: pointer;
}
.stat-card:active {
    transform: scale(0.98);
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fadeIn {
    animation: fadeIn 0.3s ease-in-out;
}
</style>

@endsection
