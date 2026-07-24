@extends('layouts.app')
@section('title', 'NF Assistant')

@section('content')
{{-- NF Assistant on the web (ASSISTANT-VIEW-PLAN §4). LEFT = money box mirror
     (auto-refresh), RIGHT = chat. Every fetch hits /assistant-view/* which
     reuses the SAME controllers the phone calls — so behaviour can never drift.
     Action parity with AssistantInboxScreen.js (mobile): suggestion one-taps,
     expense-category / vendor / customer pickers, ignore-with-remember (debits
     only), restore auto-ignored. All CSS scoped under .nfa. --}}
<style>
  .nfa{--ink:#111827;--ink2:#4b5563;--ink3:#98a2b3;--line:#e6e8ee;--surface:#fff;--surface2:#f6f8f5;
       --brand:#0F7A38;--brand-wash:#E5F4EA;--amber:#92400E;--amber-wash:#FFFBEB;--amber-line:#FCD34D;
       --blue:#1d4ed8;--blue-wash:#eff6ff;--purple:#5B21B6;--purple-wash:#F5F3FF;--red:#b42318;
       color:var(--ink);font-size:14px;line-height:1.45}
  .nfa *{box-sizing:border-box}
  .nfa .head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .nfa .head h1{font-size:20px;margin:0;font-weight:700;letter-spacing:-.01em}
  .nfa .head .sub{font-size:12.5px;color:var(--ink3)}
  .nfa .spacer{flex:1}
  .nfa .ro-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;
      font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:var(--brand-wash);color:var(--brand)}
  .nfa .dot{width:7px;height:7px;border-radius:50%;background:#10b981;display:inline-block}
  .nfa .grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,0.95fr);gap:18px;align-items:start}
  @media (max-width:980px){.nfa .grid{grid-template-columns:1fr}}
  .nfa .card{background:var(--surface);border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .nfa .strip{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
  .nfa .stat{flex:1;min-width:120px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:12px 14px}
  .nfa .stat .n{font-size:22px;font-weight:800;letter-spacing:-.02em}
  .nfa .stat .l{font-size:11px;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-top:2px}
  .nfa .sec{padding:12px 14px}
  .nfa .sec-h{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:6px}
  .nfa .pill{margin-left:auto;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px}
  .nfa .pill.amber{background:var(--amber-wash);color:var(--amber);border:1px solid var(--amber-line)}
  .nfa .pill.green{background:var(--brand-wash);color:var(--brand)}
  .nfa .row{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-top:1px solid var(--line);flex-wrap:wrap}
  .nfa .row .body{flex:1;min-width:0}
  .nfa .row .t{font-weight:600}
  .nfa .row .s{font-size:12px;color:var(--ink3);margin-top:1px}
  .nfa .amt{font-weight:800;white-space:nowrap}
  .nfa .amt.in{color:var(--brand)}.nfa .amt.out{color:var(--ink2)}
  .nfa .btns{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .nfa button.b{border:1px solid var(--line);background:#fff;border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:700;cursor:pointer;color:var(--ink)}
  .nfa button.b:hover{background:var(--surface2)}
  .nfa button.b.primary{background:var(--brand);border-color:var(--brand);color:#fff}
  .nfa button.b.sug{background:var(--brand);border-color:var(--brand);color:#fff}
  .nfa button.b.low{background:var(--amber-wash);border-color:var(--amber-line);color:var(--amber)}
  .nfa button.b.vend{background:var(--blue-wash);border-color:#bfdbfe;color:var(--blue)}
  .nfa button.b.warn{color:var(--red)}
  .nfa button.b:disabled{opacity:.5;cursor:default}
  .nfa .auto{font-size:11px;font-weight:700;color:var(--blue)}
  .nfa .empty{color:var(--ink3);font-size:13px;padding:6px 0}
  .nfa .muted{color:var(--ink3)}
  /* inline picker panel (expense category / vendor / customer) */
  .nfa .picker{flex-basis:100%;background:var(--surface2);border:1px solid var(--line);border-radius:10px;padding:10px;margin-top:8px}
  .nfa .picker .ph{font-size:12px;font-weight:800;margin-bottom:8px;display:flex;align-items:center}
  .nfa .picker .ph .x{margin-left:auto;cursor:pointer;color:var(--ink3);font-weight:700;padding:0 4px}
  .nfa .picker input{width:100%;border:1px solid var(--line);border-radius:8px;padding:8px 10px;font-size:13px;outline:none;background:#fff}
  .nfa .picker input:focus{border-color:var(--brand)}
  .nfa .chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
  .nfa .chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer}
  .nfa .chip:hover{border-color:var(--brand);color:var(--brand)}
  .nfa .pres{margin-top:8px}
  .nfa .pres .pr{display:flex;justify-content:space-between;gap:10px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;background:#fff;margin-bottom:6px;cursor:pointer;font-size:13px}
  .nfa .pres .pr:hover{border-color:var(--brand)}
  .nfa .pres .pr .m{color:var(--ink3);font-size:12px;white-space:nowrap}
  /* chat */
  .nfa .chat{display:flex;flex-direction:column;height:calc(100vh - 190px);min-height:460px}
  .nfa .chat-h{padding:11px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px}
  .nfa .chat-h .av{width:30px;height:30px;border-radius:50%;background:var(--brand-wash);display:flex;align-items:center;justify-content:center;font-size:15px}
  .nfa .chat-h .nm{font-weight:800}
  .nfa .chat-h .tg{font-size:11.5px;color:var(--ink3)}
  .nfa .thread{flex:1;overflow-y:auto;padding:14px;background:var(--surface2);display:flex;flex-direction:column;gap:10px}
  .nfa .bub{max-width:82%;padding:9px 12px;border-radius:14px;font-size:13.5px;white-space:pre-wrap;word-wrap:break-word}
  .nfa .bub.user{align-self:flex-end;background:#DCF8C6;border-bottom-right-radius:4px}
  .nfa .bub.asst{align-self:flex-start;background:#fff;border:1px solid var(--line);border-bottom-left-radius:4px}
  .nfa .cardbox{align-self:flex-start;max-width:88%;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px;box-shadow:0 4px 14px rgba(16,24,40,.06)}
  .nfa .cardbox .cs{font-weight:800;margin-bottom:8px}
  .nfa .crow{display:flex;justify-content:space-between;gap:12px;font-size:12.5px;padding:2px 0}
  .nfa .crow .k{color:var(--ink3)}
  .nfa .cstatus{font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;display:inline-block;margin-top:8px}
  .nfa .cstatus.pending{background:var(--amber-wash);color:var(--amber)}
  .nfa .cstatus.confirmed{background:var(--brand-wash);color:var(--brand)}
  .nfa .cstatus.cancelled,.nfa .cstatus.expired,.nfa .cstatus.failed{background:#fef3f2;color:var(--red)}
  .nfa .compose{display:flex;gap:8px;padding:11px;border-top:1px solid var(--line);background:#fff}
  .nfa .compose input{flex:1;border:1px solid var(--line);border-radius:11px;padding:10px 12px;font-size:14px;outline:none}
  .nfa .compose input:focus{border-color:var(--brand)}
  .nfa .compose button{border:0;background:var(--brand);color:#fff;border-radius:11px;padding:0 16px;font-weight:800;cursor:pointer}
  .nfa .compose button:disabled{opacity:.5;cursor:default}
  .nfa .toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:10px 16px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity .2s;pointer-events:none;max-width:80vw;text-align:center}
  .nfa .toast.show{opacity:1}
</style>

<div class="nfa" id="nfaRoot">
  <div class="head">
    <h1>✨ NF Assistant</h1>
    <span class="sub">Live money boxes &amp; chat — the same actions as the phone.</span>
    <span class="spacer"></span>
    <span class="ro-chip"><span class="dot" id="nfaLive"></span> auto-refresh</span>
  </div>

  {{-- Counters strip --}}
  <div class="strip" id="nfaStrip">
    <div class="stat"><div class="n" id="stTosort">–</div><div class="l">To sort</div></div>
    <div class="stat"><div class="n" id="stMatched">–</div><div class="l">Credits matched today</div></div>
    <div class="stat"><div class="n" id="stPending">–</div><div class="l">Cards waiting</div></div>
    <div class="stat"><div class="n" id="stDone">–</div><div class="l">Done today</div></div>
    <div class="stat"><div class="n" id="stDoneAmt">–</div><div class="l">Recorded today (Rs)</div></div>
  </div>

  <div class="grid">
    {{-- LEFT: money box --}}
    <div class="card" id="nfaBox">
      <div class="sec"><div class="sec-h">Money inbox <span class="muted" id="boxUpdated" style="margin-left:auto;font-weight:600;text-transform:none;letter-spacing:0"></span></div>
        <div id="boxBody"><div class="empty">Loading…</div></div>
      </div>
    </div>

    {{-- RIGHT: chat --}}
    <div class="card">
      <div class="chat">
        <div class="chat-h">
          <div class="av">✨</div>
          <div><div class="nm">NF Assistant</div><div class="tg">records expenses &amp; payments · answers questions</div></div>
          <span class="spacer"></span>
          <button class="b" id="newTopicBtn" title="Start a fresh topic">＋ New</button>
        </div>
        <div class="thread" id="thread"><div class="empty" style="margin:auto">Loading chat…</div></div>
        <div class="compose">
          <input id="msgInput" type="text" placeholder="Message NF Assistant…" autocomplete="off" />
          <button id="sendBtn">Send</button>
        </div>
      </div>
    </div>
  </div>
  <div class="toast" id="nfaToast"></div>
</div>

<script>
(function(){
  const CSRF = '{{ csrf_token() }}';
  const U = {
    summary: '{{ route('assistant-view.summary') }}',
    inbox:   '{{ route('assistant-view.inbox') }}',
    history: '{{ route('assistant-view.history') }}',
    message: '{{ route('assistant-view.message') }}',
    newTopic:'{{ route('assistant-view.new-topic') }}',
    vendorSearch:   '{{ route('assistant-view.vendor-search') }}',
    customerSearch: '{{ route('assistant-view.customer-search') }}',
    confirm: id => '{{ url('assistant-view/confirm') }}/'+id,
    cancel:  id => '{{ url('assistant-view/cancel') }}/'+id,
    choose:  id => '{{ url('assistant-view/draft') }}/'+id+'/choose',
    smsMatch:  id => '{{ url('assistant-view/sms') }}/'+id+'/match-credit',
    smsIgnore: id => '{{ url('assistant-view/sms') }}/'+id+'/ignore',
    smsRestore:id => '{{ url('assistant-view/sms') }}/'+id+'/restore',
    smsDraft:  id => '{{ url('assistant-view/sms') }}/'+id+'/draft',
  };
  const $ = s => document.querySelector(s);
  const esc = s => (s==null?'':String(s)).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const money = n => 'Rs '+Number(n||0).toLocaleString();
  let toastT;
  function toast(m){const t=$('#nfaToast');t.textContent=m;t.classList.add('show');clearTimeout(toastT);toastT=setTimeout(()=>t.classList.remove('show'),3200);}

  async function api(url, opts={}){
    const o = Object.assign({headers:{}}, opts);
    o.headers['Accept']='application/json';
    o.headers['X-CSRF-TOKEN']=CSRF;
    o.headers['X-Requested-With']='XMLHttpRequest';
    if(o.body && !(o.body instanceof FormData)){o.headers['Content-Type']='application/json';o.body=JSON.stringify(o.body);}
    o.credentials='same-origin';
    const r = await fetch(url,o);
    let data={}; try{data=await r.json();}catch(e){}
    if(r.status===419){ data.message='Session expired — reload the page.'; }
    if(!r.ok && !data.message) data.message='Request failed ('+r.status+')';
    return {ok:r.ok, status:r.status, data};
  }

  /* ---------- money box ---------- */
  let busy = {};
  let lastInbox = null;                    // last inbox payload (picker chips need it)
  // While a picker is open, auto-refresh must NOT re-render the box or the
  // open panel + typed search would be wiped every 15s. Counters stay live.
  let picker = null;                       // {smsId, mode:'expense'|'vendor'|'customer'}
  let searchT = null;

  function renderBox(d){
    if(!d) return;
    const box = $('#boxBody');
    const parts = [];
    // ALL credits — unhandled ('new', actionable) AND today's auto-handled
    // (⚡, shown so the owner can watch). matchedList scopes handled ones to
    // today, so this can't grow unbounded.
    const credits = d.matched||[];
    const toSort = d.to_sort||[];
    const cards = d.pending_cards||[];
    const done = d.done_today||[];
    const autoIg = d.auto_ignored||[];

    if(cards.length){
      parts.push(secH('Waiting for confirm', cards.length, 'green'));
      cards.forEach(c=>parts.push(cardRow(c)));
    }
    if(credits.length){
      parts.push(secH('Incoming — customer credits', credits.length));
      credits.forEach(m=>parts.push(creditRow(m)));
    }
    if(toSort.length){
      parts.push(secH('Outgoing — needs sorting', toSort.length, 'amber'));
      toSort.forEach(s=>parts.push(debitRow(s)));
    }
    if(autoIg.length){
      parts.push(secH('Auto-ignored today', autoIg.length));
      autoIg.forEach(s=>parts.push(autoIgnoredRow(s)));
    }
    parts.push(secH('Done today', done.length));
    if(done.length){ done.forEach(x=>parts.push('<div class="row"><div class="body"><div class="t">'+esc(x.label)+'</div><div class="s">'+esc(x.type)+(x.time?' · '+esc(x.time):'')+'</div></div><div class="amt out">'+money(x.amount)+'</div></div>')); }
    else parts.push('<div class="empty">Nothing recorded yet today.</div>');

    box.innerHTML = parts.join('');
    const pin = box.querySelector('.picker input');
    if(pin) pin.focus();
  }
  function secH(t,n,cls){return '<div class="sec-h" style="margin-top:14px">'+esc(t)+(n!=null?'<span class="pill '+(cls||'')+'">'+n+'</span>':'')+'</div>';}
  function autoTag(a){return a==='mapped_customer'?'<span class="auto">⚡ auto-attached</span>':a==='proof_pair'?'<span class="auto">⚡ auto-verified</span>':a==='already_verified'?'<span class="auto">⚡ already verified</span>':'';}

  function cardRow(c){
    return '<div class="row"><div class="body"><div class="t">'+esc(c.summary)+'</div><div class="s">'+esc(c.type)+(c.time?' · '+esc(c.time):'')+'</div>'
      +'<div class="btns"><button class="b primary" data-act="confirm" data-id="'+c.id+'">Confirm</button>'
      +'<button class="b warn" data-act="cancel" data-id="'+c.id+'">Cancel</button></div></div></div>';
  }
  function creditRow(m){
    let btns='';
    if(!m.matched){
      if(m.suggested_customer) btns+='<button class="b sug" data-act="match" data-id="'+m.id+'" data-cust="'+m.suggested_customer.id+'">Match to '+esc(m.suggested_customer.name)+' →</button>';
      else if(m.suggested_order) btns+='<button class="b low" data-act="match" data-id="'+m.id+'" data-cust="'+m.suggested_order.customer_id+'">Only '+esc(m.suggested_order.order_number)+' — '+esc(m.suggested_order.customer_name)+'? →</button>';
      btns+='<button class="b" data-act="pick-customer" data-id="'+m.id+'">'+(m.suggested_customer||m.suggested_order?'Someone else…':'Match customer…')+'</button>';
      btns+='<button class="b warn" data-act="ignore-credit" data-id="'+m.id+'" data-cp="'+esc(m.counterparty||'')+'">Ignore</button>';
    }
    return '<div class="row"><div class="body"><div class="t">'+esc(m.counterparty||'Credit')+' '+autoTag(m.auto)+'</div>'
      +'<div class="s">'+(m.matched?'✓ matched':'Received'+(m.time?' · '+esc(m.time):''))+'</div>'
      +(btns?'<div class="btns">'+btns+'</div>':'')+'</div><div class="amt in">+ '+money(m.amount)+'</div>'
      +pickerPanel(m.id)+'</div>';
  }
  function debitRow(s){
    let btns='';
    if(s.suggestion){
      // Mobile parity (AssistantInboxScreen ~461): one-tap prefilled draft.
      if(s.suggestion.type==='vendor' && s.suggestion.entity_id)
        btns+='<button class="b vend" data-act="draft-vendor" data-id="'+s.id+'" data-vid="'+s.suggestion.entity_id+'">Payment to '+esc(s.suggestion.label)+' →</button>';
      else if(s.suggestion.type==='expense')
        btns+='<button class="b sug" data-act="draft-expense" data-id="'+s.id+'" data-cat="'+esc(s.suggestion.label)+'">Expense: '+esc(s.suggestion.label)+' →</button>';
    }
    btns+='<button class="b" data-act="pick-expense" data-id="'+s.id+'">Expense…</button>';
    btns+='<button class="b" data-act="pick-vendor" data-id="'+s.id+'">Vendor payment…</button>';
    btns+='<button class="b warn" data-act="ignore-debit" data-id="'+s.id+'" data-cp="'+esc(s.counterparty||'')+'">Ignore</button>';
    return '<div class="row"><div class="body"><div class="t">'+esc(s.bank_name||('Sender '+(s.sender_id||'?')))+'</div>'
      +'<div class="s">'+esc(s.counterparty||'Bank SMS')+(s.time?' · '+esc(s.time):'')+'</div>'
      +'<div class="btns">'+btns+'</div></div><div class="amt out">'+(s.amount?'− '+money(s.amount):'?')+'</div>'
      +pickerPanel(s.id)+'</div>';
  }
  function autoIgnoredRow(s){
    return '<div class="row"><div class="body"><div class="s">'+esc(s.counterparty||'SMS')+(s.amount?' · '+money(s.amount):'')+(s.time?' · '+esc(s.time):'')+'</div>'
      +'<div class="btns"><button class="b" data-act="restore" data-id="'+s.id+'">Restore</button>'
      +'<button class="b" data-act="restore-forget" data-id="'+s.id+'">Restore + forget rule</button></div></div></div>';
  }

  // Inline picker panel, rendered inside the matching row while open.
  function pickerPanel(smsId){
    if(!picker || picker.smsId!==smsId) return '';
    if(picker.mode==='expense'){
      const cats = (lastInbox&&lastInbox.expense_categories)||[];
      return '<div class="picker"><div class="ph">What was this expense for?<span class="x" data-act="close-picker">✕</span></div>'
        +(cats.length?'<div class="chips">'+cats.map(c=>'<span class="chip" data-act="cat" data-id="'+smsId+'" data-cat="'+esc(c)+'">'+esc(c)+'</span>').join('')+'</div>':'')
        +'<input type="text" id="pickerInput" placeholder="Or type a category and press Enter…" data-role="cat-input" data-id="'+smsId+'" /></div>';
    }
    const isV = picker.mode==='vendor';
    return '<div class="picker"><div class="ph">'+(isV?'Which vendor was paid?':'Whose payment is this?')+'<span class="x" data-act="close-picker">✕</span></div>'
      +'<input type="text" id="pickerInput" placeholder="Type 2+ letters to search…" data-role="'+(isV?'vendor-search':'customer-search')+'" data-id="'+smsId+'" />'
      +'<div class="pres" id="pickerResults"></div></div>';
  }
  function openPicker(smsId, mode){ picker={smsId, mode}; renderBox(lastInbox); }
  function closePicker(){ picker=null; renderBox(lastInbox); }

  async function loadBox(){
    const [sm, ib] = await Promise.all([api(U.summary), api(U.inbox)]);
    if(sm.ok && sm.data.success){
      $('#stTosort').textContent = sm.data.to_sort?.count ?? 0;
      $('#stMatched').textContent = sm.data.matched?.count ?? 0;
      $('#stPending').textContent = sm.data.pending_cards?.count ?? 0;
      $('#stDone').textContent = sm.data.done_today?.count ?? 0;
      $('#stDoneAmt').textContent = money(sm.data.done_today?.amount ?? 0);
    }
    if(ib.ok && ib.data.success){
      lastInbox = ib.data;
      if(!picker){ renderBox(lastInbox); $('#boxUpdated').textContent = 'updated '+new Date().toLocaleTimeString(); }
    }
    else if(ib.status===403){ $('#boxBody').innerHTML='<div class="empty">No assistant permission.</div>'; }
  }

  // ── actions (event delegation on the box) ─────────────────────────────────
  async function smsDraft(id, payload){
    const r = await api(U.smsDraft(id),{method:'POST',body:payload});
    toast(r.data.message||(r.ok?'Card ready — confirm it below or in the chat.':'Failed'));
    if(r.ok){ closePicker(); await loadChat(); }
    return r;
  }
  async function ignoreSms(id, cp, allowRemember){
    // Mobile parity: debits offer "always ignore" (undoable); credits don't.
    if(!window.confirm('Ignore this SMS'+(cp?' from "'+cp+'"':'')+'?')) return null;
    let remember=false;
    if(allowRemember && cp){
      remember = window.confirm('Also AUTO-ignore future SMS from "'+cp+'"?\n\nOK = always (they stay visible under Auto-ignored, undoable)\nCancel = just this once');
    }
    const r = await api(U.smsIgnore(id),{method:'POST',body:remember?{remember:true}:{}});
    if(r.ok && r.data.remembered) toast('Saved — future SMS from '+r.data.remembered+' will be auto-ignored.');
    else toast(r.ok?'Ignored':(r.data.message||'Failed'));
    return r;
  }

  $('#nfaBox').addEventListener('click', async e=>{
    const b = e.target.closest('[data-act]'); if(!b) return;
    const id=b.dataset.id, act=b.dataset.act;
    if(act==='close-picker'){ closePicker(); return; }
    if(act==='pick-expense'){ openPicker(Number(id),'expense'); return; }
    if(act==='pick-vendor'){ openPicker(Number(id),'vendor'); return; }
    if(act==='pick-customer'){ openPicker(Number(id),'customer'); return; }
    if(busy[id]) return; busy[id]=1; if(b.disabled!==undefined) b.disabled=true;
    try{
      if(act==='confirm'){ const r=await api(U.confirm(id),{method:'POST',body:{}}); toast(r.data.message||'Done'); if(r.ok) loadChat(); }
      else if(act==='cancel'){ const r=await api(U.cancel(id),{method:'POST',body:{}}); toast(r.data.message||'Cancelled'); if(r.ok) loadChat(); }
      else if(act==='ignore-debit'){ if(await ignoreSms(id, b.dataset.cp, true)===null){ busy[id]=0; b.disabled=false; return; } }
      else if(act==='ignore-credit'){ if(await ignoreSms(id, b.dataset.cp, false)===null){ busy[id]=0; b.disabled=false; return; } }
      else if(act==='restore'){ const r=await api(U.smsRestore(id),{method:'POST',body:{}}); toast(r.ok?'Restored to the inbox':(r.data.message||'Failed')); }
      else if(act==='restore-forget'){ const r=await api(U.smsRestore(id),{method:'POST',body:{forget:true}}); toast(r.ok?'Restored — rule removed, future SMS will show again':(r.data.message||'Failed')); }
      else if(act==='match'){ const r=await api(U.smsMatch(id),{method:'POST',body:{customer_id:Number(b.dataset.cust)}}); toast(r.data.message||(r.ok?'Card prepared — see chat':'Failed')); if(r.ok) loadChat(); }
      else if(act==='match-picked'){ const r=await api(U.smsMatch(id),{method:'POST',body:{customer_id:Number(b.dataset.cust)}}); toast(r.data.message||(r.ok?'Card prepared — see chat':'Failed')); if(r.ok){ closePicker(); loadChat(); } }
      else if(act==='draft-vendor' || act==='vend-picked'){ await smsDraft(id,{type:'vendor_payment', vendor_id:Number(b.dataset.vid)}); }
      else if(act==='draft-expense' || act==='cat'){ await smsDraft(id,{type:'expense', expense_category:b.dataset.cat}); }
      await loadBox();
      if(picker) renderBox(lastInbox);
    }catch(err){ toast('Something went wrong'); }
    finally{ busy[id]=0; if(b.disabled!==undefined) b.disabled=false; }
  });

  // free-text expense category: Enter records
  $('#nfaBox').addEventListener('keydown', async e=>{
    const inp=e.target.closest('input[data-role="cat-input"]'); if(!inp || e.key!=='Enter') return;
    const cat=inp.value.trim(); if(!cat) return;
    await smsDraft(Number(inp.dataset.id), {type:'expense', expense_category:cat});
    await loadBox(); if(picker) renderBox(lastInbox);
  });

  // vendor / customer live search (debounced; only the results div re-renders,
  // so the input keeps focus)
  $('#nfaBox').addEventListener('input', e=>{
    const inp=e.target.closest('input[data-role="vendor-search"],input[data-role="customer-search"]'); if(!inp) return;
    const isV = inp.dataset.role==='vendor-search';
    const smsId = inp.dataset.id;
    clearTimeout(searchT);
    const q=inp.value.trim();
    if(q.length<2){ const d=$('#pickerResults'); if(d) d.innerHTML=''; return; }
    searchT=setTimeout(async ()=>{
      const r = await api((isV?U.vendorSearch:U.customerSearch)+'?q='+encodeURIComponent(q));
      const d=$('#pickerResults'); if(!d||!r.ok) return;
      const rows = isV ? (r.data.vendors||[]) : (r.data.customers||[]);
      if(!rows.length){ d.innerHTML='<div class="empty">No match.</div>'; return; }
      d.innerHTML = rows.map(x=> isV
        ? '<div class="pr" data-act="vend-picked" data-id="'+smsId+'" data-vid="'+x.id+'"><span>'+esc(x.name)+'</span><span class="m">owed '+money(x.owed)+'</span></div>'
        : '<div class="pr" data-act="match-picked" data-id="'+smsId+'" data-cust="'+x.id+'"><span>'+esc(x.name)+'</span><span class="m">'+(x.open_orders?x.open_orders+' in approvals':'nothing in approvals')+'</span></div>'
      ).join('');
    }, 300);
  });

  /* ---------- chat ---------- */
  let pinned = true;
  const thread = $('#thread');
  thread.addEventListener('scroll', ()=>{ pinned = (thread.scrollHeight - thread.scrollTop - thread.clientHeight) < 60; });
  function scrollEnd(){ if(pinned) thread.scrollTop = thread.scrollHeight; }

  function renderChat(msgs){
    if(!msgs.length){ thread.innerHTML='<div class="empty" style="margin:auto">Ask me to record something, or "how much did we spend this month?"</div>'; return; }
    thread.innerHTML = msgs.map(m=>{
      // Mobile parity: a message with a draft shows the TEXT bubble AND the
      // card (AssistantChatScreen renders both) — never swallow the reply.
      let out='';
      if(m.content) out+='<div class="bub '+(m.role==='user'?'user':'asst')+'">'+esc(m.content)+'</div>';
      if(m.draft) out+=cardBox(m);
      return out;
    }).join('');
    scrollEnd();
  }
  function cardBox(m){
    const d=m.draft; const rows=(d.rows||[]).map(r=>'<div class="crow"><span class="k">'+esc(r.label)+'</span><span>'+esc(r.value)+'</span></div>').join('');
    let foot='';
    if(d.status==='pending'){
      if(d.choices){ foot=(d.choices.options||[]).map(o=>'<button class="b" data-cact="choose" data-id="'+d.id+'" data-opt="'+o.id+'">'+esc(o.name)+'</button>').join('') + '<div class="s" style="flex-basis:100%;margin-top:6px;font-size:12px;color:var(--ink3)">'+esc(d.choices.label||'')+'</div>'; }
      else { foot='<button class="b primary" data-cact="confirm" data-id="'+d.id+'">Confirm</button><button class="b warn" data-cact="cancel" data-id="'+d.id+'">Cancel</button>'; }
    } else {
      foot='<span class="cstatus '+esc(d.status)+'">'+(d.status==='confirmed'?'✓ Recorded':esc(d.status))+'</span>'+(d.error?'<div style="flex-basis:100%;font-size:12px;color:var(--red);margin-top:4px">'+esc(d.error)+'</div>':'');
    }
    return '<div class="cardbox"><div class="cs">'+esc(d.summary)+'</div>'+rows+'<div class="btns" style="margin-top:10px">'+foot+'</div></div>';
  }
  async function loadChat(){
    const r = await api(U.history);
    if(r.ok && r.data.success) renderChat(r.data.messages||[]);
    else if(r.status===403) thread.innerHTML='<div class="empty" style="margin:auto">No assistant permission.</div>';
  }
  thread.addEventListener('click', async e=>{
    const b=e.target.closest('button[data-cact]'); if(!b) return;
    const id=b.dataset.id, act=b.dataset.cact; if(busy['c'+id])return; busy['c'+id]=1; b.disabled=true;
    try{
      let r;
      if(act==='confirm') r=await api(U.confirm(id),{method:'POST',body:{}});
      else if(act==='cancel') r=await api(U.cancel(id),{method:'POST',body:{}});
      else if(act==='choose') r=await api(U.choose(id),{method:'POST',body:{option_id:Number(b.dataset.opt)}});
      if(r && r.data.message) toast(r.data.message);
      await loadChat(); await loadBox();
    }catch(err){ toast('Something went wrong'); }
    finally{ busy['c'+id]=0; }
  });

  let sending=false;
  async function send(){
    const inp=$('#msgInput'); const text=inp.value.trim();
    if(!text || sending) return; sending=true; $('#sendBtn').disabled=true;
    // optimistic bubble
    const b=document.createElement('div'); b.className='bub user'; b.textContent=text; thread.appendChild(b); pinned=true; scrollEnd();
    inp.value='';
    const fd=new FormData(); fd.append('message', text);
    const r = await api(U.message,{method:'POST', body:fd});
    if(!r.ok && r.data.message) toast(r.data.message);
    await loadChat(); await loadBox();
    sending=false; $('#sendBtn').disabled=false; inp.focus();
  }
  $('#sendBtn').addEventListener('click', send);
  $('#msgInput').addEventListener('keydown', e=>{ if(e.key==='Enter') send(); });
  $('#newTopicBtn').addEventListener('click', async ()=>{ const r=await api(U.newTopic,{method:'POST',body:{}}); toast(r.data.message||'Fresh start'); loadChat(); });

  // boot + auto-refresh the money box (chat refreshes on action/send/visibility)
  loadBox(); loadChat();
  setInterval(loadBox, 15000);
  document.addEventListener('visibilitychange', ()=>{ if(!document.hidden){ loadBox(); loadChat(); } });
})();
</script>
@endsection
