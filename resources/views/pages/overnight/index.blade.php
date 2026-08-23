@extends('layouts.app')

@section('title', '🌙 Overnight Storage')

@php
    // Trim trailing zeros: 5.970 -> 5.97, 3.000 -> 3
    $ovFmtQty = function ($qty) {
        $s = number_format((float) $qty, 3, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    };
    $ovSectionMeta = [
        'chiller' => ['icon' => '🧊', 'label' => 'Chiller', 'color' => '#0EA5E9', 'bg' => '#F0F9FF', 'border' => '#BAE6FD', 'text' => '#0369A1'],
        'freezer' => ['icon' => '❄️', 'label' => 'Freezer', 'color' => '#4F46E5', 'bg' => '#EEF2FF', 'border' => '#C7D2FE', 'text' => '#3730A3'],
    ];
@endphp

@section('content')
<div class="container-fluid px-6 py-6">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🌙 Overnight Storage</h1>
            <p class="text-sm text-gray-600 mt-0.5">What's in the chiller & freezer — scan-in happens on the mobile app</p>
        </div>
        @if($activeTab === 'current')
        <button onclick="openManualAddModal()" class="px-4 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm transition-colors" style="background-color:#0EA5E9;" onmouseover="this.style.backgroundColor='#0284C7'" onmouseout="this.style.backgroundColor='#0EA5E9'">
            ＋ Manual Add
        </button>
        @endif
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex gap-1 -mb-px flex-wrap" aria-label="Tabs">
            @php
                $tabs = [
                    'current' => ['icon' => '📦', 'label' => 'Current Items'],
                    'history' => ['icon' => '📜', 'label' => 'History & Daily Summary'],
                ];
            @endphp
            @foreach($tabs as $tabKey => $tabInfo)
            <a href="{{ route('overnight.index', ['tab' => $tabKey]) }}"
               class="inline-flex items-center gap-2 px-5 py-3 text-sm font-medium border-b-2 transition-colors"
               style="{{ $activeTab === $tabKey ? 'border-color: #0EA5E9; color: #0369A1;' : 'border-color: transparent; color: #6B7280;' }}">
                {{ $tabInfo['icon'] }} {{ $tabInfo['label'] }}
            </a>
            @endforeach
        </nav>
    </div>

    {{-- ====================== CURRENT ITEMS TAB ====================== --}}
    @if($activeTab === 'current')

    <!-- Summary tiles -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        @foreach($ovSectionMeta as $secKey => $meta)
        @php $sec = $sections[$secKey]; @endphp
        <div class="rounded-xl border p-4" style="background-color: {{ $meta['bg'] }}; border-color: {{ $meta['border'] }};">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">{{ $meta['icon'] }}</span>
                    <div>
                        <p class="text-sm font-semibold" style="color: {{ $meta['text'] }};">{{ $meta['label'] }}</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $sec['count'] }} <span class="text-sm font-medium text-gray-500">item{{ $sec['count'] === 1 ? '' : 's' }}</span></p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-lg font-bold" style="color: {{ $meta['text'] }};">{{ $ovFmtQty($sec['total_kg']) }} kg</p>
                    @if($sec['total_pcs'] > 0)
                    <p class="text-xs text-gray-500">+ {{ $ovFmtQty($sec['total_pcs']) }} pcs</p>
                    @endif
                    {{-- Verification status at a glance: "was this checked today, and by whom?" --}}
                    @if($sec['count'] > 0)
                        @if($sec['all_verified_today'])
                        {{-- ⚠️ Ternary, not an inline @if: a Blade directive immediately preceded by a
                             word character ("today@if") is NOT compiled, but its @endif is — which
                             leaves an unmatched endif and breaks the whole view. --}}
                        <p class="text-[11px] font-semibold mt-1" style="color:#15803D;">✅ Verified today{{ $sec['last_verification'] ? ' · ' . $sec['last_verification']['by_name'] : '' }}</p>
                        @elseif($sec['verified_today_count'] > 0)
                        <p class="text-[11px] font-semibold mt-1" style="color:#B45309;">⚠️ {{ $sec['verified_today_count'] }} of {{ $sec['count'] }} verified today</p>
                        @else
                        <p class="text-[11px] font-semibold mt-1" style="color:#B45309;">⚠️ Not verified today</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <!-- Two section panels -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach($ovSectionMeta as $secKey => $meta)
        @php $sec = $sections[$secKey]; @endphp
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b" style="background-color: {{ $meta['bg'] }}; border-color: {{ $meta['border'] }};">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-bold" style="color: {{ $meta['text'] }};">{{ $meta['icon'] }} {{ $meta['label'] }} ({{ $sec['count'] }})</p>
                    @if($sec['count'] > 0)
                    <label class="flex items-center gap-1.5 text-xs font-medium cursor-pointer" style="color: {{ $meta['text'] }};">
                        <input type="checkbox" class="ov-select-all" data-section="{{ $secKey }}" onchange="toggleSelectAll('{{ $secKey }}', this.checked)" style="accent-color: {{ $meta['color'] }};">
                        Select all
                    </label>
                    @endif
                </div>
                {{-- Verification strip: who last confirmed this section, and a one-tap re-check --}}
                @if($sec['count'] > 0)
                <div class="flex items-center justify-between gap-2 mt-2 pt-2" style="border-top:1px solid {{ $meta['border'] }};">
                    <div style="min-width:0;">
                        @if($sec['all_verified_today'])
                        <p class="text-[11px] font-semibold" style="color:#15803D;">
                            ✅ All {{ $sec['count'] }} verified today{{ $sec['last_verification'] ? ' — ' . $sec['last_verification']['by_name'] . ' at ' . $sec['last_verification']['at_formatted'] : '' }}
                        </p>
                        @else
                        <p class="text-[11px] font-semibold" style="color:#B45309;">
                            @if($sec['verified_today_count'] > 0)
                                ⚠️ {{ $sec['unverified_today_count'] }} not verified today
                            @else
                                ⚠️ Not verified today
                            @endif
                        </p>
                        @if($sec['last_verification'])
                        <p class="text-[10px] text-gray-500">Last checked {{ $sec['last_verification']['at_ago'] }} by {{ $sec['last_verification']['by_name'] }}</p>
                        @endif
                        @endif
                    </div>
                    <div class="flex items-center gap-1.5">
                        {{-- 🧊 EMPTY CHILLER (owner ask, Aug-16). The morning routine was
                             already "select all → take out"; this is the same action with
                             one tap and a named confirmation. CHILLER ONLY — the freezer
                             is long-term storage and is never emptied wholesale, so
                             offering the button there would only invite an accident. --}}
                        @if($secKey === 'chiller')
                        <button onclick="emptyChiller()" class="px-2.5 py-1 text-[11px] font-bold rounded-lg whitespace-nowrap" style="background:#DC2626; color:#fff;">
                            🧊 Empty chiller
                        </button>
                        @endif
                        @if($canVerify)
                        <button onclick="verifyAll('{{ $secKey }}')" class="px-2.5 py-1 text-[11px] font-bold rounded-lg whitespace-nowrap" style="background:#16A34A; color:#fff;">
                            ✓ Verify all
                        </button>
                        @endif
                    </div>
                </div>
                @endif
            </div>
            <div class="overflow-x-auto" style="max-height: 60vh; overflow-y: auto;">
                @if($sec['count'] === 0)
                <div class="py-12 text-center">
                    <p class="text-3xl mb-2">{{ $meta['icon'] }}</p>
                    <p class="text-sm text-gray-500">Nothing in the {{ strtolower($meta['label']) }}</p>
                </div>
                @else
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 w-8"></th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qty</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Entered</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Age</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($sec['items'] as $item)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-2.5">
                                <input type="checkbox" class="ov-check" data-id="{{ $item['id'] }}" data-section="{{ $secKey }}" onchange="updateBulkBar()" style="accent-color: {{ $meta['color'] }};">
                            </td>
                            <td class="px-3 py-2.5">
                                <p class="text-sm font-medium text-gray-900">{{ $item['product_name'] }}</p>
                                @if($item['source'] === 'manual')
                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium" style="background-color:#F3F4F6; color:#6B7280;">manual</span>
                                @endif
                                @if($item['verified_today'])
                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold" style="background-color:#DCFCE7; color:#15803D;">✓ {{ $item['verified_by_name'] }} · {{ $item['verified_at_formatted'] }}</span>
                                @elseif($item['verified_at'])
                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium" style="background-color:#FEF3C7; color:#B45309;">last ✓ {{ $item['verified_by_name'] }} · {{ $item['verified_at_formatted'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right text-sm font-semibold text-gray-900 whitespace-nowrap">{{ $ovFmtQty($item['quantity']) }} {{ $item['unit'] }}</td>
                            <td class="px-3 py-2.5">
                                {{-- The ORIGINAL entry time, kept for life — this is what identifies
                                     the batch. A move never rewrites it. --}}
                                <p class="text-xs text-gray-700 whitespace-nowrap">{{ $item['entered_at_formatted'] }}</p>
                                @if($item['entered_by_name'])
                                <p class="text-[11px] text-gray-400">by {{ $item['entered_by_name'] }}</p>
                                @endif
                                @if($item['moved_from'])
                                <p class="text-[11px] whitespace-nowrap" style="color:#B45309;">↪ from {{ ucfirst($item['moved_from']) }} · {{ $item['moved_at_formatted'] }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                @if($item['age_days'] >= 6)
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" style="background-color:#FEE2E2; color:#B91C1C;">{{ $item['age_days'] }}d</span>
                                @elseif($item['age_days'] >= 3)
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-bold whitespace-nowrap" style="background-color:#FEF3C7; color:#B45309;">{{ $item['age_days'] }}d</span>
                                @elseif($item['age_days'] >= 1)
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap" style="background-color:#F3F4F6; color:#6B7280;">{{ $item['age_days'] }}d</span>
                                @else
                                <span class="text-[11px] text-gray-400">today</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- ⭐ FROZEN — live store inventory. READ-ONLY on purpose.
         These are not overnight packets and can never become any: the figure IS
         variant.inventory_quantity, which the transfer-accept and prepare/deduct flows
         already maintain, so the freezer figure and the inventory figure are the same
         number. No checkbox, no move, no take-out, no verify — managing this stock
         stays in Khaas transfers / the prepare flow / the store adjustment screen. --}}
    @if(!empty($frozen['products']))
    <div class="mt-4 bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b" style="background-color:#EEF2FF; border-color:#C7D2FE;">
            <div class="flex items-center justify-between">
                <p class="text-sm font-bold" style="color:#3730A3;">🧊❄️ Frozen — live inventory ({{ $frozen['total_packs'] }})</p>
                <span class="text-xs font-medium" style="color:#4F46E5;">{{ $frozen['product_count'] }} product{{ $frozen['product_count'] === 1 ? '' : 's' }}</span>
            </div>
            <p class="text-xs mt-1" style="color:#4F46E5;">
                Yeh store inventory hai — transfer accept aur prepare se khud update hoti hai. Ise yahan se
                move/take-out nahi karte.
            </p>
        </div>
        <div class="divide-y divide-gray-100">
            @foreach($frozen['products'] as $fp)
            <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-gray-900 truncate">{{ $fp['name'] }}</p>
                    @if(!empty($fp['warning']))
                    <p class="text-xs font-semibold" style="color:#B91C1C;">⚠ {{ $fp['warning'] }}</p>
                    @endif
                </div>
                <span class="text-sm font-bold whitespace-nowrap" style="color:#4338CA;">{{ $fp['packets'] }} pkt</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Bulk action bar (fixed; appears when anything is selected).
         Inline-styled: the purged styles.css drops fixed-position utility combos. --}}
    <div id="ovBulkBar" style="display:none; position:fixed; left:50%; transform:translateX(-50%); bottom:1.25rem; z-index:9999; background:#111827; color:#fff; border-radius:1rem; box-shadow:0 10px 25px rgba(0,0,0,0.3); padding:0.6rem 1rem; align-items:center; gap:0.6rem;">
        <span id="ovBulkCount" class="text-sm font-bold" style="white-space:nowrap;">0 selected</span>
        <button onclick="doMove('chiller')" id="ovBtnToChiller" class="px-3 py-1.5 text-xs font-semibold rounded-lg" style="background:#0EA5E9; color:#fff; white-space:nowrap;">🧊 To Chiller</button>
        <button onclick="doMove('freezer')" id="ovBtnToFreezer" class="px-3 py-1.5 text-xs font-semibold rounded-lg" style="background:#4F46E5; color:#fff; white-space:nowrap;">❄️ To Freezer</button>
        @if($canVerify)
        <button onclick="doVerify()" class="px-3 py-1.5 text-xs font-semibold rounded-lg" style="background:#16A34A; color:#fff; white-space:nowrap;">✓ Verify</button>
        @endif
        <button onclick="doTakeOut()" class="px-3 py-1.5 text-xs font-semibold rounded-lg" style="background:#DC2626; color:#fff; white-space:nowrap;">📤 Take Out</button>
        <button onclick="clearSelection()" class="px-3 py-1.5 text-xs font-medium rounded-lg" style="background:#374151; color:#D1D5DB;">Clear</button>
    </div>

    @endif

    {{-- ====================== HISTORY TAB ====================== --}}
    @if($activeTab === 'history')

    <div id="ovReconcileWarn" style="display:none;" class="mb-4 p-3 border rounded-lg flex items-center gap-2" >
        <span>⚠️</span>
        <p class="text-sm" style="color:#92400E;">The movement log and the live item list don't reconcile — someone may have edited rows directly in the database.</p>
    </div>

    <!-- Daily summary -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden mb-6">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
            <p class="text-sm font-bold text-gray-900">📅 Daily Summary <span class="font-normal text-gray-400">— end-of-day items per section</span></p>
            <div class="flex items-center gap-2">
                <button onclick="changeMonth(-1)" class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">◀</button>
                <span id="ovMonthLabel" class="text-sm font-semibold text-gray-900" style="min-width:110px; text-align:center;">—</span>
                <button onclick="changeMonth(1)" id="ovNextMonthBtn" class="w-8 h-8 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">▶</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase" style="color:#0369A1;">🧊 Chiller — end of day</th>
                        <th class="px-4 py-2.5 text-right text-xs font-medium uppercase" style="color:#3730A3;">❄️ Freezer — end of day</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium uppercase" style="color:#15803D;">✓ Verified by</th>
                        <th class="px-4 py-2.5 text-left text-xs font-medium text-gray-500 uppercase">Activity</th>
                    </tr>
                </thead>
                <tbody id="ovSummaryBody" class="divide-y divide-gray-100">
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Event feed -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
            <p class="text-sm font-bold text-gray-900">📜 Activity Log</p>
            <div class="flex items-center gap-1.5" id="ovChips">
                <button onclick="setChip(null)" data-chip="all" class="ov-chip px-3 py-1 rounded-full text-xs font-medium" style="background:#111827; color:#fff;">All</button>
                <button onclick="setChip('in')" data-chip="in" class="ov-chip px-3 py-1 rounded-full text-xs font-medium" style="background:#F3F4F6; color:#374151;">📥 In</button>
                <button onclick="setChip('move')" data-chip="move" class="ov-chip px-3 py-1 rounded-full text-xs font-medium" style="background:#F3F4F6; color:#374151;">🔄 Moved</button>
                <button onclick="setChip('out')" data-chip="out" class="ov-chip px-3 py-1 rounded-full text-xs font-medium" style="background:#F3F4F6; color:#374151;">📤 Out</button>
                <button onclick="setChip('verify')" data-chip="verify" class="ov-chip px-3 py-1 rounded-full text-xs font-medium" style="background:#F3F4F6; color:#374151;">✅ Verified</button>
            </div>
        </div>
        <div id="ovHistoryBody" class="divide-y divide-gray-100">
            <div class="px-4 py-10 text-center text-sm text-gray-400">Loading…</div>
        </div>
        <div class="px-4 py-3 border-t border-gray-100 bg-gray-50 flex justify-center">
            <button id="ovLoadMore" onclick="loadHistory(true)" style="display:none; background-color:#F3F4F6; color:#374151; border:1px solid #D1D5DB;" class="px-4 py-2 text-sm font-medium rounded-lg">Load more</button>
        </div>
    </div>

    @endif
