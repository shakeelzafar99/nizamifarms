@extends('layouts.app')

@section('title', 'Shift Planner')

@section('content')
<style>
  /* The modals live OUTSIDE #planWrap, so define the palette on them too — without
     this, var(--brand) resolves to nothing there and the Save button renders
     white-on-white (invisible). */
  #planWrap, #assignModal, #historyModal, #newLocModal, #newShiftModal { --brand:#B91C1C; --brand-soft:#FBECEC; --line:#E3E8F0; }
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
  .chip-notneeded { background:#E0E7FF; color:#3730A3; border-color:#C7D2FE; }
  .chip-nm { font-weight:600; }
  .chip-loc { display:inline-flex; align-items:center; gap:2px; font-size:9.5px; font-weight:700; background:#E6F1FB; color:#185FA5; border-radius:20px; padding:0 6px; margin-top:1px; align-self:flex-start; }
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
  .dur-badge { display:inline-block; font-size:9.5px; font-weight:800; letter-spacing:.04em; border-radius:20px; padding:1px 7px; margin-left:6px; vertical-align:middle; }
  .dur-ongoing { background:#E6F3EB; color:#15803d; }
  .dur-temp { background:#FEF3C7; color:#92400e; }
  .loc-bubbles { display:flex; flex-wrap:wrap; gap:7px; margin:4px 0 8px; }
  .loc-bubble { border:1px solid var(--line); border-radius:20px; padding:6px 13px; font-size:13px; font-weight:600; color:#334155; cursor:pointer; background:#fff; }
  .loc-bubble.on { border-color:var(--brand); background:var(--brand-soft); color:#8E1414; }
  .loc-bubble .loc-def { font-size:10px; color:#94a3b8; font-weight:700; margin-left:4px; }
  .loc-default { display:flex; align-items:center; gap:7px; font-size:12.5px; color:#475569; margin-bottom:6px; cursor:pointer; }
  .loc-bubble.add { border-style:dashed; color:#B91C1C; background:#fff; }
  .pm-addlink { float:right; background:none; border:0; color:#B91C1C; font-size:11.5px; font-weight:700; cursor:pointer; padding:0; }
  .pm-mini-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .pm-day-chips { display:flex; gap:6px; flex-wrap:wrap; }
  .pm-day-chip { border:1px solid var(--line); border-radius:8px; padding:6px 10px; font-size:12px; font-weight:600; color:#334155; cursor:pointer; }
  .pm-day-chip.off { background:#EEF2F7; color:#94a3b8; text-decoration:line-through; }
  /* Self-contained modal — scoped under #assignModal so the Metronic theme's
     component CSS can't override the width or hide the buttons. */
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-card{ background:#fff; border-radius:14px; box-shadow:0 25px 60px rgba(15,23,42,.35); width:100%; max-width:440px; margin:auto; max-height:calc(100vh - 32px); overflow-y:auto; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-head{ display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid var(--line); }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-head h2{ font-size:17px; font-weight:600; margin:0; color:#0f172a; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-x{ background:none; border:0; font-size:24px; line-height:1; color:#94a3b8; cursor:pointer; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-body{ padding:18px; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-label{ display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:14px 0 6px; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-body > .pm-label:first-child{ margin-top:0; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-input{ width:100%; padding:9px 11px; font-size:13px; border:1px solid var(--line); border-radius:8px; background:#fff; color:#0f172a; }
  #assignModal .mode-opt{ margin-bottom:7px; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-dates{ display:flex; gap:12px; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-dates > div{ flex:1; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-effect{ font-size:12px; color:#37485f; background:#F1F5FB; border:1px solid #E1E8F2; border-radius:8px; padding:9px 11px; margin:14px 0 0; line-height:1.5; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-actions{ display:flex; gap:10px; margin-top:16px; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-save{ flex:1; background:var(--brand); color:#fff; border:0; border-radius:9px; padding:11px; font-size:14px; font-weight:600; cursor:pointer; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-save:hover{ background:#a11818; }
  :is(#assignModal,#newLocModal,#newShiftModal,#historyModal) .pm-cancel{ padding:11px 16px; background:#fff; color:#475569; border:1px solid var(--line); border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; }
</style>

<div id="planWrap" class="p-4 md:p-6">
  <div class="flex flex-wrap justify-between items-center gap-3 mb-4">
    <div>
      <h1 class="text-2xl font-semibold text-gray-900">Shift Planner</h1>
      <p class="text-sm text-gray-500 mt-1">See and change who works which shift. Temporary changes come back to the primary automatically.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/shifts" class="px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100">Shift types</a>
      <a href="/attendance/locations" class="px-3 py-2 text-sm text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-100">Locations</a>
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
      <span><span class="inline-block w-3 h-3 rounded-sm align-middle" style="background:#E0E7FF;border:1px solid #C7D2FE"></span> not needed</span>
    </div>
  </div>

  <!-- how-to hint -->
  <div class="mb-3 text-xs text-gray-500 flex items-center gap-2" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:7px 11px;">
    <span style="font-size:14px;">💡</span>
    <span><b>Tap a day cell</b> to mark that rider <b>“not needed”</b> that day (paid, not counted absent) — tap again to undo. Use “Change shift for selected” to change shift times.</span>
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
      <label class="pm-label">Shift <button type="button" class="pm-addlink" onclick="openNewShift()">＋ new shift type</button></label>
      <select id="mTemplate" class="pm-input" onchange="onTemplateChange()"></select>

      <label class="pm-label">How long?</label>
      <div class="mode-opt" data-mode="until_changed" onclick="setMode('until_changed')">
        <div class="mt">New regular shift <span class="dur-badge dur-ongoing">REGULAR</span></div>
        <div class="md">Every day from the start date, <b>until you change it</b>. This is their new normal.</div>
      </div>
      <div class="mode-opt" data-mode="one_day" onclick="setMode('one_day')">
        <div class="mt">One day only <span class="dur-badge dur-temp">TEMPORARY</span></div>
        <div class="md">Just the one date you pick — back to their normal shift <b>the next day</b>.</div>
      </div>
      <div class="mode-opt" data-mode="date_range" onclick="setMode('date_range')">
        <div class="mt">A date range <span class="dur-badge dur-temp">TEMPORARY</span></div>
        <div class="md">Only between the two dates — back to their normal shift <b>after</b>.</div>
      </div>

      <div class="pm-dates">
        <div><label id="fromLabel" class="pm-label">From</label><input type="date" id="mFrom" class="pm-input" onchange="renderEffect()"></div>
        <div id="toWrap"><label class="pm-label">To</label><input type="date" id="mTo" class="pm-input" onchange="renderEffect()"></div>
      </div>

      <div id="locSection">
        <label class="pm-label">Location <span style="font-weight:400;color:#94a3b8;">— where they check in</span></label>
        <div id="locBubbles" class="loc-bubbles"></div>
        <label id="setDefaultWrap" class="loc-default"><input type="checkbox" id="mSetDefault"> <span id="setDefaultLbl">Make this their default location</span></label>
      </div>

      <p id="effectLine" class="pm-effect"></p>
      <div class="pm-actions">
        <button class="pm-cancel" onclick="closeAssign()">Cancel</button>
        <button id="saveBtn" class="pm-save" onclick="saveAssign()">Save</button>
      </div>
    </div>
  </div>
</div>

<div id="newLocModal" class="modal-bg" onclick="if(event.target===this)closeNewLoc()">
  <div class="pm-card" onclick="event.stopPropagation()" style="max-width:420px;">
    <div class="pm-head"><h2>New office location</h2><button class="pm-x" onclick="closeNewLoc()">&times;</button></div>
    <div class="pm-body">
      <label class="pm-label">Name</label>
      <input id="nlName" class="pm-input" placeholder="e.g. LaCarne DHA" maxlength="100">
      <label class="pm-label">Position</label>
      <button type="button" class="pm-cancel" style="width:100%;" onclick="nlToggleMap()">🗺 Pick on map</button>
      <div id="nlMapWrap" style="display:none;">
        <div id="nlMap" style="height:280px;border-radius:10px;border:1px solid var(--line);margin-top:10px;"></div>
        <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Click the map or drag the pin to the office entrance.</p>
      </div>
      <div class="pm-mini-grid" style="margin-top:10px;">
        <div><label class="pm-label">Latitude</label><input id="nlLat" class="pm-input" placeholder="33.5204" inputmode="decimal"></div>
        <div><label class="pm-label">Longitude</label><input id="nlLng" class="pm-input" placeholder="73.0479" inputmode="decimal"></div>
      </div>
      <label class="pm-label">Check-in radius (meters)</label>
      <input id="nlRadius" class="pm-input" value="300" inputmode="numeric">
      <p style="font-size:11px;color:#94a3b8;margin:4px 0 0;">Check-ins farther than this are flagged "remote". 100–10000m.</p>
      <div class="pm-actions">
        <button class="pm-cancel" onclick="closeNewLoc()">Cancel</button>
        <button id="nlSave" class="pm-save" onclick="saveNewLoc()">Add location</button>
      </div>
    </div>
  </div>
</div>

<div id="newShiftModal" class="modal-bg" onclick="if(event.target===this)closeNewShift()">
  <div class="pm-card" onclick="event.stopPropagation()" style="max-width:440px;">
    <div class="pm-head"><h2>New shift type</h2><button class="pm-x" onclick="closeNewShift()">&times;</button></div>
    <div class="pm-body">
      <label class="pm-label">Name</label>
      <input id="nsName" class="pm-input" placeholder="e.g. Evening 5 PM" maxlength="100">
      <div class="pm-mini-grid" style="margin-top:10px;">
        <div><label class="pm-label">Start time</label><input id="nsStart" type="time" class="pm-input"></div>
        <div><label class="pm-label">End time <span style="font-weight:400;color:#94a3b8;">(optional)</span></label><input id="nsEnd" type="time" class="pm-input"></div>
      </div>
      <p style="font-size:11px;color:#94a3b8;margin:4px 0 10px;">Leave End empty for a start-only shift (no fixed end → no overtime).</p>
      <label class="pm-label">Working days <span style="font-weight:400;color:#94a3b8;">— tap to toggle off-days</span></label>
      <div id="nsDays" class="pm-day-chips"></div>
      <div class="pm-actions">
        <button class="pm-cancel" onclick="closeNewShift()">Cancel</button>
        <button id="nsSave" class="pm-save" onclick="saveNewShift()">Add shift type</button>
      </div>
    </div>
  </div>
</div>

<div id="historyModal" class="modal-bg" onclick="closeHistory()">
  <div class="pm-card" onclick="event.stopPropagation()">
    <div class="pm-head">
      <h2 id="historyTitle">Change history</h2>
      <button class="pm-x" onclick="closeHistory()">&times;</button>
    </div>
    <div class="pm-body">
      <div id="historyBody" style="max-height:420px;overflow-y:auto;"></div>
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
function escapeHtml(s){ return String(s==null?'':s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
async function openHistory(id){
  const name = (DATA.riders.find(x=>x.user_id===id)||{}).name || '#'+id;
  document.getElementById('historyTitle').textContent = 'Change history · ' + name;
  const body = document.getElementById('historyBody');
  body.innerHTML = '<div style="color:#94a3b8;font-size:13px;padding:16px;">Loading…</div>';
  document.getElementById('historyModal').style.display = 'flex';
  try {
    const d = await fetch('/shifts/history?user_id=' + encodeURIComponent(id)).then(r=>r.json());
    const rows = d.history || [];
    if(!rows.length){ body.innerHTML = '<div style="color:#94a3b8;font-size:13px;padding:16px;">No recorded changes yet. New assignments will appear here.</div>'; return; }
    body.innerHTML = rows.map(h => `
      <div style="padding:9px 2px;border-bottom:1px solid #f1f5f9;">
        <div style="font-weight:700;font-size:13.5px;color:#0f172a;">${escapeHtml(h.label)}</div>
        <div style="font-size:11.5px;color:#64748b;margin-top:2px;">${escapeHtml(h.actor)} · ${escapeHtml(h.when)} · via ${escapeHtml(h.via)}</div>
        ${h.note ? `<div style="font-size:11px;color:#94a3b8;font-style:italic;margin-top:1px;">${escapeHtml(h.note)}</div>` : ''}
      </div>`).join('');
  } catch(e){ body.innerHTML = '<div style="color:#ef4444;font-size:13px;padding:16px;">Failed to load history.</div>'; }
}
function closeHistory(){ document.getElementById('historyModal').style.display='none'; }
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
// Toggle a "not needed" day tag from the planner (reuses the attendance day-tag endpoint).
async function toggleCellTag(uid, date, currentlyNotNeeded, name) {
  const verb = currentlyNotNeeded ? 'remove the "not needed" tag for' : 'mark not needed (paid, not counted absent) for';
  const when = fmt(date);
  if (!confirm(`${currentlyNotNeeded?'Remove':'Mark'} — ${verb} ${name} on ${when}?`)) return;
  try {
    const j = await post('/attendance/toggle-day-tag', { user_id: uid, date: date });
    if (j && j.success !== false) { loadWeek(WEEK); }
    else { alert((j && j.message) || 'Could not update the day.'); }
  } catch(e) { alert('Could not update the day.'); }
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
      let cls='chip-work', body='', clickable=false;
      if (c.is_holiday) { cls='chip-holiday'; body=`<span>Holiday</span>`; }
      else if (c.is_off) { cls='chip-off'; body=`<span>Off</span>`; }
      else if (c.not_needed) {
        // Tagged "not needed" — paid, not counted absent. Click to undo.
        cls='chip-notneeded'; clickable=true;
        body=`<span class="chip-nm">🚫 Not needed</span><span class="text-[10px] opacity-70">paid</span>`;
      }
      else {
        cls = c.is_override?'chip-override':'chip-work';
        clickable=true;
        // 📍 pin ONLY when this day's location differs from the rider's usual one —
        // a normal week shows no pins, so a pin always means "different place that day".
        const locPin = (c.location_id && r.usual_location_id && c.location_id !== r.usual_location_id)
          ? `<span class="chip-loc" title="At ${escapeHtml(c.location_name||'')} this day">📍 ${escapeHtml(c.location_name||'')}</span>` : '';
        body=`<span class="chip-nm">${c.start}${c.end?'–'+c.end:'+'}</span><span class="text-[10px] opacity-70">${c.shift_name}</span>${locPin}`;
      }
      const click = clickable ? ` onclick="toggleCellTag(${r.user_id}, '${c.date}', ${c.not_needed?1:0}, '${escapeHtml(r.name).replace(/'/g,"\\'")}')" title="${c.not_needed?'Marked not needed — click to undo':'Click to mark not needed (paid, not counted absent)'}" style="cursor:pointer;"` : '';
      return `<td class="${isToday?'col-today':''}"><span class="cell-chip ${cls}"${click}>${body}</span></td>`;
    }).join('');

    const checked = SEL.has(r.user_id)?'checked':'';
    return `<tr>
      <td>
        <label class="flex items-start cursor-pointer">
          <input type="checkbox" class="rider-cb mt-0.5" ${checked} onchange="toggleSel(${r.user_id})">
          <span>
            <span class="font-semibold text-sm text-gray-900">${r.name}</span>
            <span class="block text-[11px] text-gray-400">${r.role||''}</span>
            <span class="pchip mt-1">${r.primary.start}${r.primary.end?'–'+r.primary.end:'+'} · ${r.primary.shift_name}${r.primary.location_name?' · 📍'+r.primary.location_name:''}</span>
            <button onclick="openAssignOne(${r.user_id})" class="ml-1 text-xs text-red-600 font-semibold hover:underline">Change</button>
            <button onclick="event.preventDefault();openHistory(${r.user_id})" title="Who changed this rider's shift, and when" class="ml-2 text-[11px] text-gray-500 font-semibold hover:underline">History</button>
            ${r.has_phone===false ? `<button onclick="event.preventDefault();addNumber(${r.user_id})" title="No WhatsApp number — riders can't be notified of shift changes" class="ml-1 text-[11px] text-amber-600 font-semibold hover:underline">📱 add number</button>` : ''}
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
function fillTemplates(){
  const s=document.getElementById('mTemplate');
  // 🚫 Not required — a special "shift" that just marks the day(s) not-needed (paid, not absent).
  const nr = `<option value="not_required">🚫 Not required (day off, paid — not a shift)</option>`;
  s.innerHTML = DATA.templates.map(t=>`<option value="${t.id}">${t.name} · ${t.start}${t.end?'–'+t.end:'+'} · off ${t.off_days}</option>`).join('') + nr;
}
function isNotRequiredSelected(){ return document.getElementById('mTemplate').value === 'not_required'; }
function onTemplateChange(){
  const nr = isNotRequiredSelected();
  // Not-required is a per-day marker, not a recurring pattern: hide "until changed" + location.
  const untilOpt = document.querySelector('.mode-opt[data-mode="until_changed"]');
  if(untilOpt) untilOpt.style.display = nr ? 'none' : '';
  const loc = document.getElementById('locSection'); if(loc) loc.style.display = nr ? 'none' : '';
  if(nr && MODE==='until_changed') setMode('one_day');
  renderEffect();
}
function openAssignOne(id){ const r=DATA.riders.find(x=>x.user_id===id); TARGET=[{id, name:r.name}]; openAssignModal(); }
function openAssignBulk(){ TARGET=[...SEL].map(id=>{ const r=DATA.riders.find(x=>x.user_id===id); return {id, name:r?r.name:('#'+id)}; }); openAssignModal(); }
let SELLOC = null;           // selected location id in the assign modal
function renderLocBubbles(){
  const wrap=document.getElementById('locBubbles');
  const locs=DATA.locations||[];
  if(!locs.length){ wrap.innerHTML='<span class="loc-bubble add" onclick="openNewLoc()">＋ Add your first location</span>'; document.getElementById('setDefaultWrap').style.display='none'; return; }
  document.getElementById('setDefaultWrap').style.display='flex';
  // The rider's current default (single target) — shown with a "default" tag.
  const riderDefault = (TARGET.length===1) ? (DATA.riders.find(x=>x.user_id===TARGET[0].id)||{}).default_location_id : null;
  wrap.innerHTML = locs.map(l=>`<span class="loc-bubble ${l.id===SELLOC?'on':''}" onclick="pickLoc(${l.id})">${l.name}${l.id===riderDefault?'<span class="loc-def">default</span>':''}</span>`).join('')
    + `<span class="loc-bubble add" onclick="openNewLoc()">＋ New</span>`;
  const who = TARGET.length===1 ? (TARGET[0].name.split(' ')[0]+"'s") : "the riders'";
  document.getElementById('setDefaultLbl').textContent = 'Make this '+who+' default location';
}
function pickLoc(id){ SELLOC=id; renderLocBubbles(); }
function openAssignModal(){
  if(!TARGET.length) return;
  document.getElementById('assignTitle').textContent = TARGET.length===1 ? ('Change shift · '+TARGET[0].name) : ('Change shift · '+TARGET.length+' riders');
  fillTemplates();
  document.getElementById('mFrom').value = DATA.today;
  document.getElementById('mTo').value = DATA.today;
  setMode('until_changed');
  // Default location: the rider's own (single) → else the primary location.
  const primary=(DATA.locations||[]).find(l=>l.is_primary) || (DATA.locations||[])[0];
  const riderDefault=(TARGET.length===1) ? (DATA.riders.find(x=>x.user_id===TARGET[0].id)||{}).default_location_id : null;
  SELLOC = riderDefault || (primary?primary.id:null);
  document.getElementById('mSetDefault').checked=false;
  renderLocBubbles();
  onTemplateChange(); // reset not-required UI state (restores "until changed" + location)
  document.getElementById('assignModal').style.display='flex';
}
function closeAssign(){ document.getElementById('assignModal').style.display='none'; }
function setMode(m){ MODE=m; document.querySelectorAll('.mode-opt').forEach(el=>el.classList.toggle('on', el.dataset.mode===m)); document.getElementById('toWrap').style.display = (m==='date_range')?'block':'none'; document.getElementById('fromLabel').textContent = (m==='one_day')?'Day':'From'; renderEffect(); }
function renderEffect(){
  const from = document.getElementById('mFrom').value, to=document.getElementById('mTo').value;
  const who = TARGET.length===1?TARGET[0].name:(TARGET.length+' riders');
  if(isNotRequiredSelected()){
    const rangeTxt = (MODE==='date_range') ? `${fmt(from)}–${fmt(to)}` : fmt(from);
    document.getElementById('effectLine').innerHTML = `→ ${who} marked <b>🚫 not needed</b> on <b>${rangeTxt}</b> — paid, not counted absent. Reversible from the day cell or here.`;
    return;
  }
  const t = DATA.templates.find(x=>x.id==document.getElementById('mTemplate').value);
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
  const rawT = document.getElementById('mTemplate').value;
  const from=document.getElementById('mFrom').value, to=document.getElementById('mTo').value;
  if(!from){ alert('Pick a date.'); return; }
  if(MODE==='date_range' && (!to || to<from)){ alert('Pick a valid end date.'); return; }

  // 🚫 Not required — write not-needed day tags over the chosen date(s); no shift assignment.
  if(rawT === 'not_required'){
    const btn=document.getElementById('saveBtn'); btn.disabled=true; btn.textContent='Saving…';
    const payload={ user_ids: TARGET.map(t=>t.id), from, action:'add' };
    if(MODE==='date_range') payload.to = to;
    const json = await post('/attendance/day-tag-range', payload);
    btn.disabled=false; btn.textContent='Save';
    if(json.success){ closeAssign(); clearSel(); loadWeek(WEEK); }
    else alert(json.message||'Failed to save');
    return;
  }

  const templateId=parseInt(rawT);
  if(!templateId){ alert('Pick a shift and a date.'); return; }
  // Guard the classic mix-up: a "one day only" change dated TODAY (they revert tomorrow).
  // If they meant a lasting change they should pick "New regular shift".
  if(MODE==='one_day' && from===DATA.today){
    if(!confirm('This sets the shift for TODAY only ('+fmt(from)+'). They go back to their normal shift tomorrow.\n\nIf you want a lasting change, cancel and choose "New regular shift" (REGULAR) instead.\n\nContinue with today only?')) return;
  }
  const btn=document.getElementById('saveBtn'); btn.disabled=true; btn.textContent='Saving…';
  const payload={ shift_template_id:templateId, mode:MODE, effective_from:from };
  if(MODE==='date_range') payload.effective_to=to;
  if(MODE==='one_day') payload.effective_to=from;
  if(SELLOC){ payload.location_id=SELLOC; payload.set_default_location=document.getElementById('mSetDefault').checked; }
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
async function addNumber(id){
  const name = (DATA.riders.find(x=>x.user_id===id)||{}).name || 'this rider';
  const phone = window.prompt('WhatsApp number for '+name+' (used to send shift notifications):');
  if(phone===null) return;
  const p = phone.trim();
  if(p.length<7){ alert('Please enter a valid number.'); return; }
  const json = await post('/shift-planner/update-phone', {user_id:id, phone:p});
  if(json.success) loadWeek(WEEK); else alert(json.message||'Could not save the number.');
}
document.getElementById('assignModal').addEventListener('click', closeAssign);

/* ── "+ New" popups: create a location / shift type WITHOUT leaving the assign flow.
      Saved via the same endpoints the management pages use; the new item is appended
      to the in-memory lists and auto-selected so the manager just continues. ── */
function openNewLoc(){ document.getElementById('nlName').value=''; document.getElementById('nlLat').value=''; document.getElementById('nlLng').value=''; document.getElementById('nlRadius').value='300'; document.getElementById('nlMapWrap').style.display='none'; NL_MAP=null; document.getElementById('newLocModal').style.display='flex'; }
function closeNewLoc(){ document.getElementById('newLocModal').style.display='none'; }

/* Map pin — SAME strategy as the /attendance/locations page: Google Maps JS loaded
   on demand, click or drag the marker, coordinates written back at 8 decimals. */
let NL_MAP=null, NL_MARKER=null;
function nlLoadGoogle(cb){
  if (typeof google !== 'undefined' && google.maps) { cb(); return; }
  const s=document.createElement('script');
  s.src='https://maps.googleapis.com/maps/api/js?key=AIzaSyBFCBj7ebflrliC1pHq0XhsjuW18Q3iElk&libraries=places';
  s.async=true; s.defer=true; s.onload=cb;
  s.onerror=()=>alert('Could not load Google Maps — enter the coordinates manually.');
  document.head.appendChild(s);
}
function nlToggleMap(){
  const wrap=document.getElementById('nlMapWrap');
  if(wrap.style.display!=='none'){ wrap.style.display='none'; return; }
  wrap.style.display='block';
  nlLoadGoogle(()=>setTimeout(nlInitMap,100));
}
function nlInitMap(){
  // Center on already-typed coords, else the same default the locations page uses.
  const lat=parseFloat(document.getElementById('nlLat').value)||33.70811597;
  const lng=parseFloat(document.getElementById('nlLng').value)||73.08868750;
  NL_MAP=new google.maps.Map(document.getElementById('nlMap'),{center:{lat,lng},zoom:15,streetViewControl:false,mapTypeControl:false});
  NL_MARKER=new google.maps.Marker({position:{lat,lng},map:NL_MAP,draggable:true});
  const setPos=p=>{ document.getElementById('nlLat').value=p.lat().toFixed(8); document.getElementById('nlLng').value=p.lng().toFixed(8); };
  NL_MARKER.addListener('dragend',()=>setPos(NL_MARKER.getPosition()));
  NL_MAP.addListener('click',e=>{ NL_MARKER.setPosition(e.latLng); setPos(e.latLng); });
}
async function saveNewLoc(){
  const name=document.getElementById('nlName').value.trim();
  const lat=parseFloat(document.getElementById('nlLat').value), lng=parseFloat(document.getElementById('nlLng').value);
  const radius=parseInt(document.getElementById('nlRadius').value);
  if(!name){ alert('Give the location a name.'); return; }
  if(isNaN(lat)||isNaN(lng)||lat<-90||lat>90||lng<-180||lng>180){ alert('Enter a valid latitude and longitude (from Google Maps).'); return; }
  if(isNaN(radius)||radius<100||radius>10000){ alert('Radius must be between 100 and 10000 meters.'); return; }
  const btn=document.getElementById('nlSave'); btn.disabled=true; btn.textContent='Saving…';
  const json=await post('/attendance/locations', {location_name:name, latitude:lat, longitude:lng, radius_meters:radius});
  btn.disabled=false; btn.textContent='Add location';
  if(json.success){
    DATA.locations = DATA.locations||[]; DATA.locations.push({id:json.location_id, name:name, is_primary:false});
    SELLOC = json.location_id; renderLocBubbles(); closeNewLoc();
  } else alert(json.message||'Could not save the location.');
}

const NS_DAYS=[{n:1,l:'Mon'},{n:2,l:'Tue'},{n:3,l:'Wed'},{n:4,l:'Thu'},{n:5,l:'Fri'},{n:6,l:'Sat'},{n:7,l:'Sun'}];
let NS_ON=new Set([1,2,3,4,5,6,7]);
function renderNsDays(){ document.getElementById('nsDays').innerHTML = NS_DAYS.map(d=>`<span class="pm-day-chip ${NS_ON.has(d.n)?'':'off'}" onclick="toggleNsDay(${d.n})">${d.l}</span>`).join(''); }
function toggleNsDay(n){ NS_ON.has(n)?NS_ON.delete(n):NS_ON.add(n); renderNsDays(); }
function openNewShift(){ document.getElementById('nsName').value=''; document.getElementById('nsStart').value=''; document.getElementById('nsEnd').value=''; NS_ON=new Set([1,2,3,4,5,6,7]); renderNsDays(); document.getElementById('newShiftModal').style.display='flex'; }
function closeNewShift(){ document.getElementById('newShiftModal').style.display='none'; }
async function saveNewShift(){
  const name=document.getElementById('nsName').value.trim();
  const start=document.getElementById('nsStart').value, end=document.getElementById('nsEnd').value;
  if(!name){ alert('Give the shift a name.'); return; }
  if(!start){ alert('Pick a start time.'); return; }
  if(!NS_ON.size){ alert('Pick at least one working day.'); return; }
  const code=(name.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'')||'shift')+'_'+Date.now().toString(36).slice(-4);
  const btn=document.getElementById('nsSave'); btn.disabled=true; btn.textContent='Saving…';
  const json=await post('/shifts', {shift_name:name, shift_code:code, shift_start:start, shift_end:end||null, working_days:[...NS_ON]});
  btn.disabled=false; btn.textContent='Add shift type';
  if(json.success){
    const offNames=NS_DAYS.filter(d=>!NS_ON.has(d.n)).map(d=>d.l).join(', ')||'None';
    DATA.templates.push({id:json.data.id, name:name, start:start, end:end||null, off_days:offNames});
    fillTemplates(); document.getElementById('mTemplate').value=json.data.id; renderEffect(); closeNewShift();
  } else alert(json.message||'Could not save the shift type.');
}

loadWeek(null);
</script>
@endsection
