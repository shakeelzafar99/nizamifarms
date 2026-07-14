{{-- resources/views/pages/roles/mobile-permissions.blade.php
     Phase 3c-v2 (Jul-2026): rebuilt on the SAME visual system as the Web tab
     (pages/roles/permissions.blade.php) so switching tabs is seamless, and the
     alphabetical DB groups are re-clustered into 9 logical sections at DISPLAY level
     only — grouping is a code→section map in this file; the catalog, field names
     (permissions[code]), form action/method and save logic are unchanged. Codes not
     in the map fall back to a section named after their DB group, so anything added
     later by SQL still renders (round-trip safety). Dead codes live in a collapsed
     drawer like the Web tab's Legacy drawer. --}}

@extends('layouts.app')

@section('title', 'Role Access — Mobile')

@section('content')
<style>
  .nfp-wrap { max-width: 920px; margin: 0 auto; padding: 24px 16px 120px; color: #111827; }
  .nfp-head { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
  .nfp-ava { width: 44px; height: 44px; border-radius: 11px; background: #EFF6FF; color: #1D4ED8; display: grid; place-items: center; font-weight: 800; font-size: 16px; flex: none; }
  .nfp-title { font-size: 20px; font-weight: 750; margin: 0; }
  .nfp-sub { font-size: 12.5px; color: #6B7280; margin: 2px 0 0; }
  .nfp-head-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
  .nfp-btn { appearance: none; cursor: pointer; border: 1px solid #D1D5DB; background: #fff; color: #374151; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; }
  .nfp-btn:hover { background: #F9FAFB; }

  .nfp-tabs { display: inline-flex; gap: 2px; background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 9px; padding: 3px; margin: 14px 0 4px; }
  .nfp-tab { appearance: none; border: 0; cursor: pointer; font-size: 13px; font-weight: 600; color: #4B5563; background: transparent; padding: 7px 16px; border-radius: 7px; text-decoration: none; }
  .nfp-tab.is-on { background: #fff; color: #1D4ED8; box-shadow: 0 1px 2px rgba(0,0,0,.08); }

  .nfp-authbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 12px 0 2px; padding: 10px 12px; background: #F9FAFB; border: 1px solid #EEF1F5; border-radius: 10px; }
  .nfp-authlbl { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #9CA3AF; }
  .nfp-authchip { appearance: none; cursor: pointer; border: 1px solid #D1D5DB; background: #fff; color: #4B5563; border-radius: 20px; padding: 6px 14px; font-size: 12.5px; font-weight: 650; display: inline-flex; align-items: center; gap: 7px; }
  .nfp-authchip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #CBD5E1; flex: none; }
  .nfp-authchip[aria-pressed="true"] { border-color: #059669; background: #ECFDF5; color: #059669; }
  .nfp-authchip[aria-pressed="true"]::before { background: #059669; }
  .nfp-authchip:disabled { opacity: .6; cursor: default; }
  .nfp-authhint { font-size: 11px; color: #9CA3AF; margin-left: auto; }

  .nfp-modebar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 8px 0 2px; padding: 10px 12px; background: #F9FAFB; border: 1px solid #EEF1F5; border-radius: 10px; }
  .nfp-modechip { display: inline-flex; align-items: center; gap: 7px; border-radius: 20px; padding: 6px 13px; font-size: 12px; font-weight: 650; border: 1px solid #E5E7EB; background: #fff; color: #9CA3AF; }
  .nfp-modechip::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #D1D5DB; flex: none; }
  .nfp-modechip.on { color: #374151; }
  .nfp-modechip.on::before { background: #22C55E; }
  .nfp-modechip.on.store { border-color: #C4B5FD; background: #F5F3FF; color: #7C3AED; }
  .nfp-modechip.on.khaas { border-color: #FCD34D; background: #FFFBEB; color: #B45309; }
  .nfp-modechip.on.qurbani { border-color: #99F6E4; background: #F0FDFA; color: #0F766E; }
  .nfp-modecount { margin-left: auto; font-size: 11.5px; color: #9CA3AF; }

  .nfp-tools { position: sticky; top: 0; z-index: 5; background: #fff; padding: 10px 0; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid #EEF1F5; }
  .nfp-find { flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px; border: 1px solid #E5E7EB; border-radius: 9px; padding: 9px 12px; background: #F9FAFB; }
  .nfp-find input { border: 0; outline: 0; background: transparent; font-size: 13px; width: 100%; color: #111827; }
  .nfp-changes { font-size: 12px; color: #D97706; font-weight: 650; display: none; }
  .nfp-changes.is-on { display: inline; }

  .nfp-group { padding: 18px 0 4px; border-bottom: 1px solid #F1F3F6; transition: opacity .18s; }
  .nfp-group > h3 { margin: 0 0 2px; font-size: 12px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; color: #111827; display: flex; align-items: center; gap: 8px; }
  .nfp-group.khaas > h3 { color: #B45309; }
  .nfp-ghint { margin: 0 0 10px; font-size: 11.5px; color: #9CA3AF; }

  .nfp-row { display: flex; align-items: center; gap: 14px; padding: 9px 10px; border-radius: 10px; }
  .nfp-row:hover { background: #F9FAFB; }
  .nfp-row .meta { flex: 1; min-width: 0; }
  .nfp-row .name { font-size: 13.5px; font-weight: 600; color: #111827; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .nfp-row .name code { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; color: #9CA3AF; font-weight: 500; }
  .nfp-row .help { font-size: 11.5px; color: #9CA3AF; margin-top: 1px; }
  .nfp-row.muted { opacity: .6; }
  .nfp-row.muted .name { color: #9CA3AF; font-weight: 500; }

  {{-- master mode rows --}}
  .nfp-row.master { border: 1px solid #E5E7EB; border-radius: 10px; padding: 12px; margin-bottom: 6px; }
  .nfp-row.master.store { background: #F5F3FF; border-color: #C4B5FD; }
  .nfp-row.master.store .name { color: #7C3AED; }
  .nfp-row.master.khaas { background: #FFFBEB; border-color: #FCD34D; }
  .nfp-row.master.khaas .name { color: #B45309; }
  .nfp-row.master.qurbani { background: #F0FDFA; border-color: #99F6E4; }
  .nfp-row.master.qurbani .name { color: #0F766E; }
  .nfp-onpill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; background: #DCFCE7; color: #15803D; }

  input.nfp-cb { width: 18px; height: 18px; flex: none; accent-color: #2563EB; cursor: pointer; }

  .nfp-tag { font-size: 9.5px; font-weight: 800; letter-spacing: .03em; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
  .nfp-tag.dead { color: #6B7280; background: #F3F4F6; border: 1px solid #E5E7EB; }
  .nfp-tag.data { color: #0F766E; background: #F0FDFA; }

  details.nfp-legacydraw { margin: 12px 0 4px; border: 1px dashed #D1D5DB; border-radius: 12px; overflow: hidden; }
  details.nfp-legacydraw > summary { cursor: pointer; list-style: none; padding: 12px 16px; background: #F9FAFB; color: #4B5563; font-size: 12.5px; font-weight: 650; display: flex; align-items: center; gap: 8px; }
  details.nfp-legacydraw > summary::-webkit-details-marker { display: none; }
  details.nfp-legacydraw > summary .chev { transition: transform .18s; }
  details.nfp-legacydraw[open] > summary .chev { transform: rotate(90deg); }
  .nfp-legacybody { padding: 6px 12px 14px; }
  .nfp-legacybody .note { font-size: 11.5px; color: #6B7280; padding: 4px 6px 8px; }

  .nfp-savebar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 20; background: #fff; border-top: 1px solid #E5E7EB; box-shadow: 0 -4px 16px -8px rgba(0,0,0,.2); padding: 12px 16px; }
  .nfp-savebar .inner { max-width: 920px; margin: 0 auto; width: 100%; display: flex; align-items: center; gap: 12px; }
  .nfp-save { appearance: none; border: 0; cursor: pointer; background: #2563EB; color: #fff; font-weight: 700; font-size: 13.5px; padding: 10px 20px; border-radius: 9px; margin-left: auto; }
  .nfp-save:hover { background: #1D4ED8; }
</style>

@php
    // ---- Display-level re-clustering (code → section). DB grouping untouched. ----
    $sections = [
        'modes'     => ['title' => 'Mode Access', 'hint' => 'Master switches — which app modes this role can enter. Rider mode is always available.', 'requires' => null],
        'orders'    => ['title' => 'Orders & Dispatch', 'hint' => 'Store-mode order handling, dispatch and scanning.', 'requires' => 'store'],
        'messaging' => ['title' => 'Messaging & Campaigns', 'hint' => 'WhatsApp inbox, sending, labels, automations and campaigns.', 'requires' => 'store'],
        'finance'   => ['title' => 'Finance & Expenses', 'hint' => 'Expenses, ledger, daily closing, assets and reports.', 'requires' => 'store'],
        'people'    => ['title' => 'Customers, Products & Vendors', 'hint' => null, 'requires' => 'store'],
        'approvals' => ['title' => 'Approvals', 'hint' => null, 'requires' => 'store'],
        'team'      => ['title' => 'Attendance & Shifts', 'hint' => null, 'requires' => 'store'],
        'khaas'     => ['title' => '🌿 Khaas Features', 'hint' => 'Khaas-mode operations (transfer approval, production planning) and the Khaas inventory entry in the store sidebar.', 'requires' => 'khaas'],
        'qurbani'   => ['title' => 'Qurbani', 'hint' => 'Seasonal.', 'requires' => null],
    ];
    $sectionOf = [
        'access_store_mode' => 'modes', 'access_khaas_mode' => 'modes', 'access_qurbani_mode' => 'modes',
        'view_open_orders' => 'orders', 'assign_riders' => 'orders', 'change_order_status' => 'orders',
        'enter_packet_info' => 'orders', 'view_riders_map' => 'orders', 'view_delivery_regions' => 'orders',
        'view_dispatch_tracker' => 'orders', 'view_rider_reports' => 'orders', 'view_open_quantities' => 'orders',
        'scan_line_item_qty' => 'orders', 'dispatch_scan' => 'orders', 'print_receipts' => 'orders',
        'create_orders' => 'orders', 'view_shopify_orders' => 'orders',
        'view_whatsapp_messages' => 'messaging', 'view_whatsapp_messages_limited' => 'messaging',
        'send_whatsapp_messages' => 'messaging', 'manage_whatsapp_labels' => 'messaging',
        'manage_wa_auto_reply' => 'messaging', 'whatsapp_super_reader' => 'messaging',
        'whatsapp_marketing_dedup_override' => 'messaging', 'view_campaigns' => 'messaging',
        'view_expenses' => 'finance', 'approve_expenses' => 'finance', 'settle_expenses' => 'finance',
        'expense_all_payment_sources' => 'finance', 'create_expense_category' => 'finance',
        'configure_expense_bubbles' => 'finance', 'expense_type_qurbani' => 'finance',
        'view_nf_ledger' => 'finance', 'view_daily_closing' => 'finance', 'add_assets' => 'finance',
        'view_reports' => 'finance',
        'view_customers' => 'people', 'view_products' => 'people', 'view_vendors' => 'people',
        'view_approvals' => 'approvals', 'view_online_approvals' => 'approvals',
        'view_store_attendance' => 'team', 'view_attendance_reports' => 'team', 'manage_shifts' => 'team',
        'approve_khaas_transfer' => 'khaas', 'create_production_demand' => 'khaas', 'view_khaas_store_inventory' => 'khaas',
        'view_qurbani_invoices' => 'qurbani',
    ];
    // Audit-confirmed unused codes → collapsed drawer (kept in catalog, still saveable).
    $deadCodes = [
        'view_overall_ledger', 'manage_vendor_transactions', 'manage_vendor_products',
        'view_assets', 'view_all_business_units', 'select_business_unit',
        'manage_store_expenses', 'view_store_reports',
    ];
    $futureCodes = ['manage_store_expenses', 'view_store_reports'];
    // Codes read through DATA rather than a direct code check (request-type gate).
    $dataDrivenCodes = ['expense_type_qurbani'];

    // Flatten the DB groups, bucket by section; unmapped codes go to a fallback
    // bucket named after their DB group so new SQL-added codes always render.
    $buckets = [];
    $fallbacks = [];
    foreach ($permissionsGrouped as $group => $perms) {
        foreach ($perms as $p) {
            if (in_array($p->permission_code, $deadCodes)) { $buckets['__dead'][] = $p; continue; }
            $sec = $sectionOf[$p->permission_code] ?? null;
            if ($sec) { $buckets[$sec][] = $p; }
            else { $fallbacks[ucwords(str_replace('_', ' ', $group))][] = $p; }
        }
    }
    $hasStoreMode = in_array('access_store_mode', $currentPermissions);
    $hasKhaasMode = in_array('access_khaas_mode', $currentPermissions);
    $hasQurbaniMode = in_array('access_qurbani_mode', $currentPermissions);
    $masterMeta = [
        'access_store_mode'   => ['cls' => 'store',   'note' => null],
        'access_khaas_mode'   => ['cls' => 'khaas',   'note' => '⚠ Also controls the Khaas section on the WEB dashboard (sidebar, products, operations, sales report).'],
        'access_qurbani_mode' => ['cls' => 'qurbani', 'note' => null],
    ];
@endphp

<div class="nfp-wrap">
    <div class="nfp-head">
        <div class="nfp-ava">{{ strtoupper(substr($role->urole_name ?? 'R', 0, 2)) }}</div>
        <div>
            <h2 class="nfp-title">{{ $role->urole_name }}</h2>
            <p class="nfp-sub">Role Access · type <strong>{{ ucfirst($role->type) }}</strong>
               @isset($roleUserCount) · {{ $roleUserCount }} user{{ $roleUserCount == 1 ? '' : 's' }}@endisset</p>
        </div>
        <div class="nfp-head-actions">
            <a href="{{ route('roles.index') }}" class="nfp-btn">Back to Roles</a>
        </div>
    </div>

    <div class="nfp-tabs">
        <a class="nfp-tab" href="{{ route('roles.permissions.manage', $role->id) }}">Web</a>
        <span class="nfp-tab is-on">Mobile</span>
    </div>

    <div class="nfp-authbar" id="nfp-authbar" data-role-id="{{ $role->id }}">
        <span class="nfp-authlbl">Approval authority</span>
        <button type="button" class="nfp-authchip" data-level="1" data-id="{{ $approvalL1Id ?? '' }}"
                aria-pressed="{{ !empty($approvalL1Id) ? 'true' : 'false' }}" {{ empty($canManageApprovals) ? 'disabled' : '' }}>
            Level 1 approver
        </button>
        <button type="button" class="nfp-authchip" data-level="2" data-id="{{ $approvalL2Id ?? '' }}"
                aria-pressed="{{ !empty($approvalL2Id) ? 'true' : 'false' }}" {{ empty($canManageApprovals) ? 'disabled' : '' }}>
            Level 2 approver
        </button>
        <span class="nfp-authhint">A role can hold both. @if(empty($canManageApprovals))Read-only — needs the Requests-settings permission.@else Changes apply immediately.@endif</span>
    </div>

    <div class="nfp-modebar">
        <span class="nfp-modechip on">🏍 Rider Mode · always on</span>
        <span class="nfp-modechip {{ $hasStoreMode ? 'on store' : '' }}">🏪 Store Mode {{ $hasStoreMode ? '' : '· off' }}</span>
        <span class="nfp-modechip {{ $hasKhaasMode ? 'on khaas' : '' }}">🌿 Khaas Mode {{ $hasKhaasMode ? '' : '· off' }}</span>
        <span class="nfp-modechip {{ $hasQurbaniMode ? 'on qurbani' : '' }}">🐐 Qurbani Mode {{ $hasQurbaniMode ? '' : '· off' }}</span>
        <span class="nfp-modecount">{{ count($currentPermissions) }} permission{{ count($currentPermissions) !== 1 ? 's' : '' }} granted</span>
    </div>

    @if(session('success'))
        <div style="margin:10px 0;padding:10px 14px;border-radius:10px;background:#ECFDF5;border:1px solid #A7F3D0;color:#065F46;font-size:13px;"><strong>✓</strong> {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="margin:10px 0;padding:10px 14px;border-radius:10px;background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;font-size:13px;"><strong>✗</strong> {{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('roles.mobile-permissions.update', $role->id) }}" id="nfp-form">
        @csrf
        @method('PUT')

        <div class="nfp-tools">
            <div class="nfp-find">
                <span aria-hidden="true">🔍</span>
                <input type="text" id="nfp-search" placeholder="Filter permissions…" autocomplete="off" spellcheck="false">
            </div>
            <span class="nfp-changes" id="nfp-changes">● unsaved changes</span>
        </div>

        @foreach($sections as $secKey => $sec)
            @php $items = $buckets[$secKey] ?? []; @endphp
            @if(count($items))
            <div class="nfp-group {{ $secKey === 'khaas' ? 'khaas' : '' }}" data-nf-sec="{{ $secKey }}" data-nf-requires="{{ $sec['requires'] ?? '' }}">
                <h3>{{ $sec['title'] }}</h3>
                @if($sec['hint'])<p class="nfp-ghint">{{ $sec['hint'] }}</p>@endif
                @foreach($items as $permission)
                    @php
                        $code = $permission->permission_code;
                        $isChecked = in_array($code, $currentPermissions);
                        $isMaster = isset($masterMeta[$code]) && $secKey === 'modes';
                        $isDataDriven = in_array($code, $dataDrivenCodes);
                    @endphp
                    <label class="nfp-row {{ $isMaster ? 'master ' . $masterMeta[$code]['cls'] : '' }}">
                        <div class="meta">
                            <div class="name">
                                {{ $permission->permission_name }}
                                @if($isMaster && $isChecked)<span class="nfp-onpill">Enabled</span>@endif
                                @if($isDataDriven)<span class="nfp-tag data" title="Consumed via the request-type mapping (Requests Setup), not a direct code check">via request types</span>@endif
                                <code>{{ $code }}</code>
                            </div>
                            @if($permission->description)<div class="help">{{ $permission->description }}</div>@endif
                            @if($isMaster && $masterMeta[$code]['note'])<div class="help" style="color:#92400E;">{{ $masterMeta[$code]['note'] }}</div>@endif
                        </div>
                        <input type="checkbox" class="nfp-cb" name="permissions[{{ $code }}]" value="1" {{ $isChecked ? 'checked' : '' }}
                               @if($code === 'access_store_mode') data-nf-master="store" @elseif($code === 'access_khaas_mode') data-nf-master="khaas" @endif>
                    </label>
                @endforeach
            </div>
            @endif
        @endforeach

        {{-- Fallback buckets: codes added to the catalog later that this file's map
             doesn't know yet. They render here so they are always visible + saveable. --}}
        @foreach($fallbacks as $title => $items)
            <div class="nfp-group" data-nf-sec="other">
                <h3>{{ $title }}</h3>
                @foreach($items as $permission)
                    <label class="nfp-row">
                        <div class="meta">
                            <div class="name">{{ $permission->permission_name }} <code>{{ $permission->permission_code }}</code></div>
                            @if($permission->description)<div class="help">{{ $permission->description }}</div>@endif
                        </div>
                        <input type="checkbox" class="nfp-cb" name="permissions[{{ $permission->permission_code }}]" value="1" {{ in_array($permission->permission_code, $currentPermissions) ? 'checked' : '' }}>
                    </label>
                @endforeach
            </div>
        @endforeach

        {{-- Dead / future codes — collapsed drawer, same pattern as the Web tab. --}}
        @php $deadItems = $buckets['__dead'] ?? []; @endphp
        @if(count($deadItems))
        <details class="nfp-legacydraw">
            <summary>
                <svg class="chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
                Not used yet ({{ count($deadItems) }})
                <span class="nfp-tag dead" style="margin-left:8px">hidden by default</span>
            </summary>
            <div class="nfp-legacybody">
                <div class="note">In the catalog but checked by no code today (two are future features). Kept and still saveable — just out of the way.</div>
                @foreach($deadItems as $permission)
                    <label class="nfp-row muted">
                        <div class="meta">
                            <div class="name">
                                {{ $permission->permission_name }}
                                <span class="nfp-tag dead">{{ in_array($permission->permission_code, $futureCodes) ? 'Future' : 'Not used' }}</span>
                                <code>{{ $permission->permission_code }}</code>
                            </div>
                            @if($permission->description)<div class="help">{{ $permission->description }}</div>@endif
                        </div>
                        <input type="checkbox" class="nfp-cb" name="permissions[{{ $permission->permission_code }}]" value="1" {{ in_array($permission->permission_code, $currentPermissions) ? 'checked' : '' }}>
                    </label>
                @endforeach
            </div>
        </details>
        @endif

        <div class="nfp-savebar">
            <div class="inner">
                <span class="nfp-changes" id="nfp-changes2">● unsaved changes — remember to save</span>
                <button type="submit" class="nfp-save">💾 Save Mobile Permissions</button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    "use strict";
    function markChanged() {
        var a = document.getElementById('nfp-changes');
        var b = document.getElementById('nfp-changes2');
        if (a) a.classList.add('is-on');
        if (b) b.classList.add('is-on');
    }
    document.getElementById('nfp-form').addEventListener('change', markChanged);

    // Filter (same behaviour as the Web tab).
    var search = document.getElementById('nfp-search');
    if (search) {
        search.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            document.querySelectorAll('.nfp-group, .nfp-legacydraw').forEach(function (grp) {
                var rows = grp.querySelectorAll('.nfp-row');
                var any = false;
                rows.forEach(function (r) {
                    var m = !term || (r.textContent || '').toLowerCase().indexOf(term) !== -1;
                    r.style.display = m ? '' : 'none';
                    if (m) any = true;
                });
                grp.style.display = (!term || any) ? '' : 'none';
            });
            if (term) { var d = document.querySelector('.nfp-legacydraw'); if (d) d.open = true; }
        });
    }

    // Master-mode visual cue: sections marked data-nf-requires mute (never disable)
    // when their master toggle is off. Grants stay editable & saved regardless.
    function bindMaster(kind) {
        var master = document.querySelector('input[data-nf-master="' + kind + '"]');
        if (!master) return;
        var secs = Array.prototype.slice.call(document.querySelectorAll('.nfp-group[data-nf-requires="' + kind + '"]'));
        function apply() {
            secs.forEach(function (g) {
                g.style.opacity = master.checked ? '' : '0.55';
                g.title = master.checked ? '' : 'Requires the mode master switch above (still editable & saved)';
            });
        }
        master.addEventListener('change', apply);
        apply();
    }
    bindMaster('store');
    bindMaster('khaas');

    // Approval-authority chips — identical wiring to the Web tab (existing endpoints).
    (function () {
        var bar = document.getElementById('nfp-authbar');
        if (!bar) return;
        var tokenEl = document.querySelector('#nfp-form input[name=_token]');
        var token = tokenEl ? tokenEl.value : '';
        var roleId = bar.getAttribute('data-role-id');
        var assignUrl = "{{ route('requests.settings.roles.assign') }}";
        var removeUrlTpl = "{{ route('requests.settings.roles.remove', '__ID__') }}";
        bar.querySelectorAll('.nfp-authchip').forEach(function (chip) {
            if (chip.disabled) return;
            chip.addEventListener('click', function () {
                var level = chip.getAttribute('data-level');
                var id = chip.getAttribute('data-id');
                var on = chip.getAttribute('aria-pressed') === 'true';
                chip.disabled = true;
                var req;
                if (on && id) {
                    req = fetch(removeUrlTpl.replace('__ID__', id), {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                } else {
                    req = fetch(assignUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: JSON.stringify({ role_id: roleId, approval_level: level })
                    });
                }
                req.then(function (r) { return r.json().catch(function () { return {}; }); })
                   .then(function (d) {
                       if (d && d.success) {
                           if (on) { chip.setAttribute('aria-pressed', 'false'); chip.setAttribute('data-id', ''); }
                           else { chip.setAttribute('aria-pressed', 'true'); chip.setAttribute('data-id', d.data && d.data.id ? d.data.id : ''); }
                       } else {
                           alert((d && d.message) || 'Could not update approval level.');
                       }
                   })
                   .catch(function () { alert('Network error updating approval level.'); })
                   .finally(function () { chip.disabled = false; });
            });
        });
    })();
})();
</script>
@endsection
