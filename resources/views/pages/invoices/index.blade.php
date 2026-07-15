@extends('layouts.app')
@section('title', 'Invoices — Analysis')

@section('content')
{{-- Invoices — Analysis: read-only explorer. All CSS scoped under .inv-explore so it
     cannot collide with the app's global Metronic/Tailwind styles. Data comes from
     /invoices/data and /invoices/{id} (all GET). --}}
<style>
  .inv-explore{--surface:#fff;--surface-2:#f6f7f9;--ink:#1b1b29;--ink-2:#4b5563;--ink-3:#98a2b3;
    --line:#e6e8ee;--line-strong:#d0d5dd;--accent:#2a78d6;--accent-ink:#1c5cab;--accent-wash:#eaf2fc;
    --good-ink:#067647;--crit:#b42318;--crit-wash:#fef3f2;--shop:#5925dc;--shop-wash:#f4f0ff;
    --nf:#2a78d6;--kh:#0e9f6e;--qb:#dd9500;--shadow:0 12px 32px rgba(16,24,40,.16);
    color:var(--ink);font-size:14px;line-height:1.45;padding-bottom:40px}
  .inv-explore *{box-sizing:border-box}
  .inv-explore .head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:4px}
  .inv-explore .head h1{font-size:20px;margin:0;font-weight:700;letter-spacing:-.01em}
  .inv-explore .head .sub{font-size:12.5px;color:var(--ink-3)}
  .inv-explore .ro-chip{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;
    font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:var(--accent-wash);color:var(--accent-ink)}
  .inv-explore .spacer{flex:1}

  .inv-explore .filters{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:center;margin:14px 0 12px}
  .inv-explore .seg{display:inline-flex;background:var(--surface-2);border:1px solid var(--line);border-radius:9px;padding:2px;gap:2px}
  .inv-explore .seg button{border:0;background:transparent;color:var(--ink-2);font:inherit;font-size:12.5px;font-weight:600;padding:6px 12px;border-radius:7px;cursor:pointer}
  .inv-explore .seg button:hover{color:var(--ink)}
  .inv-explore .seg button.on{background:var(--surface);color:var(--ink);box-shadow:0 1px 2px rgba(16,24,40,.12)}
  .inv-explore input[type=search],.inv-explore input[type=date],.inv-explore select{
    font:inherit;font-size:12.5px;color:var(--ink);background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;padding:7px 10px}
  .inv-explore input[type=search]{min-width:220px;flex:1;max-width:320px}
  .inv-explore .daterow{display:flex;align-items:center;gap:6px;color:var(--ink-3);font-size:12px}
  .inv-explore .btn{font:inherit;font-size:12.5px;font-weight:600;padding:7px 13px;border-radius:8px;cursor:pointer;border:1px solid var(--line-strong);background:var(--surface);color:var(--ink-2);text-decoration:none;display:inline-block}
  .inv-explore .btn:hover{border-color:var(--ink-3);color:var(--ink)}
  .inv-explore details.more{position:relative}
  .inv-explore details.more>summary{list-style:none;cursor:pointer;font-size:12.5px;font-weight:600;color:var(--accent-ink);padding:7px 4px;user-select:none}
  .inv-explore details.more>summary::-webkit-details-marker{display:none}
  .inv-explore .morepanel{position:absolute;z-index:20;top:36px;left:0;background:var(--surface);border:1px solid var(--line-strong);border-radius:10px;box-shadow:var(--shadow);padding:12px 14px;display:flex;flex-direction:column;gap:10px;min-width:190px}
  .inv-explore .morepanel label{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);font-weight:700;display:flex;flex-direction:column;gap:4px}
  .inv-explore .morepanel select{background:var(--surface-2);border-color:var(--line)}

  .inv-explore .summary{display:flex;align-items:baseline;gap:8px 20px;flex-wrap:wrap;margin:2px 2px 12px;padding-bottom:12px;border-bottom:1px solid var(--line)}
  .inv-explore .summary .big{font-size:15px;font-weight:700}
  .inv-explore .summary .m{font-size:13px;color:var(--ink-2)}
  .inv-explore .summary .m b{color:var(--ink);font-weight:650;font-variant-numeric:tabular-nums}

  .inv-explore .card{background:var(--surface);border:1px solid var(--line);border-radius:12px;overflow:hidden}
  .inv-explore .tablewrap{overflow-x:auto}
  .inv-explore table{border-collapse:collapse;width:100%;font-size:12.5px;margin:0}
  .inv-explore th,.inv-explore td{text-align:left;padding:9px 14px;border-bottom:1px solid var(--line);white-space:nowrap;background:transparent}
  .inv-explore th{font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3);font-weight:700}
  .inv-explore th.sortable{cursor:pointer;user-select:none}
  .inv-explore th.sortable:hover{color:var(--ink)}
  .inv-explore td.num,.inv-explore th.num{text-align:right;font-variant-numeric:tabular-nums}
  .inv-explore tbody tr{cursor:pointer}
  .inv-explore tbody tr:hover{background:var(--surface-2)}
  .inv-explore tbody tr:last-child td{border-bottom:0}
  .inv-explore .ordnum{font-weight:650;color:var(--accent-ink)}
  .inv-explore .muted{color:var(--ink-3)}
  .inv-explore .cellchip{display:inline-block;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:999px;line-height:1.6}
  .inv-explore .c-shop{background:var(--shop-wash);color:var(--shop)}
  .inv-explore .c-cash{border:1px solid var(--line-strong);color:var(--ink-2)}
  .inv-explore .c-online{background:var(--accent-wash);color:var(--accent-ink)}
  .inv-explore .c-cancel{background:var(--crit-wash);color:var(--crit)}
  .inv-explore .bdot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:3px}
  .inv-explore .sent{color:var(--good-ink);font-weight:700}
  .inv-explore .notsent{color:var(--ink-3)}
  .inv-explore .tfoot{display:flex;align-items:center;gap:14px;padding:11px 16px;font-size:12px;color:var(--ink-3);flex-wrap:wrap}
  .inv-explore .rowbtn{font:inherit;font-size:12px;font-weight:600;border:1px solid var(--line-strong);background:var(--surface);color:var(--ink-2);border-radius:7px;padding:4px 11px;cursor:pointer}
  .inv-explore .rowbtn:disabled{opacity:.4;cursor:default}
  .inv-explore .empty{padding:44px;text-align:center;color:var(--ink-3)}
  .inv-explore .loading{padding:44px;text-align:center;color:var(--ink-3)}

  .inv-ov{position:fixed;inset:0;background:rgba(16,24,40,.45);display:none;z-index:1200}
  .inv-dr{position:fixed;top:0;right:0;bottom:0;width:min(430px,100vw);background:#fff;border-left:1px solid var(--line);box-shadow:var(--shadow);z-index:1201;transform:translateX(102%);transition:transform .2s ease;overflow-y:auto;padding:20px 22px 30px;color:var(--ink)}
  .inv-dr.open{transform:none}
  .inv-dr .dhead{display:flex;align-items:flex-start;gap:10px}
  .inv-dr .dhead h3{margin:0;font-size:16px}
  .inv-dr .dclose{margin-left:auto;border:0;background:var(--surface-2);border-radius:8px;width:28px;height:28px;cursor:pointer;color:var(--ink-2);font-size:14px}
  .inv-dr .damount{font-size:26px;font-weight:750;letter-spacing:-.01em;margin:10px 0 2px}
  .inv-dr .dsec{margin-top:18px}
  .inv-dr .dsec .k{font-size:10.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-3);font-weight:700;margin-bottom:7px}
  .inv-dr .kv{display:flex;justify-content:space-between;gap:14px;font-size:12.5px;padding:3px 0}
  .inv-dr .kv span:first-child{color:var(--ink-3)}
  .inv-dr .kv span:last-child{font-variant-numeric:tabular-nums;text-align:right}
  .inv-dr .ditems{width:100%;font-size:12.5px;border-collapse:collapse}
  .inv-dr .ditems td{padding:5px 0;border-bottom:1px dashed var(--line);white-space:normal}
  .inv-dr .ditems td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .inv-dr .timeline{list-style:none;margin:0;padding:0;font-size:12.5px}
  .inv-dr .timeline li{display:flex;gap:10px;padding:4px 0;align-items:baseline}
  .inv-dr .timeline .tdot{width:8px;height:8px;border-radius:50%;background:var(--line-strong);flex:none}
  .inv-dr .timeline li.done .tdot{background:var(--good-ink)}
  .inv-dr .timeline li.bad .tdot{background:var(--crit)}
  .inv-dr .timeline .when{margin-left:auto;color:var(--ink-3);font-variant-numeric:tabular-nums;white-space:nowrap}
  .inv-dr .locknote{margin-top:22px;background:var(--surface-2);border:1px dashed var(--line-strong);border-radius:10px;padding:10px 13px;font-size:12px;color:var(--ink-2)}
