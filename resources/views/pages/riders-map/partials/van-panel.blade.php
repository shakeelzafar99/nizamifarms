{{--
    🚚 Van panel (Aug-2026) — what the store watches while the van is out.

    Sits above the live riders grid and renders NOTHING when no van is out, so a
    day without the van looks exactly as it always did. Self-contained: own
    markup, .vp-* styles, vp* state — the same discipline as the Bikes tab.

    Reads /orders/van/panel, which is the SAME controller the mobile store panel
    calls, so the two surfaces cannot word the van's state differently.
--}}

{{-- ⚠ The panel CONTAINER (#vanPanel) is NOT here — it lives inside the live
     riders view in index.blade.php. This partial is included at PAGE level so the
     modal below is never a descendant of a hidden view: a position:fixed element
     inside a display:none parent does not render at all, which would have made
     "📍 Meet-up points" silently do nothing from the Bikes tab. --}}

{{-- ⚙ Meet-up stops manager. Inline-styled shell on purpose — this page's CSS is
     purged of the legacy utility classes, so a class-based modal renders top-left
     and will not scroll (see the metronic-v9 note). --}}
{{-- 🗺 Pin picker — same behaviour as the office-location picker (drag the
     marker or tap the map). Sits ABOVE the stops modal, page level like it. --}}
<div id="vpMapModal"
     style="display:none;position:fixed;inset:0;z-index:4400;background:rgba(0,0,0,.6);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:720px;
              box-shadow:0 18px 60px rgba(0,0,0,.4);overflow:hidden;">
    <div style="display:flex;align-items:center;gap:9px;padding:13px 17px;border-bottom:1px solid #e5e7eb;">
      <b id="vpMapTitle" style="font-size:15px;color:#111827;">🗺 Pin the stop</b>
      <button type="button" onclick="vpCloseMap()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div id="vpMapCanvas" style="width:100%;height:min(58vh,430px);background:#eef2f5;"></div>
    <div style="display:flex;align-items:center;gap:10px;padding:12px 17px;border-top:1px solid #e5e7eb;">
      <span id="vpMapCoords" style="font-size:12px;color:#6b7280;">Drag the pin, or tap the map.</span>
      <button type="button" onclick="vpCloseMap()"
              style="margin-left:auto;padding:8px 14px;border:1px solid #d1d5db;border-radius:8px;
                     background:#fff;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
      <button type="button" onclick="vpSaveMapPin()" id="vpMapSaveBtn"
              style="padding:8px 16px;border:none;border-radius:8px;background:#0f766e;color:#fff;
                     font-size:13px;font-weight:700;cursor:pointer;">Save pin</button>
    </div>
  </div>
</div>

<div id="vpStopsModal" onclick="if(event.target===this)vpCloseStops()"
     style="display:none;position:fixed;inset:0;z-index:4300;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b style="font-size:15px;color:#111827;">📍 Meet-up points</b>
      <button type="button" onclick="vpCloseStops()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <div style="font-size:11.5px;color:#6b7280;margin-bottom:11px;line-height:1.5;">
        The places riders collect their orders from the van. A stop can be just a
        <b>name</b> — it learns where it is the first time a driver stops there and
        sends his location. Retired stops stay on past trips.
      </div>

      <div id="vpStopsList" style="font-size:13px;">Loading…</div>

      <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Add a stop
        </label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:2;min-width:170px;">
            <input id="vpStopName" type="text" maxlength="100" placeholder="e.g. Frozen Shop turning"
                   style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          </div>
          {{-- ⭐ The pin is picked ON A MAP, not typed. Raw latitude/longitude
               boxes were a transcription error waiting to happen, and nobody
               knows a stop's coordinates by heart. Leaving it unpinned is still
               fine — the pin saves itself the first time the van stops there. --}}
          <input id="vpStopLat" type="hidden">
          <input id="vpStopLng" type="hidden">
          <button type="button" onclick="vpPickForNew()" id="vpStopPickBtn"
                  style="padding:8px 13px;border:1px solid #0f766e;border-radius:8px;background:#fff;
                         color:#0f766e;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap;">
            🗺 Pick on map</button>
          <button type="button" onclick="vpSaveStop()" id="vpStopSaveBtn"
                  style="padding:8px 15px;border:none;border-radius:8px;background:#0f766e;color:#fff;
                         font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">Add</button>
        </div>
        <div id="vpNewPinNote" style="display:none;font-size:11.5px;color:#0f766e;margin-top:6px;"></div>
        <div id="vpStopsError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
             border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:8px;"></div>
      </div>
    </div>
  </div>
</div>

<style>
/* ---------- Van panel (scoped .vp-*) ---------- */
.vp-wrap{margin:0 0 14px;}
.vp-card{border:1px solid #99f6e4;border-radius:12px;background:linear-gradient(180deg,#f0fdfa 0%,#fff 60%);
         padding:13px 15px;margin-bottom:10px;}
.vp-head{display:flex;align-items:flex-start;gap:10px;}
.vp-title{font-size:15px;font-weight:700;color:#0f766e;line-height:1.3;}
.vp-sub{font-size:12px;color:#6b7280;margin-top:2px;}
.vp-gear{margin-left:auto;border:1px solid #d1d5db;background:#fff;border-radius:7px;padding:4px 9px;
         font-size:12px;cursor:pointer;color:#374151;white-space:nowrap;}
.vp-gear:hover{background:#f3f4f6;}
.vp-strip{display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid #ccfbf1;}
.vp-stat{font-size:12.5px;color:#374151;}
.vp-stat b{color:#111827;font-variant-numeric:tabular-nums;}
.vp-stat .lab{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;font-weight:700;}
.vp-riders{margin-top:11px;}
.vp-riders h5{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;
              color:#6b7280;margin:0 0 6px;}
.vp-rider{display:flex;align-items:center;gap:9px;padding:6px 9px;background:#fff;border:1px solid #e5e7eb;
          border-radius:8px;margin-bottom:5px;font-size:12.5px;flex-wrap:wrap;}
.vp-rider.done{opacity:.6;}
.vp-rname{font-weight:700;color:#111827;min-width:120px;}
.vp-rmeta{color:#6b7280;}
.vp-reta{margin-left:auto;font-weight:700;color:#0f766e;white-space:nowrap;}
.vp-reta.approx{color:#b45309;font-weight:600;}
.vp-pill{display:inline-block;padding:1px 8px;border-radius:20px;font-size:10.5px;font-weight:700;}
.vp-pill.wait{background:#fef3c7;color:#92400e;}
.vp-pill.ok{background:#dcfce7;color:#166534;}
.vp-pill.adhoc{background:#e0e7ff;color:#3730a3;}
.vp-empty{font-size:12px;color:#9ca3af;}
.vp-strow{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12.5px;}
.vp-strow:last-child{border-bottom:none;}
.vp-sbtn{border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:3px 9px;font-size:11.5px;
         cursor:pointer;color:#374151;}
.vp-sbtn:hover{background:#f3f4f6;}
.vp-sbtn.danger{color:#b91c1c;border-color:#fecaca;}

/* ---------- the day's spine, and the order lists (Aug-2026) ---------- */
/* Every timestamp below was already recorded by the scans; this only stops the
   web throwing it away. Wraps rather than scrolls — it is read, not scrubbed. */
.vp-line{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:10px;padding-top:9px;
         border-top:1px solid #ccfbf1;font-size:11.5px;color:#4b5563;}
.vp-ev{background:#fff;border:1px solid #e5e7eb;border-radius:20px;padding:2px 9px;white-space:nowrap;}
.vp-ev b{color:#111827;font-variant-numeric:tabular-nums;font-weight:700;}
.vp-ev.now{background:#fffbeb;border-color:#fcd34d;color:#92400e;}
.vp-ev.done{background:#f0fdf4;border-color:#bbf7d0;color:#166534;}
.vp-arrow{color:#cbd5e1;}

.vp-groups{margin-top:11px;}
.vp-grp{border:1px solid #e5e7eb;border-radius:9px;background:#fff;margin-bottom:6px;overflow:hidden;}
.vp-ghead{display:flex;align-items:center;gap:8px;padding:7px 10px;cursor:pointer;font-size:12.5px;
          font-weight:700;color:#111827;user-select:none;}
.vp-ghead:hover{background:#f9fafb;}
/* `flex:1` on the count is what right-aligns everything after it, so the row
   lays out correctly whether or not the optional ETA/stale slot is present. */
.vp-gcount{font-weight:600;color:#6b7280;font-size:11.5px;flex:1;}
.vp-geta{font-weight:700;color:#0f766e;font-size:11.5px;white-space:nowrap;}
.vp-geta.approx{color:#b45309;font-weight:600;}
.vp-gchev{color:#9ca3af;font-size:11px;}
.vp-glist{border-top:1px solid #f1f5f9;}
.vp-orow{display:flex;align-items:center;gap:9px;padding:5px 10px;font-size:12px;
         border-bottom:1px solid #f8fafc;}
.vp-orow:last-child{border-bottom:none;}
.vp-ono{font-weight:700;color:#111827;min-width:78px;}
.vp-ocust{color:#6b7280;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.vp-ostate{font-size:10.5px;font-weight:700;padding:1px 8px;border-radius:20px;white-space:nowrap;}
.vp-ostate.wait{background:#fef3c7;color:#92400e;}
.vp-ostate.ok{background:#dcfce7;color:#166534;}
.vp-ostate.stale{background:#fee2e2;color:#991b1b;}
.vp-otime{color:#9ca3af;font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap;}
/* Abandoned meet-up — same visual weight as a meter / verified-pin bypass. */
.vp-bypass{background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:8px 11px;
           margin-top:10px;font-size:12px;font-weight:700;color:#991b1b;line-height:1.45;}
</style>

<script>
/* ═══ Van panel — the store's view of a van that is out ═══ */
let vpData = null;
let vpTimer = null;
let vpCanManage = false;

/* ⚠⚠ COLLAPSE STATE LIVES OUT HERE, NOT IN THE MARKUP. The card is rebuilt from
   scratch by innerHTML every 30s poll, so anything held in the DOM (a <details>
   `open`, a CSS class) is wiped four times a minute — a manager reading a rider's
   order list would have it snap shut under him. Keyed by van + group so two vans
   never share a toggle. Absent key = use the caller's default. */
let vpGrpState = {};
function vpGrpIsOpen(key, dflt) {
    return Object.prototype.hasOwnProperty.call(vpGrpState, key) ? vpGrpState[key] : dflt;
}
function vpToggleGrp(key, dflt) {
    vpGrpState[key] = !vpGrpIsOpen(key, dflt);
    vpRender();   // repaint from cached data — a toggle must never wait on a fetch
}

const VP_URL   = '/orders/van/panel';
const VP_STOPS = '/orders/van/stops';

function vpStart() {
    vpLoad();
    if (vpTimer) clearInterval(vpTimer);
    // Same cadence as the riders board — the panel is a companion to it.
    vpTimer = setInterval(vpLoad, 30000);
}
function vpStop() { if (vpTimer) { clearInterval(vpTimer); vpTimer = null; } }

function vpLoad() {
    fetch(VP_URL, {headers: {'Accept': 'application/json'}})
        .then(r => r.ok ? r.json() : {success: false})
        .then(res => {
            const el = document.getElementById('vanPanel');
            if (!el) return;
            // No van out (or the feature isn't set up) → render nothing at all.
            if (!res.success || !res.available || !(res.vans || []).length) {
                el.style.display = 'none';
                el.innerHTML = '';
                vpData = null;
                return;
            }
            vpData = res;
            el.style.display = '';
            vpRender();
        })
        .catch(() => { /* the panel is a companion view — never break the board */ });
}

/* Paint from whatever we last received. Separate from vpLoad so a collapse
   toggle repaints instantly instead of waiting out the poll. */
function vpRender() {
    const el = document.getElementById('vanPanel');
    if (!el || !vpData) return;
    el.innerHTML = (vpData.vans || []).map(vpCard).join('');
}

function vpCard(v) {
    const stop = v.stop || null;
    const t = v.totals || {};
    const waiting = stop && stop.reached_at;

    // Stats read differently per mode — show only what is true right now.
    let stats = '';
    // ⭐ Shown on the DELIVERING leg too, not just once he is formally heading
    //    there: when he delivers a few of his own stops first, "when does the van
    //    actually arrive" is the number the whole meet-up is planned around.
    if (v.van_eta) {
        stats += vpStat('Van', vpEta(v.van_eta)
            + (v.van_eta.source === 'approx' ? ' <span style="color:#b45309;">(est)</span>' : ''));
    }
    if (waiting && stop.waiting_minutes !== null) {
        stats += vpStat('Waiting', stop.waiting_minutes + ' min');
    }
    if (v.trip && v.trip.departed_at) {
        stats += vpStat('Left', vpWhen(v.trip.departed_at));
    }
    // ⭐ "WHEN IS HE BACK?" — the server only sends this once he is genuinely on
    //    his way in (not while heading to a rendezvous, not while still carrying
    //    somebody's boxes), so if it is here it can be shown without hedging.
    if (v.return_eta) {
        stats += vpStat('Back at', vpEsc(v.return_eta.arrival_display || (v.return_eta.minutes + ' min'))
            + (v.return_eta.source === 'approx' ? ' <span style="color:#b45309;">(est)</span>' : ''));
    }
    const onBoard = (t.mine_on_van || 0) + (t.carried_total || 0) - (t.carried_handed || 0);
    stats += vpStat('On board', onBoard);
    // ⭐ Tagged "On Van" (the staff's plan) but not scanned aboard yet.
    if (t.to_load) {
        stats += vpStat('To scan aboard', '<span style="color:#b45309;">' + t.to_load + '</span>'
            + (t.to_load_stale ? ' <span style="color:#b91c1c;font-size:11px;">' + t.to_load_stale + ' stale</span>' : ''));
    }
    if (t.carried_total) {
        stats += vpStat('Handed over', (t.carried_handed || 0) + ' of ' + t.carried_total);
    }
    // ⭐ The card now appears as soon as the van is ASSIGNED, before the first
    //    box is scanned aboard — so say so plainly rather than showing a bare 0.
    const nothingYet = onBoard === 0 && !(t.to_load) && !(v.trip && v.trip.departed_at);

    // Who is still to collect. Riders drop off as they finish scanning.
    let riders = '';
    if ((v.inbound || []).length) {
        riders = '<div class="vp-riders"><h5>Waiting to collect</h5>' + v.inbound.map(r => {
            const eta = r.eta
                ? '<span class="vp-reta' + (r.eta.source === 'approx' ? ' approx' : '') + '">'
                  + vpEta(r.eta) + '</span>'
                // ⚠ "no GPS" was claimed whenever the ETA was absent — including
                //   when no meet-up point is set yet, or the fix failed the
                //   60 km sanity cap. The payload says which; use it.
                : '<span class="vp-reta approx">' + (r.has_gps ? 'no ETA yet' : 'no GPS') + '</span>';
            return '<div class="vp-rider">'
                 + '<span class="vp-rname">👤 ' + vpEsc(r.name) + '</span>'
                 + '<span class="vp-rmeta">' + r.orders + ' order' + (r.orders === 1 ? '' : 's')
                 + ' · ' + r.packets + ' packet' + (r.packets === 1 ? '' : 's')
                 + (r.handed ? ' · ' + r.handed + ' collected' : '') + '</span>'
                 + eta + '</div>';
        }).join('') + '</div>';
    } else if (v.mode === 'to_stop') {
        riders = '<div class="vp-riders"><h5>Waiting to collect</h5>'
               + '<div class="vp-empty">Everyone has collected their orders.</div></div>';
    }

    const stopLine = stop
        ? '<span class="vp-pill ' + (waiting ? 'wait' : 'ok') + '">'
          + (waiting ? '🅿️ at the stop' : '➡️ on the way') + '</span>'
          + (stop.is_adhoc ? ' <span class="vp-pill adhoc">one-off spot</span>' : '')
        : '';

    /* ⚠️ ABANDONED MEET-UP — the driver drove off while somebody still had boxes
       aboard. Surfaced like a meter / verified-pin bypass so the store learns of
       it from the board it is already watching, not from tomorrow's report. */
    const forced = (v.forced_closes || []).map(fc =>
        '<div class="vp-bypass">⚠️ Meet-up “' + vpEsc(fc.label) + '” closed at '
        + vpWhen(fc.completed_at) + ' with cargo still on the van'
        + (fc.note ? ' — ' + vpEsc(String(fc.note).replace(/^Closed with cargo still aboard: /, '')) : '')
        + '</div>').join('');

    return '<div class="vp-card">'
         +   '<div class="vp-head">'
         +     '<div style="min-width:0;">'
         +       '<div class="vp-title">🚚 ' + vpEsc(v.headline) + '</div>'
         +       '<div class="vp-sub">' + stopLine + '</div>'
         +     '</div>'
         +     '<button type="button" class="vp-gear" onclick="vpOpenStops()">⚙ Meet-up points</button>'
         +   '</div>'
         +   '<div class="vp-strip">' + stats + '</div>'
         +   forced
         +   vpTimeline(v)
         +   (nothingYet
               ? '<div class="vp-riders"><div class="vp-empty">Nothing loaded yet — '
                 + 'the store scans each package onto the van, or the driver does it himself.</div></div>'
               : '')
         +   riders
         +   vpGroups(v)
         + '</div>';
}

/* ═══ THE DAY'S SPINE (Aug-2026) ══════════════════════════════════════════
   loaded → left → each stop → each rider's collection. Managers could see THAT
   a handover had happened but never WHEN, so a meet-up running to plan and one
   an hour behind looked identical on this board until Day Review the next day.

   Every timestamp is already recorded by the scans and the stop presses — this
   only renders them. Built as a sorted event list rather than fixed slots so an
   out-of-order day (a rider collecting before the driver presses "I'm here")
   still reads truthfully instead of pretending a sequence that did not happen. */
function vpTs(s) {
    if (!s) return null;
    const d = new Date(String(s).replace(' ', 'T'));
    return isNaN(d) ? null : d.getTime();
}

function vpTimeline(v) {
    const t = v.totals || {};
    const ev = [];
    const push = (at, html, cls) => {
        const ms = vpTs(at);
        if (ms !== null) ev.push({ms: ms, html: html, cls: cls || ''});
    };

    push(t.first_loaded_at, 'loaded <b>' + vpWhen(t.first_loaded_at) + '</b>');
    if (v.trip && v.trip.departed_at) {
        push(v.trip.departed_at, 'left <b>' + vpWhen(v.trip.departed_at) + '</b>');
    }

    (v.trip_stops || []).forEach(s => {
        const label = vpEsc(s.label);
        if (s.reached_at) {
            push(s.reached_at, '🅿️ ' + label + ' <b>' + vpWhen(s.reached_at) + '</b>');
        } else if (s.set_at) {
            // Named but not reached — the only forward-looking chip on the line.
            push(s.set_at, '➡️ ' + label + ' <b>' + vpWhen(s.set_at) + '</b>', 'now');
        }
        if (s.completed_at) {
            push(s.completed_at, '✓ ' + label + ' done <b>' + vpWhen(s.completed_at) + '</b>', 'done');
        }
    });

    (v.carrying || []).forEach(g => {
        const name = vpEsc(g.name);
        if (g.complete && g.last_handover_at) {
            push(g.last_handover_at, '✓ ' + name + ' <b>' + vpWhen(g.last_handover_at) + '</b>', 'done');
        } else if (g.first_handover_at) {
            // Started collecting but not finished — say so rather than call it done.
            push(g.first_handover_at, '⏳ ' + name + ' ' + (g.handed || 0) + '/' + (g.total || 0)
                 + ' <b>' + vpWhen(g.first_handover_at) + '</b>', 'now');
        }
    });

    if (!ev.length) return '';
    ev.sort((a, b) => a.ms - b.ms);
    return '<div class="vp-line">'
         + ev.map(e => '<span class="vp-ev ' + e.cls + '">' + e.html + '</span>')
             .join('<span class="vp-arrow">›</span>')
         + '</div>';
}

/* ═══ THE ORDER LISTS (Aug-2026) ══════════════════════════════════════════
   The web card showed only COUNTS while the mobile store board had listed every
   order number since Aug-4 — same endpoint, half the answer. "On board: 6" does
   not tell a manager WHICH six, which is the question actually being asked when
   somebody rings about an order.

   ⭐ OPEN BY DEFAULT WHILE THERE IS SOMETHING LIVE IN THEM, collapsed once the
   work is done. Defaulting everything closed reintroduced the original problem
   in a new costume — the manager still had to hunt for the order numbers, one
   click per group. A rider who has collected everything folds away; one still
   owed boxes stays open. Whatever he clicks wins and survives the poll. */
function vpGroups(v) {
    const vid = v.driver_user_id;
    let out = '';

    const toLoad = v.to_load || [];
    if (toLoad.length) {
        const stale = toLoad.filter(o => o.is_stale).length;
        out += vpGrp(vid, 'toload', '📦 To scan aboard', toLoad.length,
            stale ? '<span class="vp-ostate stale">' + stale + ' stale</span>' : '',
            true,
            toLoad.map(o => vpORow(
                o.order_number,
                o.rider_name ? 'for ' + o.rider_name : (o.customer_name || ''),
                o.van_loaded_count > 0
                    ? (o.van_loaded_count + '/' + o.expected_packets + ' scanned')
                    : 'to scan',
                o.is_stale ? 'stale' : 'wait',
                // A stale row says HOW old it is — that is the whole actionable
                // bit, so it must carry the DATE, not a clock time (see vpWhen).
                o.is_stale ? ('tagged ' + vpWhen(o.tagged_at)) : ''
            )).join(''));
    }

    const mine = v.mine || [];
    if (mine.length) {
        // ⭐ ALWAYS OPEN. His own stops are the one group nobody else on this page
        //    is tracking, and on a day when the van carries nothing for anybody
        //    else they are the entire content of the card. Folding them once he
        //    dispatches would blank the board at exactly the moment the owner
        //    asked to keep watching ("after he presses dispatch the van should
        //    still show what's happening, since it's being driven"). The list
        //    shrinks by itself as stops are delivered — a delivered order leaves
        //    the manifest — so it can never grow into clutter.
        out += vpGrp(vid, 'mine', '🏠 ' + vpEsc(v.driver_name || 'Driver') + "'s own", mine.length,
            '', true,
            mine.map(o => vpORow(
                o.order_number, o.customer || '',
                o.status === 'on_van' ? 'on the van' : (o.dispatched ? 'delivering' : 'out for delivery'),
                o.status === 'on_van' ? 'wait' : 'ok',
                o.dispatched_at ? vpWhen(o.dispatched_at) : ''
            )).join(''));
    }

    (v.carrying || []).forEach(g => {
        const inb = (v.inbound || []).find(r => Number(r.user_id) === Number(g.user_id));
        const eta = (!g.complete && inb && inb.eta)
            ? '<span class="vp-geta' + (inb.eta.source === 'approx' ? ' approx' : '') + '">'
              + vpEta(inb.eta) + '</span>'
            : '';
        out += vpGrp(vid, 'r' + g.user_id, (g.complete ? '✅ ' : '⏳ ') + vpEsc(g.name),
            (g.handed || 0) + '/' + (g.total || 0), eta, !g.complete,
            (g.orders || []).map(o => vpORow(
                o.order_number, o.customer || '',
                o.handed_over ? 'collected' : (o.dispatched ? 'delivering' : 'on the van'),
                o.handed_over ? 'ok' : 'wait',
                o.handover_at ? vpWhen(o.handover_at) : ''
            )).join(''));
    });

    return out ? '<div class="vp-groups">' + out + '</div>' : '';
}

function vpGrp(vid, key, title, count, right, dflt, rows) {
    const k = vid + ':' + key;
    const open = vpGrpIsOpen(k, dflt);
    return '<div class="vp-grp">'
         +   '<div class="vp-ghead" onclick="vpToggleGrp(' + vpJs(k) + ',' + (dflt ? 'true' : 'false') + ')">'
         +     '<span>' + title + '</span>'
         +     '<span class="vp-gcount">' + count + '</span>'
         +     (right || '')
         +     '<span class="vp-gchev">' + (open ? '▾' : '▸') + '</span>'
         +   '</div>'
         +   (open ? '<div class="vp-glist">' + rows + '</div>' : '')
         + '</div>';
}

function vpORow(no, cust, state, tone, time) {
    return '<div class="vp-orow">'
         +   '<span class="vp-ono">' + vpEsc(no) + '</span>'
         +   '<span class="vp-ocust">' + vpEsc(cust) + '</span>'
         +   (time ? '<span class="vp-otime">' + vpEsc(time) + '</span>' : '')
         +   '<span class="vp-ostate ' + tone + '">' + vpEsc(state) + '</span>'
         + '</div>';
}

function vpStat(label, value) {
    return '<div class="vp-stat"><span class="lab">' + label + '</span><b>' + value + '</b></div>';
}

/* ── the ⚙ stops manager ─────────────────────────────────────────────── */
function vpOpenStops() {
    document.getElementById('vpStopsModal').style.display = 'flex';
    document.getElementById('vpStopsError').style.display = 'none';
    // Opened from the Bikes tab, the panel may never have polled — without this
    // the "Keep this spot" (promote ad-hoc) section could not appear because
    // vpData was still null. One cheap fetch; the modal renders either way.
    if (!vpData) {
        fetch(VP_URL, {headers: {'Accept': 'application/json'}})
            .then(r => r.ok ? r.json() : null)
            .then(res => { if (res && res.success && res.available) vpData = res; })
            .catch(() => {})
            .finally(vpLoadStops);
    } else {
        vpLoadStops();
    }
}
function vpCloseStops() { document.getElementById('vpStopsModal').style.display = 'none'; }

/* A name safe to drop into an inline onclick — quotes and all. */
function vpJs(s) { return JSON.stringify(String(s == null ? '' : s)).replace(/"/g, '&quot;'); }

function vpLoadStops() {
    fetch(VP_STOPS + '?include_inactive=1', {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(res => {
            vpCanManage = !!res.can_manage;
            const body = document.getElementById('vpStopsList');
            const list = res.presets || [];

            let html = list.length
                ? list.map(s =>
                    '<div class="vp-strow' + (s.is_active ? '' : ' ')
                    + '" style="' + (s.is_active ? '' : 'opacity:.5;') + '">'
                    + '<b style="min-width:150px;">' + vpEsc(s.name) + '</b>'
                    + '<span style="color:#6b7280;">'
                    + (s.has_pin ? '📍 located' : '<span style="color:#b45309;">no location yet</span>')
                    + (s.is_active ? '' : ' · retired') + '</span>'
                    + (vpCanManage && s.is_active
                        ? '<span style="margin-left:auto;display:flex;gap:6px;">'
                          // 🗺 pin it on a map — never by typing coordinates
                          + '<button type="button" class="vp-sbtn"'
                          + ' onclick="vpMapName=' + vpJs(s.name) + ';vpOpenMap(' + s.id + ',' + vpJs(s.name)
                          + ',' + (s.latitude === null ? 'null' : s.latitude)
                          + ',' + (s.longitude === null ? 'null' : s.longitude) + ')">'
                          + (s.has_pin ? '🗺 Change pin' : '🗺 Set pin') + '</button>'
                          // 📍 send a van here — only meaningful once it has a pin
                          + (s.has_pin
                              ? '<button type="button" class="vp-sbtn"'
                                + ' onclick="vpSendVan(' + s.id + ',' + vpJs(s.name) + ')">📍 Send van</button>'
                              : '')
                          + '<button type="button" class="vp-sbtn danger"'
                          + ' onclick="vpRetireStop(' + s.id + ')">Retire</button>'
                          + '</span>'
                        : '')
                    // ⭐ Retiring used to be a ONE-WAY DOOR: the manage buttons only
                    //    rendered for active stops, and the duplicate-name check counts
                    //    retired rows too — so a retired point could neither be brought
                    //    back nor re-added under its own name, ever.
                    + (vpCanManage && !s.is_active
                        ? '<span style="margin-left:auto;">'
                          + '<button type="button" class="vp-sbtn"'
                          + ' onclick="vpUnretireStop(' + s.id + ',' + vpJs(s.name) + ')">↩ Bring back</button>'
                          + '</span>'
                        : '')
                    + '</div>').join('')
                : '<div class="vp-empty">No meet-up points yet — add the first one below.</div>';

            // One-off spots used on today's trip can be kept for next time.
            const adhoc = [];
            ((vpData && vpData.vans) || []).forEach(v => (v.trip_stops || []).forEach(s => {
                if (s.can_promote) adhoc.push(s);
            }));
            if (adhoc.length && vpCanManage) {
                html += '<div style="margin-top:12px;padding-top:10px;border-top:1px dashed #e5e7eb;">'
                      + '<div style="font-size:11px;color:#6b7280;margin-bottom:6px;">'
                      + 'One-off spots used today — keep one and it becomes a permanent stop:</div>'
                      + adhoc.map(s =>
                          '<div class="vp-strow"><b style="min-width:150px;">' + vpEsc(s.label) + '</b>'
                          + '<span class="vp-pill adhoc">one-off</span>'
                          + '<button type="button" class="vp-sbtn" style="margin-left:auto;"'
                          // ⚠ vpJs, NOT vpEsc + a quote-replace. vpEsc runs first and
                          //   turns every ' into &#039;, so the .replace() that followed
                          //   matched nothing — and the browser then DECODED the entity
                          //   back into a live quote inside this JS string. A label like
                          //   "McDonald's corner" broke the button outright, and a
                          //   driver-typed ad-hoc label could run script in a manager's
                          //   session. vpJs JSON-encodes first, then escapes the quotes.
                          + ' onclick="vpPromote(' + s.id + ',' + vpJs(s.label) + ')">'
                          + '💾 Keep this spot</button></div>').join('')
                      + '</div>';
            }

            body.innerHTML = html;
            document.getElementById('vpStopSaveBtn').style.display = vpCanManage ? '' : 'none';
        })
        .catch(() => {
            document.getElementById('vpStopsList').innerHTML =
                '<div class="vp-empty">Could not load the stops.</div>';
        });
}

/* ── 🗺 the pin picker ─────────────────────────────────────────────────────
   Mirrors the office-location picker (attendance/locations): drag the marker or
   tap the map. `vpMapFor` is a stop id when re-pinning an existing stop, or the
   string 'new' when picking a location for the stop being added. */
let vpMap = null, vpMapMarker = null, vpMapPick = null, vpMapFor = null, vpMapName = '';

function vpOpenMap(stopId, name, lat, lng) {
    vpMapFor = stopId;
    vpMapPick = (lat != null && lng != null) ? {lat: +lat, lng: +lng} : null;
    document.getElementById('vpMapTitle').textContent = '🗺 ' + (name || 'Pin the stop');
    document.getElementById('vpMapCoords').textContent = vpMapPick
        ? vpMapPick.lat.toFixed(5) + ', ' + vpMapPick.lng.toFixed(5)
        : 'Drag the pin, or tap the map.';
    document.getElementById('vpMapModal').style.display = 'flex';

    // Default view: the office, so a fresh pin starts somewhere recognisable.
    const start = vpMapPick || {lat: 33.70811597, lng: 73.08868750};
    const boot = () => setTimeout(() => vpInitMap(start), 80);
    if (window.google && window.google.maps) boot();
    else vpLoadMaps(boot);
}
function vpCloseMap() { document.getElementById('vpMapModal').style.display = 'none'; }

function vpLoadMaps(cb) {
    if (document.getElementById('vpMapsScript')) { cb(); return; }
    const s = document.createElement('script');
    s.id = 'vpMapsScript';
    // Same browser key the other pickers on this app use.
    s.src = 'https://maps.googleapis.com/maps/api/js?key=AIzaSyBFCBj7ebflrliC1pHq0XhsjuW18Q3iElk&libraries=places';
    s.async = true; s.defer = true;
    s.onload = cb;
    s.onerror = () => {
        document.getElementById('vpMapCoords').textContent = 'Could not load the map.';
    };
    document.head.appendChild(s);
}

function vpInitMap(start) {
    const el = document.getElementById('vpMapCanvas');
    if (!el || !window.google) return;
    vpMap = new google.maps.Map(el, {
        center: start, zoom: 16, mapTypeControl: false, streetViewControl: false,
    });
    vpMapMarker = new google.maps.Marker({position: start, map: vpMap, draggable: true});
    const set = (ll) => {
        vpMapPick = {lat: ll.lat(), lng: ll.lng()};
        vpMapMarker.setPosition(ll);
        document.getElementById('vpMapCoords').textContent =
            vpMapPick.lat.toFixed(5) + ', ' + vpMapPick.lng.toFixed(5);
    };
    vpMap.addListener('click', e => set(e.latLng));
    vpMapMarker.addListener('dragend', e => set(e.latLng));
}

function vpSaveMapPin() {
    if (!vpMapPick) { alert('Tap the map to place the pin first.'); return; }

    // Picking for the stop being ADDED — just hold the coordinates.
    if (vpMapFor === 'new') {
        document.getElementById('vpStopLat').value = vpMapPick.lat;
        document.getElementById('vpStopLng').value = vpMapPick.lng;
        const note = document.getElementById('vpNewPinNote');
        note.style.display = '';
        note.textContent = '📍 pin set — ' + vpMapPick.lat.toFixed(5) + ', ' + vpMapPick.lng.toFixed(5);
        vpCloseMap();
        return;
    }

    // Re-pinning an existing stop: the name is required by the endpoint, so send
    // the one it already has (renaming is a separate act).
    const btn = document.getElementById('vpMapSaveBtn');
    btn.disabled = true;
    fetch('/orders/van/stops/' + vpMapFor, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
        body: JSON.stringify({name: vpMapName, latitude: vpMapPick.lat, longitude: vpMapPick.lng}),
    }).then(r => r.json()).then(res => {
        if (!res.success) { alert(res.message || 'Could not save the pin.'); return; }
        vpCloseMap();
        vpLoadStops();
    }).catch(() => alert('Could not save the pin.'))
      .finally(() => { btn.disabled = false; });
}

/* ── 📍 send a van to a stop — the SAME endpoint the store's mobile board and
      the driver's own picker call, so the three surfaces cannot drift. ── */
function vpSendVan(stopId, stopName) {
    const vans = (vpData && vpData.vans) || [];
    if (!vans.length) { alert('No van is out right now.'); return; }

    const go = (v) => {
        fetch('/orders/van/stops/set', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
            body: JSON.stringify({van_user_id: v.driver_user_id, location_id: stopId}),
        }).then(r => r.json()).then(res => {
            if (!res.success) { alert(res.message || 'Could not set the meet-up point.'); return; }
            alert((v.driver_name || 'The van') + ' is heading to ' + stopName + '.'
                  + (res.riders_notified ? ' ' + res.riders_notified + ' rider(s) told.' : ''));
            vpCloseStops();
            vpLoad();
        }).catch(() => alert('Could not set the meet-up point.'));
    };

    if (vans.length === 1) {
        if (confirm('Send ' + (vans[0].driver_name || 'the van') + ' to ' + stopName + '?')) go(vans[0]);
        return;
    }
    const names = vans.map((v, i) => (i + 1) + '. ' + (v.driver_name || 'Van')).join('\n');
    const pick = prompt('Which van?\n' + names, '1');
    const idx = parseInt(pick, 10) - 1;
    if (vans[idx]) go(vans[idx]);
}

function vpPickForNew() {
    vpMapName = document.getElementById('vpStopName').value.trim() || 'New stop';
    vpOpenMap('new', vpMapName, null, null);
}

function vpSaveStop() {
    const name = document.getElementById('vpStopName').value.trim();
    const lat  = document.getElementById('vpStopLat').value.trim();
    const lng  = document.getElementById('vpStopLng').value.trim();
    const err  = document.getElementById('vpStopsError');
    if (!name) { err.textContent = 'Give the stop a name.'; err.style.display = ''; return; }

    err.style.display = 'none';
    fetch(VP_STOPS, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify({name: name, latitude: lat || null, longitude: lng || null})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        document.getElementById('vpStopName').value = '';
        document.getElementById('vpStopLat').value = '';
        document.getElementById('vpStopLng').value = '';
        vpLoadStops();
    })
    .catch(e => { err.textContent = e.message || 'Could not save that stop.'; err.style.display = ''; });
}

function vpRetireStop(id) {
    if (!confirm('Retire this meet-up point?\n\nPast trips keep it in their history; the driver just '
               + 'will not see it in his list any more.')) return;
    fetch(VP_STOPS + '/' + id, {
        method: 'DELETE',
        headers: {'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}
    })
    .then(r => r.json())
    // A refusal used to be thrown away, so the list simply reloaded with the
    // stop still there and no explanation.
    .then(res => { if (!res.success) alert(res.message || 'Could not retire that stop.'); vpLoadStops(); })
    .catch(() => alert('Could not retire that stop.'));
}

/* Bring a retired stop back into the driver's list. */
function vpUnretireStop(id, name) {
    fetch(VP_STOPS + '/' + id, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify({name: name, is_active: true})
    })
    .then(r => r.json())
    .then(res => { if (!res.success) alert(res.message || 'Could not bring that stop back.'); vpLoadStops(); })
    .catch(() => alert('Could not bring that stop back.'));
}

function vpPromote(handoverId, suggested) {
    const name = window.prompt('Save this spot as a permanent meet-up point.\n\nName it:', suggested || '');
    if (name === null || !name.trim()) return;
    fetch(VP_STOPS + '/promote/' + handoverId, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify({name: name.trim()})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        vpLoadStops();
    })
    .catch(e => alert(e.message || 'Could not save that spot.'));
}

/* ── helpers ─────────────────────────────────────────────────────────── */
/* ⭐ HOUSE TIME FORMAT — "3:42 PM" (owner, Aug-2026: AM/PM, not 24-hour).
   Identical to Day Review's `drHm` on this same page and to the mobile app's
   `hm`, so the live van card and the trip's own history read the same way. Day
   Review was ALREADY am/pm; this panel was the odd one out.

   ⚠ Parsed by SLICING THE STRING, not through `new Date()`. The API sends
   local-time strings with no zone ("2026-08-20 15:42:00"); slicing cannot be
   shifted by an engine's timezone handling, and it also accepts a bare "15:42".
   (`vpTs` below still builds a Date — but only to SORT, never to display.) */
function vpTime(ts) {
    if (!ts) return '';
    const s = String(ts);
    const t = s.length > 11 ? s.substring(11, 16) : s.substring(0, 5);
    const parts = t.split(':').map(Number);
    const h = parts[0], m = parts[1];
    if (isNaN(h) || isNaN(m)) return '';
    const ap = h >= 12 ? 'PM' : 'AM';
    const hh = h % 12 === 0 ? 12 : h % 12;
    return hh + ':' + String(m).padStart(2, '0') + ' ' + ap;
}
/* ⚠ A BARE CLOCK TIME LIES ABOUT AN OLD TIMESTAMP. A tag set two days ago
   rendered as "tagged 14:00", which reads as 14:00 TODAY — the precise opposite
   of the point, since the whole reason the row is flagged is that it is old.
   Same-day keeps the clock (that is the useful precision); anything older says
   the date instead. */
function vpWhen(ts) {
    if (!ts) return '';
    const t = vpTime(ts);
    if (!t) return '';
    const day = String(ts).substring(0, 10);
    const n = new Date();
    const today = n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0')
                + '-' + String(n.getDate()).padStart(2, '0');
    if (day === today || day.length !== 10) return t;
    // Keeps the clock time alongside the date, exactly like the mobile app's
    // `when()` ("18 Aug, 2:00 PM") — the date says it is old, the time still
    // says when, and dropping one of the two helps nobody.
    const d = new Date(day + 'T00:00:00');
    if (isNaN(d)) return t;
    return d.toLocaleDateString('en-GB', {day: 'numeric', month: 'short'}) + ', ' + t;
}
function vpEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/* ONE place that turns an ETA payload into words.
   An ETA can now be CHAINED through the stops someone still has to deliver
   before they can come to the meet-up point — in that shape there is no single
   measured leg to quote a distance for, so it reads as "after 3 stops · ~4:20 PM"
   instead of a straight-line figure that ignores the route they are on. */
function vpEta(e) {
    if (!e) return '';
    if (e.after_stops) {
        return 'after ' + e.stops_first + ' stop' + (e.stops_first === 1 ? '' : 's')
             + ' · ~' + (e.arrival_display || (e.minutes + ' min'));
    }
    return (e.distance_display ? e.distance_display + ' · ' : '') + '~' + e.minutes + ' min';
}
</script>
