@extends('layouts.app')

@section('title', '🐔 La Carne')

{{--
    La Carne — the chicken board.

    Everything on this page comes from ONE call to /lacarne/board?date=YYYY-MM-DD,
    which returns the three sections as complete drill trees. Drilling is therefore
    client-side: no request per node, and the page can never render a level that
    disagrees with its own summary.

    ⚠ ONE <script> block, and all state hangs off window.LC. Several blade pages in
      this app were broken by a second top-level `let` in a second block — that is a
      SyntaxError which silently kills the whole panel while the log stays clean.
--}}

@push('custom_css')
<style>
    .lc-row { cursor: pointer; transition: background-color .12s; }
    .lc-row:hover { background-color: #FFFBEB; }
    .lc-row.lc-leaf { cursor: default; }
    .lc-row.lc-leaf:hover { background-color: transparent; }
    .lc-crumb { cursor: pointer; color: #B45309; }
    .lc-crumb:hover { text-decoration: underline; }
    .lc-thumb { width: 104px; height: 104px; object-fit: cover; border-radius: 10px; border: 1px solid #E5E7EB; cursor: pointer; }
    .lc-num { font-variant-numeric: tabular-nums; }
</style>
@endpush

@section('content')
<div class="container-fluid px-6 py-6">

    {{-- ── header + date control ─────────────────────────────────────────── --}}
    <div class="flex items-start justify-between mb-5 flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">🐔 La Carne</h1>
            <p class="text-sm text-gray-600 mt-0.5">
                {{ $category }} demand and stock — for deciding what to buy at the supplier
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" id="lcPrevBtn" onclick="LC.shiftDay(-1)"
                    class="px-3 py-2 text-sm font-medium rounded-lg border"
                    style="border-color:#E5E7EB;background:#fff;">‹ Prev</button>
            <input type="date" id="lcDate" max="{{ $today }}" value="{{ $today }}"
                   onchange="LC.setDate(this.value)"
                   class="px-3 py-2 text-sm rounded-lg border" style="border-color:#E5E7EB;">
            <button type="button" id="lcNextBtn" onclick="LC.shiftDay(1)"
                    class="px-3 py-2 text-sm font-medium rounded-lg border"
                    style="border-color:#E5E7EB;background:#fff;">Next ›</button>
            <button type="button" onclick="LC.setDate('{{ $today }}')"
                    class="px-3 py-2 text-sm font-medium rounded-lg text-white"
                    style="background:#D97706;">Today</button>
        </div>
    </div>

    {{-- ── what is physically in our storage (today only) ─────────────────── --}}
    <div id="lcStorage" class="mb-5"></div>

    {{-- ── notices ───────────────────────────────────────────────────────── --}}
    <div id="lcNotices" class="mb-5"></div>

    {{-- ── the three sections ────────────────────────────────────────────── --}}
    <div id="lcSections"></div>

    {{-- ── invoice photos ────────────────────────────────────────────────── --}}
    <div class="rounded-xl border bg-white p-5 mt-6" style="border-color:#E5E7EB;">
        <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">🧾 Supplier invoice</h2>
                <p class="text-sm text-gray-600 mt-0.5" id="lcPhotoSubtitle">Photos filed against this date</p>
            </div>
            <div id="lcPhotoControls"></div>
        </div>
        <div id="lcPhotos"></div>
    </div>
</div>

{{-- storage breakdown --}}
<div id="lcStockModal" onclick="if(event.target===this)LC.closeStock()"
     style="display:none;position:fixed;inset:0;background:rgba(17,24,39,.6);z-index:9998;align-items:center;justify-content:center;padding:24px;">
    <div style="background:#fff;border-radius:14px;max-width:620px;width:100%;max-height:82vh;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:16px 18px;border-bottom:1px solid #E5E7EB;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div style="font-size:16px;font-weight:700;color:#111827;">🧊 In storage</div>
                <span onclick="LC.closeStock()" style="cursor:pointer;font-size:18px;color:#6B7280;font-weight:700;">✕</span>
            </div>
            <div id="lcStockSub" style="font-size:12px;color:#6B7280;margin-top:3px;"></div>
        </div>
        <div id="lcStockBody" style="padding:14px 18px;overflow-y:auto;"></div>
    </div>
</div>

{{-- lightbox --}}
<div id="lcLightbox" onclick="LC.closeLightbox()"
     style="display:none;position:fixed;inset:0;background:rgba(17,24,39,.88);z-index:9999;align-items:center;justify-content:center;padding:24px;">
    <img id="lcLightboxImg" src="" alt="" style="max-width:96vw;max-height:92vh;border-radius:12px;">
</div>
@endsection

{{-- ⚠ 'custom_js', NOT 'scripts'. layouts/app.blade.php only stacks demo1_css,
     demo1_js and modals; custom_css/custom_js/page_js live in the head and
     scripts partials. A push to a stack nobody renders vanishes silently. --}}
@push('custom_js')
<script>
window.LC = (function () {
    'use strict';

    const BOARD_URL   = @json(route('lacarne.board'));
    const UPLOAD_URL  = @json(route('lacarne.photos.add'));
    const DELETE_BASE = @json(url('/lacarne/photos'));
    // Prefer the live <meta> tag the layout already renders; the baked value is
    // only a fallback. Reading the meta means a token refreshed after a session
    // bounce is still correct without a page rebuild.
    const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content
              || @json(csrf_token()) || '';
    const TODAY = @json($today);

    const state = {
        date: TODAY,
        data: null,
        // one independent drill path per section, held as an array of row INDICES
        paths: {},
        // which section's drill the storage card follows (null = show everything)
        stockCtxKey: null,
        loading: false,
        reqSeq: 0,
    };

    const SECTION_STYLE = {
        open:             { icon: '📋', bg: '#FFFBEB', border: '#FDE68A', text: '#92400E', accent: '#D97706' },
        out_for_delivery: { icon: '🛵', bg: '#EFF6FF', border: '#BFDBFE', text: '#1E40AF', accent: '#2563EB' },
        delivered:        { icon: '✅', bg: '#ECFDF5', border: '#A7F3D0', text: '#065F46', accent: '#059669' },
    };

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // 5.970 -> 5.97, 3.000 -> 3 — the same trimming the Overnight page uses.
    function num(v) {
        const n = Number(v || 0);
        if (!isFinite(n)) return '0';
        return String(parseFloat(n.toFixed(2)));
    }

    function storageChip(st, label, icon, colour) {
        if (!st) return '';
        const bits = [];
        if (st.packets) bits.push(st.packets + ' pkt');
        // ⚠ kg and pcs are different units and are NEVER added together.
        if (st.kg)  bits.push(num(st.kg) + ' kg');
        if (st.pcs) bits.push(num(st.pcs) + ' pcs');
        if (!bits.length) return '';
        return '<span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:9999px;'
            + 'font-size:12px;font-weight:600;background:#fff;border:1px solid ' + colour + ';color:' + colour + ';">'
            + icon + ' ' + label + ' ' + esc(bits.join(' · ')) + '</span>';
    }

    // ── rendering ─────────────────────────────────────────────────────────
    function renderStorage() {
        const el = document.getElementById('lcStorage');
        const d = state.data;
        if (!d || !d.is_today) {
            el.innerHTML = '';
            return;
        }
        const items = stockItems();
        const s = stockTotals(items);
        const scope = stockScopeLabel();
        const chips = storageChip(s.chiller, 'Chiller', '🧊', '#0EA5E9') + ' '
                    + storageChip(s.freezer, 'Freezer', '❄️', '#4F46E5');

        el.innerHTML =
            '<div class="rounded-xl border p-4" style="background:#F8FAFC;border-color:#E2E8F0;cursor:pointer;"'
            + ' onclick="LC.openStock()">'
            + '<div class="flex items-center justify-between flex-wrap gap-3">'
            + '<div><div class="text-sm font-semibold text-gray-900">🧊 Already in our storage'
            + '<span style="color:#D97706;font-weight:700;margin-left:8px;font-size:12px;">Details ›</span></div>'
            + '<div class="text-xs text-gray-500 mt-0.5">'
            + (scope
                ? 'Only <strong>' + esc(scope) + '</strong> — subtract this from what you buy'
                : 'Every stored ' + esc(d.category) + ' packet right now — subtract this from what you buy')
            + '</div></div>'
            + '<div class="flex items-center gap-2 flex-wrap">'
            + (chips.trim()
                ? chips
                : '<span class="text-sm text-gray-500">'
                  + (scope ? 'Nothing stored for ' + esc(scope) : 'Nothing in the chiller or freezer')
                  + '</span>')
            + '</div></div></div>';
    }

    /** The per-product breakdown behind the storage card. */
    function renderStockModal() {
        const host = document.getElementById('lcStockBody');
        const title = document.getElementById('lcStockSub');
        if (!host) return;

        const items = stockItems();
        const scope = stockScopeLabel();
        title.innerHTML = (scope ? 'Showing <strong>' + esc(scope) + '</strong> only' : 'All ' + esc((state.data || {}).category || '') + ' stock')
            + (scope ? ' &nbsp;<span class="lc-crumb" onclick="LC.clearStockScope()">Show everything</span>' : '');

        if (!items.length) {
            host.innerHTML = '<div style="padding:22px;text-align:center;color:#9CA3AF;font-size:14px;">'
                + (scope ? 'Nothing stored for ' + esc(scope) : 'Nothing in the chiller or freezer') + '</div>';
            return;
        }

        host.innerHTML = ['chiller', 'freezer'].map(function (sec) {
            const rows = items.filter(i => i.section === sec);
            if (!rows.length) return '';
            const isChiller = sec === 'chiller';
            const colour = isChiller ? '#0369A1' : '#3730A3';
            const tot = rows.reduce(function (a, r) {
                a.packets += r.packets; a.kg += r.kg; a.pcs += r.pcs; return a;
            }, { packets: 0, kg: 0, pcs: 0 });
            const totBits = [];
            if (tot.packets) totBits.push(tot.packets + ' pkt');
            // ⚠ kg and pcs are separate units — never summed together
            if (tot.kg) totBits.push(num(tot.kg) + ' kg');
            if (tot.pcs) totBits.push(num(tot.pcs) + ' pcs');

            return '<div style="margin-bottom:16px;">'
                + '<div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #F3F4F6;">'
                    + '<span style="font-size:13px;font-weight:800;color:' + colour + ';">'
                    + (isChiller ? '🧊 Chiller' : '❄️ Freezer') + '</span>'
                    + '<span style="font-size:12px;font-weight:700;color:#374151;">' + esc(totBits.join(' · ')) + '</span>'
                + '</div>'
                + rows.map(function (r) {
                    const bits = [];
                    if (r.kg) bits.push(num(r.kg) + ' kg');
                    if (r.pcs) bits.push(num(r.pcs) + ' pcs');
                    return '<div style="display:flex;align-items:center;padding:9px 0;border-bottom:1px solid #F9FAFB;">'
                        + '<div style="flex:1;padding-right:8px;">'
                            + '<div style="font-size:13px;font-weight:600;color:#111827;">' + esc(r.product_name) + '</div>'
                            + '<div style="font-size:10px;color:#9CA3AF;margin-top:2px;">' + esc(r.attribute_2)
                            + (r.attribute_3 && r.attribute_3 !== r.attribute_2 ? ' › ' + esc(r.attribute_3) : '') + '</div>'
                        + '</div>'
                        + '<div style="text-align:right;">'
                            + '<div style="font-size:14px;font-weight:800;color:#111827;">' + r.packets + ' pkt</div>'
                            + '<div style="font-size:11px;color:#6B7280;">' + esc(bits.join(' · ')) + '</div>'
                        + '</div>'
                    + '</div>';
                }).join('')
                + '</div>';
        }).join('');
    }

    function openStock() {
        renderStockModal();
        document.getElementById('lcStockModal').style.display = 'flex';
    }

    function closeStock() {
        document.getElementById('lcStockModal').style.display = 'none';
    }

    function clearStockScope() {
        state.stockCtxKey = null;
        renderStorage();
        renderStockModal();
    }

    function renderNotices() {
        const el = document.getElementById('lcNotices');
        const d = state.data;
        if (!d) { el.innerHTML = ''; return; }
        const out = [];

        if (d.notices && d.notices.stale_open_orders > 0) {
            out.push(d.is_today
                ? '⚠️ <strong>' + d.notices.stale_open_orders + '</strong> open ' + esc(d.category)
                  + ' order(s) are older than ' + d.window_days + ' days, so they are not counted above.'
                : '⚠️ <strong>' + d.notices.stale_open_orders + '</strong> ' + esc(d.category)
                  + ' order(s) placed on this date never closed — they are still open today.');
        }
        el.innerHTML = out.length
            ? '<div class="rounded-xl border p-3 text-sm" style="background:#FFFBEB;border-color:#FDE68A;color:#92400E;">'
              + out.join('<br>') + '</div>'
            : '';
    }

    /**
     * Walk the stored drill path and return the node list to display.
     *
     * ⭐ The path holds INDICES, never names. Names would have to be interpolated
     *   into the row's onclick attribute, which puts product text straight into
     *   markup — and two siblings can legitimately share a name, which a
     *   name-keyed lookup would silently resolve to the wrong one.
     */
    function levelFor(section) {
        const path = state.paths[section.key] || [];
        let nodes = section.tree;
        const labels = [];
        const chain = [];
        for (const idx of path) {
            const hit = nodes[idx];
            if (!hit) return { nodes: section.tree, path: [], labels: [], chain: [] };
            labels.push(hit.name);
            chain.push(hit);
            nodes = hit.children || [];
        }
        return { nodes: nodes, path: path, labels: labels, chain: chain };
    }

    /** The drill path the storage card is currently following. */
    function stockChain() {
        if (!state.stockCtxKey || !state.data) return [];
        const section = (state.data.sections || []).find(s => s.key === state.stockCtxKey);
        if (!section) return [];
        return levelFor(section).chain;
    }

    /**
     * Stored packets narrowed to that path. A product node (or an order under it)
     * pins to that exact product; above that the attribute path is matched, using
     * the same 'Uncategorized' labels the tree uses.
     */
    function stockItems() {
        const items = (state.data && state.data.storage_items) || [];
        const chain = stockChain();
        if (!chain.length) return items;

        const productNode = chain.find(n => n.level === 'product');
        if (productNode) {
            return productNode.product_id
                ? items.filter(i => i.product_id === productNode.product_id)
                : [];
        }
        const a2 = (chain[0] && chain[0].level === 'attribute_2') ? chain[0].name : null;
        const a3 = (chain[1] && chain[1].level === 'attribute_3') ? chain[1].name : null;
        return items.filter(i => (!a2 || i.attribute_2 === a2) && (!a3 || i.attribute_3 === a3));
    }

    function stockTotals(items) {
        const blank = () => ({ packets: 0, kg: 0, pcs: 0 });
        const out = { chiller: blank(), freezer: blank() };
        items.forEach(function (i) {
            const sec = i.section === 'chiller' ? 'chiller' : 'freezer';
            out[sec].packets += i.packets;
            out[sec].kg += i.kg;
            out[sec].pcs += i.pcs;
        });
        return out;
    }

    function stockScopeLabel() {
        const chain = stockChain();
        return chain.length ? chain[chain.length - 1].name : null;
    }

    function renderSections() {
        const d = state.data;
        const host = document.getElementById('lcSections');
        if (!d) { host.innerHTML = ''; return; }

        host.innerHTML = d.sections.map(function (section) {
            const st = SECTION_STYLE[section.key] || SECTION_STYLE.open;
            const sum = section.summary;
            const lvl = levelFor(section);

            const crumbs = ['<span class="lc-crumb" onclick="LC.drillTo(\'' + section.key + '\', 0)">All</span>']
                .concat(lvl.labels.map(function (name, i) {
                    return '<span style="color:#9CA3AF;"> › </span>'
                        + '<span class="lc-crumb" onclick="LC.drillTo(\'' + section.key + '\', ' + (i + 1) + ')">'
                        + esc(name) + '</span>';
                })).join('');

            const rows = lvl.nodes.length ? lvl.nodes.map(function (n, rowIndex) {
                const leaf = !n.has_children;
                const stock = n.storage
                    ? (storageChip(n.storage.chiller, 'Chiller', '🧊', '#0EA5E9') + ' '
                       + storageChip(n.storage.freezer, 'Freezer', '❄️', '#4F46E5'))
                    : '';
                const sub = n.level === 'order'
                    ? esc(n.customer_name || '') + (n.is_dispatched ? ' · <span style="color:#2563EB;">dispatched</span>' : '')
                    : (n.order_count + ' order' + (n.order_count === 1 ? '' : 's')
                       + (n.product_count ? ' · ' + n.product_count + ' product' + (n.product_count === 1 ? '' : 's') : ''));

                // ⚠ Only the section key and a row INDEX ever reach the attribute —
                //   never product or customer text. Everything shown goes through esc().
                return '<tr class="lc-row' + (leaf ? ' lc-leaf' : '') + '"'
                    + (leaf ? '' : ' onclick="LC.drill(\'' + section.key + '\', ' + rowIndex + ')"')
                    + ' style="border-top:1px solid #F3F4F6;">'
                    + '<td style="padding:10px 12px;">'
                        + '<div style="font-weight:600;color:#111827;font-size:14px;">'
                        + (leaf ? '' : '<span style="color:#9CA3AF;margin-right:6px;">▸</span>')
                        + esc(n.name) + '</div>'
                        + '<div style="font-size:12px;color:#6B7280;margin-top:2px;">' + sub + '</div>'
                        + (stock.trim() ? '<div style="margin-top:6px;">' + stock + '</div>' : '')
                    + '</td>'
                    + '<td class="lc-num" style="padding:10px 12px;text-align:right;font-weight:700;color:#111827;">' + num(n.quantity) + '</td>'
                    + '<td class="lc-num" style="padding:10px 12px;text-align:right;color:#4B5563;">' + num(n.weight) + '</td>'
                    + '</tr>';
            }).join('') : '<tr><td colspan="3" style="padding:22px;text-align:center;color:#9CA3AF;font-size:14px;">Nothing here for this date</td></tr>';

            const dispatched = (typeof sum.dispatched_orders === 'number')
                ? '<div style="font-size:12px;color:' + st.text + ';margin-top:4px;">'
                  + sum.dispatched_orders + ' of ' + sum.orders + ' actually dispatched</div>'
                : '';

            const windowNote = section.key === 'open'
                ? '<div style="font-size:11px;color:#9CA3AF;margin-top:2px;">last ' + d.window_days + ' days</div>'
                : '';

            return '<div class="rounded-xl border mb-5" style="background:#fff;border-color:#E5E7EB;overflow:hidden;">'
                + '<div style="padding:14px 16px;background:' + st.bg + ';border-bottom:1px solid ' + st.border + ';">'
                    + '<div class="flex items-center justify-between flex-wrap gap-3">'
                        + '<div>'
                            + '<div style="font-size:16px;font-weight:700;color:' + st.text + ';">' + st.icon + ' ' + esc(section.title) + '</div>'
                            + '<div style="font-size:11px;color:#9CA3AF;margin-top:2px;">'
                                + esc((section.statuses || []).join(', ')) + '</div>'
                            + windowNote
                        + '</div>'
                        + '<div style="text-align:right;">'
                            + '<div class="lc-num" style="font-size:22px;font-weight:800;color:' + st.accent + ';">'
                                + num(sum.quantity) + '<span style="font-size:12px;font-weight:600;color:#6B7280;"> qty</span>'
                                + '<span style="margin-left:10px;">' + num(sum.weight) + '<span style="font-size:12px;font-weight:600;color:#6B7280;"> kg</span></span>'
                            + '</div>'
                            + '<div style="font-size:12px;color:' + st.text + ';">' + sum.orders + ' order' + (sum.orders === 1 ? '' : 's') + '</div>'
                            + dispatched
                        + '</div>'
                    + '</div>'
                    + '<div style="margin-top:8px;font-size:13px;">' + crumbs + '</div>'
                + '</div>'
                + '<table style="width:100%;border-collapse:collapse;">'
                    + '<thead><tr style="background:#FAFAFA;">'
                        + '<th style="padding:8px 12px;text-align:left;font-size:11px;text-transform:uppercase;color:#6B7280;letter-spacing:.04em;">Item</th>'
                        + '<th style="padding:8px 12px;text-align:right;font-size:11px;text-transform:uppercase;color:#6B7280;letter-spacing:.04em;">Qty</th>'
                        + '<th style="padding:8px 12px;text-align:right;font-size:11px;text-transform:uppercase;color:#6B7280;letter-spacing:.04em;">Kg</th>'
                    + '</tr></thead>'
                    + '<tbody>' + rows + '</tbody>'
                + '</table>'
                + '</div>';
        }).join('');
    }

    function renderPhotos() {
        const d = state.data;
        const host = document.getElementById('lcPhotos');
        const controls = document.getElementById('lcPhotoControls');
        const subtitle = document.getElementById('lcPhotoSubtitle');
        if (!d) { host.innerHTML = ''; controls.innerHTML = ''; return; }

        subtitle.textContent = 'Photos filed against ' + d.date
            + (d.is_today ? ' (today)' : '') + ' — ' + d.photos.length + ' on file';

        controls.innerHTML = d.can_edit_photos
            ? '<div class="flex items-center gap-2 flex-wrap">'
              + '<input type="text" id="lcNote" placeholder="Invoice no. (optional)" maxlength="255" '
              + 'class="px-3 py-2 text-sm rounded-lg border" style="border-color:#E5E7EB;width:190px;">'
              + '<input type="file" id="lcFiles" accept="image/*" multiple style="display:none;" onchange="LC.upload()">'
              + '<button type="button" onclick="document.getElementById(\'lcFiles\').click()" '
              + 'class="px-4 py-2 text-sm font-medium rounded-lg text-white" style="background:#D97706;">📷 Add photos</button>'
              + '</div>'
            : '<span class="text-xs" style="color:#92400E;background:#FFFBEB;border:1px solid #FDE68A;padding:6px 10px;border-radius:8px;">'
              + (d.is_today
                    ? 'You do not have permission to add photos'
                    : 'Only a manager can change an earlier date')
              + '</span>';

        host.innerHTML = d.photos.length
            ? '<div style="display:flex;flex-wrap:wrap;gap:12px;">' + d.photos.map(function (p, photoIndex) {
                // Per-photo right from the server (a rostered rider may only delete
                // their own upload); absent field → per-date flag, as before.
                var canDel = (p.can_delete !== undefined) ? p.can_delete : d.can_edit_photos;
                return '<div style="position:relative;">'
                    + '<img class="lc-thumb" src="' + esc(p.url) + '" alt="Invoice" onclick="LC.openLightbox(' + photoIndex + ')">'
                    + (canDel
                        ? '<button type="button" onclick="LC.removePhoto(' + p.id + ')" title="Remove"'
                          + ' style="position:absolute;top:-6px;right:-6px;width:24px;height:24px;border-radius:9999px;'
                          + 'background:#DC2626;color:#fff;border:2px solid #fff;font-size:13px;line-height:1;cursor:pointer;">×</button>'
                        : '')
                    + '<div style="font-size:10px;color:#6B7280;margin-top:4px;max-width:104px;">'
                        + (p.note ? esc(p.note) + '<br>' : '')
                        + esc(p.by_name || '') + '</div>'
                    + '</div>';
              }).join('') + '</div>'
            : '<div style="padding:22px;text-align:center;color:#9CA3AF;font-size:14px;border:1px dashed #E5E7EB;border-radius:10px;">'
              + 'No invoice photo for this date yet</div>';
    }

    function render() {
        renderStorage();
        renderNotices();
        renderSections();
        renderPhotos();

        // A rostered rider (no permission) is pinned to today by the server, so
        // the date controls are disabled rather than left to silently do nothing.
        const locked = state.data && state.data.access && state.data.access.can_change_date === false;
        const next = document.getElementById('lcNextBtn');
        const prev = document.getElementById('lcPrevBtn');
        const picker = document.getElementById('lcDate');
        if (next) next.disabled = locked || (state.date >= TODAY);
        if (prev) prev.disabled = !!locked;
        if (picker) picker.disabled = !!locked;
    }

    // ── data ──────────────────────────────────────────────────────────────
    function load() {
        state.loading = true;
        // ⚠ Response guard: a slow reply for an earlier date must never paint over
        //   a newer one. Same bug that produced "blank on fast drill" on the
        //   Quantities page.
        const seq = ++state.reqSeq;
        const url = BOARD_URL + '?date=' + encodeURIComponent(state.date);

        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(function (json) {
                if (seq !== state.reqSeq) return;
                if (!json || !json.success) throw new Error((json && json.message) || 'Could not load');
                state.data = json;
                state.paths = {};
                state.stockCtxKey = null;
                render();
            })
            .catch(function (e) {
                if (seq !== state.reqSeq) return;
                document.getElementById('lcSections').innerHTML =
                    '<div class="rounded-xl border p-4 text-sm" style="background:#FEF2F2;border-color:#FECACA;color:#991B1B;">'
                    + 'Could not load the board: ' + esc(e.message) + '</div>';
            })
            .finally(function () { if (seq === state.reqSeq) state.loading = false; });
    }

    // ── actions ───────────────────────────────────────────────────────────
    function setDate(ymd) {
        if (!ymd) return;
        if (ymd > TODAY) ymd = TODAY;
        state.date = ymd;
        const input = document.getElementById('lcDate');
        if (input) input.value = ymd;
        load();
    }

    function shiftDay(delta) {
        const d = new Date(state.date + 'T12:00:00'); // midday: immune to DST/timezone edges
        d.setDate(d.getDate() + delta);
        const ymd = d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
        setDate(ymd);
    }

    // ⭐ Drilling also narrows the storage card: at the supplier the question is
    //   "how much of THIS do we already have". Sections drill independently, so the
    //   card follows the section drilled into most recently; returning that section
    //   to its root clears the filter.
    function drill(sectionKey, index) {
        state.paths[sectionKey] = (state.paths[sectionKey] || []).concat([index]);
        state.stockCtxKey = sectionKey;
        renderSections();
        renderStorage();
    }

    function drillTo(sectionKey, depth) {
        state.paths[sectionKey] = (state.paths[sectionKey] || []).slice(0, depth);
        state.stockCtxKey = depth === 0 ? null : sectionKey;
        renderSections();
        renderStorage();
    }

    function upload() {
        const input = document.getElementById('lcFiles');
        if (!input || !input.files || !input.files.length) return;
        if (input.files.length > 8) {
            alert('Eight photos at a time — send these first, then add the rest.');
            input.value = '';
            return;
        }

        const form = new FormData();
        for (let i = 0; i < input.files.length; i++) form.append('photos[]', input.files[i]);
        form.append('date', state.date);
        const note = document.getElementById('lcNote');
        if (note && note.value.trim()) form.append('note', note.value.trim());

        fetch(UPLOAD_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: form,
        })
            .then(r => r.json().then(j => ({ ok: r.ok, j: j })))
            .then(function (res) {
                if (!res.j || !res.j.success) throw new Error((res.j && res.j.message) || 'Upload failed');
                // The server hands back the refreshed strip — use it rather than
                // re-fetching, so what is shown is exactly what was saved.
                state.data.photos = res.j.photos;
                state.data.can_edit_photos = res.j.can_edit_photos;
                renderPhotos();
                if (note) note.value = '';
            })
            .catch(function (e) { alert(e.message); })
            .finally(function () { input.value = ''; });
    }

    function removePhoto(id) {
        if (!confirm('Remove this invoice photo?')) return;
        fetch(DELETE_BASE + '/' + id + '/delete', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        })
            .then(r => r.json())
            .then(function (j) {
                if (!j || !j.success) throw new Error((j && j.message) || 'Could not remove');
                state.data.photos = j.photos;
                state.data.can_edit_photos = j.can_edit_photos;
                renderPhotos();
            })
            .catch(function (e) { alert(e.message); });
    }

    function openLightbox(index) {
        const photo = (state.data && state.data.photos) ? state.data.photos[index] : null;
        if (!photo) return;
        document.getElementById('lcLightboxImg').src = photo.url;
        document.getElementById('lcLightbox').style.display = 'flex';
    }

    function closeLightbox() {
        document.getElementById('lcLightbox').style.display = 'none';
        document.getElementById('lcLightboxImg').src = '';
    }

    document.addEventListener('DOMContentLoaded', load);

    return {
        setDate: setDate,
        shiftDay: shiftDay,
        drill: drill,
        drillTo: drillTo,
        upload: upload,
        removePhoto: removePhoto,
        openLightbox: openLightbox,
        closeLightbox: closeLightbox,
        openStock: openStock,
        closeStock: closeStock,
        clearStockScope: clearStockScope,
    };
})();
</script>
@endpush
