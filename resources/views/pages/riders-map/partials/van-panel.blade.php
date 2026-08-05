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
</style>

<script>
/* ═══ Van panel — the store's view of a van that is out ═══ */
let vpData = null;
let vpTimer = null;
let vpCanManage = false;

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
            el.innerHTML = (res.vans || []).map(vpCard).join('');
        })
        .catch(() => { /* the panel is a companion view — never break the board */ });
}

function vpCard(v) {
    const stop = v.stop || null;
    const t = v.totals || {};
    const waiting = stop && stop.reached_at;

    // Stats read differently per mode — show only what is true right now.
    let stats = '';
    if (v.mode === 'to_stop' && v.van_eta) {
        stats += vpStat('Van', v.van_eta.distance_display + ' · ~' + v.van_eta.minutes + ' min'
            + (v.van_eta.source === 'approx' ? ' <span style="color:#b45309;">(est)</span>' : ''));
    }
    if (waiting && stop.waiting_minutes !== null) {
        stats += vpStat('Waiting', stop.waiting_minutes + ' min');
    }
    if (v.trip && v.trip.departed_at) {
        stats += vpStat('Left', vpTime(v.trip.departed_at));
    }
    const onBoard = (t.mine_on_van || 0) + (t.carried_total || 0) - (t.carried_handed || 0);
    stats += vpStat('On board', onBoard);
    // ⭐ Tagged "On Van" (the staff's plan) but not scanned aboard yet.
    if (t.to_load) {
        stats += vpStat('To scan aboard', '<span style="color:#b45309;">' + t.to_load + '</span>');
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
                  + r.eta.distance_display + ' · ~' + r.eta.minutes + ' min</span>'
                : '<span class="vp-reta approx">no GPS</span>';
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

    return '<div class="vp-card">'
         +   '<div class="vp-head">'
         +     '<div style="min-width:0;">'
         +       '<div class="vp-title">🚚 ' + vpEsc(v.headline) + '</div>'
         +       '<div class="vp-sub">' + stopLine + '</div>'
         +     '</div>'
         +     '<button type="button" class="vp-gear" onclick="vpOpenStops()">⚙ Meet-up points</button>'
         +   '</div>'
         +   '<div class="vp-strip">' + stats + '</div>'
         +   (nothingYet
               ? '<div class="vp-riders"><div class="vp-empty">Nothing loaded yet — '
                 + 'the store scans each package onto the van, or the driver does it himself.</div></div>'
               : '')
         +   riders
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
                          + ' onclick="vpPromote(' + s.id + ',\'' + vpEsc(s.label).replace(/'/g, "\\'") + '\')">'
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
    }).then(r => r.json()).then(() => vpLoadStops()).catch(() => {});
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
function vpTime(ts) {
    if (!ts) return '';
    const d = new Date(String(ts).replace(' ', 'T'));
    return isNaN(d) ? '' : d.toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit'});
}
function vpEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
</script>
