@extends('layouts.app')

@section('title', 'Qurbani Performance')

@push('custom_css')
<style>
    /* Phase 5 (May-2026) — Qurbani Performance dashboard.
       Self-contained styles. Naming convention: qp-*.
       Visual language matches /qurbani/orders + /qurbani/invoices:
       white cards, 8-12px radius, soft shadows, amber accent,
       tabular numerals on counts, KeenThemes container width. */

    .qp-page { padding: 18px 22px 32px; max-width: 1400px; margin: 0 auto; }

    /* ── Page header ───────────────────────────────────────────── */
    .qp-page-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
    }
    .qp-page-head .qp-title-block h1 {
        font-size: 20px; font-weight: 600; color: #111827; margin: 0;
    }
    .qp-page-head .qp-title-block p {
        font-size: 13px; color: #6b7280; margin: 4px 0 0;
    }
    .qp-page-head .qp-toolbar {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .qp-day-select {
        padding: 7px 12px; border-radius: 6px; border: 1px solid #d1d5db;
        font-size: 13px; background: #fff; color: #111827; min-width: 130px;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .qp-day-select:focus {
        outline: none; border-color: #d97706;
        box-shadow: 0 0 0 2px rgba(217,119,6,.15);
    }
    .qp-day-select-label {
        font-size: 12px; font-weight: 600; color: #6b7280;
    }

    /* ── Day-state banner ──────────────────────────────────────── */
    .qp-day-banner {
        background: linear-gradient(135deg, #fff 0%, #fafafa 100%);
        border: 1px solid #e5e7eb; border-radius: 12px;
        padding: 14px 18px; margin-bottom: 18px;
        display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .qp-day-banner.is-active {
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border-color: #a7f3d0;
    }
    .qp-day-banner .qp-state-icon {
        width: 44px; height: 44px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px; flex-shrink: 0;
        background: #f3f4f6; color: #6b7280;
    }
    .qp-day-banner.is-active .qp-state-icon {
        background: #d1fae5; color: #065f46;
    }
    .qp-day-banner .qp-state-text { flex: 1; min-width: 200px; }
    .qp-day-banner .qp-state-label {
        font-size: 11px; font-weight: 700; color: #6b7280;
        text-transform: uppercase; letter-spacing: 0.5px;
    }
    .qp-day-banner .qp-state-value {
        font-size: 16px; font-weight: 700; color: #111827; margin-top: 2px;
    }
    .qp-day-banner.is-active .qp-state-value { color: #065f46; }
    .qp-day-banner .qp-state-since {
        font-size: 12px; color: #6b7280; margin-top: 2px;
    }
    .qp-day-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .qp-btn {
        padding: 7px 14px; border-radius: 6px; font-size: 13px; font-weight: 600;
        border: 1px solid; cursor: pointer; transition: all 0.15s; background: #fff;
        display: inline-flex; align-items: center; gap: 6px; line-height: 1.2;
    }
    .qp-btn:disabled { opacity: 0.45; cursor: not-allowed; }
    .qp-btn-primary { background: #10b981; color: #fff; border-color: #10b981; }
    .qp-btn-primary:hover:not(:disabled) { background: #059669; border-color: #059669; }
    .qp-btn-warn { background: #f59e0b; color: #fff; border-color: #f59e0b; }
    .qp-btn-warn:hover:not(:disabled) { background: #d97706; border-color: #d97706; }
    .qp-btn-ghost { background: #fff; color: #6b7280; border-color: #d1d5db; }
    .qp-btn-ghost:hover:not(:disabled) { background: #f9fafb; color: #374151; border-color: #9ca3af; }

    /* ── Section wrappers ──────────────────────────────────────── */
    .qp-section {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
        padding: 18px; margin-bottom: 18px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .qp-section-head {
        display: flex; align-items: baseline; justify-content: space-between;
        gap: 12px; margin-bottom: 14px; flex-wrap: wrap;
    }
    .qp-section-title {
        font-size: 14px; font-weight: 700; color: #111827; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .qp-section-hint {
        font-size: 12px; color: #9ca3af; font-weight: 500;
    }

    /* ── KPI grid ──────────────────────────────────────────────── */
    .qp-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 12px;
    }
    .qp-kpi {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
        padding: 14px 16px; cursor: pointer; transition: all 0.15s;
        position: relative; overflow: hidden;
    }
    .qp-kpi::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0;
        width: 3px; background: transparent; transition: background 0.15s;
    }
    .qp-kpi:hover {
        border-color: #d97706;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.10);
        transform: translateY(-1px);
    }
    .qp-kpi.is-active {
        border-color: #d97706; background: #fffbeb;
        box-shadow: 0 4px 12px rgba(217, 119, 6, 0.15);
    }
    .qp-kpi.is-active::before { background: #d97706; }
    .qp-kpi.is-disabled { opacity: 0.55; cursor: not-allowed; background: #f9fafb; }
    .qp-kpi.is-disabled:hover { border-color: #e5e7eb; box-shadow: none; transform: none; }
    .qp-kpi-label {
        font-size: 11px; font-weight: 700; color: #6b7280;
        text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px;
        display: flex; align-items: center; gap: 6px;
    }
    .qp-kpi-value {
        font-size: 30px; font-weight: 700; color: #111827; line-height: 1.1;
        font-variant-numeric: tabular-nums;
    }
    .qp-kpi-subline {
        font-size: 11px; color: #9ca3af; margin-top: 6px;
        display: flex; align-items: center; gap: 5px;
    }
    .qp-kpi.tone-success .qp-kpi-value { color: #047857; }
    .qp-kpi.tone-info    .qp-kpi-value { color: #1d4ed8; }
    .qp-kpi.tone-warn    .qp-kpi-value { color: #b45309; }
    .qp-kpi.tone-danger  .qp-kpi-value { color: #b91c1c; }
    .qp-kpi.tone-muted   .qp-kpi-value { color: #9ca3af; }
    .qp-kpi-flag {
        display: inline-block; padding: 1px 6px; border-radius: 4px;
        background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb;
        font-size: 9px; font-weight: 700; letter-spacing: 0.3px;
    }
    .qp-kpi-arrow {
        position: absolute; right: 12px; top: 14px;
        font-size: 11px; color: #d1d5db; transition: color 0.15s;
    }
    .qp-kpi:hover .qp-kpi-arrow { color: #d97706; }
    .qp-kpi.is-disabled .qp-kpi-arrow { display: none; }

    /* ── Per-slot subtabs ─────────────────────────────────────── */
    .qp-subtabs {
        display: flex; gap: 4px; margin-bottom: 14px;
        border-bottom: 2px solid #f3f4f6;
    }
    .qp-subtab {
        padding: 9px 14px; border: none; background: transparent;
        cursor: pointer; font-size: 13px; font-weight: 600; color: #6b7280;
        display: inline-flex; align-items: center; gap: 7px;
        border-bottom: 2px solid transparent; margin-bottom: -2px;
        transition: all 0.15s; border-radius: 6px 6px 0 0;
    }
    .qp-subtab:hover { color: #111827; background: #f9fafb; }
    .qp-subtab.is-active {
        color: #b45309; border-bottom-color: #b45309; background: #fffbeb;
    }
    .qp-subtab-icon { font-size: 14px; }
    .qp-subtab-count {
        background: #f3f4f6; color: #6b7280;
        padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 700;
        font-variant-numeric: tabular-nums;
    }
    .qp-subtab.is-active .qp-subtab-count {
        background: #fde68a; color: #92400e;
    }

    /* ── Per-slot table ───────────────────────────────────────── */
    .qp-slot-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .qp-slot-table th {
        text-align: left; padding: 9px 12px; background: #f9fafb;
        color: #6b7280; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.3px;
        border-bottom: 1px solid #e5e7eb;
    }
    .qp-slot-table th.num { text-align: right; }
    .qp-slot-table td {
        padding: 10px 12px; border-bottom: 1px solid #f3f4f6;
        font-variant-numeric: tabular-nums;
    }
    .qp-slot-table td.num { text-align: right; }
    .qp-slot-table tr:hover td { background: #fafafa; }
    .qp-slot-table tr:last-child td { border-bottom: none; }
    .qp-slot-table .slot-name { font-weight: 600; color: #111827; }
    .qp-slot-table .slot-end-time { color: #6b7280; font-size: 12px; }
    .qp-slot-table .qp-slot-zero { color: #d1d5db; }
    .qp-slot-table .qp-slot-late-cell { color: #b91c1c; font-weight: 700; }
    .qp-num-link {
        color: #1d4ed8; cursor: pointer;
        text-decoration: underline; text-decoration-style: dotted;
        text-underline-offset: 3px; padding: 2px 4px; border-radius: 4px;
        transition: background 0.12s;
    }
    .qp-num-link:hover { background: #eff6ff; }
    .qp-num-link.zero { color: #d1d5db; cursor: default; text-decoration: none; }
    .qp-num-link.zero:hover { background: transparent; }

    /* ── Drill-down panel ─────────────────────────────────────── */
    .qp-drill-wrap {
        margin-bottom: 18px;
    }
    .qp-drill-panel {
        background: #fff; border: 2px solid #d97706; border-radius: 10px;
        padding: 18px;
        box-shadow: 0 4px 16px rgba(217, 119, 6, 0.10);
    }
    .qp-drill-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; margin-bottom: 14px; flex-wrap: wrap;
    }
    .qp-drill-title {
        font-size: 15px; font-weight: 700; color: #111827; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .qp-drill-subtitle {
        font-size: 12px; color: #6b7280; margin-top: 2px;
    }
    .qp-drill-table {
        width: 100%; border-collapse: collapse; font-size: 12px;
    }
    .qp-drill-table th {
        text-align: left; padding: 8px 10px; background: #f9fafb;
        color: #6b7280; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.3px;
        border-bottom: 1px solid #e5e7eb; white-space: nowrap;
    }
    .qp-drill-table td {
        padding: 9px 10px; border-bottom: 1px solid #f3f4f6;
        vertical-align: top;
    }
    .qp-drill-table tr:hover td { background: #fafafa; }
    .qp-drill-table tr:last-child td { border-bottom: none; }
    .qp-drill-empty {
        padding: 32px; text-align: center; color: #9ca3af; font-size: 13px;
    }
    .qp-status-pill {
        display: inline-block; padding: 2px 7px; border-radius: 4px;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .qp-status-pill.s-delivered { background: #d1fae5; color: #065f46; }
    .qp-status-pill.s-out_for_delivery { background: #dbeafe; color: #1e40af; }
    .qp-status-pill.s-slaughtered { background: #fef3c7; color: #92400e; }
    .qp-status-pill.s-open, .qp-status-pill.s-cancelled { background: #f3f4f6; color: #6b7280; }
    .qp-slot-chip {
        display: inline-block; padding: 2px 7px; border-radius: 4px;
        font-size: 10px; font-weight: 700; white-space: nowrap;
    }
    .qp-slot-chip.within { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
    .qp-slot-chip.late   { background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; }
    .qp-slot-chip.missed { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
    .qp-order-link {
        color: #1d4ed8; text-decoration: none; font-weight: 700; font-family: monospace;
    }
    .qp-order-link:hover { text-decoration: underline; }

    /* ── Loading / error states ───────────────────────────────── */
    .qp-loading {
        padding: 32px; text-align: center; color: #6b7280; font-size: 13px;
        display: flex; align-items: center; justify-content: center; gap: 10px;
    }
    .qp-spinner {
        width: 16px; height: 16px; border: 2px solid #e5e7eb;
        border-top-color: #d97706; border-radius: 50%;
        animation: qp-spin 0.8s linear infinite;
    }
    @keyframes qp-spin { to { transform: rotate(360deg); } }
    .qp-error {
        padding: 12px 14px; background: #fef2f2; border: 1px solid #fecaca;
        color: #991b1b; border-radius: 8px; margin-bottom: 14px; font-size: 13px;
    }

    /* ── Toast ────────────────────────────────────────────────── */
    .qp-toast {
        position: fixed; top: 20px; right: 20px; z-index: 10000;
        padding: 12px 18px; border-radius: 8px; font-size: 13px; font-weight: 600;
        box-shadow: 0 4px 16px rgba(0,0,0,.18); display: none;
    }
    .qp-toast.success { background: #d1fae5; color: #065f46; }
    .qp-toast.error   { background: #fee2e2; color: #991b1b; }
    .qp-toast.info    { background: #dbeafe; color: #1e40af; }

    /* Scrollbar polish for the drill table on overflow. */
    .qp-drill-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .qp-drill-scroll::-webkit-scrollbar { height: 8px; }
    .qp-drill-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
    .qp-drill-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
</style>
@endpush

@section('content')
<div class="qp-page">

    {{-- ── Page header ────────────────────────────────────────── --}}
    <div class="qp-page-head">
        <div class="qp-title-block">
            <h1>Qurbani Performance</h1>
            <p id="qpAsOf">Loading operational snapshot…</p>
        </div>
        <div class="qp-toolbar">
            <span class="qp-day-select-label">Day filter</span>
            {{-- Phase 5 (May-2026) — default to the active operational
                 day if one is set, otherwise the first configured day.
                 Avoids loading the firehose ("All days") on first
                 render. The user can still widen back via the
                 dropdown. --}}
            <select id="qpDayFilter" class="qp-day-select" data-default="{{ $defaultDay }}">
                <option value="" {{ $defaultDay === '' ? 'selected' : '' }}>All days</option>
                @foreach($days as $d)
                    <option value="{{ $d }}" {{ $d === $defaultDay ? 'selected' : '' }}>{{ $d }}</option>
                @endforeach
            </select>
            <button id="qpBtnRefresh" class="qp-btn qp-btn-ghost" type="button" title="Refresh now">
                🔄 Refresh
            </button>
        </div>
    </div>

    {{-- ── Day-state banner ──────────────────────────────────── --}}
    <div class="qp-day-banner" id="qpDayBanner">
        <div class="qp-state-icon" id="qpStateIcon">⏸</div>
        <div class="qp-state-text">
            <div class="qp-state-label">Operational state</div>
            <div class="qp-state-value" id="qpStateValue">Inactive · Day 0</div>
            <div class="qp-state-since" id="qpStateSince">Activate the operational day to enable late + at-risk math.</div>
        </div>
        <div class="qp-day-actions">
            <select id="qpActivateDay" class="qp-day-select" title="Pick the operational day to activate" style="min-width: 110px;">
                <option value="1">Day 1</option>
                <option value="2">Day 2</option>
                <option value="3">Day 3</option>
                <option value="4">Day 4</option>
            </select>
            <button id="qpBtnActivate" class="qp-btn qp-btn-primary" type="button">▶ Activate Day</button>
            <button id="qpBtnClose"    class="qp-btn qp-btn-warn"    type="button">⏹ Close Day</button>
            <button id="qpBtnReset"    class="qp-btn qp-btn-ghost"   type="button" title="Reset state — for testing">↺ Reset</button>
        </div>
    </div>

    <div id="qpError" class="qp-error" style="display:none;"></div>

    {{-- ── Headline KPIs ─────────────────────────────────────── --}}
    <div class="qp-section">
        <div class="qp-section-head">
            <h2 class="qp-section-title">📊 Headline numbers</h2>
            <span class="qp-section-hint">Click any card to see the underlying records.</span>
        </div>
        <div class="qp-kpi-grid" id="qpKpiGrid">
            <div class="qp-loading" style="grid-column:1/-1;">
                <span class="qp-spinner"></span>
                <span>Loading KPIs…</span>
            </div>
        </div>
    </div>

    {{-- ── Drill-down panel ──────────────────────────────────── --}}
    <div id="qpDrillWrap" class="qp-drill-wrap" style="display:none;">
        <div class="qp-drill-panel">
            <div class="qp-drill-head">
                <div>
                    <h3 class="qp-drill-title" id="qpDrillTitle">📋 Drill-down</h3>
                    <div class="qp-drill-subtitle" id="qpDrillSubtitle"></div>
                </div>
                <button id="qpDrillClose" class="qp-btn qp-btn-ghost" type="button">✕ Close</button>
            </div>
            <div id="qpDrillBody"></div>
        </div>
    </div>

    {{-- ── Per-slot rollup ──────────────────────────────────── --}}
    {{-- Phase 5b (May-2026) — split into Delivery vs Self Collection
         tabs. Slots differ between the two operational flows
         (e.g. self-collection uses morning windows, delivery uses
         afternoon windows), so mixing them in one table caused
         confusion. Each tab is its own table; clicking a count
         drills into rows narrowed to that delivery-type bucket. --}}
    <div class="qp-section">
        <div class="qp-section-head">
            <h2 class="qp-section-title">🕒 Per-slot rollup</h2>
            <span class="qp-section-hint">Counts grouped by slot end time. Click a number to drill in.</span>
        </div>
        <div class="qp-subtabs">
            <button class="qp-subtab is-active" data-bucket="delivery" type="button">
                <span class="qp-subtab-icon">🚚</span>
                <span class="qp-subtab-label">Delivery</span>
                <span class="qp-subtab-count" id="qpSubCountDelivery">0</span>
            </button>
            <button class="qp-subtab" data-bucket="self_collection" type="button">
                <span class="qp-subtab-icon">🏪</span>
                <span class="qp-subtab-label">Self Collection</span>
                <span class="qp-subtab-count" id="qpSubCountSelf">0</span>
            </button>
        </div>
        <div id="qpPerSlotWrap">
            <div class="qp-loading">
                <span class="qp-spinner"></span>
                <span>Loading slot rollup…</span>
            </div>
        </div>
    </div>

    <div id="qpToast" class="qp-toast"></div>

</div>
@endsection

@push('custom_js')
<script>
(function() {
    'use strict';
    // Phase 5 (May-2026) — Qurbani Performance dashboard JS.

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const ENDPOINTS = {
        summary:  '/qurbani/api/performance/summary',
        drill:    '/qurbani/api/performance/drill',
        dayState: '/qurbani/api/performance/day-state',
    };

    // Initial day filter — server-side picks the default (active op
    // day or first configured day) and pre-selects the matching
    // <option>. Read it back so the first /summary call honours it.
    let currentDay = (document.getElementById('qpDayFilter')?.value) || '';
    let activeMetric = null;
    let activeSlotEnd = null;

    // ── Helpers ─────────────────────────────────────────────────
    const $ = (sel) => document.querySelector(sel);
    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const showError = (msg) => {
        const el = $('#qpError');
        if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
        el.style.display = 'block';
        el.textContent = msg;
    };
    const fmtTime = (iso) => {
        if (!iso) return '<span style="color:#d1d5db;">—</span>';
        try {
            const d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return esc(iso);
            return d.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
        } catch (e) { return esc(iso); }
    };
    const fmtDateTime = (iso) => {
        if (!iso) return '—';
        try {
            const d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return iso;
            return d.toLocaleString([], {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
        } catch (e) { return iso; }
    };
    const toast = (msg, kind) => {
        const t = $('#qpToast');
        t.className = 'qp-toast ' + (kind || 'info');
        t.textContent = msg;
        t.style.display = 'block';
        clearTimeout(window._qpToastTo);
        window._qpToastTo = setTimeout(() => { t.style.display = 'none'; }, 2800);
    };

    // ── Load summary ────────────────────────────────────────────
    async function loadSummary() {
        showError(null);
        const params = new URLSearchParams();
        if (currentDay) params.set('day', currentDay);
        try {
            const res = await fetch(ENDPOINTS.summary + (params.toString() ? '?' + params.toString() : ''), {
                headers: {'Accept': 'application/json'},
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (!j.success) throw new Error(j.message || 'Failed to load summary');
            renderDayState(j.day_state);
            renderKpis(j.kpis);
            renderPerSlot(j.per_slot, j.config);
            const asOf = j.as_of ? new Date(j.as_of) : new Date();
            $('#qpAsOf').textContent = 'Operational snapshot · As of ' + asOf.toLocaleString();
            // If a drill panel is open, refresh it too so it stays in sync.
            if (activeMetric) {
                runDrill(activeMetric, activeSlotEnd);
            }
        } catch (e) {
            showError('Failed to load performance summary: ' + e.message);
        }
    }

    // ── Render: day state ───────────────────────────────────────
    function renderDayState(s) {
        const banner = $('#qpDayBanner');
        const icon = $('#qpStateIcon');
        const value = $('#qpStateValue');
        const since = $('#qpStateSince');
        const isActive = s && s.active === 1;
        banner.classList.toggle('is-active', !!isActive);
        if (isActive) {
            icon.textContent = '✅';
            value.textContent = 'Active · Day ' + (s.current_day || '?');
            since.textContent = s.active_since
                ? ('Activated at ' + fmtDateTime(s.active_since) + ' · late + at-risk math is live.')
                : 'Late + at-risk math is live.';
        } else {
            icon.textContent = '⏸';
            value.textContent = 'Inactive · Day ' + ((s && s.current_day) || '0');
            since.textContent = 'Activate the operational day to enable late + at-risk math.';
        }
        if (s && s.current_day) {
            const sel = $('#qpActivateDay');
            if (sel.querySelector('option[value="' + s.current_day + '"]')) {
                sel.value = String(s.current_day);
            }
        }
    }

    // ── Render: KPI cards ───────────────────────────────────────
    function renderKpis(kpis) {
        const grid = $('#qpKpiGrid');
        grid.innerHTML = '';
        kpis.forEach(k => {
            const card = document.createElement('div');
            card.className = 'qp-kpi tone-' + (k.tone || 'neutral')
                + (k.inactive ? ' is-disabled' : '')
                + (activeMetric === k.id && activeSlotEnd === null ? ' is-active' : '');
            card.dataset.metric = k.id;
            const flag = k.inactive ? ' <span class="qp-kpi-flag">DAY OFF</span>' : '';
            card.innerHTML =
                '<div class="qp-kpi-arrow">›</div>' +
                '<div class="qp-kpi-label">' + esc(k.label) + flag + '</div>' +
                '<div class="qp-kpi-value">' + esc(k.value) + '</div>' +
                (k.subline ? '<div class="qp-kpi-subline">' + esc(k.subline) + '</div>' : '');
            if (k.drillable && !k.inactive) {
                card.addEventListener('click', () => openDrill(k.id, k.label, k.subline, null));
            }
            grid.appendChild(card);
        });
    }

    // Phase 5b (May-2026) — track the active per-slot tab. Backend
    // now returns {delivery: [...], self_collection: [...]} and the
    // user toggles between them. Stored at module scope so renders
    // outside the click handler (e.g. day refresh) repaint the
    // currently-selected tab.
    let perSlotData = { delivery: [], self_collection: [] };
    let activePerSlotBucket = 'delivery';

    // ── Render: per-slot rollup table ───────────────────────────
    function renderPerSlot(payload, cfg) {
        // Backwards-tolerant: accept either the new {delivery,
        // self_collection} object or a flat array (older API).
        if (Array.isArray(payload)) {
            perSlotData = { delivery: payload, self_collection: [] };
        } else {
            perSlotData = {
                delivery: payload?.delivery || [],
                self_collection: payload?.self_collection || [],
            };
        }
        // Update tab pill counts so the user can see which tab
        // actually has data without clicking it.
        $('#qpSubCountDelivery').textContent = perSlotData.delivery.length;
        $('#qpSubCountSelf').textContent = perSlotData.self_collection.length;
        renderPerSlotBucket(activePerSlotBucket);
    }

    function renderPerSlotBucket(bucket) {
        const wrap = $('#qpPerSlotWrap');
        const rows = perSlotData[bucket] || [];
        if (rows.length === 0) {
            const friendly = bucket === 'self_collection' ? 'self-collection' : 'delivery';
            wrap.innerHTML = '<div class="qp-drill-empty">No ' + friendly + ' slot data for this day.</div>';
            return;
        }
        // Columns walk the lifecycle left-to-right so the row reads
        // naturally: open → slaughtered → OFD → delivered, with Late
        // as the audit column at the end.
        let html = '<div class="qp-drill-scroll"><table class="qp-slot-table">'
            + '<thead><tr>'
            + '<th>Slot</th>'
            + '<th>End time</th>'
            + '<th class="num">Total</th>'
            + '<th class="num">Open</th>'
            + '<th class="num" title="Status = slaughtered. In normal flow this is also the &quot;awaiting dispatch&quot; bucket (once dispatched, status moves to OFD).">Slaughtered</th>'
            + '<th class="num">OFD</th>'
            + '<th class="num">Delivered</th>'
            + '<th class="num">Late</th>'
            + '</tr></thead><tbody>';
        const numCell = (val, metric, slotEnd) => {
            const n = Number(val) || 0;
            if (n === 0) {
                return '<td class="num"><span class="qp-num-link zero">' + esc(n) + '</span></td>';
            }
            return '<td class="num"><a class="qp-num-link" data-metric="' + metric + '" data-slotend="' + slotEnd + '">' + esc(n) + '</a></td>';
        };
        rows.forEach(r => {
            const lateCls = r.delivered_late > 0 ? 'qp-slot-late-cell' : '';
            html += '<tr>'
                + '<td><span class="slot-name">' + esc(r.slot || '—') + '</span></td>'
                + '<td><span class="slot-end-time">' + esc(r.slot_end_display || '—') + '</span></td>'
                + numCell(r.total, 'per_slot_total', r.slot_end_minute)
                + numCell(r.open, 'open', r.slot_end_minute)
                + numCell(r.slaughtered, 'slaughtered', r.slot_end_minute)
                + numCell(r.ofd, 'ofd', r.slot_end_minute)
                + numCell(r.delivered, 'delivered', r.slot_end_minute)
                + '<td class="num ' + lateCls + '">' + (r.delivered_late > 0
                    ? '<a class="qp-num-link" data-metric="delivered_late" data-slotend="' + r.slot_end_minute + '" style="color:inherit;">' + esc(r.delivered_late) + '</a>'
                    : '<span class="qp-num-link zero">0</span>') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
        const bucketTypeLabel = bucket === 'self_collection' ? 'Self Collection' : 'Delivery';
        wrap.querySelectorAll('a.qp-num-link').forEach(a => {
            a.addEventListener('click', (ev) => {
                ev.preventDefault();
                const metric = a.dataset.metric;
                const slotEnd = a.dataset.slotend;
                const row = a.closest('tr');
                const slotLabel = row?.children[0]?.innerText || '';
                const endLabel = row?.children[1]?.innerText || '';
                openDrill(
                    metric,
                    metricLabel(metric) + ' · ' + bucketTypeLabel + ' · ' + slotLabel,
                    'Slot ends at ' + endLabel,
                    slotEnd,
                    bucket
                );
            });
        });
    }

    function metricLabel(metric) {
        const labels = {
            total_items: 'All items',
            ofd: 'Out for delivery',
            delivered: 'Delivered',
            delivered_on_time: 'Delivered within slot',
            delivered_late: 'Delivered late',
            at_risk: 'At risk',
            self_collection_overdue: 'Self-collection overdue',
            dispatch_gap: 'Awaiting dispatch',
            slaughtered: 'Slaughtered',
            open: 'Open',
            per_slot_total: 'Slot total',
        };
        return labels[metric] || metric;
    }

    // ── Drill-down ──────────────────────────────────────────────
    let activeBucket = null; // tracks which per-slot tab triggered the drill (delivery / self_collection)
    async function openDrill(metric, label, subtitle, slotEndMinute, bucket) {
        activeMetric = metric;
        activeSlotEnd = slotEndMinute || null;
        activeBucket = bucket || null;
        $('#qpDrillTitle').innerHTML = '📋 ' + esc(label || metric);
        $('#qpDrillSubtitle').textContent = subtitle || '';
        $('#qpDrillBody').innerHTML = '<div class="qp-loading"><span class="qp-spinner"></span><span>Loading records…</span></div>';
        $('#qpDrillWrap').style.display = 'block';
        document.querySelectorAll('.qp-kpi').forEach(c => {
            c.classList.toggle('is-active', c.dataset.metric === metric && !slotEndMinute);
        });
        await runDrill(metric, slotEndMinute, activeBucket);
        $('#qpDrillWrap').scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    async function runDrill(metric, slotEndMinute, bucket) {
        const params = new URLSearchParams({ metric });
        if (currentDay) params.set('day', currentDay);
        if (slotEndMinute) params.set('slot_end_minute', String(slotEndMinute));
        if (bucket) params.set('delivery_type_bucket', bucket);
        try {
            const res = await fetch(ENDPOINTS.drill + '?' + params.toString(), {
                headers: {'Accept': 'application/json'},
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (!j.success) throw new Error(j.message || 'Failed to load drill-down');
            renderDrill(j.rows);
            const sub = $('#qpDrillSubtitle');
            const baseSub = sub.textContent || '';
            sub.textContent = (baseSub ? baseSub + ' · ' : '') + j.count + ' record' + (j.count === 1 ? '' : 's');
        } catch (e) {
            $('#qpDrillBody').innerHTML = '<div class="qp-error">Failed to load: ' + esc(e.message) + '</div>';
        }
    }

    function renderDrill(rows) {
        const body = $('#qpDrillBody');
        if (!rows || rows.length === 0) {
            body.innerHTML = '<div class="qp-drill-empty">No records match this metric.</div>';
            return;
        }
        let html = '<div class="qp-drill-scroll"><table class="qp-drill-table"><thead><tr>'
            + '<th>Order</th>'
            + '<th>Customer</th>'
            + '<th>Item</th>'
            + '<th>Day · Slot end</th>'
            + '<th>Region</th>'
            + '<th>Type</th>'
            + '<th>Status</th>'
            + '<th>Slaughtered</th>'
            + '<th>OFD</th>'
            + '<th>ETA</th>'
            + '<th>Delivered</th>'
            + '<th>Vs slot</th>'
            + '<th>Rider</th>'
            + '</tr></thead><tbody>';
        rows.forEach(r => {
            const sc = r.slot_compare;
            let scHtml = '<span style="color:#d1d5db;">—</span>';
            if (sc && sc.label) {
                const cls = sc.state === 'within' ? 'within'
                    : (r.qurbani_item_status === 'delivered' ? 'missed' : 'late');
                scHtml = '<span class="qp-slot-chip ' + cls + '">' + esc(sc.label) + '</span>';
            }
            const status = r.qurbani_item_status || 'open';
            const statusClass = 's-' + (status || 'open').toLowerCase().replace(/[^a-z_]/g, '');
            html += '<tr>'
                + '<td><a class="qp-order-link" href="/qurbani/invoices?customer=' + encodeURIComponent(r.order_number || '') + '" target="_blank">' + esc(r.order_number || '') + '</a></td>'
                + '<td>' + esc(r.customer_name || '') + (r.customer_phone ? '<div style="font-size:10px;color:#9ca3af;">' + esc(r.customer_phone) + '</div>' : '') + '</td>'
                + '<td>' + esc(r.product_name || '') + ' <span style="color:#9ca3af;">×' + esc(r.quantity) + '</span></td>'
                + '<td>' + esc(r.qurbani_day || '—') + (r.qurbani_slot_end_display ? '<div style="font-size:10px;color:#9ca3af;">ends ' + esc(r.qurbani_slot_end_display) + '</div>' : '') + '</td>'
                + '<td>' + esc([r.qurbani_region, r.qurbani_sub_region].filter(Boolean).join(' / ') || '—') + '</td>'
                + '<td>' + esc(r.qurbani_delivery_type || '—') + '</td>'
                + '<td><span class="qp-status-pill ' + statusClass + '">' + esc(status) + '</span></td>'
                + '<td>' + fmtTime(r.qurbani_slaughtered_at) + '</td>'
                + '<td>' + fmtTime(r.qurbani_out_for_delivery_at) + '</td>'
                + '<td>' + fmtTime(r.qurbani_estimated_delivery_at) + '</td>'
                + '<td>' + fmtTime(r.qurbani_delivered_at) + '</td>'
                + '<td>' + scHtml + '</td>'
                + '<td>' + esc(r.rider_name || '—') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
    }

    function closeDrill() {
        activeMetric = null;
        activeSlotEnd = null;
        $('#qpDrillWrap').style.display = 'none';
        document.querySelectorAll('.qp-kpi').forEach(c => c.classList.remove('is-active'));
    }

    // ── Day-state actions ──────────────────────────────────────
    async function postDayState(action, day) {
        const btnIds = ['qpBtnActivate', 'qpBtnClose', 'qpBtnReset'];
        btnIds.forEach(id => { const b = $('#' + id); if (b) b.disabled = true; });
        try {
            const body = new FormData();
            body.append('action', action);
            if (day) body.append('day', String(day));
            const res = await fetch(ENDPOINTS.dayState, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body,
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.message || 'Action failed');
            renderDayState(j.day_state);
            if (action === 'activate') toast('Day activated · KPIs are now live.', 'success');
            else if (action === 'close') toast('Day closed · advanced to Day ' + (j.day_state.current_day || '?'), 'info');
            else toast('Day state reset.', 'info');
            await loadSummary();
        } catch (e) {
            showError('Failed to update day state: ' + e.message);
            toast('Failed: ' + e.message, 'error');
        } finally {
            btnIds.forEach(id => { const b = $('#' + id); if (b) b.disabled = false; });
        }
    }

    // ── Wire up ────────────────────────────────────────────────
    $('#qpDayFilter').addEventListener('change', (e) => {
        currentDay = e.target.value;
        if (activeMetric) closeDrill();
        loadSummary();
    });
    $('#qpBtnRefresh').addEventListener('click', loadSummary);
    $('#qpBtnActivate').addEventListener('click', () => {
        const day = parseInt($('#qpActivateDay').value, 10) || 1;
        postDayState('activate', day);
    });
    $('#qpBtnClose').addEventListener('click', () => {
        if (confirm('Close the current operational day? This will turn off live late/at-risk math and advance the day counter.')) {
            postDayState('close');
        }
    });
    $('#qpBtnReset').addEventListener('click', () => {
        if (confirm('Reset the operational day state? Use this only for testing.')) {
            postDayState('reset');
        }
    });
    $('#qpDrillClose').addEventListener('click', closeDrill);

    // Phase 5b — per-slot subtab toggle (Delivery vs Self
    // Collection). Switching tabs only repaints the table; the
    // underlying perSlotData was already returned by the summary
    // endpoint, so no re-fetch is needed.
    document.querySelectorAll('.qp-subtab').forEach(btn => {
        btn.addEventListener('click', () => {
            const bucket = btn.dataset.bucket;
            if (!bucket || bucket === activePerSlotBucket) return;
            activePerSlotBucket = bucket;
            document.querySelectorAll('.qp-subtab').forEach(b => {
                b.classList.toggle('is-active', b.dataset.bucket === bucket);
            });
            renderPerSlotBucket(bucket);
        });
    });

    // First load.
    loadSummary();
})();
</script>
@endpush