</div>
@endsection

@push('modals')
@if($activeTab === 'current')
{{-- Manual Add Modal.
     ⚠️ The shell is inline-styled on purpose: the purged styles.css drops inset-0 / max-h /
     max-w / overflow-y-auto / flex-shrink-0, which makes class-only modal shells render in
     the top-left corner and refuse to scroll. Do not "clean this up" into classes. --}}
<div id="ovManualModal" class="hidden fixed inset-0" style="z-index: 999999; position:fixed; top:0; right:0; bottom:0; left:0; background-color:rgba(0,0,0,0.5); padding:1rem; display:none; align-items:center; justify-content:center;" onclick="if(event.target===this)closeManualAddModal()">
    <div style="width:100%; max-width:28rem; max-height:85vh; display:flex; flex-direction:column; background:#fff; border-radius:1rem; box-shadow:0 20px 25px -5px rgba(0,0,0,0.10), 0 10px 10px -5px rgba(0,0,0,0.04);" onclick="event.stopPropagation()">
        <div class="px-6 py-4 border-b border-gray-100" style="flex-shrink:0; background: linear-gradient(to right, #F0F9FF, #EEF2FF); padding:1rem 1.5rem;">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-900">＋ Manual Add</h3>
                <button onclick="closeManualAddModal()" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100">✕</button>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">For items without a scale barcode — scanning happens on the mobile app</p>
        </div>
        <div style="flex:1 1 auto; min-height:0; overflow-y:auto; padding:1.25rem 1.5rem;" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Product</label>
                <input type="text" id="ovManualSearch" placeholder="Search products…" oninput="filterManualProducts()"
                       class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm mb-2 focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                <select id="ovManualProduct" size="6" class="w-full px-2 py-1 border-2 border-gray-200 rounded-xl text-sm">
                    <option disabled>Loading products…</option>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Quantity</label>
                    <input type="number" id="ovManualQty" min="0.001" max="999" step="0.001" placeholder="e.g. 2.5"
                           class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-sky-500 focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1.5">Unit</label>
                    <div class="flex rounded-xl overflow-hidden border-2 border-gray-200">
                        <button type="button" id="ovUnitKg" onclick="setManualUnit('kg')" class="flex-1 py-2.5 text-sm font-semibold" style="background:#0EA5E9; color:#fff;">kg</button>
                        <button type="button" id="ovUnitPcs" onclick="setManualUnit('pcs')" class="flex-1 py-2.5 text-sm font-semibold" style="background:#fff; color:#6B7280;">pcs</button>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1.5">Section</label>
                <div class="flex rounded-xl overflow-hidden border-2 border-gray-200">
                    <button type="button" id="ovSecChiller" onclick="setManualSection('chiller')" class="flex-1 py-2.5 text-sm font-semibold" style="background:#0EA5E9; color:#fff;">🧊 Chiller</button>
                    <button type="button" id="ovSecFreezer" onclick="setManualSection('freezer')" class="flex-1 py-2.5 text-sm font-semibold" style="background:#fff; color:#6B7280;">❄️ Freezer</button>
                </div>
            </div>
            <p id="ovManualError" class="text-sm font-medium" style="display:none; color:#DC2626;"></p>
        </div>
        <div class="px-6 py-3 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3" style="flex-shrink:0; padding:0.75rem 1.5rem; display:flex; align-items:center; justify-content:flex-end; gap:0.75rem;">
            <button onclick="closeManualAddModal()" class="px-5 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-xl hover:bg-gray-50">Cancel</button>
            <button id="ovManualSave" onclick="submitManualAdd()" class="px-5 py-2.5 text-sm font-medium text-white rounded-xl shadow-sm" style="background-color:#0EA5E9;">Add Item</button>
        </div>
    </div>
