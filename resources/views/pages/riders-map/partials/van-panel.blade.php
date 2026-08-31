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

{{-- 🗺 LIVE RENDEZVOUS MAP — read-only, van + meet-up point + inbound riders.
     ⚠ This page has NO map of its own (the Live view is a card grid), so this is
     a modal of its own rather than markers on an existing canvas. It reuses the
     SAME Google loader the pin picker above uses, so there is one script tag and
     one API key on the page. Page level, like the other two modals: a
     position:fixed element inside a display:none view does not render at all. --}}
<div id="vpLiveModal" onclick="if(event.target===this)vpCloseLive()"
     style="display:none;position:fixed;inset:0;z-index:4500;background:rgba(0,0,0,.6);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:860px;
              box-shadow:0 18px 60px rgba(0,0,0,.4);overflow:hidden;">
    <div style="display:flex;align-items:center;gap:9px;padding:13px 17px;border-bottom:1px solid #e5e7eb;">
      <b id="vpLiveTitle" style="font-size:15px;color:#111827;">🗺 Live</b>
      <span id="vpLiveChip" style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;"></span>
      <button type="button" onclick="vpCloseLive()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div id="vpLiveCanvas" style="width:100%;height:min(62vh,520px);background:#eef2f5;"></div>
    <div style="display:flex;align-items:center;gap:12px;padding:10px 17px;border-top:1px solid #e5e7eb;
                font-size:11.5px;color:#6b7280;">
      <span>🚚 Van</span><span>📍 Meet-up</span><span>👤 Rider</span>
      <span style="margin-left:auto;">Faded = old GPS</span>
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
/* Planned drop position — round chip so it reads as "stop number", not as part
   of the order number. Same indigo family as the mobile boards' seq chips. */
.vp-oseq{flex:0 0 auto;min-width:17px;height:17px;line-height:17px;border-radius:9px;
    background:#E0E7FF;color:#3730A3;font-weight:800;font-size:10.5px;text-align:center;
    padding:0 3px;}
