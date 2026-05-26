@extends('layouts.app')

@section('title', 'Qurbani Riders')

@push('custom_css')
<style>
/* =====================================================================
   Qurbani Riders — Phase 1 (May-2026) read-only web mirror of the
   mobile QurbaniRidersScreen. Naming convention: qr-* so this file
   never collides with /qurbani/orders (qo-*) or /qurbani/invoices.
   Visual language mirrors /qurbani/performance: white cards, 10-12px
   radius, soft shadows, amber accent.
   ===================================================================== */
.qr-page { padding: 18px 22px 32px; max-width: 1400px; margin: 0 auto; }

/* Header */
.qr-page-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    gap: 16px; margin-bottom: 18px; flex-wrap: wrap;
}
.qr-page-head h1 { font-size: 20px; font-weight: 600; color: #111827; margin: 0; }
.qr-page-head p  { font-size: 13px; color: #6b7280; margin: 4px 0 0; }
.qr-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.qr-refresh-btn {
    padding: 7px 14px; border-radius: 6px; border: 1px solid #d1d5db;
    background: #fff; color: #1f2937; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s;
}
.qr-refresh-btn:hover { background: #f9fafb; border-color: #9ca3af; }
.qr-refresh-btn:disabled { opacity: .5; cursor: not-allowed; }
.qr-updated-at { font-size: 11px; color: #9ca3af; }

/* Phase-1 read-only banner. We don't bury this — it's the single most
   important piece of context for a manager landing on this page from
   the sidebar expecting Dispatch/Cancel/Auto Route buttons. */
.qr-readonly-banner {
    background: #FFFBEB; border: 1px solid #FCD34D; border-radius: 10px;
    padding: 12px 16px; margin-bottom: 16px;
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 13px; color: #92400E;
}
.qr-readonly-banner .qr-rb-icon { font-size: 18px; line-height: 1; }
.qr-readonly-banner strong { color: #78350F; }

/* Summary strip — counts across all riders. */
.qr-summary {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px; margin-bottom: 18px;
}
.qr-summary-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
    padding: 12px 14px; box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.qr-summary-card .qr-summary-label { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.qr-summary-card .qr-summary-value { font-size: 22px; color: #111827; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }

/* Rider grid */
.qr-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
}
/* Card is rendered as an <a> so it's keyboard-navigable, ctrl-click
   opens in a new tab, and the URL shows in the browser status bar on
   hover. We strip the default anchor styling and inherit colour. */
a.qr-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 14px 16px; box-shadow: 0 1px 2px rgba(0,0,0,.04);
    cursor: pointer; transition: all .15s;
    display: flex; flex-direction: column; gap: 10px;
    text-decoration: none; color: inherit;
}
a.qr-card:hover { border-color: #d97706; box-shadow: 0 4px 12px rgba(217,119,6,.12); transform: translateY(-1px); text-decoration: none; color: inherit; }
a.qr-card:focus-visible { outline: 2px solid #d97706; outline-offset: 2px; }

.qr-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.qr-card-name { font-size: 15px; font-weight: 700; color: #111827; line-height: 1.2; }

/* GPS pill — same colour buckets as mobile QurbaniRidersScreen. */
.qr-gps { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; line-height: 1; }
.qr-gps.qr-gps-live   { background: #D1FAE5; color: #065F46; }
.qr-gps.qr-gps-recent { background: #FEF3C7; color: #92400E; }
.qr-gps.qr-gps-stale  { background: #FEE2E2; color: #991B1B; }
.qr-gps.qr-gps-none   { background: #F3F4F6; color: #6B7280; }

/* Count row */
.qr-counts { display: flex; gap: 8px; }
.qr-count-chip {
    flex: 1; text-align: center; padding: 8px 6px; border-radius: 8px;
    background: #F9FAFB; border: 1px solid #E5E7EB;
}
.qr-count-chip .qr-count-num { font-size: 18px; font-weight: 700; color: #111827; line-height: 1; font-variant-numeric: tabular-nums; }
.qr-count-chip .qr-count-label { font-size: 10px; color: #6B7280; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
.qr-count-chip.qr-count-pending .qr-count-num   { color: #1F2937; }
.qr-count-chip.qr-count-ofd .qr-count-num       { color: #B45309; }
.qr-count-chip.qr-count-delivered .qr-count-num { color: #065F46; }

/* Last-activity line */
.qr-last { font-size: 12px; color: #6B7280; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.qr-last.qr-last-complete { color: #065F46; font-weight: 600; }
.qr-last.qr-last-active   { color: #1E40AF; font-weight: 600; }

/* Lock indicator */
.qr-lock {
    display: inline-flex; align-items: center; gap: 4px; padding: 2px 6px;
    border-radius: 4px; background: #FEE2E2; color: #991B1B; font-size: 10px;
    font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
}

/* May-2026 — Return-to-base chip. Indigo so it reads as
   "route-derived planning data" (distinct from amber pending,
   red OFD, green delivered, grey lock). Renders below the
   last-activity line; absent when the rider has no active
   dispatch or no cached estimate. */
.qr-rtb {
    display: inline-flex; align-items: center; gap: 4px;
    margin-top: 4px;
    padding: 3px 8px; border-radius: 999px;
    background: #EEF2FF; border: 1px solid #C7D2FE; color: #3730A3;
    font-size: 11px; font-weight: 700; line-height: 1;
}

/* Empty/loading states */
.qr-empty {
    text-align: center; padding: 60px 20px; color: #6B7280; font-size: 14px;
    background: #fff; border: 1px dashed #d1d5db; border-radius: 12px;
}
.qr-loading { text-align: center; padding: 40px; color: #9CA3AF; font-size: 13px; }
.qr-error {
    background: #FEF2F2; border: 1px solid #FCA5A5; color: #991B1B;
    padding: 12px 16px; border-radius: 10px; font-size: 13px;
}
</style>
@endpush

@section('content')
<div class="qr-page">
    <div class="qr-page-head">
        <div>
            <h1>🛵 Qurbani Riders</h1>
            <p>Live dispatch view — pending stops, current OFD, ETAs, GPS health. Updates every 30 seconds.</p>
        </div>
        <div class="qr-toolbar">
            <span class="qr-updated-at" id="qrUpdatedAt">—</span>
            <button class="qr-refresh-btn" id="qrRefreshBtn" type="button" onclick="qrLoad(true)">↻ Refresh</button>
        </div>
    </div>

    <div class="qr-readonly-banner">
        <span class="qr-rb-icon">ℹ️</span>
        <div>
            <strong>Read-only view (Phase 1).</strong>
            Dispatch, Cancel, Auto Route, Edit Route, Live Tracking and other actions still happen on the mobile app — open a rider here to see their current route, ETAs and GPS in real time, then switch to mobile for changes.
        </div>
    </div>

    <div class="qr-summary" id="qrSummary" style="display:none;">
        <div class="qr-summary-card"><div class="qr-summary-label">Riders</div><div class="qr-summary-value" id="qrSumRiders">0</div></div>
        <div class="qr-summary-card"><div class="qr-summary-label">Pending</div><div class="qr-summary-value" id="qrSumPending" style="color:#1F2937;">0</div></div>
        <div class="qr-summary-card"><div class="qr-summary-label">Out for Delivery</div><div class="qr-summary-value" id="qrSumOfd" style="color:#B45309;">0</div></div>
        <div class="qr-summary-card"><div class="qr-summary-label">Delivered today</div><div class="qr-summary-value" id="qrSumDelivered" style="color:#065F46;">0</div></div>
    </div>

    <div id="qrBody">
        <div class="qr-loading">Loading riders…</div>
    </div>
</div>
@endsection

@push('custom_js')
<script>
// ===================================================================
// Qurbani Riders list — Phase 1 (May-2026).
// Lightweight read-only page that fetches /qurbani/api/riders every
// 30 seconds. Same endpoint the mobile QurbaniRidersScreen uses, so
// the data shape (counts, GPS bucket, last_dispatched_at,
// last_delivered_at, lock) is guaranteed to stay in sync.
// ===================================================================
(function() {
    const REFRESH_MS = 30 * 1000;
    const RIDER_URL_BASE = @json(url('/qurbani/riders'));
    const RIDERS_API     = @json(url('/qurbani/api/riders'));
    let _qrTimer = null;
    let _qrFetching = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Pretty "Xm ago" / "Xh ago" relative formatter. Mirrors the
    // formatRelative used in QurbaniRidersScreen.js so the manager
    // sees the same phrasing on both surfaces.
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

    // May-2026 — Return-to-base time formatter. The server caches the
    // dispatch-time estimate so this page can show "Back at HH:MM"
    // WITHOUT making any Google calls per refresh. Returns null when
    // the estimate is missing OR more than 30 min past — defensive
    // (server cache TTL is the primary guard).
    function backByTime(iso) {
        if (!iso) return null;
        const d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d.getTime())) return null;
        if (d.getTime() < Date.now() - 30 * 60 * 1000) return null;
        const h = d.getHours();
        const m = d.getMinutes();
        const ampm = h >= 12 ? 'PM' : 'AM';
        const hh = (h % 12) || 12;
        const mm = m < 10 ? '0' + m : '' + m;
        return hh + ':' + mm + ' ' + ampm;
    }

    function gpsPill(g) {
        if (!g || !g.status) return '';
        const map = {
            live:   { cls: 'qr-gps-live',   label: 'GPS live'   },
            recent: { cls: 'qr-gps-recent', label: 'GPS ' + (g.age_minutes != null ? g.age_minutes + 'm' : 'recent') },
            stale:  { cls: 'qr-gps-stale',  label: 'GPS stale ' + (g.age_minutes != null ? g.age_minutes + 'm' : '') },
            none:   { cls: 'qr-gps-none',   label: 'No GPS'     },
        };
        const cfg = map[g.status] || map.none;
        return '<span class="qr-gps ' + cfg.cls + '" title="' + esc(g.captured_at || 'No reading yet') + '">📍 ' + esc(cfg.label) + '</span>';
    }

    function renderCard(r) {
        const total = (r.pending_items || 0) + (r.ofd_items || 0) + (r.delivered_items || 0);
        // Last-activity line picks the most useful state:
        //   - all delivered (pending=0, ofd=0, delivered>0) → "✓ Finished Xm ago"
        //   - has OFD now → "🚀 Started Xm ago" (or "Dispatched Xm ago" if no start yet)
        //   - has pending only → idle "Last dispatched …" / "Idle"
        let lastLine = '';
        const noActiveWork = (r.pending_items || 0) === 0 && (r.ofd_items || 0) === 0;
        if (noActiveWork && (r.delivered_items || 0) > 0) {
            const fin = relativeTime(r.last_delivered_at || r.last_dispatched_at);
            lastLine = '<div class="qr-last qr-last-complete">✓ All delivered · finished ' + esc(fin || '—') + '</div>';
        } else if ((r.ofd_items || 0) > 0) {
            const disp = relativeTime(r.last_dispatched_at);
            lastLine = '<div class="qr-last qr-last-active">🚀 Dispatched ' + esc(disp || '—') + '</div>';
        } else if (r.last_dispatched_at) {
            const disp = relativeTime(r.last_dispatched_at);
            lastLine = '<div class="qr-last">Last dispatched ' + esc(disp || '—') + '</div>';
        } else {
            lastLine = '<div class="qr-last">No dispatch yet</div>';
        }

        const lockChip = r.route_locked && r.route_lock
            ? '<span class="qr-lock" title="Locked by ' + esc(r.route_lock.locked_by_name || ('user ' + r.route_lock.locked_by)) + '">🔒 Editing</span>'
            : '';

        // May-2026 — Back-to-base chip. The server only returns
        // return_to_base for riders with an ACTIVE dispatch AND a
        // cached estimate (gated server-side); both conditions failing
        // is the common "no chip" path, so this branch is silent
        // rather than rendering a placeholder.
        let rtbChip = '';
        if (r.return_to_base && r.return_to_base.estimated_at) {
            const t = backByTime(r.return_to_base.estimated_at);
            if (t) {
                const baseName = r.return_to_base.base_name || 'base';
                const mins = r.return_to_base.return_minutes;
                const title = mins ? '~' + mins + ' min return leg from last stop' : 'Estimated return time';
                rtbChip = '<span class="qr-rtb" title="' + esc(title) + '">⮌ Back at ' + esc(baseName) + ' by ' + esc(t) + '</span>';
            }
        }

        const href = RIDER_URL_BASE + '/' + r.rider_id;
        return ''
            + '<a class="qr-card" href="' + esc(href) + '">'
            +     '<div class="qr-card-head">'
            +         '<div class="qr-card-name">' + esc(r.rider_name || 'Rider #' + r.rider_id) + '</div>'
            +         gpsPill(r.gps)
            +     '</div>'
            +     '<div class="qr-counts">'
            +         '<div class="qr-count-chip qr-count-pending"><div class="qr-count-num">' + (r.pending_items || 0) + '</div><div class="qr-count-label">Pending</div></div>'
            +         '<div class="qr-count-chip qr-count-ofd"><div class="qr-count-num">' + (r.ofd_items || 0) + '</div><div class="qr-count-label">Out for Delivery</div></div>'
            +         '<div class="qr-count-chip qr-count-delivered"><div class="qr-count-num">' + (r.delivered_items || 0) + '</div><div class="qr-count-label">Delivered</div></div>'
            +     '</div>'
            +     lastLine
            +     (rtbChip ? '<div>' + rtbChip + '</div>' : '')
            +     (lockChip ? '<div>' + lockChip + ' · ' + esc(total) + ' total assigned</div>' : '<div style="font-size:11px;color:#9CA3AF;">' + esc(total) + ' total assigned</div>')
            + '</a>';
    }

    function render(payload) {
        const list = (payload && payload.riders) || [];
        const body = document.getElementById('qrBody');
        if (!list.length) {
            body.innerHTML = '<div class="qr-empty">No riders have Qurbani items assigned right now.<br><span style="font-size:12px;color:#9ca3af;">Assign riders from the Qurbani Orders page to see them here.</span></div>';
            document.getElementById('qrSummary').style.display = 'none';
            return;
        }

        // Top summary roll-up.
        let sumP = 0, sumO = 0, sumD = 0;
        list.forEach(r => {
            sumP += (r.pending_items   || 0);
            sumO += (r.ofd_items       || 0);
            sumD += (r.delivered_items || 0);
        });
        document.getElementById('qrSumRiders').textContent    = list.length;
        document.getElementById('qrSumPending').textContent   = sumP;
        document.getElementById('qrSumOfd').textContent       = sumO;
        document.getElementById('qrSumDelivered').textContent = sumD;
        document.getElementById('qrSummary').style.display = '';

        body.innerHTML = '<div class="qr-grid">' + list.map(renderCard).join('') + '</div>';
    }

    function setError(msg) {
        document.getElementById('qrBody').innerHTML =
            '<div class="qr-error">⚠️ ' + esc(msg || 'Failed to load.') + '</div>';
    }

    window.qrLoad = async function(manual) {
        if (_qrFetching) return;
        _qrFetching = true;
        const btn = document.getElementById('qrRefreshBtn');
        if (manual && btn) { btn.disabled = true; btn.textContent = '↻ Refreshing…'; }
        try {
            // Cache-bust so 30s polls always hit fresh data even behind
            // a proxy that ignores Cache-Control on GET. Same pattern
            // /qurbani/orders uses for its print-sheet fetch.
            const res = await fetch(RIDERS_API + '?_=' + Date.now(), {
                cache: 'no-store',
                headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.message ? json.message : 'Server error');
            render(json);
            const now = new Date();
            document.getElementById('qrUpdatedAt').textContent =
                'Updated ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        } catch (e) {
            setError(e && e.message ? e.message : String(e));
        } finally {
            _qrFetching = false;
            if (btn) { btn.disabled = false; btn.textContent = '↻ Refresh'; }
        }
    };

    function startPolling() {
        if (_qrTimer) clearInterval(_qrTimer);
        _qrTimer = setInterval(() => qrLoad(false), REFRESH_MS);
    }

    // Pause polling when tab is hidden — managers often have this in
    // a background tab while they work elsewhere; no point hammering
    // the API when no human is looking. Resume + immediate refresh
    // when the tab becomes visible again.
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            if (_qrTimer) { clearInterval(_qrTimer); _qrTimer = null; }
        } else {
            qrLoad(false);
            startPolling();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        qrLoad(false);
        startPolling();
    });
})();
</script>
@endpush
