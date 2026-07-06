@extends('layouts.app')

@section('title', 'HQ — Executive')

@section('content')
{{--
  HQ EXECUTIVE DASHBOARD — Phase 1 (Monthly Closing)
  Read-only. All numbers come from /hq/* JSON endpoints (ExecutiveClosingService).
  Every style is scoped under #hq-root so nothing collides with Metronic/Tailwind.
--}}
<div class="kt-container-fixed">
<div id="hq-root">
@verbatim
<style>
  #hq-root{
    --bg:#f6f6f4; --card:#ffffff; --line:#e7e5e4; --line2:#d6d3d1;
    --ink:#1c1917; --mut:#79716b; --faint:#a8a29e;
    --blue:#2563eb; --good:#059669; --bad:#dc2626;
    --u-all:#334155; --u-nf:#059669; --u-kh:#d97706; --u-qb:#9f1239;
    --accent:var(--u-all);
    color:var(--ink);
    font-family:Inter,'Segoe UI',system-ui,sans-serif;font-size:13.5px;line-height:1.45;
  }
  #hq-root *{box-sizing:border-box}
  #hq-root .hq-head{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding-bottom:6px}
  #hq-root .hq-title{font-size:19px;font-weight:700;letter-spacing:-.01em;display:flex;align-items:center;gap:9px}
  #hq-root .hq-title .dot{width:9px;height:9px;border-radius:50%;background:var(--u-nf)}
  #hq-root .modtabs{display:flex;gap:2px;margin-left:4px}
  #hq-root .modtab{border:0;background:none;font:inherit;font-weight:600;color:var(--mut);padding:6px 13px;
    border-bottom:2px solid transparent;cursor:pointer}
  #hq-root .modtab:hover{color:var(--ink)}
  #hq-root .modtab.on{color:var(--ink);border-bottom-color:var(--ink)}
  #hq-root .modtab.soon{color:var(--faint);cursor:default}
  #hq-root .modtab.soon small{font-size:9px;vertical-align:super;letter-spacing:.04em}
  #hq-root .refresh{margin-left:auto;border:1px solid var(--line);background:#fff;border-radius:8px;
    font:inherit;font-size:12px;font-weight:600;color:var(--mut);padding:6px 12px;cursor:pointer}
  #hq-root .refresh:hover{color:var(--ink);border-color:var(--line2)}

  #hq-root .controls{display:flex;flex-wrap:wrap;align-items:center;gap:14px;padding:14px 0 4px}
  #hq-root .seg{display:flex;background:#fff;border:1px solid var(--line);border-radius:10px;padding:3px;gap:2px}
  #hq-root .seg button{border:0;background:none;font:inherit;font-size:12.5px;font-weight:600;color:var(--mut);
    padding:6px 12px;border-radius:7px;cursor:pointer;display:flex;align-items:center;gap:7px;white-space:nowrap}
  #hq-root .seg button .udot{width:8px;height:8px;border-radius:50%;background:var(--faint);flex:0 0 auto}
  #hq-root .seg button[data-u="all"] .udot{background:var(--u-all)}
  #hq-root .seg button[data-u="nf"] .udot{background:var(--u-nf)}
  #hq-root .seg button[data-u="kh"] .udot{background:var(--u-kh)}
  #hq-root .seg button[data-u="qb"] .udot{background:var(--u-qb)}
  #hq-root .seg button.on{background:var(--ink);color:#fff}
  #hq-root .seg button.on .udot{outline:2px solid rgba(255,255,255,.45)}
  #hq-root .months{display:flex;gap:6px;overflow-x:auto;padding:2px;scrollbar-width:none}
  #hq-root .months::-webkit-scrollbar{display:none}
  #hq-root .mpill{border:1px solid var(--line);background:#fff;font:inherit;font-size:12.5px;font-weight:600;
    color:var(--mut);padding:6px 13px;border-radius:999px;cursor:pointer;white-space:nowrap}
  #hq-root .mpill.on{background:var(--accent);border-color:var(--accent);color:#fff}
  #hq-root .mstate{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;border-radius:999px;
    padding:4px 11px;white-space:nowrap}
  #hq-root .mstate.closed{color:#065f46;background:#d1fae5}
  #hq-root .mstate.open{color:#9a3412;background:#ffedd5}
  #hq-root .datewin{font-size:11.5px;color:var(--mut);width:100%;padding:2px}
  #hq-root .datewin code{background:#f1f0ef;border-radius:4px;padding:1px 5px;font-size:10.5px}

  #hq-root h2.sec{font-size:11px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--mut);
    margin:24px 0 10px;display:flex;align-items:center;gap:10px}
  #hq-root h2.sec .rule{flex:1;height:1px;background:var(--line)}
  #hq-root h2.sec .hint{font-weight:500;letter-spacing:0;text-transform:none;color:var(--faint);font-size:11.5px}

  #hq-root .grid{display:grid;gap:12px}
  #hq-root .kpis{grid-template-columns:repeat(6,1fr)}
  #hq-root .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;min-width:0}
  #hq-root .card.click{cursor:pointer;transition:border-color .12s, box-shadow .12s}
  #hq-root .card.click:hover{border-color:var(--line2);box-shadow:0 2px 10px rgba(28,25,23,.06)}
  #hq-root .card.click:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
  #hq-root .k-label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--mut);
    display:flex;align-items:center;gap:6px}
  #hq-root .k-label .drill{margin-left:auto;color:var(--faint);font-size:12px;font-weight:400}
  #hq-root .k-val{font-size:22px;font-weight:700;letter-spacing:-.02em;margin-top:7px;
    font-variant-numeric:tabular-nums;white-space:nowrap}
  #hq-root .k-val small{font-size:13px;font-weight:600;color:var(--mut)}
  #hq-root .k-sub{margin-top:5px;font-size:11.5px;color:var(--mut);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  #hq-root .delta{font-size:11px;font-weight:700;border-radius:6px;padding:1px 6px;font-variant-numeric:tabular-nums}
  #hq-root .delta.up{color:#065f46;background:#d1fae5}
  #hq-root .delta.dn{color:#991b1b;background:#fee2e2}
  #hq-root .delta.flat{color:var(--mut);background:#f1f0ef}
  #hq-root .i-btn{width:16px;height:16px;border-radius:50%;border:1px solid var(--line2);background:#fff;color:var(--mut);
    font-size:9.5px;font-weight:700;line-height:1;cursor:pointer;flex:0 0 auto;padding:0}
  #hq-root .i-btn:hover{color:var(--blue);border-color:var(--blue)}

  #hq-root .pl-flow{display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto 1fr auto 1fr;align-items:stretch}
  #hq-root .pl-cell{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:13px 15px;min-width:0}
  #hq-root .pl-op{display:flex;align-items:center;justify-content:center;color:var(--faint);font-size:16px;font-weight:600;padding:0 7px}
  #hq-root .pl-cell .k-val{font-size:18px}
  #hq-root .pl-cell.result{border-color:var(--line2);background:#fbfaf9}
  #hq-root .pl-cell .ratio{font-size:11px;font-weight:700;color:var(--mut);margin-top:4px;font-variant-numeric:tabular-nums}
  #hq-root .pl-bar{display:flex;height:14px;border-radius:7px;overflow:hidden;margin-top:14px;border:1px solid var(--line)}
  #hq-root .pl-bar .seg-v{height:100%}
  #hq-root .pl-legend{display:flex;gap:18px;flex-wrap:wrap;margin-top:8px;font-size:11.5px;color:var(--mut)}
  #hq-root .pl-legend .sw{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;vertical-align:-.5px}

  #hq-root .wc{grid-template-columns:repeat(3,1fr)}
  #hq-root .wc .k-val{font-size:19px}
  #hq-root .banklist{margin-top:8px;display:flex;flex-direction:column;gap:3px;font-size:11.5px;color:var(--mut)}
  #hq-root .banklist .row{display:flex;justify-content:space-between;gap:10px;font-variant-numeric:tabular-nums}
  #hq-root .netpos{background:#1c1917;color:#fff;border:1px solid #1c1917}
  #hq-root .netpos .k-label{color:#d6d3d1}
  #hq-root .health{display:flex;flex-direction:column;gap:7px;margin-top:8px}
  #hq-root .health .h-row{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--mut)}
  #hq-root .h-dot{width:8px;height:8px;border-radius:50%;margin-top:4px;flex:0 0 auto}
  #hq-root .h-ok{background:var(--good)} #hq-root .h-warn{background:#f59e0b}
  #hq-root .wc-note{font-size:11px;color:var(--faint);margin-top:6px}

  #hq-root .trend-card svg{display:block;width:100%;height:150px}
  #hq-root .t-legend{display:flex;gap:18px;font-size:11.5px;color:var(--mut);margin-top:6px}

  #hq-root .placeholder{border:1px dashed var(--line2);border-radius:12px;padding:40px 24px;text-align:center;
    color:var(--mut);background:#fff}
  #hq-root .placeholder h3{font-size:15px;font-weight:700;color:var(--ink);margin-bottom:6px}

  #hq-root .loading{opacity:.45;pointer-events:none}
  #hq-root .errbox{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:12px 14px;
    font-size:12.5px;margin:10px 0}

  /* drill slide-over — appended to body, so styled globally but namespaced */
  .hq-scrim{position:fixed;inset:0;background:rgba(28,25,23,.38);opacity:0;pointer-events:none;transition:opacity .18s;z-index:1100}
  .hq-scrim.on{opacity:1;pointer-events:auto}
  .hq-panel{position:fixed;top:0;right:0;bottom:0;width:min(560px,94vw);background:#fff;z-index:1101;
    box-shadow:-12px 0 40px rgba(28,25,23,.18);transform:translateX(103%);transition:transform .22s ease;
    display:flex;flex-direction:column;font-family:Inter,'Segoe UI',system-ui,sans-serif}
  .hq-panel.on{transform:none}
  @media (prefers-reduced-motion:reduce){.hq-panel,.hq-scrim{transition:none}}
  .hq-panel .p-head{padding:16px 20px 12px;border-bottom:1px solid #e7e5e4;position:relative}
  .hq-panel .p-crumb{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#79716b;
    display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .hq-panel .p-crumb .sep{color:#a8a29e}
  .hq-panel .p-title{font-size:16px;font-weight:700;margin-top:5px;letter-spacing:-.01em;color:#1c1917}
  .hq-panel .p-close{position:absolute;top:12px;right:14px;border:1px solid #e7e5e4;background:#fff;border-radius:8px;
    width:30px;height:30px;font-size:15px;color:#79716b;cursor:pointer}
  .hq-panel .p-back{border:0;background:none;font:inherit;font-size:12px;font-weight:700;color:#2563eb;cursor:pointer;
    padding:0;margin-top:6px;display:none}
  .hq-panel .p-back.show{display:inline-block}
  .hq-panel .p-body{overflow-y:auto;flex:1;padding:6px 20px 26px}
  .hq-panel .p-note{font-size:11.5px;color:#a8a29e;padding:10px 20px;border-top:1px solid #e7e5e4}
  .hq-panel table.dt{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
  .hq-panel .dt th{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#79716b;
    text-align:right;padding:9px 8px;border-bottom:1px solid #e7e5e4;position:sticky;top:0;background:#fff}
  .hq-panel .dt th:first-child,.hq-panel .dt td:first-child{text-align:left}
  .hq-panel .dt td{font-size:12.5px;padding:8px;border-bottom:1px solid #f1f0ef;text-align:right;white-space:nowrap}
  .hq-panel .dt td:first-child{font-weight:600}
  .hq-panel .dt tr.rowclick{cursor:pointer}
  .hq-panel .dt tr.rowclick:hover td{background:#fafaf9}
  .hq-panel .dt tr.total td{font-weight:700;border-top:2px solid #e7e5e4;border-bottom:0}
  .hq-panel .pillm{font-size:10.5px;font-weight:700;border-radius:6px;padding:2px 7px}
  .hq-panel .pillm.cash{color:#3730a3;background:#e0e7ff}
  .hq-panel .pillm.online{color:#065f46;background:#d1fae5}
  .hq-panel .pillm.late{color:#991b1b;background:#fee2e2}
  .hq-panel .pillm.new{color:#065f46;background:#d1fae5}
  .hq-panel .p-empty{color:#a8a29e;font-size:12.5px;padding:30px 4px;text-align:center}

  /* formula dialog */
  .hq-defscrim{position:fixed;inset:0;background:rgba(28,25,23,.38);z-index:1200;display:none}
  .hq-defscrim.on{display:block}
  .hq-defbox{position:fixed;z-index:1201;top:50%;left:50%;transform:translate(-50%,-50%);width:min(500px,92vw);
    background:#fff;border-radius:14px;box-shadow:0 24px 70px rgba(28,25,23,.3);display:none;max-height:84vh;overflow:auto;
    font-family:Inter,'Segoe UI',system-ui,sans-serif}
  .hq-defbox.on{display:block}
  .hq-defbox .db-head{padding:16px 20px 12px;border-bottom:1px solid #e7e5e4;position:relative}
  .hq-defbox .db-kicker{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#79716b}
  .hq-defbox .db-title{font-size:16px;font-weight:700;margin-top:3px;color:#1c1917}
  .hq-defbox .db-val{font-size:22px;font-weight:700;margin-top:6px;font-variant-numeric:tabular-nums;color:#1c1917}
  .hq-defbox .p-close{position:absolute;top:12px;right:12px;border:1px solid #e7e5e4;background:#fff;border-radius:8px;
    width:30px;height:30px;font-size:15px;color:#79716b;cursor:pointer}
  .hq-defbox .db-body{padding:14px 20px 20px;display:flex;flex-direction:column;gap:11px}
  .hq-defbox .defrow{display:grid;grid-template-columns:120px 1fr;gap:10px;font-size:12.5px}
  .hq-defbox .defrow .dl{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#a8a29e;padding-top:2px}
  .hq-defbox .defrow .dv{color:#1c1917;line-height:1.55;font-variant-numeric:tabular-nums}
  .hq-defbox .defrow .dv code{background:#f1f0ef;border-radius:4px;padding:1px 5px;font-size:11px}
  .hq-defbox .defrow .dv .var{font-weight:700}

  @media (max-width:1100px){
    #hq-root .kpis{grid-template-columns:repeat(3,1fr)}
    #hq-root .wc{grid-template-columns:repeat(2,1fr)}
    #hq-root .wc .netpos{grid-column:span 2}
    #hq-root .pl-flow{grid-template-columns:1fr auto 1fr;row-gap:8px}
    #hq-root .pl-op.brk{display:none}
  }
  @media (max-width:600px){
    #hq-root .kpis{grid-template-columns:repeat(2,1fr)}
  }
</style>
@endverbatim

<div class="hq-head">
  <div class="hq-title"><span class="dot"></span>HQ · Executive</div>
  <nav class="modtabs" aria-label="Modules">
    <button class="modtab on" id="tabClosing" type="button" onclick="hqSetTab('closing')">Monthly Closing</button>
    <button class="modtab" id="tabGrowth" type="button" onclick="hqSetTab('growth')">Growth</button>
    <button class="modtab soon" type="button" title="Planned">Fulfilment <small>SOON</small></button>
  </nav>
  <button class="refresh" type="button" onclick="hqReload(true)">↻ Refresh</button>
</div>

<div class="controls">
  <div class="seg" role="tablist" aria-label="Business unit">
    <button data-u="nf" class="on" type="button" onclick="hqSetUnit('nf')"><span class="udot"></span>Nizami Farms</button>
    <button data-u="kh" type="button" onclick="hqSetUnit('kh')"><span class="udot"></span>Khaas · Frozen</button>
    <button data-u="all" type="button" onclick="hqSetUnit('all')"><span class="udot"></span>NF + Frozen</button>
    <button data-u="qb" type="button" onclick="hqSetUnit('qb')"><span class="udot"></span>Qurbani</button>
  </div>
  <div class="months" id="hqMonthRail"></div>
  <span class="mstate closed" id="hqMonthState">Closed</span>
  <div class="datewin" id="hqDateWin"></div>
</div>

<div id="hqErr"></div>

{{-- ===================== CLOSING ===================== --}}
<div id="hqClosing">
  <h2 class="sec">The month at a glance <span class="rule"></span><span class="hint">click any card to drill down</span></h2>
  <div class="grid kpis" id="hqKpis"></div>

  <h2 class="sec" id="hqPlHead">Profit &amp; loss flow <span class="rule"></span><span class="hint"></span></h2>
  <div class="pl-flow" id="hqPlFlow"></div>
  <div class="pl-bar" id="hqPlBar"></div>
  <div class="pl-legend" id="hqPlLegend"></div>

  <h2 class="sec">Working capital — business health today <span class="rule"></span><span class="hint" id="hqWcHint">live</span></h2>
  <div class="grid wc" id="hqWc"></div>
  <div class="wc-note" id="hqWcNote"></div>

  <h2 class="sec">Six-month trend <span class="rule"></span></h2>
  <div class="card trend-card">
    <svg id="hqTrend" viewBox="0 0 720 150" preserveAspectRatio="none" aria-label="Revenue and net profit trend"></svg>
    <div class="t-legend">
      <span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;background:var(--accent)"></span>Delivered revenue</span>
      <span><span style="display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:5px;background:#a8a29e"></span>Net profit</span>
    </div>
  </div>
</div>

{{-- ===================== GROWTH (Phase 2 placeholder) ===================== --}}
<div id="hqGrowth" style="display:none">
  <div class="placeholder" style="margin-top:20px">
    <h3>Growth view — arriving in Phase 2</h3>
    <div>Average order value, active customers (30 / 60 / 90 days), the recency win-back ladder, and cohort
      retention — each with the same click-to-drill and formula popovers as the Closing view.</div>
  </div>
</div>

</div>{{-- /#hq-root --}}
</div>{{-- /.kt-container-fixed --}}

{{-- drill slide-over --}}
<div class="hq-scrim" id="hqDrillScrim" onclick="hqCloseDrill()"></div>
<aside class="hq-panel" id="hqPanel" role="dialog" aria-modal="true" aria-labelledby="hqPTitle">
  <div class="p-head">
    <div class="p-crumb" id="hqPCrumb"></div>
    <div class="p-title" id="hqPTitle"></div>
    <button class="p-back" id="hqPBack" type="button" onclick="hqDrillBack()">‹ Back to summary</button>
    <button class="p-close" type="button" onclick="hqCloseDrill()" aria-label="Close">✕</button>
  </div>
  <div class="p-body" id="hqPBody"></div>
  <div class="p-note">Level 1 = the summary you scan · Level 2 = the underlying entries.</div>
</aside>

{{-- formula dialog --}}
<div class="hq-defscrim" id="hqDefScrim" onclick="hqCloseDef()"></div>
<div class="hq-defbox" id="hqDefBox" role="dialog" aria-modal="true" aria-labelledby="hqDbTitle">
  <div class="db-head">
    <div class="db-kicker" id="hqDbKicker">How this is calculated</div>
    <div class="db-title" id="hqDbTitle"></div>
    <div class="db-val" id="hqDbVal"></div>
    <button class="p-close" type="button" onclick="hqCloseDef()" aria-label="Close">✕</button>
  </div>
  <div class="db-body" id="hqDbBody"></div>
</div>

<script>
(function(){
  "use strict";
  var UNITS={all:{name:'NF + Frozen',hex:'#334155'},nf:{name:'Nizami Farms',hex:'#059669'},
             kh:{name:'Khaas · Frozen',hex:'#d97706'},qb:{name:'Qurbani',hex:'#9f1239'}};
  var CUR_YEAR={{ $currentYear }}, CUR_MONTH={{ $currentMonth }};
  var state={unit:'nf',year:CUR_YEAR,month:CUR_MONTH,tab:'closing'}; // default = Nizami Farms (owner rule)
  var data={closing:null,trend:null,wc:null};
  var MONTHS=[];

  var $=function(id){return document.getElementById(id);};
  function rs(n){n=Math.round(n||0);return 'Rs '+n.toLocaleString('en-PK');}
  function rsS(n){return Math.round(n||0).toLocaleString('en-PK');}
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function monName(y,m){return new Date(y,m-1,1).toLocaleDateString('en-US',{month:'short',year:'2-digit'});}
  function fmtWin(w){ if(!w)return ''; function d(s){var t=new Date(s+'T00:00:00');return t.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});}
    return d(w.start)+' – '+d(w.end); }

  // build last 6 months (rail)
  (function(){ var c=new Date(CUR_YEAR,CUR_MONTH-1,1);
    for(var i=5;i>=0;i--){var d=new Date(c.getFullYear(),c.getMonth()-i,1);
      MONTHS.push({y:d.getFullYear(),m:d.getMonth()+1,label:monName(d.getFullYear(),d.getMonth()+1)});}})();

  function fetchJSON(url){
    return fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'})
      .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
      .then(function(j){if(!j||!j.success)throw new Error('bad payload');return j.data;});
  }
  function showErr(msg){ $('hqErr').innerHTML='<div class="errbox">Could not load data: '+esc(msg)+
    '. The page is read-only — try Refresh, or check that you are logged in.</div>'; }
  function clearErr(){ $('hqErr').innerHTML=''; }

  // ---------- controls ----------
  function renderControls(){
    var isQb=state.unit==='qb';
    var rail=$('hqMonthRail');
    if(isQb){
      rail.innerHTML='<span class="mpill on" style="cursor:default">Qurbani '+state.year+' season</span>';
    }else{
      rail.innerHTML=MONTHS.map(function(mo){var on=(mo.y===state.year&&mo.m===state.month);
        return '<button class="mpill'+(on?' on':'')+'" type="button" onclick="hqPick('+mo.y+','+mo.m+')">'+mo.label+'</button>';}).join('');
    }
    document.querySelectorAll('#hq-root .seg button').forEach(function(b){b.classList.toggle('on',b.getAttribute('data-u')===state.unit);});
    document.documentElement.style.setProperty('--accent',UNITS[state.unit].hex);
    $('hq-root')&&($('hq-root').style.setProperty('--accent',UNITS[state.unit].hex));
  }
  function renderState(){
    var c=data.closing; var st=$('hqMonthState');
    if(!c){st.textContent='';return;}
    st.className='mstate '+(c.is_open?'open':'closed');
    st.textContent=c.is_open?'Open · live':'Closed';
    var win=fmtWin(c.window);
    $('hqDateWin').innerHTML=(state.unit==='qb'
      ? 'Qurbani is pre-booked &amp; seasonal — showing the <strong>booked season</strong> (all non-cancelled orders); “delivered so far” is shown as context'
      : 'Counting orders <strong>delivered '+esc(win)+'</strong> · date used: <code>when status became “delivered”</code>')
      +' · tap <strong>ⓘ</strong> on any card for its exact formula';
  }

  // ---------- closing ----------
  function deltaChip(cur,prev,goodUp){
    goodUp=(goodUp!==false);
    if(prev==null||prev===0)return '<span class="delta flat">new</span>';
    var d=(cur-prev)/prev;
    if(Math.abs(d)<0.001)return '<span class="delta flat">±0%</span>';
    var up=d>0, good=goodUp?up:!up;
    return '<span class="delta '+(good?'up':'dn')+'">'+(up?'▲':'▼')+' '+Math.abs(d*100).toFixed(1)+'%</span>';
  }
  function renderClosing(){
    var c=data.closing; if(!c)return;
    var cur=c.current, prev=c.previous, dv=c.derived, isQb=(c.unit==='qb');
    function card(def,label,val,sub,drill){
      var iBtn='<button class="i-btn" type="button" onclick="event.stopPropagation();hqDef(\''+def+'\')" aria-label="How is '+esc(label)+' calculated?">i</button>';
      var open=drill?(' onclick="hqDrill(\''+drill+'\')" onkeydown="if(event.key===\'Enter\')hqDrill(\''+drill+'\')" tabindex="0" role="button"'):'';
      return '<div class="card'+(drill?' click':'')+'"'+open+'>'+
        '<div class="k-label">'+esc(label)+iBtn+(drill?'<span class="drill">›</span>':'')+'</div>'+
        '<div class="k-val">'+val+'</div><div class="k-sub">'+sub+'</div></div>';
    }
    var qtyVal = isQb
      ? rsS(cur.pieces)+' <small>items</small>'
      : rsS(cur.kg)+' <small>kg</small>';
    var qtySub = isQb
      ? '<strong>booked</strong> · '+rsS(cur.delivered_pieces)+' delivered so far'
      : deltaChip(cur.kg,prev.kg)+' · + '+rsS(cur.pieces)+' <strong>pc items</strong>';
    var revLabel = isQb ? 'Booked revenue' : 'Delivered revenue';
    var revSub   = isQb
      ? deltaChip(cur.revenue,prev.revenue)+' vs last season · '+rs(cur.delivered_revenue)+' delivered'
      : deltaChip(cur.revenue,prev.revenue)+' vs last month';
    var ordLabel = isQb ? 'Orders booked' : 'Orders delivered';
    var ordSub   = isQb
      ? deltaChip(cur.orders,prev.orders)+' · '+rsS(cur.delivered_orders)+' delivered · AOV '+rs(dv.aov)
      : deltaChip(cur.orders,prev.orders)+' · ≈'+dv.orders_per_work_day+' / day · AOV '+rs(dv.aov);
    var cards=[
      card('rev',revLabel,rs(cur.revenue),revSub,'revenue'),
      card('ord',ordLabel,rsS(cur.orders),ordSub,'revenue'),
      card('kg',isQb?'Quantity booked':'Quantity sold',qtyVal,qtySub,'revenue'),
      card('cus','Customers ordered',rsS(cur.customers),'<span class="delta up">+'+rsS(cur.new_customers)+' new</span> first-time','customers'),
      isQb
        ? card('qbcost','Qurbani costs',rs(cur.expenses),'animal + ops costs — booked as <strong>expenses</strong>','expense')
        : card('gp','Gross profit',rs(cur.gross_profit),'<strong>'+dv.gp_margin+'%</strong> of revenue','vendor'),
      card('np','Net profit',rs(cur.net_profit),'<strong>'+dv.np_margin+'%</strong> · '+rs(dv.net_per_work_day)+' / work day ('+c.working_days+')','expense')
    ];
    $('hqKpis').innerHTML=cards.join('');

    // P&L flow
    var flow, plHint;
    if(isQb){
      plHint='Booked revenue − Qurbani expenses = Season net profit (no vendor step — Qurbani has no vendors)';
      flow=[{l:'Booked revenue',v:cur.revenue,d:'revenue'},{op:'−'},
            {l:'Qurbani expenses (incl. animals)',v:cur.expenses,d:'expense'},{op:'='},
            {l:'Season net profit',v:cur.net_profit,r:dv.np_margin+'% NP margin',res:1,d:'expense'}];
    }else{
      plHint='Revenue − Vendor purchases = Gross profit · − Expenses = Net profit';
      flow=[{l:'Delivered revenue',v:cur.revenue,d:'revenue'},{op:'−'},
            {l:'Vendor purchases',v:cur.vendor_purchases,d:'vendor'},{op:'='},
            {l:'Gross profit',v:cur.gross_profit,r:dv.gp_margin+'% GP margin',res:1,d:'vendor'},{op:'−'},
            {l:'Expenses',v:cur.expenses,d:'expense'},{op:'='},
            {l:'Net profit',v:cur.net_profit,r:dv.np_margin+'% NP margin',res:1,d:'expense'}];
    }
    $('hqPlHead').querySelector('.hint').textContent=plHint;
    $('hqPlFlow').style.gridTemplateColumns=isQb?'1fr auto 1fr auto 1fr':'';
    $('hqPlFlow').innerHTML=flow.map(function(f,i){
      if(f.op)return '<div class="pl-op'+((i===3||i===7)?' brk':'')+'">'+f.op+'</div>';
      return '<div class="pl-cell'+(f.res?' result':'')+' card click" tabindex="0" role="button" onclick="hqDrill(\''+f.d+'\')">'+
        '<div class="k-label">'+esc(f.l)+'</div><div class="k-val">'+rs(f.v)+'</div>'+
        (f.r?'<div class="ratio">'+f.r+'</div>':'')+'</div>';
    }).join('');

    var segs=[['Vendor purchases',cur.vendor_purchases,'#d6d3d1'],['Expenses',cur.expenses,'#a8a29e'],
              ['Net profit',Math.max(cur.net_profit,0),UNITS[c.unit].hex]].filter(function(s){return s[1]>0;});
    var tot=segs.reduce(function(a,s){return a+s[1];},0)||1;
    $('hqPlBar').innerHTML=segs.map(function(s){return '<div class="seg-v" style="width:'+(s[1]/tot*100)+'%;background:'+s[2]+'" title="'+s[0]+' '+rs(s[1])+'"></div>';}).join('');
    $('hqPlLegend').innerHTML=segs.map(function(s){return '<span><span class="sw" style="background:'+s[2]+'"></span>'+s[0]+' · '+rs(s[1])+' ('+(s[1]/tot*100).toFixed(1)+'%)</span>';}).join('');
  }

  function renderWc(){
    var w=data.wc; if(!w)return;
    var isQb = w.scope==='qurbani';
    function iBtn(k){return '<button class="i-btn" type="button" onclick="event.stopPropagation();hqDef(\''+k+'\')" aria-label="How is this calculated?">i</button>';}

    $('hqWcHint').textContent = isQb ? 'live · Qurbani accounts only' : 'live · NF + Frozen (Qurbani excluded)';
    $('hqWcNote').textContent = isQb
      ? 'Qurbani-only position: its own cash and online accounts plus this season’s customer balances. Qurbani has no vendor payables; fixed assets are tracked under NF / Frozen.'
      : 'Shared across Nizami Farms + Frozen (cash, banks, riders and receivables are one pool for the regular business). Qurbani money is fully separate — switch to the Qurbani unit to see it.';

    var cashRows=(w.cash_accounts||[]).map(function(a){return '<div class="row"><span>'+esc(a.name)+'</span><span>'+rsS(a.balance)+'</span></div>';}).join('');
    // Online card shows the LEDGER accounts (the accurate number). The per-bank
    // segregation lives in the drill (regular view only) until reconciled.
    var onlRows=(w.online_accounts||[]).map(function(a){return '<div class="row"><span>'+esc(a.name)+'</span><span>'+rsS(a.balance)+'</span></div>';}).join('');
    var r=w.receivables;
    var recvRows = isQb
      ? '<div class="row"><span>Season balances · unpaid ('+rsS(r.season_count||0)+')</span><span>'+rsS(r.season||0)+'</span></div>'
      : '<div class="row"><span>Regular · pending approvals ('+rsS(r.regular_count)+')</span><span>'+rsS(r.regular)+'</span></div>'+
        '<div class="row"><span>Shops · outstanding ('+rsS(r.shop_count)+')</span><span>'+rsS(r.shop)+'</span></div>'+
        '<div class="row"><span>Riders · cash to settle</span><span>'+rsS(r.rider)+'</span></div>';
    var health=(w.health||[]).map(function(h){
      if(h.drill){
        return '<div class="h-row h-click" tabindex="0" role="button" onclick="hqDrill(\''+h.drill+'\')" onkeydown="if(event.key===\'Enter\')hqDrill(\''+h.drill+'\')" style="cursor:pointer"><span class="h-dot '+(h.ok?'h-ok':'h-warn')+'"></span><span style="text-decoration:underline dotted;text-underline-offset:2px">'+esc(h.label)+' ›</span></div>';
      }
      return '<div class="h-row"><span class="h-dot '+(h.ok?'h-ok':'h-warn')+'"></span>'+esc(h.label)+'</div>';
    }).join('');
    if(isQb) health='<div class="h-row"><span class="h-dot h-ok"></span>Season-to-date position · no vendor payables (Qurbani costs are expenses)</div>';

    var html=
      '<div class="card"><div class="k-label">'+(isQb?'Qurbani cash':'Cash in hand · NF till')+iBtn('cash')+'</div><div class="k-val">'+rs(w.cash)+'</div>'+
        (cashRows?'<div class="banklist">'+cashRows+'</div>':'<div class="k-sub">'+esc(w.cash_label||'')+'</div>')+'</div>'+
      (isQb
        ? '<div class="card"><div class="k-label">Qurbani online'+iBtn('online')+'</div><div class="k-val">'+rs(w.online)+'</div><div class="banklist">'+onlRows+'</div></div>'
        : '<div class="card click" tabindex="0" role="button" onclick="hqDrill(\'banks\')"><div class="k-label">Online · ledger'+iBtn('online')+'<span class="drill">›</span></div><div class="k-val">'+rs(w.online)+'</div><div class="banklist">'+onlRows+'</div>'+
          '<div class="k-sub" style="margin-top:7px">per-bank split in drill (not yet reconciled)</div></div>')+
      '<div class="card click" tabindex="0" role="button" onclick="hqDrill(\'receivables\')"><div class="k-label">Receivables'+iBtn('recv')+'<span class="drill">›</span></div><div class="k-val">'+rs(r.total)+'</div><div class="banklist">'+recvRows+'</div>'+
        (!isQb&&r.overdue>0?'<div class="k-sub" style="margin-top:7px"><span class="delta dn">'+rs(r.overdue)+' pending &gt; 30d</span></div>':'')+'</div>';

    if(isQb){
      html+='<div class="card"><div class="k-label">Payables'+iBtn('pay')+'</div><div class="k-val">'+rs(0)+'</div><div class="k-sub">Qurbani has no vendors — costs arrive as expenses</div></div>';
    }else{
      var a=w.assets||{count:0,value:0,units:[]};
      var assetRows=(a.units||[]).map(function(u){return '<div class="row"><span>'+esc(u.unit)+' ('+u.count+')</span><span>'+rsS(u.value)+'</span></div>';}).join('');
      html+=
        '<div class="card click" tabindex="0" role="button" onclick="hqDrill(\'payables\')"><div class="k-label">Payables'+iBtn('pay')+'<span class="drill">›</span></div><div class="k-val">'+rs(w.payables)+'</div><div class="k-sub">owed to vendors</div></div>'+
        '<div class="card click" tabindex="0" role="button" onclick="hqDrill(\'assets\')"><div class="k-label">Fixed assets'+iBtn('assets')+'<span class="drill">›</span></div><div class="k-val">'+rs(a.value)+'</div>'+
          (assetRows?'<div class="banklist">'+assetRows+'</div>':'<div class="k-sub">'+a.count+' active assets</div>')+'</div>';
    }
    html+='<div class="card netpos"><div class="k-label">Net working capital'+iBtn('net')+'</div><div class="k-val">'+rs(w.net)+'</div><div class="health">'+health+'</div></div>';
    $('hqWc').innerHTML=html;
  }

  function renderTrend(){
    var t=data.trend; var svg=$('hqTrend'); if(!t||!t.length){svg.innerHTML='';return;}
    var revs=t.map(function(x){return x.revenue;}), nps=t.map(function(x){return x.net_profit;});
    var max=Math.max.apply(null,revs.concat([1]));
    var n=t.length, hex=UNITS[state.unit].hex;
    var X=function(i){return 40+i*((720-70)/(n-1||1));}, Y=function(v){return 128-(Math.max(v,0)/max)*105;};
    var ptsR=revs.map(function(v,i){return X(i)+','+Y(v);}).join(' ');
    var ptsN=nps.map(function(v,i){return X(i)+','+Y(v);}).join(' ');
    var curIdx=-1; for(var i=0;i<t.length;i++){ if(t[i].label===monName(state.year,state.month)) curIdx=i; }
    svg.innerHTML=
      [0,.5,1].map(function(f){return '<line x1="40" x2="690" y1="'+Y(max*f)+'" y2="'+Y(max*f)+'" stroke="#eceae8" stroke-width="1"/>';}).join('')+
      '<polygon points="'+X(0)+',128 '+ptsR+' '+X(n-1)+',128" fill="'+hex+'" opacity="0.10"/>'+
      '<polyline points="'+ptsR+'" fill="none" stroke="'+hex+'" stroke-width="2.5" stroke-linejoin="round"/>'+
      '<polyline points="'+ptsN+'" fill="none" stroke="#a8a29e" stroke-width="2" stroke-linejoin="round"/>'+
      revs.map(function(v,i){return '<circle cx="'+X(i)+'" cy="'+Y(v)+'" r="'+(i===curIdx?5:3)+'" fill="'+(i===curIdx?hex:'#fff')+'" stroke="'+hex+'" stroke-width="2"/>';}).join('')+
      t.map(function(x,i){return '<text x="'+X(i)+'" y="146" font-size="10.5" fill="#a8a29e" text-anchor="middle" font-family="Inter,sans-serif">'+esc(x.label)+'</text>';}).join('');
  }

  // ---------- formula popovers ----------
  function defContent(key){
    var c=data.closing, w=data.wc; if(!c)return null;
    var cur=c.current, dv=c.derived, U=UNITS[c.unit].name, win=fmtWin(c.window), isQb=(c.unit==='qb');
    var unitRule = c.unit==='all'?'Nizami Farms + Khaas together (Qurbani is a separate view).'
      : c.unit==='nf'?'Only <span class="var">Nizami Farms</span> product lines. Delivery charges &amp; order discounts sit here (owner rule).'
      : c.unit==='kh'?'Only <span class="var">Khaas / frozen</span> product lines — a shared invoice is split line by line; delivery charges go to NF.'
      : 'Only <span class="var">Qurbani</span> orders (season-to-date), identified the same way as /qurbani/invoices.';
    var D={
      rev: isQb ? {t:'Booked revenue',v:rs(cur.revenue),r:[
        ['Formula','Sum of order totals of all <span class="var">non-cancelled</span> Qurbani orders this season (QUR-numbered)'],
        ['Why booked','Qurbani is pre-sold with costs bought upfront — the season’s business is what was <span class="var">booked</span>, matched against season costs'],
        ['Delivered so far','<span class="var">'+rs(cur.delivered_revenue)+'</span> of it delivered ('+rsS(cur.delivered_orders)+' orders); the rest is booked, awaiting the Eid days'],
        ['Excludes','Cancelled orders']]} : {t:'Delivered revenue',v:rs(cur.revenue),r:[
        ['Formula','Sum of invoice totals of orders <span class="var">delivered</span> in the window'],
        ['Window','Delivered <span class="var">'+win+'</span>'],
        ['Date used','<code>latest time status became “delivered”</code> (status history), not order date'],
        ['Unit rule',unitRule],
        ['Excludes','Cancelled orders · the Shopify approval queue']]},
      ord: isQb ? {t:'Orders booked',v:rsS(cur.orders)+' orders',r:[
        ['Formula','Count of <span class="var">non-cancelled</span> Qurbani orders this season'],
        ['Delivered so far','<span class="var">'+rsS(cur.delivered_orders)+'</span> delivered; the rest awaiting the Eid days'],
        ['AOV','booked revenue ÷ orders = '+rs(cur.revenue)+' ÷ '+rsS(cur.orders)+' = <span class="var">'+rs(dv.aov)+'</span>']]} : {t:'Orders delivered',v:rsS(cur.orders)+' orders',r:[
        ['Formula','Count of distinct orders delivered in the window'],
        ['Per day','orders ÷ working days = '+rsS(cur.orders)+' ÷ <span class="var">'+c.working_days+'</span> = <span class="var">'+dv.orders_per_work_day+' / day</span>'],
        ['AOV','revenue ÷ orders = '+rs(cur.revenue)+' ÷ '+rsS(cur.orders)+' = <span class="var">'+rs(dv.aov)+'</span>'],
        ['Unit rule',unitRule]]},
      kg:{t:(isQb?'Quantity booked':'Quantity sold'),v:(isQb?rsS(cur.pieces)+' items':rsS(cur.kg)+' kg + '+rsS(cur.pieces)+' pcs'),r:(isQb?[
        ['Formula','Sum of line-item quantities on all <span class="var">non-cancelled</span> Qurbani orders this season — <span class="var">animals / shares</span>, not weight'],
        ['Booked vs delivered','<span class="var">'+rsS(cur.pieces)+'</span> booked · <span class="var">'+rsS(cur.delivered_pieces)+'</span> delivered so far'],
        ['Excludes','Cancelled orders']]:[
        ['Kg','Quantities of <span class="var">weight-sold</span> lines only = <span class="var">'+rsS(cur.kg)+' kg</span>'],
        ['Pieces','Per-piece lines counted separately = <span class="var">'+rsS(cur.pieces)+' items</span> (frozen packs, paya, brain, “per piece” products)'],
        ['How split','A line counts as pieces when its product is a frozen pack or its name says per piece / pcs / dozen — otherwise quantity is kg. Name-based rule; a proper unit flag on products would make it exact'],
        ['Window','Delivered <span class="var">'+win+'</span>'],
        ['Unit rule',unitRule]])},
      cus:{t:'Customers ordered',v:rsS(cur.customers),r:[
        ['Formula','Distinct customers (by <code>phone</code>) with a delivery in the window'],
        ['New','<span class="var">'+rsS(cur.new_customers)+'</span> placed their <span class="var">first-ever order</span> in this window (by <code>first_order_date</code>)'],
        ['Unit rule',unitRule]]},
      gp:{t:'Gross profit',v:rs(cur.gross_profit),r:[
        ['Formula','Delivered revenue − Vendor purchases'],
        ['Values','<span class="var">'+rs(cur.revenue)+'</span> − <span class="var">'+rs(cur.vendor_purchases)+'</span> = <span class="var">'+rs(cur.gross_profit)+'</span> ('+dv.gp_margin+'%)'],
        ['Vendor purchases','Approved / L2 vendor bills dated in the month for '+U+' vendors'],
        ['Honest caveat','This is <span class="var">purchases-based</span> GP, not stock-matched cost — heavy month-end buying dips it, and it corrects next month.']]},
      qbcost:{t:'Qurbani costs',v:rs(cur.expenses),r:[
        ['Formula','Sum of approved <span class="var">Qurbani-category</span> expenses in the season'],
        ['Includes','Animal purchases (booked as expenses — Qurbani has no vendor ledger), slaughter, transport, ops'],
        ['Season','Expense date within <span class="var">'+win+'</span>']]},
      np:{t:'Net profit',v:rs(cur.net_profit),r:[
        ['Formula',isQb?'Revenue − Qurbani expenses':'Gross profit − Expenses'],
        ['Values',(isQb?'<span class="var">'+rs(cur.revenue)+'</span> − <span class="var">'+rs(cur.expenses)+'</span>'
                       :'<span class="var">'+rs(cur.gross_profit)+'</span> − <span class="var">'+rs(cur.expenses)+'</span>')+' = <span class="var">'+rs(cur.net_profit)+'</span> ('+dv.np_margin+'%)'],
        ['Per work day',rs(cur.net_profit)+' ÷ <span class="var">'+c.working_days+' working days</span> (calendar − holidays) = <span class="var">'+rs(dv.net_per_work_day)+'</span>']]}
    };
    if(w){
      var R=w.receivables;
      var wcQb = w.scope==='qurbani';
      if(wcQb){
        D.cash={t:'Qurbani cash',v:rs(w.cash),r:[
          ['Formula','Balance of the <span class="var">Qurbani cash</span> account(s) only — fully separate from NF / Frozen cash'],
          ['As of','<span class="var">now</span> — live, not season-bound']]};
        D.online={t:'Qurbani online',v:rs(w.online),r:[
          ['Formula','Balance of the <span class="var">Qurbani Online</span> ledger account only'],
          ['Note','No per-bank split here — bank tagging is shared with the regular business and not yet reconciled'],
          ['As of','<span class="var">now</span>']]};
        D.recv={t:'Qurbani receivables',v:rs(R.total),r:[
          ['Formula','This season’s delivered Qurbani orders with an unpaid balance (QUR-numbered, total − paid)'],
          ['Values','<span class="var">'+rs(R.season||0)+'</span> across <span class="var">'+rsS(R.season_count||0)+'</span> orders'],
          ['Older seasons','Treated as closed (owner rule)']]};
        D.pay={t:'Payables',v:rs(0),r:[
          ['Formula','Qurbani has <span class="var">no vendors</span> — animal purchases arrive as expenses, so there is no vendor payable']]};
        D.net={t:'Qurbani net position',v:rs(w.net),r:[
          ['Formula','Qurbani cash + Qurbani online + Season receivables'],
          ['Values','<span class="var">'+rs(w.cash_total)+'</span> + <span class="var">'+rs(w.online)+'</span> + <span class="var">'+rs(R.total)+'</span> = <span class="var">'+rs(w.net)+'</span>'],
          ['Meaning','The Qurbani season’s money, fully separated from the regular business']]};
      }else{
        D.cash={t:'Cash in hand',v:rs(w.cash),r:[
          ['Formula','Headline = the <span class="var">NF Cash (main till)</span> account only (owner rule)'],
          ['Other cash','The rows beneath (Khaas till, expense fund…) are the other regular cash accounts. All-cash total = <span class="var">'+rs(w.cash_total)+'</span> — Net Working Capital uses that total'],
          ['Qurbani','Qurbani cash is NOT here — switch to the Qurbani unit to see it (owner rule)'],
          ['As of','<span class="var">now</span> — live, not month-bound'],
          ['Note','Rider-held cash is NOT here — it sits in Receivables']]};
        D.online={t:'Online · ledger',v:rs(w.online),r:[
          ['Formula','Balance of the regular <span class="var">ONLINE ledger account</span> — the accurate number (owner rule). Qurbani Online is excluded and shown only in the Qurbani view'],
          ['Per-bank split','Lives in the drill-down (click ›). It is a tagging segregation, <span class="var">not yet reconciled</span> with the ledger — its total is <span class="var">'+rs(w.online_tracked+w.online_unassigned)+'</span>; the gap is flagged in the health tile'],
          ['As of','<span class="var">now</span>']]};
        D.recv={t:'Receivables',v:rs(R.total),r:[
          ['Formula','Regular pending approvals + Shop outstanding + Rider cash'],
          ['Values','Regular <span class="var">'+rs(R.regular)+'</span> ('+rsS(R.regular_count)+' pending invoices) + Shops <span class="var">'+rs(R.shop)+'</span> ('+rsS(R.shop_count)+') + Riders <span class="var">'+rs(R.rider)+'</span>'],
          ['Reconciles with','<span class="var">Online Approvals</span> — Regular = its “All Pending” total; Shops = its Shop tab. Legacy records are treated as closed (owner rule)'],
          ['Qurbani','NOT included — Qurbani has its own cash/online accounts and belongs to the Qurbani view (owner rule)'],
          ['Overdue','<span class="var">'+rs(R.overdue)+'</span> of the regular pending is older than 30 days'],
          ['Note','Cash orders are counted via rider balance, not here — no double count']]};
        D.pay={t:'Payables',v:rs(w.payables),r:[
          ['Formula','Sum of vendor account balances (their bills − our payments)'],
          ['As of','<span class="var">now</span>']]};
        D.assets={t:'Fixed assets',v:rs((w.assets||{}).value||0),r:[
          ['Formula','Book value of <span class="var">active</span> assets in the assets register ('+(((w.assets||{}).count)||0)+' assets), split by business unit. Click › for the asset list'],
          ['Not in NWC','Shown for visibility but <span class="var">excluded from Net Working Capital</span> — equipment is not cash you can spend'],
          ['As of','<span class="var">now</span>']]};
        D.net={t:'Net working capital',v:rs(w.net),r:[
          ['Formula','All regular cash + Online ledger + Receivables − Payables'],
          ['Values','<span class="var">'+rs(w.cash_total)+'</span> (regular cash) + <span class="var">'+rs(w.online)+'</span> + <span class="var">'+rs(R.total)+'</span> − <span class="var">'+rs(w.payables)+'</span> = <span class="var">'+rs(w.net)+'</span>'],
          ['Excludes','Fixed assets (not spendable cash) and ALL Qurbani money (own view)'],
          ['Meaning','What is left if everyone paid you and you paid every vendor today — for the regular business']]};
      }
    }
    return D[key];
  }
  window.hqDef=function(key){
    var d=defContent(key); if(!d)return;
    $('hqDbTitle').textContent=d.t; $('hqDbVal').textContent=d.v; $('hqDbVal').style.display=d.v?'':'none';
    $('hqDbKicker').textContent='How this is calculated · '+UNITS[state.unit].name+' · '+(data.closing?data.closing.period_label:'');
    $('hqDbBody').innerHTML=d.r.map(function(row){return '<div class="defrow"><div class="dl">'+esc(row[0])+'</div><div class="dv">'+row[1]+'</div></div>';}).join('');
    $('hqDefScrim').classList.add('on'); $('hqDefBox').classList.add('on');
  };
  window.hqCloseDef=function(){ $('hqDefScrim').classList.remove('on'); $('hqDefBox').classList.remove('on'); };

  // ---------- drill slide-over ----------
  var drill={key:null,level:1,l1:null,row:null};
  var DRILL={
    revenue:{crumb:['Delivered revenue'],url:function(){return '/hq/drill/revenue-daily'+qs();},
      cols:['Day','Orders','Kg','Pcs','Revenue'],map:function(r){return [fmtDay(r.day),rsS(r.orders),rsS(r.kg),rsS(r.pcs||0),rsS(r.revenue)];},
      raw:function(r){return r;},total:function(rows){var o=0,k=0,p=0,v=0;rows.forEach(function(r){o+=r.orders;k+=r.kg;p+=(r.pcs||0);v+=r.revenue;});return ['Total',rsS(o),rsS(k),rsS(p),rsS(v)];},
      l2:{crumb:function(r){return fmtDay(r.day);},url:function(r){return '/hq/drill/revenue-orders?unit='+state.unit+'&date='+r.day;},
        cols:['Order #','Customer','Qty','Amount','Paid'],map:function(r){return [esc(r.order_number),esc(r.customer),r.kg,rsS(r.amount),pill(r.paid)];}}},
    vendor:{crumb:['Gross profit','Vendor purchases'],url:function(){return '/hq/drill/vendors'+qs();},
      cols:['Vendor','Bills','Purchases','Balance'],map:function(r){return [esc(r.vendor),rsS(r.bills),rsS(r.purchases),rsS(r.balance)];},
      raw:function(r){return r;},total:function(rows){var b=0,p=0;rows.forEach(function(r){b+=r.bills;p+=r.purchases;});return ['Total',rsS(b),rsS(p),''];},
      l2:{crumb:function(r){return r.vendor;},url:function(r){return '/hq/drill/vendor?account_id='+r.account_id+'&year='+state.year+'&month='+state.month;},
        cols:['Date','Note','Amount'],map:function(r){return [esc(r.date),esc(r.note),rsS(r.amount)];}}},
    expense:{crumb:['Net profit','Expenses'],url:function(){return '/hq/drill/expenses'+qs();},
      cols:['Category','Entries','Amount','%'],map:function(r){return [esc(r.category),rsS(r.entries),rsS(r.amount),r.pct+'%'];},
      raw:function(r){return r;},total:function(rows){var e=0,a=0;rows.forEach(function(r){e+=r.entries;a+=r.amount;});return ['Total',rsS(e),rsS(a),'100%'];},
      l2:{crumb:function(r){return r.category;},url:function(r){return '/hq/drill/expense?unit='+state.unit+'&year='+state.year+'&month='+state.month+'&category='+encodeURIComponent(r.category);},
        cols:['Date','By','Note','Amount'],map:function(r){return [esc(r.date),esc(r.who),esc(r.note),rsS(r.amount)];}}},
    customers:{crumb:['Customers ordered'],url:function(){return '/hq/drill/customers'+qs();},
      cols:['Customer','Type','Orders','Spent','First'],map:function(r){return [esc(r.customer),esc(r.type)+(r.is_new?' <span class="pillm new">new</span>':''),rsS(r.orders),rsS(r.spent),esc(r.first)];}},
    banks:{crumb:['Working capital','Per-bank split'],local:true,
      cols:['Bank','Balance'],rows:function(){
        var rows=(data.wc.banks||[]).map(function(b){return [esc(b.name),rsS(b.balance)];});
        if(data.wc.online_unassigned&&Math.abs(data.wc.online_unassigned)>0)
          rows.push(['Unassigned (pre-tagging history)',rsS(data.wc.online_unassigned)]);
        return rows;
      },
      note:'Segregation by which bank received each payment (Bank Balances page figures). Not yet reconciled with the ONLINE ledger — the health tile shows the gap.'},
    receivables:{crumb:['Working capital','Receivables'],url:function(){return '/hq/drill/receivables?unit='+state.unit;},
      custom:function(d){
        function typePill(t){ return t==='shop' ? '<span class="pillm online">shop</span>' : esc(t); }
        var rows=(d.customers||[]).map(function(r){return '<tr><td>'+esc(r.who)+'</td><td>'+typePill(r.type)+'</td><td>'+rsS(r.invoices||1)+'</td><td>'+rsS(r.amount)+'</td><td>'+esc(r.oldest)+'</td><td>'+(r.age>30?'<span class="pillm late">'+r.age+' d</span>':r.age+' d')+'</td></tr>';});
        var extra=(d.rider&&d.rider>0)?'<tr><td>Riders — cash to settle</td><td><span class="pillm cash">rider</span></td><td>—</td><td>'+rsS(d.rider)+'</td><td>—</td><td>—</td></tr>':'';
        var note=(state.unit==='qb')
          ? 'This season’s delivered Qurbani orders with an unpaid balance. Older seasons are treated as closed.'
          : 'Regular rows = pending invoices in Online Approvals · Shop rows = the Shop tab’s outstanding. Legacy records are treated as closed; Qurbani balances live in the Qurbani view.';
        return '<table class="dt"><thead><tr><th>Who</th><th>Type</th><th>Inv</th><th>Amount</th><th>Oldest</th><th>Age</th></tr></thead><tbody>'+rows.join('')+extra+'</tbody></table>'+
          '<div class="p-note" style="border:0;padding:12px 2px 0">'+note+'</div>';
      }},
    payables:{crumb:['Working capital','Payables'],url:function(){return '/hq/drill/payables';},
      cols:['Vendor','Unit','Balance'],map:function(r){return [esc(r.vendor),esc(r.unit),rsS(r.balance)];}},
    assets:{crumb:['Working capital','Fixed assets'],url:function(){return '/hq/drill/assets?unit='+state.unit;},
      cols:['Asset','Unit','Location','Bought','Book value'],
      map:function(r){return [esc(r.asset),esc(r.unit),esc(r.location),esc(r.bought),rsS(r.value)];}},
    missing_invoices:{crumb:['Working capital','Missing invoices'],url:function(){return '/hq/drill/missing-invoices';},
      cols:['Order #','Customer','Delivered','Amount','Paid'],
      map:function(r){return [esc(r.order_number),esc(r.customer),esc(r.delivered),rsS(r.amount),pill(r.paid)];}}
  };
  function qs(){return '?unit='+state.unit+'&year='+state.year+'&month='+state.month;}
  function fmtDay(s){var d=new Date(s+'T00:00:00');return d.toLocaleDateString('en-GB',{weekday:'short',day:'2-digit',month:'short'});}
  function pill(p){var cls=p==='cash'||p==='cash (due)'?'cash':(p==='unpaid'||p==='partial'?'late':'online');return '<span class="pillm '+cls+'">'+esc(p)+'</span>';}

  window.hqDrill=function(key){
    drill={key:key,level:1,l1:null,row:null};
    $('hqDrillScrim').classList.add('on'); $('hqPanel').classList.add('on');
    renderDrill();
  };
  window.hqCloseDrill=function(){ $('hqDrillScrim').classList.remove('on'); $('hqPanel').classList.remove('on'); };
  window.hqDrillBack=function(){ drill.level=1; renderDrill(); };
  window.hqDrillRow=function(idx){ var d=DRILL[drill.key]; if(!d.l2)return; drill.row=drill.l1[idx]; drill.level=2; renderDrill(); };

  function renderDrill(){
    var d=DRILL[drill.key]; if(!d)return;
    var crumb=[UNITS[state.unit].name+' · '+(data.closing?data.closing.period_label:'')].concat(d.crumb);
    var l2 = drill.level===2 && d.l2;
    if(l2)crumb.push(d.l2.crumb(drill.row));
    $('hqPCrumb').innerHTML=crumb.map(function(c,i){return (i?'<span class="sep">›</span>':'')+'<span>'+esc(c)+'</span>';}).join('');
    $('hqPBack').classList.toggle('show',!!l2);
    $('hqPTitle').textContent=l2?d.l2.crumb(drill.row):(d.crumb[d.crumb.length-1]+' — '+(data.closing?data.closing.period_label:''));
    $('hqPBody').innerHTML='<div class="p-empty">Loading…</div>';

    if(d.local){
      var rows=d.rows();
      $('hqPBody').innerHTML=tbl(d.cols,rows,false)+
        (d.note?'<div class="p-note" style="border:0;padding:12px 2px 0">'+esc(d.note)+'</div>':'');
      return;
    }
    var url=l2?d.l2.url(drill.row):d.url();
    fetchJSON(url).then(function(res){
      if(d.custom&&!l2){$('hqPBody').innerHTML=res&&(res.customers||res.rider!=null)?d.custom(res):'<div class="p-empty">Nothing here for this period.</div>';return;}
      if(!l2)drill.l1=res;
      var src=l2?d.l2:d;
      var list=res||[];
      if(!list.length){$('hqPBody').innerHTML='<div class="p-empty">Nothing here for this period.</div>';return;}
      var clickable=!l2&&d.l2;
      var body='<table class="dt"><thead><tr>'+src.cols.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr></thead><tbody>';
      body+=list.map(function(r,i){return '<tr'+(clickable?' class="rowclick" onclick="hqDrillRow('+i+')"':'')+'>'+src.map(r).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';}).join('');
      if(!l2&&d.total)body+='<tr class="total">'+d.total(list).map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';
      body+='</tbody></table>';
      if(clickable)body+='<div class="p-note" style="border:0;padding:12px 2px 0">Click a row for the underlying entries.</div>';
      $('hqPBody').innerHTML=body;
    }).catch(function(e){ $('hqPBody').innerHTML='<div class="p-empty">Could not load ('+esc(e.message)+').</div>'; });
  }
  function tbl(cols,rows,clickable){
    var b='<table class="dt"><thead><tr>'+cols.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr></thead><tbody>';
    b+=rows.map(function(r){return '<tr>'+r.map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>';}).join('');
    return b+'</tbody></table>';
  }

  // ---------- orchestration ----------
  function setLoading(on){ ['hqKpis','hqPlFlow','hqWc'].forEach(function(id){var e=$(id);if(e)e.classList.toggle('loading',on);}); }
  function hqReload(force){
    clearErr(); setLoading(true);
    var suffix=force?'&fresh=1':'';
    Promise.all([
      fetchJSON('/hq/closing'+qs()+suffix),
      fetchJSON('/hq/trend'+qs()+suffix),
      fetchJSON('/hq/working-capital?unit='+state.unit+(force?'&fresh=1':''))
    ]).then(function(res){
      data.closing=res[0]; data.trend=res[1]; data.wc=res[2];
      renderState(); renderClosing(); renderWc(); renderTrend(); setLoading(false);
    }).catch(function(e){ setLoading(false); showErr(e.message); });
  }
  window.hqReload=hqReload;
  window.hqSetUnit=function(u){ state.unit=u; if(u==='qb'){state.year=CUR_YEAR;} renderControls(); hqReload(); };
  window.hqPick=function(y,m){ state.year=y; state.month=m; renderControls(); hqReload(); };
  window.hqSetTab=function(t){ state.tab=t;
    $('tabClosing').classList.toggle('on',t==='closing'); $('tabGrowth').classList.toggle('on',t==='growth');
    $('hqClosing').style.display=t==='closing'?'':'none'; $('hqGrowth').style.display=t==='growth'?'':'none'; };

  document.addEventListener('keydown',function(e){ if(e.key==='Escape'){
    if($('hqDefBox').classList.contains('on'))hqCloseDef();
    else if($('hqPanel').classList.contains('on'))hqCloseDrill(); } });

  renderControls();
  hqReload();
})();
</script>
@endsection