</div>
@endif
@endpush

@push('custom_js')
<script>
var OV_CSRF = '{{ csrf_token() }}';
var OV_ROUTES = {
    items: '{{ route('overnight.items.add') }}',
    move: '{{ route('overnight.move') }}',
    takeOut: '{{ route('overnight.take-out') }}',
    verify: '{{ route('overnight.verify') }}',
    products: '{{ route('overnight.products') }}',
    history: '{{ route('overnight.history') }}',
    dailySummary: '{{ route('overnight.daily-summary') }}'
};

function ovPost(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': OV_CSRF
        },
        body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (j) { j._status = r.status; return j; }); });
}

function ovFmtQty(q) {
    var n = parseFloat(q) || 0;
    return (Math.round(n * 1000) / 1000).toString();
}

@if($activeTab === 'current')
/* ───────────── Current tab: selection + bulk actions ───────────── */

function selectedIds() {
    return Array.prototype.slice.call(document.querySelectorAll('.ov-check:checked'))
        .map(function (cb) { return parseInt(cb.getAttribute('data-id'), 10); });
}

function toggleSelectAll(section, checked) {
    document.querySelectorAll('.ov-check[data-section="' + section + '"]').forEach(function (cb) { cb.checked = checked; });
    updateBulkBar();
}

function clearSelection() {
    document.querySelectorAll('.ov-check, .ov-select-all').forEach(function (cb) { cb.checked = false; });
    updateBulkBar();
}

