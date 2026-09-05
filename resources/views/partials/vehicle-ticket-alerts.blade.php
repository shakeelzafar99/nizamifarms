{{-- 🛠 "A rider reported a problem with a bike" — corner banner (Sep-2026).

     ⚠⚠ NOT partials/service-alerts. That one is DERIVED (a machine is due for a job)
        and self-clears when the service is recorded. This one is a real ROW someone
        raised and someone must answer, and it goes away only when a manager closes it.
        Different audience key, different wording, different lifecycle — keep apart.

     The endpoint decides the audience: a manager holding
     `manage_vehicle_tickets` sees the fleet, a rider sees only his own machine's
     tickets, anyone else gets nothing. So this is safe to include on any page —
     for people it is not for it renders nothing at all.

     ⚠ It shows the newest open ticket, not a list: the corner is for "there is
       something waiting", and the Bikes screen is where the work is actually done.
       Dismissal is per browser and per NEWEST MESSAGE, so a fresh reply brings it
       back exactly once rather than being silenced forever by one click.

     Self-contained; include with @include('partials.vehicle-ticket-alerts'). --}}
{{-- Shares the #nfCornerStack host with the other corner banners — see service-alerts. --}}
<div id="vtAlerts" style="display:flex;flex-direction:column;gap:8px;"></div>
<script>(function(){var h=document.getElementById('nfCornerStack');if(!h){h=document.createElement('div');h.id='nfCornerStack';h.style.cssText='position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;';document.body.appendChild(h);}var me=document.getElementById('vtAlerts');if(me&&me.parentNode!==h)h.appendChild(me);})();</script>
<script>
(function(){
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}

  /* ⚠ Keyed to the newest MESSAGE id, not the ticket id. Keyed to the ticket, one
     dismissal would hide every later reply on it too — and the reply is the part a
     manager most needs to see. Same reasoning as the mobile banner's watermark. */
  var SEEN = 'vt_seen_message_id';
  function seen(){ try { return parseInt(localStorage.getItem(SEEN) || '0', 10) || 0; } catch(e){ return 0; } }
  function markSeen(id){ try { localStorage.setItem(SEEN, String(id)); } catch(e){} }

  function render(j){
    var box = document.getElementById('vtAlerts');
    if(!box) return;
    box.innerHTML='';
    var t = j && j.latest;
    var latestId = (j && j.latest_id) || 0;
    if(!t || !latestId || latestId <= seen()) return;

    var el = document.createElement('div');
    /* Red when the bike cannot be ridden — that is a rider stranded, not a queue item. */
    el.style.cssText='background:'+(t.urgent?'#7F1D1D':'#1E3A8A')+';color:#fff;border-radius:10px;'
      +'padding:10px 12px;box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
    el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
      +'<div><b>'+(t.urgent?'🔴 Bike not rideable':'🛠 Bike problem reported')+'</b><br>'
      + esc(t.title)
      + (t.vehicle_name ? '<br><span style="opacity:.85;">'+esc(t.vehicle_name)
          + (t.opened_for_name ? ' · '+esc(t.opened_for_name) : '') +'</span>' : '')
      + ((j.count||0) > 1 ? '<br><span style="opacity:.85;">'+(j.count)+' open in total</span>' : '')
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
    fetch('/orders/riders-map/fleet/tickets/alerts',{headers:{'Accept':'application/json'}})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if(j && j.success) render(j); })
      .catch(function(){});   /* a banner is never worth breaking a page over */
  }
  poll();
  setInterval(poll, 120000);   /* 2 min — a rider waiting on a broken bike is time-sensitive,
                                  but this is still only a notice, not a live feed */
})();
</script>
