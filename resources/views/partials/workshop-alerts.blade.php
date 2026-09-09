{{-- 🔧 "A bike is booked into the workshop" — corner banner (Sep-2026).

     ⚠⚠ THREE DIFFERENT CORNER BANNERS NOW LIVE ON THESE PAGES, and they are not
        interchangeable:
          • partials/service-alerts        — a MACHINE is due for a job (derived,
            self-clears when the service is recorded);
          • partials/vehicle-ticket-alerts — a RIDER reported a fault (a real row a
            manager must answer and close);
          • this one                       — a DATED errand somebody must go on.
        Separate audiences, separate lifecycles. Do not merge them.

     WHO SEES IT is the endpoint's decision: managers holding `receive_workshop_alerts`
     (RULED to include Farooq, who plans the shifts) see the fleet; a rider sees only
     his own. So this is safe to include anywhere — it renders nothing for people it is
     not for.

     ⭐ A MISSED visit outranks an upcoming one: a rider who did not go is the thing a
        manager has to act on today.

     Self-contained; include with @include('partials.workshop-alerts'). --}}
{{-- Shares the #nfCornerStack host with the other corner banners — see service-alerts. --}}
{{-- ⏳ The planners' approval queue sits ABOVE the notices: it is a question waiting on
     this person, not something that already happened. Empty for anyone else. --}}
<div id="wsApprovals" style="display:flex;flex-direction:column;gap:8px;"></div>
<div id="wsAlerts" style="display:flex;flex-direction:column;gap:8px;"></div>
<script>(function(){var h=document.getElementById('nfCornerStack');if(!h){h=document.createElement('div');h.id='nfCornerStack';h.style.cssText='position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;';document.body.appendChild(h);}['wsApprovals','wsAlerts'].forEach(function(id){var me=document.getElementById(id);if(me&&me.parentNode!==h)h.appendChild(me);});})();</script>
<script>
(function(){
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}

  /* Per-browser dismissal, keyed to the newest VISIT id. A reschedule writes a NEW row,
     so moving a date correctly brings the notice back — which is the whole point. */
  var SEEN = 'ws_seen_visit_id';
  function seen(){ try { return parseInt(localStorage.getItem(SEEN) || '0', 10) || 0; } catch(e){ return 0; } }
  function markSeen(id){ try { localStorage.setItem(SEEN, String(id)); } catch(e){} }

  function render(j){
    var box = document.getElementById('wsAlerts');
    if(!box) return;
    box.innerHTML='';
    var v = j && j.latest;
    var latestId = (j && j.latest_id) || 0;
    if(!v || !latestId || latestId <= seen()) return;

    /* `latest` is the visit with the NEWEST event and `latest_id` that event's instant —
       set / accepted / became tomorrow / missed each re-fire this once. */
    var missed = !!v.is_missed;
    var soon   = !!(v.is_today || v.is_tomorrow);
    var heading = missed ? 'Workshop visit missed'
                : v.is_today ? 'Workshop TODAY'
                : v.is_tomorrow ? 'Workshop TOMORROW'
                : 'Workshop visit';
    var el = document.createElement('div');
    el.style.cssText='background:'+(missed?'#7F1D1D':(soon?'#92400E':'#1E3A8A'))+';color:#fff;border-radius:10px;'
      +'padding:10px 12px;box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';

    /* ⚠ A stand-in acceptance is NEVER rendered as the rider confirming. */
    var state = v.accepted
        ? (v.accepted_on_behalf
            ? '✓ accepted for him by ' + esc(v.accepted_by_name || 'a manager')
            : '✓ confirmed by the rider')
        : '⏳ not confirmed yet';

    el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
      +'<div><b>🔧 '+heading+'</b><br>'
      + esc(v.rider_name || 'A rider') + ' → ' + esc(v.vehicle_name || 'a bike')
      + '<br><span style="opacity:.9;">' + esc(v.visit_date)
      + (v.visit_time ? ' at ' + esc(v.visit_time) : '')
      + (v.workshop ? ' · ' + esc(v.workshop) : '') + '</span>'
      + '<br><span style="opacity:.85;">' + state + '</span>'
      + ((j.missed||0) > 1 ? '<br><span style="opacity:.85;">'+j.missed+' missed in total</span>' : '')
      +'</div>'
      +'<span data-dismiss="1" title="Dismiss" style="cursor:pointer;font-size:18px;line-height:1;opacity:.85;">&times;</span></div>'
      +'<div style="margin-top:8px;"><a href="/riders-map#bikes?vehicle='+encodeURIComponent(v.vehicle_id||'')+'" style="color:#BFDBFE;font-weight:700;text-decoration:underline;">Open Bikes &rarr;</a></div>';
    box.appendChild(el);
    el.querySelector('[data-dismiss]').addEventListener('click', function(){
      markSeen(latestId);
      el.remove();
    });
  }

  /* ═══════════════════════════════════════════════════════════════════════════
     ⏳ AWAITING YOUR APPROVAL — the shift planners' queue (owner + team ruling, 6-Sep).

     ⭐⭐ THIS CARD IS NOT DISMISSIBLE, unlike the notice above it, and that is deliberate.
        The notice tells you something happened; this one asks you a question about a
        rider's day, and it leaves the screen exactly when it is answered. A proposal
        nobody answers is auto-DECLINED on the morning of the day — so a dismiss button
        would be a way to send a workshop day quietly to its death.

     ⚠ Self-contained: this partial is also on the shift planner and attendance pages,
       where the fleet screen's flForm() does not exist. Everything below is plain DOM.
     ⚠ The server re-checks `manage_shifts` on every action; `can_approve` here only
       decides whether to draw anything at all.
     ═══════════════════════════════════════════════════════════════════════════ */
  var WS_LOC = [], WS_SHIFTS = [];

  function csrf(){ var m=document.querySelector('meta[name="csrf-token"]'); return m?m.getAttribute('content'):''; }

  function post(url, payload, cb){
    fetch(url, { method:'POST',
                 headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf()},
                 body: JSON.stringify(payload||{}) })
      .then(function(r){ return r.json(); })
      .then(function(j){ cb(!!j.success, j.message || ''); })
      .catch(function(){ cb(false, 'Could not reach the server. Please try again.'); });
  }

  function say(card, good, msg){
    var n = card.querySelector('[data-say]');
    n.style.display=''; n.style.background = good ? 'rgba(255,255,255,.18)' : 'rgba(0,0,0,.28)';
    n.textContent = msg;
  }

  function approvalCard(v){
    var el = document.createElement('div');
    el.style.cssText='background:#4C1D95;color:#fff;border-radius:10px;padding:10px 12px;'
      +'box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
    var warn = (v.warnings||[]).length
      ? '<div style="margin-top:6px;padding:6px 8px;background:rgba(252,211,77,.2);border:1px solid rgba(252,211,77,.6);border-radius:7px;font-size:12px;">'
        + (v.warnings||[]).map(function(w){ return '⚠ ' + esc(w); }).join('<br>') + '</div>'
      : '';
    /* ⚠ Say plainly when nothing CAN be pinned — a workshop day with no registered
       workshop leaves the rider measured against his usual place and marked remote. */
    var noPin = v.location_id ? '' :
      '<div style="margin-top:6px;font-size:12px;opacity:.9;">📍 No registered workshop chosen — '
      + 'approving will not move his check-in place. Use <b>Adjust</b> to pick one.</div>';

    el.innerHTML = '<div><b>⏳ Workshop day needs your approval</b><br>'
      + esc(v.rider_name || 'A rider') + ' → ' + esc(v.vehicle_name || 'a bike')
      + '<br><span style="opacity:.92;">' + esc(v.visit_date)
      + (v.visit_time ? ' at ' + esc(v.visit_time) : '')
      + (v.workshop ? ' · ' + esc(v.workshop) : '') + '</span>'
      + '<br><span style="opacity:.85;">asked by ' + esc(v.proposed_by_name || v.created_by_name || 'a manager') + '</span>'
      /* ⏰ The deadline, stated: after it the request is dropped so he is never sent
         somewhere he could not have been told about in time. */
      + (v.approve_by ? '<br><span style="opacity:.85;">decide by <b>' + esc(v.approve_by) + '</b> — dropped after that</span>' : '')
      /**
       * ⭐ WHAT SAYING YES WOULD REPLACE (owner ruling 7-Sep). Without this the card looks
       *   identical whether it is his first workshop day or a move — and a move means
       *   retiring a day the rider has already been told about, and telling him again.
       */
      + (v.replaces
          ? '<div style="margin-top:6px;padding:6px 8px;border-radius:6px;background:rgba(0,0,0,.22);">'
            + '⚠ This REPLACES his approved day: <b>' + esc(v.replaces.label) + '</b>'
            + (v.replaces.accepted ? ' — he has confirmed it' : '')
            + '<br><span style="opacity:.9;">Approve karenge to usko dobara bataya jayega.</span></div>'
          : '')
      + warn + noPin + '</div>'
      + '<div data-say style="display:none;margin-top:8px;padding:6px 8px;border-radius:7px;font-size:12px;"></div>'
      + '<div data-panel style="display:none;margin-top:8px;"></div>'
      + '<div data-btns style="margin-top:9px;display:flex;gap:6px;flex-wrap:wrap;">'
      + '<button data-a="ok" style="flex:1;min-width:88px;background:#16A34A;color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✓ Approve</button>'
      + '<button data-a="adj" style="flex:1;min-width:88px;background:rgba(255,255,255,.18);color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✎ Adjust</button>'
      + '<button data-a="no" style="flex:1;min-width:88px;background:rgba(255,255,255,.10);color:#FCA5A5;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✖ Decline</button>'
      + '</div>';

    var panel = el.querySelector('[data-panel]');
    var btns  = el.querySelector('[data-btns]');
    var fld   = 'width:100%;box-sizing:border-box;border:0;border-radius:7px;padding:6px 8px;font-size:12.5px;color:#111827;margin-top:4px;';
    var lbl   = 'display:block;font-size:11px;font-weight:700;opacity:.85;margin-top:8px;';

    function finish(good, msg){
      say(el, good, msg);
      if (good) { btns.style.display='none'; panel.style.display='none';
                  setTimeout(function(){ el.remove(); pollApprovals(); }, 2500);
                  /* On the Bikes page the machine's own panel may be open on this very visit —
                     redraw it, or it keeps saying "awaiting" after the answer was given. */
                  try { if (window.flvOpenId && typeof window.flvLoadVisits === 'function') window.flvLoadVisits(window.flvOpenId); } catch(e){}
                  /* On the SHIFT PLANNER the ⏳ cell chip is drawn from the week payload — redraw
                     the week, or the cell keeps saying "workshop?" after the answer was given.
                     ⚠ `loadWeek`/`WEEK` are top-level `let`/function declarations on that page, so
                     they share the global lexical scope but are NOT on `window` — hence `typeof`. */
                  try { if (typeof loadWeek === 'function' && typeof WEEK !== 'undefined') loadWeek(WEEK); } catch(e){} }
    }

    el.querySelector('[data-a="ok"]').addEventListener('click', function(){
      this.disabled = true;
      post('/orders/riders-map/fleet/workshop/' + v.id + '/approve', {}, finish);
    });

    el.querySelector('[data-a="adj"]').addEventListener('click', function(){
      if (panel.style.display === '') { panel.style.display='none'; panel.innerHTML=''; return; }
      panel.style.display='';
      panel.innerHTML =
          '<label style="'+lbl+'">Which workshop</label>'
        + '<select data-f="loc" style="'+fld+'">'
        + '<option value="">— none (his check-in place will NOT move) —</option>'
        + WS_LOC.map(function(w){ return '<option value="'+w.id+'"'
              + (String(w.id)===String(v.location_id||'')?' selected':'')+'>'+esc(w.name)+'</option>'; }).join('')
        + '</select>'
        + (WS_LOC.length ? '' : '<div style="font-size:11px;opacity:.85;margin-top:4px;">'
              + 'No location is ticked as a workshop yet — add one on the Locations page, or from the booking form on Bikes.</div>')
        + '<label style="'+lbl+'">Appointment time</label>'
        + '<input data-f="time" type="time" value="'+esc(v.visit_time||'')+'" style="'+fld+'">'
        /* ⭐ The Danish case: his visit was 09:00 while his shift still began 09:30. Nothing
           else in the flow could fix that, and the planner is exactly who should. */
        + '<label style="'+lbl+'">His shift that day (optional — leave as is to keep his own)</label>'
        + '<select data-f="tpl" style="'+fld+'">'
        + '<option value="">— keep his usual shift —</option>'
        + WS_SHIFTS.map(function(s){ return '<option value="'+s.id+'">'+esc(s.name)+(s.start?' · '+esc(s.start):'')+'</option>'; }).join('')
        + '</select>'
        + '<button data-f="go" style="width:100%;margin-top:10px;background:#16A34A;color:#fff;border:0;border-radius:7px;padding:8px;font-weight:700;font-size:12.5px;cursor:pointer;">✓ Approve with these changes</button>';
      panel.querySelector('[data-f="go"]').addEventListener('click', function(){
        this.disabled = true;
        var body = {};
        var L = panel.querySelector('[data-f="loc"]').value;
        var T = panel.querySelector('[data-f="time"]').value;
        var P = panel.querySelector('[data-f="tpl"]').value;
        body.location_id = L ? parseInt(L,10) : null;
        body.visit_time  = T || null;
        if (P) body.shift_template_id = parseInt(P,10);
        post('/orders/riders-map/fleet/workshop/' + v.id + '/approve', body, function(good,msg){
          if (!good) panel.querySelector('[data-f="go"]').disabled = false;
          finish(good, msg);
        });
      });
    });

    el.querySelector('[data-a="no"]').addEventListener('click', function(){
      panel.style.display='';
      panel.innerHTML = '<label style="'+lbl+'">Why not? (the person who asked will see this)</label>'
        + '<input data-f="why" type="text" maxlength="255" placeholder="e.g. He is on the Faizabad run that morning" style="'+fld+'">'
        + '<button data-f="go" style="width:100%;margin-top:10px;background:#B91C1C;color:#fff;border:0;border-radius:7px;padding:8px;font-weight:700;font-size:12.5px;cursor:pointer;">✖ Decline it</button>';
      panel.querySelector('[data-f="go"]').addEventListener('click', function(){
        this.disabled = true;
        post('/orders/riders-map/fleet/workshop/' + v.id + '/decline',
             { reason: panel.querySelector('[data-f="why"]').value || null }, function(good,msg){
          if (!good) panel.querySelector('[data-f="go"]').disabled = false;
          finish(good, msg);
        });
      });
    });
    return el;
  }

  function renderApprovals(j){
    var box = document.getElementById('wsApprovals');
    if(!box) return;
    box.innerHTML='';
    if(!j || !j.can_approve) return;
    WS_LOC    = j.workshops || WS_LOC;
    WS_SHIFTS = j.shifts    || WS_SHIFTS;
    (j.pending || []).forEach(function(v){ box.appendChild(approvalCard(v)); });
  }

  function pollApprovals(){
    fetch('/orders/riders-map/fleet/workshop/approvals',{headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if(j && j.success) renderApprovals(j); })
      .catch(function(){});
  }

  function poll(){
    pollApprovals();
    fetch('/orders/riders-map/fleet/workshop/alerts',{headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if(j && j.success) render(j); })
      .catch(function(){});
  }
  poll();
  /* 5 min. A date is not a live feed — and this poll is also what fires the day-before
     reminder push, since prod has no cron. */
  setInterval(poll, 300000);
})();
</script>
