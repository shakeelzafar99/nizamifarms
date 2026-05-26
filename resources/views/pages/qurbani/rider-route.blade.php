@extends('layouts.app')

@section('title', 'Qurbani Rider Route')

@push('custom_css')
<style>
/* =====================================================================
   Qurbani Rider Route — Phase 1 (May-2026) read-only web mirror of the
   mobile QurbaniRiderRouteScreen. Naming convention: qrr-*.
   ===================================================================== */
.qrr-page { padding: 18px 22px 32px; max-width: 1400px; margin: 0 auto; }

/* Top breadcrumb / back link */
.qrr-back {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; color: #6B7280; text-decoration: none;
    margin-bottom: 10px;
}
.qrr-back:hover { color: #1F2937; text-decoration: underline; }

/* ── Read-only Phase-1 banner ───────────────────────────────────── */
.qrr-readonly-banner {
    background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 10px;
    padding: 10px 14px; margin-bottom: 14px;
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 12px; color: #92400E;
}

/* ── Header card with rider info, GPS, dispatch metadata ────────── */
.qrr-header {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 16px 18px; margin-bottom: 14px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
}
.qrr-header-top {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 12px; flex-wrap: wrap; margin-bottom: 12px;
}
.qrr-header-title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.qrr-header-title h1 { font-size: 22px; font-weight: 700; color: #111827; margin: 0; }
.qrr-header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.qrr-btn {
    padding: 7px 14px; border-radius: 6px; border: 1px solid #d1d5db;
    background: #fff; color: #1F2937; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s;
}
.qrr-btn:hover { background: #F9FAFB; border-color: #9CA3AF; }
.qrr-btn:disabled { opacity: .5; cursor: not-allowed; }
.qrr-btn.qrr-btn-primary { background: #D97706; border-color: #D97706; color: #fff; }
.qrr-btn.qrr-btn-primary:hover { background: #B45309; border-color: #B45309; }

/* GPS pill — match qr-gps from /qurbani/riders for visual consistency */
.qrr-gps { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1; }
.qrr-gps.qrr-gps-live   { background: #D1FAE5; color: #065F46; }
.qrr-gps.qrr-gps-recent { background: #FEF3C7; color: #92400E; }
.qrr-gps.qrr-gps-stale  { background: #FEE2E2; color: #991B1B; }
.qrr-gps.qrr-gps-none   { background: #F3F4F6; color: #6B7280; }

/* Dispatch metadata grid (when/by/started/live-tracking) */
.qrr-meta-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px 16px;
}
.qrr-meta-item { font-size: 12px; }
.qrr-meta-item .qrr-meta-label { color: #6B7280; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; font-size: 10px; margin-bottom: 2px; }
.qrr-meta-item .qrr-meta-value { color: #111827; font-weight: 600; font-size: 13px; }
.qrr-meta-item .qrr-meta-value.qrr-meta-active { color: #065F46; }
.qrr-meta-item .qrr-meta-value.qrr-meta-muted  { color: #9CA3AF; font-weight: 500; font-style: italic; }

/* Live-tracking pill — passive indicator on web (no toggle in Phase 1) */
.qrr-live-pill {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 999px; background: #DCFCE7; color: #166534;
    font-size: 11px; font-weight: 700;
}
.qrr-live-pill.qrr-live-off { background: #F3F4F6; color: #6B7280; }

/* Lock indicator */
.qrr-lock {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px;
    border-radius: 4px; background: #FEE2E2; color: #991B1B; font-size: 11px;
    font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}

/* Passive-recompute toast (Phase A3) */
.qrr-passive-banner {
    background: #EFF6FF; border: 1px solid #93C5FD; color: #1E40AF;
    padding: 10px 14px; border-radius: 8px; margin-bottom: 12px;
    font-size: 12px; display: flex; align-items: center; gap: 8px;
}

/* ── Section headers per dispatch batch / pending / delivered ───── */
.qrr-section {
    margin-bottom: 18px;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    box-shadow: 0 1px 2px rgba(0,0,0,.03); overflow: hidden;
}
.qrr-section-head {
    padding: 12px 16px;
    display: flex; justify-content: space-between; align-items: center;
    gap: 10px; flex-wrap: wrap;
}
/* "Out for Delivery" active batch — strong amber band so the manager's
   eye lands on the in-flight batch first. */
.qrr-section-head.qrr-sh-active   { background: linear-gradient(90deg, #B45309 0%, #D97706 100%); color: #fff; }
.qrr-section-head.qrr-sh-pending  { background: linear-gradient(90deg, #1E40AF 0%, #2563EB 100%); color: #fff; }
.qrr-section-head.qrr-sh-delivered{ background: linear-gradient(90deg, #064E3B 0%, #047857 100%); color: #fff; }
.qrr-section-head h2 { font-size: 14px; font-weight: 700; margin: 0; text-transform: uppercase; letter-spacing: .5px; }
.qrr-section-head .qrr-section-meta { font-size: 12px; opacity: .92; }
.qrr-section-body { padding: 4px 0; }

/* ── Bundle row ──────────────────────────────────────────────────── */
.qrr-bundle {
    padding: 14px 16px; border-bottom: 1px solid #F3F4F6;
    display: grid; grid-template-columns: 36px 1fr; gap: 14px; align-items: start;
}
.qrr-bundle:last-child { border-bottom: 0; }
.qrr-bundle.qrr-bundle-delivered { background: #F0FDF4; }
.qrr-priority {
    width: 36px; height: 36px; border-radius: 50%;
    background: #1F2937; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700;
}
.qrr-priority.qrr-priority-delivered { background: #047857; }
.qrr-priority.qrr-priority-none { background: #9CA3AF; }

.qrr-bundle-main { display: flex; flex-direction: column; gap: 6px; }
.qrr-bundle-row1 { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
.qrr-bundle-order { font-size: 12px; color: #6B7280; font-weight: 600; }
.qrr-bundle-customer { font-size: 15px; color: #111827; font-weight: 700; }

.qrr-bundle-row2 { display: flex; gap: 6px; flex-wrap: wrap; font-size: 12px; align-items: center; }
.qrr-chip {
    display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px;
    border-radius: 999px; font-weight: 600; line-height: 1.4;
}
.qrr-chip-slot      { background: #EEF2FF; color: #4338CA; }
.qrr-chip-eta       { background: #FEF3C7; color: #92400E; }
.qrr-chip-eta-set   { background: #DBEAFE; color: #1E40AF; }
.qrr-chip-eta-late  { background: #FEE2E2; color: #991B1B; }
.qrr-chip-delivered { background: #D1FAE5; color: #065F46; }
.qrr-chip-deltype   { background: #F3F4F6; color: #374151; }
.qrr-chip-region    { background: #F5F3FF; color: #5B21B6; }
.qrr-chip-bundle    { background: #FDF2F8; color: #9D174D; }
.qrr-chip-verified  { background: #D1FAE5; color: #065F46; cursor: pointer; }
.qrr-chip-verified:hover { background: #A7F3D0; }
.qrr-chip-unverified { background: #FEE2E2; color: #991B1B; }

/* Slot-compare (within slot / late / etc.) */
.qrr-chip-slot-on-time { background: #D1FAE5; color: #065F46; }
.qrr-chip-slot-late    { background: #FEE2E2; color: #991B1B; }
.qrr-chip-slot-grace   { background: #FEF3C7; color: #92400E; }

.qrr-bundle-addr {
    font-size: 13px; color: #1F2937; line-height: 1.4; margin-top: 2px;
}
.qrr-bundle-addr a { color: #2563EB; text-decoration: none; }
.qrr-bundle-addr a:hover { text-decoration: underline; }
.qrr-bundle-phone {
    font-size: 12px; color: #6B7280;
}
.qrr-bundle-phone a { color: #6B7280; text-decoration: none; }
.qrr-bundle-phone a:hover { color: #1F2937; text-decoration: underline; }

/* Items list inside the bundle (one row per line item) */
.qrr-items {
    margin-top: 6px;
    background: #FAFAFA; border: 1px solid #F3F4F6; border-radius: 8px;
    padding: 6px 10px;
}
.qrr-item-row {
    display: flex; justify-content: space-between; gap: 10px; padding: 3px 0;
    font-size: 12px; color: #374151; border-bottom: 1px dashed #E5E7EB;
}
.qrr-item-row:last-child { border-bottom: 0; }
.qrr-item-row .qrr-item-name { font-weight: 600; color: #1F2937; }
.qrr-item-row .qrr-item-meta { color: #6B7280; }
.qrr-item-row .qrr-item-status {
    font-size: 10px; padding: 1px 6px; border-radius: 4px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .3px;
}
.qrr-item-status.qrr-is-delivered    { background: #D1FAE5; color: #065F46; }
.qrr-item-status.qrr-is-ofd          { background: #FEF3C7; color: #92400E; }
.qrr-item-status.qrr-is-slaughtered  { background: #DBEAFE; color: #1E40AF; }
.qrr-item-status.qrr-is-pending      { background: #F3F4F6; color: #6B7280; }

/* Missing-coords warning */
.qrr-warn {
    margin-top: 6px; padding: 6px 10px;
    background: #FEF2F2; border: 1px solid #FECACA; border-radius: 6px;
    font-size: 11px; color: #991B1B;
}

/* ── Map modal ───────────────────────────────────────────────────── */
.qrr-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.5);
    z-index: 1050; display: none;
}
.qrr-modal {
    position: fixed; inset: 4% 4%; background: #fff; border-radius: 12px;
    z-index: 1051; display: none; flex-direction: column;
    box-shadow: 0 20px 40px rgba(0,0,0,.25); overflow: hidden;
}
.qrr-modal-head {
    padding: 12px 16px; border-bottom: 1px solid #E5E7EB;
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
}
.qrr-modal-head h3 { font-size: 16px; font-weight: 700; color: #111827; margin: 0; }
.qrr-modal-close {
    background: transparent; border: 0; color: #6B7280; cursor: pointer;
    font-size: 22px; line-height: 1; padding: 4px 8px; border-radius: 4px;
}
.qrr-modal-close:hover { background: #F3F4F6; color: #111827; }
.qrr-modal-body { flex: 1; position: relative; }
#qrrMapCanvas { width: 100%; height: 100%; }
.qrr-modal-legend {
    position: absolute; top: 12px; left: 12px;
    background: rgba(255,255,255,.95); border-radius: 8px; padding: 8px 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,.15); font-size: 11px;
    display: flex; flex-direction: column; gap: 4px; z-index: 5;
}
.qrr-legend-row { display: flex; align-items: center; gap: 6px; }
.qrr-legend-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff; }

.qrr-empty {
    text-align: center; padding: 60px 20px; color: #6B7280; font-size: 14px;
    background: #fff; border: 1px dashed #d1d5db; border-radius: 12px;
}
.qrr-loading { text-align: center; padding: 40px; color: #9CA3AF; font-size: 13px; }
.qrr-error {
    background: #FEF2F2; border: 1px solid #FCA5A5; color: #991B1B;
    padding: 12px 16px; border-radius: 10px; font-size: 13px;
}

.qrr-updated-at { font-size: 11px; color: #9CA3AF; }
</style>
@endpush

@section('content')
<div class="qrr-page">
    <a href="{{ url('/qurbani/riders') }}" class="qrr-back">← Back to Riders</a>

    <div class="qrr-readonly-banner">
        <span>ℹ️</span>
        <div>Read-only view (Phase 1). Dispatch, Cancel, Auto Route, Edit Route, Live Tracking are only on the mobile app.</div>
    </div>

    <div class="qrr-header" id="qrrHeader">
        <div class="qrr-header-top">
            <div class="qrr-header-title">
                <h1 id="qrrRiderName">Loading…</h1>
                <span id="qrrGpsPillSlot"></span>
                <span id="qrrLockSlot"></span>
                <span id="qrrLiveSlot"></span>
            </div>
            <div class="qrr-header-actions">
                <span class="qrr-updated-at" id="qrrUpdatedAt">—</span>
                <button class="qrr-btn" type="button" id="qrrRefreshBtn" onclick="qrrLoad(true)">↻ Refresh</button>
                <button class="qrr-btn qrr-btn-primary" type="button" onclick="qrrOpenMap()">🗺️ Open Dispatch Map</button>
            </div>
        </div>
        <div class="qrr-meta-grid" id="qrrMetaGrid"></div>
    </div>

    <div id="qrrPassive" style="display:none;"></div>
    <div id="qrrBody">
        <div class="qrr-loading">Loading route…</div>
    </div>
</div>

{{-- Map modal — lazy-loads Google Maps on first open, same key as
     /qurbani/orders and the rider's mobile dispatch map. --}}
<div class="qrr-modal-overlay" id="qrrMapOverlay" onclick="qrrCloseMap()"></div>
<div class="qrr-modal" id="qrrMapModal" role="dialog" aria-modal="true" aria-labelledby="qrrMapTitle">
    <div class="qrr-modal-head">
        <h3 id="qrrMapTitle">🗺️ Dispatch Map</h3>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:11px;color:#6B7280;" id="qrrMapMeta"></span>
            <button class="qrr-modal-close" type="button" onclick="qrrCloseMap()" aria-label="Close map">×</button>
        </div>
    </div>
    <div class="qrr-modal-body">
        <div class="qrr-modal-legend">
            <div class="qrr-legend-row"><span class="qrr-legend-dot" style="background:#7C3AED;"></span> Base</div>
            <div class="qrr-legend-row"><span class="qrr-legend-dot" style="background:#3B82F6;"></span> Rider GPS</div>
            <div class="qrr-legend-row"><span class="qrr-legend-dot" style="background:#F59E0B;"></span> Out for Delivery</div>
            <div class="qrr-legend-row"><span class="qrr-legend-dot" style="background:#10B981;opacity:.85;"></span> Delivered (this batch)</div>
            {{-- May-2026 — Start: indicator mirrors the mobile
                 dispatch map. Populated by qrrPaintMap() from
                 d.effective_origin so the manager can see which
                 point ETAs were planned from (rider GPS when fresh
                 ≤10 min, warehouse otherwise). --}}
            <div class="qrr-legend-row" id="qrrMapStartRow" style="display:none;">
                <span id="qrrMapStartBadge" style="padding:2px 8px;border-radius:4px;color:#fff;font-weight:800;font-size:11px;"></span>
                <span id="qrrMapStartNote" style="font-size:11px;color:#4B5563;margin-left:6px;"></span>
            </div>
        </div>
        <div id="qrrMapCanvas"></div>
    </div>
</div>
@endsection

@push('custom_js')
<script>
// =====================================================================
// Qurbani Rider Route — Phase 1 (May-2026) read-only web view.
// Reads from the same RiderController endpoints the mobile uses:
//   - /qurbani/api/riders/{id}/route          (bundles + dispatch + GPS)
//   - /qurbani/api/riders/{id}/dispatch-map   (map pins, lazy-loaded)
// Auto-refresh every 30s; pauses when tab is hidden.
// =====================================================================
(function() {
    const REFRESH_MS = 30 * 1000;
    const RIDER_ID   = @json($riderId);
    const ROUTE_URL  = @json(url('/qurbani/api/riders/' . $riderId . '/route'));
    const MAP_URL    = @json(url('/qurbani/api/riders/' . $riderId . '/dispatch-map'));
    const GMAPS_KEY  = 'AIzaSyBFCBj7ebflrliC1pHq0XhsjuW18Q3iElk';
    let _qrrTimer = null;
    let _qrrFetching = false;
    let _qrrMap = null;
    let _qrrMapMarkers = [];
    let _qrrMapInfo = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function relativeTime(iso) {
        if (!iso) return null;
        const t = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(t.getTime())) return null;
        const diffMs = Date.now() - t.getTime();
        const m = Math.floor(diffMs / 60000);
        if (m < 1) return 'just now';
        if (m < 60) return m + 'm ago';
        const h = Math.floor(m / 60);
        if (h < 24) return h + 'h ago';
        const d = Math.floor(h / 24);
        return d + 'd ago';
    }

    function clockTime(iso) {
        if (!iso) return '';
        const t = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(t.getTime())) return '';
        return t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: true });
    }

    function gpsPill(g) {
        if (!g || !g.status) return '';
        const map = {
            live:   { cls: 'qrr-gps-live',   label: 'GPS live' },
            recent: { cls: 'qrr-gps-recent', label: 'GPS ' + (g.age_minutes != null ? g.age_minutes + 'm' : 'recent') },
            stale:  { cls: 'qrr-gps-stale',  label: 'GPS stale ' + (g.age_minutes != null ? g.age_minutes + 'm' : '') },
            none:   { cls: 'qrr-gps-none',   label: 'No GPS' },
        };
        const cfg = map[g.status] || map.none;
        return '<span class="qrr-gps ' + cfg.cls + '" title="' + esc(g.captured_at || 'No reading yet') + '">📍 ' + esc(cfg.label) + '</span>';
    }

    // ── Header (rider name + dispatch metadata + GPS) ──────────────
    function renderHeader(payload) {
        const rider = payload.rider || {};
        document.getElementById('qrrRiderName').textContent = '🛵 ' + (rider.name || ('Rider #' + RIDER_ID));
        document.getElementById('qrrGpsPillSlot').innerHTML = gpsPill(payload.gps);

        // Lock + live-tracking pills (informational only on web — the
        // toggles for both still live on mobile in Phase 1).
        const lock = payload.route_lock;
        document.getElementById('qrrLockSlot').innerHTML = lock
            ? '<span class="qrr-lock" title="Locked by ' + esc(lock.locked_by_name || ('user ' + lock.locked_by)) + ' at ' + esc(lock.locked_at || '') + '">🔒 Editing</span>'
            : '';
        const live = !!(payload.dispatch && payload.dispatch.live_tracking_enabled);
        document.getElementById('qrrLiveSlot').innerHTML = '<span class="qrr-live-pill' + (live ? '' : ' qrr-live-off') + '" title="' + (live ? 'Live ETA refresh on' : 'Live ETA refresh off') + '">' + (live ? '🟢 Live tracking on' : '⚪ Live tracking off') + '</span>';

        const disp = payload.dispatch || {};
        const meta = [];
        meta.push({
            label: 'Dispatched',
            value: disp.dispatched_at
                ? clockTime(disp.dispatched_at) + ' · ' + (relativeTime(disp.dispatched_at) || '')
                : '—',
            muted: !disp.dispatched_at,
        });
        meta.push({
            label: 'Dispatched by',
            value: disp.dispatched_by_name || (disp.dispatched_by ? ('User #' + disp.dispatched_by) : '—'),
            muted: !disp.dispatched_by,
        });
        meta.push({
            label: 'Started delivery',
            value: disp.started_delivery_at
                ? clockTime(disp.started_delivery_at) + ' · ' + (relativeTime(disp.started_delivery_at) || '')
                : '—',
            muted: !disp.started_delivery_at,
            active: !!disp.started_delivery_at && !disp.dispatched_at_is_finished,
        });

        document.getElementById('qrrMetaGrid').innerHTML = meta.map(function(m) {
            const cls = m.active ? 'qrr-meta-active' : (m.muted ? 'qrr-meta-muted' : '');
            return ''
                + '<div class="qrr-meta-item">'
                +   '<div class="qrr-meta-label">' + esc(m.label) + '</div>'
                +   '<div class="qrr-meta-value ' + cls + '">' + esc(m.value) + '</div>'
                + '</div>';
        }).join('');

        // Passive-recompute toast — appears at most once per page-load
        // when the API actually mutated rows.
        const pr = payload.passive_recompute;
        const pasEl = document.getElementById('qrrPassive');
        if (pr && pr.action === 'updated' && (pr.updated || 0) > 0) {
            pasEl.innerHTML = '<div class="qrr-passive-banner">⏱️ Running late detected — ETAs refreshed automatically (' + esc(pr.updated) + ' bundle' + (pr.updated === 1 ? '' : 's') + ' updated).</div>';
            pasEl.style.display = '';
        } else {
            pasEl.style.display = 'none';
        }
    }

    // ── Bundle rendering ───────────────────────────────────────────
    function bundleStatusLabel(b) {
        const ds = b.bundle_dispatch_status;
        const ss = b.bundle_status;
        if (ss === 'delivered') return { key: 'delivered', label: '✓ Delivered' };
        if (ds === 'dispatched') return { key: 'ofd', label: '🚀 Dispatched' };
        return { key: 'pending', label: '📋 Pending' };
    }

    function etaChip(b) {
        const status = b.bundle_status;
        // Delivered: show actual delivery time + early/late vs ETA.
        if (status === 'delivered' && b.qurbani_delivered_at) {
            const eta = b.eta_comparison;
            if (eta && eta.actual_at_display) {
                const cls = eta.on_time ? 'qrr-chip-delivered' : (eta.status === 'late' ? 'qrr-chip-eta-late' : 'qrr-chip-delivered');
                return '<span class="qrr-chip ' + cls + '">📦 ' + esc(eta.actual_at_display) + ' · ' + esc(eta.status_text || '') + '</span>';
            }
            return '<span class="qrr-chip qrr-chip-delivered">📦 Delivered ' + esc(clockTime(b.qurbani_delivered_at)) + '</span>';
        }
        // OFD with ETA.
        if (b.qurbani_estimated_delivery_at) {
            return '<span class="qrr-chip qrr-chip-eta-set">⏱ ETA ' + esc(clockTime(b.qurbani_estimated_delivery_at)) + '</span>';
        }
        // OFD without ETA (still dispatched) or pending — distinguish.
        if (b.bundle_dispatch_status === 'dispatched') {
            return '<span class="qrr-chip qrr-chip-eta">⏱ No ETA stored</span>';
        }
        return '<span class="qrr-chip qrr-chip-eta">⏱ ETA at dispatch</span>';
    }

    function slotChip(b) {
        if (!b.qurbani_slot) return '';
        return '<span class="qrr-chip qrr-chip-slot">🕒 ' + esc(b.qurbani_slot) + '</span>';
    }

    function slotCompareChip(b) {
        const sc = b.slot_compare;
        if (!sc) return '';
        // Re-use display_text directly from the server enrichment.
        let cls = 'qrr-chip-slot-on-time';
        if (sc.state === 'late') cls = 'qrr-chip-slot-late';
        else if (sc.state === 'grace') cls = 'qrr-chip-slot-grace';
        else if (sc.state === 'within_slot' || sc.state === 'on_time') cls = 'qrr-chip-slot-on-time';
        const text = sc.display_text || sc.label || sc.state || '';
        if (!text) return '';
        return '<span class="qrr-chip ' + cls + '">' + esc(text) + '</span>';
    }

    function verifiedChip(b) {
        const url = b.verified_location_url;
        if (b.has_verified_location && url) {
            const tip = b.verified_location_saved_by_name
                ? ('Saved by ' + b.verified_location_saved_by_name + (b.verified_location_saved_at ? ' on ' + b.verified_location_saved_at : ''))
                : 'Verified pin';
            return '<a href="' + esc(url) + '" target="_blank" rel="noopener" class="qrr-chip qrr-chip-verified" title="' + esc(tip) + '" onclick="event.stopPropagation();">📍 Verified pin</a>';
        }
        if (b.has_verified_location) {
            return '<span class="qrr-chip qrr-chip-verified" title="Verified location set">📍 Verified pin</span>';
        }
        return '<span class="qrr-chip qrr-chip-unverified" title="No verified location yet">📍 No pin</span>';
    }

    function deliveryTypeChip(b) {
        if (!b.qurbani_delivery_type) return '';
        return '<span class="qrr-chip qrr-chip-deltype">' + esc(b.qurbani_delivery_type) + '</span>';
    }
    function regionChip(b) {
        const parts = [b.qurbani_region, b.qurbani_sub_region].filter(Boolean);
        if (!parts.length) return '';
        return '<span class="qrr-chip qrr-chip-region">📍 ' + esc(parts.join(' › ')) + '</span>';
    }

    function bundleChip(b) {
        if (!b.bundle_size || b.bundle_size <= 1) return '';
        return '<span class="qrr-chip qrr-chip-bundle">📦 Bundle of ' + esc(b.bundle_size) + '</span>';
    }

    function itemRow(it) {
        const statusKey = it.qurbani_item_status === 'delivered' ? 'qrr-is-delivered'
            : it.qurbani_item_status === 'out_for_delivery' ? 'qrr-is-ofd'
            : it.qurbani_item_status === 'slaughtered' ? 'qrr-is-slaughtered'
            : 'qrr-is-pending';
        const statusLabel = (it.qurbani_item_status || 'pending').replace(/_/g, ' ');
        const metaBits = [];
        if (it.qurbani_type) metaBits.push(it.qurbani_type);
        if (it.qurbani_paya) metaBits.push('Paaye: ' + it.qurbani_paya);
        if (it.bundle_size > 1 && it.bundle_position_start) {
            const range = (it.bundle_position_end && it.bundle_position_end !== it.bundle_position_start)
                ? (it.bundle_position_start + '-' + it.bundle_position_end)
                : it.bundle_position_start;
            metaBits.push(range + ' of ' + it.bundle_size);
        }
        return ''
            + '<div class="qrr-item-row">'
            +   '<div>'
            +     '<span class="qrr-item-name">' + esc(it.quantity > 1 ? (it.quantity + '× ') : '') + esc(it.name || '—') + '</span>'
            +     (metaBits.length ? ' <span class="qrr-item-meta">· ' + esc(metaBits.join(' · ')) + '</span>' : '')
            +   '</div>'
            +   '<span class="qrr-item-status ' + statusKey + '">' + esc(statusLabel) + '</span>'
            + '</div>';
    }

    function renderBundle(b) {
        const isDelivered = b.bundle_status === 'delivered';
        const pri = b.qurbani_delivery_priority;
        const priCls = isDelivered ? 'qrr-priority-delivered' : (pri == null ? 'qrr-priority-none' : '');
        const priText = isDelivered ? '✓' : (pri == null ? '—' : pri);

        // Address — link out to Google when we have a verified URL or
        // coords. Otherwise fall back to a maps search by text address.
        let addrHtml = esc(b.customer_address || '—');
        if (b.verified_location_url) {
            addrHtml = '<a href="' + esc(b.verified_location_url) + '" target="_blank" rel="noopener">' + esc(b.customer_address || 'Verified pin') + '</a>';
        } else if (b.cust_lat && b.cust_lng) {
            const url = 'https://www.google.com/maps/search/?api=1&query=' + b.cust_lat + ',' + b.cust_lng;
            addrHtml = '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(b.customer_address || (b.cust_lat + ', ' + b.cust_lng)) + '</a>';
        } else if (b.customer_address) {
            const url = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(b.customer_address);
            addrHtml = '<a href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(b.customer_address) + '</a>';
        }

        let phoneHtml = '';
        if (b.customer_phone) {
            phoneHtml = '<div class="qrr-bundle-phone">📞 <a href="tel:' + esc(b.customer_phone) + '">' + esc(b.customer_phone) + '</a></div>';
        }

        // Missing-coords warning surfaced when the rider is dispatched
        // but this stop is missing coordinates (will silently miss the
        // map/ETA). Same banner mobile shows on QurbaniRiderRouteScreen.
        let warnHtml = '';
        if (!b.cust_lat || !b.cust_lng) {
            const why = b.coords_reason || 'No coordinates resolved.';
            warnHtml = '<div class="qrr-warn">⚠️ Missing coordinates — this stop won\'t appear on the map or get an ETA. ' + esc(why) + '</div>';
        }

        const items = (b.items || []).map(itemRow).join('');

        return ''
            + '<div class="qrr-bundle' + (isDelivered ? ' qrr-bundle-delivered' : '') + '">'
            +   '<div class="qrr-priority ' + priCls + '">' + esc(priText) + '</div>'
            +   '<div class="qrr-bundle-main">'
            +     '<div class="qrr-bundle-row1">'
            +       '<span class="qrr-bundle-order">' + esc(b.order_number || '') + '</span>'
            +       '<span class="qrr-bundle-customer">' + esc(b.customer_name || 'Unknown') + '</span>'
            +     '</div>'
            +     '<div class="qrr-bundle-row2">'
            +       slotChip(b)
            +       etaChip(b)
            +       slotCompareChip(b)
            +       deliveryTypeChip(b)
            +       bundleChip(b)
            +       regionChip(b)
            +       verifiedChip(b)
            +     '</div>'
            +     '<div class="qrr-bundle-addr">' + addrHtml + '</div>'
            +     phoneHtml
            +     warnHtml
            +     (items ? '<div class="qrr-items">' + items + '</div>' : '')
            +   '</div>'
            + '</div>';
    }

    // ── Group bundles into sections ───────────────────────────────
    // Same logic as QurbaniRiderRouteScreen.js — bundles batched by
    // qurbani_dispatched_at (5-min tolerance), pending grouped under
    // "Awaiting dispatch", delivered grouped at the bottom.
    function buildSections(bundles) {
        const out = { ofdBatches: [], pending: [], delivered: [] };
        const dispatched = [];
        bundles.forEach(b => {
            if (b.bundle_status === 'delivered') out.delivered.push(b);
            else if (b.bundle_dispatch_status === 'dispatched') dispatched.push(b);
            else out.pending.push(b);
        });

        // Cluster dispatched bundles by qurbani_dispatched_at within
        // a 5-min window (same heuristic the dispatch-map endpoint uses).
        dispatched.sort((a, b) => String(a.qurbani_dispatched_at).localeCompare(String(b.qurbani_dispatched_at)));
        let currentBatch = null;
        dispatched.forEach(b => {
            const t = b.qurbani_dispatched_at ? new Date(String(b.qurbani_dispatched_at).replace(' ', 'T')).getTime() : 0;
            if (!currentBatch || Math.abs(t - currentBatch.t0) > 5 * 60 * 1000) {
                currentBatch = { t0: t, dispatched_at: b.qurbani_dispatched_at, bundles: [] };
                out.ofdBatches.push(currentBatch);
            }
            currentBatch.bundles.push(b);
        });

        // Sort within each section by priority asc (NULL last).
        const byPri = (a, b) => {
            const pa = a.qurbani_delivery_priority == null ? 9999 : a.qurbani_delivery_priority;
            const pb = b.qurbani_delivery_priority == null ? 9999 : b.qurbani_delivery_priority;
            return pa - pb;
        };
        out.ofdBatches.forEach(batch => batch.bundles.sort(byPri));
        out.pending.sort(byPri);
        out.delivered.sort(byPri);
        return out;
    }

    function section(headerClass, title, meta, bundles) {
        if (!bundles || !bundles.length) return '';
        return ''
            + '<div class="qrr-section">'
            +   '<div class="qrr-section-head ' + headerClass + '">'
            +     '<h2>' + esc(title) + '</h2>'
            +     (meta ? '<span class="qrr-section-meta">' + esc(meta) + '</span>' : '')
            +   '</div>'
            +   '<div class="qrr-section-body">'
            +     bundles.map(renderBundle).join('')
            +   '</div>'
            + '</div>';
    }

    function renderBody(payload) {
        const bundles = payload.bundles || [];
        if (!bundles.length) {
            document.getElementById('qrrBody').innerHTML = '<div class="qrr-empty">No assignments for this rider right now.</div>';
            return;
        }
        const sections = buildSections(bundles);
        let html = '';
        sections.ofdBatches.forEach((batch, idx) => {
            const title = sections.ofdBatches.length > 1
                ? ('🚀 Dispatch ' + (idx + 1) + ' · Out for Delivery')
                : '🚀 Out for Delivery';
            const undelivered = batch.bundles.filter(b => b.bundle_status !== 'delivered').length;
            const meta = batch.dispatched_at
                ? ('Dispatched ' + (relativeTime(batch.dispatched_at) || '') + ' · ' + batch.bundles.length + ' stop' + (batch.bundles.length === 1 ? '' : 's') + ' · ' + undelivered + ' undelivered')
                : (batch.bundles.length + ' stops');
            html += section('qrr-sh-active', title, meta, batch.bundles);
        });
        html += section('qrr-sh-pending', '📋 Awaiting Dispatch', sections.pending.length + ' stop' + (sections.pending.length === 1 ? '' : 's'), sections.pending);
        html += section('qrr-sh-delivered', '✓ Delivered', sections.delivered.length + ' stop' + (sections.delivered.length === 1 ? '' : 's'), sections.delivered);
        document.getElementById('qrrBody').innerHTML = html;
    }

    // ── Load + refresh loop ────────────────────────────────────────
    window.qrrLoad = async function(manual) {
        if (_qrrFetching) return;
        _qrrFetching = true;
        const btn = document.getElementById('qrrRefreshBtn');
        if (manual && btn) { btn.disabled = true; btn.textContent = '↻ Refreshing…'; }
        try {
            const res = await fetch(ROUTE_URL + '?show=all&_=' + Date.now(), {
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.message ? json.message : 'Server error');
            renderHeader(json);
            renderBody(json);
            const now = new Date();
            document.getElementById('qrrUpdatedAt').textContent =
                'Updated ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (e) {
            document.getElementById('qrrBody').innerHTML =
                '<div class="qrr-error">⚠️ ' + esc(e && e.message ? e.message : String(e)) + '</div>';
        } finally {
            _qrrFetching = false;
            if (btn) { btn.disabled = false; btn.textContent = '↻ Refresh'; }
        }
    };

    function startPolling() {
        if (_qrrTimer) clearInterval(_qrrTimer);
        _qrrTimer = setInterval(() => qrrLoad(false), REFRESH_MS);
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            if (_qrrTimer) { clearInterval(_qrrTimer); _qrrTimer = null; }
        } else {
            qrrLoad(false);
            startPolling();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        qrrLoad(false);
        startPolling();
    });

    // ── Dispatch map modal ────────────────────────────────────────
    function ensureGoogleMaps(cb) {
        if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') return cb();
        if (window._qrrMapsLoading) {
            const prev = window._qrrMapsLoading;
            window._qrrMapsLoading = function() { prev(); cb(); };
            return;
        }
        window._qrrMapsLoading = cb;
        const script = document.createElement('script');
        script.src = 'https://maps.googleapis.com/maps/api/js?key=' + GMAPS_KEY + '&libraries=places';
        script.async = true; script.defer = true;
        script.onload = function() {
            const fn = window._qrrMapsLoading; window._qrrMapsLoading = null;
            if (typeof fn === 'function') fn();
        };
        script.onerror = function() {
            alert('Failed to load Google Maps — check internet / API key.');
            window._qrrMapsLoading = null;
        };
        window.gm_authFailure = function() { alert('Google Maps API key rejected. Contact admin.'); };
        document.head.appendChild(script);
    }

    function clearMapMarkers() {
        _qrrMapMarkers.forEach(m => m.setMap(null));
        _qrrMapMarkers = [];
        if (_qrrMapInfo) _qrrMapInfo.close();
    }

    window.qrrOpenMap = function() {
        document.getElementById('qrrMapOverlay').style.display = 'block';
        document.getElementById('qrrMapModal').style.display = 'flex';
        ensureGoogleMaps(initMap);
    };

    window.qrrCloseMap = function() {
        document.getElementById('qrrMapOverlay').style.display = 'none';
        document.getElementById('qrrMapModal').style.display = 'none';
    };

    function initMap() {
        if (!_qrrMap) {
            _qrrMap = new google.maps.Map(document.getElementById('qrrMapCanvas'), {
                zoom: 12,
                center: { lat: 31.5204, lng: 74.3587 }, // Lahore fallback — overridden by bounds.fitBounds()
                streetViewControl: false,
                mapTypeControl: false,
                fullscreenControl: false,
            });
            _qrrMapInfo = new google.maps.InfoWindow();
        }
        loadMapPins();
    }

    async function loadMapPins() {
        clearMapMarkers();
        document.getElementById('qrrMapMeta').textContent = 'Loading pins…';
        try {
            const res = await fetch(MAP_URL + '?_=' + Date.now(), {
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.message ? json.message : 'Failed');
            renderMap(json);
        } catch (e) {
            document.getElementById('qrrMapMeta').textContent = '⚠️ ' + (e.message || 'Failed to load pins');
        }
    }

    function renderMap(d) {
        const bounds = new google.maps.LatLngBounds();
        let added = 0;
        // Base
        if (d.base && d.base.lat && d.base.lng) {
            const pos = { lat: d.base.lat, lng: d.base.lng };
            const m = new google.maps.Marker({
                position: pos, map: _qrrMap, title: d.base.name || 'Base',
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 12, fillColor: '#7C3AED', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
                zIndex: 100,
            });
            _qrrMapMarkers.push(m); bounds.extend(pos); added++;
        }
        // Rider GPS — dimmed when the server picked the warehouse as
        // the effective origin (rider GPS stale > 10 min) so the
        // manager can see at a glance "the rider's pin isn't being
        // used as the route start".
        const rg = d.rider_gps;
        const eo = d.effective_origin || null;
        const startIsWarehouseWeb = eo && eo.source === 'qurbani_base';
        const startIsRiderGpsWeb  = eo && eo.source === 'rider_gps';
        const riderGpsStaleUnused = rg && rg.lat && rg.lng && !startIsRiderGpsWeb;
        if (rg && rg.lat && rg.lng) {
            const pos = { lat: rg.lat, lng: rg.lng };
            const m = new google.maps.Marker({
                position: pos, map: _qrrMap,
                title: 'Rider — ' + (rg.status === 'live' ? 'Live' : rg.status === 'recent' ? ('GPS ' + (rg.age_minutes || '') + 'm') : 'Stale')
                    + (riderGpsStaleUnused ? ' · stale (warehouse used as route start)' : ''),
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 10, fillColor: '#3B82F6', fillOpacity: riderGpsStaleUnused ? 0.4 : 1, strokeColor: '#fff', strokeWeight: 3 },
                zIndex: 200,
            });
            _qrrMapMarkers.push(m); bounds.extend(pos); added++;
        }
        // Delivered (green)
        (d.delivered_bundles || []).forEach(b => {
            if (!b.lat || !b.lng) return;
            const pos = { lat: b.lat, lng: b.lng };
            const m = new google.maps.Marker({
                position: pos, map: _qrrMap, title: b.customer_name || '',
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 11, fillColor: '#10B981', fillOpacity: 0.85, strokeColor: '#fff', strokeWeight: 2 },
                zIndex: 50,
            });
            m.addListener('click', () => {
                _qrrMapInfo.setContent('<div style="font-size:12px;line-height:1.4;"><strong>' + esc(b.customer_name || '—') + '</strong><br>' + esc(b.customer_address || '') + '<br><span style="color:#065F46;">✓ Delivered ' + (b.delivered_at ? clockTime(b.delivered_at) : '') + '</span></div>');
                _qrrMapInfo.open(_qrrMap, m);
            });
            _qrrMapMarkers.push(m); bounds.extend(pos); added++;
        });
        // OFD (amber, sequence-numbered label)
        (d.ofd_bundles || []).forEach(b => {
            if (!b.lat || !b.lng) return;
            const pos = { lat: b.lat, lng: b.lng };
            const seq = b.priority != null ? String(b.priority) : '';
            const m = new google.maps.Marker({
                position: pos, map: _qrrMap, title: b.customer_name || '',
                label: seq ? { text: seq, color: '#fff', fontWeight: '700', fontSize: '11px' } : undefined,
                icon: { path: google.maps.SymbolPath.CIRCLE, scale: 13, fillColor: '#F59E0B', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2 },
                zIndex: 150,
            });
            m.addListener('click', () => {
                const etaHtml = b.estimated_delivery_at ? '<br><span style="color:#92400E;">⏱ ETA ' + clockTime(b.estimated_delivery_at) + '</span>' : '';
                _qrrMapInfo.setContent('<div style="font-size:12px;line-height:1.4;"><strong>' + esc(b.customer_name || '—') + '</strong> (#' + esc(seq || '?') + ')<br>' + esc(b.customer_address || '') + etaHtml + '</div>');
                _qrrMapInfo.open(_qrrMap, m);
            });
            _qrrMapMarkers.push(m); bounds.extend(pos); added++;
        });

        const c = d.counts || {};
        document.getElementById('qrrMapMeta').textContent =
            (c.ofd || 0) + ' OFD · ' + (c.delivered || 0) + ' delivered' +
            (d.dispatched_at ? ' · dispatched ' + (relativeTime(d.dispatched_at) || '') : '');

        // May-2026 — populate the Start: indicator. Mirrors the
        // mobile dispatch map. Hidden when there's no effective
        // origin (no warehouse + no GPS — already surfaced by the
        // dispatch panel as a missing-coords warning).
        const startRow   = document.getElementById('qrrMapStartRow');
        const startBadge = document.getElementById('qrrMapStartBadge');
        const startNote  = document.getElementById('qrrMapStartNote');
        if (startRow && startBadge && startNote) {
            if (eo && eo.lat != null && eo.lng != null) {
                if (startIsWarehouseWeb) {
                    startBadge.textContent = '🏪 Start: Warehouse';
                    startBadge.style.background = '#7C3AED';
                } else {
                    startBadge.textContent = '🛵 Start: Rider GPS';
                    startBadge.style.background = '#3B82F6';
                }
                let note = eo.source_label || '';
                if (riderGpsStaleUnused) {
                    note += ' · (rider GPS is ' + (rg.age_minutes != null ? rg.age_minutes : '?') + ' min old — using warehouse instead)';
                }
                startNote.textContent = note;
                startRow.style.display = '';
            } else {
                startRow.style.display = 'none';
            }
        }

        if (added > 0) {
            _qrrMap.fitBounds(bounds);
            // Bound the zoom — single-pin dispatches otherwise zoom in too far.
            const listener = google.maps.event.addListener(_qrrMap, 'idle', function() {
                if (_qrrMap.getZoom() > 15) _qrrMap.setZoom(15);
                google.maps.event.removeListener(listener);
            });
        }
    }
})();
</script>
@endpush
