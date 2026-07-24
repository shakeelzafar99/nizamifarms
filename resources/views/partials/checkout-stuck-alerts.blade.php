{{-- Live "rider stuck at checkout" banners for managers. A rider pressed OUT and the location/time
     rule refused it (recorded as a checkout_attempt). The endpoint is audience-gated by
     'view_store_attendance' and hides the caller's dismissals, so this renders nothing for
     non-recipients. Self-contained; include with @include('partials.checkout-stuck-alerts').
     Bottom-LEFT so it never overlaps the red bike-meter banner (bottom-right). --}}
<div id="checkoutStuckAlerts" style="position:fixed;left:16px;bottom:16px;z-index:11000;display:flex;flex-direction:column;gap:8px;max-width:360px;"></div>
<script>
(function(){
  var meta = document.querySelector('meta[name="csrf-token"]');
  var CSRF = meta ? meta.content : '';
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
  function render(alerts){
    var box = document.getElementById('checkoutStuckAlerts');
    if(!box) return;
    box.innerHTML='';
    (alerts||[]).forEach(function(a){
      var when = a.at ? (' · '+String(a.at).slice(11,16)) : '';
      var tries = (a.count && a.count > 1) ? (' · tried '+a.count+'×') : '';
      var el = document.createElement('div');
      el.style.cssText='background:#92400E;color:#fff;border-radius:10px;padding:10px 12px;box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
      var map = a.maps_url ? ' · <a href="'+esc(a.maps_url)+'" target="_blank" rel="noopener" style="color:#FDE68A;font-weight:700;text-decoration:underline;">view on map ↗</a>' : '';
      el.innerHTML='<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">'
        +'<div><b>🚫 Stuck at checkout</b>'+esc(when)+esc(tries)+'<br>'
        +'<b>'+esc(a.rider_name)+'</b> — '+esc(a.headline)+'</div>'
        +'<span data-dismiss="'+a.attendance_id+'" title="Dismiss" style="cursor:pointer;font-size:18px;line-height:1;opacity:.85;">&times;</span></div>'
        +'<div style="margin-top:8px;"><a href="/attendance'+(a.date?('?date='+encodeURIComponent(a.date)):'')+'" style="color:#FDE68A;font-weight:700;text-decoration:underline;">Open attendance →</a>'+map+'</div>';
      box.appendChild(el);
      el.querySelector('[data-dismiss]').addEventListener('click', function(){
        var id = this.getAttribute('data-dismiss');
        el.remove();
        fetch('/attendance/checkout-stuck-alerts/dismiss',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},body:JSON.stringify({attendance_id:parseInt(id,10)})}).catch(function(){});
      });
    });
  }
  function poll(){
    fetch('/attendance/checkout-stuck-alerts',{headers:{'Accept':'application/json'}})
      .then(function(r){return r.json();}).then(function(j){ if(j&&j.success) render(j.alerts); })
      .catch(function(){});
  }
  poll();
  setInterval(poll, 60000);
})();
</script>