.vp-ono{font-weight:700;color:#111827;min-width:78px;}
.vp-ocust{color:#6b7280;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.vp-ostate{font-size:10.5px;font-weight:700;padding:1px 8px;border-radius:20px;white-space:nowrap;}
.vp-ostate.wait{background:#fef3c7;color:#92400e;}
.vp-ostate.ok{background:#dcfce7;color:#166534;}
.vp-ostate.stale{background:#fee2e2;color:#991b1b;}
/* The store's release row — an ACTION inside the driver's own group, so it sits
   with the stops it acts on rather than in a toolbar away from them. */
.vp-oact{justify-content:flex-start;padding:7px 10px;background:#f8fafc;}
.vp-sendbtn{border:1px solid #0d9488;background:#0d9488;color:#fff;border-radius:7px;
            padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
.vp-sendbtn:hover{background:#0f766e;border-color:#0f766e;}
.vp-otime{color:#9ca3af;font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap;}
/* GPS freshness chip — the same three states, colours and words as the mobile
   boards, so "grey dot" means one thing across the whole system. */
.vp-gps{display:inline-block;font-size:10.5px;font-weight:700;padding:1px 8px;border-radius:20px;
        vertical-align:middle;margin-left:4px;}
.vp-gps.live{background:#dcfce7;color:#166534;}
.vp-gps.aging{background:#fef3c7;color:#92400e;}
.vp-gps.stale{background:#fee2e2;color:#991b1b;}

/* Journey bars. Clickable — they open the live map on that van. */
.vp-bars{margin-top:10px;padding-top:9px;border-top:1px solid #ccfbf1;}
.vp-bar{display:flex;align-items:center;gap:10px;padding:4px 0;cursor:pointer;}
.vp-bar:hover .vp-bfill{background:#0d9488;}
.vp-bar.stale{opacity:.55;}
.vp-bname{font-size:12px;font-weight:700;color:#111827;min-width:130px;
          overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.vp-btrack{flex:1;height:7px;border-radius:4px;background:#e5e7eb;overflow:hidden;min-width:80px;}
.vp-bfill{display:block;height:7px;border-radius:4px;background:#0f766e;transition:width .6s ease;}
.vp-bmeta{font-size:11.5px;color:#6b7280;font-weight:600;white-space:nowrap;
          font-variant-numeric:tabular-nums;}

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
    // The live map reads the same freshly-polled data — dots move on their own.
    vpLiveRefresh(false);
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

    // ⭐ GPS freshness, in the same three states every surface uses. The state
    //    is the SERVER's — this only paints it.
    const vpos = v.van_position;
    const gpsChip = (vpos && vpos.label)
        ? '<span class="vp-gps ' + (vpos.state || 'stale') + '">'
          + (vpos.state === 'live' ? '📍 ' : '') + vpEsc(vpos.label) + '</span>'
        : '';

    // 🗺 Only offer the map when there is something to draw on it.
    const mapBtn = ((vpos && vpos.lat !== null) || (stop && stop.latitude !== null))
        ? '<button type="button" class="vp-gear" onclick="vpOpenLive(' + v.driver_user_id + ')">🗺 Live map</button>'
        : '';

    // ▓▓▓░░ The journey bars — the van's own, then every rider still coming.
    //    Each is present only when the server could answer honestly; a missing
    //    bar means "cannot say", never "no progress".
    let bars = '';
    if (v.van_progress) bars += vpBar('🚚 ' + vpEsc(v.driver_name || 'Van'), v.van_progress, vpos, v.driver_user_id);
    (v.inbound || []).forEach(r => {
        if (r.progress) bars += vpBar('👤 ' + vpEsc(r.name || 'Rider'), r.progress, r.position, v.driver_user_id);
    });
    if (bars) bars = '<div class="vp-bars">' + bars + '</div>';

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
         +       '<div class="vp-title">🚚 ' + vpEsc(v.headline) + ' ' + gpsChip + '</div>'
         +       '<div class="vp-sub">' + stopLine + '</div>'
         +     '</div>'
         +     mapBtn
         +     '<button type="button" class="vp-gear" onclick="vpOpenStops()">⚙ Meet-up points</button>'
         +   '</div>'
         +   '<div class="vp-strip">' + stats + '</div>'
         +   forced
         +   bars
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
        let mineRows = mine.map(o => vpORow(
            o.order_number, o.customer || '',
            o.status === 'on_van' ? 'on the van' : (o.dispatched ? 'delivering' : 'out for delivery'),
            o.status === 'on_van' ? 'wait' : 'ok',
            o.dispatched_at ? vpWhen(o.dispatched_at) : '',
            o.priority   // planned drop position — see vpORow
        )).join('');

        /* ⭐⭐ THE STORE'S RELEASE DOOR (Aug-30, from the 29-Aug prod run).
           A driver's own box, once scanned aboard, can ONLY be sent out by the
           driver himself — and when he did not (his Dispatch button silently
           skipped his van stops on a mixed list), the store's only way to make
           the order deliverable was to launder it through 'on hold' and back
           out again. That happened twice that day, to five orders, and it
           strips the delivery time on the way past.

           This is the same door his own picker uses: one flip, timed by the
           real engine, recorded against the manager who pressed it.

           ⚠ Drawn ONLY when the server says this viewer may use it — the panel
             renders for a wider permission than the release needs, and a button
             whose only outcome is a 403 is worse than no button. */
        const parked = mine.filter(o => o.status === 'on_van');
        if (parked.length && vpData && vpData.can_dispatch_own) {
            mineRows += '<div class="vp-orow vp-oact">'
                     +   '<button type="button" class="vp-sendbtn" onclick="vpSendOwnStops(' + vid + ')">'
                     +     '🚀 Send out ' + parked.length + ' parked stop'
                     +     (parked.length === 1 ? '' : 's') + ' with times'
                     +   '</button>'
                     + '</div>';
        }

        out += vpGrp(vid, 'mine', '🏠 ' + vpEsc(v.driver_name || 'Driver') + "'s own", mine.length,
            '', true, mineRows);
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
                o.handover_at ? vpWhen(o.handover_at) : '',
                o.priority   // planned drop position — see vpORow
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

/* ⭐ seq (Aug-23) = the planned drop position (delivery_priority). The manifest
   has sent it since day one — the store board and the rider app already show
   this sequence, and the web card was the one surface with no numbers at all.
   Rendered ONLY when the stop was actually sequenced (null/undefined = no chip):
   an absent plan must look absent, not like stop zero. The to-load group never
   passes it — those rows aren't aboard yet, so a drop position would be a
   promise the load scan hasn't made. */
function vpORow(no, cust, state, tone, time, seq) {
    return '<div class="vp-orow">'
         +   (seq != null ? '<span class="vp-oseq">' + vpEsc(seq) + '</span>' : '')
         +   '<span class="vp-ono">' + vpEsc(no) + '</span>'
         +   '<span class="vp-ocust">' + vpEsc(cust) + '</span>'
         +   (time ? '<span class="vp-otime">' + vpEsc(time) + '</span>' : '')
         +   '<span class="vp-ostate ' + tone + '">' + vpEsc(state) + '</span>'
         + '</div>';
}

/* ▓▓▓░░ One journey bar. Clicking it opens the live map focused on this van.
   ⚠ The bar is a GLANCE — the km labels beside it carry the real information, so
   a viewer never has to estimate anything from a pixel width. */
function vpBar(name, p, pos, driverId) {
    const pct = Math.max(0, Math.min(100, Number(p.percent) || 0));
    const stale = pos && pos.state === 'stale';
    return '<div class="vp-bar' + (stale ? ' stale' : '') + '"'
         + ' onclick="vpOpenLive(' + driverId + ')" title="Open the live map">'
         +   '<span class="vp-bname">' + name + '</span>'
         +   '<span class="vp-btrack"><span class="vp-bfill" style="width:' + pct + '%;"></span></span>'
         +   '<span class="vp-bmeta">' + vpEsc(p.covered_display) + ' done · '
         +     vpEsc(p.remaining_display) + ' left</span>'
         + '</div>';
}

function vpStat(label, value) {
    return '<div class="vp-stat"><span class="lab">' + label + '</span><b>' + value + '</b></div>';
}

/* ═══ 🗺 THE LIVE RENDEZVOUS MAP ══════════════════════════════════════════
   Van + meet-up point + every inbound rider on one canvas, refreshed by the
   panel's own 30s poll while it is open.

   ⚠⚠ HELD BY DRIVER ID, NEVER BY THE VAN OBJECT. `vpData` is replaced wholesale
   on every poll, so a captured object would freeze the map on the snapshot taken
   when it opened — markers that never move, on a screen whose entire job is to
   move them. `vpLiveVan()` re-reads the current object each refresh. */
let vpLiveId = null, vpLiveMap = null, vpLiveMarkers = [];

function vpLiveVan() {
    if (vpLiveId === null || !vpData) return null;
    return (vpData.vans || []).find(v => Number(v.driver_user_id) === Number(vpLiveId)) || null;
}

function vpOpenLive(driverId) {
    vpLiveId = driverId;
    document.getElementById('vpLiveModal').style.display = 'flex';
    vpLoadMaps(() => {
        const v = vpLiveVan();
        const start = (v && v.van_position && v.van_position.lat !== null)
            ? {lat: v.van_position.lat, lng: v.van_position.lng}
            : (v && v.stop && v.stop.latitude !== null
                ? {lat: Number(v.stop.latitude), lng: Number(v.stop.longitude)}
                : {lat: 33.6844, lng: 73.0479});
        const el = document.getElementById('vpLiveCanvas');
        if (!el || !window.google) return;
        vpLiveMap = new google.maps.Map(el, {
            center: start, zoom: 13, mapTypeControl: false, streetViewControl: false,
        });
        vpLiveRefresh(true);
    });
}

function vpCloseLive() {
    document.getElementById('vpLiveModal').style.display = 'none';
    vpLiveId = null;
    vpLiveMarkers.forEach(m => m.setMap(null));
    vpLiveMarkers = [];
    vpLiveMap = null;
}

/* Redraw the markers from the CURRENT poll data. Called on open and from vpLoad,
   so the dots move without anyone pressing anything. */
function vpLiveRefresh(fit) {
    if (!vpLiveMap || !window.google) return;
    const v = vpLiveVan();
    if (!v) { vpCloseLive(); return; }   // trip ended — don't linger on a corpse

    vpLiveMarkers.forEach(m => m.setMap(null));
    vpLiveMarkers = [];
    const bounds = new google.maps.LatLngBounds();
    let any = false;

    const add = (lat, lng, label, state, colour, initial) => {
        const pos = {lat: Number(lat), lng: Number(lng)};
        vpLiveMarkers.push(new google.maps.Marker({
            position: pos, map: vpLiveMap, title: label,
            // A stale dot is visibly faded — the map must never imply that a
            // ten-minute-old fix is where somebody is standing now.
            opacity: state === 'stale' ? 0.45 : 1,
            // ⭐ An INITIAL inside the circle — with two riders inbound, two
            //    identical blue dots are only tellable apart by hovering each
            //    one. The full name stays in the hover title.
            label: initial
                ? {text: String(initial).toUpperCase(), color: '#fff',
                   fontSize: '10px', fontWeight: '800'}
                : null,
            icon: {
                path: google.maps.SymbolPath.CIRCLE, scale: initial ? 11 : 9,
                fillColor: state === 'stale' ? '#9ca3af' : colour,
                fillOpacity: 1, strokeColor: '#fff', strokeWeight: 2,
            },
        }));
        bounds.extend(pos); any = true;
    };

    const vp = v.van_position;
    if (vp && vp.lat !== null) {
        add(vp.lat, vp.lng, '🚚 ' + (v.driver_name || 'Van') + ' — ' + (vp.label || ''), vp.state, '#b45309');
    }
    if (v.stop && v.stop.latitude !== null && v.stop.latitude !== undefined) {
        add(v.stop.latitude, v.stop.longitude, '📍 ' + (v.stop.label || 'Meet-up'), 'fixed', '#0f766e');
    }
    (v.inbound || []).forEach(r => {
        if (!r.position || r.position.lat === null) return;
        add(r.position.lat, r.position.lng,
            '👤 ' + (r.name || 'Rider') + ' — ' + (r.position.label || ''), r.position.state, '#2563eb',
            (r.name || 'R').trim().charAt(0));
    });

    document.getElementById('vpLiveTitle').textContent = '🗺 ' + (v.driver_name || 'Van') + ' — live';
    const chip = document.getElementById('vpLiveChip');
    if (vp) {
        const tone = vp.state === 'live' ? ['#dcfce7', '#166534']
                   : vp.state === 'aging' ? ['#fef3c7', '#92400e'] : ['#fee2e2', '#991b1b'];
        chip.style.background = tone[0]; chip.style.color = tone[1];
        chip.textContent = (vp.state === 'live' ? '📍 ' : '') + (vp.label || '');
    } else { chip.textContent = ''; chip.style.background = 'transparent'; }

    // Only fit on open: re-fitting every 30s would yank the view out from under
    // a manager who has zoomed in on someone.
    if (fit && any) {
        vpLiveMap.fitBounds(bounds, 60);
        if (vpLiveMarkers.length === 1) vpLiveMap.setZoom(15);
    }
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

/* ── 🚀 send the DRIVER'S OWN parked stops out, and time them ──────────────
      The same endpoint and the same engine his own picker uses — passing
      `driver_id` is the only difference, and the server checks the store
      permission itself. Replaces the on_hold → out_for_delivery laundering.

   ⚠ Sends them in the order the manifest returned, which is already the
     planned drop sequence — the engine reads that order as the route. */
function vpSendOwnStops(vanUserId) {
    const v = ((vpData && vpData.vans) || [])
        .find(x => Number(x.driver_user_id) === Number(vanUserId));
    if (!v) return;

    const parked = (v.mine || []).filter(o => o.status === 'on_van');
    if (!parked.length) { alert('Nothing is parked on this van right now.'); return; }

    const who = v.driver_name || 'the driver';
    const msg = 'Send ' + parked.length + ' of ' + who + "'s parked stop"
              + (parked.length === 1 ? '' : 's') + ' out for delivery and work out delivery times?'
              + '\n\n' + parked.map(o => o.order_number).join(', ')
              + '\n\nThis is the same action he would take on his phone.';
    if (!confirm(msg)) return;

    fetch('/orders/van/dispatch-selected', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''},
        body: JSON.stringify({driver_id: vanUserId, order_ids: parked.map(o => o.id)}),
    }).then(r => r.json()).then(res => {
        if (!res.success) { alert(res.message || 'Could not send those stops out.'); return; }
        // `dispatched: 0` = the driver beat us to it (the server says so) —
        // claiming "2 stops sent out" about stops he sent would teach the store
        // the button lies. Repeat the server's own words instead.
        alert(res.dispatched === 0 && res.message
            ? res.message
            : (res.dispatched || parked.length) + ' stop(s) sent out for delivery and timed.');
        vpLoad();
    }).catch(() => alert('Could not send those stops out.'));
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
