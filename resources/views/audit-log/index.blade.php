@extends('layouts.app')

@section('title', 'Audit Trail')

@php
if (!function_exists('nf_render_changes')):
/** Render a {field:{old,new}} JSON blob as readable "field: old → new" lines. */
function nf_render_changes($json) {
    if (!$json) return '';
    $c = json_decode($json, true);
    if (!is_array($c)) return e($json);
    $out = [];
    foreach ($c as $field => $v) {
        $old = is_array($v) ? ($v['old'] ?? null) : null;
        $new = is_array($v) ? ($v['new'] ?? null) : $v;
        $oldS = ($old === null || $old === '') ? '∅' : e($old);
        $newS = ($new === null || $new === '') ? '∅' : e($new);
        $out[] = "<span style='color:#6b7280'>".e($field).":</span> <span style='color:#b91c1c'>{$oldS}</span> <span style='color:#9ca3af'>&rarr;</span> <span style='color:#047857'>{$newS}</span>";
    }
    return implode('<br>', $out);
}
function nf_source_badge($s) {
    $map = ['web'=>'bg-blue-50 text-blue-700 border-blue-200','mobile'=>'bg-purple-50 text-purple-700 border-purple-200','customer_app'=>'bg-amber-50 text-amber-700 border-amber-200','system'=>'bg-gray-100 text-gray-600 border-gray-200'];
    $cls = $map[$s] ?? 'bg-gray-100 text-gray-600 border-gray-200';
    return "<span class='inline-block px-2 py-0.5 rounded border text-xs {$cls}'>".e($s ?: '—')."</span>";
}
endif;
@endphp