function updateBulkBar() {
    var checked = document.querySelectorAll('.ov-check:checked');
    var bar = document.getElementById('ovBulkBar');
    if (checked.length === 0) {
        bar.style.display = 'none';
        return;
    }
    bar.style.display = 'flex';
    document.getElementById('ovBulkCount').textContent = checked.length + ' selected';
    // Hide the "move to X" button when EVERY selected item is already in X.
    var sections = {};
    checked.forEach(function (cb) { sections[cb.getAttribute('data-section')] = true; });
    document.getElementById('ovBtnToChiller').style.display = (sections.freezer) ? '' : 'none';
    document.getElementById('ovBtnToFreezer').style.display = (sections.chiller) ? '' : 'none';
}

var ovBusy = false;
function doMove(target) {
    if (ovBusy) return;
    var ids = selectedIds();
    if (ids.length === 0) return;
    ovBusy = true;
    ovPost(OV_ROUTES.move, { item_ids: ids, to_section: target })
        .then(function (j) {
            if (j.success) { location.reload(); }
            else { alert(j.message || 'Move failed'); ovBusy = false; }
        })
        .catch(function () { alert('Network error — nothing was moved.'); ovBusy = false; });
}

/* Verify = "I physically checked these are here". Recorded against the signed-in
   user and shown on this page + in the history. */