</style>

<div class="inv-explore">
  <div class="head">
    <div>
      <h1>Invoices</h1>
      <div class="sub">Browse &amp; explore delivered invoices — all brands</div>
    </div>
    <span class="ro-chip">View only</span>
    <div class="spacer"></div>
  </div>

  <div class="filters">
    <div class="seg" id="segPeriod"></div>
    <div class="daterow">
      <input type="date" id="dFrom" aria-label="From date"><span>–</span><input type="date" id="dTo" aria-label="To date">
    </div>
    <input type="search" id="fSearch" placeholder="Search order # or customer…" autocomplete="off">
    <details class="more">
      <summary>More filters ▾</summary>
      <div class="morepanel">
        <label>Brand
          <select id="fBrand"><option value="all">All brands</option><option value="nf">NF</option><option value="kh">Khaas</option><option value="qb">Qurbani</option></select>
        </label>
        <label>Customer type
          <select id="fType"><option value="all">All</option><option value="regular">Regular</option><option value="shop">Shop</option></select>
        </label>
        <label>Payment
          <select id="fPay"><option value="all">Cash + Online</option><option value="cash">Cash</option><option value="online">Online</option></select>
        </label>
      </div>
    </details>
    <div class="spacer"></div>
    <a class="btn" id="btnCsv" href="#">⬇ Export</a>
  </div>

  <div class="summary" id="summary"><span class="m">Loading…</span></div>

  <div class="card">
    <div class="tablewrap"><div id="tableHost"><div class="loading">Loading invoices…</div></div></div>
    <div class="tfoot" id="tfoot"></div>
  </div>