@section('content')
<div class="kt-container-fixed">

    <div class="flex flex-wrap items-center justify-between gap-4 pb-6">
        <div>
            <h1 class="text-xl font-medium text-mono">🧾 Audit Trail</h1>
            <p class="text-sm text-gray-600">Who changed what — orders, ledger, and payments. Status changes are merged in per-order.</p>
        </div>
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="order" value="{{ $filters['order'] ?? '' }}" placeholder="Order # timeline (e.g. SH-20840)"
                   class="px-3 py-1.5 border border-gray-300 rounded text-sm" style="min-width:230px">
            <button class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm">Timeline</button>
        </form>
    </div>

    @if (!empty($notReady))
        <div class="p-4 rounded border border-amber-300 bg-amber-50 text-amber-800 text-sm">
            The audit table isn't created yet. Run <code>database/migrations/audit_log_jul2026.sql</code> on this database,
            then this page will start filling in. (Auditing safely no-ops until then — nothing is broken.)
        </div>
    @elseif (!is_null($timeline))
        {{-- ============ PER-ORDER TIMELINE MODE ============ --}}
        <div class="mb-4">
            <a href="{{ url('/audit-log') }}" class="text-sm text-blue-600">&larr; Back to full log</a>
        </div>
        @if (!$order)
            <div class="p-4 rounded border border-red-200 bg-red-50 text-red-700 text-sm">No production order found with number “{{ $orderNumber }}”.</div>
        @else
            <div class="mb-4 text-sm text-gray-700">Timeline for <strong>{{ $order->order_number }}</strong> — {{ count($timeline) }} events (audit + status history), newest first.</div>
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div style="overflow-x:auto;">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                            <th class="px-4 py-2 whitespace-nowrap">When</th><th class="px-4 py-2">Who</th>
                            <th class="px-4 py-2">Source</th><th class="px-4 py-2">Action</th>
                            <th class="px-4 py-2">Details</th>
                        </tr></thead>
                        <tbody>
                        @forelse ($timeline as $t)
                            <tr class="border-b border-gray-50 align-top">
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $t['at'] }}</td>
                                <td class="px-4 py-2">{{ $t['user'] }}</td>
                                <td class="px-4 py-2">{!! nf_source_badge($t['source']) !!}</td>
                                <td class="px-4 py-2"><span class="font-medium">{{ $t['kind'] }}</span><div class="text-xs text-gray-400">{{ $t['entity'] }}</div></td>
                                <td class="px-4 py-2 text-xs">{!! nf_render_changes($t['changes']) !!}@if($t['note'])<div class="text-gray-500 mt-1">{{ $t['note'] }}</div>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">No recorded events for this order yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @else
        {{-- ============ FILTERED LIST MODE ============ --}}
        <form method="GET" class="flex flex-wrap items-end gap-2 mb-4 p-3 rounded-lg border border-gray-200 bg-gray-50">
            <div><label class="block text-xs text-gray-500 mb-1">Entity</label>
                <select name="entity_type" class="px-2 py-1.5 border border-gray-300 rounded text-sm">
                    <option value="">All</option>
                    @foreach ($entityTypes as $et)<option value="{{ $et }}" @selected(($filters['entity_type']??'')===$et)>{{ $et }}</option>@endforeach
                </select></div>
            <div><label class="block text-xs text-gray-500 mb-1">Action</label>
                <select name="action" class="px-2 py-1.5 border border-gray-300 rounded text-sm">
                    <option value="">All</option>
                    @foreach ($actions as $ac)<option value="{{ $ac }}" @selected(($filters['action']??'')===$ac)>{{ $ac }}</option>@endforeach
                </select></div>
            <div><label class="block text-xs text-gray-500 mb-1">Source</label>
                <select name="source" class="px-2 py-1.5 border border-gray-300 rounded text-sm">
                    <option value="">All</option>
                    @foreach (['web','mobile','customer_app','system'] as $s)<option value="{{ $s }}" @selected(($filters['source']??'')===$s)>{{ $s }}</option>@endforeach
                </select></div>
            <div><label class="block text-xs text-gray-500 mb-1">From</label><input type="date" name="date_from" value="{{ $filters['date_from']??'' }}" class="px-2 py-1.5 border border-gray-300 rounded text-sm"></div>
            <div><label class="block text-xs text-gray-500 mb-1">To</label><input type="date" name="date_to" value="{{ $filters['date_to']??'' }}" class="px-2 py-1.5 border border-gray-300 rounded text-sm"></div>
            <button class="px-3 py-1.5 bg-blue-600 text-white rounded text-sm">Filter</button>
            <a href="{{ url('/audit-log') }}" class="px-3 py-1.5 border border-gray-300 rounded text-sm bg-white">Clear</a>
        </form>

        <div class="text-sm text-gray-500 mb-2">{{ number_format($rows->total()) }} events</div>
        <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div style="overflow-x:auto;">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                        <th class="px-4 py-2 whitespace-nowrap">When</th><th class="px-4 py-2">Who</th>
                        <th class="px-4 py-2">Source</th><th class="px-4 py-2">Action</th>
                        <th class="px-4 py-2">Entity</th><th class="px-4 py-2">Order</th>
                        <th class="px-4 py-2">Changes</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($rows as $r)
                        <tr class="border-b border-gray-50 align-top">
                            <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $r->at }}</td>
                            <td class="px-4 py-2">{{ $r->user_name ?: '—' }}</td>
                            <td class="px-4 py-2">{!! nf_source_badge($r->source) !!}</td>
                            <td class="px-4 py-2"><span class="font-medium">{{ $r->action }}</span></td>
                            <td class="px-4 py-2">{{ $r->entity_type }}<div class="text-xs text-gray-400">{{ $r->entity_label }}</div></td>
                            <td class="px-4 py-2">
                                @if ($r->related_order_id && isset($orderMap[$r->related_order_id]))
                                    <a class="text-blue-600" href="{{ url('/audit-log') }}?order={{ urlencode($orderMap[$r->related_order_id]) }}">{{ $orderMap[$r->related_order_id] }}</a>
                                @else — @endif
                            </td>
                            <td class="px-4 py-2 text-xs">{!! nf_render_changes($r->changes) !!}@if($r->note)<div class="text-gray-500 mt-1">{{ $r->note }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">No audit events match these filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $rows->links() }}</div>
    @endif

    <p class="text-xs text-gray-400 mt-6">Written by the AuditLogger on order/ledger/payment changes (one indexed insert per action; never on read paths). Source: <code>t_sys_audit_log</code>.</p>
</div>
@endsection