function doVerify() {
    if (ovBusy) return;
    var ids = selectedIds();
    if (ids.length === 0) return;
    ovBusy = true;
    ovPost(OV_ROUTES.verify, { item_ids: ids })
        .then(function (j) {
            if (j.success) { location.reload(); }
            else { alert(j.message || 'Verify failed'); ovBusy = false; }
        })
        .catch(function () { alert('Network error — nothing was verified.'); ovBusy = false; });
}

function verifyAll(section) {
    if (ovBusy) return;
    if (!confirm('Confirm that everything listed in the ' + section + ' is physically there?')) return;
    ovBusy = true;
    ovPost(OV_ROUTES.verify, { section: section })
        .then(function (j) {
            if (j.success) { location.reload(); }
            else { alert(j.message || 'Verify failed'); ovBusy = false; }
        })
        .catch(function () { alert('Network error — nothing was verified.'); ovBusy = false; });
}

/* 🧊 Empty the chiller in one action — what the team was doing by hand every
   morning. Sends the SECTION, not a list of ids, so it clears what is actually in
   there at that moment rather than whatever this page happened to load. */
function emptyChiller() {
    if (ovBusy) return;
    var count = document.querySelectorAll('.ov-check[data-section="chiller"]').length;
    if (count === 0) { alert('The chiller is already empty.'); return; }
    if (!confirm('Empty the chiller?\n\nAll ' + count + ' item(s) will be taken out to be used.\n'
        + 'The freezer is not touched.')) return;
    ovBusy = true;
    ovPost(OV_ROUTES.takeOut, { section: 'chiller', source: 'manual' })
        .then(function (j) {
            if (j.success) { location.reload(); }
            else { alert(j.message || 'Could not empty the chiller'); ovBusy = false; }
        })
        .catch(function () { alert('Network error — nothing was taken out.'); ovBusy = false; });
}

