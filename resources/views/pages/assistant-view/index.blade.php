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
  /* strip tiles that filter the box below */
  .nfa .stat.pick{cursor:pointer;transition:border-color .12s,background .12s}
  .nfa .stat.pick:hover{border-color:var(--brand);background:var(--brand-wash)}
  .nfa .stat.pick.on{border-color:var(--brand);background:var(--brand-wash);box-shadow:inset 0 0 0 1px var(--brand)}
  .nfa .stat.pick.on .l{color:var(--brand)}
  .nfa .scopebar{display:flex;align-items:center;gap:8px;background:var(--brand-wash);
      border:1px solid #BFE3CC;border-radius:10px;padding:8px 12px;margin-top:12px;
      font-size:12.5px;font-weight:700;color:var(--brand);cursor:pointer}
  .nfa .scopebar .sb-x{margin-left:auto;opacity:.75}
  .nfa .scopebar:hover .sb-x{opacity:1}
  .nfa .sec{padding:12px 14px}
  .nfa .sec-h{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin-bottom:6px}
  .nfa .pill{margin-left:auto;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px}
  .nfa .pill.amber{background:var(--amber-wash);color:var(--amber);border:1px solid var(--amber-line)}
  .nfa .pill.green{background:var(--brand-wash);color:var(--brand)}
  /* direction boxes: money out / money in, each needs-action then collapsed done */
  .nfa .grp{border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin-top:12px;background:var(--surface)}
  .nfa .grp-h{display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:1px solid var(--line)}
  .nfa .grp-t{font-size:14.5px;font-weight:800}
  .nfa .grp-h .pill{margin-left:auto}
  .nfa .grp-clear{margin-left:auto;font-size:11.5px;font-weight:700;color:var(--ink3)}
  .nfa .sub-h{font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--ink3);margin:12px 0 2px}
  .nfa .donebar{margin-top:12px;padding:8px 10px;background:var(--surface2);border-radius:9px;
      font-size:12px;font-weight:700;color:var(--ink3);cursor:pointer;user-select:none}
  .nfa .donebar:hover{color:var(--ink2)}
  .nfa .donewrap .row:first-child{border-top:0}
  .nfa button.b.tag{background:var(--purple-wash);border-color:#ddd6fe;color:var(--purple)}
  .nfa button.b.acct{background:#F0F9FF;border-color:#bae6fd;color:#0369a1}
  .nfa button.b.more{color:var(--ink3)}
  .nfa .flag{color:var(--amber);font-weight:700}
  /* a credit the proof flow already identified — reported, not asked about */
  .nfa .row.settled{background:var(--brand-wash);border-radius:8px;padding-left:8px;padding-right:8px}
  .nfa .row.settled .proof{font-size:11px;font-weight:800}
  .nfa .settled-next{font-style:italic;opacity:.85}
  .nfa .hint{color:#0369a1;background:#F0F9FF;border-radius:6px;padding:3px 8px;margin-top:3px;display:inline-block}
  /* repeat debits from ONE account, clubbed so a double payment is obvious */
  .nfa .club{border:1px solid var(--amber-line);background:var(--amber-wash);border-radius:10px;padding:2px 10px 8px;margin-top:8px}
  .nfa .club-h{display:flex;align-items:center;gap:8px;padding:8px 0 2px;font-size:12.5px;font-weight:800;color:var(--amber)}
  .nfa .club-tot{margin-left:auto}
  .nfa .club-warn{font-size:11.5px;color:var(--amber);opacity:.85;padding-bottom:2px}
  .nfa .club .row{border-top:1px solid var(--amber-line)}
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

  {{-- Counters strip — mirrors the two inbox boxes exactly. The old five tiles
       mixed directions ("to sort" counted both) and split backward-looking work
       across "done" and "matched"; these three answer one question each:
       what's waiting from me going out, coming in, and what's already settled. --}}
  {{-- Each tile FILTERS the money box below to what it counts (mobile parity:
       the strip chips navigate with a focus param). A tile that only scrolls you
       to a combined list makes the number you clicked vanish into everything
       else. The Rs tile is informational, so it is not clickable. --}}
  <div class="strip" id="nfaStrip">
    <div class="stat pick" data-focus="out"><div class="n" id="stOut">–</div><div class="l">💸 Money out · waiting</div></div>
    <div class="stat pick" data-focus="in"><div class="n" id="stIn">–</div><div class="l">💰 Money in · waiting</div></div>
    <div class="stat pick" data-focus="handled"><div class="n" id="stHandled">–</div><div class="l">✓ Handled today</div></div>
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
    accountSearch:  '{{ route('assistant-view.account-search') }}',
    confirm: id => '{{ url('assistant-view/confirm') }}/'+id,
    cancel:  id => '{{ url('assistant-view/cancel') }}/'+id,
    choose:  id => '{{ url('assistant-view/draft') }}/'+id+'/choose',
    smsMatch:  id => '{{ url('assistant-view/sms') }}/'+id+'/match-credit',
    smsTargets:id => '{{ url('assistant-view/sms') }}/'+id+'/targets',
    smsAttach: id => '{{ url('assistant-view/sms') }}/'+id+'/attach',
    smsIgnore: id => '{{ url('assistant-view/sms') }}/'+id+'/ignore',
    smsRestore:id => '{{ url('assistant-view/sms') }}/'+id+'/restore',
    smsDraft:  id => '{{ url('assistant-view/sms') }}/'+id+'/draft',
    smsSaveMap:id => '{{ url('assistant-view/sms') }}/'+id+'/save-map',
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

  // The box is grouped by DIRECTION first (money out / money in) and by STATE
  // second (needs action on top, everything settled collapsed underneath).
  // Mobile parity: AssistantInboxScreen.js renders the same two boxes.
  let doneOpen = {out:false, in:false};
  const expanded = new Set();   // sms ids whose secondary actions are showing
  // Which strip tile is selected: 'all' | 'out' | 'in' | 'handled'. Mirrors the
  // mobile screen's `focus` route param.
  let focus = 'all';

  function renderBox(d){
    if(!d) return;
    const box = $('#boxBody');
    // ALL credits — unhandled ('new', actionable) AND today's auto-handled
    // (⚡, shown so the owner can watch). matchedList scopes handled ones to
    // today, so this can't grow unbounded.
    const credits = d.matched||[];
    const toSort = d.to_sort||[];
    const cards = d.pending_cards||[];
    const done = d.done_today||[];
    const autoIg = d.auto_ignored||[];
    const autoOut = d.auto_out||[];   // debits the sweep closed as already recorded
    const autoIn  = d.auto_in||[];    // credits closed as approved / verified

    const isOut = t => t==='expense'||t==='vendor_payment'||t==='vendor_purchase';
    const outCards = cards.filter(c=>isOut(c.type));
    const inCards  = cards.filter(c=>!isOut(c.type));   // payment_proof | shop_payment
    const outDone  = done.filter(x=>isOut(x.type));
    const inDone   = done.filter(x=>!isOut(x.type));
    // Three states, not two. `settled` = the proof pipeline already identified
    // this money (screenshot / bank email verified its order) and it clears
    // itself on approval — so it is reported, never asked about.
    // A row with no `state` (older payload, cached response) must FALL BACK to
    // "needs action" — never be filtered out. A credit that silently vanishes
    // from the box is money nobody is looking at.
    const creditsSettled = credits.filter(m=>m.state === 'settled');
    const creditsAction  = credits.filter(m=>!m.matched && m.state !== 'settled');
    const creditsOpen = credits.filter(m=>!m.matched);
    // A matched credit appears in BOTH `matched` and `auto_in` (verified: 100%
    // overlap on live data — matchedList and autoHandledToday select the same
    // rows). The auto row is the richer one (it states WHY and offers Bring
    // back), so it wins and the duplicate is dropped.
    const autoInIds = new Set(autoIn.map(a=>a.id));
    const creditsDone = credits.filter(m=>m.matched && !autoInIds.has(m.id));
    // Rows the counterparty map already recognises are one tap — kept apart
    // from the ones that still need Taimur to say what they were.
    const outKnown   = toSort.filter(s=>s.suggestion);
    const outUnknown = toSort.filter(s=>!s.suggestion);

    const outWaiting = toSort.length + outCards.length;
    // The pill counts what actually needs him — settled credits are excluded,
    // otherwise the number contradicts the list right under it.
    const inWaiting  = creditsAction.length + inCards.length;

    const parts = [];
    const showOut = focus === 'out' || focus === 'all';
    const showIn  = focus === 'in'  || focus === 'all';

    // Say the box is filtered, and give one click back — a filtered box and a
    // box that lost half its rows look identical otherwise.
    if(focus !== 'all'){
      parts.push('<div class="scopebar" data-act="focus-all">'
        + '<span>'+(focus==='out' ? 'Showing money out only'
                  : focus==='in'  ? 'Showing money in only'
                                  : 'Showing only what was handled today')+'</span>'
        + '<span class="sb-x">Show all ✕</span></div>');
    }

    /* ── handled: its OWN view, not the two waiting boxes with strips open.
       The tile counts settled work, so the view shows settled work only. ── */
    if(focus === 'handled'){
      const outRows = outDone.map(doneRow).join('') + autoOut.map(autoRow).join('') + autoIg.map(autoIgnoredRow).join('');
      const inRows  = autoIn.map(autoRow).join('') + creditsDone.map(creditRow).join('') + inDone.map(doneRow).join('');
      const total = outDone.length + autoOut.length + autoIg.length
                  + autoIn.length + creditsDone.length + inDone.length;
      parts.push('<div class="grp"><div class="grp-h"><span class="grp-t">✓ Handled today</span>'
        + '<span class="grp-clear">'+total+(total===1?' item':' items')+'</span></div>');
      if(!total){
        parts.push('<div class="empty">Nothing has been handled yet today.</div>');
      } else {
        if(outRows){ parts.push(sub('💸 Money out')); parts.push(outRows); }
        if(inRows){ parts.push(sub('💰 Money in')); parts.push(inRows); }
      }
      parts.push('</div>');
      box.innerHTML = parts.join('');
      const p0 = box.querySelector('.picker input'); if(p0) p0.focus();
      return;
    }

    /* ── money out ── */
    if(showOut){
    parts.push(groupHead('💸 Money out', outWaiting));
    if(outCards.length){ parts.push(sub('Waiting for your confirm')); outCards.forEach(c=>parts.push(cardRow(c))); }
    if(outKnown.length){ parts.push(sub('Recognised — one tap')); parts.push(clubbed(outKnown)); }
    if(outUnknown.length){
      parts.push(sub('Needs tagging — what was it?'));
      parts.push(ageSplit(outUnknown, s=>clubbed([s]), 'outun'));
    }
    if(!outWaiting) parts.push('<div class="empty">Nothing waiting — every bank debit is sorted.</div>');
    parts.push(doneStrip('out', outDone.length + autoIg.length + autoOut.length,
      () => outDone.map(doneRow).join('') + autoOut.map(autoRow).join('') + autoIg.map(autoIgnoredRow).join('')));
    parts.push('</div>');
    }

    /* ── money in ── */
    if(showIn){
    parts.push(groupHead('💰 Money in', inWaiting));
    if(inCards.length){ parts.push(sub('Waiting for your confirm')); inCards.forEach(c=>parts.push(cardRow(c))); }
    if(creditsAction.length){
      parts.push(sub('Customer payments — who paid?'));
      parts.push(ageSplit(creditsAction, creditRow, 'inact'));
    }
    if(!creditsAction.length && !inCards.length){
      parts.push('<div class="empty">Nothing waiting — every credit is identified.</div>');
    }
    // Already identified by the proof flow: shown so the money is visible and
    // auditable, folded away so it never competes with the rows that need him.
    if(creditsSettled.length){
      parts.push(foldStrip('insettled',
        '✓ Matched — clears on approval (' + creditsSettled.length + ')',
        () => creditsSettled.map(settledRow).join('')));
    }
    parts.push(doneStrip('in', creditsDone.length + inDone.length + autoIn.length,
      () => creditsDone.map(creditRow).join('') + autoIn.map(autoRow).join('') + inDone.map(doneRow).join('')));
    parts.push('</div>');
    }

    box.innerHTML = parts.join('');
    const pin = box.querySelector('.picker input');
    if(pin) pin.focus();
  }
  function groupHead(t,n){
    return '<div class="grp"><div class="grp-h"><span class="grp-t">'+esc(t)+'</span>'
      +(n>0?'<span class="pill amber">'+n+' to action</span>':'<span class="grp-clear">all clear</span>')+'</div>';
  }
  function sub(t){return '<div class="sub-h">'+esc(t)+'</div>';}

  // A generic collapsed strip (used for settled credits + stale rows). Built
  // lazily so a closed strip costs nothing.
  function foldStrip(key, label, build){
    const open = !!doneOpen[key];
    return '<div class="donebar" data-act="toggle-done" data-key="'+key+'">'+esc(label)+' '+(open?'▾':'▸')+'</div>'
      + (open ? '<div class="donewrap">'+build()+'</div>' : '');
  }

  // Rows older than a week fold away. They are never deleted — unexplained
  // money must keep nagging — but a 10-day-old row must not crowd out today's.
  const STALE_DAYS = 7;
  function ageSplit(rows, render, key){
    const fresh = rows.filter(r => (r.age_days||0) <= STALE_DAYS);
    const old   = rows.filter(r => (r.age_days||0) >  STALE_DAYS);
    let out = fresh.map(render).join('');
    if(old.length){
      out += foldStrip(key+'old', 'Older than a week ('+old.length+')', () => old.map(render).join(''));
    }
    return out;
  }

  /**
   * A credit the proof pipeline has ALREADY identified. This is a statement,
   * not a question: it shows BOTH sides — the credit and the invoice it matches
   * — plus how it was proven, and says what happens next. No action buttons,
   * because the only correct action (approve the order) lives in Online
   * Approvals and closes this row automatically.
   */
  function settledRow(m){
    const w = m.settled_with || {};
    return '<div class="row settled"><div class="body">'
      + '<div class="t">'+esc(m.counterparty || 'Credit')
      +   ' <span class="proof" style="color:'+esc(w.proof_color||'#16A34A')+'">'+esc(w.proof_label||'Matched')+'</span></div>'
      + '<div class="s">'+esc(w.order_number||'')+' · '+esc(w.customer_name||'')
      +   (w.order_total ? ' · invoice '+money(w.order_total) : '')+'</div>'
      // An amount-based match is honest about itself: the payer is confirmed by
      // the APPROVER (the blue chip on Online Approvals), not by a proof.
      + (w.basis==='amount'
          ? '<div class="s settled-next">Matched by amount — the approver confirms the payer; clears on approval.</div>'
          : '<div class="s settled-next">Clears itself when this order is approved.</div>')
      + '<div class="btns"><button class="b" data-act="pick-customer" data-id="'+m.id+'">Not this one…</button></div>'
      + '</div><div class="amt in">+ '+money(m.amount)+'</div>'+pickerPanel(m.id)+'</div>';
  }

  /**
   * Club debits that came from the SAME account under one heading.
   * Two of Taimur's rows were "I.SAEED · Rs 100,000" on Jul-25 and Jul-27 — as
   * separate cards they look like one row shown twice, which is exactly how the
   * same payment gets recorded a second time. Grouped, the repeat is explicit
   * and the total is stated, so a genuine double is obvious at a glance.
   * Rows keep their OWN buttons — nothing here records more than one at a time.
   */
  function clubbed(rows){
    const order = [], byKey = new Map();
    rows.forEach(r=>{
      const k = r.account_key || ('_solo'+r.id);
      if(!byKey.has(k)){ byKey.set(k, []); order.push(k); }
      byKey.get(k).push(r);
    });
    return order.map(k=>{
      const g = byKey.get(k);
      if(g.length < 2) return debitRow(g[0]);
      const total = g.reduce((s,r)=>s+Number(r.amount||0),0);
      const who = g[0].suggestion ? g[0].suggestion.label : (g[0].counterparty || 'the same account');
      return '<div class="club"><div class="club-h">'
        + '<span>'+esc(who)+' · '+g.length+' debits</span>'
        + '<span class="club-tot">'+money(total)+'</span></div>'
        + '<div class="club-warn">Same account on different days — check these are not one payment entered twice.</div>'
        + g.map(debitRow).join('') + '</div>';
    }).join('');
  }
  // Settled work, collapsed. Built lazily so a closed strip costs nothing.
  function doneStrip(key,n,build){
    if(!n) return '';
    const open = doneOpen[key];
    return '<div class="donebar" data-act="toggle-done" data-key="'+key+'">Done today ('+n+') '+(open?'▾':'▸')+'</div>'
      + (open ? '<div class="donewrap">'+build()+'</div>' : '');
  }
  function doneRow(x){
    return '<div class="row"><div class="body"><div class="t">'+esc(x.label)+'</div><div class="s">'+esc(x.type)+(x.time?' · '+esc(x.time):'')+'</div></div><div class="amt out">'+money(x.amount)+'</div></div>';
  }
  function autoTag(a){return a==='mapped_customer'?'<span class="auto">⚡ auto-attached</span>':a==='proof_pair'?'<span class="auto">⚡ auto-verified</span>':a==='already_verified'?'<span class="auto">⚡ already verified</span>':a==='approved_in_queue'?'<span class="auto">✓ approved in queue</span>':'';}

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
    const when = [m.date, m.time].filter(Boolean).map(esc).join(' · ');
    // Lead-less row context: the name resolved to one customer with nothing
    // pending — say so, with their last approved order, as information only.
    let hint = '';
    if(!m.matched && m.name_hint){
      const h = m.name_hint, lo = h.last_order;
      hint = '<div class="s hint">Name matches '+esc(h.customer_name)+' — nothing pending for them.'
        + (lo ? ' Last approved: '+esc(lo.order_number)+' · '+money(lo.amount)+' · '+esc(lo.approved_on)+'.' : '')
        + '</div>';
    }
    return '<div class="row"><div class="body"><div class="t">'+esc(m.counterparty||'Credit')+' '+autoTag(m.auto)+'</div>'
      +'<div class="s">'+(m.matched?'✓ matched':'Received'+(when?' · '+when:''))+'</div>'+hint
      +(btns?'<div class="btns">'+btns+'</div>':'')+'</div><div class="amt in">+ '+money(m.amount)+'</div>'
      +pickerPanel(m.id)+'</div>';
  }
  function debitRow(s){
    // WHO is the headline; the bank repeats on every row and belongs in the
    // meta line with the date/time/ref.
    const who = s.counterparty || s.bank_name || ('Sender '+(s.sender_id||'?'));
    const meta = [s.bank_name, s.date, s.time, s.reference ? 'ref '+s.reference : null]
      .filter(Boolean).map(esc).join(' · ')
      + (s.direction==='unknown' ? ' · <span class="flag">direction unclear</span>' : '');

    // One-tap when the account is already known.
    let lead='';
    if(s.suggestion){
      if(s.suggestion.type==='vendor' && s.suggestion.entity_id)
        lead='<button class="b vend" data-act="draft-vendor" data-id="'+s.id+'" data-vid="'+s.suggestion.entity_id+'">Payment to '+esc(s.suggestion.label)+' →</button>';
      else if(s.suggestion.type==='account' && s.suggestion.entity_id)
        lead='<button class="b acct" data-act="draft-transfer" data-id="'+s.id+'" data-aid="'+s.suggestion.entity_id+'">Transfer to '+esc(s.suggestion.label)+' →</button>';
      else if(s.suggestion.type==='expense')
        lead='<button class="b sug" data-act="draft-expense" data-id="'+s.id+'" data-cat="'+esc(s.suggestion.label)+'">Expense: '+esc(s.suggestion.label)+' →</button>';
    }

    // Everything else. On a recognised row these hide behind "Something else…"
    // so the one-tap stands alone instead of competing with four siblings.
    let rest='<button class="b" data-act="pick-expense" data-id="'+s.id+'">Expense…</button>'
      +'<button class="b" data-act="pick-vendor" data-id="'+s.id+'">Vendor payment…</button>'
      +'<button class="b" data-act="pick-account" data-id="'+s.id+'">Transfer…</button>'
      +'<button class="b warn" data-act="ignore-debit" data-id="'+s.id+'" data-cp="'+esc(s.counterparty||'')+'">Ignore</button>';
    // Teach the account WITHOUT recording anything — only when the SMS actually
    // carries an account key, since a name-only rule can never drive the
    // one-tap suggestion above.
    if(s.account_key && !s.suggestion){
      rest+='<button class="b tag" data-act="pick-tag" data-id="'+s.id+'" title="'+esc(s.account_key)+'">🏷 Always tag this account…</button>';
    }

    let btns;
    if(lead && !expanded.has(s.id)){
      btns = lead + '<button class="b more" data-act="expand" data-id="'+s.id+'">Something else…</button>';
    } else {
      btns = lead + rest;
    }

    return '<div class="row"><div class="body"><div class="t">'+esc(who)+'</div>'
      +'<div class="s">'+meta+'</div>'
      +'<div class="btns">'+btns+'</div></div><div class="amt out">'+(s.amount?'− '+money(s.amount):'?')+'</div>'
      +pickerPanel(s.id)+'</div>';
  }
  // A row the SWEEP closed on its own. It must say WHY, and be one tap from
  // coming back — automation you cannot reverse is automation you cannot trust.
  function autoRow(s){
    let btns = '<button class="b" data-act="restore" data-id="'+s.id+'">Bring back</button>';
    // Reconciling a vendor payment tells us whose account this is, but nobody
    // was ever asked to remember it — so offer that here, for free.
    if(s.teach && s.teach.account){
      btns += '<button class="b tag" data-act="pick-tag" data-id="'+s.id+'" title="'+esc(s.teach.account)+'">🏷 Remember this account…</button>';
    }
    return '<div class="row"><div class="body">'
      + '<div class="t" style="font-weight:600">'+esc(s.counterparty||'Bank SMS')+' <span class="auto">⚡</span></div>'
      + '<div class="s">'+esc(s.note||'Handled automatically')+(s.time?' · '+esc(s.time):'')+'</div>'
      + '<div class="btns">'+btns+'</div></div>'
      + '<div class="amt out">'+money(s.amount)+'</div>'+pickerPanel(s.id)+'</div>';
  }
  function autoIgnoredRow(s){
    return '<div class="row"><div class="body"><div class="s">'+esc(s.counterparty||'SMS')+(s.amount?' · '+money(s.amount):'')+(s.time?' · '+esc(s.time):'')+'</div>'
      +'<div class="btns"><button class="b" data-act="restore" data-id="'+s.id+'">Restore</button>'
      +'<button class="b" data-act="restore-forget" data-id="'+s.id+'">Restore + forget rule</button></div></div></div>';
  }

  // Inline picker panel, rendered inside the matching row while open.
  // picker.tag = "remember this account as…" instead of "record this payment as…"
  function pickerPanel(smsId){
    if(!picker || picker.smsId!==smsId) return '';
    const tagNote = picker.tag ? '<div class="s" style="margin:-4px 0 8px">Saves the account for next time. Nothing is recorded now.</div>' : '';
    // Tag mode first asks vendor-or-expense; both then reuse the pickers below.
    if(picker.mode==='tag'){
      return '<div class="picker"><div class="ph">Remember this account as…<span class="x" data-act="close-picker">✕</span></div>'+tagNote
        +'<div class="btns"><button class="b vend" data-act="pick-tag-vendor" data-id="'+smsId+'">A vendor…</button>'
        +'<button class="b" data-act="pick-tag-expense" data-id="'+smsId+'">An expense category…</button>'
        +'<button class="b acct" data-act="pick-tag-account" data-id="'+smsId+'">One of our accounts…</button></div></div>';
    }
    if(picker.mode==='expense'){
      const cats = (lastInbox&&lastInbox.expense_categories)||[];
      return '<div class="picker"><div class="ph">'+(picker.tag?'Which expense is this account always for?':'What was this expense for?')+'<span class="x" data-act="close-picker">✕</span></div>'+tagNote
        +(cats.length?'<div class="chips">'+cats.map(c=>'<span class="chip" data-act="cat" data-id="'+smsId+'" data-cat="'+esc(c)+'">'+esc(c)+'</span>').join('')+'</div>':'')
        +'<input type="text" id="pickerInput" placeholder="Or type a category and press Enter…" data-role="cat-input" data-id="'+smsId+'" /></div>';
    }
    if(picker.mode==='account'){
      return '<div class="picker"><div class="ph">'+(picker.tag?'Which account does this always go to?':'Which account did the money go to?')+'<span class="x" data-act="close-picker">✕</span></div>'+tagNote
        +'<div class="s" style="margin:-4px 0 8px">Our own money moving — a rider or staff float, NF food, another till. Not an expense.</div>'
        +'<input type="text" id="pickerInput" placeholder="Search accounts…" data-role="account-search" data-id="'+smsId+'" />'
        +'<div class="pres" id="pickerResults"></div></div>';
    }
    const isV = picker.mode==='vendor';
    return '<div class="picker"><div class="ph">'+(isV?(picker.tag?'Whose account is this?':'Which vendor was paid?'):'Whose payment is this?')+'<span class="x" data-act="close-picker">✕</span></div>'+tagNote
      +'<input type="text" id="pickerInput" placeholder="Type 2+ letters to search…" data-role="'+(isV?'vendor-search':'customer-search')+'" data-id="'+smsId+'" />'
      +'<div class="pres" id="pickerResults"></div></div>';
  }
  // Filter the box from a strip tile. Clicking the tile you're already on clears
  // the filter, so a tile is a toggle rather than a one-way trip.
  function setFocus(next){
    focus = (next === focus && next !== 'all') ? 'all' : next;
    document.querySelectorAll('#nfaStrip .stat.pick').forEach(el => {
      el.classList.toggle('on', el.dataset.focus === focus);
    });
    renderBox(lastInbox);
  }
  // Guarded: this whole file is one IIFE, so an unexpected missing element here
  // would throw and take the chat down with it.
  const stripEl = document.getElementById('nfaStrip');
  if(stripEl) stripEl.addEventListener('click', e => {
    const tile = e.target.closest('.stat.pick');
    if(tile) setFocus(tile.dataset.focus);
  });

  function openPicker(smsId, mode, tag){
    picker={smsId, mode, tag:!!tag};
    renderBox(lastInbox);
    if(mode==='account') primeAccountPicker();
  }
  function closePicker(){ picker=null; renderBox(lastInbox); }

  // Remember "this account belongs to X" without recording a payment. Money-safe:
  // vendor/expense rules only ever pre-fill a card, they never post.
  async function saveMap(id, entityType, entityId, entityLabel){
    const body = {entity_type:entityType};
    if(entityId) body.entity_id = Number(entityId);
    if(entityLabel) body.entity_label = entityLabel;
    const r = await api(U.smsSaveMap(id),{method:'POST',body});
    toast(r.data.message || (r.ok?'Saved — I\'ll recognise it next time.':'Could not save that.'));
    if(r.ok) closePicker();
    return r;
  }

  async function loadBox(){
    const [sm, ib] = await Promise.all([api(U.summary), api(U.inbox)]);
    if(sm.ok && sm.data.success){
      $('#stOut').textContent = sm.data.money_out?.count ?? 0;
      $('#stIn').textContent = sm.data.money_in?.count ?? 0;
      $('#stHandled').textContent = sm.data.handled?.count ?? 0;
      $('#stDoneAmt').textContent = money(sm.data.handled?.amount ?? sm.data.done_today?.amount ?? 0);
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

  /**
   * The customer has NOTHING awaiting approval, so a proof card can't be
   * drafted — but the money is still theirs. Show their real orders (still
   * open, or approved in the last month, labelled honestly) and attach the
   * credit to the one the human picks. A direct pick is recorded as
   * manual_confirmed — the strongest match there is — and teaches the payer's
   * bank name, so this person auto-matches next time.
   *
   * This is the Nouman-cleanup path: his order was approved before anyone
   * could re-point the credit, and the old flow just said "nothing to attach
   * to" and gave up.
   */
  async function showOrderTargets(smsId, custId){
    const r = await api(U.smsTargets(smsId)+'?customer_id='+custId);
    if(!r.ok || !r.data.orders){ toast(r.data.message||'Could not load their orders'); return; }
    const cust = r.data.customer||{};
    if(cust.is_shop){ toast((cust.name||'This customer')+' is a SHOP — record shop money from the chat, not as a proof.'); return; }
    const orders = r.data.orders;
    if(!orders.length){ toast((cust.name||'They')+' have no orders in the last month to attach this to.'); return; }

    const label = {awaiting_approval:'awaiting approval', open:'open', already_approved:'already approved'};
    const tint  = {awaiting_approval:'#B45309', open:'#1D4ED8', already_approved:'#047857'};
    const rows = orders.map(o =>
      '<div class="ord-target" data-oid="'+o.id+'" style="display:flex;justify-content:space-between;gap:10px;padding:9px 10px;border:1px solid #E5E7EB;border-radius:9px;margin-bottom:6px;cursor:pointer;background:#fff;">'
      +'<div><b>'+esc(o.order_number)+'</b> <span style="color:#6B7280;font-size:12px;">'+esc((o.order_date||'').slice(0,10))+'</span></div>'
      +'<div style="text-align:right;"><span style="font-size:12px;color:'+(tint[o.group]||'#6B7280')+';">'+(label[o.group]||o.group)+'</span> '
      +'<b>'+(o.balance>0.01?money(o.balance):'settled')+'</b></div></div>').join('');

    const ov = document.createElement('div');
    ov.id='ordTargetsOverlay';
    ov.style.cssText='position:fixed;inset:0;background:rgba(17,24,39,.5);z-index:9000;display:flex;align-items:center;justify-content:center;padding:14px;';
    ov.innerHTML = '<div style="background:#fff;border-radius:13px;max-width:460px;width:100%;max-height:80vh;overflow:auto;padding:16px;">'
      +'<div style="display:flex;justify-content:space-between;margin-bottom:4px;"><b>Which order is this payment for?</b><span id="ordTargetsClose" style="cursor:pointer;color:#9CA3AF;font-size:18px;">✕</span></div>'
      +'<div style="font-size:12.5px;color:#6B7280;margin-bottom:10px;">'+esc(cust.name||'')+' has nothing awaiting approval — pick from their recent orders. Attaching to an approved order is record-keeping only; no money moves.</div>'
      +rows+'</div>';
    ov.addEventListener('click', async ev=>{
      if(ev.target===ov || ev.target.id==='ordTargetsClose'){ ov.remove(); return; }
      const row = ev.target.closest('.ord-target'); if(!row) return;
      row.style.opacity='.5';
      const a = await api(U.smsAttach(smsId),{method:'POST',body:{customer_id:custId, order_id:Number(row.dataset.oid)}});
      toast(a.data.message||(a.ok?'Attached.':'Failed'));
      if(a.ok){ ov.remove(); await loadBox(); loadChat(); } else { row.style.opacity='1'; }
    });
    document.body.appendChild(ov);
  }

  $('#nfaBox').addEventListener('click', async e=>{
    const b = e.target.closest('[data-act]'); if(!b) return;
    const id=b.dataset.id, act=b.dataset.act;
    if(act==='close-picker'){ closePicker(); return; }
    if(act==='pick-expense'){ openPicker(Number(id),'expense'); return; }
    if(act==='pick-vendor'){ openPicker(Number(id),'vendor'); return; }
    if(act==='pick-customer'){ openPicker(Number(id),'customer'); return; }
    if(act==='pick-account'){ openPicker(Number(id),'account'); return; }
    if(act==='pick-tag'){ openPicker(Number(id),'tag'); return; }
    if(act==='pick-tag-vendor'){ openPicker(Number(id),'vendor',true); return; }
    if(act==='pick-tag-expense'){ openPicker(Number(id),'expense',true); return; }
    if(act==='pick-tag-account'){ openPicker(Number(id),'account',true); return; }
    if(act==='expand'){ expanded.add(Number(id)); renderBox(lastInbox); return; }
    if(act==='toggle-done'){ doneOpen[b.dataset.key]=!doneOpen[b.dataset.key]; renderBox(lastInbox); return; }
    if(act==='focus-all'){ setFocus('all'); return; }
    if(busy[id]) return; busy[id]=1; if(b.disabled!==undefined) b.disabled=true;
    try{
      if(act==='confirm'){ const r=await api(U.confirm(id),{method:'POST',body:{}}); toast(r.data.message||'Done'); if(r.ok) loadChat(); }
      else if(act==='cancel'){ const r=await api(U.cancel(id),{method:'POST',body:{}}); toast(r.data.message||'Cancelled'); if(r.ok) loadChat(); }
      else if(act==='ignore-debit'){ if(await ignoreSms(id, b.dataset.cp, true)===null){ busy[id]=0; b.disabled=false; return; } }
      else if(act==='ignore-credit'){ if(await ignoreSms(id, b.dataset.cp, false)===null){ busy[id]=0; b.disabled=false; return; } }
      else if(act==='restore'){ const r=await api(U.smsRestore(id),{method:'POST',body:{}}); toast(r.ok?'Restored to the inbox':(r.data.message||'Failed')); }
      else if(act==='restore-forget'){ const r=await api(U.smsRestore(id),{method:'POST',body:{forget:true}}); toast(r.ok?'Restored — rule removed, future SMS will show again':(r.data.message||'Failed')); }
      else if(act==='match'){ const r=await api(U.smsMatch(id),{method:'POST',body:{customer_id:Number(b.dataset.cust)}});
        // Customer has nothing awaiting approval (their invoice may already be
        // approved, or not raised yet) — the old dead-end. Offer their actual
        // orders instead, including recently-approved ones, for a direct attach.
        if(!r.ok && /no (open )?invoice/i.test(r.data.message||'')){ await showOrderTargets(id, Number(b.dataset.cust)); }
        else { toast(r.data.message||(r.ok?'Card prepared — see chat':'Failed')); if(r.ok) loadChat(); } }
      else if(act==='match-picked'){ const r=await api(U.smsMatch(id),{method:'POST',body:{customer_id:Number(b.dataset.cust)}});
        if(!r.ok && /no (open )?invoice/i.test(r.data.message||'')){ closePicker(); await showOrderTargets(id, Number(b.dataset.cust)); }
        else { toast(r.data.message||(r.ok?'Card prepared — see chat':'Failed')); if(r.ok){ closePicker(); loadChat(); } } }
      // In TAG mode the same pickers save a rule instead of preparing a card.
      else if(act==='draft-vendor' || act==='vend-picked'){
        if(picker && picker.tag) await saveMap(id,'vendor',b.dataset.vid,null);
        else await smsDraft(id,{type:'vendor_payment', vendor_id:Number(b.dataset.vid)});
      }
      else if(act==='draft-expense' || act==='cat'){
        if(picker && picker.tag) await saveMap(id,'expense',null,b.dataset.cat);
        else await smsDraft(id,{type:'expense', expense_category:b.dataset.cat});
      }
      else if(act==='draft-transfer' || act==='acct-picked'){
        if(picker && picker.tag) await saveMap(id,'account',b.dataset.aid,null);
        else await smsDraft(id,{type:'account_transfer', to_account_id:Number(b.dataset.aid)});
      }
      await loadBox();
      if(picker) renderBox(lastInbox);
    }catch(err){ toast('Something went wrong'); }
    finally{ busy[id]=0; if(b.disabled!==undefined) b.disabled=false; }
  });

  // free-text expense category: Enter records
  $('#nfaBox').addEventListener('keydown', async e=>{
    const inp=e.target.closest('input[data-role="cat-input"]'); if(!inp || e.key!=='Enter') return;
    const cat=inp.value.trim(); if(!cat) return;
    if(picker && picker.tag) await saveMap(Number(inp.dataset.id), 'expense', null, cat);
    else await smsDraft(Number(inp.dataset.id), {type:'expense', expense_category:cat});
    await loadBox(); if(picker) renderBox(lastInbox);
  });

  // vendor / customer live search (debounced; only the results div re-renders,
  // so the input keeps focus)
  $('#nfaBox').addEventListener('input', e=>{
    const inp=e.target.closest('input[data-role="vendor-search"],input[data-role="customer-search"],input[data-role="account-search"]'); if(!inp) return;
    const role = inp.dataset.role;
    const smsId = inp.dataset.id;
    clearTimeout(searchT);
    const q=inp.value.trim();
    // Accounts are a short, fixed list — show them all with an empty box.
    const minLen = role==='account-search' ? 0 : 2;
    if(q.length<minLen){ const d=$('#pickerResults'); if(d) d.innerHTML=''; return; }
    searchT=setTimeout(async ()=>{
      const url = role==='vendor-search' ? U.vendorSearch : role==='account-search' ? U.accountSearch : U.customerSearch;
      const r = await api(url+'?q='+encodeURIComponent(q));
      const d=$('#pickerResults'); if(!d||!r.ok) return;
      const rows = role==='vendor-search' ? (r.data.vendors||[])
                 : role==='account-search' ? (r.data.accounts||[])
                 : (r.data.customers||[]);
      if(!rows.length){ d.innerHTML='<div class="empty">No match.</div>'; return; }
      d.innerHTML = rows.map(x=>
        role==='vendor-search'
        ? '<div class="pr" data-act="vend-picked" data-id="'+smsId+'" data-vid="'+x.id+'"><span>'+esc(x.name)+'</span><span class="m">owed '+money(x.owed)+'</span></div>'
        : role==='account-search'
        ? '<div class="pr" data-act="acct-picked" data-id="'+smsId+'" data-aid="'+x.id+'"><span>'+esc(x.name)+'</span><span class="m">'+esc(x.group)+'</span></div>'
        : '<div class="pr" data-act="match-picked" data-id="'+smsId+'" data-cust="'+x.id+'"><span>'+esc(x.name)+'</span><span class="m">'+(x.open_orders?x.open_orders+' in approvals':'nothing in approvals')+'</span></div>'
      ).join('');
    }, 300);
  });

  // The account list is short and fixed, so populate it the moment the picker
  // opens rather than making the user type to discover what exists.
  function primeAccountPicker(){
    const inp = document.querySelector('input[data-role="account-search"]');
    if(inp) inp.dispatchEvent(new Event('input', {bubbles:true}));
  }

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
