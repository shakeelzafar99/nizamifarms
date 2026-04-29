@extends('layouts.app')

@section('title', 'Qurbani Analytics — Sandbox')

@push('demo1_css')
<style>
    .qa-card        { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 20px; }
    .qa-card h4     { font-size:13px; font-weight:600; color:#6b7280; margin-bottom:8px; }
    .qa-card .big   { font-size:28px; font-weight:700; color:#111827; line-height:1; }
    .qa-card .sub   { font-size:12px; color:#9ca3af; margin-top:6px; }
    .qa-grid        { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:14px; }
    .qa-bar         { display:flex; height:28px; border-radius:6px; overflow:hidden; background:#f3f4f6; }
    .qa-bar > span  { display:block; height:100%; }
    .qa-table th, .qa-table td { padding:8px 12px; font-size:13px; text-align:left; }
    .qa-table th    { background:#f9fafb; color:#6b7280; font-weight:600; }
    .qa-table tr    { border-top:1px solid #f3f4f6; }
    .qa-pill        { font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; background:#fef3c7; color:#92400e; }
</style>
@endpush

@section('content')
<div class="kt-container-fixed py-6 space-y-6">

    {{-- Header --}}
    <div class="flex items-start justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="/sandbox" class="text-xs text-gray-500 hover:text-gray-900">← Sandbox</a>
                <span class="qa-pill">prototype</span>
            </div>
            <h1 class="text-xl font-semibold text-gray-900 mt-1">Qurbani analytics</h1>
            <p class="text-sm text-gray-500">
                Live data via <code>analytics-sandbox/queries/qurbani-cohorts.example.sql</code> ·
                logged in as <strong>{{ $user?->name }}</strong> ·
                {{ $currentYear }}
            </p>
        </div>
        <a href="https://nizamifarms.vercel.app/qurbani" target="_blank"
           class="text-xs text-amber-700 underline">View Vercel reference ↗</a>
    </div>

    {{-- KPI cards --}}
    <div class="qa-grid">
        <div class="qa-card">
            <h4>Qurbani {{ $currentYear }} new customers</h4>
            <div class="big">{{ $cards['newCustomers2025'] !== null ? number_format($cards['newCustomers2025']) : '—' }}</div>
            <div class="sub">First-time Nizami buyers whose first non-cancelled order was a Qurbani SKU this year.</div>
        </div>
        <div class="qa-card">
            <h4>Qurbani {{ $currentYear }} cohort size</h4>
            <div class="big">{{ $cards['totalCohort2025'] !== null ? number_format($cards['totalCohort2025']) : '—' }}</div>
            <div class="sub">All distinct customers who placed a non-cancelled Qurbani order this year.</div>
        </div>
        <div class="qa-card">
            <h4>New-to-Qurbani ratio</h4>
            <div class="big">
                @php
                    $r = ($cards['totalCohort2025'] ?? 0) > 0
                        ? round(100 * (int)($cards['newCustomers2025'] ?? 0) / (int)$cards['totalCohort2025'], 1)
                        : null;
                @endphp
                {{ $r !== null ? $r . '%' : '—' }}
            </div>
            <div class="sub">Share of this year's Qurbani cohort that's brand new to Nizami.</div>
        </div>
    </div>

    {{-- Cohort breakdown table --}}
    <div class="qa-card">
        <h4>Qurbani customer mix by year</h4>
        <table class="w-full qa-table mt-2">
            <thead>
                <tr>
                    <th>Year</th>
                    <th>Cohort size</th>
                    <th>New</th>
                    <th>Returning</th>
                    <th class="w-1/3">New ↔ Returning</th>
                </tr>
            </thead>
            <tbody>
                @forelse($cohorts as $row)
                    @php
                        $size = max(1, (int)($row->cohort_size ?? 0));
                        $newPct = round(100 * (int)($row->new_customers ?? 0) / $size, 1);
                        $retPct = 100 - $newPct;
                    @endphp
                    <tr>
                        <td>{{ $row->year }}</td>
                        <td>{{ number_format($row->cohort_size) }}</td>
                        <td>{{ number_format($row->new_customers) }}</td>
                        <td>{{ number_format($row->returning_customers) }}</td>
                        <td>
                            <div class="qa-bar">
                                <span style="width: {{ $newPct }}%; background:#f59e0b;" title="New {{ $newPct }}%"></span>
                                <span style="width: {{ $retPct }}%; background:#9ca3af;" title="Returning {{ $retPct }}%"></span>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-gray-500 py-6">
                        No rows. The query in <code>queries/qurbani-cohorts.example.sql</code> probably needs its
                        <code>TODO: confirm</code> columns adjusted to the real schema.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Hand-off scaffolding --}}
    <div class="qa-card border-amber-200 bg-amber-50">
        <h4 class="text-amber-900">What's missing (build these next)</h4>
        <ul class="list-disc ml-5 text-sm text-amber-900 space-y-1">
            <li>30 / 90 / 180 / 365 day post-Eid conversion funnel for the {{ $currentYear }} cohort</li>
            <li>Loyalty ladder (1 / 2 / 3 / 4+ Qurbanis per customer)</li>
            <li>Pre-Eid booking curve — daily bookings 60 days before Eid, current vs prior year</li>
            <li>AOV by product type (cow hissa / goat / charity / Rajanpur)</li>
        </ul>
        <p class="text-xs text-amber-800 mt-3">
            For each new card: add a <code>queries/&lt;page&gt;-&lt;metric&gt;.sql</code> file,
            wire it through the controller, render it here, then check off the corresponding box in
            <code>handoffs/HANDOFF-qurbani.md</code>.
        </p>
    </div>

</div>
@endsection