function doTakeOut() {
    if (ovBusy) return;
    var ids = selectedIds();
    if (ids.length === 0) return;
    if (!confirm('Take out ' + ids.length + ' item(s) to be used? They will leave the chiller/freezer list.')) return;
    ovBusy = true;
    // Web is list-driven (no camera), so a take-out from here is always 'manual'.
    ovPost(OV_ROUTES.takeOut, { item_ids: ids, source: 'manual' })
        .then(function (j) {
            if (j.success) { location.reload(); }
            else { alert(j.message || 'Take out failed'); ovBusy = false; }
        })
        .catch(function () { alert('Network error — nothing was taken out.'); ovBusy = false; });
}

/* ───────────── Manual add modal ───────────── */

var ovManualProducts = null;   // [{id, name}] — fetched on first open
var ovManualUnit = 'kg';
var ovManualSection = 'chiller';

function openManualAddModal() {
    var m = document.getElementById('ovManualModal');
    m.classList.remove('hidden');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.getElementById('ovManualError').style.display = 'none';
    if (ovManualProducts === null) {
        fetch(OV_ROUTES.products + '?all=1', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                ovManualProducts = (j.manual_products || []);
                renderManualProducts(ovManualProducts);
            })
            .catch(function () {
                document.getElementById('ovManualProduct').innerHTML = '<option disabled>Failed to load products — close and retry</option>';
            });
    }
}

function closeManualAddModal() {
    var m = document.getElementById('ovManualModal');
    m.classList.add('hidden');
    m.style.display = 'none';
    document.body.style.overflow = '';
}

function renderManualProducts(list) {
    var sel = document.getElementById('ovManualProduct');
    if (!list.length) {
        sel.innerHTML = '<option disabled>No products found</option>';
        return;
    }
    sel.innerHTML = list.map(function (p) {
        return '<option value="' + p.id + '">' + String(p.name).replace(/</g, '&lt;') + '</option>';
    }).join('');
}

function filterManualProducts() {
    if (ovManualProducts === null) return;
    var q = document.getElementById('ovManualSearch').value.trim().toLowerCase();
    renderManualProducts(!q ? ovManualProducts : ovManualProducts.filter(function (p) {
        return String(p.name).toLowerCase().indexOf(q) !== -1;
    }));
}

function setManualUnit(u) {
    ovManualUnit = u;
    document.getElementById('ovUnitKg').style.background = (u === 'kg') ? '#0EA5E9' : '#fff';
    document.getElementById('ovUnitKg').style.color = (u === 'kg') ? '#fff' : '#6B7280';
    document.getElementById('ovUnitPcs').style.background = (u === 'pcs') ? '#0EA5E9' : '#fff';
    document.getElementById('ovUnitPcs').style.color = (u === 'pcs') ? '#fff' : '#6B7280';
}

