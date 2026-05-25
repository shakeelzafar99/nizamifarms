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

    /* ── CS Manager view (May-2026) additions ─────────────────────
       The records table is now ALWAYS visible at the bottom of the
       page (renamed conceptually from "drill-down" to "records"),
       with its own search bar + sticky header + per-row actions.
       The KPI cards + per-slot table at the top now act as filter
       controls — clicking them narrows the records table below. */

    /* Records section uses a softer border + neutral colour so it
       doesn't look like a transient modal anymore. */
    .qp-records-panel {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
        padding: 16px 18px 18px;
        box-shadow: 0 1px 3px rgba(0,0,0,.04);
    }
    .qp-records-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
    }
    .qp-records-title {
        font-size: 15px; font-weight: 700; color: #111827; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .qp-records-subtitle {
        font-size: 12px; color: #6b7280; margin-top: 2px;
    }

    /* Active-filter chip — shown when the user clicked a KPI or
       per-slot number so they always know what's narrowing the list.
       Includes an inline × to revert to the default day-wide view. */
    .qp-filter-chip {
        display: inline-flex; align-items: center; gap: 8px;
        background: #fef3c7; color: #92400e; border: 1px solid #fcd34d;
        border-radius: 999px; padding: 4px 6px 4px 12px;
        font-size: 12px; font-weight: 600; max-width: 100%;
    }
    .qp-filter-chip-clear {
        background: #92400e; color: #fff; border: 0; cursor: pointer;
        width: 18px; height: 18px; border-radius: 50%;
        font-size: 12px; line-height: 1; display: flex; align-items: center; justify-content: center;
    }
    .qp-filter-chip-clear:hover { background: #78350f; }
    .qp-filter-chip-none { color: #6b7280; font-size: 12px; }

    /* Search row */
    .qp-search-row {
        display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px;
        padding: 10px 12px; background: #fafafa; border: 1px solid #e5e7eb;
        border-radius: 8px;
    }
    .qp-search-input {
        flex: 1; min-width: 180px;
        padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px;
        font-size: 13px; background: #fff; color: #1f2937;
        transition: border-color 0.15s, box-shadow 0.15s;
    }
    .qp-search-input:focus {
        outline: none; border-color: #d97706;
        box-shadow: 0 0 0 2px rgba(217,119,6,.15);
    }
    .qp-search-clear {
        padding: 7px 12px; background: #fff; border: 1px solid #d1d5db;
        border-radius: 6px; font-size: 12px; color: #6b7280; cursor: pointer;
        font-weight: 600;
    }
    .qp-search-clear:hover { background: #f3f4f6; color: #1f2937; }
    .qp-search-result-count {
        font-size: 12px; color: #6b7280; align-self: center;
        font-variant-numeric: tabular-nums;
    }

    /* Sticky table header — only meaningful when the table body is
       scrollable, so we cap the wrapper at 60vh and let the body scroll
       vertically while the header stays pinned. */
    .qp-records-scroll {
        overflow: auto; max-height: 60vh;
        border: 1px solid #e5e7eb; border-radius: 8px;
        -webkit-overflow-scrolling: touch;
    }
    .qp-records-scroll::-webkit-scrollbar { height: 10px; width: 10px; }
    .qp-records-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 5px; }
    .qp-records-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    .qp-records-scroll table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12px; }
    .qp-records-scroll thead th {
        position: sticky; top: 0; z-index: 3;
        background: #f9fafb; color: #6b7280;
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px;
        padding: 8px 10px; text-align: left; white-space: nowrap;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 1px 0 #e5e7eb; /* keeps the bottom border visible while sticky */
    }
    .qp-records-scroll tbody td {
        padding: 9px 10px; border-bottom: 1px solid #f3f4f6;
        vertical-align: top;
    }
    .qp-records-scroll tbody tr:hover td { background: #fafafa; }
    .qp-records-scroll tbody tr:last-child td { border-bottom: none; }

    /* Per-row action buttons (Timeline + WhatsApp). */
    .qp-row-actions {
        display: flex; gap: 6px; align-items: center;
    }
    .qp-row-action-btn {
        display: inline-flex; align-items: center; gap: 3px;
        padding: 4px 8px; border-radius: 5px; cursor: pointer;
        font-size: 11px; font-weight: 600; border: 1px solid;
        background: #fff; line-height: 1; white-space: nowrap;
        text-decoration: none; position: relative;
    }
    .qp-row-action-btn.qp-act-timeline {
        color: #92400e; border-color: #fbbf24; background: #fffbeb;
    }
    .qp-row-action-btn.qp-act-timeline:hover { background: #fef3c7; }
    .qp-row-action-btn.qp-act-wa {
        color: #166534; border-color: #86efac; background: #f0fdf4;
    }
    .qp-row-action-btn.qp-act-wa:hover { background: #dcfce7; }
    .qp-row-action-btn.qp-act-wa.is-disabled {
        color: #9ca3af; border-color: #e5e7eb; background: #f9fafb;
        cursor: not-allowed;
    }

    /* Unread WhatsApp badge over the action button. Red dot with
       count overlay so the CS manager spots which rows need
       immediate attention without reading numbers. */
    .qp-wa-unread-badge {
        position: absolute; top: -6px; right: -6px;
        background: #dc2626; color: #fff; border-radius: 999px;
        min-width: 16px; height: 16px; padding: 0 4px;
        font-size: 9px; font-weight: 700; line-height: 16px;
        text-align: center; box-shadow: 0 0 0 2px #fff;
    }

    /* ── ETA freshness + drift indicators (May-2026) ──────────────
       The CS manager looks at this table to decide what to tell a
       customer. These chips make it impossible to accidentally quote
       a stale ETA: every ETA cell carries a "calc'd Xm ago" subtitle
       AND a drift chip showing whether the customer was told the
       same number or something different. */
    .qp-eta-fresh {
        font-size: 10px; color: #6b7280; margin-top: 2px;
        font-variant-numeric: tabular-nums;
    }
    .qp-eta-fresh.is-recent { color: #16a34a; font-weight: 600; }
    .qp-eta-fresh.is-old    { color: #b45309; font-weight: 600; }
    .qp-eta-drift {
        display: inline-block; margin-top: 4px; padding: 2px 7px;
        border-radius: 999px; font-size: 10px; font-weight: 700;
        white-space: nowrap; border: 1px solid;
        font-variant-numeric: tabular-nums;
    }
    .qp-eta-drift.is-in_sync  { background: #d1fae5; color: #065f46; border-color: #10b981; }
    .qp-eta-drift.is-drifting { background: #fef3c7; color: #92400e; border-color: #f59e0b; }
    .qp-eta-drift.is-stale    { background: #fee2e2; color: #991b1b; border-color: #ef4444; }
    .qp-eta-drift.is-none     { background: #f3f4f6; color: #6b7280; border-color: #d1d5db; }

    /* Header-level drift summary pill — sits next to the active-filter
       chip when any row in the current view has a stale ETA. */
    .qp-drift-summary {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 999px;
        font-size: 12px; font-weight: 700; border: 1px solid;
        background: #fef2f2; color: #991b1b; border-color: #fca5a5;
    }
    .qp-drift-summary.is-warning {
        background: #fef3c7; color: #92400e; border-color: #f59e0b;
    }
    .qp-drift-summary.is-clean {
        background: #d1fae5; color: #065f46; border-color: #10b981;
    }

    /* Auto-refresh status pill in the page header. The "last refresh
       Xs ago" hint reassures the manager the screen isn't frozen. */
    .qp-refresh-hint {
        font-size: 11px; color: #6b7280; margin-left: 8px;
        font-variant-numeric: tabular-nums;
    }
    .qp-refresh-hint .dot {
        display: inline-block; width: 7px; height: 7px;
        background: #16a34a; border-radius: 50%; margin-right: 4px;
        vertical-align: 1px; animation: qp-pulse 2.4s ease-in-out infinite;
    }
    @keyframes qp-pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.35; transform: scale(0.85); }
    }
    .qp-refresh-hint.is-paused .dot { background: #f59e0b; animation: none; opacity: 0.5; }

    /* Timeline modal — Phase-2 (May-2026) on the orders page; ported
       here with a qp-tl prefix so both pages can render it without
       JS collisions. Visual language matches the orders modal. */
    .qp-tl-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.55); z-index: 10000;
    }
    .qp-tl-modal {
        display: none; position: fixed; top: 0; right: 0; bottom: 0;
        width: min(480px, 95vw); background: #fff;
        box-shadow: -8px 0 24px rgba(0,0,0,0.18);
        z-index: 10001; overflow: hidden; flex-direction: column;
    }
    .qp-tl-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 18px; border-bottom: 1px solid #e5e7eb; background: #fef3c7;
    }
    .qp-tl-head h2 { margin: 0; font-size: 17px; font-weight: 700; color: #1f2937; }
    .qp-tl-head .qp-tl-sub {
        margin: 3px 0 0; font-size: 12px; color: #6b7280;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .qp-tl-close {
        background: none; border: none; font-size: 24px;
        color: #6b7280; cursor: pointer; line-height: 1;
    }
    .qp-tl-body { flex: 1; overflow-y: auto; padding: 14px 18px; background: #fff; }
</style>
@endpush

@section('content')
<div class="qp-page">

    {{-- ── Page header ────────────────────────────────────────── --}}
    <div class="qp-page-head">
        <div class="qp-title-block">
            <h1>Qurbani Performance</h1>
            <p id="qpAsOf">Loading operational snapshot…
                <span id="qpRefreshHint" class="qp-refresh-hint" title="Auto-refreshes every 30 seconds — pauses while you're typing in search">
                    <span class="dot"></span>
                    <span id="qpRefreshHintText">Live</span>
                </span>
            </p>
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

    {{-- ── Per-slot rollup ──────────────────────────────────── --}}
    {{-- Phase 5b (May-2026) — split into Delivery vs Self Collection
         tabs. Slots differ between the two operational flows
         (e.g. self-collection uses morning windows, delivery uses
         afternoon windows), so mixing them in one table caused
         confusion. Each tab is its own table; clicking a count
         filters the records table below. --}}
    <div class="qp-section">
        <div class="qp-section-head">
            <h2 class="qp-section-title">🕒 Per-slot rollup</h2>
            <span class="qp-section-hint">Counts grouped by slot end time. Click a number to filter the records below.</span>
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

    {{-- ── Always-on records table (May-2026 CS Manager view) ────────
         The KPI cards + per-slot rollup above are now filter controls;
         this table is the primary work surface for the customer-service
         manager. Defaults to showing every Qurbani item in the active
         day filter. The user narrows by clicking a KPI / slot number
         or by typing in the search inputs. --}}
    <div class="qp-section">
        <div class="qp-records-panel">
            <div class="qp-records-head">
                <div>
                    <h3 class="qp-records-title" id="qpDrillTitle">📋 Records</h3>
                    <div class="qp-records-subtitle" id="qpDrillSubtitle">Loading…</div>
                </div>
                <div id="qpFilterChipSlot" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="qp-filter-chip-none" id="qpFilterChipNone">No filter applied · showing all in day scope</span>
                    <span id="qpDriftSummary"></span>
                </div>
            </div>
            <div class="qp-search-row">
                <input id="qpSrchName"  class="qp-search-input" type="search" placeholder="🔎 Customer name, order #, or product..." autocomplete="off" />
                <input id="qpSrchPhone" class="qp-search-input" type="search" placeholder="📞 Phone number (any format)" inputmode="tel" autocomplete="off" />
                <button id="qpSrchClear" class="qp-search-clear" type="button" title="Clear searches">Clear</button>
                <span id="qpSrchCount" class="qp-search-result-count"></span>
            </div>
            <div id="qpDrillBody">
                <div class="qp-loading"><span class="qp-spinner"></span><span>Loading records…</span></div>
            </div>
        </div>
    </div>

    <div id="qpToast" class="qp-toast"></div>

</div>

{{-- ── Timeline modal (May-2026 — ported from /qurbani/orders) ──────
     Slides in from the right when a row's Timeline button is clicked.
     Reuses the existing /qurbani/api/line-items/{id}/timeline endpoint.
     Prefixed qp-tl- so it never collides with the qoTimeline modal on
     the orders page (the two pages don't co-exist but we still want
     symbol-clean code). --}}
<div class="qp-tl-overlay" id="qpTlOverlay" onclick="qpTlClose()"></div>
<div class="qp-tl-modal" id="qpTlModal" role="dialog" aria-modal="true" aria-labelledby="qpTlTitle">
    <div class="qp-tl-head">
        <div style="min-width:0;">
            <h2 id="qpTlTitle">🕒 Timeline</h2>
            <p id="qpTlSub" class="qp-tl-sub">Loading…</p>
        </div>
        <button type="button" class="qp-tl-close" onclick="qpTlClose()" aria-label="Close timeline">&times;</button>
    </div>
    <div id="qpTlBody" class="qp-tl-body">
        <div style="text-align:center;padding:40px 0;color:#9ca3af;font-size:13px;">Loading…</div>
    </div>
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

    // May-2026 (CS Manager view) — the records table is now ALWAYS
    // visible and defaults to a no-metric view (i.e. every Qurbani
    // item in the current day filter). activeMetric == null means
    // "no narrowing filter applied — show everything in scope".
    // Clicking a KPI / per-slot number sets activeMetric (+ optional
    // slotEnd + bucket) and re-runs the records query.
    let activeMetric = null;
    let activeSlotEnd = null;
    let activeBucketState = null; // delivery / self_collection / null
    let activeLabel = null;       // pretty label cached for the filter chip

    // CS search inputs — May-2026 refactor: search is now CLIENT-SIDE
    // on the already-loaded result set. Each keystroke does a cheap
    // in-memory filter (no network round-trip, no race conditions,
    // no in-flight cancellation needed). Backend still accepts q +
    // phone params for /api/performance/drill callers but the live
    // UI does NOT send them.
    //
    // Why the change: when search was server-side, fast typing fired
    // overlapping fetches; whichever returned LAST (often the oldest
    // request) clobbered the displayed rows. The Clear button also
    // had to fight pending debounce timers. Client-side filter makes
    // both problems impossible.
    let _qpSrchTimer = null;
    const SRCH_DEBOUNCE_MS = 60;          // 60ms is enough to coalesce typing bursts; UI feels instant
    let _qpAllRows = [];                  // last server payload, unfiltered (source of truth for client filter)
    let _qpDriftFromServer = null;        // mirror of j.eta_drift; rendered when present
    let _qpServerTotalCount = 0;          // unfiltered count, used for "N of M" display

    // ── ETA-freshness auto-refresh (May-2026) ────────────────────
    // The dispatch team updates ETAs from the mobile app at any
    // moment — start dispatch, auto route recompute, manual delay
    // update. Without auto-refresh the CS manager can sit on a
    // 30-minute-old page and quote stale ETAs to customers.
    // We poll loadSummary() every 30s by default, but pause while
    // the manager is interacting with the records table (typing
    // in search, holding focus) so the table doesn't repaint and
    // wreck their reading position.
    const AUTO_REFRESH_MS = 30000;
    let _qpAutoTimer = null;
    let _qpLastTypeAt = 0;
    let _qpLastRefreshAt = Date.now();
    // While a search input has focus, hold refresh — same UX as
    // Gmail's "you have new messages" banner that doesn't reflow
    // your draft mid-keystroke.
    function _qpIsTypingNow() {
        const active = document.activeElement;
        if (!active) return false;
        if (active.id === 'qpSrchName' || active.id === 'qpSrchPhone') return true;
        // 6-second cool-down after last keystroke (covers the gap
        // between thinking and resuming typing).
        return (Date.now() - _qpLastTypeAt) < 6000;
    }
    function _qpUpdateRefreshHint(paused) {
        const hint = document.getElementById('qpRefreshHint');
        const txt  = document.getElementById('qpRefreshHintText');
        if (!hint || !txt) return;
        hint.classList.toggle('is-paused', !!paused);
        if (paused) {
            txt.textContent = 'Paused while you type';
        } else {
            const ageSec = Math.max(0, Math.round((Date.now() - _qpLastRefreshAt) / 1000));
            txt.textContent = 'Live · refreshed ' + (ageSec < 5 ? 'just now' : ageSec + 's ago');
        }
    }

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
            // Rebuild the As-of line but keep the live-hint pill intact.
            const asOfEl = $('#qpAsOf');
            asOfEl.childNodes[0].nodeValue = 'Operational snapshot · As of ' + asOf.toLocaleString() + ' ';
            _qpLastRefreshAt = Date.now();
            _qpUpdateRefreshHint(false);
            // May-2026 CS view — the records table is always live, so
            // every summary refresh also re-fires the current records
            // query to keep counts and rows in lock-step. When no
            // metric is active we pull the full day-scope set; when a
            // KPI / slot click is active we re-apply that filter.
            runDrill(activeMetric, activeSlotEnd, activeBucketState);
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

    // ── Records / filter narrowing (May-2026 CS Manager view) ─────
    // openDrill() used to lazy-create a drill panel. The panel is now
    // always-on, so this function just updates the active filter state
    // + chip + smooth-scrolls into view + reruns the query.
    async function openDrill(metric, label, subtitle, slotEndMinute, bucket) {
        activeMetric = metric;
        activeSlotEnd = slotEndMinute || null;
        activeBucketState = bucket || null;
        activeLabel = label || metric;
        renderFilterChip();
        // Visually mark which KPI card is currently filtering. Per-slot
        // numbers don't have a kpi card to highlight (they live in the
        // table) so we only repaint when no slotEnd was passed.
        document.querySelectorAll('.qp-kpi').forEach(c => {
            c.classList.toggle('is-active', c.dataset.metric === metric && !slotEndMinute);
        });
        await runDrill(metric, slotEndMinute, activeBucketState);
        // Smooth-scroll the records panel into view so the click feels
        // like it did something even when the table was already showing.
        document.querySelector('.qp-records-panel')?.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    // Build the active-filter chip in the records header. When no
    // metric is active we surface a neutral "showing all in day scope"
    // hint instead. Search inputs are independent of this chip — they
    // narrow the active list without changing the metric.
    function renderFilterChip() {
        const wrap = $('#qpFilterChipSlot');
        if (!activeMetric) {
            wrap.innerHTML = '<span class="qp-filter-chip-none" id="qpFilterChipNone">No filter applied · showing all in day scope</span>';
            return;
        }
        wrap.innerHTML = '<span class="qp-filter-chip" title="Click ✕ to clear and show all in day scope">'
            + '<strong>Filtered:</strong> ' + esc(activeLabel || activeMetric)
            + '<button class="qp-filter-chip-clear" type="button" onclick="window.qpClearFilter()" title="Clear filter">×</button>'
            + '</span>';
    }
    // Exposed globally so the chip's inline onclick can find it.
    window.qpClearFilter = function() {
        activeMetric = null;
        activeSlotEnd = null;
        activeBucketState = null;
        activeLabel = null;
        renderFilterChip();
        document.querySelectorAll('.qp-kpi').forEach(c => c.classList.remove('is-active'));
        runDrill(null, null, null);
    };

    // ── Backend fetch (May-2026 — no longer takes search params) ───
    // Only the metric/slot/bucket narrowing happens server-side. The
    // text/phone search is applied client-side in applyClientSearch().
    // Auto-refresh + KPI/slot clicks call this; the search inputs do
    // NOT. We also use an AbortController + request token so back-to-
    // back metric clicks never let a stale response paint the table.
    let _qpDrillReqId = 0;
    let _qpDrillAbort = null;
    async function runDrill(metric, slotEndMinute, bucket) {
        const myId = ++_qpDrillReqId;
        if (_qpDrillAbort) { try { _qpDrillAbort.abort(); } catch (_) {} }
        _qpDrillAbort = ('AbortController' in window) ? new AbortController() : null;

        const params = new URLSearchParams();
        if (metric) params.set('metric', metric);
        else        params.set('metric', 'total_items');
        if (currentDay)    params.set('day', currentDay);
        if (slotEndMinute) params.set('slot_end_minute', String(slotEndMinute));
        if (bucket)        params.set('delivery_type_bucket', bucket);
        try {
            const res = await fetch(ENDPOINTS.drill + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                signal: _qpDrillAbort ? _qpDrillAbort.signal : undefined,
            });
            if (myId !== _qpDrillReqId) return; // a newer request superseded us
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (myId !== _qpDrillReqId) return;
            if (!j.success) throw new Error(j.message || 'Failed to load records');
            _qpAllRows = Array.isArray(j.rows) ? j.rows : [];
            _qpServerTotalCount = j.count != null ? j.count : _qpAllRows.length;
            _qpDriftFromServer = j.eta_drift || null;
            applyClientSearch();
        } catch (e) {
            if (e && e.name === 'AbortError') return; // cancelled — silent
            if (myId !== _qpDrillReqId) return;
            $('#qpDrillBody').innerHTML = '<div class="qp-error">Failed to load: ' + esc(e.message) + '</div>';
        }
    }

    // ── Client-side search (May-2026) ─────────────────────────────
    // Filters _qpAllRows in memory. Mirrors the backend's matching
    // rules so a CS manager who types "Asghar" or "+92 321 …" sees
    // the same matches they would have seen server-side:
    //   - name  → case-insensitive substring over customer_name,
    //             order_number, product_name (joined into a single
    //             haystack so multi-word queries like "Asghar QUR" hit)
    //   - phone → digit-only normalisation, match on last-9 tail of
    //             whatever's typed against the last 9 digits of the
    //             stored phone. Falls back to substring match for
    //             shorter input.
    // Cheap: 500 rows × 2 string ops = sub-millisecond.
    function applyClientSearch() {
        const rawName  = ($('#qpSrchName')  ? $('#qpSrchName').value  : '').trim();
        const rawPhone = ($('#qpSrchPhone') ? $('#qpSrchPhone').value : '').trim();
        const qName   = rawName.toLowerCase();
        const qPhone  = rawPhone.replace(/[^0-9]/g, '');
        const phoneTail = qPhone.length >= 4 ? qPhone.slice(-9) : qPhone;

        let rows = _qpAllRows;
        if (qName) {
            rows = rows.filter(r => {
                const hay = (
                    (r.customer_name || '') + ' ' +
                    (r.order_number  || '') + ' ' +
                    (r.product_name  || '')
                ).toLowerCase();
                return hay.indexOf(qName) !== -1;
            });
        }
        if (phoneTail) {
            rows = rows.filter(r => {
                const phone = (r.customer_phone || '').replace(/[^0-9]/g, '');
                if (!phone) return false;
                return phone.endsWith(phoneTail) || phone.indexOf(phoneTail) !== -1;
            });
        }

        renderDrill(rows);
        renderDriftSummary(_qpDriftFromServer);

        // Subtitle + count display — surface "N of M" when filtering
        // is active so the manager knows how much they narrowed the
        // server-returned set.
        const total    = _qpServerTotalCount;
        const filtered = rows.length;
        const hasSearch = !!(qName || qPhone);
        const sub = $('#qpDrillSubtitle');
        const parts = [];
        if (activeMetric) parts.push(activeLabel || activeMetric);
        if (hasSearch)    parts.push((rawName ? '"' + rawName + '"' : '') + (rawPhone ? ' ☎ ' + rawPhone : ''));
        parts.push(
            (hasSearch && filtered !== total)
                ? (filtered + ' of ' + total + ' record' + (total === 1 ? '' : 's'))
                : (filtered + ' record' + (filtered === 1 ? '' : 's'))
        );
        sub.textContent = parts.filter(Boolean).join(' · ');
        $('#qpSrchCount').textContent = hasSearch
            ? (filtered + ' of ' + total + ' match' + (filtered === 1 ? '' : 'es'))
            : '';
    }

    // Drift summary pill (header). Three states:
    //   stale   = at least one row has drift > threshold → red
    //   drifting= some rows are drifting but within threshold → yellow
    //   clean   = every row with an OFD message is in_sync → green
    // We only render the pill when there's *something* to flag,
    // otherwise the header stays uncluttered.
    function renderDriftSummary(drift) {
        const slot = $('#qpDriftSummary');
        if (!slot) return;
        if (!drift) { slot.innerHTML = ''; return; }
        if (drift.stale_count > 0) {
            slot.innerHTML = '<span class="qp-drift-summary" title="ETA differs from the value WhatsApped to the customer by more than ' + drift.threshold_minutes + ' minutes — they have stale information.">'
                + '⚠️ ' + drift.stale_count + ' customer' + (drift.stale_count === 1 ? '' : 's') + ' have stale ETA</span>';
        } else if (drift.drifting_count > 0) {
            slot.innerHTML = '<span class="qp-drift-summary is-warning" title="ETA has shifted but is still within the ' + drift.threshold_minutes + '-min tolerance for an auto delay-update.">'
                + drift.drifting_count + ' drifting (within ' + drift.threshold_minutes + 'm)</span>';
        } else {
            slot.innerHTML = '';
        }
    }

    // ── ETA freshness helpers ─────────────────────────────────────
    // "Calc'd Nm ago" / "Just now" / "27m ago" — clipped to a sane
    // range. Drives the small subtitle under each ETA cell.
    function _qpFmtAge(min) {
        if (min == null) return null;
        if (min < 1)  return 'just now';
        if (min < 60) return min + 'm ago';
        const h = Math.floor(min / 60);
        const m = min % 60;
        return h + 'h' + (m > 0 ? ' ' + m + 'm' : '') + ' ago';
    }
    // Drift chip — only renders when we have something to say.
    // in_sync  → small green chip "✓ matches msg"
    // drifting → yellow chip "+12m vs msg"
    // stale    → red chip "+47m vs msg · update customer"
    // none     → renders nothing (no OFD message sent yet, or no ETA)
    function _qpRenderDriftChip(r) {
        const state = r.eta_drift_state || 'none';
        if (state === 'none') return '';
        const delta = r.eta_drift_minutes;
        if (delta == null) return '';
        const sign = delta >= 0 ? '+' : '';
        const label = state === 'in_sync'
            ? '✓ matches msg'
            : (state === 'stale' ? sign + delta + 'm · update customer' : sign + delta + 'm vs msg');
        return '<div class="qp-eta-drift is-' + state + '" title="' + esc(_qpDriftTooltip(r)) + '">' + esc(label) + '</div>';
    }
    function _qpDriftTooltip(r) {
        const parts = [];
        if (r.messaged_eta_at) parts.push('Last WhatsApp ETA sent: ' + r.messaged_eta_at);
        if (r.last_wa_sent_at) parts.push('Sent at: ' + r.last_wa_sent_at);
        if (r.last_wa_trigger) parts.push('Trigger: ' + r.last_wa_trigger);
        if (r.eta_drift_minutes != null) parts.push('Drift: ' + r.eta_drift_minutes + ' min');
        return parts.join(' · ');
    }

    function renderDrill(rows) {
        const body = $('#qpDrillBody');
        // Preserve scroll position across auto-refresh re-renders so
        // the CS manager doesn't lose their reading spot every 30s.
        const oldScroll = body.querySelector('.qp-records-scroll');
        const savedTop  = oldScroll ? oldScroll.scrollTop  : 0;
        const savedLeft = oldScroll ? oldScroll.scrollLeft : 0;
        if (!rows || rows.length === 0) {
            body.innerHTML = '<div class="qp-drill-empty">No records match the current filters.</div>';
            return;
        }
        let html = '<div class="qp-records-scroll"><table><thead><tr>'
            + '<th>Order</th>'
            + '<th>Customer</th>'
            + '<th>Item</th>'
            + '<th>Day · Slot end</th>'
            + '<th>Region</th>'
            + '<th>Type</th>'
            + '<th>Status</th>'
            + '<th>Slaughtered</th>'
            + '<th>OFD</th>'
            + '<th>ETA · Freshness</th>'
            + '<th>Delivered</th>'
            + '<th>Vs slot</th>'
            + '<th>Rider</th>'
            + '<th style="text-align:center;">Actions</th>'
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

            // Timeline button — always available; opens the modal.
            // WhatsApp button — opens /messages in a new tab pre-
            // focused on the customer's conversation. Disabled when
            // we don't have a phone or customer_id to deep-link with.
            const waPhone = r.wa_phone || r.customer_phone || '';
            const unread  = r.wa_unread || 0;
            const waEnabled = !!waPhone;
            let waBtn;
            if (waEnabled) {
                const waUrl = '/messages?focus_phone=' + encodeURIComponent(waPhone);
                waBtn = '<a class="qp-row-action-btn qp-act-wa" href="' + esc(waUrl) + '" target="_blank" rel="noopener" title="Open WhatsApp inbox for ' + esc(r.customer_name) + '">💬 WhatsApp'
                    + (unread > 0 ? '<span class="qp-wa-unread-badge" title="' + unread + ' unread message(s)">' + esc(unread) + '</span>' : '')
                    + '</a>';
            } else {
                waBtn = '<span class="qp-row-action-btn qp-act-wa is-disabled" title="No phone on file">💬 WhatsApp</span>';
            }
            const tlBtn = '<button type="button" class="qp-row-action-btn qp-act-timeline" data-li="' + r.line_item_id + '" title="Open order timeline">🕒 Timeline</button>';

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
                + '<td>'
                    + fmtTime(r.qurbani_estimated_delivery_at)
                    // ETA freshness subtitle — green when calc'd within 5
                    // min, amber when >15 min stale, neutral otherwise.
                    + (r.eta_age_minutes != null
                        ? '<div class="qp-eta-fresh ' + (r.eta_age_minutes <= 5 ? 'is-recent' : (r.eta_age_minutes >= 30 ? 'is-old' : '')) + '">calc\'d ' + esc(_qpFmtAge(r.eta_age_minutes)) + '</div>'
                        : '')
                    + _qpRenderDriftChip(r)
                + '</td>'
                + '<td>' + fmtTime(r.qurbani_delivered_at) + '</td>'
                + '<td>' + scHtml + '</td>'
                + '<td>' + esc(r.rider_name || '—') + '</td>'
                + '<td><div class="qp-row-actions">' + tlBtn + waBtn + '</div></td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        body.innerHTML = html;
        // Restore scroll so auto-refresh doesn't jerk the reading
        // position. Wrapped in rAF to wait for layout.
        const newScroll = body.querySelector('.qp-records-scroll');
        if (newScroll && (savedTop || savedLeft)) {
            requestAnimationFrame(() => {
                newScroll.scrollTop  = savedTop;
                newScroll.scrollLeft = savedLeft;
            });
        }
        // Delegate Timeline button clicks. We use delegation so we
        // don't pay an event-listener cost per row on big result sets
        // (~500 records is the server cap).
        body.querySelectorAll('button.qp-act-timeline').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.dataset.li, 10);
                if (id) qpTlOpen(id);
            });
        });
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
        // May-2026 — changing the day filter clears any KPI/slot
        // narrowing because the rows that were "delivered late on
        // Day 1" don't make sense as the user pivots to Day 2.
        // Search inputs are kept (the CS manager often hunts for a
        // single customer across days).
        if (activeMetric) {
            activeMetric = null;
            activeSlotEnd = null;
            activeBucketState = null;
            activeLabel = null;
            renderFilterChip();
        }
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

    // ── Search inputs (CS Manager) ─────────────────────────────
    // Client-side filter on the already-loaded set — no network
    // round-trip per keystroke, no race conditions, instant feel.
    // The 60ms debounce just coalesces typing bursts so very fast
    // typing doesn't trigger a render per character (~1.5x faster
    // than re-rendering on every keystroke for a 500-row table).
    function _qpQueueSearch() {
        clearTimeout(_qpSrchTimer);
        _qpSrchTimer = setTimeout(applyClientSearch, SRCH_DEBOUNCE_MS);
    }
    ['qpSrchName', 'qpSrchPhone'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', () => {
            _qpLastTypeAt = Date.now();
            _qpUpdateRefreshHint(true);
            _qpQueueSearch();
        });
    });
    $('#qpSrchClear').addEventListener('click', () => {
        // CRITICAL — cancel any pending debounced filter BEFORE we
        // clear the inputs. Otherwise a leftover timer can fire
        // milliseconds later and re-apply the old filter logic on
        // the now-empty inputs (harmless but causes a brief flash).
        clearTimeout(_qpSrchTimer);
        $('#qpSrchName').value  = '';
        $('#qpSrchPhone').value = '';
        // Reset typing cooldown so the auto-refresh hint stops
        // saying "Paused while you type" the moment the user clears.
        _qpLastTypeAt = 0;
        _qpUpdateRefreshHint(false);
        // Apply immediately — no debounce wait on the Clear path.
        applyClientSearch();
        // Drop focus from the cleared input so the cursor doesn't
        // sit in an empty box looking like it's still being typed.
        try { document.activeElement && document.activeElement.blur && document.activeElement.blur(); } catch (_) {}
    });

    // ── Timeline modal (ported from /qurbani/orders openTimeline) ──
    // Reuses the same /qurbani/api/line-items/{id}/timeline endpoint
    // the orders page calls. Prefixed qpTl* so symbols don't collide
    // with the orders-page version (the two pages don't co-exist in
    // the same DOM but we still keep the namespace clean).
    function qpTlClose() {
        $('#qpTlOverlay').style.display = 'none';
        $('#qpTlModal').style.display = 'none';
    }
    // Expose on window so the modal's inline onclick handlers can find
    // them without leaking the IIFE closure.
    window.qpTlClose = qpTlClose;

    async function qpTlOpen(lineItemId) {
        const overlay = $('#qpTlOverlay');
        const modal   = $('#qpTlModal');
        const sub     = $('#qpTlSub');
        const body    = $('#qpTlBody');
        overlay.style.display = 'block';
        modal.style.display = 'flex';
        sub.textContent = 'Loading…';
        body.innerHTML = '<div style="text-align:center;padding:40px 0;color:#9ca3af;font-size:13px;">Loading…</div>';
        try {
            const res = await fetch('/qurbani/api/line-items/' + lineItemId + '/timeline', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            });
            const d = await res.json();
            if (!d || !d.success) {
                body.innerHTML = '<div style="padding:30px;color:#dc2626;font-size:13px;text-align:center;">' + esc(d && d.message ? d.message : 'Failed to load timeline.') + '</div>';
                return;
            }
            qpTlRender(d);
        } catch (e) {
            body.innerHTML = '<div style="padding:30px;color:#dc2626;font-size:13px;text-align:center;">' + esc(e.message || String(e)) + '</div>';
        }
    }
    window.qpTlOpen = qpTlOpen; // exposed so the table delegation can call it

    function qpTlRender(d) {
        const sub  = $('#qpTlSub');
        const body = $('#qpTlBody');
        function fmtTimeFull(ts) {
            if (!ts) return '';
            try {
                const dt = new Date(String(ts).replace(' ', 'T'));
                return dt.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ts; }
        }
        const order = d.order || {};
        const li = d.line_item || {};
        const subParts = [];
        if (order.customer_name) subParts.push(order.customer_name);
        if (order.order_number)  subParts.push('#' + order.order_number);
        sub.textContent = subParts.join(' · ');

        let html = '';
        // Order tags row
        const itemBits = [];
        if (li.qurbani_day)  itemBits.push(esc(li.qurbani_day));
        if (li.qurbani_slot) itemBits.push(esc(li.qurbani_slot));
        if (li.qurbani_delivery_type) itemBits.push(esc(li.qurbani_delivery_type));
        if (li.qurbani_sub_region)    itemBits.push(esc(li.qurbani_sub_region));
        if (itemBits.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">';
            itemBits.forEach(t => {
                html += '<span style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:#374151;">' + t + '</span>';
            });
            html += '</div>';
        }
        // Delay alert
        if (d.delay_alert && d.delay_alert.active) {
            html += '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;">'
                + '<span style="font-size:18px;line-height:1;">⚠️</span>'
                + '<div style="flex:1;font-size:13px;color:#92400e;line-height:1.45;"><strong>Running late.</strong> ' + esc(d.delay_alert.reason) + '</div>'
                + '</div>';
        }
        // Rider + Dispatch
        if (d.rider || d.dispatch) {
            html += '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Rider &amp; Dispatch</div>';
            if (d.rider) {
                html += '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;"><strong>🛵 Rider:</strong> ' + esc(d.rider.name) + '</div>';
            } else {
                html += '<div style="font-size:13px;color:#9ca3af;margin-bottom:4px;font-style:italic;">No rider assigned yet.</div>';
            }
            if (d.dispatch) {
                let dispLine = '<strong>🚀 Dispatched:</strong> ' + esc(fmtTimeFull(d.dispatch.at));
                if (d.dispatch.by_name) dispLine += ' · by ' + esc(d.dispatch.by_name);
                html += '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;">' + dispLine + '</div>';
                if (d.dispatch.started_at) {
                    html += '<div style="font-size:13px;color:#0e7490;"><strong>🏁 Rider started:</strong> ' + esc(fmtTimeFull(d.dispatch.started_at)) + '</div>';
                }
            } else {
                html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">Not yet dispatched.</div>';
            }
            html += '</div>';
        }
        // Current ETA
        if (d.current_eta) {
            html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Current ETA</div>';
            html += '<div style="font-size:18px;font-weight:700;color:#1e3a8a;">⏱ ' + esc(fmtTimeFull(d.current_eta.at)) + '</div>';
            if (d.current_eta.note) {
                html += '<div style="font-size:11px;color:#1d4ed8;margin-top:4px;">' + esc(d.current_eta.note) + '</div>';
            }
            if (d.slot_compare && d.slot_compare.label) {
                const sc = d.slot_compare;
                const isWithin = sc.state === 'within';
                const isDelivered = !!(li && li.qurbani_item_status === 'delivered');
                const bg = isWithin ? '#d1fae5' : (isDelivered ? '#fee2e2' : '#fef3c7');
                const fg = isWithin ? '#065f46' : (isDelivered ? '#991b1b' : '#92400e');
                const bd = isWithin ? '#10b981' : (isDelivered ? '#ef4444' : '#f59e0b');
                html += '<div style="margin-top:8px;padding:6px 8px;background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';border-radius:6px;font-size:12px;font-weight:700;display:inline-block;">'
                    + esc(sc.label) + '</div>';
            }
            html += '</div>';
        }
        // Status events
        html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Status Events</div>';
        if (!d.events || d.events.length === 0) {
            html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">No status events yet.</div>';
        } else {
            d.events.forEach((ev, idx) => {
                const isLast = idx === d.events.length - 1;
                html += '<div style="display:flex;gap:10px;align-items:flex-start;position:relative;padding-bottom:' + (isLast ? '0' : '14px') + ';">';
                if (!isLast) {
                    html += '<div style="position:absolute;left:11px;top:22px;bottom:0;width:2px;background:' + esc(ev.color || '#e5e7eb') + ';opacity:0.35;"></div>';
                }
                html += '<div style="width:24px;height:24px;border-radius:50%;background:' + esc(ev.color || '#6b7280') + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;line-height:1;z-index:1;">' + esc(ev.icon || '•') + '</div>';
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-size:13px;font-weight:600;color:#1f2937;">' + esc(ev.label) + '</div>';
                const meta = [];
                if (ev.at) meta.push(fmtTimeFull(ev.at));
                if (ev.by) meta.push('by ' + ev.by);
                if (meta.length) {
                    html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' + esc(meta.join(' · ')) + '</div>';
                }
                html += '</div></div>';
            });
        }
        html += '</div>';
        // WhatsApp today
        const wa = d.whatsapp_today || {};
        html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">📱 WhatsApp · Today</div>';
        if (!wa.last_inbound && !wa.last_outbound) {
            html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">No WhatsApp activity logged today.</div>';
        } else {
            if (wa.last_outbound) {
                html += '<div style="font-size:13px;color:#166534;margin-bottom:4px;"><strong>→ Sent:</strong> ' + esc(fmtTimeFull(wa.last_outbound.at)) + (wa.last_outbound.template ? ' · ' + esc(wa.last_outbound.template) : '') + '</div>';
            }
            if (wa.last_inbound) {
                html += '<div style="font-size:13px;color:#1f2937;"><strong>← Reply:</strong> ' + esc(fmtTimeFull(wa.last_inbound.at)) + '</div>';
            }
        }
        // Quick deep-link to the full inbox for this customer.
        const waPhone = (d.order && d.order.customer_phone) || null;
        if (waPhone) {
            html += '<a href="/messages?focus_phone=' + encodeURIComponent(waPhone) + '" target="_blank" rel="noopener" '
                + 'style="display:inline-flex;align-items:center;gap:4px;margin-top:8px;padding:5px 10px;background:#16a34a;color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">💬 Open full inbox →</a>';
        }
        html += '</div>';

        body.innerHTML = html;
    }

    // First load.
    loadSummary();

    // Auto-refresh loop (May-2026) — keeps ETAs fresh without the
    // CS manager having to click Refresh. Pauses while the search
    // inputs are focused so the table doesn't repaint mid-keystroke.
    _qpAutoTimer = setInterval(() => {
        if (_qpIsTypingNow()) {
            _qpUpdateRefreshHint(true);
            return;
        }
        loadSummary();
    }, AUTO_REFRESH_MS);

    // Tick the "refreshed Xs ago" label every 5s so the manager can
    // see at a glance that the page isn't frozen.
    setInterval(() => {
        if (!_qpIsTypingNow()) _qpUpdateRefreshHint(false);
    }, 5000);
})();
</script>
@endpush
