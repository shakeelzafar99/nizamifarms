{{-- ⏳ "A shift change is waiting for you" — corner banner (Sep-2026).

     ⚠⚠ THIS IS THE FOURTH CORNER BANNER ON THESE PAGES AND IT IS NOT INTERCHANGEABLE
        WITH THE OTHER THREE:
          • partials/service-alerts        — a MACHINE is due for a job (derived);
          • partials/vehicle-ticket-alerts — a RIDER reported a fault;
          • partials/workshop-alerts       — a DATED errand somebody must go on;
          • this one                       — SOMEONE'S WORKING HOURS would change, and it
            is waiting on the person reading this. Nothing has happened yet.
        Separate audiences, separate lifecycles. Do not merge them.

     WHO SEES IT is the endpoint's decision, never this file's: /shift-rules/approvals
     answers with an empty queue for anyone who ranks below the change, so this partial is
     safe to include anywhere — it renders nothing for people it is not for.

     ⭐ NO LOCAL DISMISSAL, deliberately. The only ways a card leaves are decisions the
        server records: approve, decline, the requester withdrawing, or the day passing.
        A "seen" flag here would hide a question that is still open — the opposite of the
        workshop notices, which report something that already happened.

     Self-contained; include with @include('partials.shift-approval-alerts'). --}}
{{-- Shares the #nfCornerStack host with the other corner banners — see service-alerts. --}}
<div id="shiftApprovals" style="display:flex;flex-direction:column;gap:8px;"></div>
<script>(function(){var h=document.getElementById('nfCornerStack');if(!h){h=document.createElement('div');h.id='nfCornerStack';h.style.cssText='position:fixed;right:16px;bottom:16px;z-index:10990;display:flex;flex-direction:column;gap:8px;max-width:360px;';document.body.appendChild(h);}var me=document.getElementById('shiftApprovals');if(me&&me.parentNode!==h)h.insertBefore(me,h.firstChild);})();</script>
<script>
(function(){
  if (window.__shiftApprovalsLoaded) return; // include-once guard
  window.__shiftApprovalsLoaded = true;

  var CSRF = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
  function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
  function post(url, body, cb){
    fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
                 body:JSON.stringify(body||{}) })
      .then(function(r){ return r.json(); })
      .then(function(j){ cb(!!j.success, j.message||''); })
      .catch(function(){ cb(false, 'Network error'); });
  }

  function card(v){
    var el = document.createElement('div');
    el.style.cssText = 'background:#92400E;color:#fff;border-radius:10px;padding:10px 12px;'
      + 'box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
    /* ⭐ The sentence a manager has to be able to read in one glance: WHO would move,
       to WHAT, WHEN, and WHO asked. Then the fact that matters most — the person has
       not been told, so declining costs nothing. */
    el.innerHTML = '<div><b>⏳ A shift change needs your approval</b><br>'
      + '<b>' + esc(v.person) + '</b> → ' + esc(v.shift_name)
      + (v.shift_time ? ' <span style="opacity:.85;">' + esc(v.shift_time) + '</span>' : '')
      + '<br>' + esc(v.when)
      + (v.location_name ? ' · 📍 ' + esc(v.location_name) : '')
      + '<br><span style="opacity:.85;">asked by <b>' + esc(v.asked_by || 'someone') + '</b>'
      + (v.asked_ago ? ' ' + esc(v.asked_ago) : '')
      + (v.source === 'mobile' ? ' · from the app' : '') + '</span>'
      + '<div style="opacity:.85;margin-top:3px;">' + esc(v.person) + ' has <b>not</b> been told'
      + (v.starts_today ? ' · <b>starts today</b>' : '') + '</div></div>'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">'
      + '<button data-a="ok" style="flex:1;min-width:88px;background:#16A34A;color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✓ Approve</button>'
      + '<button data-a="no" style="flex:1;min-width:88px;background:#7F1D1D;color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✖ Decline</button>'
      + '</div>';

    function finish(good, msg){
      el.innerHTML = '<div>' + (good ? '✓ ' : '⚠ ') + esc(msg || (good ? 'Done' : 'Could not do that')) + '</div>';
      setTimeout(function(){ el.remove(); poll(); }, 2500);
    }
    el.querySelector('[data-a="ok"]').addEventListener('click', function(){
      this.disabled = true; this.textContent = 'Approving…';
      post('/shift-rules/requests/' + v.id + '/approve', {}, finish);
    });
    el.querySelector('[data-a="no"]').addEventListener('click', function(){
      var why = window.prompt('Decline this shift change?\n\nA short reason (optional) — ' + (v.asked_by||'the person who asked') + ' will see it:', '');
      if (why === null) return;
      this.disabled = true; this.textContent = 'Declining…';
      post('/shift-rules/requests/' + v.id + '/decline', { reason: why || null }, finish);
    });
    return el;
  }

  function typeCard(t){
    var el = document.createElement('div');
    el.style.cssText = 'background:#3730A3;color:#fff;border-radius:10px;padding:10px 12px;'
      + 'box-shadow:0 3px 10px rgba(0,0,0,.25);font-size:13px;line-height:1.45;';
    el.innerHTML = '<div><b>🆕 A new shift type needs your approval</b><br>'
      + '<b>' + esc(t.name) + '</b> <span style="opacity:.85;">' + esc(t.time) + '</span>'
      + (t.days ? '<br><span style="opacity:.85;">' + esc(t.days) + '</span>' : '')
      + '<br><span style="opacity:.85;">proposed by <b>' + esc(t.proposed_by) + '</b></span>'
      + '<div style="opacity:.85;margin-top:3px;">Nobody can be put on it until you approve.</div></div>'
      + '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">'
      + '<button data-a="ok" style="flex:1;min-width:88px;background:#16A34A;color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✓ Approve</button>'
      + '<button data-a="no" style="flex:1;min-width:88px;background:#7F1D1D;color:#fff;border:0;border-radius:7px;padding:7px 9px;font-weight:700;font-size:12.5px;cursor:pointer;">✖ Decline</button>'
      + '</div>';
    function finish(good, msg){
      el.innerHTML = '<div>' + (good ? '✓ ' : '⚠ ') + esc(msg || '') + '</div>';
      setTimeout(function(){ el.remove(); poll(); }, 2500);
    }
    el.querySelector('[data-a="ok"]').addEventListener('click', function(){
      this.disabled = true; this.textContent = 'Approving…';
      post('/shift-rules/types/' + t.id + '/approve', {}, finish);
    });
    el.querySelector('[data-a="no"]').addEventListener('click', function(){
      var why = window.prompt('Decline "' + t.name + '"?\n\nA short reason (optional) — ' + t.proposed_by + ' will see it:', '');
      if (why === null) return;
      this.disabled = true; this.textContent = 'Declining…';
      post('/shift-rules/types/' + t.id + '/decline', { reason: why || null }, finish);
    });
    return el;
  }

  function render(j){
    var box = document.getElementById('shiftApprovals');
    if (!box) return;
    box.innerHTML = '';
    var list = (j && j.pending) || [];
    var types = (j && j.pending_templates) || [];
    /* Three at a time, most urgent first — the endpoint already orders by start date, so
       a change that begins today sits above one that begins next week. */
    list.slice(0, 3).forEach(function(v){ box.appendChild(card(v)); });
    types.slice(0, 2).forEach(function(t){ box.appendChild(typeCard(t)); });
    var extra = Math.max(0, list.length - 3) + Math.max(0, types.length - 2);
    if (extra > 0){
      var more = document.createElement('div');
      more.style.cssText = 'background:#1F2937;color:#fff;border-radius:10px;padding:7px 12px;font-size:12px;';
      more.innerHTML = '+ ' + extra + ' more waiting — <a href="/shift-rules" style="color:#FDE68A;font-weight:700;">open Shift rules</a>';
      box.appendChild(more);
    }
  }

  function poll(){
    fetch('/shift-rules/approvals', { headers:{'Accept':'application/json'} })
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(j){ if (j) render(j); })
      .catch(function(){ /* a poll that fails simply leaves the last state up */ });
  }

  poll();
  setInterval(poll, 60000);
  /* A decision made in another tab (or on the phone) should clear the card here too. */
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
  window.refreshShiftApprovals = poll;
})();
</script>