function setManualSection(s) {
    ovManualSection = s;
    document.getElementById('ovSecChiller').style.background = (s === 'chiller') ? '#0EA5E9' : '#fff';
    document.getElementById('ovSecChiller').style.color = (s === 'chiller') ? '#fff' : '#6B7280';
    document.getElementById('ovSecFreezer').style.background = (s === 'freezer') ? '#4F46E5' : '#fff';
    document.getElementById('ovSecFreezer').style.color = (s === 'freezer') ? '#fff' : '#6B7280';
}

function submitManualAdd() {
    var err = document.getElementById('ovManualError');
    err.style.display = 'none';
    var productId = parseInt(document.getElementById('ovManualProduct').value, 10);
    var qty = parseFloat(document.getElementById('ovManualQty').value);
    if (!productId) { err.textContent = 'Pick a product.'; err.style.display = 'block'; return; }
    if (!qty || qty <= 0) { err.textContent = 'Enter a quantity.'; err.style.display = 'block'; return; }
    var btn = document.getElementById('ovManualSave');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    ovPost(OV_ROUTES.items, {
        section: ovManualSection,
        items: [{ product_id: productId, quantity: qty, unit: ovManualUnit, source: 'manual' }]
    }).then(function (j) {
        if (j.success) { location.reload(); }
        else {
            err.textContent = j.message || 'Save failed.';
            err.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Add Item';
        }
    }).catch(function () {
        err.textContent = 'Network error — nothing was saved.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Add Item';
    });
}
@endif

@if($activeTab === 'history')
/* ───────────── History tab ───────────── */

var MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
var ovNow = new Date();
var ovYear = ovNow.getFullYear();
var ovMonth = ovNow.getMonth() + 1; // 1-12
var ovChip = null;
var ovNextBeforeId = null;

function ovMonthStr() {
    return ovYear + '-' + (ovMonth < 10 ? '0' : '') + ovMonth;
}

function changeMonth(delta) {
    ovMonth += delta;
    if (ovMonth > 12) { ovMonth = 1; ovYear++; }
    if (ovMonth < 1) { ovMonth = 12; ovYear--; }
    loadSummary();
}

function loadSummary() {
    document.getElementById('ovMonthLabel').textContent = MONTH_NAMES[ovMonth - 1] + ' ' + ovYear;
    // Never navigate past the current month.
    var isCurrent = (ovYear === ovNow.getFullYear() && ovMonth === ovNow.getMonth() + 1);
    document.getElementById('ovNextMonthBtn').disabled = isCurrent;
    document.getElementById('ovNextMonthBtn').style.opacity = isCurrent ? '0.35' : '1';

    var body = document.getElementById('ovSummaryBody');
    body.innerHTML = '<tr><td colspan="4" class="px-4 py-10 text-center text-sm text-gray-400">Loading…</td></tr>';
    fetch(OV_ROUTES.dailySummary + '?month=' + ovMonthStr(), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) { body.innerHTML = '<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-red-500">' + (j.message || 'Failed') + '</td></tr>'; return; }
            document.getElementById('ovReconcileWarn').style.display = j.reconciled ? 'none' : 'flex';
            document.getElementById('ovReconcileWarn').style.backgroundColor = '#FFFBEB';
            document.getElementById('ovReconcileWarn').style.borderColor = '#FDE68A';
            if (!j.days.length) {
                body.innerHTML = '<tr><td colspan="5" class="px-4 py-10 text-center text-sm text-gray-400">No data for this month</td></tr>';
                return;
            }
            body.innerHTML = j.days.map(function (d) {
                var act = [];
                var totalIn = d.chiller.in_count + d.freezer.in_count;
                var totalOut = d.chiller.out_count + d.freezer.out_count;
                var totalMoved = d.chiller.moved_in_count + d.freezer.moved_in_count;
                if (totalIn) act.push('<span style="color:#15803D;">📥 ' + totalIn + ' in</span>');
                if (totalMoved) act.push('<span style="color:#B45309;">🔄 ' + totalMoved + ' moved</span>');
                if (totalOut) act.push('<span style="color:#B91C1C;">📤 ' + totalOut + ' out</span>');
                // Who signed off that day, and on what (one line per section check).
                var verifCell = '<span class="text-gray-300">—</span>';
                if (d.verifications && d.verifications.length) {
                    verifCell = d.verifications.map(function (v) {
                        return '<span style="color:#15803D; font-weight:600;">✓ ' + v.by_name + '</span>'
                            + '<span class="text-gray-400"> · ' + v.section + ' ' + v.item_count + ' @ ' + v.at_formatted + '</span>';
                    }).join('<br>');
                }
                return '<tr class="' + (d.has_activity ? '' : 'opacity-60') + ' hover:bg-gray-50">'
                    + '<td class="px-4 py-2.5 text-sm ' + (d.label === 'Today' ? 'font-bold text-sky-700' : 'font-medium text-gray-900') + ' whitespace-nowrap">' + d.label + '</td>'
                    + '<td class="px-4 py-2.5 text-right whitespace-nowrap"><span class="text-sm font-bold text-gray-900">' + d.chiller.closing_count + '</span> <span class="text-xs text-gray-500">items · ' + ovFmtQty(d.chiller.closing_kg) + ' kg</span></td>'
                    + '<td class="px-4 py-2.5 text-right whitespace-nowrap"><span class="text-sm font-bold text-gray-900">' + d.freezer.closing_count + '</span> <span class="text-xs text-gray-500">items · ' + ovFmtQty(d.freezer.closing_kg) + ' kg</span></td>'
                    + '<td class="px-4 py-2.5 text-[11px]">' + verifCell + '</td>'
                    + '<td class="px-4 py-2.5 text-xs">' + (act.length ? act.join(' &nbsp; ') : '<span class="text-gray-300">—</span>') + '</td>'
                    + '</tr>';
            }).join('');
        })
        .catch(function () {
            body.innerHTML = '<tr><td colspan="4" class="px-4 py-10 text-center text-sm text-red-500">Failed to load</td></tr>';
        });
}

