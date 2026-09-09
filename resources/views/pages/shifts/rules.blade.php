@extends('layouts.app')

@section('title', 'Shift Rules')

@section('content')
{{-- ⚙ SHIFT RULES — Taimur's page (Sep-2026).

     ⚠⚠ ONLY the holder of `manage_shift_rules` can reach this. The controller aborts 403
        and every endpoint re-checks; nothing here is a client-side secret.

     ⭐ THE DESIGN POINT, from the owner review: the ladder is not a matrix of tick-boxes.
        It is an ordered list, and under it a sentence in plain English that REWRITES ITSELF
        as you drag — so Taimur reads the rule back in his own words before he trusts it.
        Every row says "and himself" out loud, because "you cannot set your own shift" was
        the whole reason this page exists and must not read like a footnote. --}}
<style>
  #srWrap { --brand:#B91C1C; --brand-soft:#FBECEC; --line:#E3E8F0; --ink:#0f172a; --mute:#64748b; }
  #srWrap .sr-card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px 20px; margin-bottom:16px; }
  #srWrap .sr-h { font-size:15px; font-weight:700; color:var(--ink); margin:0 0 4px; }
  #srWrap .sr-sub { font-size:12.5px; color:var(--mute); margin:0 0 14px; max-width:70ch; line-height:1.55; }
  #srWrap .sr-rung { display:grid; grid-template-columns:26px minmax(140px,auto) 1fr auto; gap:14px; align-items:center;
                     border:1px solid var(--line); border-radius:9px; padding:10px 12px; margin-bottom:6px; background:#fff; }
  #srWrap .sr-rank { font-size:12px; color:var(--mute); font-variant-numeric:tabular-nums; }
  #srWrap .sr-name { font-weight:700; font-size:13.5px; color:var(--ink); white-space:nowrap; }
  #srWrap .sr-role { font-weight:400; font-size:11.5px; color:#94a3b8; margin-left:6px; }
  #srWrap .sr-can { font-size:12.5px; color:#475569; }
  #srWrap .sr-can b { color:var(--ink); }
  #srWrap .sr-arrows button { border:1px solid var(--line); background:#fff; border-radius:6px; padding:2px 8px; cursor:pointer; font-size:12px; color:#475569; }
  #srWrap .sr-arrows button:disabled { opacity:.35; cursor:default; }
  #srWrap .sr-plain { background:#F8FAFC; border:1px solid #E8EDF3; border-radius:9px; padding:11px 13px; font-size:12.5px; line-height:1.7; color:#334155; margin-top:10px; }
  #srWrap .sr-add { width:100%; text-align:left; border:1px dashed var(--line); background:none; color:#64748b;
                    border-radius:9px; padding:9px 12px; font-size:12.5px; font-weight:600; cursor:pointer; margin-top:4px; }
  #srWrap .sr-sw { display:flex; align-items:flex-start; gap:10px; cursor:pointer; user-select:none; margin-top:14px; }
  #srWrap .sr-sw input { position:absolute; opacity:0; }
  #srWrap .sr-k { width:34px; height:20px; border-radius:20px; background:#CBD5E1; position:relative; transition:background .15s; flex:none; margin-top:1px; }
  #srWrap .sr-k::after { content:""; position:absolute; top:3px; left:3px; width:14px; height:14px; border-radius:50%; background:#fff; transition:left .15s; box-shadow:0 1px 2px rgba(0,0,0,.25); }
  #srWrap .sr-sw input:checked + .sr-k { background:#16A34A; }
  #srWrap .sr-sw input:checked + .sr-k::after { left:17px; }
  #srWrap .sr-sw .l { font-size:13px; font-weight:600; color:var(--ink); }
  #srWrap .sr-sw .d { display:block; font-size:11.5px; font-weight:400; color:var(--mute); margin-top:1px; line-height:1.5; }
  #srWrap table.sr-t { width:100%; border-collapse:collapse; font-size:12.5px; }
  #srWrap table.sr-t th { text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--mute); font-weight:700; padding:0 8px 8px; border-bottom:1px solid var(--line); }
  #srWrap table.sr-t td { padding:10px 8px; border-bottom:1px solid #F1F5F9; vertical-align:middle; }
  #srWrap table.sr-t tr:last-child td { border-bottom:0; }
  #srWrap .sr-p { font-weight:700; color:var(--ink); white-space:nowrap; }
  #srWrap .sr-p small { display:block; font-weight:400; color:#94a3b8; font-size:11px; }
  #srWrap .sr-chips { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
  #srWrap .sr-chip { border:1px solid var(--line); background:#F8FAFC; border-radius:20px; padding:1px 9px; font-size:11.5px; color:#334155; }
  #srWrap .sr-chip.all { background:none; border-style:dashed; color:#94a3b8; }
  #srWrap .sr-edit { border:0; background:none; color:var(--brand); font-weight:700; font-size:11.5px; cursor:pointer; padding:0 4px; }
  #srWrap .sr-radio { display:grid; grid-template-columns:18px 1fr; gap:10px; align-items:start; border:1px solid var(--line);
                      border-radius:9px; padding:10px 12px; margin-bottom:7px; cursor:pointer; }
  #srWrap .sr-radio.on { border-color:var(--brand); background:var(--brand-soft); }
  #srWrap .sr-radio b { display:block; font-size:13px; color:var(--ink); }
  #srWrap .sr-radio span { font-size:11.5px; color:var(--mute); }
  #srWrap .sr-wait { border-color:#FDE68A; background:#FFFBEB; }
  #srWrap .sr-wcard { display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;
                      background:#fff; border:1px solid #FDE68A; border-radius:9px; padding:10px 12px; margin-bottom:7px; }
  #srWrap .sr-btn { border:0; border-radius:7px; padding:6px 12px; font-size:12.5px; font-weight:700; cursor:pointer; }
  #srWrap .sr-ok { background:#16A34A; color:#fff; }
  #srWrap .sr-no { background:#fff; color:#B42318; border:1px solid #FECDCA; }
  #srWrap .sr-only { display:inline-flex; align-items:center; gap:6px; font-size:11.5px; font-weight:700; color:#92400E;
                     background:#FEF3C7; border:1px solid #FDE68A; border-radius:20px; padding:3px 11px; }
  #srToast { position:fixed; left:50%; bottom:22px; transform:translateX(-50%); background:#0f172a; color:#fff;
             padding:8px 15px; border-radius:8px; font-size:13px; opacity:0; transition:opacity .2s; pointer-events:none; z-index:11000; }
  #srToast.on { opacity:1; }
  .sr-modal { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:10000; display:none; align-items:center; justify-content:center; padding:16px; }
  .sr-modal .box { background:#fff; border-radius:14px; width:100%; max-width:430px; max-height:calc(100vh - 32px); overflow-y:auto; padding:18px 20px; }
</style>

<div id="srWrap" class="p-4 md:p-6" style="max-width:920px;margin:0 auto;">

  <div style="margin-bottom:14px;">
    <div style="font-size:11.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">
      <a href="/shift-planner" style="color:#94a3b8;">Shift Planner</a> › Shift rules
    </div>
    <h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:3px 0 0;">Shift rules</h1>
    <p style="font-size:13px;color:#64748b;margin:4px 0 0;max-width:64ch;">
      Who may change whose shift, which shifts a person may be put on, and what waits for your
      approval. Every change here saves on its own.
    </p>
    <div style="margin-top:9px;"><span class="sr-only">🔐 Only you can see this page</span></div>
  </div>

  <div id="srNotReady" class="sr-card" style="display:none;border-color:#FECDCA;background:#FEF3F2;">
    <div class="sr-h" style="color:#B42318;">The shift-rules tables are not on this server yet</div>
    <p class="sr-sub" style="margin:0;">Run <code>database/migrations/shift_authority_sep2026.sql</code> first.
      Until then everything below is inactive and shifts behave as they did before.</p>
  </div>

  {{-- ⏳ A question waiting on this person outranks settings — same ruling as the workshop
       queue. It is rendered first and hidden entirely when there is nothing to answer. --}}
  <div id="srWaitCard" class="sr-card sr-wait" style="display:none;">
    <div class="sr-h">⏳ Waiting for you <span id="srWaitCount" style="font-weight:400;color:#92400E;"></span></div>
    <p class="sr-sub" style="margin-bottom:10px;">Nothing here has happened yet. The person whose shift
      it is has not been told, so declining costs nothing.</p>
    <div id="srWaitList"></div>
  </div>

  <div class="sr-card">
    <div class="sr-h">1 · Who may change whose shift</div>
    <p class="sr-sub">Higher on the ladder means more reach. A person can change the shift of anyone
      <b>below</b> them, and not their own. Riders and everyone not on the ladder sit at the bottom
      and change nobody.</p>
    <div id="srLadder"></div>
    <button class="sr-add" onclick="srAddToLadder()">＋ Add someone to the ladder</button>
    <div class="sr-plain" id="srPlain"></div>
    <label class="sr-sw">
      <input type="checkbox" id="srSelf" onchange="srSaveSelf()"><span class="sr-k"></span>
      <span class="l">Managers may set their own shift
        <span class="d">Off: nobody on the ladder sets their own — except you, since there is nobody
          above you to ask. On: they can, and it still needs your approval if their row below says so.</span>
      </span>
    </label>
  </div>

  <div class="sr-card">
    <div class="sr-h">2 · Per-person rules</div>
    <p class="sr-sub">Only people with a rule appear here. Everyone else: any shift, cannot set their
      own, no approval needed. <b>"Allowed shifts" limits every picker on the web and the phone</b> for
      that person. You are never limited by it — your own picker always shows every shift.</p>
    <div style="overflow-x:auto;">
      <table class="sr-t">
        <thead><tr><th>Person</th><th>Allowed shifts</th><th>Changes need approval</th><th></th></tr></thead>
        <tbody id="srPeople"></tbody>
      </table>
    </div>
    <div id="srNoPeople" style="display:none;font-size:12.5px;color:#94a3b8;padding:12px 8px;">
      No per-person rules yet — everybody can be put on any shift.
    </div>
    <button class="sr-add" onclick="srAddPerson()">＋ Add a person (anyone, riders too)</button>
  </div>

  <div class="sr-card">
    <div class="sr-h">3 · New shift types</div>
    <p class="sr-sub">A shift type is a set of hours anyone can then be put on — not a single person's day.</p>
    <div id="srPolicy"></div>
  </div>
</div>

<div id="srToast"></div>

{{-- allowed-shifts picker --}}
<div class="sr-modal" id="srShiftModal" onclick="if(event.target===this)srCloseShifts()">
  <div class="box">
    <div style="font-size:16px;font-weight:700;color:#0f172a;">Allowed shifts · <span id="srShiftWho"></span></div>
    <p style="font-size:12px;color:#64748b;margin:5px 0 12px;">Tick the shifts this person may be put on.
      <b>Tick nothing to allow every shift.</b></p>
    <div id="srShiftList" style="display:flex;flex-direction:column;gap:6px;"></div>
    <div style="display:flex;gap:9px;margin-top:16px;">
      <button class="sr-btn" style="background:#fff;border:1px solid #E3E8F0;color:#475569;" onclick="srCloseShifts()">Cancel</button>
      <button class="sr-btn sr-ok" style="flex:1;" onclick="srSaveShifts()">Save</button>
    </div>
  </div>
</div>

{{-- person picker (used by both "add" buttons) --}}
<div class="sr-modal" id="srPickModal" onclick="if(event.target===this)srClosePick()">
  <div class="box">
    <div style="font-size:16px;font-weight:700;color:#0f172a;" id="srPickTitle">Add a person</div>
    <input id="srPickSearch" placeholder="Search name…" oninput="srRenderPick()" autocomplete="off"
           style="width:100%;margin-top:10px;padding:9px 11px;border:1px solid #E3E8F0;border-radius:8px;font-size:13px;">
    <div id="srPickList" style="max-height:340px;overflow-y:auto;margin-top:10px;display:flex;flex-direction:column;gap:4px;"></div>
    <button class="sr-btn" style="background:#fff;border:1px solid #E3E8F0;color:#475569;margin-top:14px;width:100%;" onclick="srClosePick()">Cancel</button>
  </div>
</div>

<script>
(function(){
  var CSRF = (document.querySelector('meta[name="csrf-token"]')||{}).content || '';
  var D = null;          // the whole payload from /shift-rules/data
  var pickMode = null;   // 'ladder' | 'person'
  var shiftTarget = null;

  function esc(s){ var d=document.createElement('div'); d.textContent=String(s==null?'':s); return d.innerHTML; }
  function post(url, body){
    return fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF},
                        body:JSON.stringify(body||{}) }).then(function(r){ return r.json(); })
           .catch(function(){ return { success:false, message:'Network error' }; });
  }
  var toastTimer = null;
  function toast(m){
    var t = document.getElementById('srToast');
    t.textContent = m; t.classList.add('on');
    clearTimeout(toastTimer); toastTimer = setTimeout(function(){ t.classList.remove('on'); }, 2000);
  }
  function join(a){ return a.length < 2 ? (a[0]||'') : a.slice(0,-1).join(', ') + ' and ' + a[a.length-1]; }
  /* Alternatives, not a committee — any one of these people can answer it. */
  function joinOr(a){ return a.length < 2 ? (a[0]||'') : a.slice(0,-1).join(', ') + ' or ' + a[a.length-1]; }

  /* ── the sentence, and the one on every rung ──────────────────────────────
     ⭐ Owner ruling: "and himself" is spelled out on EVERY row. The top rung is the
     exception and says so — he is the one person with nobody above him to ask. */
  function canText(i){
    var above = D.ladder.slice(0, i).map(function(x){ return x.name; });
    if (i === 0) return 'can change everyone, <b>including himself</b>';
    /* ⚠ "himself" joins the SAME list rather than being appended after it — otherwise two
       people above produce "except Taimur and Shabib and himself" instead of
       "except Taimur, Shabib and himself". */
    if (!D.self_assign){
      var ex = above.concat(['himself']);
      return 'can change everyone except ' + esc(join(ex)).replace('himself', '<b>himself</b>');
    }
    return 'can change everyone except ' + esc(join(above)) + ' — <b>his own shift too</b>';
  }

  function renderLadder(){
    var box = document.getElementById('srLadder');
    box.innerHTML = '';
    if (!D.ladder.length){
      box.innerHTML = '<div style="font-size:12.5px;color:#94a3b8;padding:8px 2px;">Nobody on the ladder yet — no one can change anyone else\'s shift.</div>';
    }
    D.ladder.forEach(function(p, i){
      var el = document.createElement('div');
      el.className = 'sr-rung';
      el.innerHTML = '<span class="sr-rank">' + (D.ladder.length - i) + '</span>'
        + '<span class="sr-name">' + esc(p.name) + '<span class="sr-role">' + esc(p.role||'') + '</span></span>'
        + '<span class="sr-can">' + canText(i) + '</span>'
        + '<span class="sr-arrows">'
        +   '<button onclick="srMove(' + i + ',-1)" title="Move up"' + (i===0?' disabled':'') + '>▲</button> '
        +   '<button onclick="srMove(' + i + ',1)" title="Move down"' + (i===D.ladder.length-1?' disabled':'') + '>▼</button> '
        +   '<button onclick="srDropRung(' + p.user_id + ')" title="Take off the ladder">✕</button>'
        + '</span>';
      box.appendChild(el);
    });
    var plain = D.ladder.length
      ? '<b>In plain words:</b> ' + D.ladder.map(function(p,i){
          return '<b>' + esc(p.name) + '</b> ' + canText(i).replace(/<\/?b>/g,'');
        }).join(' · ') + '.'
      : '<b>In plain words:</b> nobody may change anybody else\'s shift.';
    document.getElementById('srPlain').innerHTML = plain;
  }

  function tplNames(ids){
    if (!ids || !ids.length) return null;
    return ids.map(function(id){
      var t = D.templates.find(function(x){ return x.id === id; });
      return t ? (t.name + ' ' + t.time) : ('#' + id);
    });
  }

  function renderPeople(){
    var body = document.getElementById('srPeople');
    body.innerHTML = '';
    document.getElementById('srNoPeople').style.display = D.people.length ? 'none' : 'block';
    D.people.forEach(function(p){
      var names = tplNames(p.allowed);
      var chips = names
        ? names.map(function(n){ return '<span class="sr-chip">' + esc(n) + '</span>'; }).join('')
        : '<span class="sr-chip all">All shifts</span>';
      /* Who would be asked, spelled out — so the toggle is never an abstract "someone".
         ⚠ "or", not "and": any ONE of them can answer it. "Taimur and Shabib approves"
         reads as needing both, which is not the rule. */
      var approvers = D.ladder.filter(function(x){ return x.rank > (p.rank||0); }).map(function(x){ return x.name; });
      var lbl = p.needs_approval
        ? (approvers.length ? esc(joinOr(approvers)) + ' approves' : 'nobody above — applies at once')
        : 'No';
      var tr = document.createElement('tr');
      tr.innerHTML = '<td class="sr-p">' + esc(p.name)
          + '<small>' + esc(p.role||'') + (p.rank ? ' · rank ' + p.rank : '') + '</small></td>'
        + '<td><div class="sr-chips">' + chips
          + '<button class="sr-edit" onclick="srEditShifts(' + p.user_id + ')">edit</button></div></td>'
        + '<td><label class="sr-sw" style="margin:0;">'
          + '<input type="checkbox" ' + (p.needs_approval?'checked':'') + ' onchange="srSavePerson(' + p.user_id + ', this.checked)">'
          + '<span class="sr-k"></span><span class="l" style="font-size:12px;">' + lbl + '</span></label></td>'
        + '<td style="text-align:right;"><button class="sr-edit" style="color:#94a3b8;" '
          + 'onclick="srRemovePerson(' + p.user_id + ')" title="Remove this rule">✕</button></td>';
      body.appendChild(tr);
    });
  }

  function renderPolicy(){
    var opts = [
      ['top_only', 'Only you', 'Others do not see "＋ new shift type" at all.'],
      ['approval', 'Anyone can propose, you approve', 'A proposed type shows as "⏳ waiting" in their picker and lands in Waiting for you. Recommended.'],
      ['anyone',   'Anyone on the ladder can create', 'What happened before this page existed.']
    ];
    document.getElementById('srPolicy').innerHTML = opts.map(function(o){
      return '<label class="sr-radio ' + (D.template_policy===o[0]?'on':'') + '">'
        + '<input type="radio" name="srpol" ' + (D.template_policy===o[0]?'checked':'') + ' onchange="srSavePolicy(\'' + o[0] + '\')">'
        + '<div><b>' + esc(o[1]) + '</b><span>' + esc(o[2]) + '</span></div></label>';
    }).join('');
  }

  function renderWaiting(){
    var list = D.pending_changes || [], types = D.pending_templates || [];
    var n = list.length + types.length;
    document.getElementById('srWaitCard').style.display = n ? 'block' : 'none';
    document.getElementById('srWaitCount').textContent = n ? '(' + n + ')' : '';
    var box = document.getElementById('srWaitList');
    box.innerHTML = '';
    list.forEach(function(v){
      var el = document.createElement('div');
      el.className = 'sr-wcard';
      el.innerHTML = '<div style="font-size:13px;line-height:1.5;">'
        + '<b>' + esc(v.person) + '</b> → ' + esc(v.shift_name) + ' <span style="color:#64748b;">' + esc(v.shift_time) + '</span><br>'
        + '<span style="color:#64748b;">' + esc(v.when)
        + (v.location_name ? ' · 📍 ' + esc(v.location_name) : '')
        + ' · asked by <b>' + esc(v.asked_by||'someone') + '</b> ' + esc(v.asked_ago||'') + '</span>'
        + (v.starts_today ? '<br><b style="color:#B42318;">Starts today</b>' : '') + '</div>'
        + '<div style="display:flex;gap:7px;">'
        +   '<button class="sr-btn sr-ok" onclick="srDecide(' + v.id + ',1,this)">✓ Approve</button>'
        +   '<button class="sr-btn sr-no" onclick="srDecide(' + v.id + ',0,this)">✖ Decline</button>'
        + '</div>';
      box.appendChild(el);
    });
    types.forEach(function(t){
      var el = document.createElement('div');
      el.className = 'sr-wcard';
      el.innerHTML = '<div style="font-size:13px;line-height:1.5;">'
        + '🆕 New shift type <b>' + esc(t.name) + '</b> <span style="color:#64748b;">' + esc(t.time) + '</span><br>'
        + '<span style="color:#64748b;">' + esc(t.days||'') + ' · proposed by <b>' + esc(t.proposed_by) + '</b></span></div>'
        + '<div style="display:flex;gap:7px;">'
        +   '<button class="sr-btn sr-ok" onclick="srDecideType(' + t.id + ',1,this)">✓ Approve</button>'
        +   '<button class="sr-btn sr-no" onclick="srDecideType(' + t.id + ',0,this)">✖ Decline</button>'
        + '</div>';
      box.appendChild(el);
    });
  }

  function renderAll(){
    document.getElementById('srNotReady').style.display = D.ready ? 'none' : 'block';
    document.getElementById('srSelf').checked = !!D.self_assign;
    renderLadder(); renderPeople(); renderPolicy(); renderWaiting();
  }

  function load(){
    fetch('/shift-rules/data', { headers:{'Accept':'application/json'} })
      .then(function(r){ return r.json(); })
      .then(function(j){ if (j.success){ D = j; renderAll(); } })
      .catch(function(){ toast('Could not load the rules.'); });
  }

  // ── ladder ────────────────────────────────────────────────────────────────
  window.srMove = function(i, dir){
    var t = D.ladder.splice(i, 1)[0];
    D.ladder.splice(i + dir, 0, t);
    renderLadder(); renderPeople();
    saveLadder();
  };
  window.srDropRung = function(uid){
    D.ladder = D.ladder.filter(function(x){ return x.user_id !== uid; });
    renderLadder(); renderPeople();
    saveLadder();
  };
  function saveLadder(){
    post('/shift-rules/ladder', { user_ids: D.ladder.map(function(x){ return x.user_id; }) })
      .then(function(j){ toast(j.message || (j.success ? 'Saved' : 'Could not save')); if (j.success) load(); });
  }

  window.srSaveSelf = function(){
    var on = document.getElementById('srSelf').checked;
    D.self_assign = on;
    renderLadder(); // the sentences change with the switch
    post('/shift-rules/switch', { key:'SHIFT_SELF_ASSIGN', value: on ? 'Y' : 'N' })
      .then(function(j){ toast(on ? 'Managers may now set their own shift' : 'Own-shift changes blocked again'); });
  };

  window.srSavePolicy = function(v){
    D.template_policy = v; renderPolicy();
    post('/shift-rules/switch', { key:'SHIFT_TYPE_CREATE_POLICY', value: v })
      .then(function(j){ toast(j.message || 'Saved'); });
  };

  // ── per-person ────────────────────────────────────────────────────────────
  window.srSavePerson = function(uid, needs){
    var p = D.people.find(function(x){ return x.user_id === uid; });
    if (p) p.needs_approval = needs;
    post('/shift-rules/person', { user_id: uid, needs_approval: needs ? 1 : 0, allowed: (p && p.allowed) || [] })
      .then(function(j){ toast(j.message || 'Saved'); load(); });
  };
  window.srRemovePerson = function(uid){
    var p = D.people.find(function(x){ return x.user_id === uid; });
    if (!confirm('Remove the rule for ' + (p?p.name:'this person') + '?\n\nThey go back to the default: any shift, cannot set their own, no approval.')) return;
    post('/shift-rules/person/remove', { user_id: uid }).then(function(j){ toast(j.message||'Removed'); load(); });
  };

  window.srEditShifts = function(uid){
    shiftTarget = D.people.find(function(x){ return x.user_id === uid; });
    if (!shiftTarget) return;
    document.getElementById('srShiftWho').textContent = shiftTarget.name;
    var cur = shiftTarget.allowed || [];
    document.getElementById('srShiftList').innerHTML = D.templates.map(function(t){
      return '<label style="display:flex;align-items:center;gap:9px;border:1px solid #E3E8F0;border-radius:8px;padding:8px 11px;cursor:pointer;font-size:13px;">'
        + '<input type="checkbox" value="' + t.id + '" ' + (cur.indexOf(t.id)>=0?'checked':'') + '>'
        + '<span><b>' + esc(t.name) + '</b> <span style="color:#64748b;">' + esc(t.time) + '</span></span></label>';
    }).join('');
    document.getElementById('srShiftModal').style.display = 'flex';
  };
  window.srCloseShifts = function(){ document.getElementById('srShiftModal').style.display = 'none'; };
  window.srSaveShifts = function(){
    var ids = [].slice.call(document.querySelectorAll('#srShiftList input:checked')).map(function(c){ return parseInt(c.value,10); });
    post('/shift-rules/person', { user_id: shiftTarget.user_id,
                                  needs_approval: shiftTarget.needs_approval ? 1 : 0, allowed: ids })
      .then(function(j){ toast(ids.length ? 'Limited to ' + ids.length + ' shift' + (ids.length>1?'s':'') : 'All shifts allowed'); srCloseShifts(); load(); });
  };

  // ── person picker ─────────────────────────────────────────────────────────
  window.srAddToLadder = function(){ pickMode = 'ladder'; openPick('Add someone to the ladder'); };
  window.srAddPerson   = function(){ pickMode = 'person'; openPick('Add a person'); };
  function openPick(title){
    document.getElementById('srPickTitle').textContent = title;
    document.getElementById('srPickSearch').value = '';
    srRenderPick();
    document.getElementById('srPickModal').style.display = 'flex';
  }
  window.srClosePick = function(){ document.getElementById('srPickModal').style.display = 'none'; };
  window.srRenderPick = function(){
    var q = (document.getElementById('srPickSearch').value || '').toLowerCase();
    var taken = (pickMode === 'ladder' ? D.ladder : D.people).map(function(x){ return x.user_id; });
    var rows = D.staff.filter(function(s){
      return taken.indexOf(s.user_id) < 0 && (!q || s.name.toLowerCase().indexOf(q) >= 0);
    }).slice(0, 60);
    document.getElementById('srPickList').innerHTML = rows.length ? rows.map(function(s){
      return '<button onclick="srPicked(' + s.user_id + ')" style="text-align:left;border:1px solid #E3E8F0;background:#fff;'
        + 'border-radius:8px;padding:8px 11px;cursor:pointer;font-size:13px;">'
        + '<b>' + esc(s.name) + '</b> <span style="color:#94a3b8;font-size:11.5px;">' + esc(s.role||'') + '</span></button>';
    }).join('') : '<div style="font-size:12.5px;color:#94a3b8;padding:8px;">Nobody left to add.</div>';
  };
  window.srPicked = function(uid){
    srClosePick();
    if (pickMode === 'ladder'){
      /* New people join at the BOTTOM of the ladder — the safe end. Promoting is one
         press of ▲; joining at the top by accident would hand out real authority. */
      D.ladder.push({ user_id: uid });
      saveLadder();
    } else {
      post('/shift-rules/person', { user_id: uid, needs_approval: 0, allowed: [] })
        .then(function(j){ toast(j.message || 'Added'); load(); });
    }
  };

  // ── answering ─────────────────────────────────────────────────────────────
  window.srDecide = function(id, yes, btn){
    var reason = null;
    if (!yes){
      reason = window.prompt('Decline this shift change?\n\nA short reason (optional) — the person who asked will see it:', '');
      if (reason === null) return;
    }
    btn.disabled = true; btn.textContent = yes ? 'Approving…' : 'Declining…';
    post('/shift-rules/requests/' + id + (yes ? '/approve' : '/decline'), { reason: reason || null })
      .then(function(j){ toast(j.message || ''); load(); if (window.refreshShiftApprovals) window.refreshShiftApprovals(); });
  };
  window.srDecideType = function(id, yes, btn){
    var reason = null;
    if (!yes){
      reason = window.prompt('Decline this shift type?\n\nA short reason (optional):', '');
      if (reason === null) return;
    }
    btn.disabled = true; btn.textContent = yes ? 'Approving…' : 'Declining…';
    post('/shift-rules/types/' + id + (yes ? '/approve' : '/decline'), { reason: reason || null })
      .then(function(j){ toast(j.message || ''); load(); if (window.refreshShiftApprovals) window.refreshShiftApprovals(); });
  };

  load();
  /* A decision taken on the phone should show up here without a reload. */
  setInterval(function(){ if (!document.hidden) load(); }, 60000);
})();
</script>
@endsection
