@extends('layouts.app')

@section('title', 'Shift Planner')

@section('content')
<style>
  #planWrap { --brand:#B91C1C; --brand-soft:#FBECEC; --line:#E3E8F0; }
  .plan-scroll { overflow-x:auto; }
  .plan-table { border-collapse:separate; border-spacing:0; width:100%; min-width:860px; }
  .plan-table th, .plan-table td { border-bottom:1px solid var(--line); padding:8px 10px; vertical-align:top; }
  .plan-table thead th { position:sticky; top:0; background:#FBFCFE; z-index:2; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; font-weight:700; text-align:left; }
  .plan-table tbody td:first-child, .plan-table thead th:first-child { position:sticky; left:0; background:#fff; z-index:1; min-width:210px; box-shadow:1px 0 0 var(--line); }
  .plan-table thead th:first-child { z-index:3; background:#FBFCFE; }
  .cell-chip { display:inline-flex; flex-direction:column; gap:1px; font-size:11.5px; line-height:1.25; border-radius:7px; padding:4px 7px; white-space:nowrap; font-variant-numeric:tabular-nums; border:1px solid transparent; }
  .chip-work { background:#F1F5F9; color:#0f172a; border-color:#E2E8F0; }
  .chip-override { background:var(--brand-soft); color:#8E1414; border-color:#F3D6D6; font-weight:600; }
  .chip-off { background:repeating-linear-gradient(135deg,#F8FAFC,#F8FAFC 5px,#EEF2F7 5px,#EEF2F7 10px); color:#94a3b8; border-style:dashed; border-color:#e2e8f0; }
  .chip-holiday { background:#FEF3F2; color:#B42318; border-color:#FECDCA; }
  .chip-nm { font-weight:600; }
  .col-today { background:#FFFDF5; }
  .rider-cb { margin-right:8px; }
  .pchip { display:inline-flex; align-items:center; gap:5px; font-size:11px; font-weight:600; background:#EEF2F7; color:#334155; border:1px solid var(--line); border-radius:6px; padding:2px 7px; font-variant-numeric:tabular-nums; }
  .badge-chg { display:inline-flex; align-items:center; gap:4px; font-size:10.5px; font-weight:700; background:var(--brand-soft); color:#8E1414; border-radius:20px; padding:2px 8px; margin-top:4px; }
  .badge-ack { background:#FEF9C3; color:#854d0e; }
  .ack-yes { color:#15803d; font-weight:700; }
  .ack-no { color:#b45309; font-weight:700; }
  #awaitChip { display:none; align-items:center; gap:5px; font-size:12px; font-weight:700; background:#FEF9C3; color:#854d0e; border:1px solid #FDE68A; border-radius:20px; padding:4px 11px; }
  .modal-bg { position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:9999; display:none; align-items:center; justify-content:center; padding:16px; }
  .seg { display:inline-flex; border:1px solid var(--line); border-radius:8px; overflow:hidden; }
  .seg button { padding:6px 12px; font-size:12.5px; background:#fff; color:#475569; border:0; cursor:pointer; }
  .seg button.on { background:var(--brand); color:#fff; font-weight:600; }
  .mode-opt { border:1px solid var(--line); border-radius:9px; padding:9px 11px; cursor:pointer; font-size:13px; }
  .mode-opt.on { border-color:var(--brand); background:var(--brand-soft); }
  .mode-opt .mt { font-weight:600; color:#0f172a; }
  .mode-opt .md { font-size:11px; color:#64748b; margin-top:1px; }
  /* Self-contained modal — scoped under #assignModal so the Metronic theme's
     component CSS can't override the width or hide the buttons. */
  #assignModal .pm-card{ background:#fff; border-radius:14px; box-shadow:0 25px 60px rgba(15,23,42,.35); width:100%; max-width:440px; margin:auto; max-height:calc(100vh - 32px); overflow-y:auto; }
  #assignModal .pm-head{ display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid var(--line); }
  #assignModal .pm-head h2{ font-size:17px; font-weight:600; margin:0; color:#0f172a; }
  #assignModal .pm-x{ background:none; border:0; font-size:24px; line-height:1; color:#94a3b8; cursor:pointer; }
  #assignModal .pm-body{ padding:18px; }
  #assignModal .pm-label{ display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:14px 0 6px; }
  #assignModal .pm-body > .pm-label:first-child{ margin-top:0; }
  #assignModal .pm-input{ width:100%; padding:9px 11px; font-size:13px; border:1px solid var(--line); border-radius:8px; background:#fff; color:#0f172a; }
  #assignModal .mode-opt{ margin-bottom:7px; }
  #assignModal .pm-dates{ display:flex; gap:12px; }
  #assignModal .pm-dates > div{ flex:1; }
  #assignModal .pm-effect{ font-size:12px; color:#37485f; background:#F1F5FB; border:1px solid #E1E8F2; border-radius:8px; padding:9px 11px; margin:14px 0 0; line-height:1.5; }
  #assignModal .pm-actions{ display:flex; gap:10px; margin-top:16px; }
  #assignModal .pm-save{ flex:1; background:var(--brand); color:#fff; border:0; border-radius:9px; padding:11px; font-size:14px; font-weight:600; cursor:pointer; }
  #assignModal .pm-save:hover{ background:#a11818; }
  #assignModal .pm-cancel{ padding:11px 16px; background:#fff; color:#475569; border:1px solid var(--line); border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; }
</style>

<div id="planWrap" class="p-4 md:p-6">
  <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Shift Planner</h1>
      <p class="text-sm text-gray-500 mt-1">See and change who works which shift. Temporary changes come back to the primary automatically.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/shifts" class="px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100">Shift types</a>
      <a href="/holidays" class="px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100">Holidays</a>
    </div>
  </div>

  <!-- toolbar -->
  <div class="flex flex-wrap items-center gap-3 mb-3">
    <div class="flex items-center gap-2">
      <button onclick="stepWeek(-1)" class="w-8 h-8 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-100">‹</button>
      <span id="weekLabel" class="text-sm font-semibold text-gray-800 min-w-[150px] text-center">…</span>
      <button onclick="stepWeek(1)" class="w-8 h-8 border border-gray-300 rounded-lg text-gray-600 hover:bg-gray-100">›</button>
      <button onclick="goThisWeek()" class="px-2.5 py-1.5 text-xs text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100">This week</button>
    </div>
    <div class="seg">
      <button id="fltRiders" class="on" onclick="setFilter('riders')">Riders</button>
      <button id="fltAll" onclick="setFilter('all')">All staff</button>
    </div>
    <input id="searchBox" oninput="debouncedSearch()" placeholder="Search name…" class="px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-200 w-44">
    <span id="awaitChip">⏳ <span id="awaitCount">0</span> awaiting confirmation</span>
    <div class="text-xs text-gray-400 flex items-center gap-3 ml-auto">
      <span><span class="inline-block w-3 h-3 rounded-sm align-middle" style="background:#FBECEC;border:1px solid #F3D6D6"></span> temporary change</span>
      <span><span class="inline-block w-3 h-3 rounded-sm align-middle" style="background:#EEF2F7;border:1px dashed #cbd5e1"></span> off day</span>
    </div>
  </div>

  <!-- bulk bar -->
  <div id="bulkBar" class="hidden items-center gap-3 mb-3 p-2.5 bg-red-50 border border-red-200 rounded-lg text-sm">
    <span id="bulkCount" class="font-semibold text-red-800">0 selected</span>
    <button onclick="openAssignBulk()" class="px-3 py-1.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700">Change shift for selected</button>
    <button onclick="clearSel()" class="text-gray-500 hover:text-gray-700">Clear</button>
  </div>

  <div class="bg-white border border-gray-200 rounded-lg plan-scroll">
    <table class="plan-table">
      <thead><tr id="headRow"></tr></thead>
      <tbody id="planBody"></tbody>
    </table>
  </div>
  <div id="planEmpty" class="hidden text-center text-gray-400 py-10 text-sm">No staff match this view.</div>
</div>

<!-- Assign / change modal -->
<div id="assignModal" class="modal-bg">
  <div class="pm-card" onclick="event.stopPropagation()">
    <div class="pm-head">
      <h2 id="assignTitle">Change shift</h2>
      <button class="pm-x" onclick="closeAssign()">&times;</button>
    </div>
    <div class="pm-body">
      <label class="pm-label">Shift</label>
      <select id="mTemplate" class="pm-input" onchange="renderEffect()"></select>

      <label class="pm-label">How long?</label>
      <div class="mode-opt" data-mode="until_changed" onclick="setMode('until_changed')">
        <div class="mt">Set new primary shift</div>
        <div class="md">Until I change it — this becomes their normal shift.</div>
      </div>
      <div class="mode-opt" data-mode="one_day" onclick="setMode('one_day')">
        <div class="mt">Just one day</div>
        <div class="md">Temporary — their normal shift comes back after.</div>
      </div>
      <div class="mode-opt" data-mode="date_range" onclick="setMode('date_range')">
        <div class="mt">Start &amp; end date</div>
        <div class="md">Temporary — for a set period, then back to normal.</div>
      </div>

      <div class="pm-dates">
        <div><label id="fromLabel" class="pm-label">From</label><input type="date" id="mFrom" class="pm-input" onchange="renderEffect()"></div>
        <div id="toWrap"><label class="pm-label">To</label><input type="date" id="mTo" class="pm-input" onchange="renderEffect()"></div>
      </div>

      <p id="effectLine" class="pm-effect"></p>
      <div class="pm-actions">
        <button class="pm-cancel" onclick="closeAssign()">Cancel</button>
        <button id="saveBtn" class="pm-save" onclick="saveAssign()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let WEEK = null;         // current week_start (Y-m-d)
let FILTER = 'riders';
let SEARCH = '';
let DATA = null;
let SEL = new Set();     // selected user ids
let TARGET = [];         // users being assigned in the modal [{id,name}]
let MODE = 'until_changed';

function post(url, body) {
  return fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(body) }).then(r=>r.json());
}
function fmt(d) { const x = new Date(d+'T00:00:00'); return x.toLocaleDateString(undefined,{day:'numeric',month:'short'}); }

async function loadWeek(start) {
  const p = new URLSearchParams({ filter:FILTER, search:SEARCH });
  if (start) p.set('start', start);
  const json = await fetch('/shift-planner/week?'+p.toString()).then(r=>r.json());
  if (!json.success) { alert('Failed to load planner'); return; }
  DATA = json; WEEK = json.week_start;
  document.getElementById('weekLabel').textContent = fmt(json.week_start)+' – '+fmt(json.week_end);
  renderGrid();
}
function stepWeek(dir){ loadWeek(dir<0 ? DATA.prev_week : DATA.next_week); }
function goThisWeek(){ loadWeek(DATA.this_week); }
function setFilter(f){ FILTER=f; document.getElementById('fltRiders').classList.toggle('on',f==='riders'); document.getElementById('fltAll').classList.toggle('on',f==='all'); clearSel(); loadWeek(WEEK); }
let searchTimer=null;
function debouncedSearch(){ clearTimeout(searchTimer); searchTimer=setTimeout(()=>{ SEARCH=document.getElementById('searchBox').value.trim(); loadWeek(WEEK); },300); }

function renderGrid() {
  const head = document.getElementById('headRow');
  head.innerHTML = '<th>Rider</th>' + DATA.days.map(d=>{
    const isToday = d.date===DATA.today;
    const hol = DATA.holiday_names[d.date];
    return `<th class="${isToday?'col-today':''}">${d.label} ${d.day}${hol?`<div class="text-[10px] text-red-500 font-semibold normal-case">${hol}</div>`:''}</th>`;
  }).join('');

  const body = document.getElementById('planBody');
  const empty = document.getElementById('planEmpty');
  if (!DATA.riders.length) { body.innerHTML=''; empty.classList.remove('hidden'); return; }
  empty.classList.add('hidden');

  let awaiting = 0;
  body.innerHTML = DATA.riders.map(r=>{
    const changes = r.changes.map(c=>{
      const lbl = c.kind==='temporary'
        ? `${c.shift_name||'—'} · ${fmt(c.from)}${c.to&&c.to!==c.from?'–'+fmt(c.to):''}`
        : `${c.shift_name||'—'} · from ${fmt(c.from)}`;
      if (!c.acknowledged) awaiting++;
      const ackTxt = c.acknowledged
        ? `<span class="ack-yes">✓ confirmed</span>`
        : `<span class="ack-no">⏳ awaiting</span>`;
      const ackCls = c.acknowledged ? '' : 'badge-ack';
      return `<span class="badge-chg ${ackCls}">${c.kind==='temporary'?'⏱':'⏭'} ${lbl} ${ackTxt}
        <button title="Cancel this change" onclick="cancelChange(${c.assignment_id})" class="ml-1 text-red-700 hover:text-red-900 font-bold">×</button></span>`;
    }).join(' ');

    const cells = r.cells.map(c=>{
      const isToday = c.date===DATA.today;
      let cls='chip-work', body='';
      if (c.is_holiday) { cls='chip-holiday'; body=`<span>Holiday</span>`; }
      else if (c.is_off) { cls='chip-off'; body=`<span>Off</span>`; }
      else { cls = c.is_override?'chip-override':'chip-work'; body=`<span class="chip-nm">${c.start}–${c.end}</span><span class="text-[10px] opacity-70">${c.shift_name}</span>`; }
      return `<td class="${isToday?'col-today':''}"><span class="cell-chip ${cls}">${body}</span></td>`;
    }).join('');

    const checked = SEL.has(r.user_id)?'checked':'';
    return `<tr>
      <td>
        <label class="flex items-start cursor-pointer">
          <input type="checkbox" class="rider-cb mt-0.5" ${checked} onchange="toggleSel(${r.user_id})">
          <span>
            <span class="font-semibold text-sm text-gray-900">${r.name}</span>
            <span class="block text-[11px] text-gray-400">${r.role||''}</span>
            <span class="pchip mt-1">${r.primary.start}–${r.primary.end} · ${r.primary.shift_name}</span>
            <button onclick="openAssignOne(${r.user_id})" class="ml-1 text-xs text-red-600 font-semibold hover:underline">Change</button>
            ${r.has_phone===false ? `<button onclick="event.preventDefault();addNumber(${r.user_id}, ${JSON.stringify(r.name)})" title="No WhatsApp number — riders can't be notified of shift changes" class="ml-1 text-[11px] text-amber-600 font-semibold hover:underline">📱 add number</button>` : ''}
            <div>${changes}</div>
          </span>
        </label>
      </td>
      ${cells}
    </tr>`;
  }).join('');
  const chip = document.getElementById('awaitChip');
  document.getElementById('awaitCount').textContent = awaiting;
  chip.style.display = awaiting ? 'inline-flex' : 'none';
  updateBulkBar();
}

/* selection */
function toggleSel(id){ SEL.has(id)?SEL.delete(id):SEL.add(id); updateBulkBar(); }
function clearSel(){ SEL.clear(); document.querySelectorAll('.rider-cb').forEach(c=>c.checked=false); updateBulkBar(); }
function updateBulkBar(){ const bar=document.getElementById('bulkBar'); if(SEL.size){ bar.classList.remove('hidden'); bar.classList.add('flex'); document.getElementById('bulkCount').textContent=SEL.size+' selected'; } else { bar.classList.add('hidden'); bar.classList.remove('flex'); } }

/* modal */
function fillTemplates(){ const s=document.getElementById('mTemplate'); s.innerHTML=DATA.templates.map(t=>`<option value="${t.id}">${t.name} · ${t.start}–${t.end} · off ${t.off_days}</option>`).join(''); }
function openAssignOne(id){ const r=DATA.riders.find(x=>x.user_id===id); TARGET=[{id, name:r.name}]; openAssignModal(); }
function openAssignBulk(){ TARGET=[...SEL].map(id=>{ const r=DATA.riders.find(x=>x.user_id===id); return {id, name:r?r.name:('#'+id)}; }); openAssignModal(); }
function openAssignModal(){
  if(!TARGET.length) return;
  document.getElementById('assignTitle').textContent = TARGET.length===1 ? ('Change shift · '+TARGET[0].name) : ('Change shift · '+TARGET.length+' riders');
  fillTemplates();
  document.getElementById('mFrom').value = DATA.today;
  document.getElementById('mTo').value = DATA.today;
  setMode('until_changed');
  document.getElementById('assignModal').style.display='flex';
}
function closeAssign(){ document.getElementById('assignModal').style.display='none'; }
function setMode(m){ MODE=m; document.querySelectorAll('.mode-opt').forEach(el=>el.classList.toggle('on', el.dataset.mode===m)); document.getElementById('toWrap').style.display = (m==='date_range')?'block':'none'; document.getElementById('fromLabel').textContent = (m==='one_day')?'Day':'From'; renderEffect(); }
function renderEffect(){
  const t = DATA.templates.find(x=>x.id==document.getElementById('mTemplate').value);
  const from = document.getElementById('mFrom').value, to=document.getElementById('mTo').value;
  const who = TARGET.length===1?TARGET[0].name:(TARGET.length+' riders');
  const nm = t?t.name:'the shift';
  let msg='';
  if(MODE==='until_changed') msg=`→ ${who} will be on <b>${nm}</b> from <b>${fmt(from)}</b> until you change it. Past days are untouched.`;
  else if(MODE==='one_day') msg=`→ ${who} on <b>${nm}</b> for <b>${fmt(from)}</b> only, then back to their normal shift automatically.`;
  else msg=`→ ${who} on <b>${nm}</b> for <b>${fmt(from)}–${fmt(to)}</b>, then back to their normal shift automatically.`;
  // A bounded range that ENDS before today is a past-record correction: attendance
  // recalculates, but the rider is NOT notified and has nothing to confirm.
  const end = (MODE==='one_day') ? from : to;
  if(MODE!=='until_changed' && end && end < DATA.today){
    msg += `<br><span style="color:#b45309;font-weight:600;">✎ Correcting a past period — attendance will recalculate. The rider is not notified (nothing to confirm).</span>`;
  }
  document.getElementById('effectLine').innerHTML=msg;
}
async function saveAssign(){
  const templateId=parseInt(document.getElementById('mTemplate').value);
  const from=document.getElementById('mFrom').value, to=document.getElementById('mTo').value;
  if(!templateId||!from){ alert('Pick a shift and a date.'); return; }
  if(MODE==='date_range' && (!to || to<from)){ alert('Pick a valid end date.'); return; }
  const btn=document.getElementById('saveBtn'); btn.disabled=true; btn.textContent='Saving…';
  const payload={ shift_template_id:templateId, mode:MODE, effective_from:from };
  if(MODE==='date_range') payload.effective_to=to;
  if(MODE==='one_day') payload.effective_to=from;
  let json;
  if(TARGET.length===1){ payload.user_id=TARGET[0].id; json=await post('/shifts/assign',payload); }
  else { payload.user_ids=TARGET.map(t=>t.id); json=await post('/shifts/bulk-assign',payload); }
  btn.disabled=false; btn.textContent='Save';
  if(json.success){ closeAssign(); clearSel(); loadWeek(WEEK); }
  else alert(json.message||'Failed to save');
}
async function cancelChange(id){
  if(!confirm('Cancel this temporary change? Their normal shift resumes.')) return;
  const json=await post('/shifts/cancel-change',{assignment_id:id});
  if(json.success) loadWeek(WEEK); else alert(json.message||'Failed to cancel');
}
async function addNumber(id, name){
  const phone = window.prompt('WhatsApp number for '+name+' (used to send shift notifications):');
  if(phone===null) return;
  const p = phone.trim();
  if(p.length<7){ alert('Please enter a valid number.'); return; }
  const json = await post('/shift-planner/update-phone', {user_id:id, phone:p});
  if(json.success) loadWeek(WEEK); else alert(json.message||'Could not save the number.');
}
document.getElementById('assignModal').addEventListener('click', closeAssign);

loadWeek(null);
</script>
@endsection