function setChip(action) {
    ovChip = action;
    document.querySelectorAll('.ov-chip').forEach(function (b) {
        var active = (action === null && b.getAttribute('data-chip') === 'all') || b.getAttribute('data-chip') === action;
        b.style.background = active ? '#111827' : '#F3F4F6';
        b.style.color = active ? '#fff' : '#374151';
    });
    loadHistory(false);
}

function loadHistory(append) {
    var body = document.getElementById('ovHistoryBody');
    if (!append) {
        ovNextBeforeId = null;
        body.innerHTML = '<div class="px-4 py-10 text-center text-sm text-gray-400">Loading…</div>';
    }
    var url = OV_ROUTES.history + '?limit=40'
        + (ovChip ? '&action=' + ovChip : '')
        + (append && ovNextBeforeId ? '&before_id=' + ovNextBeforeId : '');
    fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (!j.success) { body.innerHTML = '<div class="px-4 py-10 text-center text-sm text-red-500">' + (j.message || 'Failed') + '</div>'; return; }
            var html = j.days.map(function (day) {
                var rows = day.events.map(function (ev) {
                    var colorMap = { green: '#15803D', amber: '#B45309', red: '#B91C1C', blue: '#1D4ED8', gray: '#6B7280' };
                    var c = colorMap[ev.color] || '#6B7280';
                    return '<div class="px-4 py-2.5 flex items-center gap-3">'
                        + '<span class="text-lg">' + ev.icon + '</span>'
                        + '<div style="flex:1; min-width:0;">'
                        + '<p class="text-sm text-gray-900"><span class="font-semibold">' + String(ev.product_name).replace(/</g, '&lt;') + '</span> <span class="text-gray-500">· ' + ovFmtQty(ev.quantity) + ' ' + ev.unit + '</span></p>'
                        + '<p class="text-xs" style="color:' + c + ';">' + ev.label + ' <span class="text-gray-400">· ' + ev.detail + ' · ' + ev.date_formatted + '</span></p>'
                        + '</div></div>';
                }).join('');
                return '<div class="px-4 py-2 bg-gray-50 flex items-center justify-between">'
                    + '<p class="text-xs font-bold text-gray-600 uppercase">' + day.label + '</p>'
                    + '<p class="text-[11px] text-gray-400">'
                    + (day.in_count ? '📥 ' + day.in_count + ' &nbsp;' : '')
                    + (day.moved_count ? '🔄 ' + day.moved_count + ' &nbsp;' : '')
                    + (day.out_count ? '📤 ' + day.out_count + ' &nbsp;' : '')
                    + (day.verify_count ? '✅ ' + day.verify_count : '')
                    + '</p></div>' + rows;
            }).join('');
            if (!append) {
                body.innerHTML = html || '<div class="px-4 py-10 text-center text-sm text-gray-400">No activity yet</div>';
            } else {
                body.insertAdjacentHTML('beforeend', html);
            }
            ovNextBeforeId = j.next_before_id;
            document.getElementById('ovLoadMore').style.display = j.has_more ? '' : 'none';
        })
        .catch(function () {
            if (!append) body.innerHTML = '<div class="px-4 py-10 text-center text-sm text-red-500">Failed to load</div>';
        });
}

loadSummary();
loadHistory(false);
@endif
</script>
@endpush
