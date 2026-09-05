{{-- U4 — dismissable "bike meter not recorded" banners for management, shown on the attendance
     and main invoices pages. The endpoint is audience-gated by the 'receive_bike_meter_alerts'
     permission, so this renders nothing for users who aren't recipients. Self-contained; include
     with @include('partials.home-meter-alerts'). --}}
{{-- Shares the #nfCornerStack host with the other corner banners (service / ticket /
     workshop) so they stack instead of painting over one another. Included first, so it
     stays on top of the column. --}}
<div id="homeMeterAlerts" style="display:flex;flex-direction:column;gap:8px;"></div>
<script>(function(){var h=document.getElementById('nfCornerStack');if(!h){h=document.createElement('div');h.id='nfCornerStack';h.style.cssText='position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;';document.body.appendChild(h);}var me=document.getElementById('homeMeterAlerts');if(me&&me.parentNode!==h)h.appendChild(me);})();</script>
<script>
(function(){
  var meta = document.querySelector('meta[name="csrf-token"]');
  var CSRF = meta ? meta.content : '';
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
  function render(alerts){
    var box = document.getElementById('homeMeterAlerts');
    if(!box) return;
    box.innerHTML='';
    (alerts||[]).forEach(function(a){
      var late = a.minutes_late ? (' · '+a.minutes_late+' min late') : '';
      var msg = (a.state==='late_locked')
        ? (a.rider_name+' reached home late'+late+' — meter is locked.')
        : (a.rider_name+' is home but hasn’t recorded his bike meter'+late+'.');
      var day = a.date ? (' · '+a.date) : '';
      var el = document.createElement('div');
      el.style.cssText='background:#7F1D1D;color:#fff;border-radius:10px;padding:10px 12px;box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
      el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
        +'<div><b>🏍 Bike meter not recorded</b>'+esc(day)+'<br>'+esc(msg)+'</div>'
        +'<span data-dismiss="'+a.attendance_id+'" title="Dismiss" style="cursor:pointer;font-size:18px;line-height:1;opacity:.85;">&times;</span></div>'
        +'<div style="margin-top:8px;"><a href="/attendance'+(a.date?('?date='+encodeURIComponent(a.date)):'')+'" style="color:#FECACA;font-weight:700;text-decoration:underline;">Open attendance →</a></div>';
      box.appendChild(el);
      el.querySelector('[data-dismiss]').addEventListener('click', function(){
        var id = this.getAttribute('data-dismiss');
        el.remove();
        fetch('/attendance/home-alerts/dismiss',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},body:JSON.stringify({attendance_id:parseInt(id,10)})}).catch(function(){});
      });
    });
  }
  function poll(){
    fetch('/attendance/home-alerts',{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();}).then(function(j){ if(j&&j.success) render(j.alerts); })
      .catch(function(){});
  }
  poll();
  setInterval(poll, 90000);
})();
</script>
