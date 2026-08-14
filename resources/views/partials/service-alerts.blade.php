{{-- 🛢 Dismissable "a bike is due for service" banners (Aug-2026).

     ⚠⚠ NOT the same thing as partials/home-meter-alerts. That one is about a RIDER
        (came home late / no closing meter). This is about a MACHINE (a scheduled job
        is due on it) and has its OWN audience key, `receive_service_alerts`, so
        silencing one never silences the other.

     The endpoint decides the audience — managers holding the key see the whole
     fleet, a rider sees only the machine he is holding, anyone else gets an empty
     list — so this renders nothing for people it is not for.

     ⭐ Self-clearing: the alert is derived from the service schedule, so recording
        the service makes it disappear everywhere. There is no row to close.

     Self-contained; include with @include('partials.service-alerts'). --}}
<div id="svcAlerts" style="position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;"></div>
<script>
(function(){
  var meta = document.querySelector('meta[name="csrf-token"]');
  var CSRF = meta ? meta.content : '';
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}

  function render(alerts){
    var box = document.getElementById('svcAlerts');
    if(!box) return;
    box.innerHTML='';
    (alerts||[]).forEach(function(a){
      var overdue = a.state === 'overdue';
      var el = document.createElement('div');
      /* Overdue is the deep red the meter banner uses; due-soon is amber, so a
         manager can tell "act now" from "plan it" without reading. */
      el.style.cssText='background:'+(overdue?'#7F1D1D':'#92400E')+';color:#fff;border-radius:10px;'
        +'padding:10px 12px;box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
      el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
        +'<div><b>🛢 '+(overdue?'Service overdue':'Service due soon')+'</b><br>'+esc(a.message||'')
        + (a.keeper_name ? '<br><span style="opacity:.85;">with '+esc(a.keeper_name)+'</span>' : '')
        +'</div>'
        +'<span data-dismiss="'+esc(a.alert_key)+'" title="Dismiss" style="cursor:pointer;font-size:18px;line-height:1;opacity:.85;">&times;</span></div>'
        +'<div style="margin-top:8px;"><a href="/riders-map" style="color:#FECACA;font-weight:700;text-decoration:underline;">Open Bikes &rarr;</a></div>';
      box.appendChild(el);
      el.querySelector('[data-dismiss]').addEventListener('click', function(){
        var key = this.getAttribute('data-dismiss');
        el.remove();
        fetch('/orders/riders-map/fleet/service-alerts/dismiss',{
          method:'POST',
          headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
          body:JSON.stringify({alert_key:key})
        }).catch(function(){});
      });
    });
  }

  function poll(){
    fetch('/orders/riders-map/fleet/service-alerts',{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();}).then(function(j){ if(j&&j.success) render(j.alerts); })
      .catch(function(){});
  }
  poll();
  setInterval(poll, 300000);   /* 5 min — a service clock moves in kilometres, not seconds */
})();
</script>
