{{-- Reusable "Change shift" popup. Include once per page, then call:
       openShiftChange({ userId, userName, onSaved })
     Self-contained (own CSS + IIFE JS, no page dependencies); loads its own shift
     types from /shifts/list and posts to /shifts/assign (mode-aware engine). --}}
<div id="shiftChangeModal" class="scm-bg" style="display:none;">
  <div class="scm-card">
    <div class="scm-head">
      <h2 id="scmTitle">Change shift</h2>
      <button type="button" id="scmX" class="scm-x">&times;</button>
    </div>
    <div class="scm-body">
      <div id="scmSummary" class="scm-summary"></div>

      <div class="scm-sep"><span>Set a shift</span></div>

      <label class="scm-label">Shift</label>
      <select id="scmTemplate" class="scm-input"></select>

      <label class="scm-label">How long?</label>
      <div class="scm-mode" data-mode="until_changed">
        <div class="mt">Set new primary shift</div>
        <div class="md">Until I change it — this becomes their normal shift.</div>
      </div>
      <div class="scm-mode" data-mode="one_day">
        <div class="mt">Just one day</div>
        <div class="md">Temporary — their normal shift comes back after.</div>
      </div>
      <div class="scm-mode" data-mode="date_range">
        <div class="mt">Start &amp; end date</div>
        <div class="md">Temporary — for a set period, then back to normal.</div>
      </div>

      <div class="scm-dates">
        <div><label id="scmFromLabel" class="scm-label">From</label><input type="date" id="scmFrom" class="scm-input"></div>
        <div id="scmToWrap"><label class="scm-label">To</label><input type="date" id="scmTo" class="scm-input"></div>
      </div>

      <p id="scmEffect" class="scm-effect"></p>
      <div class="scm-actions">
        <button type="button" id="scmCancel" class="scm-cancel">Cancel</button>
        <button type="button" id="scmSave" class="scm-save">Save</button>
      </div>
    </div>
  </div>
</div>

<style>
  #shiftChangeModal.scm-bg{ position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:100000; display:none; align-items:center; justify-content:center; padding:16px; }
  #shiftChangeModal .scm-card{ background:#fff; border-radius:14px; box-shadow:0 25px 60px rgba(15,23,42,.35); width:100%; max-width:440px; margin:auto; max-height:calc(100vh - 32px); overflow-y:auto; }
  #shiftChangeModal .scm-head{ display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid #e3e8f0; }
  #shiftChangeModal .scm-head h2{ font-size:17px; font-weight:600; margin:0; color:#0f172a; }
  #shiftChangeModal .scm-x{ background:none; border:0; font-size:24px; line-height:1; color:#94a3b8; cursor:pointer; }
  #shiftChangeModal .scm-body{ padding:18px; }
  #shiftChangeModal .scm-label{ display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:14px 0 6px; }
  #shiftChangeModal .scm-body > .scm-label:first-child{ margin-top:0; }
  #shiftChangeModal .scm-input{ width:100%; padding:9px 11px; font-size:13px; border:1px solid #e3e8f0; border-radius:8px; background:#fff; color:#0f172a; }
  #shiftChangeModal .scm-mode{ border:1px solid #e3e8f0; border-radius:9px; padding:9px 11px; margin-bottom:7px; cursor:pointer; }
  #shiftChangeModal .scm-mode.on{ border-color:#B91C1C; background:#FBECEC; }
  #shiftChangeModal .scm-mode .mt{ font-weight:600; font-size:13px; color:#0f172a; }
  #shiftChangeModal .scm-mode .md{ font-size:11px; color:#64748b; margin-top:1px; }
  #shiftChangeModal .scm-dates{ display:flex; gap:12px; }
  #shiftChangeModal .scm-dates > div{ flex:1; }
  #shiftChangeModal .scm-effect{ font-size:12px; color:#37485f; background:#F1F5FB; border:1px solid #E1E8F2; border-radius:8px; padding:9px 11px; margin:14px 0 0; line-height:1.5; }
  #shiftChangeModal .scm-effect b{ color:#0f172a; }
  #shiftChangeModal .scm-actions{ display:flex; gap:10px; margin-top:16px; }
  #shiftChangeModal .scm-save{ flex:1; background:#B91C1C; color:#fff; border:0; border-radius:9px; padding:11px; font-size:14px; font-weight:600; cursor:pointer; }
  #shiftChangeModal .scm-save:hover{ background:#a11818; }
  #shiftChangeModal .scm-cancel{ padding:11px 16px; background:#fff; color:#475569; border:1px solid #e3e8f0; border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; }
  /* current-shift + scheduled-changes summary */
  #shiftChangeModal .scm-summary{ background:#F8FAFC; border:1px solid #e8edf3; border-radius:10px; padding:11px 12px; }
  #shiftChangeModal .scm-now{ font-size:13px; color:#0f172a; }
  #shiftChangeModal .scm-now .scm-now-lbl{ display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#15803D; background:#E6F3EB; border-radius:20px; padding:1px 8px; margin-right:6px; }
  #shiftChangeModal .scm-now b{ color:#0f172a; }
  #shiftChangeModal .scm-changes{ display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
  #shiftChangeModal .scm-chg{ display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:600; background:#FEF9C3; color:#854d0e; border-radius:20px; padding:2px 5px 2px 9px; }
  #shiftChangeModal .scm-chg-x{ background:none; border:0; color:#a1691a; font-weight:700; font-size:14px; line-height:1; cursor:pointer; padding:0 2px; }
  #shiftChangeModal .scm-chg.acked{ background:#E6F3EB; color:#15803d; }
  #shiftChangeModal .scm-chg .scm-ack{ font-weight:700; margin-left:2px; }
  #shiftChangeModal .scm-chg.acked .scm-chg-x{ color:#15803d; }
  #shiftChangeModal .scm-nochg{ font-size:11.5px; color:#94a3b8; margin-top:7px; }
  #shiftChangeModal .scm-loading{ font-size:12px; color:#94a3b8; }
  #shiftChangeModal .scm-sep{ display:flex; align-items:center; gap:10px; margin:16px 0 2px; color:#94a3b8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  #shiftChangeModal .scm-sep::before, #shiftChangeModal .scm-sep::after{ content:""; flex:1; height:1px; background:#e8edf3; }
