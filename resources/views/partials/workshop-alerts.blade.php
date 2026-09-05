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
<div id="wsAlerts" style="display:flex;flex-direction:column;gap:8px;"></div>
<script>(function(){var h=document.getElementById('nfCornerStack');if(!h){h=document.createElement('div');h.id='nfCornerStack';h.style.cssText='position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;';document.body.appendChild(h);}var me=document.getElementById('wsAlerts');if(me&&me.parentNode!==h)h.appendChild(me);})();</script>
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
      +'<div style="margin-top:8px;"><a href="/riders-map" style="color:#BFDBFE;font-weight:700;text-decoration:underline;">Open Bikes &rarr;</a></div>';
    box.appendChild(el);
    el.querySelector('[data-dismiss]').addEventListener('click', function(){
      markSeen(latestId);
      el.remove();
    });
  }

  function poll(){
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