</div>

<div class="inv-ov" id="invOverlay"></div>
<aside class="inv-dr" id="invDrawer" aria-label="Invoice detail"></aside>

<script>
(function(){
  'use strict';
  var state={preset:'month',from:'',to:'',brand:'all',type:'all',pay:'all',q:'',page:1,sort:'date',dir:'desc'};
  var BRANDS={nf:{label:'NF',color:'var(--nf)'},kh:{label:'Khaas',color:'var(--kh)'},qb:{label:'Qurbani',color:'var(--qb)'}};
  var MONTHS=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var searchTimer=null;

  function fmt(n){return 'Rs '+Math.round(n||0).toLocaleString('en-US');}
  function dshort(s){if(!s)return '';var p=String(s).slice(0,10).split('-');if(p.length<3)return s;return parseInt(p[2],10)+' '+MONTHS[parseInt(p[1],10)-1];}
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function qs(extra){
    var p={brand:state.brand,type:state.type,pay:state.pay,q:state.q,sort:state.sort,dir:state.dir,page:state.page};
    if(state.from&&state.to){p.from=state.from;p.to=state.to;}else{p.preset=state.preset;}
    if(extra)for(var k in extra)p[k]=extra[k];
    return Object.keys(p).filter(function(k){return p[k]!==''&&p[k]!=null;}).map(function(k){return k+'='+encodeURIComponent(p[k]);}).join('&');
  }

  var PRESETS=[['month','This month'],['lastmonth','Last month'],['30','Last 30 days'],['all','All time']];
  function segment(el,opts,cur,onpick){el.innerHTML='';opts.forEach(function(o){var b=document.createElement('button');b.type='button';b.textContent=o[1];if(o[0]===cur)b.className='on';b.onclick=function(){onpick(o[0]);};el.appendChild(b);});}

  function setPreset(p){state.preset=p;state.from='';state.to='';document.getElementById('dFrom').value='';document.getElementById('dTo').value='';state.page=1;segment(document.getElementById('segPeriod'),PRESETS,p,setPreset);load();}

  function sortArrow(k){return state.sort===k?(state.dir==='asc'?' ▲':' ▼'):'';}
  function brandChips(r){var out=[];['nf','kh','qb'].forEach(function(k){if(r[k]>0)out.push('<span title="'+BRANDS[k].label+': '+fmt(r[k])+'"><span class="bdot" style="background:'+BRANDS[k].color+'"></span>'+BRANDS[k].label+'</span>');});return out.join(' ');}

  function renderSummary(s){
    var brandTxt=state.brand!=='all'?' · <span class="m">'+BRANDS[state.brand].label+' share only</span>':'';
    document.getElementById('summary').innerHTML=
      '<span class="big">'+(s.count||0).toLocaleString()+' delivered invoices</span>'+
      '<span class="m"><b>'+fmt(s.revenue)+'</b> total</span>'+
      '<span class="m"><b>'+fmt(s.average)+'</b> average</span>'+brandTxt;
  }

  function renderRows(d){
    var host=document.getElementById('tableHost');
    if(!d.rows.length){host.innerHTML='<div class="empty">No invoices match these filters.</div>';document.getElementById('tfoot').innerHTML='';return;}
    var h='<table><thead><tr>'+
      '<th>Order #</th>'+
      '<th class="sortable" data-k="date">Date'+sortArrow('date')+'</th>'+
      '<th class="sortable" data-k="customer">Customer'+sortArrow('customer')+'</th>'+
      '<th>Brand</th>'+
      '<th class="num sortable" data-k="amount">Amount'+sortArrow('amount')+'</th>'+
      '<th>Payment</th><th>Invoice</th></tr></thead><tbody>';
    d.rows.forEach(function(r){
      var amt=state.brand==='all'?r.total_price:(r[state.brand]||0);
      h+='<tr data-id="'+r.id+'">'+
        '<td><span class="ordnum">'+esc(r.order_number)+'</span></td>'+
        '<td class="muted">'+dshort(r.delivery_date)+'</td>'+
        '<td>'+esc(r.customer_name)+(r.customer_type==='shop'?' <span class="cellchip c-shop">Shop</span>':'')+'</td>'+
        '<td>'+brandChips(r)+'</td>'+
        '<td class="num">'+fmt(amt)+'</td>'+
        '<td><span class="cellchip '+(r.channel==='online'?'c-online':'c-cash')+'">'+(r.channel==='online'?'Online':'Cash')+'</span></td>'+
        '<td>'+(r.invoice_sent?'<span class="sent">✓ sent</span>':'<span class="notsent">—</span>')+'</td>'+
      '</tr>';
    });
    h+='</tbody></table>';
    host.innerHTML=h;
    host.querySelectorAll('th.sortable').forEach(function(th){th.onclick=function(){var k=th.getAttribute('data-k');if(state.sort===k)state.dir=state.dir==='asc'?'desc':'asc';else{state.sort=k;state.dir=k==='customer'?'asc':'desc';}load();};});
    host.querySelectorAll('tbody tr').forEach(function(tr){tr.onclick=function(){openDrawer(tr.getAttribute('data-id'));};});
    var start=(d.page-1)*d.per_page;
    document.getElementById('tfoot').innerHTML=
      '<span>Showing '+(start+1)+'–'+Math.min(start+d.per_page,d.total)+' of '+d.total.toLocaleString()+' · click a row for detail</span>'+
      '<span class="spacer"></span>'+
      '<button class="rowbtn" id="pgPrev"'+(d.page<=1?' disabled':'')+'>‹ Prev</button>'+
      '<span>'+d.page+' / '+Math.max(1,d.pages)+'</span>'+
      '<button class="rowbtn" id="pgNext"'+(d.page>=d.pages?' disabled':'')+'>Next ›</button>';
    var pv=document.getElementById('pgPrev'),nx=document.getElementById('pgNext');
    if(pv)pv.onclick=function(){if(state.page>1){state.page--;load();}};
    if(nx)nx.onclick=function(){state.page++;load();};
  }

  function load(){
    fetch('/invoices/data?'+qs(),{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){return r.json();})
      .then(function(d){renderSummary(d.summary);renderRows(d);})
      .catch(function(){document.getElementById('tableHost').innerHTML='<div class="empty">Could not load invoices. Please refresh.</div>';});
  }

  function openDrawer(id){
    var dr=document.getElementById('invDrawer'),ov=document.getElementById('invOverlay');
    dr.innerHTML='<div class="loading">Loading…</div>';ov.style.display='block';dr.classList.add('open');
    fetch('/invoices/'+id,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();}).then(function(d){
      var o=d.order,c=d.customer;
      var src=o.external_source==='woocommerce'?'Website':(o.external_source==='shopify'?'Shopify':'Web app');
      var itemsHtml=(d.items||[]).map(function(it){
        return '<tr><td>'+esc(it.name)+'<div class="muted" style="font-size:11px">'+BRANDS[it.brand].label+'</div></td>'+
          '<td class="num muted">'+it.quantity+' × '+Math.round(it.unit_price).toLocaleString()+'</td>'+
          '<td class="num"><b>'+fmt(it.line_total)+'</b></td></tr>';
      }).join('');
      var statusMap={delivered:['done','Delivered'],out_for_delivery:['done','Out for delivery'],processing:['done','Processing'],new:['done','Order placed'],pending:['done','Pending'],cancelled:['bad','Cancelled'],completed:['done','Completed']};
      var tl=(d.history||[]).map(function(h){var m=statusMap[h.status]||['done',h.status];var t=h.changed_at?String(h.changed_at).slice(11,16):'';return '<li class="'+m[0]+'"><span class="tdot"></span>'+esc(m[1])+'<span class="when">'+dshort(h.changed_at)+(t?' '+t:'')+'</span></li>';}).join('');
      var cancel=o.order_status==='cancelled';
      dr.innerHTML=
        '<div class="dhead"><div><h3>'+esc(o.order_number)+'</h3>'+
          '<div class="muted" style="font-size:12px">'+src+' order</div></div>'+
          '<button class="dclose" aria-label="Close">✕</button></div>'+
        '<div class="damount">'+fmt(o.total_price)+'</div>'+
        '<div>'+(cancel?'<span class="cellchip c-cancel">Cancelled</span>':'')+'</div>'+
        '<div class="dsec"><div class="k">Customer</div>'+
          '<div class="kv"><span>Name</span><span>'+esc(c.name)+(c.type==='shop'?' <span class="cellchip c-shop">Shop</span>':'')+'</span></div>'+
          '<div class="kv"><span>Location</span><span>'+esc([c.city,c.province].filter(Boolean).join(', ')||'—')+'</span></div>'+
          (c.phone?'<div class="kv"><span>Phone</span><span>'+esc(c.phone)+'</span></div>':'')+
          (o.rider_name?'<div class="kv"><span>Rider</span><span>'+esc(o.rider_name)+'</span></div>':'')+
        '</div>'+
        '<div class="dsec"><div class="k">Line items</div><table class="ditems">'+(itemsHtml||'<tr><td class="muted">No line items</td></tr>')+'</table>'+
          '<div class="kv" style="margin-top:8px"><span>Subtotal</span><span>'+fmt(o.subtotal_price)+'</span></div>'+
          (o.discount_total>0?'<div class="kv"><span>Discount</span><span>−'+fmt(o.discount_total)+'</span></div>':'')+
          (o.shipping_total>0?'<div class="kv"><span>Delivery</span><span>'+fmt(o.shipping_total)+'</span></div>':'')+
          (o.tip_amount>0?'<div class="kv"><span>Tip</span><span>'+fmt(o.tip_amount)+'</span></div>':'')+
        '</div>'+
        '<div class="dsec"><div class="k">Payment</div>'+
          '<div class="kv"><span>Channel</span><span><span class="cellchip '+(o.channel==='online'?'c-online':'c-cash')+'">'+(o.channel==='online'?'Online':'Cash')+'</span></span></div>'+
          '<div class="kv"><span>Invoice sent</span><span>'+(d.invoice_sent?'<span class="sent">✓ WhatsApp · '+dshort(d.invoice_sent)+'</span>':'<span class="muted">not sent</span>')+'</span></div>'+
        '</div>'+
        (tl?'<div class="dsec"><div class="k">Timeline</div><ul class="timeline">'+tl+'</ul></div>':'')+
        '<div class="locknote">🔒 View only — this account can browse invoices but cannot edit, resend, or change status.</div>';
      dr.querySelector('.dclose').onclick=closeDrawer;
    }).catch(function(){dr.innerHTML='<div class="empty">Could not load this invoice.<br><button class="rowbtn" style="margin-top:12px" onclick="document.getElementById(\'invDrawer\').classList.remove(\'open\');document.getElementById(\'invOverlay\').style.display=\'none\'">Close</button></div>';});
  }
  function closeDrawer(){document.getElementById('invOverlay').style.display='none';document.getElementById('invDrawer').classList.remove('open');}

  // wire controls
  segment(document.getElementById('segPeriod'),PRESETS,state.preset,setPreset);
  function bindSelect(id,key){document.getElementById(id).onchange=function(e){state[key]=e.target.value;state.page=1;load();};}
  bindSelect('fBrand','brand');bindSelect('fType','type');bindSelect('fPay','pay');
  document.getElementById('fSearch').oninput=function(e){state.q=e.target.value;clearTimeout(searchTimer);searchTimer=setTimeout(function(){state.page=1;load();},300);};
  var df=document.getElementById('dFrom'),dt=document.getElementById('dTo');
  function customRange(){if(df.value&&dt.value){state.from=df.value;state.to=dt.value;state.preset='';segment(document.getElementById('segPeriod'),PRESETS,'',setPreset);state.page=1;load();}}
  df.onchange=customRange;dt.onchange=customRange;
  document.getElementById('btnCsv').onclick=function(e){e.preventDefault();window.location='/invoices/export?'+qs();};
  document.getElementById('invOverlay').onclick=closeDrawer;
  document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDrawer();});

  load();
})();
</script>
@endsection