</style>

<script>
(function(){
  if (window.openShiftChange) return; // include-once guard
  const $ = (id) => document.getElementById(id);
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  let templates = [], target = null, mode = 'until_changed', onSaved = null;

  const fmt = (d) => { try { return new Date(d+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short'}); } catch(e){ return d; } };
  const todayStr = () => { const d=new Date(); const p=(n)=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); };

  async function loadTemplates(){
    if (templates.length) return;
    try { const j = await fetch('/shifts/list').then(r=>r.json()); if (j.success) templates = j.data.filter(t=>t.active); } catch(e){ console.error('shift templates load failed', e); }
  }

  function setMode(m){
    mode = m;
    document.querySelectorAll('#shiftChangeModal .scm-mode').forEach(el => el.classList.toggle('on', el.dataset.mode===m));
    $('scmToWrap').style.display = (m==='date_range') ? 'block' : 'none';
    $('scmFromLabel').textContent = (m==='one_day') ? 'Day' : 'From';
    renderEffect();
  }

  function renderEffect(){
    const t = templates.find(x => String(x.id)===String($('scmTemplate').value));
    const from = $('scmFrom').value, to = $('scmTo').value;
    const who = target ? target.userName : 'this rider';
    const nm = t ? t.shift_name : 'the shift';
    let msg;
    if (mode==='until_changed') msg = `→ ${who} will be on <b>${nm}</b> from <b>${fmt(from)}</b> until you change it. Past days are untouched.`;
    else if (mode==='one_day') msg = `→ ${who} on <b>${nm}</b> for <b>${fmt(from)}</b> only, then back to their normal shift automatically.`;
    else msg = `→ ${who} on <b>${nm}</b> for <b>${fmt(from)}–${fmt(to)}</b>, then back to their normal shift automatically.`;
    $('scmEffect').innerHTML = msg;
  }

  function close(){ $('shiftChangeModal').style.display='none'; }

  async function loadSummary(userId){
    $('scmSummary').innerHTML = '<div class="scm-loading">Loading current shift…</div>';
    try {
      const d = await fetch('/shifts/user-summary?user_id=' + encodeURIComponent(userId)).then(r=>r.json());
      if (d.success) renderSummary(d); else $('scmSummary').innerHTML = '';
    } catch(e){ $('scmSummary').innerHTML = ''; }
  }
  function renderSummary(d){
    const p = d.primary || {};
    let html = `<div class="scm-now"><span class="scm-now-lbl">Now</span> <b>${p.shift_name||'—'}</b> · ${p.start||''}–${p.end||''}</div>`;
    if (d.changes && d.changes.length){
      html += '<div class="scm-changes">' + d.changes.map(c=>{
        const lbl = c.kind==='temporary'
          ? `${c.shift_name||'—'} · ${fmt(c.from)}${c.to&&c.to!==c.from?'–'+fmt(c.to):''}`
          : `${c.shift_name||'—'} · from ${fmt(c.from)}`;
        const ack = c.acknowledged ? '<span class="scm-ack">✓ confirmed</span>' : '<span class="scm-ack">⏳ awaiting</span>';
        return `<span class="scm-chg ${c.acknowledged?'acked':''}">${c.kind==='temporary'?'⏱':'⏭'} ${lbl} ${ack} <button type="button" class="scm-chg-x" data-cancel="${c.assignment_id}" title="Cancel this change">×</button></span>`;
      }).join('') + '</div>';
    } else {
      html += '<div class="scm-nochg">No upcoming changes — just their normal shift.</div>';
    }
    $('scmSummary').innerHTML = html;
    $('scmSummary').querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', () => cancelChange(parseInt(b.dataset.cancel))));
  }
  async function cancelChange(id){
    if (!confirm('Cancel this temporary change? Their normal shift resumes.')) return;
    let j;
    try { j = await fetch('/shifts/cancel-change', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify({ assignment_id:id }) }).then(r=>r.json()); }
    catch(e){ j = { success:false, message:'Network error' }; }
    if (j.success){ loadSummary(target.userId); if (typeof onSaved==='function') onSaved(); }
    else alert(j.message || 'Failed to cancel');
  }

  async function save(){
    const templateId = parseInt($('scmTemplate').value);
    const from = $('scmFrom').value, to = $('scmTo').value;
    if (!templateId || !from){ alert('Pick a shift and a date.'); return; }
    if (mode==='date_range' && (!to || to<from)){ alert('Pick a valid end date.'); return; }
    const btn = $('scmSave'); btn.disabled=true; btn.textContent='Saving…';
    const payload = { user_id: target.userId, shift_template_id: templateId, mode, effective_from: from };
    if (mode==='date_range') payload.effective_to = to;
    if (mode==='one_day') payload.effective_to = from;
    let json;
    try { json = await fetch('/shifts/assign', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(payload) }).then(r=>r.json()); }
    catch(e){ json = { success:false, message:'Network error' }; }
    btn.disabled=false;
    if (json.success){
      // Keep the popup open and refresh the summary so the manager SEES the change land
      // (new primary updates "Now"; a temporary change appears in the list). Table refreshes too.
      loadSummary(target.userId);
      if (typeof onSaved==='function') onSaved();
      btn.textContent = 'Saved ✓';
      setTimeout(() => { btn.textContent = 'Save'; }, 1300);
    } else {
      btn.textContent = 'Save';
      alert(json.message || 'Failed to save');
    }
  }

  window.openShiftChange = async function(opts){
    target = opts || {}; onSaved = (opts && opts.onSaved) || null;
    await loadTemplates();
    $('scmTitle').textContent = 'Change shift · ' + (target.userName || '');
    $('scmTemplate').innerHTML = templates.map(t => `<option value="${t.id}">${t.shift_name} · ${t.shift_start}–${t.shift_end} · off ${t.off_days}</option>`).join('');
    const today = todayStr();
    $('scmFrom').value = today; $('scmTo').value = today;
    setMode('until_changed');
    $('shiftChangeModal').style.display='flex';
    loadSummary(target.userId);
  };

  // wiring
  $('scmX').addEventListener('click', close);
  $('scmCancel').addEventListener('click', close);
  $('scmSave').addEventListener('click', save);
  $('scmTemplate').addEventListener('change', renderEffect);
  $('scmFrom').addEventListener('change', renderEffect);
  $('scmTo').addEventListener('change', renderEffect);
  $('shiftChangeModal').addEventListener('click', function(e){ if (e.target === this) close(); });
  document.querySelectorAll('#shiftChangeModal .scm-mode').forEach(el => el.addEventListener('click', () => setMode(el.dataset.mode)));
})();
</script>
